<?php

namespace App\Services;

class TopdeckService
{
    protected const MAX_TOURNAMENT_ATTEMPTS = 5;

    public function __construct(
        protected TopdeckHttpClient $client
    ) {}

    /**
     * Retrieve a filtered list of competitive Commander tournaments from recent events.
     */
    public function getCedhTournamentList()
    {
        $payload = [
            'last' => 90,
            'columns' => [],
            'game' => 'Magic: The Gathering',
            'format' => 'EDH',
            'participantMin' => 20,
        ];
        
        $tournaments = $this->client->post('/v2/tournaments', $payload);
        if (empty($tournaments) || !is_array($tournaments)) {
            return [];
        }
        
        return array_filter($tournaments, function ($t) {
            return isset($t['tournamentName']) && stripos($t['tournamentName'], 'cedh') !== false;
        });
    }

    /**
     * Fetch complete tournament data including all player standings and decklists.
     */
    public function getTournament(string $tid)
    {
        return $this->client->get("/v2/tournaments/{$tid}");
    }

    /**
     * Select and return a random competitive Commander tournament with valid deck data.
     */
    public function getRandomCommanderTournament()
    {
        $cedhTournaments = $this->getCedhTournamentList();
        if (empty($cedhTournaments)) {
            return $this->buildErrorResponse('No cEDH tournaments found with 40+ participants');
        }

        shuffle($cedhTournaments);
        $maxAttempts = min(self::MAX_TOURNAMENT_ATTEMPTS, count($cedhTournaments));

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $selectedTournament = $cedhTournaments[$attempt];
            
            if (!$this->isValidTournament($selectedTournament)) {
                continue;
            }

            try {
                $tournament = $this->getTournament($selectedTournament['TID']);
                
                if (!$this->isValidTournamentData($tournament)) {
                    continue;
                }

                $playerData = $this->extractPlayerData($tournament, $selectedTournament);
                if ($playerData) {
                    $playerData['attempt'] = $attempt + 1;
                    return $playerData;
                }
                
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch tournament {$selectedTournament['TID']}: " . $e->getMessage());
                continue;
            }
        }

        return $this->buildErrorResponse(
            "No tournaments with valid decklists found after trying {$maxAttempts} tournaments",
            array_slice($cedhTournaments, 0, $maxAttempts)
        );
    }

    /**
     * Extract player data from a tournament, handling decklist and URL parsing.
     */
    protected function extractPlayerData(array $tournament, array $selectedTournament): ?array
    {
        $allStandings = $tournament['standings'] ?? [];
        $withDecklists = array_filter($allStandings, [$this, 'isValidPlayer']);

        if (empty($withDecklists)) {
            return null;
        }

        $player = $withDecklists[array_rand($withDecklists)];
        $totalParticipants = count($allStandings);
        $playerStanding = $player['standing'] ?? null;
        
        [$decklist, $decklistUrl] = $this->extractDecklistData($player);
        
        return [
            'tournament_name' => $selectedTournament['tournamentName'],
            'player_name' => $player['name'] ?? null,
            'player_decklist' => $decklist,
            'decklist_url' => $decklistUrl,
            'player_standing' => $playerStanding,
            'total_participants' => $totalParticipants,
            'tournament_id' => $selectedTournament['TID'],
        ];
    }

    /**
     * Extract decklist content and URL from player data.
     */
    protected function extractDecklistData(array $player): array
    {
        $decklist = $player['decklist'] ?? null;
        $decklistUrl = null;
        
        if (isset($player['deckObj']['metadata']['importedFrom']) 
            && !empty($player['deckObj']['metadata']['importedFrom'])) {
            $decklistUrl = $player['deckObj']['metadata']['importedFrom'];
        } elseif ($decklist && (filter_var($decklist, FILTER_VALIDATE_URL) || str_starts_with($decklist, 'http'))) {
            $decklistUrl = $decklist;
            $decklist = null;
        }
        
        return [$decklist, $decklistUrl];
    }

    /**
     * Validate tournament has required data for processing.
     */
    protected function isValidTournament(array $tournament): bool
    {
        return isset($tournament['TID']) 
            && !empty($tournament['TID'])
            && isset($tournament['tournamentName']) 
            && !empty(trim($tournament['tournamentName']))
            && stripos($tournament['tournamentName'], 'cedh') !== false;
    }

    /**
     * Validate tournament data contains complete standings information.
     */
    protected function isValidTournamentData(array $tournament): bool
    {
        return isset($tournament['standings']) 
            && is_array($tournament['standings'])
            && !empty($tournament['standings']);
    }

    /**
     * Validate player record contains meaningful decklist data.
     */
    protected function isValidPlayer(array $player): bool
    {
        return isset($player['decklist']) 
            && !empty(trim($player['decklist']))
            && isset($player['name']) 
            && !empty(trim($player['name']));
    }

    /**
     * Build standardized error response structure.
     */
    protected function buildErrorResponse(string $message, array $additionalData = []): array
    {
        $response = ['debug_error' => $message];
        
        if (!empty($additionalData)) {
            $response['tournaments_checked'] = $additionalData;
        }
        
        return $response;
    }
}
