<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TopdeckService
{
    protected string $baseUrl = 'https://topdeck.gg/api';
    protected string $apiKey;
    protected int $timeout = 45; // 45 second timeout to handle Topdeck's 30s timeout
    protected int $maxRetries = 3;

    public function __construct()
    {
        $this->apiKey = config('services.topdeck.key');
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
        ];
    }

    public function get(string $endpoint, array $params = [])
    {
        return $this->makeRequestWithRetry('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $data = [])
    {
        return $this->makeRequestWithRetry('POST', $endpoint, [], $data);
    }

    protected function makeRequestWithRetry(string $method, string $endpoint, array $params = [], array $data = [])
    {
        $url = $this->baseUrl . $endpoint;
        $attempts = 0;
        
        while ($attempts < $this->maxRetries) {
            try {
                $httpClient = Http::withHeaders($this->getHeaders())
                    ->timeout($this->timeout);
                
                if ($method === 'GET') {
                    $response = $httpClient->get($url, $params);
                } else {
                    $response = $httpClient->post($url, $data);
                }
                
                $response->throw();
                return $response->json();
                
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $attempts++;
                if ($attempts >= $this->maxRetries) {
                    throw new \Exception("Failed to connect to Topdeck API after {$this->maxRetries} attempts: " . $e->getMessage());
                }
                // Wait before retrying (exponential backoff)
                sleep(pow(2, $attempts - 1));
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $attempts++;
                if ($attempts >= $this->maxRetries) {
                    throw new \Exception("Topdeck API request failed after {$this->maxRetries} attempts: " . $e->getMessage());
                }
                sleep(pow(2, $attempts - 1));
            }
        }
        
        throw new \Exception("Unexpected error in API request retry logic");
    }

    // Step 1: Fetch lightweight tournament list (IDs and names only)
    public function getCedhTournamentList()
    {
        $payload = [
            'last' => 90,
            'columns' => [],
            'game' => 'Magic: The Gathering',
            'format' => 'EDH',
            'participantMin' => 20,
        ];
        $tournaments = $this->post('/v2/tournaments', $payload);
        if (empty($tournaments) || !is_array($tournaments)) {
            return [];
        }
        return array_filter($tournaments, function ($t) {
            return isset($t['tournamentName']) && stripos($t['tournamentName'], 'cedh') !== false;
        });
    }

    // Step 2: Fetch full tournament details by ID
    public function getTournament(string $tid)
    {
        return $this->get("/v2/tournaments/{$tid}");
    }

    // Select a random cEDH tournament and return a random player's decklist
    public function getRandomCommanderTournament()
    {
        $cedhTournaments = $this->getCedhTournamentList();
        if (empty($cedhTournaments)) {
            return [
                'debug_error' => 'No cEDH tournaments found with 40+ participants',
            ];
        }

        // Shuffle tournaments to try different ones if needed
        shuffle($cedhTournaments);
        $maxTournamentAttempts = min(5, count($cedhTournaments)); // Try up to 5 tournaments

        for ($attempt = 0; $attempt < $maxTournamentAttempts; $attempt++) {
            $selectedTournament = $cedhTournaments[$attempt];
            
            // Validate tournament has required data
            if (!$this->isValidTournament($selectedTournament)) {
                continue; // Try next tournament
            }

            try {
                $tournament = $this->getTournament($selectedTournament['TID']);
                // Validate tournament details
                if (!$this->isValidTournamentData($tournament)) {
                    continue; // Try next tournament
                }

                // Filter to players that have a decklist
                $allStandings = $tournament['standings'] ?? [];
                $withDecklists = array_filter($allStandings, function ($player) {
                    return $this->isValidPlayer($player);
                });

                if (!empty($withDecklists)) {
                    $player = $withDecklists[array_rand($withDecklists)];
                    $totalParticipants = count($allStandings);
                    $playerStanding = $player['standing'] ?? null;
                    
                    // Check for Moxfield URL in deckObj metadata
                    $decklist = $player['decklist'] ?? null;
                    $decklistUrl = null;
                    
                    // First check if there's a Moxfield URL in deckObj.metadata.importedFrom
                    if (isset($player['deckObj']['metadata']['importedFrom']) 
                        && !empty($player['deckObj']['metadata']['importedFrom'])) {
                        $decklistUrl = $player['deckObj']['metadata']['importedFrom'];
                    }
                    // Fallback: check if decklist field itself is a URL
                    elseif ($decklist && (filter_var($decklist, FILTER_VALIDATE_URL) || str_starts_with($decklist, 'http'))) {
                        $decklistUrl = $decklist;
                        $decklist = null; // Don't return the URL as text content
                    }
                    
                    return [
                        'tournament_name' => $selectedTournament['tournamentName'],
                        'player_name' => $player['name'] ?? null,
                        'player_decklist' => $decklist,
                        'decklist_url' => $decklistUrl,
                        'player_standing' => $playerStanding,
                        'total_participants' => $totalParticipants,
                        'tournament_id' => $selectedTournament['TID'],
                        'attempt' => $attempt + 1, // For debugging
                    ];
                }
            } catch (\Exception $e) {
                // Log the error but continue trying other tournaments
                \Log::warning("Failed to fetch tournament {$selectedTournament['TID']}: " . $e->getMessage());
                continue;
            }
        }

        // If we get here, none of the tournaments had valid decklists
        return [
            'debug_error' => "No tournaments with valid decklists found after trying {$maxTournamentAttempts} tournaments",
            'tournaments_checked' => array_slice($cedhTournaments, 0, $maxTournamentAttempts),
        ];
    }

    /**
     * Validate that a tournament from the list has required data
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
     * Validate that tournament details are complete
     */
    protected function isValidTournamentData(array $tournament): bool
    {
        return isset($tournament['standings']) 
            && is_array($tournament['standings'])
            && !empty($tournament['standings']);
    }

    /**
     * Validate that a player has a meaningful decklist
     */
    protected function isValidPlayer(array $player): bool
    {
        return isset($player['decklist']) 
            && !empty(trim($player['decklist']))
            && isset($player['name']) 
            && !empty(trim($player['name']));
    }
}
