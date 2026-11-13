<?php
/**
 * How to place the catalog on pages.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 */
use DWCatalog\Support\Html;

$current = 'shortcodes';
$public  = $c->fields()->fieldsByKey( $type, true );
$tree    = $c->terms()->tree( $type );
$first   = isset( $tree[0][0] ) ? $tree[0][0]['slug'] : 'category-slug';
$filter  = '';
foreach ( $public as $key => $f ) {
	if ( 'text' === $f['type'] || 'select' === $f['type'] ) {
		$filter = $key;
		break;
	}
}
$examples = array(
	'Whole catalog page'           => '[dw_catalog]',
	'One category, with a filter'  => '[dw_catalog category="' . $first . '"' . ( $filter ? ' filter_fields="' . $filter . '"' : '' ) . ']',
	'Grid of newest items'         => '[dw_catalog_grid per_page="8" columns="4"]',
	'Carousel of one category'     => '[dw_catalog_carousel category="' . $first . '"]',
);
?>
<?php echo $app->head( $app->t( 'Shortcodes' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="dwc-box">
	<p><?php echo Html::e( $app->t( 'Put a shortcode on any page. The catalog list, filters and item detail pages all appear on that page.' ) ); ?></p>
	<table class="dwc-form">
		<?php foreach ( $examples as $label => $code ) : ?>
			<tr><th><?php echo Html::e( $app->t( $label ) ); ?></th><td><code class="dwc-code"><?php echo Html::e( $code ); ?></code></td></tr>
		<?php endforeach; ?>
	</table>
</div>

<div class="dwc-box">
	<h2><?php echo Html::e( $app->t( '[dw_catalog] options' ) ); ?></h2>
	<table class="dwc-table">
		<tbody>
			<?php
			$opts = array(
				'category'      => 'Category slug(s) this page shows. The sidebar lists its sub-categories.',
				'filter_fields' => 'Field keys offered as sidebar filters (public fields only).',
				'columns'       => 'Maximum columns (fewer on small screens).',
				'per_page'      => 'Items per page (max 100).',
				'show_fields'   => 'Field keys shown on cards (default: List fields).',
				'detail_fields' => 'Field keys shown on the detail page (default: Public fields).',
				'default_sort'  => 'date_desc, date_asc, title_asc, title_desc, field:{key}:asc|desc',
				'search / sort' => 'yes / no — show the search box / sort menu.',
				'inquiry_url'   => 'Adds an "Inquire" button on detail pages.',
			);
			foreach ( $opts as $k => $v ) {
				echo '<tr><td><code>' . Html::e( $k ) . '</code></td><td>' . Html::e( $app->t( $v ) ) . '</td></tr>';
			}
			?>
		</tbody>
	</table>
</div>

<div class="dwc-box">
	<h2><?php echo Html::e( $app->t( 'Public field keys' ) ); ?></h2>
	<table class="dwc-table">
		<?php foreach ( $public as $key => $f ) : ?>
			<tr><td><code><?php echo Html::e( $key ); ?></code></td><td><?php echo Html::e( $f['label'] ); ?></td><td class="dwc-muted"><?php echo Html::e( $f['type'] ); ?></td></tr>
		<?php endforeach; ?>
	</table>
</div>
