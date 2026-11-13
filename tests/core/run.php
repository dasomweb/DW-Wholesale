<?php
/**
 * Core integration tests — run against real databases.
 *
 *   php tests/core/run.php                       (uses env vars below; skips a DB that is not set)
 *
 *   DWC_MYSQL="mysql:host=127.0.0.1;port=3306;dbname=dwc_test"  DWC_MYSQL_USER=root DWC_MYSQL_PASS=
 *   DWC_MARIADB="mysql:host=127.0.0.1;port=3307;dbname=dwc_test" DWC_MARIADB_USER=root DWC_MARIADB_PASS=
 *   DWC_PGSQL="pgsql:host=127.0.0.1;port=5432;dbname=dwc_test"  DWC_PGSQL_USER=postgres DWC_PGSQL_PASS=
 *
 * Every run uses a fresh random table prefix and drops its tables at the end.
 */

require dirname( __DIR__, 2 ) . '/core/bootstrap.php';
require __DIR__ . '/TestHost.php';
require __DIR__ . '/migration.php';
require __DIR__ . '/widgets.php';

use DWCatalog\Admin\Forbidden;
use DWCatalog\Catalog;
use DWCatalog\Db\PdoConnection;
use DWCatalog\Front\Context;
use DWCatalog\I18n\Languages;
use DWCatalog\Security\Csrf;

