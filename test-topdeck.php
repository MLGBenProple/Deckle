<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

try {
    $service = $app->make('App\Services\TopdeckService');
    $result = $service->getRandomCommanderTournament();
    
    echo "Tournament: " . ($result['tournament_name'] ?? 'N/A') . PHP_EOL;
    echo "Player: " . ($result['player_name'] ?? 'N/A') . PHP_EOL;
    echo "Decklist URL: " . ($result['decklist_url'] ?? 'No URL found') . PHP_EOL;
    echo "Has text decklist: " . (empty($result['player_decklist']) ? 'No' : 'Yes') . PHP_EOL;
    
    if (!empty($result['decklist_url'])) {
        echo "✓ URL detected successfully!" . PHP_EOL;
        if (strpos($result['decklist_url'], 'moxfield.com') !== false) {
            echo "✓ Moxfield URL detected!" . PHP_EOL;
        }
    } else {
        echo "✗ No URL found - may need to try another tournament" . PHP_EOL;
    }
    
    // Show some debug info
    if (isset($result['debug_error'])) {
        echo "Debug: " . $result['debug_error'] . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}