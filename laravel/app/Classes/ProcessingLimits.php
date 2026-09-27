<?php

namespace App\Classes;

use App\Exceptions\ProcessingLimitReached;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-user caps on concurrently processing work (ADR 0044): an approved user
 * may hold at most `limits.entities_processing_per_user` entities mid-pipeline
 * and `limits.alignments_processing_per_user` alignments pending/aligning.
 * Approved admins are exempt, matching the EntityAccessService bypass.
 */
class ProcessingLimits
{
    /**
     * Run a creation under the creator's locked user row, so two parallel
     * submissions by the same user cannot both pass the in-flight count.
     * The callback asserts its own slot (some creations — e.g. a no-file
     * entity — need none).
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    public function underCreatorLock(User $user, callable $create): mixed
    {
        return DB::transaction(function () use ($user, $create): mixed {
            // Serializing on the creator's row makes the count-then-create
            // inside $create safe against concurrent submissions.
            User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $create();
        });
    }

    public function assertEntitySlot(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $inFlight = $this->entitiesInFlight($user);

        if ($inFlight >= $this->entityLimit()) {
            throw new ProcessingLimitReached(
                "You already have {$inFlight} entities being processed. Wait for one to finish before uploading another.",
            );
        }
    }

    public function assertAlignmentSlot(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($this->alignmentsInFlight($user) >= $this->alignmentLimit()) {
            throw new ProcessingLimitReached(
                'You already have an alignment being processed. Wait for it to finish before starting another.',
            );
        }
    }

    public function entitySlotAvailable(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->entitiesInFlight($user) < $this->entityLimit();
    }

    public function alignmentSlotAvailable(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->alignmentsInFlight($user) < $this->alignmentLimit();
    }

    public function entitiesInFlight(User $user): int
    {
        return Entity::query()
            ->where('created_by', $user->getKey())
            ->where('status', 'processing')
            ->count();
    }

    public function alignmentsInFlight(User $user): int
    {
        return EntityMatch::query()
            ->where('created_by', $user->getKey())
            ->whereIn('status', ['pending', 'aligning'])
            ->count();
    }

    public function entityLimit(): int
    {
        return max(0, (int) config('limits.entities_processing_per_user', 2));
    }

    public function alignmentLimit(): int
    {
        return max(0, (int) config('limits.alignments_processing_per_user', 1));
    }
}
