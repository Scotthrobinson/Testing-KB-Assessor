<?php

declare(strict_types=1);

namespace App;

final class LlmClient
{
    private string $baseUrl;
    private string $model;
    private string $apiKey;
    private float $temperature;
    private int $maxTokens;
    private string $userAgent;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(array $config, array $appConfig)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->model = $config['model'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->temperature = (float)($config['temperature'] ?? 0.2);
        $this->maxTokens = (int)($config['max_tokens'] ?? 2048);
        $this->userAgent = $appConfig['user_agent'] ?? 'ServiceNowKB-Assessor/1.0';
        $this->timeout = (int)($appConfig['assessment_timeout'] ?? 120);
        $this->verifySsl = (bool)($config['verify_ssl'] ?? true);
        $this->caBundle = isset($config['ca_bundle']) && trim((string)$config['ca_bundle']) !== ''
            ? (string)$config['ca_bundle']
            : null;

        if (!$this->baseUrl || !$this->model) {
            throw new \InvalidArgumentException('LLM configuration incomplete.');
        }
    }

    /**
     * @param array<int, array<string, string>> $inputs
     * @return array<string, mixed>
     */
    public function chat(array $inputs): array
    {
        $payload = [
            'model' => $this->model,
            'input' => $inputs,
            'temperature' => $this->temperature,
            'max_output_tokens' => $this->maxTokens,
        ];

        $url = $this->baseUrl . '/responses';
        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . $this->userAgent,
        ];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL for LLM call.');
        }

        try {
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            curl_close($ch);
            throw new \RuntimeException('Failed to JSON-encode LLM payload: ' . $e->getMessage(), 0, $e);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
        ]);

        if (!$this->verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } elseif ($this->caBundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('LLM request failed: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('LLM API returned status ' . $status . ': ' . $body);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Log the raw body when JSON decoding fails, then rethrow as runtime exception.
            error_log('[LlmClient] Failed to decode JSON response: ' . $e->getMessage() . ' Response body: ' . $body);
            throw new \RuntimeException('Failed to decode LLM response JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            error_log('[LlmClient] Unexpected LLM response payload (not array). Raw body: ' . $body);
            throw new \RuntimeException('Unexpected LLM response payload.');
        }

        // Log the raw response and the decoded structure for debugging.
        error_log('[LlmClient] LLM response status: ' . $status);
        error_log('[LlmClient] LLM raw response: ' . $body);
        error_log('[LlmClient] LLM decoded response: ' . json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $decoded;
    }
}