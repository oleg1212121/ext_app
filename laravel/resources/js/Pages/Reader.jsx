import Main from '../Layouts/Main.jsx';
import ReaderApp from './Reader/ReaderApp.jsx';

const Reader = ({
    primaryLang,
    translationLang,
    entity,
    rows = [],
    rowImages = [],
    rowKeys = [],
    stressedRows = [],
    phrasalRows = [],
    meta = null,
    positionKey = null,
    fontSize,
    highlight,
    stressMarks = false,
    phrasalVerbs = false,
    wordMap,
    primaryHighlightable,
    translationWordMap,
    translationHighlightable,
    primaryExplainable = false,
    translationExplainable = false,
    primarySide = null,
    explain = null,
}) => (
    <ReaderApp
        primaryLang={primaryLang}
        translationLang={translationLang}
        entity={entity}
        rows={rows}
        rowImages={rowImages}
        rowKeys={rowKeys}
        stressedRows={stressedRows}
        phrasalRows={phrasalRows}
        meta={meta}
        positionKey={positionKey}
        fontSize={fontSize}
        highlight={highlight}
        stressMarks={stressMarks}
        phrasalVerbs={phrasalVerbs}
        wordMap={wordMap}
        primaryHighlightable={primaryHighlightable}
        translationWordMap={translationWordMap}
        translationHighlightable={translationHighlightable}
        primaryExplainable={primaryExplainable}
        translationExplainable={translationExplainable}
        primarySide={primarySide}
        explain={explain}
    />
);

Reader.layout = (page) => <Main>{page}</Main>;

export default Reader;
