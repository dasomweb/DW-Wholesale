<?php
namespace DWCatalog\Db;

/**
 * Table definitions (database-neutral) and installer.
 *
 * Column types: id (auto-increment PK) · bigint · int · smallint · string:N · text · decimal · datetime.
 * Index columns may carry a MySQL prefix length, e.g. "slug(150)" — composite keys stay under
 * 767 bytes on old InnoDB row formats; PostgreSQL ignores the length.
 */
class Schema {

	const VERSION = 4;

	/** Tables whose primary key is an auto-increment "id". */
	const AUTO_ID = array( 'items', 'terms', 'images', 'variations', 'applications' );

	public static function hasAutoId( string $table ): bool {
		return in_array( $table, self::AUTO_ID, true );
	}

	public static function tables(): array {
		return array(
			'settings'   => array(
				'columns' => array(
					'name'  => array( 'string:100' ),
					'value' => array( 'text' ),
				),
				'primary' => array( 'name' ),
			),
			'items'      => array(
				'columns' => array(
					'id'         => array( 'id' ),
					'type'       => array( 'string:20' ),
					'slug'       => array( 'string:200' ),
					'title'      => array( 'string:255' ),
					'sort_title' => array( 'string:191' ),
					'content'    => array( 'text' ),
					'excerpt'    => array( 'text' ),
					'status'     => array( 'string:20', 'default' => "'draft'" ),
					'review'     => array( 'string:40', 'default' => "''" ),
					'position'   => array( 'int' ),
					'author_id'  => array( 'bigint' ),
					'created_at' => array( 'datetime' ),
					'updated_at' => array( 'datetime' ),
				),
				'primary' => array( 'id' ),
				'unique'  => array( 'type_slug' => array( 'type', 'slug(150)' ) ),
				'index'   => array(
					'type_status_created' => array( 'type', 'status', 'created_at' ),
					'type_status_title'   => array( 'type', 'status', 'title(100)' ),
					'type_status_sort'    => array( 'type', 'status', 'sort_title(100)' ),
					'type_review'         => array( 'type', 'review' ),
				),
			),
			'terms'      => array(
				'columns' => array(
					'id'       => array( 'id' ),
					'type'     => array( 'string:20' ),
					'taxonomy' => array( 'string:20' ),
					'parent'   => array( 'bigint' ),
					'name'     => array( 'string:200' ),
					'slug'     => array( 'string:200' ),
					'position' => array( 'int' ),
				),
				'primary' => array( 'id' ),
				'unique'  => array( 'type_tax_slug' => array( 'type', 'taxonomy', 'slug(150)' ) ),
				'index'   => array( 'type_tax_parent' => array( 'type', 'taxonomy', 'parent' ) ),
			),
			'item_terms' => array(
				'columns' => array(
					'item_id' => array( 'bigint' ),
					'term_id' => array( 'bigint' ),
				),
				'primary' => array( 'item_id', 'term_id' ),
				'index'   => array( 'term_item' => array( 'term_id', 'item_id' ) ),
			),
			'images'     => array(
				'columns' => array(
					'id'         => array( 'id' ),
					'item_id'    => array( 'bigint' ),
					'position'   => array( 'smallint' ),
					'file'       => array( 'string:255' ),
					'mime'       => array( 'string:50' ),
					'width'      => array( 'int' ),
					'height'     => array( 'int' ),
					'sizes'      => array( 'text' ),
					'alt'        => array( 'string:255' ),
					'created_at' => array( 'datetime' ),
				),
				'primary' => array( 'id' ),
				'index'   => array( 'item_position' => array( 'item_id', 'position' ) ),
			),
			'values'     => array(
				'columns' => array(
					'item_id'   => array( 'bigint' ),
					'field_key' => array( 'string:64' ),
					'value'     => array( 'text' ),
					'value_idx' => array( 'string:191' ),
					'value_num' => array( 'decimal', 'null' => true ),
				),
				'primary' => array( 'item_id', 'field_key' ),
				'index'   => array(
					'field_text' => array( 'field_key(40)', 'value_idx(140)', 'item_id' ),
					'field_num'  => array( 'field_key(40)', 'value_num', 'item_id' ),
				),
			),
			'variations' => array(
				'columns' => array(
					'id'        => array( 'id' ),
					'item_id'   => array( 'bigint' ),
					'position'  => array( 'smallint' ),
					'item_code' => array( 'string:100' ),
					'price'     => array( 'decimal', 'null' => true ),
					'data'      => array( 'text' ),
				),
				'primary' => array( 'id' ),
				'index'   => array(
					'item_position' => array( 'item_id', 'position' ),
					'item_code'     => array( 'item_code' ),
				),
			),
			'search'     => array(
				'columns' => array(
					'token'   => array( 'string:64' ),
					'item_id' => array( 'bigint' ),
					'source'  => array( 'string:70' ),
				),
				'primary' => array( 'token', 'item_id', 'source' ),
				'index'   => array( 'item' => array( 'item_id' ) ),
			),
			// What a migration created: source record → catalog record (re-runs update, rollback deletes).
			'migration'  => array(
				'columns' => array(
					'kind'      => array( 'string:20' ),
					'source_id' => array( 'string:100' ),
					'target_id' => array( 'bigint' ),
					'created'   => array( 'smallint' ),
					'extra'     => array( 'text' ),
				),
				'primary' => array( 'kind', 'source_id' ),
				'index'   => array( 'kind_target' => array( 'kind', 'target_id' ) ),
			),
			// Wholesale applications (form entries). Attachments live in the host's private directory.
			'applications' => array(
				'columns' => array(
					'id'         => array( 'id' ),
					'status'     => array( 'string:20', 'default' => "'new'" ),
					'email'      => array( 'string:191' ),
					'company'    => array( 'string:200' ),
					'name'       => array( 'string:200' ),
					'phone'      => array( 'string:50' ),
					'data'       => array( 'text' ),
					'files'      => array( 'text' ),
					'note'       => array( 'text' ),
					'source'     => array( 'string:40' ),
					'created_at' => array( 'datetime' ),
					'updated_at' => array( 'datetime' ),
				),
				'primary' => array( 'id' ),
				'index'   => array(
					'status_created' => array( 'status', 'created_at' ),
					'email'          => array( 'email(100)' ),
				),
			),
		);
	}

