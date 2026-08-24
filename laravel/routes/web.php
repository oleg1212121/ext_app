<?php

use App\Http\Controllers\AlignmentController;
use App\Http\Controllers\AlignmentEditorController;
use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Http\Controllers\BilingualsController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\Test;
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

    Route::get('/test', [Test::class, 'test']);
    Route::get('/crossword', [Test::class, 'crossword'])->name('crossword');
    Route::redirect('/crossword-react', '/crossword-react/en');
    Route::get('/crossword-react/{lang}', [Test::class, 'crosswordReact'])
        ->where('lang', 'en|ru')
        ->name('crossword.react');
    Route::get('/reader', [Test::class, 'reader'])->name('reader');
    Route::redirect('/reader-react', '/reader-react/en');
    Route::get('/reader-react/{lang}/{entityId}', [ReaderController::class, 'show'])
        ->where('lang', 'en|ru')
        ->whereNumber('entityId')
        ->name('reader.react');
    Route::get('/reader-react/{lang}', [ReaderController::class, 'index'])
        ->where('lang', 'en|ru')
        ->name('reader.react.index');

    Route::get('/entities', [EntityController::class, 'index'])->name('entities.index');
    Route::get('/entities/{lang}', [EntityController::class, 'list'])
        ->where('lang', '[a-z]{2}')
        ->name('entities.list');
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
    Route::get('/alignments', [AlignmentController::class, 'index'])->name('alignments.index');
    Route::get('/alignments/{entityMatch}', [AlignmentController::class, 'show'])->name('alignments.show');

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
    Route::get('/bilinguals/en/ru/simulator', [SimulatorController::class, 'simulator'])->name('bilinguals.simulator');
    Route::post('/get-crossword', [Test::class, 'getCrossword']);
    Route::get('/get-texts', [Test::class, 'getTexts']);
    Route::post('/word/upvote', [Test::class, 'upvote']);
    Route::post('/word/acknowledge', [Test::class, 'acknowledge']);
    Route::post('/word/dismiss', [Test::class, 'dismiss']);
    Route::post('/word/ask-ai/', [Test::class, 'askAI']);
    Route::post('/get-texts', [BilingualsController::class, 'getTexts']);
    Route::post('/text', [SimulatorController::class, 'text']);
    Route::post('/ai/question', [SimulatorController::class, 'askAi'])->name('ai.question');
    Route::post('/ai/question/stream', [SimulatorController::class, 'askAiStreamed'])->name('ai.question.stream');
    Route::post('/dictionary/selection/save', [BilingualsController::class, 'selectionSave']);
    Route::post('/dictionary/interactions/save', [BilingualsController::class, 'interactionsSave']);
});
