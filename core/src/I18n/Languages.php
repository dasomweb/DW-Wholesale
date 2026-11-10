<?php
namespace DWCatalog\I18n;

use DWCatalog\Repo\Settings;
use DWCatalog\Support\Text;

/**
 * Language choice for the admin screens and for the site widgets — kept apart, so a Korean
 * office can run the admin in Korean while the public catalog stays in English (or the reverse).
 *
 *   admin: per-user choice (EN / 한국어 buttons)  →  site default  →  the user's host language
 *   front: site widget setting                    →  the site's host language
 *
 * Plus "text overrides": the site's own wording for any widget button or message.
 * The host turns the resolved locale into loaded translation files.
 */
class Languages {

	const KEY = 'i18n';

	/** User preference key (stored by the host per user). */
	const USER_PREF = 'dwcat_admin_lang';

	/** Languages the plugin ships translations for: code => [ label, locale ]. Labels are not translated. */
	const AVAILABLE = array(
		'en' => array( 'English', 'en_US' ),
		'ko' => array( '한국어', 'ko_KR' ),
	);

	/** Value meaning "follow the host language". */
	const FOLLOW = 'follow';

	const DEFAULTS = array(
		'admin'     => self::FOLLOW,
		'front'     => self::FOLLOW,
		'overrides' => array(),
	);

	public static function get( Settings $settings ): array {
		$saved = $settings->get( self::KEY );
		$out   = self::DEFAULTS;
		if ( is_array( $saved ) ) {
			foreach ( array( 'admin', 'front' ) as $k ) {
				if ( isset( $saved[ $k ] ) && self::valid( (string) $saved[ $k ] ) ) {
					$out[ $k ] = (string) $saved[ $k ];
				}
			}
			if ( isset( $saved['overrides'] ) && is_array( $saved['overrides'] ) ) {
				$out['overrides'] = $saved['overrides'];
			}
		}
		return $out;
	}

	/**
	 * @param array $in [ 'admin' => code|follow, 'front' => code|follow, 'overrides' => [ source => text ] ]
	 */
	public static function save( Settings $settings, array $in ): void {
		$cur = self::get( $settings );
		foreach ( array( 'admin', 'front' ) as $k ) {
			if ( isset( $in[ $k ] ) && self::valid( (string) $in[ $k ] ) ) {
				$cur[ $k ] = (string) $in[ $k ];
			}
		}
		if ( isset( $in['overrides'] ) && is_array( $in['overrides'] ) ) {
			$overrides = array();
			foreach ( FrontStrings::EDITABLE as $source ) {
				$key = self::overrideKey( $source );
				if ( isset( $in['overrides'][ $key ] ) && is_scalar( $in['overrides'][ $key ] ) ) {
					$text = Text::line( (string) $in['overrides'][ $key ], 200 );
					if ( '' !== $text ) {
						$overrides[ $source ] = $text;
					}
				}
			}
			$cur['overrides'] = $overrides;
		}
		$settings->set( self::KEY, $cur );
	}

	/** Form field key for an override (source strings contain spaces and punctuation). */
	public static function overrideKey( string $source ): string {
		return substr( md5( $source ), 0, 12 );
	}

	/**
	 * Locale for the admin screens of one user.
	 *
	 * @param string $userPref   the user's own choice ('' = none)
	 * @param string $hostLocale the user's language in the host, e.g. "ko_KR"
	 */
	public static function adminLocale( Settings $settings, string $userPref, string $hostLocale ): string {
		if ( isset( self::AVAILABLE[ $userPref ] ) ) {
			return self::AVAILABLE[ $userPref ][1];
		}
		$site = self::get( $settings )['admin'];
		return isset( self::AVAILABLE[ $site ] ) ? self::AVAILABLE[ $site ][1] : $hostLocale;
	}

	/** Locale for the site widgets. */
	public static function frontLocale( Settings $settings, string $hostLocale ): string {
		$site = self::get( $settings )['front'];
		return isset( self::AVAILABLE[ $site ] ) ? self::AVAILABLE[ $site ][1] : $hostLocale;
	}

	/** Short code ('en' / 'ko') of a locale, for the EN / 한국어 buttons; '' if not shipped. */
	public static function codeOf( string $locale ): string {
		foreach ( self::AVAILABLE as $code => $spec ) {
			if ( $locale === $spec[1] || 0 === strpos( $locale, $code . '_' ) || $locale === $code ) {
				return $code;
			}
		}
		return '';
	}

	/** The site's wording for a widget string, or null to use the translation. */
	public static function override( Settings $settings, string $source ): ?string {
		$overrides = self::get( $settings )['overrides'];
		return isset( $overrides[ $source ] ) && '' !== $overrides[ $source ] ? (string) $overrides[ $source ] : null;
	}

	private static function valid( string $v ): bool {
		return self::FOLLOW === $v || isset( self::AVAILABLE[ $v ] );
	}
}
