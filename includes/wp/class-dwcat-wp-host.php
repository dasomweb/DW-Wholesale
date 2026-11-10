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

	const ADMIN_PAGE = 'dw-catalog';

	/** Core capability → WordPress capability. */
	const CAPS = array(
		'catalog.edit'   => 'edit_posts',
		'catalog.delete' => 'delete_posts',
		'catalog.terms'  => 'manage_categories',
		'catalog.manage' => 'manage_options',
		'catalog.import' => 'edit_posts',
		'catalog.export' => 'edit_posts',
	);

	public function userId(): int {
		return (int) get_current_user_id();
	}

	public function can( string $capability ): bool {
		return isset( self::CAPS[ $capability ] ) && current_user_can( self::CAPS[ $capability ] );
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
