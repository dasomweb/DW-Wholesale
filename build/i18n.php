<?php
/**
 * Translation tooling for the dw-wholesale text domain (no gettext tools needed).
 *
 *   php build/i18n.php check   strings used in the code that the Korean .po lacks (exit 1 if any)
 *   php build/i18n.php pot     write languages/dw-wholesale.pot from the Korean .po's entries
 *   php build/i18n.php mo      compile every languages/*.po to .mo
 *   php build/i18n.php all     check + pot + mo
 *
 * English is the source language. languages/dw-wholesale-ko_KR.po is the maintained catalogue:
 * add new strings there (tests/test-i18n.php and tests/core/run.php fail when one is missing),
 * then run `php build/i18n.php all`.
 *
 * Static extraction finds literal strings passed to: __() _e() esc_html__() esc_attr__()
 * esc_html_e() esc_attr_e() _x() _n() (WordPress files, domain dw-wholesale) and ->t( '…' ),
 * ->translate( '…' ), T( '…' ) in the core. Labels kept in arrays and translated later are caught by
 * the runtime check in tests/core/run.php (every admin screen rendered in Korean).
 */

const DWCAT_I18N_DOMAIN = 'dw-wholesale';

function dwcat_i18n_root(): string {
	return dirname( __DIR__ );
}

/** Files scanned for translatable strings. */
function dwcat_i18n_files(): array {
	$root  = dwcat_i18n_root();
	$files = array( $root . '/dw-wholesale.php', $root . '/includes/license/gates.php' );
	foreach ( array( '/core', '/includes/wp' ) as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( preg_match( '/\.php$/', $f->getFilename() ) ) {
				$files[] = $f->getPathname();
			}
		}
	}
	sort( $files );
	return $files;
}

/**
 * The DASOM-Forge license SDK. It is distributed as-is to several plugins (prefix strategy), so its
 * Korean source strings are not edited here: languages/dw-wholesale-en_US.po translates them to
 * English, and the Korean admin simply shows the source.
 */
function dwcat_i18n_sdk_files(): array {
	$root = dwcat_i18n_root();
	return array( $root . '/includes/class-dw-license-manager.php', $root . '/includes/class-dw-forge-client.php', $root . '/includes/class-dwcat-license-rest.php' );
}

