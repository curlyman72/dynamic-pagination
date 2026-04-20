class DynamicPaginatorJS {
    constructor(element, ajaxUrl = null) {
        this.element = element;
        this.config = this.readConfig();
        this.ajaxUrl = ajaxUrl || this.config.apiEndpoint || element.dataset.apiEndpoint || 'paginator_ajax.php';

        this.state = {
            page: 1,
            perPage: this.config.pageSize || 25,
            search: '',
            filters: {},
            sortBy: null,
            sortDirection: 'DESC',
        };

        this.meta = {
            page: 1,
            per_page: this.state.perPage,
            total_rows: 0,
            total_pages: 1,
            has_prev: false,
            has_next: false,
            query_signature: '',
        };

        this.cache = new Map();
        this.pageElements = new Map();
        this.pendingRequests = new Map();
        this.requestSequence = 0;
        this.abortController = null;
        this.currentVisiblePage = 1;
        this.isLoading = false;
        this.isRefreshing = false;
        this.pageObserver = null;
        this.scrollObserver = null;
        this.lastKnownDatasetProgress = 0;
        this.positionTrack = null;
        this.positionThumb = null;
        this.isDraggingPositionBar = false;
        this.isLoadingPreviousPage = false;
        this.previewPage = null;
        this.virtualForwardIntent = 0;
        this.virtualIntentThreshold = 120;
        this.stableViewportHeight = 0;
        this.effectiveViewportHeightMode = null;
        this.fillFallbackActive = false;
        this.hasWarnedAboutUnsafeFill = false;
        this.statusElement = this.element.querySelector('.pagination-status');
        this.lastAnnouncedMessage = '';

        this.tableViewport = this.element.querySelector('.pagination-data-wrapper');
        this.viewportBox = this.element.querySelector('.pagination-table-viewport') || this.tableViewport;
        this.tableHeadShell = this.element.querySelector('.pagination-table-head-shell');
        this.tableBodyShell = this.element.querySelector('.pagination-table-body-shell');
        this.tableShell = this.element.querySelector('.pagination-table-shell');
        this.wrapper = this.element.querySelector('.pagination-scroll-region') || this.tableViewport;
        this.sentinel = this.element.querySelector('.pagination-sentinel');
        this.errorBox = this.element.querySelector('.pagination-error');
        this.emptyState = this.element.querySelector('.pagination-empty');
        this.loadingIndicator = this.element.querySelector('.loading-indicator');
        this.positionBar = this.element.querySelector('.position-bar');
        this.headerTable = null;
        this.bodyTable = null;
        this.tableHead = null;
        this.tableBodyHost = null;
        this.columns = [];
        this.allColumns = [];
        this.columnOrder = [];
        this.hiddenColumns = new Set();
        this.pageData = new Map();
        this.toolbarConfig = this.config.toolbar || {};
        this.tableTitleElement = this.element.querySelector('.pagination-title');
        this.toolbarElement = this.element.querySelector('.pagination-toolbar');
        this.customizePanel = this.element.querySelector('.toolbar-customize-panel');
        this.columnList = this.element.querySelector('.toolbar-column-list');
        this.useSplitHeaderLayout = (this.config.tableScrollLayout || this.element.dataset.tableScrollLayout || 'single_region') === 'split_header';

        this.controls = {
            first: Array.from(this.element.querySelectorAll('.btn-first')),
            prev: Array.from(this.element.querySelectorAll('.btn-prev')),
            next: Array.from(this.element.querySelectorAll('.btn-next')),
            last: Array.from(this.element.querySelectorAll('.btn-last')),
            pageJump: Array.from(this.element.querySelectorAll('.page-jump')),
            totalPages: Array.from(this.element.querySelectorAll('.total-pages')),
            totalCount: Array.from(this.element.querySelectorAll('.count')),
            pageSizeInput: this.element.querySelector('.toolbar-page-size-input'),
            applyPageSize: this.element.querySelector('.toolbar-apply-page-size'),
            sortColumn: this.element.querySelector('.toolbar-sort-column'),
            sortDirection: this.element.querySelector('.toolbar-sort-direction'),
            applySort: this.element.querySelector('.toolbar-apply-sort'),
            customizeToggle: this.element.querySelector('.toolbar-customize-toggle'),
        };

        this.handleScroll = this.handleScroll.bind(this);
        this.handleIntersect = this.handleIntersect.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleWheel = this.handleWheel.bind(this);
        this.handleWrapperPointerDown = this.handleWrapperPointerDown.bind(this);
        this.handleWindowResize = this.handleWindowResize.bind(this);
        this.handlePositionPointerMove = this.handlePositionPointerMove.bind(this);
        this.handlePositionPointerUp = this.handlePositionPointerUp.bind(this);
        this.handlePositionThumbKeydown = this.handlePositionThumbKeydown.bind(this);
        this.init();
    }

    async init() {
        this.hydrateInitialState();
        this.applyViewportModeSafety();
        this.bindEvents();
        this.setupObservers();
        window.addEventListener('resize', this.handleWindowResize);
        await this.reload({ resetScroll: true, preserveCache: false });
    }

    readConfig() {
        const script = this.element.querySelector('.dynamic-paginator-config');
        if (!script) {
            return {};
        }

        try {
            return JSON.parse(script.textContent || '{}');
        } catch (error) {
            console.error('Invalid paginator config JSON.', error);
            return {};
        }
    }

    hydrateInitialState() {
        const initial = this.config.initialState || {};
        this.state.page = initial.page || 1;
        this.state.perPage = initial.perPage || this.state.perPage;
        this.state.search = initial.search || '';
        this.state.filters = initial.filters || {};
        this.state.sortBy = initial.sortBy || null;
        this.state.sortDirection = initial.sortDirection || 'DESC';
        this.hiddenColumns = new Set(this.toolbarConfig.default_hidden_columns || []);
        this.columnOrder = Array.isArray(this.toolbarConfig.default_column_order) ? this.toolbarConfig.default_column_order.slice() : [];

        if (this.tableTitleElement && this.config.tableTitle) {
            this.tableTitleElement.textContent = this.config.tableTitle;
        }
    }

    bindEvents() {
        if (!this.wrapper) {
            throw new Error('Paginator scroll region not found. Expected .pagination-scroll-region inside .dynamic-paginator.');
        }

        if (!this.wrapper.hasAttribute('tabindex')) {
            this.wrapper.tabIndex = 0;
        }

        this.wrapper.addEventListener('scroll', this.handleScroll, { passive: true });
        this.wrapper.addEventListener('keydown', this.handleKeydown);
        this.wrapper.addEventListener('wheel', this.handleWheel, { passive: true });
        this.wrapper.addEventListener('pointerdown', this.handleWrapperPointerDown);
        this.wrapper.addEventListener('pointerdown', () => {
            this.wrapper.focus({ preventScroll: true });
        });

        this.controls.first.forEach((button) => {
            button.addEventListener('click', () => this.goToPage(1));
        });

        this.controls.prev.forEach((button) => {
            button.addEventListener('click', () => this.goToPage(Math.max(1, this.currentVisiblePage - 1)));
        });

        this.controls.next.forEach((button) => {
            button.addEventListener('click', () => this.goToPage(Math.min(this.meta.total_pages, this.currentVisiblePage + 1)));
        });

        this.controls.last.forEach((button) => {
            button.addEventListener('click', () => this.goToPage(this.meta.total_pages));
        });

        this.controls.pageJump.forEach((input) => {
            input.addEventListener('change', (event) => {
                const nextPage = Number.parseInt(event.target.value, 10);
                if (Number.isFinite(nextPage)) {
                    this.goToPage(nextPage);
                }
            });
        });

        if (this.controls.applyPageSize) {
            this.controls.applyPageSize.addEventListener('click', () => {
                const nextSize = this.controls.pageSizeInput ? Number.parseInt(this.controls.pageSizeInput.value, 10) : this.state.perPage;
                this.updatePageSize(nextSize);
            });
        }

        if (this.controls.pageSizeInput) {
            this.controls.pageSizeInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const nextSize = Number.parseInt(event.target.value, 10);
                    this.updatePageSize(nextSize);
                }
            });
        }

        if (this.controls.applySort) {
            this.controls.applySort.addEventListener('click', () => {
                const sortBy = this.controls.sortColumn ? this.controls.sortColumn.value : this.state.sortBy;
                const sortDirection = this.controls.sortDirection ? this.controls.sortDirection.value : this.state.sortDirection;
                this.updateSort(sortBy, sortDirection);
            });
        }

        if (this.controls.sortDirection) {
            this.controls.sortDirection.value = this.state.sortDirection || 'ASC';
        }

        if (this.controls.customizeToggle && this.customizePanel) {
            this.controls.customizeToggle.addEventListener('click', () => {
                const expanded = this.controls.customizeToggle.getAttribute('aria-expanded') === 'true';
                const nextExpanded = expanded ? 'false' : 'true';
                this.controls.customizeToggle.setAttribute('aria-expanded', nextExpanded);
                this.controls.customizeToggle.setAttribute('aria-pressed', nextExpanded);
                this.customizePanel.hidden = expanded;
                if (!expanded) {
                    this.renderColumnCustomizationList();
                }
            });
        }

        if (this.positionBar) {
            this.positionTrack = this.positionBar.querySelector('.position-track');
            this.positionThumb = this.positionBar.querySelector('.position-thumb');

            if (this.positionTrack) {
                this.positionTrack.addEventListener('click', (event) => {
                    if (this.isDraggingPositionBar) {
                        return;
                    }

                    const progress = this.getProgressFromPointer(event.clientX);
                    this.jumpToProgress(progress);
                });
            }

            if (this.positionThumb) {
                this.positionThumb.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    this.isDraggingPositionBar = true;
                    this.positionThumb.setPointerCapture(event.pointerId);
                    this.handlePositionPointerMove(event);
                });

                this.positionThumb.addEventListener('pointermove', this.handlePositionPointerMove);
                this.positionThumb.addEventListener('pointerup', this.handlePositionPointerUp);
                this.positionThumb.addEventListener('pointercancel', this.handlePositionPointerUp);
                this.positionThumb.addEventListener('keydown', this.handlePositionThumbKeydown);
            }
        }
    }

    setupObservers() {
        if ('IntersectionObserver' in window && this.sentinel) {
            this.scrollObserver = new IntersectionObserver(this.handleIntersect, {
                root: this.wrapper,
                rootMargin: '0px 0px 200px 0px',
                threshold: 0,
            });
            this.scrollObserver.observe(this.sentinel);
        }

        if ('IntersectionObserver' in window) {
            this.pageObserver = new IntersectionObserver((entries) => {
                const visibleEntries = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio);

                if (visibleEntries.length === 0) {
                    return;
                }

                const page = Number.parseInt(visibleEntries[0].target.dataset.page || '1', 10);
                if (!Number.isFinite(page)) {
                    return;
                }

                this.currentVisiblePage = page;
                this.updateUI();
            }, {
                root: this.wrapper,
                threshold: [0.2, 0.45, 0.7],
            });
        }
    }

    handleScroll() {
        this.syncSplitHeaderScroll();

        if (this.wrapper.scrollTop <= 24) {
            this.loadPreviousPageIfNeeded();
        }

        if (this.hasScrollableViewport()) {
            this.resetForwardIntent();
        }

        if (this.wrapper.scrollTop + this.wrapper.clientHeight >= this.wrapper.scrollHeight - 24) {
            this.loadNextPageIfNeeded();
        }

        const datasetProgress = this.calculateDatasetProgressFromViewport();
        if (datasetProgress !== null) {
            this.lastKnownDatasetProgress = datasetProgress;
            const estimatedPage = this.progressToPage(datasetProgress);
            if (estimatedPage !== this.currentVisiblePage) {
                this.currentVisiblePage = estimatedPage;
                this.updateUI();
                return;
            }
        }

        this.updatePositionBar(this.lastKnownDatasetProgress);
    }

    handleKeydown(event) {
        if (event.target === this.positionThumb) {
            return;
        }

        switch (event.key) {
            case 'PageDown':
                if (this.shouldUseVirtualForwardIntent()) {
                    event.preventDefault();
                    this.consumeForwardIntent(this.wrapper.clientHeight || this.virtualIntentThreshold);
                    break;
                }
                event.preventDefault();
                this.goToPage((this.currentVisiblePage || 1) + 1);
                break;
            case 'PageUp':
                event.preventDefault();
                this.resetForwardIntent();
                this.goToPage((this.currentVisiblePage || 1) - 1);
                break;
            case 'ArrowDown':
                if (this.shouldUseVirtualForwardIntent()) {
                    event.preventDefault();
                    this.consumeForwardIntent(40);
                }
                break;
            case 'Home':
                event.preventDefault();
                this.resetForwardIntent();
                this.goToPage(1);
                break;
            case 'End':
                event.preventDefault();
                this.resetForwardIntent();
                this.goToPage(this.meta.total_pages || 1);
                break;
            case 'ArrowUp':
                this.resetForwardIntent();
                if (this.wrapper.scrollTop <= 4) {
                    this.loadPreviousPageIfNeeded();
                }
                break;
            default:
                break;
        }
    }

    handleWheel(event) {
        if (event.deltaY < 0 && this.wrapper.scrollTop <= 4) {
            this.resetForwardIntent();
            this.loadPreviousPageIfNeeded();
        }

        if (event.deltaY < 0) {
            this.resetForwardIntent();
            return;
        }

        if (event.deltaY > 0 && this.shouldUseVirtualForwardIntent()) {
            this.consumeForwardIntent(event.deltaY);
        }
    }

    handleWrapperPointerDown(event) {
        if (!this.shouldUseVirtualForwardIntent()) {
            return;
        }

        const rect = this.wrapper.getBoundingClientRect();
        const distanceFromBottom = rect.bottom - event.clientY;
        if (distanceFromBottom <= 40) {
            this.consumeForwardIntent(this.virtualIntentThreshold);
        }
    }

    async handleIntersect(entries) {
        const shouldLoad = entries.some((entry) => entry.isIntersecting);
        if (!shouldLoad) {
            return;
        }

        await this.loadNextPageIfNeeded();
    }

    async fetchData(page, options = {}) {
        const signature = this.getQuerySignatureSeed();
        const cacheKey = this.getCacheKey(signature, page);
        const useCache = options.useCache !== false;

        if (useCache && this.cache.has(cacheKey)) {
            return this.cache.get(cacheKey);
        }

        if (this.pendingRequests.has(cacheKey)) {
            return this.pendingRequests.get(cacheKey);
        }

        if (options.abortPrevious !== false && this.abortController) {
            this.abortController.abort();
        }

        const controller = new AbortController();
        if (options.abortPrevious !== false) {
            this.abortController = controller;
        }
        const requestId = ++this.requestSequence;
        const payload = {
            action: 'get_page',
            page,
            rowsPerPage: this.state.perPage,
            search: this.state.search,
            filters: this.state.filters,
            sortBy: this.state.sortBy,
            sortDirection: this.state.sortDirection,
        };

        const request = fetch(this.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            cache: 'no-store',
            signal: controller.signal,
            body: JSON.stringify(payload),
        })
            .then(async (response) => {
                if (response.status === 304 && this.cache.has(cacheKey)) {
                    return this.cache.get(cacheKey);
                }

                const result = response.status === 204 ? {} : await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Unable to load data.');
                }

                if (requestId !== this.requestSequence && !options.allowStale) {
                    throw new Error('Stale paginator response ignored.');
                }

                this.cache.set(cacheKey, result);
                return result;
            })
            .finally(() => {
                this.pendingRequests.delete(cacheKey);
            });

        this.pendingRequests.set(cacheKey, request);
        return request;
    }

    async reload(options = {}) {
        if (!options.preserveCache) {
            this.clearCache();
        }

        this.clearError();
        this.toggleEmpty(false);
        this.resetRenderedPages();
        this.currentVisiblePage = this.state.page;

        if (options.resetScroll) {
            this.wrapper.scrollTop = 0;
        }

        await this.loadPage(this.state.page, {
            replace: true,
            useCache: options.preserveCache === true,
            updateVisiblePage: true,
        });

        await this.prefetchNearbyPages(this.state.page);
    }

    async loadPage(page, options = {}) {
        if (page < 1) {
            return null;
        }

        this.setLoading(true);
        this.clearError();

        try {
            const response = await this.fetchData(page, {
                useCache: options.useCache,
                abortPrevious: options.abortPrevious !== false,
                allowStale: false,
            });

            this.meta = response.meta || this.meta;
            this.state.page = page;
            this.state.sortBy = (response.meta && response.meta.sort_by) || this.state.sortBy;
            this.state.sortDirection = (response.meta && response.meta.sort_direction) || this.state.sortDirection;

            if (!Array.isArray(response.data) || response.data.length === 0) {
                if (page === 1) {
                    this.resetRenderedPages();
                    this.toggleEmpty(true);
                }
                this.updateUI();
                return response;
            }

            if (options.replace) {
                this.resetRenderedPages();
            }

            this.toggleEmpty(false);
            this.ensureTable(response.meta.columns || Object.keys(response.data[0] || {}));

            this.renderPage(page, response.data);
            this.syncSplitHeaderLayout();
            this.captureStableViewportHeight();
            if (options.updateVisiblePage !== false) {
                this.currentVisiblePage = page;
                this.lastKnownDatasetProgress = this.pageToProgress(page);
            }

            this.updateUI();

            if (options.append !== true) {
                this.wrapper.scrollTo({
                    top: 0,
                    behavior: options.smoothScroll ? 'smooth' : 'auto',
                });
            }

            return response;
        } catch (error) {
            if (error.name === 'AbortError' || error.message === 'Stale paginator response ignored.') {
                return null;
            }

            this.showError(error.message || 'Unable to load data.');
            throw error;
        } finally {
            this.setLoading(false);
        }
    }

    renderPage(page, rows) {
        if (!this.tableBodyHost) {
            return;
        }

        this.pageData.set(page, rows);

        let tbody = this.pageElements.get(page);
        if (!tbody) {
            tbody = document.createElement('tbody');
            tbody.dataset.page = String(page);
            tbody.className = 'page-block';
            this.pageElements.set(page, tbody);
            this.insertPageBody(tbody, page);
            if (this.pageObserver) {
                this.pageObserver.observe(tbody);
            }
        }

        tbody.innerHTML = '';

        rows.forEach((row, rowIndex) => {
            const tr = document.createElement('tr');
            tr.className = 'data-row';
            tr.dataset.page = String(page);
            tr.dataset.rowIndex = String(rowIndex);

            this.getVisibleColumns().forEach((column) => {
                const td = document.createElement('td');
                td.textContent = this.formatCellValue(row[column]);
                td.title = td.textContent;
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });
    }

    insertPageBody(tbody, page) {
        const existingBodies = Array.from(this.tableBodyHost.querySelectorAll('tbody.page-block'));
        const nextBody = existingBodies.find((body) => Number.parseInt(body.dataset.page || '0', 10) > page);

        if (nextBody) {
            this.tableBodyHost.insertBefore(tbody, nextBody);
        } else {
            this.tableBodyHost.appendChild(tbody);
        }
    }

    ensureTable(columns) {
        if (this.useSplitHeaderLayout && (!this.tableHeadShell || !this.tableBodyShell)) {
            throw new Error('Paginator table shells not found. Expected .pagination-table-head-shell and .pagination-table-body-shell inside .dynamic-paginator.');
        }

        if (!this.useSplitHeaderLayout && !this.tableShell) {
            throw new Error('Paginator table shell not found. Expected .pagination-table-shell inside .dynamic-paginator.');
        }

        if ((this.useSplitHeaderLayout ? (this.headerTable && this.bodyTable) : this.bodyTable) && this.allColumns.length > 0) {
            return;
        }

        this.initializeColumns(columns);
        if (this.useSplitHeaderLayout) {
            this.headerTable = document.createElement('table');
            this.headerTable.className = 'pagination-table pagination-table-header';
            this.bodyTable = document.createElement('table');
            this.bodyTable.className = 'pagination-table pagination-table-body';
            this.tableHead = document.createElement('thead');
            this.tableBodyHost = this.bodyTable;

            this.renderTableHeader();
            this.headerTable.appendChild(this.tableHead);
            this.tableHeadShell.innerHTML = '';
            this.tableBodyShell.innerHTML = '';
            this.tableHeadShell.appendChild(this.headerTable);
            this.tableBodyShell.appendChild(this.bodyTable);
            this.syncSplitHeaderLayout();
        } else {
            this.bodyTable = document.createElement('table');
            this.bodyTable.className = 'pagination-table pagination-table-body';
            this.tableHead = document.createElement('thead');
            this.tableBodyHost = this.bodyTable;

            this.renderTableHeader();
            this.bodyTable.appendChild(this.tableHead);
            this.tableShell.innerHTML = '';
            this.tableShell.appendChild(this.bodyTable);
        }
        this.syncToolbarControls();
        this.renderColumnCustomizationList();
    }

    initializeColumns(columns) {
        this.allColumns = columns.slice();

        if (this.columnOrder.length === 0) {
            this.columnOrder = columns.slice();
        } else {
            const known = this.columnOrder.filter((column) => columns.includes(column));
            const missing = columns.filter((column) => !known.includes(column));
            this.columnOrder = known.concat(missing);
        }
    }

    getOrderedColumns() {
        return this.columnOrder.filter((column) => this.allColumns.includes(column));
    }

    getVisibleColumns() {
        return this.getOrderedColumns().filter((column) => !this.hiddenColumns.has(column));
    }

    renderTableHeader() {
        if (!this.tableHead) {
            return;
        }

        this.tableHead.innerHTML = '';
        const row = document.createElement('tr');

        this.getVisibleColumns().forEach((column) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = this.formatColumnName(column);
            row.appendChild(th);
        });

        this.tableHead.appendChild(row);
    }

    rerenderLoadedPages() {
        if (!this.tableHead) {
            return;
        }

        this.renderTableHeader();
        Array.from(this.pageData.keys()).sort((a, b) => a - b).forEach((page) => {
            this.renderPage(page, this.pageData.get(page) || []);
        });
        this.syncSplitHeaderLayout();
        this.syncToolbarControls();
        this.renderColumnCustomizationList();
    }

    handleWindowResize() {
        this.applyViewportModeSafety();
        this.syncSplitHeaderLayout();
        this.syncSplitHeaderScroll();
        this.captureStableViewportHeight();
    }

    syncSplitHeaderLayout() {
        if (!this.useSplitHeaderLayout || !this.headerTable || !this.bodyTable || !this.wrapper || !this.tableHeadShell) {
            return;
        }

        const scrollbarWidth = Math.max(0, this.wrapper.offsetWidth - this.wrapper.clientWidth);
        this.tableHeadShell.style.paddingRight = `${scrollbarWidth}px`;

        const bodyRow = this.bodyTable.querySelector('tbody.page-block tr');
        const bodyCells = bodyRow ? Array.from(bodyRow.children) : [];
        const headerCells = Array.from(this.headerTable.querySelectorAll('thead th'));
        const sourceCells = bodyCells.length > 0 ? bodyCells : headerCells;

        if (sourceCells.length === 0) {
            return;
        }

        const widths = sourceCells.map((cell) => Math.ceil(cell.getBoundingClientRect().width));
        this.applyColumnWidths(this.headerTable, widths);
        this.applyColumnWidths(this.bodyTable, widths);
        this.syncSplitHeaderScroll();
    }

    syncSplitHeaderScroll() {
        if (!this.useSplitHeaderLayout || !this.headerTable || !this.wrapper) {
            return;
        }

        this.headerTable.style.transform = `translateX(${-this.wrapper.scrollLeft}px)`;
    }

    applyColumnWidths(table, widths) {
        if (!table) {
            return;
        }

        let colgroup = table.querySelector('colgroup');
        if (!colgroup) {
            colgroup = document.createElement('colgroup');
            table.insertBefore(colgroup, table.firstChild);
        }

        colgroup.innerHTML = '';
        widths.forEach((width) => {
            const col = document.createElement('col');
            col.style.width = `${width}px`;
            colgroup.appendChild(col);
        });
    }

    getViewportHeightMode() {
        const viewport = this.config.viewport || {};
        const heightMode = String(viewport.height_mode || 'range').toLowerCase();
        return ['range', 'fixed', 'fill'].includes(heightMode) ? heightMode : 'range';
    }

    getEffectiveViewportHeightMode() {
        return this.effectiveViewportHeightMode || this.getViewportHeightMode();
    }

    getConfiguredRangeMinHeight() {
        const configuredValue = this.config.viewport && this.config.viewport.min_height;
        if (configuredValue) {
            return String(configuredValue);
        }

        const cssValue = getComputedStyle(this.element).getPropertyValue('--dp-viewport-min-height').trim();
        return cssValue || '320px';
    }

    getConfiguredRangeMaxHeight() {
        const configuredValue = this.config.viewport && this.config.viewport.max_height;
        if (configuredValue) {
            return String(configuredValue);
        }

        const cssValue = getComputedStyle(this.element).getPropertyValue('--dp-viewport-max-height').trim();
        return cssValue || '500px';
    }

    hasBoundedHeightContract() {
        let node = this.viewportBox;

        while (node && node !== document.body) {
            const styles = getComputedStyle(node);
            const explicitHeight = styles.height !== 'auto' && Number.parseFloat(styles.height) > 0;
            const explicitMaxHeight = styles.maxHeight !== 'none';
            const explicitMinHeight = Number.parseFloat(styles.minHeight) > 0;

            if (node !== this.viewportBox && (explicitHeight || explicitMaxHeight || explicitMinHeight)) {
                return true;
            }

            node = node.parentElement;
        }

        return false;
    }

    applyViewportModeSafety() {
        if (!this.viewportBox) {
            this.effectiveViewportHeightMode = this.getViewportHeightMode();
            return;
        }

        const configuredMode = this.getViewportHeightMode();
        if (configuredMode !== 'fill') {
            this.effectiveViewportHeightMode = configuredMode;
            this.fillFallbackActive = false;
            this.viewportBox.style.height = '';
            this.viewportBox.style.maxHeight = '';
            if (configuredMode !== 'range') {
                this.viewportBox.style.minHeight = '';
            }
            return;
        }

        if (this.hasBoundedHeightContract()) {
            this.effectiveViewportHeightMode = 'fill';
            this.fillFallbackActive = false;
            this.viewportBox.style.height = '100%';
            this.viewportBox.style.minHeight = '0px';
            this.viewportBox.style.maxHeight = 'none';
            return;
        }

        this.effectiveViewportHeightMode = 'range';
        this.fillFallbackActive = true;
        this.viewportBox.style.height = 'auto';
        this.viewportBox.style.minHeight = this.getConfiguredRangeMinHeight();
        this.viewportBox.style.maxHeight = this.getConfiguredRangeMaxHeight();

        if (!this.hasWarnedAboutUnsafeFill) {
            console.warn('DynamicPaginator: viewport.height_mode "fill" requires a bounded parent height. Falling back to range behavior.');
            this.hasWarnedAboutUnsafeFill = true;
        }
    }

    captureStableViewportHeight() {
        if (!this.viewportBox) {
            return;
        }

        const heightMode = this.getEffectiveViewportHeightMode();
        if (heightMode !== 'range') {
            this.stableViewportHeight = 0;
            this.viewportBox.style.minHeight = '';
            return;
        }

        const measuredHeight = Math.ceil(this.viewportBox.getBoundingClientRect().height);
        if (measuredHeight <= 0) {
            return;
        }

        this.stableViewportHeight = Math.max(this.stableViewportHeight, measuredHeight);
        this.viewportBox.style.minHeight = `${this.stableViewportHeight}px`;
    }

    syncToolbarControls() {
        if (this.controls.pageSizeInput) {
            this.controls.pageSizeInput.value = String(this.state.perPage || this.meta.per_page || this.config.pageSize || 25);
            this.controls.pageSizeInput.max = String(this.config.maxPageSize || 100);
        }

        if (this.controls.sortColumn) {
            this.controls.sortColumn.innerHTML = '';
            this.getOrderedColumns().forEach((column) => {
                const option = document.createElement('option');
                option.value = column;
                option.textContent = this.formatColumnName(column);
                if ((this.state.sortBy || this.meta.sort_by) === column) {
                    option.selected = true;
                }
                this.controls.sortColumn.appendChild(option);
            });
        }

        if (this.controls.sortDirection) {
            this.controls.sortDirection.value = this.state.sortDirection || this.meta.sort_direction || 'ASC';
        }
    }

    renderColumnCustomizationList() {
        if (!this.columnList) {
            return;
        }

        this.columnList.innerHTML = '';
        this.getOrderedColumns().forEach((column, index) => {
            const item = document.createElement('li');
            item.className = 'toolbar-column-item';

            const toggleWrap = document.createElement('label');
            toggleWrap.className = 'toolbar-column-toggle';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = !this.hiddenColumns.has(column);
            checkbox.setAttribute('aria-label', `Show or hide ${this.formatColumnName(column)} column`);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    this.hiddenColumns.delete(column);
                } else {
                    this.hiddenColumns.add(column);
                }
                this.rerenderLoadedPages();
            });

            const labelText = document.createElement('span');
            labelText.textContent = this.formatColumnName(column);

            toggleWrap.appendChild(checkbox);
            toggleWrap.appendChild(labelText);
            item.appendChild(toggleWrap);

            const actions = document.createElement('div');
            actions.className = 'toolbar-column-actions';

            const upButton = document.createElement('button');
            upButton.type = 'button';
            upButton.className = 'toolbar-move-btn';
            upButton.textContent = 'Move up';
            const isFirstColumn = index === 0;
            upButton.disabled = isFirstColumn;
            upButton.setAttribute('aria-label', isFirstColumn ? `${this.formatColumnName(column)} is already the first column` : `Move ${this.formatColumnName(column)} column up`);
            upButton.setAttribute('aria-disabled', isFirstColumn ? 'true' : 'false');
            upButton.title = isFirstColumn ? 'Already the first column' : 'Move column up';
            upButton.addEventListener('click', () => this.moveColumn(column, -1));

            const downButton = document.createElement('button');
            downButton.type = 'button';
            downButton.className = 'toolbar-move-btn';
            downButton.textContent = 'Move down';
            const isLastColumn = index === this.getOrderedColumns().length - 1;
            downButton.disabled = isLastColumn;
            downButton.setAttribute('aria-label', isLastColumn ? `${this.formatColumnName(column)} is already the last column` : `Move ${this.formatColumnName(column)} column down`);
            downButton.setAttribute('aria-disabled', isLastColumn ? 'true' : 'false');
            downButton.title = isLastColumn ? 'Already the last column' : 'Move column down';
            downButton.addEventListener('click', () => this.moveColumn(column, 1));

            actions.appendChild(upButton);
            actions.appendChild(downButton);
            item.appendChild(actions);
            this.columnList.appendChild(item);
        });
    }

    moveColumn(column, direction) {
        const currentIndex = this.columnOrder.indexOf(column);
        if (currentIndex === -1) {
            return;
        }

        const targetIndex = currentIndex + direction;
        if (targetIndex < 0 || targetIndex >= this.columnOrder.length) {
            return;
        }

        const nextOrder = this.columnOrder.slice();
        nextOrder.splice(currentIndex, 1);
        nextOrder.splice(targetIndex, 0, column);
        this.columnOrder = nextOrder;
        this.rerenderLoadedPages();
    }

    async updateSort(sortBy, sortDirection) {
        if (!sortBy) {
            return;
        }

        this.state.sortBy = sortBy;
        this.state.sortDirection = sortDirection === 'DESC' ? 'DESC' : 'ASC';
        this.state.page = 1;
        this.currentVisiblePage = 1;
        this.previewPage = null;
        this.lastKnownDatasetProgress = 0;
        this.clearCache();
        await this.reload({ resetScroll: true, preserveCache: false });
    }

    async updatePageSize(pageSize) {
        const maxPageSize = Math.max(1, Number.parseInt(this.config.maxPageSize || 100, 10));
        const nextSize = Math.max(1, Math.min(maxPageSize, Number.parseInt(pageSize, 10) || this.state.perPage || 25));

        if (nextSize === this.state.perPage) {
            this.syncToolbarControls();
            return;
        }

        this.state.perPage = nextSize;
        this.meta.per_page = nextSize;
        this.state.page = 1;
        this.currentVisiblePage = 1;
        this.previewPage = null;
        this.lastKnownDatasetProgress = 0;
        this.clearCache();
        await this.reload({ resetScroll: true, preserveCache: false });
    }

    async goToPage(page) {
        const target = Math.max(1, Math.min(this.meta.total_pages || 1, page));
        this.state.page = target;
        this.currentVisiblePage = target;
        this.lastKnownDatasetProgress = this.pageToProgress(target);
        await this.loadTargetPageView(target);
    }

    async prefetchNearbyPages(page) {
        const distance = Math.max(0, Number.parseInt(this.config.prefetchPages || 0, 10));
        const tasks = [];

        for (let offset = 1; offset <= distance; offset += 1) {
            const nextPage = page + offset;
            if (nextPage <= this.meta.total_pages) {
                tasks.push(
                    this.fetchData(nextPage, {
                        abortPrevious: false,
                        useCache: true,
                        allowStale: true,
                    }).catch(() => null)
                );
            }
        }

        await Promise.all(tasks);
    }

    async search(searchTerm, filters = {}) {
        this.state.search = searchTerm || '';
        this.state.filters = filters || {};
        this.state.page = 1;
        this.currentVisiblePage = 1;
        this.lastKnownDatasetProgress = 0;
        await this.reload({ resetScroll: true, preserveCache: false });
    }

    async refresh() {
        this.isRefreshing = true;
        this.clearCache();
        try {
            await this.loadTargetPageView(this.currentVisiblePage || this.state.page || 1);
        } finally {
            this.isRefreshing = false;
        }
    }

    handlePositionPointerMove(event) {
        if (!this.isDraggingPositionBar) {
            return;
        }

        const progress = this.getProgressFromPointer(event.clientX);
        this.lastKnownDatasetProgress = progress;
        this.previewPage = this.progressToPage(progress);
        this.updatePositionBar(progress);
        this.updateUI();
    }

    handlePositionPointerUp(event) {
        if (!this.isDraggingPositionBar) {
            return;
        }

        this.isDraggingPositionBar = false;
        if (this.positionThumb && this.positionThumb.hasPointerCapture(event.pointerId)) {
            this.positionThumb.releasePointerCapture(event.pointerId);
        }

        const progress = this.getProgressFromPointer(event.clientX);
        this.previewPage = null;
        this.jumpToProgress(progress);
    }

    handlePositionThumbKeydown(event) {
        const totalPages = Math.max(1, this.meta.total_pages || 1);
        const currentPreview = this.previewPage || this.currentVisiblePage || 1;
        let nextPage = currentPreview;

        switch (event.key) {
            case 'ArrowLeft':
            case 'ArrowUp':
                nextPage = currentPreview - 1;
                break;
            case 'ArrowRight':
            case 'ArrowDown':
                nextPage = currentPreview + 1;
                break;
            case 'PageUp':
                nextPage = currentPreview - 10;
                break;
            case 'PageDown':
                nextPage = currentPreview + 10;
                break;
            case 'Home':
                nextPage = 1;
                break;
            case 'End':
                nextPage = totalPages;
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                this.goToPage(currentPreview);
                return;
            default:
                return;
        }

        event.preventDefault();
        nextPage = Math.max(1, Math.min(totalPages, nextPage));
        this.previewPage = nextPage;
        this.lastKnownDatasetProgress = this.pageToProgress(nextPage);
        this.updateUI();
    }

    async loadTargetPageView(page) {
        this.clearError();
        this.toggleEmpty(false);
        this.resetRenderedPages();
        this.wrapper.scrollTop = 0;

        await this.loadPage(page, {
            replace: true,
            useCache: true,
            updateVisiblePage: true,
            smoothScroll: false,
        });

        await this.prefetchNearbyPages(page);
    }

    async loadPreviousPageIfNeeded() {
        if (!this.config.infiniteScroll || this.isLoading || this.isLoadingPreviousPage) {
            return;
        }

        const lowestLoadedPage = this.getLowestLoadedPage();
        if (lowestLoadedPage <= 1) {
            return;
        }

        const previousPage = lowestLoadedPage - 1;
        this.isLoadingPreviousPage = true;

        const previousScrollHeight = this.wrapper.scrollHeight;
        const previousScrollTop = this.wrapper.scrollTop;

        try {
            await this.loadPage(previousPage, {
                append: true,
                updateVisiblePage: false,
                useCache: true,
                abortPrevious: false,
            });

            const newScrollHeight = this.wrapper.scrollHeight;
            const heightDelta = newScrollHeight - previousScrollHeight;
            if (heightDelta > 0) {
                this.wrapper.scrollTop = previousScrollTop + heightDelta;
            }
        } finally {
            this.isLoadingPreviousPage = false;
        }
    }

    async loadNextPageIfNeeded() {
        if (!this.config.infiniteScroll || this.isLoading || !this.meta.has_next) {
            return;
        }

        const nextPage = this.getHighestLoadedPage() + 1;
        if (nextPage > this.meta.total_pages) {
            return;
        }

        await this.loadPage(nextPage, {
            append: true,
            updateVisiblePage: false,
            useCache: true,
            abortPrevious: false,
        });
    }

    hasScrollableViewport() {
        return !!this.wrapper && this.wrapper.scrollHeight > this.wrapper.clientHeight + 1;
    }

    shouldUseVirtualForwardIntent() {
        return !!this.wrapper && this.config.infiniteScroll && !this.isLoading && !this.hasScrollableViewport() && !!this.meta.has_next;
    }

    resetForwardIntent() {
        this.virtualForwardIntent = 0;
    }

    consumeForwardIntent(amount) {
        if (!this.shouldUseVirtualForwardIntent()) {
            this.resetForwardIntent();
            return;
        }

        this.virtualForwardIntent += Math.max(0, amount);
        if (this.virtualForwardIntent < this.virtualIntentThreshold) {
            return;
        }

        this.resetForwardIntent();
        this.loadNextPageIfNeeded();
    }

    resetRenderedPages() {
        if (this.pageObserver) {
            this.pageElements.forEach((body) => this.pageObserver.unobserve(body));
        }
        this.pageElements.clear();
        this.pageData.clear();
        if (this.tableHeadShell) {
            this.tableHeadShell.innerHTML = '';
        }
        if (this.tableBodyShell) {
            this.tableBodyShell.innerHTML = '';
        }
        if (this.tableShell) {
            this.tableShell.innerHTML = '';
        }
        this.headerTable = null;
        this.bodyTable = null;
        this.tableHead = null;
        this.tableBodyHost = null;
        this.columns = [];
        this.allColumns = [];
    }

    clearCache() {
        this.cache.clear();
    }

    getQuerySignatureSeed() {
        return JSON.stringify({
            search: this.state.search,
            filters: this.state.filters,
            sortBy: this.state.sortBy,
            sortDirection: this.state.sortDirection,
            perPage: this.state.perPage,
        });
    }

    getCacheKey(signature, page) {
        return `${signature}::${page}`;
    }

    getHighestLoadedPage() {
        const pages = Array.from(this.pageElements.keys());
        return pages.length === 0 ? this.currentVisiblePage : Math.max(...pages);
    }

    getLowestLoadedPage() {
        const pages = Array.from(this.pageElements.keys());
        return pages.length === 0 ? this.currentVisiblePage : Math.min(...pages);
    }

    getProgressFromPointer(clientX) {
        if (!this.positionTrack) {
            return 0;
        }

        const rect = this.positionTrack.getBoundingClientRect();
        if (rect.width <= 0) {
            return 0;
        }

        return Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    }

    async jumpToProgress(progress) {
        const clamped = Math.max(0, Math.min(1, progress));
        this.lastKnownDatasetProgress = clamped;
        const page = this.progressToPage(clamped);
        this.previewPage = null;
        await this.goToPage(page);
    }

    getSortedPageBlocks() {
        return Array.from(this.pageElements.entries())
            .sort((a, b) => a[0] - b[0])
            .map((entry) => entry[1]);
    }

    calculateDatasetProgressFromViewport() {
        const blocks = this.getSortedPageBlocks();
        if (blocks.length === 0 || !this.wrapper) {
            return null;
        }

        const wrapperRect = this.wrapper.getBoundingClientRect();
        const wrapperTop = wrapperRect.top;
        const wrapperBottom = wrapperRect.bottom;

        for (const block of blocks) {
            const rect = block.getBoundingClientRect();
            if (rect.bottom <= wrapperTop || rect.top >= wrapperBottom) {
                continue;
            }

            const page = Number.parseInt(block.dataset.page || '1', 10);
            if (!Number.isFinite(page)) {
                continue;
            }

            const traveled = Math.max(0, wrapperTop - rect.top);
            const localProgress = rect.height > 0 ? Math.min(1, traveled / rect.height) : 0;
            return this.pageToProgress(page, localProgress);
        }

        return this.pageToProgress(this.currentVisiblePage || 1);
    }

    pageToProgress(page, localProgress = 0) {
        const totalPages = Math.max(1, this.meta.total_pages || 1);
        if (totalPages === 1) {
            return 0;
        }

        const normalizedPage = Math.max(1, Math.min(totalPages, page));
        const clampedLocal = Math.max(0, Math.min(0.999, localProgress));
        return ((normalizedPage - 1) + clampedLocal) / (totalPages - 1);
    }

    progressToPage(progress) {
        const totalPages = Math.max(1, this.meta.total_pages || 1);
        if (totalPages === 1) {
            return 1;
        }

        const clamped = Math.max(0, Math.min(1, progress));
        return Math.max(1, Math.min(totalPages, Math.floor(clamped * (totalPages - 1)) + 1));
    }

    updateUI() {
        const currentPage = this.currentVisiblePage || this.state.page || 1;
        const displayedPage = this.previewPage || currentPage;
        const totalPages = this.meta.total_pages || 1;

        this.syncToolbarControls();

        this.controls.pageJump.forEach((input) => {
            input.value = String(displayedPage);
            input.max = String(totalPages);
        });

        this.controls.totalPages.forEach((el) => {
            el.textContent = String(totalPages);
        });

        this.controls.totalCount.forEach((el) => {
            el.textContent = Number(this.meta.total_rows || 0).toLocaleString();
        });

        const isFirst = displayedPage <= 1;
        const isLast = displayedPage >= totalPages;

        this.controls.first.forEach((button) => {
            button.disabled = isFirst || this.isLoading;
        });
        this.controls.prev.forEach((button) => {
            button.disabled = isFirst || this.isLoading;
        });
        this.controls.next.forEach((button) => {
            button.disabled = isLast || this.isLoading;
        });
        this.controls.last.forEach((button) => {
            button.disabled = isLast || this.isLoading;
        });

        this.updatePositionBar(this.lastKnownDatasetProgress || this.pageToProgress(displayedPage));
        this.updateAriaState(displayedPage, totalPages);
    }

    updateAriaState(displayedPage, totalPages) {
        if (this.positionThumb) {
            this.positionThumb.setAttribute('aria-valuemin', '1');
            this.positionThumb.setAttribute('aria-valuemax', String(totalPages));
            this.positionThumb.setAttribute('aria-valuenow', String(displayedPage));
            this.positionThumb.setAttribute('aria-valuetext', `Page ${displayedPage} of ${totalPages}`);
        }

        if (!this.statusElement) {
            return;
        }

        const message = this.previewPage
            ? `Selected page ${displayedPage} of ${totalPages}. Release to load this page.`
            : `Page ${displayedPage} of ${totalPages} loaded.`;

        if (message !== this.lastAnnouncedMessage) {
            this.statusElement.textContent = message;
            this.lastAnnouncedMessage = message;
        }
    }

    updatePositionBar(percentage = 0) {
        if (!this.positionBar) {
            return;
        }

        const fill = this.positionBar.querySelector('.position-fill');
        const thumb = this.positionBar.querySelector('.position-thumb');
        const clamped = Math.max(0, Math.min(1, percentage));

        if (fill) {
            fill.style.width = `${clamped * 100}%`;
        }
        if (thumb) {
            thumb.style.left = `${clamped * 100}%`;
        }
    }

    setLoading(isLoading) {
        this.isLoading = isLoading;
        if (this.loadingIndicator) {
            this.loadingIndicator.hidden = !isLoading;
        }
        this.wrapper.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        this.updateUI();
    }

    showError(message) {
        if (!this.errorBox) {
            return;
        }
        this.errorBox.hidden = false;
        this.errorBox.textContent = message;
    }

    clearError() {
        if (!this.errorBox) {
            return;
        }
        this.errorBox.hidden = true;
        this.errorBox.textContent = '';
    }

    toggleEmpty(show) {
        if (!this.emptyState) {
            return;
        }
        this.emptyState.hidden = !show;
    }

    formatColumnName(name) {
        return String(name).replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase());
    }

    formatCellValue(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value)) {
            const date = new Date(value);
            if (!Number.isNaN(date.valueOf())) {
                return date.toLocaleString();
            }
        }

        return String(value);
    }

    getCurrentPage() {
        return this.currentVisiblePage || this.state.page;
    }

    getTotalPages() {
        return this.meta.total_pages || 1;
    }

    getTotalRows() {
        return this.meta.total_rows || 0;
    }

    getRowsPerPage() {
        return this.meta.per_page || this.state.perPage;
    }

    getCacheSize() {
        return this.cache.size;
    }

    getCachedPages() {
        const signature = this.getQuerySignatureSeed() + '::';
        return Array.from(this.cache.keys())
            .filter((key) => key.startsWith(signature))
            .map((key) => Number.parseInt(key.split('::').pop(), 10))
            .filter((page) => Number.isFinite(page))
            .sort((a, b) => a - b);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.dynamic-paginator').forEach((element) => {
        const instance = new DynamicPaginatorJS(element, element.dataset.apiEndpoint);
        element.paginatorInstance = instance;
    });
});

window.getPaginator = function getPaginator(elementId) {
    const element = document.getElementById(elementId);
    return element ? element.paginatorInstance : null;
};
