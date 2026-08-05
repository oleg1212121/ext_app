import {Checkbox as FlowbiteCheckbox} from "flowbite-react";

export default function CheckboxInput({label, id, className, children}) {
    return (
        <>
            <FlowbiteCheckbox
                id={id}
                className={[
                    'cursor-pointer rounded-sm',
                    'border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
                    'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]',
                    'focus:outline-none focus:ring-2 focus:ring-offset-2',
                    'focus:ring-[var(--color-vermilion)] dark:focus:ring-[var(--color-vermilion-night)]',
                    'focus:ring-offset-[var(--color-vellum)] dark:focus:ring-offset-[var(--color-ink-night)]',
                    className || "",
                ].join(' ')}
            />
        </>
    )
}