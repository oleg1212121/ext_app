import Main from '../Layouts/Main.jsx';
import ReaderIndexApp from './Reader/ReaderIndexApp.jsx';

const ReaderIndex = ({lang = 'en', languages = [], entities = []}) => (
    <ReaderIndexApp lang={lang} languages={languages} entities={entities}/>
);

ReaderIndex.layout = (page) => <Main>{page}</Main>;

export default ReaderIndex;
