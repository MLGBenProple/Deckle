<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ScryfallService
{
    protected string $baseUrl = 'https://api.scryfall.com';

    /**
     * Retrieve and categorize Magic: The Gathering card types for multiple cards efficiently.
     * 
     * This method uses Scryfall's collection endpoint to batch lookup card information,
     * which is more efficient than individual card lookups for multiple cards.
     * The method handles several important aspects:
     * 
     * - Rate limiting: Implements 100ms delays between requests to respect Scryfall's API limits
     * - Batch processing: Groups cards into chunks of 75 (Scryfall's maximum per request)
     * - Double-faced cards: Properly extracts type information from multi-faced cards
     * - Error handling: Throws exceptions on HTTP failures for proper error propagation
     * - Data normalization: Converts raw Scryfall type_line data into consistent categories
     * 
     * @param array $cardNames Array of Magic card names to look up (e.g., ['Lightning Bolt', 'Counterspell'])
     * @return array Associative array mapping card names to their primary type categories
     *               Format: ['Card Name' => 'Category', ...] where categories are standardized
     *               (e.g., 'Creatures', 'Instants', 'Lands', etc.)
     * 
     * @throws \Illuminate\Http\Client\RequestException When Scryfall API requests fail
     */
    public function getCardTypes(array $cardNames): array
    {
        $typeMap = [];
        $chunks = array_chunk($cardNames, 75);

        foreach ($chunks as $i => $chunk) {
            if ($i > 0) {
                usleep(100_000);
            }

            $identifiers = array_map(fn (string $name) => ['name' => $name], $chunk);
            $response = Http::post($this->baseUrl . '/cards/collection', [
                'identifiers' => $identifiers,
            ]);
            $response->throw();

            $data = $response->json();
            foreach ($data['data'] ?? [] as $card) {
                // For double-faced cards, use the front face's type_line
                if (isset($card['card_faces']) && !empty($card['card_faces'])) {
                    $typeLine = $card['card_faces'][0]['type_line'] ?? '';
                } else {
                    $typeLine = $card['type_line'] ?? '';
                }
                
                $category = $this->categorizeType($typeLine);
                $name = explode(' // ', $card['name'])[0];
                $typeMap[$name] = $category;
            }
        }

        return $typeMap;
    }

    /**
     * Convert a Magic: The Gathering type_line into a standardized display category.
     * 
     * This method implements the categorization logic used by most deckbuilding tools
     * and tournament software. It processes Scryfall's raw type_line data (e.g., 
     * "Legendary Creature — Human Wizard") and returns a simplified category for 
     * deck organization and display purposes.
     * 
     * The categorization follows a specific priority order that matches common 
     * deckbuilding conventions:
     * 1. Lands (highest priority - essential for mana base analysis)
     * 2. Creatures (most common permanent type in most decks)
     * 3. Planeswalkers (powerful permanents that deserve separate categorization)
     * 4. Battles (newest permanent type, distinct from other permanents)
     * 5. Instants (immediate effects, timing-critical spells)
     * 6. Sorceries (main-phase only spells)
     * 7. Enchantments (persistent effects)
     * 8. Artifacts (colorless permanents and utilities)
     * 
     * For cards with multiple types (e.g., "Artifact Creature"), the method returns
     * the highest priority category found. This ensures consistent categorization
     * and prevents double-counting in deck statistics.
     * 
     * @param string $typeLine The raw type_line from Scryfall API (e.g., "Instant", "Legendary Creature — Dragon")
     * @return string Standardized category name suitable for deck organization
     *                Returns: 'Lands', 'Creatures', 'Planeswalkers', 'Battles', 'Instants', 
     *                'Sorceries', 'Enchantments', 'Artifacts', or 'Other'
     */
    protected function categorizeType(string $typeLine): string
    {
        // Priority order matches common deckbuilding tools
        $categories = [
            'Land' => 'Lands',
            'Creature' => 'Creatures',
            'Planeswalker' => 'Planeswalkers',
            'Battle' => 'Battles',
            'Instant' => 'Instants',
            'Sorcery' => 'Sorceries',
            'Enchantment' => 'Enchantments',
            'Artifact' => 'Artifacts',
        ];

        foreach ($categories as $keyword => $category) {
            if (stripos($typeLine, $keyword) !== false) {
                return $category;
            }
        }

        return 'Other';
    }

}
