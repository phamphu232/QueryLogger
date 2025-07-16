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
use Symfony\Component\Uid\Ulid;

class QueryLoggerServiceProvider extends ServiceProvider
{
    private $nowDate = '';

    private $queryLoggerConfig = '';

    private $arrTransaction = [];

    public function boot()
    {
        $this->nowDate = date('Y/m/d');

        try {
            if ($this->app->runningInConsole()) {
                $this->publishes([
                    __DIR__ . '/config/query_logger.php' => config_path('query_logger.php'),
                    __DIR__ . '/database/migrations/create_query_logs_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_query_logs_table.php'),
                ], 'query-logger');
            }

            $this->queryLoggerConfig = config('query_logger');

            if (!$this->queryLoggerConfig['is_enable_query_logger']) {
                return;
            }

            if ($this->app->runningInConsole()) {
                $this->app->booted(function () {
                    $schedule = $this->app->make(Schedule::class);
                    $schedule->command('query-logger:import-query-logs')
                        ->cron($this->queryLoggerConfig['cron_import_query_logs']);
                });

                $isConsole = 0;
                $action = !empty($_SERVER['argv'][1]) ? trim($_SERVER['argv'][1]) : '';
                $userId = '0';
                $clientIp = '127.0.0.1';
                if (strpos($action, 'query-logger:import-query-logs') === 0) {
                    return '';
                }
            } else {
                $action = request()->route()?->getName() ?? '';
                $isConsole = 1;
                $authUser = auth()->user();
                $userId = $authUser ? $authUser->{$authUser->getKeyName() ?? 'id'} : '-1';
                $clientIp = request()->ip();
            }

            $options = [
                'is_console' => $isConsole,
                'action' => $action,
                'user_id' => $userId,
                'client_ip' => $clientIp,
            ];

            Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) {
                $conn = $event->connection->getName();
                $this->arrTransaction[$conn] = Ulid::generate();
            });

            Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($options) {
                $conn = $query->connection->getName();
                $transaction = $this->arrTransaction[$conn] ?? '';

                if ($transaction) {
                    $dir = "{$this->queryLoggerConfig['log_path']}/uncommitted/{$this->nowDate}/{$transaction}";
                } else {
                    $dir = "{$this->queryLoggerConfig['log_path']}/committed";
                }

                File::ensureDirectoryExists($dir);
                $ulid = Ulid::generate();

                $ext = $this->queryLoggerConfig['log_format'];
                $filePath = empty($transaction)
                    ? "{$dir}/{$ulid}_SID.{$ext}"
                    : "{$dir}/{$transaction}_TRX.{$ext}";

                $sql = Writable::format($query, $ulid, $transaction, $options);
                if (!empty($sql)) {
                    File::append($filePath, $sql);
                }
            });

            Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) {
                $conn = $event->connection->getName();
                $transaction = $this->arrTransaction[$conn] ?? '';

                if (!$transaction) {
                    return;
                }

                $from = "{$this->queryLoggerConfig['log_path']}/uncommitted/{$this->nowDate}/{$transaction}";
                $to = "{$this->queryLoggerConfig['log_path']}/committed";

                if (File::exists($from)) {
                    File::ensureDirectoryExists($to);

                    foreach (File::files($from) as $file) {
                        File::move($file->getPathname(), "{$to}/{$file->getFilename()}");
                    }

                    File::deleteDirectory($from);
                }

                unset($this->arrTransaction[$conn]);
            });

            Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
                $conn = $event->connection->getName();
                $transaction = $this->arrTransaction[$conn] ?? '';

                if (!$this->queryLoggerConfig['is_save_uncommitted_query'] && !empty($transaction)) {
                    $from = "{$this->queryLoggerConfig['log_path']}/uncommitted/{$this->nowDate}/{$transaction}";
                    File::deleteDirectory($from);
                }

                unset($this->arrTransaction[$conn]);
            });
        } catch (\Exception $e) {
            report($e);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/query_logger.php', 'query_logger');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\ImportQueryLogs::class,
            ]);
        }
    }
}
