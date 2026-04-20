<?php

declare(strict_types=1);

const DP2_DB_CONFIG_PATH = __DIR__ . '/config/db.config.php';
const DP2_INSTALL_DEFAULTS = [
    'host' => 'localhost',
    'port' => '3306',
    'username' => 'root',
    'password' => '',
    'database' => 'pagination_demo',
    'sql_file' => 'sql/pagination_demo.sample.sql',
    'drop_existing' => false,
];

function dp2_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dp2_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function dp2_load_db_config_defaults(): array
{
    $defaults = DP2_INSTALL_DEFAULTS;
    if (!is_file(DP2_DB_CONFIG_PATH)) {
        return $defaults;
    }

    $config = require DP2_DB_CONFIG_PATH;
    if (!is_array($config)) {
        return $defaults;
    }

    return array_replace($defaults, array_intersect_key($config, $defaults));
}

function dp2_collect_input(): array
{
    $defaults = dp2_load_db_config_defaults();

    if (dp2_is_cli()) {
        $options = getopt('', [
            'host::',
            'port::',
            'username::',
            'password::',
            'database::',
            'sql-file::',
            'drop-existing',
        ]);

        return [
            'host' => trim((string) ($options['host'] ?? $defaults['host'])),
            'port' => trim((string) ($options['port'] ?? $defaults['port'])),
            'username' => trim((string) ($options['username'] ?? $defaults['username'])),
            'password' => (string) ($options['password'] ?? $defaults['password']),
            'database' => trim((string) ($options['database'] ?? $defaults['database'])),
            'sql_file' => trim((string) ($options['sql-file'] ?? $defaults['sql_file'])),
            'drop_existing' => isset($options['drop-existing']),
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $defaults;
    }

    return [
        'host' => trim((string) ($_POST['host'] ?? $defaults['host'])),
        'port' => trim((string) ($_POST['port'] ?? $defaults['port'])),
        'username' => trim((string) ($_POST['username'] ?? $defaults['username'])),
        'password' => (string) ($_POST['password'] ?? $defaults['password']),
        'database' => trim((string) ($_POST['database'] ?? $defaults['database'])),
        'sql_file' => trim((string) ($_POST['sql_file'] ?? $defaults['sql_file'])),
        'drop_existing' => isset($_POST['drop_existing']),
    ];
}

function dp2_resolve_sql_path(string $input): string
{
    $candidate = $input;
    if ($candidate === '') {
        throw new RuntimeException('SQL file is required.');
    }

    if (!preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\/)/', $candidate)) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . ltrim($candidate, '\\/');
    }

    $realPath = realpath($candidate);
    if ($realPath === false || !is_file($realPath)) {
        throw new RuntimeException("SQL dump not found: {$input}");
    }

    return $realPath;
}

function dp2_normalize_database_name(string $database): string
{
    $database = trim($database);
    if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('Database name must use only letters, numbers, and underscores.');
    }

    return $database;
}

function dp2_prepare_dump_sql(string $sql, string $database): string
{
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';

    $sql = preg_replace_callback(
        '/(-- Current Database:\s*`)([^`]+)(`)/',
        static fn(array $matches): string => $matches[1] . $database . $matches[3],
        $sql,
        1
    ) ?? $sql;

    $sql = preg_replace_callback(
        '/(CREATE DATABASE\b.*?`)([^`]+)(`)/i',
        static fn(array $matches): string => $matches[1] . $database . $matches[3],
        $sql,
        1
    ) ?? $sql;

    $sql = preg_replace_callback(
        '/(USE `)([^`]+)(`;)/i',
        static fn(array $matches): string => $matches[1] . $database . $matches[3],
        $sql,
        1
    ) ?? $sql;

    if (!preg_match('/CREATE DATABASE\b/i', $sql)) {
        $sql = "CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE {$quotedDatabase};\n\n" . $sql;
    }

    return $sql;
}

function dp2_execute_multi_query(mysqli $connection, string $sql): void
{
    if (!$connection->multi_query($sql)) {
        throw new RuntimeException('SQL import failed: ' . $connection->error);
    }

    do {
        $result = $connection->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());

    if ($connection->errno) {
        throw new RuntimeException('SQL import failed: ' . $connection->error);
    }
}

