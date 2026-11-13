<?php
/**
 * WordPress implementation of the core Host: user + capabilities, license policy, uploads dir,
 * admin URLs, translation, transients.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Host implements \DWCatalog\Host\Host {

	const ADMIN_PAGE = 'dw-wholesale';

	public function userId(): int {
		return (int) get_current_user_id();
	}

	/**
	 * Roles of the current user. What a role may do in the catalog is decided by the core
	 * (Security\Access, the DW Wholesale → Access screen), not by WordPress capabilities.
	 */
	public function userRoles(): array {
		$user = wp_get_current_user();
		return $user && $user->exists() ? array_values( (array) $user->roles ) : array();
	}

	/** The site administrator (or a network super admin) always has every permission. */
	public function isAdministrator(): bool {
		return self::is_administrator( wp_get_current_user() );
	}

	/** @param WP_User|false|null $user */
	public static function is_administrator( $user ): bool {
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	/** Standard roles get the plugin's own labels so they follow the DW Wholesale admin language. */
	public function roles(): array {
		$own = array(
			'administrator' => __( 'Administrator', 'dw-wholesale' ),
			'editor'        => __( 'Editor', 'dw-wholesale' ),
			'author'        => __( 'Author', 'dw-wholesale' ),
			'contributor'   => __( 'Contributor', 'dw-wholesale' ),
			'subscriber'    => __( 'Subscriber', 'dw-wholesale' ),
		);
		$out = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$out[ $slug ] = isset( $own[ $slug ] ) ? $own[ $slug ] : translate_user_role( $name );
		}
		return $out;
	}

	public function userMeta( string $key ): string {
		$uid = get_current_user_id();
		return $uid ? (string) get_user_meta( $uid, $key, true ) : '';
	}

	public function setUserMeta( string $key, string $value ): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		if ( '' === $value ) {
			delete_user_meta( $uid, $key );
		} else {
			update_user_meta( $uid, $key, $value );
		}
	}

	public function userLocale(): string {
		return get_current_user_id() ? (string) get_user_locale() : (string) get_locale();
	}

	public function siteLocale(): string {
		return (string) get_locale();
	}

	/**
	 * License policy. Each feature maps to its own gate (gates.php) — the same gates are also
	 * checked again at the WordPress entry points, so no single function decides alone.
	 */
	public function allows( string $feature ): bool {
		switch ( $feature ) {
			case 'render':
				return dwcat_can_render() && dwcat_can_use_shortcode();
			case 'assets':
				return dwcat_can_load_assets();
			case 'save':
				return dwcat_can_save_meta();
			case 'manage_fields':
				return dwcat_can_manage_fields();
			case 'import':
				return dwcat_can_bulk_import();
			case 'export':
				return dwcat_can_export_pdf();
			case 'admin':
				return dwcat_can_render_admin();
		}
		return false;
	}

	public function secret(): string {
		return hash_hmac( 'sha256', 'dw-catalog-core', wp_salt( 'auth' ) );
	}

	public function translate( string $text ): string {
		return translate( $text, 'dw-wholesale' ); // phpcs:ignore WordPress.WP.I18n — core strings are dynamic
	}

	public function sanitizeHtml( string $html ): string {
		return wp_kses_post( $html );
	}

	public function richText( string $name, string $html ): string {
		ob_start();
		wp_editor( $html, 'dwc_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $name ) ), array(
			'textarea_name' => $name,
			'media_buttons' => false, // catalog images live in the catalog, not the media library
			'textarea_rows' => 12,
		) );
		return (string) ob_get_clean();
	}

	public function storageDir(): string {
		$up = wp_upload_dir( null, false );
		return rtrim( $up['basedir'], '/\\' ) . '/dw-catalog';
	}

	public function storageUrl(): string {
		$up = wp_upload_dir( null, false );
		return rtrim( set_url_scheme( $up['baseurl'] ), '/' ) . '/dw-catalog';
	}

	/**
	 * Files that must not be reachable by URL (application attachments). Web servers are told to
	 * refuse the directory, and the files themselves carry random names without their extension, so
	 * a server that ignores .htaccess still gives nothing away.
	 */
	public function privateDir(): string {
		$up  = wp_upload_dir( null, false );
		$dir = rtrim( $up['basedir'], '/\\' ) . '/dw-wholesale-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) && ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
			@file_put_contents( $dir . '/web.config', '<?xml version="1.0"?><configuration><system.web><authorization><deny users="*"/></authorization></system.web></configuration>' ); // phpcs:ignore
		}
		return $dir;
	}

	/** The older catalog on this site (DW Product Catalog posts), if there is one. */
	public function migrationSource(): ?\DWCatalog\Migration\Source {
		return DWCAT_WP_Migration_Source::exists() ? new DWCAT_WP_Migration_Source() : null;
	}

	/** Pages that carry a catalog shortcode. */
	public function catalogPages(): array {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 30", '%' . $wpdb->esc_like( '[dw_catalog' ) . '%' ) );
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '/\[dw_catalog[^\]]*\]/', (string) $row->post_content, $m ) ) {
				$out[] = array( 'title' => (string) $row->post_title, 'url' => (string) get_permalink( (int) $row->ID ), 'edit_url' => (string) get_edit_post_link( (int) $row->ID, 'raw' ), 'shortcode' => $m[0] );
			}
		}
		return $out;
	}

	public function adminUrl( array $params = array() ): string {
		$params = array_filter( $params, function ( $v ) {
			return null !== $v && '' !== $v;
		} );
		return admin_url( 'admin.php?' . http_build_query( array_merge( array( 'page' => self::ADMIN_PAGE ), $params ) ) );
	}

	public function adminPostUrl(): string {
		return admin_url( 'admin-post.php?action=dwcat' );
	}

	public function assetUrl( string $file ): string {
		return plugins_url( 'core/assets/' . $file, dwcat_get_file() );
	}

	public function version(): string {
		$config = dwcat_get_config();
		return $config['plugin_version'];
	}

	public function remember( string $key, int $ttl, callable $fn ) {
		$key    = substr( $key, 0, 172 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = $fn();
		set_transient( $key, $value, $ttl );
		return $value;
	}
}
