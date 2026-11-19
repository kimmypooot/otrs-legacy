<?php
/**
 * imis/otrs/inc/email_template_engine.php
 *
 * Centralized template engine for OTRS HRD email notifications.
 * Fetches templates from otrs_email_templates, substitutes {variable}
 * placeholders, and wraps with the standard CSC RO VIII email chrome.
 *
 * All functions are guarded with function_exists() so this file
 * may be require_once'd from multiple callers without conflict.
 */

// ──────────────────────────────────────────────────────────────────
// Template retrieval
// ──────────────────────────────────────────────────────────────────

if (!function_exists('getEmailTemplate')) {
    /**
     * Fetch an active template by its unique code.
     * Returns the full row array, or null if not found / inactive.
     */
    function getEmailTemplate(string $code, PDO $conn): ?array
    {
        try {
            $stmt = $conn->prepare("
                SELECT id, template_code, template_name, badge_label,
                       email_subject, email_body, email_altbody, status, version
                FROM   otrs_email_templates
                WHERE  template_code = :code
                LIMIT  1
            ");
            $stmt->execute([':code' => $code]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log('[email_template_engine] getEmailTemplate: ' . $e->getMessage());
            return null;
        }
    }
}

// ──────────────────────────────────────────────────────────────────
// Variable substitution
// ──────────────────────────────────────────────────────────────────

if (!function_exists('etReplace')) {
    /** Replace {key} placeholders with $vars values. */
    function etReplace(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $text = str_replace('{' . $key . '}', (string) $value, $text);
            }
        }
        // Sanitize any unreplaced placeholders so they don't leak
        return preg_replace('/\{[a-z_][a-z0-9_]*\}/i', '', $text);
    }
}

// ──────────────────────────────────────────────────────────────────
// Full render
// ──────────────────────────────────────────────────────────────────

if (!function_exists('renderEmailTemplate')) {
    /**
     * Render a template row with variable substitution and email chrome.
     *
     * $vars keys mirror all supported {variable} placeholders.
     * Block-level variables ({bank_details_block}, {penalty_note}, etc.)
     * are auto-computed from email_template.php helpers; callers may
     * override them by passing pre-rendered HTML in $vars.
     *
     * Returns ['subject' => '...', 'body' => '...', 'altbody' => '...']
     */
    function renderEmailTemplate(array $template, array $vars): array
    {
        // email_template.php must already be loaded by the caller (or we load it here)
        if (!function_exists('hrdEmailBankBlock')) {
            require_once __DIR__ . '/email_template.php';
        }

        // Built-in block variables (caller may override any of these)
        $builtIn = [
            'bank_details_block'  => hrdEmailBankBlock(),
            'penalty_note'        => hrdEmailPenaltyNote(),
            'contact_line'        => hrdEmailContact(),
            'signature_block'     => hrdEmailSignature(),
        ];

        // Caller vars take precedence over built-ins
        $allVars = array_merge($builtIn, $vars);

        // Substitute in subject and bodies
        $subject  = etReplace($template['email_subject'], $allVars);
        $innerHtml = etReplace($template['email_body'],   $allVars);
        $altBody  = etReplace($template['email_altbody'], $allVars);

        // Wrap inner content in full email chrome
        $badge    = $template['badge_label'] ?? '';
        $bodyRows = hrdEmailHeader($badge);
        $bodyRows .= "<tr><td style='padding:30px 36px 24px;'>{$innerHtml}</td></tr>";
        $bodyRows .= hrdEmailFooter();
        $fullHtml = hrdEmailWrap($bodyRows);

        return [
            'subject' => $subject,
            'body'    => $fullHtml,
            'altbody' => $altBody,
        ];
    }
}

// ──────────────────────────────────────────────────────────────────
// Audit logging
// ──────────────────────────────────────────────────────────────────

