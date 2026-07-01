<?php
/**
 * WhatsApp Meta Cloud API — Core Service Layer
 *
 * Handles all WhatsApp messaging via the Meta Cloud API (v20.0).
 * Reads credentials from the .env file in the project root.
 *
 * @package EventManagement
 */

// ─── .env loader ────────────────────────────────────────────────────────────

if (!function_exists('wa_load_env')) {
    function wa_load_env(): void {
        static $loaded = false;
        if ($loaded) return;
        $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile)) { $loaded = true; return; }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if ($key === '') continue;
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
        $loaded = true;
    }
}
wa_load_env();

// ─── Schema Creation ─────────────────────────────────────────────────────────

function ensureWhatsAppSchema(): void {
    global $pdo;
    if (!$pdo) return;

    // WhatsApp templates registry
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trigger_type VARCHAR(60) NOT NULL,
        template_name VARCHAR(120) NOT NULL COMMENT 'Exact name approved on Meta Business Manager',
        category ENUM('utility','marketing','authentication','service') NOT NULL DEFAULT 'utility',
        language_code VARCHAR(10) NOT NULL DEFAULT 'en',
        body_preview TEXT NULL COMMENT 'Preview with {variable} placeholders',
        variables_json TEXT NULL COMMENT 'JSON array of variable names in order',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_wa_trigger (trigger_type),
        INDEX idx_wa_tmpl_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Full message log
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_message_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient VARCHAR(30) NOT NULL COMMENT 'E.164 phone number',
        template_name VARCHAR(120) NULL,
        trigger_type VARCHAR(60) NULL,
        variables_json TEXT NULL,
        status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
        wa_message_id VARCHAR(120) NULL COMMENT 'Message ID from Meta API response',
        error_message TEXT NULL,
        related_type VARCHAR(40) NULL COMMENT 'event/expense/lead/employee/campaign',
        related_id INT NULL,
        user_id INT NULL COMMENT 'Employee user_id if applicable',
        queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL,
        INDEX idx_wa_log_status (status),
        INDEX idx_wa_log_recipient (recipient),
        INDEX idx_wa_log_trigger (trigger_type),
        INDEX idx_wa_log_related (related_type, related_id),
        INDEX idx_wa_log_queued (queued_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Campaigns
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        template_id INT NULL,
        segment ENUM('all_clients','all_employees','custom') NOT NULL DEFAULT 'all_clients',
        custom_phones TEXT NULL COMMENT 'JSON array of phone numbers for custom segment',
        scheduled_at DATETIME NULL,
        status ENUM('draft','scheduled','running','completed','cancelled') NOT NULL DEFAULT 'draft',
        created_by INT NULL,
        festival_name VARCHAR(80) NULL,
        sent_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_wa_camp_status (status),
        INDEX idx_wa_camp_scheduled (scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-recipient campaign tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_campaign_recipients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        recipient VARCHAR(30) NOT NULL,
        name VARCHAR(100) NULL,
        log_id INT NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        sent_at TIMESTAMP NULL,
        INDEX idx_wa_cr_campaign (campaign_id),
        INDEX idx_wa_cr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Deduplication guard
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_duplicate_guard (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guard_key VARCHAR(120) NOT NULL COMMENT 'sha256 of trigger_type+entity_id+recipient+date',
        expires_at DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_wa_guard (guard_key),
        INDEX idx_wa_guard_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Automation rules — per-trigger on/off
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_automation_rules (
        trigger_type VARCHAR(60) NOT NULL PRIMARY KEY,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        send_hour_start TINYINT NOT NULL DEFAULT 9 COMMENT 'Do not send before this hour (24h)',
        send_hour_end TINYINT NOT NULL DEFAULT 21 COMMENT 'Do not send after this hour (24h)',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default automation rules if empty
    $check = $pdo->query("SELECT COUNT(*) as c FROM wa_automation_rules")->fetch();
    if ((int)($check['c'] ?? 0) === 0) {
        $triggers = [
            'expense_approved','expense_rejected','attendance_summary','payroll_summary',
            'event_status_update','feedback_request','lead_welcome','lead_followup',
            'incentive_earned','employee_performance','festival_campaign','event_team_assigned'
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO wa_automation_rules (trigger_type, is_enabled) VALUES (?, 0)");
        foreach ($triggers as $t) {
            $stmt->execute([$t]);
        }
    }

    // Seed default templates if empty
    $tcheck = $pdo->query("SELECT COUNT(*) as c FROM wa_templates")->fetch();
    if ((int)($tcheck['c'] ?? 0) === 0) {
        _wa_seed_default_templates();
    }
}

function _wa_seed_default_templates(): void {
    global $pdo;
    $templates = [
        ['expense_approved',     'expense_status_update',   'utility',   'Hello {{1}}, your expense of {{2}} for {{3}} has been *approved* ✅. It will be included in your next payroll.',       '["employee_name","amount","expense_type"]'],
        ['expense_rejected',     'expense_status_rejected',  'utility',   'Hello {{1}}, your expense of {{2}} for {{3}} has been *rejected* ❌. Reason: {{4}}. Contact admin for details.',    '["employee_name","amount","expense_type","reason"]'],
        ['attendance_summary',   'monthly_attendance_report','utility',   'Hi {{1}}, your attendance for *{{2}}*:\n✅ Present: {{3}} days\n⏰ Late: {{4}} days\n❌ Absent: {{5}} days\n💰 Payable: {{6}}', '["employee_name","month","present","late","absent","payable"]'],
        ['payroll_summary',      'salary_notification',      'utility',   'Hi {{1}}, your salary for *{{2}}* has been calculated.\n💰 Basic: {{3}}\n➖ Deductions: {{4}}\n✅ Net Payable: {{5}}', '["employee_name","month","basic","deductions","net"]'],
        ['event_status_update',  'event_status_update',      'service',   'Event Update 📅\n*{{1}}* status changed to *{{2}}*.\nVenue: {{3}}\nDate: {{4}}',                                     '["event_name","status","venue","date"]'],
        ['event_team_assigned',  'event_team_assigned',      'utility',   'Hi {{1}}, you have been assigned to *{{2}}*.\nVenue: {{3}}\nDate: {{4}}\nYour Role: {{5}}',                           '["employee_name","event_name","venue","date","role"]'],
        ['feedback_request',     'client_feedback_request',  'service',   'Thank you for choosing us for *{{1}}*! 🙏\nWe value your feedback. Please rate your experience:\n{{2}}',               '["event_name","feedback_link"]'],
        ['lead_welcome',         'lead_acknowledgment',      'marketing', 'Hi {{1}}, thank you for your interest in *{{2}}*! 🎉\nOur team will reach out shortly.\nFor quick assistance, reply to this message.', '["lead_name","company_name"]'],
        ['lead_followup',        'lead_followup_reminder',   'marketing', 'Hi {{1}}, following up on your event planning inquiry with *{{2}}*.\nWould you like to schedule a call? We\'d love to help! 📞', '["lead_name","company_name"]'],
        ['incentive_earned',     'incentive_notification',   'utility',   '🎉 Congratulations {{1}}!\nYou\'ve earned an incentive of *{{2}}* for the event *{{3}}*.\nClient Rating: {{4}}/5 ⭐',   '["employee_name","amount","event_name","rating"]'],
        ['employee_performance', 'performance_summary',      'utility',   'Hi {{1}}, your performance report for *{{2}}*:\n⭐ Score: {{3}}/100\n🏅 Grade: {{4}}\n📅 Events: {{5}}\n💰 Incentives: {{6}}', '["employee_name","month","score","grade","events","incentives"]'],
        ['festival_campaign',    'festival_greeting',        'marketing', '🎊 *{{1}}* wishes you and your family a very Happy *{{2}}*! 🎉\nMay this festival bring joy and prosperity.\n\nWith warm regards,\n{{1}} Team', '["company_name","festival_name"]'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO wa_templates (trigger_type, template_name, category, body_preview, variables_json) VALUES (?, ?, ?, ?, ?)");
    foreach ($templates as [$trigger, $name, $cat, $body, $vars]) {
        $stmt->execute([$trigger, $name, $cat, $body, $vars]);
    }
}

// ─── .env Config Accessors ──────────────────────────────────────────────────

function wa_env(string $key, string $default = ''): string {
    return trim((string)($_ENV[$key] ?? getenv($key) ?: $default));
}

function isWhatsAppEnabled(): bool {
    // Check .env master switch first
    $envEnabled = strtolower(wa_env('WHATSAPP_ENABLED', 'false'));
    if ($envEnabled !== 'true' && $envEnabled !== '1') return false;
    // Also check app_settings override (admin toggle)
    $dbEnabled = getAppSetting('wa_enabled', '');
    if ($dbEnabled === '0') return false;
    // Check API credentials present
    if (wa_env('WHATSAPP_API_TOKEN') === '' || wa_env('WHATSAPP_PHONE_NUMBER_ID') === '') return false;
    return true;
}

function wa_getConfig(): array {
    return [
        'phone_number_id'    => wa_env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_token'          => wa_env('WHATSAPP_API_TOKEN'),
        'business_account_id'=> wa_env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'api_version'        => wa_env('WHATSAPP_API_VERSION', 'v20.0'),
        'default_country'    => wa_env('WHATSAPP_DEFAULT_COUNTRY_CODE', '91'),
    ];
}

// ─── Phone Normalization ─────────────────────────────────────────────────────

function wa_formatPhone(string $phone, string $countryCode = '91'): string {
    // Strip everything except digits
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return '';

    // If already has full country code (91 + 10 digits = 12 digits for India)
    $cc = preg_replace('/\D+/', '', $countryCode);
    if (str_starts_with($digits, $cc) && strlen($digits) === strlen($cc) + 10) {
        return $digits;
    }
    // If 10 digits (Indian mobile), prepend country code
    if (strlen($digits) === 10) {
        return $cc . $digits;
    }
    // If starts with 0 (local format), replace with country code
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        return $cc . substr($digits, 1);
    }
    // Return as-is if already long enough
    return $digits;
}

// ─── Rate Limiting & Deduplication ──────────────────────────────────────────

function wa_canSend(string $recipient, string $triggerType, string $relatedId = ''): bool {
    global $pdo;
    if (!$pdo) return false;

    // Check automation rule is enabled
    try {
        $stmt = $pdo->prepare("SELECT is_enabled, send_hour_start, send_hour_end FROM wa_automation_rules WHERE trigger_type = ? LIMIT 1");
        $stmt->execute([$triggerType]);
        $rule = $stmt->fetch();
        if ($rule) {
            if ((int)($rule['is_enabled'] ?? 0) !== 1) return false;
            $hour = (int)date('G');
            if ($hour < (int)$rule['send_hour_start'] || $hour >= (int)$rule['send_hour_end']) return false;
        }
    } catch (PDOException $e) {}

    // Deduplication check (prevent same trigger + entity + recipient same day)
    if ($relatedId !== '') {
        $guardKey = hash('sha256', $triggerType . '|' . $relatedId . '|' . $recipient . '|' . date('Y-m-d'));
        try {
            $pdo->exec("DELETE FROM wa_duplicate_guard WHERE expires_at < CURDATE()");
            $stmt = $pdo->prepare("SELECT id FROM wa_duplicate_guard WHERE guard_key = ? LIMIT 1");
            $stmt->execute([$guardKey]);
            if ($stmt->fetch()) return false;
            // Register this send
            $stmt = $pdo->prepare("INSERT IGNORE INTO wa_duplicate_guard (guard_key, expires_at) VALUES (?, DATE_ADD(CURDATE(), INTERVAL 1 DAY))");
            $stmt->execute([$guardKey]);
        } catch (PDOException $e) {}
    }

    return true;
}

// ─── Template Resolution ─────────────────────────────────────────────────────

function wa_getTemplate(string $triggerType): ?array {
    global $pdo;
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM wa_templates WHERE trigger_type = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$triggerType]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// ─── Core Send Function (Meta Cloud API) ────────────────────────────────────

/**
 * Send a WhatsApp template message via Meta Cloud API.
 *
 * @param string $recipient   Phone number (will be normalized to E.164)
 * @param string $triggerType Internal trigger type key (e.g., 'expense_approved')
 * @param array  $variables   Ordered list of values for template {{1}}, {{2}}, ...
 * @param array  $context     ['related_type' => 'expense', 'related_id' => 5, 'user_id' => 2]
 * @return array ['success' => bool, 'message_id' => string|null, 'error' => string|null, 'log_id' => int|null]
 */
function sendWhatsAppMessage(string $recipient, string $triggerType, array $variables = [], array $context = []): array {
    global $pdo;

    $cfg = wa_getConfig();
    $phone = wa_formatPhone($recipient, $cfg['default_country']);
    if ($phone === '') {
        return ['success' => false, 'message_id' => null, 'error' => 'Invalid phone number', 'log_id' => null];
    }

    // Get template
    $tpl = wa_getTemplate($triggerType);
    if (!$tpl) {
        return ['success' => false, 'message_id' => null, 'error' => "No active template for trigger: $triggerType", 'log_id' => null];
    }

    $templateName = (string)($tpl['template_name'] ?? '');
    $languageCode = (string)($tpl['language_code'] ?? 'en');

    // Log as queued
    $logId = null;
    try {
        $relType = (string)($context['related_type'] ?? '');
        $relId   = isset($context['related_id']) ? (int)$context['related_id'] : null;
        $userId  = isset($context['user_id'])    ? (int)$context['user_id']    : null;
        $stmt = $pdo->prepare("INSERT INTO wa_message_log
            (recipient, template_name, trigger_type, variables_json, status, related_type, related_id, user_id, queued_at)
            VALUES (?, ?, ?, ?, 'queued', ?, ?, ?, NOW())");
        $stmt->execute([$phone, $templateName, $triggerType, json_encode($variables), $relType ?: null, $relId, $userId]);
        $logId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {}

    // Build Meta API payload
    $components = [];
    if (!empty($variables)) {
        $params = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], array_values($variables));
        $components[] = ['type' => 'body', 'parameters' => $params];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'template',
        'template'          => [
            'name'     => $templateName,
            'language' => ['code' => $languageCode],
            'components' => $components,
        ],
    ]);

    $apiUrl = "https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}/messages";

    // Make HTTP request
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_token'],
                'Content-Length: ' . strlen($payload),
            ]),
            'content'       => $payload,
            'timeout'        => 15,
            'ignore_errors' => true,
        ],
    ]);

    $responseRaw = @file_get_contents($apiUrl, false, $ctx);
    $httpCode    = 0;
    $headers = isset($http_response_header) ? $http_response_header : [];
    if (is_array($headers)) {
        foreach ($headers as $h) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $h, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    $response = $responseRaw !== false ? json_decode($responseRaw, true) : null;
    $waMessageId = (string)($response['messages'][0]['id'] ?? '');
    $success     = ($httpCode >= 200 && $httpCode < 300 && $waMessageId !== '');
    $errorMsg    = $success ? null : (string)($response['error']['message'] ?? ($responseRaw !== false ? $responseRaw : 'Request failed'));

    // Update log
    if ($logId && $pdo) {
        try {
            if ($success) {
                $pdo->prepare("UPDATE wa_message_log SET status = 'sent', wa_message_id = ?, sent_at = NOW() WHERE id = ? LIMIT 1")
                    ->execute([$waMessageId, $logId]);
            } else {
                $pdo->prepare("UPDATE wa_message_log SET status = 'failed', error_message = ? WHERE id = ? LIMIT 1")
                    ->execute([substr((string)$errorMsg, 0, 500), $logId]);
            }
        } catch (PDOException $e) {}
    }

    return [
        'success'    => $success,
        'message_id' => $success ? $waMessageId : null,
        'error'      => $errorMsg,
        'log_id'     => $logId,
    ];
}

/**
 * Queue a message for later processing (used by cron batch sending).
 */
function queueWhatsAppMessage(string $recipient, string $triggerType, array $variables = [], array $context = []): ?int {
    global $pdo;
    if (!$pdo) return null;

    $cfg   = wa_getConfig();
    $phone = wa_formatPhone($recipient, $cfg['default_country']);
    if ($phone === '') return null;

    $tpl = wa_getTemplate($triggerType);
    $templateName = $tpl ? (string)($tpl['template_name'] ?? '') : null;

    try {
        $stmt = $pdo->prepare("INSERT INTO wa_message_log
            (recipient, template_name, trigger_type, variables_json, status, related_type, related_id, user_id, queued_at)
            VALUES (?, ?, ?, ?, 'queued', ?, ?, ?, NOW())");
        $stmt->execute([
            $phone, $templateName, $triggerType, json_encode($variables),
            ($context['related_type'] ?? null), ($context['related_id'] ?? null), ($context['user_id'] ?? null),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Process the pending message queue (called by cron or manual admin button).
 * @return array ['processed' => int, 'succeeded' => int, 'failed' => int]
 */
function wa_processQueue(int $limit = 50): array {
    global $pdo;
    $results = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
    if (!$pdo || !isWhatsAppEnabled()) return $results;

    try {
        $stmt = $pdo->prepare("SELECT * FROM wa_message_log WHERE status = 'queued' ORDER BY queued_at ASC LIMIT ?");
        $stmt->execute([$limit]);
        $pending = $stmt->fetchAll();
    } catch (PDOException $e) {
        return $results;
    }

    $cfg = wa_getConfig();
    foreach ($pending as $msg) {
        $results['processed']++;
        $phone    = (string)($msg['recipient'] ?? '');
        $tplName  = (string)($msg['template_name'] ?? '');
        $vars     = json_decode((string)($msg['variables_json'] ?? '[]'), true) ?? [];
        $logId    = (int)($msg['id'] ?? 0);
        $langCode = 'en';

        // Lookup template for language
        $triggerType = (string)($msg['trigger_type'] ?? '');
        $tpl = wa_getTemplate($triggerType);
        if ($tpl) $langCode = (string)($tpl['language_code'] ?? 'en');

        $components = [];
        if (!empty($vars)) {
            $params = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], array_values($vars));
            $components[] = ['type' => 'body', 'parameters' => $params];
        }

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'   => $phone,
            'type' => 'template',
            'template' => [
                'name'       => $tplName,
                'language'   => ['code' => $langCode],
                'components' => $components,
            ],
        ]);

        $apiUrl = "https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}/messages";
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$cfg['api_token']}\r\nContent-Length: " . strlen($payload),
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);

        $responseRaw = @file_get_contents($apiUrl, false, $ctx);
        $httpCode = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $h, $m)) $httpCode = (int)$m[1];
        }
        $response    = $responseRaw !== false ? json_decode($responseRaw, true) : null;
        $waMessageId = (string)($response['messages'][0]['id'] ?? '');
        $success     = ($httpCode >= 200 && $httpCode < 300 && $waMessageId !== '');

        try {
            if ($success) {
                $pdo->prepare("UPDATE wa_message_log SET status = 'sent', wa_message_id = ?, sent_at = NOW() WHERE id = ? LIMIT 1")
                    ->execute([$waMessageId, $logId]);
                $results['succeeded']++;
            } else {
                $errMsg = (string)($response['error']['message'] ?? 'Unknown error');
                $pdo->prepare("UPDATE wa_message_log SET status = 'failed', error_message = ? WHERE id = ? LIMIT 1")
                    ->execute([substr($errMsg, 0, 500), $logId]);
                $results['failed']++;
            }
        } catch (PDOException $e) {}
    }

    return $results;
}

