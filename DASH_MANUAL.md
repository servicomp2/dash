# Dash CLI - Manual Oficial de Usuario

**Dash** es un motor de generación de andamiaje (scaffold) diseñado para crear a la velocidad de la luz APIs documentadas en OpenAPI/Swagger, y Paneles Administrativos Web estilo Django, completamente auto-generados desde el CLI.

---

## 🚀 Arranque Rápido

```bash
# Servidor de desarrollo local
php -S localhost:8088 -t public

# Panel de administración
http://localhost:8088/admin  →  Requiere sesión SuperAdmin

# Documentación API interactiva (Swagger UI)
http://localhost:8088/docs/

# Schema raw de la API
http://localhost:8088/swagger.json
```

---

## 📋 Comandos del CLI

Todos los comandos se ejecutan con: `php dash <comando> [argumento]`

### Seguridad y Autenticación

| Comando | Descripción |
|---------|-------------|
| `make:auth` | Instala la capa de autenticación base para el **SuperAdmin** (contraseña + JWT) |
| `make:user-auth` | Instala el módulo de autenticación para **usuarios finales de la app** |

### Migraciones y Base de Datos

| Comando | Descripción |
|---------|-------------|
| `make:migration <tabla>` | Genera un archivo de migración vacío con Blueprint builder |
| `make:alter <tabla>` | Genera una migración para modificar una tabla existente (ALTER TABLE) |
| `migrate` | Ejecuta todas las migraciones pendientes y auto-genera MVC, rutas, vistas y Swagger |
| `rollback` | Deshace la **última migración** y elimina todos sus archivos generados |

### Andamiaje y API

| Comando | Descripción |
|---------|-------------|
| `scaffold:db <tabla>` | Regenera el andamiaje MVC+API completo desde la tabla existente en DB |
| `make:scaffold <file.json>` | Genera andamiaje desde un archivo JSON de esquema |
| `swagger:generate` | Escanea todos los controladores y actualiza `public/swagger.json` |

---

## 🔧 El Flujo de Trabajo: "Migration-First"

### Paso 0 — `make:auth` (Solo proyectos nuevos)

```bash
php dash make:auth
```

Instala `AuthController`, la tabla `superadmin`, y la ruta `POST /api/v1/auth/login`.

**Credenciales por defecto (cambiar inmediatamente):**  
Crea manualmente el primer superadmin en DB:
```sql
INSERT INTO superadmin (name, email, password, uuid)
VALUES ('Admin', 'admin@dash.io', '<hash_de_password_hash>', uuid());
```

### Paso 1 — Crear una migración

```bash
php dash make:migration productos
```

Genera `database/migrations/<timestamp>_CreateProductosTable.php`. Edítalo:

```php
class CreateProductosTable {
    public string $table = 'productos';
    public string $route = '/api/v1/productos';
    public bool   $auth  = true;

    // ✨ PARÁMETROS DE BÚSQUEDA EXACTA PARA LA API
    // Los campos aquí definidos aparecerán como query params en Swagger
    public array $search = ['nombre', 'categoria'];

    public function up(Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();

        $table->string('nombre', 150)->ui('text');
        $table->string('categoria', 80)->nullable()->ui('text');
        $table->decimal('precio')->ui('number');
        $table->text('descripcion')->nullable()->ui('textarea');
        $table->boolean('activo')->default(false)->ui('switch');

        $table->timestamps();
        $table->softDeletes();
    }

    public function down(Blueprint $table): void {
        $table->dropTable();
    }
}
```

### Paso 2 — Ejecutar la migración

```bash
php dash migrate
```

**Esto genera automáticamente:**
- ✅ Tabla en la base de datos
- ✅ `app/Controllers/Api/ProductosController.php` con Swagger completo
- ✅ `app/Controllers/Web/ProductosController.php` con CRUD paginado y búsqueda
- ✅ `routes/api_modules/productos.php`
- ✅ `routes/web_modules/productos.php`
- ✅ `app/Views/productos/` (index, create, edit)
- ✅ `public/swagger.json` actualizado

---

## 🎨 Tipos de Columnas Blueprint

