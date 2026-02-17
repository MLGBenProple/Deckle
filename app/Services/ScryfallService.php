<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ScryfallService
{
    protected string $baseUrl = 'https://api.scryfall.com';





    /**
     * Look up card types for a list of card names using the collection endpoint.
     *
     * Returns ['Card Name' => 'Creatures', ...] mapping.
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
     * Categorize a type_line into a primary display category.
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
