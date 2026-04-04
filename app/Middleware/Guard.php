<?php
declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Guard {
    /**
     * Valida el Token JWT y autoriza la petición.
     */
    public static function auth(string $type = 'superadmin'): void {
        header('Content-Type: application/json');

        $authHeader = null;
        if (isset($_SERVER['Authorization'])) {
            $authHeader = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
            if (isset($requestHeaders['authorization'])) {
                $authHeader = trim($requestHeaders['authorization']);
            }
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (isset($headers['authorization'])) {
                $authHeader = trim($headers['authorization']);
            }
        }

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            exit(json_encode(["error" => "No autorizado - Es necesario autenticar para obtener la respuesta solicitada"]));
        }

        $jwt = $matches[1];

        try {
            // Decodificar usando la clave secreta del .env
            $decoded = JWT::decode($jwt, new Key($_ENV['JWT_SECRET'], $_ENV['JWT_ALGO']));

            // Validar el tipo de usuario (superadmin vs user)
            $tokenType = $decoded->type ?? 'superadmin'; // Retrocompatibilidad: tokens viejos son superadmin
            
            if ($tokenType !== $type) {
                http_response_code(403);
                exit(json_encode(["error" => "Acceso denegado - El token no tiene los privilegios adecuados ($type requerido)"]));
            }

            // Inyectar el UUID del usuario y tipo en el REQUEST
            $_REQUEST['auth_user_uuid'] = $decoded->sub;
            $_REQUEST['auth_user_type'] = $tokenType;
            $_REQUEST['auth_user_role'] = $decoded->role ?? null;

        } catch (Exception $e) {
            http_response_code(401);
            exit(json_encode(["error" => "Token inválido o expirado: " . $e->getMessage()]));
        }
    }
}