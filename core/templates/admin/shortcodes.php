<?php
/**
 * Shortcodes (M4): saved catalogs ([dw_wholesale_catalog id="…"]), the header search box and a
 * reference of every attribute.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array $widgets id => config
 * @var string $editing '' (list) | 'new' | widget id
 */
use DWCatalog\Front\Design;
use DWCatalog\Front\Widgets;
use DWCatalog\Support\Html;

$current = 'shortcodes';
$t       = function ( $s ) use ( $app ) {
	return Html::e( $app->t( $s ) );
};
$canEdit = $app->canEdit( 'shortcodes' );
$public  = array_filter( $c->fields()->fieldsByKey( $type, true ), function ( $f ) {
	return empty( $f['is_title_field'] );
} );
$tree    = $c->terms()->tree( $type );
$scopeOf = function ( array $w ) use ( $app, $c, $type ) {
	if ( 'all' === $w['scope'] ) {
		$text = $app->t( 'Whole catalog' );
	} else {
		$names = array();
		foreach ( 'group' === $w['scope'] ? $w['groups'] : $w['categories'] as $slug ) {
			$term    = $c->terms()->bySlug( $type, $slug );
			$names[] = $term ? $term['name'] : $slug;
		}
		$text = ( 'group' === $w['scope'] ? $app->t( 'Groups:' ) : $app->t( 'Categories:' ) ) . ' ' . ( $names ? implode( ', ', $names ) : '—' );
	}
	if ( $w['exclude'] ) {
		$text .= ' · ' . sprintf( $app->t( 'without %d' ), count( $w['exclude'] ) );
	}
	return $text;
};
?>
<?php echo $app->head( $app->t( 'Shortcodes' ), $canEdit && '' === $editing ? '<a class="dwc-btn dwc-btn-primary" href="' . Html::url( $app->url( array( 'view' => 'shortcodes', 'widget' => 'new' ) ) ) . '">+ ' . $t( 'New catalog' ) . '</a>' : '' ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php if ( '' === $editing ) : ?>

	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Saved catalogs' ); ?></h2>
		<p class="dwc-muted"><?php echo $t( 'Set up a catalog once — which products, filters, sorting, columns per device, card contents and design — then put its shortcode on any page.' ); ?></p>
		<?php if ( ! $widgets ) : ?>
			<p class="dwc-empty"><?php echo $t( 'No saved catalog yet.' ); ?></p>
		<?php else : ?>
			<div class="dwc-scroll">
				<table class="dwc-table">
					<thead><tr><th><?php echo $t( 'Name' ); ?></th><th><?php echo $t( 'Shows' ); ?></th><th><?php echo $t( 'Shortcode' ); ?></th><th><?php echo $t( 'Used on' ); ?></th><th class="dwc-r"></th></tr></thead>
					<tbody>
						<?php foreach ( $widgets as $id => $w ) : ?>
							<?php $pages = Widgets::pagesUsing( $c, $id ); ?>
							<tr>
								<td class="dwc-strong"><a href="<?php echo Html::url( $app->url( array( 'view' => 'shortcodes', 'widget' => $id ) ) ); ?>"><?php echo Html::e( $w['name'] ); ?></a></td>
								<td class="dwc-muted"><?php echo Html::e( $scopeOf( $w ) ); ?></td>
								<td><code class="dwc-code" data-dwc-copy-text>[dw_wholesale_catalog id="<?php echo Html::e( $id ); ?>"]</code></td>
								<td>
									<?php if ( ! $pages ) : ?>
										<span class="dwc-muted"><?php echo $t( 'Not on a page yet' ); ?></span>
									<?php else : ?>
										<?php foreach ( $pages as $i => $pg ) : ?><?php echo $i ? ', ' : ''; ?><a href="<?php echo Html::url( $pg['url'] ); ?>" target="_blank" rel="noopener"><?php echo Html::e( $pg['title'] ); ?></a><?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td class="dwc-r"><a class="dwc-rowlink" href="<?php echo Html::url( $app->url( array( 'view' => 'shortcodes', 'widget' => $id ) ) ); ?>"><?php echo $canEdit ? $t( 'Edit' ) : $t( 'Open' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Shortcodes' ); ?></h2>
		<table class="dwc-form">
			<?php
			$first    = isset( $tree[0][0] ) ? $tree[0][0]['slug'] : 'frozen';
			$second   = isset( $tree[0][1] ) ? $tree[0][1]['slug'] : 'dry-goods';
			$examples = array(
				array( $app->t( 'Whole catalog' ), '[dw_wholesale_catalog]' ),
				array( $app->t( 'One or more groups' ), '[dw_wholesale_catalog group="' . $first . ',' . $second . '"]' ),
				array( $app->t( 'Some categories, leaving others out' ), '[dw_wholesale_catalog categories="' . $first . '" exclude="…"]' ),
				array( $app->t( 'A saved catalog' ), '[dw_wholesale_catalog id="…"]' ),
				array( $app->t( 'Search box for the site header' ), '[dw_wholesale_search]' ),
				array( $app->t( 'Grid / carousel of products (2.x, still supported)' ), '[dw_catalog_grid per_page="8"] · [dw_catalog_carousel category="' . $first . '"]' ),
			);
			foreach ( $examples as $ex ) :
				?>
				<tr><th><?php echo Html::e( $ex[0] ); ?></th><td><code class="dwc-code"><?php echo Html::e( $ex[1] ); ?></code></td></tr>
			<?php endforeach; ?>
		</table>
		<p class="dwc-muted"><?php echo $t( 'Pages made with [dw_catalog] keep working as before.' ); ?></p>
	</div>

	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( '[dw_wholesale_catalog] options' ); ?></h2>
		<div class="dwc-scroll">
			<table class="dwc-table">
				<tbody>
					<?php
					$opts = array(
						'id'                => $app->t( 'A saved catalog. Other options on the shortcode override its settings.' ),
						'group / categories' => $app->t( 'Category slugs (comma-separated). Empty = the whole catalog.' ),
						'exclude'           => $app->t( 'Category slugs left out, with their sub-categories.' ),
						'search_size'       => 'large · small · hidden',
						'suggest'           => $app->t( 'Suggested search words, comma-separated.' ),
						'filters'           => 'category, brand, origin, stock, new, photo, ' . $app->t( 'field keys — in this order' ),
						'filter_position'   => 'sidebar · top',
						'sorts / default_sort' => 'title_asc, title_desc, code_asc, code_desc, brand_asc, date_desc, category_asc, manual',
						'per_page'          => '24 · 48 · 96',
						'columns / cols_tablet / cols_mobile' => $app->t( 'Columns on each device (4 / 3 / 2).' ),
						'per_page_tablet / per_page_mobile'   => $app->t( 'Products shown at first on tablets and phones; "Load more" shows the rest.' ),
						'mobile_view'       => 'grid · list',
						'card_fields'       => 'code, pack, brand, origin, category, price, ' . $app->t( 'field keys' ),
						'click'             => $app->t( 'drawer (side panel) · page (product page)' ),
						'design'            => $app->t( 'A design preset (Design screen).' ),
					);
					foreach ( $opts as $k => $v ) {
						echo '<tr><td class="dwc-nowrap"><code>' . Html::e( $k ) . '</code></td><td>' . Html::e( $v ) . '</td></tr>';
					}
					?>
				</tbody>
			</table>
		</div>
		<h2><?php echo $t( '[dw_wholesale_search] options' ); ?></h2>
		<p class="dwc-muted"><code>placeholder</code> · <code>size</code> (small · large) · <code>suggest</code> · <code>page</code> (<?php echo $t( 'page ID of the catalog it searches; default: the main catalog page' ); ?>) · <code>design</code></p>
		<h2><?php echo $t( 'Public field keys' ); ?></h2>
		<table class="dwc-table">
			<?php foreach ( $public as $key => $f ) : ?>
				<tr><td><code><?php echo Html::e( $key ); ?></code></td><td><?php echo Html::e( $f['label'] ); ?></td><td class="dwc-muted"><?php echo Html::e( $f['type'] ); ?></td></tr>
			<?php endforeach; ?>
		</table>
	</div>

<?php else : ?>
	<?php
	$isNew   = 'new' === $editing;
	$w       = $isNew ? Widgets::defaults() : $widgets[ $editing ];
	$tops    = isset( $tree[0] ) ? $tree[0] : array();
	$sorts   = $c->query()->sortOptions( $type, true );
	$filters = array(
		'category' => $app->t( 'Categories' ),
		'brand'    => $app->t( 'Brand' ),
		'origin'   => $app->t( 'Origin' ),
		'stock'    => $app->t( 'In stock' ),
		'new'      => $app->t( 'New in the last 30 days' ),
		'photo'    => $app->t( 'With photo' ),
	);
	$cards   = array(
		'code'     => $app->t( 'Item code' ),
		'pack'     => $app->t( 'Pack size' ),
		'brand'    => $app->t( 'Brand' ),
		'origin'   => $app->t( 'Origin' ),
		'category' => $app->t( 'Category badge' ),
		'price'    => $app->t( 'Price (lowest option price)' ),
	);
	foreach ( $public as $key => $f ) {
		$filters[ $key ] = $f['label'] . ' · ' . $key;
		$cards[ $key ]   = $f['label'] . ' · ' . $key;
	}
	// Chosen filters first, in their order; then the rest
	$ordered = array();
	foreach ( $w['filters'] as $k ) {
		if ( isset( $filters[ $k ] ) ) {
			$ordered[ $k ] = true;
		}
	}
	foreach ( $filters as $k => $lbl ) {
		if ( ! isset( $ordered[ $k ] ) ) {
			$ordered[ $k ] = false;
		}
	}
	$termList = function ( string $name, array $selected, bool $topOnly ) use ( $tree ) {
		$html = '';
		$walk = function ( $parent, $depth ) use ( &$walk, &$html, $tree, $name, $selected, $topOnly ) {
			foreach ( isset( $tree[ $parent ] ) ? $tree[ $parent ] : array() as $term ) {
				$html .= '<label class="dwc-check-line dwc-check-compact"' . ( $depth ? ' style="padding-left:' . ( 18 * $depth ) . 'px"' : '' ) . '><input type="checkbox" name="widget[' . $name . '][]" value="' . Html::attr( $term['slug'] ) . '"' . ( in_array( $term['slug'], $selected, true ) ? ' checked' : '' ) . '> <span>' . Html::e( $term['name'] ) . '</span></label>';
				if ( ! $topOnly ) {
					$walk( (int) $term['id'], $depth + 1 );
				}
			}
		};
		$walk( 0, 0 );
		return '' !== $html ? '<div class="dwc-termbox">' . $html . '</div>' : '';
	};
	$radio = function ( string $name, array $options, string $value ) {
		$html = '<div class="dwc-radios">';
		foreach ( $options as $val => $lbl ) {
			$html .= '<label><input type="radio" name="widget[' . $name . ']" value="' . Html::attr( $val ) . '"' . ( (string) $value === (string) $val ? ' checked' : '' ) . '> ' . Html::e( $lbl ) . '</label>';
		}
		return $html . '</div>';
	};
	$number = function ( string $name, int $value, int $min, int $max, string $label ) {
		return '<label class="dwc-field"><span>' . Html::e( $label ) . '</span><input type="number" name="widget[' . $name . ']" value="' . (int) $value . '" min="' . $min . '" max="' . $max . '" class="dwc-narrow-number"></label>';
	};
	?>
	<p><a href="<?php echo Html::url( $app->url( array( 'view' => 'shortcodes' ) ) ); ?>">&larr; <?php echo $t( 'All saved catalogs' ); ?></a></p>
	<?php if ( ! $isNew ) : ?>
		<div class="dwc-notice dwc-notice-info"><?php echo $t( 'Shortcode:' ); ?> <code>[dw_wholesale_catalog id="<?php echo Html::e( $editing ); ?>"]</code>
			<?php $pages = Widgets::pagesUsing( $c, $editing ); ?>
			<?php if ( $pages ) : ?> · <?php echo $t( 'Used on' ); ?> <?php foreach ( $pages as $i => $pg ) : ?><?php echo $i ? ', ' : ''; ?><a href="<?php echo Html::url( $pg['url'] ); ?>" target="_blank" rel="noopener"><?php echo Html::e( $pg['title'] ); ?></a><?php endforeach; ?><?php endif; ?>
		</div>
	<?php endif; ?>

	<?php echo $app->form( 'save_widget', $type, false, 'class="dwc-stack"' ); ?>
		<?php if ( ! $isNew ) : ?>
			<input type="hidden" name="id" value="<?php echo Html::attr( $editing ); ?>">
		<?php endif; ?>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Name' ); ?></h2>
			<div class="dwc-design-grid">
				<label class="dwc-field"><span><?php echo $t( 'Name (for you)' ); ?></span><input type="text" name="widget[name]" value="<?php echo Html::attr( $w['name'] ); ?>" maxlength="80" required></label>
				<?php if ( $isNew ) : ?>
					<label class="dwc-field"><span><?php echo $t( 'ID in the shortcode' ); ?></span><input type="text" name="id" value="" maxlength="40" pattern="[a-z0-9\-]*" class="dwc-mono" placeholder="<?php echo Html::attr( $app->t( 'from the name' ) ); ?>"></label>
				<?php endif; ?>
				<label class="dwc-field"><span><?php echo $t( 'Heading above the catalog (optional)' ); ?></span><input type="text" name="widget[title]" value="<?php echo Html::attr( $w['title'] ); ?>" maxlength="120"></label>
			</div>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Which products' ); ?></h2>
			<?php echo $radio( 'scope', array( 'all' => $app->t( 'Whole catalog' ), 'group' => $app->t( 'Category groups' ), 'categories' => $app->t( 'Categories' ) ), $w['scope'] ); ?>
			<div class="dwc-design-grid">
				<div class="dwc-field"><span class="dwc-label"><?php echo $t( 'Groups (when "Category groups")' ); ?></span><?php echo $termList( 'groups', $w['groups'], true ) ?: '<span class="dwc-muted">' . $t( 'No categories yet.' ) . '</span>'; ?></div>
				<div class="dwc-field"><span class="dwc-label"><?php echo $t( 'Categories (when "Categories")' ); ?></span><?php echo $termList( 'categories', $w['categories'], false ); ?></div>
				<div class="dwc-field"><span class="dwc-label"><?php echo $t( 'Leave out' ); ?></span><?php echo $termList( 'exclude', $w['exclude'], false ); ?></div>
			</div>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Search' ); ?></h2>
			<?php echo $radio( 'search', array( 'large' => $app->t( 'Large' ), 'small' => $app->t( 'Small' ), 'hidden' => $app->t( 'Hidden' ) ), $w['search'] ); ?>
			<label class="dwc-field"><span><?php echo $t( 'Suggested words (comma-separated)' ); ?></span><input type="text" name="widget[suggest]" value="<?php echo Html::attr( $w['suggest'] ); ?>" placeholder="<?php echo Html::attr( $app->t( 'e.g. nori, wasabi, sushi rice' ) ); ?>"></label>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Filters' ); ?></h2>
			<p class="dwc-muted"><?php echo $t( 'Tick the filters to show and use the arrows to set their order. A filter with nothing to offer is hidden on its own.' ); ?></p>
			<ul class="dwc-orderlist">
				<?php foreach ( $ordered as $key => $on ) : ?>
					<li>
						<label><input type="checkbox" name="widget[filters][]" value="<?php echo Html::attr( $key ); ?>" <?php echo $on ? 'checked' : ''; ?>> <?php echo Html::e( $filters[ $key ] ); ?></label>
						<span><button type="button" class="dwc-icon" data-dwc-up aria-label="<?php echo Html::attr( $app->t( 'Move up' ) ); ?>">↑</button><button type="button" class="dwc-icon" data-dwc-down aria-label="<?php echo Html::attr( $app->t( 'Move down' ) ); ?>">↓</button></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="dwc-field"><span class="dwc-label"><?php echo $t( 'Where the filters go' ); ?></span><?php echo $radio( 'filter_position', array( 'sidebar' => $app->t( 'Sidebar' ), 'top' => $app->t( 'Top bar' ) ), $w['filter_position'] ); ?></div>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Sorting' ); ?></h2>
			<div class="dwc-termbox dwc-termbox-wide">
				<?php foreach ( $sorts as $key => $lbl ) : ?>
					<label class="dwc-check-line dwc-check-compact"><input type="checkbox" name="widget[sorts][]" value="<?php echo Html::attr( $key ); ?>" <?php echo ! $w['sorts'] || in_array( $key, $w['sorts'], true ) ? 'checked' : ''; ?>> <span><?php echo Html::e( $lbl ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<div class="dwc-design-grid">
				<label class="dwc-field"><span><?php echo $t( 'Default sort' ); ?></span>
					<select name="widget[default_sort]">
						<?php foreach ( $sorts as $key => $lbl ) : ?>
							<option value="<?php echo Html::attr( $key ); ?>" <?php echo $w['default_sort'] === $key ? 'selected' : ''; ?>><?php echo Html::e( $lbl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="dwc-field"><span><?php echo $t( 'Products per page' ); ?></span>
					<select name="widget[per_page]">
						<?php foreach ( Widgets::PER_PAGE as $n ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php echo (int) $w['per_page'] === $n ? 'selected' : ''; ?>><?php echo (int) $n; ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Devices' ); ?></h2>
			<div class="dwc-devices">
				<div><strong><?php echo $t( 'Desktop' ); ?></strong><?php echo $number( 'cols_desktop', (int) $w['cols_desktop'], 1, 6, $app->t( 'Columns' ) ); ?><span class="dwc-muted"><?php echo $t( 'Filters in the sidebar or top bar.' ); ?></span></div>
				<div><strong><?php echo $t( 'Tablet' ); ?></strong><?php echo $number( 'cols_tablet', (int) $w['cols_tablet'], 1, 4, $app->t( 'Columns' ) ); ?><?php echo $number( 'per_page_tablet', (int) $w['per_page_tablet'], 4, 96, $app->t( 'Products shown at first' ) ); ?><span class="dwc-muted"><?php echo $t( 'Filters slide in from the side.' ); ?></span></div>
				<div><strong><?php echo $t( 'Phone' ); ?></strong><?php echo $number( 'cols_mobile', (int) $w['cols_mobile'], 1, 2, $app->t( 'Columns' ) ); ?><?php echo $number( 'per_page_mobile', (int) $w['per_page_mobile'], 4, 96, $app->t( 'Products shown at first' ) ); ?><?php echo $radio( 'mobile_view', array( 'grid' => $app->t( 'Cards' ), 'list' => $app->t( 'List' ) ), $w['mobile_view'] ); ?><span class="dwc-muted"><?php echo $t( 'Filters open as a sheet from the bottom; "Load more" instead of page numbers.' ); ?></span></div>
			</div>
		</div>

		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Cards' ); ?></h2>
			<div class="dwc-termbox dwc-termbox-wide">
				<?php foreach ( $cards as $key => $lbl ) : ?>
					<label class="dwc-check-line dwc-check-compact"><input type="checkbox" name="widget[card_fields][]" value="<?php echo Html::attr( $key ); ?>" <?php echo in_array( $key, $w['card_fields'], true ) ? 'checked' : ''; ?>> <span><?php echo Html::e( $lbl ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<p class="dwc-muted"><?php echo $t( 'Only fields marked Public are ever shown. Prices by customer group come with customer accounts.' ); ?></p>
			<div class="dwc-field"><span class="dwc-label"><?php echo $t( 'Clicking a card opens' ); ?></span><?php echo $radio( 'click', array( 'drawer' => $app->t( 'A side panel over the list' ), 'page' => $app->t( 'The product page' ) ), $w['click'] ); ?></div>
			<label class="dwc-field"><span><?php echo $t( 'Design' ); ?></span>
				<select name="widget[design]">
					<?php foreach ( Design::presets( $c->settings() ) as $slug => $name ) : ?>
						<option value="<?php echo Html::attr( 'default' === $slug ? '' : $slug ); ?>" <?php echo ( 'default' === $slug ? '' : $slug ) === $w['design'] ? 'selected' : ''; ?>><?php echo Html::e( 'default' === $slug ? $app->t( 'Default design' ) : $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<p class="dwc-actions"><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo $t( 'Save catalog' ); ?></button></p>
	</form>

	<?php if ( ! $isNew && $canEdit ) : ?>
		<?php echo $app->form( 'delete_widget', $type, false, 'class="dwc-delete"' ); ?>
			<input type="hidden" name="id" value="<?php echo Html::attr( $editing ); ?>">
			<button type="submit" class="dwc-link dwc-danger" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Delete this saved catalog? Pages using its shortcode will show the whole catalog instead.' ) ); ?>"><?php echo $t( 'Delete this saved catalog' ); ?></button>
		</form>
	<?php endif; ?>
<?php endif; ?>
