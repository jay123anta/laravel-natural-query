<?php

namespace Jayanta\NaturalQuery\Tests\Unit;

use Jayanta\NaturalQuery\Engine\QueryOrchestrator;
use Jayanta\NaturalQuery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A database error has to say something, and must not say the query.
 *
 * Two requirements pull against each other. The message reaches a user, so it
 * cannot carry the SQL - that describes the shape of the database to whoever
 * asked. But it also has to be worth reading, or the user is left with
 * "something went wrong" and no way to act.
 *
 * Only PostgreSQL used to get through. The pattern looked for an `ERROR:`
 * token, which MySQL does not emit at all - so on the stack this package is
 * most often installed on, every fault collapsed to the generic sentence.
 *
 * That became load-bearing when a database error stopped being reworded into
 * "could not understand the query": this string is now what the user actually
 * sees, so a missing table has to read as a missing table.
 *
 * The strings below are real `QueryException::getMessage()` shapes, including
 * the "(Connection: …, SQL: …)" tail Laravel appends - which an earlier
 * version left in, echoing the whole statement back.
 */
class DatabaseErrorsSayWhatWentWrongTest extends TestCase
{
    public static function driverMessages(): array
    {
        return [
            'sqlite missing table' => [
                'SQLSTATE[HY000]: General error: 1 no such table: shop_ordrs '
                    . '(Connection: sqlite, SQL: select sum(revenue) from shop_ordrs)',
                'no such table: shop_ordrs',
            ],
            'mysql missing table' => [
                "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'app.shop_ordrs' "
                    . "doesn't exist (Connection: mysql, SQL: select sum(revenue) from shop_ordrs)",
                "Table 'app.shop_ordrs' doesn't exist",
            ],
            'mysql unknown column' => [
                "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'revenoo' in 'field list' "
                    . '(Connection: mysql, SQL: select revenoo from orders)',
                "Unknown column 'revenoo' in 'field list'",
            ],
            'postgres missing relation' => [
                'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "shop_ordrs" does not exist'
                    . "\nLINE 1: select sum(revenue) from shop_ordrs",
                'relation "shop_ordrs" does not exist',
            ],
        ];
    }

    #[DataProvider('driverMessages')]
    #[Test]
    public function the_cause_survives_on_every_supported_driver(string $raw, string $expected)
    {
        $this->assertSame($expected, $this->sanitize($raw));
    }

    #[DataProvider('driverMessages')]
    #[Test]
    public function the_statement_never_reaches_the_user(string $raw, string $expected)
    {
        // $expected is unused here on purpose: this case asserts what must be
        // ABSENT, and shares the provider with the case that asserts content.
        $clean = strtolower($this->sanitize($raw));

        foreach (['select', 'sql:', 'connection:'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $clean,
                "the sanitised message carries [{$leak}], so the query is being shown to whoever "
                    . 'asked the question'
            );
        }
    }

    /** Anything unrecognised falls back rather than passing the raw text through. */
    #[Test]
    public function an_unrecognised_message_is_not_forwarded_verbatim()
    {
        $this->assertSame(
            'An error occurred while querying the database.',
            $this->sanitize('some driver nobody planned for exploded, SQL: select secret from vault')
        );
    }

    private function sanitize(string $raw): string
    {
        $method = new \ReflectionMethod(QueryOrchestrator::class, 'sanitizeDbError');
        $method->setAccessible(true);

        return $method->invoke(
            (new \ReflectionClass(QueryOrchestrator::class))->newInstanceWithoutConstructor(),
            $raw
        );
    }
}
