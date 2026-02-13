<?php

namespace App\Services;

class DecklistService
{
    public function __construct(
        protected TopdeckService $topdeck,
        protected ScryfallService $scryfall,
    ) {}

    public function parseDecklist(string $raw): array
    {
        $decklist = [];
        $lines = explode('\n', $raw);
        $currentSection = 'Mainboard';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^~~(.+)~~$/', $line, $matches)) {
                $currentSection = trim($matches[1]);
                continue;
            }
            if (preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
                $decklist[$currentSection][] = [
                    'quantity' => (int) $matches[1],
                    'name' => explode(' // ', stripslashes($matches[2]))[0],
                ];
            }
        }

        return $decklist;
    }

    public const SECTION_ORDER = ['Commanders', 'Creatures', 'Planeswalkers', 'Battles', 'Instants', 'Sorceries', 'Enchantments', 'Artifacts', 'Lands', 'Other'];

    public function groupByType(array $mainboard): array
    {
        $cardNames = array_map(fn ($card) => $card['name'], $mainboard);
        $typeMap = $this->scryfall->getCardTypes($cardNames);

        $grouped = [];
        foreach ($mainboard as $card) {
            $category = $typeMap[$card['name']] ?? 'Other';
            $grouped[$category][] = $card;
        }

        return $this->sortSections($grouped);
    }

    public function sortSections(array $decklist): array
    {
        $sorted = [];
        foreach (self::SECTION_ORDER as $section) {
            if (isset($decklist[$section])) {
                $sorted[$section] = $decklist[$section];
            }
        }

        return $sorted;
    }

    public function buildDecklist(string $rawDecklist): array
    {
        $parsed = $this->parseDecklist($rawDecklist);

        $decklist = [];
        if (!empty($parsed['Commanders'])) {
            $decklist['Commanders'] = $parsed['Commanders'];
        }

        if (!empty($parsed['Mainboard'])) {
            $decklist = array_merge($decklist, $this->groupByType($parsed['Mainboard']));
        }

        return $decklist;
    }

    public function fetchRandomGame(): array
    {
        $tournament = $this->topdeck->getRandomCommanderTournament();

        $decklist = [];
        if (!empty($tournament['player_decklist'])) {
            $decklist = $this->buildDecklist($tournament['player_decklist']);
        }

        return [
            'tournament_name' => $tournament['tournament_name'] ?? null,
            'player_name' => $tournament['player_name'] ?? null,
            'decklist' => $decklist,
        ];
    }
}
