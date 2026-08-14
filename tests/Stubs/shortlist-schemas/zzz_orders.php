<?php

/**
 * Sorts LAST alphabetically among this fixture set (property 1). Carries a
 * distinctive multi-word alias ("SL Warehouses") mirroring the exact defect
 * named in commit cc9b07b: a model answering with an alias, not the exact
 * key, must still resolve — SchemaShortlister::resolve() must call
 * registry->findByAlias() for a seed that fails registry->has(), not only
 * the exact-key check.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders', 'SL Warehouses'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'zzz_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer Id'],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'zzz_customers', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
