<?php
declare(strict_types=1);

namespace App\Utils;

class Slug {
    /**
     * Convierte un texto en un slug amigable para URLs.
     * Ejemplo: "Hola Mundo" -> "hola-mundo"
     */
    public static function create(string $text): string {
        // Reemplazar caracteres no alfanuméricos con guiones
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        // Transliterar (quitar acentos)
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        // Eliminar caracteres no deseados
        $text = preg_replace('~[^-\w]+~', '', $text);
        // Trim de guiones
        $text = trim($text, '-');
        // Eliminar guiones duplicados
        $text = preg_replace('~-+~', '-', $text);
        // A minúsculas
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a-' . time();
        }

        return $text;
    }
}
