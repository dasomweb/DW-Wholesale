<?php
/**
 * In-memory migration source for core tests: a small old catalog with every problem the real one has
 * (empty status, empty brand, a repeated item code, "-1" addresses, a product without photo, a hidden
 * product, an empty category, a category in no group), two catalog pages and two applications.
 */

use DWCatalog\Migration\Source;

class TestSource implements Source {

	public $products     = array();
	public $categories   = array();
	public $groups       = array();
	public $applications = array();
	public $pages        = array();
	/** @var string[] every write the migration made to the site: "replace:ID" / "restore:ID" */
	public $writes = array();

	public function __construct( string $dir ) {
		$img = $dir . '/source-photo.png';
		$im  = imagecreatetruecolor( 40, 30 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 80, 40 ) );
		imagepng( $im, $img );
		$doc = $dir . '/source-permit.pdf';
		file_put_contents( $doc, "%PDF-1.4\n% test\n" );

		$this->categories = array(
			array( 'id' => 11, 'name' => 'Nori', 'slug' => 'nori', 'parent' => 0, 'count' => 3 ),
			array( 'id' => 12, 'name' => 'Wasabi', 'slug' => 'wasabi', 'parent' => 0, 'count' => 2 ),
			array( 'id' => 13, 'name' => 'Frozen Fish', 'slug' => 'frozen-fish', 'parent' => 0, 'count' => 2 ),
			array( 'id' => 14, 'name' => 'Knives', 'slug' => 'knives', 'parent' => 0, 'count' => 1 ), // in no group
			array( 'id' => 15, 'name' => 'Empty Shelf', 'slug' => 'empty-shelf', 'parent' => 0, 'count' => 0 ),
		);
		$this->groups     = array(
			array( 'name' => 'Dry Goods', 'slug' => 'dry-goods', 'page_id' => 501, 'categories' => array( 11, 12 ) ),
			array( 'name' => 'Frozen', 'slug' => 'frozen', 'page_id' => 502, 'categories' => array( 13, 15 ) ),
		);
		$this->pages      = array(
			501 => array( 'id' => 501, 'title' => 'Dry Goods', 'url' => 'https://test.example/dry-goods/', 'content' => '<p>Intro</p><!-- wp:kadence/query {"id":9} /-->', 'blocks' => 1, 'group_slug' => 'dry-goods' ),
			502 => array( 'id' => 502, 'title' => 'Frozen', 'url' => 'https://test.example/frozen/', 'content' => '<!-- wp:kadence/query {"id":10} /-->', 'blocks' => 1, 'group_slug' => 'frozen' ),
		);

