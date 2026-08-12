<?php

/**
 * Two foreign keys: one to sl_orders (a real, whitelisted dataset) and one to
 * sl_products, which deliberately has NO schema file in this fixture. Mirrors
 * `discover --table=...` leaving a foreign key pointed at an undiscovered
 * table — expansion must never offer that join.
 */
return [
    'name' => 'SL Order Items',
    'description' => 'Order line items, for shortlist expansion tests',
    'aliases' => ['sl order items'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sl_order_items',
            'description' => 'Order line items',
            'group_column' => 'sku',
            'columns' => [
                'order_id' => ['type' => 'integer', 'description' => 'Order Id'],
                'product_id' => ['type' => 'integer', 'description' => 'Product Id'],
                'sku' => ['type' => 'varchar', 'description' => 'SKU', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'order_id', 'references_table' => 'sl_orders', 'references_column' => 'id'],
                ['column' => 'product_id', 'references_table' => 'sl_products', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
