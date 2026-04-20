<?php

declare(strict_types=1);

require_once __DIR__ . '/DynamicPaginator.php';

$dbConfig = require __DIR__ . '/config/db.config.php';

$configPath = __DIR__ . '/config/paginator.config.json';
$paginator = new DynamicPaginator(
    $dbConfig,
    'colors',
    '.pagination-data-wrapper',
    $configPath
);

$paginator
    ->setSearchableColumns(['name', 'category', 'description'])
    ->setFilterableColumns(['category'])
    ->setSortableColumns(['id', 'name', 'category', 'created_at'])
    ->setOrderBy('id', 'ASC');

$paginator->handleRequest();
