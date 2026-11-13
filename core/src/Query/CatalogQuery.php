<?php
namespace DWCatalog\Query;

use DWCatalog\Db\Connection;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Host\Host;
use DWCatalog\Repo\Items;
use DWCatalog\Repo\Settings;
use DWCatalog\Repo\Terms;
use DWCatalog\Search\Tokenizer;
use DWCatalog\Support\Text;

/**
 * Search / filter / sort / paging over the core tables. Every condition is an indexed lookup:
 *
 *   search   each word: item IN (search WHERE token LIKE 'word%' [AND source IN public])
 *   cats     item IN (item_terms WHERE term_id IN (term + descendants))
 *   meta     item IN (values WHERE field_key = ? AND value_idx IN (...))
 *   sort     LEFT JOIN values on (item_id, field_key) — PK lookup per row
 *
 * public=true (visitors): only public fields can be searched, filtered or sorted — internal
 * values cannot be probed from the site.
 *
 * Input caps: MAX_WORDS search words, MAX_VALUES values per filter, MAX_PER_PAGE rows.
 */
class CatalogQuery {

	const MAX_WORDS    = 6;
	const MAX_VALUES   = 50;
	const MAX_PER_PAGE = 100;

	/** Status values that are not "in stock". */
	const NOT_IN_STOCK = array( 'out_of_stock', 'discontinued', 'inactive' );

	/** @var Connection */
	private $db;
	/** @var FieldConfig */
	private $fields;
	/** @var Items */
	private $items;
	/** @var Terms */
	private $terms;
	/** @var Settings */
	private $settings;
	/** @var Host */
	private $host;

	public function __construct( Connection $db, FieldConfig $fields, Items $items, Terms $terms, Settings $settings, Host $host ) {
		$this->db       = $db;
		$this->fields   = $fields;
		$this->items    = $items;
		$this->terms    = $terms;
		$this->settings = $settings;
		$this->host     = $host;
	}

	/**
	 * @param array $f search, cats[], base_cat, meta[key => values[]], sort, public, status
	 *                 ('publish' | 'draft' | 'any'), page, per_page, ids[],
	 *                 shared (admin only: field key — items whose value is used by another item too),
	 *                 empty[] (admin only: field keys that must have no value),
	 *                 review (admin only: flagged for review, or sharing the `shared` field's value),
	 *                 image ('yes' | 'no': has / has no photo)
	 *                 exclude (category refs left out, with sub-categories), in_stock (bool),
	 *                 since ('Y-m-d H:i:s': created at or after)
	 */
	private function defaults( array $f ): array {
		return array_merge( array(
			'search'   => '',
			'cats'     => array(),
			'base_cat' => '',
			'meta'     => array(),
			'sort'     => 'date_desc',
			'public'   => false,
			'status'   => 'publish',
			'page'     => 1,
			'per_page' => 20,
			'ids'      => array(),
			'shared'   => '',
			'empty'    => array(),
			'review'   => false,
			'image'    => '',
			'exclude'  => '',
			'in_stock' => false,
			'since'    => '',
		), $f );
	}