if (!function_exists('logEmailTemplateAction')) {
    /**
     * Write an audit entry to otrs_email_template_logs.
     * Silently swallows DB errors so it never interrupts a send flow.
     */
    function logEmailTemplateAction(
        PDO    $conn,
        int    $templateId,
        string $templateCode,
        string $action,
        string $performedBy,
        string $details   = '',
        string $ipAddress = ''
    ): void {
        try {
            $conn->prepare("
                INSERT INTO otrs_email_template_logs
                    (template_id, template_code, action, performed_by, performed_at, details, ip_address)
                VALUES (:tid, :code, :action, :by, NOW(), :details, :ip)
            ")->execute([
                ':tid'     => $templateId,
                ':code'    => $templateCode,
                ':action'  => $action,
                ':by'      => $performedBy,
                ':details' => $details,
                ':ip'      => $ipAddress,
            ]);
        } catch (PDOException $e) {
            error_log('[email_template_engine] logEmailTemplateAction: ' . $e->getMessage());
        }
    }
}

// ──────────────────────────────────────────────────────────────────
// Version archiving
// ──────────────────────────────────────────────────────────────────

if (!function_exists('archiveEmailTemplateVersion')) {
    /**
     * Snapshot the current template state into otrs_email_template_versions
     * before overwriting it with an update.
     */
    function archiveEmailTemplateVersion(PDO $conn, array $current, string $changedBy, string $note = ''): void
    {
        try {
            $conn->prepare("
                INSERT INTO otrs_email_template_versions
                    (template_id, version, template_name, badge_label,
                     email_subject, email_body, email_altbody, changed_by, changed_at, change_note)
                VALUES
                    (:tid, :ver, :name, :badge, :subj, :body, :alt, :by, NOW(), :note)
            ")->execute([
                ':tid'   => $current['id'],
                ':ver'   => $current['version'],
                ':name'  => $current['template_name'],
                ':badge' => $current['badge_label'],
                ':subj'  => $current['email_subject'],
                ':body'  => $current['email_body'],
                ':alt'   => $current['email_altbody'],
                ':by'    => $changedBy,
                ':note'  => $note,
            ]);
        } catch (PDOException $e) {
            error_log('[email_template_engine] archiveEmailTemplateVersion: ' . $e->getMessage());
        }
    }
}

// ──────────────────────────────────────────────────────────────────
// Sample data for preview / test-send
// ──────────────────────────────────────────────────────────────────

