<?php
namespace DWCatalog\Admin;

use DWCatalog\Catalog;

/**
 * Numbers and lookups for the admin screens: status counts, shared item codes,
 * distinct values (brand suggestions). Admin only — every value here may be internal.
 */
class ItemStats {

	/** @var Catalog */
	private $c;

	public function __construct( Catalog $c ) {
		$this->c = $c;
	}

	/** @return array total, publish, draft */
	public function counts( string $type ): array {
		$out = array( 'total' => 0, 'publish' => 0, 'draft' => 0 );
		foreach ( $this->c->db()->select( 'SELECT status, COUNT(*) AS n FROM ' . $this->c->db()->table( 'items' ) . ' WHERE type = ? GROUP BY status', array( $type ) ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
			$out['total']       += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Tab counts of the product list, and the dashboard's data-quality numbers.
	 *
	 * @return array total, active, no_status, out_of_stock, hidden, review, no_brand, no_image,
	 *               empty_categories, duplicate_codes
	 */
	public function quality( string $type ): array {
		$db     = $this->c->db();
		$fields = $this->c->fields();
		$items  = $db->table( 'items' );
		$values = $db->table( 'values' );
		$n      = function ( string $sql, array $params ) use ( $db ) {
			return (int) $db->selectValue( $sql, $params );
		};
		$total  = $n( "SELECT COUNT(*) FROM $items WHERE type = ?", array( $type ) );
		$with   = function ( string $key, string $value = '' ) use ( $n, $items, $values, $type ) {
			$sql    = "SELECT COUNT(*) FROM $items i INNER JOIN $values v ON v.item_id = i.id AND v.field_key = ? WHERE i.type = ?";
			$params = array( $key, $type );
			if ( '' !== $value ) {
				$sql     .= ' AND v.value_idx = ?';
				$params[] = $value;
			}
			return $n( $sql, $params );
		};
		$status = $fields->roleField( $type, 'status', false );
		$brand  = $fields->roleField( $type, 'brand', false );
		$code   = $fields->roleField( $type, 'code', false );
		$shared = $code ? $this->shared( $type, $code['meta_key'] ) : array();
		$review = $n( "SELECT COUNT(*) FROM $items WHERE type = ? AND review <> ''", array( $type ) );
		if ( $shared ) {
			$review = $n(
				"SELECT COUNT(*) FROM $items i WHERE i.type = ? AND (i.review <> '' OR i.id IN (SELECT v.item_id FROM $values v WHERE v.field_key = ? AND v.value_idx IN (?)))",
				array( $type, $code['meta_key'], array_map( 'strval', array_keys( $shared ) ) )
			);
		}
		$used  = $db->selectColumn( 'SELECT DISTINCT it.term_id FROM ' . $db->table( 'item_terms' ) . ' it' );
		$empty = 0;
		$tree  = $this->c->terms()->tree( $type );
		foreach ( $this->c->terms()->all( $type ) as $t ) {
			if ( ! in_array( $t['id'], $used ) && empty( $tree[ (int) $t['id'] ] ) ) { // phpcs:ignore WordPress.PHP.StrictInArray — ids arrive as strings or ints by driver
				$empty++;
			}
		}
		return array(
			'total'            => $total,
			'active'           => $status ? $with( $status['meta_key'], 'active' ) : 0,
			'out_of_stock'     => $status ? $with( $status['meta_key'], 'out_of_stock' ) : 0,
			'no_status'        => $status ? $total - $with( $status['meta_key'] ) : 0,
			'hidden'           => $n( "SELECT COUNT(*) FROM $items WHERE type = ? AND status = ?", array( $type, 'draft' ) ),
			'review'           => $review,
			'no_brand'         => $brand ? $total - $with( $brand['meta_key'] ) : 0,
			'no_image'         => $total - $n( "SELECT COUNT(*) FROM $items i WHERE i.type = ? AND i.id IN (SELECT item_id FROM " . $db->table( 'images' ) . ')', array( $type ) ),
			'empty_categories' => $empty,
			'duplicate_codes'  => count( $shared ),
			'has_status'       => (bool) $status,
		);
	}

	/** @return array top, sub — category counts */
	public function categoryCounts( string $type ): array {
		$top = 0;
		$sub = 0;
		foreach ( $this->c->terms()->all( $type ) as $t ) {
			(int) $t['parent'] ? $sub++ : $top++;
		}
		return array( 'top' => $top, 'sub' => $sub );
	}

	/** Values of $key used by more than one item. @return array value => item count */
	public function shared( string $type, string $key ): array {
		$db  = $this->c->db();
		$out = array();
		$sql = 'SELECT v.value_idx, COUNT(*) AS n FROM ' . $db->table( 'values' ) . ' v'
			. ' INNER JOIN ' . $db->table( 'items' ) . ' i ON i.id = v.item_id AND i.type = ?'
			. ' WHERE v.field_key = ? GROUP BY v.value_idx HAVING COUNT(*) > 1';
		foreach ( $db->select( $sql, array( $type, $key ) ) as $r ) {
			$out[ (string) $r['value_idx'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Other items using the same value. @return array rows id, title */
	public function usedBy( string $type, string $key, string $value, int $exceptId, int $limit = 5 ): array {
		if ( '' === $value ) {
			return array();
		}
		$db = $this->c->db();
		return $db->select(
			'SELECT i.id, i.title FROM ' . $db->table( 'values' ) . ' v INNER JOIN ' . $db->table( 'items' ) . ' i ON i.id = v.item_id AND i.type = ?'
			. ' WHERE v.field_key = ? AND v.value_idx = ? AND v.item_id <> ? ORDER BY i.id LIMIT ?',
			array( $type, $key, $value, $exceptId, $limit )
		);
	}

	/** Distinct non-empty values of a field, alphabetical. @return string[] */
	public function distinct( string $type, string $key, int $limit = 200 ): array {
		$db = $this->c->db();
		return array_map( 'strval', $db->selectColumn(
			'SELECT DISTINCT v.value_idx FROM ' . $db->table( 'values' ) . ' v INNER JOIN ' . $db->table( 'items' ) . ' i ON i.id = v.item_id AND i.type = ?'
			. ' WHERE v.field_key = ? ORDER BY v.value_idx LIMIT ?',
			array( $type, $key, $limit )
		) );
	}
}
