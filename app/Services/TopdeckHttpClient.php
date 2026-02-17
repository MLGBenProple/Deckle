<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TopdeckHttpClient
{
    protected string $baseUrl = 'https://topdeck.gg/api';
    protected string $apiKey;
    protected int $timeout = 45;
    protected int $maxRetries = 3;

    public function __construct()
    {
        $this->apiKey = config('services.topdeck.key');
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

    protected function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
        ];
    }
}