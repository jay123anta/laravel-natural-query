<?php

/**
 * NQ-001-v3 defect-1 fixture ("winner-takes-all cascade").
 *
 * FK-linked to cm_customers (customer_id), so a `cm_orders` seed pulls
 * cm_customers into scope via SchemaShortlister's one-hop expansion — this
 * is the part of the reproduction that is NOT in question. cm_products is
 * deliberately NOT linked here (see cm_products.php): the whole defect is
 * that a question naming "products" by alias must seed cm_products on its
 * own, because no FK expansion from cm_orders will ever reach it.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'cm_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer Id'],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'cm_customers', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
