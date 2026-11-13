<?php
namespace DWCatalog\Repo;

use DWCatalog\Db\Connection;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Fields\VariationColumns;
use DWCatalog\Search\Tokenizer;
use DWCatalog\Support\Text;

/**
 * Items with their field values, variations and search index.
 *
 * Item row: id, type, slug, title, content, excerpt, status (publish|draft), author_id,
 *           created_at, updated_at.
 */
class Items {

	const STATUSES   = array( 'publish', 'draft' );
	const MAX_TOKENS = 600;

	/** @var Connection */
	private $db;
	/** @var Settings */
	private $settings;
	/** @var FieldConfig */
	private $fields;

	/** Request caches */
	private $items      = array();
	private $values     = array();
	private $variations = array();

	/** While > 0, reindex() is deferred to the end of the batch (one save = one reindex). */
	private $batch   = 0;
	private $pending = array();

	public function __construct( Connection $db, Settings $settings, FieldConfig $fields ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->fields   = $fields;
	}

	// ── Items ───────────────────────────────────────────────────

	public function get( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->items ) ) {
			$this->items[ $id ] = $this->db->selectRow( 'SELECT * FROM ' . $this->db->table( 'items' ) . ' WHERE id = ?', array( $id ) );
		}
		return $this->items[ $id ];
	}

	public function getBySlug( string $type, string $slug, bool $publishedOnly = true ): ?array {
		$sql  = 'SELECT * FROM ' . $this->db->table( 'items' ) . ' WHERE type = ? AND slug = ?';
		$args = array( $type, $slug );
		if ( $publishedOnly ) {
			$sql   .= ' AND status = ?';
			$args[] = 'publish';
		}
		$row = $this->db->selectRow( $sql, $args );
		if ( $row ) {
			$this->items[ (int) $row['id'] ] = $row;
		}
		return $row;
	}

	/** Remember rows already fetched by a list query, and load their values in one query. */
	public function primeRows( array $rows ): void {
		$ids = array();
		foreach ( $rows as $row ) {
			$this->items[ (int) $row['id'] ] = $row;
			$ids[]                           = (int) $row['id'];
		}
		$this->primeValues( $ids );
	}

	/**
	 * Create or update an item. @return int id
	 *
	 * @param array $data id?, type, title, slug?, content?, excerpt?, status?, author_id?
	 */
	public function save( array $data ): int {
		$id   = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$type = (string) $data['type'];
		if ( ! $this->fields->type( $type ) ) {
			throw new \InvalidArgumentException( 'Unknown catalog type.' );
		}
		$title = Text::line( isset( $data['title'] ) ? $data['title'] : '', 255 );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Title is required.' );
		}
		$slug   = Text::slug( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) ? $data['slug'] : $title );
		$slug   = $this->uniqueSlug( $type, '' !== $slug ? $slug : 'item', $id );
		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'draft';
		$row    = array(
			'type'       => $type,
			'slug'       => $slug,
			'title'      => $title,
			'sort_title' => Text::sortKey( $title ),
			'content'    => isset( $data['content'] ) ? (string) $data['content'] : '',
			'excerpt'    => Text::block( isset( $data['excerpt'] ) ? $data['excerpt'] : '', 1000 ),
			'status'     => $status,
			'updated_at' => Text::now(),
		);

		if ( $id && $this->get( $id ) ) {
			$sets = array();
			foreach ( array_keys( $row ) as $col ) {
				$sets[] = $this->db->dialect()->quoteIdent( $col ) . ' = ?';
			}
			$this->db->execute( 'UPDATE ' . $this->db->table( 'items' ) . ' SET ' . implode( ', ', $sets ) . ' WHERE id = ?', array_merge( array_values( $row ), array( $id ) ) );
		} else {
			$row['author_id']  = isset( $data['author_id'] ) ? (int) $data['author_id'] : 0;
			$row['created_at'] = $row['updated_at'];
			$id                = $this->db->insert( 'items', $row );
		}
		unset( $this->items[ $id ] );
		$this->reindex( $id );
		return $id;
	}

	/** Set the status of many items at once (bulk action). */
	public function setStatus( array $ids, string $status ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$this->db->execute( 'UPDATE ' . $this->db->table( 'items' ) . ' SET status = ?, updated_at = ? WHERE id IN (?)', array( $status, Text::now(), $ids ) );
		foreach ( $ids as $id ) {
			unset( $this->items[ $id ] );
		}
		$this->settings->bumpCacheVersion();
	}

	/** Set one field of many items (bulk action). The value is already cleaned by the caller. */
	public function setValue( array $ids, string $key, string $value ): void {
		$this->beginBatch();
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			if ( ! $this->get( $id ) ) {
				continue;
			}
			$values         = $this->values( $id );
			$values[ $key ] = $value;
			$this->saveValues( $id, $values );
		}
		$this->endBatch();
	}

	/** Mark items for review ('' clears the mark). */
	public function setReview( array $ids, string $reason ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$this->db->execute( 'UPDATE ' . $this->db->table( 'items' ) . ' SET review = ? WHERE id IN (?)', array( Text::key( $reason, 40 ), $ids ) );
		foreach ( $ids as $id ) {
			unset( $this->items[ $id ] );
		}
	}

	public function delete( int $id ): void {
		foreach ( array( 'values', 'variations', 'search', 'item_terms' ) as $t ) {
			$this->db->execute( 'DELETE FROM ' . $this->db->table( $t ) . ' WHERE item_id = ?', array( $id ) );
		}
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'items' ) . ' WHERE id = ?', array( $id ) );
		unset( $this->items[ $id ], $this->values[ $id ], $this->variations[ $id ] );
		$this->settings->bumpCacheVersion();
	}

	private function uniqueSlug( string $type, string $slug, int $selfId ): string {
		$base = $slug;
		for ( $n = 2; $n < 1000; $n++ ) {
			$taken = $this->db->selectValue( 'SELECT id FROM ' . $this->db->table( 'items' ) . ' WHERE type = ? AND slug = ? AND id <> ?', array( $type, $slug, $selfId ) );
			if ( ! $taken ) {
				return $slug;
			}
			$slug = Text::cut( $base, 180 ) . '-' . $n;
		}
		return $base . '-' . bin2hex( random_bytes( 3 ) );
	}

	// ── Field values ────────────────────────────────────────────

	/** @return array field_key => value */
	public function values( int $id ): array {
		if ( ! isset( $this->values[ $id ] ) ) {
			$this->primeValues( array( $id ) );
		}
		return $this->values[ $id ];
	}

	public function value( int $id, string $key, string $default = '' ): string {
		$values = $this->values( $id );
		return isset( $values[ $key ] ) && '' !== $values[ $key ] ? (string) $values[ $key ] : $default;
	}

	/** Load values for many items in one query (list pages: no N+1). */
	public function primeValues( array $ids ): void {
		$ids = array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), array_keys( $this->values ), array( 0 ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			$this->values[ $id ] = array();
		}
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			foreach ( $this->db->select( 'SELECT item_id, field_key, value FROM ' . $this->db->table( 'values' ) . ' WHERE item_id IN (?)', array( $chunk ) ) as $r ) {
				$this->values[ (int) $r['item_id'] ][ $r['field_key'] ] = (string) $r['value'];
			}
		}
	}

	/** Replace all field values of an item (already sanitized by the caller). */
	public function saveValues( int $id, array $values ): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'values' ) . ' WHERE item_id = ?', array( $id ) );
		$clean = array();
		$rows  = array();
		foreach ( $values as $key => $value ) {
			$key   = Text::key( $key );
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$clean[ $key ] = $value;
			$rows[]        = array(
				'item_id'   => $id,
				'field_key' => $key,
				'value'     => $value,
				'value_idx' => Text::cut( $value, 191 ),
				'value_num' => is_numeric( $value ) && abs( (float) $value ) < 1e15 ? (string) $value : null,
			);
		}
		$this->db->insertMany( 'values', array( 'item_id', 'field_key', 'value', 'value_idx', 'value_num' ), $rows );
		$this->values[ $id ] = $clean;
		$this->reindex( $id );
	}

	// ── Variations ──────────────────────────────────────────────

	public function variations( int $id ): array {
		if ( ! isset( $this->variations[ $id ] ) ) {
			$rows = array();
			foreach ( $this->db->selectColumn( 'SELECT data FROM ' . $this->db->table( 'variations' ) . ' WHERE item_id = ? ORDER BY position ASC', array( $id ) ) as $json ) {
				$row = json_decode( (string) $json, true );
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$this->variations[ $id ] = $rows;
		}
		return $this->variations[ $id ];
	}

	/** Replace variation rows (already sanitized against the columns). */
	public function saveVariations( int $id, array $rows ): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'variations' ) . ' WHERE item_id = ?', array( $id ) );
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		foreach ( $rows as $pos => $row ) {
			$code  = '';
			$price = null;
			foreach ( array( 'item_code', 'sku' ) as $k ) {
				if ( '' === $code && ! empty( $row[ $k ] ) ) {
					$code = Text::cut( (string) $row[ $k ], 100 );
				}
			}
			foreach ( array( 'price', 'wholesale', 'case_price' ) as $k ) {
				if ( null === $price && isset( $row[ $k ] ) && is_numeric( $row[ $k ] ) ) {
					$price = (string) $row[ $k ];
				}
			}
			$this->db->insert( 'variations', array(
				'item_id'   => $id,
				'position'  => $pos,
				'item_code' => $code,
				'price'     => $price,
				'data'      => json_encode( $row, JSON_UNESCAPED_UNICODE ),
			) );
		}
		$this->variations[ $id ] = $rows;
		$this->reindex( $id );
	}

	// ── Search index ────────────────────────────────────────────

	public function beginBatch(): void {
		$this->batch++;
	}

	public function endBatch(): void {
		$this->batch = max( 0, $this->batch - 1 );
		if ( 0 === $this->batch ) {
			$ids           = array_keys( $this->pending );
			$this->pending = array();
			foreach ( $ids as $id ) {
				$this->reindex( $id );
			}
		}
	}

	/**
	 * Rebuild an item's tokens. Each token records its source (field key, '_title', '_content',
	 * '_var_{column}') so visitor searches can be limited to public sources at query time.
	 */
	public function reindex( int $id ): void {
		if ( $this->batch > 0 ) {
			$this->pending[ $id ] = true;
			return;
		}
		$item = $this->get( $id );
		if ( ! $item ) {
			return;
		}
		$sources = array(
			'_title'   => $item['title'],
			'_content' => Text::cut( Text::summary( (string) $item['content'], 5000 ), 5000 ),
		);
		foreach ( $this->values( $id ) as $key => $value ) {
			$sources[ $key ] = $value;
		}
		foreach ( $this->variations( $id ) as $row ) {
			foreach ( $row as $col => $value ) {
				$k             = '_var_' . Text::key( $col );
				$sources[ $k ] = ( isset( $sources[ $k ] ) ? $sources[ $k ] . ' ' : '' ) . ( is_scalar( $value ) ? (string) $value : '' );
			}
		}

		$rows = array();
		foreach ( $sources as $source => $text ) {
			foreach ( Tokenizer::tokenize( (string) $text, true ) as $t ) {
				$rows[] = array( 'token' => $t, 'item_id' => $id, 'source' => Text::cut( $source, 70 ) );
				if ( count( $rows ) >= self::MAX_TOKENS ) {
					break 2;
				}
			}
		}
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'search' ) . ' WHERE item_id = ?', array( $id ) );
		$this->db->insertMany( 'search', array( 'token', 'item_id', 'source' ), $rows );
		$this->settings->bumpCacheVersion();
	}

	/** Public search sources for a type: title, content, public fields, public variation columns. */
	public function publicSources( string $type ): array {
		$sources = array( '_title', '_content' );
		foreach ( $this->fields->fieldsByKey( $type, true ) as $key => $f ) {
			$sources[] = $key;
		}
		foreach ( $this->fields->variationColumns( $type ) as $c ) {
			if ( VariationColumns::isPublic( $c ) ) {
				$sources[] = '_var_' . $c['key'];
			}
		}
		return $sources;
	}
}
