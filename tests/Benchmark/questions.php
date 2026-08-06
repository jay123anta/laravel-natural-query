<?php

/**
 * A question set with gold SQL, for measuring execution accuracy.
 *
 * Modelled on how Spider and BIRD grade text-to-SQL: you do not compare query
 * strings, because many different queries are correct. You run the generated
 * query and a hand-written reference against the same database and compare the
 * RESULT SETS. A query is right when its answer is right.
 *
 * Most of these need MORE THAN ONE TABLE, because that is what real questions
 * need. The measure, the label and the filter routinely live in three
 * different places: "revenue by region" is line_total in bm_order_items,
 * region name in bm_regions, and the path between them runs through
 * bm_orders and bm_customers — four tables for a question a person would call
 * simple. Every serious defect in this package has been found by a question of
 * that shape, and none by a single-table one.
 *
 * `hardness` follows Spider's component-count definition:
 *
 *   easy   — one table, at most one aggregate or filter
 *   medium — grouping, ordering, or a join
 *   hard   — several components at once: joins AND a grouping AND/OR a period
 *   extra  — needs SQL the intent contract cannot express at all:
 *            HAVING, DISTINCT, a ratio of aggregates, a window function
 *
 * `joins` records how many tables the gold answer touches, so the report can
 * show whether accuracy degrades with join depth.
 *
 * Gold SQL is written from the QUESTION, never from the package's output.
 * Where a question implies an order ("top 3"), `ordered` is set and the
 * comparison respects row order; otherwise results are compared as multisets.
 */

return [
    // ---------------------------------------------------------------- easy
    [
        'question' => 'how many customers are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_customers',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'what is the total revenue',
        'gold' => 'SELECT SUM(line_total) AS revenue FROM bm_order_items',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many support tickets are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_support_tickets',
        'hardness' => 'easy',
        'joins' => 1,
    ],

    // -------------------------------------------------------------- medium
    [
        'question' => 'revenue by product category',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_categories c ON c.id = p.category_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 3,
    ],
    [
        'question' => 'how many orders by status',
        'gold' => 'SELECT status, COUNT(*) AS n FROM bm_orders GROUP BY status',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'top 3 customers by revenue',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name ORDER BY revenue DESC LIMIT 3',
        'hardness' => 'medium',
        'joins' => 3,
        'ordered' => true,
    ],
    [
        'question' => 'how many support tickets per customer',
        'gold' => 'SELECT c.name, COUNT(*) AS n
                   FROM bm_support_tickets t
                   JOIN bm_customers c ON c.id = t.customer_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],

    // ---------------------------------------------------------------- hard
    [
        'question' => 'revenue by region',
        'gold' => 'SELECT r.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   JOIN bm_regions r ON r.id = c.region_id
                   GROUP BY r.name',
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'revenue by region in July 2026',
        'gold' => "SELECT r.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   JOIN bm_regions r ON r.id = c.region_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'
                   GROUP BY r.name",
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'total revenue in July 2026',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'",
        'hardness' => 'hard',
        'joins' => 2,
    ],
    [
        'question' => 'revenue by supplier',
        'gold' => 'SELECT s.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_suppliers s ON s.id = p.supplier_id
                   GROUP BY s.name',
        'hardness' => 'hard',
        'joins' => 3,
    ],

    // --------------------------------------------------------------- extra
    [
        'question' => 'revenue excluding cancelled orders',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.status <> 'cancelled'",
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'how many different products have been ordered',
        'gold' => 'SELECT COUNT(DISTINCT product_id) AS n FROM bm_order_items',
        'hardness' => 'extra',
        'joins' => 1,
    ],
    [
        'question' => 'which customers have opened more than 1 support ticket',
        'gold' => 'SELECT c.name FROM bm_support_tickets t
                   JOIN bm_customers c ON c.id = t.customer_id
                   GROUP BY c.name HAVING COUNT(*) > 1',
        'hardness' => 'extra',
        'joins' => 2,
    ],
];
