<?php
/**
 * Dashboard: catalog counts and the screens this user can open.
 * (Data-quality alerts and catalog-page status arrive with the migration phase.)
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array $counts   total, publish, draft
 * @var array $catStat  top, sub
 * @var array $shared   item code => number of items using it
 */
use DWCatalog\Admin\AdminApp;
use DWCatalog\Security\Access;
use DWCatalog\Support\Html;

$current     = 'dashboard';
$canProducts = $app->canView( 'products' );
$dupItems    = array_sum( $shared );
$tiles       = array(
	array( 'Products', (int) $counts['total'], array( 'view' => 'items' ), '' ),
	array( 'Active', (int) $counts['publish'], array( 'view' => 'items', 'status' => 'publish' ), 'dwc-ok' ),
	array( 'Inactive', (int) $counts['draft'], array( 'view' => 'items', 'status' => 'draft' ), '' ),
	array( 'Needs review', $dupItems, array( 'view' => 'items', 'dup' => 1 ), $dupItems ? 'dwc-warn-text' : '' ),
);
$about = array(
	'products'      => 'Add and edit products, prices, options and photos.',
	'categories'    => 'Arrange category groups and categories.',
	'fields'        => 'Choose which product fields exist and who sees them.',
	'customers'     => 'Wholesale applications and customer accounts.',
	'import_export' => 'Bring products in from a spreadsheet, or take them out.',
	'migration'     => 'Move products, categories and applications from the previous plugins.',
	'shortcodes'    => 'Put the catalog, search box and forms on your pages.',
	'design'        => 'Colors, fonts and corners of the catalog on your site.',
	'presets'       => 'Ready-made setups for each kind of wholesale business.',
	'settings'      => 'Languages and the wording of buttons on your site.',
	'access'        => 'Which staff roles see which menus.',
);
?>
<?php echo $app->head( $app->t( 'Dashboard' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="dwc-stats">
	<?php foreach ( $tiles as $tile ) : ?>
		<?php if ( $canProducts ) : ?>
			<a class="dwc-stat" href="<?php echo Html::url( $app->url( $tile[2] + array( 'type' => $type ) ) ); ?>">
		<?php else : ?>
			<div class="dwc-stat">
		<?php endif; ?>
			<span><?php echo Html::e( $app->t( $tile[0] ) ); ?></span><strong class="<?php echo Html::attr( $tile[3] ); ?>"><?php echo (int) $tile[1]; ?></strong>
		<?php echo $canProducts ? '</a>' : '</div>'; ?>
	<?php endforeach; ?>
	<?php if ( $app->canView( 'categories' ) ) : ?>
		<a class="dwc-stat" href="<?php echo Html::url( $app->url( array( 'view' => 'categories', 'type' => $type ) ) ); ?>">
	<?php else : ?>
		<div class="dwc-stat">
	<?php endif; ?>
		<span><?php echo Html::e( $app->t( 'Categories' ) ); ?></span><strong><?php echo (int) $catStat['top'] + (int) $catStat['sub']; ?></strong>
	<?php echo $app->canView( 'categories' ) ? '</a>' : '</div>'; ?>
	<div class="dwc-stat">
		<span><?php echo Html::e( $app->t( 'Applications' ) ); ?></span><strong class="dwc-muted">—</strong>
	</div>
</div>

<?php if ( $dupItems && $canProducts ) : ?>
	<div class="dwc-notice dwc-notice-warn">
		<?php echo Html::e( sprintf( $app->t( '%d products share an item code with another product.' ), $dupItems ) ); ?>
		<a href="<?php echo Html::url( $app->url( array( 'view' => 'items', 'type' => $type, 'dup' => 1 ) ) ); ?>"><?php echo Html::e( $app->t( 'Review them' ) ); ?></a>
	</div>
<?php endif; ?>

<section class="dwc-launch" aria-label="<?php echo Html::attr( $app->t( 'Your screens' ) ); ?>">
	<?php foreach ( Access::MENUS as $menu => $spec ) : ?>
		<?php
		if ( 'dashboard' === $menu || ! $app->canView( $menu ) ) {
			continue;
		}
		$view = AdminApp::MENU_VIEW[ $menu ];
		if ( 'import_export' === $menu && ! $app->canEdit( $menu ) ) {
			$view = 'export';
		}
		?>
		<a class="dwc-launch-card" href="<?php echo Html::url( $app->url( array( 'view' => $view, 'type' => $type ) ) ); ?>">
			<strong><?php echo Html::e( $app->t( $spec[0] ) ); ?></strong>
			<span><?php echo Html::e( $app->t( $about[ $menu ] ) ); ?></span>
			<?php if ( Access::EDIT === $spec[1] && ! $app->canEdit( $menu ) ) : ?>
				<em class="dwc-tag"><?php echo Html::e( $app->t( 'View only' ) ); ?></em>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</section>
