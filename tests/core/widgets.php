<?php
/**
 * M4 shortcode widgets + M5 design — run by tests/core/run.php against each database.
 */

use DWCatalog\Admin\Forbidden;
use DWCatalog\Catalog;
use DWCatalog\Front\Context;
use DWCatalog\Front\Design;
use DWCatalog\Front\FrontApp;
use DWCatalog\Front\Widgets;
use DWCatalog\Security\Csrf;

require_once __DIR__ . '/CountingConnection.php';

function run_widgets_suite( Catalog $base, TestHost $host, string $type ) {
	echo "--- widgets (M4) ---\n";
	$base->settings()->delete( 'fields.' . $type ); // default product fields: name, code, pack, brand, origin, status, note
	$host->roles = array( 'administrator' );
	$host->cache = array();
	$c           = new Catalog( $base->db(), $host, $base->prefix() );
	$db          = $c->db();
	$terms       = $c->terms();
	$items       = $c->items();

	// Catalog: two groups with categories, one loose category
	$frozen = $terms->save( $type, 'category', 'W Frozen', 'w-frozen' );
	$eel    = $terms->save( $type, 'category', 'W Eel', 'w-eel', $frozen );
	$tuna   = $terms->save( $type, 'category', 'W Tuna', 'w-tuna', $frozen );
	$dry    = $terms->save( $type, 'category', 'W Dry', 'w-dry' );
	$nori   = $terms->save( $type, 'category', 'W Nori', 'w-nori', $dry );
	$wasabi = $terms->save( $type, 'category', 'W Wasabi', 'w-wasabi', $dry );
	$tools  = $terms->save( $type, 'category', 'W Tools', 'w-tools' );
	$made   = array();
	$add    = function ( string $title, int $cat, array $values, int $daysOld = 400, int $position = 0, string $status = 'publish' ) use ( $c, $items, $terms, $type, $db, &$made ) {
		$id = $items->save( array( 'type' => $type, 'title' => $title, 'status' => $status, 'position' => $position ) );
		$items->saveValues( $id, $values + array( 'dw_pc_product_name' => $title ) );
		$terms->setItem( $type, $id, 'category', array( $cat ) );
		$db->execute( 'UPDATE ' . $db->table( 'items' ) . ' SET created_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', time() - $daysOld * 86400 ), $id ) );
		$made[ $title ] = $id;
		return $id;
	};
	$add( 'Wx Eel Kabayaki', $eel, array( 'dw_pc_item_code' => 'WX-300', 'dw_pc_brand_raw' => 'Ocean', 'dw_pc_origin_raw' => 'China', 'dw_pc_status' => 'active', 'dw_pc_pack_size_raw' => '20/case', 'dw_pc_internal_note' => 'WXSECRET' ), 3, 2 );
	$add( 'Wx Eel Shirayaki', $eel, array( 'dw_pc_item_code' => 'WX-301', 'dw_pc_brand_raw' => 'Ocean', 'dw_pc_origin_raw' => 'Japan', 'dw_pc_status' => 'out_of_stock' ), 10, 5 );
	$add( 'Wx Tuna Saku', $tuna, array( 'dw_pc_item_code' => 'WX-310', 'dw_pc_brand_raw' => 'Blue Sea', 'dw_pc_origin_raw' => 'Korea', 'dw_pc_status' => 'active' ), 200, 1 );
	$add( 'Wx Tuna Toro', $tuna, array( 'dw_pc_item_code' => 'WX-311', 'dw_pc_brand_raw' => 'Blue Sea', 'dw_pc_status' => 'discontinued' ), 300, 9 );
	$add( 'Wx Nori Gold', $nori, array( 'dw_pc_item_code' => 'WX-100', 'dw_pc_brand_raw' => 'Sea JEM', 'dw_pc_origin_raw' => 'Korea', 'dw_pc_status' => 'active' ), 20, 3 );
	$add( 'Wx Nori Silver', $nori, array( 'dw_pc_item_code' => 'WX-101', 'dw_pc_brand_raw' => 'Sea JEM', 'dw_pc_origin_raw' => 'Korea' ), 500, 4 );
	$add( 'Wx Wasabi Powder', $wasabi, array( 'dw_pc_item_code' => 'WX-200', 'dw_pc_brand_raw' => 'Kinjirushi', 'dw_pc_origin_raw' => 'Japan', 'dw_pc_status' => 'active' ), 700, 6 );
	$add( 'Wx Sashimi Knife', $tools, array( 'dw_pc_item_code' => 'WX-900', 'dw_pc_brand_raw' => 'Masamoto', 'dw_pc_origin_raw' => 'Japan', 'dw_pc_status' => 'active' ), 5, 7 );
	$add( 'Wx Hidden Thing', $nori, array( 'dw_pc_item_code' => 'WX-999', 'dw_pc_brand_raw' => 'Sea JEM' ), 1, 0, 'draft' );
	$items->saveVariations( $made['Wx Nori Gold'], array( array( 'name' => 'Box', 'price' => '42.5' ), array( 'name' => 'Case', 'price' => '39' ) ) );
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$png = $host->dir . '/w-photo.png';
		$im  = imagecreatetruecolor( 60, 40 );
		imagepng( $im, $png );
		$c->images()->store( $made['Wx Eel Kabayaki'], $png, 'eel.png' );
		$c->images()->store( $made['Wx Nori Gold'], $png, 'nori.png' );
	}
	$host->cache = array();

	$front = $c->front();
	$page  = function ( array $atts, array $q = array() ) use ( $c ) {
		return $c->front()->catalog( $atts, new Context( 'https://site.test/shop/', 'https://site.test/shop/item/', $q ) );
	};
	$titles = function ( string $html ) {
		preg_match_all( '/class="dwcat-card-link">(Wx [^<]+)</', $html, $m );
		return $m[1];
	};
	$w = function ( array $atts ) use ( $front ) {
		return $front->resolve( $atts, true );
	};

	// Attribute layers
	$r = $w( array() );
	eq( array( 24, 'grid', 2, 'category,brand,origin,stock,new,photo' ), array( $r['per_page'], $r['mobile_view'], $r['cols_mobile'], $r['filters'] ), '[dw_wholesale_catalog] has 3.0 defaults' );
	eq( 20, $front->resolve( array(), false )['per_page'], '[dw_catalog] keeps 2.x defaults' );

	// Scope: one group, several groups, categories, exclude
	$all = array( 'filters' => 'category', 'per_page' => 50, 'default_sort' => 'title_asc' );
	eq( array( 'Wx Eel Kabayaki', 'Wx Eel Shirayaki', 'Wx Tuna Saku', 'Wx Tuna Toro' ), $titles( $page( $w( $all + array( 'group' => 'w-frozen' ) ) ) ), 'scope: one group (with its categories)' );
	$html = $page( $w( $all + array( 'group' => 'w-frozen,w-dry' ) ) );
	eq( 7, count( $titles( $html ) ), 'scope: several groups' );
	ok( false !== strpos( $html, '>W Frozen</a>' ) && false !== strpos( $html, '>W Dry</a>' ) && false === strpos( $html, '>W Tools</a>' ), 'scope: several groups become the tabs' );
	eq( array( 'Wx Eel Kabayaki', 'Wx Eel Shirayaki', 'Wx Nori Gold', 'Wx Nori Silver' ), $titles( $page( $w( $all + array( 'categories' => 'w-eel,w-nori' ) ) ) ), 'scope: several categories' );
	eq( array( 'Wx Eel Kabayaki', 'Wx Eel Shirayaki' ), $titles( $page( $w( $all + array( 'group' => 'w-frozen', 'exclude' => 'w-tuna' ) ) ) ), 'scope: exclude a category' );
	ok( false === strpos( $page( $w( $all + array( 'group' => 'w-frozen', 'exclude' => 'w-tuna' ) ) ), '>W Tuna<' ), 'excluded category is not offered as a filter' );
	ok( false !== strpos( $page( $w( $all + array( 'group' => 'no-such-group' ) ) ), 'No items found' ), 'scope naming nothing shows nothing' );
	$inside  = new Context( 'https://site.test/shop/', '', array( 'dw_item' => $items->get( $made['Wx Tuna Saku'] )['slug'] ) );
	ok( null === $front->requestedItem( $w( array( 'group' => 'w-frozen', 'exclude' => 'w-tuna' ) ), $inside ), 'item of an excluded category cannot be opened on that page' );
	ok( null !== $front->requestedItem( $w( array( 'group' => 'w-frozen' ) ), $inside ), 'item inside the scope opens' );

	// Flags
	$fl   = $w( array( 'group' => 'w-frozen,w-dry,w-tools', 'per_page' => 50 ) );
	$only = function ( array $flags ) use ( $page, $fl, $titles ) {
		return $titles( $page( $fl, array( 'dwo' => $flags ) ) );
	};
	ok( ! in_array( 'Wx Eel Shirayaki', $only( array( 'in_stock' ) ), true ) && ! in_array( 'Wx Tuna Toro', $only( array( 'in_stock' ) ), true ) && in_array( 'Wx Nori Silver', $only( array( 'in_stock' ) ), true ), 'in stock: out of stock / discontinued left out, no status counts as in stock' );
	eq( array( 'Wx Eel Kabayaki', 'Wx Eel Shirayaki', 'Wx Nori Gold', 'Wx Sashimi Knife' ), $only( array( 'new' ) ), 'new: added in the last 30 days' );
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		eq( array( 'Wx Eel Kabayaki', 'Wx Nori Gold' ), $only( array( 'photo' ) ), 'with photo' );
	}
	eq( 4, count( $titles( $page( $w( array( 'group' => 'w-frozen', 'filters' => 'category' ) ), array( 'dwo' => array( 'new' ) ) ) ) ), 'a flag the page does not offer is ignored' );

	// Counts = results: every brand / origin / flag count equals the list you get by ticking it,
	// together with a search and another filter
	$html = $page( $fl, array( 'dwq' => 'wx', 'dwo' => array( 'in_stock' ) ) );
	preg_match_all( '/name="dwf\[dw_pc_brand_raw\]\[\]" value="([^"]+)"[^>]*><span>[^<]*<\/span><em>(\d+)<\/em>/', $html, $m, PREG_SET_ORDER );
	ok( count( $m ) >= 4, 'brand filter lists values with counts' );
	$same = true;
	foreach ( $m as $hit ) {
		$got = count( $titles( $page( $fl, array( 'dwq' => 'wx', 'dwo' => array( 'in_stock' ), 'dwf' => array( 'dw_pc_brand_raw' => array( html_entity_decode( $hit[1] ) ) ) ) ) ) );
		$same = $same && ( (int) $hit[2] === $got );
	}
	ok( $same, 'brand counts match the results (search + in stock + brand together)' );
	preg_match( '/value="new"[^>]*><span>[^<]*<\/span><em>(\d+)<\/em>/', $html, $nm );
	eq( count( $titles( $page( $fl, array( 'dwq' => 'wx', 'dwo' => array( 'in_stock', 'new' ) ) ) ) ), isset( $nm[1] ) ? (int) $nm[1] : -1, '"new" count matches the results with the other filters' );
	preg_match( '/name="dwc\[\]" value="' . $nori . '"[^>]*><span>[^<]*<\/span><em>(\d+)<\/em>/', $html, $cm );
	eq( count( $titles( $page( $fl, array( 'dwq' => 'wx', 'dwo' => array( 'in_stock' ), 'dwc' => array( $nori ) ) ) ) ), isset( $cm[1] ) ? (int) $cm[1] : -1, 'category count matches the results' );
	ok( false === strpos( $page( $w( array( 'group' => 'w-frozen' ) ), array( 'dwf' => array( 'dw_pc_origin_raw' => array( 'Japan' ) ) ) ), '>WXSECRET<' ), 'internal note never on the page' );

	// Auto-hide: nothing to offer → no panel
	$tools_only = $page( $w( array( 'categories' => 'w-tools' ) ) );
	ok( false === strpos( $tools_only, 'value="photo"' ) && false !== strpos( $tools_only, 'value="new"' ), 'a filter with nothing to offer is hidden' );
	ok( false !== strpos( $page( $fl ), 'value="in_stock"' ), 'in-stock filter shown when there is a status field' );

	// Chips and state in URLs
	$html = $page( $fl, array( 'dwq' => 'nori', 'dwo' => array( 'in_stock' ), 'dwf' => array( 'dw_pc_brand_raw' => array( 'Sea JEM' ) ) ) );
	ok( 3 === substr_count( $html, 'class="dwcat-chip"' ) && false !== strpos( $html, 'dwcat-chips-clear' ), 'one chip per selected filter, and Clear all' );
	ok( false !== strpos( $html, 'dwo%5B0%5D=in_stock' ) && false !== strpos( $html, 'dwq=nori' ), 'links keep the whole state (shareable)' );
	preg_match( '/<a class="dwcat-chip" href="([^"]+)"[^>]*>Sea JEM/', $html, $chip );
	ok( isset( $chip[1] ) && false === strpos( html_entity_decode( $chip[1] ), 'Sea JEM' ) && false !== strpos( html_entity_decode( $chip[1] ), 'dwq=nori' ), 'removing a chip keeps the other filters' );

	// Sorting
	$sorted = function ( string $sort ) use ( $page, $fl, $titles ) {
		return $titles( $page( array_merge( $fl, array( 'filters' => 'category' ) ), array( 'dws' => $sort ) ) );
	};
	eq( 'Wx Nori Gold', $sorted( 'code_asc' )[0], 'sort: item code low → high' );
	eq( 'Wx Sashimi Knife', $sorted( 'code_desc' )[0], 'sort: item code high → low' );
	eq( 'Wx Tuna Saku', $sorted( 'brand_asc' )[0], 'sort: brand' );
	eq( array( 'Wx Tuna Saku', 'Wx Eel Kabayaki', 'Wx Nori Gold' ), array_slice( $sorted( 'manual' ), 0, 3 ), 'sort: recommended order (position)' );
	$cat = $sorted( 'category_asc' );
	ok( array_search( 'Wx Eel Kabayaki', $cat, true ) < array_search( 'Wx Nori Gold', $cat, true ) && array_search( 'Wx Nori Gold', $cat, true ) < array_search( 'Wx Sashimi Knife', $cat, true ), 'sort: by category (tree order)' );
	eq( 'Wx Eel Kabayaki', $sorted( 'date_desc' )[0], 'sort: newest' );
	$limited = $page( $w( array( 'group' => 'w-dry', 'sorts' => 'title_asc,code_asc', 'default_sort' => 'title_asc' ) ) );
	ok( 2 === substr_count( $limited, '<option value="title_' ) + substr_count( $limited, '<option value="code_' ) && false === strpos( $limited, 'value="manual"' ), 'only the allowed sorts are offered' );
	eq( $titles( $page( $w( array( 'group' => 'w-dry', 'sorts' => 'title_asc' ) ), array( 'dws' => 'code_desc' ) ) ), $titles( $page( $w( array( 'group' => 'w-dry', 'sorts' => 'title_asc' ) ) ) ), 'a sort the page does not allow is ignored' );
	ok( false !== strpos( $page( $w( array() ) ), '<option value="24" selected>24</option><option value="48">48</option><option value="96">96</option>' ), 'per page 24 / 48 / 96' );

	// Layout options
	$html = $page( $w( array( 'group' => 'w-dry', 'columns' => 5, 'cols_tablet' => 3, 'cols_mobile' => 1, 'per_page_tablet' => 12, 'per_page_mobile' => 6, 'mobile_view' => 'list', 'click' => 'page' ) ) );
	ok( false !== strpos( $html, '--dwcat-cols: 5; --dwcat-cols-t: 3; --dwcat-cols-m: 1;' ) && false !== strpos( $html, 'data-per-tablet="12" data-per-mobile="6"' ), 'columns and first count per device' );
	ok( false !== strpos( $html, 'dwcat-mview-list' ) && false !== strpos( $html, 'data-dwcat-click="page"' ), 'phone view and click target' );
	ok( false !== strpos( $page( $w( array( 'filter_position' => 'top' ) ) ), 'class="dwcat-panel dwcat-dd"' ), 'filters in a top bar' );
	$big = $page( $w( array( 'search_size' => 'small', 'suggest' => 'nori, wasabi' ) ) );
	ok( false !== strpos( $big, 'dwcat-search-small' ) && 2 === substr_count( preg_replace( '/.*class="dwcat-suggest"(.*?)<\/p>.*/s', '$1', $big ), '<a ' ), 'small search box with suggested words' );
	ok( false === strpos( $page( $w( array( 'search_size' => 'hidden' ) ) ), 'id="dwcat-search"' ), 'search box can be hidden' );
	$paged = $page( array_merge( $w( array( 'group' => 'w-frozen,w-dry' ) ), array( 'per_page' => 2 ) ) );
	ok( false !== strpos( $paged, 'data-dwcat-more-page' ) && false !== strpos( $paged, 'dwp=2' ), '"Load more" links to the next page (works without JS)' );

	$plainUrls = $c->front()->catalog( $w( array( 'group' => 'w-dry' ) ), new Context( 'https://site.test/?page_id=7', '' ) );
	ok( false !== strpos( $plainUrls, 'action="https://site.test/"' ) && false !== strpos( $plainUrls, '<input type="hidden" name="page_id" value="7">' ), 'plain permalinks: the filter form keeps the page address' );
	ok( false !== strpos( $page( $w( array( 'group' => 'w-dry' ) ) ), 'data-dwcat-more-page data-last' ), 'last page: "Load more" only reveals what tablets / phones held back' );

	// Cards
	$cards = $page( $w( array( 'categories' => 'w-nori', 'card_fields' => 'code,pack,brand,origin,category,price' ) ) );
	ok( false !== strpos( $cards, 'WX-100' ) && false !== strpos( $cards, 'Sea JEM' ) && false !== strpos( $cards, 'dwcat-badge' ), 'card: code, brand, category badge' );
	ok( false !== strpos( $cards, 'dwcat-price">39<' ), 'card: lowest option price' );
	$plain = $page( $w( array( 'categories' => 'w-nori', 'card_fields' => 'code' ) ) );
	$plain = substr( $plain, strpos( $plain, 'data-dwcat-grid' ) ); // the cards, not the filters
	ok( false === strpos( $plain, 'dwcat-badge' ) && false === strpos( $plain, 'Sea JEM' ) && false === strpos( $plain, 'dwcat-price' ), 'card: only the chosen fields' );
	ok( false === strpos( $page( $w( array( 'categories' => 'w-eel', 'card_fields' => 'code,dw_pc_internal_note' ) ) ), 'WXSECRET' ), 'card: a private field cannot be put on a card' );

	// Same number of queries for 4 or 8 products on the page
	$counting = function ( array $atts ) use ( $base, $host ) {
		$conn        = new CountingConnection( $base->db() );
		$fresh       = new TestHost( $host->dir );
		$fresh->roles = array();
		$fresh->user  = 0;
		$cc          = new Catalog( $conn, $fresh, $base->prefix() );
		$cc->front()->catalog( $cc->front()->resolve( $atts, true ), new Context( 'https://site.test/shop/', '' ) );
		return $conn->count;
	};
	$q4 = $counting( array( 'group' => 'w-frozen', 'card_fields' => 'code,pack,brand,origin,category,price' ) );
	$q8 = $counting( array( 'group' => 'w-frozen,w-dry,w-tools', 'card_fields' => 'code,pack,brand,origin,category,price' ) );
	ok( $q4 === $q8, "queries do not grow with the number of products ($q4 for 4 products, $q8 for 8)" );

	// Header search
	$hs = $front->search( array( 'placeholder' => 'Find', 'suggest' => 'nori, wasabi' ), new Context( 'https://site.test/?page_id=4' ) );
	ok( false !== strpos( $hs, 'action="https://site.test/"' ) && false !== strpos( $hs, 'name="page_id" value="4"' ) && false !== strpos( $hs, 'name="dwq"' ), 'header search: GET to the catalog page, page address kept' );
	ok( false !== strpos( $hs, '<datalist' ) && false !== strpos( $hs, 'placeholder="Find"' ), 'header search: suggestions and placeholder' );
	ok( false === strpos( $front->search( array( 'placeholder' => '"><script>' ), new Context( 'https://site.test/shop/' ) ), '<script>' ), 'header search: escaped' );

	// Saved widgets
	echo "--- saved catalogs ---\n";
	$clean = Widgets::sanitize( $c, array( 'name' => 'Frozen <b>page</b>', 'scope' => 'group', 'groups' => array( 'w-frozen', 'w-eel', 'nope' ), 'exclude' => 'w-tuna,nope', 'filters' => array( 'brand', 'bogus', 'category', 'brand' ), 'sorts' => array( 'code_asc', 'zzz' ), 'default_sort' => 'title_asc', 'per_page' => '48', 'cols_desktop' => 9, 'cols_mobile' => 5, 'card_fields' => array( 'code', 'dw_pc_internal_note' ), 'click' => 'x', 'design' => 'nope', 'suggest' => 'a, b,, c' ) );
	eq( array( 'Frozen page', array( 'w-frozen' ), array( 'w-tuna' ), array( 'brand', 'category' ), array( 'code_asc', 'title_asc' ), 48, 6, 2, array( 'code' ), 'drawer', '', 'a, b, c' ),
		array( $clean['name'], $clean['groups'], $clean['exclude'], $clean['filters'], $clean['sorts'], $clean['per_page'], $clean['cols_desktop'], $clean['cols_mobile'], $clean['card_fields'], $clean['click'], $clean['design'], $clean['suggest'] ),
		'saved catalog: every value checked against the catalog (groups are top-level, private fields refused, default sort kept)' );
	$admin = $c->admin();
	$post  = function ( string $do, array $extra ) use ( $admin, $c ) {
		return $admin->handle( array_merge( array( 'do' => $do, Csrf::FIELD => $c->csrf()->token( $do ) ), $extra ) );
	};
	$res = $post( 'save_widget', array( 'id' => '', 'widget' => array( 'name' => 'Frozen Page', 'scope' => 'group', 'groups' => array( 'w-frozen' ), 'filters' => array( 'brand' ), 'per_page' => 24, 'default_sort' => 'code_asc' ) ) );
	ok( false !== strpos( $res['redirect'], 'widget=frozen-page' ), 'saved: id made from the name' );
	$html = $page( $w( array( 'id' => 'frozen-page' ) ) );
	eq( array( 'Wx Eel Kabayaki', 'Wx Eel Shirayaki', 'Wx Tuna Saku', 'Wx Tuna Toro' ), $titles( $html ), '[dw_wholesale_catalog id="frozen-page"] shows its scope, in its sort' );
	ok( false === strpos( $html, 'name="dwc[]"' ) && false !== strpos( $html, 'dwf[dw_pc_brand_raw]' ), 'saved filters used' );
	eq( 2, count( $titles( $page( $w( array( 'id' => 'frozen-page', 'exclude' => 'w-tuna' ) ) ) ) ), 'attributes on the page override the saved settings' );
	$fallback = $page( array_merge( $w( array( 'id' => 'deleted-one' ) ), array( 'per_page' => 100 ) ) );
	ok( false !== strpos( $fallback, 'Wx Sashimi Knife' ) && false !== strpos( $fallback, 'Wx Eel Kabayaki' ), 'unknown id falls back to the whole catalog' );
	$host->pages = array( array( 'title' => 'Frozen', 'url' => 'https://site.test/frozen/', 'edit_url' => '', 'shortcode' => '[dw_wholesale_catalog id="frozen-page"]' ), array( 'title' => 'Other', 'url' => 'https://site.test/o/', 'edit_url' => '', 'shortcode' => '[dw_wholesale_catalog id="frozen-page-2"]' ) );
	eq( array( 'Frozen' ), array_column( Widgets::pagesUsing( $c, 'frozen-page' ), 'title' ), 'pages using a saved catalog (exact id)' );
	ok( false !== strpos( $admin->render( array( 'view' => 'shortcodes' ) ), 'https://site.test/frozen/' ), 'shortcodes screen lists where each catalog is used' );
	ok( false !== strpos( $admin->render( array( 'view' => 'shortcodes', 'widget' => 'frozen-page' ) ), 'name="widget[filters][]" value="brand" checked' ), 'editor shows the saved values' );
	ok( false !== strpos( $admin->render( array( 'view' => 'shortcodes', 'widget' => 'new' ) ), 'name="widget[name]"' ), 'editor for a new catalog' );
	$host->roles = array( 'editor' );
	$denied      = false;
	try {
		$post( 'save_widget', array( 'id' => 'x', 'widget' => array( 'name' => 'X' ) ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	ok( $denied, 'editor cannot save catalogs (Shortcodes is administrator-only by default)' );
	$host->roles = array( 'administrator' );
	$denied      = false;
	try {
		$admin->handle( array( 'do' => 'save_widget', 'id' => 'x', 'widget' => array(), Csrf::FIELD => 'bad' ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	ok( $denied, 'saving a catalog needs a valid token' );
	$post( 'delete_widget', array( 'id' => 'frozen-page' ) );
	ok( null === Widgets::get( $c, 'frozen-page' ), 'saved catalog deleted' );
	$host->pages = array();

	// Design
	echo "--- design (M5) ---\n";
	$s = $c->settings();
	$s->delete( Design::KEY );
	$s->delete( Design::PRESETS );
	$v = Design::sanitize( array( 'accent' => 'red;}body{x', 'radius_card' => 99, 'button_style' => 'neon', 'font_set' => 'nanum', 'text_size' => 'l', 'shadow' => 'soft' ) );
	eq( array( '#1466d6', 32, 'fill', 'nanum', 'l', 'soft' ), array( $v['accent'], $v['radius_card'], $v['button_style'], $v['font_set'], $v['text_size'], $v['shadow'] ), 'design: values checked (bad color, range, unknown option)' );
	$s->set( Design::KEY, array( 'card_border_color' => '#abcdef', 'card_radius' => 4, 'card_shadow' => 'md', 'font_family' => 'IBM Plex Sans' ) );
	$old = Design::get( $s );
	eq( array( '#abcdef', 4, 'soft', 'plex_kr' ), array( $old['border_color'], $old['radius_card'], $old['shadow'], $old['font_set'] ), 'design: 2.x settings carried over' );
	$post( 'save_design', array( 'design' => array( 'accent' => '#ff0000', 'button_style' => 'outline', 'radius_button' => 20, 'font_set' => 'noto' ) ) );
	$style = Design::style( $s );
	ok( false !== strpos( $style, '--dwcat-accent:#ff0000' ) && false !== strpos( $style, '--dwcat-btn-radius:20px' ) && false !== strpos( $style, "--dwcat-btn-bg:#ffffff" ) && false !== strpos( $style, "Noto Sans KR" ), 'design: CSS variables (color, corners, button style, font)' );
	ok( false !== strpos( Design::fontUrl( $s ), 'Noto+Sans+KR' ) && '' === Design::fontUrlFor( 'theme' ), 'design: font stylesheet per font set (none for the theme font)' );
	$res  = $post( 'create_design', array( 'name' => 'Frozen Look', 'design' => array( 'accent' => '#0000ff', 'shadow' => 'soft' ) ) );
	ok( false !== strpos( $res['redirect'], 'preset=frozen-look' ), 'preset created' );
	eq( '#0000ff', Design::get( $s, 'frozen-look' )['accent'], 'preset keeps its own values' );
	eq( '#ff0000', Design::get( $s )['accent'], 'default design unchanged' );
	$html = $page( $w( array( 'group' => 'w-dry', 'design' => 'frozen-look' ) ) );
	ok( false !== strpos( $html, '--dwcat-accent:#0000ff' ), 'widget uses design="frozen-look"' );
	ok( false !== strpos( $page( $w( array( 'group' => 'w-dry', 'design' => 'missing' ) ) ), '--dwcat-accent:#ff0000' ), 'unknown preset → default design' );
	$post( 'save_design', array( 'preset' => 'frozen-look', 'design' => array( 'accent' => '#00aa00' ) ) );
	eq( array( '#00aa00', '#ff0000' ), array( Design::get( $s, 'frozen-look' )['accent'], Design::get( $s )['accent'] ), 'saving a preset leaves the default alone' );
	$copy = $post( 'create_design', array( 'name' => 'Copy', 'from' => 'frozen-look' ) );
	eq( '#00aa00', Design::get( $s, 'copy' )['accent'], 'new preset without values copies the preset it was made from' );
	$spec = Design::previewSpec();
	ok( isset( $spec['direct']['accent'], $spec['direct']['radius_input'], $spec['enums']['button_style']['tint'], $spec['fonts']['nanum'] ), 'preview knows how every setting maps to the page' );
	$designScreen = $admin->render( array( 'view' => 'design', 'preset' => 'frozen-look' ) );
	ok( false !== strpos( $designScreen, 'data-dwc-preview-root' ) && false !== strpos( $designScreen, '--dwcat-accent:#00aa00' ) && 4 === substr_count( $designScreen, 'dwcat-card-title' ), 'design screen: preview with four product cards in the preset' );
	ok( false !== strpos( $designScreen, 'data-dwc-preview-width="390"' ) && false !== strpos( $designScreen, 'data-dwc-preview-width="834"' ), 'design screen: desktop / tablet / phone preview' );
	$post( 'delete_design', array( 'preset' => 'copy' ) );
	ok( ! isset( Design::presets( $s )['copy'] ), 'preset deleted' );
	ok( isset( Widgets::sanitize( $c, array( 'design' => 'frozen-look' ) )['design'] ) && 'frozen-look' === Widgets::sanitize( $c, array( 'design' => 'frozen-look' ) )['design'], 'saved catalog can pick a preset' );

	// Korean: the new screens and widget strings
	require_once dirname( __DIR__, 2 ) . '/build/i18n.php';
	$host->dict    = dwcat_i18n_dict( dirname( __DIR__, 2 ) . '/languages/dw-wholesale-ko_KR.po' );
	$host->missing = array();
	$host->cache   = array();
	$ck            = new Catalog( $base->db(), $host, $base->prefix() );
	Widgets::save( $ck, 'ko-check', array( 'name' => 'Ko' ) );
	foreach ( array( array( 'view' => 'shortcodes' ), array( 'view' => 'shortcodes', 'widget' => 'new' ), array( 'view' => 'shortcodes', 'widget' => 'ko-check' ), array( 'view' => 'design' ), array( 'view' => 'design', 'preset' => 'frozen-look' ), array( 'view' => 'edit', 'id' => $made['Wx Nori Gold'] ) ) as $q ) {
		$ck->admin()->render( $q + array( 'msg' => 'saved' ) );
	}
	$ctxK = new Context( 'https://site.test/shop/', '', array( 'dwq' => 'wx', 'dwo' => array( 'in_stock' ), 'dwf' => array( 'dw_pc_brand_raw' => array( 'Ocean' ) ) ) );
	$ck->front()->catalog( $ck->front()->resolve( array( 'suggest' => 'nori', 'card_fields' => 'code,price', 'filter_position' => 'top' ), true ), $ctxK );
	$ck->front()->catalog( $ck->front()->resolve( array( 'per_page' => 2 ), true ), new Context( 'https://site.test/shop/', '' ) );
	$ck->front()->search( array(), new Context( 'https://site.test/shop/' ) );
	$missing = array_keys( $host->missing );
	if ( $missing && getenv( 'DWC_DUMP_MISSING' ) ) {
		file_put_contents( getenv( 'DWC_DUMP_MISSING' ), implode( "\n", $missing ) . "\n", FILE_APPEND );
	}
	ok( ! $missing, 'Korean: no untranslated string on the shortcode / design screens and the 3.0 widget' . ( $missing ? ' — missing ' . count( $missing ) . ': ' . implode( ' | ', array_slice( $missing, 0, 40 ) ) : '' ) );
	$host->dict    = null;
	$host->missing = array();
	Widgets::delete( $ck, 'ko-check' );

	// Clean up
	foreach ( $made as $id ) {
		$c->deleteItem( $id );
	}
	foreach ( array( $eel, $tuna, $nori, $wasabi, $frozen, $dry, $tools ) as $tid ) {
		$terms->delete( $type, $tid );
	}
	$s->delete( Design::KEY );
	$s->delete( Design::PRESETS );
	$s->delete( Widgets::KEY );
	$host->cache = array();
}