// ─── Automation Rule Helpers ─────────────────────────────────────────────────

function wa_isTriggerEnabled(string $triggerType): bool {
    global $pdo;
    if (!isWhatsAppEnabled()) return false;
    if (!$pdo) return false;
    try {
        $stmt = $pdo->prepare("SELECT is_enabled, send_hour_start, send_hour_end FROM wa_automation_rules WHERE trigger_type = ? LIMIT 1");
        $stmt->execute([$triggerType]);
        $rule = $stmt->fetch();
        if (!$rule || (int)($rule['is_enabled'] ?? 0) !== 1) return false;
        $hour = (int)date('G');
        if ($hour < (int)$rule['send_hour_start'] || $hour >= (int)$rule['send_hour_end']) return false;
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ─── Webhook Delivery Status Update ─────────────────────────────────────────

/**
 * Process incoming webhook status updates from Meta.
 * Call this from your webhook endpoint.
 */
function wa_processWebhook(array $payload): void {
    global $pdo;
    if (!$pdo) return;

    $entries = $payload['entry'] ?? [];
    foreach ($entries as $entry) {
        $changes = $entry['changes'] ?? [];
        foreach ($changes as $change) {
            $statuses = $change['value']['statuses'] ?? [];
            foreach ($statuses as $status) {
                $waId   = (string)($status['id'] ?? '');
                $state  = strtolower((string)($status['status'] ?? ''));
                if ($waId === '' || $state === '') continue;
                $validStates = ['sent', 'delivered', 'read', 'failed'];
                if (!in_array($state, $validStates, true)) continue;
                try {
                    $pdo->prepare("UPDATE wa_message_log SET status = ? WHERE wa_message_id = ?")
                        ->execute([$state, $waId]);
                } catch (PDOException $e) {}
            }
        }
    }
}

// ─── Festival Campaign Data ──────────────────────────────────────────────────

function wa_getIndianFestivals(): array {
    return [
        ['name' => 'Diwali',            'approximate_month' => '10'],
        ['name' => 'Holi',              'approximate_month' => '03'],
        ['name' => 'Eid ul-Fitr',       'approximate_month' => '04'],
        ['name' => 'Eid ul-Adha',       'approximate_month' => '06'],
        ['name' => 'Christmas',         'approximate_month' => '12'],
        ['name' => 'New Year',          'approximate_month' => '01'],
        ['name' => 'Navratri',          'approximate_month' => '10'],
        ['name' => 'Durga Puja',        'approximate_month' => '10'],
        ['name' => 'Raksha Bandhan',    'approximate_month' => '08'],
        ['name' => 'Janmashtami',       'approximate_month' => '08'],
        ['name' => 'Ganesh Chaturthi', 'approximate_month' => '09'],
        ['name' => 'Onam',              'approximate_month' => '09'],
        ['name' => 'Pongal',            'approximate_month' => '01'],
        ['name' => 'Makar Sankranti',   'approximate_month' => '01'],
        ['name' => 'Baisakhi',          'approximate_month' => '04'],
        ['name' => 'Ugadi',             'approximate_month' => '04'],
        ['name' => 'Gudi Padwa',        'approximate_month' => '04'],
        ['name' => 'Mahashivratri',     'approximate_month' => '02'],
        ['name' => 'Ram Navami',        'approximate_month' => '04'],
        ['name' => 'Independence Day',  'approximate_month' => '08'],
        ['name' => 'Republic Day',      'approximate_month' => '01'],
        ['name' => 'Gandhi Jayanti',    'approximate_month' => '10'],
        ['name' => 'Teachers Day',      'approximate_month' => '09'],
        ['name' => 'Valentine\'s Day',  'approximate_month' => '02'],
        ['name' => 'Mother\'s Day',     'approximate_month' => '05'],
    ];
}

// ─── Message Stats Helper ────────────────────────────────────────────────────

function wa_getStats(string $period = 'today'): array {
    global $pdo;
    $result = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'queued' => 0, 'pending_queue' => 0];
    if (!$pdo) return $result;

    $where = match($period) {
        'today'     => "DATE(queued_at) = CURDATE()",
        'week'      => "queued_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        'month'     => "DATE_FORMAT(queued_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')",
        default     => "1=1",
    };

    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM wa_message_log WHERE $where GROUP BY status");
        foreach ($stmt->fetchAll() as $row) {
            $s = (string)($row['status'] ?? '');
            $c = (int)($row['cnt'] ?? 0);
            $result['total'] += $c;
            $result[$s] = ($result[$s] ?? 0) + $c;
        }
        // Pending queue (all time)
        $result['pending_queue'] = (int)($pdo->query("SELECT COUNT(*) FROM wa_message_log WHERE status = 'queued'")->fetchColumn() ?? 0);
    } catch (PDOException $e) {}

    return $result;
}
