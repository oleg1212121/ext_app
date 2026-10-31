<?php

namespace App\Classes\Enrichment;

use Illuminate\Support\Carbon;

/**
 * The completion-stamp contract shared by the entity-level stamps
 * (entities.enrichment_stamps) and the per-sentence stamps
 * (entity_sentences.enrichment_stamps): one map entry per enricher key,
 * `{key: {v, pv, at}}` — `v` the Laravel-side algorithm version that ran,
 * `pv` the python-reported ALGORITHM_VERSION (ADR 0067), `at` the completion
 * time. The staleness predicate is the same on both levels; only the change
 * reference differs (the entity's grouped max(updated_at) vs the sentence's
 * own updated_at).
 */
final class Stamp
{
    /**
     * The stamp written for one enricher after a successful run. A python
     * version the run did not report falls back to the enricher's declared
     * python version. Pass $at to stamp a batch with one shared completion
     * time (identical stamp json batches into one write).
     */
    public static function build(Enricher $enricher, ?int $reportedPythonVersion = null, ?string $at = null): array
    {
        return [
            'v' => $enricher->version(),
            'pv' => $reportedPythonVersion ?? $enricher->pythonVersion(),
            'at' => $at ?? now()->toISOString(),
        ];
    }

    /**
     * Encode a stamp map for a jsonb write. An empty PHP array would encode
     * as the jsonb LIST [], and Postgres concatenating an object with an
     * array wraps the result into [{}] — the empty stamp map is the
     * OBJECT {}.
     *
     * @param  array<string, mixed>  $stamps
     */
    public static function encode(array $stamps): string
    {
        return $stamps === [] ? '{}' : json_encode($stamps, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The staleness predicate for one stamp against one enricher: a missing
     * stamp, an older algorithm version on either side (a bump on either
     * side re-stales — ADR 0059, ADR 0067), or a content change after the
     * stamp make it stale.
     *
     * Legacy tolerances: v1-era stamps were bare ISO strings (read as
     * version 1) and pre-parity stamps carry no `pv` (read as 0, so every
     * pre-parity stamp is stale exactly once).
     *
     * @param  mixed  $stamp  the decoded stamp map entry (array, bare ISO
     *                        string, or null)
     * @param  string|null  $lastChange  the raw last-change datetime of the
     *                                   thing the stamp covers — a sentence's
     *                                   updated_at or the entity's grouped
     *                                   max(updated_at) aggregate
     */
    public static function stale(Enricher $enricher, mixed $stamp, ?string $lastChange): bool
    {
        if ($stamp === null) {
            return true;
        }

        // v1 stamps were bare ISO strings; the array shape carries
        // the algorithm version next to the completion time.
        $enrichedAt = is_array($stamp) ? ($stamp['at'] ?? null) : $stamp;

        if ($enrichedAt === null) {
            return true;
        }

        if ((int) (is_array($stamp) ? ($stamp['v'] ?? 1) : 1) < $enricher->version()) {
            return true;
        }

        // The python-side algorithm version the stamp was written
        // with (ADR 0067). Legacy stamps carry no pv — read as 0, so
        // every pre-parity stamp is stale exactly once and the sweep
        // rewrites it in the versioned shape.
        $pythonVersion = (int) (is_array($stamp) ? ($stamp['pv'] ?? 0) : 0);

        if ($pythonVersion < $enricher->pythonVersion()) {
            return true;
        }

        return $lastChange !== null
            && Carbon::parse($lastChange)->gt(Carbon::parse($enrichedAt));
    }
}
