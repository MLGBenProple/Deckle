<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => \Inertia\Inertia::render('Welcome'))->name('welcome');

Route::get('/play', [\App\Http\Controllers\CommanderTournamentController::class, 'daily'])
    ->name('commander-tournament');

Route::get('/play/hard', [\App\Http\Controllers\CommanderTournamentController::class, 'hardDaily'])
    ->name('commander-tournament.hard');
