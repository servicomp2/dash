<?php
declare(strict_types=1);

namespace App\Utils;

class Guid {
    /**
     * Genera un UUID v4 (RFC 4122) de forma criptográficamente segura.
     */
    public static function v4(): string {
        $data = random_bytes(16);

        // Configurar bits para la versión 4
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Configurar bits para el variante RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}