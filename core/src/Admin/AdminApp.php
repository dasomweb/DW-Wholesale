<?php
namespace DWCatalog\Admin;

use DWCatalog\Catalog;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Fields\VariationColumns;
use DWCatalog\Front\Design;
use DWCatalog\Front\Widgets;
use DWCatalog\I18n\Languages;
use DWCatalog\Security\Access;
use DWCatalog\Security\Csrf;
use DWCatalog\Query\CatalogQuery;
use DWCatalog\Support\Html;
use DWCatalog\Support\Text;

/**
 * The one admin UI of the catalog — server-rendered pages, embedded by the host.
 *
 *   GET  render( $query )            view = see VIEWS (each belongs to one menu of Security\Access)
 *   POST handle( $post, $files )     do   = see ACTIONS
 *
 * Every GET: menu level >= view. Every POST: menu level (+ action permission) -> host policy (license)
 * -> CSRF token bound to user + action. Input arrives unslashed from the host (no magic quotes).
 */
class AdminApp {

	/** @var Catalog */
	private $c;

	/** view => menu (Security\Access::MENUS). */
	const VIEWS = array(
		'dashboard'  => 'dashboard',
		'items'      => 'products',
		'edit'       => 'products',
		'categories' => 'categories',
		'fields'     => 'fields',
		'customers'  => 'customers',
		'import'     => 'import_export',
		'export'     => 'import_export',
		'migration'  => 'migration',
		'shortcodes' => 'shortcodes',
		'design'     => 'design',
		'presets'    => 'presets',
		'settings'   => 'settings',
		'access'     => 'access',
	);

	/** Old view names still accepted in URLs. */
	const VIEW_ALIASES = array(
		'reference' => 'shortcodes',
	);

	/** menu => the view its menu entry opens. */
	const MENU_VIEW = array(
		'dashboard'     => 'dashboard',
		'products'      => 'items',
		'categories'    => 'categories',
		'fields'        => 'fields',
		'customers'     => 'customers',
		'import_export' => 'import',
		'migration'     => 'migration',
		'shortcodes'    => 'shortcodes',
		'design'        => 'design',
		'presets'       => 'presets',
		'settings'      => 'settings',
		'access'        => 'access',
	);

	/** Screens of later phases (shown as a short "coming" card until built). */
	const PLACEHOLDERS = array( 'customers', 'presets' );

	/**
	 * do => [ menu, minimum level, action permission or '', host feature ].
	 * set_lang has no menu: any user who can open some screen may pick their own language.
	 */
	const ACTIONS = array(
		'save_item'     => array( 'products', Access::EDIT, '', 'save' ),
		'delete_item'   => array( 'products', Access::EDIT, 'product_delete', 'save' ),
		'bulk_items'    => array( 'products', Access::VIEW, '', 'save' ), // per bulk action: see bulk()
		'save_term'     => array( 'categories', Access::EDIT, '', 'save' ),
		'delete_term'   => array( 'categories', Access::EDIT, '', 'save' ),
		'save_tree'     => array( 'categories', Access::EDIT, '', 'save' ),
		'save_fields'   => array( 'fields', Access::EDIT, '', 'manage_fields' ),
		'import'        => array( 'import_export', Access::EDIT, '', 'import' ),
		'export'        => array( 'import_export', Access::VIEW, '', 'export' ),
		'export_csv'    => array( 'import_export', Access::VIEW, 'csv_export', 'export' ),
		'save_design'   => array( 'design', Access::EDIT, '', 'save' ),
		'create_design' => array( 'design', Access::EDIT, '', 'save' ),
		'delete_design' => array( 'design', Access::EDIT, '', 'save' ),
		'save_widget'   => array( 'shortcodes', Access::EDIT, '', 'save' ),
		'delete_widget' => array( 'shortcodes', Access::EDIT, '', 'save' ),
		'save_settings' => array( 'settings', Access::EDIT, '', 'save' ),
		'save_access'   => array( 'access', Access::EDIT, '', 'admin' ),
		'reset_access'  => array( 'access', Access::EDIT, '', 'admin' ),
		'set_lang'      => array( '', Access::VIEW, '', 'admin' ),
		'migrate'       => array( 'migration', Access::EDIT, '', 'import' ), // op = see migrate()
		'check_code'    => array( 'products', Access::VIEW, '', 'admin' ),
	);

	/** Product list tabs (M2). */
	const TABS = array(
		'all'          => 'All',
		'active'       => 'Active',
		'no_status'    => 'No status',
		'out_of_stock' => 'Out of stock',
		'hidden'       => 'Hidden',
		'review'       => 'Needs review',
	);

	const NOTICES = array(
		'saved'     => 'Saved.',
		'deleted'   => 'Deleted.',
		'imported'  => 'Import finished.',
		'not_found' => 'Item not found.',
		'invalid'   => 'Please check the form — some values were not accepted.',
		'rolled'    => 'The migration was undone. The original data was not touched.',
	);

	public function __construct( Catalog $c ) {
		$this->c = $c;
	}

