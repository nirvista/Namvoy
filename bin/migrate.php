#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies pending SQL migrations from database/migrations/ in filename order and
 * records each in schema_migrations. Migration SQL is static (no user input), so
 * multi_query() is acceptable here and ONLY here.
 *
 * Usage:
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied / pending
 */

use App\Database;

require __DIR__ . '/../config/bootstrap.php';

$migrationsDir = dirname(__DIR__) . '/database/migrations';
$conn = Database::connection();

$conn->query('CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(255) PRIMARY KEY,
  applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$applied = array_column(Database::fetchAll('SELECT filename FROM schema_migrations ORDER BY filename'), 'filename');

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);
$pending = array_values(array_filter($files, static fn (string $f): bool => !in_array(basename($f), $applied, true)));

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        $name = basename($f);
        printf("%-10s %s\n", in_array($name, $applied, true) ? 'applied' : 'pending', $name);
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "Applying {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "cannot read {$file}\n");
        exit(1);
    }

    // DDL is auto-committing in MySQL, so a failed migration cannot be rolled
    // back automatically: fix the SQL / database by hand and re-run.
    try {
        $conn->multi_query($sql);
        do {
            $result = $conn->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    } catch (mysqli_sql_exception $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  {$e->getMessage()}\n");
        exit(1);
    }

    Database::execute('INSERT INTO schema_migrations (filename) VALUES (?)', 's', [$name]);
    echo "done\n";
}

printf("Applied %d migration(s).\n", count($pending));
