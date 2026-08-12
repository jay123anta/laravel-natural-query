<?php

/** Unrelated to every other dataset in this fixture — no FK in, no FK out. */
return [
    'name' => 'SL Warehouses',
    'description' => 'Warehouses, unrelated to every other dataset in this fixture',
    'aliases' => ['sl warehouses'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sl_warehouses',
            'description' => 'Warehouses',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Warehouse name', 'filterable' => true, 'groupable' => true],
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
