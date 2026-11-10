<?php
/**
 * Loads the plugin's translations for the language chosen in DW Wholesale → Settings.
 *
 *   admin requests (wp-admin, admin-post, admin-ajax): the user's EN / 한국어 choice → the site's
 *                  admin language setting → the user's WordPress language
 *   site pages:    the widget language setting → the site language
 *
 * Only the dw-wholesale text domain is affected; WordPress itself keeps its own language.
 * Translation files: languages/dw-wholesale-{locale}.mo (English is the source language).
 *
 * @package DW_Wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_WP_I18n {

	const DOMAIN = 'dw-wholesale';

	/** @var string locale currently loaded into the domain ('' = not decided yet) */
	private static $loaded = '';

	public static function init() {
		// After the user is known, before menus and pages translate anything.
		add_action( 'init', array( __CLASS__, 'load_for_request' ), 1 );
	}

	/**
	 * Admin requests decide now. Site pages decide only when a widget renders (ensure_front), so
	 * pages without a catalog cost no extra database query.
	 */
	public static function load_for_request() {
		if ( is_admin() ) {
			self::load( self::admin_locale() );
		}
	}

	/** Called by every widget before it renders (cheap once loaded). */
	public static function ensure_front() {
		self::load( self::front_locale() );
	}

	public static function admin_locale(): string {
		$user_locale = (string) get_user_locale();
		try {
			$pref = get_current_user_id() ? (string) get_user_meta( get_current_user_id(), \DWCatalog\I18n\Languages::USER_PREF, true ) : '';
			return \DWCatalog\I18n\Languages::adminLocale( dwcat()->settings(), $pref, $user_locale );
		} catch ( \Throwable $e ) {
			return $user_locale; // database not reachable — fall back to WordPress
		}
	}

	public static function front_locale(): string {
		try {
			return \DWCatalog\I18n\Languages::frontLocale( dwcat()->settings(), (string) get_locale() );
		} catch ( \Throwable $e ) {
			return (string) get_locale();
		}
	}

	/** Swap the domain to $locale. English (or a language without a file) = source strings. */
	public static function load( string $locale ) {
		if ( $locale === self::$loaded ) {
			return;
		}
		self::$loaded = $locale;
		unload_textdomain( self::DOMAIN, true );
		$file = self::file( $locale );
		if ( $file ) {
			// No $locale argument on purpose: since WordPress 6.5 translations are stored per locale and
			// looked up under the request's locale (determine_locale()). Passing "ko_KR" while the user's
			// WordPress language is English would file the strings where this request never looks.
			load_textdomain( self::DOMAIN, $file );
			return;
		}
		// Keep WordPress from loading another language "just in time" behind our back.
		$GLOBALS['l10n'][ self::DOMAIN ] = new NOOP_Translations(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	/** Shipped translation file for a locale ("ko_KR", or "ko" matched to ko_KR), or ''. */
	public static function file( string $locale ): string {
		if ( '' === $locale || 0 === strpos( $locale, 'en_' ) || 'en' === $locale ) {
			return '';
		}
		$dir = dwcat_get_path() . 'languages/';
		foreach ( array( $locale, substr( $locale, 0, 2 ) . '_' . strtoupper( substr( $locale, 0, 2 ) ) ) as $try ) {
			if ( is_readable( $dir . self::DOMAIN . '-' . $try . '.mo' ) ) {
				return $dir . self::DOMAIN . '-' . $try . '.mo';
			}
		}
		return '';
	}
}