	/** Create missing tables / indexes, apply upgrades, record the schema version. Idempotent. */
	public static function install( Connection $db, string $prefix ): void {
		// Upgrade existing tables first: PostgreSQL creates indexes separately, and those may
		// reference columns that only an upgrade adds.
		$from = self::installedVersion( $db );
		if ( $from > 0 && $from < 2 ) {
			self::upgradeTo2( $db, $prefix );
		}
		if ( $from > 0 && $from < 3 ) {
			self::upgradeTo3( $db, $prefix );
		}
		if ( $from > 0 && $from < 4 ) {
			self::upgradeTo4( $db );
		}
		foreach ( self::tables() as $name => $def ) {
			foreach ( $db->dialect()->createTable( $db->table( $name ), $prefix . $name, $def ) as $sql ) {
				$db->execute( $sql );
			}
		}
		$db->execute( 'DELETE FROM ' . $db->table( 'settings' ) . ' WHERE name = ?', array( 'schema_version' ) );
		$db->insert( 'settings', array( 'name' => 'schema_version', 'value' => (string) self::VERSION ) );
	}

	/** v2: items.sort_title (punctuation-free name sort key, same order on every database). */
	private static function upgradeTo2( Connection $db, string $prefix ): void {
		$table = $db->table( 'items' );
		if ( ! $db->columnExists( 'items', 'sort_title' ) ) {
			foreach ( $db->dialect()->addColumn( $table, 'sort_title', array( 'string:191' ) ) as $sql ) {
				$db->execute( $sql );
			}
			foreach ( $db->dialect()->addIndex( $table, $prefix . 'items', 'type_status_sort', array( 'type', 'status', 'sort_title(100)' ) ) as $sql ) {
				$db->execute( $sql );
			}
		}
		foreach ( $db->select( 'SELECT id, title FROM ' . $table ) as $row ) {
			$db->execute( 'UPDATE ' . $table . ' SET sort_title = ? WHERE id = ?', array( \DWCatalog\Support\Text::sortKey( (string) $row['title'] ), (int) $row['id'] ) );
		}
	}

	/** v3: items.review (why a product needs a look, '' = fine). New tables are created by install(). */
	private static function upgradeTo3( Connection $db, string $prefix ): void {
		$table = $db->table( 'items' );
		if ( ! $db->columnExists( 'items', 'review' ) ) {
			foreach ( $db->dialect()->addColumn( $table, 'review', array( 'string:40', 'default' => "''" ) ) as $sql ) {
				$db->execute( $sql );
			}
			foreach ( $db->dialect()->addIndex( $table, $prefix . 'items', 'type_review', array( 'type', 'review' ) ) as $sql ) {
				$db->execute( $sql );
			}
			$db->execute( 'UPDATE ' . $table . " SET review = '' WHERE review IS NULL" );
		}
	}

	/** v4: items.position (manual "recommended" order on the site; 0 = no particular place). */
	private static function upgradeTo4( Connection $db ): void {
		if ( ! $db->columnExists( 'items', 'position' ) ) {
			foreach ( $db->dialect()->addColumn( $db->table( 'items' ), 'position', array( 'int' ) ) as $sql ) {
				$db->execute( $sql );
			}
		}
	}

	public static function installedVersion( Connection $db ): int {
		if ( ! $db->tableExists( 'settings' ) ) {
			return 0;
		}
		return (int) $db->selectValue( 'SELECT value FROM ' . $db->table( 'settings' ) . ' WHERE name = ?', array( 'schema_version' ) );
	}

	/** Remove every core table (uninstall). */
	public static function drop( Connection $db ): void {
		foreach ( array_keys( self::tables() ) as $name ) {
			$db->execute( 'DROP TABLE IF EXISTS ' . $db->table( $name ) );
		}
	}
}
