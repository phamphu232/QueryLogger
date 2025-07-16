<?php

namespace PhamPhu232\QueryLogger\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportQueryLogs extends Command
{
    // Define the Artisan command signature and default path argument
    protected $signature = 'query-logger:import-query-logs {path=logs/query_logs/committed}';

    // Description for Artisan list
    protected $description = 'Import all .tsv log files into the query_logs table using LOAD DATA INFILE';

    public function handle()
    {
        $path = storage_path($this->argument('path'));

        // Validate if the given directory exists
        if (!is_dir($path)) {
            $this->error("❌ Directory does not exist: $path");

            return 1;
        }

        // Fetch all .tsv files in the directory
        $files = glob($path . '/*.tsv');

        if (empty($files)) {
            $this->info('✅ No .tsv files found to import.');

            return 0;
        }

        $connection = config('query_logger.connection');
        $queryLogsTable = config('query_logger.table_name');

        foreach ($files as $file) {
            $absolutePath = realpath($file);
            $this->info("📥 Importing: $absolutePath");

            try {
                // Use LOAD DATA INFILE to efficiently load TSV data into the query_logs table
                DB::connection($connection)->statement("
                    LOAD DATA LOCAL INFILE '{$absolutePath}'
                    INTO TABLE {$queryLogsTable}
                    CHARACTER SET utf8mb4
                    FIELDS TERMINATED BY '\t'
                    OPTIONALLY ENCLOSED BY '\"'
                    LINES TERMINATED BY '\n'
                    (`ulid`, `action`, `sql`, `duration`, `transaction`, `connection`, `user_id`, `is_console`, `hostname`, `client_ip`, `execute_at`)
                    SET created_at = NOW()
                ");

                $this->info("✔️  Successfully imported: $file");
                unlink($absolutePath);
            } catch (\Exception $e) {
                $this->error("❌ Failed to import $file: " . $e->getMessage());
            }
        }

        return 0;
    }
}