if (!function_exists('etSampleVars')) {
    /**
     * Returns a complete set of placeholder values with realistic sample data.
     * Used by the preview and test-send endpoints.
     */
    function etSampleVars(): array
    {
        if (!function_exists('hrdEmailBankBlock')) {
            require_once __DIR__ . '/email_template.php';
        }
        return [
            'participant_name'    => 'Juan Dela Cruz',
            'training_title'      => 'Supervisory Development Course (SDC)',
            'training_date'       => 'June 23&ndash;27, 2025',
            'training_schedule'   => 'June 23–27, 2025',
            'venue'               => 'CSC RO VIII Training Room, Leyte Academic Center, Palo, Leyte',
            'payment_amount'      => 'Php 3,500.00',
            'payment_deadline'    => 'June 13, 2025',
            'payment_status'      => 'Pending',
            'reference_number'    => 'OTRS-2025-00123',
            'agency'              => 'Department of Education – Division of Leyte',
            'evaluation_link'     => 'https://otrs.cscro8.com/evaluation_form.php?reg_id=1&day=1&sig=sample',
            'training_link'       => 'https://zoom.us/j/00000000000',
            'contact_person'      => 'Jay M. Merelos',
            'contact_email'       => 'hrd@cscro8.com',
            // Block overrides (leave empty to auto-use helpers)
            'bank_details_block'  => hrdEmailBankBlock(),
            'penalty_note'        => hrdEmailPenaltyNote(),
            'contact_line'        => hrdEmailContact(),
            'signature_block'     => hrdEmailSignature(),
            // Composite blocks (for ack and advisory)
            'registration_link_block' => "
              <p style='margin:0 0 16px;font-size:14px;color:#333;line-height:1.7;'>
                Please send your documents through the registration link below:
              </p>
              <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 20px;text-align:center;'>
                <tr><td>
                  <a href='https://otrs.cscro8.com/proof_payment'
                     style='display:inline-block;background-color:#012970;color:#ffffff;font-size:13px;font-weight:700;padding:12px 28px;border-radius:5px;text-decoration:none;'>
                    Send Your Attachments Here
                  </a>
                </td></tr>
              </table>",
            'payment_status_badge' => "
              <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
                <tr><td style='background-color:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;'>
                  <p style='margin:0 0 6px;font-size:13px;font-weight:700;color:#856404;'>Payment Status</p>
                  <p style='margin:0;font-size:13px;color:#856404;line-height:1.7;'>
                    Your registration fee of <b>Php 3,500.00</b> is currently tagged as: <b><u>Pending</u></b>.
                  </p>
                  <p style='margin:8px 0 0;font-size:13px;color:#856404;line-height:1.7;'>
                    Please settle your payment on or before <b>June 13, 2025</b>, or present a <b>Promissory Note</b>.
                  </p>
                </td></tr>
              </table>",
            'training_info_block' => "
              <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 20px;'>
                <tr><td style='background-color:#eaf1fb;border-left:4px solid #012970;border-radius:4px;padding:14px 18px;'>
                  <p style='margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#012970;'>Training Details</p>
                  <table cellpadding='0' cellspacing='0' style='font-size:13px;color:#1e3a5f;'>
                    <tr><td style='padding:2px 10px 2px 0;font-weight:700;'>Training:</td><td>Supervisory Development Course (SDC)</td></tr>
                    <tr><td style='padding:2px 10px 2px 0;font-weight:700;'>Date:</td><td>June 23&ndash;27, 2025</td></tr>
                    <tr><td style='padding:2px 10px 2px 0;font-weight:700;'>Venue:</td><td>CSC RO VIII Training Room, Palo, Leyte</td></tr>
                  </table>
                </td></tr>
              </table>",
            'evaluation_links_table' => "
              <table width='100%' cellpadding='0' cellspacing='0'
                     style='border:1px solid #d0dff0;border-radius:6px;overflow:hidden;margin-bottom:20px;'>
                <thead>
                  <tr style='background-color:#eaf1fb;'>
                    <th style='padding:10px 14px;font-size:12px;font-weight:700;text-align:left;color:#012970;text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #d0dff0;'>Session</th>
                    <th style='padding:10px 14px;font-size:12px;font-weight:700;text-align:left;color:#012970;text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #d0dff0;'>Evaluation Link</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style='padding:10px 14px;font-size:13px;color:#333;border-bottom:1px solid #d0dff0;font-weight:700;'>Day 1</td>
                    <td style='padding:10px 14px;font-size:13px;border-bottom:1px solid #d0dff0;'>
                      <a href='https://otrs.cscro8.com/evaluation_form.php?reg_id=1&day=1&sig=sample' style='color:#012970;'>
                        https://otrs.cscro8.com/evaluation_form.php?reg_id=1&day=1&sig=sample
                      </a>
                    </td>
                  </tr>
                </tbody>
              </table>",
            'training_link_block' => "
              <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 18px;'>
                <tr><td style='background-color:#eaf7f0;border-left:4px solid #28a745;border-radius:4px;padding:14px 18px;'>
                  <p style='margin:0 0 8px;font-size:13px;font-weight:700;color:#1a5c32;'>Your Access Link</p>
                  <a href='https://zoom.us/j/00000000000'
                     style='display:inline-block;background-color:#012970;color:#ffffff;font-size:13px;font-weight:700;padding:10px 22px;border-radius:4px;text-decoration:none;margin-bottom:8px;'>
                    Join Training
                  </a>
                  <p style='margin:8px 0 0;font-size:11px;color:#555;'>https://zoom.us/j/00000000000</p>
                </td></tr>
              </table>",
        ];
    }
}
