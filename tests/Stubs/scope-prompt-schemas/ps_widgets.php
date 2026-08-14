<?php

/**
 * NQ-001-v2 fixture: out-of-scope for every test in PromptScopeFilteringTest.
 * No relationship to ps_orders/ps_districts. Its key, physical table name
 * (`ps_widgets_table`) and column name (`ps_widget_serial`) must appear
 * NOWHERE in a scope-filtered multi-dataset prompt — not in the schema
 * sections, not in a RELATED join line, not in routing hints, not in
 * corrections.
 */
return [
    'name' => 'Widgets',
    'description' => 'Widget inventory',
    'aliases' => ['widgets'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ps_widgets_table',
            'description' => 'Widget inventory',
            'group_column' => 'ps_widget_serial',
            'columns' => [
                'ps_widget_serial' => ['type' => 'varchar', 'description' => 'Widget serial number', 'groupable' => true],
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
