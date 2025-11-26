<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

abstract class Model
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1";
        return $this->db->fetchOne($sql, ['id' => $id]);
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$column} = :value LIMIT 1";
        return $this->db->fetchOne($sql, ['value' => $value]);
    }

    public function all(): array
    {
        return $this->db->fetchAll("SELECT * FROM {$this->table}");
    }

    public function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) RETURNING %s",
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $this->primaryKey
        );

        $stmt = $this->db->query($sql, $data);
        $result = $stmt->fetch();
        return (int)$result[$this->primaryKey];
    }

    public function update(int $id, array $data): bool
    {
        $sets = array_map(fn($col) => "$col = :$col", array_keys($data));
        $data['id'] = $id;

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = :id",
            $this->table,
            implode(', ', $sets),
            $this->primaryKey
        );

        return $this->db->execute($sql, $data);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id";
        return $this->db->execute($sql, ['id' => $id]);
    }
}
