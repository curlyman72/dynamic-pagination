# Dynamic Paginator

API-driven PHP and JavaScript paginator for large datasets, built for fast paging, infinite scroll, dataset jumping, and accessible table customization.

This component is designed for projects that need a reusable pagination layer without locking the UI to one framework. It renders a server-backed table, exposes a JSON API, supports keyboard-accessible navigation, and includes a configurable toolbar for sorting, page size, and column customization.

## Features

- API-first pagination with consistent `data`, `meta`, and `links` response structure
- Infinite scroll plus standard `First`, `Previous`, `Next`, and `Last` pagination controls
- Infinite scroll that can advance from user intent even before a native scrollbar exists
- Horizontal dataset position slider for jumping through the full result set
- Query-aware client caching with stale-request protection
- Configurable table title and toolbar
- Toolbar support for page size, sorting, column visibility, and column ordering
- JSON, PHP array, or inline JSON configuration
- Viewport sizing modes for bounded `range`, stable `fixed`, and advanced `fill`
- Configurable table scroll layouts with `single_region` and optional `split_header`
- Keyboard navigation support for `PageUp`, `PageDown`, `Home`, and `End`
- WCAG-oriented accessibility improvements including focus states, ARIA slider support, and live announcements
- Component-scoped CSS under `.dynamic-paginator` to reduce style collisions in existing applications
- Commented stylesheet sections to make customization easier

## Project Structure

```text
dp2/
├── config/
│   ├── db.config.php
│   └── paginator.config.json
├── assets/
│   ├── css/
│   │   └── paginator.css
│   └── js/
│       └── paginator.js
├── sql/
│   └── pagination_demo.sample.sql
├── install.php
├── index.php
├── DynamicPaginator.php
└── paginator_ajax.php
```

## Requirements

- PHP 7.4+ or newer
- PDO with MySQL support
- MySQL/MariaDB credentials with permission to create or import a database
- A MySQL table to paginate
- A web server such as Apache, Nginx, or WAMP

## Database Config

Shared database settings for the demo app live in `config/db.config.php`.

That file is used by:

- `index.php`
- `paginator_ajax.php`
- `install.php` as the installer default values

Example:

```php
<?php

return [
    'host' => 'localhost',
    'port' => '3306',
    'username' => 'root',
    'password' => '',
    'database' => 'pagination_demo',
];
```

If you move the project to another environment, update `config/db.config.php` once instead of editing multiple files.

## Installer

The project includes a browser-friendly installer at `install.php`.

It can:

- create the sample demo database
- import the bundled SQL dump exported from the current localhost setup
- optionally drop and recreate the target database
- verify that the `colors` sample table was imported

Open the installer from the folder where you copied `dp2`:

```text
./install.php
```

Default installer values:

- host: pulled from `config/db.config.php`
- port: pulled from `config/db.config.php`
- username: pulled from `config/db.config.php`
- password: pulled from `config/db.config.php`
- database: pulled from `config/db.config.php`
- SQL dump: `sql/pagination_demo.sample.sql`

The bundled SQL dump was exported from the live localhost demo database and contains the `colors` sample table used by the current component demo.

## Quick Start

1. Copy the `dp2` files into your project.
2. Run `install.php` if you want the sample localhost demo database.
3. Update your database connection settings if you are using a different database.
4. Point the paginator at your table and API endpoint.
5. Load `assets/css/paginator.css` and `assets/js/paginator.js` on the page where you render the component.

Minimal example:

```php
<?php
require_once __DIR__ . '/DynamicPaginator.php';

$dbConfig = require __DIR__ . '/config/db.config.php';

$paginator = new DynamicPaginator(
    $dbConfig,
    'colors',
    '.pagination-data-wrapper',
    __DIR__ . '/config/paginator.config.json'
);

$paginator
    ->setSearchableColumns(['name', 'category', 'description'])
    ->setFilterableColumns(['category'])
    ->setSortableColumns(['id', 'name', 'category', 'created_at'])
    ->setOrderBy('id', 'ASC');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dynamic Paginator</title>
    <link rel="stylesheet" href="assets/css/paginator.css">
</head>
<body>
    <?= $paginator->render() ?>
    <script src="assets/js/paginator.js"></script>
</body>
</html>
```

## API Endpoint

The included `paginator_ajax.php` file is the server endpoint for client requests.

Example setup:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/DynamicPaginator.php';

