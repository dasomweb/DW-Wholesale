<?php
/**
 * The older catalog on this WordPress site, read for the migration:
 *   - DW Product Catalog: posts of type "product", meta dw_pc_*, taxonomy "product_category"
 *   - DW Wholesale Customer Management: table {prefix}dw_wcm_applications (prefix detected)
 *   - DW Admin: users with dw_admin_* user meta
 *   - Kadence query blocks on pages: which categories each catalog page shows (= category groups)
 *
 * Everything is read with $wpdb, so it works whether the old plugins are active or not.
 * The only writes are replacePage() / restorePage() on site pages (a revision is kept by WordPress,
 * and the migration keeps the original content for its own rollback).
 *
 * @package DW_Wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_Migration_Source implements \DWCatalog\Migration\Source {

	const POST_TYPE = 'product';
	const TAXONOMY  = 'product_category';
	const META_LIKE = 'dw\_pc\_%';

	/** Known fields in the order the old catalog shows them. Other dw_pc_* keys follow. */
	const FIELDS = array(
		'dw_pc_product_name'  => 'Product Name',
		'dw_pc_item_code'     => 'Item Code',
		'dw_pc_pack_size_raw' => 'Pack Size',
		'dw_pc_brand_raw'     => 'Brand',
		'dw_pc_origin_raw'    => 'Origin',
		'dw_pc_status'        => 'Status',
		'dw_pc_internal_note' => 'Internal note',
	);

	/** @var array|null */
	private $detected = null;
	/** @var array|null */
	private $cats = null;

	/** Is there an older catalog on this site at all? (one indexed query) */
	public static function exists(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", self::META_LIKE ) );
	}

	public function detect(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}
		global $wpdb;
		$public = 0;
		$hidden = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_status AS s, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') GROUP BY post_status", self::POST_TYPE ) ) as $row ) {
			'publish' === $row->s ? $public += (int) $row->n : $hidden += (int) $row->n;
		}
		$images = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value > 0 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit')", self::POST_TYPE ) );
		$found  = self::exists();
		$table  = $this->application_table();
		$users  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'dw\\_admin\\_%'" );

		$this->detected = array(
			'products'     => array( 'found' => $found && ( $public + $hidden ) > 0, 'count' => $found ? $public + $hidden : 0, 'public' => $found ? $public : 0, 'hidden' => $found ? $hidden : 0, 'with_image' => $found ? $images : 0 ),
			'categories'   => array( 'found' => count( $this->categories() ) > 0, 'count' => count( $this->categories() ) ),
			'applications' => array( 'found' => '' !== $table, 'count' => '' !== $table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0, 'table' => $table ), // phpcs:ignore WordPress.DB.PreparedSQL — table name from SHOW TABLES
			'users'        => array( 'found' => $users > 0, 'count' => $users ),
		);
		return $this->detected;
	}

	/** The application table, whatever prefix it was created with ('' if none). */
	private function application_table(): string {
		global $wpdb;
		$own = $wpdb->prefix . 'dw_wcm_applications';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $own ) ) === $own ) {
			return $own;
		}
		$any = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( 'dw_wcm_applications' ) ) );
		foreach ( (array) $any as $name ) {
			if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ) {
				return (string) $name;
			}
		}
		return '';
	}

	public function productFields(): array {
		global $wpdb;
		$out  = self::FIELDS;
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_key LIMIT 100", self::META_LIKE ) );
		foreach ( (array) $keys as $key ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = ucwords( str_replace( '_', ' ', preg_replace( '/^dw_pc_|_raw$/', '', $key ) ) );
			}
		}
		return $out;
	}

	public function products( int $afterId, int $limit ): array {
		global $wpdb;
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_name, post_title, post_content, post_status, post_date_gmt, post_date, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') AND ID > %d ORDER BY ID ASC LIMIT %d",
			self::POST_TYPE,
			$afterId,
			max( 1, min( 500, $limit ) )
		) );
		if ( ! $posts ) {
			return array();
		}
		$ids  = array_map( function ( $p ) {
			return (int) $p->ID;
		}, $posts );
		$in   = implode( ',', $ids ); // integers only
		$meta = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND ( meta_key LIKE %s OR meta_key = '_thumbnail_id' )", self::META_LIKE ) ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$meta[ (int) $m->post_id ][ $m->meta_key ] = is_scalar( $m->meta_value ) ? (string) $m->meta_value : '';
		}
		$cats = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s WHERE tr.object_id IN ($in)", self::TAXONOMY ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cats[ (int) $r->object_id ][] = (int) $r->term_id;
		}
		$out = array();
		foreach ( $posts as $p ) {
			$id    = (int) $p->ID;
			$m     = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$thumb = isset( $m['_thumbnail_id'] ) ? (int) $m['_thumbnail_id'] : 0;
			unset( $m['_thumbnail_id'] );
			$image = null;
			if ( $thumb ) {
				$path = get_attached_file( $thumb );
				if ( $path ) {
					$image = array( 'path' => $path, 'name' => basename( $path ), 'alt' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
				}
			}
			$out[] = array(
				'id'         => $id,
				'slug'       => (string) $p->post_name,
				'title'      => (string) $p->post_title,
				'content'    => (string) $p->post_content,
				'status'     => 'publish' === $p->post_status ? 'publish' : 'hidden',
				'created'    => '0000-00-00 00:00:00' !== $p->post_date_gmt ? (string) $p->post_date_gmt : (string) $p->post_date,
				'modified'   => (string) $p->post_modified_gmt,
				'meta'       => $m,
				'categories' => isset( $cats[ $id ] ) ? $cats[ $id ] : array(),
				'image'      => $image,
			);
		}
		return $out;
	}

	public function categories(): array {
		if ( null === $this->cats ) {
			global $wpdb;
			$this->cats = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s ORDER BY t.name ASC", self::TAXONOMY ) ) as $t ) {
				$this->cats[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count );
			}
		}
		return $this->cats;
	}

	// ── Pages with Kadence query blocks ─────────────────────────

	/** Published / draft / private pages that contain a Kadence query block. */
	private function query_pages(): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_name, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private') AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 200",
			'%' . $wpdb->esc_like( '<!-- wp:kadence/query' ) . '%'
		) );
	}

	/** Query block openers of a page: [ full match, attributes (array) ]. */
	private static function query_blocks( string $content ): array {
		$out = array();
		if ( preg_match_all( '#<!--\s+wp:kadence/query\s+(\{.*?\})?\s*/?-->#s', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$attrs = isset( $hit[1] ) ? json_decode( $hit[1], true ) : null;
				$out[] = array( $hit[0], is_array( $attrs ) ? $attrs : array() );
			}
		}
		return $out;
	}

	public function groups(): array {
		$ids   = array();
		$slugs = array();
		foreach ( $this->categories() as $cat ) {
			$ids[ $cat['id'] ]     = true;
			$slugs[ $cat['slug'] ] = $cat['id'];
		}
		$out = array();
		foreach ( $this->query_pages() as $page ) {
			$found = array();
			foreach ( self::query_blocks( (string) $page->post_content ) as $block ) {
				self::collect( $block[1], false, $ids, $slugs, $found );
				// Kadence keeps the query itself in a "kadence_query" post: {"id":123}
				$qid = isset( $block[1]['id'] ) ? (int) $block[1]['id'] : 0;
				if ( $qid && 'kadence_query' === get_post_type( $qid ) ) {
					foreach ( (array) get_post_meta( $qid ) as $values ) {
						foreach ( (array) $values as $raw ) {
							$value = maybe_unserialize( $raw );
							if ( is_string( $value ) && '' !== $value && ( '{' === $value[0] || '[' === $value[0] ) ) {
								$value = json_decode( $value, true );
							}
							if ( is_array( $value ) ) {
								self::collect( $value, false, $ids, $slugs, $found );
							}
						}
					}
				}
			}
			if ( $found ) {
				$out[] = array( 'name' => (string) $page->post_title, 'slug' => (string) $page->post_name, 'page_id' => (int) $page->ID, 'categories' => array_keys( $found ) );
			}
		}
		return $out;
	}

	/**
	 * Walk block attributes / query settings and collect product_category terms, wherever the page
	 * builder keeps them: {"taxonomy":"product_category","terms":[..]}, {"product_category":[..]}, …
	 */
	private static function collect( $node, bool $inTax, array $ids, array $slugs, array &$found ): void {
		if ( is_array( $node ) ) {
			$here = $inTax;
			foreach ( array( 'taxonomy', 'tax', 'slug', 'type' ) as $k ) {
				if ( isset( $node[ $k ] ) && self::TAXONOMY === $node[ $k ] ) {
					$here = true;
				}
			}
			foreach ( $node as $key => $value ) {
				self::collect( $value, $here || ( is_string( $key ) && false !== strpos( $key, self::TAXONOMY ) ), $ids, $slugs, $found );
			}
			return;
		}
		if ( ! $inTax || ! is_scalar( $node ) ) {
			return;
		}
		foreach ( explode( ',', (string) $node ) as $part ) {
			$part = trim( $part );
			if ( ctype_digit( $part ) && isset( $ids[ (int) $part ] ) ) {
				$found[ (int) $part ] = true;
			} elseif ( isset( $slugs[ $part ] ) ) {
				$found[ $slugs[ $part ] ] = true;
			}
		}
	}

	public function pages(): array {
		$out = array();
		foreach ( $this->query_pages() as $page ) {
			$out[] = array(
				'id'         => (int) $page->ID,
				'title'      => (string) $page->post_title,
				'url'        => (string) get_permalink( (int) $page->ID ),
				'content'    => (string) $page->post_content,
				'blocks'     => count( self::query_blocks( (string) $page->post_content ) ),
				'group_slug' => (string) $page->post_name,
			);
		}
		return $out;
	}

	public function replacePage( int $pageId, string $shortcode, bool $apply ): ?string {
		$post = get_post( $pageId );
		if ( ! $post || 'page' !== $post->post_type ) {
			return null;
		}
		$first   = true;
		$replace = function () use ( &$first, $shortcode ) {
			$out   = $first ? "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->" : '';
			$first = false;
			return $out;
		};
		// Block with inner content first, then the self-closing form.
		$content = preg_replace_callback( '#<!--\s+wp:kadence/query\s+(?:\{.*?\}\s+)?-->.*?<!--\s+/wp:kadence/query\s+-->#s', $replace, (string) $post->post_content );
		$content = preg_replace_callback( '#<!--\s+wp:kadence/query\s+(?:\{.*?\}\s+)?/-->#s', $replace, (string) $content );
		if ( null === $content || $content === $post->post_content ) {
			return null;
		}
		if ( $apply ) {
			wp_update_post( wp_slash( array( 'ID' => $pageId, 'post_content' => $content ) ) );
		}
		return $content;
	}

	public function restorePage( int $pageId, string $content ): void {
		if ( get_post( $pageId ) ) {
			wp_update_post( wp_slash( array( 'ID' => $pageId, 'post_content' => $content ) ) );
		}
	}

	public function oldUrl( string $slug ): string {
		return '/' . self::POST_TYPE . '/' . $slug . '/';
	}

	// ── Applications ────────────────────────────────────────────

	public function applications( int $afterId, int $limit ): array {
		global $wpdb;
		$table = $this->application_table();
		if ( '' === $table ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", $afterId, max( 1, min( 200, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL — table name from SHOW TABLES
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$fields = array();
			$files  = array();
			foreach ( $row as $col => $value ) {
				if ( in_array( $col, array( 'id', 'created_at', 'updated_at' ), true ) || null === $value || '' === $value ) {
					continue;
				}
				// File fields hold media-library ids: "12,13"
				if ( preg_match( '/file|attachment|upload/', $col ) && preg_match( '/^\d+(\s*,\s*\d+)*$/', (string) $value ) ) {
					foreach ( array_map( 'intval', explode( ',', (string) $value ) ) as $aid ) {
						$path = get_attached_file( $aid );
						if ( $path ) {
							$files[] = array( 'path' => $path, 'name' => basename( $path ) );
						}
					}
					continue;
				}
				$fields[ $col ] = (string) $value;
			}
			$out[] = array( 'id' => (int) $row['id'], 'created' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '', 'fields' => $fields, 'files' => $files );
		}
		return $out;
	}
}
