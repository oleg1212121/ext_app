import Main from '../Layouts/Main.jsx';
import ReaderApp from './Reader/ReaderApp.jsx';

const ReaderReact = ({
    lang = 'en',
    entity,
    rows = [],
    rowKeys = [],
    meta = null,
    positionKey = null,
    fontSize,
    highlight,
    wordMap,
    primaryHighlightable,
    translationWordMap,
    translationHighlightable,
}) => (
    <ReaderApp
        lang={lang}
        entity={entity}
        rows={rows}
        rowKeys={rowKeys}
        meta={meta}
        positionKey={positionKey}
        fontSize={fontSize}
        highlight={highlight}
        wordMap={wordMap}
        primaryHighlightable={primaryHighlightable}
        translationWordMap={translationWordMap}
        translationHighlightable={translationHighlightable}
    />
);

ReaderReact.layout = (page) => <Main>{page}</Main>;

export default ReaderReact;