	// ─────────────────────────────────────────────────────────────
	// GET
	// ─────────────────────────────────────────────────────────────

	/** View to show for a request (aliases resolved; unknown or missing → the user's first screen). */
	public function resolveView( array $q ): string {
		$view = isset( $q['view'] ) && is_string( $q['view'] ) ? $q['view'] : '';
		if ( isset( self::VIEW_ALIASES[ $view ] ) ) {
			$view = self::VIEW_ALIASES[ $view ];
		}
		if ( ! isset( self::VIEWS[ $view ] ) ) {
			$first = $this->access()->firstMenu();
			$view  = '' !== $first ? self::MENU_VIEW[ $first ] : 'dashboard';
		}
		return $view;
	}

	/**
	 * May the current user open this request? Hosts call this before printing anything, so a hidden
	 * screen reached by URL gets a real 403 (render() refuses it again).
	 */
	public function canOpen( array $q ): bool {
		$view = $this->resolveView( $q );
		$menu = self::VIEWS[ $view ];
		if ( 'edit' === $view && empty( $q['id'] ) ) {
			return $this->access()->canEdit( $menu ); // "Add New" needs edit
		}
		return $this->access()->canView( $menu );
	}

	public function render( array $q ): string {
		$view = $this->resolveView( $q );
		if ( ! $this->c->host()->allows( 'admin' ) ) {
			return $this->wrap( '<div class="dwc-notice dwc-notice-error">' . Html::e( $this->t( 'The catalog is not licensed on this site. Activate the license to manage the catalog.' ) ) . '</div>' );
		}
		if ( ! $this->canOpen( $q ) ) {
			return $this->wrap( '<div class="dwc-notice dwc-notice-error">' . Html::e( $this->t( 'You do not have permission to view this page.' ) ) . '</div>' );
		}
		$menu              = self::VIEWS[ $view ];
		$this->view        = $view;
		$this->readonly    = Access::EDIT === Access::MENUS[ $menu ][1] && ! $this->access()->canEdit( $menu );
		$type              = $this->type( $q );
		$this->currentType = isset( $q['type'] ) ? $type : '';
		$this->currentId   = isset( $q['id'] ) ? (int) $q['id'] : 0;
		$this->currentQuery = $q;
		$vars              = array( 'type' => $type, 'q' => $q, 'readonly' => $this->readonly );
		$template          = in_array( $view, self::PLACEHOLDERS, true ) ? 'placeholder' : $view;

		switch ( $view ) {
			case 'dashboard':
				$vars += $this->dashboardData( $type );
				break;
			case 'items':
				$vars += $this->itemsData( $type, $q );
				break;
			case 'migration':
				$vars += $this->migrationData( $q );
				break;
			case 'shortcodes':
				$editing = isset( $q['widget'] ) && is_string( $q['widget'] ) ? Text::slug( $q['widget'], 40 ) : '';
				$vars   += array(
					'widgets' => Widgets::all( $this->c ),
					'editing' => '' !== $editing ? ( 'new' === $editing ? 'new' : ( Widgets::get( $this->c, $editing ) ? $editing : '' ) ) : '',
				);
				break;
			case 'design':
				$presets = Design::presets( $this->c->settings() );
				$preset  = isset( $q['preset'] ) && is_string( $q['preset'] ) && isset( $presets[ $q['preset'] ] ) ? $q['preset'] : 'default';
				$vars   += array( 'presets' => $presets, 'preset' => $preset );
				break;
			case 'edit':
				$id   = isset( $q['id'] ) ? (int) $q['id'] : 0;
				$item = $id ? $this->c->items()->get( $id ) : null;
				if ( $id && ( ! $item || $item['type'] !== $type ) ) {
					return $this->wrap( $this->notice( 'not_found', 'error' ) );
				}
				$vars['item'] = $item;
				$vars        += $this->editData( $type, $item );
				break;
		}
		$notice = isset( $q['msg'] ) && isset( self::NOTICES[ $q['msg'] ] ) ? $this->notice( $q['msg'], 'invalid' === $q['msg'] ? 'error' : 'success' ) : '';
		$flash  = $this->takeFlash();
		$ro     = $this->readonly ? '<div class="dwc-notice dwc-notice-info">' . Html::e( $this->t( 'View only — you can look at this screen but not change it.' ) ) . '</div>' : '';
		return $this->wrap( $notice . $flash . $ro . $this->template( $template, $vars ) );
	}

	/** @var array query of the screen being rendered (for the language switch's way back) */
	private $currentQuery = array();

	private function currentExtra( string $key ): string {
		return isset( $this->currentQuery[ $key ] ) && is_string( $this->currentQuery[ $key ] ) ? Text::key( $this->currentQuery[ $key ], 40 ) : '';
	}

		/** @var string view being rendered */
	private $view = '';
	/** @var bool the current screen is view-only for this user */
	private $readonly = false;
	/** @var string */
	private $currentType = '';
	/** @var int */
	private $currentId = 0;

