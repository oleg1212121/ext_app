<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Test;
use App\Livewire\Crossword;
use App\Livewire\WordsSearch;
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

Route::get('/pepe', WordsSearch::class);
Route::get('/test', [Test::class, 'test']);
Route::get('/crossword', [Test::class, 'crossword']);
Route::get('/reader', [Test::class, 'reader']);
Route::get('/get-crossword', [Test::class, 'getCrossword']);
Route::get('/crossword2', Crossword::class);

