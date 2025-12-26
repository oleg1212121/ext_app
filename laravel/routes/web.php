<?php

use App\Http\Controllers\BilingualsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Test;
use Illuminate\Support\Facades\Route;

// Public route - welcome page only
Route::get('/', function () {
    return view('welcome');
});

// Authentication routes (login, register, etc.)
require __DIR__.'/auth.php';

// All other routes require authentication
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware('verified')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/test', [Test::class, 'test']);
    Route::get('/crossword', [Test::class, 'crossword'])->name('crossword');
    Route::get('/reader', [Test::class, 'reader'])->name('reader');
    Route::get('/bilinguals/en/ru/simulator', [BilingualsController::class, 'simulator'])->name('bilinguals.simulator');
    Route::post('/get-crossword', [Test::class, 'getCrossword']);
    Route::get('/get-texts', [Test::class, 'getTexts']);
    Route::post('/word/upvote', [Test::class, 'upvote']);
    Route::post('/word/acknowledge', [Test::class, 'acknowledge']);
    Route::post('/word/dismiss', [Test::class, 'dismiss']);
    Route::post('/word/ask-ai/', [Test::class, 'askAI']);
    Route::post('/get-texts', [BilingualsController::class, 'getTexts']);
    Route::post('/text', [BilingualsController::class, 'text']);
    Route::post('/ai/question', [BilingualsController::class, 'askAi']);
    Route::post('/dictionary/selection/save', [BilingualsController::class, 'selectionSave']);
    Route::post('/dictionary/interactions/save', [BilingualsController::class, 'interactionsSave']);
});

