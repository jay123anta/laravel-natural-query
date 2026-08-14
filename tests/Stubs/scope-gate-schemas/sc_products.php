<?php

/**
 * NQ-001-v2 scope-gate fixture. Deliberately unrelated to sc_orders — no
 * foreign key either way — so scoping to this dataset can never legitimately
 * expand to include sc_orders.
 */
return [
    'name' => 'Products',
    'description' => 'Product catalog',
    'aliases' => ['products'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sc_products',
            'description' => 'Product catalog',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Product name', 'groupable' => true],
                'price' => ['type' => 'decimal', 'description' => 'Price', 'aggregatable' => true, 'sortable' => true],
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
