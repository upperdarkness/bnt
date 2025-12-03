<?php

declare(strict_types=1);

namespace BNT\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection(): PDO
    {
        if (self::$connection === null) {
            $cfg = $this->config['database'];
            $dsn = sprintf(
                '%s:host=%s;port=%d;dbname=%s',
                $cfg['driver'],
                $cfg['host'],
                $cfg['port'],
                $cfg['database']
            );

            try {
                self::$connection = new PDO(
                    $dsn,
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['options']
                );
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$connection;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        
        // Bind parameters with explicit type casting for PostgreSQL
        foreach ($params as $key => $value) {
            // Parameter name should include colon for named parameters
            $paramName = (strpos($key, ':') === 0) ? $key : ':' . $key;
            
            if ($value === null) {
                // Handle NULL values explicitly
                $stmt->bindValue($paramName, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                // Explicitly bind boolean values
                $stmt->bindValue($paramName, $value, PDO::PARAM_BOOL);
            } elseif ($value === 'true' || $value === '1') {
                $stmt->bindValue($paramName, true, PDO::PARAM_BOOL);
            } elseif ($value === 'false' || $value === '0') {
                $stmt->bindValue($paramName, false, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($paramName, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($paramName, $value);
            }
        }
        
        $stmt->execute();
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    public function rollback(): bool
    {
        return $this->getConnection()->rollback();
    }
}
