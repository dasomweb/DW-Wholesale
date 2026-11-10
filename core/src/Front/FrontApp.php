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
 *   catalog()   head (title · count · search · top-level category tabs), left filters
 *               (category groups · field filters), toolbar (count · sort · grid/list · per page),
 *               grid or list, pagination — or an item's detail when one is requested.
 *               catalog.js opens items in a side drawer; a direct item URL renders the full page.
 *   grid() / carousel()   compact blocks linking to the catalog page.
 *
 * List state lives in the URL, so every state is a shareable link and works without JS:
 *   dwq search · dwt tab (top-level category) · dwc[] sub-categories · dwf[key][] field filters ·
 *   dws sort · dwv view (grid|list) · dwn per page · dwp page
 */
class FrontApp {

	const P_SEARCH = 'dwq';
	const P_TAB    = 'dwt';
	const P_CATS   = 'dwc';
	const P_FIELDS = 'dwf';
	const P_SORT   = 'dws';
	const P_VIEW   = 'dwv';
	const P_NUM    = 'dwn';
	const P_PAGE   = 'dwp';

	/** Dot colours for top-level categories (tabs, badges, groups), by position. */
	const DOTS = array( '#1d4ed8', '#0f766e', '#7c3aed', '#b45309', '#64748b', '#be185d', '#0369a1', '#4d7c0f' );

	/** @var Catalog */
	private $c;

	public function __construct( Catalog $c ) {
		$this->c = $c;
	}

	public static function defaults(): array {
		return array(
			'type'          => '',
			'category'      => '',
			'title'         => '',
			'columns'       => 0,
			'per_page'      => 20,
			'show_fields'   => '',
			'detail_fields' => '',
			'filter_fields' => '',
			'search'        => 'yes',
			'sort'          => 'yes',
			'tabs'          => 'yes',
			'default_sort'  => 'date_desc',
			'default_view'  => 'grid',
			'show_category' => 'yes',
			'related'       => 4,
			'inquiry_url'   => '',
			'inquiry_label' => '',
		);
	}

	private function atts( array $atts ): array {
		$a = array_merge( self::defaults(), array_intersect_key( $atts, self::defaults() ) );
		$a['type'] = $this->c->fields()->type( (string) $a['type'] ) ? (string) $a['type'] : $this->c->fields()->primaryType();
		if ( ! (int) $a['columns'] ) {
			$a['columns'] = Design::get( $this->c->settings() )['columns'];
		}
		$a['per_page']     = max( 1, min( 100, (int) $a['per_page'] ) );
		$a['default_view'] = 'list' === $a['default_view'] ? 'list' : 'grid';
		return $a;
	}

	private function t( string $s ): string {
		return $this->c->frontText( $s );
	}

	// ─────────────────────────────────────────────────────────────
	// [dw_catalog]
	// ─────────────────────────────────────────────────────────────

	public function catalog( array $atts, Context $ctx ): string {
		$a     = $this->atts( $atts );
		$state = $this->state( $a, $ctx->query );
		$item  = $this->requestedItem( $a, $ctx );
		if ( '' !== $ctx->requestedItem() && ! $item ) {
			$html = '<div class="dwcat-empty"><p>' . Html::e( $this->t( 'This item is not available.' ) ) . '</p>'
				. '<p><a class="dwcat-back" href="' . Html::url( $ctx->listUrl() ) . '" data-dwcat-link>&larr; ' . Html::e( $this->t( 'Back to list' ) ) . '</a></p></div>';
		} else {
			$html = $item ? $this->detail( $item, $a, $ctx, $state, true ) : $this->listView( $a, $ctx, $state );
		}
		return '<div class="dwcat-catalog alignwide" data-dwcat-catalog style="' . Html::attr( Design::style( $this->c->settings() ) ) . '">' . $html . '</div>';
	}

	/**
	 * The published item this request asks for, within the page's catalog type and category.
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
		if ( '' !== trim( $a['category'] ) ) {
			$allowed = $this->c->terms()->withDescendants( $a['type'], $this->c->terms()->resolve( $a['type'], $a['category'] ) );
			$mine    = array_map( 'intval', array_column( $this->c->terms()->ofItem( $a['type'], (int) $item['id'] ), 'id' ) );
			if ( ! array_intersect( $allowed, $mine ) ) {
				return null;
			}
		}
		return $item;
	}

	/** Root of the tab bar: the page's fixed category, or 0 (whole catalog). */
	private function root( array $a ): int {
		if ( '' === trim( $a['category'] ) ) {
			return 0;
		}
		$ids = $this->c->terms()->resolve( $a['type'], $a['category'] );
		return $ids ? $ids[0] : -1;
	}