		$p = function ( $id, $slug, $title, $status, $meta, $cats, $image = true, $modified = '2026-01-01 00:00:00' ) use ( $img ) {
			return array(
				'id'         => $id,
				'slug'       => $slug,
				'title'      => $title,
				'content'    => '<p>About ' . $title . '</p><script>alert(1)</script>',
				'status'     => $status,
				'created'    => '2025-06-0' . ( $id % 9 + 1 ) . ' 10:00:00',
				'modified'   => $modified,
				'meta'       => $meta + array( 'dw_pc_product_name' => $title ),
				'categories' => $cats,
				'image'      => $image ? array( 'path' => $img, 'name' => 'photo-' . $id . '.png', 'alt' => $title ) : null,
			);
		};
		$this->products = array(
			$p( 101, 'yaki-nori-gold', 'Yaki Nori Gold', 'publish', array( 'dw_pc_item_code' => '10001', 'dw_pc_brand_raw' => 'Sea JEM', 'dw_pc_status' => 'active', 'dw_pc_pack_size_raw' => '10/50', 'dw_pc_origin_raw' => 'Korea', 'dw_pc_internal_note' => 'cost 12.40' ), array( 11 ) ),
			$p( 102, 'yaki-nori-silver', 'Yaki Nori Silver', 'publish', array( 'dw_pc_item_code' => '10002', 'dw_pc_brand_raw' => 'Sea JEM', 'dw_pc_status' => '' ), array( 11 ) ),                    // empty status
			$p( 103, 'nori-flakes-1', 'Nori Flakes', 'publish', array( 'dw_pc_item_code' => '10003', 'dw_pc_brand_raw' => '', 'dw_pc_status' => 'active' ), array( 11 ) ),                               // "-1" address, empty brand
			$p( 104, 'wasabi-powder', 'Wasabi Powder', 'publish', array( 'dw_pc_item_code' => '22162', 'dw_pc_brand_raw' => 'Kinjirushi', 'dw_pc_status' => 'active' ), array( 12 ), true, '2026-02-01 00:00:00' ), // repeated code (older)
			$p( 105, 'wasabi-powder-1', 'Wasabi Powder', 'publish', array( 'dw_pc_item_code' => '22162', 'dw_pc_brand_raw' => 'Kinjirushi', 'dw_pc_status' => 'out_of_stock' ), array( 12 ), true, '2026-03-01 00:00:00' ), // repeated code (latest), "-1"
			$p( 106, 'frozen-eel', 'Frozen Eel', 'publish', array( 'dw_pc_item_code' => '30001', 'dw_pc_brand_raw' => 'Ocean', 'dw_pc_status' => 'discontinued' ), array( 13 ), false ),                 // no photo
			$p( 107, 'frozen-tuna-saku', 'Frozen Tuna Saku', 'hidden', array( 'dw_pc_item_code' => '30002', 'dw_pc_brand_raw' => 'Ocean', 'dw_pc_status' => '' ), array( 13 ) ),                         // hidden + empty status
			$p( 108, 'sashimi-knife-240', 'Sashimi Knife 240', 'publish', array( 'dw_pc_item_code' => '40001', 'dw_pc_brand_raw' => 'Masamoto', 'dw_pc_status' => 'active' ), array( 14 ) ),             // number belongs to the name
		);
		$this->applications = array(
			array( 'id' => 1, 'created' => '2026-04-01 09:00:00', 'fields' => array( 'template_key' => 'default', 'company_name' => 'Sushi Ko', 'first_name' => 'Mina', 'last_name' => 'Park', 'email' => 'Mina@SushiKo.example', 'phone' => '404-555-0101' ), 'files' => array( array( 'path' => $doc, 'name' => 'permit.pdf' ) ) ),
			array( 'id' => 2, 'created' => '2026-04-02 09:00:00', 'fields' => array( 'company_name' => 'Ramen Bar', 'first_name' => 'Jo', 'last_name' => 'Lee', 'email' => 'jo@ramenbar.example' ), 'files' => array( array( 'path' => $dir . '/missing.pdf', 'name' => 'missing.pdf' ), array( 'path' => $doc, 'name' => 'run.php' ) ) ),
		);
	}

	public function detect(): array {
		$public = count( array_filter( $this->products, function ( $p ) {
			return 'publish' === $p['status'];
		} ) );
		return array(
			'products'     => array( 'found' => true, 'count' => count( $this->products ), 'public' => $public, 'hidden' => count( $this->products ) - $public, 'with_image' => 7 ),
			'categories'   => array( 'found' => true, 'count' => count( $this->categories ) ),
			'applications' => array( 'found' => true, 'count' => count( $this->applications ), 'table' => 'kin_dw_wcm_applications' ),
			'users'        => array( 'found' => true, 'count' => 3 ),
		);
	}

	public function productFields(): array {
		return array(
			'dw_pc_product_name'  => 'Product Name',
			'dw_pc_item_code'     => 'Item Code',
			'dw_pc_pack_size_raw' => 'Pack Size',
			'dw_pc_brand_raw'     => 'Brand',
			'dw_pc_origin_raw'    => 'Origin',
			'dw_pc_status'        => 'Status',
			'dw_pc_internal_note' => 'Internal note',
		);
	}

	public function products( int $afterId, int $limit ): array {
		return array_slice( array_values( array_filter( $this->products, function ( $p ) use ( $afterId ) {
			return $p['id'] > $afterId;
		} ) ), 0, $limit );
	}

	public function categories(): array {
		return $this->categories;
	}

	public function groups(): array {
		return $this->groups;
	}

	public function applications( int $afterId, int $limit ): array {
		return array_slice( array_values( array_filter( $this->applications, function ( $a ) use ( $afterId ) {
			return $a['id'] > $afterId;
		} ) ), 0, $limit );
	}

	public function pages(): array {
		return array_values( array_filter( $this->pages, function ( $p ) {
			return false !== strpos( $p['content'], 'wp:kadence/query' );
		} ) );
	}

	public function replacePage( int $pageId, string $shortcode, bool $apply ): ?string {
		if ( ! isset( $this->pages[ $pageId ] ) ) {
			return null;
		}
		$new = preg_replace( '#<!-- wp:kadence/query .*?/-->#', $shortcode, $this->pages[ $pageId ]['content'] );
		if ( $new === $this->pages[ $pageId ]['content'] ) {
			return null;
		}
		if ( $apply ) {
			$this->pages[ $pageId ]['content'] = $new;
			$this->writes[]                    = 'replace:' . $pageId;
		}
		return $new;
	}

	public function restorePage( int $pageId, string $content ): void {
		$this->pages[ $pageId ]['content'] = $content;
		$this->writes[]                    = 'restore:' . $pageId;
	}

	public function oldUrl( string $slug ): string {
		return '/product/' . $slug . '/';
	}
}
