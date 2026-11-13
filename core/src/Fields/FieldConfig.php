<?php
namespace DWCatalog\Fields;

use DWCatalog\Repo\Settings;
use DWCatalog\Support\Text;

/**
 * Catalog types and their field schema.
 *
 * Field: meta_key, label, type, required, options, description,
 *        show_in_list (admin list + cards), show_in_export (PDF),
 *        show_in_detail (PUBLIC: site output, site search/filter/sort), is_title_field.
 */
class FieldConfig {

	const FIELD_TYPES = array( 'text', 'textarea', 'number', 'email', 'url', 'date', 'select' );

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	// ── Catalog types ───────────────────────────────────────────

	/** @return array slug => config */
	public function types(): array {
		$types = $this->settings->get( 'types' );
		return is_array( $types ) && $types ? $types : self::defaultTypes();
	}

	public function type( string $slug ): ?array {
		$types = $this->types();
		return isset( $types[ $slug ] ) ? $types[ $slug ] : null;
	}

	public function primaryType(): string {
		$types = $this->types();
		reset( $types );
		return (string) key( $types );
	}

	public static function defaultTypes(): array {
		return array(
			'product' => array(
				'slug'          => 'product',
				'singular_name' => 'Product',
				'plural_name'   => 'Products',
				'menu_name'     => 'DW Wholesale',
				'has_category'  => true,
				'has_tag'       => true,
			),
		);
	}

	// ── Fields ──────────────────────────────────────────────────

	public function fields( string $type ): array {
		$fields = $this->settings->get( 'fields.' . $type );
		if ( ! is_array( $fields ) ) {
			$fields = 'product' === $type ? self::defaultProductFields() : array();
		}
		return array_values( $fields );
	}

	public function saveFields( string $type, array $fields ): void {
		$this->settings->set( 'fields.' . $type, array_values( $fields ) );
	}

	/** @return array meta_key => field */
	public function fieldsByKey( string $type, bool $publicOnly = false ): array {
		$out = array();
		foreach ( $this->fields( $type ) as $f ) {
			if ( ! $publicOnly || self::isPublic( $f ) ) {
				$out[ $f['meta_key'] ] = $f;
			}
		}
		return $out;
	}

	/**
	 * The field playing a role, found by key: 'brand' → *brand*, 'code' → *item_code* / *sku*,
	 * 'status' → *status* (the product's own state: active, out of stock, …).
	 * $publicOnly: visitors only ever see public fields.
	 */
	public function roleField( string $type, string $role, bool $publicOnly = true ): ?array {
		$patterns = array( 'brand' => '/brand/', 'code' => '/item_code|sku/', 'status' => '/status/', 'origin' => '/origin|country/', 'pack' => '/pack|case_size|unit_size/' );
		if ( ! isset( $patterns[ $role ] ) ) {
			return null;
		}
		foreach ( $this->fieldsByKey( $type, $publicOnly ) as $key => $f ) {
			if ( empty( $f['is_title_field'] ) && preg_match( $patterns[ $role ], $key ) ) {
				return $f;
			}
		}
		return null;
	}

	/** Codes, pack sizes and SKUs read best in a monospace face. */
	public static function mono( array $f ): bool {
		return (bool) preg_match( '/code|sku|pack|size/', $f['meta_key'] );
	}

