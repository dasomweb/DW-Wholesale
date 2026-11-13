<?php
namespace DWCatalog\I18n;

/**
 * Widget wording a site may replace with its own (Settings → Widget text).
 *
 * Source strings exactly as the front end passes them to the translator; placeholders
 * (%s, %1$s, %d) must be kept in an override. tests/core checks that every entry is still used.
 */
class FrontStrings {

	const EDITABLE = array(
		'Search by name, item code or brand',
		'Search',
		'Filters',
		'Filters apply instantly — no Apply button needed.',
		'Apply',
		'Reset filters',
		'Clear',
		'All',
		'Show all %s',
		'Sort by',
		'Per page',
		'Showing %1$s of %2$s items',
		'No items found.',
		'No items match your filters.',
		'Previous',
		'Next',
		'Back to list',
		'Item details',
		'Options',
		'Related items',
		'Inquire about this item',
		'Copy %s',
		'Copied',
		'This item is not available.',
		'Load more',
		'Show only',
		'In stock',
		'New in the last 30 days',
		'With photo',
		'Clear all',
		'Show results',
		'Popular:',
	);
}
