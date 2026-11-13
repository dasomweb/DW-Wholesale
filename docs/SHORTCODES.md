# Shortcodes

The catalog is placed on WordPress pages with shortcodes. Everything inside the shortcode —
list, filters, item pages — comes from the catalog system; the page around it is designed
in WordPress as usual.

---

## `[dw_wholesale_catalog]` — catalog page (3.0)

```
[dw_wholesale_catalog]                                   whole catalog
[dw_wholesale_catalog group="frozen,dry-goods"]          one or more category groups
[dw_wholesale_catalog categories="nori,wasabi,gari"]     some categories
[dw_wholesale_catalog group="frozen" exclude="squid"]    leaving some out
[dw_wholesale_catalog id="frozen"]                       a saved catalog (DW Wholesale → Shortcodes)
```

Everything `[dw_catalog]` does (below), plus:

- **Saved catalogs** — set a catalog up once on the Shortcodes screen (products, search, filters
  and their order, sorting, columns and first count per device, card contents, click target,
  design) and place it with `id="…"`. The screen lists the pages using each one. Attributes written
  on the shortcode override the saved settings.
- **Filters** — categories (with a search box when the list is long), brand, origin, in stock,
  new in the last 30 days, with photo, and any public field — in the order you choose, in the
  sidebar or a top bar. Every choice shows how many products you get by ticking it (with the other
  filters applied); a filter with nothing to offer is hidden. Selected filters appear as chips
  above the results, each removable, with "Clear all".
- **Sorting** — name, item code, brand, newest, category, recommended order (the "Recommended
  order" number on each product), and number fields. Choose which ones visitors see and the default.
- **Devices** — desktop: columns + sidebar / top bar. Tablet: its own columns, filters slide in
  from the side. Phone: 1–2 columns of cards (or a list), filters open as a sheet from the bottom,
  "Load more" instead of page numbers. Tablets and phones first show their own number of products.
- **Cards** — choose item code, pack size, brand, origin, category badge, price (lowest option
  price, when the option price column is public) and other public fields; clicking opens the side
  panel or the product page.
- Every state (search, filters, sort, view, page) is in the URL, so a link shows the same results.
  The page costs the same number of database queries whatever the number of products.

| Option | Default | |
|---|---|---|
| `id` | — | Saved catalog |
| `group` / `categories` / `category` | — | Category slugs (comma-separated) the page shows |
| `exclude` | — | Category slugs left out, with their sub-categories |
| `title` | — | Heading |
| `search_size` | `large` | `large` · `small` · `hidden` |
| `suggest` | — | Suggested search words under the box |
| `filters` | `category,brand,origin,stock,new,photo` | Filters in order; field keys allowed |
| `filter_position` | `sidebar` | `sidebar` · `top` |
| `sorts` | all | Sorts offered: `title_asc` `title_desc` `code_asc` `code_desc` `brand_asc` `date_desc` `date_asc` `category_asc` `manual` `field:{key}:asc\|desc` |
| `default_sort` | `title_asc` | |
| `per_page` | `24` | Visitors can pick ×2 and ×4 (24 / 48 / 96) |
| `columns` / `cols_tablet` / `cols_mobile` | `4` / `3` / `2` | |
| `per_page_tablet` / `per_page_mobile` | `24` / `12` | Shown at first; "Load more" shows the rest |
| `mobile_view` | `grid` | `grid` (cards) · `list` (rows) |
| `card_fields` | `code,pack,brand,origin,category` | Plus `price` and public field keys |
| `click` | `drawer` | `drawer` (side panel) · `page` (product page) |
| `design` | — | Design preset |

URL parameters, in addition to the ones below: `dwo[]` = `in_stock` · `new` · `photo`.

---

## `[dw_wholesale_search]` — search box for a header

```
[dw_wholesale_search placeholder="Search products" size="small" suggest="nori, wasabi"]
```

Sends visitors to the main catalog page (or `page="123"`) with their search. `size`: `small` ·
`large`; `suggest` adds autocomplete words; `design` picks a preset.

---

## `[dw_catalog]` — catalog page (2.x, still supported)

```
[dw_catalog category="refrigerated" filter_fields="dw_pc_brand_raw"]
```

- **List**: header (title, item count, large search box, top-category tabs), left sidebar
  (category groups with counts · field filters, applied instantly), toolbar ("Showing a–b of N",
  sort, grid / list view), card grid or table, pagination with a rows-per-page menu. Filter changes
  update in place and keep the URL shareable; without JavaScript the same links and forms work as
  normal pages. On phones the sidebar becomes a "Filters" panel and cards become compact rows.
- **Item drawer**: clicking an item opens it in a side panel over the list; its URL is the item's
  own page (reload or share = full item page). Esc / Back closes it.
- **Item page**: `/{page}/item/{slug}/` on the same page (or `?dw_item={slug}` without pretty
  permalinks) — image gallery, public fields, variations table, description, related items,
  "Back to list" keeping the filters. Title, meta description, canonical URL, Open Graph and
  JSON-LD are printed in `<head>`.
- **Search**: word-prefix match over the name, description, public fields and public variation
  columns (`WK-009` = `wk009`; `miso` finds "Miso Ramen", `iso` does not).
- **Privacy**: fields whose **Public** box is unchecked (Fields screen) and non-public variation
  columns are never printed, never filterable, never sortable and never searchable on the site.
  `show_fields`, `detail_fields` and `filter_fields` ignore them.

| Option | Default | |
|---|---|---|
| `category` | — | Category slug(s) the page shows; the sidebar lists its sub-categories |
| `title` | — | Heading above the search box |
| `tabs` | `yes` | Top-level category tabs under the search box |
| `default_view` | `grid` | `grid` or `list` (visitors can switch) |
| `filter_fields` | — | Field keys offered as sidebar filters (public fields only) |
| `columns` | Design setting | Maximum columns (fewer on narrow screens) |
| `per_page` | `20` | Items per page (max 100) |
| `show_fields` | "List" fields | Field keys on cards |
| `detail_fields` | Public fields | Field keys on item pages |
| `default_sort` | `date_desc` | `date_desc` · `date_asc` · `title_asc` · `title_desc` · `field:{key}:asc\|desc` |
| `search` / `sort` | `yes` | Show the search box / sort menu |
| `show_category` | `yes` | Category name on cards |
| `related` | `4` | Related items on item pages (`0` = off) |
| `inquiry_url` / `inquiry_label` | — | "Inquire" button on item pages (`?item=` carries the name) |

URL parameters (shareable): `dwq` search · `dwt` tab · `dwc[]` categories · `dwf[key][]` field
filters · `dws` sort · `dwv` view · `dwn` per page · `dwp` page.

> One `[dw_catalog]` per page. Items reached from several pages share one canonical URL —
> the main catalog page (the first page with `[dw_catalog]` and no `category`), which is also
> what the XML sitemap (`/wp-sitemap-dwcatalog-1.xml`) lists.

---

## `[dw_catalog_grid]` / `[dw_catalog_carousel]`

Compact blocks for any page (home page highlights, etc.). Cards link to the main catalog page.

```
[dw_catalog_grid per_page="8" columns="4"]
[dw_catalog_carousel category="refrigerated" per_slide="3" autoplay="yes" interval="5000"]
```

| Option | Default | |
|---|---|---|
| `category` | — | Category slug(s) |
| `per_page` | `12` | Number of items |
| `columns` (grid) / `per_slide` (carousel) | Design / `3` | |
| `ids` | — | Specific item IDs, shown in that order |
| `show_fields` | "List" fields | Field keys on cards (public only) |
| `orderby` / `order` | `date` / `DESC` | `date` or `title` |
| `autoplay` / `interval` | `yes` / `5000` | Carousel only |

---

## Design

`DW Wholesale → Design`: main color, border color, button style (filled · outline · light
background), font set (Inter + Noto Sans KR · Noto Sans KR · IBM Plex Sans KR · Nanum Gothic ·
theme font), text size (S · M · L), corners of cards / buttons / inputs, card shadow (none · soft),
columns and spacing. A preview shows the result at desktop, tablet and phone width as you change it.

Save the values as the default design or as a named **preset**; a catalog uses a preset with
`design="slug"` (or by choosing it in a saved catalog). Values reach the page as CSS custom
properties on the catalog root (`--dwcat-*`), so a theme can still override them in its own CSS.
