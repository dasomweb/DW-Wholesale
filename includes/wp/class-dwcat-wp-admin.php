<?php
/**
 * WordPress admin integration: one "DW Wholesale" menu that hosts the core admin app,
 * and admin-post.php routing for its forms.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Admin {

	/** Submenu → core view. Import/Export and Settings each group two core screens (section tabs). */
	const SUBMENU = array(
		'items'      => array( 'All Items', 'edit_posts' ),
		'edit'       => array( 'Add New', 'edit_posts' ),
		'categories' => array( 'Categories', 'manage_categories' ),
		'fields'     => array( 'Fields', 'manage_options' ),
		'import'     => array( 'Import / Export', 'edit_posts' ),
		'design'     => array( 'Settings', 'manage_options' ),
	);

	/** Core views reached through another submenu entry. */
	const SECTION_OF = array(
		'export'    => 'import',
		'reference' => 'design',
	);

	/** Per-action license gate, checked here before the core sees the request. */
	const ACTION_GATES = array(
		'save_item'   => 'dwcat_can_save_meta',
		'delete_item' => 'dwcat_can_save_meta',
		'bulk_items'  => 'dwcat_can_save_meta',
		'save_term'   => 'dwcat_can_save_meta',
		'delete_term' => 'dwcat_can_save_meta',
		'save_tree'   => 'dwcat_can_save_meta',
		'save_fields' => 'dwcat_can_manage_fields',
		'import'      => 'dwcat_can_bulk_import',
		'export'      => 'dwcat_can_export_pdf',
		'export_csv'  => 'dwcat_can_export_pdf',
		'save_design' => 'dwcat_can_save_meta',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_post_dwcat', array( $this, 'post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'submenu_file', array( $this, 'highlight' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/** The submenu lists the screens, so the core's own tab bar is hidden (.dwc-host-nav). */
	public function body_class( $classes ) {
		if ( isset( $_GET['page'] ) && DWCAT_WP_Host::ADMIN_PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$classes .= ' dwc-host-nav';
		}
		return $classes;
	}

	public function menu() {
		add_menu_page( 'DW Wholesale', 'DW Wholesale', 'edit_posts', DWCAT_WP_Host::ADMIN_PAGE, array( $this, 'page' ), 'dashicons-products', 21 );
		foreach ( self::SUBMENU as $view => $item ) {
			add_submenu_page(
				DWCAT_WP_Host::ADMIN_PAGE,
				__( $item[0], 'dw-wholesale' ), // phpcs:ignore WordPress.WP.I18n
				__( $item[0], 'dw-wholesale' ), // phpcs:ignore WordPress.WP.I18n
				$item[1],
				'items' === $view ? DWCAT_WP_Host::ADMIN_PAGE : 'admin.php?page=' . DWCAT_WP_Host::ADMIN_PAGE . '&view=' . $view
			);
		}
	}

	/** Keep the right submenu item highlighted for ?view=. */
	public function highlight( $submenu_file, $parent_file ) {
		if ( DWCAT_WP_Host::ADMIN_PAGE !== $parent_file && ( ! isset( $_GET['page'] ) || DWCAT_WP_Host::ADMIN_PAGE !== $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $submenu_file;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'items'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $view && ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$view = 'items';
		}
		if ( isset( self::SECTION_OF[ $view ] ) ) {
			$view = self::SECTION_OF[ $view ];
		}
		return ( 'items' === $view || ! isset( self::SUBMENU[ $view ] ) ) ? DWCAT_WP_Host::ADMIN_PAGE : 'admin.php?page=' . DWCAT_WP_Host::ADMIN_PAGE . '&view=' . $view;
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, DWCAT_WP_Host::ADMIN_PAGE ) ) {
			return;
		}
		$host = new DWCAT_WP_Host();
		wp_enqueue_style( 'dwcat-admin', $host->assetUrl( 'admin.css' ), array(), $host->version() );
		wp_enqueue_style( 'dwcat-admin-mono', 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion — Google Fonts URL
		wp_enqueue_script( 'dwcat-admin', $host->assetUrl( 'admin.js' ), array(), $host->version(), true );
	}

	public function page() {
		// 게이트 7 — 라이선스가 없으면 카탈로그 관리 화면을 렌더하지 않습니다.
		if ( ! dwcat_can_render_admin() ) {
			printf(
				'<div class="wrap"><h1>DW Wholesale</h1><div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div></div>',
				esc_html__( '라이선스가 활성화되지 않아 카탈로그를 표시할 수 없습니다.', 'dw-wholesale' ),
				esc_url( admin_url( 'admin.php?page=dw-wholesale-license' ) ),
				esc_html__( '라이선스 설정으로 이동', 'dw-wholesale' )
			);
			return;
		}
		try {
			echo dwcat()->admin()->render( wp_unslash( $_GET ) ); // phpcs:ignore — core escapes its own output
		} catch ( \Throwable $e ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
		}
	}

	public function post() {
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification — core verifies its CSRF token
		if ( ! isset( self::ACTION_GATES[ $do ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'dw-wholesale' ), 400 );
		}
		if ( ! call_user_func( self::ACTION_GATES[ $do ] ) ) {
			wp_die( esc_html__( 'DW Wholesale: 라이선스가 활성화되지 않아 이 작업을 실행할 수 없습니다.', 'dw-wholesale' ), 403 );
		}
		if ( 'export' === $do && is_file( dwcat_get_path() . 'vendor/autoload.php' ) ) {
			require_once dwcat_get_path() . 'vendor/autoload.php';
		}
		try {
			$result = dwcat()->admin()->handle( wp_unslash( $_POST ), $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification
		} catch ( \DWCatalog\Admin\Forbidden $e ) {
			wp_die( esc_html( $e->getMessage() ), 403 );
		}
		if ( isset( $result['download'] ) ) {
			list( $name, $mime, $body ) = $result['download'];
			nocache_headers();
			header( 'Content-Type: ' . $mime );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo $body; // phpcs:ignore — binary PDF
			exit;
		}
		wp_safe_redirect( $result['redirect'] );
		exit;
	}
}
