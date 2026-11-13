<?php
namespace DWCatalog\Front;

use DWCatalog\Repo\Settings;
use DWCatalog\Support\Text;

/**
 * Visual settings (M5) → CSS custom properties on the widget root.
 *
 * A design is a set of values; the default one is stored as `design`, named ones ("presets") as
 * `design_presets` [ slug => [ name, values ] ]. A widget picks one with design="slug".
 * Values are validated on save (colors, numbers, enums), so they are safe inside style="".
 */
final class Design {

	const KEY     = 'design';
	const PRESETS = 'design_presets';

	/** Font sets: key => [ label, Google Fonts css2 query ('' = none), CSS font-family ]. */
	const FONT_SETS = array(
		'inter_noto' => array( 'Inter + Noto Sans KR', 'family=Inter:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;700', "'Inter', 'Noto Sans KR', system-ui, sans-serif" ),
		'noto'       => array( 'Noto Sans KR', 'family=Noto+Sans+KR:wght@400;500;700', "'Noto Sans KR', system-ui, sans-serif" ),
		'plex_kr'    => array( 'IBM Plex Sans KR', 'family=IBM+Plex+Sans+KR:wght@400;500;600;700', "'IBM Plex Sans KR', system-ui, sans-serif" ),
		'nanum'      => array( 'Nanum Gothic (나눔고딕)', 'family=Nanum+Gothic:wght@400;700;800', "'Nanum Gothic', system-ui, sans-serif" ),
		'theme'      => array( 'Theme font', '', 'inherit' ),
	);

	const ENUMS = array(
		'button_style' => array( 'fill', 'outline', 'tint' ),
		'font_set'     => array( 'inter_noto', 'noto', 'plex_kr', 'nanum', 'theme' ),
		'text_size'    => array( 's', 'm', 'l' ),
		'shadow'       => array( 'none', 'soft' ),
	);

	const TEXT_SIZES = array( 's' => '14px', 'm' => '15px', 'l' => '16px' );

	const SHADOWS = array(
		'none' => 'none',
		'soft' => '0 1px 3px rgba(15,27,45,.06), 0 6px 18px rgba(15,27,45,.07)',
	);

	/** Number keys: key => [ min, max ]. */
	const RANGES = array(
		'radius_card'       => array( 0, 32 ),
		'radius_button'     => array( 0, 32 ),
		'radius_input'      => array( 0, 32 ),
		'columns'           => array( 1, 6 ),
		'grid_gap'          => array( 0, 60 ),
		'title_size'        => array( 10, 40 ),
		'title_weight'      => array( 300, 900 ),
		'field_label_size'  => array( 10, 24 ),
		'field_value_size'  => array( 10, 24 ),
		'card_border_width' => array( 0, 6 ),
		'card_padding'      => array( 0, 40 ),
	);

	public static function defaults(): array {
		return array(
			'accent'            => '#1466d6',
			'border_color'      => '#e3e8ef',
			'button_style'      => 'fill',
			'font_set'          => 'inter_noto',
			'text_size'         => 'm',
			'radius_card'       => 12,
			'radius_button'     => 8,
			'radius_input'      => 10,
			'shadow'            => 'none',
			'columns'           => 4,
			'grid_gap'          => 18,
			'card_bg'           => '#ffffff',
			'title_color'       => '#0f1b2d',
			'title_size'        => 16,
			'title_weight'      => 600,
			'field_label_size'  => 13,
			'field_label_color' => '#5b6b82',
			'field_value_size'  => 13,
			'field_value_color' => '#0f1b2d',
			'card_border_width' => 1,
			'card_padding'      => 0,
		);
	}

	// ── Reading ─────────────────────────────────────────────────

	/** Values of a design: a preset slug, or '' / 'default' / unknown → the default design. */
	public static function get( Settings $s, string $preset = '' ): array {
		$presets = self::presetRows( $s );
		if ( '' !== $preset && 'default' !== $preset && isset( $presets[ $preset ] ) ) {
			return self::sanitize( $presets[ $preset ]['values'] );
		}
		$saved = $s->get( self::KEY );
		return self::sanitize( is_array( $saved ) ? self::upgrade( $saved ) : array() );
	}

