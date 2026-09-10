<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
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
 * To read an EntityMatch in the bilingual surfaces, the user must be able to
 * read BOTH of its entities. Grants stay per-entity: access to one entity of
 * a work does not unlock its other translations.
 */
class EntityAccessService
{
    public function canRead(User $user, Entity $entity): bool
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
    public function canEdit(User $user, Entity $entity): bool
    {
        return $this->canRead($user, $entity);
    }

    /**
     * A bilingual match is readable only when both of its entities are readable.
     */
    public function canReadMatch(User $user, EntityMatch $match): bool
    {
        if ($match->aEntity === null || $match->bEntity === null) {
            return false;
        }

        return $this->canRead($user, $match->aEntity)
            && $this->canRead($user, $match->bEntity);
    }

    /**
     * Record (or refresh) a user's read grant on a restricted entity. A missing
     * grant is inserted; an existing one is updated with the current similarity.
     */
    public function grant(User $user, Entity $entity, ?float $similarity): void
    {
        $relation = $entity->grantedUsers();

        if ($relation->whereKey($user->getKey())->exists()) {
            $relation->updateExistingPivot($user->getKey(), ['similarity' => $similarity]);

            return;
        }

        $relation->attach($user->getKey(), ['similarity' => $similarity]);
    }

    /**
     * Query for entities (optionally in the given language) the user is allowed
     * to read: public entities plus restricted entities the user has a grant for.
     */
    public function readableQuery(User $user, ?int $languageId = null): Builder
    {
        $query = Entity::query();

        if ($languageId !== null) {
            $query->where('language_id', $languageId);
        }

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
     * Query for entity matches the user is allowed to read: matches whose a and
     * b entities are both readable by the user.
     */
    public function readableMatchQuery(User $user): Builder
    {
        $query = EntityMatch::query();

        if ($user->isAdmin()) {
            return $query;
        }

        $readable = fn (Builder $query): Builder => $query->where('is_restricted', false)
            ->orWhereHas('grantedUsers', fn (Builder $query): Builder => $query->whereKey($user->getKey()));

        return $query
            ->whereHas('aEntity', $readable)
            ->whereHas('bEntity', $readable);
    }
}
