<?php
/**
 * WordPress side of the migration (DW Wholesale → Migration):
 *   - keeps a started run going in the background (WP-Cron) when the browser tab is closed
 *   - 301 from old product addresses (/product/{slug}/) to the new product page
 *   - closes the old catalog's open REST list (/wp-json/wp/v2/product) to visitors
 *
 * The two switches live in the catalog settings; they are mirrored into autoloaded options so a
 * normal page view costs no extra query.
 *
 * @package DW_Wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Migration {

	const CRON         = 'dwcat_migration_tick';
	const OPT_REDIRECT = 'dwcat_mig_redirects';
	const OPT_REST     = 'dwcat_mig_rest_block';

	public function __construct() {
		add_action( self::CRON, array( $this, 'tick' ) );
		if ( get_option( self::OPT_REDIRECT ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_old_url' ), 1 );
		}
		if ( get_option( self::OPT_REST ) ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'block_rest' ), 10, 3 );
		}
	}

	/** After a migration action in the admin: mirror the switches, keep the background run alive. */
	public static function after_action(): void {
		try {
			$m = dwcat()->migrator();
			if ( ! $m ) {
				return;
			}
			$state = $m->state();
		} catch ( \Throwable $e ) {
			return;
		}
		update_option( self::OPT_REDIRECT, $state['finish']['redirects'] ? 1 : 0, true );
		update_option( self::OPT_REST, $state['finish']['rest_block'] ? 1 : 0, true );
		if ( 'running' === $state['run']['status'] ) {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_single_event( time() + 60, self::CRON );
			}
		} else {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/** Background: a few batches, then book the next tick while there is work left. */
	public function tick() {
		if ( ! dwcat_can_bulk_import() ) {
			return;
		}
		try {
			$m = dwcat()->migrator();
			if ( ! $m ) {
				return;
			}
			$until = time() + 20;
			do {
				$run = $m->batch();
			} while ( 'running' === $run['status'] && empty( $run['busy'] ) && time() < $until );
			if ( 'running' === $run['status'] ) {
				wp_schedule_single_event( time() + 60, self::CRON );
			}
		} catch ( \Throwable $e ) {
			error_log( 'DW Wholesale migration: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/** /product/{old-slug}/ → the migrated product (301). Unknown slugs fall through untouched. */
	public function redirect_old_url() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( ! preg_match( '#^/' . DWCAT_WP_Migration_Source::POST_TYPE . '/([^/]+)/?$#', $path, $m ) ) {
			return;
		}
		try {
			$c        = dwcat();
			$migrator = $c->migrator();
			$id       = $migrator ? $migrator->itemForOldSlug( sanitize_title( rawurldecode( $m[1] ) ) ) : 0;
			$item     = $id ? $c->items()->get( $id ) : null;
			$page     = $item && 'publish' === $item['status'] ? DWCAT_WP_Front::catalog_page_id() : 0;
		} catch ( \Throwable $e ) {
			return;
		}
		if ( ! $page ) {
			return;
		}
		$url = get_permalink( $page );
		$ctx = new \DWCatalog\Front\Context( $url, get_option( 'permalink_structure' ) ? trailingslashit( $url ) . DWCAT_WP_Front::ENDPOINT . '/' : '' );
		wp_safe_redirect( $ctx->itemUrl( $item['slug'] ), 301, 'DW Wholesale' );
		exit;
	}

	/** The old catalog listed every product to anyone at /wp-json/wp/v2/product. Staff only now. */
	public function block_rest( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( preg_match( '#^/wp/v2/(' . DWCAT_WP_Migration_Source::POST_TYPE . '|' . DWCAT_WP_Migration_Source::TAXONOMY . ')(/|$)#', $route ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'dw-wholesale' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return $result;
	}
}
