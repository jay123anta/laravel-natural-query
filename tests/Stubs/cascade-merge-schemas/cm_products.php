<?php

/**
 * NQ-001-v3 defect-1 fixture. Deliberately carries NO relationship to
 * cm_orders/cm_customers in either direction, and no required_join either —
 * so nothing about SchemaShortlister's FK/join expansion can rescue this
 * dataset into scope. It can only get there by DatasetSeeder::seeds()
 * matching its own alias ('products') in the question text, which is
 * exactly the signal the winner-takes-all `?:` cascade suppresses once a
 * query_routing keyword ('revenue') has matched anything at all.
 */
return [
    'name' => 'Products',
    'description' => 'Product catalog',
    'aliases' => ['products'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'cm_products',
            'description' => 'Product catalog',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Product name', 'groupable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue brought in by this product', 'aggregatable' => true, 'sortable' => true],
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
