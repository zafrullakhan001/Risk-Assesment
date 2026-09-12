<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Mail\EmailTemplates;
use RiskAssessment\Mail\SmtpMailer;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\AssessmentAccessRepository;
use RiskAssessment\Repositories\EmailOutboxRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\UserNotificationRepository;
use RiskAssessment\Repositories\UserRepository;

/**
 * In-app notices + email outbox queue for due/overdue governance exceptions.
 * Monitor never sends SMTP; Email Sender drains the outbox.
 */
final class ExceptionNotifier
{
    public function __construct(
        private readonly FindingStatusRepository $findings,
        private readonly UserNotificationRepository $notifications,
        private readonly AssessmentAccessRepository $access,
        private readonly UserRepository $users,
        private readonly EmailOutboxRepository $outbox,
        private readonly Branding $branding,
    ) {
    }

    /**
     * Create in-app notices + queue emails for one due exception, then mark reminded_at.
     *
     * @param array{
     *   assessment_id: int,
     *   finding_id: string,
     *   status: string,
     *   expires_at: string,
     *   project_name: string,
     *   finding_text: string,
     *   owner_user_id: int,
     *   notify_emails?: list<string>|string,
     *   servicenow_links?: list<string>|string
     * } $item
     * @return array{notified_users: int, emails_queued: int, marked: bool}
     */
    public function queueDueItem(array $item): array
    {
        $assessmentId = (int) ($item['assessment_id'] ?? 0);
        $findingId = trim((string) ($item['finding_id'] ?? ''));
        $expiresAt = trim((string) ($item['expires_at'] ?? ''));
        $projectName = trim((string) ($item['project_name'] ?? '')) !== ''
            ? trim((string) $item['project_name'])
            : 'Untitled project';
        $findingText = trim((string) ($item['finding_text'] ?? $findingId));
        $status = FindingStatusRepository::normalizeStatus((string) ($item['status'] ?? 'Open'));
        $ownerUserId = (int) ($item['owner_user_id'] ?? 0);
        $serviceNowLinks = FindingStatusRepository::normalizeLinks($item['servicenow_links'] ?? []);

        if ($assessmentId <= 0 || $findingId === '' || $expiresAt === '') {
            return [
                'notified_users' => 0,
                'emails_queued' => 0,
                'marked' => false,
            ];
        }

        $linkPath = 'index.php?view=1&id=' . $assessmentId . '&tab=actions&action_tab=exceptions#exception-tracker';
        $projectUrl = AppUrl::absolute($linkPath);
        $overdue = $expiresAt < date('Y-m-d');
        $title = $overdue ? 'Exception overdue' : 'Exception due today';
        $body = $projectName . ': ' . $findingText . ' (expiry ' . $expiresAt . ').';

        $recipientIds = [];
        if ($ownerUserId > 0) {
            $recipientIds[$ownerUserId] = true;
        }
        foreach ($this->access->listEditors($assessmentId) as $editor) {
            $uid = (int) ($editor['user_id'] ?? 0);
            if ($uid > 0) {
                $recipientIds[$uid] = true;
            }
        }

        $notified = 0;
        $queued = 0;
        $templates = new EmailTemplates($this->branding);
        $queuedEmailKeys = [];

        foreach (array_keys($recipientIds) as $userId) {
            $this->notifications->create(
                $userId,
                UserNotificationRepository::TYPE_EXCEPTION_DUE,
                $title,
                $body,
                $linkPath,
                $assessmentId,
                [
                    'project_name' => $projectName,
                    'finding_id' => $findingId,
                    'finding_text' => $findingText,
                    'expires_at' => $expiresAt,
                    'status' => $status,
                ]
            );
            $notified++;

            $user = $this->users->findById($userId);
            if ($user === null) {
                continue;
            }
            $email = $this->usableEmail((string) ($user['email'] ?? ''));
            if ($email === null) {
                continue;
            }
            $message = $templates->exceptionDue(
                $projectName,
                $projectUrl,
                $findingText,
                $expiresAt,
                $status,
                $serviceNowLinks
            );
            $id = $this->outbox->enqueue(
                EmailOutboxRepository::KIND_EXCEPTION_DUE,
                $email,
                $message['subject'],
                $message['text'],
                $message['html'],
                $userId,
                $assessmentId,
                $findingId,
                [
                    'project_name' => $projectName,
                    'finding_text' => $findingText,
                    'expires_at' => $expiresAt,
                    'status' => $status,
                    'servicenow_links' => $serviceNowLinks,
                    'recipient_kind' => 'project_member',
                ]
            );
            if ($id > 0) {
                $queued++;
                $queuedEmailKeys[strtolower($email)] = true;
            }
        }

        $clientEmails = FindingStatusRepository::normalizeNotifyEmails($item['notify_emails'] ?? []);
        if ($clientEmails !== []) {
            $message = $templates->exceptionDue(
                $projectName,
                $projectUrl,
                $findingText,
                $expiresAt,
                $status,
                $serviceNowLinks
            );
            foreach ($clientEmails as $email) {
                $key = strtolower($email);
                if (isset($queuedEmailKeys[$key])) {
                    continue;
                }
                $id = $this->outbox->enqueue(
                    EmailOutboxRepository::KIND_EXCEPTION_DUE,
                    $email,
                    $message['subject'],
                    $message['text'],
                    $message['html'],
                    null,
                    $assessmentId,
                    $findingId,
                    [
                        'project_name' => $projectName,
                        'finding_text' => $findingText,
                        'expires_at' => $expiresAt,
                        'status' => $status,
                        'servicenow_links' => $serviceNowLinks,
                        'recipient_kind' => 'client',
                    ]
                );
                if ($id > 0) {
                    $queued++;
                    $queuedEmailKeys[$key] = true;
                }
            }
        }

        $marked = $this->findings->markReminded($assessmentId, $findingId);

        return [
            'notified_users' => $notified,
            'emails_queued' => $queued,
            'marked' => $marked,
        ];
    }

