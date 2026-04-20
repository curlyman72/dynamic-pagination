<?php

declare(strict_types=1);

require_once __DIR__ . '/DynamicPaginator.php';

$assetVersion = (string) max(
    @filemtime(__DIR__ . '/assets/js/paginator.js') ?: 0,
    @filemtime(__DIR__ . '/assets/css/paginator.css') ?: 0
);

$dbConfig = require __DIR__ . '/config/db.config.php';

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$filters = [];
if (!empty($_GET['category'])) {
    $filters['category'] = trim((string) $_GET['category']);
}

$configPath = __DIR__ . '/config/paginator.config.json';
$configData = json_decode((string) file_get_contents($configPath), true);
if (!is_array($configData)) {
    $configData = [];
}

$readBool = static function (string $key, bool $default): bool {
    if (!isset($_GET[$key])) {
        return $default;
    }
    return in_array(strtolower((string) $_GET[$key]), ['1', 'true', 'yes', 'on'], true);
};

$readString = static function (string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
};

$readInt = static function (string $key, int $default): int {
    return isset($_GET[$key]) ? max(1, (int) $_GET[$key]) : $default;
};

$normalizeChoice = static function (string $value, array $allowed, string $default): string {
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $default;
};

$readPixelValue = static function (string $key, int $default): int {
    if (!isset($_GET[$key])) {
        return $default;
    }

    $raw = trim((string) $_GET[$key]);
    if ($raw === '') {
        return $default;
    }

    if (preg_match('/-?\d+/', $raw, $matches) !== 1) {
        return $default;
    }

    return max(0, (int) $matches[0]);
};

$configData['table_title'] = $readString('table_title', (string) ($configData['table_title'] ?? 'Color Catalog'));
$configData['toolbar']['enabled'] = $readBool('toolbar_enabled', (bool) ($configData['toolbar']['enabled'] ?? true));
$configData['position_bar']['enabled'] = $readBool('position_bar_enabled', (bool) ($configData['position_bar']['enabled'] ?? true));
$configData['infinite_scroll'] = $readBool('infinite_scroll', (bool) ($configData['infinite_scroll'] ?? true));
$configData['table_scroll_layout'] = $readBool('split_header_layout', (string) ($configData['table_scroll_layout'] ?? 'single_region') === 'split_header')
    ? 'split_header'
    : 'single_region';
$configData['page_size'] = $readInt('page_size', (int) ($configData['page_size'] ?? 25));
$configData['viewport']['width_mode'] = $normalizeChoice(
    $readString('width_mode', (string) ($configData['viewport']['width_mode'] ?? 'fill')),
    ['fill', 'fixed'],
    'fill'
);
$configData['viewport']['height_mode'] = $normalizeChoice(
    $readString('height_mode', (string) ($configData['viewport']['height_mode'] ?? 'range')),
    ['range', 'fixed', 'fill'],
    'range'
);
$configData['viewport']['width'] = $readString('width', (string) ($configData['viewport']['width'] ?? '100%'));
$configData['viewport']['height'] = $readString('height', (string) ($configData['viewport']['height'] ?? ''));
$configData['viewport']['min_height'] = $readPixelValue('min_height', 320) . 'px';
$configData['viewport']['max_height'] = $readPixelValue('max_height', 500) . 'px';

$storedWidthValue = $readString('stored_width_value', (string) ($configData['viewport']['width'] ?? '960px'));
$storedHeightValue = $readString('stored_height_value', (string) ($configData['viewport']['height'] ?? '500px'));
$storedMinHeightValue = (string) $readPixelValue('stored_min_height_value', (int) preg_replace('/[^0-9]/', '', (string) ($configData['viewport']['min_height'] ?? '320px')));
$storedMaxHeightValue = (string) $readPixelValue('stored_max_height_value', (int) preg_replace('/[^0-9]/', '', (string) ($configData['viewport']['max_height'] ?? '500px')));

$paginator = new DynamicPaginator(
    $dbConfig,
    'colors',
    '.pagination-data-wrapper',
    $configData
);

