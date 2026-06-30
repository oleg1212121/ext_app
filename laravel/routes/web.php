<?php

use App\Http\Controllers\AlignmentController;
use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Http\Controllers\BilingualsController;
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

// All other routes require authentication
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        //        return view('dashboard');
        return Inertia::render('Dashboard');
    })->middleware('verified')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

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
    Route::get('/alignments', [AlignmentController::class, 'index'])->name('alignments.index');
    Route::get('/alignments/{entityMatch}', [AlignmentController::class, 'show'])->name('alignments.show');
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
    Route::post('/dictionary/selection/save', [BilingualsController::class, 'selectionSave']);
    Route::post('/dictionary/interactions/save', [BilingualsController::class, 'interactionsSave']);
});
