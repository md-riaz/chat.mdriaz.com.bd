<?php

declare(strict_types=1);

namespace Framework\Core;

use InvalidArgumentException;
use RuntimeException;
use Framework\Core\Collection;

abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';

    // Whitelist of mass-assignable columns
    protected array $fillable = [];

    // Auto-manage created_at / updated_at if present
    protected bool $timestamps = true;

    // Current and original state for dirty checking
    protected array $attributes = [];
    protected array $original = [];
    protected array $relations = [];

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

    protected static function db(): Database
    {
        return DBManager::getDB();
    }

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::db(), static::$table, static::class);
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (method_exists($this, $name)) {
            $this->relations[$name] = $this->$name();
            return $this->relations[$name];
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if (\in_array($name, $this->fillable, true) || $name === static::$primaryKey) {
            $this->attributes[$name] = $value;
        }
    }

    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if (\in_array($key, $this->fillable, true)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    public function toArray(): array
    {
        $data = $this->attributes;

        foreach ($this->relations as $key => $relation) {
            if ($relation instanceof self) {
                $data[$key] = $relation->toArray();
            } elseif ($relation instanceof Collection) {
                $data[$key] = $relation->toArray();
            } elseif (is_array($relation)) {
                $data[$key] = $relation;
            } else {
                $data[$key] = $relation;
            }

            $foreignKey = $key . '_id';
            if (array_key_exists($foreignKey, $data)) {
                unset($data[$foreignKey]);
            }
        }

        return $data;
    }

    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    protected function foreignKeyName(string $class): string
    {
        $base = basename(str_replace('\\', '/', $class));
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
        return $snake . '_id';
    }

    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Collection
    {
        $foreignKey = $foreignKey ?? $this->foreignKeyName(static::class);
        $localKey = $localKey ?? static::$primaryKey;
        $value = $this->attributes[$localKey] ?? null;
        if ($value === null) {
            return new Collection();
        }
        /** @var class-string<Model> $related */
        $results = $related::where([[$foreignKey, '=', $value]]);
        return new Collection($results);
    }

    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        $results = $this->hasMany($related, $foreignKey, $localKey);
        return $results[0] ?? null;
    }

    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?Model
    {
        $foreignKey = $foreignKey ?? $this->foreignKeyName($related);
        /** @var class-string<Model> $related */
        $ownerKey = $ownerKey ?? $related::getPrimaryKey();
        $value = $this->attributes[$foreignKey] ?? null;
        if ($value === null) {
            return null;
        }
        return $related::first([[$ownerKey, '=', $value]]);
    }

    protected function belongsToMany(
        string $relatedClass,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $localKey = 'id',
        string $relatedKey = 'id',
        bool $withPivot = false
    ): Collection {
        $localValue = $this->attributes[$localKey] ?? null;
        if ($localValue === null) {
            return new Collection();
        }

        $db = static::db();

        $pivotRows = $db
            ->query(
                "SELECT * FROM {$pivotTable} WHERE {$foreignPivotKey} = ?",
                [$localValue]
            )
            ->fetchAll();
        if (!$pivotRows) {
            return new Collection();
        }

        $relatedIds = array_unique(array_column($pivotRows, $relatedPivotKey));
        $placeholders = implode(', ', array_fill(0, count($relatedIds), '?'));
        /** @var class-string<Model> $relatedClass */
        $relatedTable = $relatedClass::$table;
        $relatedRows = $db
            ->query(
                "SELECT * FROM {$relatedTable} WHERE {$relatedKey} IN ({$placeholders})",
                $relatedIds
            )
            ->fetchAll() ?: [];

        $pivotMap = [];
        foreach ($pivotRows as $pivot) {
            $pivotMap[$pivot[$relatedPivotKey]] = $pivot;
        }

        $results = [];
        foreach ($relatedRows as $row) {
            $model = new $relatedClass();
            $model->attributes = $row;
            $model->original = $row;
            if ($withPivot && isset($pivotMap[$row[$relatedKey]])) {
                $model->relations['_pivot'] = $pivotMap[$row[$relatedKey]];
            }
            $results[] = $model;
        }

        return new Collection($results);
    }

    public static function all(): array
    {
        $db = static::db();
        $table = static::$table;

        $rows = $db->query("SELECT * FROM {$table}")->fetchAll() ?: [];

        return array_map(function (array $row) {
            $model = new static();
            // Hydrate all columns, not only fillable
            $model->attributes = $row;
            $model->original = $row;

            return $model;
        }, $rows);
    }

    public static function find(int $id): ?static
    {
        $db = static::db();
        $table = static::$table;
        $pk = static::$primaryKey;

        $row = $db->query("SELECT * FROM {$table} WHERE {$pk} = ? LIMIT 1", [$id])->fetchArray();
        if (!$row) {
            return null;
        }

        $model = new static();
        // Hydrate all columns, not only fillable
        $model->attributes = $row;
        $model->original = $row;

        return $model;
    }

    public function save(): void
    {
        $pk = static::$primaryKey;
        $isNew = empty($this->attributes[$pk]);

        if ($this->timestamps) {
            $now = $this->now();
            $this->attributes['updated_at'] = $now;

            if ($isNew) {
                $this->attributes['created_at'] = $now;
            }
        }

        $isNew ? $this->insert() : $this->updateRow();

        // Sync original after successful persistence
        $this->original = $this->attributes;
    }

    public function delete(): void
    {
        $pk = static::$primaryKey;
        if (empty($this->attributes[$pk])) {
            return;
        }

        $db = static::db();
        $table = static::$table;

        $db->query("DELETE FROM {$table} WHERE {$pk} = ?", [$this->attributes[$pk]]);
    }

    protected function insert(): void
    {
        $db = static::db();
        $table = static::$table;
        $pk = static::$primaryKey;

        // Insert only fillable columns (and any explicitly set primary key)
        $insertable = array_values(array_unique(array_merge($this->fillable, [$pk])));
        $data = array_intersect_key($this->attributes, array_flip($insertable));
        unset($data[$pk]); // let DB autogenerate if auto-increment

        if ($data === []) {
            throw new RuntimeException('No attributes to insert.');
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $db->query($sql, array_values($data));

        // If PK is auto-increment, capture it
        if (empty($this->attributes[$pk])) {
            $this->attributes[$pk] = (int) $db->lastInsertID();
        }
    }

    protected function updateRow(): void
    {
        $db = static::db();
        $table = static::$table;
        $pk = static::$primaryKey;

        $changes = $this->changedAttributes();

        // Only persist fillable changes (never overwrite PK here)
        $changes = array_intersect_key($changes, array_flip($this->fillable));

        if ($changes === []) {
            return; // nothing to do
        }

        $sets = [];
        $values = [];
        foreach ($changes as $col => $val) {
            $sets[] = "{$col} = ?";
            $values[] = $val;
        }
        $values[] = $this->attributes[$pk];

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $table,
            implode(', ', $sets),
            $pk
        );

        $db->query($sql, $values);
    }

    protected function changedAttributes(): array
    {
        $changes = [];
        foreach ($this->attributes as $key => $value) {
            $orig = $this->original[$key] ?? null;
            if ($value !== $orig) {
                $changes[$key] = $value;
            }
        }
        return $changes;
    }

    protected function now(): string
    {
        $tzName = defined('TIMEZONE') ? TIMEZONE : \date_default_timezone_get();
        if (!$tzName) {
            $tzName = 'UTC';
        }

        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    /**
     * Fetch all rows matching the given conditions.
     *
     * Examples:
     *  User::where(['status' => 'active']);
     *  User::where([['age', '>=', 18], ['name', 'LIKE', 'A%']]);
     *  User::where([['id', 'IN', [1,2,3]]]);
     */
    public static function where(array $conditions): array
    {
        $db = static::db();
        $table = static::$table;

        [$whereSql, $params] = static::compileWhere($conditions);

        $sql = "SELECT * FROM {$table} {$whereSql}";
        $rows = $db->query($sql, $params)->fetchAll() ?: [];

        return array_map(function (array $row) {
            $model = new static();
            $model->attributes = $row; // hydrate all columns
            $model->original = $row;
            return $model;
        }, $rows);
    }

    /**
     * Fetch the first row matching the given conditions or null.
     *
     * Examples:
     *  User::first(['email' => 'foo@example.com']);
     *  User::first([['created_at', '>=', '2025-01-01 00:00:00']]);
     */
    public static function first(array $conditions): ?static
    {
        $db = static::db();
        $table = static::$table;

        [$whereSql, $params] = static::compileWhere($conditions);

        // LIMIT 1 is safe on MySQL/SQLite/Postgres
        $sql = "SELECT * FROM {$table} {$whereSql} LIMIT 1";
        $row = $db->query($sql, $params)->fetchArray();
        if (!$row) {
            return null;
        }

        $model = new static();
        $model->attributes = $row; // hydrate all columns
        $model->original = $row;
        return $model;
    }

    /**
     * Find the first record matching the conditions or create it.
     */
    public static function firstOrCreate(array $where, array $attributes = []): static
    {
        $existing = static::first($where);
        if ($existing !== null) {
            return $existing;
        }

        $model = new static($attributes + $where);
        $model->save();

        return $model;
    }

    /**
     * Update an existing record matching the conditions or create it.
     */
    public static function updateOrCreate(array $where, array $values = []): static
    {
        $model = static::first($where);
        if ($model !== null) {
            $model->fill($values);
            $model->save();
            return $model;
        }

        $model = new static($where + $values);
        $model->save();

        return $model;
    }

    /**
     * Insert multiple rows at once.
     */
    public static function bulkInsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $db = static::db();
        $table = static::$table;
        $pk = static::$primaryKey;

        $model = new static();
        $insertable = array_values(array_unique(array_merge($model->fillable, [$pk])));
        $columns = array_intersect($insertable, array_keys($rows[0]));
        if ($columns === []) {
            throw new RuntimeException('No columns to insert.');
        }

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = [];
        $values = [];
        foreach ($rows as $row) {
            $placeholders[] = $rowPlaceholder;
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $db->query($sql, $values);
    }

    /**
     * Delete multiple rows matching the given conditions.
     *
     * @return int number of affected rows
     */
    public static function bulkDelete(array $conditions): int
    {
        $db = static::db();
        $table = static::$table;

        [$whereSql, $params] = static::compileWhere($conditions);
        if ($whereSql === '') {
            throw new InvalidArgumentException('Bulk delete requires conditions.');
        }

        $sql = "DELETE FROM {$table} {$whereSql}";
        return $db->query($sql, $params)->affectedRows();
    }

    /**
     * Perform a select with optional joins, ordering, limits and conditions.
     *
     * @param array $conditions Where conditions
     * @param array $joins      Each join: [table, first, operator, second, type]
     * @param array $orderBy    Each order: [column, direction]
     * @param int|null $limit   Limit
     * @param int|null $offset  Offset
     * @param array|string $columns Columns to select
     * @return array
     */
    public static function filter(
        array $conditions = [],
        array $joins = [],
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null,
        array|string $columns = '*'
    ): array {
        $db = static::db();
        $table = static::$table;

        $select = is_array($columns) ? implode(', ', $columns) : $columns;

        $joinSql = '';
        foreach ($joins as $join) {
            $type = strtoupper($join['type'] ?? 'INNER');
            $joinTable = $join['table'];
            $first = $join['first'];
            $op = $join['operator'] ?? '=';
            $second = $join['second'];
            $joinSql .= " {$type} JOIN {$joinTable} ON {$first} {$op} {$second}";
        }

        [$whereSql, $params] = static::compileWhere($conditions);

        $orderSql = '';
        if ($orderBy) {
            $parts = [];
            foreach ($orderBy as $order) {
                if (is_array($order)) {
                    [$col, $dir] = $order + [1 => 'ASC'];
                    $parts[] = "{$col} " . strtoupper($dir);
                } else {
                    $parts[] = (string) $order;
                }
            }
            $orderSql = ' ORDER BY ' . implode(', ', $parts);
        }

        $limitSql = '';
        if ($limit !== null) {
            $limitSql = ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $limitSql .= ' OFFSET ' . (int) $offset;
            }
        }

        $sql = "SELECT {$select} FROM {$table}{$joinSql} {$whereSql}{$orderSql}{$limitSql}";
        return $db->query($sql, $params)->fetchAll() ?: [];
    }

    /**
     * Build a WHERE clause and parameters from a simple conditions array.
     *
     * Supported forms:
     *  - ['col' => $value]
     *  - ['col', 'OP', $value] where OP ∈ (=, !=, <, >, <=, >=, LIKE, IN)
     * For IN, value must be an array.
     *
     * @return array{0:string,1:array} [whereSql, params]
     */
    public static function compileWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $params = [];
        $paramCounter = 0;

        $where = static::buildWhere($conditions, $params, $paramCounter, 'AND');

        return ['WHERE ' . $where, $params];
    }

    private static function buildWhere(
        array $conditions,
        array &$params,
        int &$paramCounter,
        string $boolean = 'AND'
    ): string {
        $parts = [];

        foreach ($conditions as $key => $cond) {
            // Handle grouped conditions like ['OR' => [...]]
            if ($key === 'OR' || $key === 'AND') {
                if (!is_array($cond)) {
                    throw new InvalidArgumentException("The value for {$key} must be an array.");
                }
                $groupSql = static::buildWhere($cond, $params, $paramCounter, $key);
                if ($groupSql !== '') {
                    $parts[] = '(' . $groupSql . ')';
                }
                continue;
            }

            if (is_string($key)) {
                $col = $key;
                $op = '=';
                $val = $cond;
            } elseif (is_array($cond)) {
                $count = count($cond);
                if ($count === 2) {
                    [$col, $op] = $cond;
                    $val = null;
                } elseif ($count === 3) {
                    [$col, $op, $val] = $cond;
                } else {
                    throw new InvalidArgumentException(
                        'Each condition must be ["col", "op", value] or ["col" => value].'
                    );
                }
            } else {
                throw new InvalidArgumentException('Invalid condition format.');
            }

            $op = strtoupper(trim((string) $op));
            switch ($op) {
                case '=':
                case '!=':
                case '<':
                case '>':
                case '<=':
                case '>=':
                case 'LIKE': {
                    $paramName = ':p' . $paramCounter++;
                    $parts[] = "{$col} {$op} {$paramName}";
                    $params[ltrim($paramName, ':')] = $val;
                    break;
                }
                case 'IN': {
                    if (!is_array($val) || $val === []) {
                        $parts[] = '1=0';
                        break;
                    }
                    $phs = [];
                    foreach ($val as $v) {
                        $paramName = ':p' . $paramCounter++;
                        $phs[] = $paramName;
                        $params[ltrim($paramName, ':')] = $v;
                    }
                    $parts[] = "{$col} IN (" . implode(', ', $phs) . ')';
                    break;
                }
                case 'IS': {
                    if ($val === null) {
                        $parts[] = "{$col} IS NULL";
                        break;
                    }
                    $paramName = ':p' . $paramCounter++;
                    $parts[] = "{$col} IS {$paramName}";
                    $params[ltrim($paramName, ':')] = $val;
                    break;
                }
                case 'IS NULL':
                case 'IS NOT NULL': {
                    $parts[] = "{$col} {$op}";
                    break;
                }
                default:
                    throw new InvalidArgumentException("Unsupported operator: {$op}");
            }
        }

        return implode(" {$boolean} ", $parts);
    }
}
