<?php

declare(strict_types=1);

namespace App;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * mysqli connection wrapper. All queries that touch user-supplied values MUST go
 * through queryPrepared() (or the query()/fetchOne()/fetchAll()/execute() helpers
 * built on it). Never concatenate a variable into a SQL string.
 */
final class Database
{
    private static ?mysqli $conn = null;

    public static function connection(): mysqli
    {
        if (self::$conn !== null) {
            return self::$conn;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $conn = new mysqli(
            env('DB_HOST', '127.0.0.1'),
            env('DB_USER', ''),
            env('DB_PASS', ''),
            env('DB_NAME', ''),
            (int) env('DB_PORT', '3306')
        );
        $conn->set_charset('utf8mb4');

        self::$conn = $conn;
        return $conn;
    }

    /**
     * Prepare, bind and execute a statement.
     *
     * @param string $types  bind_param type string, e.g. "ssi" (one char per param)
     * @param array<int, mixed> $params
     */
    public static function queryPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        if (strlen($types) !== count($params)) {
            throw new RuntimeException(sprintf(
                'queryPrepared: type string length (%d) does not match param count (%d)',
                strlen($types),
                count($params)
            ));
        }

        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * Run a SELECT and return all rows as associative arrays.
     *
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = self::queryPrepared(self::connection(), $sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Run a SELECT and return the first row, or null.
     *
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = self::queryPrepared(self::connection(), $sql, $types, $params);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Run an INSERT/UPDATE/DELETE and return affected row count.
     *
     * @param array<int, mixed> $params
     */
    public static function execute(string $sql, string $types = '', array $params = []): int
    {
        $stmt = self::queryPrepared(self::connection(), $sql, $types, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Run $fn inside a transaction; commits on success, rolls back on any throwable.
     *
     * @template T
     * @param callable(mysqli): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $conn = self::connection();
        $conn->begin_transaction();
        try {
            $result = $fn($conn);
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