```php
// Numéricos
$table->id();                        // INT AUTO_INCREMENT PRIMARY KEY
$table->int('stock');                // INT
$table->bigInt('clicks');            // BIGINT
$table->decimal('precio', 10, 2);   // DECIMAL(10,2)
$table->float('latitud');            // FLOAT
$table->boolean('activo');           // TINYINT(1)

// Texto
$table->string('nombre', 150);      // VARCHAR(150)
$table->text('descripcion');         // TEXT
$table->longText('contenido');       // LONGTEXT
$table->enum('estado', ['pendiente', 'aprobado', 'rechazado']);

// Fecha/Hora
$table->date('fecha_nacimiento');
$table->dateTime('publicado_en');
$table->timestamps();                // created_at + updated_at
$table->softDeletes();               // deleted_at (eliminación lógica)

// Especiales
$table->uuid('uuid')->unique();      // UUID
$table->json('metadata');

// Modificadores (encadenables)
->nullable()
->unique()
->default(false)
->after('nombre')

// Relaciones (llaves foráneas / foreign keys)
// 1) crear campo local que almacenará la clave foránea
$table->int('categoria_id')->nullable();

// 2) definir constraint de llave foránea
//    formato: foreign(columna_local, tabla_referenciada)
//    - columna_local: campo en esta tabla (ej. categoria_id)
//    - tabla_referenciada: tabla donde se apunta (ej. categorias)
//    aplica un índice automático y establece integridad referencial
$table->foreign('categoria_id', 'categorias')
      ->references('id')   // columna en la tabla referenciada (por defecto "id")
      ->onDelete('cascade'); // qué hacer al borrar padre (cascade, set null, restrict, no action)

// Ejemplo completo de mapeo de relación:
// productos.categoria_id -> categorias.id
// Al borrar una categoria, los productos relacionados también se borran (cascade).

// UI (componente del formulario admin)
->ui('text')           // Input texto (default)
->ui('email')          // Input email
->ui('password')       // Input password (enmascarado)
->ui('textarea')       // Textarea
->ui('switch')         // Toggle on/off
->ui('number')         // Input número
->ui('date')           // Selector de fecha
->ui('select', ['Pendiente', 'Activo', 'Inactivo'])  // Select con opciones
```

---

## 🔑 Parámetros de Búsqueda API (`$search`)

La propiedad `$search` en tu migración controla qué campos son filtrables como query params exactos en la API:

```php
public array $search = ['email', 'categoria', 'estado'];
```

**Resultado en Swagger:**
```
GET /api/v1/productos?email=xxx&categoria=xxx&q=busqueda_general
```

- Los campos en `$search` hacen **match exacto** (`WHERE campo = ?`)
- El param `q` hace **búsqueda LIKE** en todas las columnas de texto
- `page` y `limit` siempre están disponibles para paginación

---

## 🌐 Rutas del Panel Web Admin

```
GET  /admin                          → Dashboard
GET  /admin/<tabla>                  → Listado con búsqueda y paginación
GET  /admin/<tabla>/create           → Formulario de creación
POST /admin/<tabla>/store            → Guardar nuevo registro
GET  /admin/<tabla>/{id}/edit        → Formulario de edición
POST /admin/<tabla>/{id}/update      → Actualizar registro
POST /admin/<tabla>/{id}/delete      → Eliminación lógica (soft delete)
GET  /admin/login                    → Formulario de acceso SuperAdmin
POST /admin/logout                   → Cerrar sesión
```

---

## 🔗 Rutas de la API REST

Cada módulo generado expone automáticamente:

```
GET    /api/v1/<tabla>           → Listado paginado + filtros de $search + búsqueda q
GET    /api/v1/<tabla>/{uuid}    → Registro individual
POST   /api/v1/<tabla>           → Crear (JSON body)
PUT    /api/v1/<tabla>/{uuid}    → Actualizar (JSON body)
DELETE /api/v1/<tabla>/{uuid}    → Eliminación lógica

POST   /api/v1/auth/login        → Login SuperAdmin → JWT 24h
POST   /api/v1/user/auth/login   → Login Usuario final → JWT 7d
```

**Autenticación API:** Bearer Token en header:
```
Authorization: Bearer <token_jwt>
```

---

## 🗂 Estructura de Archivos

```
├── app/
│   ├── Console/
│   │   └── ScaffoldGenerator.php   # Motor de andamiaje
│   ├── Controllers/
│   │   ├── Api/                    # Controladores REST
│   │   └── Web/                    # Controladores Admin
│   ├── Core/
│   │   ├── Blueprint.php           # Builder de migraciones
│   │   └── View.php                # Renderizador Twig
│   ├── Middleware/
│   │   ├── Guard.php               # Auth API (JWT)
│   │   └── WebGuard.php            # Auth Web (sesión)
│   ├── Utils/
│   │   ├── Guid.php                # Generador UUID v4
│   │   └── Slug.php                # Generador de slugs URL-friendly
│   └── Views/
│       ├── layout.twig             # Layout base del admin
│       └── <tabla>/                # Vistas por módulo
├── database/
│   └── migrations/                 # Archivos de migración
├── public/
│   ├── index.php                   # Punto de entrada
│   └── swagger.json                # Spec OpenAPI generada
├── routes/
│   ├── api.php                     # Router API principal
│   ├── web.php                     # Router Web principal
│   ├── api_modules/                # Rutas API por módulo
│   └── web_modules/                # Rutas Web por módulo
├── stubs/                          # Plantillas para generación
└── dash                            # CLI ejecutable
```

---

## ⚡ Regenerar Módulos Existentes

Si modificas manualmente la migración o el esquema de la DB, ejecuta:

```bash
# Regenerar desde la BD (lee $search de la migración automáticamente)
php dash scaffold:db <tabla>

# Regenerar documentación Swagger
php dash swagger:generate
```

---

## 🔄 Rollback

```bash
php dash rollback
```

Elimina la última migración aplicada **y todos sus archivos generados** (controladores, rutas, vistas). Útil para empezar de nuevo.

> ⚠️ **Cuidado**: El rollback elimina datos de la tabla en la base de datos. Haz backup antes.