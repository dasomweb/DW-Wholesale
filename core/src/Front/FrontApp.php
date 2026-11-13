<?php
namespace DWCatalog\Front;

use DWCatalog\Catalog;
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Fields\VariationColumns;
use DWCatalog\Support\Html;
use DWCatalog\Support\Text;

/**
 * Visitor-facing HTML. Only public fields are ever printed; nothing is exposed as JSON.
 *
 *   catalog()   head (title · count · search · suggestions · category tabs), filters (sidebar or
 *               top bar: categories · brand · origin · in stock · new · photo · fields, each with
 *               counts), selected-filter chips, toolbar (count · sort · grid/list), grid or list,
 *               pagination / "load more" — or an item's detail when one is requested.
 *               catalog.js opens items in a side drawer; a direct item URL renders the full page.
 *   search()    a search box for a header, sending visitors to a catalog page.
 *   grid() / carousel()   compact blocks linking to the catalog page.
 *
 * List state lives in the URL, so every state is a shareable link and works without JS:
 *   dwq search · dwt tab · dwc[] categories · dwf[key][] field filters · dwo[] in_stock|new|photo ·
 *   dws sort · dwv view (grid|list) · dwn per page · dwp page
 *
 * Shortcodes: [dw_catalog] (2.x attributes) and [dw_wholesale_catalog] (3.0: wholesaleDefaults(),
 * optionally a saved widget with id="…"). Both end up as one attribute set — see resolve().
 */
class FrontApp {

	const P_SEARCH = 'dwq';
	const P_TAB    = 'dwt';
	const P_CATS   = 'dwc';
	const P_FIELDS = 'dwf';
	const P_FLAGS  = 'dwo';
	const P_SORT   = 'dws';
	const P_VIEW   = 'dwv';
	const P_NUM    = 'dwn';
	const P_PAGE   = 'dwp';

	/** Flag filters: filter name → URL value. */
	const FLAGS = array( 'stock' => 'in_stock', 'new' => 'new', 'photo' => 'photo' );

	/** Dot colours for top-level categories (tabs, badges, groups), by position. */
	const DOTS = array( '#1d4ed8', '#0f766e', '#7c3aed', '#b45309', '#64748b', '#be185d', '#0369a1', '#4d7c0f' );

	/** @var Catalog */
	private $c;

	public function __construct( Catalog $c ) {
		$this->c = $c;
	}

	public static function defaults(): array {
		return array(
			'id'              => '',
			'type'            => '',
			'category'        => '',
			'group'           => '',
			'categories'      => '',
			'exclude'         => '',
			'title'           => '',
			'columns'         => 0,
			'cols_tablet'     => 0,
			'cols_mobile'     => 0,
			'per_page'        => 20,
			'per_page_tablet' => 0,
			'per_page_mobile' => 0,
			'mobile_view'     => 'list',
			'show_fields'     => '',
			'card_fields'     => '',
			'detail_fields'   => '',
			'filter_fields'   => '',
			'filters'         => '',
			'filter_position' => 'sidebar',
			'search'          => 'yes',
			'search_size'     => '',
			'suggest'         => '',
			'sort'            => 'yes',
			'sorts'           => '',
			'tabs'            => 'yes',
			'default_sort'    => 'date_desc',
			'default_view'    => 'grid',
			'show_category'   => 'yes',
			'click'           => 'drawer',
			'design'          => '',
			'related'         => 4,
			'inquiry_url'     => '',
			'inquiry_label'   => '',
		);
	}

	/** Defaults of [dw_wholesale_catalog] (3.0) where they differ from [dw_catalog]. */
	public static function wholesaleDefaults(): array {
		return array(
			'per_page'        => 24,
			'per_page_mobile' => 12,
			'columns'         => 4,
			'cols_tablet'     => 3,
			'cols_mobile'     => 2,
			'mobile_view'     => 'grid',
			'filters'         => 'category,brand,origin,stock,new,photo',
			'card_fields'     => 'code,pack,brand,origin,category',
			'default_sort'    => 'title_asc',
		);
	}

	/**
	 * Attributes of a shortcode as written on a page → the full set.
	 * Order: defaults < 3.0 defaults (wholesale) < saved widget (id) < attributes on the page.
	 */
	public function resolve( array $atts, bool $wholesale ): array {
		$atts = array_intersect_key( $atts, self::defaults() );
		$base = $wholesale ? array_merge( self::defaults(), self::wholesaleDefaults() ) : self::defaults();
		$id   = isset( $atts['id'] ) ? Text::slug( (string) $atts['id'], 40 ) : '';
		if ( $wholesale && '' !== $id ) {
			$w = Widgets::get( $this->c, $id );
			if ( $w ) {
				$base = array_merge( $base, Widgets::atts( $w ) );
			}
		}
		return array_merge( $base, $atts );
	}

