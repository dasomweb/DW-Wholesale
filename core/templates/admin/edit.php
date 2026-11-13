<?php
/**
 * Item editor.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array|null $item
 * @var array|null $code      item code field
 * @var array|null $brand     brand field
 * @var array $codeUsed       other items with the same code (id, title)
 * @var string[] $brands      brand suggestions
 * @var string $oldUrl        address before the migration ('' if not migrated)
 */
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Fields\VariationColumns;
use DWCatalog\Support\Html;
use DWCatalog\Support\Text;

$current = 'edit';
$config  = $c->fields()->type( $type );
$id      = $item ? (int) $item['id'] : 0;
$values  = $id ? $c->items()->values( $id ) : array();
$terms   = $id ? $c->terms()->ofItem( $type, $id ) : array();
$cats    = array_map( 'intval', array_column( $terms, 'id' ) );
$tags    = $id ? implode( ', ', array_column( $c->terms()->ofItem( $type, $id, 'tag' ), 'name' ) ) : '';
$cols    = $c->fields()->variationColumns( $type );
$rows    = $id ? $c->items()->variations( $id ) : array();
$images  = $id ? $c->images()->forItem( $id ) : array();
$codeKey = $code ? $code['meta_key'] : '';
$brandKey = $brand ? $brand['meta_key'] : '';
$val     = function ( $key ) use ( $values ) {
	return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
};

$fieldInput = function ( array $f, string $value, string $extra = '' ) {
	$name = 'fields[' . $f['meta_key'] . ']';
	$idA  = 'dwc-f-' . $f['meta_key'];
	$req  = ! empty( $f['required'] ) ? ' required' : '';
	$cls  = FieldConfig::mono( $f ) ? ' class="dwc-mono"' : '';
	switch ( $f['type'] ) {
		case 'textarea':
			return '<textarea id="' . Html::attr( $idA ) . '" name="' . Html::attr( $name ) . '" rows="3"' . $req . $extra . '>' . Html::e( $value ) . '</textarea>';
		case 'select':
			$h = '<select id="' . Html::attr( $idA ) . '" name="' . Html::attr( $name ) . '"' . $req . $extra . '><option value="">—</option>';
			foreach ( Text::parseOptions( $f['options'] ) as $v => $l ) {
				$h .= '<option value="' . Html::attr( $v ) . '"' . ( (string) $v === $value ? ' selected' : '' ) . '>' . Html::e( $l ) . '</option>';
			}
			return $h . '</select>';
		default:
			$types = array( 'number' => 'number" step="any', 'email' => 'email', 'url' => 'url', 'date' => 'date' );
			$t     = isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : 'text';
			return '<input type="' . $t . '" id="' . Html::attr( $idA ) . '" name="' . Html::attr( $name ) . '" value="' . Html::attr( $value ) . '"' . $cls . $req . $extra . '>';
	}
};
$fieldLabel = function ( array $f ) use ( $app ) {
	return Html::e( $f['label'] ) . ( ! empty( $f['required'] ) ? ' <span class="dwc-req" aria-hidden="true">*</span>' : '' )
		. ( FieldConfig::isPublic( $f ) ? '' : ' <span class="dwc-badge" title="' . Html::attr( $app->t( 'Not shown on the website' ) ) . '">' . Html::e( $app->t( 'Internal' ) ) . '</span>' );
};
$varCell = function ( array $col, string $name, string $value ) {
	if ( 'select' === $col['type'] ) {
		$h = '<select name="' . Html::attr( $name ) . '"><option value="">—</option>';
		foreach ( Text::parseOptions( $col['options'] ) as $v => $l ) {
			$h .= '<option value="' . Html::attr( $v ) . '"' . ( (string) $v === $value ? ' selected' : '' ) . '>' . Html::e( $l ) . '</option>';
		}
		return $h . '</select>';
	}
	return '<input type="' . ( 'number' === $col['type'] ? 'number" step="any' : 'text' ) . '" name="' . Html::attr( $name ) . '" value="' . Html::attr( $value ) . '">';
};
$varRow = function ( $i, array $row ) use ( $cols, $varCell, $app ) {
	$h = '<tr><td class="dwc-move"><button type="button" class="dwc-icon" data-dwc-up title="' . Html::attr( $app->t( 'Move up' ) ) . '">↑</button><button type="button" class="dwc-icon" data-dwc-down title="' . Html::attr( $app->t( 'Move down' ) ) . '">↓</button></td>';
	foreach ( $cols as $col ) {
		$h .= '<td>' . $varCell( $col, 'var[' . $i . '][' . $col['key'] . ']', isset( $row[ $col['key'] ] ) ? (string) $row[ $col['key'] ] : '' ) . '</td>';
	}
	return $h . '<td><button type="button" class="dwc-icon dwc-danger" data-dwc-remove title="' . Html::attr( $app->t( 'Remove' ) ) . '">×</button></td></tr>';
};

