<?php
/**
 * DW Wholesale core — bootstrap.
 *
 * The core is a standalone PHP library (namespace DWCatalog\) with no WordPress dependency.
 * Hosts (the WordPress adapter, or any other PHP app) include this file, then build a
 * DWCatalog\Catalog with their own Host implementations.
 *
 * Requires PHP 7.4+, PDO (pdo_mysql or pdo_pgsql) unless the host supplies its own Connection.
 */

if ( defined( 'DWCATALOG_CORE_LOADED' ) ) {
	return;
}
define( 'DWCATALOG_CORE_LOADED', true );
define( 'DWCATALOG_CORE_DIR', __DIR__ );

spl_autoload_register( static function ( $class ) {
	$prefix = 'DWCatalog\\';
	if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
		return;
	}
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );
