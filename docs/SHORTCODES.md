# Shortcodes

The catalog is placed on WordPress pages with shortcodes. Everything inside the shortcode —
list, filters, item pages — comes from the catalog system; the page around it is designed
in WordPress as usual.

---

## `[dw_catalog]` — catalog page

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

`DW Wholesale → Design` sets colors, fonts, card style, grid gap and default columns. Values are
applied as CSS custom properties on the catalog root (`--dwcat-*`), so a theme can also
override them in its own CSS.
