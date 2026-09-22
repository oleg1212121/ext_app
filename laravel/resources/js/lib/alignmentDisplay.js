// Display helpers for entity-match (alignment) payloads — shared by the
// work page's Alignments tab and the alignment editor.

export const STATUS_BADGE = {
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] bg-transparent border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    verifying: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] bg-transparent border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    aligning: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] bg-transparent border-[var(--wbench-ink-soft)]/40 dark:border-[var(--wbench-ink-soft-night)]/40',
    completed: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] bg-transparent border-[var(--wbench-ink)]/30 dark:border-[var(--wbench-ink-night)]/30',
    failed: 'text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] bg-transparent border-[var(--wbench-danger)]/40 dark:border-[var(--wbench-danger-night)]/40',
};

export function similarityClass(value) {
    if (value === null) return 'text-[var(--wbench-ink-soft)]/50 dark:text-[var(--wbench-ink-soft-night)]/50';
    if (value >= 0.85) return 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]';
    if (value >= 0.70) return 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';
    return 'text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]';
}
