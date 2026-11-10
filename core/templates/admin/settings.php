<?php
/**
 * Settings: admin language, site widget language, the site's own widget wording.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 * @var bool $readonly
 */
use DWCatalog\I18n\FrontStrings;
use DWCatalog\I18n\Languages;
use DWCatalog\Support\Html;

$current = 'settings';
$i18n    = Languages::get( $c->settings() );
$choices = function ( string $follow, string $selected ) {
	$html = '<option value="' . Languages::FOLLOW . '"' . ( Languages::FOLLOW === $selected ? ' selected' : '' ) . '>' . Html::e( $follow ) . '</option>';
	foreach ( Languages::AVAILABLE as $code => $spec ) {
		$html .= '<option value="' . Html::attr( $code ) . '"' . ( $code === $selected ? ' selected' : '' ) . ' lang="' . Html::attr( $code ) . '">' . Html::e( $spec[0] ) . '</option>';
	}
	return $html;
};
?>
<?php echo $app->head( $app->t( 'Settings' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php echo $app->form( 'save_settings', $type ); ?>
	<section class="dwc-card">
		<h2><?php echo Html::e( $app->t( 'Languages' ) ); ?></h2>
		<div class="dwc-card-body">
			<table class="dwc-form">
				<tr>
					<th><label for="dwc-lang-admin"><?php echo Html::e( $app->t( 'Admin screens' ) ); ?></label></th>
					<td>
						<select id="dwc-lang-admin" name="i18n[admin]"><?php echo $choices( $app->t( "Follow each user's WordPress language" ), $i18n['admin'] ); ?></select>
						<p class="dwc-muted"><?php echo Html::e( $app->t( 'The default for everyone. Each person can still switch with the EN / 한국어 buttons at the top of these screens — that choice applies to their own account only.' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dwc-lang-front"><?php echo Html::e( $app->t( 'Site widgets' ) ); ?></label></th>
					<td>
						<select id="dwc-lang-front" name="i18n[front]"><?php echo $choices( $app->t( 'Follow the site language' ), $i18n['front'] ); ?></select>
						<p class="dwc-muted"><?php echo Html::e( $app->t( 'Language of the catalog, search box and forms your visitors see. Set separately from the admin screens.' ) ); ?></p>
					</td>
				</tr>
			</table>
		</div>
	</section>

	<section class="dwc-card">
		<h2><?php echo Html::e( $app->t( 'Widget text' ) ); ?></h2>
		<div class="dwc-card-body">
			<p class="dwc-muted"><?php echo Html::e( $app->t( 'Replace any button or message on the site with your own words. Leave a box empty to use the translation. Keep placeholders such as %s and %1$s.' ) ); ?></p>
			<table class="dwc-table dwc-overrides">
				<thead>
					<tr><th scope="col"><?php echo Html::e( $app->t( 'Original' ) ); ?></th><th scope="col"><?php echo Html::e( $app->t( 'Your text' ) ); ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ( FrontStrings::EDITABLE as $source ) : ?>
						<?php $key = Languages::overrideKey( $source ); ?>
						<tr>
							<td><label for="dwc-ov-<?php echo Html::attr( $key ); ?>"><?php echo Html::e( $source ); ?></label></td>
							<td><input type="text" id="dwc-ov-<?php echo Html::attr( $key ); ?>" name="i18n[overrides][<?php echo Html::attr( $key ); ?>]" value="<?php echo Html::attr( isset( $i18n['overrides'][ $source ] ) ? $i18n['overrides'][ $source ] : '' ); ?>" placeholder="<?php echo Html::attr( $c->t( $source ) ); ?>" maxlength="200"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( ! $readonly ) : ?>
			<div class="dwc-card-foot"><span></span><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( $app->t( 'Save settings' ) ); ?></button></div>
		<?php endif; ?>
	</section>
</form>
