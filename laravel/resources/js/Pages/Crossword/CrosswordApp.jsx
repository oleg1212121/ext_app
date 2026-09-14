import CrosswordHeader from './Components/CrosswordHeader';
import CrosswordGrid from './Components/CrosswordGrid';
import RightPanel from './Components/RightPanel';
import UnsolvedModal from './Components/UnsolvedModal';
import {useCrossword} from './useCrossword';

export default function CrosswordApp({works = [], languages = [], levels = []}) {
    const crosswordState = useCrossword({works, languages, levels});

    return (
        <div
            id="crosswordRoot"
            className="flex flex-col flex-1 min-h-0 bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-sans)]"
        >
            <UnsolvedModal
                show={crosswordState.showUnsolvedModal}
                onClose={() => crosswordState.setShowUnsolvedModal(false)}
                items={crosswordState.unsolvedList()}
                hasCrossword={!!crosswordState.crossword?.dictionary}
            />

            <CrosswordHeader
                works={crosswordState.works}
                languages={crosswordState.languages}
                languageFilter={crosswordState.languageFilter}
                setLanguageFilter={crosswordState.setLanguageFilter}
                currentEntity={crosswordState.currentEntity}
                setCurrentEntity={crosswordState.setCurrentEntity}
                wordLevels={crosswordState.wordLevels}
                currentLevel={crosswordState.currentLevel}
                setCurrentLevel={crosswordState.setCurrentLevel}
                onBuild={crosswordState.getCrossword}
            />

            <main className="flex-1 flex flex-row overflow-hidden bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] min-h-0">
                <CrosswordGrid
                    crossword={crosswordState.crossword}
                    cellValues={crosswordState.cellValues}
                    isError={crosswordState.isError}
                    onArrowClick={crosswordState.clickArrowCell}
                    onSymbolClick={crosswordState.clickSymbolCell}
                    onCellKeyDown={crosswordState.changeCell}
                    registerInputRef={crosswordState.registerInputRef}
                    onAltKeyDown={crosswordState.setAltBlock}
                    onAltKeyUp={crosswordState.unsetAltBlock}
                    onRetry={crosswordState.getCrossword}
                />

                <RightPanel
                    width={crosswordState.rightPanelWidth}
                    currentTab={crosswordState.currentTab}
                    setCurrentTab={crosswordState.setCurrentTab}
                    definitions={crosswordState.definitions}
                    translations={crosswordState.translations}
                    onShowUnsolved={() => crosswordState.setShowUnsolvedModal(true)}
                    onStartDrag={crosswordState.startDragRightPanel}
                />
            </main>
        </div>
    );
}
