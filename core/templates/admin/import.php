<?php
/**
 * CSV import.
 *
 * @var DWCatalog\Admin\AdminApp $app
 * @var DWCatalog\Catalog $c
 * @var string $type
 */
use DWCatalog\Admin\CsvFormat;
use DWCatalog\Support\Html;

$current = 'import';
$header  = CsvFormat::header( $c, $type );
?>
<?php echo $app->head( $app->t( 'Import / Export' ) ); ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="dwc-cols">
	<div class="dwc-main">
		<?php echo $app->form( 'import', $type, true ); ?>
			<table class="dwc-form">
				<tr><th><?php echo Html::e( $app->t( 'CSV file' ) ); ?></th><td><input type="file" name="csv" accept=".csv,text/csv" required></td></tr>
				<tr><th><?php echo Html::e( $app->t( 'Existing items' ) ); ?></th><td>
					<select name="existing">
						<option value="update"><?php echo Html::e( $app->t( 'Update (match by slug)' ) ); ?></option>
						<option value="skip"><?php echo Html::e( $app->t( 'Skip' ) ); ?></option>
					</select>
					<p class="dwc-muted"><?php echo Html::e( $app->t( 'Update changes only the columns in the file — a file with just slug and price updates prices.' ) ); ?></p>
				</td></tr>
				<tr><th><?php echo Html::e( $app->t( 'Status for new items' ) ); ?></th><td>
					<select name="status">
						<option value="publish"><?php echo Html::e( $app->t( 'Published' ) ); ?></option>
						<option value="draft"><?php echo Html::e( $app->t( 'Draft' ) ); ?></option>
					</select>
					<p class="dwc-muted"><?php echo Html::e( $app->t( 'Used when the file has no status column.' ) ); ?></p>
				</td></tr>
				<tr><th><?php echo Html::e( $app->t( 'Delimiter' ) ); ?></th><td>
					<select name="delimiter">
						<option value="auto"><?php echo Html::e( $app->t( 'Detect automatically' ) ); ?></option>
						<option value="comma"><?php echo Html::e( $app->t( 'Comma (,)' ) ); ?></option>
						<option value="semicolon"><?php echo Html::e( $app->t( 'Semicolon (;)' ) ); ?></option>
						<option value="tab"><?php echo Html::e( $app->t( 'Tab' ) ); ?></option>
					</select>
				</td></tr>
			</table>
			<p><button type="submit" class="dwc-btn dwc-btn-primary"><?php echo Html::e( $app->t( 'Import' ) ); ?></button></p>
		</form>
	</div>
	<aside class="dwc-side">
		<div class="dwc-box">
			<h2><?php echo Html::e( $app->t( 'File format' ) ); ?></h2>
			<p class="dwc-muted"><?php echo Html::e( $app->t( 'Same layout as the CSV export: export, edit in a spreadsheet, import.' ) ); ?></p>
			<ul class="dwc-muted">
				<li><?php echo Html::e( $app->t( 'One row per variation. Rows with the same slug are one item; the first row carries the item columns.' ) ); ?></li>
				<li><?php echo Html::e( $app->t( 'var_… columns are the variation columns.' ) ); ?></li>
				<li><?php echo Html::e( $app->t( 'category: "Parent > Child"; several categories or image URLs separated by " | ".' ) ); ?></li>
				<li><?php echo Html::e( $app->t( 'New image URLs are downloaded; images already in the catalog are kept.' ) ); ?></li>
			</ul>
			<pre class="dwc-code"><?php echo Html::e( implode( ',', $header ) ); ?></pre>
			<?php if ( $app->allowed( 'csv_export' ) ) : ?>
				<?php echo $app->form( 'export_csv', $type ); ?>
					<input type="hidden" name="template" value="1">
					<button type="submit" class="dwc-btn"><?php echo Html::e( $app->t( 'Download template CSV' ) ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</aside>
</div>
