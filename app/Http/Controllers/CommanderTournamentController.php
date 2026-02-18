<?php

namespace App\Http\Controllers;

use App\Models\DailyGame;
use App\Services\DecklistService;
use Inertia\Inertia;

class CommanderTournamentController extends Controller
{
    /**
     * Display the daily Commander tournament puzzle game in normal difficulty mode.
     */
    public function daily(DecklistService $decklistService)
    {
        return $this->showDailyGame($decklistService, 'normal');
    }

    /**
     * Display the daily Commander tournament puzzle game in hard difficulty mode.
     */
    public function hardDaily(DecklistService $decklistService)
    {
        return $this->showDailyGame($decklistService, 'hard');
    }

    /**
     * Display the daily Commander tournament puzzle game for the specified difficulty mode.
     * 
     * The method handles:
     * - Daily puzzle generation and caching (one puzzle per day per mode)
     * - Tournament data formatting for frontend display
     * - Deck organization using standard Magic card type ordering
     * - React/Inertia.js data passing for interactive gameplay
     * 
     * Hard mode uses a separate daily game instance, allowing players to
     * complete both normal and hard versions of the daily puzzle if desired.
     * 
     * @param DecklistService $decklistService Service for deck processing and formatting
     * @param string $mode Difficulty mode ('normal' or 'hard')
     * @return \Inertia\Response Inertia.js response with tournament puzzle data
     */
    protected function showDailyGame(DecklistService $decklistService, string $mode)
    {
        $game = $this->resolveGame($decklistService, $mode);

        $data = [
            'tournamentName' => $game->tournament_name,
            'tournamentId' => $game->tournament_id,
            'playerName' => $game->player_name,
            'playerStanding' => $game->player_standing,
            'totalParticipants' => $game->total_participants,
            'decklist' => $decklistService->sortSections($game->decklist),
            'decklistUrl' => $game->decklist_url,
            'gameDate' => $game->date->format('F j, Y'),
        ];

        if ($mode === 'hard') {
            $data['hardMode'] = true;
        }

        return Inertia::render('CommanderTournament', $data);
    }

    /**
     * Resolve or create a daily game instance for the specified difficulty mode.
     * 
     * The caching strategy:
     * 1. Check if today's puzzle already exists for the specified mode
     * 2. If found, return the existing puzzle (ensuring consistency)
     * 3. If not found, generate a new puzzle using DecklistService
     * 4. Store the new puzzle in the database for future requests
     * 5. Return the puzzle data for immediate use
     * 
     * This approach guarantees:
     * - All players see the same daily puzzle
     * - Puzzles persist across server restarts and deployments  
     * - No duplicate puzzle generation for the same day/mode
     * - Tournament API is called only once per day per mode
     * 
     * The method supports multiple difficulty modes ('normal', 'hard') with
     * completely independent puzzle instances, allowing players to attempt
     * both difficulties if desired.
     * 
     * @param DecklistService $decklistService Service for fetching and processing tournament data
     * @param string $mode Difficulty mode identifier ('normal' or 'hard')
     * @return DailyGame Cached or newly created daily game instance with complete tournament data
     */
    private function resolveGame(DecklistService $decklistService, string $mode): DailyGame
    {
        $game = DailyGame::forToday()->mode($mode)->first();

        if (! $game) {
            $data = $decklistService->fetchRandomGame();

            $game = DailyGame::create([
                'date' => today(),
                'mode' => $mode,
                'tournament_name' => $data['tournament_name'],
                'tournament_id' => $data['tournament_id'],
                'player_name' => $data['player_name'],
                'player_standing' => $data['player_standing'],
                'total_participants' => $data['total_participants'],
                'decklist' => $data['decklist'],
                'decklist_url' => $data['decklist_url'],
            ]);
        }

        return $game;
    }
}
