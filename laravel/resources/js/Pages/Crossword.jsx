import '../../css/crossword.css';
import Main from '../Layouts/Main.jsx';
import CrosswordApp from './Crossword/CrosswordApp.jsx';

const Crossword = ({lang = 'en', texts = []}) => (
    <CrosswordApp lang={lang} texts={texts}/>
);

Crossword.layout = (page) => <Main>{page}</Main>;

export default Crossword;
