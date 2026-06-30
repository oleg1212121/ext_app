import Textarea from "../../../Components/Forms/Textarea.jsx";
import React from "react";

const DEFAULT_WORKPLACE_HEIGHT = 256;

export default function Workplace(props) {
    const [workplaceHeight, setWorkplaceHeight] = React.useState(DEFAULT_WORKPLACE_HEIGHT);

    const startDrag = (event) => {
        event.preventDefault();
        const startY = event.clientY;
        const startHeight = workplaceHeight;

        const onMove = (e) => {
            const delta = e.clientY - startY;
            setWorkplaceHeight(Math.max(80, Math.min(Math.round(window.innerHeight * 0.6), startHeight - delta)));
        };

        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'row-resize';
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    return (
        <div className="mt-auto shrink-0 flex flex-col">
            <div className="drag-handle-horizontal flex-none" onMouseDown={startDrag}></div>
            <div id="workplace_area"
                 className="shrink-0 border-t-2 border-gray-400 dark:border-gray-600 bg-orange-100 dark:bg-gray-800 shadow-lg overflow-y-auto pb-5"
                 style={{height: `${workplaceHeight}px`}}>

                <div className="p-3 border-b border-gray-300 dark:border-gray-600">
                    <Textarea ref={props.workplaceRef} label="Workplace" value="" placeholder="Workplace" className="resizeable_element"/>
                </div>
                {props.showQuestion === true && <div className="p-3">
                    <Textarea ref={props.questionRef} label="Question" value={props.currentQuestion} placeholder="Question" className="resizeable_element"/>
                </div>}
            </div>
        </div>
    )
}
