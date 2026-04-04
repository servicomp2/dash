<?php
declare(strict_types=1);

namespace App\Core;

use OpenApi\Attributes as OA;

#[OA\Info(title: "Dash API", version: "1.0.0", description: "Documentación Automática de API REST generada por Dash")]
#[OA\Server(url: "/", description: "Servidor Principal")]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT",
    description: "Ingresa el token provisto tras iniciar sesión en el formato: Bearer {token}"
)]
class SwaggerAnnotations {
    // Esta clase solo sirve para albergar las anotaciones globales estructurales de OpenAPI.
}