// Fields shown in "Basic information" vs. "Details"
$details = array_filter( $c->fields()->fields( $type ), function ( $f ) use ( $codeKey, $brandKey ) {
	return empty( $f['is_title_field'] ) && $f['meta_key'] !== $codeKey && $f['meta_key'] !== $brandKey;
} );

// Card preview: top category leaf · brand, code · first mono list field
$leaf     = $terms ? $terms[0]['name'] : '';
$kicker   = implode( ' · ', array_filter( array( $leaf, $brand ? $val( $brandKey ) : '' ) ) );
$packKey  = '';
foreach ( $details as $f ) {
	if ( FieldConfig::mono( $f ) && ! empty( $f['show_in_list'] ) ) {
		$packKey = $f['meta_key'];
		break;
	}
}
$mainImg = $images ? $images[0] : null;
?>
<div class="dwc-head">
	<div class="dwc-head-title">
		<nav class="dwc-crumbs" aria-label="<?php echo Html::attr( $app->t( 'Breadcrumb' ) ); ?>"><a href="<?php echo Html::url( $app->url( array( 'view' => 'items', 'type' => $type ) ) ); ?>"><?php echo Html::e( $app->t( 'Products' ) ); ?></a> <span aria-hidden="true">/</span> <?php echo Html::e( $id ? $app->t( 'Edit' ) : $app->t( 'Add New' ) ); ?></nav>
		<div class="dwc-titlerow">
			<h1><?php echo Html::e( $id ? $item['title'] : $app->t( 'Add item' ) ); ?></h1>
			<?php if ( $id ) : ?>
				<?php if ( 'publish' === $item['status'] ) : ?>
					<span class="dwc-pill dwc-pill-on"><span aria-hidden="true"></span><?php echo Html::e( $app->t( 'Active' ) ); ?></span>
				<?php else : ?>
					<span class="dwc-pill dwc-pill-off"><span aria-hidden="true"></span><?php echo Html::e( $app->t( 'Inactive' ) ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php include __DIR__ . '/_nav.php'; ?>

<?php echo $app->form( 'save_item', $type, true, 'class="dwc-editor" data-dwc-editor' ); ?>
	<input type="hidden" name="id" value="<?php echo $id; ?>">
	<div class="dwc-editor-main">

		<section class="dwc-card">
			<h2><?php echo Html::e( $app->t( 'Basic information' ) ); ?></h2>
			<div class="dwc-card-body dwc-grid2">
				<div class="dwc-field dwc-span">
					<label for="dwc-title"><?php echo Html::e( $app->t( 'Product name' ) ); ?> <span class="dwc-req" aria-hidden="true">*</span></label>
					<input type="text" id="dwc-title" name="title" required maxlength="255" value="<?php echo Html::attr( $item ? $item['title'] : '' ); ?>" data-dwc-preview="title">
				</div>
				<?php if ( $code ) : ?>
					<div class="dwc-field">
						<label for="dwc-f-<?php echo Html::attr( $codeKey ); ?>"><?php echo $fieldLabel( $code ); ?></label>
						<?php echo $fieldInput( $code, $val( $codeKey ), ' data-dwc-preview="code" autocomplete="off"' ); ?>
						<span data-dwc-codecheck="dwc-f-<?php echo Html::attr( $codeKey ); ?>" data-dwc-post="<?php echo Html::url( $c->host()->adminPostUrl() ); ?>" data-dwc-token="<?php echo Html::attr( $c->csrf()->token( 'check_code' ) ); ?>" data-dwc-id="<?php echo $item ? (int) $item['id'] : 0; ?>" data-dwc-type="<?php echo Html::attr( $type ); ?>">
						<?php if ( $codeUsed ) : ?>
							<span class="dwc-hint dwc-hint-warn">
								<?php echo Html::e( $app->t( 'Also used by:' ) ); ?>
								<?php foreach ( $codeUsed as $i => $other ) : ?>
									<?php echo $i ? ', ' : ''; ?><a href="<?php echo Html::url( $app->url( array( 'view' => 'edit', 'type' => $type, 'id' => (int) $other['id'] ) ) ); ?>"><?php echo Html::e( $other['title'] ); ?></a>
								<?php endforeach; ?>
							</span>
						<?php elseif ( '' !== $val( $codeKey ) ) : ?>
							<span class="dwc-hint dwc-hint-ok"><?php echo Html::e( $app->t( 'Unique — no other item uses this code.' ) ); ?></span>
						<?php else : ?>
							<span class="dwc-hint"><?php echo Html::e( $app->t( 'Each item should have its own code.' ) ); ?></span>
						<?php endif; ?>
						</span>
					</div>
				<?php endif; ?>
				<?php if ( $brand ) : ?>
					<div class="dwc-field">
						<label for="dwc-f-<?php echo Html::attr( $brandKey ); ?>"><?php echo $fieldLabel( $brand ); ?></label>
						<?php echo $fieldInput( $brand, $val( $brandKey ), ( $brands ? ' list="dwc-brands"' : '' ) . ' data-dwc-preview="brand"' ); ?>
						<?php if ( $brands && 'select' !== $brand['type'] ) : ?>
							<datalist id="dwc-brands">
								<?php foreach ( $brands as $b ) : ?>
									<option value="<?php echo Html::attr( $b ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<span class="dwc-hint"><?php echo Html::e( $app->t( 'Pick an existing brand or type a new one.' ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="dwc-field dwc-span">
					<label><?php echo Html::e( $app->t( 'Description' ) ); ?></label>
					<?php echo $c->host()->richText( 'content', $item ? (string) $item['content'] : '' ); ?>
				</div>
				<div class="dwc-field dwc-span">
					<label for="dwc-excerpt"><?php echo Html::e( $app->t( 'Short summary' ) ); ?></label>
					<textarea id="dwc-excerpt" name="excerpt" rows="2" maxlength="1000" placeholder="<?php echo Html::attr( $app->t( 'Optional — used by search engines' ) ); ?>"><?php echo Html::e( $item ? $item['excerpt'] : '' ); ?></textarea>
				</div>
			</div>
		</section>

		<?php if ( $details ) : ?>
			<section class="dwc-card">
				<h2><?php echo Html::e( $app->t( 'Details' ) ); ?></h2>
				<div class="dwc-card-body dwc-grid2">
					<?php foreach ( $details as $f ) : ?>
						<div class="dwc-field<?php echo 'textarea' === $f['type'] ? ' dwc-span' : ''; ?>">
							<label for="dwc-f-<?php echo Html::attr( $f['meta_key'] ); ?>"><?php echo $fieldLabel( $f ); ?></label>
							<?php echo $fieldInput( $f, $val( $f['meta_key'] ), $f['meta_key'] === $packKey ? ' data-dwc-preview="pack"' : '' ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $cols ) : ?>
			<section class="dwc-card">
				<h2><?php echo Html::e( $app->t( 'Variations' ) ); ?></h2>
				<div class="dwc-card-body">
					<p class="dwc-muted"><?php echo Html::e( $app->t( 'Add rows when this item comes in several sizes, packs or flavors.' ) ); ?></p>
					<div class="dwc-scroll">
						<table class="dwc-table dwc-rows" data-dwc-rows="var">
							<thead><tr><th></th>
								<?php foreach ( $cols as $col ) : ?>
									<th><?php echo Html::e( $col['label'] ); ?><?php echo VariationColumns::isPublic( $col ) ? '' : ' <span class="dwc-badge">' . Html::e( $app->t( 'Internal' ) ) . '</span>'; ?></th>
								<?php endforeach; ?>
							<th></th></tr></thead>
							<tbody>
								<?php foreach ( $rows as $i => $row ) { echo $varRow( $i, $row ); } ?>
							</tbody>
						</table>
					</div>
					<template data-dwc-template="var"><?php echo $varRow( '__i__', array() ); ?></template>
					<p><button type="button" class="dwc-btn" data-dwc-add="var">+ <?php echo Html::e( $app->t( 'Add variation' ) ); ?></button></p>
				</div>
			</section>
		<?php endif; ?>

		<section class="dwc-card">
			<h2><?php echo Html::e( $app->t( 'Images' ) ); ?></h2>
			<div class="dwc-card-body">
				<?php if ( $images ) : ?>
					<ul class="dwc-images" data-dwc-images>
						<?php foreach ( $images as $img ) : ?>
							<li>
								<input type="hidden" name="img_order[]" value="<?php echo (int) $img['id']; ?>">
								<?php echo $c->images()->tag( $img, 'thumb' ); ?>
								<input type="text" name="img_alt[<?php echo (int) $img['id']; ?>]" value="<?php echo Html::attr( $img['alt'] ); ?>" placeholder="<?php echo Html::attr( $app->t( 'Alt text' ) ); ?>">
								<span class="dwc-imgtools">
									<button type="button" class="dwc-icon" data-dwc-up title="<?php echo Html::attr( $app->t( 'Move up' ) ); ?>">↑</button>
									<button type="button" class="dwc-icon" data-dwc-down title="<?php echo Html::attr( $app->t( 'Move down' ) ); ?>">↓</button>
									<label class="dwc-danger"><input type="checkbox" name="img_delete[]" value="<?php echo (int) $img['id']; ?>"> <?php echo Html::e( $app->t( 'Delete' ) ); ?></label>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<label class="dwc-drop" data-dwc-drop>
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
					<strong><?php echo Html::e( $app->t( 'Drop images or choose files' ) ); ?></strong>
					<span><?php echo Html::e( $app->t( 'First image is the catalog thumbnail · JPG, PNG, WebP · resized for the web before upload' ) ); ?></span>
					<input type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-dwc-resize>
					<span class="dwc-drop-status" data-dwc-resize-status></span>
				</label>
			</div>
		</section>
	</div>

	<aside class="dwc-editor-side">
		<section class="dwc-card">
			<h2><?php echo Html::e( $app->t( 'Publish' ) ); ?></h2>
			<div class="dwc-card-body dwc-stack">
				<div class="dwc-field">
					<label for="dwc-status"><?php echo Html::e( $app->t( 'Status' ) ); ?></label>
					<select id="dwc-status" name="status">
						<option value="publish" <?php echo ! $item || 'publish' === $item['status'] ? 'selected' : ''; ?>><?php echo Html::e( $app->t( 'Active — visible on catalog' ) ); ?></option>
						<option value="draft" <?php echo $item && 'draft' === $item['status'] ? 'selected' : ''; ?>><?php echo Html::e( $app->t( 'Inactive — hidden' ) ); ?></option>
					</select>
				</div>
				<div class="dwc-field">
					<label for="dwc-slug"><?php echo Html::e( $app->t( 'URL slug' ) ); ?></label>
					<input type="text" id="dwc-slug" name="slug" class="dwc-mono" value="<?php echo Html::attr( $item ? $item['slug'] : '' ); ?>" placeholder="<?php echo Html::attr( $app->t( 'from the name' ) ); ?>">
					<?php if ( '' !== $oldUrl ) : ?>
						<span class="dwc-hint"><?php echo Html::e( $app->t( 'Old address:' ) ); ?> <span class="dwc-mono"><?php echo Html::e( $oldUrl ); ?></span> → <?php echo Html::e( $app->t( 'this product' ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $id ) : ?>
					<span class="dwc-hint"><?php echo Html::e( sprintf( $app->t( 'Last updated %s' ), substr( $item['updated_at'], 0, 16 ) ) ); ?> · #<?php echo $id; ?></span>
				<?php endif; ?>
			</div>
			<div class="dwc-card-foot">
				<?php if ( $id && ! $readonly && $app->allowed( 'product_delete' ) ) : ?>
					<button type="submit" form="dwc-delete-form" class="dwc-link dwc-danger" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Delete this item and its images? This cannot be undone.' ) ); ?>"><?php echo Html::e( $app->t( 'Delete item' ) ); ?></button>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<?php if ( ! $readonly ) : ?>
				<button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( $id ? $app->t( 'Save changes' ) : $app->t( 'Add item' ) ); ?></button>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( ! empty( $config['has_category'] ) ) : ?>
			<section class="dwc-card">
				<h2><?php echo Html::e( $app->t( 'Category' ) ); ?></h2>
				<div class="dwc-card-body">
					<?php
					$tree = $c->terms()->tree( $type );
					if ( empty( $tree ) ) :
						?>
						<p class="dwc-muted"><?php echo Html::e( $app->t( 'No categories yet.' ) ); ?> <a href="<?php echo Html::url( $app->url( array( 'view' => 'categories', 'type' => $type ) ) ); ?>"><?php echo Html::e( $app->t( 'Add categories' ) ); ?></a></p>
					<?php else : ?>
						<label class="dwc-search dwc-search-sm">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
							<span class="dwc-sr"><?php echo Html::e( $app->t( 'Find category' ) ); ?></span>
							<input type="search" placeholder="<?php echo Html::attr( $app->t( 'Find category' ) ); ?>" data-dwc-catfind>
						</label>
						<div class="dwc-checklist" data-dwc-catlist>
							<?php
							$list = function ( $parent ) use ( &$list, $tree, $cats ) {
								if ( empty( $tree[ $parent ] ) ) {
									return;
								}
								echo '<ul>';
								foreach ( $tree[ $parent ] as $t ) {
									echo '<li><label><input type="checkbox" name="cats[]" value="' . (int) $t['id'] . '"' . ( in_array( (int) $t['id'], $cats, true ) ? ' checked' : '' ) . ' data-dwc-preview-cat="' . Html::attr( $t['name'] ) . '"> ' . Html::e( $t['name'] ) . '</label>';
									$list( (int) $t['id'] );
									echo '</li>';
								}
								echo '</ul>';
							};
							$list( 0 );
							?>
						</div>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $config['has_tag'] ) ) : ?>
			<section class="dwc-card">
				<h2><label for="dwc-tags"><?php echo Html::e( $app->t( 'Tags' ) ); ?></label></h2>
				<div class="dwc-card-body">
					<input type="text" id="dwc-tags" name="tags" value="<?php echo Html::attr( $tags ); ?>" placeholder="<?php echo Html::attr( $app->t( 'comma, separated' ) ); ?>">
				</div>
			</section>
		<?php endif; ?>

		<section class="dwc-card">
			<h2><?php echo Html::e( $app->t( 'Card preview' ) ); ?></h2>
			<div class="dwc-card-body">
				<div class="dwc-preview" data-dwc-previewcard>
					<div class="dwc-preview-media">
						<?php if ( $mainImg ) : ?>
							<?php echo $c->images()->tag( $mainImg, 'medium' ); ?>
						<?php else : ?>
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-5 9 5-9 5-9-5z"/><path d="M3 9v6l9 5 9-5V9"/></svg>
						<?php endif; ?>
					</div>
					<div class="dwc-preview-body">
						<span class="dwc-preview-kicker" data-dwc-out="kicker"><?php echo Html::e( $kicker ); ?></span>
						<span class="dwc-preview-title" data-dwc-out="title"><?php echo Html::e( $item ? $item['title'] : $app->t( 'Product name' ) ); ?></span>
						<span class="dwc-preview-meta" data-dwc-out="meta"><?php echo Html::e( implode( ' · ', array_filter( array( $code && '' !== $val( $codeKey ) ? '#' . $val( $codeKey ) : '', $packKey ? $val( $packKey ) : '' ) ) ) ); ?></span>
					</div>
				</div>
			</div>
		</section>
	</aside>
</form>

<?php if ( $id && ! $readonly && $app->allowed( 'product_delete' ) ) : ?>
	<?php echo $app->form( 'delete_item', $type, false, 'id="dwc-delete-form" hidden' ); ?>
		<input type="hidden" name="id" value="<?php echo $id; ?>">
	</form>
<?php endif; ?>
