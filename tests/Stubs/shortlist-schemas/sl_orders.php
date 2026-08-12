<?php

/** Points at sl_customers. The only dataset that must appear when the seed is "sl_customers" alone. */
return [
    'name' => 'SL Orders',
    'description' => 'Orders, for shortlist expansion tests',
    'aliases' => ['sl orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sl_orders',
            'description' => 'Orders',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer Id'],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'sl_customers', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
