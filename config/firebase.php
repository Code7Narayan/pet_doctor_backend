<?php
// backend/config/firebase.php
//
// Firebase Cloud Messaging (Legacy HTTP API) helper.
// Used by: cron/send_reminders.php and any endpoint that needs to push a notification.
//
// Replace FCM_SERVER_KEY with your actual Firebase Server Key
// (Firebase Console → Project Settings → Cloud Messaging → Server Key)

define('FCM_SERVER_KEY', getenv('FCM_SERVER_KEY') ?: 'YOUR_FCM_SERVER_KEY_HERE');
define('FCM_ENDPOINT',   'https://fcm.googleapis.com/fcm/send');

class Firebase {

    /**
     * Send a push notification to a single device token.
     *
     * @param string $token     FCM registration token (stored in users.fcm_token)
     * @param string $title     Notification title
     * @param string $body      Notification body text
     * @param array  $data      Optional data payload (key → string pairs)
     * @param bool   $silent    If true, sends a data-only message (no visible notification)
     *
     * @return array ['success' => bool, 'response' => array]
     */
    public static function sendToDevice(
        string $token,
        string $title,
        string $body,
        array  $data   = [],
        bool   $silent = false
    ): array {
        $payload = [
            'to'       => $token,
            'priority' => 'high',
        ];

        if (!$silent) {
            $payload['notification'] = [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ];
        }

        if (!empty($data)) {
            // FCM data values must be strings
            $payload['data'] = array_map('strval', $data);
        }

        return self::post($payload);
    }

    /**
     * Send to multiple devices (up to 1000 tokens per batch).
     *
     * @param array $tokens  Array of FCM tokens
     */
    public static function sendToMultiple(
        array  $tokens,
        string $title,
        string $body,
        array  $data = []
    ): array {
        if (empty($tokens)) return ['success' => false, 'response' => []];

        $payload = [
            'registration_ids' => array_values($tokens),
            'priority' => 'high',
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
        ];
        if (!empty($data)) $payload['data'] = array_map('strval', $data);

        return self::post($payload);
    }

    /**
     * Notify the owner when their treatment request is accepted.
     */
    public static function notifyTreatmentAccepted(string $ownerToken, string $doctorName,
                                                    string $animalName, int $treatmentId): array {
        return self::sendToDevice(
            $ownerToken,
            'Treatment Accepted',
            "Dr. $doctorName has accepted the request for $animalName",
            ['type' => 'treatment_accepted', 'treatment_id' => (string) $treatmentId]
        );
    }

    /**
     * Notify the doctor when a new treatment request arrives near them.
     */
    public static function notifyNewRequest(string $doctorToken, string $animalName,
                                             string $ownerName, int $treatmentId): array {
        return self::sendToDevice(
            $doctorToken,
            'New Treatment Request',
            "$ownerName needs a vet for $animalName",
            ['type' => 'new_request', 'treatment_id' => (string) $treatmentId]
        );
    }

    // ── Private HTTP ───────────────────────────────────────────
    private static function post(array $payload): array {
        $ch = curl_init(FCM_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: key=' . FCM_SERVER_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'error' => $curlErr, 'response' => []];
        }

        $response = json_decode($raw, true) ?? [];
        $success  = $httpCode === 200 && ($response['success'] ?? 0) > 0;

        return ['success' => $success, 'http_code' => $httpCode, 'response' => $response];
    }
}