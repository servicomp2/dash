<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\View;
use App\Middleware\WebGuard;

class DashboardController {
    public function index(): void {
        WebGuard::auth();

        global $db;

        // Recoger métricas básicas de cada tabla disponible
        $tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        $reserved = ['migrations', 'superadmin'];
        $modules = [];

        foreach ($tables as $table) {
            if (in_array($table, $reserved)) continue;
            try {
                // Medoo count requires a column array, check if deleted_at exists
                $cols = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'deleted_at'")->fetch();
                $count = $cols
                    ? $db->count($table, ['deleted_at' => null])
                    : $db->count($table);
                $modules[] = ['name' => $table, 'count' => $count ?? 0];
            } catch (\Exception $e) {
                $modules[] = ['name' => $table, 'count' => '—'];
            }
        }

        View::render('admin/dashboard', ['modules' => $modules]);
    }
}
