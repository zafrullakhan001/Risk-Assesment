<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Mail\EmailTemplates;
use RiskAssessment\Mail\SmtpMailer;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\UserNotificationRepository;
use RiskAssessment\Repositories\UserRepository;

/**
 * Durable in-app notices + optional SMTP email for grant/transfer actions.
 * Access changes always commit; mail failures never roll them back.
 */
final class AccessNotifier
{
    public function __construct(
        private readonly UserNotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly SmtpSettings $smtp,
        private readonly Branding $branding,
    ) {
    }

    /**
     * @param array<string, mixed> $actorUser Current signed-in user row
     * @return array{email_attempted: bool, email_sent: bool, email_note: string, other_label: string}
     */
    public function notifyEditorGrant(
        int $assessmentId,
        string $projectName,
        array $actorUser,
        int $editorUserId
    ): array {
        $actor = Actor::fromUser($actorUser);
        $editor = $this->users->findById($editorUserId);
        if ($editor === null) {
            return $this->emptyResult();
        }

        $editorActor = Actor::fromUser($editor);
        $projectName = trim($projectName) !== '' ? trim($projectName) : 'Untitled project';
        $projectUrl = AppUrl::absolute('index.php?view=1&id=' . max(0, $assessmentId));
        $linkPath = 'index.php?view=1&id=' . max(0, $assessmentId);

        $this->notifications->create(
            $editorUserId,
            UserNotificationRepository::TYPE_EDITOR_GRANTED,
            'Edit access granted',
            $actor['label'] . ' granted you edit access to ' . $projectName . '.',
            $linkPath,
            $assessmentId > 0 ? $assessmentId : null,
            [
                'project_name' => $projectName,
                'other_party' => $actor['label'],
                'other_user_id' => $actor['user_id'],
            ]
        );

        $this->notifications->create(
            $actor['user_id'],
            UserNotificationRepository::TYPE_EDITOR_GRANTED_SENT,
            'Edit access granted',
            'You granted edit access to ' . $projectName . ' for ' . $editorActor['label'] . '.',
            $linkPath,
            $assessmentId > 0 ? $assessmentId : null,
            [
                'project_name' => $projectName,
                'other_party' => $editorActor['label'],
                'other_user_id' => $editorUserId,
            ]
        );

        $this->users->logAudit(
            'access.grant',
            $actor['user_id'],
            $actor['username'],
            $editorUserId,
            $editorActor['username'],
            [
                'assessment_id' => $assessmentId,
                'project_name' => $projectName,
            ]
        );

        $templates = new EmailTemplates($this->branding);
        $toRecipient = $templates->editorAccessGranted($projectName, $projectUrl, $actor['label'], true);
        $toGranter = $templates->editorAccessGranted($projectName, $projectUrl, $editorActor['label'], false);

        return $this->sendPair(
            $actorUser,
            $editor,
            $toGranter,
            $toRecipient,
            $editorActor['label']
        );
    }

    /**
     * @param array<string, mixed> $actorUser Former owner (current user)
     * @param list<string> $projectNames
     * @return array{email_attempted: bool, email_sent: bool, email_note: string, other_label: string}
     */
    public function notifyOwnershipTransfer(
        int $assessmentId,
        string $projectName,
        array $actorUser,
        int $newOwnerUserId,
        array $projectNames = [],
        int $projectCount = 1
    ): array {
        $actor = Actor::fromUser($actorUser);
        $newOwner = $this->users->findById($newOwnerUserId);
        if ($newOwner === null) {
            return $this->emptyResult();
        }

        $newOwnerActor = Actor::fromUser($newOwner);
        $projectCount = max(1, $projectCount);
        $names = $this->cleanNames($projectNames);
        if ($names === [] && trim($projectName) !== '') {
            $names = [trim($projectName)];
        }

        $summary = trim($projectName);
        if ($summary === '') {
            $summary = $projectCount === 1
                ? ($names[0] ?? 'Untitled project')
                : $projectCount . ' projects';
        }

        $singleLink = $assessmentId > 0
            ? 'index.php?view=1&id=' . $assessmentId
            : 'index.php#find-projects';
        $singleUrl = AppUrl::absolute($singleLink);
        $bulkLink = 'index.php#find-projects';
        $linkPath = $projectCount === 1 ? $singleLink : $bulkLink;
        $projectUrl = $projectCount === 1 ? $singleUrl : AppUrl::absolute($bulkLink);

        $receiverBody = $actor['label'] . ' transferred ownership of ' . $summary . ' to you.';
        $senderBody = 'You transferred ownership of ' . $summary . ' to ' . $newOwnerActor['label'] . '.';

        $this->notifications->create(
            $newOwnerUserId,
            UserNotificationRepository::TYPE_OWNERSHIP_RECEIVED,
            $projectCount === 1 ? 'You are the new owner' : 'Ownership received',
            $receiverBody,
            $linkPath,
            $assessmentId > 0 ? $assessmentId : null,
            [
                'project_name' => $summary,
                'project_names' => $names,
                'project_count' => $projectCount,
                'other_party' => $actor['label'],
                'other_user_id' => $actor['user_id'],
            ]
        );

        $this->notifications->create(
            $actor['user_id'],
            UserNotificationRepository::TYPE_OWNERSHIP_SENT,
            'Ownership transferred',
            $senderBody,
            $linkPath,
            $assessmentId > 0 ? $assessmentId : null,
            [
                'project_name' => $summary,
                'project_names' => $names,
                'project_count' => $projectCount,
                'other_party' => $newOwnerActor['label'],
                'other_user_id' => $newOwnerUserId,
            ]
        );

        $this->users->logAudit(
            'access.transfer',
            $actor['user_id'],
            $actor['username'],
            $newOwnerUserId,
            $newOwnerActor['username'],
            [
                'assessment_id' => $assessmentId > 0 ? $assessmentId : null,
                'project_name' => $summary,
                'project_count' => $projectCount,
            ]
        );

        $templates = new EmailTemplates($this->branding);
        $toNewOwner = $templates->ownershipTransferred(
            $summary,
            $projectUrl,
            $actor['label'],
            true,
            $names,
            $projectCount
        );
        $toFormer = $templates->ownershipTransferred(
            $summary,
            $projectUrl,
            $newOwnerActor['label'],
            false,
            $names,
            $projectCount
        );

        return $this->sendPair(
            $actorUser,
            $newOwner,
            $toFormer,
            $toNewOwner,
            $newOwnerActor['label']
        );
    }

