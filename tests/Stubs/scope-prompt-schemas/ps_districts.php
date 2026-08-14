<?php

/** The FK target ps_orders joins to — expected to stay in scope alongside it. */
return [
    'name' => 'Districts',
    'description' => 'Districts',
    'aliases' => ['districts'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ps_districts',
            'description' => 'Districts',
            'group_column' => 'ps_district_name',
            'columns' => [
                'ps_district_name' => ['type' => 'varchar', 'description' => 'District name', 'groupable' => true],
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