$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $msg\n";
	} else {
		$fail++;
		echo "  ✗ $msg\n";
	}
}
function eq( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

$targets = array();
foreach ( array( 'mysql' => 'DWC_MYSQL', 'mariadb' => 'DWC_MARIADB', 'pgsql' => 'DWC_PGSQL' ) as $name => $env ) {
	if ( getenv( $env ) ) {
		$targets[ $name ] = array( getenv( $env ), (string) getenv( $env . '_USER' ), (string) getenv( $env . '_PASS' ) );
	}
}
if ( ! $targets ) {
	echo "No databases configured (DWC_MYSQL / DWC_PGSQL) — skipped.\n";
	exit( 0 );
}

foreach ( $targets as $target => list( $dsn, $user, $pw ) ) {
	$driver = 'pgsql' === $target ? 'pgsql' : 'mysql'; // MariaDB speaks the MySQL dialect
	echo "\n=== $target ===\n";
	$prefix = 't' . bin2hex( random_bytes( 3 ) ) . '_dwcat_';
	$dir    = sys_get_temp_dir() . '/dwc-test-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir );
	$pdo  = new PDO( $dsn, $user, $pw );
	$db   = new PdoConnection( $pdo, $prefix );
	$host = new TestHost( $dir );
	$c    = new Catalog( $db, $host, $prefix );

	try {
		run_suite( $c, $host, $driver );
	} catch ( Throwable $e ) {
		ok( false, 'Uncaught: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
	$c->uninstall();
	ok( 0 === (int) $db->selectValue( $driver === 'pgsql'
		? "SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE ?"
		: "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?", array( $prefix . '%' ) ), 'uninstall drops every table' );
}

echo "\nPASS: $pass\nFAIL: $fail\n";
exit( $fail ? 1 : 0 );

function run_suite( Catalog $c, TestHost $host, string $driver ) {
	echo "--- schema ---\n";
	ok( $c->needsInstall(), 'fresh database needs install' );
	$c->install();
	$c->install(); // idempotent
	ok( ! $c->needsInstall(), 'installed (twice, idempotent)' );
	// v1 → v2 upgrade path: drop the column, mark v1, reinstall
	$db = $c->db();
	if ( 'pgsql' === $driver ) {
		$db->execute( 'DROP INDEX IF EXISTS ' . $db->dialect()->quoteIdent( $c->prefix() . 'items_type_status_sort' ) );
	} else {
		$db->execute( 'ALTER TABLE ' . $db->table( 'items' ) . ' DROP INDEX type_status_sort' );
	}
	$db->execute( 'ALTER TABLE ' . $db->table( 'items' ) . ' DROP COLUMN sort_title' );
	$db->execute( 'UPDATE ' . $db->table( 'settings' ) . ' SET value = ? WHERE name = ?', array( '1', 'schema_version' ) );
	ok( $c->needsInstall() && ! $db->columnExists( 'items', 'sort_title' ), 'simulated v1 schema' );
	$c->install();
	ok( ! $c->needsInstall() && $db->columnExists( 'items', 'sort_title' ), 'upgrade to v2 adds sort_title' );

	echo "--- fields ---\n";
	$type = $c->fields()->primaryType();
	eq( 'product', $type, 'default type' );
	$fields   = $c->fields()->fields( $type );
	$fields[] = array( 'meta_key' => 'dw_pc_price', 'label' => 'Price', 'type' => 'number', 'show_in_list' => true, 'show_in_export' => true, 'show_in_detail' => true );
	$fields[] = array( 'meta_key' => 'dw_pc_cost', 'label' => 'Cost', 'type' => 'number', 'show_in_list' => true, 'show_in_detail' => false );
	$c->fields()->saveFields( $type, $fields );
	eq( 9, count( $c->fields()->fields( $type ) ), 'fields saved' );
	ok( ! isset( $c->fields()->fieldsByKey( $type, true )['dw_pc_cost'] ), 'non-public field excluded from public set' );

	echo "--- terms ---\n";
	$t     = $c->terms();
	$ref   = $t->save( $type, 'category', 'Refrigerated' );
	$ramen = $t->save( $type, 'category', 'Ramen Soup Base', '', $ref );
	$tofu  = $t->save( $type, 'category', 'Tofu', '', $ref );
	$froz  = $t->save( $type, 'category', 'Frozen' );
	$dup   = $t->save( $type, 'category', 'Tofu', '', $froz );
	ok( 'tofu-2' === $t->get( $type, $dup )['slug'], 'duplicate slug gets a suffix' );
	eq( array( $ref, $ramen, $tofu ), array_values( array_intersect( array( $ref, $ramen, $tofu ), $t->withDescendants( $type, array( $ref ) ) ) ), 'descendants include children' );
	$t->save( $type, 'category', 'Refrigerated', 'refrigerated', $ramen, $ref );
	eq( 0, (int) $t->get( $type, $ref )['parent'], 'cannot make a category its own descendant' );
	eq( $tofu, $t->ensure( $type, 'Refrigerated > Tofu' ), 'ensure() finds "Parent > Child"' );

	echo "--- items ---\n";
	$items = $c->items();
	$mk    = function ( $title, $values, $cat, $status = 'publish', $vars = array() ) use ( $c, $items, $type ) {
		$id = $items->save( array( 'type' => $type, 'title' => $title, 'status' => $status, 'content' => '<p>Rich soup base</p>' ) );
		$items->saveValues( $id, $values );
		$c->terms()->setItem( $type, $id, 'category', array( $cat ) );
		if ( $vars ) {
			$items->saveVariations( $id, $vars );
		}
		return $id;
	};
	$a = $mk( '(Wakou#9) Spicy Miso Ramen Soup', array( 'dw_pc_item_code' => 'WK-009', 'dw_pc_brand_raw' => 'Wakou', 'dw_pc_price' => '42.5', 'dw_pc_cost' => '30', 'dw_pc_internal_note' => 'SECRETNOTE' ), $ramen, 'publish',
		array( array( 'name' => '6 pack', 'item_code' => 'WK-009-6', 'price' => '42.5', 'stock' => 'in_stock' ), array( 'name' => '12 pack', 'item_code' => 'WK-009-12', 'price' => '80', 'stock' => 'out_of_stock' ) ) );
	$b = $mk( '(Wakou#1) Tonkotsu Ramen Soup', array( 'dw_pc_item_code' => 'WK-001', 'dw_pc_brand_raw' => 'Wakou', 'dw_pc_price' => '39' ), $ramen );
	$d = $mk( '(Somi) Miso Ramen Soup', array( 'dw_pc_item_code' => 'SM-310', 'dw_pc_brand_raw' => 'Somi' ), $ramen );
	$e = $mk( 'Firm Tofu', array( 'dw_pc_item_code' => 'TF-100', 'dw_pc_brand_raw' => 'House Foods', 'dw_pc_price' => '18' ), $tofu );
	$f = $mk( 'Black Tiger Shrimp', array( 'dw_pc_item_code' => 'SH-1620', 'dw_pc_brand_raw' => 'Sea Jem', 'dw_pc_price' => '120' ), $froz );
	$g = $mk( 'Draft Miso', array( 'dw_pc_item_code' => 'DR-1' ), $ramen, 'draft' );
	$x = $items->save( array( 'type' => $type, 'title' => 'Firm Tofu', 'status' => 'publish' ) );
	eq( 'firm-tofu-2', $items->get( $x )['slug'], 'item slug made unique' );
	$c->deleteItem( $x );
	ok( null === $items->get( $x ), 'item deleted' );
	eq( 'Wakou', $items->value( $a, 'dw_pc_brand_raw' ), 'value read back' );
	eq( 2, count( $items->variations( $a ) ), 'variations read back' );
	eq( 'WK-009-12', $items->variations( $a )[1]['item_code'], 'variation order kept' );

	$q   = $c->query();
	$run = function ( $f ) use ( $q, $type ) {
		return array_column( $q->run( $type, $f )['rows'], 'title' );
	};
	$count = function ( $f ) use ( $q, $type ) {
		return $q->run( $type, $f )['total'];
	};

	echo "--- search ---\n";
	eq( 2, $count( array( 'search' => 'miso', 'public' => true ) ), 'word search (draft excluded)' );
	eq( 1, $count( array( 'search' => 'wk-009', 'public' => true ) ), 'code with punctuation' );
	eq( 1, $count( array( 'search' => 'wk009', 'public' => true ) ), 'compact code' );
	eq( 1, $count( array( 'search' => 'WK-009-12', 'public' => true ) ), 'variation code' );
	eq( 1, $count( array( 'search' => 'spicy mi', 'public' => true ) ), 'all words must match (prefix)' );
	eq( 0, $count( array( 'search' => 'iso', 'public' => true ) ), 'mid-word does not match (index prefix)' );
	eq( 0, $count( array( 'search' => 'secretnote', 'public' => true ) ), 'internal field not searchable publicly' );
	eq( 1, $count( array( 'search' => 'secretnote', 'status' => 'any' ) ), 'admin search finds internal field' );
	eq( $count( array( 'public' => true ) ), $count( array( 'search' => '%%% ___', 'public' => true ) ), 'symbol-only search is ignored (no LIKE wildcards reach SQL)' );

	echo "--- filters ---\n";
	eq( 4, $count( array( 'base_cat' => 'refrigerated', 'public' => true ) ), 'base category includes descendants' );
	eq( 1, $count( array( 'base_cat' => 'refrigerated', 'cats' => array( $tofu ), 'public' => true ) ), 'category filter' );
	eq( 0, $count( array( 'base_cat' => 'refrigerated', 'cats' => array( $froz ), 'public' => true ) ), 'category filter ANDs with base' );
	eq( 0, $count( array( 'base_cat' => 'nope', 'public' => true ) ), 'unknown base category → nothing' );
	eq( 3, $count( array( 'meta' => array( 'dw_pc_brand_raw' => array( 'Wakou', 'Somi' ) ), 'public' => true ) ), 'field filter OR within field' );
	eq( 5, $count( array( 'meta' => array( 'dw_pc_cost' => array( '30' ) ), 'public' => true ) ), 'non-public field filter ignored on site' );
	eq( 1, $count( array( 'meta' => array( 'dw_pc_cost' => array( '30' ) ) ) ), 'admin may filter any field' );

	echo "--- sort ---\n";
	eq( array( 'Firm Tofu', '(Wakou#1) Tonkotsu Ramen Soup', '(Wakou#9) Spicy Miso Ramen Soup', 'Black Tiger Shrimp', '(Somi) Miso Ramen Soup' ), $run( array( 'sort' => 'field:dw_pc_price:asc', 'public' => true ) ), 'numeric asc, empty last' );
	eq( array( 'Black Tiger Shrimp', '(Wakou#9) Spicy Miso Ramen Soup', '(Wakou#1) Tonkotsu Ramen Soup', 'Firm Tofu', '(Somi) Miso Ramen Soup' ), $run( array( 'sort' => 'field:dw_pc_price:desc', 'public' => true ) ), 'numeric desc, empty last' );
	eq( array( 'Black Tiger Shrimp', 'Firm Tofu', '(Somi) Miso Ramen Soup', '(Wakou#1) Tonkotsu Ramen Soup', '(Wakou#9) Spicy Miso Ramen Soup' ), $run( array( 'sort' => 'title_asc', 'public' => true ) ), 'title sort ignores punctuation (same on every database)' );
	ok( ! isset( $q->sortOptions( $type, true )['field:dw_pc_cost:asc'] ), 'non-public number field not offered for sorting' );
	eq( 'date_desc', $q->resolveSort( $type, 'field:dw_pc_cost:asc', true )['column'] === 'created_at' ? 'date_desc' : 'x', 'non-public sort falls back' );
	eq( array( 'Firm Tofu', '(Somi) Miso Ramen Soup' ), $run( array( 'ids' => array( $e, $d ), 'sort' => 'ids' ) ), 'explicit id order' );

	echo "--- paging ---\n";
	$r = $q->run( $type, array( 'public' => true, 'per_page' => 2, 'page' => 3 ) );
	eq( array( 5, 3, 1 ), array( $r['total'], $r['pages'], count( $r['rows'] ) ), 'total / pages / last page' );
	eq( 100, count( $q->run( $type, array( 'per_page' => 100000 ) )['rows'] ) <= 100 ? 100 : 0, 'per_page capped' );

	echo "--- filter choices ---\n";
	$brand = $c->fields()->fieldsByKey( $type )['dw_pc_brand_raw'];
	eq( array( 'House Foods', 'Somi', 'Wakou' ), array_values( $q->filterChoices( $type, $brand, 'refrigerated' ) ), 'choices scoped to category, published only' );

	echo "--- csrf ---\n";
	$token = $c->csrf()->token( 'save_item' );
	ok( $c->csrf()->verify( 'save_item', $token ), 'token verifies' );
	ok( ! $c->csrf()->verify( 'delete_item', $token ), 'token bound to action' );
	$host->user = 2;
	ok( ! $c->csrf()->verify( 'save_item', $token ), 'token bound to user' );
	$host->user = 1;

	echo "--- admin ---\n";
	$admin = $c->admin();
	foreach ( array_merge( array_keys( \DWCatalog\Admin\AdminApp::VIEWS ), array( 'reference' ) ) as $view ) {
		$html = $admin->render( array( 'view' => $view ) );
		ok( false !== strpos( $html, 'dwc-admin' ) && false === stripos( $html, 'warning' ), "view renders: $view" );
	}
	ok( false !== strpos( $admin->render( array( 'view' => 'edit', 'id' => $a ) ), 'WK-009-12' ), 'editor shows variations' );
	$post = array( 'do' => 'save_item', 'type' => $type, 'id' => $b, 'title' => 'Tonkotsu <b>Renamed</b>', 'status' => 'publish',
		'fields' => array( 'dw_pc_price' => 'abc', 'dw_pc_brand_raw' => 'Wakou' ), 'cats' => array( $ramen ),
		'var' => array( array( 'name' => 'Big', 'item_code' => 'WK1-B', 'price' => 'x', 'stock' => 'bogus' ), array( 'name' => '', 'stock' => 'in_stock' ) ) );
	try {
		$admin->handle( $post );
		ok( false, 'POST without CSRF token rejected' );
	} catch ( Forbidden $ex ) {
		ok( true, 'POST without CSRF token rejected' );
	}
	$post[ Csrf::FIELD ] = $c->csrf()->token( 'save_item' );
	$res                 = $admin->handle( $post );
	ok( false !== strpos( $res['redirect'], 'msg=saved' ), 'save_item redirects with saved' );
	$items2 = ( new Catalog( $c->db(), $host, '' ) )->items(); // fresh caches
	eq( 'Tonkotsu Renamed', $items2->get( $b )['title'], 'title stripped of tags' );
	eq( '', $items2->value( $b, 'dw_pc_price' ), 'invalid number rejected' );
	eq( array( array( 'name' => 'Big', 'item_code' => 'WK1-B', 'pack_size' => '', 'price' => '', 'stock' => '' ) ), $items2->variations( $b ), 'variation row cleaned, blank row dropped' );
	$host->roles = array( 'author' ); // products: edit, but no delete permission
	try {
		$admin->handle( array( 'do' => 'delete_item', 'type' => $type, 'id' => $b, Csrf::FIELD => $c->csrf()->token( 'delete_item' ) ) );
		ok( false, 'delete without the product_delete permission rejected' );
	} catch ( Forbidden $ex ) {
		ok( true, 'delete without the product_delete permission rejected' );
	}
	$host->roles  = array( 'administrator' );
	$host->denied = array( 'manage_fields' );
	try {
		$admin->handle( array( 'do' => 'save_fields', 'type' => $type, Csrf::FIELD => $c->csrf()->token( 'save_fields' ) ) );
		ok( false, 'host policy (license) blocks action' );
	} catch ( Forbidden $ex ) {
		ok( true, 'host policy (license) blocks action' );
	}
	$host->denied = array();

	// List: shared item codes, bulk + row actions
	$items->saveValues( $g, array_merge( $items->values( $g ), array( 'dw_pc_item_code' => 'TF-100' ) ) );
	eq( array( 'TF-100' => 2 ), ( new \DWCatalog\Admin\ItemStats( $c ) )->shared( $type, 'dw_pc_item_code' ), 'shared item codes detected' );
	$html = $admin->render( array( 'view' => 'items', 'dup' => 1 ) );
	ok( false !== strpos( $html, 'Firm Tofu' ) && false !== strpos( $html, 'Draft Miso' ) && false === strpos( $html, 'Spicy Miso' ), 'needs-review filter lists only shared codes' );
	ok( false !== strpos( $html, 'dwc-shared' ), 'shared badge shown' );
	ok( false !== strpos( $admin->render( array( 'view' => 'edit', 'id' => $e ) ), 'Draft Miso' ), 'editor names the other item using the code' );
	$bulk = function ( array $extra ) use ( $admin, $c, $type ) {
		return $admin->handle( array_merge( array( 'do' => 'bulk_items', 'type' => $type, 'back' => 's=tofu&n=50&evil=1', Csrf::FIELD => $c->csrf()->token( 'bulk_items' ) ), $extra ) );
	};
	$res = $bulk( array( 'bulk' => 'draft', 'ids' => array( $e, 999999 ) ) );
	ok( false !== strpos( $res['redirect'], 's=tofu' ) && false !== strpos( $res['redirect'], 'n=50' ) && false === strpos( $res['redirect'], 'evil' ), 'bulk keeps list state, drops unknown params' );
	eq( 'draft', ( new Catalog( $c->db(), $host, '' ) )->items()->get( $e )['status'], 'bulk set inactive' );
	$bulk( array( 'one' => 'publish:' . $e ) );
	eq( 'publish', ( new Catalog( $c->db(), $host, '' ) )->items()->get( $e )['status'], 'row action set active' );
	$bulk( array( 'bulk' => 'move', 'move_cat' => $ramen, 'ids' => array( $e ) ) );
	eq( array( $ramen ), array_map( 'intval', array_column( ( new Catalog( $c->db(), $host, '' ) )->terms()->ofItem( $type, $e ), 'id' ) ), 'bulk move to category' );
	$res = $bulk( array( 'bulk' => 'export', 'ids' => array( $e ) ) );
	ok( isset( $res['download'] ) && false !== strpos( $res['download'][2], 'TF-100' ) && false === strpos( $res['download'][2], 'WK-009' ), 'export selected = only those items' );
	$host->roles = array( 'author' ); // products: edit, but no delete permission
	try {
		$bulk( array( 'bulk' => 'delete', 'ids' => array( $e ) ) );
		ok( false, 'bulk delete needs the product_delete permission' );
	} catch ( Forbidden $ex ) {
		ok( true, 'bulk delete needs the product_delete permission' );
	}
	$host->roles = array( 'administrator' );
	$c->terms()->setItem( $type, $e, 'category', array( $tofu ) );
	$items->saveValues( $g, array_merge( $items->values( $g ), array( 'dw_pc_item_code' => 'DR-1' ) ) );

	// Category tree: drag & drop save (order + parent)
	$tree = function ( array $nodes ) use ( $admin, $c, $type ) {
		return $admin->handle( array( 'do' => 'save_tree', 'type' => $type, 'tree' => json_encode( $nodes ), Csrf::FIELD => $c->csrf()->token( 'save_tree' ) ) );
	};
	$fresh = function () use ( $c, $host ) {
		return ( new Catalog( $c->db(), $host, '' ) )->terms();
	};
	// Tofu → under Frozen, first; Ramen stays under Refrigerated; Frozen before Refrigerated
	$tree( array( array( 'id' => $froz, 'parent' => 0 ), array( 'id' => $tofu, 'parent' => $froz ), array( 'id' => $ref, 'parent' => 0 ), array( 'id' => $ramen, 'parent' => $ref ), array( 'id' => 999999, 'parent' => 0 ) ) );
	$tt = $fresh()->all( $type );
	eq( array( $froz, 0 ), array( (int) $tt[ $tofu ]['parent'], (int) $tt[ $tofu ]['position'] ), 'tree: moved into another parent' );
	eq( array( 0, 1 ), array( (int) $tt[ $froz ]['position'], (int) $tt[ $ref ]['position'] ), 'tree: top-level order renumbered' );
	ok( false !== strpos( implode( ',', $fresh()->resolve( $type, 'frozen' ) ), (string) $froz ) && in_array( $tofu, $fresh()->withDescendants( $type, array( $froz ) ), true ), 'tree: descendants follow the new parent' );
	// A cycle (Refrigerated inside Ramen, its own child) is refused → top level
	$tree( array( array( 'id' => $ref, 'parent' => $ramen ), array( 'id' => $ramen, 'parent' => $ref ) ) );
	$tt = $fresh()->all( $type );
	ok( 0 === (int) $tt[ $ref ]['parent'] || 0 === (int) $tt[ $ramen ]['parent'], 'tree: cycle broken' );
	$res = $admin->handle( array( 'do' => 'save_tree', 'type' => $type, 'tree' => 'not json', Csrf::FIELD => $c->csrf()->token( 'save_tree' ) ) );
	ok( false !== strpos( $res['redirect'], 'msg=invalid' ), 'tree: bad payload rejected' );
	ok( false !== strpos( $admin->render( array( 'view' => 'categories' ) ), 'data-dwc-tree' ), 'tree: categories screen renders the tree' );
	// Restore the fixture
	$tree( array( array( 'id' => $ref, 'parent' => 0 ), array( 'id' => $ramen, 'parent' => $ref ), array( 'id' => $tofu, 'parent' => $ref ), array( 'id' => $froz, 'parent' => 0 ) ) );

	echo "--- front ---\n";
	$ctx  = new Context( 'https://site.test/refrigerated/', 'https://site.test/refrigerated/item/', array() );
	$html = $c->front()->catalog( array( 'category' => 'refrigerated', 'filter_fields' => 'dw_pc_brand_raw,dw_pc_cost' ), $ctx );
	ok( false !== strpos( $html, '4 items' ), 'list renders with count' );
	ok( false !== strpos( $html, 'dwf[dw_pc_brand_raw][]' ) && false === strpos( $html, 'dwf[dw_pc_cost]' ), 'only public filter offered' );
	ok( false === strpos( $html, 'SECRETNOTE' ) && false === strpos( $html, 'Cost' ), 'no internal data in list HTML' );
	ok( false !== strpos( $html, 'https://site.test/refrigerated/item/firm-tofu/' ), 'pretty item links' );
	$ctx2 = new Context( 'https://site.test/refrigerated/', 'https://site.test/refrigerated/item/', array(), $items->get( $a )['slug'] );
	$html = $c->front()->catalog( array( 'category' => 'refrigerated' ), $ctx2 );
	ok( false !== strpos( $html, 'dwcat-detail' ) && false !== strpos( $html, 'WK-009-12' ), 'detail renders with variations' );
	ok( false === strpos( $html, 'SECRETNOTE' ) && false === strpos( $html, '>30<' ), 'no internal data in detail HTML' );
	$ctx3 = new Context( 'https://site.test/refrigerated/', '', array( 'dw_item' => $items->get( $f )['slug'] ) );
	ok( false !== strpos( $c->front()->catalog( array( 'category' => 'refrigerated' ), $ctx3 ), 'not available' ), 'item outside the page category is not shown' );
	$ctx4 = new Context( 'https://site.test/refrigerated/', '', array( 'dw_item' => $items->get( $g )['slug'] ) );
	ok( false !== strpos( $c->front()->catalog( array(), $ctx4 ), 'not available' ), 'draft item is not shown' );
	$seo = $c->front()->seo( $items->get( $a ), $ctx2 );
	eq( 'https://site.test/refrigerated/item/' . $items->get( $a )['slug'] . '/', $seo['canonical'], 'SEO canonical' );
	eq( 'WK-009', $seo['json_ld']['sku'], 'JSON-LD sku from public field' );
	$xss = $items->save( array( 'type' => $type, 'title' => '<script>alert(1)</script>Evil', 'status' => 'publish' ) );
	$items->saveValues( $xss, array( 'dw_pc_brand_raw' => '"><img src=x onerror=alert(1)>' ) );
	$html = $c->front()->catalog( array(), new Context( 'https://site.test/c/', '', array( 'dwq' => '"><svg onload=alert(1)>' ) ) );
	ok( false === strpos( $html, '<script>alert' ) && false === strpos( $html, '<img src=x' ) && false === strpos( $html, '<svg onload' ), 'output escaped (title, values, query echo)' );
	$grid = $c->front()->grid( array( 'category' => 'refrigerated', 'per_page' => 2 ), $ctx );
	ok( 2 === substr_count( $grid, 'dwcat-card-title' ), 'grid block' );

	run_access_suite( $c, $host, $type, $a );
	run_i18n_suite( $c, $host, $type, $a, $ctx, $ctx2 );

	echo "--- csv export / import ---\n";
	$items->saveValues( $d, array_merge( $items->values( $d ), array( 'dw_pc_pack_size_raw' => '=SUM(A1)' ) ) );
	$exp  = ( new \DWCatalog\Admin\CsvExporter( $c ) )->run( $type, array( 'status' => 'any' ) );
	$body = $exp['download'][2];
	ok( "\xEF\xBB\xBF" === substr( $body, 0, 3 ), 'CSV has UTF-8 BOM (Excel)' );
	$lines = array_map( function ( $l ) { return str_getcsv( $l, ',', '"', '' ); }, array_values( array_filter( explode( "\n", substr( $body, 3 ) ), 'strlen' ) ) );
	$head  = $lines[0];
	ok( in_array( 'var_item_code', $head, true ) && in_array( 'dw_pc_cost', $head, true ) && in_array( 'category', $head, true ), 'header: fields (incl. internal), category, var_ columns' );
	$slugA = $items->get( $a )['slug'];
	$rowsA = array_values( array_filter( $lines, function ( $l ) use ( $slugA ) { return $l[0] === $slugA; } ) );
	eq( 2, count( $rowsA ), 'one row per variation' );
	eq( '', $rowsA[1][ array_search( 'title', $head, true ) ], 'continuation row carries only slug + var_' );
	eq( 'Refrigerated > Ramen Soup Base', $rowsA[0][ array_search( 'category', $head, true ) ], 'category exported as path' );
	ok( false !== strpos( $body, "'=SUM(A1)" ), 'formula-looking cell neutralised' );

	$tmpCsv = tempnam( sys_get_temp_dir(), 'csv' );
	// round trip: re-import the export unchanged → everything updated, nothing duplicated
	file_put_contents( $tmpCsv, $body );
	$before = $q->run( $type, array( 'status' => 'any' ) )['total'];
	$rep    = ( new \DWCatalog\Admin\Importer( $c ) )->importFile( $type, $tmpCsv, array( 'existing' => 'update' ) );
	ok( false !== strpos( $rep[0], '0 created' ), 're-import of export creates nothing: ' . $rep[0] );
	eq( $before, $q->run( $type, array( 'status' => 'any' ) )['total'], 'no duplicates after round trip' );
	$fresh = ( new Catalog( $c->db(), $host, '' ) )->items();
	eq( 2, count( $fresh->variations( $a ) ), 'variations survive round trip' );
	eq( '=SUM(A1)', $fresh->value( $d, 'dw_pc_pack_size_raw' ), 'formula guard undone on import' );
	eq( 'SECRETNOTE', $fresh->value( $a, 'dw_pc_internal_note' ), 'internal field round-trips' );

	// partial update: only slug + price
	file_put_contents( $tmpCsv, "slug,dw_pc_price\n" . $items->get( $e )['slug'] . ",19.5\n" );
	( new \DWCatalog\Admin\Importer( $c ) )->importFile( $type, $tmpCsv, array( 'existing' => 'update' ) );
	$fresh = ( new Catalog( $c->db(), $host, '' ) )->items();
	eq( '19.5', $fresh->value( $e, 'dw_pc_price' ), 'partial update changes the given column' );
	eq( 'TF-100', $fresh->value( $e, 'dw_pc_item_code' ), 'partial update keeps other columns' );

	// new item with variations + new category path, semicolon file
	file_put_contents( $tmpCsv, "slug;title;category;dw_pc_brand_raw;var_name;var_price;var_stock\nking-crab;King Crab;Frozen > Crab;Sea Jem;Small;30;in_stock\nking-crab;;;;Large;55;out_of_stock\n" );
	$rep  = ( new \DWCatalog\Admin\Importer( $c ) )->importFile( $type, $tmpCsv, array( 'existing' => 'update', 'delimiter' => 'auto' ) );
	$fresh = new Catalog( $c->db(), $host, '' );
	$crab  = $fresh->items()->getBySlug( $type, 'king-crab' );
	ok( $crab && false !== strpos( $rep[0], '1 created' ), 'new item created from CSV' );
	eq( array( 'Small', 'Large' ), array_column( $fresh->items()->variations( (int) $crab['id'] ), 'name' ), 'variations from continuation rows' );
	eq( 'Crab', $fresh->terms()->ofItem( $type, (int) $crab['id'] )[0]['name'], 'category path created' );
	$rep = ( new \DWCatalog\Admin\Importer( $c ) )->importFile( $type, $tmpCsv, array( 'existing' => 'skip' ) );
	ok( false !== strpos( $rep[0], '1 skipped' ), 'skip mode leaves existing items: ' . $rep[0] );

	$tpl = ( new \DWCatalog\Admin\CsvExporter( $c ) )->run( $type, array( 'template' => 1 ) );
	eq( 3, count( array_filter( explode( "\n", $tpl['download'][2] ), 'strlen' ) ), 'template = header + 2 example rows' );
	@unlink( $tmpCsv );

	echo "--- images ---\n";
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		echo "  (GD not loaded — run with -d extension=gd)\n";
	} else {
		$tmp = tempnam( sys_get_temp_dir(), 'img' );
		$im  = imagecreatetruecolor( 1600, 900 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 30, 30 ) );
		imagejpeg( $im, $tmp, 90 );
		$id = $c->images()->store( $e, $tmp, 'Firm Tofu Front.JPG' );
		ok( is_int( $id ), 'jpeg stored' );
		$img = $c->images()->main( $e );
		ok( preg_match( '#^\d{4}/\d{2}/firm-tofu-front-[0-9a-f]{8}-large\.(webp|jpg)$#', $img['file'] ) === 1, 'safe generated file name' );
		eq( function_exists( 'imagewebp' ) ? 'image/webp' : 'image/jpeg', $img['mime'], 'web format (WebP when available)' );
		ok( $img['width'] <= 1200 && $img['height'] <= 1200, 'largest stored size is 1200px (web, not print)' );
		ok( false !== strpos( $c->images()->tag( $img, 'large', '100vw', false ), 'fetchpriority="high"' ), 'priority image tag' );
		eq( array( 'large', 'medium', 'thumb' ), array_keys( $img['sizes'] ), 'sizes generated' );
		eq( array( 300, 300 ), array( $img['sizes']['thumb']['width'], $img['sizes']['thumb']['height'] ), 'thumb is a 300² crop' );
		eq( 1200, $img['sizes']['large']['width'], 'large fits 1200' );
		ok( is_file( $c->images()->path( $img, 'medium' ) ), 'size file on disk' );
		ok( false !== strpos( $c->images()->tag( $img, 'medium' ), 'srcset=' ), 'img tag with srcset' );

		$evil = tempnam( sys_get_temp_dir(), 'evil' );
		file_put_contents( $evil, "<?php echo 'pwned'; ?>" );
		ok( is_string( $c->images()->store( $e, $evil, 'shell.php.jpg' ) ), 'non-image rejected' );
		$poly = tempnam( sys_get_temp_dir(), 'poly' );
		file_put_contents( $poly, file_get_contents( $tmp ) . "<?php system('id'); ?>" );
		$pid  = $c->images()->store( $e, $poly, 'poly.jpg' );
		$prow = null;
		foreach ( $c->images()->forItem( $e ) as $r ) {
			if ( (int) $r['id'] === $pid ) {
				$prow = $r;
			}
		}
		ok( $prow && false === strpos( file_get_contents( $c->images()->path( $prow ) ), '<?php' ), 'appended payload stripped by re-encode' );
		ok( is_file( $host->dir . '/.htaccess' ) && is_file( $host->dir . '/index.html' ), 'storage guards written' );

		$path = $c->images()->path( $img );
		$c->deleteItem( $e );
		ok( ! is_file( $path ), 'image files removed with the item' );
		@unlink( $tmp );
		@unlink( $evil );
		@unlink( $poly );
	}

	echo "--- delete cleanup ---\n";
	$c->deleteItem( $a );
	$left = 0;
	foreach ( array( 'values', 'variations', 'search', 'item_terms' ) as $tbl ) {
		$left += (int) $c->db()->selectValue( 'SELECT COUNT(*) FROM ' . $c->db()->table( $tbl ) . ' WHERE item_id = ?', array( $a ) );
	}
	eq( 0, $left, 'all rows of a deleted item removed' );

	run_migration_suite( $c, $host, $type );
	run_widgets_suite( $c, $host, $type );
}

/** M8 — role × menu levels, action permissions, operator mode, hidden screens refused. */
function run_access_suite( Catalog $c, TestHost $host, string $type, int $itemId ) {
	echo "--- access ---\n";
	$acc    = $c->access();
	$admin  = $c->admin();
	$as     = function ( array $roles ) use ( $host ) {
		$host->roles = $roles;
	};
	$denied = function ( callable $fn ) {
		try {
			$fn();
			return false;
		} catch ( Forbidden $e ) {
			return true;
		}
	};
	$post = function ( string $do, array $extra = array() ) use ( $admin, $c, $type ) {
		return $admin->handle( array_merge( array( 'do' => $do, 'type' => $type, Csrf::FIELD => $c->csrf()->token( $do ) ), $extra ) );
	};

	// Defaults (spec M8)
	$expect = array(
		'editor'      => array( 'dashboard' => 1, 'products' => 2, 'categories' => 2, 'customers' => 2, 'fields' => 0, 'import_export' => 0, 'migration' => 0, 'shortcodes' => 0, 'design' => 0, 'presets' => 0, 'settings' => 0, 'access' => 0 ),
		'author'      => array( 'dashboard' => 1, 'products' => 2, 'categories' => 1, 'customers' => 1, 'fields' => 0, 'settings' => 0 ),
		'contributor' => array( 'dashboard' => 1, 'products' => 1, 'categories' => 0, 'customers' => 0 ),
	);
	foreach ( $expect as $role => $menus ) {
		$got = array();
		foreach ( $menus as $menu => $lvl ) {
			$got[ $menu ] = $acc->levelFor( array( $role ), false, $menu );
		}
		eq( $menus, $got, "defaults: $role" );
	}
	eq( 2, $acc->levelFor( array( 'administrator' ), true, 'access' ), 'administrator: full, including Access' );
	eq( 0, $acc->levelFor( array( 'shop_manager' ), false, 'dashboard' ), 'unknown role: nothing by default' );
	eq( '', $acc->firstMenuFor( array( 'shop_manager' ), false ), 'unknown role: no landing screen' );
	eq( 0, $acc->levelFor( array( 'editor', 'dw_wholesale_customer' ), false, 'products' ), 'customer role never reaches the admin' );
	ok( $acc->allowedFor( array( 'editor' ), false, 'product_delete' ) && ! $acc->allowedFor( array( 'author' ), false, 'product_delete' ), 'action defaults: editor deletes, author does not' );
	ok( ! $acc->allowedFor( array( 'editor' ), false, 'csv_export' ) && $acc->allowedFor( array(), true, 'csv_export' ), 'CSV export: administrators only by default' );
	eq( 2, $acc->levelFor( array( 'contributor', 'editor' ), false, 'products' ), 'several roles: highest level wins' );

	// Screens: hidden ones are refused, view-only ones are locked
	$as( array( 'editor' ) );
	ok( ! $admin->canOpen( array( 'view' => 'fields' ) ), 'editor: Fields hidden (canOpen)' );
	ok( false !== strpos( $admin->render( array( 'view' => 'fields' ) ), 'do not have permission' ), 'editor: Fields refused by URL' );
	ok( ! $admin->canOpen( array( 'view' => 'access' ) ) && ! $admin->canOpen( array( 'view' => 'settings' ) ), 'editor: Access and Settings hidden' );
	$dash = $admin->render( array( 'view' => 'dashboard' ) );
	ok( false !== strpos( $dash, 'view=items' ) && false === strpos( $dash, 'view=fields' ) && false === strpos( $dash, 'view=access' ), 'editor: navigation lists only allowed menus' );
	ok( $denied( function () use ( $post ) {
		$post( 'save_fields' );
	} ), 'editor: save_fields refused' );
	ok( $denied( function () use ( $post ) {
		$post( 'save_access', array( 'access' => array( 'roles' => array( 'editor' => array( 'menus' => array( 'fields' => 2 ) ) ) ) ) );
	} ), 'editor cannot change Access' );
	ok( $denied( function () use ( $post ) {
		$post( 'export_csv' );
	} ), 'editor: CSV export needs the csv_export permission' );

	$as( array( 'contributor' ) );
	$list = $admin->render( array( 'view' => 'items' ) );
	ok( false !== strpos( $list, 'is-readonly' ) && false !== strpos( $list, 'View only' ), 'contributor: product list is view-only' );
	ok( false === strpos( $list, 'Add item' ) && false === strpos( $list, 'name="bulk" value="publish"' ) && false === strpos( $list, 'value="publish:' ), 'contributor: no add / status buttons' );
	ok( ! $admin->canOpen( array( 'view' => 'edit' ) ) && $admin->canOpen( array( 'view' => 'edit', 'id' => $itemId ) ), 'contributor: may open a product, may not add one' );
	ok( $denied( function () use ( $post, $itemId ) {
		$post( 'save_item', array( 'id' => $itemId, 'title' => 'x' ) );
	} ), 'contributor: save_item refused' );
	ok( $denied( function () use ( $post, $itemId ) {
		$post( 'bulk_items', array( 'bulk' => 'draft', 'ids' => array( $itemId ) ) );
	} ), 'contributor: bulk status change refused' );
	ok( $denied( function () use ( $post, $itemId ) {
		$post( 'bulk_items', array( 'bulk' => 'export', 'ids' => array( $itemId ) ) );
	} ), 'contributor: bulk export refused without csv_export' );

	$as( array( 'shop_manager' ) );
	ok( ! $admin->canOpen( array() ), 'role without access: nothing opens' );
	ok( $denied( function () use ( $post ) {
		$post( 'set_lang', array( 'lang' => 'ko' ) );
	} ), 'role without access: cannot even switch language' );
	$host->user  = 0;
	$host->roles = array();
	ok( ! $admin->canOpen( array( 'view' => 'dashboard' ) ), 'visitor: nothing opens' );
	$host->user = 1;

	// Saving the matrix (administrator)
	$as( array( 'administrator' ) );
	$post( 'save_access', array( 'access' => array(
		'roles'         => array(
			'contributor'  => array( 'menus' => array( 'products' => 2, 'dashboard' => 2, 'access' => 2 ), 'actions' => array( 'csv_export' => '1', 'bogus' => '1' ) ),
			'shop_manager' => array( 'menus' => array( 'categories' => 1 ) ),
			'intruder'     => array( 'menus' => array( 'products' => 2 ) ),
		),
		'operator_mode' => '1',
	) ) );
	$acc = ( new Catalog( $c->db(), $host, $c->prefix() ) )->access(); // fresh settings cache
	eq( 2, $acc->levelFor( array( 'contributor' ), false, 'products' ), 'saved: contributor edits products' );
	eq( 1, $acc->levelFor( array( 'contributor' ), false, 'dashboard' ), 'saved: dashboard capped at View' );
	eq( 0, $acc->levelFor( array( 'contributor' ), false, 'access' ), 'saved: Access stays administrator-only' );
	ok( $acc->allowedFor( array( 'contributor' ), false, 'csv_export' ), 'saved: action permission' );
	eq( 1, $acc->levelFor( array( 'shop_manager' ), false, 'categories' ), 'saved: custom role' );
	eq( 0, $acc->levelFor( array( 'editor' ), false, 'products' ), 'saved: a role left out of the form loses its menus' );
	ok( ! isset( $acc->matrix()['intruder'] ), 'saved: unknown role dropped' );
	ok( $acc->operatorMode(), 'saved: operator mode on' );
	$post( 'reset_access' );
	$acc = ( new Catalog( $c->db(), $host, $c->prefix() ) )->access();
	eq( 2, $acc->levelFor( array( 'editor' ), false, 'products' ), 'reset: defaults back' );
	ok( $acc->operatorMode(), 'reset keeps operator mode' );
	$acc->save( array(), array_keys( $host->roles() ) );
	ok( ! $acc->operatorMode(), 'operator mode off when saved off' );
	$c->settings()->delete( 'access' );
	$host->roles = array( 'administrator' );
}

/** M9 — admin / widget language, per-user switch, widget text overrides, Korean completeness. */
function run_i18n_suite( Catalog $c, TestHost $host, string $type, int $itemId, Context $ctx, Context $ctxDetail ) {
	echo "--- i18n ---\n";
	$s     = $c->settings();
	$admin = $c->admin();
	eq( 'ko_KR', Languages::adminLocale( $s, '', 'ko_KR' ), 'admin: follows the user language by default' );
	eq( 'fr_FR', Languages::adminLocale( $s, '', 'fr_FR' ), 'admin: other languages passed to the host' );
	eq( 'en_US', Languages::adminLocale( $s, 'en', 'ko_KR' ), 'admin: personal EN choice wins' );
	Languages::save( $s, array( 'admin' => 'ko', 'front' => 'en' ) );
	eq( 'ko_KR', Languages::adminLocale( $s, '', 'en_US' ), 'admin: site default when no personal choice' );
	eq( 'en_US', Languages::adminLocale( $s, 'en', 'en_US' ), 'admin: personal choice beats site default' );
	eq( 'en_US', Languages::frontLocale( $s, 'ko_KR' ), 'widgets: own setting, independent of admin' );
	Languages::save( $s, array( 'admin' => 'xx', 'front' => 'follow' ) );
	eq( 'ko', Languages::get( $s )['admin'], 'invalid language ignored' );
	eq( 'ko_KR', Languages::frontLocale( $s, 'ko_KR' ), 'widgets: follow the site language' );
	eq( 'ko', Languages::codeOf( 'ko_KR' ), 'codeOf(ko_KR)' );
	eq( '', Languages::codeOf( 'fr_FR' ), 'codeOf(fr_FR) = none' );
	$s->delete( Languages::KEY );

	// Personal switch (any staff user, own account only)
	$res = $admin->handle( array( 'do' => 'set_lang', 'lang' => 'en', 'back' => 'view=items&evil=1&id=5', Csrf::FIELD => $c->csrf()->token( 'set_lang' ) ) );
	eq( 'en', $host->userMeta( Languages::USER_PREF ), 'set_lang stores the personal choice' );
	ok( false !== strpos( $res['redirect'], 'view=items' ) && false !== strpos( $res['redirect'], 'id=5' ) && false === strpos( $res['redirect'], 'evil' ), 'set_lang returns to the same screen' );
	$host->user = 2;
	eq( '', $host->userMeta( Languages::USER_PREF ), 'personal choice does not affect other users' );
	$host->user = 1;
	$admin->handle( array( 'do' => 'set_lang', 'lang' => 'zz', Csrf::FIELD => $c->csrf()->token( 'set_lang' ) ) );
	eq( '', $host->userMeta( Languages::USER_PREF ), 'unknown language clears the choice' );
	ok( false !== strpos( $admin->render( array( 'view' => 'dashboard' ) ), 'name="lang" value="ko"' ), 'EN / 한국어 switch on every screen' );

	// Widget text overrides
	$key = Languages::overrideKey( 'Search' );
	$admin->handle( array( 'do' => 'save_settings', 'type' => $type, 'i18n' => array( 'admin' => 'follow', 'front' => 'follow', 'overrides' => array( $key => 'Find <b>it</b>', 'nope' => 'x' ) ), Csrf::FIELD => $c->csrf()->token( 'save_settings' ) ) );
	$c2   = new Catalog( $c->db(), $host, $c->prefix() );
	$html = $c2->front()->catalog( array(), $ctx );
	ok( false !== strpos( $html, 'Find it' ) && false === strpos( $html, '<b>it</b>' ), 'override shown on the site (tags stripped)' );
	eq( array( 'Search' => 'Find it' ), Languages::get( $c2->settings() )['overrides'], 'only known strings can be overridden' );
	$frontSrc = (string) file_get_contents( DWCATALOG_CORE_DIR . '/src/Front/FrontApp.php' );
	foreach ( \DWCatalog\I18n\FrontStrings::EDITABLE as $src ) {
		ok( false !== strpos( $frontSrc, "'" . $src . "'" ), "editable widget string still used: $src" );
	}
	$s->delete( Languages::KEY );

	// Korean: every string any screen asks for must be translated
	require_once dirname( __DIR__, 2 ) . '/build/i18n.php';
	$host->dict    = dwcat_i18n_dict( dirname( __DIR__, 2 ) . '/languages/dw-wholesale-ko_KR.po' );
	$host->missing = array();
	$c3            = new Catalog( $c->db(), $host, $c->prefix() );
	$admin3        = $c3->admin();
	foreach ( array( array( 'administrator' ), array( 'editor' ), array( 'contributor' ) ) as $roles ) {
		$host->roles = $roles;
		foreach ( array_keys( \DWCatalog\Admin\AdminApp::VIEWS ) as $view ) {
			$admin3->render( array( 'view' => $view, 'msg' => 'saved' ) );
		}
		$admin3->render( array( 'view' => 'edit', 'id' => $itemId ) );
	}
	$host->roles = array( 'administrator' );
	foreach ( array_keys( \DWCatalog\Admin\AdminApp::NOTICES ) as $msg ) {
		$admin3->render( array( 'view' => 'dashboard', 'msg' => $msg ) );
	}
	$c3->front()->catalog( array(), $ctx );
	$c3->front()->catalog( array( 'category' => 'refrigerated' ), $ctxDetail );
	$c3->front()->catalog( array(), new Context( 'https://site.test/c/', '', array( 'dwq' => 'zzzz-nothing' ) ) );
	$missing = array_keys( $host->missing );
	if ( $missing && getenv( 'DWC_DUMP_MISSING' ) ) {
		file_put_contents( getenv( 'DWC_DUMP_MISSING' ), implode( "\n", $missing ) . "\n" );
	}
	ok( ! $missing, 'Korean: no untranslated string on any admin screen or widget' . ( $missing ? ' — missing ' . count( $missing ) . ': ' . implode( ' | ', array_slice( $missing, 0, 30 ) ) : '' ) );
	ok( false !== strpos( $admin3->render( array( 'view' => 'dashboard' ) ), '대시보드' ), 'Korean dashboard renders' );
	$host->dict    = null;
	$host->missing = array();
}
