<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Services;

use Core\Mod\Uptelligence\Data\AIAnalysis;
use Core\Mod\Uptelligence\Data\DiffResult;
use Core\Mod\Uptelligence\Models\AnalysisLog;
use Core\Mod\Uptelligence\Models\DiffCache;
use Core\Mod\Uptelligence\Models\UpstreamTodo;
use Core\Mod\Uptelligence\Models\VersionRelease;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * AI Analyzer Service - uses AI to analyse version releases and create todos.
 *
 * Supports both Anthropic Claude and OpenAI APIs.
 */
class AIAnalyzerService
{
    protected string $provider;

    protected string $model;

    protected string $apiKey;

    protected int $maxTokens;

    protected float $temperature;

    public function __construct()
    {
        $config = config('upstream.ai');
        $this->provider = $config['provider'] ?? 'anthropic';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->temperature = $config['temperature'] ?? 0.3;
        $this->apiKey = match ($this->provider) {
            'anthropic' => (string) config('services.anthropic.api_key', ''),
            'openai' => (string) config('services.openai.api_key', ''),
            'lthn.ai', 'lthn_ai', 'lthn' => (string) (
                config('services.lthn_ai.api_key')
                ?? config('upstream.ai.lthn_ai.api_key')
                ?? config('upstream.ai.api_key')
                ?? ''
            ),
            default => (string) config('upstream.ai.api_key', ''),
        };
    }

    /**
     * Analyse a structured diff and return the RFC DTO.
     */
    public function analyze(DiffResult|array $diff): AIAnalysis
    {
        $diffResult = is_array($diff) ? DiffResult::fromArray($diff) : $diff;

        $cached = $this->cachedAnalysis($diffResult);
        if ($cached instanceof AIAnalysis) {
            return $cached;
        }

        $response = $this->callAI($this->buildDiffAnalysisPrompt($diffResult));
        $analysis = is_string($response)
            ? $this->parseDiffAnalysisResponse($response, $diffResult)
            : null;

        if (! $analysis instanceof AIAnalysis) {
            $analysis = $this->fallbackAnalysis($diffResult, $response === null ? 'AI provider unavailable' : 'AI response was not valid JSON');
        }

        $this->cacheAnalysis($diffResult, $analysis);

        return $analysis;
    }

    public function analyse(DiffResult|array $diff): AIAnalysis
    {
        return $this->analyze($diff);
    }

