# DW Wholesale — Architecture

DW Wholesale is an **independent catalog system** that ships as a WordPress plugin because that
is the easiest way to install and run it. WordPress is only the *host*: themes, simple posts and
page design. Catalog data never lives in WordPress tables.

```
┌──────────────── core/  (DWCatalog\ — no WordPress code) ────────────────┐
│ Db        Connection interface · MySQL / PostgreSQL dialects · migrator   │
│ Repo      items · categories · images · field values · variations ·      │
│           search index · settings          (own tables, own prefix)      │
│ Query     search / filter / sort / paging on indexes                     │
│ Fields    field schema · presets · variation columns · public rules     │
│ Admin     the one admin UI (server-rendered pages + actions)            │
│ Front     catalog list / sidebar / detail HTML · SEO head data           │
│ Security  auth + capability checks · CSRF · escaping · input limits      │
│ Storage   image files (local disk) · resizing (GD / Imagick)             │
└──────────────────────────────────────────────────────────────────────────┘
          ▲ host adapter supplies: DB connection, current user + capabilities,
          │ file paths/URLs, URLs for pages, translation, where to print HTML
  ├─ includes/wp/   WordPress adapter (default) — shortcode, admin menu,
  │                 login → capabilities, DASOM-Forge license gates, packaging
  └─ (future)       plain PHP site, other CMS / frameworks
```

## Rules

1. **Core does not call WordPress.** No `$wpdb`, `esc_html`, `wp_upload_dir`, hooks, options.
   Everything host-specific comes in through an interface (`DWCatalog\Host\*`).
2. **Data stays behind the server.** Visitors receive finished HTML only. There is no public
   data API. The admin JSON/actions require an authenticated user with the right capability
   and a CSRF token. Fields not marked public never reach visitor output, filters, sort or search.
3. **Speed by construction.** Every visitor query path is an index lookup; list pages load
   field values for the whole page in one query; no `ORDER BY RAND()`, no `LIKE '%x%'` scans.
4. **Databases.** MySQL / MariaDB first (what WordPress hosts run), PostgreSQL supported.
   A new database = a new `Dialect` + `Connection`, nothing else changes.

## Tables (prefix `{host_prefix}dwcat_`)

| Table | Purpose | Key indexes |
|---|---|---|
| `settings` | field schema, variation columns, design, misc config (JSON) | PK name |
| `items` | catalog items: type, slug, title, content, excerpt, status, timestamps | (type, slug) unique · (type, status, created_at) · (type, status, title) |
| `terms` | categories / tags tree | (type, taxonomy, slug) unique · (type, taxonomy, parent) |
| `item_terms` | item ↔ term | PK (item_id, term_id) · (term_id, item_id) |
| `images` | image files per item (+ generated sizes, alt, order) | (item_id, position) |
| `values` | custom field values | PK (item_id, field_key) · (field_key, value_idx) · (field_key, value_num) |
| `variations` | variation rows (JSON data + indexed code/price) | (item_id, position) · (item_code) |
| `search` | word tokens with their source field | PK (token, item_id, source) · (item_id) |

## Request flow (WordPress)

- **Visitor page** with `[dw_catalog]` → adapter checks license gates → `Front\FrontApp::render()`
  → HTML. Detail pages: `/{page}/item/{slug}/`; SEO tags from `Front\Seo` printed in `wp_head`.
- **Admin** → `DW Wholesale` menu → the adapter reports the WP user's roles → `Security\Access`
  decides which screens and actions they get → `Admin\AdminApp` renders inside the WP admin frame;
  form posts go through `admin-post.php` to the same app.

## Admin menus and access (3.0)

`DW Wholesale > Dashboard, Products, Categories, Fields, Customers, Import / Export, Migration,
Shortcodes, Design, Presets, Settings, Access` — each core view belongs to one menu
(`AdminApp::VIEWS`).

`core/src/Security/Access.php` holds the whole permission model; the host only reports roles:

| | |
|---|---|
| role × menu | `hidden` · `view` · `edit` (Dashboard: hidden/view). Saved on the **Access** screen (settings key `access`); roles never saved use `Access::DEFAULTS` (Editor / Author / Contributor per spec), any other role starts with nothing |
| role × action | `product_delete`, `application_decide`, `price_group_change`, `staff_fields`, `csv_export` — needed on top of the menu level |
| Administrator | always everything; the only role that can open **Access** |
| `dw_wholesale_customer` | never reaches the admin, whatever other role it has |

Enforcement is server-side and layered: the WP menu only lists visible screens; `load-{page}`
refuses a hidden screen opened by URL with **403** before output (`AdminApp::canOpen()`);
`AdminApp::render()` refuses again; every POST checks menu level + action permission + license
feature + CSRF (`AdminApp::ACTIONS`). View-level screens render with a "View only" note, without
write buttons, and with form controls locked.

**Operator mode** (Access screen, `includes/wp/class-dwcat-wp-access.php`): for non-administrators,
removes Appearance / Plugins / Tools, refuses those pages (403) and sends them to the DW Wholesale
dashboard after login.

## Languages (3.0)

English is the source language; `languages/dw-wholesale-ko_KR.po` is the maintained Korean
catalogue (`php build/i18n.php all` checks it against the code and writes the `.pot` / `.mo`).

| | Resolution (`I18n\Languages`) |
|---|---|
| Admin screens | user's EN / 한국어 choice (user meta `dwcat_admin_lang`) → Settings default → user's WP language |
| Site widgets | Settings → widget language → site language |
| Widget wording | Settings → Widget text: per-string overrides (`I18n\FrontStrings::EDITABLE`), applied by `Catalog::frontText()` |

`DWCAT_WP_I18n` loads the chosen `.mo` into the `dw-wholesale` domain on `init` (admin) or when a
widget renders (site) — WordPress' own language is untouched.

## Host interface

`Host` supplies: user id, **roles**, **is administrator**, all roles (for the Access screen),
per-user preferences, user / site locale, license policy (`allows()`), CSRF secret, translation,
HTML sanitising, file storage, URLs, cache. Permission decisions are never made by the host.
