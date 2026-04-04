<?php
declare(strict_types=1);

namespace App\Middleware;

class WebGuard {
    public static function auth(): void {
        if (empty($_SESSION['admin_auth'])) {
            header('Location: /admin/login');
            exit;
        }
    }
}
