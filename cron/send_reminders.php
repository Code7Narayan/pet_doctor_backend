#!/usr/bin/env php
<?php
// backend/cron/send_reminders.php
//
// Run every minute via crontab:
//   * * * * * php /var/www/html/vetcare/backend/cron/send_reminders.php >> /var/log/vetcare_reminders.log 2>&1
//
// Finds reminders due in the next 5 minutes that haven't been sent,
// pushes an FCM notification to the owner's device, then marks them sent.

require_once __DIR__ . '/../config/database.php';

define('FCM_SERVER_KEY', 'YOUR_FCM_SERVER_KEY_HERE');  // replace in production
define('FCM_URL',        'https://fcm.googleapis.com/fcm/send');

$db = Database::getConnection();

// Fetch unsent reminders due within the next 5 minutes
$stmt = $db->prepare("
    SELECT r.id, r.owner_id, r.title, r.description, r.type,
           r.animal_id, a.name AS animal_name,
           u.fcm_token, u.language
    FROM reminders r
    INNER JOIN users    u ON u.id    = r.owner_id
    LEFT  JOIN animals  a ON a.id    = r.animal_id
    WHERE r.is_sent      = 0
      AND r.remind_at   <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)
      AND r.remind_at   >= NOW()
      AND u.fcm_token IS NOT NULL
    LIMIT 100
");
$stmt->execute();
$reminders = $stmt->fetchAll();

if (empty($reminders)) {
    echo date('[Y-m-d H:i:s]') . " No reminders due.\n";
    exit(0);
}

$sentIds = [];

foreach ($reminders as $rem) {
    $token = $rem['fcm_token'];
    $lang  = $rem['language'];

    // Build notification body (basic i18n)
    $animalPart = $rem['animal_name'] ? " for {$rem['animal_name']}" : '';
    $body = $rem['description'] ?: ucfirst($rem['type']) . ' reminder' . $animalPart;

    $payload = [
        'to'           => $token,
        'notification' => [
            'title' => $rem['title'],
            'body'  => $body,
            'sound' => 'default',
            'badge' => '1',
        ],
        'data' => [
            'type'        => 'reminder',
            'reminder_id' => (string) $rem['id'],
            'animal_id'   => (string) ($rem['animal_id'] ?? ''),
            'rem_type'    => $rem['type'],
        ],
        'priority' => 'high',
    ];

    $ch = curl_init(FCM_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: key=' . FCM_SERVER_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 10,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $response = json_decode($result, true);
        if (($response['success'] ?? 0) === 1) {
            $sentIds[] = $rem['id'];
            echo date('[Y-m-d H:i:s]') . " Sent reminder #{$rem['id']}: {$rem['title']}\n";
        } else {
            echo date('[Y-m-d H:i:s]') . " FCM failed for reminder #{$rem['id']}: $result\n";
        }
    } else {
        echo date('[Y-m-d H:i:s]') . " HTTP $httpCode for reminder #{$rem['id']}\n";
    }
}

// Mark successfully sent reminders
if (!empty($sentIds)) {
    $placeholders = implode(',', array_fill(0, count($sentIds), '?'));
    $db->prepare("UPDATE reminders SET is_sent = 1 WHERE id IN ($placeholders)")
       ->execute($sentIds);
    echo date('[Y-m-d H:i:s]') . " Marked " . count($sentIds) . " reminder(s) as sent.\n";
}