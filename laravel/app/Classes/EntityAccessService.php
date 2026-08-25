<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides who may read an entity or an entity match, and records per-user
 * grants to restricted entities.
 *
 * Access rule:
 *  - an admin may read anything (Admin bypass),
 *  - a public entity (is_restricted = false) is readable by any approved user,
 *  - a restricted entity is readable only by a user with an access grant row.
 *
 * To read an EnRuEntityMatch in the bilingual surfaces, the user must be
 * able to read BOTH the EN and RU entities.
 */
class EntityAccessService
{
    public function canRead(User $user, EnEntity|RuEntity $entity): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $entity->is_restricted) {
            return true;
        }

        return $entity->grantedUsers()
            ->whereKey($user->getKey())
            ->exists();
    }

    /**
     * Who may edit an Entity (name, description) and its sentences in the
     * entities frontend. Structurally identical to canRead: admin bypass;
     * Public editable by any approved user; Restricted editable by grantees.
     * See ADR 0015.
     */
    public function canEdit(User $user, EnEntity|RuEntity $entity): bool
    {
        return $this->canRead($user, $entity);
    }

    /**
     * A bilingual match is readable only when both of its entities are readable.
     */
    public function canReadMatch(User $user, EnRuEntityMatch $match): bool
    {
        if ($match->enEntity === null || $match->ruEntity === null) {
            return false;
        }

        return $this->canRead($user, $match->enEntity)
            && $this->canRead($user, $match->ruEntity);
    }

    /**
     * Record (or refresh) a user's read grant on a restricted entity. A missing
     * grant is inserted; an existing one is updated with the current similarity.
     */
    public function grant(User $user, EnEntity|RuEntity $entity, ?float $similarity): void
    {
        $relation = $entity->grantedUsers();

        if ($relation->whereKey($user->getKey())->exists()) {
            $relation->updateExistingPivot($user->getKey(), ['similarity' => $similarity]);

            return;
        }

        $relation->attach($user->getKey(), ['similarity' => $similarity]);
    }

    /**
     * Query for entities in the given language the user is allowed to read:
     * public entities plus restricted entities the user has a grant for.
     */
    public function readableQuery(User $user, string $lang): Builder
    {
        /** @var class-string<EnEntity>|class-string<RuEntity> $modelClass */
        $modelClass = $lang === 'en' ? EnEntity::class : RuEntity::class;

        $query = $modelClass::query();

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('is_restricted', false)
                ->orWhereHas('grantedUsers', function (Builder $query) use ($user): void {
                    $query->whereKey($user->getKey());
                });
        });
    }

    /**
     * Query for entity matches the user is allowed to read: matches whose EN and
     * RU entities are both readable by the user.
     */
    public function readableMatchQuery(User $user): Builder
    {
        $query = EnRuEntityMatch::query();

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('enEntity', function (Builder $query) use ($user): void {
            $query->where('is_restricted', false)
                ->orWhereHas('grantedUsers', function (Builder $query) use ($user): void {
                    $query->whereKey($user->getKey());
                });
        })->whereHas('ruEntity', function (Builder $query) use ($user): void {
            $query->where('is_restricted', false)
                ->orWhereHas('grantedUsers', function (Builder $query) use ($user): void {
                    $query->whereKey($user->getKey());
                });
        });
    }
}
