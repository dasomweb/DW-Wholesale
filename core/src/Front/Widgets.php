<?php
namespace DWCatalog\Front;

use DWCatalog\Catalog;
use DWCatalog\Support\Text;

/**
 * Saved catalog widgets (M4): a configuration stored once and placed with
 * [dw_wholesale_catalog id="frozen"]. Stored as settings `widgets` [ id => config ].
 */
final class Widgets {

	const KEY = 'widgets';
	const MAX = 50;

	const FILTERS     = array( 'category', 'brand', 'origin', 'stock', 'new', 'photo' );
	const CARD_FIELDS = array( 'code', 'pack', 'brand', 'origin', 'category', 'price' );
	const PER_PAGE    = array( 24, 48, 96 );

	public static function defaults(): array {
		return array(
			'name'            => '',
			'title'           => '',
			'scope'           => 'all',      // all | group | categories
			'groups'          => array(),    // top-level category slugs
			'categories'      => array(),    // category slugs
			'exclude'         => array(),    // category slugs left out
			'search'          => 'large',    // large | small | hidden
			'suggest'         => '',         // comma-separated suggested words
			'filters'         => array( 'category', 'brand', 'origin', 'stock', 'new', 'photo' ),
			'filter_position' => 'sidebar',  // sidebar | top
			'sorts'           => array(),    // allowed sort keys (empty = all)
			'default_sort'    => 'title_asc',
			'per_page'        => 24,
			'per_page_tablet' => 24,
			'per_page_mobile' => 12,
			'cols_desktop'    => 4,
			'cols_tablet'     => 3,
			'cols_mobile'     => 2,
			'mobile_view'     => 'grid',     // grid | list
			'card_fields'     => array( 'code', 'pack', 'brand', 'origin', 'category' ),
			'click'           => 'drawer',   // drawer | page
			'design'          => '',
		);
	}