	/** @return array slug => name, the default design first */
	public static function presets( Settings $s ): array {
		$out = array( 'default' => '' );
		foreach ( self::presetRows( $s ) as $slug => $row ) {
			$out[ $slug ] = (string) $row['name'];
		}
		return $out;
	}

	private static function presetRows( Settings $s ): array {
		$rows = $s->get( self::PRESETS );
		return is_array( $rows ) ? array_filter( $rows, function ( $r ) {
			return is_array( $r ) && isset( $r['name'], $r['values'] ) && is_array( $r['values'] );
		} ) : array();
	}

	/** Settings saved by 2.x (card_border_color, card_radius, card_shadow, font_family). */
	private static function upgrade( array $v ): array {
		if ( ! isset( $v['border_color'] ) && isset( $v['card_border_color'] ) ) {
			$v['border_color'] = $v['card_border_color'];
		}
		if ( ! isset( $v['radius_card'] ) && isset( $v['card_radius'] ) ) {
			$v['radius_card'] = $v['card_radius'];
		}
		if ( ! isset( $v['shadow'] ) && isset( $v['card_shadow'] ) ) {
			$v['shadow'] = 'none' === $v['card_shadow'] ? 'none' : 'soft';
		}
		if ( ! isset( $v['font_set'] ) && array_key_exists( 'font_family', $v ) ) {
			$v['font_set'] = 'IBM Plex Sans' === $v['font_family'] ? 'plex_kr' : 'theme';
		}
		return $v;
	}

	// ── Writing ─────────────────────────────────────────────────

	/** Clean a value set (unknown keys dropped, every key present). */
	public static function sanitize( array $in ): array {
		$out = array();
		foreach ( self::defaults() as $key => $default ) {
			$v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : null;
			if ( isset( self::RANGES[ $key ] ) ) {
				$out[ $key ] = null === $v || ! is_numeric( $v ) ? $default : max( self::RANGES[ $key ][0], min( self::RANGES[ $key ][1], (int) $v ) );
			} elseif ( isset( self::ENUMS[ $key ] ) ) {
				$out[ $key ] = in_array( $v, self::ENUMS[ $key ], true ) ? $v : $default;
			} else {
				$out[ $key ] = null !== $v && self::color( $v ) ? $v : $default;
			}
		}
		return $out;
	}

	/** Save the default design ('' / 'default') or an existing preset. */
	public static function save( Settings $s, array $in, string $preset = '' ): void {
		$values = self::sanitize( $in );
		$rows   = self::presetRows( $s );
		if ( '' !== $preset && 'default' !== $preset && isset( $rows[ $preset ] ) ) {
			$rows[ $preset ]['values'] = $values;
			$s->set( self::PRESETS, $rows );
			return;
		}
		$s->set( self::KEY, $values );
	}

	/** New preset from a value set. @return string its slug ('' when the name is empty) */
	public static function create( Settings $s, string $name, array $in ): string {
		$name = Text::line( $name, 60 );
		$base = Text::slug( $name, 40 );
		if ( '' === $name || '' === $base ) {
			return '';
		}
		$rows = self::presetRows( $s );
		$slug = 'default' === $base ? 'default-2' : $base;
		for ( $n = 2; isset( $rows[ $slug ] ); $n++ ) {
			$slug = $base . '-' . $n;
		}
		$rows[ $slug ] = array( 'name' => $name, 'values' => self::sanitize( $in ) );
		$s->set( self::PRESETS, array_slice( $rows, 0, 30, true ) );
		return $slug;
	}

	public static function deletePreset( Settings $s, string $slug ): void {
		$rows = self::presetRows( $s );
		unset( $rows[ $slug ] );
		$s->set( self::PRESETS, $rows );
	}

	// ── Output ──────────────────────────────────────────────────