    /**
     * @return array{email_attempted: bool, email_sent: bool, email_note: string, other_label: string}
     */
    private function emptyResult(string $otherLabel = ''): array
    {
        return [
            'email_attempted' => false,
            'email_sent' => false,
            'email_note' => '',
            'other_label' => $otherLabel,
        ];
    }

    /**
     * @param array<string, mixed> $actorUser
     * @param array<string, mixed> $otherUser
     * @param array{subject: string, html: string, text: string} $toActor
     * @param array{subject: string, html: string, text: string} $toOther
     * @return array{email_attempted: bool, email_sent: bool, email_note: string, other_label: string}
     */
    private function sendPair(
        array $actorUser,
        array $otherUser,
        array $toActor,
        array $toOther,
        string $otherLabel
    ): array {
        if (!$this->smtp->isEnabled()) {
            return $this->emptyResult($otherLabel);
        }

        $actorEmail = $this->usableEmail((string) ($actorUser['email'] ?? ''));
        $otherEmail = $this->usableEmail((string) ($otherUser['email'] ?? ''));
        if ($actorEmail === null && $otherEmail === null) {
            return [
                'email_attempted' => true,
                'email_sent' => false,
                'email_note' => 'Access updated; email could not be sent (no usable addresses).',
                'other_label' => $otherLabel,
            ];
        }

        $mailer = new SmtpMailer();
        $config = $this->smtp->mailerConfig();
        $sentAny = false;
        $failed = false;

        if ($otherEmail !== null) {
            $result = $mailer->send(
                [$otherEmail],
                $toOther['subject'],
                $toOther['text'],
                $config,
                [],
                ['html' => $toOther['html'], 'text' => $toOther['text']]
            );
            if ($result === true) {
                $sentAny = true;
            } else {
                $failed = true;
            }
        }

        if ($actorEmail !== null) {
            $result = $mailer->send(
                [$actorEmail],
                $toActor['subject'],
                $toActor['text'],
                $config,
                [],
                ['html' => $toActor['html'], 'text' => $toActor['text']]
            );
            if ($result === true) {
                $sentAny = true;
            } else {
                $failed = true;
            }
        }

        if ($sentAny && !$failed && $actorEmail !== null && $otherEmail !== null) {
            return [
                'email_attempted' => true,
                'email_sent' => true,
                'email_note' => 'Email sent to you and ' . $otherLabel . '.',
                'other_label' => $otherLabel,
            ];
        }

        if ($sentAny && !$failed) {
            $who = $otherEmail !== null ? $otherLabel : 'you';
            return [
                'email_attempted' => true,
                'email_sent' => true,
                'email_note' => 'Email sent to ' . $who . '.',
                'other_label' => $otherLabel,
            ];
        }

        return [
            'email_attempted' => true,
            'email_sent' => false,
            'email_note' => 'Access updated; email could not be sent.',
            'other_label' => $otherLabel,
        ];
    }

    private function usableEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        if (str_ends_with(strtolower($email), '@ldap.local')) {
            return null;
        }
        $normalized = SmtpSettings::normalizeRecipients($email);

        return $normalized[0] ?? null;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function cleanNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