    protected function cachedAnalysis(DiffResult $diff): ?AIAnalysis
    {
        try {
            $cacheKey = $diff->cacheKey();
            $cache = DiffCache::query()
                ->where('metadata->analysis_cache_key', $cacheKey)
                ->latest()
                ->first();

            if (! $cache instanceof DiffCache) {
                $cacheIds = $this->diffCacheIds($diff);
                $cache = $cacheIds === []
                    ? null
                    : DiffCache::query()->whereIn('id', $cacheIds)->get()
                        ->first(fn (DiffCache $row): bool => $row->cachedAIAnalysis() !== null);
            }

            $analysis = $cache?->cachedAIAnalysis();

            return is_array($analysis) ? AIAnalysis::fromArray($analysis, cached: true) : null;
        } catch (Throwable $e) {
            Log::debug('Uptelligence: unable to read AI analysis cache', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function buildDiffAnalysisPrompt(DiffResult $diff): string
    {
        $files = collect($diff->byFile)
            ->take(30)
            ->map(function (array $file): string {
                $diffContent = (string) ($file['diff_content'] ?? '');
                $diffContent = strlen($diffContent) > 6000 ? substr($diffContent, 0, 6000)."\n[truncated]\n" : $diffContent;

                return sprintf(
                    "[%s] %s (%s)\n```diff\n%s\n```",
                    $file['change_type'] ?? 'modified',
                    $file['file_path'] ?? 'unknown',
                    $file['category'] ?? 'other',
                    $diffContent,
                );
            })
            ->implode("\n\n");

        $breaking = $diff->breakingChanges === []
            ? 'None detected by the static diff pass.'
            : implode("\n- ", $diff->breakingChanges);
        $migrationSteps = $diff->migrationSteps === []
            ? 'No generated migration steps.'
            : implode("\n- ", $diff->migrationSteps);

        return <<<PROMPT
Analyse this upstream dependency diff for upgrade impact.

Return JSON only with this exact shape:
{
  "severity": "low|medium|high|critical",
  "summary": "One or two sentences.",
  "actionItems": ["Concrete upgrade action"],
  "riskLevel": "low|medium|high|critical",
  "categories": ["breaking", "feature", "security", "performance", "docs", "styling"],
  "findings": [{"kind": "breaking|feature|security|performance|docs|styling", "title": "Short finding", "description": "Why it matters"}]
}

Diff totals:
- files_changed: {$diff->filesChanged}
- additions: {$diff->additions}
- deletions: {$diff->deletions}

Static breaking-change hints:
- {$breaking}

Migration-step hints:
- {$migrationSteps}

Changed files:
{$files}
PROMPT;
    }

    protected function parseDiffAnalysisResponse(string $response, DiffResult $diff): ?AIAnalysis
    {
        $data = $this->decodeJsonResponse($response);
        if (! is_array($data)) {
            return null;
        }

        $payload = is_array($data['analysis'] ?? null) ? $data['analysis'] : $data;
        $severity = $this->normaliseRiskValue($payload['severity'] ?? $payload['priority'] ?? null, $diff);
        $riskLevel = $this->normaliseRiskValue($payload['riskLevel'] ?? $payload['risk_level'] ?? $severity, $diff);
        $summary = trim((string) ($payload['summary'] ?? ''));

        if ($summary === '') {
            $summary = $this->fallbackSummary($diff);
        }

        return new AIAnalysis(
            severity: $severity,
            summary: $summary,
            actionItems: $this->normaliseActionItems($payload['actionItems'] ?? $payload['action_items'] ?? []),
            riskLevel: $riskLevel,
            categories: $this->normaliseList($payload['categories'] ?? []),
            findings: $this->normaliseFindings($payload['findings'] ?? []),
            metadata: [
                'provider' => $this->provider,
                'model' => $this->model,
                'diff_cache_key' => $diff->cacheKey(),
            ],
        );
    }

    protected function decodeJsonResponse(string $response): ?array
    {
        $json = trim($response);
        if (preg_match('/```json\s*(.*?)\s*```/s', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    protected function fallbackAnalysis(DiffResult $diff, string $reason): AIAnalysis
    {
        $severity = $diff->breakingChanges === []
            ? ($diff->filesChanged > 10 ? 'medium' : 'low')
            : 'high';

        if (collect($diff->breakingChanges)->contains(fn (string $change): bool => str_contains(strtolower($change), 'security'))) {
            $severity = 'critical';
        }

        $actionItems = $diff->migrationSteps !== []
            ? $diff->migrationSteps
            : ['Review the changed files and run the relevant regression tests.'];

        return new AIAnalysis(
            severity: $severity,
            summary: $this->fallbackSummary($diff),
            actionItems: $actionItems,
            riskLevel: $severity,
            categories: $diff->breakingChanges === [] ? ['maintenance'] : ['breaking'],
            findings: collect($diff->breakingChanges)
                ->map(fn (string $change): array => [
                    'kind' => 'breaking',
                    'title' => $change,
                    'description' => $change,
                ])
                ->values()
                ->all(),
            metadata: [
                'provider' => 'heuristic',
                'fallback_reason' => $reason,
                'diff_cache_key' => $diff->cacheKey(),
            ],
        );
    }

    protected function fallbackSummary(DiffResult $diff): string
    {
        if ($diff->isEmpty()) {
            return 'No meaningful code changes were detected after filtering noise files.';
        }

        return "Detected {$diff->filesChanged} changed file(s), {$diff->additions} addition(s), and {$diff->deletions} deletion(s).";
    }

    protected function normaliseRiskValue(mixed $value, DiffResult $diff): string
    {
        if (is_numeric($value)) {
            return match (true) {
                (int) $value >= 9 => 'critical',
                (int) $value >= 7 => 'high',
                (int) $value >= 4 => 'medium',
                default => 'low',
            };
        }

        $risk = strtolower((string) $value);

        return in_array($risk, ['low', 'medium', 'high', 'critical'], true)
            ? $risk
            : ($diff->breakingChanges === [] ? 'medium' : 'high');
    }

    protected function normaliseActionItems(mixed $items): array
    {
        if (is_string($items)) {
            return [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn (mixed $item): string => is_array($item)
                ? (string) ($item['title'] ?? $item['description'] ?? json_encode($item))
                : (string) $item)
            ->filter()
            ->values()
            ->all();
    }

    protected function normaliseList(mixed $items): array
    {
        if (is_string($items)) {
            return [$items];
        }

        return is_array($items)
            ? array_values(array_filter(array_map('strval', $items)))
            : [];
    }

    protected function normaliseFindings(mixed $findings): array
    {
        if (! is_array($findings)) {
            return [];
        }

        return collect($findings)
            ->map(function (mixed $finding): array {
                if (is_string($finding)) {
                    return [
                        'kind' => 'general',
                        'title' => $finding,
                        'description' => $finding,
                    ];
                }

                return is_array($finding) ? $finding : [];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function cacheAnalysis(DiffResult $diff, AIAnalysis $analysis): void
    {
        try {
            $cacheIds = $this->diffCacheIds($diff);
            $rows = $cacheIds === []
                ? collect()
                : DiffCache::query()->whereIn('id', $cacheIds)->get();

            if ($rows->isEmpty() && is_numeric($diff->metadata['version_release_id'] ?? null)) {
                $rows = DiffCache::query()
                    ->where('version_release_id', (int) $diff->metadata['version_release_id'])
                    ->get();
            }

            foreach ($rows as $row) {
                $row->cacheAIAnalysis($analysis, $diff->cacheKey());
            }
        } catch (Throwable $e) {
            Log::debug('Uptelligence: unable to cache AI analysis', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function diffCacheIds(DiffResult $diff): array
    {
        $metadataIds = $diff->metadata['diff_cache_ids'] ?? [];
        $fileIds = collect($diff->byFile)
            ->map(fn (array $file): mixed => $file['cache_id'] ?? $file['diff_cache_id'] ?? null)
            ->filter()
            ->all();

        return collect([...((array) $metadataIds), ...$fileIds])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Analyse a version release and create todos.
     */
    public function analyzeRelease(VersionRelease $release): Collection
    {
        $diffs = $release->diffs;
        $todos = collect();

        // Group related diffs for batch analysis
        $groups = $this->groupRelatedDiffs($diffs);

        foreach ($groups as $group) {
            $analysis = $this->analyzeGroup($release, $group);

            if ($analysis && $this->shouldCreateTodo($analysis)) {
                $todo = $this->createTodo($release, $group, $analysis);
                $todos->push($todo);
            }
        }

        // Update release with AI-generated summary
        $summary = $this->generateReleaseSummary($release, $todos);
        $release->update(['summary' => $summary]);

        return $todos;
    }

    /**
     * Group related diffs together (e.g., controller + view + route).
     */
    protected function groupRelatedDiffs(Collection $diffs): array
    {
        $groups = [];
        $processed = [];

        foreach ($diffs as $diff) {
            if (in_array($diff->id, $processed)) {
                continue;
            }

            $group = [$diff];
            $processed[] = $diff->id;

            // Find related files by common patterns
            $baseName = $this->extractBaseName($diff->file_path);

            foreach ($diffs as $related) {
                if (in_array($related->id, $processed)) {
                    continue;
                }

                if ($this->areRelated($diff, $related, $baseName)) {
                    $group[] = $related;
                    $processed[] = $related->id;
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Extract base name from file path for grouping.
     */
    protected function extractBaseName(string $path): string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        // Remove common suffixes
        $filename = preg_replace('/(Controller|Model|Service|View|Block)$/i', '', $filename);

        return strtolower($filename);
    }

    /**
     * Check if two diffs are related.
     */
    protected function areRelated(DiffCache $diff1, DiffCache $diff2, string $baseName): bool
    {
        // Same directory
        if (dirname($diff1->file_path) === dirname($diff2->file_path)) {
            return true;
        }

        // Same base name in different directories
        $name2 = $this->extractBaseName($diff2->file_path);
        if ($baseName && $baseName === $name2) {
            return true;
        }

        return false;
    }

    /**
     * Analyse a group of related diffs using AI.
     */
    protected function analyzeGroup(VersionRelease $release, array $diffs): ?array
    {
        // Build context for AI
        $context = $this->buildContext($release, $diffs);

        // Call AI API
        $prompt = $this->buildAnalysisPrompt($context);
        $response = $this->callAI($prompt);

        if (! $response) {
            return null;
        }

        return $this->parseAnalysisResponse($response);
    }

    /**
     * Build context string for AI.
     */
    protected function buildContext(VersionRelease $release, array $diffs): string
    {
        $context = "Vendor: {$release->vendor->name}\n";
        $context .= "Version: {$release->previous_version} → {$release->version}\n\n";
        $context .= "Changed files:\n";

        foreach ($diffs as $diff) {
            $context .= "- [{$diff->change_type}] {$diff->file_path} ({$diff->category})\n";

            // Include diff content for modified files (truncated)
            if ($diff->diff_content && strlen($diff->diff_content) < 5000) {
                $context .= "```diff\n".$diff->diff_content."\n```\n\n";
            }
        }

        return $context;
    }

    /**
     * Build the analysis prompt.
     */
    protected function buildAnalysisPrompt(string $context): string
    {
        return <<<PROMPT
Analyse the following code changes from an upstream vendor and categorise them for potential porting to our codebase.

{$context}

Please provide your analysis in the following JSON format:
{
    "type": "feature|bugfix|security|ui|block|api|refactor|dependency",
    "title": "Brief title describing the change",
    "description": "Detailed description of what changed and why it might be valuable",
    "priority": 1-10 (10 = most important, consider security > features > bugfixes > refactors),
    "effort": "low|medium|high" (low = < 1 hour, medium = 1-4 hours, high = 4+ hours),
    "has_conflicts": true|false (likely to conflict with our customisations?),
    "conflict_reason": "If has_conflicts is true, explain why",
    "port_notes": "Any specific notes for the developer who will port this",
    "tags": ["relevant", "tags"],
    "dependencies": ["list of other features this depends on"],
    "skip_reason": null or "reason to skip this change"
}

Only return the JSON, no additional text.
PROMPT;
    }

    /**
     * Call the AI API with rate limiting.
     */
    protected function callAI(string $prompt): ?string
    {
        if (! $this->apiKey) {
            Log::debug('Uptelligence: AI API key not configured, skipping analysis');

            return null;
        }

        // Check rate limit before making API call
        if (RateLimiter::tooManyAttempts('upstream-ai-api', 10)) {
            $seconds = RateLimiter::availableIn('upstream-ai-api');
            Log::warning('Uptelligence: AI API rate limit exceeded', [
                'provider' => $this->provider,
                'retry_after_seconds' => $seconds,
            ]);

            return null;
        }

        RateLimiter::hit('upstream-ai-api');

        try {
            if ($this->provider === 'anthropic') {
                return $this->callAnthropic($prompt);
            }

            if (in_array($this->provider, ['lthn.ai', 'lthn_ai', 'lthn'], true)) {
                return $this->callLthnAi($prompt);
            }

            return $this->callOpenAI($prompt);
        } catch (\Exception $e) {
            Log::error('Uptelligence: AI API call failed', [
                'provider' => $this->provider,
                'model' => $this->model,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            report($e);

            return null;
        }
    }

    /**
     * Call lthn.ai direct API.
     */
    protected function callLthnAi(string $prompt): ?string
    {
        $endpoint = (string) (
            config('upstream.ai.lthn_ai.endpoint')
            ?? config('services.lthn_ai.endpoint')
            ?? 'https://lthn.ai/api/llm/analyse'
        );

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(60)
            ->retry(3, function (int $attempt, \Exception $exception) {
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: lthn.ai API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post($endpoint, [
                'model' => $this->model,
                'prompt' => $prompt,
                'response_format' => ['type' => 'json_object'],
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens,
            ]);

        if ($response->successful()) {
            $content = $response->json('analysis')
                ?? $response->json('content')
                ?? $response->json('message')
                ?? $response->body();

            return is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR);
        }

        Log::error('Uptelligence: lthn.ai API request failed', [
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Call Anthropic API with retry logic.
     */
    protected function callAnthropic(string $prompt): ?string
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(60)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: Anthropic API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx responses
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('content.0.text');
        }

        Log::error('Uptelligence: Anthropic API request failed', [
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Call OpenAI API with retry logic.
     */
    protected function callOpenAI(string $prompt): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = (int) pow(2, $attempt - 1) * 1000;

                Log::warning('Uptelligence: OpenAI API retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (\Exception $exception) {
                // Only retry on connection/timeout errors or 5xx responses
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    return $status >= 500 || $status === 429;
                }

                return false;
            })
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        Log::error('Uptelligence: OpenAI API request failed', [
            'status' => $response->status(),
            'body' => $this->redactSensitiveData(substr($response->body(), 0, 500)),
        ]);

        return null;
    }

    /**
     * Redact sensitive data from log messages.
     *
     * Removes or masks API keys, tokens, and other credentials that
     * might appear in error responses or debug output.
     */
    protected function redactSensitiveData(string $content): string
    {
        // Redact common API key patterns
        $patterns = [
            // Anthropic API keys (sk-ant-...)
            '/sk-ant-[a-zA-Z0-9_-]+/' => '[REDACTED_ANTHROPIC_KEY]',
            // OpenAI API keys (sk-...)
            '/sk-[a-zA-Z0-9]{20,}/' => '[REDACTED_OPENAI_KEY]',
            // Generic bearer tokens
            '/Bearer\s+[a-zA-Z0-9._-]+/' => 'Bearer [REDACTED]',
            // Authorization headers
            '/["\']?[Aa]uthorization["\']?\s*:\s*["\']?[^"\'}\s]+/' => '"authorization": "[REDACTED]"',
            // API key query parameters
            '/api[_-]?key=([^&\s"\']+)/' => 'api_key=[REDACTED]',
            // x-api-key header values
            '/x-api-key["\']?\s*:\s*["\']?[^"\'}\s]+/' => '"x-api-key": "[REDACTED]"',
            // Generic secret patterns
            '/["\']?secret["\']?\s*:\s*["\']?[^"\'}\s]+/' => '"secret": "[REDACTED]"',
            // Token patterns
            '/["\']?token["\']?\s*:\s*["\']?[a-zA-Z0-9._-]{20,}["\']?/' => '"token": "[REDACTED]"',
        ];

        $redacted = $content;
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted);
        }

        return $redacted;
    }

    /**
     * Parse AI response into structured data.
     */
    protected function parseAnalysisResponse(string $response): ?array
    {
        // Extract JSON from response
        $json = $response;
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $json = $matches[1];
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Determine if we should create a todo from analysis.
     */
    protected function shouldCreateTodo(array $analysis): bool
    {
        // Skip if explicitly marked to skip
        if (! empty($analysis['skip_reason'])) {
            return false;
        }

        // Skip very low priority refactors
        if ($analysis['type'] === 'refactor' && ($analysis['priority'] ?? 5) < 3) {
            return false;
        }

        return true;
    }

    /**
     * Create a todo from analysis.
     */
    protected function createTodo(VersionRelease $release, array $diffs, array $analysis): UpstreamTodo
    {
        $files = array_map(fn ($d) => $d->file_path, $diffs);

        $todo = UpstreamTodo::create([
            'vendor_id' => $release->vendor_id,
            'from_version' => $release->previous_version,
            'to_version' => $release->version,
            'type' => $analysis['type'] ?? 'feature',
            'status' => UpstreamTodo::STATUS_PENDING,
            'title' => $analysis['title'] ?? 'Untitled change',
            'description' => $analysis['description'] ?? null,
            'port_notes' => $analysis['port_notes'] ?? null,
            'priority' => $analysis['priority'] ?? 5,
            'effort' => $analysis['effort'] ?? UpstreamTodo::EFFORT_MEDIUM,
            'has_conflicts' => $analysis['has_conflicts'] ?? false,
            'conflict_reason' => $analysis['conflict_reason'] ?? null,
            'files' => $files,
            'dependencies' => $analysis['dependencies'] ?? [],
            'tags' => $analysis['tags'] ?? [],
            'ai_analysis' => $analysis,
            'ai_confidence' => 0.85, // Default confidence
        ]);

        AnalysisLog::logTodoCreated($todo);

        // Update release todos count
        $release->increment('todos_created');

        return $todo;
    }

    /**
     * Generate AI summary of the release.
     */
    protected function generateReleaseSummary(VersionRelease $release, Collection $todos): array
    {
        return [
            'overview' => $this->generateOverviewText($release, $todos),
            'features' => $todos->where('type', 'feature')->pluck('title')->toArray(),
            'fixes' => $todos->where('type', 'bugfix')->pluck('title')->toArray(),
            'security' => $todos->where('type', 'security')->pluck('title')->toArray(),
            'breaking_changes' => $todos->where('has_conflicts', true)->pluck('title')->toArray(),
            'quick_wins' => $todos->filter->isQuickWin()->pluck('title')->toArray(),
            'stats' => [
                'total_todos' => $todos->count(),
                'by_type' => $todos->groupBy('type')->map->count()->toArray(),
                'by_effort' => $todos->groupBy('effort')->map->count()->toArray(),
            ],
        ];
    }

    /**
     * Generate overview text.
     */
    protected function generateOverviewText(VersionRelease $release, Collection $todos): string
    {
        $features = $todos->where('type', 'feature')->count();
        $security = $todos->where('type', 'security')->count();
        $quickWins = $todos->filter->isQuickWin()->count();

        $text = "Version {$release->version} contains {$todos->count()} notable changes";

        if ($features > 0) {
            $text .= ", including {$features} new feature(s)";
        }

        if ($security > 0) {
            $text .= ". {$security} security-related update(s) require attention";
        }

        if ($quickWins > 0) {
            $text .= ". {$quickWins} quick win(s) can be ported easily";
        }

        return $text.'.';
    }
}