/** Decode a PHP single- or double-quoted literal body. */
function dwcat_i18n_unquote( string $quote, string $body ): string {
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/**
 * Literal strings in translation calls: msgid => [ "file:line", … ].
 */
function dwcat_i18n_extract( ?array $files = null ): array {
	$root = dwcat_i18n_root();
	$out  = array();
	$lit  = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")';
	$wp   = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*' . $lit . '\s*,\s*[\'"]' . DWCAT_I18N_DOMAIN . '[\'"]/s';
	$wpx  = '/\b_x\(\s*' . $lit . '\s*,\s*' . $lit . '\s*,\s*[\'"]' . DWCAT_I18N_DOMAIN . '[\'"]/s';
	$wpn  = '/\b_n\(\s*' . $lit . '\s*,\s*' . $lit . '\s*,/s';
	$core = '/(?:->t|->translate|->frontText|\bT)\(\s*' . $lit . '\s*[,)]/s';
	// Shorthand  $t( '…' )  (templates: Html::e( $app->t( … ) ); Importer: [ $catalog, 't' ]).
	$tpl  = '/\$t\(\s*' . $lit . '\s*[,)]/s';
	foreach ( null === $files ? dwcat_i18n_files() : $files as $file ) {
		$src = (string) file_get_contents( $file );
		$rel = ltrim( str_replace( '\\', '/', substr( $file, strlen( $root ) ) ), '/' );
		// CatalogQuery's $t is table(), not a translator.
		foreach ( array_merge( array( $wp, $wpx, $wpn, $core ), 'CatalogQuery.php' === basename( $file ) ? array() : array( $tpl ) ) as $re ) {
			if ( ! preg_match_all( $re, $src, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$groups = $re === $wpn ? array( 1, 2 ) : array( 1 );
			foreach ( $groups as $g ) {
				foreach ( $m[ $g ] as $hit ) {
					$raw  = $hit[0];
					$text = dwcat_i18n_unquote( $raw[0], substr( $raw, 1, -1 ) );
					if ( '' === $text ) {
						continue;
					}
					$line           = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
					$out[ $text ][] = $rel . ':' . $line;
				}
			}
		}
	}
	ksort( $out );
	return $out;
}

// ── .po read / write ─────────────────────────────────────────────

function dwcat_i18n_po_unescape( string $s ): string {
	return stripcslashes( $s );
}

function dwcat_i18n_po_escape( string $s ): string {
	return addcslashes( $s, "\0..\37\\\"" );
}

/**
 * @return array [ 'header' => string, 'entries' => [ msgid => [ 'msgstr' => string, 'comments' => string[] ] ] ]
 */
function dwcat_i18n_parse_po( string $file ): array {
	$entries  = array();
	$header   = '';
	$comments = array();
	$cur      = null;
	$field    = '';
	$flush    = function () use ( &$cur, &$entries, &$header, &$comments ) {
		if ( null === $cur ) {
			return;
		}
		if ( '' === $cur['msgid'] ) {
			$header = $cur['msgstr'];
		} else {
			$entries[ $cur['msgid'] ] = array( 'msgstr' => $cur['msgstr'], 'comments' => $comments );
		}
		$cur      = null;
		$comments = array();
	};
	// Not \R: without /u it also matches byte 0x85, which occurs inside UTF-8 Korean ("테" = ED 85 8C).
	foreach ( preg_split( '/\r\n|\n|\r/', (string) file_get_contents( $file ) ) as $line ) {
		if ( '' === trim( $line ) ) {
			$flush();
			continue;
		}
		if ( '#' === $line[0] ) {
			if ( null !== $cur ) {
				$flush();
			}
			if ( 0 !== strpos( $line, '#~' ) ) {
				$comments[] = $line;
			}
			continue;
		}
		if ( preg_match( '/^(msgid|msgstr)\s+"(.*)"$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] ) {
				$flush();
				$cur = array( 'msgid' => '', 'msgstr' => '' );
			}
			$field         = $m[1];
			$cur[ $field ] = dwcat_i18n_po_unescape( $m[2] );
			continue;
		}
		if ( null !== $cur && preg_match( '/^"(.*)"$/', $line, $m ) ) {
			$cur[ $field ] .= dwcat_i18n_po_unescape( $m[1] );
		}
	}
	$flush();
	return array( 'header' => $header, 'entries' => $entries );
}

/** msgid => msgstr (translated entries only). */
function dwcat_i18n_dict( string $file ): array {
	$out = array();
	foreach ( dwcat_i18n_parse_po( $file )['entries'] as $id => $e ) {
		if ( '' !== $e['msgstr'] ) {
			$out[ $id ] = $e['msgstr'];
		}
	}
	return $out;
}

/** Placeholders (%s, %d, %1$s …) of a format string, sorted. */
function dwcat_i18n_placeholders( string $s ): array {
	preg_match_all( '/%(?:\d+\$)?[sd]/', str_replace( '%%', '', $s ), $m );
	$p = $m[0];
	sort( $p );
	return $p;
}

function dwcat_i18n_write_pot( string $po, string $pot ): void {
	$data = dwcat_i18n_parse_po( $po );
	$out  = "# DW Wholesale — translation template.\n# Generated by build/i18n.php from languages/dw-wholesale-ko_KR.po; do not edit by hand.\n"
		. "msgid \"\"\nmsgstr \"\"\n"
		. "\"Project-Id-Version: DW Wholesale\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: " . DWCAT_I18N_DOMAIN . "\\n\"\n\n";
	foreach ( $data['entries'] as $id => $e ) {
		foreach ( $e['comments'] as $c ) {
			if ( 0 === strpos( $c, '#.' ) || 0 === strpos( $c, '#:' ) ) {
				$out .= $c . "\n";
			}
		}
		$out .= 'msgid "' . dwcat_i18n_po_escape( $id ) . "\"\nmsgstr \"\"\n\n";
	}
	// License SDK strings (Korean source), kept in the English catalogue
	$en = dirname( $po ) . '/dw-wholesale-en_US.po';
	if ( is_file( $en ) ) {
		foreach ( dwcat_i18n_parse_po( $en )['entries'] as $id => $e ) {
			if ( ! isset( $data['entries'][ $id ] ) ) {
				$out .= "#. License SDK (Korean source)\n" . 'msgid "' . dwcat_i18n_po_escape( $id ) . "\"\nmsgstr \"\"\n\n";
			}
		}
	}
	file_put_contents( $pot, $out );
}

/** Compile a .po to a GNU .mo (translated entries only, sorted, no hash table). */
function dwcat_i18n_compile_mo( string $po, string $mo ): int {
	$data    = dwcat_i18n_parse_po( $po );
	$strings = array( '' => $data['header'] );
	foreach ( $data['entries'] as $id => $e ) {
		if ( '' !== $e['msgstr'] ) {
			$strings[ $id ] = $e['msgstr'];
		}
	}
	ksort( $strings, SORT_STRING );
	$n      = count( $strings );
	$ids    = '';
	$strs   = '';
	$idTab  = array();
	$strTab = array();
	foreach ( $strings as $id => $str ) {
		$idTab[]  = array( strlen( (string) $id ), strlen( $ids ) );
		$ids     .= $id . "\0";
		$strTab[] = array( strlen( $str ), strlen( $strs ) );
		$strs    .= $str . "\0";
	}
	$idsStart  = 28 + 16 * $n;
	$strsStart = $idsStart + strlen( $ids );
	$bin       = pack( 'V7', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, 0 );
	foreach ( $idTab as $t ) {
		$bin .= pack( 'V2', $t[0], $idsStart + $t[1] );
	}
	foreach ( $strTab as $t ) {
		$bin .= pack( 'V2', $t[0], $strsStart + $t[1] );
	}
	file_put_contents( $mo, $bin . $ids . $strs );
	return $n - 1;
}

/** Read back a .mo (for tests): msgid => msgstr. */
function dwcat_i18n_read_mo( string $mo ): array {
	$bin = (string) file_get_contents( $mo );
	$h   = unpack( 'Vmagic/Vrev/Vn/Vo/Vt', substr( $bin, 0, 20 ) );
	$out = array();
	for ( $i = 0; $i < $h['n']; $i++ ) {
		$o            = unpack( 'Vlen/Voff', substr( $bin, $h['o'] + 8 * $i, 8 ) );
		$t            = unpack( 'Vlen/Voff', substr( $bin, $h['t'] + 8 * $i, 8 ) );
		$out[ substr( $bin, $o['off'], $o['len'] ) ] = substr( $bin, $t['off'], $t['len'] );
	}
	return $out;
}

/** Problems with the Korean catalogue: missing strings, placeholder mismatches. */
function dwcat_i18n_problems(): array {
	$po       = dwcat_i18n_root() . '/languages/dw-wholesale-ko_KR.po';
	$dict     = is_file( $po ) ? dwcat_i18n_dict( $po ) : array();
	$problems = array();
	foreach ( dwcat_i18n_extract() as $id => $where ) {
		if ( ! isset( $dict[ $id ] ) ) {
			$problems[] = 'missing: "' . $id . '" (' . $where[0] . ')';
		}
	}
	foreach ( $dict as $id => $str ) {
		if ( dwcat_i18n_placeholders( $id ) !== dwcat_i18n_placeholders( $str ) ) {
			$problems[] = 'placeholders differ: "' . $id . '" → "' . $str . '"';
		}
	}
	// License SDK (Korean source) → English catalogue
	$en     = dwcat_i18n_root() . '/languages/dw-wholesale-en_US.po';
	$enDict = is_file( $en ) ? dwcat_i18n_dict( $en ) : array();
	foreach ( dwcat_i18n_sdk_strings() as $id => $where ) {
		if ( ! isset( $enDict[ $id ] ) ) {
			$problems[] = 'missing in en_US: "' . $id . '" (' . $where[0] . ')';
		}
	}
	foreach ( $enDict as $id => $str ) {
		if ( dwcat_i18n_placeholders( $id ) !== dwcat_i18n_placeholders( $str ) ) {
			$problems[] = 'placeholders differ (en_US): "' . $id . '" → "' . $str . '"';
		}
	}
	return $problems;
}

/** Korean-source strings of the license SDK (English-source ones need no English translation). */
function dwcat_i18n_sdk_strings(): array {
	return array_filter( dwcat_i18n_extract( dwcat_i18n_sdk_files() ), function ( $id ) {
		return 1 === preg_match( '/[\x{AC00}-\x{D7A3}]/u', (string) $id );
	}, ARRAY_FILTER_USE_KEY );
}

// ── CLI ──────────────────────────────────────────────────────────

if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$cmd  = isset( $argv[1] ) ? $argv[1] : 'all';
	$lang = dwcat_i18n_root() . '/languages';
	$code = 0;
	if ( 'list-sdk' === $cmd ) { // Korean-source strings of the license SDK
		foreach ( dwcat_i18n_sdk_strings() as $id => $where ) {
			echo $id, "
";
		}
		exit( 0 );
	}
	if ( 'list' === $cmd ) { // every extracted string (for translators)
		foreach ( dwcat_i18n_extract() as $id => $where ) {
			echo $id, "\n";
		}
		exit( 0 );
	}
	if ( in_array( $cmd, array( 'check', 'all' ), true ) ) {
		$problems = dwcat_i18n_problems();
		foreach ( $problems as $p ) {
			echo $p, "\n";
		}
		echo count( $problems ) ? count( $problems ) . " problem(s)\n" : "Korean catalogue complete.\n";
		$code = count( $problems ) ? 1 : 0;
	}
	if ( in_array( $cmd, array( 'pot', 'all' ), true ) ) {
		dwcat_i18n_write_pot( $lang . '/dw-wholesale-ko_KR.po', $lang . '/dw-wholesale.pot' );
		echo "Wrote languages/dw-wholesale.pot\n";
	}
	if ( in_array( $cmd, array( 'mo', 'all' ), true ) ) {
		foreach ( glob( $lang . '/*.po' ) as $po ) {
			$n = dwcat_i18n_compile_mo( $po, substr( $po, 0, -3 ) . '.mo' );
			echo 'Compiled ', basename( $po ), " ($n strings)\n";
		}
	}
	exit( $code );
}
