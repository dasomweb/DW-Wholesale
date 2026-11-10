<?php
/**
 * WordPress front-end integration: shortcodes, pretty item URLs (/{page}/item/{slug}/),
 * SEO tags for item pages, XML sitemap of items, assets.
 *
 * The page is designed in WordPress; everything inside the shortcode comes from the core.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Front {

	const ENDPOINT = 'item';

	/** SEO data for the current item page (set in template_redirect). */
	private $seo = null;

	public function __construct() {
		add_action( 'init', array( $this, 'endpoint' ) );
		add_shortcode( 'dw_catalog', array( $this, 'shortcode_catalog' ) );
		add_shortcode( 'dw_catalog_grid', array( $this, 'shortcode_grid' ) );
		add_shortcode( 'dw_catalog_carousel', array( $this, 'shortcode_carousel' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'template_redirect', array( $this, 'item_page' ), 5 );
		add_action( 'wp_sitemaps_init', array( $this, 'sitemap' ) );
		add_action( 'save_post_page', array( $this, 'forget_catalog_page' ) );
	}

	public function endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	// ─────────────────────────────────────────────────────────────
	// Context
	// ─────────────────────────────────────────────────────────────

	private function context_for( $page_id ) {
		$url  = get_permalink( $page_id );
		$base = get_option( 'permalink_structure' ) ? trailingslashit( $url ) . self::ENDPOINT . '/' : '';
		$slug = get_query_var( self::ENDPOINT, '' );
		return new \DWCatalog\Front\Context( $url, $base, wp_unslash( $_GET ), is_string( $slug ) ? trim( $slug, '/' ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification — read-only list state
	}

	/** First published page with [dw_catalog] (no category preferred) — target for grid links & sitemap. */
	public static function catalog_page_id() {
		$id = get_transient( 'dwcat_catalog_page' );
		if ( false !== $id ) {
			return (int) $id;
		}
		global $wpdb;
		$best = 0;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 50", '%' . $wpdb->esc_like( '[dw_catalog' ) . '%' ) );
		foreach ( $rows as $row ) {
			if ( ! has_shortcode( $row->post_content, 'dw_catalog' ) ) {
				continue;
			}
			$atts = self::shortcode_atts_in( $row->post_content );
			if ( empty( $atts['category'] ) ) {
				$best = (int) $row->ID;
				break;
			}
			$best = $best ? $best : (int) $row->ID;
		}
		set_transient( 'dwcat_catalog_page', $best, DAY_IN_SECONDS );
		return $best;
	}

	public function forget_catalog_page() {
		delete_transient( 'dwcat_catalog_page' );
	}

	private static function shortcode_atts_in( $content ) {
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'dw_catalog' ) ) . '/', (string) $content, $m, PREG_SET_ORDER ) ) {
			$atts = shortcode_parse_atts( $m[0][3] );
			return is_array( $atts ) ? $atts : array();
		}
		return null;
	}

	// ─────────────────────────────────────────────────────────────
	// Shortcodes
	// ─────────────────────────────────────────────────────────────

	public function shortcode_catalog( $atts ) {
		// 게이트 1 + 8 — 라이선스 없이는 프런트에 카탈로그를 렌더하지 않습니다.
		if ( ! dwcat_can_render() || ! dwcat_can_use_shortcode() ) {
			return dwcat_gate_placeholder();
		}
		$this->enqueue( true, false );
		$atts = shortcode_atts( \DWCatalog\Front\FrontApp::defaults(), $atts, 'dw_catalog' );
		return $this->safely( function () use ( $atts ) {
			return dwcat()->front()->catalog( $atts, $this->context_for( get_the_ID() ) );
		} );
	}

	public function shortcode_grid( $atts ) {
		return $this->block( $atts, false, 'dw_catalog_grid' );
	}

	public function shortcode_carousel( $atts ) {
		return $this->block( $atts, true, 'dw_catalog_carousel' );
	}

	private function block( $atts, $carousel, $tag ) {
		// 게이트 1 + 8
		if ( ! dwcat_can_render() || ! dwcat_can_use_shortcode() ) {
			return dwcat_gate_placeholder();
		}
		$this->enqueue( false, $carousel );
		$atts = shortcode_atts( array( 'type' => '', 'category' => '', 'per_page' => 12, 'columns' => 0, 'per_slide' => 3, 'ids' => '', 'show_fields' => '', 'orderby' => 'date', 'order' => 'DESC', 'autoplay' => 'yes', 'interval' => 5000 ), $atts, $tag );
		$page = self::catalog_page_id();
		return $this->safely( function () use ( $atts, $carousel, $page ) {
			$ctx = $page ? $this->context_for( $page ) : new \DWCatalog\Front\Context( home_url( '/' ) );
			return dwcat()->front()->grid( $atts, $ctx, $carousel );
		} );
	}

	private function safely( callable $fn ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			return current_user_can( 'manage_options' ) ? '<p class="dwcat-empty">DW Wholesale: ' . esc_html( $e->getMessage() ) . '</p>' : '';
		}
	}

	// ─────────────────────────────────────────────────────────────
	// Assets
	// ─────────────────────────────────────────────────────────────

	public function assets() {
		$post = get_post();
		if ( ! is_singular() || ! $post ) {
			return;
		}
		$catalog  = has_shortcode( $post->post_content, 'dw_catalog' );
		$carousel = has_shortcode( $post->post_content, 'dw_catalog_carousel' );
		if ( $catalog || $carousel || has_shortcode( $post->post_content, 'dw_catalog_grid' ) ) {
			$this->enqueue( $catalog, $carousel );
		}
	}

	private function enqueue( $catalog, $carousel ) {
		// 게이트 2 — 프런트 CSS/JS
		if ( ! dwcat_can_load_assets() ) {
			return;
		}
		$host = new DWCAT_WP_Host();
		wp_enqueue_style( 'dwcat-front', $host->assetUrl( 'front.css' ), array(), $host->version() );
		try {
			$font = \DWCatalog\Front\Design::fontUrl( dwcat()->settings() );
			if ( '' !== $font ) {
				wp_enqueue_style( 'dwcat-font', $font, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion — Google Fonts URL
			}
		} catch ( \Throwable $e ) { // phpcs:ignore — catalog DB unavailable: theme font
		}
		if ( $catalog ) {
			wp_enqueue_script( 'dwcat-catalog', $host->assetUrl( 'catalog.js' ), array(), $host->version(), true );
		}
		if ( $carousel ) {
			wp_enqueue_script( 'dwcat-carousel', $host->assetUrl( 'carousel.js' ), array(), $host->version(), true );
		}
	}

	// ─────────────────────────────────────────────────────────────
	// Item pages: status, title, SEO tags
	// ─────────────────────────────────────────────────────────────

	public function item_page() {
		$slug = get_query_var( self::ENDPOINT, null );
		$asks = ( null !== $slug && '' !== $slug ) || ! empty( $_GET['dw_item'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $asks || ! is_page() ) {
			return;
		}
		$atts = self::shortcode_atts_in( get_post_field( 'post_content', get_queried_object_id() ) );
		if ( null === $atts || ! dwcat_can_render() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$atts = shortcode_atts( \DWCatalog\Front\FrontApp::defaults(), $atts, 'dw_catalog' );
		$ctx  = $this->context_for( get_queried_object_id() );
		try {
			$item = dwcat()->front()->requestedItem( $atts, $ctx );
		} catch ( \Throwable $e ) {
			$item = null;
		}
		if ( ! $item ) {
			status_header( 404 );
			nocache_headers();
			return;
		}
		// One canonical URL per item (the main catalog page, as in the sitemap) even when the
		// item is also reachable from category pages — no duplicate-content signals.
		$main      = self::catalog_page_id();
		$this->seo = dwcat()->front()->seo( $item, $main ? $this->context_for( $main ) : $ctx );
		remove_action( 'wp_head', 'rel_canonical' );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
	}

	public function title( $parts ) {
		$parts['title'] = $this->seo['title'];
		return $parts;
	}

	public function head() {
		$s = $this->seo;
		echo '<link rel="canonical" href="' . esc_url( $s['canonical'] ) . '">' . "\n";
		if ( '' !== $s['description'] ) {
			echo '<meta name="description" content="' . esc_attr( $s['description'] ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $s['description'] ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="product">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $s['title'] ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $s['canonical'] ) . '">' . "\n";
		if ( '' !== $s['image'] ) {
			echo '<meta property="og:image" content="' . esc_url( $s['image'] ) . '">' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		}
		echo '<script type="application/ld+json">' . \DWCatalog\Support\Html::json( $s['json_ld'] ) . '</script>' . "\n";
	}

	// ─────────────────────────────────────────────────────────────
	// Sitemap: /wp-sitemap-dwcatalog-1.xml
	// ─────────────────────────────────────────────────────────────

	public function sitemap( $sitemaps ) {
		if ( ! class_exists( 'WP_Sitemaps_Provider' ) || ! self::catalog_page_id() || ! dwcat_can_render() ) {
			return;
		}
		$front = $this;
		$sitemaps->registry->add_provider( 'dwcatalog', new class( $front ) extends WP_Sitemaps_Provider {
			private $front;
			public function __construct( $front ) {
				$this->front       = $front;
				$this->name        = 'dwcatalog';
				$this->object_type = 'dwcatalog';
			}
			public function get_url_list( $page_num, $object_subtype = '' ) {
				$page = DWCAT_WP_Front::catalog_page_id();
				$ctx  = new \DWCatalog\Front\Context( get_permalink( $page ), get_option( 'permalink_structure' ) ? trailingslashit( get_permalink( $page ) ) . DWCAT_WP_Front::ENDPOINT . '/' : '' );
				$c    = dwcat();
				$rows = $c->db()->select( 'SELECT slug, updated_at FROM ' . $c->db()->table( 'items' ) . ' WHERE type = ? AND status = ? ORDER BY id ASC LIMIT ? OFFSET ?', array( $c->fields()->primaryType(), 'publish', 2000, max( 0, (int) $page_num - 1 ) * 2000 ) );
				return array_map( function ( $r ) use ( $ctx ) {
					return array( 'loc' => $ctx->itemUrl( $r['slug'] ), 'lastmod' => gmdate( 'c', strtotime( $r['updated_at'] . ' UTC' ) ) );
				}, $rows );
			}
			public function get_max_num_pages( $object_subtype = '' ) {
				$c = dwcat();
				return (int) ceil( (int) $c->db()->selectValue( 'SELECT COUNT(*) FROM ' . $c->db()->table( 'items' ) . ' WHERE type = ? AND status = ?', array( $c->fields()->primaryType(), 'publish' ) ) / 2000 );
			}
		} );
	}
}
