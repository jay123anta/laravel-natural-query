<?php

namespace Jayanta\NaturalQuery\Schema;

use Jayanta\NaturalQuery\Schema\Introspectors\MysqlIntrospector;
use Jayanta\NaturalQuery\Schema\Introspectors\PostgresIntrospector;
use Jayanta\NaturalQuery\Schema\Introspectors\SqliteIntrospector;

/**
 * Single source of truth for which database drivers can be introspected.
 *
 * Both the ServiceProvider (which builds the introspector) and
 * `naturalquery:doctor` (which reports an unsupported driver) read this, so the
 * two can never disagree about what is supported.
 *
 * The built-in map lives in CODE, not in the publishable config file, and that
 * is deliberate: Laravel's `mergeConfigFrom` is a shallow merge, so an app that
 * published config/naturalquery.php under an earlier version never receives
 * nested keys added later. If this map were config-only, upgrading would leave
 * those apps with zero supported drivers and a package that refuses every
 * query. Config can only ADD to or override the built-ins.
 */
class IntrospectorRegistry
{
    /** Drivers supported out of the box, mapped to their introspector. */
    public const BUILT_IN = [
        'pgsql' => PostgresIntrospector::class,
        'mysql' => MysqlIntrospector::class,
        'mariadb' => MysqlIntrospector::class,
        // Laravel 11+ creates new apps on SQLite, so this is the database most
        // people who try the package already have.
        'sqlite' => SqliteIntrospector::class,
    ];

    /**
     * Built-in drivers plus anything registered in
     * `naturalquery.sql.introspectors`. Config entries win on key conflicts.
     *
     * Mapping a driver to null removes it, so a built-in can be turned off as
     * well as replaced. Nulls are stripped here rather than in each caller, so
     * supports(), supportedDrivers() and classFor() cannot disagree about
     * whether a disabled driver exists.
     *
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        $configured = config('naturalquery.sql.introspectors') ?: [];

        $merged = array_merge(self::BUILT_IN, is_array($configured) ? $configured : []);

        return array_filter($merged, fn ($class) => is_string($class) && $class !== '');
    }

    /** @return string[] */
    public static function supportedDrivers(): array
    {
        return array_keys(self::map());
    }

    public static function supports(string $driver): bool
    {
        return isset(self::map()[$driver]);
    }

    public static function classFor(string $driver): ?string
    {
        return self::map()[$driver] ?? null;
    }
}
