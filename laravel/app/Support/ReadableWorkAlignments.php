<?php

namespace App\Support;

use App\Classes\EntityAccessService;
use App\Models\EntityMatch;
use App\Models\User;
use App\Models\Work;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reader surface's readable-alignments rule: a match is listed and
 * counted when its status is completed or stale (pending, aligning and
 * failed matches have no meaning-match rows to read — the same rule the
 * Bilinguals simulator picker applies) and both of its sides are readable
 * (EntityAccessService's readableMatchConstraint). One home for the rule so
 * the works list's per-card counts, the sections page's Alignments card,
 * the entity reader's cross-link presence and the alignments list can
 * never disagree.
 */
class ReadableWorkAlignments
{
    /**
     * The statuses whose matches carry meaning-match rows to read.
     *
     * @var list<string>
     */
    public const STATUSES = ['completed', 'stale'];

    public function __construct(protected EntityAccessService $access) {}

    /**
     * The work's readable completed/stale matches as a standalone query.
     * The work comes via the A-side entity — an EntityMatch has no work_id
     * of its own, and going through Work::alignments()' hasManyThrough join
     * turns unqualified column names ambiguous in Postgres.
     *
     * @return Builder<EntityMatch>
     */
    public function forWork(Work $work, User $user): Builder
    {
        return EntityMatch::query()
            ->whereHas('aEntity', fn (Builder $query): Builder => $query->where('work_id', $work->id))
            ->whereIn('status', self::STATUSES)
            ->where($this->access->readableMatchConstraint($user));
    }

    /**
     * The count constraint for Work::alignments() inside withCount (the
     * works lists' per-card counts): the same statuses + readable rule, so
     * a card's count agrees with forWork's total for the same work. Columns
     * name the matches table because the relation's join puts entities and
     * entity_matches side by side — a bare status is ambiguous in Postgres.
     *
     * @return Closure(Builder): Builder
     */
    public function workCountConstraint(User $user): Closure
    {
        return fn (Builder $query): Builder => $query
            ->whereIn('entity_matches.status', self::STATUSES)
            ->where($this->access->readableMatchConstraint($user));
    }
}
