import Main from '../Layouts/Main.jsx';
import ReaderApp from './Reader/ReaderApp.jsx';

const Reader = ({
    entity,
    rows = [],
    defaultSide = null,
    langs = {a: null, b: null},
    meta = null,
    positionKey = null,
    fontSize,
    highlight,
    stressMarks = false,
    phrasalVerbs = false,
    wordMaps = {a: {}, b: {}},
    highlightable = {a: false, b: false},
    explainable = {a: false, b: false},
    explain = null,
}) => (
    <ReaderApp
        entity={entity}
        rows={rows}
        defaultSide={defaultSide}
        langs={langs}
        meta={meta}
        positionKey={positionKey}
        fontSize={fontSize}
        highlight={highlight}
        stressMarks={stressMarks}
        phrasalVerbs={phrasalVerbs}
        wordMaps={wordMaps}
        highlightable={highlightable}
        explainable={explainable}
        explain={explain}
    />
);

Reader.layout = (page) => <Main>{page}</Main>;

export default Reader;
