# Schema configuration

Schema files are the only thing that makes NaturalQuery understand your
data. `naturalquery:discover` writes one per table; everything below is what
you can put in them and why it matters.

## Schema Config Reference

Each schema file in `config/naturalquery-schemas/` tells the AI everything about one dataset.

### Columns

```php
'columns' => [
    'column_name' => [
        'type' => 'varchar|integer|decimal|date|boolean',
        'description' => 'What this column contains',
        'unit' => '$',                    // display unit
        'aliases' => ['revenue', 'sales'], // words users might say
        'filterable' => true,             // can be used in WHERE
        'groupable' => true,              // can be used in GROUP BY
        'aggregatable' => true,           // summed per group (see below)
        'sortable' => true,               // can be used in ORDER BY
    ],
],
```

**`aggregatable` drives aggregation.** For transactional tables (many rows
per group value - e.g. one row per order), mark measure columns
`'aggregatable' => true`: rankings and group detail views then build
`SUM(column) ... GROUP BY group_column` so each group appears once with its
total. For pre-aggregated tables (one row per group value - e.g. one row per
district with pre-computed totals), omit `aggregatable` (use `sortable`
alone) and rows are read as-is with no GROUP BY. Computed metrics whose
expression already aggregates (`SUM`/`COUNT`/`AVG`/`MIN`/`MAX`) are grouped
as-is and never double-wrapped.

### Computed Metrics

For calculated values that don't exist as columns:

```php
'computed_metrics' => [
    'completion_rate' => [
        'expression' => 'ROUND(completed * 100.0 / NULLIF(target, 0), 2)',
        'description' => 'Completion percentage',
        'unit' => '%',
        'aliases' => ['rate', 'percentage', 'progress'],
    ],
],
```

**Counting is built in.** Every dataset gets a `record_count` metric
(`COUNT(*)`) without declaring anything, so "how many orders by status" is
answered by counting rows rather than by falling back to whichever measure is
default and reporting revenue per status instead. Declare your own
`record_count` in `computed_metrics` to override it - counting distinct
customers is not counting rows:

```php
'computed_metrics' => [
    'record_count' => [
        'expression' => 'COUNT(DISTINCT customer_id)',
        'description' => 'Number of distinct customers',
        'unit' => 'customers',
    ],
],
```

Set `sql.implicit_count_metric` to `false` in the config to remove the built-in
entirely.

### Time periods

Declare which column a period applies to. A table often has several dates -
`order_date`, `shipped_at`, `created_at` - and they answer different questions,
so the choice is yours rather than a guess from the wording:

```php
'tables' => [
    'primary' => [
        'name' => 'orders',
        'date_column' => 'order_date',   // written for you by discover
        'columns' => [ ... ],
    ],
],
```

"Revenue last month", "orders in 2025", "sales since April" then narrow
properly. The model resolves the wording to actual dates (it is told today's
date, since it has no reliable idea what day it is); those dates are checked
against a strict `YYYY-MM-DD` pattern and passed as bound parameters. A period
that cannot be parsed, or one asked for on a dataset with no date column, is
refused - answering over all time instead would be a confidently wrong number.

### Beyond the intent contract

Intent mode expresses a deliberate subset of SQL: one measure, one breakdown,
one name filter, one period, an order and a limit. That covers most questions
and is safer than free-form SQL - deterministic, no invented column names.

Some questions need more, and the failure mode used to be silent. There is no
`HAVING` in that list, so "customers with more than 10 orders" became
"customers", ranked. No negation, so "excluding cancelled" was dropped and
cancelled orders counted toward the total.

In `auto` mode those questions now go straight to SQL generation, which can
express arbitrary (still validated, still SELECT-only) SQL:

| Wording | Needs | 
|---|---|
| "customers with more than 10 orders" | `HAVING` |
| "orders over 5000" | numeric `WHERE` |
| "revenue excluding cancelled" | negation |
| "how many different customers" | `DISTINCT` |
| "what percentage of orders were cancelled" | ratio of aggregates |
| "top 2 customers in each region" | window function |

This costs nothing - intent parsing and SQL generation are one API call each -
and `metadata.escalated_for` reports which of these triggered it. Set
`sql.escalate_beyond_intent` to `false` to disable. An explicit `query_mode`
of `intent` is always honoured as written.

### Breakdowns

Any column marked `groupable` can be the dimension of a question: "revenue by
region", "orders per status". The schema's `group_column` is only the default
used when the question does not name one. A breakdown by a column that is not
`groupable` is refused with the list of what is available, rather than being
quietly answered with the default grouping.

