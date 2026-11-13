<?php
/**
 * Screens that arrive in a later 3.0 phase: a short card saying what will be here.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var string $type
 * @var array $q
 */
use DWCatalog\Admin\AdminApp;
use DWCatalog\Security\Access;
use DWCatalog\Support\Html;

$current = $app->resolveView( $q );
$coming  = array(
	'customers' => array(
		'Wholesale applications, approval with a price group, customer accounts and quote requests.',
		array(
			'Application form with your own questions and file uploads',
			'New · More info requested · Approved · Rejected, with notes and history',
			'Approval creates the account and sends a one-time password link',
		),
	),
	'migration' => array(
		'Move products, categories, images and applications from DW Product Catalog and DW Wholesale Customer Management.',
		array(
			'Finds the previous plugins and their data automatically',
			'Dry run first: counts, problems and how to handle each one',
			'Runs in the background in batches, can be resumed and undone',
		),
	),
	'presets' => array(
		'Ready-made setups for each kind of wholesale business.',
		array(
			'Fields, option columns, category groups, filters and price groups in one bundle',
			'Preview the changes before applying; a restore point is saved each time',
			'Export and import the current setup as a .json file',
		),
	),
);
$menu = AdminApp::VIEWS[ $current ];
list( $lead, $points ) = $coming[ $current ];
?>
<?php echo $app->head( $app->t( Access::MENUS[ $menu ][0] ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="dwc-card dwc-soon">
	<div class="dwc-card-body">
		<span class="dwc-tag"><?php echo Html::e( $app->t( 'Coming in a later 3.0 update' ) ); ?></span>
		<p class="dwc-soon-lead"><?php echo Html::e( $app->t( $lead ) ); ?></p>
		<ul>
			<?php foreach ( $points as $point ) : ?>
				<li><?php echo Html::e( $app->t( $point ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
