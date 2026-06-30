import {createRoot} from 'react-dom/client';
import AlignmentsShow from './alignments/AlignmentsShow.jsx';

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('alignments-show');
    if (container) {
        const root = createRoot(container);
        root.render(<AlignmentsShow/>);
    }
});
