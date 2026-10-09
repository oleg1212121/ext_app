<?php

use App\Http\Controllers\AlignmentController;
use App\Http\Controllers\AlignmentEditorController;
use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Http\Controllers\CrosswordController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\EntityIllustrationController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReadingAiController;
use App\Http\Controllers\RecommendationsController;
use App\Http\Controllers\UiSettingsController;
use App\Http\Controllers\WordController;
use App\Http\Controllers\WordTestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public route - welcome page only
Route::get('/', function () {
    return Inertia::render('Welcome');
});

// Authentication routes (login, register, etc.)
require __DIR__.'/auth.php';

Route::get('/pending-approval', function () {
    return view('auth.pending-approval');
})->middleware('auth')->name('pending-approval');

// Profile routes - accessible to all authenticated users (including unapproved)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings.update');
    Route::patch('/profile/ai-models', [ProfileController::class, 'updateAiModels'])->name('profile.ai-models.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/api-keys', [ProfileController::class, 'storeApiKey'])->name('profile.api-keys.store');
    Route::delete('/profile/api-keys/{providerKey}', [ProfileController::class, 'destroyApiKey'])->name('profile.api-keys.destroy');

    // Per-user UI settings (simulator/reader panels, popup section visibility).
    // Auth-only like the profile it feeds: the Popups tab is reachable before
    // approval, and the endpoint only ever touches the caller's own settings.
    Route::patch('/ui-settings', [UiSettingsController::class, 'update'])->name('ui-settings.update');
});

