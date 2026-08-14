<?php

/** The FK target of cm_orders.customer_id — pulled into scope by the
 *  one-hop expansion that is not in question for the defect-1 reproduction. */
return [
    'name' => 'Customers',
    'description' => 'Customers who placed orders',
    'aliases' => ['customers'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'cm_customers',
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
