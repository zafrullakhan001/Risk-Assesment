<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\EmailOutboxRepository;
use RiskAssessment\Repositories\FindingStatusRepository;

/**
 * Windows Task Scheduler control for exception monitor + email sender workers.
 * Adapted from LinkNest monitor_worker_scheduler_lib.php for PHP CLIs.
 */
final class ExceptionWorkerScheduler
{
    public const TASK_MONITOR = 'monitor';
    public const TASK_EMAIL = 'email';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?FindingStatusRepository $findings = null,
        private readonly ?EmailOutboxRepository $outbox = null,
        private readonly ?SmtpSettings $smtp = null,
    ) {
    }

    public function projectRoot(): string
    {
        $root = realpath($this->projectRoot) ?: $this->projectRoot;

        return rtrim(str_replace('/', '\\', $root), '\\');
    }

    public function folderLabel(): string
    {
        $base = basename(str_replace('\\', '/', $this->projectRoot()));
        $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '', $base);
        $safe = is_string($safe) ? trim($safe, " .-") : '';

        return $safe !== '' ? $safe : 'RiskRegister';
    }

    public function tasksFile(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'exception_worker_tasks.json';
    }

    public function scriptBat(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'schedule-exception-workers.bat';
    }

    public function scriptPs1(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'schedule-exception-workers.ps1';
    }

    /**
     * @return array{monitor?: string, email?: string, interval_minutes?: int}
     */
    public function savedTaskNames(): array
    {
        $file = $this->tasksFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        $out = [];
        if (!empty($json['monitor']) && is_string($json['monitor'])) {
            $out['monitor'] = $json['monitor'];
        }
        if (!empty($json['email']) && is_string($json['email'])) {
            $out['email'] = $json['email'];
        }
        if (isset($json['interval_minutes'])) {
            $out['interval_minutes'] = (int) $json['interval_minutes'];
        }

        return $out;
    }

    public function preferredTaskName(string $kind): string
    {
        $saved = $this->savedTaskNames();
        if ($kind === self::TASK_EMAIL && !empty($saved['email'])) {
            return $saved['email'];
        }
        if ($kind === self::TASK_MONITOR && !empty($saved['monitor'])) {
            return $saved['monitor'];
        }
        $label = $this->folderLabel();

        return $kind === self::TASK_EMAIL
            ? $label . ' - Email Sender'
            : $label . ' - Exception Monitor';
    }

    public function resolvePhpPath(): ?string
    {
        $candidates = [
            'C:\\xampp\\php\\php.exe',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
            PHP_BINARY,
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path) && preg_match('/php(\.exe)?$/i', $path) === 1) {
                return $path;
            }
        }

        return is_file(PHP_BINARY) ? PHP_BINARY : null;
    }

    /**
     * @return array{
     *   ok: bool,
     *   workers_on: bool,
     *   banner: string,
     *   php: array{ok: bool, path: string},
     *   sqlite: array{ok: bool, path: string},
     *   smtp: array{ok: bool, enabled: bool},
     *   due_count: int,
     *   outbox_pending: int,
     *   interval_minutes: int,
     *   bat_path: string,
     *   tasks: array{
     *     monitor: array<string, mixed>,
     *     email: array<string, mixed>
     *   },
     *   hint: string
     * }
     */
    public function status(): array
    {
        $phpPath = $this->resolvePhpPath() ?? '';
        $dbConfig = require $this->projectRoot() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        $dbPath = (string) ($dbConfig['path'] ?? '');
        $smtpEnabled = $this->smtp !== null && $this->smtp->isEnabled();
        $dueCount = 0;
        $outboxPending = 0;
        if ($this->findings !== null) {
            $dueCount = count($this->findings->listDueForReminder(200));
        }
        if ($this->outbox !== null) {
            $outboxPending = $this->outbox->countPending();
        }

        $monitor = $this->probeTask($this->preferredTaskName(self::TASK_MONITOR));
        $email = $this->probeTask($this->preferredTaskName(self::TASK_EMAIL));
        $bothExist = !empty($monitor['exists']) && !empty($email['exists']);
        $bothEnabled = !empty($monitor['enabled']) && !empty($email['enabled']);
        $workersOn = $bothExist && $bothEnabled;

        $banner = 'Task Scheduler — workers off';
        if ($workersOn) {
            $banner = 'Task Scheduler — workers on';
        } elseif ($bothExist) {
            $banner = 'Task Scheduler — workers disabled';
        } elseif (!empty($monitor['exists']) || !empty($email['exists'])) {
            $banner = 'Task Scheduler — partial install';
        }

        $hint = '';
        if (!$bothExist) {
            $hint = 'Use Install tasks below, or run scripts\\schedule-exception-workers.bat';
        } elseif (!$bothEnabled) {
            $hint = 'Tasks are installed but disabled. Enable them (or re-run scripts\\schedule-exception-workers.bat).';
        }

        $saved = $this->savedTaskNames();

        return [
            'ok' => true,
            'workers_on' => $workersOn,
            'banner' => $banner,
            'php' => [
                'ok' => $phpPath !== '' && is_file($phpPath),
                'path' => $phpPath,
            ],
            'sqlite' => [
                'ok' => $dbPath !== '' && is_file($dbPath),
                'path' => $dbPath,
            ],
            'smtp' => [
                'ok' => $smtpEnabled,
                'enabled' => $smtpEnabled,
            ],
            'due_count' => $dueCount,
            'outbox_pending' => $outboxPending,
            'interval_minutes' => (int) ($saved['interval_minutes'] ?? 60),
            'bat_path' => $this->scriptBat(),
            'tasks' => [
                'monitor' => $monitor,
                'email' => $email,
            ],
            'hint' => $hint,
        ];
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function install(int $intervalMinutes = 60, bool $skipSmoke = true): array
    {
        $intervalMinutes = max(15, min(1440, $intervalMinutes));
        $ps1 = $this->scriptPs1();
        if (!is_file($ps1)) {
            return ['ok' => false, 'output' => 'Missing schedule-exception-workers.ps1', 'code' => 1];
        }
        $args = [
            '-NoProfile',
            '-ExecutionPolicy', 'Bypass',
            '-File', $ps1,
            '-Action', 'install',
            '-IntervalMinutes', (string) $intervalMinutes,
            '-Root', $this->projectRoot(),
        ];
        if ($skipSmoke) {
            $args[] = '-SkipSmoke';
        }
        $php = $this->resolvePhpPath();
        if ($php !== null) {
            $args[] = '-Php';
            $args[] = $php;
        }

        return $this->runPowerShell($args);
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function uninstall(): array
    {
        $ps1 = $this->scriptPs1();
        if (!is_file($ps1)) {
            return ['ok' => false, 'output' => 'Missing schedule-exception-workers.ps1', 'code' => 1];
        }

        return $this->runPowerShell([
            '-NoProfile',
            '-ExecutionPolicy', 'Bypass',
            '-File', $ps1,
            '-Action', 'uninstall',
            '-Root', $this->projectRoot(),
        ]);
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function setEnabled(string $kind, bool $enabled): array
    {
        $name = $this->preferredTaskName($kind === self::TASK_EMAIL ? self::TASK_EMAIL : self::TASK_MONITOR);
        $flag = $enabled ? '/ENABLE' : '/DISABLE';
        $cmd = 'schtasks /Change /TN ' . escapeshellarg($name) . ' ' . $flag . ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        return [
            'ok' => $code === 0,
            'output' => trim(implode("\n", $out)),
            'code' => $code,
        ];
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function enableBoth(): array
    {
        $a = $this->setEnabled(self::TASK_MONITOR, true);
        $b = $this->setEnabled(self::TASK_EMAIL, true);

        return [
            'ok' => $a['ok'] && $b['ok'],
            'output' => trim($a['output'] . "\n" . $b['output']),
            'code' => ($a['ok'] && $b['ok']) ? 0 : 1,
        ];
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function disableBoth(): array
    {
        $a = $this->setEnabled(self::TASK_MONITOR, false);
        $b = $this->setEnabled(self::TASK_EMAIL, false);

        return [
            'ok' => $a['ok'] && $b['ok'],
            'output' => trim($a['output'] . "\n" . $b['output']),
            'code' => ($a['ok'] && $b['ok']) ? 0 : 1,
        ];
    }

    /**
     * @return array{ok: bool, output: string, code: int}
     */
    public function runNow(string $kind): array
    {
        $name = $this->preferredTaskName($kind === self::TASK_EMAIL ? self::TASK_EMAIL : self::TASK_MONITOR);
        $cmd = 'schtasks /Run /TN ' . escapeshellarg($name) . ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        return [
            'ok' => $code === 0,
            'output' => trim(implode("\n", $out)),
            'code' => $code,
        ];
    }

    /**
     * Run a worker CLI in-process via php.exe (fallback when schtasks /Run is denied).
     *
     * @return array{ok: bool, output: string, code: int}
     */
    public function runWorkerCli(string $kind): array
    {
        $php = $this->resolvePhpPath();
        if ($php === null) {
            return ['ok' => false, 'output' => 'php.exe not found', 'code' => 1];
        }
        $script = $kind === self::TASK_EMAIL
            ? $this->projectRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'exception_email_sender.php'
            : $this->projectRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'exception_monitor.php';
        if (!is_file($script)) {
            return ['ok' => false, 'output' => 'Worker script missing: ' . $script, 'code' => 1];
        }
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        return [
            'ok' => $code === 0,
            'output' => trim(implode("\n", $out)),
            'code' => $code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeTask(string $taskName): array
    {
        $base = [
            'name' => $taskName,
            'exists' => false,
            'enabled' => false,
            'state' => '',
            'last_run' => '',
            'next_run' => '',
            'logon_type' => '',
            'run_as' => '',
            'repetition_interval' => '',
        ];
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $base['state'] = 'unsupported';

            return $base;
        }

        $cmd = 'schtasks /Query /TN ' . escapeshellarg($taskName) . ' /FO LIST /V 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return $base;
        }
        $text = implode("\n", $out);
        $base['exists'] = true;
        $base['enabled'] = !preg_match('/^Status:\s*Disabled/mi', $text);
        if (preg_match('/^Status:\s*(.+)$/mi', $text, $m)) {
            $base['state'] = trim($m[1]);
        }
        if (preg_match('/^Last Run Time:\s*(.+)$/mi', $text, $m)) {
            $base['last_run'] = trim($m[1]);
        }
        if (preg_match('/^Next Run Time:\s*(.+)$/mi', $text, $m)) {
            $base['next_run'] = trim($m[1]);
        }
        if (preg_match('/^Run As User:\s*(.+)$/mi', $text, $m)) {
            $base['run_as'] = trim($m[1]);
        }

        $detail = $this->queryTaskDetail($taskName);
        if ($detail !== []) {
            $base['logon_type'] = (string) ($detail['logon_type'] ?? '');
            if (!empty($detail['run_as'])) {
                $base['run_as'] = (string) $detail['run_as'];
            }
            if (array_key_exists('enabled', $detail)) {
                $base['enabled'] = (bool) $detail['enabled'];
            }
            $base['repetition_interval'] = (string) ($detail['repetition_interval'] ?? '');
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryTaskDetail(string $taskName): array
    {
        $ps1 = $this->projectRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'query-scheduled-task.ps1';
        if (!is_file($ps1)) {
            return [];
        }
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
            . escapeshellarg($ps1) . ' -TaskName ' . escapeshellarg($taskName) . ' 2>nul';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $json = trim(implode("\n", $out));
        if ($json === '' || $json === '{}') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $args
     * @return array{ok: bool, output: string, code: int}
     */
    private function runPowerShell(array $args): array
    {
        $cmd = 'powershell';
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        return [
            'ok' => $code === 0,
            'output' => trim(implode("\n", $out)),
            'code' => $code,
        ];
    }
}
