import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {useI18n} from '../../i18n';
import {completeCrossword, fetchCrossword} from './api';
import {
    ALLOWED_KEYS,
    cellKey,
    DEFAULT_LEVEL,
    getDefaultPanelWidth,
    getMaxPanelWidth,
    MIN_PANEL_WIDTH,
    VECTORS,
} from './constants';

function parseCoords(y, x) {
    return [parseInt(String(y), 10), parseInt(String(x), 10)];
}

function buildCellValues(grid) {
    const values = {};
    if (!grid) {
        return values;
    }

    for (let y = 0; y < grid.length; y++) {
        for (let x = 0; x < grid[y].length; x++) {
            const cell = grid[y][x];
            if (cell?.type === 4) {
                values[cellKey(y, x)] = cell.answer ?? '';
            }
        }
    }

    return values;
}

function cloneGrid(grid) {
    return grid.map((row) => row.map((cell) => ({...cell})));
}

// Default to the first work, preferring its original-language entity so the
// page opens on the source text rather than a random translation.
function defaultEntityId(works) {
    const first = works[0];
    if (!first?.entities?.length) {
        return '';
    }

    const original = first.entities.find((entity) => entity.language_code === first.original_language_code);
    return (original ?? first.entities[0]).id;
}

export function useCrossword({works: initialWorks = [], languages = [], levels = []} = {}) {
    const {t} = useI18n();
    const [crossword, setCrossword] = useState(null);
    const [works] = useState(initialWorks);
    const [languageFilter, setLanguageFilter] = useState('');
    const [currentEntity, setCurrentEntity] = useState(() => defaultEntityId(initialWorks));
    const [currentLevel, setCurrentLevel] = useState(DEFAULT_LEVEL);
    const [currentTab, setCurrentTab] = useState(0);
    const [showUnsolvedModal, setShowUnsolvedModal] = useState(false);
    const [definitions, setDefinitions] = useState([]);
    const [translations, setTranslations] = useState([]);
    const [currentWord, setCurrentWord] = useState('');
    const [solvedWords, setSolvedWords] = useState([]);
    const [vector, setVector] = useState(true);
    const [altPressed, setAltPressed] = useState(false);
    const [rightPanelWidth, setRightPanelWidth] = useState(() => getDefaultPanelWidth());
    const [cellValues, setCellValues] = useState({});
    const [isError, setIsError] = useState(false);

    const vectorRef = useRef(true);
    const crosswordRef = useRef(null);
    const cellValuesRef = useRef({});
    const solvedWordsRef = useRef([]);
    const inputRefs = useRef({});
    const currentEmphasizedRef = useRef([]);

    useEffect(() => {
        vectorRef.current = vector;
    }, [vector]);

    useEffect(() => {
        crosswordRef.current = crossword;
    }, [crossword]);

    useEffect(() => {
        cellValuesRef.current = cellValues;
    }, [cellValues]);

    useEffect(() => {
        solvedWordsRef.current = solvedWords;
    }, [solvedWords]);

    const worksWithEntities = useMemo(
        () => works.filter((work) => work.entities?.length > 0),
        [works],
    );

    // The language select filters entity options across all works; works left
    // without matching entities disappear from the picker entirely.
    const filteredWorks = useMemo(() => {
        if (!languageFilter) {
            return worksWithEntities;
        }

        return worksWithEntities
            .map((work) => ({
                ...work,
                entities: work.entities.filter((entity) => entity.language_code === languageFilter),
            }))
            .filter((work) => work.entities.length > 0);
    }, [worksWithEntities, languageFilter]);

    // When the filter hides the selected entity, fall back to the first visible one.
    useEffect(() => {
        const visible = filteredWorks.some((work) => work.entities.some((entity) => entity.id === currentEntity));
        if (!visible) {
            setCurrentEntity(filteredWorks[0]?.entities[0]?.id ?? '');
        }
    }, [filteredWorks, currentEntity]);

    const focusCell = useCallback((y, x) => {
        const ref = inputRefs.current[cellKey(y, x)];
        ref?.focus();
    }, []);

    const refreshData = useCallback(() => {
        setCrossword(null);
        setCurrentWord('');
        setSolvedWords([]);
        setIsError(false);
        setAltPressed(false);
        setVector(true);
        vectorRef.current = true;
        setCurrentTab(0);
        setShowUnsolvedModal(false);
        setDefinitions([]);
        setTranslations([]);
        setCellValues({});
        currentEmphasizedRef.current = [];
    }, []);

    const wordLevels = levels.map((level) => ({id: level, name: t(`crossword.level_${level}`)}));

    const getCrossword = useCallback(async () => {
        refreshData();

        if (!currentEntity) {
            return;
        }

        try {
            const data = await fetchCrossword(currentEntity, currentLevel);
            if (data.used?.length > 1) {
                setCrossword(data);
                setCellValues(buildCellValues(data.newGrid));
                setSolvedWords([]);
            }
        } catch (error) {
            console.error('Error:', error);
            setIsError(true);
        }
    }, [currentEntity, currentLevel, refreshData]);

    const paintWord = useCallback((word, color, changeable = true) => {
        const colors = ['green', 'white', 'grey', 'blue'];
        if (!colors.includes(color)) {
            return;
        }

        setCrossword((prev) => {
            if (!prev?.newGrid) {
                return prev;
            }

            const newGrid = cloneGrid(prev.newGrid);
            let {x, y} = word;

            if (word.vector) {
                for (x = word.x; x < word.x + word.value.length; x++) {
                    if (newGrid[y][x]?.changeable) {
                        newGrid[y][x].class = color;
                        newGrid[y][x].changeable = changeable;
                    }
                }
            } else {
                for (y = word.y; y < word.y + word.value.length; y++) {
                    if (newGrid[y][x]?.changeable) {
                        newGrid[y][x].class = color;
                        newGrid[y][x].changeable = changeable;
                    }
                }
            }

            return {...prev, newGrid};
        });
    }, []);

    const findWord = useCallback((y, x, grid, currentVector) => {
        [y, x] = parseCoords(y, x);
        const cell = grid[y][x];
        const word1 = cell.words?.[0] ?? null;
        const word2 = cell.words?.[1] ?? null;
        let word = word1 ?? word2;
        let nextVector = currentVector;

        if (word1 && word2) {
            if (currentVector === word1.vector) {
                word = word1;
            } else {
                word = word2;
            }
        } else if (cell.vector !== undefined) {
            nextVector = cell.vector;
        }

        return {word, vector: nextVector};
    }, []);

    const checkIfWordIsCorrect = useCallback((y, x, grid, values, solved) => {
        const {word} = findWord(y, x, grid, vectorRef.current);
        if (!word || solved.includes(word.value)) {
            return;
        }

        let isCorrect = true;
        const len = word.value.length;
        let cx = word.x;
        let cy = word.y;

        if (word.vector) {
            for (cx = word.x; cx < word.x + len; cx++) {
                const val = (values[cellKey(cy, cx)] ?? '').toLowerCase();
                if (val === word.value[cx - word.x].toLowerCase()) {
                    continue;
                }
                isCorrect = false;
                break;
            }
        } else {
            for (cy = word.y; cy < word.y + len; cy++) {
                const val = (values[cellKey(cy, cx)] ?? '').toLowerCase();
                if (val === word.value[cy - word.y].toLowerCase()) {
                    continue;
                }
                isCorrect = false;
                break;
            }
        }

        if (isCorrect) {
            paintWord(word, 'green', false);
            setSolvedWords((prev) => [...prev, word.value]);
        }
    }, [findWord, paintWord]);

    // When every placed word is solved, persist the solved words server-side.
    useEffect(() => {
        const placedCount = crossword?.words?.length ?? 0;
        if (placedCount === 0 || solvedWords.length < placedCount) {
            return;
        }

        const wordIds = Object.values(crossword?.word_ids ?? {});
        if (wordIds.length === 0) {
            return;
        }

        completeCrossword(wordIds).catch((error) => {
            console.error('Error:', error);
        });
    }, [crossword, solvedWords]);

    const applySelectedCell = useCallback((y, x) => {
        const grid = crosswordRef.current?.newGrid;
        if (!grid) {
            return;
        }

        [y, x] = parseCoords(y, x);

        for (let i = 0; i < currentEmphasizedRef.current.length; i++) {
            paintWord(currentEmphasizedRef.current[i], 'white');
        }
        currentEmphasizedRef.current = [];

        const {word, vector: nextVector} = findWord(y, x, grid, vectorRef.current);
        if (!word) {
            return;
        }

        setVector(nextVector);
        vectorRef.current = nextVector;
        setCurrentWord(word.value);

        const dictionary = crosswordRef.current.dictionary[word.value] ?? {};
        setDefinitions(dictionary.definitions ?? []);
        setTranslations(dictionary.translations ?? []);

        currentEmphasizedRef.current = [word];
        paintWord(word, 'blue');
        checkIfWordIsCorrect(y, x, grid, cellValuesRef.current, solvedWordsRef.current);
        focusCell(y, x);
    }, [checkIfWordIsCorrect, findWord, focusCell, paintWord]);

    const findPossibleCell = useCallback((y, x, dirVector) => {
        const grid = crosswordRef.current?.newGrid;
        if (!grid) {
            return;
        }

        [y, x] = parseCoords(y, x);
        let count = 0;

        while (grid[y]?.[x]?.type !== 4 && count < 300) {
            count++;
            x += VECTORS[+dirVector][1];
            y += VECTORS[+dirVector][0];

            if (y < 0) {
                y = grid.length - 1;
            }
            if (y >= grid.length) {
                y = 0;
            }
            if (x < 0) {
                x = grid[0].length - 1;
            }
            if (x >= grid[0].length) {
                x = 0;
            }
        }

        applySelectedCell(y, x);
    }, [applySelectedCell]);

    const moveTargetCell = useCallback((y, x, dirVector) => {
        [y, x] = parseCoords(y, x);
        x += VECTORS[+dirVector][1];
        y += VECTORS[+dirVector][0];
        findPossibleCell(y, x, dirVector);
    }, [findPossibleCell]);

    const changeFocus = useCallback((y, x, direction = true) => {
        const grid = crosswordRef.current?.newGrid;
        if (!grid) {
            return;
        }

        [y, x] = parseCoords(y, x);

        if (vectorRef.current) {
            x = direction ? x + 1 : x - 1;
        } else {
            y = direction ? y + 1 : y - 1;
        }

        if (grid[y]?.[x]?.type === 4) {
            focusCell(y, x);
        }
    }, [focusCell]);

    const clickArrowCell = useCallback((y, x) => {
        const grid = crosswordRef.current?.newGrid;
        if (!grid) {
            return;
        }

        [y, x] = parseCoords(y, x);
        const cell = grid[y][x];
        setVector(cell.vector);
        vectorRef.current = cell.vector;
        moveTargetCell(y, x, cell.vector);
    }, [moveTargetCell]);

    const clickSymbolCell = useCallback((y, x) => {
        applySelectedCell(y, x);
    }, [applySelectedCell]);

    const changeCell = useCallback((event) => {
        if (altPressed) {
            return;
        }

        const target = event.target;
        const value = event.key;
        let y = target.dataset.y;
        let x = target.dataset.x;
        const grid = crosswordRef.current?.newGrid;

        if (!grid) {
            return;
        }

        const cell = grid[y][x];
        const key = cellKey(y, x);

        if (event.key === 'ArrowLeft') {
            moveTargetCell(y, x, 3);
            event.preventDefault();
            return;
        }
        if (event.key === 'ArrowRight') {
            moveTargetCell(y, x, 1);
            event.preventDefault();
            return;
        }
        if (event.key === 'ArrowUp') {
            moveTargetCell(y, x, 2);
            event.preventDefault();
            return;
        }
        if (event.key === 'ArrowDown') {
            moveTargetCell(y, x, 0);
            event.preventDefault();
            return;
        }

        if (event.key === 'Backspace' || event.key === 'Delete') {
            const currentVal = cellValuesRef.current[key] ?? '';

            if (currentVal !== '') {
                setCellValues((prev) => ({...prev, [key]: ''}));
            } else {
                if (!(cell.changeable ?? false)) {
                    setCellValues((prev) => ({...prev, [key]: cell.value ?? cell.answer ?? ''}));
                }
                if (grid[y][x].type === 4) {
                    changeFocus(y, x, false);
                }
            }
            event.preventDefault();
            return;
        }

        if (ALLOWED_KEYS.includes(event.key)) {
            if (!cell.changeable) {
                setCellValues((prev) => ({...prev, [key]: cell.value ?? cell.answer ?? ''}));
            } else {
                setCellValues((prev) => {
                    const next = {...prev, [key]: value};
                    setTimeout(() => {
                        checkIfWordIsCorrect(y, x, grid, next, solvedWordsRef.current);
                    }, 0);
                    return next;
                });
            }

            if (grid[y][x].type === 4) {
                changeFocus(y, x, true);
            }
            event.preventDefault();
        }
    }, [altPressed, changeFocus, checkIfWordIsCorrect, moveTargetCell]);

    const unsolvedList = useCallback(() => {
        if (!crossword?.dictionary) {
            return [];
        }

        const solved = new Set(solvedWords.map((w) => ('' + w).toLowerCase()));
        return Object.keys(crossword.dictionary)
            .filter((word) => !solved.has(('' + word).toLowerCase()))
            .map((word) => ({
                word,
                definitions: crossword.dictionary[word].definitions ?? [],
            }));
    }, [crossword, solvedWords]);

    const startDragRightPanel = useCallback((event) => {
        const startX = event.clientX;
        const startWidth = rightPanelWidth;
        const maxWidth = getMaxPanelWidth();

        const onMove = (e) => {
            const delta = e.clientX - startX;
            setRightPanelWidth(Math.max(MIN_PANEL_WIDTH, Math.min(maxWidth, startWidth - delta)));
        };

        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'col-resize';
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    }, [rightPanelWidth]);

    const setAltBlock = useCallback(() => setAltPressed(true), []);
    const unsetAltBlock = useCallback(() => setAltPressed(false), []);

    const registerInputRef = useCallback((y, x, el) => {
        if (el) {
            inputRefs.current[cellKey(y, x)] = el;
        } else {
            delete inputRefs.current[cellKey(y, x)];
        }
    }, []);

    return {
        crossword,
        works: filteredWorks,
        languages,
        languageFilter,
        setLanguageFilter,
        currentEntity,
        setCurrentEntity,
        currentLevel,
        setCurrentLevel,
        wordLevels,
        currentTab,
        setCurrentTab,
        showUnsolvedModal,
        setShowUnsolvedModal,
        definitions,
        translations,
        currentWord,
        solvedWords,
        rightPanelWidth,
        cellValues,
        isError,
        getCrossword,
        clickArrowCell,
        clickSymbolCell,
        changeCell,
        startDragRightPanel,
        setAltBlock,
        unsetAltBlock,
        unsolvedList,
        registerInputRef,
    };
}
