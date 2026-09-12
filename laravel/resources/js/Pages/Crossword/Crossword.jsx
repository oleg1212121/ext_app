import Main from '../../Layouts/Main.jsx';
import CrosswordApp from './CrosswordApp.jsx';

const Crossword = ({entities = [], levels = []}) => <CrosswordApp entities={entities} levels={levels}/>;

Crossword.layout = (page) => <Main>{page}</Main>;

export default Crossword;
