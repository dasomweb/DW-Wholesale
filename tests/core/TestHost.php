<?php
/**
 * In-memory Host for core integration tests.
 */

use DWCatalog\Host\Host;

class TestHost implements Host {

	public $user     = 1;
	public $roles    = array( 'administrator' );
	public $denied   = array();
	public $meta     = array();      // user id => [ key => value ]
	public $locale   = 'en_US';
	/** @var array|null msgid => msgstr; when set, translate() uses it and records misses */
	public $dict     = null;
	public $missing  = array();
	public $cache    = array();
	public $dir;

	public function __construct( string $dir ) {
		$this->dir = $dir;
	}

	public function userId(): int {
		return $this->user;
	}

	public function userRoles(): array {
		return $this->user > 0 ? $this->roles : array();
	}

	public function isAdministrator(): bool {
		return $this->user > 0 && in_array( 'administrator', $this->roles, true );
	}

	public function roles(): array {
		return array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'author' => 'Author', 'contributor' => 'Contributor', 'shop_manager' => 'Shop manager' );
	}

	public function userMeta( string $key ): string {
		return isset( $this->meta[ $this->user ][ $key ] ) ? (string) $this->meta[ $this->user ][ $key ] : '';
	}

	public function setUserMeta( string $key, string $value ): void {
		$this->meta[ $this->user ][ $key ] = $value;
	}

	public function userLocale(): string {
		return $this->locale;
	}

	public function siteLocale(): string {
		return $this->locale;
	}

	public function allows( string $feature ): bool {
		return ! in_array( $feature, $this->denied, true );
	}

	public function secret(): string {
		return 'test-secret-0123456789';
	}

	public function translate( string $text ): string {
		if ( null === $this->dict ) {
			return $text;
		}
		if ( isset( $this->dict[ $text ] ) && '' !== $this->dict[ $text ] ) {
			return $this->dict[ $text ];
		}
		$this->missing[ $text ] = true;
		return $text;
	}

	public function sanitizeHtml( string $html ): string {
		return strip_tags( $html, '<p><br><strong><em><ul><ol><li><a><h2><h3>' );
	}

	public function richText( string $name, string $html ): string {
		return '<textarea name="' . htmlspecialchars( $name ) . '">' . htmlspecialchars( $html ) . '</textarea>';
	}

	public function storageDir(): string {
		return $this->dir;
	}

	public function storageUrl(): string {
		return 'https://test.example/files';
	}

	/** @var \DWCatalog\Migration\Source|null */
	public $source = null;
	public $pages  = array();

	public function privateDir(): string {
		return $this->dir . '/private';
	}

	public function migrationSource(): ?\DWCatalog\Migration\Source {
		return $this->source;
	}

	public function catalogPages(): array {
		return $this->pages;
	}

	public function adminUrl( array $params = array() ): string {
		return 'https://test.example/admin?' . http_build_query( array_merge( array( 'page' => 'catalog' ), $params ) );
	}

	public function adminPostUrl(): string {
		return 'https://test.example/admin-post';
	}

	public function assetUrl( string $file ): string {
		return 'https://test.example/assets/' . $file;
	}

	public function version(): string {
		return 'test';
	}

	public function remember( string $key, int $ttl, callable $fn ) {
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $fn();
		}
		return $this->cache[ $key ];
	}
}
