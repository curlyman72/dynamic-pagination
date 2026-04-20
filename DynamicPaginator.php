<?php

declare(strict_types=1);

final class DynamicPaginator
{
    private PDO $pdo;
    private string $table;
    private string $containerSelector;
    private array $config;
    private array $columns = [];
    private array $searchableColumns = [];
    private array $filterableColumns = [];
    private array $sortableColumns = [];
    private array $hiddenColumns = [];
    private string $primaryKey = 'id';
    private string $defaultSort = 'id';
    private string $defaultDirection = 'ASC';

    private array $defaultConfig = [
        'api_endpoint' => 'paginator_ajax.php',
        'page_size' => 25,
        'max_page_size' => 100,
        'prefetch_pages' => 1,
        'viewport' => [
            'width_mode' => 'fill',
            'width' => '100%',
            'min_width' => null,
            'max_width' => null,
            'height_mode' => 'range',
            'height' => null,
            'min_height' => '320px',
            'max_height' => '500px',
        ],
        'infinite_scroll' => true,
        'position_bar' => [
            'enabled' => true,
            'orientation' => 'horizontal',
        ],
        'controls_position' => 'both',
        'total_count_position' => 'top',
        'loading_indicator' => 'spinner',
        'table_scroll_layout' => 'single_region',
        'table_title' => 'Data Table',
        'toolbar' => [
            'enabled' => true,
            'allow_sorting' => true,
            'allow_column_ordering' => true,
            'allow_column_visibility' => true,
            'default_hidden_columns' => [],
            'default_column_order' => [],
        ],
        'debug' => false,
        'empty_state_title' => 'No results found',
        'empty_state_message' => 'Try adjusting your search or filters.',
    ];

    public function __construct(array $databaseConfig, string $table, string $containerSelector, $config = [])
    {
        $this->table = $this->sanitizeIdentifier($table);
        $this->containerSelector = $containerSelector;
        $this->config = array_replace_recursive($this->defaultConfig, $this->normalizeConfig($config));
        $this->config['viewport'] = $this->normalizeViewportConfig($this->config['viewport'] ?? []);
        $this->pdo = $this->createPdo($databaseConfig);

        $this->assertTableExists();
        $this->columns = $this->fetchTableColumns();

        if (empty($this->columns)) {
            throw new RuntimeException("Table '{$this->table}' has no columns.");
        }

        if (in_array('id', $this->columns, true)) {
            $this->primaryKey = 'id';
            $this->defaultSort = 'id';
        } else {
            $this->primaryKey = $this->columns[0];
            $this->defaultSort = $this->columns[0];
        }

        $this->searchableColumns = $this->columns;
        $this->filterableColumns = $this->columns;
        $this->sortableColumns = $this->columns;
    }

    public function setSearchableColumns(array $columns): self
    {
        $this->searchableColumns = $this->normalizeColumnList($columns);
        return $this;
    }

    public function setFilterableColumns(array $columns): self
    {
        $this->filterableColumns = $this->normalizeColumnList($columns);
        return $this;
    }

    public function setSortableColumns(array $columns): self
    {
        $this->sortableColumns = $this->normalizeColumnList($columns);
        return $this;
    }

    public function setHiddenColumns(array $columns): self
    {
        $this->hiddenColumns = $this->normalizeColumnList($columns);
        return $this;
    }

    public function setPrimaryKey(string $column): self
    {
        $column = $this->assertColumnExists($column);
        $this->primaryKey = $column;
        return $this;
    }

    public function setOrderBy(string $column, string $direction = 'DESC'): self
    {
        $column = $this->assertColumnExists($column);
        if (!in_array($column, $this->sortableColumns, true)) {
            throw new InvalidArgumentException("Column '{$column}' is not sortable.");
        }

        $this->defaultSort = $column;
        $this->defaultDirection = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }

    public function setConfig(string $key, $value): self
    {
        if (strpos($key, '.') === false) {
            $this->config[$key] = $value;
            return $this;
        }

        $segments = explode('.', $key);
        $cursor = &$this->config;

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        $cursor = $value;
        return $this;
    }

