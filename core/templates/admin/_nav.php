<?php
/**
 * Admin navigation.
 *
 * Main tabs: for hosts without their own menu. A host that lists these screens in its own menu
 * (WordPress: the DW Wholesale submenu) puts class "dwc-host-nav" on an ancestor and they are hidden.
 * Section tabs (Import | Export, Design | Shortcodes) always show.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var string $type
 * @var string $current
 */
use DWCatalog\Support\Html;

$navTabs = array(
	'items'      => array( 'All Items', 'catalog.edit' ),
	'edit'       => array( 'Add New', 'catalog.edit' ),
	'categories' => array( 'Categories', 'catalog.terms' ),
	'fields'     => array( 'Fields', 'catalog.manage' ),
	'import'     => array( 'Import / Export', 'catalog.import', array( 'import', 'export' ) ),
	'design'     => array( 'Settings', 'catalog.manage', array( 'design', 'reference' ) ),
);
$navSections = array(
	'import'    => array( 'Import', 'catalog.import' ),
	'export'    => array( 'Export', 'catalog.export' ),
	'design'    => array( 'Design', 'catalog.manage' ),
	'reference' => array( 'Shortcodes', 'catalog.edit' ),
);
$navGroups = array(
	'import'    => array( 'import', 'export' ),
	'export'    => array( 'import', 'export' ),
	'design'    => array( 'design', 'reference' ),
	'reference' => array( 'design', 'reference' ),
);
$navHost = $app->catalog()->host();
?>
<nav class="dwc-tabs" aria-label="<?php echo Html::attr( $app->t( 'Catalog' ) ); ?>">
	<?php foreach ( $navTabs as $navView => $navTab ) : ?>
		<?php
		$navMembers = isset( $navTab[2] ) ? $navTab[2] : array( $navView );
		$navTarget  = $navView;
		if ( ! $navHost->can( $navTab[1] ) ) {
			// e.g. export-only users: link to the first section they may open
			$navTarget = '';
			foreach ( $navMembers as $navM ) {
				if ( isset( $navSections[ $navM ] ) && $navHost->can( $navSections[ $navM ][1] ) ) {
					$navTarget = $navM;
					break;
				}
			}
			if ( '' === $navTarget ) {
				continue;
			}
		}
		$navActive = in_array( $current, $navMembers, true );
		?>
		<a href="<?php echo Html::url( $app->url( array( 'view' => $navTarget, 'type' => $type ) ) ); ?>" class="<?php echo $navActive ? 'is-active' : ''; ?>"<?php echo $navActive ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $app->t( $navTab[0] ) ); ?></a>
	<?php endforeach; ?>
</nav>
<?php if ( isset( $navGroups[ $current ] ) ) : ?>
	<nav class="dwc-sections">
		<?php foreach ( $navGroups[ $current ] as $navView ) : ?>
			<?php if ( $navHost->can( $navSections[ $navView ][1] ) ) : ?>
				<a href="<?php echo Html::url( $app->url( array( 'view' => $navView, 'type' => $type ) ) ); ?>" class="<?php echo $navView === $current ? 'is-active' : ''; ?>"<?php echo $navView === $current ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $app->t( $navSections[ $navView ][0] ) ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
	</nav>
<?php endif; ?>
