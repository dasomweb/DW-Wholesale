<?php
/**
 * Admin navigation.
 *
 * Main tabs: for hosts without their own menu. A host that lists these screens in its own menu
 * (WordPress: the DW Wholesale submenu) puts class "dwc-host-nav" on an ancestor and they are hidden.
 * Only menus the user may open are listed (Security\Access). Section tabs (Import | Export) always show.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var string $type
 * @var string $current  view being shown
 */
use DWCatalog\Admin\AdminApp;
use DWCatalog\Security\Access;
use DWCatalog\Support\Html;

$navCurrentMenu = isset( AdminApp::VIEWS[ $current ] ) ? AdminApp::VIEWS[ $current ] : '';
$navSections    = array(
	'import' => array( 'Import', Access::EDIT ),
	'export' => array( 'Export', Access::VIEW ),
);
?>
<nav class="dwc-tabs" aria-label="<?php echo Html::attr( $app->t( 'DW Wholesale' ) ); ?>">
	<?php foreach ( Access::MENUS as $navMenu => $navSpec ) : ?>
		<?php
		if ( ! $app->canView( $navMenu ) ) {
			continue;
		}
		$navView = AdminApp::MENU_VIEW[ $navMenu ];
		if ( 'import_export' === $navMenu && ! $app->canEdit( $navMenu ) ) {
			$navView = 'export'; // view-only users cannot import
		}
		$navActive = $navCurrentMenu === $navMenu;
		?>
		<a href="<?php echo Html::url( $app->url( array( 'view' => $navView, 'type' => $type ) ) ); ?>" class="<?php echo $navActive ? 'is-active' : ''; ?>"<?php echo $navActive ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $app->t( $navSpec[0] ) ); ?></a>
	<?php endforeach; ?>
</nav>
<?php if ( 'import_export' === $navCurrentMenu ) : ?>
	<nav class="dwc-sections">
		<?php foreach ( $navSections as $navView => $navSection ) : ?>
			<?php if ( $app->access()->level( 'import_export' ) >= $navSection[1] ) : ?>
				<a href="<?php echo Html::url( $app->url( array( 'view' => $navView, 'type' => $type ) ) ); ?>" class="<?php echo $navView === $current ? 'is-active' : ''; ?>"<?php echo $navView === $current ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $app->t( $navSection[0] ) ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
	</nav>
<?php endif; ?>
