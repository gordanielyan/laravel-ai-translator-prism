<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

use Illuminate\Support\Facades\Http;

/**
 * HTTP client for calling Ollama server
 * Ollama provides OpenAI-compatible API endpoints
 */
class OllamaClient
{
    protected string $baseUrl;

    protected ?string $apiKey;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl ?? config('ai-translator.ai.ollama_base_url');
    }

    /**
     * Performs a regular HTTP request.
     *
     * @param  string  $method  HTTP method
     * @param  string  $endpoint  API endpoint
     * @param  array  $data  Request data
     * @return array Response data
     *
     * @throws \Exception When API error occurs
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Ollama doesn't require API key by default, but some setups might use it
        if ($this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $response = Http::withHeaders($headers)->$method("{$this->baseUrl}/{$endpoint}", $data);

        if (! $response->successful()) {
            throw new \Exception("Ollama API error: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Performs a message generation request in non-streaming mode.
     *
     * @param  array  $data  Request data
     * @return array Response data
     *
     * @throws \Exception When API error occurs
     */
    public function createChat(array $data): array
    {
        // Ensure streaming is disabled
        $data['stream'] = false;

        return $this->request('post', 'chat/completions', $data);
    }

    /**
     * Performs a message generation request in streaming mode.
     *
     * @param  array  $data  Request data
     * @param  callable  $onChunk  Callback function to be called for each chunk
     * @return array Final response data
     *
     * @throws \Exception When API error occurs
     */
    public function createChatStream(array $data, ?callable $onChunk = null): array
    {
        // Enable streaming
        $data['stream'] = true;

        // Final response data
        $finalResponse = [
            'id' => null,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $data['model'] ?? null,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                    ],
                    'finish_reason' => null,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];

        // Execute streaming request
        $this->requestStream('post', 'chat/completions', $data, function ($chunk) use ($onChunk, &$finalResponse) {
            // Process chunk data
            if ($chunk && trim($chunk) !== '') {
                // Handle multiple lines of data
                $lines = explode("\n", $chunk);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Handle SSE format (lines starting with 'data: ')
                    if (strpos($line, 'data: ') === 0) {
                        $jsonData = substr($line, 6); // Remove 'data: '

                        // Handle '[DONE]' message
                        if (trim($jsonData) === '[DONE]') {
                            continue;
                        }

                        // Decode JSON
                        $data = json_decode($jsonData, true);

                        if (json_last_error() === JSON_ERROR_NONE && $data) {
                            // Update metadata
                            if (isset($data['id']) && ! $finalResponse['id']) {
                                $finalResponse['id'] = $data['id'];
                            }

                            if (isset($data['model'])) {
                                $finalResponse['model'] = $data['model'];
                            }

                            // Process content
                            if (isset($data['choices']) && is_array($data['choices']) && ! empty($data['choices'])) {
                                foreach ($data['choices'] as $choice) {
                                    if (isset($choice['delta']['content'])) {
                                        $content = $choice['delta']['content'];

                                        // Append content
                                        $finalResponse['choices'][0]['message']['content'] .= $content;
                                    }

                                    if (isset($choice['finish_reason'])) {
                                        $finalResponse['choices'][0]['finish_reason'] = $choice['finish_reason'];
                                    }
                                }
                            }

                            // Update token usage if available
                            if (isset($data['usage'])) {
                                if (isset($data['usage']['prompt_tokens'])) {
                                    $finalResponse['usage']['prompt_tokens'] = $data['usage']['prompt_tokens'];
                                }
                                if (isset($data['usage']['completion_tokens'])) {
                                    $finalResponse['usage']['completion_tokens'] = $data['usage']['completion_tokens'];
                                }
                                if (isset($data['usage']['total_tokens'])) {
                                    $finalResponse['usage']['total_tokens'] = $data['usage']['total_tokens'];
                                }
                            }

                            // Call callback
                            if ($onChunk) {
                                $onChunk($line, $data);
                            }
                        }
                    } elseif (strpos($line, 'event: ') === 0) {
                        // Handle event (if needed)
                        continue;
                    }
                }
            }
        });

        return $finalResponse;
    }

    /**
     * Performs a streaming HTTP request.
     *
     * @param  string  $method  HTTP method
     * @param  string  $endpoint  API endpoint
     * @param  array  $data  Request data
     * @param  callable  $onChunk  Callback function to be called for each chunk
     *
     * @throws \Exception When API error occurs
     */
    public function requestStream(string $method, string $endpoint, array $data, callable $onChunk): void
    {
        // Set up streaming request
        $url = "{$this->baseUrl}/{$endpoint}";
        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];

        // Add API key if provided
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer '.$this->apiKey;
        }

        // Initialize cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Set up callback for chunk data processing
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk) {
            $onChunk($data);

            return strlen($data);
        });

        // Execute request
        $result = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Ollama API streaming error: {$error}");
        }

        // Check HTTP status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            curl_close($ch);
            throw new \Exception("Ollama API streaming error: HTTP {$httpCode}");
        }

        // Close cURL
        curl_close($ch);
    }
}

