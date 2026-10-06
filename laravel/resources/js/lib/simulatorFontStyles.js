// The simulator's font size applies by injecting one page-wide <style> rule
// set (created once, text replaced on every change) instead of threading
// fontSize props through every leaf. It styles .resizeable_element markers
// AND #ai_answer_div by id — the AI panel's answer div is part of that
// contract even though the AI component knows nothing about fonts.
export const CONTROL_FONT_SCALE = 0.62;

export function updateResizeableFontStyles(fontSize) {
    let styleElement = document.getElementById('resizeable-font-styles');
    if (!styleElement) {
        styleElement = document.createElement('style');
        styleElement.id = 'resizeable-font-styles';
        document.head.appendChild(styleElement);
    }
    const controlFontSize = Math.round(fontSize * CONTROL_FONT_SCALE);

    styleElement.textContent = `
        .target.resizeable_element,
        .base.resizeable_element,
        textarea.resizeable_element,
        #ai_answer_div {
            font-size: ${fontSize}px;
            line-height: 1.55;
        }

        .bilingual-control-resizeable {
            font-size: ${controlFontSize}px;
            line-height: 1;
        }

        .bilingual-control-resizeable input[type="checkbox"] {
            width: 1em !important;
            height: 1em !important;
            min-width: 1em;
            min-height: 1em;
        }
    `;
}
