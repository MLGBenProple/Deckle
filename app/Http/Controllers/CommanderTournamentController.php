<?php

namespace App\Http\Controllers;

use App\Models\DailyGame;
use App\Services\DecklistService;
use Inertia\Inertia;

class CommanderTournamentController extends Controller
{
    public function daily(DecklistService $decklistService)
    {
        $game = $this->resolveGame($decklistService, 'normal');

        return Inertia::render('CommanderTournament', [
            'tournamentName' => $game->tournament_name,
            'playerName' => $game->player_name,
            'playerStanding' => $game->player_standing,
            'totalParticipants' => $game->total_participants,
            'decklist' => $decklistService->sortSections($game->decklist),
        ]);
    }

    public function hardDaily(DecklistService $decklistService)
    {
        $game = $this->resolveGame($decklistService, 'hard');

        return Inertia::render('CommanderTournament', [
            'tournamentName' => $game->tournament_name,
            'playerName' => $game->player_name,
            'playerStanding' => $game->player_standing,
            'totalParticipants' => $game->total_participants,
            'decklist' => $decklistService->sortSections($game->decklist),
            'hardMode' => true,
        ]);
    }

    private function resolveGame(DecklistService $decklistService, string $mode): DailyGame
    {
        $game = DailyGame::forToday()->mode($mode)->first();

        if (! $game) {
            $data = $decklistService->fetchRandomGame();

            $game = DailyGame::create([
                'date' => today(),
                'mode' => $mode,
                'tournament_name' => $data['tournament_name'],
                'player_name' => $data['player_name'],
                'player_standing' => $data['player_standing'],
                'total_participants' => $data['total_participants'],
                'decklist' => $data['decklist'],
            ]);
        }

        return $game;
    }
}
