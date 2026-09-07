<?php

declare(strict_types=1);

namespace RiskAssessment\Mail;

use RiskAssessment\AppUrl;
use RiskAssessment\Branding;

/**
 * Outlook-safe HTML email templates (table layout, hosted logo URL, no CID).
 * Adapted from LinkNest email_templates.php.
 */
final class EmailTemplates
{
    public function __construct(
        private readonly ?Branding $branding = null,
    ) {
    }

    public function appName(): string
    {
        $branding = $this->branding ?? Branding::current();
        $title = trim($branding->brandTitle());

        return $title !== '' ? $title : 'Architecture Risk';
    }

    public function accentHex(): string
    {
        return '#0d9488';
    }

    public function logoAbsoluteUrl(): string
    {
        $branding = $this->branding ?? Branding::current();
        $relative = $branding->logoUrl();
        if ($relative === '') {
            return '';
        }
        // logoUrl() may be "../assets/..." from admin or "assets/..." from public.
        $path = preg_replace('#^\.\./#', '', $relative) ?? $relative;
        $path = ltrim(str_replace('\\', '/', $path), '/');
        // Strip query for AppUrl then re-append version.
        $parts = explode('?', $path, 2);
        $file = $parts[0];
        $query = $parts[1] ?? '';
        $url = AppUrl::absolute($file);
        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        return $url;
    }

    /**
     * @param array{logo_url?: string, footer_label?: string, skip_logo?: bool} $options
     */
    public function baseLayout(
        string $title,
        string $subtitle,
        string $accentHex,
        string $innerHtml,
        string $preheader = '',
        ?string $appName = null,
        array $options = []
    ): string {
        $appName = $appName ?? $this->appName();
        $safeTitle = $this->e($title);
        $safeSubtitle = $this->e($subtitle);
        $safePreheader = $this->e($preheader);
        $safeApp = $this->e($appName);
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accentHex) === 1 ? $accentHex : $this->accentHex();
        $footerLabel = trim((string) ($options['footer_label'] ?? ''));
        if ($footerLabel === '') {
            $footerLabel = 'Sent by ' . $appName;
        }

        $logoSrc = '';
        if (empty($options['skip_logo'])) {
            $customLogo = trim((string) ($options['logo_url'] ?? ''));
            $logoSrc = $customLogo !== '' ? $customLogo : $this->logoAbsoluteUrl();
            if (stripos($logoSrc, 'cid:') === 0) {
                $logoSrc = '';
            }
        }

        $logoRow = '';
        if ($logoSrc !== '') {
            $logoRow = '
          <tr>
            <td bgcolor="#ffffff" style="background-color:#ffffff;padding:18px 24px 12px 24px;border-bottom:1px solid #e2e8f0;">
              <img src="' . $this->e($logoSrc) . '" alt="' . $safeApp . '" width="180" border="0" style="display:block;border:0;outline:none;text-decoration:none;height:auto;max-width:180px;width:180px;" />
            </td>
          </tr>';
        }

        $stamp = date('Y-m-d H:i:s');

