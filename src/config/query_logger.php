<?php

return [
    'enabled' => true,

    'connection' => '',

    'cron_import_query_logs' => '*/5 * * * *',

    'exclude_tables' => [
        'jobs',
        'failed_jobs',
        'sessions',
    ],

    'hostname' => gethostname(),

    'log_select_queries' => false,

    'keep_uncommitted_queries' => false,

    'log_format' => env('QUERY_LOG_FORMAT', 'tsv'), // Support format: tsv, csv

    'log_path' => storage_path('logs/query_logs'),

    'table_name' => 'query_logs',
];
