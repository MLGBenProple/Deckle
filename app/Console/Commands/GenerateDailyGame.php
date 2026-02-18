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
        $maxCommandRetries = 3;

        foreach ($modes as $mode) {
            if (DailyGame::where('date', $dateString)->where('mode', $mode)->exists()) {
                $this->info("Daily game ({$mode}) for {$dateString} already exists. Skipping.");
                continue;
            }

            $allExisted = false;
            $this->info("Generating {$mode} daily game for {$dateString}...");

            $game = $this->fetchGameWithRetry($decklistService, $mode, $maxCommandRetries, $date);

            if (empty($game['decklist'])) {
                $this->error("Failed to fetch a valid decklist for {$mode} mode after {$maxCommandRetries} attempts.");
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
                'decklist_url' => $game['decklist_url'],
            ]);

            $this->info("Daily game ({$mode}) for {$dateString} created successfully.");
            
            if (!empty($game['commander_key'])) {
                $this->info("Commander: {$game['commander_key']}");
            }
        }

        if ($allExisted) {
            $this->info("All daily games for {$dateString} already exist.");
        }

        return self::SUCCESS;
    }

    protected function fetchGameWithRetry(DecklistService $decklistService, string $mode, int $maxRetries, \Carbon\Carbon $date): array
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->info("Attempt {$attempt}/{$maxRetries} to fetch {$mode} game...");
                $game = $decklistService->fetchRandomGame($date);
                
                if (!empty($game['decklist'])) {
                    $this->info("Successfully fetched {$mode} game on attempt {$attempt}.");
                    return $game;
                }
                
                $this->warn("Attempt {$attempt}: Received empty decklist, retrying...");
                
            } catch (\Exception $e) {
                $this->warn("Attempt {$attempt} failed: " . $e->getMessage());
                
                if ($attempt === $maxRetries) {
                    $this->error("All {$maxRetries} attempts failed. Last error: " . $e->getMessage());
                }
            }
            
            // Wait before retrying (exponential backoff)
            if ($attempt < $maxRetries) {
                $waitTime = pow(2, $attempt - 1) * 5; // 5, 10, 20 seconds
                $this->info("Waiting {$waitTime} seconds before retry...");
                sleep($waitTime);
            }
        }
        
        return ['decklist' => []];
    }
}