	/** @return array id => config */
	public static function all( Catalog $c ): array {
		$rows = $c->settings()->get( self::KEY );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $id => $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $id ] = array_merge( self::defaults(), $row );
			}
		}
		return $out;
	}

	public static function get( Catalog $c, string $id ): ?array {
		$all = self::all( $c );
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Clean a configuration from the admin form against what this catalog has.
	 */
	public static function sanitize( Catalog $c, array $in ): array {
		$type    = $c->fields()->primaryType();
		$d       = self::defaults();
		$terms   = array();
		$tops    = array();
		foreach ( $c->terms()->all( $type ) as $t ) {
			$terms[ $t['slug'] ] = true;
			if ( ! (int) $t['parent'] ) {
				$tops[ $t['slug'] ] = true;
			}
		}
		$list = function ( $key, array $allowed ) use ( $in ) {
			$raw = isset( $in[ $key ] ) ? ( is_array( $in[ $key ] ) ? $in[ $key ] : explode( ',', (string) $in[ $key ] ) ) : array();
			$out = array();
			foreach ( $raw as $v ) {
				$v = is_scalar( $v ) ? trim( (string) $v ) : '';
				if ( '' !== $v && isset( $allowed[ $v ] ) && ! in_array( $v, $out, true ) ) {
					$out[] = $v;
				}
			}
			return $out;
		};
		$pick = function ( $key, array $options ) use ( $in, $d ) {
			return isset( $in[ $key ] ) && in_array( $in[ $key ], $options, true ) ? $in[ $key ] : $d[ $key ];
		};
		$int  = function ( $key, $min, $max ) use ( $in, $d ) {
			return isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? max( $min, min( $max, (int) $in[ $key ] ) ) : $d[ $key ];
		};

		$public  = $c->fields()->fieldsByKey( $type, true );
		$filters = array_fill_keys( self::FILTERS, true );
		$cards   = array_fill_keys( self::CARD_FIELDS, true );
		foreach ( $public as $key => $f ) {
			if ( empty( $f['is_title_field'] ) ) {
				$filters[ $key ] = true;
				$cards[ $key ]   = true;
			}
		}
		$sorts  = array_fill_keys( array_keys( $c->query()->sortOptions( $type, true ) ), true );
		$out    = array(
			'name'            => Text::line( isset( $in['name'] ) ? $in['name'] : '', 80 ),
			'title'           => Text::line( isset( $in['title'] ) ? $in['title'] : '', 120 ),
			'scope'           => $pick( 'scope', array( 'all', 'group', 'categories' ) ),
			'groups'          => $list( 'groups', $tops ),
			'categories'      => $list( 'categories', $terms ),
			'exclude'         => $list( 'exclude', $terms ),
			'search'          => $pick( 'search', array( 'large', 'small', 'hidden' ) ),
			'suggest'         => implode( ', ', array_slice( array_filter( array_map( function ( $w ) {
				return Text::line( $w, 40 );
			}, explode( ',', isset( $in['suggest'] ) ? (string) $in['suggest'] : '' ) ), 'strlen' ), 0, 12 ) ),
			'filters'         => $list( 'filters', $filters ),
			'filter_position' => $pick( 'filter_position', array( 'sidebar', 'top' ) ),
			'sorts'           => $list( 'sorts', $sorts ),
			'default_sort'    => isset( $in['default_sort'] ) && isset( $sorts[ $in['default_sort'] ] ) ? $in['default_sort'] : ( isset( $sorts[ $d['default_sort'] ] ) ? $d['default_sort'] : 'date_desc' ),
			'per_page'        => 24,
			'per_page_tablet' => $int( 'per_page_tablet', 4, 96 ),
			'per_page_mobile' => $int( 'per_page_mobile', 4, 96 ),
			'cols_desktop'    => $int( 'cols_desktop', 1, 6 ),
			'cols_tablet'     => $int( 'cols_tablet', 1, 4 ),
			'cols_mobile'     => $int( 'cols_mobile', 1, 2 ),
			'mobile_view'     => $pick( 'mobile_view', array( 'grid', 'list' ) ),
			'card_fields'     => $list( 'card_fields', $cards ),
			'click'           => $pick( 'click', array( 'drawer', 'page' ) ),
			'design'          => isset( $in['design'] ) && isset( Design::presets( $c->settings() )[ $in['design'] ] ) && 'default' !== $in['design'] ? (string) $in['design'] : '',
		);
		// Per-page values are compared as integers
		$out['per_page'] = (int) ( isset( $in['per_page'] ) && in_array( (int) $in['per_page'], self::PER_PAGE, true ) ? $in['per_page'] : $d['per_page'] );
		if ( $out['sorts'] && ! in_array( $out['default_sort'], $out['sorts'], true ) ) {
			$out['sorts'][] = $out['default_sort'];
		}
		return $out;
	}

	/**
	 * Save under $id (new: '' → made from the name). @return string the id, '' when refused.
	 */
	public static function save( Catalog $c, string $id, array $in ): string {
		$config = self::sanitize( $c, $in );
		$all    = self::all( $c );
		$id     = Text::slug( '' !== trim( $id ) ? $id : $config['name'], 40 );
		if ( '' === $id ) {
			return '';
		}
		if ( '' === $config['name'] ) {
			$config['name'] = $id;
		}
		if ( ! isset( $all[ $id ] ) && count( $all ) >= self::MAX ) {
			return '';
		}
		$all[ $id ] = $config;
		ksort( $all );
		$c->settings()->set( self::KEY, $all );
		return $id;
	}

	public static function delete( Catalog $c, string $id ): void {
		$all = self::all( $c );
		unset( $all[ $id ] );
		$c->settings()->set( self::KEY, $all );
	}

	/** A configuration as FrontApp shortcode attributes. */
	public static function atts( array $w ): array {
		$scope = array();
		if ( 'group' === $w['scope'] ) {
			$scope = $w['groups'];
		} elseif ( 'categories' === $w['scope'] ) {
			$scope = $w['categories'];
		}
		return array(
			'title'           => $w['title'],
			'category'        => implode( ',', $scope ),
			'exclude'         => implode( ',', $w['exclude'] ),
			'search_size'     => $w['search'],
			'suggest'         => $w['suggest'],
			'filters'         => implode( ',', $w['filters'] ),
			'filter_position' => $w['filter_position'],
			'sorts'           => implode( ',', $w['sorts'] ),
			'default_sort'    => $w['default_sort'],
			'per_page'        => $w['per_page'],
			'per_page_tablet' => $w['per_page_tablet'],
			'per_page_mobile' => $w['per_page_mobile'],
			'columns'         => $w['cols_desktop'],
			'cols_tablet'     => $w['cols_tablet'],
			'cols_mobile'     => $w['cols_mobile'],
			'mobile_view'     => $w['mobile_view'],
			'card_fields'     => implode( ',', $w['card_fields'] ),
			'click'           => $w['click'],
			'design'          => $w['design'],
		);
	}

	/** Pages using a widget id (from the host's list of catalog pages). @return array[] title, url */
	public static function pagesUsing( Catalog $c, string $id ): array {
		$out = array();
		foreach ( $c->host()->catalogPages() as $page ) {
			if ( preg_match( '/\[dw_wholesale_catalog[^\]]*\bid\s*=\s*["\']?' . preg_quote( $id, '/' ) . '["\'\s\]]/', $page['shortcode'] . ' ' ) ) {
				$out[] = $page;
			}
		}
		return $out;
	}
}
