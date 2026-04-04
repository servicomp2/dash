<?php
declare(strict_types=1);

echo "--- GENERADOR DE LLAVES DASH (PHP 8.4) ---\n\n";

// 1. APP_KEY y JWT_SECRET (32 bytes = 256 bits, estándar AES-256)
$appKey = bin2hex(random_bytes(32));
$jwtSecret = bin2hex(random_bytes(32));

echo "APP_KEY=" . $appKey . "\n";
echo "JWT_SECRET=" . $jwtSecret . "\n\n";

// 2. VAPID KEYS (Para Web Push - Curva Elíptica prime256v1)
echo "Generando llaves VAPID (Web Push)...\n";

$config = [
    "curve_name" => "prime256v1",
    "private_key_type" => OPENSSL_KEYTYPE_EC,
];

$res = openssl_pkey_new($config);
openssl_pkey_export($res, $privateKey);
$publicKeyData = openssl_pkey_get_details($res);
$publicKey = $publicKeyData["key"];

// Limpiamos las llaves para que queden en formato de una sola línea (Base64 URL Safe)
function cleanKey(string $key): string {
    return trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', '-----BEGIN EC PRIVATE KEY-----', '-----END EC PRIVATE KEY-----', "\n", "\r"], '', $key));
}

echo "VAPID_PUBLIC_KEY=" . cleanKey($publicKey) . "\n";
echo "VAPID_PRIVATE_KEY=" . cleanKey($privateKey) . "\n";

echo "\n--- Copia estas líneas en tu archivo .env ---\n";