import {Label, Textarea as FlowbiteTextarea} from "flowbite-react";

export default function Textarea({label, value, id, placeholder, ref, className, ...rest}) {
    return (
        <div className="">
            <div className="mb-2 block">
                <Label htmlFor={id} className="font-[var(--wbench-mono)] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] text-[10px] tracking-[0.24em] uppercase">{label}</Label>
            </div>
            <FlowbiteTextarea
                ref={ref}
                id={id}
                placeholder={placeholder}
                defaultValue={value}
                className={[
                    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-deep-night)]',
                    'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
                    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/60 dark:placeholder:text-[var(--wbench-ink-soft-night)]/60',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] focus-visible:border-[var(--wbench-accent)]',
                    'rounded-sm font-[var(--wbench-serif)]',
                    className || "",
                ].join(' ')}
                rows={4}
                {...rest}
            />
        </div>
    )
}