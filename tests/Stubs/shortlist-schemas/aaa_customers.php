<?php

/** Sorts FIRST alphabetically, unrelated to zzz_orders — must never be pulled
 *  into a scope seeded from zzz_orders. */
return [
    'name' => 'Unrelated Customers',
    'description' => 'A dimension table with nothing to do with orders',
    'aliases' => ['unrelated-customers'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'aaa_customers',
            'description' => 'Unrelated customers',
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
