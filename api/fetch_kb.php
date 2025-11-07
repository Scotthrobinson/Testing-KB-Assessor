<?php

declare(strict_types=1);

namespace App;

final class ServiceNowClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $table;
    private string $bodyField;
    private string $defaultQuery;
    private string $userAgent;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(array $config, array $appConfig)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->table = $config['table'] ?? 'kb_knowledge';
        $this->bodyField = $config['body_field'] ?? 'text';
        $this->defaultQuery = $config['sysparm_query'] ?? '';
        $this->userAgent = $appConfig['user_agent'] ?? 'ServiceNowKB-Assessor/1.0';
        $this->timeout = (int)($appConfig['request_timeout'] ?? 30);
        $this->verifySsl = (bool)($config['verify_ssl'] ?? true);
        $this->caBundle = isset($config['ca_bundle']) && $config['ca_bundle'] !== null
            ? (string)$config['ca_bundle']
            : null;

        if (!$this->baseUrl || !$this->username || !$this->password) {
            throw new \InvalidArgumentException('ServiceNow configuration incomplete.');
        }
    }

    /**
     * Fetch KB article summaries updated since given ISO timestamp.
     *
     * @param string|null $sinceIso ISO timestamp to filter by update date
     * @param bool $full If true, fetch all articles ignoring date filters in default query
     * @return array<int, array<string, string|null>>
     */
    public function fetchUpdatedArticles(?string $sinceIso = null, bool $full = false): array
    {
        $all = [];
        $limit = 100; // paginate to fetch all records beyond instance default
        $offset = 0;

        do {
            $params = [
                'sysparm_query' => $this->buildQuery($sinceIso, $full),
                'sysparm_fields' => 'number,sys_updated_on,short_description',
                'sysparm_display_value' => 'false',
                'sysparm_exclude_reference_link' => 'true',
                'sysparm_limit' => (string)$limit,
                'sysparm_offset' => (string)$offset,
            ];

            $url = $this->buildUrl($params);
            $response = $this->request($url);
            $batch = is_array($response['result'] ?? null) ? $response['result'] : [];
            $count = count($batch);

            if ($count > 0) {
                $all = array_merge($all, $batch);
            }

            $offset += $limit;
        } while ($count === $limit); // continue while pages are full

        return $all;
    }

    /**
     * Fetch full article including body field.
     *
     * @return array<string, mixed>
     */
    public function fetchArticleBody(string $kbNumber): array
    {
        // Ensure we fetch the currently published version of the article
        $filters = [];
        if ($this->defaultQuery !== '') {
            $filters[] = $this->defaultQuery;
        }
        // Force published workflow state for body retrieval
        $filters[] = 'workflow_state=published';
        $filters[] = 'sys_class_name=kb_knowledge';
        $filters[] = 'number=' . $kbNumber;

        $params = [
            'sysparm_query' => implode('^', $filters),
            'sysparm_fields' => implode(',', [
                'number',
                'short_description',
                'sys_updated_on',
                $this->bodyField,
            ]),
            'sysparm_limit' => '1',
            'sysparm_display_value' => 'false',
        ];

        $url = $this->buildUrl($params);
        $response = $this->request($url);
        $results = $response['result'] ?? [];

        return $results[0] ?? [];
    }

    private function buildQuery(?string $sinceIso, bool $full = false): string
    {
        $filters = [];
        
        if ($full) {
            // For full fetch, only include essential filters, remove date-based filters
            // Parse default query and remove any date/time based filters
            if ($this->defaultQuery !== '') {
                $parts = explode('^', $this->defaultQuery);
                foreach ($parts as $part) {
                    // Skip any filters that contain date/time functions or sys_updated_on
                    if (stripos($part, 'sys_updated_on') === false 
                        && stripos($part, 'javascript:') === false
                        && stripos($part, 'gs.days') === false
                        && stripos($part, 'gs.months') === false
                        && stripos($part, 'gs.years') === false) {
                        $filters[] = $part;
                    }
                }
            }
            // For full fetch, we ignore $sinceIso even if provided
        } else {
            // Normal incremental fetch - use default query as-is
            if ($this->defaultQuery !== '') {
                $filters[] = $this->defaultQuery;
            }
            // Add date filter if provided
            if ($sinceIso) {
                $filters[] = 'sys_updated_on>=' . $sinceIso;
            }
        }

        return implode('^', $filters);
    }

    private function buildUrl(array $params): string
    {
        return sprintf(
            '%s/api/now/table/%s?%s',
            $this->baseUrl,
            rawurlencode($this->table),
            http_build_query($params, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
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
            throw new \RuntimeException('ServiceNow request failed: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('ServiceNow API returned status ' . $status . ': ' . $body);
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected response payload from ServiceNow.');
        }

        return $decoded;
    }
}