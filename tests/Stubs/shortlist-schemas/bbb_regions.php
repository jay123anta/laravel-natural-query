<?php

/** Sorts second alphabetically, unrelated to zzz_orders. */
return [
    'name' => 'Unrelated Regions',
    'description' => 'A dimension table with nothing to do with orders',
    'aliases' => ['unrelated-regions'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'bbb_regions',
            'description' => 'Unrelated regions',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Name', 'groupable' => true],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
