<?php

/**
 * Leaf table: nothing references TO this table, but sl_orders references it.
 * Used to pin that expansion follows a foreign key in reverse — a seed of
 * just "customers" must still pull in "orders", because customers itself
 * carries no relationship the expander could walk forward.
 */
return [
    'name' => 'SL Customers',
    'description' => 'Customers, for shortlist expansion tests',
    'aliases' => ['sl customers'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sl_customers',
            'description' => 'Customers',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Customer name', 'filterable' => true, 'groupable' => true],
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
