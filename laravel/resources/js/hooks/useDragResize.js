import {useCallback, useRef, useState} from 'react';

// Shared drag-to-resize for panel edges (simulator AI panel + workplace,
// crossword right panel): the hook owns the size state and the window
// mousemove/mouseup choreography, including the body userSelect/cursor
// dance. Dragging toward the content edge grows the panel (startSize -
// delta, the convention all three extracted copies shared).
//
// axis 'x' tracks clientX with a col-resize cursor, 'y' clientY with
// row-resize. min clamps every frame; max clamps at drag start and may be a
// function (e.g. a viewport fraction evaluated when the drag begins).
// Persistence is the caller's business (UI settings autosave, or nothing).
export function useDragResize({axis = 'x', min = 0, max = Infinity, initial = 0}) {
    const [size, setSize] = useState(initial);
    // Mirrors size for startDrag: the drag starts from the current size
    // without rebinding the handler on every render.
    const sizeRef = useRef(initial);

    const startDrag = useCallback((event) => {
        event.preventDefault();
        const startPos = axis === 'x' ? event.clientX : event.clientY;
        const startSize = sizeRef.current;
        const maxAtDragStart = typeof max === 'function' ? max() : max;

        const onMove = (e) => {
            const delta = (axis === 'x' ? e.clientX : e.clientY) - startPos;
            const next = Math.max(min, Math.min(maxAtDragStart, startSize - delta));
            sizeRef.current = next;
            setSize(next);
        };

        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = axis === 'x' ? 'col-resize' : 'row-resize';
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    }, [axis, min, max]);

    return {size, setSize, startDrag};
}