	public function titleField( string $type ): ?array {
		foreach ( $this->fields( $type ) as $f ) {
			if ( ! empty( $f['is_title_field'] ) ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Public = shown on the site and usable by visitors (search/filter/sort).
	 * Non-public fields never leave the admin.
	 */
	public static function isPublic( array $field ): bool {
		if ( isset( $field['show_in_detail'] ) ) {
			return ! empty( $field['show_in_detail'] );
		}
		return empty( $field['is_title_field'] ) && false === strpos( (string) $field['meta_key'], 'internal' );
	}

	/** Clean a field list from the admin form. */
	public static function sanitizeFields( array $raw, $titleIndex ): array {
		$fields = array();
		$seen   = array();
		foreach ( $raw as $index => $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$key   = Text::key( isset( $f['meta_key'] ) ? $f['meta_key'] : '' );
			$label = Text::line( isset( $f['label'] ) ? $f['label'] : '', 100 );
			if ( '' === $key || '' === $label || isset( $seen[ $key ] ) ) {
				continue;
			}
			$type         = isset( $f['type'] ) && in_array( $f['type'], self::FIELD_TYPES, true ) ? $f['type'] : 'text';
			$fields[]     = array(
				'meta_key'       => $key,
				'label'          => $label,
				'type'           => $type,
				'required'       => ! empty( $f['required'] ),
				'options'        => Text::line( isset( $f['options'] ) ? $f['options'] : '', 2000 ),
				'description'    => '',
				'show_in_list'   => ! empty( $f['show_in_list'] ),
				'show_in_export' => ! empty( $f['show_in_export'] ),
				'show_in_detail' => ! empty( $f['show_in_detail'] ),
				'is_title_field' => (string) $index === (string) $titleIndex,
			);
			$seen[ $key ] = true;
		}
		return $fields;
	}

	/** Clean one field value by type. Returns '' for invalid input. */
	public static function sanitizeValue( array $field, $raw ): string {
		$raw = is_scalar( $raw ) ? (string) $raw : '';
		switch ( $field['type'] ) {
			case 'textarea':
				return Text::block( $raw, 20000 );
			case 'number':
				$raw = trim( $raw );
				return is_numeric( $raw ) ? $raw : '';
			case 'email':
				$raw = trim( $raw );
				return false !== filter_var( $raw, FILTER_VALIDATE_EMAIL ) ? $raw : '';
			case 'url':
				$raw = trim( $raw );
				return ( false !== filter_var( $raw, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $raw ) ) ? $raw : '';
			case 'date':
				$raw = trim( $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			case 'select':
				$raw = trim( $raw );
				return array_key_exists( $raw, Text::parseOptions( (string) $field['options'] ) ) ? $raw : '';
			default:
				return Text::line( $raw, 1000 );
		}
	}

	/** Stored value → display text (select keys become labels). */
	public static function display( array $field, string $value ): string {
		if ( '' !== $value && 'select' === $field['type'] ) {
			$opts = Text::parseOptions( (string) $field['options'] );
			return isset( $opts[ $value ] ) ? $opts[ $value ] : $value;
		}
		return $value;
	}

	// ── Variation columns ───────────────────────────────────────

	public function variationColumns( string $type ): array {
		$cols = $this->settings->get( 'variation_columns.' . $type );
		return is_array( $cols ) ? $cols : VariationColumns::defaults();
	}

	public function saveVariationColumns( string $type, array $cols ): void {
		$this->settings->set( 'variation_columns.' . $type, VariationColumns::sanitize( $cols ) );
	}

	// ── Defaults ────────────────────────────────────────────────

	public static function defaultProductFields(): array {
		$f = function ( $key, $label, $type, $extra = array() ) {
			return array_merge( array(
				'meta_key'       => $key,
				'label'          => $label,
				'type'           => $type,
				'required'       => false,
				'options'        => '',
				'description'    => '',
				'show_in_list'   => true,
				'show_in_export' => true,
				'show_in_detail' => true,
				'is_title_field' => false,
			), $extra );
		};
		return array(
			$f( 'dw_pc_product_name', 'Product Name', 'text', array( 'required' => true, 'is_title_field' => true, 'show_in_detail' => false ) ),
			$f( 'dw_pc_item_code', 'Item Code', 'text' ),
			$f( 'dw_pc_pack_size_raw', 'Pack Size / Case Pack', 'text' ),
			$f( 'dw_pc_brand_raw', 'Brand', 'text' ),
			$f( 'dw_pc_origin_raw', 'Origin', 'text' ),
			$f( 'dw_pc_status', 'Status', 'select', array( 'options' => 'active:Active,inactive:Inactive,out_of_stock:Out of Stock,discontinued:Discontinued' ) ),
			$f( 'dw_pc_internal_note', 'ETC', 'textarea', array( 'show_in_list' => false, 'show_in_detail' => false, 'description' => 'Internal notes' ) ),
		);
	}
}
