<?php
/**
 * WhatsApp Meta API Webhook Receiver
 * 
 * This file receives real-time updates from Meta regarding message statuses
 * (sent, delivered, read, failed). You need to register this URL in the 
 * Meta App Dashboard under WhatsApp > Configuration > Webhook.
 * 
 * URL to provide to Meta: https://yourdomain.com/event_management/webhook_whatsapp.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/whatsapp.php';

// 1. Verification Request from Meta (GET)
// Meta sends a GET request to verify the webhook URL when you first configure it.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = wa_env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', '');
    if ($verify_token === '') {
        // No verify token configured — reject the verification request
        http_response_code(403);
        echo 'WHATSAPP_WEBHOOK_VERIFY_TOKEN not configured.';
        exit;
    }
    
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    
    if ($mode === 'subscribe' && $token === $verify_token) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo "Forbidden - Verify Token Mismatch";
        exit;
    }
}

// 2. Incoming Payload from Meta (POST)
// Meta sends POST requests when messages are delivered, read, or when a user replies.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read raw JSON data
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    
    if ($payload && isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
        // Process the delivery statuses using our built-in function
        wa_processWebhook($payload);
        
        // Always return a 200 OK quickly so Meta knows we received it
        http_response_code(200);
        echo "EVENT_RECEIVED";
        exit;
    }
    
    // If not a WhatsApp payload
    http_response_code(404);
    exit;
}