### JOINs

When your data table uses IDs instead of names:

```php
'tables' => [
    'primary' => [
        'name' => 'data_table',
        'group_column' => 'district_name',
        'required_join' => 'JOIN districts d ON d.id = data_table.district_id',
        'select_override' => 'd.district_name',
        'columns' => [...],
    ],
],
```

### Relationships

`required_join` above is unconditional - it is applied to every query for that
dataset. `relationships` is the opposite: a list of joins the model *may* use
when a question needs a column from another table.

```php
'tables' => [
    'primary' => [
        'name' => 'orders',
        'columns' => [...],
        'relationships' => [
            [
                'column' => 'customer_id',        // column on THIS table
                'references_table' => 'customers', // table it points at
                'references_column' => 'id',
                'constraint' => 'orders_customer_id_fkey', // optional
            ],
        ],
    ],
],
```

`php artisan naturalquery:discover` writes this for you from the real foreign
keys in your database, so most people never type it by hand.

**Composite keys** are several entries that share one `constraint` name. They
are rendered to the model as a single join with `AND`, because joining on half
a composite key silently matches rows it should not and inflates every total:

```php
'relationships' => [
    ['column' => 'tenant_id',   'references_table' => 'regions',
     'references_column' => 'tenant_id',   'constraint' => 'sales_region_fkey'],
    ['column' => 'region_code', 'references_table' => 'regions',
     'references_column' => 'region_code', 'constraint' => 'sales_region_fkey'],
],
```

**A join is only offered for a table that has its own schema file.** Generated
SQL is checked against a whitelist built from your schema files, so a join to a
table you have not described would be rejected. Rather than suggest a join that
cannot run, the package stays quiet about it. If a relationship you expected is
being ignored, the referenced table has no schema file yet:

```bash
php artisan naturalquery:discover --table=customers --merge
```

### LLM Instructions

The most important field for accuracy. Plain English instructions for the AI:

```php
'llm_instructions' => "
    Revenue means SUM(quantity * unit_price), never the 'amount' column -
    'amount' is stored in cents and excludes tax.

    Always exclude cancelled orders (status = 'cancelled') unless the
    question asks about cancellations.

    Customer names live in the customers table; join it rather than
    grouping by customer_id.
    Use ROUND(value::numeric, 2) for PostgreSQL decimals.
",
```

### Example Queries

More examples = fewer AI errors. Cover every query pattern:

```php
'example_queries' => [
    // Ranking
    ['natural' => 'Top 10 by revenue', 'sql' => 'SELECT ... ORDER BY revenue DESC LIMIT 10'],
    // Bottom/worst
    ['natural' => 'Worst 5 performers', 'sql' => 'SELECT ... ORDER BY rate ASC LIMIT 5'],
    // Single record
    ['natural' => 'Show details for Tokyo', 'sql' => "SELECT ... WHERE LOWER(name) = LOWER('Tokyo') LIMIT 1"],
    // Aggregation
    ['natural' => 'Total revenue', 'sql' => 'SELECT SUM(revenue) FROM ...'],
    // Computed metric
    ['natural' => 'Best completion rate', 'sql' => 'SELECT ..., ROUND(...) AS rate ... ORDER BY rate DESC'],
    // If JOIN needed - show it in EVERY example
    ['natural' => 'Districts by count', 'sql' => 'SELECT d.name, COUNT(*) FROM data JOIN districts d ON ... GROUP BY d.name'],
],
```

## Rules the schema cannot imply

Some things are true of your data and written down nowhere in it. The commonest
is that certain rows must never count:

```php
'tables' => [
    'primary' => [
        'name' => 'orders',
        'required_filter' => "status != 'cancelled'",
        // …
    ],
],
```

Nothing in a column's name, type or foreign keys says whether a cancelled order
belongs in a total. Only you know. Without the rule the model decides, and a
total that quietly includes cancelled orders looks exactly like a correct one —
no error, no warning, just a number that is too big.

**It is enforced, not suggested.** In `intent` mode `SqlBuilder` appends the
filter to the SQL, so it cannot be omitted. In `sql_generation` mode the filter
is put in the prompt *and* the returned SQL is checked for it — a query that
omits it is refused rather than executed. You will get an error saying which
filter was missing, which is the correct outcome: the alternative is a wrong
number reported as a success.

