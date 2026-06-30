import {Label, Select as FlowbiteSelect} from "flowbite-react";

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
                                // selected={props.value === modelId}
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
