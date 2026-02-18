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
     *
     * @param array $excludedCommanders Array of commander keys to exclude (e.g., ['Tymna the Weaver / Kraum, Ludevic's Opus'])
     */
    public function getRandomCommanderTournament(array $excludedCommanders = [])
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

                $playerData = $this->extractPlayerData($tournament, $selectedTournament, $excludedCommanders);
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
     * 
     * Players are grouped by commander/commander pairing first, then a random
     * commander group is selected, and finally a random player from that group.
     * This ensures equal representation across commander archetypes regardless
     * of their popularity in the tournament.
     *
     * @param array $excludedCommanders Commander keys to exclude from selection
     */
    protected function extractPlayerData(array $tournament, array $selectedTournament, array $excludedCommanders = []): ?array
    {
        $allStandings = $tournament['standings'] ?? [];
        $withDecklists = array_filter($allStandings, [$this, 'isValidPlayer']);

        if (empty($withDecklists)) {
            return null;
        }

        // Group players by commander(s) to equalize selection across archetypes
        $commanderGroups = $this->groupPlayersByCommander($withDecklists);
        if (empty($commanderGroups)) {
            return null;
        }

        // Filter out excluded commanders
        if (!empty($excludedCommanders)) {
            $commanderGroups = array_filter(
                $commanderGroups,
                fn($key) => !in_array($key, $excludedCommanders, true),
                ARRAY_FILTER_USE_KEY
            );
            
            if (empty($commanderGroups)) {
                return null;
            }
        }
            
        // Select a random commander group, then a random player from that group
        $randomCommanderKey = array_rand($commanderGroups);
        $playersWithCommander = $commanderGroups[$randomCommanderKey];

        $player = $playersWithCommander[array_rand($playersWithCommander)];

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
            'commander_key' => $randomCommanderKey,
        ];
    }

    /**
     * Group players by their commander or commander pairing.
     * 
     * Extracts commander names from each player's decklist and creates
     * a normalized key (alphabetically sorted for partner pairs).
     * 
     * @param array $players Array of player standings with decklists
     * @return array Players grouped by commander key
     */
    protected function groupPlayersByCommander(array $players): array
    {
        $groups = [];
        
        foreach ($players as $player) {
            $commanders = $this->extractCommanders($player['decklist'] ?? '');
            
            if (empty($commanders)) {
                // Put players without identifiable commanders in an "Unknown" group
                $key = 'Unknown';
            } else {
                // Sort alphabetically to normalize partner pairs (Tymna/Kraum = Kraum/Tymna)
                sort($commanders);
                $key = implode(' / ', $commanders);
            }
            
            $groups[$key][] = $player;
        }
        
        return $groups;
    }

    /**
     * Extract commander names from a raw decklist string.
     * 
     * Parses the decklist looking for a ~~Commanders~~ section and
     * extracts card names from that section.
     * 
     * @param string $decklist Raw decklist text
     * @return array List of commander card names
     */
    protected function extractCommanders(string $decklist): array
    {
        $commanders = [];
        $lines = explode('\n', $decklist);
        $inCommanderSection = false;

        foreach ($lines as $line) {
            $line = trim($line);
            
            if (preg_match('/^~~(.+)~~$/', $line, $matches)) {
                $section = trim($matches[1]);
                $inCommanderSection = (strcasecmp($section, 'Commanders') === 0);
                continue;
            }
            
            if ($inCommanderSection && preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
                // Extract card name, handling double-faced cards
                $cardName = explode(' // ', stripslashes($matches[2]))[0];
                $commanders[] = trim($cardName);
            }
        }

        return $commanders;
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

    /**
     * Get a normalized commander key from a processed decklist array.
     * 
     * This method extracts commander names from a structured decklist
     * and returns a normalized key (alphabetically sorted for partner pairs).
     * Useful for comparing commanders across different decklists.
     * 
     * @param array $decklist Processed decklist with 'Commanders' section
     * @return string|null Normalized commander key or null if no commanders found
     */
    public function getCommanderKeyFromDecklist(array $decklist): ?string
    {
        if (empty($decklist['Commanders'])) {
            return null;
        }

        $commanders = array_map(
            fn($card) => $card['name'],
            $decklist['Commanders']
        );

        if (empty($commanders)) {
            return null;
        }

        sort($commanders);
        return implode(' / ', $commanders);
    }
}