    public function loadConfig($config): self
    {
        $this->config = array_replace_recursive($this->defaultConfig, $this->normalizeConfig($config), $this->config);
        $this->config['viewport'] = $this->normalizeViewportConfig($this->config['viewport'] ?? []);
        return $this;
    }

    public function getData(
        int $page = 1,
        ?int $rowsPerPage = null,
        string $search = '',
        array $filters = [],
        ?string $sortBy = null,
        ?string $sortDirection = null
    ): array {
        $page = max(1, $page);
        $rowsPerPage = max(1, min((int) ($rowsPerPage ?? $this->config['page_size']), (int) $this->config['max_page_size']));
        $offset = ($page - 1) * $rowsPerPage;

        $normalizedFilters = $this->normalizeFilters($filters);
        $sortColumn = $this->normalizeSortColumn($sortBy);
        $direction = strtoupper((string) $sortDirection) === 'ASC' ? 'ASC' : $this->defaultDirection;

        [$whereSql, $bindings] = $this->buildWhereClause($search, $normalizedFilters);
        $selectColumns = $this->buildSelectColumnSql();

        $countSql = "SELECT COUNT(*) FROM `{$this->table}` {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $rowsPerPage));

        $sql = "SELECT {$selectColumns} FROM `{$this->table}` {$whereSql} ORDER BY `{$sortColumn}` {$direction}, `{$this->primaryKey}` {$direction} LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $rowsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $querySignature = $this->buildQuerySignature($search, $normalizedFilters, $sortColumn, $direction, $rowsPerPage);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $rowsPerPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'offset' => $offset,
                'sort_by' => $sortColumn,
                'sort_direction' => $direction,
                'query_signature' => $querySignature,
                'returned_rows' => count($rows),
                'columns' => $this->getVisibleColumns(),
                'filters' => $normalizedFilters,
                'search' => $search,
            ],
            'links' => [
                'self' => $this->buildLink($page, $rowsPerPage, $search, $normalizedFilters, $sortColumn, $direction),
                'next' => $page < $totalPages ? $this->buildLink($page + 1, $rowsPerPage, $search, $normalizedFilters, $sortColumn, $direction) : null,
                'prev' => $page > 1 ? $this->buildLink($page - 1, $rowsPerPage, $search, $normalizedFilters, $sortColumn, $direction) : null,
            ],
        ];
    }

    public function render(string $search = '', array $filters = []): string
    {
        $uniqueId = 'dynamic_paginator_' . bin2hex(random_bytes(6));
        $viewportId = $uniqueId . '_viewport';
        $instructionsId = $uniqueId . '_instructions';
        $statusId = $uniqueId . '_status';
        $initialState = [
            'search' => $search,
            'filters' => $this->normalizeFilters($filters),
            'page' => 1,
            'perPage' => (int) $this->config['page_size'],
            'sortBy' => $this->defaultSort,
            'sortDirection' => $this->defaultDirection,
        ];

        $clientConfig = [
            'apiEndpoint' => (string) $this->config['api_endpoint'],
            'containerSelector' => $this->containerSelector,
            'prefetchPages' => (int) $this->config['prefetch_pages'],
            'infiniteScroll' => (bool) $this->config['infinite_scroll'],
            'viewport' => $this->config['viewport'],
            'pageSize' => (int) $this->config['page_size'],
            'maxPageSize' => (int) $this->config['max_page_size'],
            'positionBar' => $this->config['position_bar'],
            'tableScrollLayout' => (string) $this->config['table_scroll_layout'],
            'tableTitle' => (string) $this->config['table_title'],
            'toolbar' => $this->config['toolbar'],
            'debug' => (bool) $this->config['debug'],
            'emptyStateTitle' => (string) $this->config['empty_state_title'],
            'emptyStateMessage' => (string) $this->config['empty_state_message'],
            'initialState' => $initialState,
        ];
        $componentStyle = $this->buildViewportStyle();
        $tableScrollLayout = strtolower((string) ($this->config['table_scroll_layout'] ?? 'single_region')) === 'split_header'
            ? 'split_header'
            : 'single_region';

        ob_start();
        ?>
        <div
            id="<?= htmlspecialchars($uniqueId, ENT_QUOTES, 'UTF-8') ?>"
            class="dynamic-paginator"
            data-table-scroll-layout="<?= htmlspecialchars($tableScrollLayout, ENT_QUOTES, 'UTF-8') ?>"
            data-api-endpoint="<?= htmlspecialchars((string) $this->config['api_endpoint'], ENT_QUOTES, 'UTF-8') ?>"
            style="<?= htmlspecialchars($componentStyle, ENT_QUOTES, 'UTF-8') ?>"
        >
            <script type="application/json" class="dynamic-paginator-config"><?= json_encode($clientConfig, JSON_UNESCAPED_SLASHES) ?></script>

            <div class="pagination-header">
                <div class="pagination-title-wrap">
                    <h2 class="pagination-title"><?= htmlspecialchars((string) $this->config['table_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>

                <?php if (!empty($this->config['toolbar']['enabled'])): ?>
                <div class="pagination-toolbar" aria-label="Table customization toolbar">
                    <div class="toolbar-group toolbar-page-size">
                        <label class="toolbar-label" for="<?= htmlspecialchars($uniqueId . '_page_size', ENT_QUOTES, 'UTF-8') ?>">Page size</label>
                        <input
                            id="<?= htmlspecialchars($uniqueId . '_page_size', ENT_QUOTES, 'UTF-8') ?>"
                            class="toolbar-page-size-input"
                            type="number"
                            min="1"
                            max="<?= (int) $this->config['max_page_size'] ?>"
                            value="<?= (int) $this->config['page_size'] ?>"
                            inputmode="numeric"
                            aria-label="Rows per page"
                        >
                        <button type="button" class="toolbar-apply-page-size">Apply size</button>
                    </div>

                    <?php if (!empty($this->config['toolbar']['allow_sorting'])): ?>
                    <div class="toolbar-group toolbar-sort">
                        <label class="toolbar-label" for="<?= htmlspecialchars($uniqueId . '_sort_column', ENT_QUOTES, 'UTF-8') ?>">Sort</label>
                        <select id="<?= htmlspecialchars($uniqueId . '_sort_column', ENT_QUOTES, 'UTF-8') ?>" class="toolbar-sort-column" aria-label="Sort column"></select>
                        <select id="<?= htmlspecialchars($uniqueId . '_sort_direction', ENT_QUOTES, 'UTF-8') ?>" class="toolbar-sort-direction" aria-label="Sort direction">
                            <option value="ASC">Ascending</option>
                            <option value="DESC">Descending</option>
                        </select>
                        <button type="button" class="toolbar-apply-sort">Apply sort</button>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($this->config['toolbar']['allow_column_ordering']) || !empty($this->config['toolbar']['allow_column_visibility'])): ?>
                    <div class="toolbar-group toolbar-columns">
                        <button
                            type="button"
                            class="toolbar-customize-toggle"
                            aria-expanded="false"
                            aria-pressed="false"
                            aria-controls="<?= htmlspecialchars($uniqueId . '_customize_panel', ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="Customize columns"
                            title="Customize columns"
                        >
                            <span class="toolbar-gear-icon" aria-hidden="true">&#9881;</span>
                            <span class="sr-only">Customize columns</span>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($this->config['toolbar']['enabled']) && (!empty($this->config['toolbar']['allow_column_ordering']) || !empty($this->config['toolbar']['allow_column_visibility']))): ?>
            <div id="<?= htmlspecialchars($uniqueId . '_customize_panel', ENT_QUOTES, 'UTF-8') ?>" class="toolbar-customize-panel" hidden>
                <div class="toolbar-customize-help">
                    Use the checkboxes to show or hide columns. Use Move up and Move down to reorder columns in an accessible way.
                </div>
                <ul class="toolbar-column-list" aria-label="Column customization list"></ul>
            </div>
            <?php endif; ?>

            <?php if (in_array($this->config['total_count_position'], ['top', 'both'], true)): ?>
            <div class="pagination-total top">
                <span class="total-rows">Total: <span class="count">0</span> items</span>
            </div>
            <?php endif; ?>

            <?php if (in_array($this->config['controls_position'], ['top', 'both'], true)): ?>
            <div class="pagination-controls top" aria-label="Pagination controls">
                <button type="button" class="btn-first" disabled aria-label="Go to first page">First</button>
                <button type="button" class="btn-prev" disabled aria-label="Go to previous page">Previous</button>
                <span class="page-info">
                    Page <input type="number" class="page-jump" min="1" value="1" aria-label="Page number"> of <span class="total-pages">1</span>
                </span>
                <button type="button" class="btn-next" disabled aria-label="Go to next page">Next</button>
                <button type="button" class="btn-last" disabled aria-label="Go to last page">Last</button>
            </div>
            <?php endif; ?>

            <?php if (!empty($this->config['position_bar']['enabled'])): ?>
            <div class="position-bar <?= htmlspecialchars((string) ($this->config['position_bar']['orientation'] ?? 'horizontal'), ENT_QUOTES, 'UTF-8') ?>">
                <div class="position-track" aria-hidden="true">
                    <div class="position-fill"></div>
                    <button
                        type="button"
                        class="position-thumb"
                        aria-label="Dataset position slider"
                        aria-controls="<?= htmlspecialchars($viewportId, ENT_QUOTES, 'UTF-8') ?>"
                        aria-describedby="<?= htmlspecialchars($instructionsId, ENT_QUOTES, 'UTF-8') ?>"
                        aria-valuemin="1"
                        aria-valuemax="1"
                        aria-valuenow="1"
                        aria-valuetext="Page 1 of 1"
                        role="slider"
                    ></button>
                </div>
            </div>
            <?php endif; ?>

            <p id="<?= htmlspecialchars($instructionsId, ENT_QUOTES, 'UTF-8') ?>" class="sr-only">
                Use Page Up and Page Down to move to the previous or next page. Use Home for the first page and End for the last page.
                The horizontal slider moves through the entire dataset. Drag it and release to load the displayed page.
            </p>
            <div id="<?= htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8') ?>" class="pagination-status sr-only" aria-live="polite" aria-atomic="true"></div>

            <?php if ($tableScrollLayout === 'split_header'): ?>
            <div class="pagination-data-wrapper">
                <div class="loading-indicator" hidden>
                    <div class="spinner" aria-hidden="true"></div>
                    <span>Loading results...</span>
                </div>
                <div class="pagination-error" hidden></div>
                <div class="pagination-empty" hidden>
                    <h3><?= htmlspecialchars((string) $this->config['empty_state_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars((string) $this->config['empty_state_message'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="pagination-table-viewport">
                    <div class="pagination-table-head-shell"></div>
                    <div
                        id="<?= htmlspecialchars($viewportId, ENT_QUOTES, 'UTF-8') ?>"
                        class="pagination-scroll-region"
                        role="region"
                        aria-live="polite"
                        aria-busy="false"
                        aria-label="Paginated results"
                        aria-describedby="<?= htmlspecialchars($instructionsId, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <div class="pagination-table-body-shell"></div>
                        <div class="pagination-sentinel" aria-hidden="true"></div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div
                id="<?= htmlspecialchars($viewportId, ENT_QUOTES, 'UTF-8') ?>"
                class="pagination-data-wrapper"
                role="region"
                aria-live="polite"
                aria-busy="false"
                aria-label="Paginated results"
                aria-describedby="<?= htmlspecialchars($instructionsId, ENT_QUOTES, 'UTF-8') ?>"
            >
                <div class="loading-indicator" hidden>
                    <div class="spinner" aria-hidden="true"></div>
                    <span>Loading results...</span>
                </div>
                <div class="pagination-error" hidden></div>
                <div class="pagination-empty" hidden>
                    <h3><?= htmlspecialchars((string) $this->config['empty_state_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars((string) $this->config['empty_state_message'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="pagination-table-shell"></div>
                <div class="pagination-sentinel" aria-hidden="true"></div>
            </div>
            <?php endif; ?>

            <?php if (in_array($this->config['controls_position'], ['bottom', 'both'], true)): ?>
            <div class="pagination-controls bottom" aria-label="Pagination controls">
                <button type="button" class="btn-first" disabled aria-label="Go to first page">First</button>
                <button type="button" class="btn-prev" disabled aria-label="Go to previous page">Previous</button>
                <span class="page-info">
                    Page <input type="number" class="page-jump" min="1" value="1" aria-label="Page number"> of <span class="total-pages">1</span>
                </span>
                <button type="button" class="btn-next" disabled aria-label="Go to next page">Next</button>
                <button type="button" class="btn-last" disabled aria-label="Go to last page">Last</button>
            </div>
            <?php endif; ?>

            <?php if (in_array($this->config['total_count_position'], ['bottom', 'both'], true)): ?>
            <div class="pagination-total bottom">
                <span class="total-rows">Total: <span class="count">0</span> items</span>
            </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function handleRequest(): void
    {
        $this->sendJsonHeaders();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
            $this->respond(['error' => 'Method not allowed'], 405);
            return;
        }

        try {
            $request = $this->readRequest();
            $action = $request['action'] ?? 'get_page';

            if ($action === 'health_check') {
                $this->respond([
                    'status' => 'ok',
                    'version' => '3.0',
                    'timestamp' => gmdate('c'),
                    'table' => $this->table,
                ]);
                return;
            }

            if ($action === 'capabilities') {
                $this->respond([
                    'searchable_columns' => $this->searchableColumns,
                    'filterable_columns' => $this->filterableColumns,
                    'sortable_columns' => $this->sortableColumns,
                    'default_sort' => $this->defaultSort,
                    'default_direction' => $this->defaultDirection,
                    'config' => [
                        'page_size' => $this->config['page_size'],
                        'max_page_size' => $this->config['max_page_size'],
                    ],
                ]);
                return;
            }

            if ($action !== 'get_page') {
                $this->respond([
                    'error' => 'Invalid action',
                    'valid_actions' => ['get_page', 'health_check', 'capabilities'],
                ], 400);
                return;
            }

            $response = $this->getData(
                (int) ($request['page'] ?? 1),
                isset($request['rowsPerPage']) ? (int) $request['rowsPerPage'] : null,
                trim((string) ($request['search'] ?? '')),
                is_array($request['filters'] ?? null) ? $request['filters'] : [],
                isset($request['sortBy']) ? (string) $request['sortBy'] : null,
                isset($request['sortDirection']) ? (string) $request['sortDirection'] : null
            );

            $etag = '"' . sha1(json_encode($response['meta'], JSON_UNESCAPED_SLASHES) ?: '') . '"';
            header('ETag: ' . $etag);

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                return;
            }

            $this->respond($response);
        } catch (Throwable $exception) {
            $payload = [
                'error' => $exception->getMessage(),
                'error_type' => $exception instanceof PDOException ? 'database' : 'application',
            ];

            if (!empty($this->config['debug'])) {
                $payload['debug'] = [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ];
            }

            $this->respond($payload, 500);
        }
    }

    private function createPdo(array $databaseConfig): PDO
    {
        $host = (string) ($databaseConfig['host'] ?? 'localhost');
        $database = (string) ($databaseConfig['database'] ?? '');
        $port = isset($databaseConfig['port']) && $databaseConfig['port'] !== ''
            ? ';port=' . (int) $databaseConfig['port']
            : '';

        $dsn = sprintf(
            'mysql:host=%s%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        return new PDO(
            $dsn,
            (string) ($databaseConfig['username'] ?? ''),
            (string) ($databaseConfig['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function readRequest(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $_GET;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== false) {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Invalid JSON request body.');
            }
            return $payload;
        }

        return $_POST;
    }

    private function buildWhereClause(string $search, array $filters): array
    {
        $conditions = [];
        $bindings = [];
        $index = 0;

        foreach ($filters as $column => $value) {
            $placeholder = ':filter_' . $index++;
            $conditions[] = "`{$column}` = {$placeholder}";
            $bindings[$placeholder] = $value;
        }

        $searchTerms = $this->parseSearchTerms($search);
        foreach ($searchTerms as $term) {
            $termConditions = [];
            foreach ($this->searchableColumns as $column) {
                $placeholder = ':search_' . $index++;
                $termConditions[] = "`{$column}` LIKE {$placeholder}";
                $bindings[$placeholder] = '%' . $term . '%';
            }
            if ($termConditions) {
                $conditions[] = '(' . implode(' OR ', $termConditions) . ')';
            }
        }

        if (!$conditions) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    private function parseSearchTerms(string $search): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/', $search, $matches);
        $terms = [];
        foreach ($matches[0] as $index => $_match) {
            $term = trim($matches[1][$index] !== '' ? $matches[1][$index] : $matches[2][$index]);
            if ($term !== '') {
                $terms[] = $term;
            }
        }
        return array_values(array_unique($terms));
    }

    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $column => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $column = $this->sanitizeIdentifier((string) $column);
            if ($column === '' || !in_array($column, $this->filterableColumns, true)) {
                continue;
            }

            $normalized[$column] = trim((string) $value);
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeSortColumn(?string $column): string
    {
        if ($column === null || $column === '') {
            return $this->defaultSort;
        }

        $column = $this->sanitizeIdentifier($column);
        if (!in_array($column, $this->sortableColumns, true)) {
            return $this->defaultSort;
        }

        return $column;
    }

    private function buildSelectColumnSql(): string
    {
        $columns = array_map(
            static fn (string $column): string => "`{$column}`",
            $this->getVisibleColumns()
        );

        return implode(', ', $columns);
    }

    private function getVisibleColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn (string $column): bool => !in_array($column, $this->hiddenColumns, true)
        ));
    }

    private function buildQuerySignature(
        string $search,
        array $filters,
        string $sortColumn,
        string $direction,
        int $rowsPerPage
    ): string {
        return sha1(json_encode([
            'table' => $this->table,
            'search' => $search,
            'filters' => $filters,
            'sort_by' => $sortColumn,
            'sort_direction' => $direction,
            'per_page' => $rowsPerPage,
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function buildLink(
        int $page,
        int $rowsPerPage,
        string $search,
        array $filters,
        string $sortColumn,
        string $direction
    ): string {
        $params = [
            'action' => 'get_page',
            'page' => $page,
            'rowsPerPage' => $rowsPerPage,
            'search' => $search,
            'sortBy' => $sortColumn,
            'sortDirection' => $direction,
        ];

        if ($filters) {
            $params['filters'] = $filters;
        }

        return (string) $this->config['api_endpoint'] . '?' . http_build_query($params);
    }

    private function fetchTableColumns(): array
    {
        $sql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ORDINAL_POSITION';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':table' => $this->table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function assertTableExists(): void
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':table' => $this->table]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException("Table '{$this->table}' does not exist.");
        }
    }

    private function normalizeColumnList(array $columns): array
    {
        $valid = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $column = $this->sanitizeIdentifier($column);
            if ($column !== '' && in_array($column, $this->columns, true)) {
                $valid[] = $column;
            }
        }

        return array_values(array_unique($valid));
    }

    private function assertColumnExists(string $column): string
    {
        $column = $this->sanitizeIdentifier($column);
        if (!in_array($column, $this->columns, true)) {
            throw new InvalidArgumentException("Column '{$column}' does not exist in table '{$this->table}'.");
        }
        return $column;
    }

    private function sanitizeIdentifier(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $value) ?? '';
    }

    private function sendJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Accept, Content-Type');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
    }

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function normalizeConfig($config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if ($config === null || $config === '') {
            return [];
        }

        if (!is_string($config)) {
            throw new InvalidArgumentException('Paginator config must be an array, JSON string, or path to a JSON file.');
        }

        $trimmed = trim($config);
        if ($trimmed === '') {
            return [];
        }

        if ($this->looksLikeJson($trimmed)) {
            return $this->decodeJsonConfig($trimmed, 'inline JSON');
        }

        if (!preg_match('/\.json$/i', $trimmed)) {
            throw new InvalidArgumentException('Paginator config string must be valid JSON or a .json file path.');
        }

        $filePath = $trimmed;
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\/)/', $filePath)) {
            $filePath = __DIR__ . DIRECTORY_SEPARATOR . ltrim($filePath, '\\/');
        }

        if (!is_file($filePath)) {
            throw new InvalidArgumentException("Paginator config file not found: {$trimmed}");
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read paginator config file: {$trimmed}");
        }

        return $this->decodeJsonConfig($contents, $trimmed);
    }

    private function looksLikeJson(string $value): bool
    {
        $first = substr($value, 0, 1);
        return $first === '{' || $first === '[';
    }

    private function decodeJsonConfig(string $json, string $source): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Invalid paginator JSON config in {$source}: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function normalizeViewportConfig($viewport): array
    {
        $viewport = is_array($viewport) ? $viewport : [];

        $widthMode = strtolower((string) ($viewport['width_mode'] ?? 'fill'));
        $heightMode = strtolower((string) ($viewport['height_mode'] ?? 'range'));

        $viewport['width_mode'] = in_array($widthMode, ['fill', 'fixed'], true) ? $widthMode : 'fill';
        $viewport['height_mode'] = in_array($heightMode, ['range', 'fixed', 'fill'], true) ? $heightMode : 'range';

        return $viewport;
    }

    private function buildViewportStyle(): string
    {
        $viewport = $this->normalizeViewportConfig($this->config['viewport'] ?? []);

        $styles = [
            '--dp-component-width' => '100%',
            '--dp-component-min-width' => '0px',
            '--dp-component-max-width' => 'none',
            '--dp-viewport-width' => '100%',
            '--dp-viewport-height' => 'auto',
            '--dp-viewport-min-height' => '320px',
            '--dp-viewport-max-height' => '500px',
        ];

        $widthMode = strtolower((string) ($viewport['width_mode'] ?? 'fill'));
        $heightMode = strtolower((string) ($viewport['height_mode'] ?? 'range'));

        if ($widthMode === 'fixed') {
            $width = $this->normalizeCssSize($viewport['width'] ?? '960px');
            if ($width !== null) {
                $styles['--dp-component-width'] = $width;
                $styles['--dp-component-max-width'] = $width;
            }
            $minWidth = $this->normalizeCssSize($viewport['min_width'] ?? null);
            if ($minWidth !== null) {
                $styles['--dp-component-min-width'] = $minWidth;
            }
        } else {
            $width = $this->normalizeCssSize($viewport['width'] ?? '100%');
            if ($width !== null) {
                $styles['--dp-component-width'] = $width;
            }
            $minWidth = $this->normalizeCssSize($viewport['min_width'] ?? null);
            if ($minWidth !== null) {
                $styles['--dp-component-min-width'] = $minWidth;
            }
            $maxWidth = $this->normalizeCssSize($viewport['max_width'] ?? null);
            if ($maxWidth !== null) {
                $styles['--dp-component-max-width'] = $maxWidth;
            }
        }

        if ($heightMode === 'fixed') {
            $height = $this->normalizeCssSize($viewport['height'] ?? '500px');
            if ($height !== null) {
                $styles['--dp-viewport-height'] = $height;
                $styles['--dp-viewport-min-height'] = $height;
                $styles['--dp-viewport-max-height'] = $height;
            }
        } elseif ($heightMode === 'fill') {
            $styles['--dp-viewport-height'] = '100%';
            $styles['--dp-viewport-min-height'] = '0px';
            $styles['--dp-viewport-max-height'] = 'none';
        } else {
            $height = $this->normalizeCssSize($viewport['height'] ?? null);
            $minHeight = $this->normalizeCssSize($viewport['min_height'] ?? '320px');
            $maxHeight = $this->normalizeCssSize($viewport['max_height'] ?? '500px');
            if ($height !== null) {
                $styles['--dp-viewport-height'] = $height;
            }
            if ($minHeight !== null) {
                $styles['--dp-viewport-min-height'] = $minHeight;
            }
            if ($maxHeight !== null) {
                $styles['--dp-viewport-max-height'] = $maxHeight;
            }
        }

        $declarations = [];
        foreach ($styles as $property => $value) {
            $declarations[] = $property . ':' . $value;
        }

        return implode(';', $declarations) . ';';
    }

    private function normalizeCssSize($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value . 'px';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return $value . 'px';
        }

        if (preg_match('/^\d+(?:\.\d+)?(px|%|vh|vw|rem|em|ch|svh|dvh|lvh)$/i', $value)) {
            return $value;
        }

        if (in_array(strtolower($value), ['auto', 'none', 'fit-content', 'min-content', 'max-content', '100%'], true)) {
            return $value;
        }

        return null;
    }
}
