<?php
/**
 * Authenticated item list for the DW Admin SPA:
 *   GET /wp-json/dw-wholesale/v1/items?search=&page=   (edit_posts + REST nonce required)
 *
 * There is no public data API — visitors only ever receive rendered HTML.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Rest {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'plugins_loaded', array( $this, 'spa' ), 20 );
	}

	public function routes() {
		register_rest_route( 'dw-wholesale/v1', '/items', array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' ) && dwcat_can_call_rest();
			},
			'args'                => array(
				'search' => array( 'type' => 'string', 'default' => '' ),
				'page'   => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			),
			'callback'            => array( $this, 'items' ),
		) );
	}

	public function items( WP_REST_Request $req ) {
		$c      = dwcat();
		$type   = $c->fields()->primaryType();
		$result = $c->query()->run( $type, array( 'search' => (string) $req['search'], 'status' => 'any', 'page' => (int) $req['page'], 'per_page' => 50 ) );
		$cols   = array_filter( $c->fields()->fields( $type ), function ( $f ) {
			return ! empty( $f['show_in_list'] ) && empty( $f['is_title_field'] );
		} );
		$out    = array();
		foreach ( $result['rows'] as $row ) {
			$item = array( 'id' => (int) $row['id'], 'title' => $row['title'], 'status' => $row['status'] );
			foreach ( $cols as $f ) {
				$item[ $f['meta_key'] ] = \DWCatalog\Fields\FieldConfig::display( $f, $c->items()->value( (int) $row['id'], $f['meta_key'] ) );
			}
			$out[] = $item;
		}
		return rest_ensure_response( array( 'items' => $out, 'total' => $result['total'], 'pages' => $result['pages'] ) );
	}

	/** DW Admin SPA module (optional — only when DW Admin is installed). */
	public function spa() {
		// 게이트 9: 라이선스가 없으면 SPA 모듈을 노출하지 않습니다.
		if ( ! function_exists( 'dw_admin' ) || ! dwcat_can_call_rest() ) {
			return;
		}
		add_filter( 'dw_spa_modules', function ( $modules ) {
			try {
				$c    = dwcat();
				$type = $c->fields()->primaryType();
				$cols = array( array( 'key' => 'title', 'label' => 'Name' ) );
				foreach ( $c->fields()->fields( $type ) as $f ) {
					if ( ! empty( $f['show_in_list'] ) && empty( $f['is_title_field'] ) ) {
						$cols[] = array( 'key' => $f['meta_key'], 'label' => $f['label'] );
					}
				}
				$modules[] = array(
					'key'       => 'dwcat-' . $type,
					'label'     => 'DW Wholesale',
					'icon'      => 'grid',
					'rest_base' => rest_url( 'dw-wholesale/v1/items' ),
					'columns'   => $cols,
					'roles'     => array(),
				);
			} catch ( \Throwable $e ) { // phpcs:ignore — catalog unavailable: no module
			}
			return $modules;
		} );
	}
}
