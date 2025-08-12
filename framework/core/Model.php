<?php

declare(strict_types=1);

namespace Framework\Core;

use InvalidArgumentException;
use RuntimeException;

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

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

    protected static function db(): Database
    {
        return DBManager::getDB();
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
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
        return $this->attributes;
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
     * Build a WHERE clause and parameters from a simple conditions array.
     *
     * Supported forms:
     *  - ['col' => $value]
     *  - ['col', 'OP', $value] where OP ∈ (=, !=, <, >, <=, >=, LIKE, IN)
     * For IN, value must be an array.
     *
     * @return array{0:string,1:array} [whereSql, params]
     */
    protected static function compileWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        $paramCounter = 0;

        // Normalize associative ['col' => val] into triplets
        $normalized = [];
        foreach ($conditions as $key => $cond) {
            if (is_string($key)) {
                $normalized[] = [$key, '=', $cond];
            } else {
                // Expect [col, op, val]
                if (!is_array($cond) || count($cond) !== 3) {
                    throw new InvalidArgumentException(
                        'Each condition must be ["col", "op", value] or ["col" => value].'
                    );
                }
                $normalized[] = $cond;
            }
        }

        foreach ($normalized as [$col, $op, $val]) {
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
                        // Empty IN lists are always false; return no rows safely
                        $parts[] = '1=0';
                        break;
                    }
                    $phs = [];
                    foreach ($val as $v) {
                        $paramName = ':p' . $paramCounter++;
                        $phs[] = $paramName;
                        $params[ltrim($paramName, ':')] = $v;
                    }
                    $inList = implode(', ', $phs);
                    $parts[] = "{$col} IN ({$inList})";
                    break;
                }
                default:
                    throw new InvalidArgumentException("Unsupported operator: {$op}");
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $parts);
        return [$whereSql, $params];
    }
}
