<?php

/**
 * A question set with gold SQL, for measuring execution accuracy.
 *
 * Modelled on how Spider and BIRD grade text-to-SQL: you do not compare query
 * strings, because many different queries are correct. You run the generated
 * query and a hand-written reference against the same database and compare the
 * RESULT SETS. A query is right when its answer is right.
 *
 * `hardness` follows Spider's component-count definition, so the breakdown is
 * readable against published work:
 *
 *   easy   — one table, at most one aggregate or filter
 *   medium — grouping, ordering, or a join
 *   hard   — several components at once: a period, a join AND a grouping
 *   extra  — needs SQL the intent contract cannot express at all:
 *            HAVING, DISTINCT, a ratio of aggregates, a window function
 *
 * The gold SQL is written from the QUESTION, never from the package's output.
 * Where a question implies an order ("top 3"), `ordered` is set and the
 * comparison respects row order; otherwise results are compared as multisets,
 * which is what execution accuracy grades.
 */

return [
    // ---------------------------------------------------------------- easy
    [
        'question' => 'how many customers are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_customers',
        'hardness' => 'easy',
    ],
    [
        'question' => 'what is the total revenue',
        'gold' => 'SELECT SUM(line_total) AS revenue FROM bm_order_items',
        'hardness' => 'easy',
    ],
    [
        'question' => 'how many orders are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_orders',
        'hardness' => 'easy',
    ],

    // -------------------------------------------------------------- medium
    [
        'question' => 'revenue by region',
        'gold' => 'SELECT c.region, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.region',
        'hardness' => 'medium',
    ],
    [
        'question' => 'how many orders by status',
        'gold' => 'SELECT status, COUNT(*) AS n FROM bm_orders GROUP BY status',
        'hardness' => 'medium',
    ],
    [
        'question' => 'top 3 customers by revenue',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name ORDER BY revenue DESC LIMIT 3',
        'hardness' => 'medium',
        'ordered' => true,
    ],
    [
        'question' => 'revenue by product category',
        'gold' => 'SELECT p.category, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   GROUP BY p.category',
        'hardness' => 'medium',
    ],

    // ---------------------------------------------------------------- hard
    [
        'question' => 'total revenue in July 2026',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'",
        'hardness' => 'hard',
    ],
    [
        'question' => 'revenue by region in July 2026',
        'gold' => "SELECT c.region, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'
                   GROUP BY c.region",
        'hardness' => 'hard',
    ],
    [
        'question' => 'how many orders were placed in July 2026',
        'gold' => "SELECT COUNT(*) AS n FROM bm_orders
                   WHERE order_date >= '2026-07-01' AND order_date <= '2026-07-31'",
        'hardness' => 'hard',
    ],

    // --------------------------------------------------------------- extra
    [
        'question' => 'revenue excluding cancelled orders',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.status <> 'cancelled'",
        'hardness' => 'extra',
    ],
    [
        'question' => 'how many different products have been ordered',
        'gold' => 'SELECT COUNT(DISTINCT product_id) AS n FROM bm_order_items',
        'hardness' => 'extra',
    ],
    [
        'question' => 'which customers have placed more than 2 orders',
        'gold' => 'SELECT c.name FROM bm_orders o
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name HAVING COUNT(*) > 2',
        'hardness' => 'extra',
    ],
];