	private function state( array $a, array $q ): array {
		$state = array( 'search' => '', 'tab' => 0, 'cats' => array(), 'meta' => array(), 'sort' => $a['default_sort'], 'view' => $a['default_view'], 'num' => $a['per_page'], 'page' => 1 );
		if ( isset( $q[ self::P_SEARCH ] ) && is_string( $q[ self::P_SEARCH ] ) ) {
			$state['search'] = Text::line( $q[ self::P_SEARCH ], 200 );
		}
		if ( isset( $q[ self::P_TAB ] ) ) {
			$tab  = (int) $q[ self::P_TAB ];
			$tree = $this->c->terms()->tree( $a['type'] );
			foreach ( isset( $tree[ $this->root( $a ) ] ) ? $tree[ $this->root( $a ) ] : array() as $t ) {
				if ( (int) $t['id'] === $tab ) {
					$state['tab'] = $tab; // only a real tab of this page
				}
			}
		}
		if ( isset( $q[ self::P_CATS ] ) ) {
			$state['cats'] = array_slice( array_values( array_filter( array_map( 'intval', (array) $q[ self::P_CATS ] ) ) ), 0, 50 );
		}
		if ( isset( $q[ self::P_FIELDS ] ) && is_array( $q[ self::P_FIELDS ] ) ) {
			$allowed = $this->filterKeys( $a );
			foreach ( $q[ self::P_FIELDS ] as $key => $values ) {
				if ( in_array( $key, $allowed, true ) ) {
					$state['meta'][ $key ] = array_slice( array_map( function ( $v ) {
						return Text::line( $v, 191 );
					}, array_filter( (array) $values, 'is_scalar' ) ), 0, 50 );
				}
			}
		}
		if ( isset( $q[ self::P_SORT ] ) && is_string( $q[ self::P_SORT ] ) && isset( $this->c->query()->sortOptions( $a['type'], true )[ $q[ self::P_SORT ] ] ) ) {
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

	/** Sidebar filter keys — public fields only. */
	private function filterKeys( array $a ): array {
		$public = $this->c->fields()->fieldsByKey( $a['type'], true );
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $a['filter_fields'] ) ), function ( $k ) use ( $public ) {
			return isset( $public[ $k ] );
		} ) );
	}

	// ─────────────────────────────────────────────────────────────
	// List view
	// ─────────────────────────────────────────────────────────────

	private function listView( array $a, Context $ctx, array $state ): string {
		$type = $a['type'];
		$root = $this->root( $a );
		if ( $root < 0 ) {
			return '<p class="dwcat-empty">' . Html::e( $this->t( 'No items found.' ) ) . '</p>';
		}
		$result = $this->c->query()->run( $type, array(
			'search'   => $state['search'],
			'cats'     => $state['cats'],
			'base_cat' => $state['tab'] ? (string) $state['tab'] : $a['category'],
			'meta'     => $state['meta'],
			'sort'     => $state['sort'],
			'public'   => true,
			'page'     => $state['page'],
			'per_page' => $state['num'],
		) );
		$ids = array_map( 'intval', array_column( $result['rows'], 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $type, $ids );

		$params   = $this->params( $state, $a );
		$fields   = $this->cardFields( $type, $a['show_fields'] );
		$filtered = '' !== $state['search'] || $state['cats'] || $state['meta'];
		$from     = $result['total'] ? ( $result['page'] - 1 ) * $state['num'] + 1 : 0;
		$to       = min( $result['total'], $from + count( $result['rows'] ) - 1 );
		$form     = 'dwcat-filter-form';

		ob_start();
		?>
		<div class="dwcat-head">
			<?php if ( '' !== trim( $a['title'] ) ) : ?>
				<h1 class="dwcat-h1"><?php echo Html::e( $a['title'] ); ?></h1>
			<?php endif; ?>
			<p class="dwcat-sub"><?php echo Html::e( $this->summary( $a, $root ) ); ?></p>
			<?php if ( 'yes' === $a['search'] ) : ?>
				<div class="dwcat-search" role="search">
					<label for="dwcat-search" class="dwcat-sr"><?php echo Html::e( $this->t( 'Search catalog' ) ); ?></label>
					<svg class="dwcat-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
					<input type="search" id="dwcat-search" name="<?php echo self::P_SEARCH; ?>" form="<?php echo $form; ?>" value="<?php echo Html::attr( $state['search'] ); ?>" placeholder="<?php echo Html::attr( $this->t( 'Search by name, item code or brand' ) ); ?>" maxlength="200">
					<button type="submit" form="<?php echo $form; ?>" class="dwcat-search-btn"><?php echo Html::e( $this->t( 'Search' ) ); ?></button>
				</div>
			<?php endif; ?>
			<?php echo $this->tabs( $a, $ctx, $state, $root ); ?>
		</div>

		<div class="dwcat-layout">
			<aside class="dwcat-sidebar" aria-label="<?php echo Html::attr( $this->t( 'Filters' ) ); ?>" data-dwcat-sidebar>
				<div class="dwcat-sheet-head">
					<strong><?php echo Html::e( $this->t( 'Filters' ) ); ?></strong>
					<button type="button" class="dwcat-icon-btn" data-dwcat-toggle aria-label="<?php echo Html::attr( $this->t( 'Close filters' ) ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				</div>
				<form id="<?php echo $form; ?>" class="dwcat-filter-form" method="get" action="<?php echo Html::url( $ctx->listUrl() ); ?>" data-dwcat-form>
					<?php if ( $state['tab'] ) : ?>
						<input type="hidden" name="<?php echo self::P_TAB; ?>" value="<?php echo (int) $state['tab']; ?>">
					<?php endif; ?>
					<?php if ( $state['view'] !== $a['default_view'] ) : ?>
						<input type="hidden" name="<?php echo self::P_VIEW; ?>" value="<?php echo Html::attr( $state['view'] ); ?>">
					<?php endif; ?>
					<?php if ( 'yes' !== $a['sort'] && $state['sort'] !== $a['default_sort'] ) : ?>
						<input type="hidden" name="<?php echo self::P_SORT; ?>" value="<?php echo Html::attr( $state['sort'] ); ?>">
					<?php endif; ?>
					<?php echo $this->categoryFilter( $a, $state, $root, $ctx ); ?>
					<?php echo $this->fieldFilters( $a, $state ); ?>
					<noscript><button type="submit" class="dwcat-btn"><?php echo Html::e( $this->t( 'Apply' ) ); ?></button></noscript>
					<?php if ( $filtered ) : ?>
						<a class="dwcat-btn dwcat-btn-block" href="<?php echo Html::url( $ctx->listUrl( $this->params( $state, $a, array( self::P_SEARCH, self::P_CATS, self::P_FIELDS, self::P_PAGE ) ) ) ); ?>" data-dwcat-link><?php echo Html::e( $this->t( 'Reset filters' ) ); ?></a>
					<?php endif; ?>
					<p class="dwcat-note"><?php echo Html::e( $this->t( 'Filters apply instantly — no Apply button needed.' ) ); ?></p>
				</form>
			</aside>

			<div class="dwcat-main">
				<div class="dwcat-toolbar">
					<button type="button" class="dwcat-pill dwcat-filter-toggle" data-dwcat-toggle aria-expanded="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg><?php echo Html::e( $this->t( 'Filters' ) ); ?>
					</button>
					<p class="dwcat-count">
						<?php if ( $result['total'] ) : ?>
							<?php echo sprintf( Html::e( $this->t( 'Showing %1$s of %2$s items' ) ), '<strong>' . (int) $from . '–' . (int) $to . '</strong>', '<strong>' . (int) $result['total'] . '</strong>' ); ?>
						<?php else : ?>
							<?php echo Html::e( $this->t( 'No items' ) ); ?>
						<?php endif; ?>
					</p>
					<div class="dwcat-tools">
						<?php if ( 'yes' === $a['sort'] ) : ?>
							<label class="dwcat-sort"><span><?php echo Html::e( $this->t( 'Sort by' ) ); ?></span>
								<select name="<?php echo self::P_SORT; ?>" form="<?php echo $form; ?>" data-default="<?php echo Html::attr( $a['default_sort'] ); ?>">
									<?php foreach ( $this->c->query()->sortOptions( $type, true ) as $key => $label ) : ?>
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

				<?php if ( ! $result['rows'] ) : ?>
					<p class="dwcat-empty"><?php echo Html::e( $this->t( 'No items match your filters.' ) ); ?></p>
				<?php elseif ( 'list' === $state['view'] ) : ?>
					<?php echo $this->table( $result['rows'], $fields, $ctx, $params, $a ); ?>
				<?php else : ?>
					<div class="dwcat-grid" style="--dwcat-cols: <?php echo max( 1, min( 6, (int) $a['columns'] ) ); ?>;">
						<?php foreach ( $result['rows'] as $i => $item ) : ?>
							<?php echo $this->card( $item, $fields, $ctx->itemUrl( $item['slug'], $params ), 'yes' === $a['show_category'], $i < (int) $a['columns'] ); ?>
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

	/** "343 items across Frozen, Fresh, …" (published items under this page). */
	private function summary( array $a, int $root ): string {
		$total = $this->c->query()->run( $a['type'], array( 'base_cat' => $a['category'], 'public' => true, 'per_page' => 1 ) )['total'];
		$tree  = $this->c->terms()->tree( $a['type'] );
		$names = array_column( isset( $tree[ $root ] ) ? $tree[ $root ] : array(), 'name' );
		$text  = sprintf( 1 === $total ? $this->t( '%d item' ) : $this->t( '%d items' ), $total );
		if ( $names && count( $names ) <= 8 ) {
			$last  = array_pop( $names );
			$text .= ' ' . sprintf( $this->t( 'across %s' ), $names ? implode( ', ', $names ) . ' ' . $this->t( 'and' ) . ' ' . $last : $last ) . '.';
		}
		return $text;
	}

	private function tabs( array $a, Context $ctx, array $state, int $root ): string {
		$tree = $this->c->terms()->tree( $a['type'] );
		if ( 'yes' !== $a['tabs'] || empty( $tree[ $root ] ) ) {
			return '';
		}
		$html = '<nav class="dwcat-tabs" aria-label="' . Html::attr( $this->t( 'Categories' ) ) . '">';
		$all  = $this->params( array_merge( $state, array( 'tab' => 0, 'cats' => array() ) ), $a, array( self::P_PAGE ) );
		$html .= '<a class="dwcat-tab' . ( ! $state['tab'] ? ' is-on' : '' ) . '" href="' . Html::url( $ctx->listUrl( $all ) ) . '"' . ( ! $state['tab'] ? ' aria-current="page"' : '' ) . ' data-dwcat-link><i style="background:#0f1b2d" aria-hidden="true"></i>' . Html::e( $this->t( 'All' ) ) . '</a>';
		foreach ( $tree[ $root ] as $i => $term ) {
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
		$html = '<nav class="dwcat-pagination" aria-label="' . Html::attr( $this->t( 'Pages' ) ) . '"><div class="dwcat-pages">';
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

	/**
	 * Category groups: each tab-level category is a collapsible group of its sub-categories.
	 * With a tab selected, only that tab's sub-categories are listed.
	 */
	private function categoryFilter( array $a, array $state, int $root, Context $ctx ): string {
		$config = $this->c->fields()->type( $a['type'] );
		if ( empty( $config['has_category'] ) ) {
			return '';
		}
		$tree   = $this->c->terms()->tree( $a['type'] );
		$counts = $this->c->terms()->counts( $a['type'] );
		$total  = function ( $id ) use ( &$total, $tree, $counts ) {
			$n = isset( $counts[ $id ] ) ? $counts[ $id ] : 0;
			foreach ( isset( $tree[ $id ] ) ? $tree[ $id ] : array() as $child ) {
				$n += $total( (int) $child['id'] );
			}
			return $n;
		};
		$checks = function ( $parent, $depth = 0 ) use ( &$checks, $tree, $state, $total ) {
			$html = '';
			foreach ( isset( $tree[ $parent ] ) ? $tree[ $parent ] : array() as $t ) {
				$n = $total( (int) $t['id'] );
				if ( ! $n ) {
					continue;
				}
				$html .= '<li><label class="dwcat-check"' . ( $depth ? ' style="padding-left:' . ( 16 * $depth ) . 'px"' : '' ) . '><input type="checkbox" name="' . self::P_CATS . '[]" value="' . (int) $t['id'] . '"' . ( in_array( (int) $t['id'], $state['cats'], true ) ? ' checked' : '' ) . '><span>' . Html::e( $t['name'] ) . '</span><em>' . (int) $n . '</em></label></li>'
					. $checks( (int) $t['id'], $depth + 1 );
			}
			return $html;
		};

		$clear  = $state['cats'] ? '<a class="dwcat-link" href="' . Html::url( $ctx->listUrl( $this->params( $state, $a, array( self::P_CATS, self::P_PAGE ) ) ) ) . '" data-dwcat-link>' . Html::e( $this->t( 'Clear' ) ) . '</a>' : '';
		$html   = '<section class="dwcat-panel"><div class="dwcat-panel-head"><h2>' . Html::e( $this->t( 'Categories' ) ) . '</h2>' . $clear . '</div>';
		$groups = $state['tab'] ? array( array( 'id' => $state['tab'], 'only' => true ) ) : ( isset( $tree[ $root ] ) ? $tree[ $root ] : array() );
		$body   = '';
		foreach ( $groups as $i => $g ) {
			$gid = (int) $g['id'];
			$n   = $total( $gid );
			if ( ! $n ) {
				continue;
			}
			$subs = $checks( $gid );
			if ( ! empty( $g['only'] ) ) {
				$body .= '' !== $subs ? '<ul class="dwcat-checklist">' . $subs . '</ul>' : '';
				continue;
			}
			if ( '' === $subs ) { // no children: the group itself is the filter
				$body .= '<ul class="dwcat-checklist"><li><label class="dwcat-check dwcat-check-top"><input type="checkbox" name="' . self::P_CATS . '[]" value="' . $gid . '"' . ( in_array( $gid, $state['cats'], true ) ? ' checked' : '' ) . '><i style="background:' . self::DOTS[ $i % count( self::DOTS ) ] . '" aria-hidden="true"></i><span>' . Html::e( $g['name'] ) . '</span><em>' . (int) $n . '</em></label></li></ul>';
				continue;
			}
			$open  = (bool) array_intersect( $state['cats'], $this->c->terms()->withDescendants( $a['type'], array( $gid ) ) );
			$body .= '<details class="dwcat-group"' . ( $open ? ' open' : '' ) . '><summary><i style="background:' . self::DOTS[ $i % count( self::DOTS ) ] . '" aria-hidden="true"></i><span>' . Html::e( $g['name'] ) . '</span><em>' . (int) $n . '</em><svg class="dwcat-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary><ul class="dwcat-checklist">' . $subs . '</ul></details>';
		}
		return '' !== $body ? $html . $body . '</section>' : '';
	}

	private function fieldFilters( array $a, array $state ): string {
		$fields = $this->c->fields()->fieldsByKey( $a['type'], true );
		$html   = '';
		$base   = $state['tab'] ? (string) $state['tab'] : $a['category'];
		foreach ( $this->filterKeys( $a ) as $key ) {
			$choices = $this->c->query()->filterChoices( $a['type'], $fields[ $key ], $base );
			if ( ! $choices ) {
				continue;
			}
			$sel   = isset( $state['meta'][ $key ] ) ? $state['meta'][ $key ] : array();
			$html .= '<section class="dwcat-panel"><div class="dwcat-panel-head"><h2>' . Html::e( $fields[ $key ]['label'] ) . '</h2></div><ul class="dwcat-checklist' . ( count( $choices ) > 8 ? ' is-collapsible' : '' ) . '">';
			$i     = 0;
			foreach ( $choices as $value => $label ) {
				$checked = in_array( (string) $value, $sel, true );
				$html   .= '<li' . ( $i++ >= 8 && ! $checked ? ' class="dwcat-more"' : '' ) . '><label class="dwcat-check"><input type="checkbox" name="' . self::P_FIELDS . '[' . Html::attr( $key ) . '][]" value="' . Html::attr( $value ) . '"' . ( $checked ? ' checked' : '' ) . '><span>' . Html::e( $label ) . '</span></label></li>';
			}
			$html .= '</ul>';
			if ( count( $choices ) > 8 ) {
				$html .= '<button type="button" class="dwcat-link" data-dwcat-more>' . Html::e( sprintf( $this->t( 'Show all %s' ), Text::lower( $fields[ $key ]['label'] ) ) ) . '</button>';
			}
			$html .= '</section>';
		}
		return $html;
	}

	// ─────────────────────────────────────────────────────────────
	// Card / list row
	// ─────────────────────────────────────────────────────────────

	/** Card fields — explicit keys or the "List" fields; public only, never the name field or the brand (shown in the kicker). */
	private function cardFields( string $type, string $keys ): array {
		$brand = $this->roleField( $type, 'brand' );
		$all   = array_filter( $this->c->fields()->fields( $type ), function ( $f ) use ( $brand ) {
			return FieldConfig::isPublic( $f ) && empty( $f['is_title_field'] ) && ( ! $brand || $f['meta_key'] !== $brand['meta_key'] );
		} );
		$want  = array_filter( array_map( 'trim', explode( ',', $keys ) ) );
		return array_values( array_filter( $all, function ( $f ) use ( $want ) {
			return $want ? in_array( $f['meta_key'], $want, true ) : ! empty( $f['show_in_list'] );
		} ) );
	}

	/** A public field playing a role (brand, item code). */
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

	private function kicker( array $item, ?array $leaf ): string {
		$parts = array();
		if ( $leaf ) {
			$parts[] = $leaf['name'];
		}
		$brand = $this->roleField( $item['type'], 'brand' );
		if ( $brand ) {
			$v = $this->c->items()->value( (int) $item['id'], $brand['meta_key'] );
			if ( '' !== $v ) {
				$parts[] = $v;
			}
		}
		return implode( ' · ', $parts );
	}

	private function placeholder( int $size ): string {
		return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-5 9 5-9 5-9-5z"/><path d="M3 9v6l9 5 9-5V9"/><path d="M12 14v6"/></svg>';
	}

	/** $eager: first row — load immediately (it is what the visitor sees first). */
	private function card( array $item, array $fields, string $url, bool $showCategory, bool $eager = false ): string {
		$id                       = (int) $item['id'];
		$img                      = $this->c->images()->main( $id );
		list( $top, $leaf, $dot ) = $this->placement( $item );

		$html  = '<article class="dwcat-card"><div class="dwcat-card-media">';
		// Cards are ~300px on PC, ~half the screen on phones → the 600px file covers both at 2×.
		$html .= $img ? $this->c->images()->tag( $img, 'medium', '(max-width: 600px) 50vw, 300px', ! $eager ) : '<span class="dwcat-ph">' . $this->placeholder( 56 ) . '</span>';
		if ( $showCategory && $top ) {
			$html .= '<span class="dwcat-badge"><i style="background:' . self::DOTS[ $dot % count( self::DOTS ) ] . '" aria-hidden="true"></i>' . Html::e( $top['name'] ) . '</span>';
		}
		$html .= '</div><div class="dwcat-card-body">';
		$kick  = $this->kicker( $item, $leaf );
		if ( '' !== $kick ) {
			$html .= '<span class="dwcat-kicker">' . Html::e( $kick ) . '</span>';
		}
		$html .= '<h3 class="dwcat-card-title"><a href="' . Html::url( $url ) . '" class="dwcat-card-link">' . Html::e( $item['title'] ) . '</a></h3>';
		$rows  = '';
		$inline = array();
		foreach ( $fields as $f ) {
			$v = FieldConfig::display( $f, $this->c->items()->value( $id, $f['meta_key'] ) );
			if ( '' !== $v ) {
				$rows    .= '<dt>' . Html::e( $f['label'] ) . '</dt><dd' . ( self::mono( $f ) ? ' class="is-mono"' : '' ) . '>' . Html::e( $v ) . '</dd>';
				$inline[] = Html::e( $v );
			}
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
			$id                 = (int) $item['id'];
			$img                = $this->c->images()->main( $id );
			list( , $leaf )     = $this->placement( $item );
			$kick               = $this->kicker( $item, $leaf );
			$html              .= '<tr><td><div class="dwcat-row-product"><span class="dwcat-row-thumb">' . ( $img ? $this->c->images()->tag( $img, 'thumb' ) : $this->placeholder( 22 ) ) . '</span><span><a class="dwcat-card-link dwcat-row-title" href="' . Html::url( $ctx->itemUrl( $item['slug'], $params ) ) . '">' . Html::e( $item['title'] ) . '</a>' . ( '' !== $kick ? '<span class="dwcat-row-kicker">' . Html::e( $kick ) . '</span>' : '' ) . '</span></div></td>';
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
		$id                       = (int) $item['id'];
		$type                     = $item['type'];
		$images                   = $this->c->images()->forItem( $id );
		list( $top, $leaf )       = $this->placement( $item );
		$want                     = array_filter( array_map( 'trim', explode( ',', (string) $a['detail_fields'] ) ) );
		$fields                   = array_filter( $this->c->fields()->fields( $type ), function ( $f ) use ( $want ) {
			return FieldConfig::isPublic( $f ) && empty( $f['is_title_field'] ) && ( ! $want || in_array( $f['meta_key'], $want, true ) );
		} );
		$code                     = $this->roleField( $type, 'code' );
		$codeValue                = $code ? $this->c->items()->value( $id, $code['meta_key'] ) : '';
		$kicker                   = implode( ' · ', array_filter( array( $top ? $top['name'] : '', $leaf && ( ! $top || $leaf['id'] !== $top['id'] ) ? $leaf['name'] : '' ) ) );

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
		$result = $this->c->query()->run( $item['type'], array( 'cats' => $terms, 'public' => true, 'per_page' => $count + 1 ) );
		$rows   = array_slice( array_values( array_filter( $result['rows'], function ( $r ) use ( $item ) {
			return (int) $r['id'] !== (int) $item['id'];
		} ) ), 0, $count );
		if ( ! $rows ) {
			return '';
		}
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$this->c->images()->primeItems( $ids );
		$this->c->terms()->primeItems( $item['type'], $ids );
		$fields = $this->cardFields( $item['type'], (string) $a['show_fields'] );
		$params = $this->params( $state, $a );
		$html   = '<section class="dwcat-related"><h2 class="dwcat-section-title">' . Html::e( $this->t( 'Related items' ) ) . '</h2><div class="dwcat-grid" style="--dwcat-cols: ' . min( 4, $count ) . ';">';
		foreach ( $rows as $r ) {
			$html .= $this->card( $r, $fields, $ctx->itemUrl( $r['slug'], $params ), true );
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
	// Grid / carousel blocks
	// ─────────────────────────────────────────────────────────────

	/**
	 * @param array   $atts category, per_page, columns, ids, show_fields, orderby (date|title), order
	 * @param Context $link context of the catalog page items link to
	 */
	public function grid( array $atts, Context $link, bool $carousel = false ): string {
		$a    = array_merge( array( 'type' => '', 'category' => '', 'per_page' => 12, 'columns' => 0, 'per_slide' => 3, 'ids' => '', 'show_fields' => '', 'orderby' => 'date', 'order' => 'DESC', 'autoplay' => 'yes', 'interval' => 5000 ), $atts );
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
		$fields = $this->cardFields( $type, (string) $a['show_fields'] );
		$cols   = (int) $a['columns'] ?: Design::get( $this->c->settings() )['columns'];
		$style  = Html::attr( Design::style( $this->c->settings() ) );

		$cards = '';
		foreach ( $res['rows'] as $item ) {
			$card   = $this->card( $item, $fields, $link->itemUrl( $item['slug'] ), true );
			$cards .= $carousel ? '<div class="dwcat-carousel-slide">' . $card . '</div>' : $card;
		}
		if ( ! $carousel ) {
			return '<div class="dwcat-block" style="' . $style . '"><div class="dwcat-grid" style="--dwcat-cols: ' . max( 1, min( 6, $cols ) ) . ';">' . $cards . '</div></div>';
		}
		$per = max( 1, min( 6, (int) $a['per_slide'] ) );
		return '<div class="dwcat-block dwcat-carousel" data-autoplay="' . ( 'yes' === $a['autoplay'] ? '1' : '0' ) . '" data-interval="' . max( 1000, (int) $a['interval'] ) . '" data-per-slide="' . $per . '" style="--dwcat-cols: ' . $per . ';' . $style . '">'
			. '<button type="button" class="dwcat-carousel-btn dwcat-prev" aria-label="' . Html::attr( $this->t( 'Previous' ) ) . '">&#10094;</button>'
			. '<div class="dwcat-carousel-viewport"><div class="dwcat-carousel-track">' . $cards . '</div></div>'
			. '<button type="button" class="dwcat-carousel-btn dwcat-next" aria-label="' . Html::attr( $this->t( 'Next' ) ) . '">&#10095;</button></div>';
	}
}
