<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ScryfallService
{
    protected string $baseUrl = 'https://api.scryfall.com';

    /**
     * Get card data by exact name.
     */
    public function getCardByName(string $name): array
    {
        $response = Http::get($this->baseUrl . '/cards/named', [
            'exact' => $name,
        ]);
        $response->throw();

        return $response->json();
    }

    /**
     * Get the image URL for a card by name.
     */
    public function getCardImage(string $name, string $version = 'normal'): string
    {
        $card = $this->getCardByName($name);

        // Double-faced cards store images on each face instead of at the top level
        if (isset($card['image_uris'][$version])) {
            return $card['image_uris'][$version];
        }

        if (isset($card['card_faces'][0]['image_uris'][$version])) {
            return $card['card_faces'][0]['image_uris'][$version];
        }

        throw new \RuntimeException("No image found for card: {$name}");
    }

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
                $typeLine = $card['type_line'] ?? '';
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
            'Creature' => 'Creatures',
            'Planeswalker' => 'Planeswalkers',
            'Battle' => 'Battles',
            'Instant' => 'Instants',
            'Sorcery' => 'Sorceries',
            'Land' => 'Lands',
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

    /**
     * Get image URLs for an entire decklist.
     *
     * Expects the decklist format from TopdeckService:
     * ['Commanders' => [['name' => '...'], ...], 'Mainboard' => [['name' => '...'], ...]]
     *
     * Respects Scryfall's rate limit of ~100ms between requests.
     */
    public function getDecklistImages(array $decklist, string $version = 'normal'): array
    {
        $images = [];

        foreach ($decklist as $section => $cards) {
            foreach ($cards as $card) {
                $name = $card['name'];
                $images[$section][] = [
                    'name' => $name,
                    'quantity' => $card['quantity'] ?? 1,
                    'image' => $this->getCardImage($name, $version),
                ];

                usleep(100_000); // 100ms delay per Scryfall rate limit
            }
        }

        return $images;
    }
}
