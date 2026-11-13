<?php
/**
 * Dashboard: catalog counts and the screens this user can open.
 * Data-quality alerts, catalog pages, and the migration card when an older catalog is found.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array $counts   total, publish, draft
 * @var array $catStat  top, sub
 * @var array $shared   item code => number of items using it
 * @var array $quality  ItemStats::quality
 * @var int $applications
 * @var array[] $pages  title, url, edit_url, shortcode
 * @var array|null $migration  what the older catalog on this site holds (null: none, or not allowed)
 * @var bool $migrated
 */
use DWCatalog\Admin\AdminApp;
use DWCatalog\Security\Access;
use DWCatalog\Support\Html;

$current     = 'dashboard';
$canProducts = $app->canView( 'products' );
$dupItems    = array_sum( $shared );
$tiles       = array(
	array( 'Products', (int) $counts['total'], array( 'view' => 'items' ), '' ),
	array( 'On the site', (int) $counts['publish'], array( 'view' => 'items' ), 'dwc-ok' ),
	array( 'Hidden', (int) $quality['hidden'], array( 'view' => 'items', 'tab' => 'hidden' ), '' ),
	array( 'Needs review', (int) $quality['review'], array( 'view' => 'items', 'tab' => 'review' ), $quality['review'] ? 'dwc-warn-text' : '' ),
);
// Data quality: only what needs attention is listed.
$alerts = array_filter( array(
	array( $quality['has_status'] ? (int) $quality['no_status'] : 0, $app->t( '%d products have no status.' ), array( 'view' => 'items', 'tab' => 'no_status' ) ),
	array( (int) $quality['duplicate_codes'], $app->t( '%d item codes are used by more than one product.' ), array( 'view' => 'items', 'tab' => 'review' ) ),
	array( (int) $quality['no_brand'], $app->t( '%d products have no brand.' ), array( 'view' => 'items' ) ),
	array( (int) $quality['no_image'], $app->t( '%d products have no photo.' ), array( 'view' => 'items', 'img' => 'no' ) ),
	array( (int) $quality['empty_categories'], $app->t( '%d categories have no products.' ), array( 'view' => 'categories' ) ),
), function ( $a ) {
	return $a[0] > 0;
} );
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
		<span><?php echo Html::e( $app->t( 'Applications' ) ); ?></span><strong><?php echo (int) $applications; ?></strong>
	</div>
</div>

<?php if ( $migration && ! empty( $migration['products']['found'] ) && ! $migrated ) : ?>
	<div class="dwc-notice dwc-notice-info">
		<?php echo Html::e( sprintf( $app->t( 'An older catalog with %d products was found on this site.' ), (int) $migration['products']['count'] ) ); ?>
		<a href="<?php echo Html::url( $app->url( array( 'view' => 'migration' ) ) ); ?>"><?php echo Html::e( $app->t( 'Open Migration' ) ); ?></a>
	</div>
<?php endif; ?>

<div class="dwc-two">
	<section class="dwc-panel dwc-pad" aria-labelledby="dwc-quality">
		<h2 id="dwc-quality"><?php echo Html::e( $app->t( 'Data quality' ) ); ?></h2>
		<?php if ( ! $alerts ) : ?>
			<p class="dwc-ok"><?php echo Html::e( $app->t( 'Nothing needs attention.' ) ); ?></p>
		<?php else : ?>
			<ul class="dwc-alerts">
				<?php foreach ( $alerts as $a ) : ?>
					<li>
						<?php if ( $canProducts ) : ?>
							<a href="<?php echo Html::url( $app->url( $a[2] + array( 'type' => $type ) ) ); ?>"><?php echo Html::e( sprintf( $a[1], $a[0] ) ); ?></a>
						<?php else : ?>
							<?php echo Html::e( sprintf( $a[1], $a[0] ) ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<section class="dwc-panel dwc-pad" aria-labelledby="dwc-pages">
		<h2 id="dwc-pages"><?php echo Html::e( $app->t( 'Catalog pages' ) ); ?></h2>
		<?php if ( ! $pages ) : ?>
			<p class="dwc-muted"><?php echo Html::e( $app->t( 'No page shows the catalog yet. Put a shortcode on a page to publish it.' ) ); ?></p>
		<?php else : ?>
			<ul class="dwc-alerts">
				<?php foreach ( $pages as $pg ) : ?>
					<li><a href="<?php echo Html::url( $pg['url'] ); ?>" target="_blank" rel="noopener"><?php echo Html::e( $pg['title'] ); ?></a> <code><?php echo Html::e( $pg['shortcode'] ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>

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
