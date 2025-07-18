<?php

namespace PhamPhu232\QueryLogger;

use Illuminate\Database\Events\QueryExecuted;

class Writable
{
    public static function format(QueryExecuted $query, $ulid, $transaction, $options)
    {
        $queryLoggerConfig = config('query_logger');

        $micro = microtime(true);
        $time = floor($micro);
        $datetime = date('Y-m-d H:i:s', $time);
        $milliseconds = sprintf('%03d', ($micro - $time) * 1000);

        $record = [
            'ulid' => $ulid,
            'action' => $options['action'],
            'sql' => self::interpolate($query->sql, $query->bindings),
            'duration' => $query->time,
            'transaction' => $transaction,
            'connection' => $query->connection->getName(),
            'user_id' => $options['user_id'],
            'is_console' => $options['is_console'],
            'hostname' => $queryLoggerConfig['hostname'],
            'client_ip' => $options['client_ip'],
            'execute_at' => "{$datetime}.$milliseconds",
        ];

        switch ($queryLoggerConfig['log_format']) {
            case 'csv':
                $content = self::toDelimited([$record], ',');
                break;

            case 'tsv':
            default:
                $content = self::toDelimited([$record], "\t");
                break;
        }

        return $content;
    }

    public static function interpolate($sql, $bindings)
    {
        foreach ($bindings as $binding) {
            // Convert binding to its SQL-compatible string representation
            if (is_null($binding)) {
                $value = 'null';
            } elseif (is_bool($binding)) {
                $value = $binding ? '1' : '0';
            } elseif ($binding instanceof \DateTimeInterface) {
                $value = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } elseif (is_numeric($binding)) {
                $value = $binding;
            } else {
                // Escape single quotes for SQL syntax
                $value = "'" . str_replace("'", "''", $binding) . "'";
            }

            // Replace only the first occurrence of the placeholder `?`
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, $value, $pos, 1);
            }
        }

        return $sql;
    }

    protected static function toDelimited(array $records, string $delimiter): string
    {
        if (empty($records)) {
            return '';
        }

        // $header = array_keys($records[0]);
        // $rows = [$header];

        foreach ($records as $record) {
            $rows[] = array_map(function ($v) use ($delimiter) {
                $v = str_replace(["\r", "\n"], ['\\r', '\\n'], $v);

                return str_replace($delimiter, ' ', $v);
            }, array_values($record));
        }

        $lines = array_map(function ($row) use ($delimiter) {
            return implode($delimiter, $row);
        }, $rows);

        return implode("\n", $lines) . "\n";
    }
}
