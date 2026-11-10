<?php
/**
 * Domain Agnostic Verification Script
 * 
 * This script checks for hardcoded site URLs in the plugin code.
 * Run this script to verify the plugin is domain-change friendly.
 * 
 * Usage: php verify-domain-agnostic.php
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	// If not in WordPress, we can still check files
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

$plugin_dir = dirname( __FILE__ );
$errors = array();
$warnings = array();

// Files to check
$files_to_check = array(
	'dw-wholesale.php',
	'includes/class-pc-github-updater.php',
	'includes/class-pc-url-helper.php',
);

// Patterns that indicate hardcoded site URLs (BAD)
$bad_patterns = array(
	// Common site URL patterns
	'/(https?:\/\/[a-zA-Z0-9.-]+\.(com|net|org|sg-host|localhost|dev|test|local)[^"\s]*)/i',
	// WordPress-specific hardcoded URLs
	'/wp-content\/plugins\/[^"\']+\.(php|js|css)/i',
	'/wp-admin\/[^"\']+/i',
	// Direct domain references (excluding GitHub, external services)
	'/(?!https?:\/\/(api\.github|github\.com|www\.gnu\.org))https?:\/\/[a-zA-Z0-9.-]+/i',
);

// Acceptable external URLs (these are OK)
$acceptable_domains = array(
	'api.github.com',
	'github.com',
	'www.gnu.org',
	'github.io',
);

// Check each file
foreach ( $files_to_check as $file ) {
	$file_path = $plugin_dir . '/' . $file;
	
	if ( ! file_exists( $file_path ) ) {
		$warnings[] = "File not found: {$file}";
		continue;
	}
	
	$content = file_get_contents( $file_path );
	$lines = explode( "\n", $content );
	
	foreach ( $lines as $line_num => $line ) {
		// Skip comments in markdown/docs
		if ( strpos( $file, '.md' ) !== false ) {
			continue;
		}
		
		// Check for hardcoded URLs
		foreach ( $bad_patterns as $pattern ) {
			if ( preg_match( $pattern, $line, $matches ) ) {
				$url = $matches[0];
				$is_acceptable = false;
				
				// Check if it's an acceptable external URL
				foreach ( $acceptable_domains as $domain ) {
					if ( strpos( $url, $domain ) !== false ) {
						$is_acceptable = true;
						break;
					}
				}
				
				// Check if it's in a comment (plugin header)
				if ( preg_match( '/^\s*\*?\s*(Plugin URI|Author URI|License URI|@link|@see)/i', $line ) ) {
					$is_acceptable = true;
				}
				
				if ( ! $is_acceptable ) {
					$errors[] = sprintf(
						"File: %s, Line %d: Hardcoded URL found: %s",
						$file,
						$line_num + 1,
						$url
					);
				}
			}
		}
		
		// Check for WordPress URL functions (GOOD)
		$good_functions = array( 'site_url', 'home_url', 'admin_url', 'plugin_dir_url', 'plugin_dir_path', 'rest_url' );
		$has_good_function = false;
		foreach ( $good_functions as $func ) {
			if ( strpos( $line, $func . '(' ) !== false ) {
				$has_good_function = true;
				break;
			}
		}
	}
}

// Output results
echo "========================================\n";
echo "Domain Agnostic Verification Report\n";
echo "========================================\n\n";

if ( empty( $errors ) && empty( $warnings ) ) {
	echo "✅ SUCCESS: No hardcoded site URLs found!\n";
	echo "The plugin is domain-change friendly.\n\n";
} else {
	if ( ! empty( $errors ) ) {
		echo "❌ ERRORS FOUND:\n";
		foreach ( $errors as $error ) {
			echo "  - {$error}\n";
		}
		echo "\n";
	}
	
	if ( ! empty( $warnings ) ) {
		echo "⚠️  WARNINGS:\n";
		foreach ( $warnings as $warning ) {
			echo "  - {$warning}\n";
		}
		echo "\n";
	}
}

// Check for required WordPress functions
echo "Checking for WordPress URL functions...\n";
$required_functions = array( 'site_url', 'home_url', 'admin_url', 'plugin_dir_url' );
$found_functions = array();

foreach ( $files_to_check as $file ) {
	$file_path = $plugin_dir . '/' . $file;
	if ( ! file_exists( $file_path ) ) {
		continue;
	}
	
	$content = file_get_contents( $file_path );
	foreach ( $required_functions as $func ) {
		if ( strpos( $content, $func . '(' ) !== false ) {
			$found_functions[ $func ] = true;
		}
	}
}

echo "\nWordPress URL Functions Usage:\n";
foreach ( $required_functions as $func ) {
	if ( isset( $found_functions[ $func ] ) ) {
		echo "  ✅ {$func}() - Found\n";
	} else {
		echo "  ⚠️  {$func}() - Not found (may not be needed)\n";
	}
}

// Check for central config
echo "\nChecking for central configuration...\n";
$main_file = $plugin_dir . '/dw-wholesale.php';
if ( file_exists( $main_file ) ) {
	$content = file_get_contents( $main_file );
	if ( strpos( $content, 'dwcat_get_config' ) !== false ) {
		echo "  ✅ dwcat_get_config() - Found\n";
	} else {
		echo "  ❌ dwcat_get_config() - NOT FOUND\n";
	}
} else {
	echo "  ❌ Main plugin file not found\n";
}

echo "\n========================================\n";
echo "Verification Complete\n";
echo "========================================\n";

// Exit with error code if issues found
if ( ! empty( $errors ) ) {
	exit( 1 );
}

exit( 0 );


