<?php

namespace App\Services;

use App\Models\DailyGame;

class DecklistService
{
    public function __construct(
        protected TopdeckService $topdeck,
        protected ScryfallService $scryfall,
    ) {}

    /**
     * Parse a raw decklist string into a structured array format.
     * 
     * This method processes decklist text using common MTG decklist formats,
     * supporting both mainboard and sideboard sections. It handles various
     * formatting conventions used by tournament organizers and deckbuilding tools.
     * 
     * The parser recognizes:
     * - Section headers wrapped in tildes: ~~Mainboard~~, ~~Sideboard~~, ~~Commanders~~
     * - Card entries in "quantity cardname" format: "4 Lightning Bolt"
     * - Double-faced card names (extracts front face only)
     * - Empty lines and whitespace (ignored)
     * 
     * @param string $raw Raw decklist text from tournament data or user input
     * @return array Structured decklist with sections as keys:
     *               ['Mainboard' => [['quantity' => 4, 'name' => 'Lightning Bolt']], ...]
     *               ['Sideboard' => [...], 'Commanders' => [...], etc.]
     */
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

    /**
     * Standard section ordering for Magic: The Gathering decklists.
     * 
     * This constant defines the canonical display order for deck sections,
     * following competitive Magic conventions. The order prioritizes:
     * - Commanders first (for Commander format identification)
     * - Permanents by strategic importance (creatures, planeswalkers, etc.)
     * - Spells by timing (instants before sorceries)
     * - Utility cards (enchantments, artifacts) 
     * - Mana base (lands)
     * - Edge cases (other/unknown types)
     * 
     * This ordering is used by deck visualization tools and tournament software
     * to provide consistent, readable deck presentations.
     */
    public const SECTION_ORDER = ['Commanders', 'Creatures', 'Planeswalkers', 'Battles', 'Instants', 'Sorceries', 'Enchantments', 'Artifacts', 'Lands', 'Other'];

    /**
     * Group mainboard cards by their Magic: The Gathering card types.
     * 
     * This method takes a flat list of mainboard cards and organizes them
     * into type-based categories for better deck analysis and presentation.
     * It leverages the Scryfall API to determine accurate card types,
     * handling edge cases like:
     * - Multi-type cards ("Artifact Creature" becomes "Creatures")
     * - Double-faced cards (uses front face type)
     * - Unknown/new card types (defaults to "Other")
     * 
     * The grouping enables features like mana curve analysis, deck composition
     * statistics, and organized deck display interfaces.
     * 
     * @param array $mainboard Array of card objects: [['quantity' => int, 'name' => string], ...]
     * @return array Cards grouped by type in standardized order:
     *               ['Creatures' => [...], 'Instants' => [...], etc.]
     */
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

    /**
     * Reorganize decklist sections according to standard Magic: The Gathering ordering.
     * 
     * This method ensures consistent section presentation across the application
     * by reordering deck sections to match tournament and deckbuilding conventions.
     * It preserves all existing sections while applying the standard order defined
     * in SECTION_ORDER constant.
     * 
     * Sections not present in the decklist are omitted from the result.
     * This allows for flexible deck formats while maintaining consistent presentation.
     * 
     * @param array $decklist Associative array with section names as keys
     * @return array Same decklist with sections reordered according to SECTION_ORDER
     */
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

    /**
     * Process raw decklist text into a fully structured and categorized deck.
     * 
     * This is the primary method for converting tournament decklist data
     * into the application's standard deck format.
     */
    public function buildDecklist(string $rawDecklist): array
    {
        $parsed = $this->parseDecklist($rawDecklist);
        
        return $this->processParsedDecklist($parsed);
    }

    /**
     * Process parsed decklist into final format with proper section organization.
     */
    protected function processParsedDecklist(array $parsed): array
    {
        $decklist = [];
        
        if (!empty($parsed['Commanders'])) {
            $decklist['Commanders'] = $parsed['Commanders'];
        }

        if (!empty($parsed['Mainboard'])) {
            $decklist = array_merge($decklist, $this->groupByType($parsed['Mainboard']));
        }

        return $decklist;
    }

