<?php

namespace PhamPhu232\QueryLogger;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use PhamPhu232\QueryLogger\Console\Commands\ImportQueryLogs;
use PhamPhu232\QueryLogger\Support\TransactionContext;
use PhamPhu232\QueryLogger\Support\Ulid;

class QueryLoggerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/query_logger.php', 'query_logger');

        $this->app->singleton(TransactionContext::class, function () {
            return new TransactionContext();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ImportQueryLogs::class]);
        }
    }

    public function boot()
    {
        $nowDate = date('Y/m/d');
        $queryLoggerConfig = config('query_logger');

        if (!$queryLoggerConfig['enabled']) {
            return;
        }

        $this->registerPublishing();

        try {
            if ($this->app->runningInConsole()) {
                $this->app->booted(function () use ($queryLoggerConfig) {
                    if (empty($queryLoggerConfig['cron_import_query_logs'])) {
                        return;
                    }

                    $schedule = $this->app->make(Schedule::class);
                    $schedule->command('query-logger:import-query-logs')
                        ->cron($queryLoggerConfig['cron_import_query_logs']);
                });
            }

            Event::listen(
                TransactionBeginning::class,
                function (TransactionBeginning $event) {
                    $conn = $event->connection->getName();
                    app(TransactionContext::class)->set($conn, Ulid::generate());
                }
            );

            Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($queryLoggerConfig, $nowDate) {
                if (
                    stripos(trim($query->sql), 'select') === 0
                    && !$queryLoggerConfig['log_select_queries']
                ) {
                    return '';
                }

                $options = $this->resolveRequestOptions();

                if (
                    $options['is_console'] === 1
                    && !empty($options['action'])
                    && $options['action'] === 'query-logger:import-query-logs'
                ) {
                    return '';
                }

                $excludedTables = array_flip(array_map('strtolower', array_merge(
                    $queryLoggerConfig['exclude_tables'],
                    [$queryLoggerConfig['table_name']]
                )));

                // Extract table names from SQL using regex
                preg_match_all(
                    '/\b(from|into|update|join|replace\s+into|delete\s+from)\s+([`"]?)([a-zA-Z0-9_]+)\2/i',
                    $query->sql,
                    $matches
                );
                $tablesInQuery = array_map('strtolower', $matches[3]);

                // If any excluded table is used in the query, skip it
                foreach ($tablesInQuery as $table) {
                    if (isset($excludedTables[$table])) {
                        return '';
                    }
                }

                $conn = $query->connection->getName();
                $transaction = app(TransactionContext::class)->get($conn);
                $transaction = $transaction ? $transaction : '';

                if ($transaction) {
                    $dir = "{$queryLoggerConfig['log_path']}/uncommitted/{$nowDate}/{$transaction}";
                } else {
                    $dir = "{$queryLoggerConfig['log_path']}/committed";
                }

                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                $ulid = Ulid::generate();

                $ext = $queryLoggerConfig['log_format'];
                $logId = empty($transaction) ? $ulid : $transaction;
                $suffix = empty($transaction) ? 'SID' : 'TRX';
                $filePath = "{$dir}/{$logId}_{$suffix}.{$ext}";

                $sql = Writable::format($query, $ulid, $transaction, $options);
                if (!empty($sql)) {
                    file_put_contents($filePath, $sql, FILE_APPEND);
                }
            });

            Event::listen(
                TransactionCommitted::class,
                function (TransactionCommitted $event) use ($queryLoggerConfig, $nowDate) {
                    $conn = $event->connection->getName();
                    $transaction = app(TransactionContext::class)->get($conn);
                    $transaction = $transaction ? $transaction : '';

                    if (!$transaction) {
                        return;
                    }

                    $from = "{$queryLoggerConfig['log_path']}/uncommitted/{$nowDate}/{$transaction}";
                    $to = "{$queryLoggerConfig['log_path']}/committed";

                    if (File::exists($from)) {
                        if (!is_dir($to)) {
                            mkdir($to, 0777, true);
                        }

                        foreach (File::files($from) as $file) {
                            File::move($file->getPathname(), "{$to}/{$file->getFilename()}");
                        }

                        File::deleteDirectory($from);
                    }

                    app(TransactionContext::class)->remove($conn);
                }
            );

            Event::listen(
                TransactionRolledBack::class,
                function (TransactionRolledBack $event) use ($queryLoggerConfig, $nowDate) {
                    $conn = $event->connection->getName();
                    $transaction = app(TransactionContext::class)->get($conn);
                    $transaction = $transaction ? $transaction : '';

                    if (!$queryLoggerConfig['keep_uncommitted_queries'] && !empty($transaction)) {
                        $from = "{$queryLoggerConfig['log_path']}/uncommitted/{$nowDate}/{$transaction}";
                        File::deleteDirectory($from);
                    }

                    app(TransactionContext::class)->remove($conn);
                }
            );
        } catch (\Exception $e) {
            report($e);
        }
    }

    protected function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/query_logger.php' => config_path('query_logger.php'),
                __DIR__ . '/database/migrations/create_query_logs_table.php.stub' => database_path(
                    'migrations/' . date('Y_m_d_His') . '_create_query_logs_table.php'
                ),
            ], 'query-logger');
        }
    }

    private function resolveRequestOptions()
    {
        if (app()->runningInConsole()) {
            return [
                'is_console' => 1,
                'action' => isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '',
                'user_id' => '0',
                'client_ip' => '127.0.0.1',
            ];
        }

        $route = request()->route();
        $action = $route ? (!empty($route->getName()) ? $route->getName() : $route->getActionName()) : null;

        return [
            'is_console' => 0,
            'action' => $action,
            'user_id' => optional(request()->user())->getAuthIdentifier(),
            'client_ip' => !empty(request()->getClientIp()) ? request()->getClientIp() : 'unknown',
        ];
    }
}
