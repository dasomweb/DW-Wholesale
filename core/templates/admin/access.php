<?php
/**
 * Access (administrators only): role × menu levels, role × sensitive actions, operator mode.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 */
use DWCatalog\Security\Access;
use DWCatalog\Support\Html;

$current = 'access';
$access  = $app->access();
$matrix  = $access->matrix();
$roles   = array_diff_key( $c->host()->roles(), array( 'administrator' => 1, Access::CUSTOMER_ROLE => 1 ) );
?>
<?php echo $app->head( $app->t( 'Access' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php echo $app->form( 'save_access', $type, false, 'class="dwc-access"' ); ?>
	<section class="dwc-card">
		<h2><?php echo Html::e( $app->t( 'Menus by role' ) ); ?></h2>
		<div class="dwc-card-body">
			<p class="dwc-muted"><?php echo Html::e( $app->t( 'Hidden menus disappear from the menu and are refused even when the address is typed in. View shows the screen without letting the person change anything. Administrators always have full access.' ) ); ?></p>
			<div class="dwc-scroll">
				<table class="dwc-table dwc-matrix">
					<thead>
						<tr>
							<th scope="col"><?php echo Html::e( $app->t( 'Menu' ) ); ?></th>
							<th scope="col"><?php echo Html::e( $c->host()->roles()['administrator'] ?? 'Administrator' ); ?></th>
							<?php foreach ( $roles as $role => $label ) : ?>
								<th scope="col"><?php echo Html::e( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Access::MENUS as $menu => $spec ) : ?>
							<tr>
								<th scope="row"><?php echo Html::e( $app->t( $spec[0] ) ); ?></th>
								<td><span class="dwc-pill dwc-pill-on"><span aria-hidden="true"></span><?php echo Html::e( $app->t( 'Full' ) ); ?></span></td>
								<?php foreach ( $roles as $role => $label ) : ?>
									<td>
										<?php if ( in_array( $menu, Access::ADMIN_ONLY, true ) ) : ?>
											<span class="dwc-muted"><?php echo Html::e( $app->t( 'Hidden' ) ); ?></span>
										<?php else : ?>
											<?php $level = isset( $matrix[ $role ]['menus'][ $menu ] ) ? (int) $matrix[ $role ]['menus'][ $menu ] : Access::HIDDEN; ?>
											<select name="access[roles][<?php echo Html::attr( $role ); ?>][menus][<?php echo Html::attr( $menu ); ?>]" aria-label="<?php echo Html::attr( $label . ' — ' . $app->t( $spec[0] ) ); ?>" class="dwc-level dwc-level-<?php echo (int) $level; ?>">
												<?php foreach ( Access::LEVEL_NAMES as $value => $name ) : ?>
													<?php if ( $value <= $spec[1] ) : ?>
														<option value="<?php echo (int) $value; ?>" <?php echo $value === $level ? 'selected' : ''; ?>><?php echo Html::e( $app->t( $name ) ); ?></option>
													<?php endif; ?>
												<?php endforeach; ?>
											</select>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="dwc-card">
		<h2><?php echo Html::e( $app->t( 'Actions by role' ) ); ?></h2>
		<div class="dwc-card-body">
			<p class="dwc-muted"><?php echo Html::e( $app->t( 'These need their own permission, on top of being able to edit the screen they are on.' ) ); ?></p>
			<div class="dwc-scroll">
				<table class="dwc-table dwc-matrix">
					<thead>
						<tr>
							<th scope="col"><?php echo Html::e( $app->t( 'Action' ) ); ?></th>
							<th scope="col"><?php echo Html::e( $c->host()->roles()['administrator'] ?? 'Administrator' ); ?></th>
							<?php foreach ( $roles as $role => $label ) : ?>
								<th scope="col"><?php echo Html::e( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Access::ACTIONS as $action => $actionLabel ) : ?>
							<tr>
								<th scope="row"><?php echo Html::e( $app->t( $actionLabel ) ); ?></th>
								<td><input type="checkbox" checked disabled aria-label="<?php echo Html::attr( $app->t( 'Always allowed' ) ); ?>"></td>
								<?php foreach ( $roles as $role => $label ) : ?>
									<?php $on = isset( $matrix[ $role ]['actions'] ) && in_array( $action, $matrix[ $role ]['actions'], true ); ?>
									<td><input type="checkbox" name="access[roles][<?php echo Html::attr( $role ); ?>][actions][<?php echo Html::attr( $action ); ?>]" value="1" <?php echo $on ? 'checked' : ''; ?> aria-label="<?php echo Html::attr( $label . ' — ' . $app->t( $actionLabel ) ); ?>"></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="dwc-card">
		<h2><?php echo Html::e( $app->t( 'Operator mode' ) ); ?></h2>
		<div class="dwc-card-body">
			<label class="dwc-check">
				<input type="checkbox" name="access[operator_mode]" value="1" <?php echo $access->operatorMode() ? 'checked' : ''; ?>>
				<span>
					<strong><?php echo Html::e( $app->t( 'Keep staff on the wholesale screens' ) ); ?></strong><br>
					<span class="dwc-muted"><?php echo Html::e( $app->t( 'For everyone except administrators: hide the WordPress Appearance, Plugins and Tools menus (and refuse those pages), and open the DW Wholesale dashboard after logging in.' ) ); ?></span>
				</span>
			</label>
		</div>
		<div class="dwc-card-foot">
			<button type="submit" form="dwc-access-reset" class="dwc-link" data-dwc-confirm="<?php echo Html::attr( $app->t( 'Put every role back to the default menus and actions?' ) ); ?>"><?php echo Html::e( $app->t( 'Restore defaults' ) ); ?></button>
			<button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( $app->t( 'Save access' ) ); ?></button>
		</div>
	</section>
</form>
<?php echo $app->form( 'reset_access', $type, false, 'id="dwc-access-reset" hidden' ); ?></form>
