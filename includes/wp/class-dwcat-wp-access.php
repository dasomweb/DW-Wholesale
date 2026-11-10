<?php
/**
 * Operator mode (DW Wholesale → Access): keeps staff who are not administrators on the
 * wholesale screens.
 *
 *   - Appearance, Plugins and Tools menus are removed for them …
 *   - … and those pages are refused when opened by address (HTTP 403)
 *   - after logging in they land on the DW Wholesale dashboard (if they may open it)
 *
 * Administrators are never affected. The menu matrix itself is enforced by DWCAT_WP_Admin + core.
 *
 * @package DW_Wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Access {

	/** Top-level menus removed in operator mode. */
	const HIDDEN_MENUS = array( 'themes.php', 'plugins.php', 'tools.php' );

	/** Screens refused in operator mode (Appearance, Plugins and Tools, including their sub-pages). */
	const BLOCKED_SCREENS = array(
		'themes.php', 'theme-install.php', 'theme-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php', 'site-editor.php',
		'plugins.php', 'plugin-install.php', 'plugin-editor.php',
		'tools.php', 'import.php', 'export.php', 'site-health.php', 'export-personal-data.php', 'erase-personal-data.php',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menus' ), 999 );
		add_action( 'admin_init', array( $this, 'block' ), 1 );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
	}

	/** Operator mode applies to this user (on, and not an administrator). */
	public static function applies( $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || DWCAT_WP_Host::is_administrator( $user ) ) {
			return false;
		}
		try {
			return dwcat()->access()->operatorMode();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function menus() {
		if ( ! self::applies() ) {
			return;
		}
		foreach ( self::HIDDEN_MENUS as $slug ) {
			remove_menu_page( $slug );
		}
	}

	public function block() {
		if ( wp_doing_ajax() || ! self::applies() ) {
			return;
		}
		global $pagenow;
		if ( in_array( (string) $pagenow, self::BLOCKED_SCREENS, true ) ) {
			wp_die( esc_html__( 'This page is not available to your account.', 'dw-wholesale' ), esc_html__( 'Not allowed', 'dw-wholesale' ), array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/**
	 * @param string           $redirect_to
	 * @param string           $requested
	 * @param WP_User|WP_Error $user
	 */
	public function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! self::applies( $user ) ) {
			return $redirect_to;
		}
		try {
			$access = dwcat()->access();
			$open   = $access->firstMenuFor( (array) $user->roles, false );
		} catch ( \Throwable $e ) {
			return $redirect_to;
		}
		if ( '' === $open ) {
			return $redirect_to;
		}
		// Only replace the default destination (the WordPress dashboard), not a page they asked for.
		$default = admin_url();
		if ( '' !== (string) $requested && untrailingslashit( (string) $requested ) !== untrailingslashit( $default ) && false === strpos( (string) $requested, 'wp-admin/index.php' ) ) {
			return $redirect_to;
		}
		return admin_url( 'admin.php?page=' . DWCAT_WP_Host::ADMIN_PAGE );
	}
}
