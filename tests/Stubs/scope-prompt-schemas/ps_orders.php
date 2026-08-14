<?php

/**
 * NQ-001-v2 fixture: carries all four degrade-prone features property 4 must
 * see survive scope filtering — required_filter, required_join,
 * select_override, and a computed metric — plus a foreign key to ps_districts
 * so joinsFor() has something to render.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ps_orders',
            'description' => 'Order lines',
            'group_column' => 'ps_district_name',
            'required_join' => 'INNER JOIN ps_districts d ON d.id = ps_orders.district_id',
            'select_override' => 'd.ps_district_name',
            'columns' => [
                'district_id' => ['type' => 'integer', 'description' => 'District Id'],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'district_id', 'references_table' => 'ps_districts', 'references_column' => 'id'],
            ],
            'required_filter' => "status != 'cancelled'",
        ],
    ],
    'computed_metrics' => [
        'net_revenue' => [
            'expression' => "SUM(CASE WHEN status != 'cancelled' THEN revenue ELSE 0 END)",
            'description' => 'Revenue excluding cancelled orders',
            'unit' => 'currency',
        ],
    ],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
