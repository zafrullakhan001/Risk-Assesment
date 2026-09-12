<?php

declare(strict_types=1);

/**
 * Uses a local Ollama LLM to reason over the Ticket Dossier JSON export text
 * and fill the Product & Design Summary template.
 */
final class DossierReasoningService
{
    public const DEFAULT_ETA_SECONDS = 90;

    private OllamaClient $client;

    private string $model;

    public function __construct(?OllamaClient $client = null, ?string $model = null)
    {
        $this->client = $client ?? new OllamaClient();
        $this->model = $model !== null && trim($model) !== '' ? trim($model) : TD_OLLAMA_MODEL;
    }

    /**
     * @return array{ok: bool, available: bool, model: string, message: string, models: list<string>, eta_seconds: int}
     */
    public function status(): array
    {
        try {
            if (!$this->client->isReachable()) {
                return [
                    'ok' => false,
                    'available' => false,
                    'model' => $this->model,
                    'message' => 'Ollama is not running. Start Ollama, then run: ollama pull ' . $this->model,
                    'models' => [],
                    'eta_seconds' => self::DEFAULT_ETA_SECONDS,
                ];
            }

            $models = array_map(
                static fn (array $m): string => $m['name'],
                $this->client->listModels()
            );
            $has = $this->client->hasModel($this->model);

            return [
                'ok' => $has,
                'available' => $has,
                'model' => $this->model,
                'message' => $has
                    ? $this->model . ' is ready to fill the Product & Design Summary from the dossier JSON export.'
                    : 'Model "' . $this->model . '" is not installed. Run: ollama pull ' . $this->model,
                'models' => $models,
                'eta_seconds' => self::DEFAULT_ETA_SECONDS,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'available' => false,
                'model' => $this->model,
                'message' => $e->getMessage(),
                'models' => [],
                'eta_seconds' => self::DEFAULT_ETA_SECONDS,
            ];
        }
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed>|null $parsed
     * @param array<string, mixed>|null $mappedSummary
     * @param callable(array{stage: string, percent: int, message: string, eta_seconds: int, elapsed_seconds: int}): void|null $onProgress
     * @return array<string, mixed>
     */
    public function reason(
        array $project,
        ?array $parsed = null,
        ?array $mappedSummary = null,
        ?callable $onProgress = null
    ): array {
        $started = microtime(true);
        $etaSeconds = self::DEFAULT_ETA_SECONDS;

        $emit = static function (string $stage, int $percent, string $message, int $eta, float $started) use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            $elapsed = (int) max(0, round(microtime(true) - $started));
            $onProgress([
                'stage' => $stage,
                'percent' => max(0, min(99, $percent)),
                'message' => $message,
                'eta_seconds' => max(0, $eta - $elapsed),
                'elapsed_seconds' => $elapsed,
            ]);
        };

        $status = $this->status();
        if (!$status['available']) {
            throw new RuntimeException($status['message']);
        }

        $emit('prepare', 3, 'Preparing dossier JSON export…', $etaSeconds, $started);

        $mappedSummary ??= ProjectSummaryMapper::map($project, is_array($parsed) ? $parsed : []);
        $exportText = ProjectJsonExport::toReasoningText($project, $parsed, TD_GEMMA_CONTEXT_CHARS);
        $contextChars = strlen($exportText);
        $draftHints = ProjectSummaryMapper::toAiHints($mappedSummary);

        $emit('export_ready', 8, 'JSON export ready (' . $contextChars . ' chars).', $etaSeconds, $started);

        // Pass 1: fill Product & Design Summary template (highest priority for the UI).
        $emit('generating', 12, 'Pass 1/2: filling Product & Design Summary fields…', $etaSeconds, $started);
        $templateJson = $this->runJsonChat(
            $this->templateSystemPrompt(),
            "Dossier JSON export:\n{$exportText}\n\nRule-mapped draft hints:\n"
            . json_encode($draftHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nReturn the template JSON object now. Keep each value concise.",
            static function (int $chars, bool $done) use ($emit, $etaSeconds, $started): void {
                $elapsed = (int) max(0, round(microtime(true) - $started));
                $pct = min(48, 12 + (int) round(min(1.0, $chars / 5000) * 30) + (int) round(min(1.0, $elapsed / max(1, $etaSeconds * 0.45)) * 10));
                $emit('generating', $pct, 'Pass 1/2: filling template fields… (' . $chars . ' chars)', $etaSeconds, $started);
            },
            2500
        );

        $templateValues = $this->extractTemplate($templateJson['content']);
        $emit('generating', 52, 'Template received. Pass 2/2: generating architecture reasoning…', $etaSeconds, $started);

        // Pass 2: architecture reasoning (smaller payload).
        $reasoningJson = $this->runJsonChat(
            $this->reasoningSystemPrompt(),
            "Dossier JSON export:\n{$exportText}\n\nReturn the reasoning JSON object now.",
            static function (int $chars, bool $done) use ($emit, $etaSeconds, $started): void {
                $elapsed = (int) max(0, round(microtime(true) - $started));
                $pct = min(90, 52 + (int) round(min(1.0, $chars / 3500) * 30) + (int) round(min(1.0, max(0, $elapsed - ($etaSeconds * 0.45)) / max(1, $etaSeconds * 0.45)) * 8));
                $emit('generating', $pct, 'Pass 2/2: writing reasoning… (' . $chars . ' chars)', $etaSeconds, $started);
            },
            1600
        );

        $emit('parsing', 93, 'Applying AI values to the Product & Design Summary…', $etaSeconds, $started);

        $parsedReasoning = $this->parseJsonResponse($reasoningJson['content']);
        $durationMs = (int) max(1, round((microtime(true) - $started) * 1000));
        $modelName = $reasoningJson['model'] !== '' ? $reasoningJson['model'] : $this->model;
        if ($templateJson['model'] !== '') {
            $modelName = $templateJson['model'];
        }

        $payload = [
            'executive_summary' => $this->asString($parsedReasoning['executive_summary'] ?? ''),
            'architecture_risks' => $this->asStringList($parsedReasoning['architecture_risks'] ?? []),
            'security_gaps' => $this->asStringList($parsedReasoning['security_gaps'] ?? []),
            'open_questions' => $this->asStringList($parsedReasoning['open_questions'] ?? []),
            'recommended_next_steps' => $this->asStringList($parsedReasoning['recommended_next_steps'] ?? []),
            'evidence_notes' => $this->asStringList($parsedReasoning['evidence_notes'] ?? []),
            'template' => $templateValues,
            'model' => $modelName,
            'generated_at' => gmdate('c'),
            'source' => 'ticket-dossier-json-export',
            'context_chars' => $contextChars,
            'duration_ms' => $durationMs,
            'eta_hint_seconds' => max(45, (int) round($durationMs / 1000)),
            'raw_text' => $templateJson['content'] . "\n---\n" . $reasoningJson['content'],
        ];

        if ($onProgress !== null) {
            $onProgress([
                'stage' => 'complete',
                'percent' => 100,
                'message' => 'Finished filling the Product & Design Summary.',
                'eta_seconds' => 0,
                'elapsed_seconds' => (int) round($durationMs / 1000),
            ]);
        }

        return $payload;
    }

    private function templateSystemPrompt(): string
    {
        return <<<'PROMPT'
You fill a Product & Design Summary template from a Ticket Dossier JSON export.
Use only export evidence plus draft hints. Do not invent facts.
If unknown, use "". Keep each value concise (under ~350 characters).
Return ONLY JSON with this shape (no extra keys):
{
  "product_summary": {
    "product_name": "",
    "purpose": "",
    "core_features": "",
    "value_proposition": "",
    "target_audience": "",
    "app_short_name": ""
  },
  "business_requirements": {
    "business_goals": "",
    "design_choices": "",
    "stakeholder_inputs": "",
    "driving_factors": "",
    "design_constraints": ""
  },
  "key_questions": {
    "ad_groups": "",
    "service_accounts": "",
    "security_exceptions": "",
    "web_url_preferences": ""
  },
  "design_includes": {
    "project_core": "",
    "locations": "",
    "vlans": "",
    "server_specs": "",
    "user_volume": "",
    "vendor_pra": "",
    "install_docs": "",
    "sso": "",
    "aia": "",
    "sla": "",
    "go_live": ""
  },
  "goals": {
    "short_term": "",
    "long_term": ""
  },
  "integrations": {
    "emr_epic": "",
    "lis": ""
  },
  "third_party_review": {
    "tprm": "",
    "technology_review": "",
    "grc_profile": ""
  },
  "owners": {
    "product_owner": "",
    "business_owner": "",
    "support_owner": ""
  },
  "vendor_commitments": {
    "vendor_website": "",
    "vendor_support": "",
    "linux_super_user": ""
  }
}
PROMPT;
    }

    private function reasoningSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an architecture and third-party risk analyst.
Reason only from the Ticket Dossier JSON export. Do not invent facts.
Return ONLY JSON:
{
  "executive_summary": "string",
  "architecture_risks": ["string"],
  "security_gaps": ["string"],
  "open_questions": ["string"],
  "recommended_next_steps": ["string"],
  "evidence_notes": ["string"]
}
Keep lists to at most 5 short bullets each.
PROMPT;
    }

