import CrosswordHeader from './Components/CrosswordHeader';
import CrosswordGrid from './Components/CrosswordGrid';
import RightPanel from './Components/RightPanel';
import UnsolvedModal from './Components/UnsolvedModal';
import {useCrossword} from './useCrossword';

export default function CrosswordApp({lang = 'en', texts = []}) {
    const crosswordState = useCrossword({lang, texts});

    return (
        <div id="crosswordRoot" className="flex flex-col flex-1 min-h-0 bg-orange-50 dark:bg-gray-900">
            <UnsolvedModal
                show={crosswordState.showUnsolvedModal}
                onClose={() => crosswordState.setShowUnsolvedModal(false)}
                items={crosswordState.unsolvedList()}
                hasCrossword={!!crosswordState.crossword?.dictionary}
            />

            <CrosswordHeader
                texts={crosswordState.texts}
                currentText={crosswordState.currentText}
                setCurrentText={crosswordState.setCurrentText}
                wordLevels={crosswordState.wordLevels}
                currentLevel={crosswordState.currentLevel}
                setCurrentLevel={crosswordState.setCurrentLevel}
                onBuild={crosswordState.getCrossword}
            />

            <main className="flex-1 flex flex-row overflow-hidden bg-orange-100 dark:bg-gray-800 min-h-0">
                <CrosswordGrid
                    crossword={crosswordState.crossword}
                    cellValues={crosswordState.cellValues}
                    onArrowClick={crosswordState.clickArrowCell}
                    onSymbolClick={crosswordState.clickSymbolCell}
                    onCellKeyDown={crosswordState.changeCell}
                    registerInputRef={crosswordState.registerInputRef}
                    onAltKeyDown={crosswordState.setAltBlock}
                    onAltKeyUp={crosswordState.unsetAltBlock}
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
                    onAskAi={crosswordState.handleAskAi}
                    onAcknowledge={crosswordState.handleAcknowledge}
                    onDismiss={crosswordState.handleDismiss}
                    onShowUnsolved={() => crosswordState.setShowUnsolvedModal(true)}
                    onStartDrag={crosswordState.startDragRightPanel}
                />
            </main>
        </div>
    );
}