    /**
     * Fetch and process a random competitive Commander tournament deck.
     * 
     * This method coordinates with the TopdeckService to retrieve a random
     * cEDH (competitive Commander) tournament result and processes it into
     * the application's standard format for daily puzzle games.
     * 
     * The method handles the complete tournament data pipeline:
     * 1. Builds exclusion list from yesterday's games and today's other mode
     * 2. Requests a random tournament from TopdeckService (excluding repeat commanders)
     * 3. Processes the raw decklist text through buildDecklist()
     * 4. Extracts relevant tournament and player metadata
     * 5. Returns structured data suitable for game creation
     * 
     * This data is typically used to generate daily puzzle games where
     * players guess tournament performance based on deck composition.
     * 
     * Error handling is delegated to TopdeckService, which implements
     * comprehensive retry logic and validation.
     * 
     * @param \Carbon\Carbon|null $forDate The date being generated for (defaults to today)
     * @param array $additionalExclusions Extra commander keys to exclude beyond auto-detected ones
     * @return array Complete tournament game data:
     *               [
     *                 'tournament_name' => string,
     *                 'player_name' => string,
     *                 'player_standing' => int,
     *                 'total_participants' => int,
     *                 'decklist' => array, // Processed through buildDecklist()
     *                 'decklist_url' => string|null, // Original Moxfield/archiving URL
     *                 'commander_key' => string|null // Normalized commander key for exclusion tracking
     *               ]
     */
    public function fetchRandomGame(?\Carbon\Carbon $forDate = null, array $additionalExclusions = []): array
    {
        $excludedCommanders = $this->buildExcludedCommanders($forDate, $additionalExclusions);
        $tournament = $this->topdeck->getRandomCommanderTournament($excludedCommanders);

        $decklist = [];
        if (!empty($tournament['player_decklist'])) {
            $decklist = $this->buildDecklist($tournament['player_decklist']);
        }

        return [
            'tournament_name' => $tournament['tournament_name'] ?? null,
            'player_name' => $tournament['player_name'] ?? null,
            'player_standing' => $tournament['player_standing'] ?? null,
            'total_participants' => $tournament['total_participants'] ?? null,
            'decklist' => $decklist,
            'decklist_url' => $tournament['decklist_url'] ?? null,
            'commander_key' => $tournament['commander_key'] ?? null,
        ];
    }

    /**
     * Build the list of commander keys to exclude from selection.
     * 
     * Automatically excludes:
     * - Yesterday's commanders (both modes) to avoid day-to-day repeats
     * - The target date's other mode commander to ensure normal/hard have different commanders
     * 
     * @param \Carbon\Carbon|null $forDate The date being generated for (defaults to today)
     * @param array $additionalExclusions Extra commander keys to add to the exclusion list
     * @return array Combined list of commander keys to exclude
     */
    protected function buildExcludedCommanders(?\Carbon\Carbon $forDate = null, array $additionalExclusions = []): array
    {
        $targetDate = $forDate ?? today();
        $excluded = $additionalExclusions;

        // Exclude yesterday's commanders (both modes) relative to target date
        $yesterdaysGames = DailyGame::where('date', $targetDate->copy()->subDay())->get();
        foreach ($yesterdaysGames as $game) {
            $commanderKey = $this->topdeck->getCommanderKeyFromDecklist($game->decklist ?? []);
            if ($commanderKey && !in_array($commanderKey, $excluded, true)) {
                $excluded[] = $commanderKey;
            }
        }

        // Exclude target date's other mode commander (if any mode already generated)
        $targetDaysGames = DailyGame::where('date', $targetDate)->get();
        foreach ($targetDaysGames as $game) {
            $commanderKey = $this->topdeck->getCommanderKeyFromDecklist($game->decklist ?? []);
            if ($commanderKey && !in_array($commanderKey, $excluded, true)) {
                $excluded[] = $commanderKey;
            }
        }

        return $excluded;
    }
}
