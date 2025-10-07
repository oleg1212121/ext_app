<?php

use App\Http\Controllers\BilingualsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Test;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::get('/test', [Test::class, 'test']);
Route::get('/crossword', [Test::class, 'crossword']);
Route::get('/reader', [Test::class, 'reader']);
Route::get('/bilinguals/en/ru/simulator', [BilingualsController::class, 'simulator']);
Route::post('/get-crossword', [Test::class, 'getCrossword']);
Route::get('/get-textes', [Test::class, 'getTextes']);
Route::post('/word/upvote', [Test::class, 'upvote']);
Route::post('/word/acknowledge', [Test::class, 'acknowledge']);
Route::post('/word/dismiss', [Test::class, 'dismiss']);
Route::post('/word/ask-ai/', [Test::class, 'askAI']);
Route::post('/get-textes', [BilingualsController::class, 'getTextes']);
Route::post('/text', [BilingualsController::class, 'text']);
Route::post('/ai/question', [BilingualsController::class, 'askAi']);
Route::post('/dictionary/selection/save', [BilingualsController::class, 'selectionSave']);
Route::post('/dictionary/interactions/save', [BilingualsController::class, 'interactionsSave']);

