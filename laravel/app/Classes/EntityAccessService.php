<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\User;
use Closure;
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
     * entities frontend. Admin bypass; Public editable by any approved user;
     * Restricted editable by grantees (ADR 0015). An Approved entity is
     * edit-locked for everyone but admins — the creator must un-approve
     * first (ADR 0034).
     */
    public function canEdit(User $user, Entity $entity): bool
    {
        if ($entity->is_approved) {
            return $user->isAdmin();
        }

        return $this->canRead($user, $entity);
    }

    /**
     * Who may flip an Entity's approval flag (in either direction): its
     * uploader or an admin. While approved this is the only change the
     * creator can still make to the entity.
     */
    public function canChangeApproval(User $user, Entity $entity): bool
    {
        return $user->isAdmin() || $entity->created_by === $user->getKey();
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
     * Who may mutate an alignment in the alignment editor: read access to
     * both sides, and neither side Approved — an approved entity freezes
     * every alignment it takes part in (ADR 0034). Admin bypass.
     */
    public function canEditMatch(User $user, EntityMatch $match): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($match->aEntity?->is_approved || $match->bEntity?->is_approved) {
            return false;
        }

        return $this->canReadMatch($user, $match);
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

        return $query->where($this->readableConstraint($user));
    }

    /**
     * Where-group scoping any entity query to what the user may read. Usable
     * both on plain entity queries (readableQuery) and inside relation
     * constraints such as withCount (e.g. the Library's per-work entity counts).
     * A no-op for admins.
     *
     * @return Closure(Builder): Builder
     */
    public function readableConstraint(User $user): Closure
    {
        if ($user->isAdmin()) {
            return fn (Builder $query): Builder => $query;
        }

        return fn (Builder $query): Builder => $query->where(function (Builder $query) use ($user): void {
            $query->where('is_restricted', false)
                ->orWhereHas('grantedUsers', fn (Builder $query): Builder => $query->whereKey($user->getKey()));
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