$paginator
    ->setSearchableColumns(['name', 'category', 'description'])
    ->setFilterableColumns(['category'])
    ->setSortableColumns(['id', 'name', 'category', 'created_at'])
    ->setOrderBy('id', 'ASC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Paginator Demo</title>
    <link rel="stylesheet" href="assets/css/paginator.css?v=<?= urlencode($assetVersion) ?>">
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(18, 105, 219, 0.15), transparent 30%),
                linear-gradient(180deg, #f7fbff 0%, #eef4fa 100%);
            color: #1f2328;
        }

        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .hero {
            background: linear-gradient(135deg, #114d92 0%, #0d6bd6 48%, #5ea8ff 100%);
            color: #fff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 24px 48px rgba(8, 40, 79, 0.18);
        }

        .hero h1 {
            margin: 0 0 10px;
            font-size: clamp(28px, 5vw, 42px);
        }

        .hero p {
            margin: 0;
            max-width: 860px;
            font-size: 16px;
            line-height: 1.65;
            opacity: 0.95;
        }

        .hero .badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .hero .badge {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            font-size: 13px;
            font-weight: 600;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(280px, 0.9fr);
            gap: 20px;
            margin-top: 22px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(208, 215, 222, 0.8);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.06);
            backdrop-filter: blur(12px);
        }

        .panel h2,
        .panel h3 {
            margin-top: 0;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            font-size: 13px;
            font-weight: 700;
            color: #314152;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .field input,
        .field select {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #c8d4e1;
            background: #fff;
            font-size: 14px;
        }

        .field input,
        .field select,
        .button-link,
        .checkbox-field,
        .checkbox-field input {
            cursor: pointer;
        }

        .field input[readonly] {
            background: #f4f7fb;
            color: #536274;
        }

        .unit-input {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
        }

        .unit-suffix {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 12px 10px;
            border-radius: 12px;
            background: #eef4fb;
            border: 1px solid #d3dfeb;
            color: #415468;
            font-size: 14px;
            font-weight: 700;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            margin-bottom: 22px;
        }

        .button-row button {
            border: 0;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            background: #0b63ce;
            box-shadow: 0 12px 20px rgba(11, 99, 206, 0.22);
        }

        .button-row button.secondary {
            color: #17406a;
            background: #dbeafe;
            box-shadow: none;
        }

        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            color: #17406a;
            background: #dbeafe;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            border-radius: 16px;
            background: linear-gradient(180deg, #f8fbff 0%, #edf4fb 100%);
            border: 1px solid #d8e3ee;
            padding: 14px;
        }

        .stat .label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #546275;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat .value {
            display: block;
            margin-top: 6px;
            font-size: 24px;
            font-weight: 800;
            color: #12385e;
        }

        .notes {
            display: grid;
            gap: 12px;
        }

        .knob-panel {
            display: grid;
            gap: 14px;
        }

        .knob-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
        }

        .knob-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
        }

        .knob-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .knob-actions button {
            border: 0;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .knob-actions .primary-action {
            color: #fff;
            background: #0b63ce;
            box-shadow: 0 12px 20px rgba(11, 99, 206, 0.22);
        }

        .checkbox-field {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            font-weight: 600;
            min-width: 0;
            flex-wrap: nowrap;
        }

        .checkbox-stack {
            display: grid;
            gap: 10px;
        }

        .hint {
            color: #546275;
            font-size: 13px;
            line-height: 1.5;
        }

        .note {
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
            border: 1px solid #d8e3ee;
            padding: 16px;
        }

        .note strong {
            display: block;
            margin-bottom: 6px;
            color: #12385e;
        }

        .sidebar {
            display: grid;
            gap: 20px;
            align-content: start;
        }

        .project-meta {
            margin-top: 20px;
            display: grid;
            gap: 12px;
        }

        .project-meta-card {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-radius: 18px;
            border: 1px solid rgba(208, 215, 222, 0.9);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
        }

        .project-meta-copy {
            display: grid;
            gap: 6px;
        }

        .project-meta-copy strong {
            font-size: 16px;
            color: #12385e;
        }

        .project-meta-copy span,
        .author-block span {
            color: #546275;
            font-size: 14px;
            line-height: 1.5;
        }

        .project-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .project-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            color: #114d92;
            background: #e8f1ff;
            border: 1px solid #c8daf8;
            cursor: pointer;
        }

        .coffee-link {
            color: #fff;
            background: linear-gradient(135deg, #9a3412 0%, #c2410c 38%, #f59e0b 100%);
            border-color: rgba(154, 52, 18, 0.25);
            box-shadow: 0 14px 24px rgba(194, 65, 12, 0.22);
        }

        .coffee-link:hover,
        .coffee-link:focus-visible {
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(194, 65, 12, 0.28);
        }

        .author-block {
            display: grid;
            gap: 4px;
        }

        code {
            background: #eff6ff;
            color: #0f3a6a;
            border-radius: 8px;
            padding: 2px 6px;
        }

        @media (max-width: 960px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .project-meta-card {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (min-width: 720px) {
            .knob-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

    </style>
</head>
<body>
    <div class="page">
        <section class="hero">
            <h1>Dynamic Pagination</h1>
            <p>
                Dynamic Pagination is a reusable API-driven table component written in PHP for browsing large datasets without full page reloads.
                It combines pagination with infinite scroll so users can keep moving naturally through results without losing the
                orientation, control, and jump points that classic paging provides. That makes it useful for data-heavy interfaces
                where people need both smooth browsing and precise movement through a large dataset.
            </p>
            <div class="badge-row">
                <span class="badge">Query-aware cache</span>
                <span class="badge">Stale request protection</span>
                <span class="badge">ETag-enabled API</span>
                <span class="badge">Configurable integration layer</span>
                <span class="badge">PHP 7.4+</span>
            </div>
        </section>

        <div class="layout">
            <main class="panel">
                <h2>Demo Controls</h2>
                <div class="controls-grid">
                    <div class="field">
                        <label for="search-input">Search</label>
                        <input id="search-input" type="text" placeholder="Try red, sunset, or quoted phrases" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label for="category-filter">Category</label>
                        <select id="category-filter">
                            <option value="">All categories</option>
                            <?php foreach (['Red', 'Blue', 'Green', 'Yellow', 'Purple'] as $category): ?>
                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= (($filters['category'] ?? '') === $category) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="button-row">
                    <button type="button" id="search-button">Search</button>
                    <button type="button" id="reset-button" class="secondary">Reset</button>
                    <button type="button" id="refresh-button" class="secondary">Refresh</button>
                </div>

                <?= $paginator->render($search, $filters) ?>

                <div class="stats">
                    <div class="stat">
                        <span class="label">Current Page</span>
                        <span class="value" id="stat-page">1</span>
                    </div>
                    <div class="stat">
                        <span class="label">Total Rows</span>
                        <span class="value" id="stat-rows">0</span>
                    </div>
                    <div class="stat">
                        <span class="label">Total Pages</span>
                        <span class="value" id="stat-pages">1</span>
                    </div>
                    <div class="stat">
                        <span class="label">Cache Entries</span>
                        <span class="value" id="stat-cache">0</span>
                    </div>
                </div>
            </main>

            <aside class="sidebar">
                <section class="panel">
                    <h3>Knobs</h3>
                    <form class="knob-panel" method="get">
                        <input type="hidden" id="stored_width_value" name="stored_width_value" value="<?= htmlspecialchars($storedWidthValue, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="stored_height_value" name="stored_height_value" value="<?= htmlspecialchars($storedHeightValue, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="stored_min_height_value" name="stored_min_height_value" value="<?= htmlspecialchars($storedMinHeightValue, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" id="stored_max_height_value" name="stored_max_height_value" value="<?= htmlspecialchars($storedMaxHeightValue, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="field">
                            <label for="table_title">Table title</label>
                            <input id="table_title" name="table_title" type="text" value="<?= htmlspecialchars((string) ($configData['table_title'] ?? 'Color Catalog'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="knob-grid">
                            <div class="knob-row">
                                <div class="field">
                                    <label for="width_mode">Width mode</label>
                                    <select id="width_mode" name="width_mode">
                                        <?php foreach (['fill', 'fixed'] as $mode): ?>
                                        <option value="<?= $mode ?>" <?= (($configData['viewport']['width_mode'] ?? '') === $mode) ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="height_mode">Height mode</label>
                                    <select id="height_mode" name="height_mode">
                                        <?php foreach (['range', 'fixed', 'fill'] as $mode): ?>
                                        <option value="<?= $mode ?>" <?= (($configData['viewport']['height_mode'] ?? '') === $mode) ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="knob-row">
                                <div class="field">
                                    <label for="width">Width</label>
                                    <input id="width" name="width" type="text" value="<?= htmlspecialchars((string) ($configData['viewport']['width'] ?? '100%'), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="field">
                                    <label for="height">Height</label>
                                    <input id="height" name="height" type="text" value="<?= htmlspecialchars((string) ($configData['viewport']['height'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>
                            <div class="knob-row">
                                <div class="field">
                                    <label for="min_height">Min height</label>
                                    <div class="unit-input">
                                        <input id="min_height" name="min_height" type="number" min="0" step="1" inputmode="numeric" value="<?= htmlspecialchars((string) preg_replace('/[^0-9]/', '', (string) ($configData['viewport']['min_height'] ?? '320px')), ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="unit-suffix" aria-hidden="true">px</span>
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="max_height">Max height</label>
                                    <div class="unit-input">
                                        <input id="max_height" name="max_height" type="number" min="0" step="1" inputmode="numeric" value="<?= htmlspecialchars((string) preg_replace('/[^0-9]/', '', (string) ($configData['viewport']['max_height'] ?? '500px')), ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="unit-suffix" aria-hidden="true">px</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="checkbox-stack">
                            <label class="checkbox-field"><input type="hidden" name="toolbar_enabled" value="0"><input type="checkbox" name="toolbar_enabled" value="1" <?= !empty($configData['toolbar']['enabled']) ? 'checked' : '' ?>> Toolbar enabled</label>
                            <label class="checkbox-field"><input type="hidden" name="position_bar_enabled" value="0"><input type="checkbox" name="position_bar_enabled" value="1" <?= !empty($configData['position_bar']['enabled']) ? 'checked' : '' ?>> Position bar enabled</label>
                            <label class="checkbox-field"><input type="hidden" name="infinite_scroll" value="0"><input type="checkbox" name="infinite_scroll" value="1" <?= !empty($configData['infinite_scroll']) ? 'checked' : '' ?>> Infinite scroll enabled</label>
                            <label class="checkbox-field"><input type="hidden" name="split_header_layout" value="0"><input type="checkbox" name="split_header_layout" value="1" <?= (($configData['table_scroll_layout'] ?? 'single_region') === 'split_header') ? 'checked' : '' ?>> Split-header layout</label>
                        </div>
                        <p class="hint">These knobs update the shared config for this demo render. Use <strong>range</strong> or <strong>fixed</strong> for the safest large-dataset behavior. <strong>Fill</strong> is an advanced mode for parent-constrained layouts where the container already defines the available size.</p>
                        <div class="knob-actions">
                            <button type="submit" class="primary-action">Apply knobs</button>
                            <a class="secondary button-link" href="index.php">Reset knobs</a>
                        </div>
                    </form>
                </section>

                <section class="panel" aria-label="Project information">
                    <div class="project-meta-copy">
                        <strong>Dynamic Pagination on GitHub</strong>
                        <span>Source, documentation, and integration reference for this reusable API-driven paginator component.</span>
                    </div>
                    <div class="project-links">
                        <a class="project-link" href="https://github.com/curlyman72/dynamic-pagination" target="_blank" rel="noopener noreferrer">View on GitHub</a>
                        <a class="project-link coffee-link" href="https://paypal.me/curlyman72" target="_blank" rel="noopener noreferrer">Buy me a coffee</a>
                    </div>
                </section>
            </aside>
        </div>

        <section class="project-meta" aria-label="Project information">
            <div class="project-meta-card">
                <div class="author-block">
                    <strong>Author: Jerry Lee</strong>
                    <span><a class="project-link" href="mailto:jerry.github@raptorlabs.com">jerry.github@raptorlabs.com</a></span>
                    <span><a class="project-link" href="https://www.linkedin.com/in/jerry-l-9a7164/" target="_blank" rel="noopener noreferrer">LinkedIn Profile</a></span>
                </div>
            </div>
        </section>
    </div>

    <script src="assets/js/paginator.js?v=<?= urlencode($assetVersion) ?>"></script>
    <script>
        let paginatorInstance = null;

        function collectFilters() {
            const category = document.getElementById('category-filter').value;
            return category ? { category } : {};
        }

        function updateStats() {
            if (!paginatorInstance) {
                return;
            }

            document.getElementById('stat-page').textContent = paginatorInstance.getCurrentPage();
            document.getElementById('stat-rows').textContent = paginatorInstance.getTotalRows().toLocaleString();
            document.getElementById('stat-pages').textContent = paginatorInstance.getTotalPages();
            document.getElementById('stat-cache').textContent = paginatorInstance.getCacheSize();
        }

        function runSearch() {
            if (!paginatorInstance) {
                return;
            }

            const search = document.getElementById('search-input').value.trim();
            paginatorInstance.search(search, collectFilters()).then(updateStats);
        }

        function resetFilters() {
            document.getElementById('search-input').value = '';
            document.getElementById('category-filter').value = '';

            if (!paginatorInstance) {
                return;
            }

            paginatorInstance.search('', {}).then(updateStats);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const paginatorElement = document.querySelector('.dynamic-paginator');
            const knobForm = document.querySelector('.knob-panel');
            const widthModeField = document.getElementById('width_mode');
            const heightModeField = document.getElementById('height_mode');
            const widthField = document.getElementById('width');
            const heightField = document.getElementById('height');
            const minHeightField = document.getElementById('min_height');
            const maxHeightField = document.getElementById('max_height');
            const storedWidthValueField = document.getElementById('stored_width_value');
            const storedHeightValueField = document.getElementById('stored_height_value');
            const storedMinHeightValueField = document.getElementById('stored_min_height_value');
            const storedMaxHeightValueField = document.getElementById('stored_max_height_value');
            let lastWidthMode = widthModeField ? widthModeField.value : 'fill';
            let lastHeightMode = heightModeField ? heightModeField.value : 'range';

            function rememberViewportValues() {
                if (storedWidthValueField && widthField && lastWidthMode === 'fixed' && widthField.value.trim() !== '') {
                    storedWidthValueField.value = widthField.value.trim();
                }

                if (storedHeightValueField && heightField && lastHeightMode === 'fixed' && heightField.value.trim() !== '') {
                    storedHeightValueField.value = heightField.value.trim();
                }

                if (storedMinHeightValueField && minHeightField && lastHeightMode === 'range' && minHeightField.value.trim() !== '') {
                    storedMinHeightValueField.value = minHeightField.value.trim();
                }

                if (storedMaxHeightValueField && maxHeightField && lastHeightMode === 'range' && maxHeightField.value.trim() !== '') {
                    storedMaxHeightValueField.value = maxHeightField.value.trim();
                }
            }

            function syncViewportKnobs() {
                rememberViewportValues();

                if (widthModeField && widthField) {
                    if (widthModeField.value === 'fill') {
                        widthField.value = '100%';
                        widthField.readOnly = true;
                    } else {
                        widthField.value = storedWidthValueField && storedWidthValueField.value.trim() !== '' ? storedWidthValueField.value.trim() : '960px';
                        widthField.readOnly = false;
                    }
                }

                if (!heightModeField || !heightField || !minHeightField || !maxHeightField) {
                    lastWidthMode = widthModeField ? widthModeField.value : lastWidthMode;
                    lastHeightMode = heightModeField ? heightModeField.value : lastHeightMode;
                    return;
                }

                if (heightModeField.value === 'range') {
                    heightField.value = '';
                    heightField.readOnly = true;
                    minHeightField.value = storedMinHeightValueField && storedMinHeightValueField.value.trim() !== '' ? storedMinHeightValueField.value.trim() : '320';
                    maxHeightField.value = storedMaxHeightValueField && storedMaxHeightValueField.value.trim() !== '' ? storedMaxHeightValueField.value.trim() : '500';
                    minHeightField.readOnly = false;
                    maxHeightField.readOnly = false;
                    lastWidthMode = widthModeField ? widthModeField.value : lastWidthMode;
                    lastHeightMode = heightModeField.value;
                    return;
                }

                if (heightModeField.value === 'fixed') {
                    heightField.value = storedHeightValueField && storedHeightValueField.value.trim() !== '' ? storedHeightValueField.value.trim() : '500px';
                    heightField.readOnly = false;
                    minHeightField.value = storedMinHeightValueField && storedMinHeightValueField.value.trim() !== '' ? storedMinHeightValueField.value.trim() : '320';
                    maxHeightField.value = storedMaxHeightValueField && storedMaxHeightValueField.value.trim() !== '' ? storedMaxHeightValueField.value.trim() : '500';
                    minHeightField.readOnly = true;
                    maxHeightField.readOnly = true;
                    lastWidthMode = widthModeField ? widthModeField.value : lastWidthMode;
                    lastHeightMode = heightModeField.value;
                    return;
                }

                heightField.value = '100%';
                minHeightField.value = storedMinHeightValueField && storedMinHeightValueField.value.trim() !== '' ? storedMinHeightValueField.value.trim() : '320';
                maxHeightField.value = storedMaxHeightValueField && storedMaxHeightValueField.value.trim() !== '' ? storedMaxHeightValueField.value.trim() : '500';
                heightField.readOnly = true;
                minHeightField.readOnly = true;
                maxHeightField.readOnly = true;
                lastWidthMode = widthModeField ? widthModeField.value : lastWidthMode;
                lastHeightMode = heightModeField.value;
            }

            if (widthModeField) {
                widthModeField.addEventListener('change', syncViewportKnobs);
            }

            if (heightModeField) {
                heightModeField.addEventListener('change', syncViewportKnobs);
            }

            if (knobForm) {
                knobForm.addEventListener('submit', () => {
                    if (storedWidthValueField && widthModeField && widthField && widthModeField.value === 'fixed' && widthField.value.trim() !== '') {
                        storedWidthValueField.value = widthField.value.trim();
                    }

                    if (storedHeightValueField && heightModeField && heightField && heightModeField.value === 'fixed' && heightField.value.trim() !== '') {
                        storedHeightValueField.value = heightField.value.trim();
                    }

                    if (storedMinHeightValueField && minHeightField && minHeightField.value.trim() !== '') {
                        storedMinHeightValueField.value = minHeightField.value.trim();
                    }

                    if (storedMaxHeightValueField && maxHeightField && maxHeightField.value.trim() !== '') {
                        storedMaxHeightValueField.value = maxHeightField.value.trim();
                    }
                });
            }

            syncViewportKnobs();

            if (!paginatorElement) {
                return;
            }

            const poll = window.setInterval(() => {
                if (!paginatorElement.paginatorInstance) {
                    return;
                }

                paginatorInstance = paginatorElement.paginatorInstance;
                window.clearInterval(poll);
                updateStats();
                window.setInterval(updateStats, 800);
            }, 100);

            document.getElementById('search-button').addEventListener('click', runSearch);
            document.getElementById('reset-button').addEventListener('click', resetFilters);
            document.getElementById('refresh-button').addEventListener('click', () => {
                if (!paginatorInstance) {
                    return;
                }
                paginatorInstance.refresh().then(updateStats);
            });
            document.getElementById('search-input').addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    runSearch();
                }
            });
            document.getElementById('category-filter').addEventListener('change', runSearch);
        });
    </script>
</body>
</html>
