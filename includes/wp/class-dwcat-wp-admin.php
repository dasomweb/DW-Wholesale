<?php
/**
 * WordPress admin integration: the "DW Wholesale" menu that hosts the core admin app,
 * and admin-post.php routing for its forms.
 *
 * Which entries a user sees comes from the core access matrix (DW Wholesale → Access), not from
 * WordPress capabilities. Hidden screens are refused before anything is printed (HTTP 403).
 *
 * @package DW_Wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Admin {

	/** Per-action license gate, checked here before the core sees the request. */
	const ACTION_GATES = array(
		'save_item'     => 'dwcat_can_save_meta',
		'delete_item'   => 'dwcat_can_save_meta',
		'bulk_items'    => 'dwcat_can_save_meta',
		'save_term'     => 'dwcat_can_save_meta',
		'delete_term'   => 'dwcat_can_save_meta',
		'save_tree'     => 'dwcat_can_save_meta',
		'save_fields'   => 'dwcat_can_manage_fields',
		'import'        => 'dwcat_can_bulk_import',
		'export'        => 'dwcat_can_export_pdf',
		'export_csv'    => 'dwcat_can_export_pdf',
		'save_design'   => 'dwcat_can_save_meta',
		'save_settings' => 'dwcat_can_save_meta',
		'save_access'   => 'dwcat_can_render_admin',
		'reset_access'  => 'dwcat_can_render_admin',
		'set_lang'      => 'dwcat_can_render_admin',
	);

	/** @var string hook suffix of the plugin page */
	private $hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_post_dwcat', array( $this, 'post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'submenu_file', array( $this, 'highlight' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/** The submenu lists the screens, so the core's own tab bar is hidden (.dwc-host-nav). */
	public function body_class( $classes ) {
		if ( self::on_page() ) {
			$classes .= ' dwc-host-nav';
		}
		return $classes;
	}

	private static function on_page(): bool {
		return isset( $_GET['page'] ) && DWCAT_WP_Host::ADMIN_PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Menu labels, translated with literal strings so the extractor finds them. */
	public static function menu_labels(): array {
		return array(
			'dashboard'     => __( 'Dashboard', 'dw-wholesale' ),
			'products'      => __( 'Products', 'dw-wholesale' ),
			'categories'    => __( 'Categories', 'dw-wholesale' ),
			'fields'        => __( 'Fields', 'dw-wholesale' ),
			'customers'     => __( 'Customers', 'dw-wholesale' ),
			'import_export' => __( 'Import / Export', 'dw-wholesale' ),
			'migration'     => __( 'Migration', 'dw-wholesale' ),
			'shortcodes'    => __( 'Shortcodes', 'dw-wholesale' ),
			'design'        => __( 'Design', 'dw-wholesale' ),
			'presets'       => __( 'Presets', 'dw-wholesale' ),
			'settings'      => __( 'Settings', 'dw-wholesale' ),
			'access'        => __( 'Access', 'dw-wholesale' ),
		);
	}

	public function menu() {
		try {
			$access = dwcat()->access();
			$first  = $access->firstMenu();
		} catch ( \Throwable $e ) {
			// Database unreachable: administrators still get the menu (the page shows the error).
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$access = null;
			$first  = 'dashboard';
		}
		if ( '' === $first ) {
			return; // nothing this user may open
		}

		$this->hook = (string) add_menu_page( 'DW Wholesale', 'DW Wholesale', 'read', DWCAT_WP_Host::ADMIN_PAGE, array( $this, 'page' ), 'dashicons-products', 21 );
		add_action( 'load-' . $this->hook, array( $this, 'guard' ) );

		$labels = self::menu_labels();
		$isTop  = true;
		foreach ( \DWCatalog\Security\Access::MENUS as $menu => $spec ) {
			if ( $access && ! $access->canView( $menu ) ) {
				continue;
			}
			$view = \DWCatalog\Admin\AdminApp::MENU_VIEW[ $menu ];
			if ( 'import_export' === $menu && $access && ! $access->canEdit( $menu ) ) {
				$view = 'export';
			}
			// The first visible entry shares the top-level slug (WordPress links the parent to it).
			$slug = $isTop ? DWCAT_WP_Host::ADMIN_PAGE : self::view_slug( $view );
			add_submenu_page( DWCAT_WP_Host::ADMIN_PAGE, $labels[ $menu ], $labels[ $menu ], 'read', $slug, $isTop ? array( $this, 'page' ) : '' );
			$isTop = false;
		}
	}

	private static function view_slug( string $view ): string {
		return 'admin.php?page=' . DWCAT_WP_Host::ADMIN_PAGE . '&view=' . $view;
	}

	/**
	 * Before the page prints: refuse a screen the user may not open (hidden menus reached by URL).
	 */
	public function guard() {
		if ( ! dwcat_can_render_admin() ) {
			return; // page() shows the license notice
		}
		try {
			$ok = dwcat()->admin()->canOpen( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} catch ( \Throwable $e ) {
			return; // page() shows the error
		}
		if ( ! $ok ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dw-wholesale' ), esc_html__( 'Not allowed', 'dw-wholesale' ), array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/** Keep the right submenu item highlighted for ?view=. */
	public function highlight( $submenu_file, $parent_file ) {
		if ( DWCAT_WP_Host::ADMIN_PAGE !== $parent_file && ! self::on_page() ) {
			return $submenu_file;
		}
		try {
			$view = dwcat()->admin()->resolveView( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$menu = \DWCatalog\Admin\AdminApp::VIEWS[ $view ];
			$first = dwcat()->access()->firstMenu();
		} catch ( \Throwable $e ) {
			return $submenu_file;
		}
		if ( $menu === $first ) {
			return DWCAT_WP_Host::ADMIN_PAGE;
		}
		$target = \DWCatalog\Admin\AdminApp::MENU_VIEW[ $menu ];
		if ( 'import_export' === $menu && ! dwcat()->access()->canEdit( $menu ) ) {
			$target = 'export';
		}
		return self::view_slug( $target );
	}

	public function assets( $hook ) {
		if ( ! $this->hook || $hook !== $this->hook ) {
			return;
		}
		$host = new DWCAT_WP_Host();
		wp_enqueue_style( 'dwcat-admin-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Sans+KR:wght@400;500;700&family=IBM+Plex+Mono:wght@400;500&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion — Google Fonts URL
		wp_enqueue_style( 'dwcat-admin', $host->assetUrl( 'admin.css' ), array( 'dwcat-admin-fonts' ), $host->version() );
		wp_enqueue_script( 'dwcat-admin', $host->assetUrl( 'admin.js' ), array(), $host->version(), true );
	}

	public function page() {
		// 게이트 7 — 라이선스가 없으면 카탈로그 관리 화면을 렌더하지 않습니다.
		if ( ! dwcat_can_render_admin() ) {
			printf(
				'<div class="wrap"><h1>DW Wholesale</h1><div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div></div>',
				esc_html__( 'The license is not active, so the catalog cannot be shown.', 'dw-wholesale' ),
				esc_url( admin_url( 'admin.php?page=dw-wholesale-license' ) ),
				esc_html__( 'Go to license settings', 'dw-wholesale' )
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
			wp_die( esc_html__( 'DW Wholesale: the license is not active, so this action cannot run.', 'dw-wholesale' ), 403 );
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