function dp2_install(array $input): array
{
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('The mysqli extension is required to run this installer.');
    }

    $host = $input['host'] !== '' ? $input['host'] : DP2_INSTALL_DEFAULTS['host'];
    $port = (int) ($input['port'] !== '' ? $input['port'] : DP2_INSTALL_DEFAULTS['port']);
    $username = $input['username'] !== '' ? $input['username'] : DP2_INSTALL_DEFAULTS['username'];
    $password = (string) $input['password'];
    $database = dp2_normalize_database_name((string) $input['database']);
    $sqlPath = dp2_resolve_sql_path((string) $input['sql_file']);

    $rawSql = (string) file_get_contents($sqlPath);
    if ($rawSql === '') {
        throw new RuntimeException('SQL dump file is empty.');
    }

    $sql = dp2_prepare_dump_sql($rawSql, $database);

    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = @new mysqli($host, $username, $password, '', $port);
    if ($connection->connect_errno) {
        throw new RuntimeException('Unable to connect to MySQL: ' . $connection->connect_error);
    }

    try {
        $connection->set_charset('utf8mb4');

        if (!empty($input['drop_existing'])) {
            $connection->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $database) . '`');
        }

        dp2_execute_multi_query($connection, $sql);

        $verification = @new mysqli($host, $username, $password, $database, $port);
        if ($verification->connect_errno) {
            throw new RuntimeException('Database imported but verification failed: ' . $verification->connect_error);
        }

        try {
            $verification->set_charset('utf8mb4');

            $tables = [];
            $tableResult = $verification->query('SHOW TABLES');
            if ($tableResult instanceof mysqli_result) {
                while ($row = $tableResult->fetch_array(MYSQLI_NUM)) {
                    $tables[] = (string) $row[0];
                }
                $tableResult->free();
            }

            $colorsCount = 0;

            if (in_array('colors', $tables, true)) {
                $result = $verification->query('SELECT COUNT(*) AS c FROM `colors`');
                if ($result instanceof mysqli_result) {
                    $row = $result->fetch_assoc();
                    $colorsCount = (int) ($row['c'] ?? 0);
                    $result->free();
                }
            }

            return [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'sql_path' => $sqlPath,
                'tables' => $tables,
                'colors_count' => $colorsCount,
            ];
        } finally {
            $verification->close();
        }
    } finally {
        $connection->close();
    }
}

$input = dp2_collect_input();
$result = null;
$error = null;

