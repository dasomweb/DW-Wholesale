<?php
namespace DWCatalog\Front;

/**
 * Where the catalog is being rendered — supplied by the host for each request.
 *
 *   pageUrl   URL of the page hosting the catalog, without query string
 *   itemBase  pretty item URLs: itemBase . slug . '/' (e.g. https://site/refrigerated/item/);
 *             empty → ?dw_item=slug on pageUrl
 *   query     the request's query parameters (unslashed)
 *   itemSlug  item requested by a pretty URL ('' when none)
 */
final class Context {

	/** @var string */
	public $pageUrl;
	/** @var string */
	public $itemBase;
	/** @var array */
	public $query;
	/** @var string */
	public $itemSlug;

	public function __construct( string $pageUrl, string $itemBase = '', array $query = array(), string $itemSlug = '' ) {
		$this->pageUrl  = $pageUrl;
		$this->itemBase = $itemBase;
		$this->query    = $query;
		$this->itemSlug = $itemSlug;
	}

	/** The item slug asked for — pretty URL first, then ?dw_item=. */
	public function requestedItem(): string {
		if ( '' !== $this->itemSlug ) {
			return $this->itemSlug;
		}
		return isset( $this->query['dw_item'] ) && is_string( $this->query['dw_item'] ) ? $this->query['dw_item'] : '';
	}

	public function listUrl( array $params = array() ): string {
		return $params ? $this->pageUrl . $this->sep() . http_build_query( $params ) : $this->pageUrl;
	}

	public function itemUrl( string $slug, array $params = array() ): string {
		if ( '' !== $this->itemBase ) {
			$url = rtrim( $this->itemBase, '/' ) . '/' . rawurlencode( $slug ) . '/';
			return $params ? $url . '?' . http_build_query( $params ) : $url;
		}
		return $this->pageUrl . $this->sep() . http_build_query( array_merge( $params, array( 'dw_item' => $slug ) ) );
	}

	/** "?" — or "&" when the page address already has a query (plain permalinks: ?page_id=4). */
	private function sep(): string {
		return false === strpos( $this->pageUrl, '?' ) ? '?' : '&';
	}
}
