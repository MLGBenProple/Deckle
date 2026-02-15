<?php

namespace App\Console\Commands;

use App\Models\DailyGame;
use App\Services\DecklistService;
use Illuminate\Console\Command;

class GenerateDailyGame extends Command
{
    protected $signature = 'daily-game:generate {date? : The date to generate for (YYYY-MM-DD, defaults to tomorrow)}';

    protected $description = 'Generate a daily game puzzle for the given date';

    public function handle(DecklistService $decklistService): int
    {
        $date = $this->argument('date')
            ? \Carbon\Carbon::parse($this->argument('date'))
            : now()->addDay();

        $dateString = $date->toDateString();

        $modes = ['normal', 'hard'];
        $allExisted = true;

        foreach ($modes as $mode) {
            if (DailyGame::where('date', $dateString)->where('mode', $mode)->exists()) {
                $this->info("Daily game ({$mode}) for {$dateString} already exists. Skipping.");
                continue;
            }

            $allExisted = false;
            $this->info("Generating {$mode} daily game for {$dateString}...");

            $game = $decklistService->fetchRandomGame();

            if (empty($game['decklist'])) {
                $this->error("Failed to fetch a valid decklist for {$mode} mode.");
                return self::FAILURE;
            }

            DailyGame::create([
                'date' => $dateString,
                'mode' => $mode,
                'tournament_name' => $game['tournament_name'],
                'player_name' => $game['player_name'],
                'player_standing' => $game['player_standing'],
                'total_participants' => $game['total_participants'],
                'decklist' => $game['decklist'],
            ]);

            $this->info("Daily game ({$mode}) for {$dateString} created successfully.");
        }

        if ($allExisted) {
            $this->info("All daily games for {$dateString} already exist.");
        }

        return self::SUCCESS;
    }
}
