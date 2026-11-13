<?php
namespace DWCatalog\Host;

/**
 * What the core needs from wherever it is installed. One implementation per host
 * (WordPress adapter: includes/wp/class-dwcat-wp-host.php).
 */
interface Host {

	// ── Identity & permission ───────────────────────────────────

	/** Logged-in user id, 0 for visitors. */
	public function userId(): int;

	/**
	 * Role slugs of the current user (WordPress: "editor", "author", …). Empty for visitors.
	 * What each role may do in the catalog admin is decided by the core (Security\Access).
	 */
	public function userRoles(): array;

	/** True when the current user is the site's full administrator (always has every permission). */
	public function isAdministrator(): bool;

	/** Every role of the site: slug => display name (for the Access screen). */
	public function roles(): array;

	/** A per-user preference of the current user ('' if unset). */
	public function userMeta( string $key ): string;

	public function setUserMeta( string $key, string $value ): void;

	/** The current user's language in the host, e.g. "ko_KR" (site language for visitors). */
	public function userLocale(): string;

	/** The site's language in the host, e.g. "en_US". */
	public function siteLocale(): string;

	/**
	 * Host policy for a core feature (the WordPress adapter maps these to license gates).
	 * Features: render, assets, save, manage_fields, import, export, admin.
	 */
	public function allows( string $feature ): bool;

	/** Long random secret for CSRF tokens (stable per installation). */
	public function secret(): string;

	// ── Text & HTML ─────────────────────────────────────────────

	public function translate( string $text ): string;

	/** Clean rich text (item descriptions) to safe HTML. */
	public function sanitizeHtml( string $html ): string;

	/** Rich-text editor field (posts as $name). A plain host may return a <textarea>. */
	public function richText( string $name, string $html ): string;

	// ── Files ───────────────────────────────────────────────────

	/** Absolute directory for catalog files (images), no trailing slash. Must exist & be writable. */
	public function storageDir(): string;

	/** Public URL of storageDir(), no trailing slash. */
	public function storageUrl(): string;

	// ── URLs ────────────────────────────────────────────────────

	/** URL of the admin app with the given query params (GET pages). */
	public function adminUrl( array $params = array() ): string;

	/** URL admin forms POST to; the host routes it to AdminApp::handlePost(). */
	public function adminPostUrl(): string;

	/** Public URL of a core asset, e.g. assetUrl('front.css'). */
	public function assetUrl( string $file ): string;

	/** Core version (cache busting for assets). */
	public function version(): string;

	// ── Cache ───────────────────────────────────────────────────

	/**
	 * Return the cached value for $key, or compute it with $fn and keep it for $ttl seconds.
	 * Keys already include a data version, so stale entries are simply never read again.
	 */
	public function remember( string $key, int $ttl, callable $fn );
}
