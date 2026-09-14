import React from 'react';
import {getCsrfToken} from '../lib/http';

export function useUiSettingsAutosave(section, value) {
    const serialized = JSON.stringify(value);
    const firstRender = React.useRef(true);
    React.useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            fetch('/ui-settings', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
                },
                body: JSON.stringify({[section]: JSON.parse(serialized)}),
            }).catch((e) => console.warn('ui-settings save failed', e));
        }, 800);
        return () => clearTimeout(timer);
    }, [section, serialized]);
}
