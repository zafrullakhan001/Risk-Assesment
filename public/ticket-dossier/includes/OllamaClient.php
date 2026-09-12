<?php

declare(strict_types=1);

/**
 * Minimal HTTP client for a local Ollama instance.
 */
final class OllamaClient
{
    public function __construct(
        private readonly string $baseUrl = TD_OLLAMA_BASE_URL,
        private readonly int $timeoutSeconds = TD_GEMMA_TIMEOUT_SECONDS
    ) {
    }

    public function isReachable(): bool
    {
        try {
            $response = $this->request('GET', '/api/tags', null, 5);
        } catch (Throwable) {
            return false;
        }

        return isset($response['models']) && is_array($response['models']);
    }

    /**
     * @return list<array{name: string, size?: int|float, modified_at?: string}>
     */
    public function listModels(): array
    {
        $response = $this->request('GET', '/api/tags');
        $models = $response['models'] ?? [];
        if (!is_array($models)) {
            return [];
        }

        $out = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = trim((string) ($model['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'size' => isset($model['size']) ? (float) $model['size'] : null,
                'modified_at' => isset($model['modified_at']) ? (string) $model['modified_at'] : null,
            ];
        }

        return $out;
    }

    public function hasModel(string $model): bool
    {
        $needle = strtolower(trim($model));
        if ($needle === '') {
            return false;
        }

        foreach ($this->listModels() as $item) {
            $name = strtolower($item['name']);
            if ($name === $needle || str_starts_with($name, $needle . ':')) {
                return true;
            }
            // qwen3.5:4b matches qwen3.5:4b and base prefix forms
            $base = explode(':', $name, 2)[0];
            if ($base === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Non-streaming chat completion.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @param array<string, mixed>|string|null $format Pass 'json' or a JSON schema object for structured output
     * @return array{content: string, model: string, raw: array<string, mixed>}
     */
    public function chat(
        string $model,
        array $messages,
        array $options = [],
        bool $think = false,
        array|string|null $format = null
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'think' => $think,
            'options' => $options === [] ? (object) [] : $options,
        ];
        if ($format !== null) {
            $payload['format'] = $format;
        }

        $response = $this->request('POST', '/api/chat', $payload);
        $message = $response['message'] ?? null;
        $content = '';
        if (is_array($message)) {
            $content = trim((string) ($message['content'] ?? ''));
            // Some thinking models may leave content empty while filling thinking.
            if ($content === '' && isset($message['thinking'])) {
                $content = trim((string) $message['thinking']);
            }
        }

        return [
            'content' => $content,
            'model' => (string) ($response['model'] ?? $model),
            'raw' => $response,
        ];
    }

    /**
     * Streaming chat. Invokes $onChunk for each Ollama NDJSON frame.
     * $onChunk receives array{content_delta?: string, done?: bool, raw: array<string, mixed>}.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @param callable(array{content_delta?: string, done?: bool, raw: array<string, mixed>}): void $onChunk
     * @param array<string, mixed>|string|null $format
     * @return array{content: string, model: string, raw: array<string, mixed>}
     */
    public function chatStream(
        string $model,
        array $messages,
        callable $onChunk,
        array $options = [],
        bool $think = false,
        array|string|null $format = null
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'think' => $think,
            'options' => $options === [] ? (object) [] : $options,
        ];
        if ($format !== null) {
            $payload['format'] = $format;
        }

        $url = rtrim($this->baseUrl, '/') . '/api/chat';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to start HTTP client for Ollama.');
        }

        $buffer = '';
        $content = '';
        $modelName = $model;
        $lastRaw = [];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/x-ndjson',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$content, &$modelName, &$lastRaw, $onChunk): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $lastRaw = $decoded;
                    if (isset($decoded['model']) && is_string($decoded['model']) && $decoded['model'] !== '') {
                        $modelName = $decoded['model'];
                    }
                    $delta = '';
                    $message = $decoded['message'] ?? null;
                    if (is_array($message)) {
                        $delta = (string) ($message['content'] ?? '');
                    }
                    if ($delta !== '') {
                        $content .= $delta;
                    }
                    $onChunk([
                        'content_delta' => $delta,
                        'done' => !empty($decoded['done']),
                        'raw' => $decoded,
                    ]);
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            throw new RuntimeException(
                'Ollama stream failed at ' . $this->baseUrl . '. '
                . ($error !== '' ? $error : 'Start Ollama and pull your configured model (TD_OLLAMA_MODEL).')
            );
        }
        if ($status >= 400) {
            $detail = '';
            if (isset($lastRaw['error'])) {
                $detail = ' — ' . (string) $lastRaw['error'];
            } elseif ($buffer !== '') {
                $maybe = json_decode($buffer, true);
                if (is_array($maybe) && isset($maybe['error'])) {
                    $detail = ' — ' . (string) $maybe['error'];
                }
            }
            throw new RuntimeException('Ollama stream error: HTTP ' . $status . $detail);
        }

        $content = trim($content);
        if ($content === '' && isset($lastRaw['message']) && is_array($lastRaw['message'])) {
            $content = trim((string) ($lastRaw['message']['content'] ?? ''));
            if ($content === '' && isset($lastRaw['message']['thinking'])) {
                $content = trim((string) $lastRaw['message']['thinking']);
            }
        }

        return [
            'content' => $content,
            'model' => $modelName,
            'raw' => $lastRaw,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, ?int $timeoutOverride = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $timeout = $timeoutOverride ?? $this->timeoutSeconds;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to start HTTP client for Ollama.');
        }

        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException(
                'Ollama is not reachable at ' . $this->baseUrl . '. '
                . ($error !== '' ? $error : 'Start Ollama and pull your configured model (TD_OLLAMA_MODEL).')
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ollama returned an invalid JSON response (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $msg = (string) ($decoded['error'] ?? ('HTTP ' . $status));
            throw new RuntimeException('Ollama error: ' . $msg);
        }

        return $decoded;
    }
}
