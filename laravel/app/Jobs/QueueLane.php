<?php

namespace App\Jobs;

/**
 * The queue lanes a job can be assigned to (ADR 0042).
 *
 * Workers consume both lanes in strict order — `default` first, `low` only
 * when no default work is pending — so a lane is a processing-order knob,
 * not a different failure/retry treatment. A job declares its lane once, as
 * a class attribute: `#[Queue(QueueLane::DEFAULT)]`. (A `public $queue`
 * property cannot be redeclared — the Queueable trait already owns it, and
 * PHP forbids incompatible trait-property redeclarations.) Re-classify by
 * editing the attribute; `grep -rn '#\[Queue(' app/Jobs` lists the registry.
 */
class QueueLane
{
    /** Someone waits on the result — user-facing work (e.g. the upload pipeline). */
    public const DEFAULT = 'default';

    /** Nobody waits on it — process-later work; may wait behind all default jobs. */
    public const LOW = 'low';
}
