<?php

return [
    'allowed_tables' => array_values(array_filter(array_map(
        static fn (string $table): string => trim($table),
        explode(',', (string) env('RELAY_ALLOWED_TABLES', ''))
    ))),
];