        return '<!doctype html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no" />
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <style type="text/css">
    table, td, div, span, a { font-family: Arial, Helvetica, sans-serif !important; }
  </style>
  <![endif]-->
  <title>' . $safeTitle . '</title>
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse !important; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    a { color: #2563eb; }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;width:100% !important;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . $safePreheader . '</div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#f1f5f9" style="background-color:#f1f5f9;width:100%;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <!--[if mso]>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td>
        <![endif]-->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background-color:#ffffff;">
          ' . $logoRow . '
          <tr>
            <td bgcolor="' . $accent . '" style="background-color:' . $accent . ';padding:22px 24px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:0 0 8px 0;">
                    ' . $safeApp . '
                  </td>
                </tr>
                <tr>
                  <td style="font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-size:22px;font-weight:700;line-height:28px;padding:0 0 6px 0;">
                    ' . $safeTitle . '
                  </td>
                </tr>
                <tr>
                  <td style="font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-size:14px;font-weight:600;line-height:20px;">
                    ' . $safeSubtitle . '
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td bgcolor="#ffffff" style="background-color:#ffffff;padding:24px;">
              ' . $innerHtml . '
            </td>
          </tr>
          <tr>
            <td bgcolor="#f8fafc" style="background-color:#f8fafc;padding:16px 24px;border-top:1px solid #e2e8f0;">
              <p style="margin:0;font-family:Arial,Helvetica,sans-serif;color:#64748b;font-size:12px;line-height:18px;text-align:center;">
                ' . $this->e($footerLabel) . ' &bull; ' . $this->e($stamp) . '
              </p>
            </td>
          </tr>
        </table>
        <!--[if mso]>
        </td></tr></table>
        <![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    public function ctaButton(string $url, string $label, ?string $accentHex = null): string
    {
        $accent = $accentHex ?? $this->accentHex();
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            $accent = $this->accentHex();
        }

        return '<div style="margin:22px 0 8px 0;">
      <a href="' . $this->e($url) . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;font-family:Segoe UI,Arial,sans-serif;font-size:14px;font-weight:800;text-decoration:none;padding:12px 22px;border-radius:12px;">' . $this->e($label) . '</a>
    </div>
    <div style="font-family:Segoe UI,Arial,sans-serif;font-size:12px;line-height:1.5;color:#64748b;word-break:break-all;">
      Or copy this link: ' . $this->e($url) . '
    </div>';
    }

    /** @return array{html: string, text: string, subject: string} */
    public function smtpTest(?string $toEmail = null): array
    {
        $appName = $this->appName();
        $who = $toEmail !== null && $toEmail !== '' ? $this->e($toEmail) : 'your inbox';
        $stamp = date('Y-m-d H:i:s');
        $inner = '
      <div style="font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
        <div style="font-size:22px;font-weight:900;margin:0 0 10px 0;">Congratulations!</div>
        <div style="font-size:14px;line-height:1.6;color:#334155;">
          Your SMTP email configuration is working correctly. This test message confirms that ' . $this->e($appName) . ' can connect and send mail.
        </div>
        <div style="margin-top:16px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;">
          <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:8px;">Test details</div>
          <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td style="font-size:13px;color:#334155;padding:3px 0;"><strong>Status:</strong></td>
              <td style="font-size:13px;color:#334155;padding:3px 0;" align="right"><span style="display:inline-block;background:#10b981;color:#fff;padding:4px 10px;border-radius:999px;font-weight:800;font-size:12px;">SUCCESS</span></td>
            </tr>
            <tr>
              <td style="font-size:13px;color:#334155;padding:3px 0;"><strong>Recipient:</strong></td>
              <td style="font-size:13px;color:#334155;padding:3px 0;" align="right">' . $who . '</td>
            </tr>
            <tr>
              <td style="font-size:13px;color:#334155;padding:3px 0;"><strong>Time:</strong></td>
              <td style="font-size:13px;color:#334155;padding:3px 0;" align="right">' . $this->e($stamp) . '</td>
            </tr>
          </table>
        </div>
        <div style="margin-top:14px;font-size:13px;line-height:1.6;color:#475569;">
          Next steps: share public assessment, catalog, or owners links by email from the Share panels.
        </div>
      </div>
    ';

        $html = $this->baseLayout(
            'SMTP Configuration Test',
            $appName . ' email delivery check',
            '#6d28d9',
            $inner,
            'SMTP configuration test: success',
            $appName,
            ['footer_label' => 'Sent by ' . $appName]
        );

        $textWho = $toEmail !== null && $toEmail !== '' ? $toEmail : 'your inbox';
        $text = "SMTP Configuration Test\n\n"
            . "Status: SUCCESS\n"
            . "Recipient: {$textWho}\n"
            . "Time: {$stamp}\n\n"
            . "Your SMTP email configuration is working correctly. {$appName} can connect and send mail.\n";

        return [
            'subject' => $appName . ' SMTP Test',
            'html' => $html,
            'text' => $text,
        ];
    }

    /**
     * @param 'assessment'|'catalog'|'owners' $kind
     * @return array{html: string, text: string, subject: string}
     */
    public function shareLink(
        string $kind,
        string $url,
        string $senderName = '',
        string $note = '',
        string $linkLabel = ''
    ): array {
        $appName = $this->appName();
        $accent = $this->accentHex();
        $kindLabel = match ($kind) {
            'catalog' => 'catalog',
            'owners' => 'project owners',
            default => 'assessment',
        };
        $title = 'A public ' . $kindLabel . ' link was shared with you';
        $ctaLabel = match ($kind) {
            'catalog' => 'Open catalog',
            'owners' => 'Open owners board',
            default => 'Open assessment',
        };

        $senderLine = '';
        if (trim($senderName) !== '') {
            $senderLine = '<div style="font-size:14px;line-height:1.6;color:#334155;margin:0 0 12px 0;">'
                . 'Shared by <strong>' . $this->e(trim($senderName)) . '</strong>.'
                . '</div>';
        }

        $labelLine = '';
        if (trim($linkLabel) !== '') {
            $labelLine = '<div style="font-size:13px;color:#475569;margin:0 0 10px 0;">'
                . 'Link tag: <strong>' . $this->e(trim($linkLabel)) . '</strong>'
                . '</div>';
        }

        $noteBlock = '';
        $note = trim($note);
        if ($note !== '') {
            $noteBlock = '<div style="margin:14px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;">'
                . '<div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin:0 0 8px 0;">Message</div>'
                . '<div style="font-size:14px;line-height:1.6;color:#0f172a;white-space:pre-wrap;">' . $this->e($note) . '</div>'
                . '</div>';
        }

        $inner = '
      <div style="font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
        ' . $senderLine . '
        <div style="font-size:14px;line-height:1.6;color:#334155;">
          You can open this read-only public link without signing in. Recipients cannot edit responses, sync folders, or change settings.
        </div>
        ' . $labelLine . '
        ' . $noteBlock . '
        ' . $this->ctaButton($url, $ctaLabel, $accent) . '
      </div>
    ';

        $html = $this->baseLayout(
            $title,
            $appName,
            $accent,
            $inner,
            $title,
            $appName
        );

        $text = $title . "\n\n";
        if (trim($senderName) !== '') {
            $text .= 'Shared by: ' . trim($senderName) . "\n";
        }
        if (trim($linkLabel) !== '') {
            $text .= 'Link tag: ' . trim($linkLabel) . "\n";
        }
        if ($note !== '') {
            $text .= "\nMessage:\n" . $note . "\n";
        }
        $text .= "\nOpen link:\n{$url}\n\n";
        $text .= "This is a read-only public link. You do not need to sign in.\n";

        return [
            'subject' => $appName . ': ' . $title,
            'html' => $html,
            'text' => $text,
        ];
    }

    /**
     * @return array{html: string, text: string, subject: string}
     */
    public function editorAccessGranted(
        string $projectName,
        string $projectUrl,
        string $granterName = '',
        bool $forRecipient = true
    ): array {
        $appName = $this->appName();
        $accent = $this->accentHex();
        $projectName = trim($projectName) !== '' ? trim($projectName) : 'a risk project';
        $granterName = trim($granterName);

        if ($forRecipient) {
            $title = 'Edit access granted';
            $lead = $granterName !== ''
                ? '<strong>' . $this->e($granterName) . '</strong> granted you edit access to <strong>' . $this->e($projectName) . '</strong>.'
                : 'You were granted edit access to <strong>' . $this->e($projectName) . '</strong>.';
            $detail = 'You can open the project, update responses, and upload new workbook versions. Only the owner can lock the project, manage editors, share public links, or delete versions.';
            $cta = 'Open project';
            $textLead = ($granterName !== '' ? $granterName . ' granted you' : 'You were granted')
                . ' edit access to ' . $projectName . '.';
        } else {
            $title = 'Edit access confirmation';
            $lead = 'You granted edit access to <strong>' . $this->e($projectName) . '</strong>'
                . ($granterName !== '' ? ' for <strong>' . $this->e($granterName) . '</strong>.' : '.');
            $detail = 'This email is your record of the grant. You can revoke access anytime from Actions → Access.';
            $cta = 'Open project';
            $textLead = 'You granted edit access to ' . $projectName
                . ($granterName !== '' ? ' for ' . $granterName : '') . '.';
        }

        return $this->accessMessage($title, $lead, $detail, $projectUrl, $cta, $textLead, $appName, $accent);
    }

    /**
     * @param list<string> $projectNames
     * @return array{html: string, text: string, subject: string}
     */
    public function ownershipTransferred(
        string $projectNameOrSummary,
        string $projectUrl,
        string $otherPartyName = '',
        bool $forNewOwner = true,
        array $projectNames = [],
        int $projectCount = 1
    ): array {
        $appName = $this->appName();
        $accent = $this->accentHex();
        $otherPartyName = trim($otherPartyName);
        $projectCount = max(1, $projectCount);
        $summary = trim($projectNameOrSummary);
        if ($summary === '') {
            $summary = $projectCount === 1 ? 'a risk project' : $projectCount . ' risk projects';
        }

        $listHtml = $this->projectListHtml($projectNames);
        $listText = $this->projectListText($projectNames);

        if ($forNewOwner) {
            $title = $projectCount === 1 ? 'You are the new project owner' : 'You received project ownership';
            $lead = $otherPartyName !== ''
                ? '<strong>' . $this->e($otherPartyName) . '</strong> transferred ownership of <strong>' . $this->e($summary) . '</strong> to you.'
                : 'Ownership of <strong>' . $this->e($summary) . '</strong> was transferred to you.';
            $detail = 'You now control edit access, locking, public share links, and deletion for '
                . ($projectCount === 1 ? 'this project' : 'these projects')
                . ' (including all saved versions).';
            $textLead = ($otherPartyName !== '' ? $otherPartyName . ' transferred' : 'Someone transferred')
                . ' ownership of ' . $summary . ' to you.';
        } else {
            $title = $projectCount === 1 ? 'Ownership transfer confirmation' : 'Ownership transfer confirmation';
            $lead = 'You transferred ownership of <strong>' . $this->e($summary) . '</strong>'
                . ($otherPartyName !== '' ? ' to <strong>' . $this->e($otherPartyName) . '</strong>.' : '.');
            $detail = 'This email is your record of the handoff. The new owner can manage access and may keep you as an editor if you opted in.';
            $textLead = 'You transferred ownership of ' . $summary
                . ($otherPartyName !== '' ? ' to ' . $otherPartyName : '') . '.';
        }

        return $this->accessMessage(
            $title,
            $lead . $listHtml,
            $detail,
            $projectUrl,
            $projectCount === 1 ? 'Open project' : 'Open Risk Register',
            $textLead . $listText,
            $appName,
            $accent
        );
    }

    /**
     * @return array{html: string, text: string, subject: string}
     */
    private function accessMessage(
        string $title,
        string $leadHtml,
        string $detail,
        string $url,
        string $ctaLabel,
        string $textLead,
        string $appName,
        string $accent
    ): array {
        $inner = '
      <div style="font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
        <div style="font-size:14px;line-height:1.6;color:#334155;margin:0 0 12px 0;">'
            . $leadHtml .
        '</div>
        <div style="font-size:14px;line-height:1.6;color:#334155;margin:0 0 14px 0;">'
            . $this->e($detail) .
        '</div>
        ' . ($url !== '' ? $this->ctaButton($url, $ctaLabel, $accent) : '') . '
      </div>
    ';

        $html = $this->baseLayout($title, $appName, $accent, $inner, $title, $appName);
        $text = $title . "\n\n" . $textLead . "\n\n" . $detail . "\n";
        if ($url !== '') {
            $text .= "\nOpen:\n{$url}\n";
        }

        return [
            'subject' => $appName . ': ' . $title,
            'html' => $html,
            'text' => $text,
        ];
    }

    /**
     * @param list<string> $projectNames
     */
    private function projectListHtml(array $projectNames): string
    {
        $names = [];
        foreach ($projectNames as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if (count($names) <= 1) {
            return '';
        }

        $shown = array_slice($names, 0, 12);
        $extra = count($names) - count($shown);
        $items = '';
        foreach ($shown as $name) {
            $items .= '<li style="margin:0 0 4px 0;">' . $this->e($name) . '</li>';
        }
        if ($extra > 0) {
            $items .= '<li style="margin:0;">…and ' . (int) $extra . ' more</li>';
        }

        return '<div style="margin:12px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;">'
            . '<div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin:0 0 8px 0;">Projects</div>'
            . '<ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.5;color:#0f172a;">' . $items . '</ul>'
            . '</div>';
    }

    /**
     * @param list<string> $projectNames
     */
    private function projectListText(array $projectNames): string
    {
        $names = [];
        foreach ($projectNames as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if (count($names) <= 1) {
            return '';
        }

        $shown = array_slice($names, 0, 12);
        $extra = count($names) - count($shown);
        $text = "\n\nProjects:\n";
        foreach ($shown as $name) {
            $text .= '- ' . $name . "\n";
        }
        if ($extra > 0) {
            $text .= '- …and ' . $extra . " more\n";
        }

        return $text;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
