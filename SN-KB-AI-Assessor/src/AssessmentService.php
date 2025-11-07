<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class AssessmentService
{
    public function __construct(
        private Db $db,
        private ServiceNowClient $serviceNow,
        private LlmClient $llm,
        private array $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function queueAndProcess(int $articleId): array
    {
        $article = $this->db->fetchOne(
            'SELECT * FROM articles WHERE id = :id',
            ['id' => $articleId]
        );

        if ($article === null) {
            throw new RuntimeException('Article not found: ' . $articleId);
        }

        $assessmentId = $this->createAssessment($articleId);

        try {
            $this->db->execute(
                'UPDATE assessments SET status = :status, started_at = :started_at WHERE id = :id',
                [
                    'status' => 'running',
                    'started_at' => Db::now(),
                    'id' => $assessmentId,
                ]
            );

            $article = $this->ensureBody($article);
            $result = $this->runAssessment($article);

            $this->db->execute(
                'UPDATE assessments
                 SET status = :status,
                     completed_at = :completed_at,
                     llm_model = :llm_model,
                     verdict_current = :verdict,
                     recommendations = :recommendations
                 WHERE id = :id',
                [
                    'status' => 'done',
                    'completed_at' => Db::now(),
                    'llm_model' => $this->config['llm']['model'] ?? '',
                    'verdict' => $result['verdict_current'] ? 1 : 0,
                    'recommendations' => json_encode($result['recommendations'], JSON_THROW_ON_ERROR),
                    'id' => $assessmentId,
                ]
            );

            return [
                'assessment_id' => $assessmentId,
                'status' => 'done',
                'verdict_current' => $result['verdict_current'],
                'recommendations_count' => count($result['recommendations']),
            ];
        } catch (Throwable $e) {
            $this->db->execute(
                'UPDATE assessments
                 SET status = :status,
                     completed_at = :completed_at,
                     error = :error
                 WHERE id = :id',
                [
                    'status' => 'error',
                    'completed_at' => Db::now(),
                    'error' => $e->getMessage(),
                    'id' => $assessmentId,
                ]
            );

            throw $e;
        }
    }

    private function createAssessment(int $articleId): int
    {
        return $this->db->insert(
            'INSERT INTO assessments (article_id, status, requested_at)
             VALUES (:article_id, :status, :requested_at)',
            [
                'article_id' => $articleId,
                'status' => 'queued',
                'requested_at' => Db::now(),
            ]
        );
    }

    /**
     * @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function ensureBody(array $article): array
    {
        if (!empty($article['body_html'])) {
            return $article;
        }

        $fresh = $this->serviceNow->fetchArticleBody($article['kb_number']);
        if (!$fresh || empty($fresh[$this->config['servicenow']['body_field'] ?? 'text'])) {
            throw new RuntimeException('Unable to fetch article body from ServiceNow.');
        }

        $bodyHtml = (string)$fresh[$this->config['servicenow']['body_field'] ?? 'text'];
        $shortDescription = (string)($fresh['short_description'] ?? $article['short_description'] ?? '');
        $sysUpdatedOn = (string)($fresh['sys_updated_on'] ?? $article['sys_updated_on']);

        $this->db->execute(
            'UPDATE articles
             SET body_html = :body_html,
                 short_description = :short_description,
                 sys_updated_on = :sys_updated_on,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'body_html' => $bodyHtml,
                'short_description' => $shortDescription,
                'sys_updated_on' => $sysUpdatedOn,
                'updated_at' => Db::now(),
                'id' => $article['id'],
            ]
        );

        $article['body_html'] = $bodyHtml;
        $article['short_description'] = $shortDescription;
        $article['sys_updated_on'] = $sysUpdatedOn;

        return $article;
    }

    /**
     * @param array<string, mixed> $article
     * @return array{verdict_current: bool, recommendations: array<int, string>}
     */
    private function runAssessment(array $article): array
    {
        // Get prompts from configuration
        $prompts = $this->config['prompts'] ?? [];
        $organizationContext = trim($prompts['organization_context'] ?? '');
        $assessmentSystem = trim($prompts['assessment_system'] ?? '');
        $assessmentFormat = trim($prompts['assessment_format'] ?? '');

        // Build system prompt
        $systemPromptParts = [];

        // Add organization context if provided
        if (!empty($organizationContext)) {
            $systemPromptParts[] = $organizationContext;
            $systemPromptParts[] = ''; // blank line
        }

        // Add assessment system prompt
        if (!empty($assessmentSystem)) {
            $systemPromptParts[] = $assessmentSystem;
        }

        // Add format instructions
        if (!empty($assessmentFormat)) {
            $systemPromptParts[] = '';
            $systemPromptParts[] = $assessmentFormat;
        }

        $systemPrompt = implode("\n", $systemPromptParts);

        $baseInput = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'kb_number' => $article['kb_number'],
                    'short_description' => $article['short_description'],
                    'last_updated' => $article['sys_updated_on'],
                    'body_html' => $article['body_html'],
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        return $this->attemptAssessment($baseInput, (string)($article['kb_number'] ?? 'unknown'));
    }

    /**
     * @param array<int, array<string, string>> $input
     * @throws RuntimeException
     */
    private function attemptAssessment(array $inputs, string $context, int $attempt = 1): array
    {
        $response = $this->llm->chat($inputs);

        // Robustly extract assistant content from common response shapes.
        $content = '';
        if (isset($response['choices'][0]['message']['content']) && is_string($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        } elseif (isset($response['choices'][0]['text']) && is_string($response['choices'][0]['text'])) {
            $content = $response['choices'][0]['text'];
        } elseif (isset($response['output'][0]['content']) && is_string($response['output'][0]['content'])) {
            $content = $response['output'][0]['content'];
        // handle formats like: output[0].content[0].text (e.g. qwen style)
        } elseif (isset($response['output'][0]['content'][0]['text']) && is_string($response['output'][0]['content'][0]['text'])) {
            $content = $response['output'][0]['content'][0]['text'];
        } elseif (isset($response['results'][0]['output'][0]['content']) && is_string($response['results'][0]['output'][0]['content'])) {
            $content = $response['results'][0]['output'][0]['content'];
        } else {
            // Last resort: try to locate any useful string in nested response (content or text)
            array_walk_recursive($response, function ($v, $k) use (&$content) {
                if ($content !== '') {
                    return;
                }
                if (($k === 'content' || $k === 'text') && is_string($v) && trim($v) !== '') {
                    $content = $v;
                }
            });
        }

        if (!is_string($content) || trim($content) === '') {
            // Log the entire response for debugging
            try {
                $dump = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                $dump = var_export($response, true);
            }
            error_log(sprintf('[LLM][article:%s][attempt:%d] Missing content. Full response: %s', $context, $attempt,
                $dump));

            throw new RuntimeException('LLM response missing content.');
        }

        if (($this->config['app']['log_llm_responses'] ?? false) === true) {
            error_log(sprintf('[LLM][article:%s][attempt:%d] %s', $context, $attempt, $content));
        }

        try {
            return $this->parseAssessmentJson($content);
        } catch (RuntimeException $e) {
            if ($attempt >= 2) {
                throw new RuntimeException($e->getMessage() . ' Raw response: ' . $content, previous: $e);
            }

            $repairMessages = array_merge(
                $inputs,
                [
                    [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Your previous reply was not valid JSON. Respond again with ONLY a valid JSON object containing the keys verdict_current (boolean) and recommendations (array of short strings). Do not include any extra commentary.',
                    ],
                ]
            );

            return $this->attemptAssessment($repairMessages, $context, $attempt + 1);
        }
    }

    /**
     * @return array{verdict_current: bool, recommendations: array<int, string>}
     */
    private function parseAssessmentJson(string $content): array
    {
        // Strip markdown code fences if present
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $content, $matches)) {
            $content = $matches[1];
        }
        
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('LLM returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($decoded['verdict_current'], $decoded['recommendations'])) {
            throw new RuntimeException('LLM response missing required keys.');
        }

        $verdict = (bool)$decoded['verdict_current'];
        $recommendations = array_map('strval', (array)$decoded['recommendations']);

        return [
            'verdict_current' => $verdict,
            'recommendations' => $recommendations,
        ];
    }
}