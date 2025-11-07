<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class RewriteService
{
    public function __construct(
        private Db $db,
        private ServiceNowClient $serviceNow,
        private LlmClient $llm,
        private array $config
    ) {
    }

    /**
     * Rewrite article content based on selected recommendations
     * 
     * @param int $articleId
     * @param array<int, string> $selectedRecommendations
     * @return array<string, mixed>
     */
    public function rewriteArticle(int $articleId, array $selectedRecommendations): array
    {
        if (empty($selectedRecommendations)) {
            throw new RuntimeException('No recommendations selected for rewrite.');
        }

        $article = $this->db->fetchOne(
            'SELECT * FROM articles WHERE id = :id',
            ['id' => $articleId]
        );

        if ($article === null) {
            throw new RuntimeException('Article not found: ' . $articleId);
        }

        // Ensure we have the article body
        if (empty($article['body_html'])) {
            $fresh = $this->serviceNow->fetchArticleBody($article['kb_number']);
            if (!$fresh || empty($fresh[$this->config['servicenow']['body_field'] ?? 'text'])) {
                throw new RuntimeException('Unable to fetch article body from ServiceNow.');
            }
            $article['body_html'] = (string)$fresh[$this->config['servicenow']['body_field'] ?? 'text'];
        }

        // Get the latest assessment to retrieve all recommendations
        $assessment = $this->db->fetchOne(
            'SELECT * FROM assessments 
             WHERE article_id = :article_id 
             AND status = :status 
             ORDER BY completed_at DESC 
             LIMIT 1',
            [
                'article_id' => $articleId,
                'status' => 'done'
            ]
        );

        if ($assessment === null) {
            throw new RuntimeException('No completed assessment found for article.');
        }

        // Perform the rewrite
        $result = $this->runRewrite($article, $selectedRecommendations);

        // Store the rewrite result (optional - you may want to create a rewrites table)
        // For now, we'll just return the result
        return [
            'success' => true,
            'rewritten_content' => $result['rewritten_content'],
            'changes_made' => $result['changes_made'],
        ];
    }

    /**
     * @param array<string, mixed> $article
     * @param array<int, string> $selectedRecommendations
     * @return array{rewritten_content: string, changes_made: array<int, string>}
     */
    private function runRewrite(array $article, array $selectedRecommendations): array
    {
        $recommendationsList = implode("\n", array_map(
            fn($i, $rec) => ($i + 1) . ". " . $rec,
            array_keys($selectedRecommendations),
            $selectedRecommendations
        ));

        // Get prompts from configuration
        $prompts = $this->config['prompts'] ?? [];
        $organizationContext = trim($prompts['organization_context'] ?? '');
        $rewriteSystem = trim($prompts['rewrite_system'] ?? '');
        $rewriteFormat = trim($prompts['rewrite_format'] ?? '');

        // Build system prompt
        $systemPromptParts = [];

        // Add organization context if provided
        if (!empty($organizationContext)) {
            $systemPromptParts[] = $organizationContext;
            $systemPromptParts[] = ''; // blank line
        }

        // Add rewrite system prompt
        if (!empty($rewriteSystem)) {
            $systemPromptParts[] = $rewriteSystem;
        }

        // Add format instructions
        if (!empty($rewriteFormat)) {
            $systemPromptParts[] = '';
            $systemPromptParts[] = $rewriteFormat;
        }

        $systemPrompt = implode("\n", $systemPromptParts);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'kb_number' => $article['kb_number'],
                    'short_description' => $article['short_description'],
                    'current_body_html' => $article['body_html'],
                    'recommendations_to_implement' => $recommendationsList,
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        return $this->attemptRewrite($messages, (string)($article['kb_number'] ?? 'unknown'));
    }

    /**
     * @param array<int, array<string, string>> $messages
     * @throws RuntimeException
     */
    private function attemptRewrite(array $messages, string $context, int $attempt = 1): array
    {
        // Use the rewrite LLM configuration
        $rewriteConfig = $this->config['llm_rewrite'] ?? $this->config['llm'];
        $appConfig = $this->config['app'] ?? [];
        
        // Create a temporary LLM client with rewrite config
        $rewriteLlm = new LlmClient($rewriteConfig, $appConfig);
        
        $response = $rewriteLlm->chat($messages);

        // Robustly extract assistant content from common response shapes (handle qwen style).
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
            // Last resort: locate any useful string in nested response (content or text)
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
            try {
                $dump = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                $dump = var_export($response, true);
            }
            error_log(sprintf('[LLM-REWRITE][article:%s][attempt:%d] Missing content. Full response: %s', $context, $attempt, $dump));
            throw new RuntimeException('LLM response missing content.');
        }

        if (($this->config['app']['log_llm_responses'] ?? false) === true) {
            error_log(sprintf('[LLM-REWRITE][article:%s][attempt:%d] %s', $context, $attempt, substr($content, 0, 500)));
        }

        try {
            return $this->parseRewriteJson($content);
        } catch (RuntimeException $e) {
            if ($attempt >= 2) {
                throw new RuntimeException($e->getMessage() . ' Raw response: ' . substr($content, 0, 500), previous: $e);
            }

            $repairMessages = array_merge(
                $messages,
                [
                    [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Your previous reply was not valid JSON. Respond again with ONLY a valid JSON object containing the keys rewritten_content (string) and changes_made (array of strings). Do not include any extra commentary.',
                    ],
                ]
            );

            return $this->attemptRewrite($repairMessages, $context, $attempt + 1);
        }
    }

    /**
     * @return array{rewritten_content: string, changes_made: array<int, string>}
     */
    private function parseRewriteJson(string $content): array
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

        if (!isset($decoded['rewritten_content'], $decoded['changes_made'])) {
            throw new RuntimeException('LLM response missing required keys.');
        }

        $rewrittenContent = (string)$decoded['rewritten_content'];
        $changesMade = array_map('strval', (array)$decoded['changes_made']);

        return [
            'rewritten_content' => $rewrittenContent,
            'changes_made' => $changesMade,
        ];
    }
}