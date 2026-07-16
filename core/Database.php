<?php

/**
 * Database Wrapper
 *
 * Provides convenient methods for common database operations.
 * Wraps PDO with helper methods while maintaining security.
 */

declare(strict_types=1);

namespace Core;

use PDO;
use PDOStatement;

class Database
{
    private PDO $pdo;
    private static ?Database $instance = null;

    /**
     * Create database wrapper.
     *
     * @param PDO $pdo PDO connection instance
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get singleton instance.
     *
     * @return self|null
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Set singleton instance.
     *
     * @param PDO $pdo
     * @return self
     */
    public static function setInstance(PDO $pdo): self
    {
        self::$instance = new self($pdo);
        return self::$instance;
    }

    /**
     * Get underlying PDO connection.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query and return all results.
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Named parameters
     * @return array All rows as associative arrays
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute a query and return first row.
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Named parameters
     * @return array|null First row or null if no results
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Execute a query and return single column value.
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Named parameters
     * @return mixed First column of first row, or null
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->execute($sql, $params);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : null;
    }

    /**
     * Insert a row and return the last insert ID.
     *
     * @param string $table Table name
     * @param array $data Column => value pairs
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $this->execute($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update rows and return affected count.
     *
     * @param string $table Table name
     * @param array $data Column => value pairs to update
     * @param string $where WHERE clause (without 'WHERE')
     * @param array $whereParams Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $paramName = 'set_' . $column;
            $setClauses[] = $this->quoteIdentifier($column) . ' = :' . $paramName;
            $params[$paramName] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            $where
        );

        $stmt = $this->execute($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    /**
     * Delete rows and return affected count.
     *
     * @param string $table Table name
     * @param string $where WHERE clause (without 'WHERE')
     * @param array $params Parameters for WHERE clause
     * @return int Number of deleted rows
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $where
        );

        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Check if a record exists.
     *
     * @param string $table Table name
     * @param string $where WHERE clause
     * @param array $params Parameters
     * @return bool True if at least one row exists
     */
    public function exists(string $table, string $where, array $params = []): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s LIMIT 1',
            $this->quoteIdentifier($table),
            $where
        );

        return $this->fetchColumn($sql, $params) !== null;
    }

    /**
     * Count rows matching criteria.
     *
     * @param string $table Table name
     * @param string $where WHERE clause (optional)
     * @param array $params Parameters
     * @return int Row count
     */
    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $where
        );

        return (int) $this->fetchColumn($sql, $params);
    }

    /**
     * Execute a prepared statement.
     *
     * @param string $sql SQL query
     * @param array $params Named parameters
     * @return PDOStatement Executed statement
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Run a callback within a transaction.
     *
     * @param callable $callback Function to execute
     * @return mixed Return value from callback
     * @throws \Throwable Re-throws any exception after rollback
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Check if currently in a transaction.
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Quote an identifier (table/column name).
     *
     * @param string $identifier
     * @return string Quoted identifier
     */
    private function quoteIdentifier(string $identifier): string
    {
        // Only allow alphanumeric and underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }
        return '`' . $identifier . '`';
    }

    /**
     * Get last insert ID.
     *
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
