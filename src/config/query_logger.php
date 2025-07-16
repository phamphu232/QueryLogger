<?php

return [
    'connection' => '',

    'cron_import_query_logs' => '*/5 * * * *',

    'exclude_tables' => [
        'jobs',
        'failed_jobs',
        'sessions',
    ],

    'hostname' => gethostname(),

    'is_enable_query_logger' => true,

    'is_log_select_query' => false,

    'is_save_uncommitted_query' => false,

    'log_format' => env('QUERY_LOG_FORMAT', 'tsv'), // Support format: tsv, csv

    'log_path' => storage_path('logs/query_logs'),

    'table_name' => 'query_logs',
];
