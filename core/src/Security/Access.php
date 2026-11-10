<?php
namespace DWCatalog\Security;

use DWCatalog\Host\Host;
use DWCatalog\Repo\Settings;

/**
 * Who may open which admin screen, and who may perform the sensitive actions.
 *
 *   role × menu   → hidden | view | edit
 *   role × action → allowed or not
 *
 * The host only reports the current user's roles (and whether the user is the site's full
 * administrator); every decision is made here, from the matrix saved on the Access screen.
 * Administrators always have every permission, and only they can open the Access screen.
 */
class Access {

	const HIDDEN = 0;
	const VIEW   = 1;
	const EDIT   = 2;

	/** Settings key. */
	const KEY = 'access';

	/** Role that never reaches the admin (wholesale customers; phase 5). */
	const CUSTOMER_ROLE = 'dw_wholesale_customer';

	/** Admin menus in display order: key => [ label, highest level that means something ]. */
	const MENUS = array(
		'dashboard'     => array( 'Dashboard', self::VIEW ),
		'products'      => array( 'Products', self::EDIT ),
		'categories'    => array( 'Categories', self::EDIT ),
		'fields'        => array( 'Fields', self::EDIT ),
		'customers'     => array( 'Customers', self::EDIT ),
		'import_export' => array( 'Import / Export', self::EDIT ),
		'migration'     => array( 'Migration', self::EDIT ),
		'shortcodes'    => array( 'Shortcodes', self::EDIT ),
		'design'        => array( 'Design', self::EDIT ),
		'presets'       => array( 'Presets', self::EDIT ),
		'settings'      => array( 'Settings', self::EDIT ),
		'access'        => array( 'Access', self::EDIT ),
	);

	/** Menus that only administrators can ever open (not part of the matrix). */
	const ADMIN_ONLY = array( 'access' );

	/** Sensitive actions that need their own permission on top of the menu level. */
	const ACTIONS = array(
		'product_delete'     => 'Delete products',
		'application_decide' => 'Approve or reject applications',
		'price_group_change' => 'Change price groups',
		'staff_fields'       => 'See staff-only fields',
		'csv_export'         => 'Export CSV',
	);

	/** Defaults for the standard WordPress roles. Any other role starts with nothing. */
	const DEFAULTS = array(
		'editor'      => array(
			'menus'   => array( 'dashboard' => self::VIEW, 'products' => self::EDIT, 'categories' => self::EDIT, 'customers' => self::EDIT ),
			'actions' => array( 'product_delete', 'application_decide', 'staff_fields' ),
		),
		'author'      => array(
			'menus'   => array( 'dashboard' => self::VIEW, 'categories' => self::VIEW, 'customers' => self::VIEW, 'products' => self::EDIT ),
			'actions' => array( 'staff_fields' ),
		),
		'contributor' => array(
			'menus'   => array( 'dashboard' => self::VIEW, 'products' => self::VIEW ),
			'actions' => array(),
		),
	);

	const LEVEL_NAMES = array(
		self::HIDDEN => 'Hidden',
		self::VIEW   => 'View',
		self::EDIT   => 'Edit',
	);

	/** @var Host */
	private $host;
	/** @var Settings */
	private $settings;

	public function __construct( Host $host, Settings $settings ) {
		$this->host     = $host;
		$this->settings = $settings;
	}

	// ── Current user ─────────────────────────────────────────────

	public function level( string $menu ): int {
		return $this->levelFor( $this->host->userRoles(), $this->host->isAdministrator(), $menu );
	}

	public function canView( string $menu ): bool {
		return $this->level( $menu ) >= self::VIEW;
	}

	public function canEdit( string $menu ): bool {
		return $this->level( $menu ) >= self::EDIT;
	}

	public function allowed( string $action ): bool {
		return $this->allowedFor( $this->host->userRoles(), $this->host->isAdministrator(), $action );
	}

	public function isAdministrator(): bool {
		return $this->host->userId() > 0 && $this->host->isAdministrator();
	}

	/** First menu the current user may open (where the plugin's top menu should land), or ''. */
	public function firstMenu(): string {
		return $this->firstMenuFor( $this->host->userRoles(), $this->host->isAdministrator() );
	}

	// ── Any user (e.g. on login, before they are "current") ─────

