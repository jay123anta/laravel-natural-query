<?php

namespace Jayanta\NaturalQuery\Tests\Support;

use Jayanta\NaturalQuery\Schema\Introspectors\MysqlIntrospector;

/**
 * Registers SQLite as an introspectable driver for tests only.
 *
 * The package genuinely does not support SQLite — `sql.introspectors` says so,
 * and `naturalquery:doctor` now reports it as a problem. But the test suite
 * runs on in-memory SQLite so it needs no database service, and most doctor
 * checks (config, tables, schemas, routes) are driver-independent.
 *
 * Mapping SQLite to this class in a test's environment is the honest way to
 * say "for this test, pretend the driver is introspectable" — and it exercises
 * the real extension point, so the `sql.introspectors` config is covered by
 * every test that relies on it.
 *
 * MySQL's information_schema queries work well enough for the column checks
 * these tests make; anything deeper belongs in a test against a real server.
 */
class SqliteTestIntrospector extends MysqlIntrospector
{
    public function getDialect(?string $connection = null): string
    {
        return 'sqlite';
    }

    public function getDriver(?string $connection = null): string
    {
        return 'sqlite';
    }
}
