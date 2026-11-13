<?php
/**
 * Design (M5): the default design and named presets, with a live desktop / tablet / phone preview.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var array $presets slug => name ('default' first)
 * @var string $preset  the one being edited
 */
use DWCatalog\Front\Design;
use DWCatalog\Support\Html;

$current = 'design';
$d       = Design::get( $c->settings(), $preset );
$t       = function ( $s ) use ( $app ) {
	return Html::e( $app->t( $s ) );
};
$label   = function ( $slug, $name ) use ( $app ) {
	return 'default' === $slug ? $app->t( 'Default design' ) : $name;
};
$choices = array(
	'button_style' => array( 'fill' => $app->t( 'Filled' ), 'outline' => $app->t( 'Outline' ), 'tint' => $app->t( 'Light background' ) ),
	'text_size'    => array( 's' => $app->t( 'Small' ), 'm' => $app->t( 'Medium' ), 'l' => $app->t( 'Large' ) ),
	'shadow'       => array( 'none' => $app->t( 'None' ), 'soft' => $app->t( 'Soft' ) ),
	'font_set'     => array(),
);
foreach ( Design::FONT_SETS as $key => $spec ) {
	$choices['font_set'][ $key ] = 'theme' === $key ? $app->t( 'Theme font' ) : $spec[0];
}
$groups = array(
	$app->t( 'Colors and buttons' ) => array(
		'accent'       => array( $app->t( 'Main color' ), 'color' ),
		'border_color' => array( $app->t( 'Border color' ), 'color' ),
		'button_style' => array( $app->t( 'Button style' ), 'choice' ),
	),
	$app->t( 'Text' )               => array(
		'font_set'  => array( $app->t( 'Font' ), 'choice' ),
		'text_size' => array( $app->t( 'Text size' ), 'choice' ),
	),
	$app->t( 'Corners and shadow' ) => array(
		'radius_card'   => array( $app->t( 'Card corners (px)' ), 'number' ),
		'radius_button' => array( $app->t( 'Button corners (px)' ), 'number' ),
		'radius_input'  => array( $app->t( 'Input corners (px)' ), 'number' ),
		'shadow'        => array( $app->t( 'Card shadow' ), 'choice' ),
	),
	$app->t( 'Other settings' )     => array(
		'columns'           => array( $app->t( 'Columns (max)' ), 'number' ),
		'grid_gap'          => array( $app->t( 'Grid gap (px)' ), 'number' ),
		'card_bg'           => array( $app->t( 'Card background' ), 'color' ),
		'title_color'       => array( $app->t( 'Name color' ), 'color' ),
		'title_size'        => array( $app->t( 'Name size (px)' ), 'number' ),
		'title_weight'      => array( $app->t( 'Name weight' ), 'number' ),
		'field_label_color' => array( $app->t( 'Label color' ), 'color' ),
		'field_value_color' => array( $app->t( 'Value color' ), 'color' ),
		'card_border_width' => array( $app->t( 'Border width (px)' ), 'number' ),
		'card_padding'      => array( $app->t( 'Extra card padding (px)' ), 'number' ),
	),
);
$ro = $app->readonly();
?>
<?php echo $app->head( $app->t( 'Design' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<nav class="dwc-statustabs" aria-label="<?php echo Html::attr( $app->t( 'Designs' ) ); ?>">
	<?php foreach ( $presets as $slug => $name ) : ?>
		<a class="<?php echo $preset === $slug ? 'is-on' : ''; ?>" href="<?php echo Html::url( $app->url( array( 'view' => 'design', 'preset' => 'default' === $slug ? null : $slug ) ) ); ?>"<?php echo $preset === $slug ? ' aria-current="page"' : ''; ?>><?php echo Html::e( $label( $slug, $name ) ); ?><?php if ( 'default' !== $slug ) : ?> <span class="dwc-mono">design="<?php echo Html::e( $slug ); ?>"</span><?php endif; ?></a>
	<?php endforeach; ?>
</nav>

<div class="dwc-design">
	<div class="dwc-design-form">
		<?php echo $app->form( 'save_design', $type, false, 'id="dwc-design-form" data-dwc-design' ); ?>
			<input type="hidden" name="preset" value="<?php echo Html::attr( $preset ); ?>">
			<?php foreach ( $groups as $title => $inputs ) : ?>
				<div class="dwc-panel dwc-pad">
					<h2><?php echo Html::e( $title ); ?></h2>
					<div class="dwc-design-grid">
						<?php foreach ( $inputs as $key => $spec ) : ?>
							<div class="dwc-field">
								<label for="dwc-d-<?php echo Html::attr( $key ); ?>"><?php echo Html::e( $spec[0] ); ?></label>
								<?php if ( 'choice' === $spec[1] ) : ?>
									<select id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]" data-dwc-dkey="<?php echo Html::attr( $key ); ?>">
										<?php foreach ( $choices[ $key ] as $val => $text ) : ?>
											<option value="<?php echo Html::attr( $val ); ?>" <?php echo (string) $d[ $key ] === (string) $val ? 'selected' : ''; ?>><?php echo Html::e( $text ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'color' === $spec[1] ) : ?>
									<span class="dwc-colorpick">
										<input type="color" value="<?php echo Html::attr( 7 === strlen( $d[ $key ] ) ? $d[ $key ] : '#000000' ); ?>" data-dwc-colorpick aria-label="<?php echo Html::attr( $spec[0] ); ?>">
										<input type="text" id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]" value="<?php echo Html::attr( $d[ $key ] ); ?>" class="dwc-color" pattern="#[0-9a-fA-F]{3,8}|rgba?\(.+\)" data-dwc-dkey="<?php echo Html::attr( $key ); ?>">
									</span>
								<?php else : ?>
									<?php $range = isset( Design::RANGES[ $key ] ) ? Design::RANGES[ $key ] : array( 0, 100 ); ?>
									<input type="number" id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]" value="<?php echo (int) $d[ $key ]; ?>" min="<?php echo (int) $range[0]; ?>" max="<?php echo (int) $range[1]; ?>" class="dwc-narrow-number" data-dwc-dkey="<?php echo Html::attr( $key ); ?>">
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<p class="dwc-actions">
				<button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( sprintf( $app->t( 'Save "%s"' ), $label( $preset, $presets[ $preset ] ) ) ); ?></button>
			</p>
		</form>
		<?php if ( ! $ro ) : ?>
			<div class="dwc-panel dwc-pad">
				<h2><?php echo $t( 'Save as a new preset' ); ?></h2>
				<p class="dwc-muted"><?php echo $t( 'Keeps the values above under a name. A catalog then uses it with design="…" or by picking it in a saved catalog.' ); ?></p>
				<?php echo $app->form( 'create_design', $type, false, 'class="dwc-inline" data-dwc-design-copy' ); ?>
					<input type="hidden" name="from" value="<?php echo Html::attr( $preset ); ?>">
					<label class="dwc-sr" for="dwc-preset-name"><?php echo $t( 'Preset name' ); ?></label>
					<input type="text" id="dwc-preset-name" name="name" maxlength="60" required placeholder="<?php echo Html::attr( $app->t( 'e.g. Frozen page' ) ); ?>">
					<button type="submit" class="dwc-btn"><?php echo $t( 'Save as preset' ); ?></button>
				</form>
				<?php if ( 'default' !== $preset ) : ?>
					<?php echo $app->form( 'delete_design', $type, false, 'class="dwc-delete"' ); ?>
						<input type="hidden" name="preset" value="<?php echo Html::attr( $preset ); ?>">
						<button type="submit" class="dwc-link dwc-danger" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Delete this preset? Catalogs using it go back to the default design.' ) ); ?>"><?php echo $t( 'Delete this preset' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="dwc-design-preview">
		<div class="dwc-panel dwc-pad">
			<div class="dwc-preview-bar">
				<h2><?php echo $t( 'Preview' ); ?></h2>
				<div class="dwc-sections" role="group" aria-label="<?php echo Html::attr( $app->t( 'Preview width' ) ); ?>">
					<?php foreach ( array( '1200' => 'Desktop', '834' => 'Tablet', '390' => 'Phone' ) as $w => $name ) : ?>
						<a href="#" class="<?php echo '1200' === $w ? 'is-active' : ''; ?>" data-dwc-preview-width="<?php echo Html::attr( $w ); ?>"><?php echo $t( $name ); ?> <span class="dwc-muted"><?php echo (int) $w; ?>px</span></a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="dwc-preview-frame" data-dwc-preview-frame data-dwc-design-spec="<?php echo Html::attr( (string) json_encode( Design::previewSpec() ) ); ?>">
				<div class="dwc-preview-stage" data-dwc-preview-stage style="width:1200px"><?php echo $c->front()->preview( $preset ); // phpcs:ignore — core escapes its own output ?></div>
			</div>
			<p class="dwc-muted"><?php echo $t( 'The preview follows the form as you change it. Nothing is saved until you click Save.' ); ?></p>
		</div>
	</div>
</div>
