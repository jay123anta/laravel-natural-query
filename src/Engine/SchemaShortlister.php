<?php

namespace Jayanta\NaturalQuery\Engine;

use Jayanta\NaturalQuery\Schema\SchemaRegistry;

/**
 * Decide which datasets a question needs full column detail for (NQ-001,
 * index-first prompting).
 *
 * Two-stage prompting: a compact index goes to the model first, it names the
 * dataset(s) it recognises, and THIS class expands those seeds over the
 * foreign-key graph mechanically — never by trusting the model to have named
 * every table the question touches. An 8B model naming only "customers" must
 * still get "orders" back, because orders.customer_id -> customers.id, and
 * that expansion happens here, locally, with no second API call.
 *
 * `shortlist()` is the provider-free half of this and the part that has to
 * be unit-testable without HTTP — it takes seeds, walks the FK graph via
 * `SchemaRegistry::expand()`, and returns a shortlist. It never calls a
 * provider and never guesses: an entirely hallucinated seed list falls OPEN
 * to every dataset (binding condition 5) rather than producing a
 * confidently empty, or confidently wrong, shortlist.
 *
 * The provider-calling stage — deciding whether a seeding call is even
 * needed, prompting the model for seeds, parsing its reply — is a SEPARATE
 * concern and lives in a different method so this one stays a pure function
 * of its input and the schema.
 */
class SchemaShortlister
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Expand a seed list of dataset keys into a shortlist, mechanically.
     *
     * @param array<int, string> $seedKeys dataset keys a model (or a
     *        routing rule, or a previous turn's state) named
     * @return array{
     *     keys: array<int, string>,
     *     omitted: array<int, string>,
     *     source: string,
     *     reason?: string
     * }
     */
    public function shortlist(array $seedKeys): array
    {
        $allKeys = $this->registry->keys();

        // A hallucinated table name is dropped here, not expanded — treating
        // it as a real seed with no neighbours would silently narrow the
        // shortlist on the strength of a name that does not exist.
        $validSeeds = array_values(array_unique(array_filter(
            $seedKeys,
            fn ($key) => $this->registry->has($key)
        )));

        if (empty($validSeeds)) {
            // Every named seed was fictional (or none were named at all).
            // Proceeding on zero real seeds would be a guess, and binding
            // condition 5 forbids that — the only safe shortlist here is
            // everyone, with the fall-open decision recorded rather than
            // silent.
            return [
                'keys' => $allKeys,
                'omitted' => [],
                'source' => 'all',
                'reason' => empty($seedKeys)
                    ? 'no seed datasets were supplied'
                    : 'none of the named datasets exist in this schema',
            ];
        }

        $hops = (int) config('naturalquery.prompts.shortlist_hops', 1);
        $expanded = $this->registry->expand($validSeeds, $hops);

        return [
            'keys' => $expanded,
            'omitted' => array_values(array_diff($allKeys, $expanded)),
            'source' => 'seeded',
        ];
    }
}
