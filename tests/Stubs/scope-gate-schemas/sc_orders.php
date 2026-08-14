<?php

/**
 * NQ-001-v2 scope-gate fixture. `required_filter` excludes cancelled rows —
 * the whole point of PromptScopeGateTest::a_question_wrongly_seeded_away_from_orders_is_refused_not_executed_unfiltered
 * is that a wrongly-scoped question must never reach this table at all, so the
 * filter below never gets the chance to be silently skipped.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sc_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [],
            'required_filter' => "status != 'cancelled'",
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
