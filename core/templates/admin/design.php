<?php
/**
 * Design settings.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 */
use DWCatalog\Front\Design;
use DWCatalog\Support\Html;

$current = 'design';
$d       = Design::get( $c->settings() );
$groups  = array(
	'General' => array(
		'accent'      => array( 'Accent color', 'color' ),
		'font_family' => array( 'Font family (blank = theme font)', 'text' ),
		'columns'     => array( 'Columns (max)', 'number' ),
		'grid_gap'    => array( 'Grid gap (px)', 'number' ),
	),
	'Card'    => array(
		'card_bg'           => array( 'Background', 'color' ),
		'card_border_color' => array( 'Border color', 'color' ),
		'card_border_width' => array( 'Border width (px)', 'number' ),
		'card_radius'       => array( 'Corner radius (px)', 'number' ),
		'card_padding'      => array( 'Padding (px)', 'number' ),
		'card_shadow'       => array( 'Shadow', 'shadow' ),
	),
	'Text'    => array(
		'title_size'        => array( 'Name size (px)', 'number' ),
		'title_weight'      => array( 'Name weight', 'number' ),
		'title_color'       => array( 'Name color', 'color' ),
		'field_label_size'  => array( 'Label size (px)', 'number' ),
		'field_label_color' => array( 'Label color', 'color' ),
		'field_value_size'  => array( 'Value size (px)', 'number' ),
		'field_value_color' => array( 'Value color', 'color' ),
	),
);
?>
<?php echo $app->head( $app->t( 'Design' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php echo $app->form( 'save_design', $type ); ?>
	<?php foreach ( $groups as $title => $inputs ) : ?>
		<h2><?php echo Html::e( $app->t( $title ) ); ?></h2>
		<table class="dwc-form">
			<?php foreach ( $inputs as $key => $spec ) : ?>
				<tr>
					<th><label for="dwc-d-<?php echo Html::attr( $key ); ?>"><?php echo Html::e( $app->t( $spec[0] ) ); ?></label></th>
					<td>
						<?php if ( 'shadow' === $spec[1] ) : ?>
							<select id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]">
								<?php foreach ( array( 'none', 'sm', 'md', 'lg' ) as $s ) : ?>
									<option value="<?php echo $s; ?>" <?php echo $d[ $key ] === $s ? 'selected' : ''; ?>><?php echo Html::e( $s ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( 'color' === $spec[1] ) : ?>
							<input type="text" id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]" value="<?php echo Html::attr( $d[ $key ] ); ?>" class="dwc-color" pattern="#[0-9a-fA-F]{3,8}|rgba?\(.+\)">
							<span class="dwc-swatch" style="background:<?php echo Html::attr( $d[ $key ] ); ?>"></span>
						<?php else : ?>
							<input type="<?php echo 'number' === $spec[1] ? 'number' : 'text'; ?>" id="dwc-d-<?php echo Html::attr( $key ); ?>" name="design[<?php echo Html::attr( $key ); ?>]" value="<?php echo Html::attr( $d[ $key ] ); ?>" class="dwc-narrow-<?php echo Html::attr( $spec[1] ); ?>">
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endforeach; ?>
	<p><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( $app->t( 'Save design' ) ); ?></button></p>
</form>
