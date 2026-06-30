import {createRoot} from 'react-dom/client';
import AlignmentsIndex from './alignments/AlignmentsIndex';

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('alignments-app');
    if (container) {
        const root = createRoot(container);
        root.render(<AlignmentsIndex />);
    }
});