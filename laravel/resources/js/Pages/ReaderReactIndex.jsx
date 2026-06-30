import Main from '../Layouts/Main.jsx';
import ReaderIndexApp from './Reader/ReaderIndexApp.jsx';

const ReaderReactIndex = ({lang = 'en', languages = [], entities = []}) => (
    <ReaderIndexApp lang={lang} languages={languages} entities={entities}/>
);

ReaderReactIndex.layout = (page) => <Main>{page}</Main>;

export default ReaderReactIndex;
