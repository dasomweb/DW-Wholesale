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
	 * @return array rows, total, pages, page
	 */
	public function run( string $type, array $f ): array {
		$f = array_merge( array(
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
		), $f );

		$public  = (bool) $f['public'];
		$perPage = max( 1, min( self::MAX_PER_PAGE, (int) $f['per_page'] ) );
		$page    = max( 1, (int) $f['page'] );
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

		$whereSql = ' WHERE ' . implode( ' AND ', $where );
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
		$o = array(
			'date_desc'  => $this->host->translate( 'Newest' ),
			'date_asc'   => $this->host->translate( 'Oldest' ),
			'title_asc'  => $this->host->translate( 'Name: A → Z' ),
			'title_desc' => $this->host->translate( 'Name: Z → A' ),
		);
		foreach ( $this->fields->fieldsByKey( $type, $public ) as $key => $field ) {
			if ( 'number' === $field['type'] ) {
				$o[ "field:$key:asc" ]  = $field['label'] . ': ' . $this->host->translate( 'Low → High' );
				$o[ "field:$key:desc" ] = $field['label'] . ': ' . $this->host->translate( 'High → Low' );
			}
		}
		return $o;
	}

	/** @return array column|field, dir, numeric */
	public function resolveSort( string $type, string $sort, bool $public ): array {
		if ( preg_match( '/^field:([a-z0-9_]+):(asc|desc)$/', $sort, $m ) ) {
			$allowed = $this->fields->fieldsByKey( $type, $public );
			if ( isset( $allowed[ $m[1] ] ) ) {
				return array( 'column' => '', 'field' => $m[1], 'dir' => strtoupper( $m[2] ), 'numeric' => 'number' === $allowed[ $m[1] ]['type'] );
			}
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
		return array( 'column' => $pick[0], 'field' => '', 'dir' => $pick[1], 'numeric' => false );
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
