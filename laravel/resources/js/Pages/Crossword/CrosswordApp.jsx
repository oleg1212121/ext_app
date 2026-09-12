import CrosswordHeader from './Components/CrosswordHeader';
import CrosswordGrid from './Components/CrosswordGrid';
import RightPanel from './Components/RightPanel';
import UnsolvedModal from './Components/UnsolvedModal';
import {useCrossword} from './useCrossword';

export default function CrosswordApp({entities = [], levels = []}) {
    const crosswordState = useCrossword({entities, levels});

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
                entities={crosswordState.entities}
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
                    obsolete={crosswordState.obsolete}
                    translations={crosswordState.translations}
                    forms={crosswordState.forms}
                    onCheckImage={crosswordState.handleCheckImage}
                    onKnow={crosswordState.handleKnow}
                    onShowUnsolved={() => crosswordState.setShowUnsolvedModal(true)}
                    onStartDrag={crosswordState.startDragRightPanel}
                />
            </main>
        </div>
    );
}
