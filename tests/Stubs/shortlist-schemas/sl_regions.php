<?php

/** Unrelated to every other dataset in this fixture — no FK in, no FK out. */
return [
    'name' => 'SL Regions',
    'description' => 'Sales regions, unrelated to every other dataset in this fixture',
    'aliases' => ['sl regions'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sl_regions',
            'description' => 'Sales regions',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Region name', 'filterable' => true, 'groupable' => true],
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
