import {Checkbox as FlowbiteCheckbox} from "flowbite-react";

export default function CheckboxInput({label, id, className, children}) {
    return (
        <>
            <FlowbiteCheckbox id={id} className={className}/>
            {/*<Label htmlFor="remember">Remember me</Label>*/}
        </>
    )
}
