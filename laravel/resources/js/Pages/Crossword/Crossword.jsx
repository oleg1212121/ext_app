import Main from '../../Layouts/Main.jsx';
import CrosswordApp from './CrosswordApp.jsx';

const Crossword = ({works = [], languages = [], levels = []}) => (
    <CrosswordApp works={works} languages={languages} levels={levels}/>
);

Crossword.layout = (page) => <Main>{page}</Main>;

export default Crossword;
