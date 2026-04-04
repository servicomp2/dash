<?php
declare(strict_types=1);

namespace App\Services;

class PushService {
    public static function notify(string $token, string $title, string $message): string {
        $url = 'https://fcm.googleapis.com/fcm/send'; // O el endpoint de tu servicio
        $apiKey = $_ENV['PUSH_API_KEY'] ?? '';

        $fields = [
            'to' => $token,
            'notification' => ['title' => $title, 'body' => $message]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: key=' . $apiKey, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        return (string)$result;
    }
}