One consequence worth knowing. The check is literal — whitespace is collapsed
and `<>` is folded to `!=`, nothing more. A model that writes an equivalent
filter in different words (`status NOT IN ('cancelled')`) is refused even
though its SQL was right. That is a deliberate trade: a false refusal costs an
error on a good answer, while a looser check that merely looked for the column
name would pass `GROUP BY status` and hand back the unfiltered total.

Other uses of the same setting:

```php
// Time-series tables where only the latest snapshot is meaningful
'required_filter' => "as_of_date = (SELECT MAX(as_of_date) FROM stock_levels)",

// Soft deletes, if you are not using Eloquent's global scope here
'required_filter' => 'deleted_at IS NULL',

// Multi-tenant tables — but see the note below
'required_filter' => 'tenant_id = 42',
```

**Not a security boundary.** It is a correctness rule expressed in your schema
file, applied to questions this package answers. It is not row-level security
and does not protect anything from code that queries the database directly. For
tenancy, use a database user, a global scope, or a policy — and treat
`required_filter` as the thing that keeps *answers* right, not the thing that
keeps *data* private.

`php artisan naturalquery:audit-schema` flags a filterable column whose declared
values include things like `cancelled`, `void` or `deleted` when the table has
no `required_filter`, so you get asked the question rather than having to
remember it.

## Working with many tables

A realistic Laravel application has dozens of tables, and the first question
anyone asks spans several of them - the name is in `customers`, the money is in
`orders`. Here is what the package does on its own and where you come in.

**Discovery does the mechanical part.** One command reads every table, its
columns, and its real foreign keys, and writes one schema file per table:

```bash
php artisan naturalquery:discover
```

Foreign keys become [`relationships`](#relationships), so the model is told how
the tables connect rather than being left to guess that `customer_id` is a
number worth totalling. Re-run it after a migration with `--merge` to pick up
schema changes without discarding descriptions and aliases you have written.

**Every table goes into the prompt, and that is affordable.** A 40-table
database produces a prompt of roughly 4,200 tokens, against about 600 for a
single table. At current flagship prices that is a fraction of a cent per
question, and well inside every provider's context window. Tested on a 40-table
database, questions requiring a ten-table join chain were answered correctly
without any hand-written configuration.

**Cost is not the limit; ambiguity is.** And it is worth being precise about
which part you have to supply, because it is smaller than people expect.

Measured on a 14-table schema, asking the same 14 questions twice:

| | out of the box | with 3 sentences of config |
|---|---|---|
| 1-table questions | 5/5 | 5/5 |
| 2-table questions | 3/4 | 2/4 |
| 3-table questions | 2/3 | **3/3** |
| 4-table questions | 1/2 | **2/2** |
| overall | 79% | **86%** |

**You do not need to hand-write joins.** Discovery reads your real foreign keys
and the joins were right in every case above - a four-table path from order
lines through orders and customers to regions was found unaided.

What the package cannot know is which measure you mean. That schema had both
`order_items.line_total` and `payments.amount`; asked for revenue, the model
chose payments, so customers who had not paid vanished from the ranking and a
region came back at half its real revenue. The columns are identical in kind -
both decimal, both legitimately related to orders. Only the business knows.

Saying so took three sentences:

```php
// config/naturalquery.php
'system_instructions' => "
    Revenue means SUM(order_items.line_total). Never use payments.amount as
    revenue — payments record what has been collected, not what was sold.
    sessions, jobs and audit_log are infrastructure tables and never answer a
    business question.
",
```

That is the shape of the work: the package gives you a working base from your
own database, and you add the handful of things only you know on top.
`php artisan naturalquery:doctor` tells you when more than one dataset could
answer the same question, so you know when this is worth writing.

Three fields cover nearly everything else, in increasing order of effort:

| Field | Use it when |
|---|---|
| `aliases` on a column | Users say "revenue", the column is `total_amt` |
| `llm_instructions` | A rule the model cannot infer: "always exclude cancelled orders", "amounts are in cents" |
| `example_queries` | One correct SQL example of the join path you want preferred |

**Narrow the surface if you want to.** You do not have to expose everything:

```bash
php artisan naturalquery:discover --table=orders --table=customers
```

Only tables with a schema file are queryable - the generated SQL is validated
against a whitelist built from those files. Joins are only suggested between
tables you have described, so a partial install stays coherent rather than
producing SQL the validator then rejects.

**When an answer is wrong, correct it once.** Submitted corrections are stored
and fed back into later prompts as examples, so a join path you fix by hand
becomes the one the model reuses. See [Feedback / Training](API.md#feedback--training).
