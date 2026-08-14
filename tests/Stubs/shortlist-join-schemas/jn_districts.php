<?php

/** Named only inside jn_orders' required_join text — no 'relationships' entry
 *  anywhere points at it. */
return [
    'name' => 'Joined Districts',
    'description' => 'Districts named only in a hand-written join',
    'aliases' => ['joined-districts'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'jn_districts',
            'description' => 'Joined districts',
            'group_column' => 'jn_district_name',
            'columns' => [
                'jn_district_name' => ['type' => 'varchar', 'description' => 'District name', 'groupable' => true],
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
