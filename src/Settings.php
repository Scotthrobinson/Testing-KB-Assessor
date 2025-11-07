<?php

declare(strict_types=1);

namespace App;

final class Settings
{
    private array $settings = [];

    public function __construct(private Db $db)
    {
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $rows = $this->db->fetchAll('SELECT key, value, category FROM settings ORDER BY display_order, key');
        foreach ($rows as $row) {
            $this->settings[$row['key']] = $row['value'];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return (int)$value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return (float)$value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->execute(
            'UPDATE settings SET value = :value WHERE key = :key',
            ['value' => (string)$value, 'key' => $key]
        );
        $this->settings[$key] = (string)$value;
    }

    public function getAll(): array
    {
        return $this->settings;
    }

    public function getAllByCategory(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT key, value, category, label, type, description, display_order
             FROM settings
             ORDER BY category, display_order, key'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $category = $row['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $row;
        }

        return $grouped;
    }

    /**
     * Get configuration array in the format expected by existing code
     */
    public function getConfig(): array
    {
        return [
            'db' => [
                'path' => $this->get('db.path', __DIR__ . '/../db/app.sqlite'),
            ],
            'servicenow' => [
                'base_url' => $this->get('servicenow.base_url', ''),
                'username' => $this->get('servicenow.username', ''),
                'password' => $this->get('servicenow.password', ''),
                'table' => $this->get('servicenow.table', 'kb_knowledge'),
                'body_field' => $this->get('servicenow.body_field', 'text'),
                'sysparm_query' => $this->get('servicenow.sysparm_query', ''),
                'verify_ssl' => $this->getBool('servicenow.verify_ssl', false),
                'ca_bundle' => $this->get('servicenow.ca_bundle'),
            ],
            'llm' => [
                'base_url' => $this->get('llm.base_url', ''),
                'model' => $this->get('llm.model', ''),
                'api_key' => $this->get('llm.api_key', ''),
                'temperature' => $this->getFloat('llm.temperature', 0.2),
                'max_tokens' => $this->getInt('llm.max_tokens', 2048),
                'verify_ssl' => $this->getBool('llm.verify_ssl', false),
                'ca_bundle' => $this->get('llm.ca_bundle'),
            ],
            'llm_rewrite' => [
                'base_url' => $this->get('llm_rewrite.base_url', ''),
                'model' => $this->get('llm_rewrite.model', ''),
                'api_key' => $this->get('llm_rewrite.api_key', ''),
                'temperature' => $this->getFloat('llm_rewrite.temperature', 0.3),
                'max_tokens' => $this->getInt('llm_rewrite.max_tokens', 4096),
                'verify_ssl' => $this->getBool('llm_rewrite.verify_ssl', false),
                'ca_bundle' => $this->get('llm_rewrite.ca_bundle'),
            ],
            'app' => [
                'user_agent' => $this->get('app.user_agent', 'ServiceNowKB-Assessor/1.0'),
                'request_timeout' => $this->getInt('app.request_timeout', 30),
                'assessment_timeout' => $this->getInt('app.assessment_timeout', 120),
                'log_errors' => $this->getBool('app.log_errors', true),
                'log_llm_responses' => $this->getBool('app.log_llm_responses', true),
                'timezone' => $this->get('app.timezone', 'UTC'),
                'app_name' => $this->get('app.app_name', 'KB Assessor'),
                'system_prompt' => $this->get('app.system_prompt', ''),
            ],
            'prompts' => [
                'assessment_system' => $this->get('prompts.assessment_system', ''),
                'assessment_format' => $this->get('prompts.assessment_format', ''),
                'rewrite_system' => $this->get('prompts.rewrite_system', ''),
                'rewrite_format' => $this->get('prompts.rewrite_format', ''),
                'organization_context' => $this->get('prompts.organization_context', ''),
            ],
        ];
    }

    public function updateMultiple(array $settings): void
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
