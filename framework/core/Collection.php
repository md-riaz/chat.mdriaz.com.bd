<?php

declare(strict_types=1);

namespace Framework\Core;

use ArrayIterator;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

class Collection implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var array<int|string, mixed> */
    protected array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return array_map(
            fn($item) => $item instanceof Model ? $item->toArray() : $item,
            $this->items
        );
    }
}
