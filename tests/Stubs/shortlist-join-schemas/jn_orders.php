<?php

/**
 * Deliberately carries NO 'relationships' entry — only a hand-written
 * required_join. `hasLinkedSchemas()` already counts required_join as a
 * link (SchemaRegistry.php: "A hand-written join counts too"); the fix
 * makes relatedDatasets()/expand() agree, so a required_join-only neighbour
 * is pulled into scope exactly like a foreign-key one is.
 */
return [
    'name' => 'Joined Orders',
    'description' => 'Orders that only declare a hand-written join',
    'aliases' => ['joined-orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'jn_orders',
            'description' => 'Joined orders',
            'group_column' => 'jn_district_name',
            'required_join' => 'INNER JOIN jn_districts d ON d.id = jn_orders.district_id',
            'select_override' => 'd.jn_district_name',
            'columns' => [
                'district_id' => ['type' => 'integer', 'description' => 'District Id'],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
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