$dbConfig = require __DIR__ . '/config/db.config.php';

$paginator = new DynamicPaginator(
    $dbConfig,
    'colors',
    '.pagination-data-wrapper',
    __DIR__ . '/config/paginator.config.json'
);

$paginator
    ->setSearchableColumns(['name', 'category', 'description'])
    ->setFilterableColumns(['category'])
    ->setSortableColumns(['id', 'name', 'category', 'created_at'])
    ->setOrderBy('id', 'ASC');

$paginator->handleRequest();
```

### Request Payload

The JavaScript client sends JSON similar to:

```json
{
  "action": "get_page",
  "page": 1,
  "rowsPerPage": 25,
  "search": "red",
  "filters": {
    "category": "Red"
  },
  "sortBy": "id",
  "sortDirection": "ASC"
}
```

### Response Shape

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total_rows": 0,
    "total_pages": 1,
    "has_prev": false,
    "has_next": false,
    "offset": 0,
    "sort_by": "id",
    "sort_direction": "ASC",
    "query_signature": "..."
  },
  "links": {
    "self": "...",
    "next": null,
    "prev": null
  }
}
```

## Configuration

The paginator accepts configuration as:

- a PHP array
- an inline JSON string
- a path to a `.json` file

Example `config/paginator.config.json`:

```json
{
  "api_endpoint": "paginator_ajax.php",
  "page_size": 25,
  "max_page_size": 100,
  "prefetch_pages": 2,
  "infinite_scroll": true,
  "controls_position": "both",
  "total_count_position": "top",
  "table_scroll_layout": "single_region",
  "table_title": "Color Catalog",
  "toolbar": {
    "enabled": true,
    "allow_sorting": true,
    "allow_column_ordering": true,
    "allow_column_visibility": true,
    "default_hidden_columns": [],
    "default_column_order": []
  },
  "viewport": {
    "width_mode": "fill",
    "width": "100%",
    "height_mode": "range",
    "min_height": "320px",
    "max_height": "500px"
  },
  "position_bar": {
    "enabled": true,
    "orientation": "horizontal"
  }
}
```

### Key Options

- `api_endpoint`: AJAX endpoint URL
- `page_size`: default rows per page
- `max_page_size`: maximum allowed page size
- `prefetch_pages`: number of pages to prefetch ahead
- `infinite_scroll`: enable scroll-based page loading
- `controls_position`: `top`, `bottom`, or `both`
- `total_count_position`: `top`, `bottom`, or `both`
- `table_scroll_layout`: `single_region` or `split_header`
- `table_title`: visible table heading
- `toolbar.enabled`: show or hide the toolbar
- `toolbar.allow_sorting`: enable sorting controls
- `toolbar.allow_column_ordering`: enable column move controls
- `toolbar.allow_column_visibility`: enable column hide/show controls
- `viewport.width_mode`: `fill` or `fixed`
- `viewport.height_mode`: `range`, `fixed`, or `fill`
- `position_bar.enabled`: enable top dataset slider

## Viewport Sizing

Viewport sizing is intentionally separate from page size.

- `page_size` controls how many records come back from the API
- `viewport` controls how much vertical or horizontal space the table uses

Supported patterns:

- `width_mode: "fill"` for responsive full-width layouts
- `width_mode: "fixed"` with `width: "960px"` for card or dashboard layouts
- `height_mode: "fixed"` with `height: "500px"` for a locked table viewport
- `height_mode: "range"` with `min_height: "320px"` and `max_height: "500px"` for bounded responsive layouts
- `height_mode: "fill"` only when the parent container is explicitly responsible for height

`range` mode is intentionally separate from page size. The page size controls how many records come back from the API, while the range bounds control how much space the results viewport can use.

Recommended production guidance:

- Use `range` as the default mode for most embedded tables and full-page results
- Use `fixed` when you want the most predictable scroll behavior
- Use `fill` only when the parent layout already provides a stable, explicit width or height contract
- Avoid unbounded viewport growth for large datasets, because it weakens the scroll-container contract and makes infinite scrolling less predictable

When `fill` is configured without a detectable bounded parent height, the client falls back to bounded `range` behavior at runtime instead of keeping an unsafe unbounded scroll contract.

`auto` is intentionally not a supported viewport mode in the current component API. Older configs that still specify it are normalized to a safer bounded mode.

