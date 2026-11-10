<?php
/**
 * M9 — translation files (no database needed).
 *
 *   php tests/test-i18n.php
 *
 * The runtime check (every admin screen and widget rendered in Korean, nothing untranslated)
 * lives in tests/core/run.php because it needs a database.
 */

require dirname( __DIR__ ) . '/core/bootstrap.php';
require dirname( __DIR__ ) . '/build/i18n.php';

$pass = 0;
$fail = 0;
function t( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $msg\n";
	} else {
		$fail++;
		echo "  ✗ $msg\n";
	}
}

$lang = dirname( __DIR__ ) . '/languages';
$po   = $lang . '/dw-wholesale-ko_KR.po';
$mo   = $lang . '/dw-wholesale-ko_KR.mo';
$pot  = $lang . '/dw-wholesale.pot';

echo "--- files ---\n";
t( is_file( $po ) && is_file( $mo ) && is_file( $pot ), 'Korean .po/.mo and .pot shipped' );
$dict = dwcat_i18n_dict( $po );
t( count( $dict ) > 300, 'Korean catalogue has ' . count( $dict ) . ' translations' );

echo "--- code ↔ catalogue ---\n";
$problems = dwcat_i18n_problems();
t( ! $problems, 'every string in the code is translated, placeholders match' . ( $problems ? "\n      " . implode( "\n      ", array_slice( $problems, 0, 30 ) ) : '' ) );

// Labels kept in constants (translated when shown) — the static scan cannot see them.
$labels = array();
foreach ( \DWCatalog\Security\Access::MENUS as $spec ) {
	$labels[] = $spec[0];
}
$labels = array_merge( $labels, array_values( \DWCatalog\Security\Access::ACTIONS ), array_values( \DWCatalog\Security\Access::LEVEL_NAMES ), \DWCatalog\I18n\FrontStrings::EDITABLE, array_values( \DWCatalog\Admin\AdminApp::NOTICES ) );
$untranslated = array_values( array_filter( array_unique( $labels ), function ( $s ) use ( $dict ) {
	return ! isset( $dict[ $s ] );
} ) );
t( ! $untranslated, 'menu / action / level / widget / notice labels translated' . ( $untranslated ? ': ' . implode( ' | ', $untranslated ) : '' ) );

echo "--- compiled files up to date ---\n";
$compiled = dwcat_i18n_read_mo( $mo );
unset( $compiled[''] );
ksort( $compiled );
ksort( $dict );
t( $compiled === $dict, '.mo matches .po (run `php build/i18n.php mo` after editing the .po)' );
$enPo = $lang . '/dw-wholesale-en_US.po';
$enMo = $lang . '/dw-wholesale-en_US.mo';
t( is_file( $enPo ) && is_file( $enMo ), 'English catalogue for the license SDK shipped' );
$enDict     = dwcat_i18n_dict( $enPo );
$enCompiled = dwcat_i18n_read_mo( $enMo );
unset( $enCompiled[''] );
ksort( $enDict );
ksort( $enCompiled );
t( $enCompiled === $enDict, 'en_US .mo matches .po' );
$sdk = dwcat_i18n_sdk_strings();
t( count( $sdk ) > 50 && ! array_diff_key( $sdk, $enDict ), 'every Korean string of the license screens has English (' . count( $sdk ) . ')' );
$tmp = tempnam( sys_get_temp_dir(), 'pot' );
dwcat_i18n_write_pot( $po, $tmp );
t( file_get_contents( $tmp ) === file_get_contents( $pot ), '.pot matches .po (run `php build/i18n.php pot`)' );
@unlink( $tmp );

echo "--- WordPress menu labels ---\n";
$wpAdmin = (string) file_get_contents( dirname( __DIR__ ) . '/includes/wp/class-dwcat-wp-admin.php' );
foreach ( \DWCatalog\Security\Access::MENUS as $key => $spec ) {
	t( 1 === preg_match( "/'" . preg_quote( $key, '/' ) . "'\s*=>\s*__\( '" . preg_quote( $spec[0], '/' ) . "', 'dw-wholesale' \)/", $wpAdmin ), "menu label for $key matches the core" );
}

echo "--- loader ---\n";
$loader = (string) file_get_contents( dirname( __DIR__ ) . '/includes/wp/class-dwcat-wp-i18n.php' );
t( false !== strpos( $loader, "add_action( 'init'" ), 'translations load on init (WordPress 6.7+ rule)' );
t( false !== strpos( $loader, 'NOOP_Translations' ), 'a language without a file blocks just-in-time loading of another language' );
t( false !== strpos( $loader, '$locale = \'en_US\';' ), 'English admin loads the en_US file (license screens)' );
$main = (string) file_get_contents( dirname( __DIR__ ) . '/dw-wholesale.php' );
t( false === strpos( $main, 'load_plugin_textdomain' ), 'no plugins_loaded textdomain load (language is chosen per request)' );

echo "\nPASS: $pass\nFAIL: $fail\n";
echo $fail ? "SOME TESTS FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );
