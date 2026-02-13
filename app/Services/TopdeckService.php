<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TopdeckService
{
    protected string $baseUrl = 'https://topdeck.gg/api';
    protected string $apiKey;

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
        $url = $this->baseUrl . $endpoint;
        $response = Http::withHeaders($this->getHeaders())
            ->get($url, $params);
        $response->throw();
        return $response->json();
    }

    public function post(string $endpoint, array $data = [])
    {
        $url = $this->baseUrl . $endpoint;
        $response = Http::withHeaders($this->getHeaders())
            ->post($url, $data);
        $response->throw();
        return $response->json();
    }

    // Step 1: Fetch lightweight tournament list (IDs and names only)
    public function getCedhTournamentList()
    {
        $payload = [
            'last' => 90,
            'columns' => [],
            'game' => 'Magic: The Gathering',
            'format' => 'EDH',
            'participantMin' => 40,
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

        $selected = $cedhTournaments[array_rand($cedhTournaments)];
        $tournament = $this->getTournament($selected['TID']);

        // Filter to players that have a decklist
        $withDecklists = array_filter($tournament['standings'] ?? [], function ($player) {
            return !empty($player['decklist']);
        });

        if (empty($withDecklists)) {
            return [
                'tournament_name' => $selected['tournamentName'],
                'player_name' => null,
                'player_decklist' => null,
            ];
        }

        $player = $withDecklists[array_rand($withDecklists)];
        return [
            'tournament_name' => $selected['tournamentName'],
            'player_name' => $player['name'] ?? null,
            'player_decklist' => $player['decklist'] ?? null,
        ];
    }
}
