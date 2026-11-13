<?php
namespace DWCatalog\Migration;

use DWCatalog\Catalog;
use DWCatalog\Db\Connection;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Support\Text;

/**
 * Moves an older catalog into this one.
 *
 *   detect → mapping → groups → dry run (choices) → run (batches of 50, resumable) → finish → rollback
 *
 * Rules:
 *   - The source is only ever read. Nothing of the source is changed or deleted.
 *   - Everything created is recorded in the `migration` table (source record → catalog record), so
 *     running again updates instead of duplicating, and a rollback deletes exactly what was created.
 *   - The dry run and the real run share one decision function (decide()), so what the dry run
 *     shows is what the run does.
 */
class Migrator {

	const KEY   = 'migration';
	const BATCH = 50;

	/** Largest source the one-request scan (dry run) handles. */
	const MAX_SCAN = 20000;

	const CHOICES = array(
		'empty_status'   => array( 'keep', 'active', 'hidden' ),
		'duplicate_code' => array( 'review', 'latest' ),
		'dash_slug'      => array( 'keep', 'clean' ),
	);

	const UNGROUPED = 'Uncategorized';

	/** @var Catalog */
	private $c;
	/** @var Source */
	private $source;
	/** @var string catalog type products go into */
	private $type;

	public function __construct( Catalog $c, Source $source ) {
		$this->c      = $c;
		$this->source = $source;
		$this->type   = $c->fields()->primaryType();
	}

	public function source(): Source {
		return $this->source;
	}

	// ── State ───────────────────────────────────────────────────

	public function state(): array {
		$s = $this->c->settings()->get( self::KEY );
		$s = is_array( $s ) ? $s : array();
		return array(
			'mapping' => isset( $s['mapping'] ) && is_array( $s['mapping'] ) ? $s['mapping'] : $this->defaultMapping(),
			'groups'  => isset( $s['groups'] ) && is_array( $s['groups'] ) ? $s['groups'] : null,
			'choices' => $this->cleanChoices( isset( $s['choices'] ) && is_array( $s['choices'] ) ? $s['choices'] : array() ),
			'run'     => isset( $s['run'] ) && is_array( $s['run'] ) ? $s['run'] + self::freshRun() : self::freshRun(),
			'finish'  => ( isset( $s['finish'] ) && is_array( $s['finish'] ) ? $s['finish'] : array() ) + array( 'redirects' => false, 'rest_block' => false ),
		);
	}

	private function put( string $part, $value ): void {
		$s          = $this->c->settings()->get( self::KEY );
		$s          = is_array( $s ) ? $s : array();
		$s[ $part ] = $value;
		$this->c->settings()->set( self::KEY, $s );
	}

