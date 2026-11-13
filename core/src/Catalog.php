<?php
namespace DWCatalog;

use DWCatalog\Db\Connection;
use DWCatalog\Db\Schema;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Host\Host;
use DWCatalog\I18n\Languages;
use DWCatalog\Security\Access;
use DWCatalog\Query\CatalogQuery;
use DWCatalog\Repo\Images;
use DWCatalog\Repo\Items;
use DWCatalog\Repo\Settings;
use DWCatalog\Repo\Terms;
use DWCatalog\Security\Csrf;

/**
 * Entry point of the core. A host builds one per request:
 *
 *   $catalog = new DWCatalog\Catalog( $connection, $host, $prefix );
 *   $catalog->install();               // on activation / upgrade
 *   echo $catalog->front()->render( $atts, $request );
 *
 * Services are created lazily and shared.
 */
class Catalog {

	const VERSION = '3.0.0';

	/** @var Connection */
	private $db;
	/** @var Host */
	private $host;
	/** @var string raw table prefix, e.g. "wp_dwcat_" */
	private $prefix;

	private $services = array();

	public function __construct( Connection $db, Host $host, string $prefix ) {
		$this->db     = $db;
		$this->host   = $host;
		$this->prefix = $prefix;
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function db(): Connection {
		return $this->db;
	}

	public function host(): Host {
		return $this->host;
	}

	public function install(): void {
		Schema::install( $this->db, $this->prefix );
	}

	public function needsInstall(): bool {
		return Schema::installedVersion( $this->db ) < Schema::VERSION;
	}

	public function uninstall(): void {
		Schema::drop( $this->db );
	}

	public function settings(): Settings {
		return $this->service( 'settings', function () {
			return new Settings( $this->db );
		} );
	}

	public function fields(): FieldConfig {
		return $this->service( 'fields', function () {
			return new FieldConfig( $this->settings() );
		} );
	}

	public function items(): Items {
		return $this->service( 'items', function () {
			return new Items( $this->db, $this->settings(), $this->fields() );
		} );
	}

	public function terms(): Terms {
		return $this->service( 'terms', function () {
			return new Terms( $this->db, $this->settings() );
		} );
	}

	public function images(): Images {
		return $this->service( 'images', function () {
			return new Images( $this->db, $this->host, $this->settings() );
		} );
	}

	public function applications(): Repo\Applications {
		return $this->service( 'applications', function () {
			return new Repo\Applications( $this->db, $this->host );
		} );
	}

	/** Migration from the older catalog on this site; null when the host has no source. */
	public function migrator(): ?Migration\Migrator {
		return $this->service( 'migrator', function () {
			$source = $this->host->migrationSource();
			return $source ? new Migration\Migrator( $this, $source ) : false;
		} ) ?: null;
	}

	public function query(): CatalogQuery {
		return $this->service( 'query', function () {
			return new CatalogQuery( $this->db, $this->fields(), $this->items(), $this->terms(), $this->settings(), $this->host );
		} );
	}

	public function csrf(): Csrf {
		return $this->service( 'csrf', function () {
			return new Csrf( $this->host );
		} );
	}

	public function admin(): Admin\AdminApp {
		return $this->service( 'admin', function () {
			return new Admin\AdminApp( $this );
		} );
	}

	public function front(): Front\FrontApp {
		return $this->service( 'front', function () {
			return new Front\FrontApp( $this );
		} );
	}

	/** Delete an item with everything attached (values, variations, terms, index, image files). */
	public function deleteItem( int $id ): void {
		$this->images()->deleteForItem( $id );
		$this->items()->delete( $id );
	}

	/** Translate (host-provided). */
	public function t( string $text ): string {
		return $this->host->translate( $text );
	}

	/** Widget text: the site's own wording (Settings → Widget text) if set, else the translation. */
	public function frontText( string $text ): string {
		$own = Languages::override( $this->settings(), $text );
		return null !== $own ? $own : $this->host->translate( $text );
	}

	public function access(): Access {
		return $this->service( 'access', function () {
			return new Access( $this->host, $this->settings() );
		} );
	}

	private function service( string $name, callable $make ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			$this->services[ $name ] = $make();
		}
		return $this->services[ $name ];
	}
}