    /**
     * Drain pending exception emails via SMTP.
     *
     * @return array{attempted: int, sent: int, failed: int, skipped_smtp: bool}
     */
    public function sendPendingEmails(SmtpSettings $smtp, int $limit = 25): array
    {
        if (!$smtp->isEnabled()) {
            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped_smtp' => true,
            ];
        }

        $pending = $this->outbox->listPending($limit, EmailOutboxRepository::KIND_EXCEPTION_DUE);
        if ($pending === []) {
            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped_smtp' => false,
            ];
        }

        $mailer = new SmtpMailer();
        $config = $smtp->mailerConfig();
        $sent = 0;
        $failed = 0;

        foreach ($pending as $row) {
            $id = (int) ($row['id'] ?? 0);
            $to = trim((string) ($row['to_email'] ?? ''));
            if ($id <= 0 || $to === '') {
                continue;
            }
            $result = $mailer->send(
                [$to],
                (string) ($row['subject'] ?? ''),
                (string) ($row['body_text'] ?? ''),
                $config,
                [],
                [
                    'html' => (string) ($row['body_html'] ?? ''),
                    'text' => (string) ($row['body_text'] ?? ''),
                ]
            );
            if ($result === true) {
                $this->outbox->markSent($id);
                $sent++;
            } else {
                $attempts = (int) ($row['attempts'] ?? 0);
                $backoff = min(240, 15 * (2 ** max(0, $attempts)));
                $this->outbox->markFailed($id, is_string($result) ? $result : 'Send failed', $backoff);
                $failed++;
            }
        }

        return [
            'attempted' => count($pending),
            'sent' => $sent,
            'failed' => $failed,
            'skipped_smtp' => false,
        ];
    }

    /** @deprecated Use queueDueItem + sendPendingEmails */
    public function notifyDueItem(array $item): array
    {
        $queued = $this->queueDueItem($item);

        return [
            'notified_users' => $queued['notified_users'],
            'email_attempted' => $queued['emails_queued'] > 0,
            'email_sent' => 0,
            'emails_queued' => $queued['emails_queued'],
            'marked' => $queued['marked'],
        ];
    }

    private function usableEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        if (str_ends_with(strtolower($email), '@ldap.local')) {
            return null;
        }

        return $email;
    }
}
