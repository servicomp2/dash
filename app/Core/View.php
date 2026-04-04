<?php
declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class View {
    private static ?Environment $twig = null;

    public static function getEnvironment(): Environment {
        if (self::$twig === null) {
            $loader = new FilesystemLoader(__DIR__ . '/../../app/Views');
            self::$twig = new Environment($loader, [
                // 'cache' => __DIR__ . '/../../storage/views',
                'cache' => false, // Disable cache for development
                'auto_reload' => true
            ]);
        }
        return self::$twig;
    }

    public static function render(string $template, array $data = []): void {
        $twig = self::getEnvironment();
        // Automatically add .twig extension if omitted
        if (!str_ends_with($template, '.twig')) {
            $template .= '.twig';
        }
        echo $twig->render($template, $data);
    }
}
