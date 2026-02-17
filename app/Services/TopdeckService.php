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

    /**
     * Generate HTTP headers required for Topdeck.gg API authentication.
     * 
     * Topdeck.gg uses API key-based authentication via the Authorization header.
     * This method centralizes header generation to ensure consistent authentication
     * across all API requests.
     * 
     * @return array HTTP headers for Topdeck API requests
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
        ];
    }

    /**
     * Perform a GET request to the Topdeck.gg API with automatic retry handling.
     * 
     * This method provides a simplified interface for GET requests while leveraging
     * the comprehensive retry logic implemented in makeRequestWithRetry().
     * All GET requests benefit from timeout handling, connection error recovery,
     * and exponential backoff retry strategies.
     * 
     * @param string $endpoint API endpoint path (e.g., '/v2/tournaments/12345')
     * @param array $params Query parameters to append to the request URL
     * @return array Decoded JSON response from the API
     * @throws \Exception When all retry attempts are exhausted
     */
    public function get(string $endpoint, array $params = [])
    {
        return $this->makeRequestWithRetry('GET', $endpoint, $params);
    }

    /**
     * Perform a POST request to the Topdeck.gg API with automatic retry handling.
     * 
     * This method provides a simplified interface for POST requests with JSON payloads.
     * It's commonly used for Topdeck's search endpoints that require complex
     * filter criteria in the request body rather than query parameters.
     * 
     * @param string $endpoint API endpoint path (e.g., '/v2/tournaments')
     * @param array $data Request body data that will be JSON-encoded
     * @return array Decoded JSON response from the API
     * @throws \Exception When all retry attempts are exhausted
     */
    public function post(string $endpoint, array $data = [])
    {
        return $this->makeRequestWithRetry('POST', $endpoint, [], $data);
    }

    /**
     * Execute HTTP requests with comprehensive retry logic and error handling.
     * 
     * This method implements robust communication with the Topdeck.gg API,
     * which can be unreliable due to:
     * - High server load during tournament seasons
     * - Network intermittency 
     * - Rate limiting and temporary service unavailability
     * 
     * The retry strategy includes:
     * - Exponential backoff delays (1s, 2s, 4s) between attempts
     * - Distinction between connection errors and HTTP errors
     * - Comprehensive timeout handling (45s to accommodate Topdeck's 30s timeout)
     * - Up to 3 total attempts before permanent failure
     * 
     * Connection errors (network timeouts, DNS failures) and HTTP errors
     * (4xx/5xx responses) are both retried, as Topdeck's API can return
     * transient errors that resolve on retry.
     * 
     * @param string $method HTTP method ('GET' or 'POST')
     * @param string $endpoint API endpoint path
     * @param array $params Query parameters for GET requests
     * @param array $data Request body data for POST requests
     * @return array Decoded JSON response from successful API call
     * @throws \Exception When all retry attempts fail with detailed error context
     */
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

    /**
     * Retrieve a filtered list of competitive Commander tournaments from recent events.
     * 
     * This method implements a two-stage tournament discovery process for performance:
     * 1. Fetch lightweight tournament metadata (IDs and names only)
     * 2. Filter for competitive Commander events ("cedh" in tournament name)
     * 
     * The approach avoids downloading full tournament data (which includes all player
     * standings and decklists) until we've identified relevant tournaments.
     * 
     * Search criteria:
     * - Last 90 days of tournaments (balances freshness with availability)
     * - Magic: The Gathering game
     * - EDH (Commander) format
     * - Minimum 20 participants (ensures competitive environment)
     * - Tournament name contains "cedh" (case-insensitive)
     * 
     * This filtered list is used by getRandomCommanderTournament() to select
     * tournaments that likely contain high-quality competitive decklists.
     * 
     * @return array Filtered array of tournament metadata:
     *               [['TID' => 'tournament_id', 'tournamentName' => 'CEDH Event'], ...]
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
        $tournaments = $this->post('/v2/tournaments', $payload);
        if (empty($tournaments) || !is_array($tournaments)) {
            return [];
        }
        return array_filter($tournaments, function ($t) {
            return isset($t['tournamentName']) && stripos($t['tournamentName'], 'cedh') !== false;
        });
    }

    /**
     * Fetch complete tournament data including all player standings and decklists.
     * 
     * This method retrieves the full tournament dataset for a specific tournament ID,
     * including detailed player standings, deck information, and tournament metadata.
     * This is the second stage of tournament data access after identifying relevant
     * tournaments through getCedhTournamentList().
     * 
     * The response includes:
     * - Tournament metadata (name, date, format, etc.)
     * - Complete standings with player names and final positions
     * - Individual player deck information (when available)
     * - Deck URLs and imported deck data
     * 
     * This method is typically called only after tournament filtering to minimize
     * bandwidth usage and API rate limiting impacts.
     * 
     * @param string $tid Tournament ID from Topdeck.gg ('TID' field)
     * @return array Complete tournament data structure from Topdeck API
     * @throws \Exception When tournament cannot be retrieved after retries
     */
    public function getTournament(string $tid)
    {
        return $this->get("/v2/tournaments/{$tid}");
    }

    /**
     * Select and return a random competitive Commander tournament with valid deck data.
     * 
     * This method implements the core tournament selection logic for daily puzzle games.
     * It performs intelligent tournament sampling to find events with meaningful
     * decklist data suitable for gameplay.
     * 
     * The selection process:
     * 1. Retrieves filtered cEDH tournament list (last 90 days, 20+ players)
     * 2. Randomizes tournament order to ensure variety across game sessions
     * 3. Validates each tournament for required data completeness
     * 4. Downloads full tournament data only for promising candidates
     * 5. Filters to players with actual decklist content (not just URLs)
     * 6. Randomly selects one qualifying player
     * 7. Extracts both decklist text and original URLs when available
     * 
     * Validation includes:
     * - Tournament has valid ID and name
     * - Tournament contains standings data
     * - At least one player has non-empty decklist content
     * - Player names are present for attribution
     * 
     * The method tries up to 5 tournaments before giving up, balancing
     * success rate with API efficiency. It handles both text-based decklists
     * and Moxfield URL references commonly used in competitive play.
     * 
     * @return array Tournament game data ready for DailyGame creation:
     *               [
     *                 'tournament_name' => string,
     *                 'player_name' => string,
     *                 'player_decklist' => string, // Raw decklist text
     *                 'decklist_url' => string|null, // Original Moxfield/archive URL
     *                 'player_standing' => int,
     *                 'total_participants' => int,
     *                 'tournament_id' => string,
     *                 'attempt' => int // Debugging info
     *               ]
     *               Or error structure with 'debug_error' key when no valid tournaments found
     */
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
     * Validate that a tournament from the list has required data for processing.
     * 
     * This method performs preliminary validation on tournament metadata before
     * making expensive API calls to download full tournament data. It ensures
     * tournaments have the basic required fields and meet competitive criteria.
     * 
     * Validation checks:
     * - Tournament ID exists and is not empty (required for API calls)
     * - Tournament name exists and is meaningful (not whitespace)
     * - Name contains "cedh" indicating competitive Commander format
     * 
     * This validation prevents wasted API calls on tournaments that won't
     * contribute to game content generation.
     * 
     * @param array $tournament Tournament metadata from getCedhTournamentList()
     * @return bool True if tournament meets basic requirements for processing
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
     * Validate that downloaded tournament data contains complete standings information.
     * 
     * This method validates the full tournament data structure after API download,
     * ensuring it contains the standings information needed for player selection.
     * 
     * This validation occurs after the expensive API call but before attempting
     * to process player data, providing early failure detection for malformed
     * or incomplete tournament records.
     * 
     * Validation checks:
     * - Standings field exists and is an array
     * - Standings array is not empty (tournament has participants)
     * 
     * @param array $tournament Complete tournament data from getTournament()
     * @return bool True if tournament has usable standings data
     */
    protected function isValidTournamentData(array $tournament): bool
    {
        return isset($tournament['standings']) 
            && is_array($tournament['standings'])
            && !empty($tournament['standings']);
    }

    /**
     * Validate that a player record contains meaningful decklist data for gameplay.
     * 
     * This method filters tournament participants to those with actual deck content
     * suitable for puzzle game generation. Many tournament records contain players
     * without decklist submissions or with only placeholder data.
     * 
     * Validation ensures:
     * - Player has a decklist field with actual content (not just whitespace)
     * - Player has a meaningful name for proper attribution
     * - Decklist content is suitable for parsing and game generation
     * 
     * This validation is crucial for game quality, as puzzles require actual
     * deck compositions for players to analyze and guess tournament performance.
     * 
     * @param array $player Player data from tournament standings
     * @return bool True if player has usable decklist and identification data
     */
    protected function isValidPlayer(array $player): bool
    {
        return isset($player['decklist']) 
            && !empty(trim($player['decklist']))
            && isset($player['name']) 
            && !empty(trim($player['name']));
    }
}