	private static function freshRun(): array {
		return array(
			'status'  => 'idle', // idle | running | paused | done | rollback
			'stage'   => 'terms', // terms → items → applications → done
			'cursor'  => 0,
			'done'    => 0,
			'total'   => 0,
			'counts'  => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'images' => 0, 'image_errors' => 0, 'terms' => 0, 'applications' => 0, 'files' => 0 ),
			'skip'    => array(),
			'dup'     => array(),
			'errors'  => array(),
			'started' => '',
			'ended'   => '',
		);
	}

	// ── Step 2: field mapping ───────────────────────────────────

	/** Source field → catalog field with the same key, else the same label, else '' (not imported). */
	public function defaultMapping(): array {
		$targets = $this->c->fields()->fieldsByKey( $this->type );
		$out     = array();
		foreach ( $this->source->productFields() as $key => $label ) {
			$to = isset( $targets[ $key ] ) ? $key : '';
			if ( '' === $to ) {
				foreach ( $targets as $tk => $f ) {
					if ( 0 === strcasecmp( (string) $f['label'], (string) $label ) ) {
						$to = $tk;
						break;
					}
				}
			}
			$out[ $key ] = $to;
		}
		return $out;
	}

	/**
	 * @return string[] warnings (a note field pointed at a public field is not saved that way)
	 */
	public function saveMapping( array $in ): array {
		$targets  = $this->c->fields()->fieldsByKey( $this->type );
		$out      = array();
		$used     = array();
		$warnings = array();
		foreach ( $this->source->productFields() as $key => $label ) {
			$to = isset( $in[ $key ] ) && is_string( $in[ $key ] ) && isset( $targets[ $in[ $key ] ] ) ? $in[ $key ] : '';
			if ( '' !== $to && isset( $used[ $to ] ) ) {
				$to = ''; // one catalog field takes one source field
			}
			if ( '' !== $to && self::isInternal( $key ) && FieldConfig::isPublic( $targets[ $to ] ) ) {
				$warnings[] = sprintf( $this->c->t( '%s holds internal notes, so it cannot go into a field visitors can see. It is not imported until you pick a private field.' ), $label );
				$to         = '';
			}
			if ( '' !== $to ) {
				$used[ $to ] = true;
			}
			$out[ $key ] = $to;
		}
		$this->put( 'mapping', $out );
		return $warnings;
	}

	public static function isInternal( string $sourceKey ): bool {
		return (bool) preg_match( '/internal|private|memo/', $sourceKey );
	}

	// ── Step 3: category groups ─────────────────────────────────

	/** Groups to create: the saved plan, or what the site's pages say. @return array[] name, slug, cats[] */
	public function groups(): array {
		$state = $this->state();
		if ( null !== $state['groups'] ) {
			return $state['groups'];
		}
		$out = array();
		foreach ( $this->source->groups() as $g ) {
			$slug = Text::slug( isset( $g['slug'] ) && '' !== $g['slug'] ? $g['slug'] : $g['name'] );
			if ( '' === $slug ) {
				continue;
			}
			$out[] = array( 'name' => Text::line( $g['name'], 200 ), 'slug' => $slug, 'cats' => array_values( array_unique( array_map( 'intval', (array) $g['categories'] ) ) ) );
		}
		return $out;
	}

	/** Source category id => group slug ('' = in no group). A category listed twice keeps its first group. */
	public function groupOf(): array {
		$out = array();
		foreach ( $this->groups() as $g ) {
			foreach ( $g['cats'] as $cid ) {
				if ( ! isset( $out[ $cid ] ) ) {
					$out[ $cid ] = $g['slug'];
				}
			}
		}
		foreach ( $this->source->categories() as $cat ) {
			if ( ! isset( $out[ (int) $cat['id'] ] ) ) {
				$out[ (int) $cat['id'] ] = '';
			}
		}
		return $out;
	}

	/**
	 * Save the plan from the form: names[slug] => name, assign[source category id] => group slug.
	 * A new group may be added with names['+'].
	 */
	public function saveGroups( array $names, array $assign ): void {
		$groups = array();
		foreach ( $names as $slug => $name ) {
			$name = Text::line( is_scalar( $name ) ? (string) $name : '', 200 );
			if ( '' === $name ) {
				continue;
			}
			$slug            = '+' === (string) $slug ? Text::slug( $name ) : Text::slug( (string) $slug );
			$groups[ $slug ] = array( 'name' => $name, 'slug' => $slug, 'cats' => array() );
		}
		foreach ( $this->source->categories() as $cat ) {
			$to = isset( $assign[ $cat['id'] ] ) && is_scalar( $assign[ $cat['id'] ] ) ? Text::slug( (string) $assign[ $cat['id'] ] ) : '';
			if ( isset( $groups[ $to ] ) ) {
				$groups[ $to ]['cats'][] = (int) $cat['id'];
			}
		}
		$this->put( 'groups', array_values( $groups ) );
	}

	public function resetGroups(): void {
		$this->put( 'groups', null );
	}

	// ── Step 4: dry run ─────────────────────────────────────────

	public function cleanChoices( array $in ): array {
		$out = array();
		foreach ( self::CHOICES as $key => $allowed ) {
			$out[ $key ] = isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $allowed[0];
		}
		return $out;
	}

	public function saveChoices( array $in ): void {
		$this->put( 'choices', $this->cleanChoices( $in ) );
	}

	/**
	 * Read every source product once and decide what happens to each. Writes nothing.
	 *
	 * @return array summary (counts), issues (counts), rows (one per product), categories (comparison)
	 */
	public function dryRun( ?array $choices = null ): array {
		$state   = $this->state();
		$choices = null === $choices ? $state['choices'] : $this->cleanChoices( $choices );
		$mapping = $state['mapping'];
		$keys    = $this->roleKeys( $mapping );

		// Pass 1: everything, light — codes need the whole set before any product can be decided.
		$products = array();
		$after    = 0;
		do {
			$chunk = $this->source->products( $after, 500 );
			foreach ( $chunk as $p ) {
				$products[] = $p;
				$after      = (int) $p['id'];
			}
		} while ( $chunk && count( $products ) < self::MAX_SCAN );
		$ctx = $this->context( $products, $keys );

		$issues  = array( 'empty_status' => 0, 'empty_brand' => 0, 'duplicate_code' => 0, 'dash_slug' => 0, 'no_image' => 0, 'hidden' => 0, 'no_category' => 0 );
		$summary = array( 'products' => count( $products ), 'migrate' => 0, 'skip' => 0, 'visible' => 0, 'hidden' => 0, 'images' => 0 );
		$perCat  = array();
		$rows    = array();
		foreach ( $products as $p ) {
			$d = $this->decide( $p, $ctx, $choices, $keys );
			foreach ( $d['issues'] as $i ) {
				$issues[ $i ]++;
			}
			$d['skip'] ? $summary['skip']++ : $summary['migrate']++;
			if ( ! $d['skip'] ) {
				'publish' === $d['status'] ? $summary['visible']++ : $summary['hidden']++;
				$summary['images'] += $p['image'] ? 1 : 0;
			}
			foreach ( (array) $p['categories'] as $cid ) {
				$perCat[ (int) $cid ]['source'] = ( isset( $perCat[ (int) $cid ]['source'] ) ? $perCat[ (int) $cid ]['source'] : 0 ) + 1;
				if ( ! $d['skip'] ) {
					$perCat[ (int) $cid ]['planned'] = ( isset( $perCat[ (int) $cid ]['planned'] ) ? $perCat[ (int) $cid ]['planned'] : 0 ) + 1;
				}
			}
			$rows[] = array(
				'id'       => (int) $p['id'],
				'title'    => $d['title'],
				'code'     => $d['code'],
				'old_slug' => (string) $p['slug'],
				'new_slug' => $d['slug'],
				'status'   => $d['pstatus'],
				'visible'  => 'publish' === $d['status'],
				'cats'     => array_map( 'intval', (array) $p['categories'] ),
				'image'    => (bool) $p['image'],
				'issues'   => $d['issues'],
				'skip'     => $d['skip'],
				'review'   => $d['review'],
			);
		}

		$groupOf    = $this->groupOf();
		$groupNames = array();
		foreach ( $this->groups() as $g ) {
			$groupNames[ $g['slug'] ] = $g['name'];
		}
		$categories = array();
		$empty      = 0;
		foreach ( $this->source->categories() as $cat ) {
			$cid          = (int) $cat['id'];
			$n            = isset( $perCat[ $cid ]['source'] ) ? $perCat[ $cid ]['source'] : 0;
			$empty       += $n ? 0 : 1;
			$slug         = isset( $groupOf[ $cid ] ) ? $groupOf[ $cid ] : '';
			$categories[] = array(
				'id'      => $cid,
				'name'    => (string) $cat['name'],
				'slug'    => (string) $cat['slug'],
				'group'   => isset( $groupNames[ $slug ] ) ? $groupNames[ $slug ] : '',
				'source'  => $n,
				'planned' => isset( $perCat[ $cid ]['planned'] ) ? $perCat[ $cid ]['planned'] : 0,
			);
		}
		$issues['empty_category'] = $empty;
		$issues['ungrouped']      = count( array_filter( $groupOf, function ( $g ) {
			return '' === $g;
		} ) );

		return array(
			'summary'    => $summary + array( 'categories' => count( $categories ), 'groups' => count( $groupNames ) ),
			'issues'     => $issues,
			'rows'       => $rows,
			'categories' => $categories,
			'choices'    => $choices,
			'detect'     => $this->source->detect(),
		);
	}

	/** Dry-run result as CSV (UTF-8 with BOM, opens in Excel). */
	public function dryRunCsv( ?array $choices = null ): string {
		$report = $this->dryRun( $choices );
		$names  = array();
		foreach ( $report['categories'] as $cat ) {
			$names[ $cat['id'] ] = ( '' !== $cat['group'] ? $cat['group'] . ' > ' : '' ) . $cat['name'];
		}
		$out = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'source_id', 'title', 'item_code', 'old_slug', 'new_slug', 'status', 'visible', 'categories', 'image', 'issues', 'action' ) );
		foreach ( $report['rows'] as $r ) {
			fputcsv( $out, array_map( array( '\DWCatalog\Admin\CsvFormat', 'safeCell' ), array(
				$r['id'],
				$r['title'],
				$r['code'],
				$r['old_slug'],
				$r['new_slug'],
				$r['status'],
				$r['visible'] ? 'yes' : 'no',
				implode( ' | ', array_map( function ( $cid ) use ( $names ) {
					return isset( $names[ $cid ] ) ? $names[ $cid ] : '#' . $cid;
				}, $r['cats'] ) ),
				$r['image'] ? 'yes' : 'no',
				implode( ', ', $r['issues'] ),
				$r['skip'] ? 'skip' : ( '' !== $r['review'] ? 'migrate + review' : 'migrate' ),
			) ) );
		}
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	/** Catalog field keys playing a role, and the source keys feeding them. */
	private function roleKeys( array $mapping ): array {
		$fields = $this->c->fields();
		$out    = array( 'title' => '', 'code' => '', 'brand' => '', 'status' => '' );
		$title  = $fields->titleField( $this->type );
		$role   = array(
			'title'  => $title ? $title['meta_key'] : '',
			'code'   => ( $f = $fields->roleField( $this->type, 'code', false ) ) ? $f['meta_key'] : '',
			'brand'  => ( $f = $fields->roleField( $this->type, 'brand', false ) ) ? $f['meta_key'] : '',
			'status' => ( $f = $fields->roleField( $this->type, 'status', false ) ) ? $f['meta_key'] : '',
		);
		foreach ( $mapping as $src => $to ) {
			foreach ( $role as $name => $key ) {
				if ( '' !== $to && $to === $key && '' === $out[ $name ] ) {
					$out[ $name ] = $src;
				}
			}
		}
		return array( 'source' => $out, 'target' => $role );
	}

	/** What only the whole set can tell: which codes repeat, and which product of each code is the latest. */
	private function context( array $products, array $keys ): array {
		$byCode = array();
		$src    = $keys['source']['code'];
		foreach ( $products as $p ) {
			$code = '' !== $src && isset( $p['meta'][ $src ] ) ? trim( (string) $p['meta'][ $src ] ) : '';
			if ( '' !== $code ) {
				$byCode[ Text::lower( $code ) ][] = array( (int) $p['id'], (string) $p['modified'] );
			}
		}
		$dup    = array();
		$latest = array();
		foreach ( $byCode as $list ) {
			if ( count( $list ) < 2 ) {
				continue;
			}
			usort( $list, function ( $a, $b ) {
				return array( $b[1], $b[0] ) <=> array( $a[1], $a[0] );
			} );
			foreach ( $list as $i => $entry ) {
				$dup[ $entry[0] ] = true;
				if ( 0 === $i ) {
					$latest[ $entry[0] ] = true;
				}
			}
		}
		return array( 'dup' => $dup, 'latest' => $latest );
	}

	/**
	 * The outcome for one source product under the chosen handling. Used by the dry run and the run.
	 *
	 * @return array title, slug, status (publish|draft), pstatus, code, review, skip, issues[]
	 */
	public function decide( array $p, array $ctx, array $choices, array $keys ): array {
		$meta   = isset( $p['meta'] ) && is_array( $p['meta'] ) ? $p['meta'] : array();
		$get    = function ( $role ) use ( $meta, $keys ) {
			$k = $keys['source'][ $role ];
			return '' !== $k && isset( $meta[ $k ] ) ? trim( (string) $meta[ $k ] ) : '';
		};
		$title  = '' !== $get( 'title' ) ? $get( 'title' ) : (string) $p['title'];
		$code   = $get( 'code' );
		$issues = array();
		$status = 'publish' === $p['status'] ? 'publish' : 'draft';
		if ( 'draft' === $status ) {
			$issues[] = 'hidden';
		}

		$pstatus = $get( 'status' );
		if ( '' === $pstatus && '' !== $keys['source']['status'] ) {
			$issues[] = 'empty_status';
			if ( 'active' === $choices['empty_status'] ) {
				$pstatus = 'active';
			} elseif ( 'hidden' === $choices['empty_status'] ) {
				$status = 'draft';
			}
		}
		if ( '' === $get( 'brand' ) && '' !== $keys['source']['brand'] ) {
			$issues[] = 'empty_brand';
		}

		$review = '';
		$skip   = false;
		$id     = (int) $p['id'];
		if ( isset( $ctx['dup'][ $id ] ) ) {
			$issues[] = 'duplicate_code';
			if ( 'latest' === $choices['duplicate_code'] ) {
				$skip = ! isset( $ctx['latest'][ $id ] );
			} else {
				$review = 'duplicate_code';
			}
		}

		$slug = Text::slug( (string) $p['slug'] );
		if ( self::hasDashSuffix( $slug, $title ) ) {
			$issues[] = 'dash_slug';
			if ( 'clean' === $choices['dash_slug'] && '' !== Text::slug( $code ) ) {
				$slug = preg_replace( '/-\d{1,2}$/', '', $slug ) . '-' . Text::slug( $code );
			}
		}
		if ( ! $p['image'] ) {
			$issues[] = 'no_image';
		}
		if ( ! $p['categories'] ) {
			$issues[] = 'no_category';
		}
		return array( 'title' => $title, 'slug' => $slug, 'status' => $status, 'pstatus' => $pstatus, 'code' => $code, 'review' => $review, 'skip' => $skip, 'issues' => $issues );
	}

	/** "-1" / "-2" added by the old system to a repeated URL — not a number that belongs to the name. */
	public static function hasDashSuffix( string $slug, string $title ): bool {
		if ( ! preg_match( '/-(\d{1,2})$/', $slug, $m ) ) {
			return false;
		}
		return ! preg_match( '/(^|-)' . $m[1] . '$/', Text::slug( $title ) );
	}

	// ── Step 5: run ─────────────────────────────────────────────

	/** Start (or restart from the beginning — existing records are updated, never duplicated). */
	public function start(): array {
		$report        = $this->dryRun();
		$run           = self::freshRun();
		$run['status'] = 'running';
		$run['total']  = $report['summary']['products'] + (int) $report['detect']['applications']['count'];
		$run['started'] = Text::now();
		foreach ( $report['rows'] as $r ) {
			if ( $r['skip'] ) {
				$run['skip'][] = $r['id'];
			} elseif ( '' !== $r['review'] ) {
				$run['dup'][] = $r['id'];
			}
		}
		$this->put( 'run', $run );
		return $run;
	}

	public function pause(): void {
		$run = $this->state()['run'];
		if ( 'running' === $run['status'] ) {
			$run['status'] = 'paused';
			$this->put( 'run', $run );
		}
	}

	public function resume(): void {
		$run = $this->state()['run'];
		if ( 'paused' === $run['status'] ) {
			$run['status'] = 'running';
			$this->put( 'run', $run );
		}
	}

	/**
	 * Do one batch. Safe to call from the browser and from a background timer at once: a second
	 * caller finds the lock taken and does nothing.
	 *
	 * @return array the run state after the batch (+ 'busy' => true when another batch holds the lock)
	 */
	public function batch( int $limit = self::BATCH ): array {
		$run = $this->state()['run'];
		if ( 'running' !== $run['status'] ) {
			return $run;
		}
		if ( ! $this->lock() ) {
			return $run + array( 'busy' => true );
		}
		try {
			$run = $this->state()['run']; // re-read under the lock
			if ( 'running' === $run['status'] ) {
				$run = $this->step( $run, max( 1, min( 200, $limit ) ) );
				$this->put( 'run', $run );
			}
		} finally {
			$this->unlock();
		}
		return $run;
	}

	private function step( array $run, int $limit ): array {
		$state = $this->state();
		switch ( $run['stage'] ) {
			case 'terms':
				$run['counts']['terms'] = $this->migrateTerms();
				$run['stage']           = 'items';
				$run['cursor']          = 0;
				break;
			case 'items':
				$products = $this->source->products( (int) $run['cursor'], $limit );
				if ( ! $products ) {
					$run['stage']  = 'applications';
					$run['cursor'] = 0;
					break;
				}
				$keys   = $this->roleKeys( $state['mapping'] );
				$ctx    = array( 'dup' => array_fill_keys( array_merge( $run['dup'], $run['skip'] ), true ), 'latest' => array_fill_keys( $run['dup'], true ) );
				$skip   = array_fill_keys( $run['skip'], true );
				$termOf = $this->map( 'term' );
				$items  = $this->c->items();
				$items->beginBatch();
				foreach ( $products as $p ) {
					$run['cursor'] = (int) $p['id'];
					$run['done']++;
					if ( isset( $skip[ (int) $p['id'] ] ) ) {
						$run['counts']['skipped']++;
						continue;
					}
					try {
						$this->migrateItem( $p, $ctx, $state, $keys, $termOf, $run );
					} catch ( \Throwable $e ) {
						$run['errors'][] = '#' . (int) $p['id'] . ' ' . Text::line( (string) $p['title'], 80 ) . ': ' . Text::line( $e->getMessage(), 200 );
						$run['errors']   = array_slice( $run['errors'], -100 );
					}
				}
				$items->endBatch();
				break;
			case 'applications':
				$apps = $this->source->applications( (int) $run['cursor'], $limit );
				if ( ! $apps ) {
					$run['stage']  = 'done';
					$run['status'] = 'done';
					$run['ended']  = Text::now();
					break;
				}
				foreach ( $apps as $a ) {
					$run['cursor'] = (int) $a['id'];
					$run['done']++;
					$this->migrateApplication( $a, $run );
				}
				break;
		}
		return $run;
	}

	/** Groups become top-level categories, source categories go under their group. @return int terms created */
	private function migrateTerms(): int {
		$terms   = $this->c->terms();
		$created = 0;
		$groupId = array();
		$groupOf = $this->groupOf();
		$groups  = $this->groups();
		if ( in_array( '', $groupOf, true ) && $groups ) {
			$groups[] = array( 'name' => self::UNGROUPED, 'slug' => 'uncategorized', 'cats' => array() );
		}
		$ensure = function ( string $kind, string $sourceId, string $name, string $slug, int $parent ) use ( $terms, &$created ) {
			$known = $this->mapGet( $kind, $sourceId );
			if ( $known && $terms->get( $this->type, (int) $known['target_id'] ) ) {
				if ( (int) $known['created'] ) {
					$terms->save( $this->type, 'category', $name, $slug, $parent, (int) $known['target_id'], (int) $terms->get( $this->type, (int) $known['target_id'] )['position'] );
				}
				return (int) $known['target_id'];
			}
			$existing = $terms->bySlug( $this->type, $slug );
			if ( $existing ) {
				$this->mapSet( $kind, $sourceId, (int) $existing['id'], false ); // was already here: never deleted by a rollback
				return (int) $existing['id'];
			}
			$id = $terms->save( $this->type, 'category', $name, $slug, $parent, 0, count( $terms->all( $this->type ) ) );
			$this->mapSet( $kind, $sourceId, $id, true );
			$created++;
			return $id;
		};
		foreach ( $groups as $g ) {
			$groupId[ $g['slug'] ] = $ensure( 'group', $g['slug'], $g['name'], $g['slug'], 0 );
		}
		foreach ( $this->source->categories() as $cat ) {
			$slug   = isset( $groupOf[ (int) $cat['id'] ] ) ? $groupOf[ (int) $cat['id'] ] : '';
			$parent = isset( $groupId[ $slug ] ) ? $groupId[ $slug ] : ( isset( $groupId['uncategorized'] ) ? $groupId['uncategorized'] : 0 );
			$ensure( 'term', (string) (int) $cat['id'], (string) $cat['name'], (string) $cat['slug'], $parent );
		}
		return $created;
	}

	private function migrateItem( array $p, array $ctx, array $state, array $keys, array $termOf, array &$run ): void {
		$d       = $this->decide( $p, $ctx, $state['choices'], $keys );
		$items   = $this->c->items();
		$known   = $this->mapGet( 'item', (string) (int) $p['id'] );
		$id      = $known && $items->get( (int) $known['target_id'] ) ? (int) $known['target_id'] : 0;
		$isNew   = 0 === $id;
		$id      = $items->save( array(
			'id'      => $id,
			'type'    => $this->type,
			'title'   => $d['title'],
			'slug'    => $d['slug'],
			'content' => $this->c->host()->sanitizeHtml( (string) $p['content'] ),
			'status'  => $d['status'],
		) );
		$db = $this->c->db();
		if ( $isNew ) {
			$this->mapSet( 'item', (string) (int) $p['id'], $id, true, array( 'slug' => (string) $p['slug'] ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $p['created'] ) ) {
				$db->execute( 'UPDATE ' . $db->table( 'items' ) . ' SET created_at = ? WHERE id = ?', array( $p['created'], $id ) );
			}
			$run['counts']['created']++;
		} else {
			$run['counts']['updated']++;
		}
		$db->execute( 'UPDATE ' . $db->table( 'items' ) . ' SET review = ? WHERE id = ?', array( $d['review'], $id ) );
		// Old URL → this product (301 once redirects are switched on)
		if ( '' !== (string) $p['slug'] && ! $this->mapGet( 'slug', (string) $p['slug'] ) ) {
			$this->mapSet( 'slug', (string) $p['slug'], $id, true );
		}

		// Field values through the mapping
		$targets = $this->c->fields()->fieldsByKey( $this->type );
		$values  = array();
		foreach ( $state['mapping'] as $src => $to ) {
			if ( '' === $to || ! isset( $targets[ $to ] ) ) {
				continue;
			}
			$raw = isset( $p['meta'][ $src ] ) ? (string) $p['meta'][ $src ] : '';
			if ( $to === $keys['target']['status'] ) {
				$raw = $d['pstatus'];
			}
			$values[ $to ] = ! empty( $targets[ $to ]['is_title_field'] ) ? $d['title'] : FieldConfig::sanitizeValue( $targets[ $to ], $raw );
		}
		if ( '' !== $keys['target']['title'] ) {
			$values[ $keys['target']['title'] ] = $d['title'];
		}
		$items->saveValues( $id, $values );

		$termIds = array();
		foreach ( (array) $p['categories'] as $cid ) {
			if ( isset( $termOf[ (string) (int) $cid ] ) ) {
				$termIds[] = $termOf[ (string) (int) $cid ];
			}
		}
		$this->c->terms()->setItem( $this->type, $id, 'category', $termIds );

		// Photo: converted to the catalog's sizes and format (WebP where the server can)
		if ( $p['image'] && ! empty( $p['image']['path'] ) ) {
			$key = (int) $p['id'] . ':' . md5( (string) $p['image']['path'] );
			if ( ! $this->mapGet( 'image', $key ) ) {
				$result = is_file( $p['image']['path'] ) ? $this->c->images()->store( $id, $p['image']['path'], isset( $p['image']['name'] ) ? (string) $p['image']['name'] : 'image' ) : 'Image file is missing.';
				if ( is_int( $result ) ) {
					$this->mapSet( 'image', $key, $result, true );
					if ( ! empty( $p['image']['alt'] ) ) {
						$db->execute( 'UPDATE ' . $db->table( 'images' ) . ' SET alt = ? WHERE id = ?', array( Text::line( $p['image']['alt'], 255 ), $result ) );
					}
					$run['counts']['images']++;
				} else {
					$run['counts']['image_errors']++;
					$run['errors'][] = '#' . (int) $p['id'] . ' ' . Text::line( $d['title'], 80 ) . ': ' . $this->c->t( (string) $result );
					$run['errors']   = array_slice( $run['errors'], -100 );
				}
			}
		}
	}

	private function migrateApplication( array $a, array &$run ): void {
		if ( $this->mapGet( 'application', (string) (int) $a['id'] ) ) {
			return;
		}
		$apps = $this->c->applications();
		$id   = $apps->create( (array) $a['fields'], 'migration', isset( $a['created'] ) ? (string) $a['created'] : '' );
		$this->mapSet( 'application', (string) (int) $a['id'], $id, true );
		$run['counts']['applications']++;
		foreach ( isset( $a['files'] ) ? (array) $a['files'] : array() as $f ) {
			$ok = $apps->attach( $id, (string) $f['path'], (string) $f['name'] );
			if ( true === $ok ) {
				$run['counts']['files']++;
			} else {
				$run['errors'][] = 'Application #' . (int) $a['id'] . ' — ' . Text::line( (string) $f['name'], 80 ) . ': ' . $this->c->t( (string) $ok );
			}
		}
	}

	/** After the run: source vs migrated, per category and in total. */
	public function compare(): array {
		$db    = $this->c->db();
		$dry   = $this->dryRun();
		$terms = $this->map( 'term' );
		$count = array();
		$sql   = 'SELECT it.term_id, COUNT(*) AS n FROM ' . $db->table( 'item_terms' ) . ' it INNER JOIN ' . $db->table( 'migration' ) . ' m ON m.kind = ? AND m.target_id = it.item_id GROUP BY it.term_id';
		foreach ( $db->select( $sql, array( 'item' ) ) as $r ) {
			$count[ (int) $r['term_id'] ] = (int) $r['n'];
		}
		$rows = array();
		foreach ( $dry['categories'] as $cat ) {
			$tid    = isset( $terms[ (string) $cat['id'] ] ) ? $terms[ (string) $cat['id'] ] : 0;
			$rows[] = $cat + array( 'migrated' => isset( $count[ $tid ] ) ? $count[ $tid ] : 0 );
		}
		$n = function ( string $kind ) use ( $db ) {
			return (int) $db->selectValue( 'SELECT COUNT(*) FROM ' . $db->table( 'migration' ) . ' WHERE kind = ?', array( $kind ) );
		};
		return array(
			'categories' => $rows,
			'totals'     => array(
				'products'     => array( $dry['summary']['migrate'], $n( 'item' ) ),
				'categories'   => array( $dry['summary']['categories'], $n( 'term' ) ),
				'images'       => array( $dry['summary']['images'], $n( 'image' ) ),
				'applications' => array( (int) $dry['detect']['applications']['count'], $n( 'application' ) ),
			),
		);
	}

	// ── Step 6: finish ──────────────────────────────────────────

	public function setFinish( string $flag, bool $on ): void {
		$finish = $this->state()['finish'];
		if ( in_array( $flag, array( 'redirects', 'rest_block' ), true ) ) {
			$finish[ $flag ] = $on;
			$this->put( 'finish', $finish );
		}
	}

	/** Shortcode that shows what a page's query block showed. */
	public function shortcodeFor( array $page ): string {
		$slug = isset( $page['group_slug'] ) ? Text::slug( (string) $page['group_slug'] ) : '';
		return '' !== $slug ? '[dw_catalog category="' . $slug . '"]' : '[dw_catalog]';
	}

	/** Pages with query blocks: what would change, and whether it already has. */
	public function pages(): array {
		$out = array();
		foreach ( $this->source->pages() as $page ) {
			$backup = $this->mapGet( 'page', (string) (int) $page['id'] );
			$out[]  = $page + array( 'shortcode' => $this->shortcodeFor( $page ), 'replaced' => (bool) $backup );
		}
		return $out;
	}

	/** Replace the query blocks of the chosen pages. The original content is kept for the rollback. */
	public function replacePages( array $pageIds ): int {
		$done = 0;
		foreach ( $this->source->pages() as $page ) {
			$id = (int) $page['id'];
			if ( ! in_array( $id, array_map( 'intval', $pageIds ), true ) || $this->mapGet( 'page', (string) $id ) ) {
				continue;
			}
			$this->mapSet( 'page', (string) $id, $id, true, array( 'content' => (string) $page['content'] ) );
			if ( null === $this->source->replacePage( $id, $this->shortcodeFor( $page ), true ) ) {
				$this->mapDelete( 'page', (string) $id );
				continue;
			}
			$done++;
		}
		return $done;
	}

	/** Catalog item an old product URL slug now belongs to (for the 301), or 0. */
	public function itemForOldSlug( string $slug ): int {
		if ( ! $this->state()['finish']['redirects'] ) {
			return 0;
		}
		$row = $this->mapGet( 'slug', Text::cut( $slug, 100 ) );
		return $row ? (int) $row['target_id'] : 0;
	}

	/** Old slug of a migrated item ('' if it was not migrated). */
	public function oldSlugOf( int $itemId ): string {
		$db = $this->c->db();
		return (string) $db->selectValue( 'SELECT source_id FROM ' . $db->table( 'migration' ) . ' WHERE kind = ? AND target_id = ? ORDER BY source_id', array( 'slug', $itemId ) );
	}

	// ── Step 7: rollback ────────────────────────────────────────

	public function hasData(): bool {
		return (int) $this->c->db()->selectValue( 'SELECT COUNT(*) FROM ' . $this->c->db()->table( 'migration' ) ) > 0;
	}

	/**
	 * Undo one batch: pages first (the site looks as before at once), then products, applications,
	 * categories. Only records this migration created are deleted. @return int records still to undo
	 */
	public function rollbackBatch( int $limit = 100 ): int {
		$db  = $this->c->db();
		$run = $this->state()['run'];
		if ( 'rollback' !== $run['status'] ) {
			$run['status'] = 'rollback';
			$this->put( 'run', $run );
			$this->put( 'finish', array( 'redirects' => false, 'rest_block' => false ) );
		}
		if ( ! $this->lock() ) {
			return $this->remaining();
		}
		try {
			$rows = $db->select( 'SELECT kind, source_id, target_id, created, extra FROM ' . $db->table( 'migration' ) . " ORDER BY CASE kind WHEN 'page' THEN 0 WHEN 'image' THEN 1 WHEN 'slug' THEN 2 WHEN 'item' THEN 3 WHEN 'application' THEN 4 WHEN 'term' THEN 5 ELSE 6 END, source_id LIMIT ?", array( $limit ) );
			foreach ( $rows as $r ) {
				$tid = (int) $r['target_id'];
				if ( (int) $r['created'] ) {
					switch ( $r['kind'] ) {
						case 'page':
							$extra = json_decode( (string) $r['extra'], true );
							if ( is_array( $extra ) && isset( $extra['content'] ) ) {
								$this->source->restorePage( $tid, (string) $extra['content'] );
							}
							break;
						case 'item':
							$this->c->deleteItem( $tid ); // values, variations, terms, index, photos
							break;
						case 'application':
							$this->c->applications()->delete( $tid );
							break;
						case 'term':
						case 'group':
							$this->c->terms()->delete( $this->type, $tid );
							break;
					}
				}
				$this->mapDelete( (string) $r['kind'], (string) $r['source_id'] );
			}
		} finally {
			$this->unlock();
		}
		$left = $this->remaining();
		if ( 0 === $left ) {
			$this->put( 'run', self::freshRun() );
		}
		return $left;
	}

	private function remaining(): int {
		return (int) $this->c->db()->selectValue( 'SELECT COUNT(*) FROM ' . $this->c->db()->table( 'migration' ) );
	}

	// ── Map (source record → catalog record) ────────────────────

	public function mapGet( string $kind, string $sourceId ): ?array {
		$db = $this->c->db();
		return $db->selectRow( 'SELECT * FROM ' . $db->table( 'migration' ) . ' WHERE kind = ? AND source_id = ?', array( $kind, Text::cut( $sourceId, 100 ) ) );
	}

	private function mapSet( string $kind, string $sourceId, int $targetId, bool $created, array $extra = array() ): void {
		$this->mapDelete( $kind, $sourceId );
		$this->c->db()->insert( 'migration', array(
			'kind'      => $kind,
			'source_id' => Text::cut( $sourceId, 100 ),
			'target_id' => $targetId,
			'created'   => $created ? 1 : 0,
			'extra'     => $extra ? json_encode( $extra, JSON_UNESCAPED_UNICODE ) : '',
		) );
	}

	private function mapDelete( string $kind, string $sourceId ): void {
		$db = $this->c->db();
		$db->execute( 'DELETE FROM ' . $db->table( 'migration' ) . ' WHERE kind = ? AND source_id = ?', array( $kind, Text::cut( $sourceId, 100 ) ) );
	}

	/** @return array source id => target id */
	private function map( string $kind ): array {
		$db  = $this->c->db();
		$out = array();
		foreach ( $db->select( 'SELECT source_id, target_id FROM ' . $db->table( 'migration' ) . ' WHERE kind = ?', array( $kind ) ) as $r ) {
			$out[ (string) $r['source_id'] ] = (int) $r['target_id'];
		}
		return $out;
	}

	// ── Lock (one batch at a time) ──────────────────────────────

	private function lock(): bool {
		$db    = $this->c->db();
		$table = $db->table( 'settings' );
		$now   = (string) time();
		// Free → taken in one statement, so two requests can never both get it.
		if ( 1 === $db->execute( 'UPDATE ' . $table . ' SET value = ? WHERE name = ? AND value = ?', array( $now, 'migration_lock', '0' ) ) ) {
			return true;
		}
		$held = $db->selectValue( 'SELECT value FROM ' . $table . ' WHERE name = ?', array( 'migration_lock' ) );
		if ( null === $held ) {
			try {
				$db->insert( 'settings', array( 'name' => 'migration_lock', 'value' => $now ) );
				return true;
			} catch ( \Throwable $e ) {
				return false; // another request created it first
			}
		}
		// A lock older than five minutes belongs to a request that died.
		if ( time() - (int) $held >= 300 ) {
			return 1 === $db->execute( 'UPDATE ' . $table . ' SET value = ? WHERE name = ? AND value = ?', array( $now, 'migration_lock', (string) $held ) );
		}
		return false;
	}

	private function unlock(): void {
		$db = $this->c->db();
		$db->execute( 'UPDATE ' . $db->table( 'settings' ) . ' SET value = ? WHERE name = ?', array( '0', 'migration_lock' ) );
	}
}
