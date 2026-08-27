<?php

namespace Jayanta\NaturalQuery\Facades;

use Illuminate\Support\Facades\Facade;
use Jayanta\NaturalQuery\Engine\QueryOrchestrator;

/**
 * NaturalQuery Facade
 *
 * The signatures below are what an IDE completes against, so they are kept
 * exact -  a facade docblock that has drifted from the class is worse than none,
 * because it is believed.
 *
 * @method static array query(string $naturalLanguageQuery, ?string $datasetHint = null, array $context = [])
 * @method static array getDatasets()
 * @method static array getDatasetMetrics(string $datasetKey)
 * @method static array healthCheck()
 * @method static \Jayanta\NaturalQuery\Schema\SchemaRegistry registry()
 *
 * @see QueryOrchestrator
 */
class NaturalQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueryOrchestrator::class;
    }
}