	public static function color( string $v ): bool {
		return (bool) preg_match( '/^#[0-9a-fA-F]{3,8}$/', $v )
			|| (bool) preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $v );
	}

	/** CSS custom properties of a value set. */
	public static function vars( array $d ): array {
		$d = self::sanitize( $d );
		return array_merge(
			array(
				'--dwcat-accent'            => $d['accent'],
				'--dwcat-card-border-color' => $d['border_color'],
				'--dwcat-card-radius'       => $d['radius_card'] . 'px',
				'--dwcat-btn-radius'        => $d['radius_button'] . 'px',
				'--dwcat-input-radius'      => $d['radius_input'] . 'px',
				'--dwcat-grid-gap'          => $d['grid_gap'] . 'px',
				'--dwcat-card-bg'           => $d['card_bg'],
				'--dwcat-title-color'       => $d['title_color'],
				'--dwcat-title-size'        => $d['title_size'] . 'px',
				'--dwcat-title-weight'      => $d['title_weight'],
				'--dwcat-field-label-size'  => $d['field_label_size'] . 'px',
				'--dwcat-field-label-color' => $d['field_label_color'],
				'--dwcat-field-value-size'  => $d['field_value_size'] . 'px',
				'--dwcat-field-value-color' => $d['field_value_color'],
				'--dwcat-card-border-width' => $d['card_border_width'] . 'px',
				'--dwcat-card-padding'      => $d['card_padding'] . 'px',
			),
			self::enumVars( 'button_style', $d['button_style'] ),
			self::enumVars( 'font_set', $d['font_set'] ),
			self::enumVars( 'text_size', $d['text_size'] ),
			self::enumVars( 'shadow', $d['shadow'] )
		);
	}

	private static function enumVars( string $key, string $value ): array {
		switch ( $key ) {
			case 'button_style':
				$styles = array(
					'fill'    => array( 'var(--dwcat-accent)', '#ffffff', 'var(--dwcat-accent)' ),
					'outline' => array( '#ffffff', 'var(--dwcat-accent)', 'var(--dwcat-accent)' ),
					'tint'    => array( 'color-mix(in srgb, var(--dwcat-accent) 12%, #ffffff)', 'var(--dwcat-accent)', 'transparent' ),
				);
				$v = $styles[ $value ];
				return array( '--dwcat-btn-bg' => $v[0], '--dwcat-btn-fg' => $v[1], '--dwcat-btn-border' => $v[2] );
			case 'font_set':
				return array( '--dwcat-font-family' => self::FONT_SETS[ $value ][2] );
			case 'text_size':
				return array( '--dwcat-font-size' => self::TEXT_SIZES[ $value ] );
			case 'shadow':
				return array( '--dwcat-card-shadow' => self::SHADOWS[ $value ] );
		}
		return array();
	}

	/** style="" value for a widget root. */
	public static function style( Settings $s, string $preset = '' ): string {
		$css = '';
		foreach ( self::vars( self::get( $s, $preset ) ) as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		return $css;
	}

	/** Google Fonts stylesheet of a design ('' for the theme font). */
	public static function fontUrl( Settings $s, string $preset = '' ): string {
		return self::fontUrlFor( self::get( $s, $preset )['font_set'] );
	}

	public static function fontUrlFor( string $set ): string {
		return isset( self::FONT_SETS[ $set ] ) && '' !== self::FONT_SETS[ $set ][1] ? 'https://fonts.googleapis.com/css2?' . self::FONT_SETS[ $set ][1] . '&display=swap' : '';
	}

	/**
	 * What the admin preview needs to follow the form without a round trip:
	 * direct => [ key => [ var, unit ] ], enums => [ key => [ option => [ var => value ] ] ], fonts => [ set => url ].
	 */
	public static function previewSpec(): array {
		$direct = array();
		$probe  = self::vars( self::defaults() );
		foreach ( self::defaults() as $key => $default ) {
			if ( isset( self::ENUMS[ $key ] ) ) {
				continue;
			}
			$changed = self::vars( array( $key => is_int( $default ) ? $default + 1 : '#123456' ) + self::defaults() );
			foreach ( array_diff_assoc( $changed, $probe ) as $var => $value ) {
				$direct[ $key ] = array( $var, isset( self::RANGES[ $key ] ) && 'title_weight' !== $key && 'columns' !== $key ? 'px' : '' );
			}
		}
		$enums = array();
		foreach ( self::ENUMS as $key => $options ) {
			foreach ( $options as $o ) {
				$enums[ $key ][ $o ] = self::enumVars( $key, $o );
			}
		}
		$fonts = array();
		foreach ( array_keys( self::FONT_SETS ) as $set ) {
			$fonts[ $set ] = self::fontUrlFor( $set );
		}
		return array( 'direct' => $direct, 'enums' => $enums, 'fonts' => $fonts );
	}
}
