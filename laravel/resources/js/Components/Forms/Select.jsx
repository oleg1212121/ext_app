import {Label, Select as FlowbiteSelect} from "flowbite-react";

export default function Select(props) {
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
            >
                {props.items.map((item) => (
                    <option
                        key={item.id}
                        value={item.id}
                    >
                        {item.text}
                    </option>
                ))}
            </FlowbiteSelect>
        </div>
    )
}