	private function dashboardData( string $type ): array {
		$stats = new ItemStats( $this->c );
		$code  = $this->c->fields()->roleField( $type, 'code', false );
		$migrator = $this->access()->canView( 'migration' ) ? $this->c->migrator() : null;
		return array(
			'counts'       => $stats->counts( $type ),
			'catStat'      => $stats->categoryCounts( $type ),
			'shared'       => $code ? $stats->shared( $type, $code['meta_key'] ) : array(),
			'quality'      => $stats->quality( $type ),
			'applications' => $this->c->applications()->count(),
			'pages'        => $this->c->host()->catalogPages(),
			'migration'    => $migrator ? $migrator->source()->detect() : null,
			'migrated'     => $migrator ? $migrator->hasData() : false,
		);
	}

	/** Which step of the migration screen to show, and what it needs. */
	private function migrationData( array $q ): array {
		$m = $this->c->migrator();
		if ( ! $m ) {
			return array( 'm' => null, 'step' => 'detect' );
		}
		$steps = array( 'detect', 'mapping', 'groups', 'check', 'run', 'finish', 'rollback' );
		$step  = isset( $q['step'] ) && in_array( $q['step'], $steps, true ) ? $q['step'] : 'detect';
		$vars  = array( 'm' => $m, 'step' => $step, 'steps' => $steps, 'state' => $m->state(), 'detect' => $m->source()->detect() );
		if ( 'check' === $step ) {
			$vars['report'] = $m->dryRun();
		} elseif ( 'run' === $step && 'done' === $vars['state']['run']['status'] ) {
			$vars['compare'] = $m->compare();
		} elseif ( 'finish' === $step ) {
			$vars['pages'] = $m->pages();
		}
		return $vars;
	}

	const PER_PAGE = array( 10, 20, 50, 100 );

