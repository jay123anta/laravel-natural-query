<?php

/**
 * NQ-001-v3, item B fixture ("incidental alias mention"). FK-linked to
 * im_customers so `hasLinkedSchemas()` is true for this registry — the
 * multi-dataset prompt path (`buildMultiDatasetPrompt()`) is what renders
 * prompts here, not the single-dataset auto-mode shortcut that a
 * zero-relationship registry (like scope-gate-schemas) always takes
 * regardless of `$scope`. That is what lets this fixture show a genuinely
 * WIDENED scope's dataset in full, required_filter included.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'im_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer Id'],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'im_customers', 'references_column' => 'id'],
            ],
            'required_filter' => "status != 'cancelled'",
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
