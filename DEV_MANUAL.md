# Dash Framework - Manual del Desarrollador (Interno)

Bienvenido al manual interno de arquitectura de Dash Framework. Este documento está diseñado para desarrolladores que necesiten comprender la magia detrás del motor de generación de código (scaffolding), las convenciones estructurales, y cómo extender o corregir los módulos autogenerados.

---

## 1. Arquitectura Central

Dash es un framework minimalista tipo MVC con ruteo gestionado por **Bramus Router** y persistencia ORM gestionada por **Medoo**. Está diseñado bajo el patrón **Migration-First**, lo cual significa que la base de datos es la fuente única de verdad (Single Source of Truth).

### 1.1 Estructura del Framework

- `app/Console/ScaffoldGenerator.php`: **El corazón de Dash.** Este script lee el esquema de la base de datos (y la instancia de la migración más reciente) para generar de manera automática componentes visuales y APIs documentadas.
- `app/Controllers/Api/`: Los Endpoints RESTful con anotaciones OpenAPI (Swagger).
- `app/Controllers/Web/`: Controladores de paneles administrativos que devuelven vistas Twig.
- `app/Views/`: Plantillas Twig renderizadas por el componente del motor `\App\Core\View`.
- `stubs/`: Plantillas bases (Stubs). Al modificar cualquier archivo aquí, **TODOS** los futuros módulos generados heredarán esos cambios.

---

## 2. El Motor de Scaffolding (ScaffoldGenerator)

Cuando ejecutas `php dash migrate` o `php dash scaffold:db <table>`, ocurren múltiples pasos en cascada:

1. **Lectura de DB y Blueprint:** Se escanea la tabla en MariaDB (o a través de blueprint si es inicial), determinando los tipos SQL de las columnas.
2. **Asignación de UI:** Se asigna un componente UI (texto, switch booleano, select, text-area, email, etc) mediante `ui_metadata` u obtención inferida. Ex. las columnas `_id` se interpretan como Foreign Keys que necesitan relaciones (`select` pre-populado).
3. **Inyección de Propiedades:** `$search = []` definido en la migración, le avisa al generador qué columnas inyectar como parámetros de búsqueda (`WHERE OR` exacto) en el `$where` de listado (index) y detalle (show) de la API REST.

### 2.1 Generación de Rutas Seguras e Inserciones Automáticas

**API:** Los controladores y rutas API son inyectados incluyendo las validaciones JWT a través del middleware `\App\Middleware\Guard`.
**WEB:** Cuentan con el middleware `WebGuard`. WebAdmin requiere siempre autenticación basada en sesión y es exclusivo para roles de SuperAdmin u operaciones de Backend puras.

### 2.2 Variables Mágicas (`autoFields`)

En el proceso de guardado `store` o `update`, el código generado incluye lógica "autoField" para inyectar inteligentemente ciertos valores:
- **`uuid`:** Si tu tabla posee un uuid, este será generado usando `\App\Utils\Guid::v4()` silenciosamente evitando romper tu UI.
- **`slug`:** Si posees una columna slug, y no se le pasa un valor en el payload, genera automáticamente un `slug = \App\Utils\Slug::create($input['name'] ?? '')`.
- **`password`:** De forma nativa, usa `password_hash()` automáticamente al detectar componentes tipo UI password. Ignora actualizar contraseñas en blanco enviadas en un evento update.

---

## 3. Resolución de Problemas (Troubleshooting)

### Los cambios que aplico manualmente en un controlador desaparecen
Esto ocurre porque ejecutaste un *re-scaffold* (`php dash scaffold:db <table>`) o hiciste un `rollback`. Dash reescribe los archivos generados con los encontrados en la carpeta `stubs/`.
**Solución:** Si el cambio que intentas hacer es un comportamiento que quieres estandarizar, ponlo en la carpeta `stubs/`. Si es lógica de negocio *custom*, considera ignorar el rescaffolding para esa tabla particular.

### El Swagger UI no muestra mis endpoints
Ejecuta manualmente el comando de recompilación:
`php dash swagger:generate`

### Búsquedas de API
- **Endpoint Listado (`GET /api/v1/tabla`):** Soporta búesqueda ambigüa global `?q=buscar` que busca coincidencias tipo LIKE en **TODAS** las columnas de texto. Si defines la variable `$search = ['email'];` en tu migración, Swagger dispondrá automáticamente este campo en UI como query params que aplicarán como condición exacta.
- **Endpoint Detalle (`GET /api/v1/tabla/{identifier}`):** Permite usar la Primary Key (uuid), **O bien**, recuperar registros individuales inyectándole los campos extra que especificaste en `$search`.

### Modificación de Contraseñas desde Admin
La lógica de `ScaffoldGenerator` ha sido adaptada. Al crear registros administrativos que requieren passwords, asegúrate de mantener el tipo de campo SQL/Blueprint correcto o haberle forzado el flag visual en la migración `->ui('password')`. Al editar desde la sección Admin el campo password quedará en blanco, evitando corromper la BD o deshashear la contraseña existente a menos que el usuario admin escriba explícitamente algo.

### Modificación o Restauración de Base de Datos
Nunca modifiques esquemas (columnas) manualmente en el gestor SQL si esperas que Swagger y el admin se actualicen. Utiliza:
`php dash make:alter <nombre_tabla>` y haz los cambios vía código.

---

## 4. Personalización del Frontend Admin (Twig)

- Archivo de layout global: `app/Views/layout.twig`. Modifíca este archivo para agregar librerías, links fijos en menú lateral o configuraciones comunes de JS.
- Cuando creas nuevos módulos, por convención se generarán `create.twig`, `index.twig` y `edit.twig` en su propia carpeta `app/Views/{modulo}/`.
- Relaciones de select en Admin (Foreign Keys): Dashboard automáticamente detecta llaves foráneas gracias a `information_schema`. Enviará a Twig una variable `$context_{nombre_fk}` con las equivalencias relacionales necesarias. Mantiene sincronía automática para listados relacionados sin codificar join manual.
