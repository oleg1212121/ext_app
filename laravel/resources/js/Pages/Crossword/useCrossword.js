import {useCallback, useEffect, useRef, useState} from 'react';
import {
    acknowledgeWord,
    askAi,
    dismissWord,
    fetchCrossword,
    upvoteWord,
} from './api';
import {
    ALLOWED_KEYS,
    cellKey,
    DEFAULT_LEVEL,
    getDefaultPanelWidth,
    getMaxPanelWidth,
    MIN_PANEL_WIDTH,
    VECTORS,
    WORD_LEVELS,
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

export function useCrossword({lang = 'en', texts: initialTexts = []} = {}) {
    const [crossword, setCrossword] = useState(null);
    const [texts, setTexts] = useState(initialTexts);
    const [currentText, setCurrentText] = useState(() => initialTexts[0]?.id ?? '');
    const [currentLevel, setCurrentLevel] = useState(DEFAULT_LEVEL);
    const [currentTab, setCurrentTab] = useState(0);
    const [showUnsolvedModal, setShowUnsolvedModal] = useState(false);
    const [definitions, setDefinitions] = useState([]);
    const [obsolete, setObsolete] = useState([]);
    const [translations, setTranslations] = useState([]);
    const [forms, setForms] = useState([]);
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
    const currentTextRef = useRef('');
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

    useEffect(() => {
        currentTextRef.current = currentText;
    }, [currentText]);

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
        setObsolete([]);
        setTranslations([]);
        setForms([]);
        setCellValues({});
        currentEmphasizedRef.current = [];
    }, []);

    useEffect(() => {
        setTexts(initialTexts);
        setCurrentText(initialTexts[0]?.id ?? '');
    }, [lang, initialTexts]);

    const getCrossword = useCallback(async () => {
        refreshData();

        try {
            const data = await fetchCrossword(currentText, currentLevel);
            if (data.used?.length > 1) {
                setCrossword(data);
                setCellValues(buildCellValues(data.newGrid));
                setSolvedWords([]);
            }
        } catch (error) {
            console.error('Error:', error);
            setIsError(true);
        }
    }, [currentText, currentLevel, refreshData]);

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
            upvoteWord(word.value, currentTextRef.current).catch((error) => {
                console.error('Error:', error);
            });
            setSolvedWords((prev) => [...prev, word.value]);
        }
    }, [findWord, paintWord]);

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
        setObsolete(dictionary.obsolete ?? []);
        setTranslations(dictionary.translations ?? []);
        setForms(dictionary.forms ?? []);

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

    const handleAcknowledge = useCallback(async () => {
        if (!currentWord) {
            return;
        }

        try {
            await acknowledgeWord(currentWord);
        } catch (error) {
            console.error('Error:', error);
        }
    }, [currentWord]);

    const handleDismiss = useCallback(async () => {
        if (!currentWord) {
            return;
        }

        try {
            await dismissWord(currentWord);
        } catch (error) {
            console.error('Error:', error);
        }
    }, [currentWord]);

    const handleAskAi = useCallback(async () => {
        const word = currentWord || '';
        if (!word) {
            console.warn('No current word selected');
            return;
        }

        try {
            const newDefinitions = await askAi(word);
            if (newDefinitions.length > 0) {
                setCrossword((prev) => {
                    if (!prev?.dictionary?.[word]) {
                        return prev;
                    }

                    const dictionary = {...prev.dictionary};
                    dictionary[word] = {
                        ...dictionary[word],
                        definitions: [...(dictionary[word].definitions ?? []), ...newDefinitions],
                    };

                    return {...prev, dictionary};
                });
                setDefinitions((prev) => [...prev, ...newDefinitions]);
            }
            setCurrentTab(0);
        } catch (error) {
            console.error('Error:', error);
            setIsError(true);
        }
    }, [currentWord]);

    const handleCheckImage = useCallback(() => {
        const word = currentWord;
        const url = 'https://www.google.com/search?q=' + word + '+meaning&udm=2';
        window.open(url);
    }, [currentWord]);

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
        texts,
        currentText,
        setCurrentText,
        currentLevel,
        setCurrentLevel,
        wordLevels: WORD_LEVELS,
        currentTab,
        setCurrentTab,
        showUnsolvedModal,
        setShowUnsolvedModal,
        definitions,
        obsolete,
        translations,
        forms,
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
        handleAcknowledge,
        handleDismiss,
        handleAskAi,
        handleCheckImage,
        registerInputRef,
    };
}
