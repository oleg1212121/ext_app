<?php

use App\Http\Controllers\AlignmentController;
use App\Http\Controllers\AlignmentEditorController;
use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Http\Controllers\CrosswordController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\UiSettingsController;
use App\Http\Controllers\WordController;
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

    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/create', [LibraryController::class, 'createWork'])->name('library.create');
    Route::post('/library', [LibraryController::class, 'storeWork'])->name('library.store');
    Route::get('/library/{work}', [LibraryController::class, 'showWork'])
        ->whereNumber('work')
        ->name('library.show');
    Route::get('/library/{work}/entities/create', [LibraryController::class, 'createEntity'])
        ->whereNumber('work')
        ->name('library.entities.create');
    Route::post('/library/{work}/entities', [LibraryController::class, 'storeEntity'])
        ->whereNumber('work')
        ->name('library.entities.store');
    Route::get('/library/{work}/alignments/create', [LibraryController::class, 'createAlignment'])
        ->whereNumber('work')
        ->name('library.alignments.create');
    Route::post('/library/{work}/alignments', [LibraryController::class, 'storeAlignment'])
        ->whereNumber('work')
        ->name('library.alignments.store');

    // The language-first browse pages moved to the work-first Library
    Route::redirect('/entities', '/library');
    Route::redirect('/entities/{lang}', '/library')->where('lang', '[a-z]{2}');
    Route::get('/entities/{lang}/create', [EntityController::class, 'create'])
        ->where('lang', '[a-z]{2}')
        ->name('entities.create');
    Route::post('/entities/{lang}', [EntityController::class, 'store'])
        ->where('lang', '[a-z]{2}')
        ->name('entities.store');
    Route::get('/entities/{lang}/{entity}', [EntityController::class, 'show'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.show');
    Route::get('/entities/{lang}/{entity}/edit', [EntityController::class, 'edit'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.edit');
    Route::patch('/entities/{lang}/{entity}', [EntityController::class, 'update'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.update');
    Route::patch('/entities/{lang}/{entity}/approved', [EntityController::class, 'updateApproved'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.approved.update');
    Route::get('/entities/{lang}/{entity}/sentences', [EntityController::class, 'sentences'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.sentences');
    Route::post('/entities/{lang}/{entity}/sentences', [EntityController::class, 'storeSentence'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.sentences.store');
    Route::post('/entities/{lang}/{entity}/sentences/reorder', [EntityController::class, 'reorderSentences'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->name('entities.sentences.reorder');
    Route::patch('/entities/{lang}/{entity}/sentences/{sentence}', [EntityController::class, 'updateSentence'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->whereNumber('sentence')
        ->name('entities.sentences.update');
    Route::delete('/entities/{lang}/{entity}/sentences/{sentence}', [EntityController::class, 'destroySentence'])
        ->where('lang', '[a-z]{2}')
        ->whereNumber('entity')
        ->whereNumber('sentence')
        ->name('entities.sentences.destroy');
    // The global alignments browse pages moved under each work
    // (/library/{work}?tab=alignments); only the editor stays global.
    Route::get('/alignments/{entityMatch}', [AlignmentController::class, 'show'])
        ->whereNumber('entityMatch')
        ->name('alignments.show');

    Route::get('/alignments/{entityMatch}/rows', [AlignmentEditorController::class, 'rows']);
    Route::get('/alignments/{entityMatch}/unmatched', [AlignmentEditorController::class, 'unmatched']);
    Route::get('/alignments/{entityMatch}/needs-review', [AlignmentEditorController::class, 'needsReview']);
    Route::post('/alignments/{entityMatch}/rows', [AlignmentEditorController::class, 'storeRow']);
    Route::delete('/alignments/{entityMatch}/rows/{meaningMatch}', [AlignmentEditorController::class, 'destroyRow']);
    Route::post('/alignments/{entityMatch}/rows/{meaningMatch}/approve', [AlignmentEditorController::class, 'approveRow']);
    Route::post('/alignments/{entityMatch}/sentences', [AlignmentEditorController::class, 'storeSentence']);
    Route::post('/alignments/{entityMatch}/sentences/move', [AlignmentEditorController::class, 'moveSentence']);
    Route::patch('/alignments/{entityMatch}/sentences/{sentence}', [AlignmentEditorController::class, 'updateSentence'])->whereNumber('sentence');
    Route::delete('/alignments/{entityMatch}/sentences/{sentence}', [AlignmentEditorController::class, 'unlinkSentence'])->whereNumber('sentence');
    Route::delete('/alignments/{entityMatch}/unmatched/{sentence}', [AlignmentEditorController::class, 'destroyUnmatched'])->whereNumber('sentence');
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
    Route::post('/ai/question', [SimulatorController::class, 'askAi'])->name('ai.question')->middleware('throttle:20,1');
    Route::post('/ai/question/stream', [SimulatorController::class, 'askAiStreamed'])->name('ai.question.stream')->middleware('throttle:20,1');
    Route::post('/ai/word-explain', [SimulatorController::class, 'explainWord'])->name('ai.word-explain')->middleware('throttle:20,1');
    Route::patch('/ui-settings', [UiSettingsController::class, 'update'])->name('ui-settings.update');
});