	public function levelFor( array $roles, bool $isAdmin, string $menu ): int {
		if ( ! isset( self::MENUS[ $menu ] ) ) {
			return self::HIDDEN;
		}
		$max = self::MENUS[ $menu ][1];
		if ( $isAdmin ) {
			return $max;
		}
		if ( in_array( $menu, self::ADMIN_ONLY, true ) ) {
			return self::HIDDEN;
		}
		$matrix = $this->matrix();
		$level  = self::HIDDEN;
		foreach ( $roles as $role ) {
			if ( self::CUSTOMER_ROLE === $role ) {
				return self::HIDDEN; // a customer account never reaches the admin, whatever else it has
			}
			if ( isset( $matrix[ $role ]['menus'][ $menu ] ) ) {
				$level = max( $level, (int) $matrix[ $role ]['menus'][ $menu ] );
			}
		}
		return min( $level, $max );
	}

	public function allowedFor( array $roles, bool $isAdmin, string $action ): bool {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return false;
		}
		if ( $isAdmin ) {
			return true;
		}
		if ( in_array( self::CUSTOMER_ROLE, $roles, true ) ) {
			return false;
		}
		$matrix = $this->matrix();
		foreach ( $roles as $role ) {
			if ( isset( $matrix[ $role ]['actions'] ) && in_array( $action, $matrix[ $role ]['actions'], true ) ) {
				return true;
			}
		}
		return false;
	}

	public function firstMenuFor( array $roles, bool $isAdmin ): string {
		foreach ( array_keys( self::MENUS ) as $menu ) {
			if ( $this->levelFor( $roles, $isAdmin, $menu ) >= self::VIEW ) {
				return $menu;
			}
		}
		return '';
	}

	// ── Stored matrix ────────────────────────────────────────────

	/**
	 * role => [ 'menus' => [ menu => level ], 'actions' => [ action, … ] ].
	 * Roles never saved fall back to DEFAULTS (standard roles) or nothing.
	 */
	public function matrix(): array {
		$saved = $this->settings->get( self::KEY );
		$roles = is_array( $saved ) && isset( $saved['roles'] ) && is_array( $saved['roles'] ) ? $saved['roles'] : array();
		return $roles + self::DEFAULTS;
	}

	/** Operator mode: hide WordPress appearance / plugins / tools from non-administrators. */
	public function operatorMode(): bool {
		$saved = $this->settings->get( self::KEY );
		return is_array( $saved ) && ! empty( $saved['operator_mode'] );
	}

	/**
	 * Save the Access screen.
	 *
	 * @param array $input  [ 'roles' => [ role => [ 'menus' => [ menu => level ], 'actions' => [ action => '1' ] ] ], 'operator_mode' => '1' ]
	 * @param array $known  role slugs the host has (anything else is dropped)
	 */
	public function save( array $input, array $known ): void {
		$roles = array();
		$in    = isset( $input['roles'] ) && is_array( $input['roles'] ) ? $input['roles'] : array();
		foreach ( $known as $role ) {
			$role = (string) $role;
			if ( 'administrator' === $role || self::CUSTOMER_ROLE === $role ) {
				continue;
			}
			$row   = isset( $in[ $role ] ) && is_array( $in[ $role ] ) ? $in[ $role ] : array();
			$menus = array();
			foreach ( self::MENUS as $menu => $spec ) {
				if ( in_array( $menu, self::ADMIN_ONLY, true ) ) {
					continue;
				}
				$level = isset( $row['menus'][ $menu ] ) ? (int) $row['menus'][ $menu ] : self::HIDDEN;
				$level = max( self::HIDDEN, min( $spec[1], $level ) );
				if ( $level > self::HIDDEN ) {
					$menus[ $menu ] = $level;
				}
			}
			$actions = array();
			foreach ( array_keys( self::ACTIONS ) as $action ) {
				if ( ! empty( $row['actions'][ $action ] ) ) {
					$actions[] = $action;
				}
			}
			$roles[ $role ] = array( 'menus' => $menus, 'actions' => $actions );
		}
		$this->settings->set( self::KEY, array(
			'roles'         => $roles,
			'operator_mode' => ! empty( $input['operator_mode'] ),
		) );
	}

	/** Forget the saved matrix (back to DEFAULTS); operator mode is kept. */
	public function reset(): void {
		$this->settings->set( self::KEY, array( 'roles' => array(), 'operator_mode' => $this->operatorMode() ) );
	}
}