The demo preserves entered sizing values when switching between `fill`, `fixed`, and `range` so users can move between safe viewport modes without losing the last useful values they entered.

## Table Scroll Layout

The table area supports two layout strategies:

- `single_region`: the simplest and most scalable option, with one scroll container and a sticky header
- `split_header`: renders the header above the scroll body so the vertical scrollbar starts below the header

Use `single_region` by default. Choose `split_header` only when the presentation benefit is worth the extra header/body synchronization logic.

## Accessibility

The component was created to support accessible interaction patterns suitable for production use.

- Focus-visible states across controls
- Keyboard paging with `PageUp`, `PageDown`, `Home`, and `End`
- `ArrowDown` and downward input can advance infinite scrolling when the viewport does not yet have a native scrollbar
- ARIA-enabled dataset slider
- Live region announcements for page changes and slider previews
- Accessible column customization without requiring drag and drop
- Gear-toggle customization panel with stateful open and close behavior
- Overscroll containment on the table viewport to reduce accidental page-scroll chaining
- Loading spinner displayed as an overlay so loading states do not push the table layout down

Accessibility note:

- the component uses local `overscroll-behavior: contain` instead of globally locking page scroll, which is a safer approach for keyboard and assistive-technology users.  This prevents the main page from scrolling when navigating through rows in the paginator.

## Toolbar Capabilities

The built-in toolbar supports:

- page size changes
- sort column and direction
- column show and hide toggles
- column order controls using `Move up` and `Move down`

Disabled move controls are preserved for the first and last column positions so the user can understand why they cannot move farther.

The toolbar is designed to work as an accessible alternative to drag-only table customization. Sorting changes are sent through the API, while column visibility and ordering are applied client-side to the currently loaded table structure.

## Scrolling Model

This component combines pagination and infinite scroll because the two patterns solve different user needs:

- infinite scroll keeps browsing fluid when a user wants to continue moving through nearby records
- pagination preserves orientation, direct navigation, and predictable “where am I?” landmarks
- the top dataset slider provides fast jumping across the full result set without requiring the user to step page by page

When `infinite_scroll` is disabled, the component behaves like standard pagination only. Vertical scroll no longer loads earlier or later pages automatically, and page changes happen through explicit navigation controls.

When `infinite_scroll` is enabled, the component supports:

- forward loading from normal scroll reach
- forward loading from downward user intent when the viewport has no native scrollbar yet
- upward page recovery when scrolling back toward the top of the loaded range
- stable request sequencing to avoid stale-response bounce during rapid interactions
- bounded viewport sizing so the component does not try to behave like an unbounded “show everything” grid

## Styling and Namespacing

The shared stylesheet is scoped under `.dynamic-paginator` so it can be embedded in applications with existing global styles more safely.

`assets/css/paginator.css` is also divided into commented sections for easier customization:

- root tokens and shell
- header and toolbar
- column customization panel
- pagination controls
- position bar
- focus and disabled states
- viewport and table
- loading, empty, and error states
- accessibility helpers
- responsive rules

## Integration Notes

- Whitelist searchable, filterable, and sortable columns on the PHP side
- Keep the API endpoint and the render config aligned
- Use `setOrderBy('id', 'ASC')` if you want deterministic first-page ordering
- For reuse across systems, keep your environment-specific values in JSON config files
- For dashboards or embedded panels, use `fixed` or `range` viewport modes
- For full-page result views, prefer `fill` width with `range` height
- Use `fill` height only when the parent container already has an explicit height and you want the paginator to inherit it deliberately
- Prefer the shared stylesheet instead of reusing demo-specific CSS
- If your page already has strong global styles, keep the component root class intact so the shared `.dynamic-paginator` scoping continues to work

## Demo

`index.php` includes:

- live search and category filtering
- configurable knobs for layout and behavior
- toolbar controls for page size, sorting, and column customization
- retained sizing values across mode changes
- GitHub, author, and support links
- compatibility with the bundled `sql/pagination_demo.sample.sql` installer seed

## License

This project is released under the MIT License.

Copyright (c) Jerry Lee

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files to deal in the software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the software, subject to the MIT License terms.

## Author

Jerry Lee  
jerry.github@raptorlabs.com  
LinkedIn: <https://www.linkedin.com/in/jerry-l-9a7164/>

## Support

If this project saves you time, you can buy me a coffee:

<https://paypal.me/curlyman72>
