import Main from '../Layouts/Main.jsx';
import ReaderApp from './Reader/ReaderApp.jsx';

const ReaderReact = ({lang = 'en', entity, rows = []}) => (
    <ReaderApp lang={lang} entity={entity} rows={rows}/>
);

ReaderReact.layout = (page) => <Main>{page}</Main>;

export default ReaderReact;
