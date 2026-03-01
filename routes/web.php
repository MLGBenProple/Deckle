<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => \Inertia\Inertia::render('Welcome'))->name('welcome');

Route::get('/play', [\App\Http\Controllers\CommanderTournamentController::class, 'daily'])
    ->name('commander-tournament');

Route::get('/play/hard', [\App\Http\Controllers\CommanderTournamentController::class, 'hardDaily'])
    ->name('commander-tournament.hard');

Route::get('/play/previous', [\App\Http\Controllers\CommanderTournamentController::class, 'previousDays'])
    ->name('commander-tournament.previous');

Route::get('/play/previous/{date}', [\App\Http\Controllers\CommanderTournamentController::class, 'showPreviousDay'])
    ->name('commander-tournament.previous.show');

Route::get('/play/previous/{date}/hard', [\App\Http\Controllers\CommanderTournamentController::class, 'showPreviousDay'])
    ->defaults('mode', 'hard')
    ->name('commander-tournament.previous.hard');
