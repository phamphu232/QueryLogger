<?php

namespace PhamPhu232\QueryLogger\Support;

class TransactionContext
{
    protected $map = [];

    public function set(string $connection, string $id)
    {
        $this->map[$connection] = $id;
    }

    public function get(string $connection): ?string
    {
        return $this->map[$connection] ?? null;
    }

    public function remove(string $connection): void
    {
        unset($this->map[$connection]);
    }

    public function all(): array
    {
        return $this->map;
    }
}
