<?php

/**
 * FK target of im_orders.customer_id. Present only so this registry has a
 * link somewhere, which is what makes `hasLinkedSchemas()` true and the
 * multi-dataset prompt path the one that actually renders — see
 * im_orders.php's docblock.
 */
return [
    'name' => 'Customers',
    'description' => 'Customers who placed orders',
    'aliases' => ['customers'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'im_customers',
            'description' => 'Customers',
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
