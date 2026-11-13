<?php
namespace DWCatalog\Migration;

/**
 * The site being migrated FROM, as the core sees it. The WordPress adapter reads DW Product Catalog
 * posts, the DW WCM application table and DW Admin users (includes/wp/class-dwcat-wp-migration-source.php).
 *
 * Everything here READS the source. The only writes are to site pages (replacePage / restorePage),
 * and every replaced page is backed up first so a rollback puts it back exactly.
 */
interface Source {

	/**
	 * What exists on this site.
	 *
	 * @return array products  => [ found, count, public, hidden, with_image, label ]
	 *               categories => [ found, count ]
	 *               applications => [ found, count, table ]
	 *               users => [ found, count ]
	 */
	public function detect(): array;

	/** Product fields of the source: key => label, in the source's own order. */
	public function productFields(): array;

	/**
	 * Products after the given source id, ordered by id.
	 *
	 * @return array[] id, slug, title, content, status ('publish' or anything else = hidden), created,
	 *                 modified, meta [ key => value ], categories [ source category ids ],
	 *                 image [ path, name, alt ] or null
	 */
	public function products( int $afterId, int $limit ): array;

	/** @return array[] id, name, slug, parent, count */
	public function categories(): array;

	/**
	 * Category groups found in the site's pages (page builder query blocks).
	 *
	 * @return array[] name, slug, page_id, categories [ source category ids ]
	 */
	public function groups(): array;

	/** @return array[] id, created, updated, fields [ key => value ], files [ [ path, name, mime ] ] */
	public function applications( int $afterId, int $limit ): array;

	/**
	 * Pages whose query blocks can be replaced with a shortcode.
	 *
	 * @return array[] id, title, url, content, blocks (number of query blocks), group_slug
	 */
	public function pages(): array;

	/** Replace the query blocks of a page with $shortcode. @return string|null new content, null if nothing changed */
	public function replacePage( int $pageId, string $shortcode, bool $apply ): ?string;

	/** Put a page's content back (rollback). */
	public function restorePage( int $pageId, string $content ): void;

	/** Old product URL for a source slug, e.g. "/product/{slug}/". */
	public function oldUrl( string $slug ): string;
}
