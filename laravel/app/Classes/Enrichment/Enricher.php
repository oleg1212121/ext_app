<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;

/**
 * One enrichment analysis with declared language applicability (ADR 0057):
 * the registry decides which enrichers an entity's language gets, so adding
 * an algorithm (e.g. an English-only analysis) is one class plus one
 * registry entry — never an edit to a shared language conditional.
 *
 * Everything an enricher contributes rides a single python /enrich call the
 * service makes per sentence chunk: token hints and request extras go into
 * the request, and the python output comes back keyed by key().
 */
interface Enricher
{
    /**
     * The stable identifier of this analysis — the python dispatch key and
     * the entities.enrichment_stamps map key.
     */
    public function key(): string;

    /**
     * The analysis algorithm version. The completion stamp records it, and
     * a bump makes every already-enriched entity stale again — the
     * five-minute sweep re-runs the analysis over the corpus without any
     * manual backfill (ADR 0059).
     */
    public function version(): int;

    /**
     * The ISO language codes this enricher applies to ("en" for phrasal
     * verbs, "ru" for Silero stress — Russian sentences never run the
     * English analyses and vice versa).
     *
     * @return list<string>
     */
    public function languages(): array;

    /**
     * The entity_sentences column this enricher's result persists into.
     */
    public function column(): string;

    /**
     * Per-token dictionary hints contributed to the python payload, keyed by
     * the chunk's lookup keys; each entry maps token payload field names
     * (ipa, parts, stressed, ...) to values.
     *
     * @param  list<string>  $keys
     * @param  array<string, array{cls: ?string, lemma: ?string, headword: ?string, word_id: ?int}>  $resolved
     *                                                                                                          the service's shared base resolution per key: cls/lemma from the
     *                                                                                                          linked-or-best dictionary word, headword from the direct dictionary
     *                                                                                                          match, word_id for satellite lookups
     * @return array<string, array<string, mixed>>
     */
    public function tokenHints(Entity $entity, array $keys, array $resolved): array;

    /**
     * Request-level extras merged into the python payload (e.g. the phrasal
     * lexicon).
     *
     * @return array<string, mixed>
     */
    public function requestExtras(Entity $entity): array;

    /**
     * Convert the python output returned under key() into the value written
     * into column(); null clears the column.
     */
    public function toStorage(mixed $output): mixed;
}
