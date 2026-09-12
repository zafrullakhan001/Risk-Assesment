<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Mail\EmailTemplates;
use RiskAssessment\Mail\SmtpMailer;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\AssessmentAccessRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\UserNotificationRepository;
use RiskAssessment\Repositories\UserRepository;

/**
 * In-app notices + optional SMTP for due/overdue governance exceptions.
 * Reminder job always commits in-app rows; mail failures never block marking reminded.
 */
final class ExceptionNotifier
{
    public function __construct(
        private readonly FindingStatusRepository $findings,
        private readonly UserNotificationRepository $notifications,
        private readonly AssessmentAccessRepository $access,
        private readonly UserRepository $users,
        private readonly SmtpSettings $smtp,
        private readonly Branding $branding,
    ) {
    }

    /**
     * Notify owner + editors for one due exception, then mark reminded_at.
     *
     * @param array{
     *   assessment_id: int,
     *   finding_id: string,
     *   status: string,
     *   expires_at: string,
     *   project_name: string,
     *   finding_text: string,
     *   owner_user_id: int
     * } $item
     * @return array{notified_users: int, email_attempted: bool, email_sent: int, marked: bool}
     */
    public function notifyDueItem(array $item): array
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

        if ($assessmentId <= 0 || $findingId === '' || $expiresAt === '') {
            return [
                'notified_users' => 0,
                'email_attempted' => false,
                'email_sent' => 0,
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
        $emailAttempted = false;
        $emailSent = 0;
        $templates = new EmailTemplates($this->branding);
        $mailer = $this->smtp->isEnabled() ? new SmtpMailer() : null;
        $config = $mailer !== null ? $this->smtp->mailerConfig() : [];

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

            if ($mailer === null) {
                continue;
            }
            $user = $this->users->findById($userId);
            if ($user === null) {
                continue;
            }
            $email = $this->usableEmail((string) ($user['email'] ?? ''));
            if ($email === null) {
                continue;
            }
            $emailAttempted = true;
            $message = $templates->exceptionDue(
                $projectName,
                $projectUrl,
                $findingText,
                $expiresAt,
                $status
            );
            $result = $mailer->send(
                [$email],
                $message['subject'],
                $message['text'],
                $config,
                [],
                ['html' => $message['html'], 'text' => $message['text']]
            );
            if ($result === true) {
                $emailSent++;
            }
        }

        $marked = $this->findings->markReminded($assessmentId, $findingId);

        return [
            'notified_users' => $notified,
            'email_attempted' => $emailAttempted,
            'email_sent' => $emailSent,
            'marked' => $marked,
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
