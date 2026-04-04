<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\View;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController {

    public function login(): void {
        // Si ya está logeado, mandarlo al home administrativo
        if (!empty($_SESSION['admin_auth'])) {
            header('Location: /admin');
            exit;
        }
        View::render('auth/login', []);
    }

    public function authenticate(): void {
        global $db;

        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            View::render('auth/login', ['error' => 'Email o contraseña inválidos', 'email' => $email]);
            return;
        }

        $user = $db->get('superadmin', '*', ['email' => $email, 'deleted_at' => null]);

        if (!$user) {
            View::render('auth/login', ['error' => 'Email o contraseña incorrectos', 'email' => $email]);
            return;
        }

        $storedPassword = $user['password'] ?? null;
        $validPassword = false;

        if ($storedPassword !== null && $storedPassword !== '') {
            if (password_verify($password, $storedPassword)) {
                $validPassword = true;
            } elseif (hash_equals($storedPassword, $password)) {
                $validPassword = true;
            }
        }

        if (!$validPassword) {
            View::render('auth/login', ['error' => 'Email o contraseña incorrectos', 'email' => $email]);
            return;
        }

        $_SESSION['admin_auth'] = $user['uuid'] ?? $user['id'];
        header('Location: /admin');
        exit;
    }

    public function session(): void {
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';

        if (!$token) {
            http_response_code(400);
            echo json_encode(['error' => 'Token no provisto']);
            return;
        }

        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], $_ENV['JWT_ALGO'] ?? 'HS256'));
            // Guardamos el sub (UUID del usuario) en la sesión nativa PHP
            $_SESSION['admin_auth'] = $decoded->sub;
            
            echo json_encode(['status' => 'success', 'redirect' => '/admin']);
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Token inválido', 'message' => $e->getMessage()]);
        }
    }
    
    public function logout(): void {
        session_destroy();
        header('Location: /admin/login');
        exit;
    }
}
