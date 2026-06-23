<?php
/**
 * WhatsApp Cron Job Script
 * 
 * Run this script periodically via Windows Task Scheduler or cron.
 * E.g., php c:\xampp\htdocs\event_management\whatsapp_cron.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/whatsapp.php';

// Prevent web access
if (php_sapi_name() !== 'cli') {
    // Optional: allow web access if a specific token is provided
    $token = $_GET['token'] ?? '';
    if ($token !== wa_env('WHATSAPP_WEBHOOK_VERIFY_TOKEN')) {
        http_response_code(403);
        die("Access denied. Run from CLI or provide valid token.");
    }
}

echo "Starting WhatsApp Cron Tasks...\n";
$timeStart = microtime(true);

// 1. Process queued messages
echo "1. Processing queued messages...\n";
$results = wa_processQueue(100); // Process up to 100 messages per run
echo "   Processed: {$results['processed']}, Succeeded: {$results['succeeded']}, Failed: {$results['failed']}\n";

// 2. Scheduled Campaigns
echo "2. Processing scheduled campaigns...\n";
$campaignsProcessed = 0;
try {
    // Find scheduled campaigns that are due
    $stmtC = $pdo->query("SELECT id, template_id FROM wa_campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW()");
    $campaigns = $stmtC->fetchAll();
    
    foreach ($campaigns as $camp) {
        $campId = (int)$camp['id'];
        
        // Update status to running
        $pdo->prepare("UPDATE wa_campaigns SET status = 'running' WHERE id = ?")->execute([$campId]);
        
        // Find pending recipients
        $stmtR = $pdo->prepare("SELECT id, recipient, name FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
        $stmtR->execute([$campId]);
        $recipients = $stmtR->fetchAll();
        
        // Fetch template
        $stmtT = $pdo->prepare("SELECT trigger_type FROM wa_templates WHERE id = ?");
        $stmtT->execute([(int)$camp['template_id']]);
        $tpl = $stmtT->fetch();
        $triggerType = $tpl ? $tpl['trigger_type'] : 'festival_campaign';
        
        $companyName = getAppSetting('company_name', 'Our Company');
        
        foreach ($recipients as $rec) {
            $logId = queueWhatsAppMessage($rec['recipient'], $triggerType, [
                $rec['name'] ?: 'Valued Client',
                $companyName
            ], ['related_type' => 'campaign', 'related_id' => $campId]);
            
            if ($logId) {
                $pdo->prepare("UPDATE wa_campaign_recipients SET status = 'sent', log_id = ?, sent_at = NOW() WHERE id = ?")
                    ->execute([$logId, $rec['id']]);
            } else {
                $pdo->prepare("UPDATE wa_campaign_recipients SET status = 'failed' WHERE id = ?")
                    ->execute([$rec['id']]);
            }
        }
        
        // Mark campaign completed
        $pdo->prepare("UPDATE wa_campaigns SET status = 'completed' WHERE id = ?")->execute([$campId]);
        $campaignsProcessed++;
    }
} catch (PDOException $e) {
    echo "   Error processing campaigns: " . $e->getMessage() . "\n";
}
echo "   Campaigns started: $campaignsProcessed\n";

// 3. Automated Follow-ups (Daily checks)
// E.g., Lead follow-ups if lead status is still 'new' after 3 days.
echo "3. Processing automated follow-ups...\n";
if (wa_isTriggerEnabled('lead_followup')) {
    try {
        $stmtL = $pdo->query("SELECT id, name, phone FROM leads WHERE status IN ('new', 'contacted') AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 3 DAY)");
        while ($lead = $stmtL->fetch()) {
            if (!empty($lead['phone'])) {
                queueWhatsAppMessage($lead['phone'], 'lead_followup', [
                    $lead['name'] ?: 'there',
                    getAppSetting('company_name', 'Our Company')
                ], ['related_type' => 'lead', 'related_id' => $lead['id']]);
            }
        }
    } catch (PDOException $e) {
        echo "   Error processing lead follow-ups: " . $e->getMessage() . "\n";
    }
}

// 4. End of Month Summaries (Attendance & Payroll)
echo "4. Processing end-of-month summaries...\n";
// Only run if today is the last day of the month
if (date('Y-m-d') === date('Y-m-t')) {
    $currentMonth = date('Y-m');
    $monthInt = (int)date('Ym');
    
    try {
        // Fetch all active employees with phones
        $stmtEmp = $pdo->query("SELECT u.id, u.name, e.phone FROM users u JOIN employees e ON e.user_id = u.id WHERE u.role = 'employee' AND e.status = 'active' AND e.phone IS NOT NULL AND e.phone != ''");
        $activeEmployees = $stmtEmp->fetchAll();

        // Attendance Summary
        if (wa_isTriggerEnabled('attendance_summary')) {
            foreach ($activeEmployees as $emp) {
                $phone = wa_formatPhone($emp['phone'], wa_getConfig()['default_country']);
                if (!$phone) continue;

                $check = $pdo->prepare("SELECT id FROM wa_message_log WHERE trigger_type = 'attendance_summary' AND related_type = 'month' AND related_id = ? AND recipient = ?");
                $check->execute([$monthInt, $phone]);
                
                if (!$check->fetch()) {
                    $salaryData = calculateSalary($emp['id'], $currentMonth);
                    
                    queueWhatsAppMessage($phone, 'attendance_summary', [
                        $emp['name'],
                        date('F Y'),
                        (string)$salaryData['present_days'],
                        (string)($salaryData['absent_days'] ?? 0)
                    ], ['related_type' => 'month', 'related_id' => $monthInt]);
                }
            }
        }

        // Payroll Summary
        if (wa_isTriggerEnabled('payroll_summary')) {
            foreach ($activeEmployees as $emp) {
                $phone = wa_formatPhone($emp['phone'], wa_getConfig()['default_country']);
                if (!$phone) continue;

                $check = $pdo->prepare("SELECT id FROM wa_message_log WHERE trigger_type = 'payroll_summary' AND related_type = 'month' AND related_id = ? AND recipient = ?");
                $check->execute([$monthInt, $phone]);
                
                if (!$check->fetch()) {
                    $salaryData = calculateSalary($emp['id'], $currentMonth);
                    // Ensure formatCurrency is available, but config/database.php includes it or it's there
                    // Actually, if formatCurrency isn't available, we can just use number_format
                    $amt = function_exists('formatCurrency') ? formatCurrency($salaryData['total_payable']) : 'Rs ' . number_format($salaryData['total_payable'], 2);
                    
                    queueWhatsAppMessage($phone, 'payroll_summary', [
                        $emp['name'],
                        date('F Y'),
                        $amt
                    ], ['related_type' => 'month', 'related_id' => $monthInt]);
                }
            }
        }
    } catch (PDOException $e) {
        echo "   Error processing end-of-month summaries: " . $e->getMessage() . "\n";
    }
}

$timeEnd = microtime(true);
$duration = round($timeEnd - $timeStart, 2);
echo "Cron completed in {$duration}s.\n";
