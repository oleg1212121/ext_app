<?php

namespace App\Classes;

use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The one writer of new Entity matches. Every creation surface — the
 * user-facing Library Alignments form and the Filament consoles — resolves
 * its two entities, calls create(), and translates the outcome into its own
 * UX; the creation rules live only here, so the surfaces cannot diverge.
 *
 * Owned rules:
 *  - Same-Work validation: a cross-Work pair throws CrossWorkEntityPair.
 *  - Canonical sides (ADR 0019): the lower entity id is the A-side, whatever
 *    order the caller passes, so the unique(a_entity_id, b_entity_id)
 *    constraint covers both orders.
 *  - Duplicates are rejected everywhere, never deleted: a hit on the
 *    canonical pair returns the duplicate outcome carrying the existing
 *    match. Creation never deletes a match, meaning match or junction row —
 *    deleting a match stays an explicit operator action. A unique-constraint
 *    loss on the insert (a concurrent creation by a different user) is
 *    converted to the same outcome by re-running the lookup.
 *  - Processing limits (ADR 0044): creation runs under the creator's locked
 *    user row with the alignment slot asserted inside; admins are exempt
 *    (that is ProcessingLimits' own rule). ProcessingLimitReached propagates
 *    to the caller.
 *  - Alignment copy reuse (ADR 0033): the pending row is created first
 *    (AlignmentCopyService::copyFor mutates it in place); when an exact-copy
 *    pair has a completed alignment available the match ends completed and
 *    no pipeline job is dispatched. Only otherwise does the pipeline run.
 *
 * Not owned here: readable-access checks (entry surfaces), entity resolution
 * from HTTP input (callers pass resolved entities), deletion of anything.
 */
class EntityMatchCreationService
{
    /**
     * The knob defaults, matching the entity_matches column defaults —
     * owned here so surfaces that do not care get sane values.
     */
    private const DEFAULT_CHUNK_SIZE = 75;

    private const DEFAULT_MAX_N = 6;

    public function __construct(
        private readonly AlignmentCopyService $alignmentCopy = new AlignmentCopyService,
        private readonly ProcessingLimits $limits = new ProcessingLimits,
    ) {}

    /**
     * Open an Entity match between two resolved entities of one Work.
     *
     * @param  int|null  $chunkSize  null uses the module default (75)
     * @param  int|null  $maxN  null uses the module default (6)
     * @return array{status: 'created'|'created_from_copy'|'duplicate', match: ?EntityMatch, existing: ?EntityMatch}
     *                                                                                                               - created: the new match, pending-born, creator recorded; the
     *                                                                                                               pipeline was dispatched after the creation transaction committed
     *                                                                                                               (its synchronous preamble leaves the row aligning);
     *                                                                                                               - created_from_copy: the new match, completed by cloning a
     *                                                                                                               completed alignment between exact copies of both sides (ADR 0033);
     *                                                                                                               - duplicate: nothing was created or deleted; existing carries the
     *                                                                                                               match the canonical pair already has.
     *
     * @throws CrossWorkEntityPair
     * @throws ProcessingLimitReached
     */
    public function create(
        User $creator,
        Entity $first,
        Entity $second,
        ?int $chunkSize = null,
        ?int $maxN = null,
    ): array {
        if ((string) $first->work_id !== (string) $second->work_id) {
            throw new CrossWorkEntityPair('Both entities must belong to the same work.');
        }

        // Canonical pair order (ADR 0019): the lower entity id is always the
        // a side, whichever order the caller passes.
        [$aEntity, $bEntity] = $first->id < $second->id
            ? [$first, $second]
            : [$second, $first];

        $existing = $this->findExisting($aEntity->id, $bEntity->id);

        if ($existing !== null) {
            return ['status' => 'duplicate', 'match' => null, 'existing' => $existing];
        }

        try {
            // Under the creator's lock so two parallel submissions by the
            // same user cannot both pass the alignment slot (ADR 0044).
            $match = $this->limits->underCreatorLock(
                $creator,
                fn (): EntityMatch => $this->createUnderCreatorLock($creator, $aEntity, $bEntity, $chunkSize, $maxN),
            );
        } catch (UniqueConstraintViolationException $exception) {
            // The creator lock serializes one user's submissions; two
            // different users can still race the same pair to the insert.
            // The constraint is the duplicate rule's backstop: surface the
            // winner instead of erroring.
            $existing = $this->findExisting($aEntity->id, $bEntity->id);

            if ($existing === null) {
                throw $exception;
            }

            return ['status' => 'duplicate', 'match' => null, 'existing' => $existing];
        }

        // An exact-copy pair reuses a completed alignment instead of running
        // the (potentially half-hour) pipeline; copyFor mutates the pending
        // row in place, so the row must exist first (ADR 0033).
        if ($this->alignmentCopy->copyFor($match)) {
            return ['status' => 'created_from_copy', 'match' => $match, 'existing' => null];
        }

        // Post-commit dispatch, like EntityCreationService: beginFromScratch
        // runs the pipeline's synchronous preamble (pair verification,
        // snapshot, transition to aligning) before queueing the first chunk
        // job, so it must never run inside the creation transaction.
        AlignEntitySentences::beginFromScratch($match->id);

        return ['status' => 'created', 'match' => $match->refresh(), 'existing' => null];
    }

    /**
     * The duplicate lookup, on the canonical pair. Any existing row counts —
     * status is irrelevant; a pending or aligning pair is as much a duplicate
     * as a completed one.
     */
    private function findExisting(int $aEntityId, int $bEntityId): ?EntityMatch
    {
        return EntityMatch::query()
            ->where('a_entity_id', $aEntityId)
            ->where('b_entity_id', $bEntityId)
            ->first();
    }

    /**
     * Runs inside the creator lock: assert the alignment slot, then insert
     * the pending row with the creator as its Alignment owner.
     */
    private function createUnderCreatorLock(
        User $creator,
        Entity $aEntity,
        Entity $bEntity,
        ?int $chunkSize,
        ?int $maxN,
    ): EntityMatch {
        $this->limits->assertAlignmentSlot($creator);

        return EntityMatch::query()->create([
            'a_entity_id' => $aEntity->id,
            'b_entity_id' => $bEntity->id,
            'chunk_size' => $chunkSize ?? self::DEFAULT_CHUNK_SIZE,
            'max_n' => $maxN ?? self::DEFAULT_MAX_N,
            'status' => 'pending',
            'created_by' => $creator->id,
        ]);
    }
}
