<?php
/**
 * Item list: stats, filters, bulk actions, table, paging.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array $q
 * @var array $result
 * @var string $orderby
 * @var string $order
 * @var string $status
 * @var array $meta
 * @var int $perPage
 * @var bool $dup
 * @var array|null $code   item code field
 * @var array|null $brand  brand field
 * @var array $shared      shared code value => count
 * @var array $counts      total, publish, draft
 * @var array $catStat     top, sub
 * @var string[] $brands   brand suggestions (text field)
 * @var string $tab         all | active | no_status | out_of_stock | hidden | review
 * @var array $quality      tab counts (ItemStats::quality)
 * @var array|null $pstatus product status field (active, out of stock, …)
 * @var string $image       '' | yes | no
 */
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Support\Html;
use DWCatalog\Support\Text;

$current  = 'items';
$host     = $c->host();
$config   = $c->fields()->type( $type );
$hasCat   = ! empty( $config['has_category'] );
$codeKey  = $code ? $code['meta_key'] : '';
$brandKey = $brand ? $brand['meta_key'] : '';
$psKey    = $pstatus ? $pstatus['meta_key'] : '';
// The status field has its own column and tabs, so it is not repeated as a field column / filter.
$listFields = array_filter( $c->fields()->fields( $type ), function ( $f ) use ( $codeKey, $psKey ) {
	return ! empty( $f['show_in_list'] ) && empty( $f['is_title_field'] ) && $f['meta_key'] !== $codeKey && $f['meta_key'] !== $psKey;
} );
$filterFields = array_filter( $c->fields()->fields( $type ), function ( $f ) use ( $brandKey, $psKey ) {
	return 'select' === $f['type'] && ! empty( $f['show_in_list'] ) && $f['meta_key'] !== $brandKey && $f['meta_key'] !== $psKey;
} );
$tabMeta = $meta;
unset( $tabMeta[ $psKey ] );
$state = array_filter( array(
	'view'    => 'items',
	'type'    => $type,
	's'       => isset( $q['s'] ) ? (string) $q['s'] : '',
	'cat'     => ! empty( $q['cat'] ) ? (int) $q['cat'] : '',
	'tab'     => 'all' !== $tab ? $tab : '',
	'img'     => $image,
	'f'       => array_map( 'reset', $tabMeta ),
	'orderby' => $orderby,
	'order'   => $orderby ? $order : '',
	'n'       => 20 !== $perPage ? $perPage : '',
), function ( $v ) {
	return '' !== $v && array() !== $v;
} );
$back     = http_build_query( array_merge( $state, array( 'paged' => $result['page'] > 1 ? $result['page'] : null ) ) );
$filtered = ! empty( $q['s'] ) || ! empty( $q['cat'] ) || $tabMeta || '' !== $image;
$link     = function ( array $over ) use ( $app, $state ) {
	return Html::url( $app->url( array_merge( $state, $over ) ) );
};
$sortLink = function ( $label, $key ) use ( $link, $orderby, $order ) {
	$next  = ( $orderby === $key && 'asc' === $order ) ? 'desc' : 'asc';
	$arrow = $orderby === $key ? ( 'asc' === $order ? ' ↑' : ' ↓' ) : '';
	return '<a href="' . $link( array( 'orderby' => $key, 'order' => $next, 'paged' => null ) ) . '">' . Html::e( $label ) . $arrow . '</a>';
};
$tree    = $hasCat ? $c->terms()->tree( $type ) : array();
$catOpts = function ( $selected ) use ( $tree ) {
	$html = '';
	$walk = function ( $parent, $depth ) use ( &$walk, &$html, $tree, $selected ) {
		foreach ( isset( $tree[ $parent ] ) ? $tree[ $parent ] : array() as $term ) {
			$html .= '<option value="' . (int) $term['id'] . '"' . ( (int) $selected === (int) $term['id'] ? ' selected' : '' ) . '>' . str_repeat( '— ', $depth ) . Html::e( $term['name'] ) . '</option>';
			$walk( (int) $term['id'], $depth + 1 );
		}
	};
	$walk( 0, 0 );
	return $html;
};
// One status cell: the product's own status, "Hidden" when it is not on the site, and the review mark.
$status_pill = function ( array $item, string $value ) use ( $app, $pstatus ) {
	$html = '';
	if ( $pstatus && '' !== $value ) {
		$html .= '<span class="dwc-pill ' . ( 'active' === $value ? 'dwc-pill-on' : 'dwc-pill-off' ) . '"><span aria-hidden="true"></span>' . Html::e( FieldConfig::display( $pstatus, $value ) ) . '</span>';
	} elseif ( $pstatus ) {
		$html .= '<span class="dwc-muted">' . Html::e( $app->t( 'No status' ) ) . '</span>';
	} elseif ( 'publish' === $item['status'] ) {
		$html .= '<span class="dwc-pill dwc-pill-on"><span aria-hidden="true"></span>' . Html::e( $app->t( 'On the site' ) ) . '</span>';
	}
	if ( 'publish' !== $item['status'] ) {
		$html .= ' <span class="dwc-pill dwc-pill-off"><span aria-hidden="true"></span>' . Html::e( $app->t( 'Hidden' ) ) . '</span>';
	}
	if ( ! empty( $item['review'] ) ) {
		$html .= ' <em class="dwc-tag dwc-tag-warn">' . Html::e( $app->t( 'Review' ) ) . '</em>';
	}
	return $html;
};
$tabCount = array( 'all' => $quality['total'], 'active' => $quality['active'], 'no_status' => $quality['no_status'], 'out_of_stock' => $quality['out_of_stock'], 'hidden' => $quality['hidden'], 'review' => $quality['review'] );
$from = $result['total'] ? ( $result['page'] - 1 ) * $perPage + 1 : 0;
$to   = min( $result['total'], $result['page'] * $perPage );

