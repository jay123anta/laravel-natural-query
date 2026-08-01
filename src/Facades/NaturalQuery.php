<?php

namespace Jayanta\NaturalQuery\Facades;

use Illuminate\Support\Facades\Facade;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;

/**
 * NaturalQuery Facade
 *
 * @method static array query(string $naturalLanguageQuery, ?string $schemeHint = null)
 * @method static array getSchemes()
 * @method static array healthCheck()
 *
 * @see \Jayanta\NaturalQuery\Engine\QueryOrchestrator
 */
class NaturalQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueryOrchestrator::class;
    }
}