	private function atts( array $atts ): array {
		$a         = array_merge( self::defaults(), array_intersect_key( $atts, self::defaults() ) );
		$a['type'] = $this->c->fields()->type( (string) $a['type'] ) ? (string) $a['type'] : $this->c->fields()->primaryType();
		if ( ! (int) $a['columns'] ) {
			$a['columns'] = Design::get( $this->c->settings(), (string) $a['design'] )['columns'];
		}
		$a['columns']         = max( 1, min( 6, (int) $a['columns'] ) );
		$a['cols_tablet']     = (int) $a['cols_tablet'] ? max( 1, min( 4, (int) $a['cols_tablet'] ) ) : min( 3, $a['columns'] );
		$a['cols_mobile']     = (int) $a['cols_mobile'] ? max( 1, min( 2, (int) $a['cols_mobile'] ) ) : 1;
		$a['per_page']        = max( 1, min( 100, (int) $a['per_page'] ) );
		$a['per_page_tablet'] = (int) $a['per_page_tablet'] ? max( 1, min( 100, (int) $a['per_page_tablet'] ) ) : $a['per_page'];
		$a['per_page_mobile'] = (int) $a['per_page_mobile'] ? max( 1, min( 100, (int) $a['per_page_mobile'] ) ) : $a['per_page'];
		$a['default_view']    = 'list' === $a['default_view'] ? 'list' : 'grid';
		$a['mobile_view']     = 'grid' === $a['mobile_view'] ? 'grid' : 'list';
		$a['filter_position'] = 'top' === $a['filter_position'] ? 'top' : 'sidebar';
		$a['click']           = 'page' === $a['click'] ? 'page' : 'drawer';
		if ( ! in_array( $a['search_size'], array( 'large', 'small', 'hidden' ), true ) ) {
			$a['search_size'] = 'yes' === $a['search'] ? 'large' : 'hidden';
		}
		// Scope: the page's categories / groups, whichever attribute named them
		$a['scope'] = implode( ',', array_filter( array_map( 'trim', explode( ',', $a['category'] . ',' . $a['group'] . ',' . $a['categories'] ) ), 'strlen' ) );
		// Filters, in order. 2.x: categories + filter_fields.
		$list = '' !== trim( (string) $a['filters'] ) ? $a['filters'] : 'category,' . $a['filter_fields'];
		$a['filter_list'] = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', (string) $list ) ), 'strlen' ) ) );
		// Allowed sorts (the default is always allowed)
		$options = $this->c->query()->sortOptions( $a['type'], true );
		if ( ! isset( $options[ $a['default_sort'] ] ) && 0 !== strpos( (string) $a['default_sort'], 'field:' ) ) {
			$a['default_sort'] = 'date_desc';
		}
		$want            = array_filter( array_map( 'trim', explode( ',', (string) $a['sorts'] ) ), 'strlen' );
		$a['sort_list']  = array_intersect_key( $options, $want ? array_flip( array_merge( $want, array( $a['default_sort'] ) ) ) : $options );
		$a['card_list']  = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( '' !== trim( (string) $a['card_fields'] ) ? $a['card_fields'] : '' ) ) ), 'strlen' ) );
		return $a;
	}

	private function t( string $s ): string {
		return $this->c->frontText( $s );
	}

	// ─────────────────────────────────────────────────────────────
	// [dw_catalog] / [dw_wholesale_catalog]
	// ─────────────────────────────────────────────────────────────

	public function catalog( array $atts, Context $ctx ): string {
		$a     = $this->atts( $atts );
		$state = $this->state( $a, $ctx->query );
		$item  = $this->requestedItem( $atts, $ctx );
		if ( '' !== $ctx->requestedItem() && ! $item ) {
			$html = '<div class="dwcat-empty"><p>' . Html::e( $this->t( 'This item is not available.' ) ) . '</p>'
				. '<p><a class="dwcat-back" href="' . Html::url( $ctx->listUrl() ) . '" data-dwcat-link>&larr; ' . Html::e( $this->t( 'Back to list' ) ) . '</a></p></div>';
		} else {
			$html = $item ? $this->detail( $item, $a, $ctx, $state, true ) : $this->listView( $a, $ctx, $state );
		}
		$class = 'dwcat-catalog alignwide dwcat-mview-' . $a['mobile_view'] . ' dwcat-filters-' . $a['filter_position'];
		return '<div class="' . $class . '" data-dwcat-catalog data-dwcat-click="' . Html::attr( $a['click'] ) . '" data-dwcat-done="' . Html::attr( $this->t( 'Show results' ) ) . '" style="' . Html::attr( Design::style( $this->c->settings(), (string) $a['design'] ) ) . '">' . $html . '</div>';
	}

	/**
	 * The published item this request asks for, within the page's catalog type and scope.
	 * Hosts call this before output to set the page title / SEO tags.
	 */
	public function requestedItem( array $atts, Context $ctx ): ?array {
		$slug = Text::cut( (string) $ctx->requestedItem(), 200 );
		if ( '' === $slug ) {
			return null;
		}
		$a    = $this->atts( $atts );
		$item = $this->c->items()->getBySlug( $a['type'], $slug );
		if ( ! $item ) {
			return null;
		}
		$terms = $this->c->terms();
		$mine  = array_map( 'intval', array_column( $terms->ofItem( $a['type'], (int) $item['id'] ), 'id' ) );
		if ( '' !== $a['scope'] && ! array_intersect( $terms->withDescendants( $a['type'], $terms->resolve( $a['type'], $a['scope'] ) ), $mine ) ) {
			return null;
		}
		if ( '' !== trim( (string) $a['exclude'] ) && array_intersect( $terms->withDescendants( $a['type'], $terms->resolve( $a['type'], (string) $a['exclude'] ) ), $mine ) ) {
			return null;
		}
		return $item;
	}

	/**
	 * The categories shown as tabs (and as sidebar groups): the top level for the whole catalog,
	 * the children of a single scope category, or the scope categories themselves.
	 * null = the scope names no existing category (nothing to show).
	 */
	private function tabTerms( array $a ): ?array {
		$tree = $this->c->terms()->tree( $a['type'] );
		if ( '' === $a['scope'] ) {
			$rows = isset( $tree[0] ) ? $tree[0] : array();
		} else {
			$ids = $this->c->terms()->resolve( $a['type'], $a['scope'] );
			if ( ! $ids ) {
				return null;
			}
			if ( 1 === count( $ids ) ) {
				$rows = isset( $tree[ $ids[0] ] ) ? $tree[ $ids[0] ] : array();
			} else {
				$rows = array();
				foreach ( $ids as $id ) {
					$t = $this->c->terms()->get( $a['type'], $id );
					if ( $t ) {
						$rows[] = $t;
					}
				}
			}
		}
		$out = $this->excludedIds( $a );
		return array_values( array_filter( $rows, function ( $t ) use ( $out ) {
			return ! in_array( (int) $t['id'], $out, true );
		} ) );
	}

	private function excludedIds( array $a ): array {
		static $cache = array();
		$key = $a['type'] . '|' . $a['exclude'];
		if ( ! isset( $cache[ $key ] ) ) {
			$ids           = '' !== trim( (string) $a['exclude'] ) ? $this->c->terms()->resolve( $a['type'], (string) $a['exclude'] ) : array();
			$cache[ $key ] = $ids ? $this->c->terms()->withDescendants( $a['type'], $ids ) : array();
		}
		return $cache[ $key ];
	}

	private function state( array $a, array $q ): array {
		$state = array( 'search' => '', 'tab' => 0, 'cats' => array(), 'meta' => array(), 'flags' => array(), 'sort' => $a['default_sort'], 'view' => $a['default_view'], 'num' => $a['per_page'], 'page' => 1 );
		if ( isset( $q[ self::P_SEARCH ] ) && is_string( $q[ self::P_SEARCH ] ) ) {
			$state['search'] = Text::line( $q[ self::P_SEARCH ], 200 );
		}
		if ( isset( $q[ self::P_TAB ] ) && 'yes' === $a['tabs'] ) {
			$tab = (int) $q[ self::P_TAB ];
			foreach ( (array) $this->tabTerms( $a ) as $t ) {
				if ( (int) $t['id'] === $tab ) {
					$state['tab'] = $tab; // only a real tab of this page
				}
			}
		}
		if ( isset( $q[ self::P_CATS ] ) && in_array( 'category', $a['filter_list'], true ) ) {
			$state['cats'] = array_slice( array_values( array_filter( array_map( 'intval', (array) $q[ self::P_CATS ] ) ) ), 0, 50 );
		}
		if ( isset( $q[ self::P_FIELDS ] ) && is_array( $q[ self::P_FIELDS ] ) ) {
			$allowed = $this->fieldFilterKeys( $a );
			foreach ( $q[ self::P_FIELDS ] as $key => $values ) {
				if ( in_array( $key, $allowed, true ) ) {
					$state['meta'][ $key ] = array_values( array_slice( array_map( function ( $v ) {
						return Text::line( $v, 191 );
					}, array_filter( (array) $values, 'is_scalar' ) ), 0, 50 ) );
				}
			}
		}
		if ( isset( $q[ self::P_FLAGS ] ) ) {
			foreach ( self::FLAGS as $name => $value ) {
				if ( in_array( $name, $a['filter_list'], true ) && in_array( $value, (array) $q[ self::P_FLAGS ], true ) ) {
					$state['flags'][] = $value;
				}
			}
		}
		if ( isset( $q[ self::P_SORT ] ) && is_string( $q[ self::P_SORT ] ) && isset( $a['sort_list'][ $q[ self::P_SORT ] ] ) ) {
			$state['sort'] = $q[ self::P_SORT ];
		}
		if ( isset( $q[ self::P_VIEW ] ) && in_array( $q[ self::P_VIEW ], array( 'grid', 'list' ), true ) ) {
			$state['view'] = $q[ self::P_VIEW ];
		}
		if ( isset( $q[ self::P_NUM ] ) && in_array( (int) $q[ self::P_NUM ], $this->perPageOptions( $a ), true ) ) {
			$state['num'] = (int) $q[ self::P_NUM ];
		}
		if ( isset( $q[ self::P_PAGE ] ) ) {
			$state['page'] = max( 1, min( 10000, (int) $q[ self::P_PAGE ] ) );
		}
		return $state;
	}

	private function perPageOptions( array $a ): array {
		$base = (int) $a['per_page'];
		return array_values( array_unique( array( $base, min( 100, $base * 2 ), min( 100, $base * 4 ) ) ) );
	}

	/** URL params of a state (defaults omitted). $drop removes keys (e.g. page when filters change). */
	private function params( array $state, array $a, array $drop = array() ): array {
		$p = array();
		if ( '' !== $state['search'] ) {
			$p[ self::P_SEARCH ] = $state['search'];
		}
		if ( $state['tab'] ) {
			$p[ self::P_TAB ] = $state['tab'];
		}
		if ( $state['cats'] ) {
			$p[ self::P_CATS ] = $state['cats'];
		}
		if ( $state['meta'] ) {
			$p[ self::P_FIELDS ] = $state['meta'];
		}
		if ( $state['flags'] ) {
			$p[ self::P_FLAGS ] = $state['flags'];
		}
		if ( $state['sort'] !== $a['default_sort'] ) {
			$p[ self::P_SORT ] = $state['sort'];
		}
		if ( $state['view'] !== $a['default_view'] ) {
			$p[ self::P_VIEW ] = $state['view'];
		}
		if ( $state['num'] !== (int) $a['per_page'] ) {
			$p[ self::P_NUM ] = $state['num'];
		}
		if ( $state['page'] > 1 ) {
			$p[ self::P_PAGE ] = $state['page'];
		}
		return array_diff_key( $p, array_flip( $drop ) );
	}

	/** Field keys a visitor may filter by: brand / origin roles and named public fields. */
	private function fieldFilterKeys( array $a ): array {
		$public = $this->c->fields()->fieldsByKey( $a['type'], true );
		$out    = array();
		foreach ( $a['filter_list'] as $name ) {
			$key = $this->filterField( $a, $name );
			if ( '' !== $key && isset( $public[ $key ] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** Field key behind a filter name ('brand' → the brand field), '' if none. */
	private function filterField( array $a, string $name ): string {
		if ( 'brand' === $name || 'origin' === $name ) {
			$f = $this->roleField( $a['type'], $name );
			return $f ? $f['meta_key'] : '';
		}
		return in_array( $name, array( 'category', 'stock', 'new', 'photo' ), true ) ? '' : $name;
	}

	/** Query arguments of a state. */
	private function query( array $a, array $state ): array {
		return array(
			'search'   => $state['search'],
			'cats'     => $state['cats'],
			'base_cat' => $state['tab'] ? (string) $state['tab'] : $a['scope'],
			'exclude'  => (string) $a['exclude'],
			'meta'     => $state['meta'],
			'in_stock' => in_array( 'in_stock', $state['flags'], true ),
			'since'    => in_array( 'new', $state['flags'], true ) ? gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) : '',
			'image'    => in_array( 'photo', $state['flags'], true ) ? 'yes' : '',
			'sort'     => $state['sort'],
			'public'   => true,
			'page'     => $state['page'],
			'per_page' => $state['num'],
		);
	}

	// ─────────────────────────────────────────────────────────────
	// List view
	// ─────────────────────────────────────────────────────────────

	private function listView( array $a, Context $ctx, array $state ): string {
		$type = $a['type'];
		$tabs = $this->tabTerms( $a );
		if ( null === $tabs ) {
			return '<p class="dwcat-empty">' . Html::e( $this->t( 'No items found.' ) ) . '</p>';
		}
		$query  = $this->query( $a, $state );
		$result = $this->c->query()->run( $type, $query );
		$ids    = array_map( 'intval', array_column( $result['rows'], 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $type, $ids );
		$prices = in_array( 'price', $a['card_list'], true ) && $this->publicPrice( $type ) ? $this->c->items()->minPrices( $ids ) : array();

		$params   = $this->params( $state, $a );
		$fields   = $this->cardFields( $type, $a );
		$filtered = '' !== $state['search'] || $state['cats'] || $state['meta'] || $state['flags'];
		$from     = $result['total'] ? ( $result['page'] - 1 ) * $state['num'] + 1 : 0;
		$to       = min( $result['total'], $from + count( $result['rows'] ) - 1 );
		$form     = 'dwcat-filter-form';
		$panels   = $this->filters( $a, $state, $ctx, $tabs, $query );

		ob_start();
		?>
		<div class="dwcat-head">
			<?php if ( '' !== trim( $a['title'] ) ) : ?>
				<h1 class="dwcat-h1"><?php echo Html::e( $a['title'] ); ?></h1>
			<?php endif; ?>
			<p class="dwcat-sub"><?php echo Html::e( $this->summary( $a, $tabs ) ); ?></p>
			<?php if ( 'hidden' !== $a['search_size'] ) : ?>
				<div class="dwcat-search dwcat-search-<?php echo Html::attr( $a['search_size'] ); ?>" role="search">
					<label for="dwcat-search" class="dwcat-sr"><?php echo Html::e( $this->t( 'Search catalog' ) ); ?></label>
					<svg class="dwcat-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
					<input type="search" id="dwcat-search" name="<?php echo self::P_SEARCH; ?>" form="<?php echo $form; ?>" value="<?php echo Html::attr( $state['search'] ); ?>" placeholder="<?php echo Html::attr( $this->t( 'Search by name, item code or brand' ) ); ?>" maxlength="200">
					<button type="submit" form="<?php echo $form; ?>" class="dwcat-search-btn"><?php echo Html::e( $this->t( 'Search' ) ); ?></button>
				</div>
				<?php echo $this->suggestions( $a, $state, $ctx ); ?>
			<?php endif; ?>
			<?php echo $this->tabs( $a, $ctx, $state, $tabs ); ?>
		</div>

		<div class="dwcat-layout">
			<aside class="dwcat-sidebar" aria-label="<?php echo Html::attr( $this->t( 'Filters' ) ); ?>" data-dwcat-sidebar>
				<div class="dwcat-sheet-head">
					<strong><?php echo Html::e( $this->t( 'Filters' ) ); ?></strong>
					<button type="button" class="dwcat-icon-btn" data-dwcat-toggle aria-label="<?php echo Html::attr( $this->t( 'Close filters' ) ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				</div>
				<form id="<?php echo $form; ?>" class="dwcat-filter-form" method="get" action="<?php echo Html::url( strtok( $ctx->listUrl(), '?' ) ); ?>" data-dwcat-form>
					<?php echo $this->pageQueryFields( $ctx ); ?>
					<?php if ( $state['tab'] ) : ?>
						<input type="hidden" name="<?php echo self::P_TAB; ?>" value="<?php echo (int) $state['tab']; ?>">
					<?php endif; ?>
					<?php if ( $state['view'] !== $a['default_view'] ) : ?>
						<input type="hidden" name="<?php echo self::P_VIEW; ?>" value="<?php echo Html::attr( $state['view'] ); ?>">
					<?php endif; ?>
					<?php if ( ( 'yes' !== $a['sort'] || count( $a['sort_list'] ) < 2 ) && $state['sort'] !== $a['default_sort'] ) : ?>
						<input type="hidden" name="<?php echo self::P_SORT; ?>" value="<?php echo Html::attr( $state['sort'] ); ?>">
					<?php endif; ?>
					<?php if ( 'hidden' === $a['search_size'] && '' !== $state['search'] ) : ?>
						<input type="hidden" name="<?php echo self::P_SEARCH; ?>" value="<?php echo Html::attr( $state['search'] ); ?>">
					<?php endif; ?>
					<?php echo $panels; ?>
					<noscript><button type="submit" class="dwcat-btn"><?php echo Html::e( $this->t( 'Apply' ) ); ?></button></noscript>
					<?php if ( $filtered ) : ?>
						<a class="dwcat-btn dwcat-btn-block dwcat-reset" href="<?php echo Html::url( $ctx->listUrl( $this->params( $state, $a, array( self::P_SEARCH, self::P_CATS, self::P_FIELDS, self::P_FLAGS, self::P_PAGE ) ) ) ); ?>" data-dwcat-link><?php echo Html::e( $this->t( 'Reset filters' ) ); ?></a>
					<?php endif; ?>
					<p class="dwcat-note"><?php echo Html::e( $this->t( 'Filters apply instantly — no Apply button needed.' ) ); ?></p>
				</form>
			</aside>

			<div class="dwcat-main">
				<div class="dwcat-toolbar">
					<button type="button" class="dwcat-pill dwcat-filter-toggle" data-dwcat-toggle aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg><?php echo Html::e( $this->t( 'Filters' ) ); ?>
						<?php $active = count( $state['cats'] ) + count( $state['flags'] ) + array_sum( array_map( 'count', $state['meta'] ) ); ?>
						<?php if ( $active ) : ?><span class="dwcat-pill-count"><?php echo (int) $active; ?></span><?php endif; ?>
					</button>
					<p class="dwcat-count" aria-live="polite">
						<?php if ( $result['total'] ) : ?>
							<?php echo sprintf( Html::e( $this->t( 'Showing %1$s of %2$s items' ) ), '<strong>' . (int) $from . '–' . (int) $to . '</strong>', '<strong>' . (int) $result['total'] . '</strong>' ); ?>
						<?php else : ?>
							<?php echo Html::e( $this->t( 'No items' ) ); ?>
						<?php endif; ?>
					</p>
					<div class="dwcat-tools">
						<?php if ( 'yes' === $a['sort'] && count( $a['sort_list'] ) > 1 ) : ?>
							<label class="dwcat-sort"><span><?php echo Html::e( $this->t( 'Sort by' ) ); ?></span>
								<select name="<?php echo self::P_SORT; ?>" form="<?php echo $form; ?>" data-default="<?php echo Html::attr( $a['default_sort'] ); ?>">
									<?php foreach ( $a['sort_list'] as $key => $label ) : ?>
										<option value="<?php echo Html::attr( $key ); ?>" <?php echo $state['sort'] === $key ? 'selected' : ''; ?>><?php echo Html::e( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>
						<div class="dwcat-view" role="group" aria-label="<?php echo Html::attr( $this->t( 'View' ) ); ?>">
							<?php foreach ( array( 'grid' => 'Grid view', 'list' => 'List view' ) as $v => $label ) : ?>
								<?php $vp = $this->params( array_merge( $state, array( 'view' => $v ) ), $a, array( self::P_PAGE ) ); ?>
								<a href="<?php echo Html::url( $ctx->listUrl( $vp ) ); ?>" class="dwcat-view-btn<?php echo $state['view'] === $v ? ' is-on' : ''; ?>" aria-label="<?php echo Html::attr( $this->t( $label ) ); ?>" aria-pressed="<?php echo $state['view'] === $v ? 'true' : 'false'; ?>" data-dwcat-link>
									<?php if ( 'grid' === $v ) : ?>
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1"/></svg>
									<?php else : ?>
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<?php echo $this->chips( $a, $state, $ctx ); ?>

				<?php if ( ! $result['rows'] ) : ?>
					<p class="dwcat-empty"><?php echo Html::e( $this->t( 'No items match your filters.' ) ); ?></p>
				<?php elseif ( 'list' === $state['view'] ) : ?>
					<?php echo $this->table( $result['rows'], $fields, $ctx, $params, $a ); ?>
				<?php else : ?>
					<div class="dwcat-grid" data-dwcat-grid data-per-tablet="<?php echo (int) $a['per_page_tablet']; ?>" data-per-mobile="<?php echo (int) $a['per_page_mobile']; ?>" style="--dwcat-cols: <?php echo (int) $a['columns']; ?>; --dwcat-cols-t: <?php echo (int) $a['cols_tablet']; ?>; --dwcat-cols-m: <?php echo (int) $a['cols_mobile']; ?>;">
						<?php foreach ( $result['rows'] as $i => $item ) : ?>
							<?php echo $this->card( $item, $fields, $ctx->itemUrl( $item['slug'], $params ), $a, $i < (int) $a['columns'], $prices ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php echo $this->pagination( $result, $state, $a, $ctx ); ?>
			</div>
		</div>
		<div class="dwcat-drawer" data-dwcat-drawer hidden>
			<div class="dwcat-drawer-backdrop" data-dwcat-close></div>
			<div class="dwcat-drawer-panel" role="dialog" aria-modal="true" aria-label="<?php echo Html::attr( $this->t( 'Item details' ) ); ?>">
				<div class="dwcat-drawer-bar">
					<span class="dwcat-drawer-kicker" data-dwcat-drawer-kicker></span>
					<button type="button" class="dwcat-icon-btn" data-dwcat-close aria-label="<?php echo Html::attr( $this->t( 'Close' ) ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				</div>
				<div class="dwcat-drawer-body" data-dwcat-drawer-body></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A GET form drops the query of its action, so a page address that has one (plain permalinks:
	 * ?page_id=4) travels as hidden fields.
	 */
	private function pageQueryFields( Context $ctx ): string {
		parse_str( (string) parse_url( $ctx->listUrl(), PHP_URL_QUERY ), $base );
		$html = '';
		foreach ( $base as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$html .= '<input type="hidden" name="' . Html::attr( $k ) . '" value="' . Html::attr( $v ) . '">';
			}
		}
		return $html;
	}

	/** "343 items across Frozen, Fresh, …" (published items this page can show). */
	private function summary( array $a, array $tabs ): string {
		$total = $this->c->query()->run( $a['type'], array( 'base_cat' => $a['scope'], 'exclude' => (string) $a['exclude'], 'public' => true, 'per_page' => 1 ) )['total'];
		$names = array_column( $tabs, 'name' );
		$text  = sprintf( 1 === $total ? $this->t( '%d item' ) : $this->t( '%d items' ), $total );
		if ( $names && count( $names ) <= 8 ) {
			$last  = array_pop( $names );
			$text .= ' ' . sprintf( $this->t( 'across %s' ), $names ? implode( ', ', $names ) . ' ' . $this->t( 'and' ) . ' ' . $last : $last ) . '.';
		}
		return $text;
	}

	/** Suggested search words under the search box. */
	private function suggestions( array $a, array $state, Context $ctx ): string {
		$words = array_slice( array_filter( array_map( 'trim', explode( ',', (string) $a['suggest'] ) ), 'strlen' ), 0, 12 );
		if ( ! $words ) {
			return '';
		}
		$html = '<p class="dwcat-suggest"><span>' . Html::e( $this->t( 'Popular:' ) ) . '</span>';
		foreach ( $words as $w ) {
			$p     = $this->params( array_merge( $state, array( 'search' => Text::line( $w, 40 ) ) ), $a, array( self::P_PAGE ) );
			$html .= '<a href="' . Html::url( $ctx->listUrl( $p ) ) . '" data-dwcat-link>' . Html::e( $w ) . '</a>';
		}
		return $html . '</p>';
	}

	private function tabs( array $a, Context $ctx, array $state, array $tabs ): string {
		if ( 'yes' !== $a['tabs'] || ! $tabs ) {
			return '';
		}
		$html = '<nav class="dwcat-tabs" aria-label="' . Html::attr( $this->t( 'Categories' ) ) . '">';
		$all  = $this->params( array_merge( $state, array( 'tab' => 0, 'cats' => array() ) ), $a, array( self::P_PAGE ) );
		$html .= '<a class="dwcat-tab' . ( ! $state['tab'] ? ' is-on' : '' ) . '" href="' . Html::url( $ctx->listUrl( $all ) ) . '"' . ( ! $state['tab'] ? ' aria-current="page"' : '' ) . ' data-dwcat-link><i style="background:#0f1b2d" aria-hidden="true"></i>' . Html::e( $this->t( 'All' ) ) . '</a>';
		foreach ( $tabs as $i => $term ) {
			$on    = (int) $term['id'] === $state['tab'];
			$p     = $this->params( array_merge( $state, array( 'tab' => (int) $term['id'], 'cats' => array() ) ), $a, array( self::P_PAGE ) );
			$html .= '<a class="dwcat-tab' . ( $on ? ' is-on' : '' ) . '" href="' . Html::url( $ctx->listUrl( $p ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . ' data-dwcat-link><i style="background:' . self::DOTS[ $i % count( self::DOTS ) ] . '" aria-hidden="true"></i>' . Html::e( $term['name'] ) . '</a>';
		}
		return $html . '</nav>';
	}

	private function pagination( array $result, array $state, array $a, Context $ctx ): string {
		if ( ! $result['total'] ) {
			return '';
		}
		$html = '<nav class="dwcat-pagination" aria-label="' . Html::attr( $this->t( 'Pages' ) ) . '">';
		// "Load more": shows products held back on tablets / phones, then appends the next page
		// (catalog.js). Without JS it is simply the next page link (last page: not shown).
		$last  = $result['page'] >= $result['pages'];
		$next  = $this->params( array_merge( $state, array( 'page' => min( $result['pages'], $result['page'] + 1 ) ) ), $a );
		$html .= '<a class="dwcat-btn dwcat-btn-block dwcat-more-btn" href="' . Html::url( $ctx->listUrl( $next ) ) . '" data-dwcat-more-page' . ( $last ? ' data-last' : '' ) . '>' . Html::e( $this->t( 'Load more' ) ) . '</a>';
		$html .= '<div class="dwcat-pages">';
		$link = function ( $p, $label, $aria = '' ) use ( $state, $a, $ctx, $result ) {
			$disabled = $p < 1 || $p > $result['pages'];
			if ( $disabled ) {
				return '<span class="dwcat-page is-disabled"' . ( $aria ? ' aria-label="' . Html::attr( $aria ) . '"' : '' ) . '>' . $label . '</span>';
			}
			if ( $p === $result['page'] && '' === $aria ) {
				return '<span class="dwcat-page is-current" aria-current="page">' . $label . '</span>';
			}
			$params = $this->params( array_merge( $state, array( 'page' => $p ) ), $a );
			return '<a class="dwcat-page" href="' . Html::url( $ctx->listUrl( $params ) ) . '"' . ( $aria ? ' aria-label="' . Html::attr( $aria ) . '"' : '' ) . ' data-dwcat-link>' . $label . '</a>';
		};
		$html .= $link( $result['page'] - 1, '&lsaquo;', $this->t( 'Previous page' ) );
		$gap   = false;
		for ( $p = 1; $p <= $result['pages']; $p++ ) {
			if ( $p > 1 && $p < $result['pages'] && abs( $p - $result['page'] ) > 2 ) {
				if ( ! $gap ) {
					$html .= '<span class="dwcat-page-gap">…</span>';
					$gap   = true;
				}
				continue;
			}
			$gap   = false;
			$html .= $link( $p, (string) $p );
		}
		$html .= $link( $result['page'] + 1, '&rsaquo;', $this->t( 'Next page' ) ) . '</div>';
		$opts  = $this->perPageOptions( $a );
		if ( count( $opts ) > 1 ) {
			$html .= '<label class="dwcat-perpage">' . Html::e( $this->t( 'Per page' ) ) . ' <select name="' . self::P_NUM . '" form="dwcat-filter-form" data-default="' . (int) $a['per_page'] . '">';
			foreach ( $opts as $n ) {
				$html .= '<option value="' . (int) $n . '"' . ( $n === $state['num'] ? ' selected' : '' ) . '>' . (int) $n . '</option>';
			}
			$html .= '</select></label>';
		}
		return $html . '</nav>';
	}

	// ─────────────────────────────────────────────────────────────
	// Filters
	// ─────────────────────────────────────────────────────────────

	/** Every filter panel, in the configured order, with counts. Filters with nothing to offer are left out. */
	private function filters( array $a, array $state, Context $ctx, array $tabs, array $query ): string {
		$facets = array();
		$config = $this->c->fields()->type( $a['type'] );
		$keys   = array();
		foreach ( $a['filter_list'] as $name ) {
			if ( 'category' === $name && ! empty( $config['has_category'] ) ) {
				$facets[] = 'category';
			} elseif ( isset( self::FLAGS[ $name ] ) ) {
				if ( 'stock' !== $name || $this->roleField( $a['type'], 'status' ) ) {
					$facets[] = 'flag:' . self::FLAGS[ $name ];
				}
			} else {
				$key = $this->filterField( $a, $name );
				if ( '' !== $key && in_array( $key, $this->fieldFilterKeys( $a ), true ) ) {
					$facets[]      = $key;
					$keys[ $name ] = $key;
				}
			}
		}
		if ( ! $facets ) {
			return '';
		}
		$counts = $this->c->query()->facets( $a['type'], $query, array_values( array_unique( $facets ) ) );
		$html   = '';
		$flags  = false;
		foreach ( $a['filter_list'] as $name ) {
			if ( 'category' === $name && isset( $counts['category'] ) ) {
				$html .= $this->categoryFilter( $a, $state, $tabs, $ctx, $counts['category'] );
			} elseif ( isset( self::FLAGS[ $name ] ) ) {
				if ( ! $flags ) {
					$flags = true;
					$html .= $this->flagFilter( $a, $state, $counts );
				}
			} elseif ( isset( $keys[ $name ] ) && isset( $counts[ $keys[ $name ] ] ) ) {
				$html .= $this->fieldFilter( $a, $state, $keys[ $name ], $counts[ $keys[ $name ] ] );
			}
		}
		return $html;
	}

	/** One filter panel: a section in the sidebar, a dropdown in the top bar. */
	private function panel( array $a, string $title, string $body, string $clear = '', int $selected = 0, bool $open = false ): string {
		if ( 'top' === $a['filter_position'] ) {
			return '<details class="dwcat-panel dwcat-dd"' . ( $open ? ' open' : '' ) . '><summary><span>' . Html::e( $title ) . '</span>' . ( $selected ? '<em class="dwcat-dd-count">' . (int) $selected . '</em>' : '' ) . '<svg class="dwcat-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary><div class="dwcat-dd-body">' . ( '' !== $clear ? '<div class="dwcat-panel-head">' . $clear . '</div>' : '' ) . $body . '</div></details>';
		}
		return '<section class="dwcat-panel"><div class="dwcat-panel-head"><h2>' . Html::e( $title ) . '</h2>' . $clear . '</div>' . $body . '</section>';
	}

	/** A search box over a long choice list (catalog.js filters the list as you type). */
	private function listSearch( string $label ): string {
		return '<label class="dwcat-listsearch"><span class="dwcat-sr">' . Html::e( sprintf( $this->t( 'Search %s' ), Text::lower( $label ) ) ) . '</span><input type="search" data-dwcat-listsearch placeholder="' . Html::attr( sprintf( $this->t( 'Search %s' ), Text::lower( $label ) ) ) . '" autocomplete="off"></label>';
	}

	/**
	 * Category groups: each tab-level category is a collapsible group of its sub-categories.
	 * With a tab selected, only that tab's sub-categories are listed. Counts follow the other filters.
	 */
	private function categoryFilter( array $a, array $state, array $tabs, Context $ctx, array $counts ): string {
		$tree  = $this->c->terms()->tree( $a['type'] );
		$out   = $this->excludedIds( $a );
		$total = function ( $id ) use ( &$total, $tree, $counts, $out ) {
			if ( in_array( (int) $id, $out, true ) ) {
				return 0;
			}
			$n = isset( $counts[ $id ] ) ? $counts[ $id ] : 0;
			foreach ( isset( $tree[ $id ] ) ? $tree[ $id ] : array() as $child ) {
				$n += $total( (int) $child['id'] );
			}
			return $n;
		};
		$rows   = 0;
		$checks = function ( $parent, $depth = 0 ) use ( &$checks, &$rows, $tree, $state, $total ) {
			$html = '';
			foreach ( isset( $tree[ $parent ] ) ? $tree[ $parent ] : array() as $t ) {
				$n  = $total( (int) $t['id'] );
				$on = in_array( (int) $t['id'], $state['cats'], true );
				if ( ! $n && ! $on ) {
					continue;
				}
				$rows++;
				$html .= '<li><label class="dwcat-check"' . ( $depth ? ' style="padding-left:' . ( 16 * $depth ) . 'px"' : '' ) . '><input type="checkbox" name="' . self::P_CATS . '[]" value="' . (int) $t['id'] . '"' . ( $on ? ' checked' : '' ) . '><span>' . Html::e( $t['name'] ) . '</span><em>' . (int) $n . '</em></label></li>'
					. $checks( (int) $t['id'], $depth + 1 );
			}
			return $html;
		};

		$clear  = $state['cats'] ? '<a class="dwcat-link" href="' . Html::url( $ctx->listUrl( $this->params( $state, $a, array( self::P_CATS, self::P_PAGE ) ) ) ) . '" data-dwcat-link>' . Html::e( $this->t( 'Clear' ) ) . '</a>' : '';
		$groups = $state['tab'] ? array( array( 'id' => $state['tab'], 'only' => true ) ) : $tabs;
		$body   = '';
		foreach ( $groups as $i => $g ) {
			$gid = (int) $g['id'];
			$n   = $total( $gid );
			$on  = in_array( $gid, $state['cats'], true );
			if ( ! $n && ! $on ) {
				continue;
			}
			$subs = $checks( $gid );
			if ( ! empty( $g['only'] ) ) {
				$body .= '' !== $subs ? '<ul class="dwcat-checklist">' . $subs . '</ul>' : '';
				continue;
			}
			$rows++;
			if ( '' === $subs ) { // no children: the group itself is the filter
				$body .= '<ul class="dwcat-checklist"><li><label class="dwcat-check dwcat-check-top"><input type="checkbox" name="' . self::P_CATS . '[]" value="' . $gid . '"' . ( $on ? ' checked' : '' ) . '><i style="background:' . self::DOTS[ $i % count( self::DOTS ) ] . '" aria-hidden="true"></i><span>' . Html::e( $g['name'] ) . '</span><em>' . (int) $n . '</em></label></li></ul>';
				continue;
			}
			$open  = (bool) array_intersect( $state['cats'], $this->c->terms()->withDescendants( $a['type'], array( $gid ) ) );
			$body .= '<details class="dwcat-group"' . ( $open ? ' open' : '' ) . '><summary><i style="background:' . self::DOTS[ $i % count( self::DOTS ) ] . '" aria-hidden="true"></i><span>' . Html::e( $g['name'] ) . '</span><em>' . (int) $n . '</em><svg class="dwcat-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary><ul class="dwcat-checklist">' . $subs . '</ul></details>';
		}
		if ( '' === $body ) {
			return '';
		}
		$label = $this->t( 'Categories' );
		return $this->panel( $a, $label, ( $rows > 8 ? $this->listSearch( $label ) : '' ) . '<div class="dwcat-catlist" data-dwcat-searchable>' . $body . '</div>', $clear, count( $state['cats'] ) );
	}

	/** Brand, origin or any public field: its values with counts. */
	private function fieldFilter( array $a, array $state, string $key, array $counts ): string {
		$field = $this->c->fields()->fieldsByKey( $a['type'], true )[ $key ];
		$sel   = isset( $state['meta'][ $key ] ) ? $state['meta'][ $key ] : array();
		$opts  = 'select' === $field['type'] ? Text::parseOptions( (string) $field['options'] ) : array();
		$items = array();
		foreach ( $opts ?: array_combine( array_keys( $counts ), array_keys( $counts ) ) as $value => $label ) {
			$value = (string) $value;
			$n     = isset( $counts[ $value ] ) ? $counts[ $value ] : 0;
			if ( $n || in_array( $value, $sel, true ) ) {
				$items[ $value ] = array( (string) $label, $n );
			}
		}
		if ( ! $items ) {
			return '';
		}
		$long = count( $items ) > 8;
		$body = ( $long ? $this->listSearch( $field['label'] ) : '' ) . '<ul class="dwcat-checklist' . ( $long ? ' is-collapsible' : '' ) . '" data-dwcat-searchable>';
		$i    = 0;
		foreach ( $items as $value => list( $label, $n ) ) {
			$checked = in_array( $value, $sel, true );
			$body   .= '<li' . ( $i++ >= 8 && ! $checked ? ' class="dwcat-more"' : '' ) . '><label class="dwcat-check"><input type="checkbox" name="' . self::P_FIELDS . '[' . Html::attr( $key ) . '][]" value="' . Html::attr( $value ) . '"' . ( $checked ? ' checked' : '' ) . '><span>' . Html::e( $label ) . '</span><em>' . (int) $n . '</em></label></li>';
		}
		$body .= '</ul>';
		if ( $long ) {
			$body .= '<button type="button" class="dwcat-link" data-dwcat-more>' . Html::e( sprintf( $this->t( 'Show all %s' ), Text::lower( $field['label'] ) ) ) . '</button>';
		}
		return $this->panel( $a, $field['label'], $body, '', count( $sel ) );
	}

	/** In stock · New in the last 30 days · With photo — one panel. */
	private function flagFilter( array $a, array $state, array $counts ): string {
		$labels = array(
			'in_stock' => $this->t( 'In stock' ),
			'new'      => $this->t( 'New in the last 30 days' ),
			'photo'    => $this->t( 'With photo' ),
		);
		$body   = '';
		$sel    = 0;
		foreach ( $a['filter_list'] as $name ) {
			if ( ! isset( self::FLAGS[ $name ] ) ) {
				continue;
			}
			$flag = self::FLAGS[ $name ];
			$n    = isset( $counts[ 'flag:' . $flag ][1] ) ? $counts[ 'flag:' . $flag ][1] : 0;
			$on   = in_array( $flag, $state['flags'], true );
			if ( ! isset( $counts[ 'flag:' . $flag ] ) || ( ! $n && ! $on ) ) {
				continue;
			}
			$sel  += $on ? 1 : 0;
			$body .= '<li><label class="dwcat-check"><input type="checkbox" name="' . self::P_FLAGS . '[]" value="' . Html::attr( $flag ) . '"' . ( $on ? ' checked' : '' ) . '><span>' . Html::e( $labels[ $flag ] ) . '</span><em>' . (int) $n . '</em></label></li>';
		}
		return '' !== $body ? $this->panel( $a, $this->t( 'Show only' ), '<ul class="dwcat-checklist">' . $body . '</ul>', '', $sel ) : '';
	}

	/** Chips of what is selected, each removable, plus "Clear all". */
	private function chips( array $a, array $state, Context $ctx ): string {
		$chips = array();
		$drop  = function ( array $over ) use ( $state, $a, $ctx ) {
			return Html::url( $ctx->listUrl( $this->params( array_merge( $state, $over, array( 'page' => 1 ) ), $a ) ) );
		};
		if ( '' !== $state['search'] ) {
			$chips[] = array( '“' . $state['search'] . '”', $drop( array( 'search' => '' ) ) );
		}
		foreach ( $state['cats'] as $id ) {
			$t = $this->c->terms()->get( $a['type'], $id );
			if ( $t ) {
				$chips[] = array( $t['name'], $drop( array( 'cats' => array_values( array_diff( $state['cats'], array( $id ) ) ) ) ) );
			}
		}
		$fields = $this->c->fields()->fieldsByKey( $a['type'], true );
		foreach ( $state['meta'] as $key => $values ) {
			foreach ( $values as $v ) {
				$meta         = $state['meta'];
				$meta[ $key ] = array_values( array_diff( $values, array( $v ) ) );
				if ( ! $meta[ $key ] ) {
					unset( $meta[ $key ] );
				}
				$chips[] = array( FieldConfig::display( $fields[ $key ], $v ), $drop( array( 'meta' => $meta ) ) );
			}
		}
		$labels = array( 'in_stock' => $this->t( 'In stock' ), 'new' => $this->t( 'New in the last 30 days' ), 'photo' => $this->t( 'With photo' ) );
		foreach ( $state['flags'] as $flag ) {
			$chips[] = array( $labels[ $flag ], $drop( array( 'flags' => array_values( array_diff( $state['flags'], array( $flag ) ) ) ) ) );
		}
		if ( ! $chips ) {
			return '';
		}
		$html = '<div class="dwcat-chips" aria-label="' . Html::attr( $this->t( 'Selected filters' ) ) . '">';
		foreach ( $chips as list( $label, $url ) ) {
			$html .= '<a class="dwcat-chip" href="' . $url . '" data-dwcat-link aria-label="' . Html::attr( sprintf( $this->t( 'Remove %s' ), $label ) ) . '">' . Html::e( $label ) . '<span aria-hidden="true">&times;</span></a>';
		}
		$all = $ctx->listUrl( $this->params( $state, $a, array( self::P_SEARCH, self::P_CATS, self::P_FIELDS, self::P_FLAGS, self::P_PAGE ) ) );
		return $html . '<a class="dwcat-link dwcat-chips-clear" href="' . Html::url( $all ) . '" data-dwcat-link>' . Html::e( $this->t( 'Clear all' ) ) . '</a></div>';
	}

	// ─────────────────────────────────────────────────────────────
	// Card / list row
	// ─────────────────────────────────────────────────────────────

	/**
	 * Fields on a card. card_fields (3.0): code · pack · brand · origin · category · price · field
	 * keys, in that order. Otherwise (2.x) show_fields or the "List" fields, without the brand
	 * (shown in the kicker). Public fields only, never the name field.
	 */
	private function cardFields( string $type, array $a ): array {
		$public = array_filter( $this->c->fields()->fieldsByKey( $type, true ), function ( $f ) {
			return empty( $f['is_title_field'] );
		} );
		if ( $a['card_list'] ) {
			$out = array();
			foreach ( $a['card_list'] as $token ) {
				if ( in_array( $token, array( 'code', 'pack', 'origin' ), true ) ) {
					$f = $this->roleField( $type, $token );
					if ( $f && isset( $public[ $f['meta_key'] ] ) ) {
						$out[ $f['meta_key'] ] = $f;
					}
				} elseif ( isset( $public[ $token ] ) ) {
					$out[ $token ] = $public[ $token ];
				}
			}
			$brand = $this->roleField( $type, 'brand' );
			return array_values( array_filter( $out, function ( $f ) use ( $brand ) {
				return ! $brand || $f['meta_key'] !== $brand['meta_key'];
			} ) );
		}
		$brand = $this->roleField( $type, 'brand' );
		$want  = array_filter( array_map( 'trim', explode( ',', (string) $a['show_fields'] ) ) );
		return array_values( array_filter( $public, function ( $f ) use ( $brand, $want ) {
			if ( $brand && $f['meta_key'] === $brand['meta_key'] ) {
				return false;
			}
			return $want ? in_array( $f['meta_key'], $want, true ) : ! empty( $f['show_in_list'] );
		} ) );
	}

	/** Is there a public option price column? */
	private function publicPrice( string $type ): bool {
		foreach ( $this->c->fields()->variationColumns( $type ) as $col ) {
			if ( in_array( $col['key'], array( 'price', 'wholesale', 'case_price' ), true ) && VariationColumns::isPublic( $col ) ) {
				return true;
			}
		}
		return false;
	}

	/** A public field playing a role (brand, item code, origin, pack, status). */
	private function roleField( string $type, string $role ): ?array {
		return $this->c->fields()->roleField( $type, $role, true );
	}

	private static function mono( array $f ): bool {
		return FieldConfig::mono( $f );
	}

	/** Top-level ancestor and deepest category of an item. @return array [ top, leaf, dotIndex ] */
	private function placement( array $item ): array {
		$terms = $this->c->terms()->ofItem( $item['type'], (int) $item['id'] );
		if ( ! $terms ) {
			return array( null, null, 0 );
		}
		$leaf = $terms[0];
		$top  = $leaf;
		$seen = array();
		while ( (int) $top['parent'] && ! isset( $seen[ (int) $top['id'] ] ) ) {
			$seen[ (int) $top['id'] ] = true;
			$parent                   = $this->c->terms()->get( $item['type'], (int) $top['parent'] );
			if ( ! $parent ) {
				break;
			}
			$top = $parent;
		}
		$tree = $this->c->terms()->tree( $item['type'] );
		$dot  = 0;
		foreach ( isset( $tree[0] ) ? $tree[0] : array() as $i => $t ) {
			if ( (int) $t['id'] === (int) $top['id'] ) {
				$dot = $i;
			}
		}
		return array( $top, $leaf, $dot );
	}

	private function kicker( array $item, ?array $leaf, bool $category = true, bool $brand = true ): string {
		$parts = array();
		if ( $leaf && $category ) {
			$parts[] = $leaf['name'];
		}
		$field = $brand ? $this->roleField( $item['type'], 'brand' ) : null;
		if ( $field ) {
			$v = $this->c->items()->value( (int) $item['id'], $field['meta_key'] );
			if ( '' !== $v ) {
				$parts[] = $v;
			}
		}
		return implode( ' · ', $parts );
	}

	private function placeholder( int $size ): string {
		return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-5 9 5-9 5-9-5z"/><path d="M3 9v6l9 5 9-5V9"/><path d="M12 14v6"/></svg>';
	}

	/**
	 * @param array $a      card_list decides badge / brand / price (3.0); otherwise show_category
	 * @param bool  $eager  first row — load immediately (it is what the visitor sees first)
	 * @param array $prices item id => lowest option price (only when the card shows a price)
	 */
	private function card( array $item, array $fields, string $url, array $a, bool $eager = false, array $prices = array() ): string {
		$id                       = (int) $item['id'];
		$img                      = $this->c->images()->main( $id );
		list( $top, $leaf, $dot ) = $this->placement( $item );
		$modern                   = ! empty( $a['card_list'] );
		$showCat                  = $modern ? in_array( 'category', $a['card_list'], true ) : 'yes' === $a['show_category'];
		$showBrand                = ! $modern || in_array( 'brand', $a['card_list'], true );

		$html  = '<article class="dwcat-card"><div class="dwcat-card-media">';
		// Cards are ~300px on PC, ~half the screen on phones → the 600px file covers both at 2×.
		$html .= $img ? $this->c->images()->tag( $img, 'medium', '(max-width: 600px) 50vw, 300px', ! $eager ) : '<span class="dwcat-ph">' . $this->placeholder( 56 ) . '</span>';
		if ( $showCat && $top ) {
			$html .= '<span class="dwcat-badge"><i style="background:' . self::DOTS[ $dot % count( self::DOTS ) ] . '" aria-hidden="true"></i>' . Html::e( $top['name'] ) . '</span>';
		}
		$html .= '</div><div class="dwcat-card-body">';
		$kick  = $this->kicker( $item, $leaf, ! $modern || $showCat, $showBrand );
		if ( '' !== $kick ) {
			$html .= '<span class="dwcat-kicker">' . Html::e( $kick ) . '</span>';
		}
		$html  .= '<h3 class="dwcat-card-title"><a href="' . Html::url( $url ) . '" class="dwcat-card-link">' . Html::e( $item['title'] ) . '</a></h3>';
		$rows   = '';
		$inline = array();
		foreach ( $fields as $f ) {
			$v = FieldConfig::display( $f, $this->c->items()->value( $id, $f['meta_key'] ) );
			if ( '' !== $v ) {
				$rows    .= '<dt>' . Html::e( $f['label'] ) . '</dt><dd' . ( self::mono( $f ) ? ' class="is-mono"' : '' ) . '>' . Html::e( $v ) . '</dd>';
				$inline[] = Html::e( $v );
			}
		}
		if ( isset( $prices[ $id ] ) ) {
			$price = rtrim( rtrim( number_format( (float) $prices[ $id ], 2, '.', ',' ), '0' ), '.' );
			$rows .= '<dt>' . Html::e( $this->t( 'From' ) ) . '</dt><dd class="is-mono dwcat-price">' . Html::e( $price ) . '</dd>';
		}
		if ( '' !== $rows ) {
			$html .= '<dl class="dwcat-card-fields">' . $rows . '</dl><p class="dwcat-card-inline">' . implode( ' · ', $inline ) . '</p>';
		}
		return $html . '</div></article>';
	}

	private function table( array $rows, array $fields, Context $ctx, array $params, array $a ): string {
		$html = '<div class="dwcat-table-wrap"><table class="dwcat-table"><thead><tr><th scope="col">' . Html::e( $this->t( 'Product' ) ) . '</th>';
		foreach ( $fields as $f ) {
			$html .= '<th scope="col">' . Html::e( $f['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $item ) {
			$id             = (int) $item['id'];
			$img            = $this->c->images()->main( $id );
			list( , $leaf ) = $this->placement( $item );
			$kick           = $this->kicker( $item, $leaf );
			$html          .= '<tr><td><div class="dwcat-row-product"><span class="dwcat-row-thumb">' . ( $img ? $this->c->images()->tag( $img, 'thumb' ) : $this->placeholder( 22 ) ) . '</span><span><a class="dwcat-card-link dwcat-row-title" href="' . Html::url( $ctx->itemUrl( $item['slug'], $params ) ) . '">' . Html::e( $item['title'] ) . '</a>' . ( '' !== $kick ? '<span class="dwcat-row-kicker">' . Html::e( $kick ) . '</span>' : '' ) . '</span></div></td>';
			foreach ( $fields as $f ) {
				$v     = FieldConfig::display( $f, $this->c->items()->value( $id, $f['meta_key'] ) );
				$html .= '<td' . ( self::mono( $f ) ? ' class="is-mono"' : '' ) . '>' . Html::e( '' !== $v ? $v : '—' ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	// ─────────────────────────────────────────────────────────────
	// Detail (full page; catalog.js shows the same block in a drawer)
	// ─────────────────────────────────────────────────────────────

	private function detail( array $item, array $a, Context $ctx, array $state, bool $page ): string {
		$id                 = (int) $item['id'];
		$type               = $item['type'];
		$images             = $this->c->images()->forItem( $id );
		list( $top, $leaf ) = $this->placement( $item );
		$want               = array_filter( array_map( 'trim', explode( ',', (string) $a['detail_fields'] ) ) );
		$fields             = array_filter( $this->c->fields()->fields( $type ), function ( $f ) use ( $want ) {
			return FieldConfig::isPublic( $f ) && empty( $f['is_title_field'] ) && ( ! $want || in_array( $f['meta_key'], $want, true ) );
		} );
		$code               = $this->roleField( $type, 'code' );
		$codeValue          = $code ? $this->c->items()->value( $id, $code['meta_key'] ) : '';
		$kicker             = implode( ' · ', array_filter( array( $top ? $top['name'] : '', $leaf && ( ! $top || $leaf['id'] !== $top['id'] ) ? $leaf['name'] : '' ) ) );

		ob_start();
		?>
		<article class="dwcat-detail" data-dwcat-kicker="<?php echo Html::attr( $kicker ); ?>">
			<a class="dwcat-back" href="<?php echo Html::url( $ctx->listUrl( $this->params( $state, $a ) ) ); ?>" data-dwcat-link>&larr; <?php echo Html::e( $this->t( 'Back to list' ) ); ?></a>
			<div class="dwcat-detail-top">
				<div class="dwcat-detail-media">
					<?php if ( $images ) : ?>
						<div class="dwcat-detail-main" data-dwcat-main><?php echo $this->c->images()->tag( $images[0], 'large', '(max-width: 900px) 100vw, 600px', false ); ?></div>
						<?php if ( count( $images ) > 1 ) : ?>
							<div class="dwcat-detail-thumbs">
								<?php foreach ( $images as $i => $img ) : ?>
									<button type="button" class="dwcat-thumb<?php echo 0 === $i ? ' is-active' : ''; ?>" data-dwcat-thumb="<?php echo Html::attr( $this->c->images()->url( $img, 'large' ) ); ?>" aria-label="<?php echo Html::attr( sprintf( $this->t( 'Image %d' ), $i + 1 ) ); ?>"><?php echo $this->c->images()->tag( $img, 'thumb' ); ?></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<div class="dwcat-detail-main"><span class="dwcat-ph"><?php echo $this->placeholder( 80 ); ?></span></div>
					<?php endif; ?>
				</div>
				<div class="dwcat-detail-info">
					<?php if ( '' !== $kicker ) : ?>
						<span class="dwcat-kicker"><?php echo Html::e( $kicker ); ?></span>
					<?php endif; ?>
					<h1 class="dwcat-detail-title"><?php echo Html::e( $item['title'] ); ?></h1>
					<?php
					$rows = '';
					foreach ( $fields as $f ) {
						$v = FieldConfig::display( $f, $this->c->items()->value( $id, $f['meta_key'] ) );
						if ( '' !== $v ) {
							$rows .= '<dt>' . Html::e( $f['label'] ) . '</dt><dd' . ( self::mono( $f ) ? ' class="is-mono"' : '' ) . '>' . ( 'textarea' === $f['type'] ? nl2br( Html::e( $v ) ) : Html::e( $v ) ) . '</dd>';
						}
					}
					if ( '' !== $rows ) {
						echo '<dl class="dwcat-detail-fields">' . $rows . '</dl>';
					}
					echo $this->variationsTable( $item );
					?>
					<div class="dwcat-actions">
						<?php if ( '' !== $codeValue ) : ?>
							<button type="button" class="dwcat-btn dwcat-btn-primary" data-dwcat-copy="<?php echo Html::attr( $codeValue ); ?>" data-copied="<?php echo Html::attr( $this->t( 'Copied' ) ); ?>"><?php echo Html::e( sprintf( $this->t( 'Copy %s' ), Text::lower( $code['label'] ) ) ); ?></button>
						<?php endif; ?>
						<?php if ( '' !== $a['inquiry_url'] ) : ?>
							<a class="dwcat-btn<?php echo '' === $codeValue ? ' dwcat-btn-primary' : ''; ?>" href="<?php echo Html::url( $a['inquiry_url'] . ( false === strpos( $a['inquiry_url'], '?' ) ? '?' : '&' ) . 'item=' . rawurlencode( $item['title'] ) ); ?>"><?php echo Html::e( '' !== $a['inquiry_label'] ? $a['inquiry_label'] : $this->t( 'Inquire about this item' ) ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php if ( '' !== trim( (string) $item['content'] ) ) : ?>
				<div class="dwcat-detail-description"><?php echo $this->c->host()->sanitizeHtml( self::paragraphs( (string) $item['content'] ) ); ?></div>
			<?php endif; ?>
			<?php echo $page ? $this->related( $item, $a, $ctx, $state ) : ''; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	private function variationsTable( array $item ): string {
		$rows = $this->c->items()->variations( (int) $item['id'] );
		if ( ! $rows ) {
			return '';
		}
		$cols = array();
		foreach ( $this->c->fields()->variationColumns( $item['type'] ) as $col ) {
			if ( ! VariationColumns::isPublic( $col ) ) {
				continue; // internal columns never reach the site
			}
			foreach ( $rows as $row ) {
				if ( isset( $row[ $col['key'] ] ) && '' !== (string) $row[ $col['key'] ] ) {
					$cols[] = $col;
					break;
				}
			}
		}
		if ( ! $cols ) {
			return '';
		}
		$html = '<div class="dwcat-variations"><h2 class="dwcat-section-title">' . Html::e( $this->t( 'Options' ) ) . '</h2><div class="dwcat-table-wrap"><table class="dwcat-table dwcat-variations-table"><thead><tr>';
		foreach ( $cols as $col ) {
			$html .= '<th scope="col">' . Html::e( $col['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $cols as $col ) {
				$v    = isset( $row[ $col['key'] ] ) ? (string) $row[ $col['key'] ] : '';
				$mono = (bool) preg_match( '/code|sku|pack|size/', $col['key'] );
				if ( 'select' === $col['type'] && '' !== $v ) {
					$opts = Text::parseOptions( $col['options'] );
					$v    = '<span class="dwcat-var-opt dwcat-var-' . Html::attr( $col['key'] . '-' . $v ) . '">' . Html::e( isset( $opts[ $v ] ) ? $opts[ $v ] : $v ) . '</span>';
				} else {
					$v = Html::e( $v );
				}
				$html .= '<td data-label="' . Html::attr( $col['label'] ) . '"' . ( $mono ? ' class="is-mono"' : '' ) . '>' . $v . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div></div>';
	}

	private function related( array $item, array $a, Context $ctx, array $state ): string {
		$count = (int) $a['related'];
		$terms = array_map( 'intval', array_column( $this->c->terms()->ofItem( $item['type'], (int) $item['id'] ), 'id' ) );
		if ( $count < 1 || ! $terms ) {
			return '';
		}
		$result = $this->c->query()->run( $item['type'], array( 'cats' => $terms, 'exclude' => (string) $a['exclude'], 'public' => true, 'per_page' => $count + 1 ) );
		$rows   = array_slice( array_values( array_filter( $result['rows'], function ( $r ) use ( $item ) {
			return (int) $r['id'] !== (int) $item['id'];
		} ) ), 0, $count );
		if ( ! $rows ) {
			return '';
		}
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $item['type'], $ids );
		$fields = $this->cardFields( $item['type'], $a );
		$params = $this->params( $state, $a );
		$html   = '<section class="dwcat-related"><h2 class="dwcat-section-title">' . Html::e( $this->t( 'Related items' ) ) . '</h2><div class="dwcat-grid" style="--dwcat-cols: ' . min( 4, $count ) . '; --dwcat-cols-t: ' . min( 3, $count ) . '; --dwcat-cols-m: 2;">';
		foreach ( $rows as $r ) {
			$html .= $this->card( $r, $fields, $ctx->itemUrl( $r['slug'], $params ), array_merge( $a, array( 'show_category' => 'yes' ) ) );
		}
		return $html . '</div></section>';
	}

	/** Plain line breaks → paragraphs (descriptions typed without an editor). */
	private static function paragraphs( string $html ): string {
		if ( preg_match( '/<(p|div|ul|ol|h[1-6]|table)\b/i', $html ) ) {
			return $html;
		}
		$parts = preg_split( '/\n\s*\n/', trim( $html ) );
		return '<p>' . implode( '</p><p>', array_map( 'nl2br', $parts ) ) . '</p>';
	}

	// ─────────────────────────────────────────────────────────────
	// SEO (host prints these in <head>)
	// ─────────────────────────────────────────────────────────────

	/** @return array title, description, canonical, image, json_ld */
	public function seo( array $item, Context $ctx ): array {
		$id    = (int) $item['id'];
		$img   = $this->c->images()->main( $id );
		$desc  = '' !== trim( (string) $item['excerpt'] ) ? Text::summary( (string) $item['excerpt'] ) : Text::summary( (string) $item['content'] );
		$url   = $ctx->itemUrl( $item['slug'] );
		$ld    = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $item['title'],
			'url'      => $url,
		);
		if ( '' !== $desc ) {
			$ld['description'] = $desc;
		}
		if ( $img ) {
			$ld['image'] = $this->c->images()->url( $img, 'large' );
		}
		foreach ( array( 'code' => 'sku', 'brand' => 'brand' ) as $role => $prop ) {
			$f = $this->roleField( $item['type'], $role );
			$v = $f ? $this->c->items()->value( $id, $f['meta_key'] ) : '';
			if ( '' !== $v ) {
				$ld[ $prop ] = 'brand' === $prop ? array( '@type' => 'Brand', 'name' => $v ) : $v;
			}
		}
		return array(
			'title'       => $item['title'],
			'description' => $desc,
			'canonical'   => $url,
			'image'       => $img ? $this->c->images()->url( $img, 'large' ) : '',
			'json_ld'     => $ld,
		);
	}

	// ─────────────────────────────────────────────────────────────
	// [dw_wholesale_search] — header search box
	// ─────────────────────────────────────────────────────────────

	/**
	 * @param array   $atts   placeholder, size (large|small), suggest (comma words → autocomplete), design
	 * @param Context $target the catalog page the search goes to
	 */
	public function search( array $atts, Context $target ): string {
		$a     = array_merge( array( 'placeholder' => '', 'size' => 'small', 'suggest' => '', 'design' => '', 'label' => '' ), $atts );
		$size  = 'large' === $a['size'] ? 'large' : 'small';
		$id    = 'dwcat-hs-' . substr( md5( json_encode( $a ) ), 0, 6 );
		$words = array_slice( array_filter( array_map( 'trim', explode( ',', (string) $a['suggest'] ) ), 'strlen' ), 0, 20 );
		$place = '' !== trim( (string) $a['placeholder'] ) ? (string) $a['placeholder'] : $this->t( 'Search by name, item code or brand' );
		$html  = '<form class="dwcat-block dwcat-hsearch dwcat-search dwcat-search-' . $size . '" role="search" method="get" action="' . Html::url( strtok( $target->listUrl(), '?' ) ) . '" style="' . Html::attr( Design::style( $this->c->settings(), (string) $a['design'] ) ) . '">';
		$html .= $this->pageQueryFields( $target );
		$html .= '<label for="' . $id . '" class="dwcat-sr">' . Html::e( '' !== $a['label'] ? $a['label'] : $this->t( 'Search catalog' ) ) . '</label>'
			. '<svg class="dwcat-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
			. '<input type="search" id="' . $id . '" name="' . self::P_SEARCH . '" placeholder="' . Html::attr( $place ) . '" maxlength="200"' . ( $words ? ' list="' . $id . '-list"' : '' ) . '>'
			. '<button type="submit" class="dwcat-search-btn">' . Html::e( $this->t( 'Search' ) ) . '</button>';
		if ( $words ) {
			$html .= '<datalist id="' . $id . '-list">';
			foreach ( $words as $w ) {
				$html .= '<option value="' . Html::attr( $w ) . '"></option>';
			}
			$html .= '</datalist>';
		}
		return $html . '</form>';
	}

	// ─────────────────────────────────────────────────────────────
	// Design preview (admin)
	// ─────────────────────────────────────────────────────────────

	/**
	 * A small catalog for the Design screen: search box, chips, buttons and four cards made from
	 * the first published products (placeholders when there are none). Same markup and CSS as the site.
	 */
	public function preview( string $preset = '' ): string {
		$type = $this->c->fields()->primaryType();
		$a    = $this->atts( array_merge( self::wholesaleDefaults(), array( 'design' => $preset ) ) );
		$rows = $this->c->query()->run( $type, array( 'public' => true, 'per_page' => 4, 'sort' => 'title_asc' ) )['rows'];
		$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $type, $ids );
		while ( count( $rows ) < 4 ) {
			$rows[] = array( 'id' => 0, 'type' => $type, 'slug' => 'sample', 'title' => sprintf( $this->t( 'Sample product %d' ), count( $rows ) + 1 ) );
		}
		$ctx    = new Context( '#', '' );
		$fields = $this->cardFields( $type, $a );
		$cards  = '';
		foreach ( $rows as $item ) {
			$cards .= $this->card( $item, $fields, '#', $a );
		}
		return '<div class="dwcat-catalog dwcat-mview-grid" data-dwc-preview-root style="' . Html::attr( Design::style( $this->c->settings(), $preset ) ) . '">'
			. '<div class="dwcat-head"><div class="dwcat-search dwcat-search-large"><svg class="dwcat-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" tabindex="-1" placeholder="' . Html::attr( $this->t( 'Search by name, item code or brand' ) ) . '"><button type="button" tabindex="-1" class="dwcat-search-btn">' . Html::e( $this->t( 'Search' ) ) . '</button></div></div>'
			. '<div class="dwcat-chips"><span class="dwcat-chip">' . Html::e( $this->t( 'In stock' ) ) . '<span aria-hidden="true">&times;</span></span><button type="button" tabindex="-1" class="dwcat-btn dwcat-btn-primary">' . Html::e( $this->t( 'Load more' ) ) . '</button><button type="button" tabindex="-1" class="dwcat-btn">' . Html::e( $this->t( 'Reset filters' ) ) . '</button></div>'
			. '<div class="dwcat-grid" style="--dwcat-cols: 4; --dwcat-cols-t: 3; --dwcat-cols-m: 2;">' . $cards . '</div></div>';
	}

	// ─────────────────────────────────────────────────────────────
	// Grid / carousel blocks
	// ─────────────────────────────────────────────────────────────

	/**
	 * @param array   $atts category, per_page, columns, ids, show_fields, orderby (date|title), order, design
	 * @param Context $link context of the catalog page items link to
	 */
	public function grid( array $atts, Context $link, bool $carousel = false ): string {
		$a    = array_merge( array( 'type' => '', 'category' => '', 'per_page' => 12, 'columns' => 0, 'per_slide' => 3, 'ids' => '', 'show_fields' => '', 'orderby' => 'date', 'order' => 'DESC', 'autoplay' => 'yes', 'interval' => 5000, 'design' => '' ), $atts );
		$type = $this->c->fields()->type( (string) $a['type'] ) ? (string) $a['type'] : $this->c->fields()->primaryType();
		$ids  = array_filter( array_map( 'intval', explode( ',', (string) $a['ids'] ) ) );
		$sort = $ids ? 'ids' : ( 'title' === $a['orderby'] ? 'title_' : 'date_' ) . ( 'ASC' === strtoupper( $a['order'] ) ? 'asc' : 'desc' );
		$res  = $this->c->query()->run( $type, array( 'base_cat' => $a['category'], 'ids' => $ids, 'sort' => $sort, 'public' => true, 'per_page' => (int) $a['per_page'] ) );
		if ( ! $res['rows'] ) {
			return '<p class="dwcat-empty">' . Html::e( $this->t( 'No items found.' ) ) . '</p>';
		}
		$rowIds = array_map( 'intval', array_column( $res['rows'], 'id' ) );
		$this->c->images()->primeItems( $rowIds );
		$this->c->terms()->primeItems( $type, $rowIds );
		$cardA  = array_merge( self::defaults(), array( 'show_fields' => (string) $a['show_fields'], 'card_list' => array() ) );
		$fields = $this->cardFields( $type, $cardA );
		$cols   = (int) $a['columns'] ?: Design::get( $this->c->settings(), (string) $a['design'] )['columns'];
		$style  = Html::attr( Design::style( $this->c->settings(), (string) $a['design'] ) );

		$cards = '';
		foreach ( $res['rows'] as $item ) {
			$card   = $this->card( $item, $fields, $link->itemUrl( $item['slug'] ), $cardA );
			$cards .= $carousel ? '<div class="dwcat-carousel-slide">' . $card . '</div>' : $card;
		}
		if ( ! $carousel ) {
			$cols = max( 1, min( 6, $cols ) );
			return '<div class="dwcat-block" style="' . $style . '"><div class="dwcat-grid" style="--dwcat-cols: ' . $cols . '; --dwcat-cols-t: ' . min( 3, $cols ) . '; --dwcat-cols-m: ' . min( 2, $cols ) . ';">' . $cards . '</div></div>';
		}
		$per = max( 1, min( 6, (int) $a['per_slide'] ) );
		return '<div class="dwcat-block dwcat-carousel" data-autoplay="' . ( 'yes' === $a['autoplay'] ? '1' : '0' ) . '" data-interval="' . max( 1000, (int) $a['interval'] ) . '" data-per-slide="' . $per . '" style="--dwcat-cols: ' . $per . ';' . $style . '">'
			. '<button type="button" class="dwcat-carousel-btn dwcat-prev" aria-label="' . Html::attr( $this->t( 'Previous' ) ) . '">&#10094;</button>'
			. '<div class="dwcat-carousel-viewport"><div class="dwcat-carousel-track">' . $cards . '</div></div>'
			. '<button type="button" class="dwcat-carousel-btn dwcat-next" aria-label="' . Html::attr( $this->t( 'Next' ) ) . '">&#10095;</button></div>';
	}
}
