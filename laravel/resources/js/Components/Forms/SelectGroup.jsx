import {Label, Select as FlowbiteSelect} from "flowbite-react";

const selectClass = [
    'font-serif text-sm tracking-tight',
    'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40',
    'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
    'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
    'focus:outline-none focus:ring-2',
    'focus:border-[var(--color-vermilion)] dark:focus:border-[var(--color-vermilion-night)]',
    'focus:ring-[var(--color-vermilion)] dark:focus:ring-[var(--color-vermilion-night)]',
    'rounded-sm',
].join(' ');

export default function SelectGroup(props) {
    return (
        <div className="max-w-md">
            {props.label &&
                <div className="mb-2 block">
                    <Label htmlFor={props.id}>{props.label}</Label>
                </div>
            }
            <FlowbiteSelect
                id={props.id}
                onChange={props.onChange}
                value={props.value}
                className={selectClass}
            >
                {Object.entries(props.groups).map(([groupName, modelsObj]) => (
                    <optgroup
                        key={groupName}
                        label={groupName}
                    >
                        {Object.entries(modelsObj).map(([modelId, modelLabel]) => (
                            <option
                                key={modelId}
                                value={modelId}
                            >
                                {modelLabel}
                            </option>
                        ))}
                    </optgroup>
                ))}
            </FlowbiteSelect>
        </div>
    )
}