<?php

/** The FK target of zzz_orders.customer_id — must be pulled into scope by a
 *  single-hop FK expansion from a zzz_orders seed. */
return [
    'name' => 'Order Customers',
    'description' => 'Customers who placed zzz_orders',
    'aliases' => ['order-customers'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'zzz_customers',
            'description' => 'Order customers',
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