	/** WHERE clause for a filter set. @return array [ ' WHERE …', params ] */
	private function where( string $type, array $f ): array {
		$public  = (bool) $f['public'];
		$t       = array( $this->db, 'table' );

		$where  = array( 'i.type = ?' );
		$params = array( $type );
		if ( 'any' !== $f['status'] ) {
			$where[]  = 'i.status = ?';
			$params[] = in_array( $f['status'], Items::STATUSES, true ) ? $f['status'] : 'publish';
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) $f['ids'] ) ) );
		if ( $ids ) {
			$where[]  = 'i.id IN (?)';
			$params[] = array_slice( $ids, 0, self::MAX_PER_PAGE );
		}

		// Search
		$words = Tokenizer::queryWords( (string) $f['search'], self::MAX_WORDS );
		if ( $words ) {
			$sources = $public ? $this->items->publicSources( $type ) : array();
			foreach ( $words as $w ) {
				$where[]  = 'i.id IN (SELECT item_id FROM ' . $t( 'search' ) . ' WHERE token LIKE ?' . ( $sources ? ' AND source IN (?)' : '' ) . ')';
				$params[] = $w . '%'; // tokens are letters/digits only — no LIKE wildcards inside
				if ( $sources ) {
					$params[] = $sources;
				}
			}
		}

		// Categories (fixed by the page + chosen by the visitor), descendants included
		foreach ( array( $this->terms->resolve( $type, (string) $f['base_cat'] ), array_slice( array_map( 'intval', (array) $f['cats'] ), 0, self::MAX_VALUES ) ) as $termIds ) {
			$termIds = array_filter( $termIds );
			if ( $termIds ) {
				$where[]  = 'i.id IN (SELECT item_id FROM ' . $t( 'item_terms' ) . ' WHERE term_id IN (?))';
				$params[] = $this->terms->withDescendants( $type, $termIds );
			}
		}
		if ( '' !== trim( (string) $f['base_cat'] ) && ! $this->terms->resolve( $type, (string) $f['base_cat'] ) ) {
			$where[] = '1 = 0'; // fixed category does not exist → nothing
		}

		// Field filters
		$allowed = $this->fields->fieldsByKey( $type, $public );
		foreach ( (array) $f['meta'] as $key => $values ) {
			$values = array_slice( array_values( array_filter( array_map( 'strval', (array) $values ), 'strlen' ) ), 0, self::MAX_VALUES );
			if ( isset( $allowed[ $key ] ) && $values ) {
				$where[]  = 'i.id IN (SELECT item_id FROM ' . $t( 'values' ) . ' WHERE field_key = ? AND value_idx IN (?))';
				$params[] = $key;
				$params[] = $values;
			}
		}

		// Values shared by several items of this type (e.g. duplicate item codes) — admin review
		if ( ! $public && '' !== (string) $f['shared'] && isset( $allowed[ $f['shared'] ] ) ) {
			$sharedSql = 'i.id IN (SELECT v.item_id FROM ' . $t( 'values' ) . ' v WHERE v.field_key = ? AND v.value_idx IN ('
				. 'SELECT d.value_idx FROM ' . $t( 'values' ) . ' d INNER JOIN ' . $t( 'items' ) . ' di ON di.id = d.item_id AND di.type = ?'
				. ' WHERE d.field_key = ? GROUP BY d.value_idx HAVING COUNT(*) > 1))';
			$where[]   = $f['review'] ? "(i.review <> '' OR $sharedSql)" : $sharedSql;
			$params[]  = $f['shared'];
			$params[]  = $type;
			$params[]  = $f['shared'];
		} elseif ( ! $public && $f['review'] ) {
			$where[] = "i.review <> ''";
		}
		if ( ! $public ) {
			foreach ( array_slice( (array) $f['empty'], 0, 5 ) as $key ) {
				if ( isset( $allowed[ $key ] ) ) {
					$where[]  = 'i.id NOT IN (SELECT item_id FROM ' . $t( 'values' ) . ' WHERE field_key = ?)';
					$params[] = $key;
				}
			}
		}
		if ( 'yes' === $f['image'] || 'no' === $f['image'] ) {
			$where[] = 'i.id ' . ( 'no' === $f['image'] ? 'NOT ' : '' ) . 'IN (SELECT item_id FROM ' . $t( 'images' ) . ')';
		}

		// Categories left out of the page (with their sub-categories)
		$out = $this->terms->resolve( $type, (string) $f['exclude'] );
		if ( $out ) {
			$where[]  = 'i.id NOT IN (SELECT item_id FROM ' . $t( 'item_terms' ) . ' WHERE term_id IN (?))';
			$params[] = $this->terms->withDescendants( $type, $out );
		}
		// In stock: the status field does not say out of stock / discontinued / inactive
		$status = $this->fields->roleField( $type, 'status', $public );
		if ( $f['in_stock'] && $status ) {
			$where[]  = 'i.id NOT IN (SELECT item_id FROM ' . $t( 'values' ) . ' WHERE field_key = ? AND value_idx IN (?))';
			$params[] = $status['meta_key'];
			$params[] = self::NOT_IN_STOCK;
		}
		// New: created since a moment (visitors: "last 30 days")
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $f['since'] ) ) {
			$where[]  = 'i.created_at >= ?';
			$params[] = $f['since'];
		}

		return array( ' WHERE ' . implode( ' AND ', $where ), $params );
	}

	/** @return array rows, total, pages, page */
	public function run( string $type, array $f ): array {
		$f       = $this->defaults( $f );
		$public  = (bool) $f['public'];
		$perPage = max( 1, min( self::MAX_PER_PAGE, (int) $f['per_page'] ) );
		$page    = max( 1, (int) $f['page'] );
		$t       = array( $this->db, 'table' );
		$ids     = array_values( array_filter( array_map( 'intval', (array) $f['ids'] ) ) );
		list( $whereSql, $params ) = $this->where( $type, $f );

		$total    = (int) $this->db->selectValue( 'SELECT COUNT(*) FROM ' . $t( 'items' ) . ' i' . $whereSql, $params );

		// Sort
		$sort       = $this->resolveSort( $type, (string) $f['sort'], $public );
		$join       = '';
		$joinParams = array();
		if ( $ids && 'ids' === $f['sort'] ) {
			$case = 'CASE i.id';
			foreach ( $ids as $pos => $id ) {
				$case .= ' WHEN ' . (int) $id . ' THEN ' . (int) $pos;
			}
			$order = $case . ' END';
		} elseif ( 'manual' === $sort['special'] ) {
			$order = 'i.position ASC, i.sort_title ASC, i.id ASC';
		} elseif ( 'category' === $sort['special'] ) {
			// First category of each item by its place in the tree, then by name
			$order = 'COALESCE((SELECT MIN(ct.position * 1000000 + ct.id) FROM ' . $t( 'item_terms' ) . ' cit INNER JOIN ' . $t( 'terms' ) . " ct ON ct.id = cit.term_id AND ct.taxonomy = 'category' WHERE cit.item_id = i.id), 999999999999) ASC, i.sort_title ASC, i.id ASC";
		} elseif ( $sort['field'] ) {
			$join         = ' LEFT JOIN ' . $t( 'values' ) . ' sv ON sv.item_id = i.id AND sv.field_key = ?';
			$joinParams[] = $sort['field'];
			$col          = $sort['numeric'] ? 'sv.value_num' : 'sv.value_idx';
			$order        = "($col IS NULL) ASC, $col {$sort['dir']}, i.sort_title ASC";
		} else {
			$order = 'i.' . $sort['column'] . ' ' . $sort['dir'] . ', i.id ' . $sort['dir'];
		}

		$rows = $this->db->select(
			'SELECT i.* FROM ' . $t( 'items' ) . ' i' . $join . $whereSql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
			array_merge( $joinParams, $params, array( $perPage, ( $page - 1 ) * $perPage ) )
		);
		$this->items->primeRows( $rows );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $perPage ),
			'page'  => $page,
		);
	}

	/** @return array key => label (number fields get low/high entries; public ones on the site) */
	public function sortOptions( string $type, bool $public = true ): array {
		$tr = array( $this->host, 'translate' );
		$o  = array(
			'date_desc'  => $tr( 'Newest' ),
			'date_asc'   => $tr( 'Oldest' ),
			'title_asc'  => $tr( 'Name: A → Z' ),
			'title_desc' => $tr( 'Name: Z → A' ),
		);
		$code = $this->fields->roleField( $type, 'code', $public );
		if ( $code ) {
			$o['code_asc']  = sprintf( $tr( '%s: low → high' ), $code['label'] );
			$o['code_desc'] = sprintf( $tr( '%s: high → low' ), $code['label'] );
		}
		$brand = $this->fields->roleField( $type, 'brand', $public );
		if ( $brand ) {
			$o['brand_asc'] = $brand['label'];
		}
		$config = $this->fields->type( $type );
		if ( ! empty( $config['has_category'] ) ) {
			$o['category_asc'] = $tr( 'Category' );
		}
		$o['manual'] = $tr( 'Recommended order' );
		foreach ( $this->fields->fieldsByKey( $type, $public ) as $key => $field ) {
			if ( 'number' === $field['type'] ) {
				$o[ "field:$key:asc" ]  = $field['label'] . ': ' . $tr( 'Low → High' );
				$o[ "field:$key:desc" ] = $field['label'] . ': ' . $tr( 'High → Low' );
			}
		}
		return $o;
	}

	/** @return array column|field|special, dir, numeric */
	public function resolveSort( string $type, string $sort, bool $public ): array {
		$none = array( 'column' => '', 'field' => '', 'special' => '', 'dir' => 'ASC', 'numeric' => false );
		if ( preg_match( '/^field:([a-z0-9_]+):(asc|desc)$/', $sort, $m ) ) {
			$allowed = $this->fields->fieldsByKey( $type, $public );
			if ( isset( $allowed[ $m[1] ] ) ) {
				return array( 'field' => $m[1], 'dir' => strtoupper( $m[2] ), 'numeric' => 'number' === $allowed[ $m[1] ]['type'] ) + $none;
			}
		}
		if ( preg_match( '/^(code|brand)_(asc|desc)$/', $sort, $m ) ) {
			$f = $this->fields->roleField( $type, $m[1], $public );
			if ( $f ) {
				return array( 'field' => $f['meta_key'], 'dir' => strtoupper( $m[2] ), 'numeric' => 'number' === $f['type'] ) + $none;
			}
		}
		if ( 'category_asc' === $sort ) {
			return array( 'special' => 'category' ) + $none;
		}
		if ( 'manual' === $sort ) {
			return array( 'special' => 'manual' ) + $none;
		}
		$map = array(
			'date_desc'     => array( 'created_at', 'DESC' ),
			'date_asc'      => array( 'created_at', 'ASC' ),
			'title_asc'     => array( 'sort_title', 'ASC' ),
			'title_desc'    => array( 'sort_title', 'DESC' ),
			'modified_desc' => array( 'updated_at', 'DESC' ),
			'modified_asc'  => array( 'updated_at', 'ASC' ),
		);
		$pick = isset( $map[ $sort ] ) ? $map[ $sort ] : $map['date_desc'];
		return array( 'column' => $pick[0], 'dir' => $pick[1] ) + $none;
	}

	/**
	 * Counts for the filter panel, each computed with every OTHER filter applied (so a count is what
	 * the visitor gets by ticking it). A constant number of queries, whatever the catalog size;
	 * cached until the next catalog write.
	 *
	 * @param array $facets list of: 'category' | 'flag:in_stock' | 'flag:new' | 'flag:photo' | field key
	 * @return array facet => [ value => count ] (flags: [ 1 => count ]; category: [ term id => count ])
	 */
	public function facets( string $type, array $f, array $facets ): array {
		$f   = $this->defaults( $f );
		$key = 'dwcat_fa_' . md5( $type . '|' . json_encode( array( $f, $facets ) ) . '|' . $this->settings->cacheVersion() );
		return $this->host->remember( $key, 43200, function () use ( $type, $f, $facets ) {
			$t   = array( $this->db, 'table' );
			$out = array();
			foreach ( $facets as $facet ) {
				$g = $f;
				if ( 'category' === $facet ) {
					$g['cats'] = array();
					list( $w, $p ) = $this->where( $type, $g );
					$rows = $this->db->select( 'SELECT it.term_id AS k, COUNT(DISTINCT i.id) AS n FROM ' . $t( 'items' ) . ' i INNER JOIN ' . $t( 'item_terms' ) . ' it ON it.item_id = i.id' . $w . ' GROUP BY it.term_id', $p );
				} elseif ( 0 === strpos( $facet, 'flag:' ) ) {
					$flag = substr( $facet, 5 );
					if ( 'in_stock' === $flag ) {
						$g['in_stock'] = true;
					} elseif ( 'new' === $flag ) {
						$g['since'] = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
					} else {
						$g['image'] = 'yes';
					}
					list( $w, $p ) = $this->where( $type, $g );
					$rows = array( array( 'k' => 1, 'n' => $this->db->selectValue( 'SELECT COUNT(*) FROM ' . $t( 'items' ) . ' i' . $w, $p ) ) );
				} else {
					unset( $g['meta'][ $facet ] );
					list( $w, $p ) = $this->where( $type, $g );
					$rows = $this->db->select( 'SELECT v.value_idx AS k, COUNT(DISTINCT i.id) AS n FROM ' . $t( 'items' ) . ' i INNER JOIN ' . $t( 'values' ) . ' v ON v.item_id = i.id AND v.field_key = ? AND v.value_idx <> ?' . $w . ' GROUP BY v.value_idx ORDER BY v.value_idx ASC LIMIT 300', array_merge( array( $facet, '' ), $p ) );
				}
				$out[ $facet ] = array();
				foreach ( $rows as $r ) {
					if ( (int) $r['n'] > 0 ) {
						$out[ $facet ][ (string) $r['k'] ] = (int) $r['n'];
					}
				}
			}
			return $out;
		} );
	}

	/**
	 * Filter choices: select options, or distinct stored values of published items (within the
	 * fixed category). Cached until the next catalog write. Pass only fields you may expose.
	 */
	public function filterChoices( string $type, array $field, string $baseCat = '' ): array {
		if ( 'select' === $field['type'] ) {
			return Text::parseOptions( (string) $field['options'] );
		}
		$key = 'dwcat_fc_' . md5( $type . '|' . $field['meta_key'] . '|' . $baseCat . '|' . $this->settings->cacheVersion() );
		return $this->host->remember( $key, 43200, function () use ( $type, $field, $baseCat ) {
			$t      = array( $this->db, 'table' );
			$sql    = 'SELECT DISTINCT v.value_idx FROM ' . $t( 'values' ) . ' v INNER JOIN ' . $t( 'items' ) . ' i ON i.id = v.item_id AND i.type = ? AND i.status = ?';
			$params = array( $type, 'publish' );
			$terms  = $this->terms->resolve( $type, $baseCat );
			if ( '' !== trim( $baseCat ) ) {
				if ( ! $terms ) {
					return array();
				}
				$sql     .= ' AND i.id IN (SELECT item_id FROM ' . $t( 'item_terms' ) . ' WHERE term_id IN (?))';
				$params[] = $this->terms->withDescendants( $type, $terms );
			}
			$sql     .= ' WHERE v.field_key = ? AND v.value_idx <> ? ORDER BY v.value_idx ASC LIMIT 200';
			$params[] = $field['meta_key'];
			$params[] = '';
			$values   = $this->db->selectColumn( $sql, $params );
			return $values ? array_combine( $values, $values ) : array();
		} );
	}
}
