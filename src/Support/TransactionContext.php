<?php

namespace PhamPhu232\QueryLogger\Support;

class TransactionContext
{
    protected $map = [];

    public function set($connection, $id)
    {
        $this->map[$connection] = $id;
    }

    public function get($connection)
    {
        return isset($this->map[$connection]) ? $this->map[$connection] : null;
    }

    public function remove($connection)
    {
        unset($this->map[$connection]);
    }

    public function all()
    {
        return $this->map;
    }
}
