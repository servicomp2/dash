<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Bramus\Router\Router;
use Medoo\Medoo;

// Iniciar Sesión para Web Admin
session_start();

// Cargar variables de entorno
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Configuración de errores según entorno
if (($_ENV['APP_ENV'] ?? 'production') === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Configuración Global de MariaDB (Medoo)
$GLOBALS['db'] = new Medoo([
    'type' => 'mysql',
    'host' => $_ENV['DB_HOST'],
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'port' => (int)($_ENV['DB_PORT'] ?? 3306),
    'option' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
]);

// Cabeceras de API REST
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')) {
    header("Content-Type: application/json; charset=UTF-8");
} else {
    header("Content-Type: text/html; charset=UTF-8");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Inicializar Router
$router = new Router();
$router->setNamespace('\App\Controllers');

// Cargar Definiciones de Rutas
if (file_exists(__DIR__ . '/../routes/api.php')) {
    require_once __DIR__ . '/../routes/api.php';
}


if (file_exists(__DIR__ . '/../routes/web.php')) {
    require_once __DIR__ . '/../routes/web.php';
}

$router->set404(function() {
    http_response_code(404);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(['error' => 'Endpoint no encontrado']);
    } else {
        header("Content-Type: text/html; charset=UTF-8");
        \App\Core\View::render('errors/404', []);
    }
});

$router->run();