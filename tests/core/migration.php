<?php
/**
 * M1 migration + M2 product list — run by tests/core/run.php against each database.
 */

use DWCatalog\Admin\Forbidden;
use DWCatalog\Catalog;
use DWCatalog\Migration\Migrator;
use DWCatalog\Security\Csrf;

require_once __DIR__ . '/TestSource.php';

function run_migration_suite( Catalog $base, TestHost $host, string $type ) {
	echo "--- migration (M1) ---\n";
	$base->settings()->delete( 'fields.' . $type ); // default product fields (dw_pc_*)
	$base->settings()->delete( Migrator::KEY );
	$source       = new TestSource( $host->dir );
	$host->source = $source;
	$host->roles  = array( 'administrator' );
	$c            = new Catalog( $base->db(), $host, $base->prefix() );
	$db           = $c->db();
	$m            = $c->migrator();
	$count        = function ( string $table, string $where = '', array $params = array() ) use ( $db ) {
		return (int) $db->selectValue( 'SELECT COUNT(*) FROM ' . $db->table( $table ) . ( $where ? ' WHERE ' . $where : '' ), $params );
	};
	$before = array( 'items' => $count( 'items' ), 'terms' => $count( 'terms' ), 'images' => $count( 'images' ), 'apps' => $count( 'applications' ) );
	$frozen = json_encode( array( $source->products, $source->categories, $source->applications ) );

	ok( $m instanceof Migrator, 'a host with an older catalog gets a migrator' );
	ok( null === ( new Catalog( $base->db(), new TestHost( $host->dir ), $base->prefix() ) )->migrator(), 'no older catalog → no migrator' );

	// 1. Detect
	$d = $m->source()->detect();
	eq( array( 8, 5, 2, 3 ), array( $d['products']['count'], $d['categories']['count'], $d['applications']['count'], $d['users']['count'] ), 'detect: products, categories, applications, users' );

	// 2. Mapping
	$map = $m->state()['mapping'];
	eq( 'dw_pc_item_code', $map['dw_pc_item_code'], 'mapping: same key by default' );
	eq( 'dw_pc_internal_note', $map['dw_pc_internal_note'], 'mapping: internal note → the private field' );
	$warn = $m->saveMapping( array_merge( $map, array( 'dw_pc_internal_note' => 'dw_pc_origin_raw', 'dw_pc_origin_raw' => '' ) ) );
	ok( 1 === count( $warn ) && '' === $m->state()['mapping']['dw_pc_internal_note'], 'mapping: internal note is refused for a public field' );
	$m->saveMapping( array_merge( $map, array( 'dw_pc_brand_raw' => 'dw_pc_item_code' ) ) );
	eq( '', $m->state()['mapping']['dw_pc_brand_raw'], 'mapping: one catalog field takes one source field' );
	$m->saveMapping( $map );

	// 3. Groups
	$groupOf = $m->groupOf();
	eq( array( 11 => 'dry-goods', 12 => 'dry-goods', 13 => 'frozen', 14 => '', 15 => 'frozen' ), array( 11 => $groupOf[11], 12 => $groupOf[12], 13 => $groupOf[13], 14 => $groupOf[14], 15 => $groupOf[15] ), 'groups: read from the pages, the rest is ungrouped' );
	$m->saveGroups( array( 'dry-goods' => 'Dry Goods', 'frozen' => 'Frozen', '+' => 'Hardware' ), array( 11 => 'dry-goods', 12 => 'dry-goods', 13 => 'frozen', 14 => 'hardware', 15 => 'frozen' ) );
	eq( 'hardware', $m->groupOf()[14], 'groups: the admin can add a group and move a category' );
	$m->resetGroups();
	eq( '', $m->groupOf()[14], 'groups: read again from the pages' );

	// 4. Dry run — writes nothing
	$r = $m->dryRun();
	eq( array( 'products' => 8, 'migrate' => 8, 'skip' => 0, 'visible' => 7, 'hidden' => 1, 'images' => 7 ), array_intersect_key( $r['summary'], array_flip( array( 'products', 'migrate', 'skip', 'visible', 'hidden', 'images' ) ) ), 'dry run: summary' );
	eq( array( 'empty_status' => 2, 'empty_brand' => 1, 'duplicate_code' => 2, 'dash_slug' => 2, 'no_image' => 1, 'hidden' => 1, 'no_category' => 0, 'empty_category' => 1, 'ungrouped' => 1 ), $r['issues'], 'dry run: every problem counted' );
	eq( $before, array( 'items' => $count( 'items' ), 'terms' => $count( 'terms' ), 'images' => $count( 'images' ), 'apps' => $count( 'applications' ) ), 'dry run: nothing written' );
	eq( 0, $count( 'migration' ), 'dry run: no migration records' );
	ok( false === Migrator::hasDashSuffix( 'sashimi-knife-240', 'Sashimi Knife 240' ) && false === Migrator::hasDashSuffix( 'rice-10', 'Rice 10' ) && true === Migrator::hasDashSuffix( 'rice-1', 'Rice' ), '"-1" detection ignores numbers that belong to the name' );

	$alt  = $m->dryRun( array( 'empty_status' => 'active', 'duplicate_code' => 'latest', 'dash_slug' => 'clean' ) );
	$byId = array_column( $alt['rows'], null, 'id' );
	eq( array( 7, 1 ), array( $alt['summary']['migrate'], $alt['summary']['skip'] ), 'choice "latest only": the older duplicate is skipped' );
	ok( $byId[104]['skip'] && ! $byId[105]['skip'], 'choice "latest only": keeps the one modified last' );
	eq( 'active', $byId[102]['status'], 'choice "set to Active": empty status becomes active' );
	eq( array( 'nori-flakes-10003', 'wasabi-powder-22162', 'sashimi-knife-240' ), array( $byId[103]['new_slug'], $byId[105]['new_slug'], $byId[108]['new_slug'] ), 'choice "tidy": "-1" addresses use the item code' );
	$hid = array_column( $m->dryRun( array( 'empty_status' => 'hidden' ) )['rows'], null, 'id' );
	ok( ! $hid[102]['visible'] && $hid[101]['visible'], 'choice "hide": products with no status are hidden' );
	$csv = $m->dryRunCsv();
	ok( 0 === strpos( $csv, "\xEF\xBB\xBF" ) && 9 === substr_count( trim( $csv ), "\n" ) + 1 && false !== strpos( $csv, 'duplicate_code' ) && false !== strpos( $csv, 'Dry Goods > Nori' ), 'dry run CSV: header + one row per product, with issues and group' );

	// Admin: permissions and CSRF
	$admin = $c->admin();
	$post  = function ( array $extra ) use ( $admin, $c ) {
		return $admin->handle( array_merge( array( 'do' => 'migrate', Csrf::FIELD => $c->csrf()->token( 'migrate' ) ), $extra ) );
	};
	$host->roles = array( 'editor' );
	$denied      = false;
	try {
		$post( array( 'op' => 'start' ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	ok( $denied && ! $admin->canOpen( array( 'view' => 'migration' ) ), 'editor: migration screen and actions are refused' );
	$host->roles = array( 'administrator' );
	$denied      = false;
	try {
		$admin->handle( array( 'do' => 'migrate', 'op' => 'start', Csrf::FIELD => 'bad' ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	ok( $denied, 'migration actions need a valid token' );
	$host->denied = array( 'import' );
	$denied       = false;
	try {
		$post( array( 'op' => 'start' ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	$host->denied = array();
	ok( $denied, 'migration actions need the license feature' );
	foreach ( array( 'detect', 'mapping', 'groups', 'check', 'run', 'finish', 'rollback' ) as $step ) {
		$html = $admin->render( array( 'view' => 'migration', 'step' => $step ) );
		ok( false !== strpos( $html, 'dwc-steps' ) && false === strpos( $html, 'Fatal' ), "screen renders: $step" );
	}
	$dl = $post( array( 'op' => 'csv' ) );
	ok( isset( $dl['download'] ) && 'migration-check.csv' === $dl['download'][0], 'CSV download from the check screen' );
	$post( array( 'op' => 'choices', 'choices' => array( 'empty_status' => 'bogus', 'duplicate_code' => 'review', 'dash_slug' => 'clean' ) ) );
	eq( array( 'empty_status' => 'keep', 'duplicate_code' => 'review', 'dash_slug' => 'clean' ), $m->state()['choices'], 'choices saved (unknown value → default)' );

	// 5. Run — batches, pause, resume
	$res = $post( array( 'op' => 'start' ) );
	ok( false !== strpos( $res['redirect'], 'step=run' ), 'start goes to the run screen' );
	eq( array( 'running', 10 ), array( $m->state()['run']['status'], $m->state()['run']['total'] ), 'run: started, total = products + applications' );
	$already = 0; // categories earlier tests left behind are reused, not created
	foreach ( array( 'dry-goods', 'frozen', 'uncategorized', 'nori', 'wasabi', 'frozen-fish', 'knives', 'empty-shelf' ) as $slug ) {
		$already += $c->terms()->bySlug( $type, $slug ) ? 1 : 0;
	}
	$m->batch( 3 );                                         // terms
	eq( 8 - $already, $count( 'terms' ) - $before['terms'], 'run: 3 groups (2 + Uncategorized) and 5 categories created (existing ones reused)' );
	$run = $m->batch( 3 );                                  // 3 products
	eq( array( 'items', 3, 3 ), array( $run['stage'], $run['done'], $count( 'items' ) - $before['items'] ), 'run: a batch moves its share only' );
	$j = $post( array( 'op' => 'pause' ) );
	eq( 'paused', $j['json']['status'], 'pause' );
	eq( 3, $m->batch( 3 )['done'], 'paused: a batch does nothing' );
	ok( ! isset( $j['json']['skip'] ) && ! isset( $j['json']['dup'] ), 'progress answers carry no internal lists' );
	$post( array( 'op' => 'resume' ) );
	for ( $i = 0; $i < 20 && 'done' !== $m->state()['run']['status']; $i++ ) {
		$j = $post( array( 'op' => 'batch' ) );
	}
	$run = $m->state()['run'];
	eq( 'done', $run['status'], 'run: finished after resume' );
	eq( array( 'created' => 8, 'updated' => 0, 'skipped' => 0, 'images' => 7, 'image_errors' => 0, 'terms' => 8 - $already, 'applications' => 2, 'files' => 1 ), $run['counts'], 'run: counts' );
	eq( 2, count( $run['errors'] ), 'run: a missing attachment and a refused file type are reported' );
	eq( array( 8, 7, 2 ), array( $count( 'items' ) - $before['items'], $count( 'images' ) - $before['images'], $count( 'applications' ) - $before['apps'] ), 'same numbers in the database' );

	$idOf = function ( int $sourceId ) use ( $m ) {
		return (int) $m->mapGet( 'item', (string) $sourceId )['target_id'];
	};
	$items = $c->items();
	$gold  = $items->get( $idOf( 101 ) );
	eq( array( 'Yaki Nori Gold', 'yaki-nori-gold', 'publish', '2025-06-03 10:00:00' ), array( $gold['title'], $gold['slug'], $gold['status'], substr( (string) $gold['created_at'], 0, 19 ) ), 'product: title, address, visibility and original date kept' );
	eq( array( '10001', 'Sea JEM', 'active', '10/50', 'Korea', 'cost 12.40' ), array_map( function ( $k ) use ( $items, $idOf ) {
		return $items->value( $idOf( 101 ), $k );
	}, array( 'dw_pc_item_code', 'dw_pc_brand_raw', 'dw_pc_status', 'dw_pc_pack_size_raw', 'dw_pc_origin_raw', 'dw_pc_internal_note' ) ), 'product: every mapped field copied' );
	ok( false === strpos( (string) $gold['content'], '<script' ) && false !== strpos( (string) $gold['content'], 'About Yaki Nori Gold' ), 'product: description cleaned' );
	eq( 'draft', $items->get( $idOf( 107 ) )['status'], 'hidden product stays hidden' );
	eq( array( 'duplicate_code', 'duplicate_code', '' ), array( $items->get( $idOf( 104 ) )['review'], $items->get( $idOf( 105 ) )['review'], (string) $gold['review'] ), 'repeated codes: both kept and marked for review' );
	eq( array( 'nori-flakes-10003', 'wasabi-powder-22162' ), array( $items->get( $idOf( 103 ) )['slug'], $items->get( $idOf( 105 ) )['slug'] ), '"-1" addresses tidied as chosen' );
	$terms = $c->terms()->ofItem( $type, $idOf( 101 ) );
	$top   = $c->terms()->get( $type, (int) $terms[0]['parent'] );
	eq( array( 'Nori', 'Dry Goods' ), array( $terms[0]['name'], $top['name'] ), 'category sits under its group' );
	$knife = $c->terms()->ofItem( $type, $idOf( 108 ) );
	eq( 'Uncategorized', $c->terms()->get( $type, (int) $knife[0]['parent'] )['name'], 'a category in no group goes under Uncategorized' );
	$img = $c->images()->main( $idOf( 101 ) );
	ok( $img && is_file( $c->images()->path( $img ) ) && 'Yaki Nori Gold' === $img['alt'], 'photo stored in the catalog with its alt text' );
	ok( null === $c->images()->main( $idOf( 106 ) ), 'product without photo has none' );
	ok( false === strpos( $c->front()->catalog( array(), new \DWCatalog\Front\Context( 'https://site.test/c/', '' ) ), 'cost 12.40' ), 'internal note never reaches the site' );

	// Applications + private attachments
	$apps = $c->applications();
	$aid  = (int) $m->mapGet( 'application', '1' )['target_id'];
	$app  = $apps->get( $aid );
	eq( array( 'new', 'mina@sushiko.example', 'Sushi Ko', 'Mina Park', '2026-04-01 09:00:00' ), array( $app['status'], $app['email'], $app['company'], $app['name'], substr( (string) $app['created_at'], 0, 19 ) ), 'application copied with its date' );
	$file = $app['files'][0];
	$path = $apps->filePath( $aid, $file['file'] );
	ok( 1 === count( $app['files'] ) && 'permit.pdf' === $file['name'] && is_file( $path ), 'attachment copied' );
	ok( 0 === strpos( $path, $host->privateDir() ) && false === strpos( $path, 'permit' ) && '.bin' === substr( $path, -4 ), 'attachment sits in the private directory under a random name' );
	ok( false === strpos( $host->storageDir() . '/', dirname( $path ) . '/' ) && '' === $apps->filePath( $aid, '../../wp-config.php' ) && '' === $apps->filePath( $aid + 999, $file['file'] ), 'attachment path cannot be steered elsewhere' );
	ok( 0 === count( $apps->get( (int) $m->mapGet( 'application', '2' )['target_id'] )['files'] ), 'a .php "attachment" is not stored' );
	ok( is_file( $source->applications[0]['files'][0]['path'] ), 'original attachment left in place' );

	// Comparison table
	$cmp = $m->compare();
	eq( array( array( 8, 8 ), array( 5, 5 ), array( 7, 7 ), array( 2, 2 ) ), array_values( $cmp['totals'] ), 'compare: products, categories, photos, applications — same counts' );
	$diff = array_filter( $cmp['categories'], function ( $row ) {
		return $row['planned'] !== $row['migrated'];
	} );
	ok( ! $diff && 5 === count( $cmp['categories'] ), 'compare: every category has the same count' );
	ok( false !== strpos( $admin->render( array( 'view' => 'migration', 'step' => 'run' ) ), 'dwc-pill-on' ), 'result screen shows the comparison' );

	// Run again — no duplicates
	$n = array( $count( 'items' ), $count( 'terms' ), $count( 'images' ), $count( 'applications' ), $count( 'migration' ) );
	$source->products[0]['title']                     = 'Yaki Nori Gold';
	$source->products[0]['meta']['dw_pc_brand_raw']   = 'Sea JEM Premium';
	$frozen = json_encode( array( $source->products, $source->categories, $source->applications ) ); // the test's own edit
	$post( array( 'op' => 'start' ) );
	for ( $i = 0; $i < 20 && 'done' !== $m->state()['run']['status']; $i++ ) {
		$m->batch();
	}
	eq( $n, array( $count( 'items' ), $count( 'terms' ), $count( 'images' ), $count( 'applications' ), $count( 'migration' ) ), 'run again: nothing created twice' );
	eq( array( 0, 8, 0 ), array( $m->state()['run']['counts']['created'], $m->state()['run']['counts']['updated'], $m->state()['run']['counts']['images'] ), 'run again: products updated, photos untouched' );
	$c2 = new Catalog( $base->db(), $host, $base->prefix() );
	eq( 'Sea JEM Premium', $c2->items()->value( $idOf( 101 ), 'dw_pc_brand_raw' ), 'run again: changed source value arrives' );

	// 6. Finish — pages, redirects, REST switch
	eq( 0, $m->itemForOldSlug( 'wasabi-powder-1' ), 'old address: no redirect until switched on' );
	$post( array( 'op' => 'flags', 'redirects' => '1' ) );
	eq( array( true, false ), array( $m->state()['finish']['redirects'], $m->state()['finish']['rest_block'] ), 'finish: switches saved separately' );
	eq( array( $idOf( 105 ), $idOf( 103 ), 0 ), array( $m->itemForOldSlug( 'wasabi-powder-1' ), $m->itemForOldSlug( 'nori-flakes-1' ), $m->itemForOldSlug( 'no-such-product' ) ), 'old address → the migrated product' );
	eq( '/product/wasabi-powder-1/', $m->source()->oldUrl( $m->oldSlugOf( $idOf( 105 ) ) ), 'product knows its old address' );
	ok( false !== strpos( $c2->admin()->render( array( 'view' => 'edit', 'id' => $idOf( 105 ) ) ), '/product/wasabi-powder-1/' ), 'editor shows old address → this product' );
	$pages = $m->pages();
	eq( array( '[dw_catalog category="dry-goods"]', false ), array( $pages[0]['shortcode'], $pages[0]['replaced'] ), 'finish: preview of the shortcode each page gets' );
	eq( array(), $source->writes, 'finish: the preview writes nothing' );
	$post( array( 'op' => 'pages', 'pages' => array( '501' ) ) );
	ok( false !== strpos( $source->pages[501]['content'], '[dw_catalog category="dry-goods"]' ) && false !== strpos( $source->pages[501]['content'], '<p>Intro</p>' ) && false !== strpos( $source->pages[502]['content'], 'kadence/query' ), 'finish: only the selected page is changed, the rest of it kept' );

	// M2 — product list tabs, filters, bulk actions (on the migrated data)
	echo "--- product list (M2) ---\n";
	$admin2 = $c2->admin();
	$q      = ( new \DWCatalog\Admin\ItemStats( $c2 ) )->quality( $type );
	ok( $q['active'] >= 4 && $q['no_status'] >= 2 && $q['out_of_stock'] >= 1 && $q['hidden'] >= 1 && $q['review'] >= 2 && $q['no_image'] >= 1 && $q['empty_categories'] >= 1, 'quality counts' );
	$list = function ( array $params ) use ( $admin2 ) {
		return $admin2->render( array( 'view' => 'items', 'n' => 100 ) + $params );
	};
	$has  = function ( string $html, string $title ) {
		return false !== strpos( $html, '>' . $title . '</a>' );
	};
	$html = $list( array( 'tab' => 'no_status' ) );
	ok( $has( $html, 'Yaki Nori Silver' ) && $has( $html, 'Frozen Tuna Saku' ) && ! $has( $html, 'Yaki Nori Gold' ), 'tab: no status' );
	$html = $list( array( 'tab' => 'out_of_stock' ) );
	ok( $has( $html, 'Wasabi Powder' ) && ! $has( $html, 'Nori Flakes' ), 'tab: out of stock' );
	$html = $list( array( 'tab' => 'hidden' ) );
	ok( $has( $html, 'Frozen Tuna Saku' ) && ! $has( $html, 'Frozen Eel' ), 'tab: hidden' );
	$html = $list( array( 'tab' => 'review' ) );
	ok( 2 <= substr_count( $html, '>Wasabi Powder</a>' ) && ! $has( $html, 'Yaki Nori Gold' ) && false !== strpos( $html, 'value="reviewed"' ), 'tab: needs review (marked + shared codes)' );
	$html = $list( array( 'tab' => 'active', 'img' => 'no' ) );
	ok( ! $has( $html, 'Yaki Nori Gold' ), 'filter: without photo' );
	ok( $has( $list( array( 'img' => 'no' ) ), 'Frozen Eel' ) && ! $has( $list( array( 'img' => 'yes' ) ), 'Frozen Eel' ), 'filter: photo yes / no' );
	$group = $c2->terms()->bySlug( $type, 'dry-goods' );
	$html  = $list( array( 'cat' => (int) $group['id'] ) );
	ok( $has( $html, 'Yaki Nori Gold' ) && $has( $html, 'Wasabi Powder' ) && ! $has( $html, 'Frozen Eel' ), 'filter: a group lists the products of its categories' );
	ok( $has( $list( array( 's' => 'kinjirushi' ) ), 'Wasabi Powder' ) && $has( $list( array( 's' => '30001' ) ), 'Frozen Eel' ), 'search: brand and item code' );
	ok( 1 === substr_count( $list( array() ), '<th>Status</th>' ) && false === strpos( $list( array() ), 'Any status' ), 'one status column, no second status filter' );

	$bulk = function ( array $extra ) use ( $admin2, $c2, $type ) {
		return $admin2->handle( array_merge( array( 'do' => 'bulk_items', 'type' => $type, 'back' => 'tab=review', Csrf::FIELD => $c2->csrf()->token( 'bulk_items' ) ), $extra ) );
	};
	$res = $bulk( array( 'bulk' => 'pstatus', 'pstatus_value' => 'active', 'ids' => array( $idOf( 102 ), $idOf( 107 ) ) ) );
	ok( false !== strpos( $res['redirect'], 'tab=review' ), 'bulk: returns to the same tab' );
	$c3 = new Catalog( $base->db(), $host, $base->prefix() );
	eq( array( 'active', 'active' ), array( $c3->items()->value( $idOf( 102 ), 'dw_pc_status' ), $c3->items()->value( $idOf( 107 ) , 'dw_pc_status' ) ), 'bulk: status changed' );
	eq( '10002', $c3->items()->value( $idOf( 102 ), 'dw_pc_item_code' ), 'bulk: other fields untouched' );
	ok( false !== strpos( $bulk( array( 'bulk' => 'pstatus', 'pstatus_value' => 'nonsense', 'ids' => array( $idOf( 102 ) ) ) )['redirect'], 'msg=invalid' ), 'bulk: unknown status refused' );
	$bulk( array( 'bulk' => 'brand', 'brand_value' => 'House Brand', 'ids' => array( $idOf( 103 ) ) ) );
	$bulk( array( 'bulk' => 'reviewed', 'ids' => array( $idOf( 104 ) ) ) );
	$c3 = new Catalog( $base->db(), $host, $base->prefix() );
	eq( array( 'House Brand', '' ), array( $c3->items()->value( $idOf( 103 ), 'dw_pc_brand_raw' ), (string) $c3->items()->get( $idOf( 104 ) )['review'] ), 'bulk: brand set, review mark cleared' );
	$host->roles = array( 'contributor' );
	$denied      = false;
	try {
		$c3->admin()->handle( array( 'do' => 'bulk_items', 'type' => $type, 'bulk' => 'pstatus', 'pstatus_value' => 'active', 'ids' => array( $idOf( 102 ) ), Csrf::FIELD => $c3->csrf()->token( 'bulk_items' ) ) );
	} catch ( Forbidden $e ) {
		$denied = true;
	}
	ok( $denied, 'bulk: view-only role cannot change status' );
	$j = $c3->admin()->handle( array( 'do' => 'check_code', 'type' => $type, 'code' => '22162', 'id' => $idOf( 104 ), Csrf::FIELD => $c3->csrf()->token( 'check_code' ) ) );
	eq( array( 'Wasabi Powder' ), array_column( $j['json']['used'], 'title' ), 'code check: names the other product using the code' );
	$j = $c3->admin()->handle( array( 'do' => 'check_code', 'type' => $type, 'code' => 'brand-new-code', Csrf::FIELD => $c3->csrf()->token( 'check_code' ) ) );
	eq( array(), $j['json']['used'], 'code check: free code' );
	$host->roles = array( 'administrator' );
	$dash        = $c3->admin()->render( array( 'view' => 'dashboard' ) );
	ok( false !== strpos( $dash, 'dwc-alerts' ) && false !== strpos( $dash, 'tab=review' ) && false !== strpos( $dash, 'img=no' ), 'dashboard: data-quality alerts link to the list' );
	$host->pages = array( array( 'title' => 'Shop', 'url' => 'https://test.example/shop/', 'edit_url' => '', 'shortcode' => '[dw_catalog]' ) );
	ok( false !== strpos( $c3->admin()->render( array( 'view' => 'dashboard' ) ), 'https://test.example/shop/' ), 'dashboard: catalog pages listed' );

	// Korean: every migration step, the product list tabs, the dashboard
	require_once dirname( __DIR__, 2 ) . '/build/i18n.php';
	$host->dict    = dwcat_i18n_dict( dirname( __DIR__, 2 ) . '/languages/dw-wholesale-ko_KR.po' );
	$host->missing = array();
	$ck            = new Catalog( $base->db(), $host, $base->prefix() );
	foreach ( array( 'detect', 'mapping', 'groups', 'check', 'run', 'finish', 'rollback' ) as $step ) {
		$ck->admin()->render( array( 'view' => 'migration', 'step' => $step, 'msg' => 'rolled' ) );
	}
	foreach ( array_keys( \DWCatalog\Admin\AdminApp::TABS ) as $tab ) {
		$ck->admin()->render( array( 'view' => 'items', 'tab' => $tab ) );
	}
	$ck->admin()->render( array( 'view' => 'dashboard' ) );
	$ck->admin()->render( array( 'view' => 'edit', 'id' => $idOf( 105 ) ) );
	$ck->migrator()->saveMapping( array_merge( $map, array( 'dw_pc_internal_note' => 'dw_pc_origin_raw', 'dw_pc_origin_raw' => '' ) ) );
	$ck->migrator()->saveMapping( $map );
	$missing = array_keys( $host->missing );
	if ( $missing && getenv( 'DWC_DUMP_MISSING' ) ) {
		file_put_contents( getenv( 'DWC_DUMP_MISSING' ), implode( "\n", $missing ) . "\n", FILE_APPEND );
	}
	ok( ! $missing, 'Korean: no untranslated string on the migration, product list and dashboard screens' . ( $missing ? ' — missing ' . count( $missing ) . ': ' . implode( ' | ', array_slice( $missing, 0, 40 ) ) : '' ) );
	$host->dict    = null;
	$host->missing = array();

	// 7. Rollback — only what the migration created; the source untouched
	echo "--- migration rollback ---\n";
	$c4    = new Catalog( $base->db(), $host, $base->prefix() );
	$m4    = $c4->migrator();
	$mine  = $c4->items()->save( array( 'type' => $type, 'title' => 'Added By Hand', 'status' => 'publish' ) ); // not part of the migration
	$photo = $c4->images()->path( $c4->images()->main( $idOf( 101 ) ) );
	$left  = $m4->rollbackBatch( 5 );
	ok( $left > 0 && 'rollback' === $m4->state()['run']['status'], 'rollback works batch by batch' );
	eq( array( 'replace:501', 'restore:501' ), $source->writes, 'rollback: the replaced page is put back first' );
	ok( false !== strpos( $source->pages[501]['content'], 'wp:kadence/query {"id":9}' ), 'rollback: page content exactly as before' );
	$j = $c4->admin()->handle( array( 'do' => 'migrate', 'op' => 'rollback', Csrf::FIELD => $c4->csrf()->token( 'migrate' ) ) );
	for ( $i = 0; $i < 50 && ! $j['json']['done']; $i++ ) {
		$j = $c4->admin()->handle( array( 'do' => 'migrate', 'op' => 'rollback', Csrf::FIELD => $c4->csrf()->token( 'migrate' ) ) );
	}
	ok( $j['json']['done'] && ! $m4->hasData(), 'rollback: finished' );
	eq( array( 'items' => $before['items'] + 1, 'terms' => $before['terms'], 'images' => $before['images'], 'apps' => $before['apps'] ), array( 'items' => $count( 'items' ), 'terms' => $count( 'terms' ), 'images' => $count( 'images' ), 'apps' => $count( 'applications' ) ), 'rollback: everything the migration created is gone, nothing else' );
	ok( null !== ( new Catalog( $base->db(), $host, $base->prefix() ) )->items()->get( $mine ), 'rollback: a product added by hand stays' );
	ok( ! is_file( $photo ) && ! is_file( $path ), 'rollback: migrated photo and attachment files removed' );
	ok( json_encode( array( $source->products, $source->categories, $source->applications ) ) === $frozen, 'the source was never modified' );
	ok( is_file( $source->applications[0]['files'][0]['path'] ) && is_file( $source->products[0]['image']['path'] ), 'source files still there' );
	eq( array( 'idle', false, 0 ), array( $m4->state()['run']['status'], $m4->state()['finish']['redirects'], $m4->itemForOldSlug( 'wasabi-powder-1' ) ), 'rollback: run reset, redirects off' );
	eq( 'clean', $m4->state()['choices']['dash_slug'], 'rollback: mapping and choices kept for the next try' );

	// A category that already existed is reused and survives a rollback
	$own = $c4->terms()->save( $type, 'category', 'Nori', 'nori' );
	$c5  = new Catalog( $base->db(), $host, $base->prefix() );
	$m5  = $c5->migrator();
	$m5->start();
	for ( $i = 0; $i < 20 && 'done' !== $m5->state()['run']['status']; $i++ ) {
		$m5->batch();
	}
	eq( array( $own, 0 ), array( (int) $m5->mapGet( 'term', '11' )['target_id'], (int) $m5->mapGet( 'term', '11' )['created'] ), 'existing category reused, not duplicated' );
	for ( $i = 0; $i < 50 && $m5->rollbackBatch() > 0; $i++ ) {
		continue;
	}
	ok( null !== ( new Catalog( $base->db(), $host, $base->prefix() ) )->terms()->get( $type, $own ), 'rollback keeps a category that was there before' );
	$c5->terms()->delete( $type, $own );
	$c5->deleteItem( $mine );
	$base->settings()->delete( Migrator::KEY );
	$host->source = null;
	$host->pages  = array();
}
