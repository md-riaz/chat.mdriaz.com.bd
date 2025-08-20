<?php

declare(strict_types=1);

namespace Framework\Core;

class QueryBuilder
{
    private Database $db;
    private string $table;
    /** @var class-string<Model> */
    private string $modelClass;
    private array $columns = ['*'];
    private array $conditions = [];
    private array $rawConditions = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(Database $db, string $table, string $modelClass)
    {
        $this->db = $db;
        $this->table = $table;
        $this->modelClass = $modelClass;
    }

    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function where(array $conditions): self
    {
        $this->conditions = array_merge($this->conditions, $conditions);
        return $this;
    }

    /**
     * Add a "IS NULL" condition for the given column.
     */
    public function whereNull(string $column): self
    {
        $this->conditions[] = [$column, 'IS', null];
        return $this;
    }

    public function whereRaw(string $sql, array $params = []): self
    {
        $this->rawConditions[] = ['sql' => $sql, 'params' => $params];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = $column . ' ' . strtoupper($direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    private function buildWhere(): array
    {
        $sqlParts = [];
        $params = [];

        if ($this->conditions !== []) {
            [$condSql, $condParams] = $this->modelClass::compileWhere($this->conditions);
            if ($condSql !== '') {
                $sqlParts[] = substr($condSql, 6); // remove leading 'WHERE '
                $params = $condParams;
            }
        }

        foreach ($this->rawConditions as $raw) {
            $sqlParts[] = $raw['sql'];
            $params = array_merge($params, $raw['params']);
        }

        if ($sqlParts === []) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $sqlParts), $params];
    }

    public function get(): array
    {
        [$whereSql, $params] = $this->buildWhere();
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table . ' ' . $whereSql;
        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        $rows = $this->db->query($sql, $params)->fetchAll() ?: [];

        $hydrator = \Closure::bind(
            static function (array $row) {
                return static::hydrate($row);
            },
            null,
            $this->modelClass
        );

        return array_map($hydrator, $rows);
    }

    public function count(): int
    {
        [$whereSql, $params] = $this->buildWhere();
        $sql = 'SELECT COUNT(*) as count FROM ' . $this->table . ' ' . $whereSql;
        $row = $this->db->query($sql, $params)->fetchArray();
        return (int)($row['count'] ?? 0);
    }
}