// All other routes require authentication + approval
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/dashboard', function () {
        //        return view('dashboard');
        return Inertia::render('Dashboard');
    })->middleware('verified')->name('dashboard');

    // The entity id alone names the text — its entity match carries both
    // languages, and the side rule picks which one is read (same reasoning
    // as ADR 0036's simulator route: no language segment).
    Route::get('/reader/{entityId}', [ReaderController::class, 'show'])
        ->whereNumber('entityId')
        ->name('reader.show');

    // Practice → Reader: the text library. Bare /reader derives the user's
    // native enabled language (fallback en). Registered after reader.show;
    // whereNumber vs [a-z]{2} keeps /reader/5 and /reader/en disjoint.
    Route::get('/reader/{lang?}', [ReaderController::class, 'index'])
        ->where('lang', '[a-z]{2}')
        ->name('reader.index');

    Route::get('/crossword', [CrosswordController::class, 'index'])->name('crossword');
    Route::post('/crossword/generate', [CrosswordController::class, 'generate'])->name('crossword.generate');
    Route::post('/crossword/complete', [CrosswordController::class, 'complete'])->name('crossword.complete');

    // Word test: GET draws a fresh frequency-rank sample, POST scores it and
    // bulk-marks the presumed-known range (ADR 0071). The server keeps the
    // served sample in the cache — submits score against it, not the client.
    Route::get('/word-test', [WordTestController::class, 'index'])->name('word-test.show');
    Route::post('/word-test/submit', [WordTestController::class, 'submit'])->name('word-test.submit');

    // Resources → Pronunciation guide: a static client-side chart (ADR 0054);
    // no props beyond the shared auth/uiStrings payload (ADR 0056).
    Route::get('/resources/pronunciation-guide', function () {
        return Inertia::render('Resources/PronunciationGuide');
    })->name('resources.pronunciation-guide');

    // Recommendations (ADR 0075): the viewer's readable texts re-ranked by
    // their stored word-knowledge snapshots — a read-only pass over
    // user_entity_word_knowledge, never a computation.
    Route::get('/recommendations', [RecommendationsController::class, 'index'])
        ->name('recommendations.index');

    Route::get('/words/{word}', [WordController::class, 'show'])
        ->whereNumber('word')
        ->name('words.show');
    Route::patch('/words/{word}/progress', [WordController::class, 'setFamiliarity'])
        ->whereNumber('word')
        ->name('words.progress.update');
    Route::delete('/words/{word}/progress', [WordController::class, 'resetProgress'])
        ->whereNumber('word')
        ->name('words.progress.reset');
    Route::post('/word-events', [WordController::class, 'recordEvents'])
        ->name('word.events.store');

    // Library → Works: the catalog plus its Entities and Alignments branches
    // (ADR 0039). Static branch lists register before {work}; whereNumber
    // keeps /works/{work} disjoint from /works/entities and /works/alignments.
    Route::get('/works', [LibraryController::class, 'index'])->name('works.index');
    Route::get('/works/create', [LibraryController::class, 'createWork'])->name('works.create');
    Route::post('/works', [LibraryController::class, 'storeWork'])->name('works.store');
    Route::get('/works/entities', [LibraryController::class, 'entitiesIndex'])->name('works.entities.index');
    Route::get('/works/alignments', [LibraryController::class, 'alignmentsIndex'])->name('works.alignments.index');
    Route::get('/works/{work}', [LibraryController::class, 'showWork'])
        ->whereNumber('work')
        ->name('works.show');
    Route::get('/works/{work}/entities', [LibraryController::class, 'workEntities'])
        ->whereNumber('work')
        ->name('works.entities.show');
    Route::get('/works/{work}/entities/create', [LibraryController::class, 'createEntity'])
        ->whereNumber('work')
        ->name('works.entities.create');
    Route::post('/works/{work}/entities', [LibraryController::class, 'storeEntity'])
        ->whereNumber('work')
        ->name('works.entities.store');
    // Entity view/edit and its sentence JSON API are work-nested too
    // (ADR 0073): the language segment was redundant — the entity carries
    // its language — and the URL now names the work the text belongs to.
    // The old flat /entities/{lang}/{entity} routes are gone without
    // redirects. Route names keep the entities.* prefix: works.entities.*
    // is already the browse page and the per-work list.
    Route::get('/works/{work}/entities/{entity}', [EntityController::class, 'show'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.show');
    Route::get('/works/{work}/entities/{entity}/edit', [EntityController::class, 'edit'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.edit');
    Route::patch('/works/{work}/entities/{entity}', [EntityController::class, 'update'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.update');
    Route::patch('/works/{work}/entities/{entity}/approved', [EntityController::class, 'updateApproved'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.approved.update');
    Route::get('/works/{work}/entities/{entity}/sentences', [EntityController::class, 'sentences'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.sentences');
    Route::post('/works/{work}/entities/{entity}/sentences', [EntityController::class, 'storeSentence'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.sentences.store');
    Route::post('/works/{work}/entities/{entity}/sentences/reorder', [EntityController::class, 'reorderSentences'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->name('entities.sentences.reorder');
    Route::patch('/works/{work}/entities/{entity}/sentences/{sentence}', [EntityController::class, 'updateSentence'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->whereNumber('sentence')
        ->name('entities.sentences.update');
    Route::delete('/works/{work}/entities/{entity}/sentences/{sentence}', [EntityController::class, 'destroySentence'])
        ->whereNumber('work')
        ->whereNumber('entity')
        ->whereNumber('sentence')
        ->name('entities.sentences.destroy');
    Route::get('/works/{work}/alignments', [LibraryController::class, 'workAlignments'])
        ->whereNumber('work')
        ->name('works.alignments.show');
    Route::get('/works/{work}/alignments/create', [LibraryController::class, 'createAlignment'])
        ->whereNumber('work')
        ->name('works.alignments.create');
    Route::post('/works/{work}/alignments', [LibraryController::class, 'storeAlignment'])
        ->whereNumber('work')
        ->name('works.alignments.store');

    // The language-first browse pages moved to the works Entities branch;
    // the flat /entities/{lang}/... entity routes are gone (ADR 0073).
    Route::redirect('/entities', '/works/entities');
    Route::redirect('/entities/{lang}', '/works/entities')->where('lang', '[a-z]{2}');
    // Illustration files live on the private local disk; this route is the
    // only way they leave it — gated by the owning entity's read access.
    Route::get('/illustrations/{sentence}', [EntityIllustrationController::class, 'show'])
        ->whereNumber('sentence')
        ->name('illustrations.show');
    // The alignment editor is work-nested too (ADR 0072): both of the pair's
    // entities belong to one work, and the JSON editor API shares the prefix.
    // The old flat /alignments/{id} routes are gone without redirects.
    Route::get('/works/{work}/alignments/{entityMatch}/edit', [AlignmentController::class, 'show'])
        ->whereNumber('work')
        ->whereNumber('entityMatch')
        ->name('works.alignments.edit');

    Route::get('/works/{work}/alignments/{entityMatch}/rows', [AlignmentEditorController::class, 'rows'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::get('/works/{work}/alignments/{entityMatch}/unmatched', [AlignmentEditorController::class, 'unmatched'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::get('/works/{work}/alignments/{entityMatch}/needs-review', [AlignmentEditorController::class, 'needsReview'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/refine', [AlignmentEditorController::class, 'refine'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/rows', [AlignmentEditorController::class, 'storeRow'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::delete('/works/{work}/alignments/{entityMatch}/rows/{meaningMatch}', [AlignmentEditorController::class, 'destroyRow'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/rows/{meaningMatch}/approve', [AlignmentEditorController::class, 'approveRow'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/rows/{meaningMatch}/disapprove', [AlignmentEditorController::class, 'disapproveRow'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/sentences', [AlignmentEditorController::class, 'storeSentence'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::post('/works/{work}/alignments/{entityMatch}/sentences/move', [AlignmentEditorController::class, 'moveSentence'])
        ->whereNumber('work')->whereNumber('entityMatch');
    Route::patch('/works/{work}/alignments/{entityMatch}/sentences/{sentence}', [AlignmentEditorController::class, 'updateSentence'])
        ->whereNumber('work')->whereNumber('entityMatch')->whereNumber('sentence');
    Route::delete('/works/{work}/alignments/{entityMatch}/sentences/{sentence}', [AlignmentEditorController::class, 'unlinkSentence'])
        ->whereNumber('work')->whereNumber('entityMatch')->whereNumber('sentence');
    Route::delete('/works/{work}/alignments/{entityMatch}/unmatched/{sentence}', [AlignmentEditorController::class, 'destroyUnmatched'])
        ->whereNumber('work')->whereNumber('entityMatch')->whereNumber('sentence');
    // Practice → Simulator: the standalone page with the alignment picker;
    // the pinned route below stays the deep-link entry from alignment cards.
    Route::get('/simulator', [SimulatorController::class, 'simulator'])
        ->name('bilinguals.simulator');

    // A match pinned from its alignment card: no text selector — the URL
    // names the match (the pair itself carries both languages).
    Route::get('/bilinguals/simulator/{entityMatch}', [SimulatorController::class, 'simulatorForMatch'])
        ->whereNumber('entityMatch')
        ->name('bilinguals.simulator.forMatch');
    Route::post('/text', [SimulatorController::class, 'text']);
    // The AI answers are the reading surfaces' shared endpoints (the word
    // popup's Context explanation serves the reader too); paths, names and
    // throttle are unchanged — only the controller moved.
    Route::post('/ai/question', [ReadingAiController::class, 'askAi'])->name('ai.question')->middleware('throttle:20,1');
    Route::post('/ai/question/stream', [ReadingAiController::class, 'askAiStreamed'])->name('ai.question.stream')->middleware('throttle:20,1');
    Route::post('/ai/word-explain', [ReadingAiController::class, 'explainWord'])->name('ai.word-explain')->middleware('throttle:20,1');
});