if (dp2_is_cli() || $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = dp2_install($input);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (dp2_is_cli()) {
    if ($error !== null) {
        fwrite(STDERR, "Install failed: {$error}\n");
        exit(1);
    }

    fwrite(STDOUT, "Install complete.\n");
    fwrite(STDOUT, "Database: {$result['database']}\n");
    fwrite(STDOUT, "Tables: " . implode(', ', $result['tables']) . "\n");
    fwrite(STDOUT, "Colors rows: {$result['colors_count']}\n");
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Paginator Installer</title>
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #f5f9fd 0%, #eaf1f8 100%);
            color: #18324a;
        }

        .page {
            max-width: 920px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .card {
            background: #fff;
            border: 1px solid #d7e3ee;
            border-radius: 18px;
            box-shadow: 0 20px 40px rgba(17, 77, 146, 0.08);
            padding: 28px;
        }

        .lead {
            font-size: 1.02rem;
            color: #35526c;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
        }

        p {
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 600;
        }

        input[type="text"],
        input[type="password"],
        input[type="number"] {
            border: 1px solid #b8cada;
            border-radius: 10px;
            padding: 11px 12px;
            font: inherit;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
        }

        .actions {
            margin-top: 24px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        button {
            border: 0;
            border-radius: 999px;
            background: linear-gradient(135deg, #114d92 0%, #0d6bd6 100%);
            color: #fff;
            font: inherit;
            font-weight: 700;
            padding: 12px 18px;
            cursor: pointer;
        }

        .note,
        .success,
        .error {
            border-radius: 14px;
            padding: 16px 18px;
            margin-top: 22px;
        }

        .note {
            background: #f6faff;
            border: 1px solid #d7e8f8;
        }

        .success {
            background: #eefaf1;
            border: 1px solid #b7e0c1;
            color: #1d5b2a;
        }

        .error {
            background: #fff1f1;
            border: 1px solid #efc0c0;
            color: #8d2222;
        }

        code {
            background: #eff5fb;
            border-radius: 6px;
            padding: 2px 6px;
        }

        ul {
            margin: 12px 0 0 20px;
        }

        ol {
            margin: 12px 0 0 20px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <h1>Dynamic Paginator Installer</h1>
            <p class="lead">
                Use this page to create the sample <code>pagination_demo</code> database for the Dynamic Paginator demo and import the bundled
                <code>colors</code> dataset.
            </p>
            <p>
                This installer is intended for developers who copied the project into a new environment and want the demo working quickly without
                building the sample database by hand. Enter your database connection details, choose the target database name, and run the import.
            </p>

            <?php if ($error !== null): ?>
            <div class="error">
                <strong>Install failed.</strong>
                <div><?= dp2_h($error) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($result !== null && $error === null): ?>
            <div class="success">
                <strong>Demo database installed.</strong>
                <ul>
                    <li>Database: <code><?= dp2_h((string) $result['database']) ?></code></li>
                    <li>Tables: <code><?= dp2_h(implode(', ', $result['tables'])) ?></code></li>
                    <li>Colors rows: <code><?= dp2_h((string) $result['colors_count']) ?></code></li>
                    <li>SQL source: <code><?= dp2_h((string) $result['sql_path']) ?></code></li>
                </ul>
            </div>
            <?php endif; ?>

            <div class="note">
                <strong>How to use this installer</strong>
                <ol>
                    <li>Enter the MySQL or MariaDB host, port, username, and password for the environment where you want the demo database created.</li>
                    <li>Leave the database name as <code>pagination_demo</code> if you want the demo to work without changing the PHP config files.</li>
                    <li>Keep the SQL file set to <code>sql/pagination_demo.sample.sql</code> unless you are importing a different seed file.</li>
                    <li>Enable <code>Drop the target database first</code> only when you intentionally want to replace an existing demo database.</li>
                    <li>After import, load <code>index.php</code> to confirm the paginator can read the new <code>colors</code> table.</li>
                </ol>
            </div>

            <form method="post">
                <div class="grid">
                    <div class="field">
                        <label for="host">Host</label>
                        <input id="host" name="host" type="text" value="<?= dp2_h((string) $input['host']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="port">Port</label>
                        <input id="port" name="port" type="number" min="1" max="65535" value="<?= dp2_h((string) $input['port']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" value="<?= dp2_h((string) $input['username']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" value="<?= dp2_h((string) $input['password']) ?>">
                    </div>
                    <div class="field">
                        <label for="database">Database name</label>
                        <input id="database" name="database" type="text" value="<?= dp2_h((string) $input['database']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="sql_file">SQL dump file</label>
                        <input id="sql_file" name="sql_file" type="text" value="<?= dp2_h((string) $input['sql_file']) ?>" required>
                    </div>
                </div>

                <label class="checkbox">
                    <input type="checkbox" name="drop_existing" value="1" <?= !empty($input['drop_existing']) ? 'checked' : '' ?>>
                    Drop the target database first if it already exists
                </label>

                <div class="actions">
                    <button type="submit">Install sample database</button>
                </div>
            </form>

            <div class="note">
                <strong>Setup notes</strong>
                <ul>
                    <li>The bundled dump contains the demo <code>colors</code> table used by the sample paginator interface.</li>
                    <li>If you choose a different database name, the installer rewrites the dump to use that name during import.</li>
                    <li>The demo code expects <code>pagination_demo</code> by default. If you use another name, update <code>config/db.config.php</code>.</li>
                    <li>Shared hosting providers may require a remote database hostname and a database name prefixed with your account name.</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
