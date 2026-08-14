<?php

/**
 * NQ-001-v3, item B fixture. Routed to by "gizmo" — the same routing rule
 * that, under the old cascade, used to suppress im_orders' incidental
 * "orders" mention in the same sentence. Deliberately unrelated to
 * im_orders/im_customers: nothing about FK expansion pulls this in or keeps
 * im_orders out, so whatever happens is `DatasetSeeder::seeds()`'s doing
 * alone.
 */
return [
    'name' => 'Products',
    'description' => 'Product catalog',
    'aliases' => ['products'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'im_products',
            'description' => 'Product catalog',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Product name', 'groupable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
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
