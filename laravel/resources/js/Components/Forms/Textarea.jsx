import {Label, Textarea as FlowbiteTextarea} from "flowbite-react";

export default function Textarea({label, value, id, placeholder, ref, className, ...rest}) {
    return (
        <div className="">
            <div className="mb-2 block">
                <Label htmlFor={id}>{label}</Label>
            </div>
            <FlowbiteTextarea
                ref={ref}
                id={id}
                placeholder={placeholder}
                defaultValue={value}
                className={className}
                rows={4}
                {...rest}
            />
        </div>
    )
}
