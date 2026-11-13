<?php
namespace DWCatalog\Admin;

use DWCatalog\Catalog;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Fields\VariationColumns;
use DWCatalog\Support\Text;

/**
 * Saves the item edit form: item row, field values, categories, tags, variations, images.
 * Everything is cleaned here; the search index is rebuilt once at the end.
 */
class ItemEditor {

	const MAX_UPLOADS = 20;

	/** @var Catalog */
	private $c;
	/** @var AdminApp */
	private $app;

	public function __construct( Catalog $c, AdminApp $app ) {
		$this->c   = $c;
		$this->app = $app;
	}

	public function save( string $type, array $post, array $files ): array {
		$items  = $this->c->items();
		$id     = isset( $post['id'] ) ? (int) $post['id'] : 0;
		$exists = $id ? $items->get( $id ) : null;
		if ( $id && ( ! $exists || $exists['type'] !== $type ) ) {
			return $this->app->redirect( array( 'view' => 'items', 'type' => $type, 'msg' => 'not_found' ) );
		}

		$warnings = array();
		$items->beginBatch();
		try {
			$id = $items->save( array(
				'id'        => $id,
				'type'      => $type,
				'title'     => isset( $post['title'] ) ? $post['title'] : '',
				'slug'      => isset( $post['slug'] ) ? $post['slug'] : '',
				'content'   => $this->c->host()->sanitizeHtml( isset( $post['content'] ) ? (string) $post['content'] : '' ),
				'excerpt'   => isset( $post['excerpt'] ) ? $post['excerpt'] : '',
				'status'    => isset( $post['status'] ) ? $post['status'] : 'draft',
			'position'  => isset( $post['position'] ) ? (int) $post['position'] : 0,
				'author_id' => $this->c->host()->userId(),
			) );
		} catch ( \InvalidArgumentException $e ) {
			$items->endBatch();
			$this->app->flash( array( $this->app->t( $e->getMessage() ) ) );
			return $this->app->redirect( array( 'view' => 'edit', 'type' => $type, 'id' => $id ?: null, 'msg' => 'invalid' ) );
		}
		$item = $items->get( $id );

		// Field values — the title field mirrors the item title
		$raw    = isset( $post['fields'] ) && is_array( $post['fields'] ) ? $post['fields'] : array();
		$values = array();
		foreach ( $this->c->fields()->fields( $type ) as $field ) {
			$key = $field['meta_key'];
			if ( ! empty( $field['is_title_field'] ) ) {
				$values[ $key ] = $item['title'];
				continue;
			}
			$value = FieldConfig::sanitizeValue( $field, isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			if ( isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) && '' === $value ) {
				$warnings[] = sprintf( $this->app->t( '%s: the value was not accepted.' ), $field['label'] );
			} elseif ( '' === $value && ! empty( $field['required'] ) ) {
				$warnings[] = sprintf( $this->app->t( '%s is required.' ), $field['label'] );
			}
			$values[ $key ] = $value;
		}
		$items->saveValues( $id, $values );

		// Categories + tags
		$config = $this->c->fields()->type( $type );
		if ( ! empty( $config['has_category'] ) ) {
			$this->c->terms()->setItem( $type, $id, 'category', isset( $post['cats'] ) ? (array) $post['cats'] : array() );
		}
		if ( ! empty( $config['has_tag'] ) ) {
			$tagIds = array();
			foreach ( array_slice( explode( ',', isset( $post['tags'] ) ? (string) $post['tags'] : '' ), 0, 50 ) as $tag ) {
				$tag = Text::line( $tag, 100 );
				if ( '' !== $tag ) {
					$tagIds[] = $this->c->terms()->ensure( $type, $tag, 'tag' );
				}
			}
			$this->c->terms()->setItem( $type, $id, 'tag', $tagIds );
		}

		// Variations
		$cols = $this->c->fields()->variationColumns( $type );
		$rows = array();
		foreach ( array_slice( isset( $post['var'] ) && is_array( $post['var'] ) ? $post['var'] : array(), 0, 500 ) as $r ) {
			$row = VariationColumns::sanitizeRow( $cols, $r );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		$items->saveVariations( $id, $rows );

		// Images: delete → reorder/alt → new uploads
		$images = $this->c->images();
		foreach ( isset( $post['img_delete'] ) ? (array) $post['img_delete'] : array() as $imgId ) {
			$images->delete( $id, (int) $imgId );
		}
		$images->update( $id, isset( $post['img_order'] ) ? (array) $post['img_order'] : array(), isset( $post['img_alt'] ) && is_array( $post['img_alt'] ) ? $post['img_alt'] : array() );
		foreach ( array_slice( self::normalizeFiles( isset( $files['images'] ) ? $files['images'] : array() ), 0, self::MAX_UPLOADS ) as $file ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}
			$result = $images->upload( $id, $file );
			if ( is_string( $result ) ) {
				$warnings[] = Text::line( $file['name'], 100 ) . ': ' . $this->app->t( $result );
			}
		}

		$items->endBatch();
		if ( $warnings ) {
			$this->app->flash( $warnings );
		}
		return $this->app->redirect( array( 'view' => 'edit', 'type' => $type, 'id' => $id, 'msg' => 'saved' ) );
	}

	/** $_FILES['x'] with name[] → list of single-file arrays. */
	public static function normalizeFiles( array $f ): array {
		if ( ! isset( $f['name'] ) ) {
			return array();
		}
		if ( ! is_array( $f['name'] ) ) {
			return array( $f );
		}
		$out = array();
		foreach ( array_keys( $f['name'] ) as $i ) {
			$out[] = array(
				'name'     => $f['name'][ $i ],
				'type'     => isset( $f['type'][ $i ] ) ? $f['type'][ $i ] : '',
				'tmp_name' => $f['tmp_name'][ $i ],
				'error'    => $f['error'][ $i ],
				'size'     => isset( $f['size'][ $i ] ) ? $f['size'][ $i ] : 0,
			);
		}
		return $out;
	}
}
