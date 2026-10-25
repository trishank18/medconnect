<?php
require_once __DIR__ . '/env.php';

function medconnect_normalize_phone(string $phone): string
{
    $digits = preg_replace('/[^0-9+]/', '', $phone);
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '+') === 0) {
        return '+' . preg_replace('/\D+/', '', substr($digits, 1));
    }

    return preg_replace('/\D+/', '', $digits);
}

function medconnect_send_sms(string $to, string $message): bool
{
    medconnect_load_env_file();

    $accountSid = getenv('TWILIO_ACCOUNT_SID') ?: '';
    $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $fromNumber = getenv('TWILIO_FROM_NUMBER') ?: '';

    if ($accountSid === '' || $authToken === '' || $fromNumber === '') {
        return false;
    }

    $normalizedTo = medconnect_normalize_phone($to);
    if ($normalizedTo === '') {
        return false;
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $fields = [
        'To' => $normalizedTo,
        'From' => $fromNumber,
        'Body' => $message,
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $accountSid . ':' . $authToken);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);

    return $curlError === 0 && $response !== false && $httpCode >= 200 && $httpCode < 300;
}
