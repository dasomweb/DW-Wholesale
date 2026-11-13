<?php
/**
 * Migration from the older catalog on this site: find → fields → groups → check → run → finish → undo.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var DWCatalog\Migration\Migrator|null $m
 * @var string $step
 * @var string[] $steps
 * @var array $state
 * @var array $detect
 * @var array $report   (step check)
 * @var array $compare  (step run, when finished)
 * @var array $pages    (step finish)
 */
use DWCatalog\Fields\FieldConfig;
use DWCatalog\Migration\Migrator;
use DWCatalog\Support\Html;

$current = 'migration';
$canEdit = $app->canEdit( 'migration' );
$t       = function ( $s ) use ( $app ) {
	return Html::e( $app->t( $s ) );
};
$stepUrl = function ( $s ) use ( $app ) {
	return Html::url( $app->url( array( 'view' => 'migration', 'step' => $s ) ) );
};
$form    = function ( $op, $attrs = '' ) use ( $app, $type ) {
	return $app->form( 'migrate', $type, false, $attrs ) . '<input type="hidden" name="op" value="' . Html::attr( $op ) . '">';
};
$labels  = array(
	'detect'   => $app->t( 'Find' ),
	'mapping'  => $app->t( 'Fields' ),
	'groups'   => $app->t( 'Groups' ),
	'check'    => $app->t( 'Check' ),
	'run'      => $app->t( 'Run' ),
	'finish'   => $app->t( 'Finish' ),
	'rollback' => $app->t( 'Undo' ),
);
?>
<?php echo $app->head( $app->t( 'Migration' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php if ( ! $m ) : ?>
	<div class="dwc-panel dwc-pad"><p class="dwc-empty"><?php echo $t( 'There is nothing to migrate on this site.' ); ?></p></div>
	<?php return; ?>
<?php endif; ?>

<?php
$run    = $state['run'];
$has    = $m->hasData();
$found  = ! empty( $detect['products']['found'] );
?>
<ol class="dwc-steps" aria-label="<?php echo Html::attr( $app->t( 'Migration steps' ) ); ?>">
	<?php foreach ( $steps as $i => $s ) : ?>
		<li class="<?php echo $s === $step ? 'is-on' : ''; ?>">
			<a href="<?php echo $stepUrl( $s ); ?>"<?php echo $s === $step ? ' aria-current="step"' : ''; ?>><span><?php echo (int) $i + 1; ?></span><?php echo Html::e( $labels[ $s ] ); ?></a>
		</li>
	<?php endforeach; ?>
</ol>

<p class="dwc-notice dwc-notice-info"><?php echo $t( 'The original products, categories and applications are only read. Nothing of the original is changed or deleted, and everything this migration creates can be undone.' ); ?></p>

<?php if ( 'detect' === $step ) : ?>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'What was found on this site' ); ?></h2>
		<table class="dwc-table">
			<thead><tr><th><?php echo $t( 'Source' ); ?></th><th><?php echo $t( 'Found' ); ?></th><th class="dwc-r"><?php echo $t( 'Count' ); ?></th><th><?php echo $t( 'Details' ); ?></th></tr></thead>
			<tbody>
				<?php
				$rows = array(
					array( $app->t( 'Products (DW Product Catalog)' ), $detect['products'], sprintf( $app->t( '%1$d visible, %2$d hidden, %3$d with a photo' ), (int) $detect['products']['public'], (int) $detect['products']['hidden'], (int) $detect['products']['with_image'] ) ),
					array( $app->t( 'Categories' ), $detect['categories'], '' ),
					array( $app->t( 'Applications (DW Wholesale Customer Management)' ), $detect['applications'], isset( $detect['applications']['table'] ) ? (string) $detect['applications']['table'] : '' ),
					array( $app->t( 'Registered users (DW Admin)' ), $detect['users'], $app->t( 'Listed here only. Accounts are connected to customers in the customer phase.' ) ),
				);
				?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td class="dwc-strong"><?php echo Html::e( $r[0] ); ?></td>
						<td><?php echo ! empty( $r[1]['found'] ) ? '<span class="dwc-pill dwc-pill-on"><span aria-hidden="true"></span>' . $t( 'Yes' ) . '</span>' : '<span class="dwc-pill dwc-pill-off"><span aria-hidden="true"></span>' . $t( 'No' ) . '</span>'; ?></td>
						<td class="dwc-r dwc-mono"><?php echo (int) $r[1]['count']; ?></td>
						<td class="dwc-muted"><?php echo Html::e( $r[2] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $found ) : ?>
			<p class="dwc-actions"><a class="dwc-btn dwc-btn-primary" href="<?php echo $stepUrl( 'mapping' ); ?>"><?php echo $t( 'Next: fields' ); ?></a></p>
		<?php else : ?>
			<p class="dwc-empty"><?php echo $t( 'No products of the older catalog were found, so there is nothing to move.' ); ?></p>
		<?php endif; ?>
	</div>

<?php elseif ( 'mapping' === $step ) : ?>
	<?php
	$targets = $c->fields()->fieldsByKey( $type );
	$source  = $m->source()->productFields();
	?>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Where each field goes' ); ?></h2>
		<p class="dwc-muted"><?php echo $t( 'Each field of the older catalog is copied into the field you pick. Fields marked Private are never shown on the site.' ); ?></p>
		<?php echo $form( 'mapping' ); ?>
			<table class="dwc-table">
				<thead><tr><th><?php echo $t( 'Old field' ); ?></th><th><?php echo $t( 'Goes into' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $source as $key => $label ) : ?>
						<tr>
							<td><span class="dwc-strong"><?php echo Html::e( $label ); ?></span> <span class="dwc-mono dwc-muted"><?php echo Html::e( $key ); ?></span>
								<?php if ( Migrator::isInternal( $key ) ) : ?><em class="dwc-tag"><?php echo $t( 'Internal' ); ?></em><?php endif; ?></td>
							<td>
								<select name="map[<?php echo Html::attr( $key ); ?>]" aria-label="<?php echo Html::attr( $label ); ?>">
									<option value=""><?php echo $t( '— Do not import —' ); ?></option>
									<?php foreach ( $targets as $tk => $f ) : ?>
										<option value="<?php echo Html::attr( $tk ); ?>" <?php echo isset( $state['mapping'][ $key ] ) && $state['mapping'][ $key ] === $tk ? 'selected' : ''; ?>><?php echo Html::e( $f['label'] . ( FieldConfig::isPublic( $f ) ? '' : ' · ' . $app->t( 'Private' ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr><td class="dwc-strong"><?php echo $t( 'Description' ); ?></td><td class="dwc-muted"><?php echo $t( 'Product description (always copied)' ); ?></td></tr>
					<tr><td class="dwc-strong"><?php echo $t( 'Main photo' ); ?></td><td class="dwc-muted"><?php echo $t( 'Product photo, converted to WebP where the server can' ); ?></td></tr>
				</tbody>
			</table>
			<p class="dwc-actions"><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo $t( 'Save and continue' ); ?></button></p>
		</form>
	</div>

<?php elseif ( 'groups' === $step ) : ?>
	<?php
	$groups  = $m->groups();
	$groupOf = $m->groupOf();
	$cats    = $m->source()->categories();
	?>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Category groups' ); ?></h2>
		<p class="dwc-muted"><?php echo $t( 'Groups were read from the filter settings of the catalog pages. A category in no group is placed under "Uncategorized". Change anything that is not right.' ); ?></p>
		<?php echo $form( 'groups' ); ?>
			<div class="dwc-grouplist">
				<?php foreach ( $groups as $g ) : ?>
					<label><span class="dwc-sr"><?php echo $t( 'Group name' ); ?></span><input type="text" name="names[<?php echo Html::attr( $g['slug'] ); ?>]" value="<?php echo Html::attr( $g['name'] ); ?>"> <span class="dwc-muted"><?php echo Html::e( sprintf( $app->t( '%d categories' ), count( $g['cats'] ) ) ); ?></span></label>
				<?php endforeach; ?>
				<label><span class="dwc-sr"><?php echo $t( 'New group' ); ?></span><input type="text" name="names[+]" value="" placeholder="<?php echo Html::attr( $app->t( 'Add a group…' ) ); ?>"></label>
			</div>
			<div class="dwc-scroll">
				<table class="dwc-table">
					<thead><tr><th><?php echo $t( 'Category' ); ?></th><th class="dwc-r"><?php echo $t( 'Products' ); ?></th><th><?php echo $t( 'Group' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $cats as $cat ) : ?>
							<?php $slug = isset( $groupOf[ (int) $cat['id'] ] ) ? $groupOf[ (int) $cat['id'] ] : ''; ?>
							<tr>
								<td><?php echo Html::e( $cat['name'] ); ?></td>
								<td class="dwc-r dwc-mono"><?php echo (int) $cat['count']; ?></td>
								<td>
									<select name="assign[<?php echo (int) $cat['id']; ?>]" aria-label="<?php echo Html::attr( sprintf( $app->t( 'Group of %s' ), $cat['name'] ) ); ?>">
										<option value=""><?php echo $t( 'Uncategorized' ); ?></option>
										<?php foreach ( $groups as $g ) : ?>
											<option value="<?php echo Html::attr( $g['slug'] ); ?>" <?php echo $slug === $g['slug'] ? 'selected' : ''; ?>><?php echo Html::e( $g['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="dwc-actions">
				<button type="submit" class="dwc-btn dwc-btn-primary"><?php echo $t( 'Save and continue' ); ?></button>
				<button type="submit" name="stay" value="1" class="dwc-btn"><?php echo $t( 'Save' ); ?></button>
			</p>
		</form>
		<?php echo $form( 'reset_groups' ); ?>
			<button type="submit" class="dwc-link"><?php echo $t( 'Read the groups from the pages again' ); ?></button>
		</form>
	</div>

<?php elseif ( 'check' === $step ) : ?>
	<?php
	$sum    = $report['summary'];
	$iss    = $report['issues'];
	$ch     = $report['choices'];
	$choose = array(
		'empty_status'   => array( $app->t( 'Products with no status' ), $iss['empty_status'], array( 'keep' => $app->t( 'Leave empty' ), 'active' => $app->t( 'Set to Active' ), 'hidden' => $app->t( 'Hide from the site' ) ) ),
		'duplicate_code' => array( $app->t( 'Products sharing an item code' ), $iss['duplicate_code'], array( 'review' => $app->t( 'Keep all and mark for review' ), 'latest' => $app->t( 'Keep only the latest' ) ) ),
		'dash_slug'      => array( $app->t( 'Addresses ending in "-1"' ), $iss['dash_slug'], array( 'keep' => $app->t( 'Leave as they are' ), 'clean' => $app->t( 'Tidy with the item code' ) ) ),
	);
	$info   = array(
		'empty_brand'    => $app->t( 'No brand' ),
		'no_image'       => $app->t( 'No photo' ),
		'hidden'         => $app->t( 'Hidden in the old catalog' ),
		'no_category'    => $app->t( 'In no category' ),
		'empty_category' => $app->t( 'Categories with no products' ),
		'ungrouped'      => $app->t( 'Categories in no group' ),
	);
	?>
	<div class="dwc-stats">
		<div class="dwc-stat"><span><?php echo $t( 'Products' ); ?></span><strong><?php echo (int) $sum['products']; ?></strong></div>
		<div class="dwc-stat"><span><?php echo $t( 'Will be moved' ); ?></span><strong class="dwc-ok"><?php echo (int) $sum['migrate']; ?></strong></div>
		<div class="dwc-stat"><span><?php echo $t( 'Will be skipped' ); ?></span><strong><?php echo (int) $sum['skip']; ?></strong></div>
		<div class="dwc-stat"><span><?php echo $t( 'Categories' ); ?></span><strong><?php echo (int) $sum['categories']; ?> <small><?php echo Html::e( sprintf( $app->t( 'in %d groups' ), $sum['groups'] ) ); ?></small></strong></div>
		<div class="dwc-stat"><span><?php echo $t( 'Photos' ); ?></span><strong><?php echo (int) $sum['images']; ?></strong></div>
		<div class="dwc-stat"><span><?php echo $t( 'Applications' ); ?></span><strong><?php echo (int) $report['detect']['applications']['count']; ?></strong></div>
	</div>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Check before anything is written' ); ?></h2>
		<p class="dwc-muted"><?php echo $t( 'Nothing has been written yet. Choose how each problem is handled; the numbers above follow your choices.' ); ?></p>
		<?php echo $form( 'choices' ); ?>
			<table class="dwc-table">
				<thead><tr><th><?php echo $t( 'Problem' ); ?></th><th class="dwc-r"><?php echo $t( 'Count' ); ?></th><th><?php echo $t( 'What to do' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $choose as $key => $spec ) : ?>
						<tr>
							<td class="dwc-strong"><?php echo Html::e( $spec[0] ); ?></td>
							<td class="dwc-r dwc-mono"><?php echo (int) $spec[1]; ?></td>
							<td>
								<select name="choices[<?php echo Html::attr( $key ); ?>]" aria-label="<?php echo Html::attr( $spec[0] ); ?>">
									<?php foreach ( $spec[2] as $val => $label ) : ?>
										<option value="<?php echo Html::attr( $val ); ?>" <?php echo $ch[ $key ] === $val ? 'selected' : ''; ?>><?php echo Html::e( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $info as $key => $label ) : ?>
						<tr><td><?php echo Html::e( $label ); ?></td><td class="dwc-r dwc-mono"><?php echo (int) $iss[ $key ]; ?></td><td class="dwc-muted"><?php echo $t( 'Moved as it is — fix later in Products' ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="dwc-actions"><button type="submit" class="dwc-btn"><?php echo $t( 'Apply choices' ); ?></button></p>
		</form>
		<div class="dwc-actions">
			<?php echo $form( 'csv' ); ?><button type="submit" class="dwc-btn"><?php echo $t( 'Download the check as CSV' ); ?></button></form>
			<?php echo $form( 'start' ); ?><button type="submit" class="dwc-btn dwc-btn-primary" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Start the migration with these choices?' ) ); ?>"><?php echo Html::e( $has ? $app->t( 'Run again (updates, no duplicates)' ) : $app->t( 'Start the migration' ) ); ?></button></form>
		</div>
	</div>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( 'Products per category' ); ?></h2>
		<div class="dwc-scroll">
			<table class="dwc-table">
				<thead><tr><th><?php echo $t( 'Group' ); ?></th><th><?php echo $t( 'Category' ); ?></th><th class="dwc-r"><?php echo $t( 'Old catalog' ); ?></th><th class="dwc-r"><?php echo $t( 'Will be moved' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $report['categories'] as $cat ) : ?>
						<tr><td class="dwc-muted"><?php echo '' !== $cat['group'] ? Html::e( $cat['group'] ) : $t( 'Uncategorized' ); ?></td><td><?php echo Html::e( $cat['name'] ); ?></td><td class="dwc-r dwc-mono"><?php echo (int) $cat['source']; ?></td><td class="dwc-r dwc-mono"><?php echo (int) $cat['planned']; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php elseif ( 'run' === $step ) : ?>
	<?php
	$pct    = $run['total'] ? min( 100, (int) floor( 100 * $run['done'] / $run['total'] ) ) : 0;
	$counts = array( 'created' => $app->t( 'Created' ), 'updated' => $app->t( 'Updated' ), 'skipped' => $app->t( 'Skipped' ), 'images' => $app->t( 'Photos' ), 'image_errors' => $app->t( 'Photo problems' ), 'applications' => $app->t( 'Applications' ), 'files' => $app->t( 'Attachments' ) );
	?>
	<div class="dwc-panel dwc-pad" data-dwc-migrate="<?php echo Html::attr( $run['status'] ); ?>" data-dwc-post="<?php echo Html::url( $c->host()->adminPostUrl() ); ?>" data-dwc-token="<?php echo Html::attr( $c->csrf()->token( 'migrate' ) ); ?>" data-dwc-done-url="<?php echo $stepUrl( 'run' ); ?>">
		<h2><?php echo $t( 'Run' ); ?></h2>
		<?php if ( 'idle' === $run['status'] ) : ?>
			<p class="dwc-empty"><?php echo $t( 'The migration has not been started. Go through the check first.' ); ?></p>
			<p class="dwc-actions"><a class="dwc-btn dwc-btn-primary" href="<?php echo $stepUrl( 'check' ); ?>"><?php echo $t( 'Go to the check' ); ?></a></p>
		<?php else : ?>
			<p class="dwc-muted"><?php echo $t( '50 records at a time. You can pause, close this page and continue later — it goes on in the background and never creates a record twice.' ); ?></p>
			<div class="dwc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $pct; ?>" aria-label="<?php echo Html::attr( $app->t( 'Migration progress' ) ); ?>"><span data-dwc-bar style="width:<?php echo (int) $pct; ?>%"></span></div>
			<p class="dwc-progress-text"><strong data-dwc-done><?php echo (int) $run['done']; ?></strong> / <span data-dwc-total><?php echo (int) $run['total']; ?></span> <span class="dwc-muted" data-dwc-status aria-live="polite"><?php echo 'done' === $run['status'] ? $t( 'Finished.' ) : ( 'paused' === $run['status'] ? $t( 'Paused.' ) : '' ); ?></span></p>
			<div class="dwc-stats">
				<?php foreach ( $counts as $key => $label ) : ?>
					<div class="dwc-stat"><span><?php echo Html::e( $label ); ?></span><strong data-dwc-count="<?php echo Html::attr( $key ); ?>"><?php echo (int) $run['counts'][ $key ]; ?></strong></div>
				<?php endforeach; ?>
			</div>
			<?php if ( $canEdit && 'done' !== $run['status'] ) : ?>
				<p class="dwc-actions">
					<button type="button" class="dwc-btn" data-dwc-pause <?php echo 'running' === $run['status'] ? '' : 'hidden'; ?>><?php echo $t( 'Pause' ); ?></button>
					<button type="button" class="dwc-btn dwc-btn-primary" data-dwc-resume <?php echo 'paused' === $run['status'] ? '' : 'hidden'; ?>><?php echo $t( 'Continue' ); ?></button>
				</p>
			<?php endif; ?>
			<ul class="dwc-errors" data-dwc-errors><?php foreach ( array_slice( $run['errors'], -20 ) as $e ) : ?><li><?php echo Html::e( $e ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
	</div>
	<?php if ( isset( $compare ) ) : ?>
		<div class="dwc-panel dwc-pad">
			<h2><?php echo $t( 'Result: old catalog and new catalog side by side' ); ?></h2>
			<table class="dwc-table">
				<thead><tr><th></th><th class="dwc-r"><?php echo $t( 'Expected' ); ?></th><th class="dwc-r"><?php echo $t( 'Moved' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( array( 'products' => $app->t( 'Products' ), 'categories' => $app->t( 'Categories' ), 'images' => $app->t( 'Photos' ), 'applications' => $app->t( 'Applications' ) ) as $key => $label ) : ?>
						<?php $same = $compare['totals'][ $key ][0] === $compare['totals'][ $key ][1]; ?>
						<tr><td class="dwc-strong"><?php echo Html::e( $label ); ?></td><td class="dwc-r dwc-mono"><?php echo (int) $compare['totals'][ $key ][0]; ?></td><td class="dwc-r dwc-mono"><?php echo (int) $compare['totals'][ $key ][1]; ?></td><td><?php echo $same ? '<span class="dwc-pill dwc-pill-on"><span aria-hidden="true"></span>' . $t( 'Same' ) . '</span>' : '<span class="dwc-pill dwc-pill-warn"><span aria-hidden="true"></span>' . $t( 'Different' ) . '</span>'; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="dwc-scroll">
				<table class="dwc-table">
					<thead><tr><th><?php echo $t( 'Group' ); ?></th><th><?php echo $t( 'Category' ); ?></th><th class="dwc-r"><?php echo $t( 'Expected' ); ?></th><th class="dwc-r"><?php echo $t( 'Moved' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $compare['categories'] as $cat ) : ?>
							<tr class="<?php echo (int) $cat['planned'] === (int) $cat['migrated'] ? '' : 'dwc-row-warn'; ?>"><td class="dwc-muted"><?php echo '' !== $cat['group'] ? Html::e( $cat['group'] ) : $t( 'Uncategorized' ); ?></td><td><?php echo Html::e( $cat['name'] ); ?></td><td class="dwc-r dwc-mono"><?php echo (int) $cat['planned']; ?></td><td class="dwc-r dwc-mono"><?php echo (int) $cat['migrated']; ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="dwc-actions"><a class="dwc-btn dwc-btn-primary" href="<?php echo $stepUrl( 'finish' ); ?>"><?php echo $t( 'Next: finish' ); ?></a> <a class="dwc-btn" href="<?php echo Html::url( $app->url( array( 'view' => 'items', 'type' => $type ) ) ); ?>"><?php echo $t( 'Open Products' ); ?></a></p>
		</div>
	<?php endif; ?>

<?php elseif ( 'finish' === $step ) : ?>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( '1. Replace the page blocks' ); ?></h2>
		<p class="dwc-muted"><?php echo $t( 'Each catalog page gets a shortcode in place of its query block. The page as it is now is kept, and Undo puts it back.' ); ?></p>
		<?php if ( ! $pages ) : ?>
			<p class="dwc-empty"><?php echo $t( 'No page with a query block was found.' ); ?></p>
		<?php else : ?>
			<?php echo $form( 'pages' ); ?>
				<table class="dwc-table">
					<thead><tr><th class="dwc-check"></th><th><?php echo $t( 'Page' ); ?></th><th><?php echo $t( 'Shortcode it gets' ); ?></th><th><?php echo $t( 'State' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<tr>
								<td class="dwc-check"><input type="checkbox" name="pages[]" value="<?php echo (int) $page['id']; ?>" <?php echo $page['replaced'] ? 'disabled' : 'checked'; ?> aria-label="<?php echo Html::attr( $page['title'] ); ?>"></td>
								<td><a href="<?php echo Html::url( $page['url'] ); ?>" target="_blank" rel="noopener"><?php echo Html::e( $page['title'] ); ?></a></td>
								<td><code><?php echo Html::e( $page['shortcode'] ); ?></code></td>
								<td><?php echo $page['replaced'] ? $t( 'Replaced' ) : Html::e( sprintf( $app->t( '%d block(s) to replace' ), (int) $page['blocks'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="dwc-actions"><button type="submit" class="dwc-btn dwc-btn-primary" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Replace the blocks on the selected pages?' ) ); ?>"><?php echo $t( 'Replace on the selected pages' ); ?></button></p>
			</form>
		<?php endif; ?>
	</div>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( '2. Old addresses and the open product list' ); ?></h2>
		<?php echo $form( 'flags' ); ?>
			<label class="dwc-check-line"><input type="checkbox" name="redirects" value="1" <?php echo $state['finish']['redirects'] ? 'checked' : ''; ?>> <span><strong><?php echo $t( 'Send old product addresses to the new ones (301)' ); ?></strong><br><span class="dwc-muted"><?php echo $t( 'Visitors and search engines following an old link land on the same product.' ); ?></span></span></label>
			<label class="dwc-check-line"><input type="checkbox" name="rest_block" value="1" <?php echo $state['finish']['rest_block'] ? 'checked' : ''; ?>> <span><strong><?php echo $t( 'Close the open product list of the old catalog' ); ?></strong><br><span class="dwc-muted"><?php echo $t( 'Until now anyone could read every product through the site\'s data address. Only signed-in staff can after this.' ); ?></span></span></label>
			<p class="dwc-actions"><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo $t( 'Save' ); ?></button></p>
		</form>
	</div>
	<div class="dwc-panel dwc-pad">
		<h2><?php echo $t( '3. Switch off the older plugins' ); ?></h2>
		<p><?php echo $t( 'When the pages look right, switch off DW Product Catalog and DW Wholesale Customer Management on the Plugins screen. Do not delete them until you are sure: Undo needs their data to still be there.' ); ?></p>
	</div>

<?php elseif ( 'rollback' === $step ) : ?>
	<div class="dwc-panel dwc-pad" data-dwc-rollback data-dwc-post="<?php echo Html::url( $c->host()->adminPostUrl() ); ?>" data-dwc-token="<?php echo Html::attr( $c->csrf()->token( 'migrate' ) ); ?>" data-dwc-done-url="<?php echo Html::url( $app->url( array( 'view' => 'migration', 'step' => 'detect', 'msg' => 'rolled' ) ) ); ?>">
		<h2><?php echo $t( 'Undo the migration' ); ?></h2>
		<p><?php echo $t( 'This deletes the products, photos, categories and applications this migration created, puts the replaced pages back, and switches the redirects off. Products you added yourself and the original data stay.' ); ?></p>
		<?php if ( ! $has ) : ?>
			<p class="dwc-empty"><?php echo $t( 'There is nothing to undo.' ); ?></p>
		<?php elseif ( $canEdit ) : ?>
			<p class="dwc-actions"><button type="button" class="dwc-btn dwc-btn-danger" data-dwc-rollback-start data-dwc-confirm-text="<?php echo Html::attr( $app->t( 'Undo the migration? Everything it created is deleted.' ) ); ?>"><?php echo $t( 'Undo the migration' ); ?></button> <span class="dwc-muted" data-dwc-status aria-live="polite"></span></p>
		<?php endif; ?>
	</div>
<?php endif; ?>