    /**
     * @param callable(int, bool): void $onChars
     * @return array{content: string, model: string, raw: array<string, mixed>}
     */
    private function runJsonChat(string $system, string $user, callable $onChars, int $numPredict): array
    {
        // Non-streaming is more reliable with Qwen on CPU; large streamed requests often return HTTP 500.
        $onChars(0, false);
        try {
            $result = $this->client->chat(
                $this->model,
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                [
                    'temperature' => 0.2,
                    'num_ctx' => 8192,
                    'num_predict' => $numPredict,
                ],
                false,
                'json'
            );
        } catch (Throwable $firstError) {
            try {
                $result = $this->client->chat(
                    $this->model,
                    [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    [
                        'temperature' => 0.2,
                        'num_ctx' => 4096,
                        'num_predict' => max(600, (int) floor($numPredict * 0.7)),
                    ],
                    false,
                    'json'
                );
            } catch (Throwable $retryError) {
                throw new RuntimeException(
                    $firstError->getMessage() . ' (retry also failed: ' . $retryError->getMessage() . ')',
                    0,
                    $retryError
                );
            }
        }

        $onChars(strlen($result['content']), true);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTemplate(string $content): array
    {
        $decoded = $this->parseJsonResponse($content);
        // Pass-1 returns the template object at the top level (no "template" wrapper).
        if (isset($decoded['product_summary']) || isset($decoded['business_requirements'])) {
            return $decoded;
        }
        if (isset($decoded['template']) && is_array($decoded['template'])) {
            return $decoded['template'];
        }

        return $this->salvageTemplate($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function salvageTemplate(string $content): array
    {
        $blob = $content;
        if (preg_match('/"template"\s*:\s*(\{.*)/s', $content, $m) === 1) {
            $blob = $m[1];
        } elseif (preg_match('/(\{\s*"product_summary".*)/s', $content, $m) === 1) {
            $blob = $m[1];
        } else {
            return [];
        }

        $quoteCount = substr_count($blob, '"') - substr_count($blob, '\\"');
        if ($quoteCount % 2 === 1) {
            $blob .= '"';
        }
        $opens = substr_count($blob, '{') - substr_count($blob, '}');
        $blob .= str_repeat('}', max(0, $opens));

        $decoded = json_decode($blob, true);
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['product_summary']) || isset($decoded['business_requirements'])) {
            return $decoded;
        }

        return is_array($decoded['template'] ?? null) ? $decoded['template'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $content): array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return [];
        }

        $candidates = [$trimmed];
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $trimmed, $m) === 1) {
            $candidates[] = $m[1];
        }
        if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            $candidates[] = $m[0];
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'executive_summary' => $trimmed,
            'architecture_risks' => [],
            'security_gaps' => [],
            'open_questions' => [],
            'recommended_next_steps' => [],
            'evidence_notes' => ['Model response was not valid JSON; showing raw text as executive summary.'],
        ];
    }

    private function asString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            $single = $this->asString($value);

            return $single === '' ? [] : [$single];
        }

        $out = [];
        foreach ($value as $item) {
            $text = $this->asString($item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values($out);
    }
}