	private function itemsData( string $type, array $q ): array {
		$fields = $this->c->fields()->fieldsByKey( $type );
		$meta   = array();
		foreach ( isset( $q['f'] ) && is_array( $q['f'] ) ? $q['f'] : array() as $k => $v ) {
			if ( isset( $fields[ $k ] ) && '' !== (string) $v && is_scalar( $v ) ) {
				$meta[ $k ] = array( (string) $v );
			}
		}
		$orderby = isset( $q['orderby'] ) ? Text::key( $q['orderby'] ) : '';
		$order   = isset( $q['order'] ) && 'asc' === $q['order'] ? 'asc' : 'desc';
		if ( in_array( $orderby, array( 'title', 'date', 'modified' ), true ) ) {
			$sort = $orderby . '_' . $order;
		} elseif ( isset( $fields[ $orderby ] ) ) {
			$sort = 'field:' . $orderby . ':' . $order;
		} else {
			$sort = 'date_desc';
		}
		$status  = isset( $q['status'] ) && in_array( $q['status'], array( 'publish', 'draft' ), true ) ? $q['status'] : 'any';
		// Tabs. Older links (?dup=1, ?status=draft) open the matching tab.
		$tab = isset( $q['tab'] ) && isset( self::TABS[ $q['tab'] ] ) ? $q['tab'] : 'all';
		if ( 'all' === $tab && ! empty( $q['dup'] ) ) {
			$tab = 'review';
		} elseif ( 'all' === $tab && 'draft' === $status ) {
			$tab = 'hidden';
		}
		$pstatus = $this->c->fields()->roleField( $type, 'status', false );
		$empty   = array();
		if ( $pstatus && in_array( $tab, array( 'active', 'out_of_stock' ), true ) ) {
			$meta[ $pstatus['meta_key'] ] = array( $tab );
		} elseif ( $pstatus && 'no_status' === $tab ) {
			$empty[] = $pstatus['meta_key'];
		} elseif ( 'hidden' === $tab ) {
			$status = 'draft';
		}
		$image = isset( $q['img'] ) && in_array( $q['img'], array( 'yes', 'no' ), true ) ? $q['img'] : '';
		$perPage = isset( $q['n'] ) && in_array( (int) $q['n'], self::PER_PAGE, true ) ? (int) $q['n'] : 20;
		$code    = $this->c->fields()->roleField( $type, 'code', false );
		$brand   = $this->c->fields()->roleField( $type, 'brand', false );
		$stats   = new ItemStats( $this->c );
		$shared  = $code ? $stats->shared( $type, $code['meta_key'] ) : array();
		$dup     = 'review' === $tab;
		$result  = $this->c->query()->run( $type, array(
			'search'   => isset( $q['s'] ) ? (string) $q['s'] : '',
			'cats'     => ! empty( $q['cat'] ) ? array( (int) $q['cat'] ) : array(),
			'meta'     => $meta,
			'sort'     => $sort,
			'status'   => $status,
			'shared'   => $dup && $code ? $code['meta_key'] : '',
			'review'   => $dup,
			'empty'    => $empty,
			'image'    => $image,
			'page'     => isset( $q['paged'] ) ? (int) $q['paged'] : 1,
			'per_page' => $perPage,
		) );
		$ids = array_map( 'intval', array_column( $result['rows'], 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $type, $ids );
		$this->c->items()->primeValues( $ids );
		return array(
			'result'  => $result,
			'orderby' => $orderby,
			'order'   => $order,
			'status'  => $status,
			'meta'    => $meta,
			'perPage' => $perPage,
			'dup'     => $dup,
			'code'    => $code,
			'brand'   => $brand,
			'shared'  => $shared,
			'counts'  => $stats->counts( $type ),
			'catStat' => $stats->categoryCounts( $type ),
			'brands'  => $brand && 'select' !== $brand['type'] ? $stats->distinct( $type, $brand['meta_key'] ) : array(),
			'tab'     => $tab,
			'quality' => $stats->quality( $type ),
			'pstatus' => $pstatus,
			'image'   => $image,
		);
	}

	private function editData( string $type, ?array $item ): array {
		$code   = $this->c->fields()->roleField( $type, 'code', false );
		$brand  = $this->c->fields()->roleField( $type, 'brand', false );
		$stats  = new ItemStats( $this->c );
		$id     = $item ? (int) $item['id'] : 0;
		$value  = $code && $id ? $this->c->items()->value( $id, $code['meta_key'] ) : '';
		return array(
			'code'     => $code,
			'brand'    => $brand,
			'codeUsed' => $code ? $stats->usedBy( $type, $code['meta_key'], $value, $id ) : array(),
			'oldUrl'   => $this->oldUrl( $id ),
			'brands'   => $brand && 'select' !== $brand['type'] ? $stats->distinct( $type, $brand['meta_key'] ) : array(),
		);
	}

	// ─────────────────────────────────────────────────────────────
	// POST
	// ─────────────────────────────────────────────────────────────

	/**
	 * @return array [ 'redirect' => url ] or [ 'download' => [ filename, mime, body ] ]
	 */
	public function handle( array $post, array $files = array() ): array {
		$do = isset( $post['do'] ) && is_string( $post['do'] ) && isset( self::ACTIONS[ $post['do'] ] ) ? $post['do'] : '';
		if ( '' === $do ) {
			throw new Forbidden( 'Unknown action.' );
		}
		list( $menu, $level, $permission, $feature ) = self::ACTIONS[ $do ];
		$access = $this->access();
		$ok     = '' === $menu ? '' !== $access->firstMenu() : $access->level( $menu ) >= $level;
		if ( ! $ok || ( '' !== $permission && ! $access->allowed( $permission ) ) ) {
			throw new Forbidden( $this->t( 'You do not have permission to do that.' ) );
		}
		if ( ! $this->c->host()->allows( $feature ) ) {
			throw new Forbidden( $this->t( 'This feature requires an active license.' ) );
		}
		if ( ! $this->c->csrf()->verify( $do, isset( $post[ Csrf::FIELD ] ) ? $post[ Csrf::FIELD ] : '' ) ) {
			throw new Forbidden( $this->t( 'The form has expired. Please reload the page and try again.' ) );
		}
		$type = $this->type( $post );

		switch ( $do ) {
			case 'save_item':
				return ( new ItemEditor( $this->c, $this ) )->save( $type, $post, $files );
			case 'delete_item':
				$id   = isset( $post['id'] ) ? (int) $post['id'] : 0;
				$item = $this->c->items()->get( $id );
				if ( $item && $item['type'] === $type ) {
					$this->c->deleteItem( $id );
				}
				return $this->redirect( array( 'view' => 'items', 'type' => $type, 'msg' => 'deleted' ) );
			case 'bulk_items':
				return $this->bulk( $type, $post );
			case 'save_term':
				try {
					$this->c->terms()->save(
						$type,
						'category',
						isset( $post['name'] ) ? (string) $post['name'] : '',
						isset( $post['slug'] ) ? (string) $post['slug'] : '',
						isset( $post['parent'] ) ? (int) $post['parent'] : 0,
						isset( $post['id'] ) ? (int) $post['id'] : 0,
						isset( $post['position'] ) ? (int) $post['position'] : 0
					);
					$msg = 'saved';
				} catch ( \InvalidArgumentException $e ) {
					$msg = 'invalid';
				}
				return $this->redirect( array( 'view' => 'categories', 'type' => $type, 'msg' => $msg ) );
			case 'save_tree':
				$nodes = json_decode( isset( $post['tree'] ) ? (string) $post['tree'] : '', true );
				if ( ! is_array( $nodes ) ) {
					return $this->redirect( array( 'view' => 'categories', 'type' => $type, 'msg' => 'invalid' ) );
				}
				$this->c->terms()->arrange( $type, $nodes );
				return $this->redirect( array( 'view' => 'categories', 'type' => $type, 'msg' => 'saved' ) );
			case 'delete_term':
				$this->c->terms()->delete( $type, isset( $post['id'] ) ? (int) $post['id'] : 0 );
				return $this->redirect( array( 'view' => 'categories', 'type' => $type, 'msg' => 'deleted' ) );
			case 'save_fields':
				$raw = isset( $post['fields'] ) && is_array( $post['fields'] ) ? $post['fields'] : array();
				$this->c->fields()->saveFields( $type, FieldConfig::sanitizeFields( $raw, isset( $post['title_field'] ) ? $post['title_field'] : '' ) );
				$this->c->fields()->saveVariationColumns( $type, isset( $post['variation_columns'] ) && is_array( $post['variation_columns'] ) ? $post['variation_columns'] : array() );
				return $this->redirect( array( 'view' => 'fields', 'type' => $type, 'msg' => 'saved' ) );
			case 'import':
				$report = ( new Importer( $this->c ) )->run( $type, $post, isset( $files['csv'] ) ? $files['csv'] : array() );
				$this->flash( $report );
				return $this->redirect( array( 'view' => 'import', 'type' => $type, 'msg' => 'imported' ) );
			case 'export':
				return ( new PdfExporter( $this->c ) )->run( $type, $post );
			case 'export_csv':
				return ( new CsvExporter( $this->c ) )->run( $type, $post );
			case 'save_design':
				$preset = isset( $post['preset'] ) && is_string( $post['preset'] ) ? $post['preset'] : '';
				Design::save( $this->c->settings(), isset( $post['design'] ) && is_array( $post['design'] ) ? $post['design'] : array(), $preset );
				return $this->redirect( array( 'view' => 'design', 'preset' => 'default' !== $preset ? $preset : null, 'msg' => 'saved' ) );
			case 'create_design':
				$slug = Design::create( $this->c->settings(), isset( $post['name'] ) ? (string) $post['name'] : '', isset( $post['design'] ) && is_array( $post['design'] ) ? $post['design'] : Design::get( $this->c->settings(), isset( $post['from'] ) ? (string) $post['from'] : '' ) );
				return $this->redirect( array( 'view' => 'design', 'preset' => '' !== $slug ? $slug : null, 'msg' => '' !== $slug ? 'saved' : 'invalid' ) );
			case 'delete_design':
				Design::deletePreset( $this->c->settings(), isset( $post['preset'] ) ? (string) $post['preset'] : '' );
				return $this->redirect( array( 'view' => 'design', 'msg' => 'deleted' ) );
			case 'save_widget':
				$in = isset( $post['widget'] ) && is_array( $post['widget'] ) ? $post['widget'] : array();
				$id = Widgets::save( $this->c, isset( $post['id'] ) ? (string) $post['id'] : '', $in );
				return $this->redirect( array( 'view' => 'shortcodes', 'widget' => '' !== $id ? $id : 'new', 'msg' => '' !== $id ? 'saved' : 'invalid' ) );
			case 'delete_widget':
				Widgets::delete( $this->c, isset( $post['id'] ) ? (string) $post['id'] : '' );
				return $this->redirect( array( 'view' => 'shortcodes', 'msg' => 'deleted' ) );
			case 'save_settings':
				Languages::save( $this->c->settings(), isset( $post['i18n'] ) && is_array( $post['i18n'] ) ? $post['i18n'] : array() );
				return $this->redirect( array( 'view' => 'settings', 'msg' => 'saved' ) );
			case 'save_access':
				$access->save( isset( $post['access'] ) && is_array( $post['access'] ) ? $post['access'] : array(), array_keys( $this->c->host()->roles() ) );
				return $this->redirect( array( 'view' => 'access', 'msg' => 'saved' ) );
			case 'reset_access':
				$access->reset();
				return $this->redirect( array( 'view' => 'access', 'msg' => 'saved' ) );
			case 'migrate':
				return $this->migrate( $post );
			case 'check_code':
				$field = $this->c->fields()->roleField( $type, 'code', false );
				$value = Text::line( isset( $post['code'] ) ? $post['code'] : '', 191 );
				$used  = $field ? ( new ItemStats( $this->c ) )->usedBy( $type, $field['meta_key'], $value, isset( $post['id'] ) ? (int) $post['id'] : 0 ) : array();
				return array( 'json' => array( 'used' => array_map( function ( $r ) use ( $type ) {
					return array( 'title' => (string) $r['title'], 'url' => $this->url( array( 'view' => 'edit', 'type' => $type, 'id' => (int) $r['id'] ) ) );
				}, $used ) ) );
			case 'set_lang':
				$lang = isset( $post['lang'] ) && is_string( $post['lang'] ) && isset( Languages::AVAILABLE[ $post['lang'] ] ) ? $post['lang'] : '';
				$this->c->host()->setUserMeta( Languages::USER_PREF, $lang );
				return array( 'redirect' => $this->backUrl( isset( $post['back'] ) ? (string) $post['back'] : '' ) );
		}
		throw new Forbidden( 'Unknown action.' );
	}

	/** Address this product had before the migration ('' when it was not migrated). */
	private function oldUrl( int $id ): string {
		$m    = $id ? $this->c->migrator() : null;
		$slug = $m ? $m->oldSlugOf( $id ) : '';
		return '' !== $slug ? $m->source()->oldUrl( $slug ) : '';
	}

	/**
	 * Migration steps. op: mapping · groups · reset_groups · choices · csv · start · batch · pause ·
	 * resume · pages · flags · rollback. batch / rollback answer JSON (the screen calls them in a loop).
	 */
	private function migrate( array $post ): array {
		$m = $this->c->migrator();
		if ( ! $m ) {
			throw new Forbidden( $this->t( 'There is nothing to migrate on this site.' ) );
		}
		$op   = isset( $post['op'] ) && is_string( $post['op'] ) ? $post['op'] : '';
		$arr  = function ( string $key ) use ( $post ) {
			return isset( $post[ $key ] ) && is_array( $post[ $key ] ) ? $post[ $key ] : array();
		};
		$step = function ( string $step, string $msg = 'saved' ) {
			return $this->redirect( array( 'view' => 'migration', 'step' => $step, 'msg' => $msg ) );
		};
		switch ( $op ) {
			case 'mapping':
				$warnings = $m->saveMapping( $arr( 'map' ) );
				if ( $warnings ) {
					$this->flash( $warnings );
					return $step( 'mapping', 'invalid' );
				}
				return $step( 'groups' );
			case 'groups':
				$m->saveGroups( $arr( 'names' ), $arr( 'assign' ) );
				return $step( empty( $post['stay'] ) ? 'check' : 'groups' );
			case 'reset_groups':
				$m->resetGroups();
				return $step( 'groups' );
			case 'choices':
				$m->saveChoices( $arr( 'choices' ) );
				return $step( 'check' );
			case 'csv':
				return array( 'download' => array( 'migration-check.csv', 'text/csv; charset=utf-8', $m->dryRunCsv() ) );
			case 'start':
				$m->start();
				return $step( 'run', '' );
			case 'pause':
				$m->pause();
				return array( 'json' => $this->runJson( $m->state()['run'] ) );
			case 'resume':
				$m->resume();
				return array( 'json' => $this->runJson( $m->state()['run'] ) );
			case 'batch':
				return array( 'json' => $this->runJson( $m->batch() ) );
			case 'pages':
				$m->replacePages( $arr( 'pages' ) );
				return $step( 'finish' );
			case 'flags':
				$m->setFinish( 'redirects', ! empty( $post['redirects'] ) );
				$m->setFinish( 'rest_block', ! empty( $post['rest_block'] ) );
				return $step( 'finish' );
			case 'rollback':
				$left = $m->rollbackBatch();
				return array( 'json' => array( 'left' => $left, 'done' => 0 === $left ) );
		}
		throw new Forbidden( 'Unknown action.' );
	}

	/** Run state for the progress screen (no internal id lists). */
	private function runJson( array $run ): array {
		return array(
			'status' => $run['status'],
			'stage'  => $run['stage'],
			'done'   => (int) $run['done'],
			'total'  => (int) $run['total'],
			'counts' => $run['counts'],
			'errors' => array_slice( $run['errors'], -20 ),
			'busy'   => ! empty( $run['busy'] ),
		);
	}

	/** Bulk / row actions on the item list: status, visibility, category, brand, export, delete. */
	private function bulk( string $type, array $post ): array {
		$action = isset( $post['bulk'] ) ? (string) $post['bulk'] : '';
		$ids    = isset( $post['ids'] ) ? (array) $post['ids'] : array();
		if ( ! empty( $post['one'] ) && preg_match( '/^(\w+):(\d+)$/', (string) $post['one'], $m ) ) {
			$action = $m[1];
			$ids    = array( $m[2] );
		}
		$valid = array();
		foreach ( array_slice( $ids, 0, CatalogQuery::MAX_PER_PAGE ) as $id ) {
			$item = $this->c->items()->get( (int) $id );
			if ( $item && $item['type'] === $type ) {
				$valid[] = (int) $id;
			}
		}
		$back = $this->listState( isset( $post['back'] ) ? (string) $post['back'] : '', $type );
		$msg  = 'saved';
		// Exporting only reads; every other bulk action changes products.
		if ( 'export' !== $action && ! $this->access()->canEdit( 'products' ) ) {
			throw new Forbidden( $this->t( 'You do not have permission to do that.' ) );
		}
		switch ( $action ) {
			case 'publish':
			case 'draft':
				$this->c->items()->setStatus( $valid, $action );
				break;
			case 'pstatus':
			case 'brand':
				$field = $this->c->fields()->roleField( $type, 'pstatus' === $action ? 'status' : 'brand', false );
				$value = $field ? FieldConfig::sanitizeValue( $field, isset( $post[ $action . '_value' ] ) ? $post[ $action . '_value' ] : '' ) : '';
				if ( ! $field || '' === $value ) {
					$msg = 'invalid';
					break;
				}
				$this->c->items()->setValue( $valid, $field['meta_key'], $value );
				break;
			case 'reviewed':
				$this->c->items()->setReview( $valid, '' );
				break;
			case 'move':
				$term = $this->c->terms()->get( $type, isset( $post['move_cat'] ) ? (int) $post['move_cat'] : 0 );
				if ( ! $term ) {
					$msg = 'invalid';
					break;
				}
				foreach ( $valid as $id ) {
					$this->c->terms()->setItem( $type, $id, 'category', array( (int) $term['id'] ) );
				}
				break;
			case 'export':
				if ( ! $this->access()->allowed( 'csv_export' ) || ! $this->c->host()->allows( 'export' ) ) {
					throw new Forbidden( $this->t( 'You do not have permission to do that.' ) );
				}
				if ( $valid ) {
					return ( new CsvExporter( $this->c ) )->run( $type, array( 'ids' => $valid ) );
				}
				break;
			case 'delete':
				if ( ! $this->access()->allowed( 'product_delete' ) ) {
					throw new Forbidden( $this->t( 'You do not have permission to do that.' ) );
				}
				foreach ( $valid as $id ) {
					$this->c->deleteItem( $id );
				}
				$msg = 'deleted';
				break;
			default:
				$msg = 'invalid';
		}
		return $this->redirect( array_merge( $back, array( 'msg' => $msg ) ) );
	}

	/** The list URL the user came from (query string), reduced to known list parameters. */
	private function listState( string $qs, string $type ): array {
		parse_str( $qs, $in );
		$out = array( 'view' => 'items', 'type' => $type );
		foreach ( array( 's', 'cat', 'status', 'orderby', 'order', 'n', 'paged', 'dup', 'tab', 'img' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) && '' !== (string) $in[ $k ] ) {
				$out[ $k ] = Text::line( (string) $in[ $k ], 100 );
			}
		}
		if ( isset( $in['f'] ) && is_array( $in['f'] ) ) {
			foreach ( $in['f'] as $k => $v ) {
				if ( is_scalar( $v ) && '' !== (string) $v ) {
					$out['f'][ Text::key( $k ) ] = Text::line( (string) $v, 100 );
				}
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────
	// Helpers used by templates
	// ─────────────────────────────────────────────────────────────

	public function t( string $text ): string {
		return $this->c->t( $text );
	}

	public function access(): Access {
		return $this->c->access();
	}

	/** Shortcuts for templates. */
	public function canEdit( string $menu ): bool {
		return $this->access()->canEdit( $menu );
	}

	public function canView( string $menu ): bool {
		return $this->access()->canView( $menu );
	}

	public function allowed( string $action ): bool {
		return $this->access()->allowed( $action );
	}

	/** The current screen is view-only for this user. */
	public function readonly(): bool {
		return $this->readonly;
	}

	/** Language of the admin screens for the current user ('en' / 'ko', '' for another language). */
	public function language(): string {
		$host = $this->c->host();
		return Languages::codeOf( Languages::adminLocale( $this->c->settings(), $host->userMeta( Languages::USER_PREF ), $host->userLocale() ) );
	}

	/** Same screen after a language switch: only view / type / id survive. */
	private function backUrl( string $qs ): string {
		parse_str( $qs, $in );
		$out = array();
		foreach ( array( 'view', 'type', 'id', 'widget', 'preset', 'step' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) && '' !== (string) $in[ $k ] ) {
				$out[ $k ] = 'id' === $k ? (int) $in[ $k ] : Text::key( (string) $in[ $k ], 40 );
			}
		}
		return $this->url( $out );
	}

	public function url( array $params ): string {
		return $this->c->host()->adminUrl( $params );
	}

	public function redirect( array $params ): array {
		return array( 'redirect' => $this->url( $params ) );
	}

	/** Page header: optional line above the title (breadcrumb), title, action buttons (already-escaped HTML). */
	public function head( string $title, string $actions = '', string $before = '' ): string {
		return '<div class="dwc-head"><div class="dwc-head-title">' . $before
			. '<h1>' . Html::e( $title ) . '</h1></div>' . ( '' !== $actions ? '<div class="dwc-head-actions">' . $actions . '</div>' : '' ) . '</div>';
	}

	/** <form> opening + hidden action/type/CSRF fields. */
	public function form( string $do, string $type, bool $multipart = false, string $attrs = '' ): string {
		return '<form method="post" action="' . Html::url( $this->c->host()->adminPostUrl() ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ( $attrs ? ' ' . $attrs : '' ) . '>'
			. '<input type="hidden" name="do" value="' . Html::attr( $do ) . '">'
			. '<input type="hidden" name="type" value="' . Html::attr( $type ) . '">'
			. $this->c->csrf()->field( $do );
	}

	/**
	 * GET form opening. Browsers drop the query string of a GET form's action, so the host's own
	 * base params (e.g. WordPress "page=") are carried as hidden fields.
	 */
	public function getForm( array $hidden, string $attrs = '' ): string {
		$base = $this->url( array() );
		parse_str( (string) parse_url( $base, PHP_URL_QUERY ), $baseParams );
		$html = '<form method="get" action="' . Html::url( strtok( $base, '?' ) ) . '"' . ( $attrs ? ' ' . $attrs : '' ) . '>';
		foreach ( array_merge( $baseParams, $hidden ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$html .= '<input type="hidden" name="' . Html::attr( $k ) . '" value="' . Html::attr( $v ) . '">';
			}
		}
		return $html;
	}

	public function catalog(): Catalog {
		return $this->c;
	}

	/** Flash messages (per user, one-shot) for import reports / upload errors. */
	public function flash( array $lines ): void {
		$uid = $this->c->host()->userId();
		if ( $uid && $lines ) {
			$this->c->settings()->set( 'flash.' . $uid, array_slice( array_values( $lines ), 0, 50 ) );
		}
	}

	private function takeFlash(): string {
		$uid   = $this->c->host()->userId();
		$lines = $uid ? $this->c->settings()->get( 'flash.' . $uid ) : null;
		if ( ! is_array( $lines ) || ! $lines ) {
			return '';
		}
		$this->c->settings()->delete( 'flash.' . $uid );
		$html = '<div class="dwc-notice dwc-notice-info"><ul>';
		foreach ( $lines as $line ) {
			$html .= '<li>' . Html::e( $line ) . '</li>';
		}
		return $html . '</ul></div>';
	}

	private function notice( string $code, string $kind ): string {
		return '<div class="dwc-notice dwc-notice-' . Html::attr( $kind ) . '">' . Html::e( $this->t( self::NOTICES[ $code ] ) ) . '</div>';
	}

	private function type( array $in ): string {
		$type = isset( $in['type'] ) ? Text::key( $in['type'], 20 ) : '';
		return $this->c->fields()->type( $type ) ? $type : $this->c->fields()->primaryType();
	}

	private function wrap( string $body ): string {
		return '<div class="dwc-admin' . ( $this->readonly ? ' is-readonly' : '' ) . '" data-dwc-i18n="' . Html::attr( (string) json_encode( $this->jsStrings(), JSON_UNESCAPED_UNICODE ) ) . '">'
			. $this->topbar() . $body . '</div>';
	}

	/** Top strip: product name + the user's own EN / 한국어 switch (applies to this account only). */
	private function topbar(): string {
		$html = '<div class="dwc-topbar"><span class="dwc-kicker">DW WHOLESALE</span>';
		if ( $this->c->host()->userId() > 0 && '' !== $this->access()->firstMenu() ) {
			$cur   = $this->language();
			$back  = http_build_query( array_filter( array( 'view' => $this->view, 'type' => $this->currentType, 'id' => $this->currentId, 'widget' => $this->currentExtra( 'widget' ), 'preset' => $this->currentExtra( 'preset' ), 'step' => $this->currentExtra( 'step' ) ) ) );
			$html .= '<form class="dwc-lang" method="post" action="' . Html::url( $this->c->host()->adminPostUrl() ) . '">'
				. '<input type="hidden" name="do" value="set_lang">'
				. '<input type="hidden" name="back" value="' . Html::attr( $back ) . '">'
				. $this->c->csrf()->field( 'set_lang' )
				. '<span class="dwc-lang-label">' . Html::e( $this->t( 'Admin language (your account only)' ) ) . '</span>';
			foreach ( Languages::AVAILABLE as $code => $spec ) {
				$on    = $cur === $code;
				$html .= '<button type="submit" name="lang" value="' . Html::attr( $code ) . '" class="' . ( $on ? 'is-on' : '' ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '" lang="' . Html::attr( $code ) . '">'
					. Html::e( 'en' === $code ? 'EN' : $spec[0] ) . '</button>';
			}
			$html .= '</form>';
		}
		return $html . '</div>';
	}

	/** Strings admin.js shows (translated here, read from data-dwc-i18n). */
	private function jsStrings(): array {
		$out = array();
		foreach ( array(
			'Optimizing photos…',
			'%1$d photo(s): %2$s → %3$s',
			'Preset removed. Saved fields are unchanged.',
			'Also used by:',
			'Unique — no other item uses this code.',
			'Paused.',
			'Working…',
			'Finished.',
			'Something went wrong. Reload the page and continue — nothing is lost.',
			'Undoing… %d left',
			'%1$s: %2$d fields added, %3$d already existed, %4$d variation columns added. Pick another type to switch, or click it again to undo. Click "Save fields" to keep.',
		) as $s ) {
			$out[ $s ] = $this->t( $s );
		}
		return $out;
	}

	private function template( string $name, array $vars ): string {
		$app = $this;
		$c   = $this->c;
		extract( $vars, EXTR_SKIP );
		ob_start();
		include DWCATALOG_CORE_DIR . '/templates/admin/' . $name . '.php';
		return (string) ob_get_clean();
	}

	/** Shared bits for templates. */
	public function variationColumnsFor( string $type ): array {
		return $this->c->fields()->variationColumns( $type );
	}

	public function stockOptions(): array {
		return Text::parseOptions( VariationColumns::STOCK_OPTIONS );
	}
}