$actions = '';
if ( $app->canEdit( 'import_export' ) ) {
	$actions .= '<a class="dwc-btn" href="' . Html::url( $app->url( array( 'view' => 'import', 'type' => $type ) ) ) . '">' . Html::e( $app->t( 'Import CSV' ) ) . '</a>';
}
if ( $app->canView( 'import_export' ) ) {
	$actions .= '<a class="dwc-btn" href="' . Html::url( $app->url( array( 'view' => 'export', 'type' => $type ) ) ) . '">' . Html::e( $app->t( 'Export' ) ) . '</a>';
}
$canWrite  = $app->canEdit( 'products' );
$canExport = $app->allowed( 'csv_export' );
$canDelete = $canWrite && $app->allowed( 'product_delete' );
$hasBulk   = $canWrite || $canExport;
if ( $canWrite ) {
	$actions .= '<a class="dwc-btn dwc-btn-primary" href="' . Html::url( $app->url( array( 'view' => 'edit', 'type' => $type ) ) ) . '">+ ' . Html::e( $app->t( 'Add item' ) ) . '</a>';
}
?>
<?php echo $app->head( $app->t( 'Products' ), $actions ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<nav class="dwc-statustabs" aria-label="<?php echo Html::attr( $app->t( 'Product status' ) ); ?>">
	<?php foreach ( DWCatalog\Admin\AdminApp::TABS as $key => $label ) : ?>
		<?php
		if ( ! $pstatus && in_array( $key, array( 'active', 'no_status', 'out_of_stock' ), true ) ) {
			continue;
		}
		?>
		<a class="<?php echo $tab === $key ? 'is-on' : ''; ?><?php echo 'review' === $key && $tabCount[ $key ] ? ' is-warn' : ''; ?>" href="<?php echo $link( array( 'tab' => 'all' === $key ? null : $key, 'paged' => null ) ); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $app->t( $label ) ); ?> <span><?php echo (int) $tabCount[ $key ]; ?></span></a>
	<?php endforeach; ?>
</nav>

<div class="dwc-panel">
	<?php echo $app->getForm( array( 'view' => 'items', 'type' => $type ), 'class="dwc-filterbar" id="dwc-list-filter" data-dwc-autosubmit' ); ?>
		<?php if ( $orderby ) : ?>
			<input type="hidden" name="orderby" value="<?php echo Html::attr( $orderby ); ?>">
			<input type="hidden" name="order" value="<?php echo Html::attr( $order ); ?>">
		<?php endif; ?>
		<?php if ( 'all' !== $tab ) : ?>
			<input type="hidden" name="tab" value="<?php echo Html::attr( $tab ); ?>">
		<?php endif; ?>
		<label class="dwc-search">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
			<span class="dwc-sr"><?php echo Html::e( $app->t( 'Search items' ) ); ?></span>
			<input type="search" name="s" value="<?php echo Html::attr( isset( $q['s'] ) ? $q['s'] : '' ); ?>" placeholder="<?php echo Html::attr( $code ? sprintf( $app->t( 'Search name, %s, brand' ), Text::lower( $code['label'] ) ) : $app->t( 'Search items' ) ); ?>">
		</label>
		<?php foreach ( $filterFields as $field ) : ?>
			<select name="f[<?php echo Html::attr( $field['meta_key'] ); ?>]" aria-label="<?php echo Html::attr( $field['label'] ); ?>">
				<option value=""><?php echo Html::e( sprintf( $app->t( 'Any %s' ), Text::lower( $field['label'] ) ) ); ?></option>
				<?php foreach ( Text::parseOptions( $field['options'] ) as $val => $label ) : ?>
					<option value="<?php echo Html::attr( $val ); ?>" <?php echo isset( $meta[ $field['meta_key'] ] ) && $meta[ $field['meta_key'] ][0] === (string) $val ? 'selected' : ''; ?>><?php echo Html::e( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endforeach; ?>
		<?php if ( $hasCat ) : ?>
			<select name="cat" aria-label="<?php echo Html::attr( $app->t( 'Category' ) ); ?>">
				<option value=""><?php echo Html::e( $app->t( 'Any category' ) ); ?></option>
				<?php echo $catOpts( ! empty( $q['cat'] ) ? (int) $q['cat'] : 0 ); ?>
			</select>
		<?php endif; ?>
		<?php if ( $brand && ( 'select' === $brand['type'] || $brands ) ) : ?>
			<select name="f[<?php echo Html::attr( $brandKey ); ?>]" aria-label="<?php echo Html::attr( $brand['label'] ); ?>">
				<option value=""><?php echo Html::e( sprintf( $app->t( 'Any %s' ), Text::lower( $brand['label'] ) ) ); ?></option>
				<?php foreach ( 'select' === $brand['type'] ? Text::parseOptions( $brand['options'] ) : array_combine( $brands, $brands ) as $val => $label ) : ?>
					<option value="<?php echo Html::attr( $val ); ?>" <?php echo isset( $meta[ $brandKey ] ) && $meta[ $brandKey ][0] === (string) $val ? 'selected' : ''; ?>><?php echo Html::e( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<select name="img" aria-label="<?php echo Html::attr( $app->t( 'Photo' ) ); ?>">
			<option value=""><?php echo Html::e( $app->t( 'With or without photo' ) ); ?></option>
			<option value="yes" <?php echo 'yes' === $image ? 'selected' : ''; ?>><?php echo Html::e( $app->t( 'Has a photo' ) ); ?></option>
			<option value="no" <?php echo 'no' === $image ? 'selected' : ''; ?>><?php echo Html::e( $app->t( 'No photo' ) ); ?></option>
		</select>
		<button type="submit" class="dwc-btn"><?php echo Html::e( $app->t( 'Filter' ) ); ?></button>
		<?php if ( $filtered ) : ?>
			<a class="dwc-clear" href="<?php echo Html::url( $app->url( array( 'view' => 'items', 'type' => $type ) ) ); ?>"><?php echo Html::e( $app->t( 'Clear filters' ) ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( ! $result['rows'] ) : ?>
		<p class="dwc-empty"><?php echo Html::e( $filtered ? $app->t( 'No items match these filters.' ) : $app->t( 'No items yet.' ) ); ?></p>
	<?php else : ?>
		<?php echo $app->form( 'bulk_items', $type, false, 'id="dwc-bulk-form" data-dwc-bulk' ); ?>
			<input type="hidden" name="back" value="<?php echo Html::attr( $back ); ?>">
			<div class="dwc-selbar" data-dwc-selbar hidden>
				<strong><span data-dwc-selcount>0</span> <?php echo Html::e( $app->t( 'selected' ) ); ?></strong>
				<?php if ( $canWrite ) : ?>
					<?php if ( $pstatus ) : ?>
						<select name="pstatus_value" class="dwc-btn-outline" data-dwc-bulkpick="pstatus" aria-label="<?php echo Html::attr( $app->t( 'Change status' ) ); ?>">
							<option value=""><?php echo Html::e( $app->t( 'Change status…' ) ); ?></option>
							<?php foreach ( Text::parseOptions( $pstatus['options'] ) as $val => $label ) : ?>
								<option value="<?php echo Html::attr( $val ); ?>"><?php echo Html::e( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				<button type="submit" name="bulk" value="publish" class="dwc-btn dwc-btn-outline"><?php echo Html::e( $app->t( 'Show on site' ) ); ?></button>
				<button type="submit" name="bulk" value="draft" class="dwc-btn dwc-btn-outline"><?php echo Html::e( $app->t( 'Hide from site' ) ); ?></button>
					<?php if ( $brand ) : ?>
						<span class="dwc-bulk-brand">
							<input type="text" name="brand_value" <?php echo $brands ? 'list="dwc-bulk-brands"' : ''; ?> placeholder="<?php echo Html::attr( $app->t( 'Brand…' ) ); ?>" aria-label="<?php echo Html::attr( $app->t( 'Brand for the selected products' ) ); ?>">
							<?php if ( $brands ) : ?>
								<datalist id="dwc-bulk-brands"><?php foreach ( $brands as $b ) : ?><option value="<?php echo Html::attr( $b ); ?>"></option><?php endforeach; ?></datalist>
							<?php endif; ?>
							<button type="submit" name="bulk" value="brand" class="dwc-btn dwc-btn-outline"><?php echo Html::e( $app->t( 'Set brand' ) ); ?></button>
						</span>
					<?php endif; ?>
					<?php if ( 'review' === $tab ) : ?>
						<button type="submit" name="bulk" value="reviewed" class="dwc-btn dwc-btn-outline"><?php echo Html::e( $app->t( 'Mark as reviewed' ) ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $canWrite && $hasCat && $tree ) : ?>
					<select name="move_cat" class="dwc-btn-outline" data-dwc-move aria-label="<?php echo Html::attr( $app->t( 'Move to category' ) ); ?>">
						<option value=""><?php echo Html::e( $app->t( 'Move to category…' ) ); ?></option>
						<?php echo $catOpts( 0 ); ?>
					</select>
					<button type="submit" name="bulk" value="move" hidden data-dwc-move-submit><?php echo Html::e( $app->t( 'Move' ) ); ?></button>
				<?php endif; ?>
				<?php if ( $canExport ) : ?>
					<button type="submit" name="bulk" value="export" class="dwc-btn dwc-btn-outline"><?php echo Html::e( $app->t( 'Export selected' ) ); ?></button>
				<?php endif; ?>
				<?php if ( $canDelete ) : ?>
					<button type="submit" name="bulk" value="delete" class="dwc-btn dwc-btn-danger" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Delete the selected items? This cannot be undone.' ) ); ?>"><?php echo Html::e( $app->t( 'Delete' ) ); ?></button>
				<?php endif; ?>
				<button type="button" class="dwc-link dwc-selbar-clear" data-dwc-clearsel><?php echo Html::e( $app->t( 'Clear selection' ) ); ?></button>
			</div>
			<div class="dwc-scroll">
				<table class="dwc-table dwc-list">
					<thead>
						<tr>
							<th class="dwc-check"><input type="checkbox" data-dwc-checkall aria-label="<?php echo Html::attr( $app->t( 'Select all' ) ); ?>"></th>
							<th><?php echo $sortLink( $app->t( 'Product' ), 'title' ); ?></th>
							<?php if ( $code ) : ?>
								<th><?php echo $sortLink( $code['label'], $codeKey ); ?></th>
							<?php endif; ?>
							<?php if ( $hasCat ) : ?>
								<th><?php echo Html::e( $app->t( 'Category' ) ); ?></th>
							<?php endif; ?>
							<?php foreach ( $listFields as $field ) : ?>
								<th><?php echo $sortLink( $field['label'], $field['meta_key'] ); ?></th>
							<?php endforeach; ?>
							<th><?php echo Html::e( $app->t( 'Status' ) ); ?></th>
							<th class="dwc-r"><?php echo Html::e( $app->t( 'Actions' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['rows'] as $item ) : ?>
							<?php
							$id    = (int) $item['id'];
							$edit  = $app->url( array( 'view' => 'edit', 'type' => $type, 'id' => $id ) );
							$img   = $c->images()->main( $id );
							$terms = $hasCat ? $c->terms()->ofItem( $type, $id ) : array();
							$leaf  = $terms ? $terms[0] : null;
							$up    = $leaf && (int) $leaf['parent'] ? $c->terms()->get( $type, (int) $leaf['parent'] ) : null;
							$cv    = $code ? $c->items()->value( $id, $codeKey ) : '';
							?>
							<tr>
								<td class="dwc-check"><input type="checkbox" name="ids[]" value="<?php echo $id; ?>" aria-label="<?php echo Html::attr( sprintf( $app->t( 'Select %s' ), $item['title'] ) ); ?>"></td>
								<td>
									<div class="dwc-product">
										<span class="dwc-thumb"><?php echo $img ? $c->images()->tag( $img, 'thumb' ) : ''; ?></span>
										<a class="dwc-strong" href="<?php echo Html::url( $edit ); ?>"><?php echo Html::e( $item['title'] ); ?></a>
									</div>
								</td>
								<?php if ( $code ) : ?>
									<td class="dwc-nowrap">
										<span class="dwc-mono"><?php echo Html::e( '' !== $cv ? $cv : '—' ); ?></span>
										<?php if ( '' !== $cv && isset( $shared[ Text::cut( $cv, 191 ) ] ) ) : ?>
											<a class="dwc-shared" href="<?php echo Html::url( $app->url( array( 'view' => 'items', 'type' => $type, 's' => $cv ) ) ); ?>" title="<?php echo Html::attr( sprintf( $app->t( 'This %s is shared with another item' ), Text::lower( $code['label'] ) ) ); ?>">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 8v5M12 17h.01"/><path d="M10.3 3.9L2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><?php echo Html::e( $app->t( 'Shared' ) ); ?>
											</a>
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<?php if ( $hasCat ) : ?>
									<td>
										<?php if ( $leaf ) : ?>
											<?php if ( $up ) : ?><span class="dwc-sub"><?php echo Html::e( $up['name'] ); ?></span><?php endif; ?>
											<?php echo Html::e( $leaf['name'] ); ?><?php echo count( $terms ) > 1 ? ' <span class="dwc-muted">+' . ( count( $terms ) - 1 ) . '</span>' : ''; ?>
										<?php else : ?>
											<span class="dwc-muted">—</span>
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<?php foreach ( $listFields as $field ) : ?>
									<?php $v = FieldConfig::display( $field, $c->items()->value( $id, $field['meta_key'] ) ); ?>
									<td class="<?php echo FieldConfig::mono( $field ) ? 'dwc-mono dwc-nowrap' : ''; ?>"><?php echo Html::e( '' !== $v ? $v : '—' ); ?></td>
								<?php endforeach; ?>
								<td class="dwc-nowrap"><?php echo $status_pill( $item, $psKey ? $c->items()->value( $id, $psKey ) : '' ); ?></td>
								<td class="dwc-r dwc-nowrap">
									<a class="dwc-rowlink" href="<?php echo Html::url( $edit ); ?>"><?php echo Html::e( $canWrite ? $app->t( 'Edit' ) : $app->t( 'Open' ) ); ?></a>
									<?php if ( $hasBulk ) : ?>
									<details class="dwc-menu">
										<summary aria-label="<?php echo Html::attr( sprintf( $app->t( 'More actions for %s' ), $item['title'] ) ); ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></summary>
										<div class="dwc-menu-list">
											<?php if ( ! $canWrite ) : ?>
											<?php elseif ( 'publish' === $item['status'] ) : ?>
												<button type="submit" form="dwc-row-form" name="one" value="draft:<?php echo $id; ?>"><?php echo Html::e( $app->t( 'Hide from site' ) ); ?></button>
											<?php else : ?>
												<button type="submit" form="dwc-row-form" name="one" value="publish:<?php echo $id; ?>"><?php echo Html::e( $app->t( 'Show on site' ) ); ?></button>
											<?php endif; ?>
											<?php if ( $canExport ) : ?>
												<button type="submit" form="dwc-row-form" name="one" value="export:<?php echo $id; ?>"><?php echo Html::e( $app->t( 'Export CSV' ) ); ?></button>
											<?php endif; ?>
											<?php if ( $canDelete ) : ?>
												<button type="submit" form="dwc-row-form" name="one" value="delete:<?php echo $id; ?>" class="dwc-danger" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Delete this item and its images? This cannot be undone.' ) ); ?>"><?php echo Html::e( $app->t( 'Delete' ) ); ?></button>
											<?php endif; ?>
										</div>
									</details>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php echo $app->form( 'bulk_items', $type, false, 'id="dwc-row-form"' ); ?>
			<input type="hidden" name="back" value="<?php echo Html::attr( $back ); ?>">
		</form>
	<?php endif; ?>

	<div class="dwc-foot">
		<span><?php echo Html::e( sprintf( $app->t( '%1$d–%2$d of %3$d items' ), $from, $to, $result['total'] ) ); ?></span>
		<div class="dwc-pager">
			<label><?php echo Html::e( $app->t( 'Rows' ) ); ?>
				<select name="n" form="dwc-list-filter">
					<?php foreach ( DWCatalog\Admin\AdminApp::PER_PAGE as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php echo $n === $perPage ? 'selected' : ''; ?>><?php echo (int) $n; ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( $result['page'] > 1 ) : ?>
				<a class="dwc-pg" href="<?php echo $link( array( 'paged' => $result['page'] - 1 ) ); ?>" aria-label="<?php echo Html::attr( $app->t( 'Previous page' ) ); ?>">‹</a>
			<?php else : ?>
				<span class="dwc-pg is-disabled" aria-hidden="true">‹</span>
			<?php endif; ?>
			<span><?php echo sprintf( Html::e( $app->t( 'Page %1$s of %2$s' ) ), '<strong>' . (int) $result['page'] . '</strong>', (int) max( 1, $result['pages'] ) ); ?></span>
			<?php if ( $result['page'] < $result['pages'] ) : ?>
				<a class="dwc-pg" href="<?php echo $link( array( 'paged' => $result['page'] + 1 ) ); ?>" aria-label="<?php echo Html::attr( $app->t( 'Next page' ) ); ?>">›</a>
			<?php else : ?>
				<span class="dwc-pg is-disabled" aria-hidden="true">›</span>
			<?php endif; ?>
		</div>
	</div>
</div>
