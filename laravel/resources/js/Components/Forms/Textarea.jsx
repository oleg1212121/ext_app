import {Label, Textarea as FlowbiteTextarea} from "flowbite-react";

export default function Textarea({label, value, id, placeholder, ref, className, ...rest}) {
    return (
        <div className="">
            <div className="mb-2 block">
                <Label htmlFor={id} className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-xs tracking-[0.2em] uppercase">{label}</Label>
            </div>
            <FlowbiteTextarea
                ref={ref}
                id={id}
                placeholder={placeholder}
                defaultValue={value}
                className={[
                    'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40',
                    'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
                    'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] placeholder:text-[var(--color-ink-soft)]/60 dark:placeholder:text-[var(--color-vellum-night)]/40',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
                    'rounded-sm',
                    className || "",
                ].join(' ')}
                rows={4}
                {...rest}
            />
        </div>
    )
}