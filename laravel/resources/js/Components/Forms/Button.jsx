import {Button as FlowbiteButton} from "flowbite-react";
import React from "react";

export default function Button({color, children, className, ...rest}) {
    return (
        <FlowbiteButton
            color={color}
            className={['cursor-pointer', className || ""].filter(Boolean).join(' ')}
            {...rest}
        >
            {children}
        </FlowbiteButton>

    )
}
