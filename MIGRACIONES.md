# Manual de Migraciones de Dash (MIGRACIONES)

Este documento es el complemento definitivo a `DEV_MANUAL.md`/`DASH_MANUAL.md` para manejar migraciones y scaffolding en AgroApp (Dash). Incluye cada opción posible de campos, `Blueprint`, UI metadata, convenciones y flujo completo para crear tablas correctas paso a paso.

---

## 1. Visión general del flujo de migración + scaffold

1. Redactas o editas una migración en `database/migrations/` (clase con `up()` y `down()`).
2. Ejecutas `php dash migrate`.
3. `dash` crea la tabla con `Blueprint` o ejecuta `getRawSql()` si está definido.
4. El sistema registra la migración en tabla `migrations`.
5. `ScaffoldGenerator::generateFromDb()` lee la tabla y genera:
   - controladores API (`app/Controllers/Api/<Tabla>Controller.php`)
   - controladores Web (`app/Controllers/Web/<Tabla>Controller.php`)
   - rutas en `routes/api_modules/` y `routes/web_modules/`
   - vistas Twig `app/Views/<tabla>/*`
   - y actualiza `public/swagger.json` (si todo OK)

Extras:
- `php dash make:migration <tabla>` crea plantilla de migración básica (en blanco).
- `php dash make:alter <tabla>` crea migración para alteraciones.
- `php dash scaffold:db <tabla>` genera scaffolding desde una tabla existente.
- `php dash make:scaffold <schema.json>` genera todo desde JSON.
- `php dash rollback` deshace la última migración y borra CRUD/autogenerados.

---

## 2. Estructura de migración estándar (stub)

Todas las migraciones estándar en `database/migrations` tienen estructura:

```php
<?php
declare(strict_types=1);

class CreateArticulosTable {
    public string $table = 'articulos';

    public function up(\App\Core\Blueprint $table): void {
        $table->id();
        $table->string('name', 150)->unique();
        $table->text('description')->nullable();
        $table->integer('stock')->default(0);
        $table->timestamps();
        $table->softDeletes();
    }

    public function down(\App\Core\Blueprint $table): void {
        $table->dropTable();
    }
}
```

Puntos clave:
- `up()` define la transformación de creación/alter.
- `down()` revierte (idealmente con `dropTable()` o `alter` inverso).
- `table` define nombre de tabla usada por `runMigrations` y `rollback`.

---

## 3. Métodos de Blueprint para crear columnas

### 3.1 Tipos numéricos
- `tinyInt($name)` → `TINYINT`
- `smallInt($name)` → `SMALLINT`
- `mediumInt($name)` → `MEDIUMINT`
- `int($name)` → `INT`
- `bigInt($name)` → `BIGINT`
- `decimal($name, $p = 10, $s = 2)` → `DECIMAL(p,s)`
- `float($name)` → `FLOAT`
- `double($name)` → `DOUBLE`
- `bit($name, $len = 1)` → `BIT(len)`
- `boolean($name)` → `TINYINT(1)` (para auto UI `switch`)
- `id()` → `id INT AUTO_INCREMENT PRIMARY KEY`

### 3.2 Strings y binarios
- `char($name, $len = 255)` → `CHAR(len)`
- `string($name, $len = 255)` → `VARCHAR(len)`
- `tinyText($name)`, `text($name)`, `mediumText($name)`, `longText($name)`
- `binary($name, $len = 255)`, `varBinary($name, $len = 255)`
- `blob($name)`, `longBlob($name)`
- `enum($name, array $allowed)` → `ENUM('v1','v2')`

### 3.3 Fecha y hora
- `date($name)`
- `dateTime($name, $fsp = 0)`
- `timestamp($name, $fsp = 0)`
- `time($name)`
- `year($name)`
- `timestamps()` → `created_at` y `updated_at` con valores CURRENT_TIMESTAMP

### 3.4 Tipos espaciales
- `geometry($name)`, `point($name)`, `lineString($name)`, `polygon($name)`

### 3.5 Especiales
- `json($name)`
- `inet4($name)`, `inet6($name)`
- `uuid($name = 'uuid')` (identidad única, importante para API PK)
- `vector($name)`

---

## 4. Modificadores de Blueprint

Puedes encadenar modificadores (métodos fluidos) luego de cualquier definición de columna.

- `nullable()`: permite valor NULL (reemplaza `NOT NULL` por `NULL`).
- `unique()`: crea índice único.
- `default($value)`: establece valor por defecto (strings con comillas automáticas, bool 0/1).
- `after($column)`: (solo para `alter`) ubica nueva columna tras otra columna.
- `autoIncrement()`: convierte la última columna en `AUTO_INCREMENT`.
- `primaryKey()`: marca la última columna como `PRIMARY KEY`.
- `softDeletes()`: agrega `deleted_at DATETIME NULL`.

---

## 5. Índices y llaves foráneas

- `index($column, $name = null)`
- `uniqueIndex($column, $name = null)`
- `foreign($column, $refTable, $refColumn = 'id', $onDelete = 'RESTRICT')`
   - Agrega índice + constraint
   - `onDelete`: `RESTRICT`, `CASCADE`, `SET NULL` (según MySQL)
   - También soporta encadenamiento: `->references('column')` para cambiar la columna referenciada, `->onDelete('action')` para la acción de eliminación.

Estas se agregan a SQL final dentro de `CREATE TABLE` (o `ALTER TABLE` cuando está en modo alter).

---

## 6. Alteraciones de esquema con Blueprint

Para tablas existentes, usa `php dash make:alter <tabla>` y en `up()` del archivo resultante:

- `alter()` (cambia modo implícito para `ADD COLUMN` y `pendingAlter`)
- `dropColumn($name)`
- `renameColumn($from, $to)`
- `modifyColumn($name, $type)` (ej: `modifyColumn('price', 'DECIMAL(10,2)')`)
- `foreign`/`index`/`uniqueIndex` con `mode alter`

En `down()` debes revertir: quitar columna, renombrar, etc. (no hay reversión automática).

---

## 7. UI  metadata y su impacto en Scaffolding

`Blueprint` guarda metadata UI para la última columna con `.ui(component, options)`.
Esta metadata controla los componentes del admin y la API al generar campos desde `ScaffoldGenerator`.

- `component` posibles valores: `input`, `textarea`, `select`, `switch`, `email`, `password`, `date`, `datetime`, `time`, `file`, etc.
- `options`:
  - para `select`: `['options' => [...]]` o `['relationship' => ['table'=>'x','value'=>'id','label'=>'name']]`
  - para texto: `['rows'=>3]`, etc.

Ejemplo:

```php
$table->string('status', 20)
      ->default('active')
      ->ui('select', [
          'options' => [
              ['value' => 'active', 'label' => 'Activo'],
              ['value' => 'inactive', 'label' => 'Inactivo'],
          ]
      ]);

$table->boolean('is_public')->default(1)->ui('switch');
```

`ScaffoldGenerator` detecta también tipos automáticos:
- tinyint(1) / bool -> `switch`
- text -> `textarea`
- email / _email -> `email`
- password / _password -> `password`
- *_id -> `select` (relación FK internamente)

---

## 8. Control total: `runMigrations`, generación y rollback

### `php dash migrate`
- crea tabla en DB (revisando la tabla `migrations`) y registra.
- cuando migración crea tabla, luego ejecuta `ScaffoldGenerator::generateFromDb`.
- obtiene `uiMetadata` desde migración o desde migración previa encontrada.
- construye CRUD completo y Swagger.

### `php dash rollback`
- busca última entrada en `migrations`.
- ejecuta `down()` y SQL generado (`dropTable` / `alter` según blueprint).
- si tabla se elimina, limpia archivos generados de controllers, rutas, vistas y menu.

---

## 9. Reglas y buenas prácticas para crear tablas robustas

1. Evita nombres reservados: `superadmin`, `migrations` no se scaffoldean.
2. Incluye `timestamps()` en casi todas tus tablas.
3. Usa `softDeletes()` si quieres eliminar lógicos, sobre todo en recursos CRUD.
4. Define explicitamente `->nullable()` para campos opcionales (especialmente texto).
5. Usa `->unique()` para email/slug/identificadores.
6. Para relaciones, ocúpate de cubrir `foreign()->onDelete('CASCADE')` si es necesario.
7. Define `$search` en migración para búsquedas específicas por campo:

```php
public array $search = ['email', 'name'];
```

   - `ScaffoldGenerator` lo usa en API para rutas y filtros exactos.

8. Para slug autogenerado:
   - define `slug` en migración `->string('slug')->unique()->nullable()`
   - el scaffold detecta `slug` y puede generar texto de fallback usando `nombre/title`.

9. Evita alteraciones manuales directas en DB que no pasan por migraciones (desincroniza scaffold y API).

---

## 10. Ejemplo completo: `articulos`

Archivo: `database/migrations/20260402_190935_CreateArticulosTable.php`

```php
<?php
declare(strict_types=1);

class CreateArticulosTable {
    public string $table = 'articulos';

    public function up(\App\Core\Blueprint $table): void {
        $table->id();
        $table->uuid();
        $table->string('name', 120)->unique()->ui('input');
        $table->string('slug', 160)->unique()->nullable()->ui('input');
        $table->text('description')->nullable()->ui('textarea');
        $table->decimal('price', 12, 2)->default(0.00)->ui('input', ['type' => 'number']);
        $table->int('stock')->default(0)->ui('input', ['type' => 'number']);
        $table->boolean('is_active')->default(1)->ui('switch');
        $table->foreign('category_id', 'categorias')->onDelete('SET NULL');
        $table->timestamps();
        $table->softDeletes();

        $table->index('name');
    }

    public function down(\App\Core\Blueprint $table): void {
        $table->dropTable();
    }
}
```

- `ScaffoldGenerator` detecta `uuid` y `slug`, aplica comportamiento especial para PK y SEO.
- `category_id` se convierte en `<select>` relacional en el admin.
- `price` puede ser parseado en API + swagger con tipo decimal.

---

## 11. Perfiles de columnas en UI / comportamiento esperado

- `boolean` / `tinyint` -> `switch` + estado on/off.
- `text` -> `textarea`
- `email` detectado por nombre -> `email` input.
- `password` detectado -> hash + no devuelve en response.
- `_id` -> `select` con relación FK auto-detectada desde `information_schema`.

Si necesitas forzar un componente explicítamente, usa `->ui('component', ['options' => ...])`.

---

## 12. Depuración de migración y scaffold

- `vendor/bin/phpunit` (si lo configuraste) no existe por defecto, pero puedes usar pruebas sql manuales.
- Verificar migration aplicada: `SELECT * FROM migrations;`
- Verificar tabla creada: `DESCRIBE <tabla>;`
- Verificar autogenerados: archivos en `app/Controllers/Api`, `app/Controllers/Web`, `routes`, `app/Views`.
- Reinicia servidor/clear cache si no aparecen cambios en Swagger UI (`php dash swagger:generate`).

---

## 13. Resumen rápido de todos los métodos de Blueprint

Tipo                 | Método
-------------------- | ----------------
INT                  | `int()`
TINYINT              | `tinyInt()`
SMALLINT             | `smallInt()`
MEDIUMINT            | `mediumInt()`
BIGINT               | `bigInt()`
DECIMAL              | `decimal(name,p,s)`
FLOAT                | `float()`
DOUBLE               | `double()`
BIT                  | `bit()`
BOOLEAN (tinyint1)   | `boolean()`
ID PK                | `id()`
CHAR                 | `char(name,len)`
VARCHAR              | `string(name,len)`
TEXT                 | `text()`, `tinyText()`, `mediumText()`, `longText()`
BLOB                 | `blob()`, `longBlob()`
BINARY               | `binary()`
VARBINARY            | `varBinary()`
ENUM                 | `enum(name,array)`
DATE/TIME            | `date`, `dateTime`, `timestamp`, `time`, `year`
ESPACIAL             | `geometry`, `point`, `lineString`, `polygon`
ESPECIALES           | `json`, `inet4`, `inet6`, `uuid`, `vector`
Modificadores        | `nullable`, `unique`, `default`, `after`, `autoIncrement`, `primaryKey`, `softDeletes`
Índices/Claves       | `index`, `uniqueIndex`, `foreign`
Alter schema         | `alter`, `dropColumn`, `renameColumn`, `modifyColumn`

---

## 14. Checklist de migración 100% correcta

- [ ] Nombre de clase y tabla `CreateXTable`, `AlterXTable` consistente con `$table`.
- [ ] `up()` y `down()` implementados.
- [ ] `timestamps()` si requiere auditoría.
- [ ] `softDeletes()` si requiere borrado lógico.
- [ ] `unique` en valores únicos.
- [ ] `foreign()` + `index()` para relaciones.
- [ ] `ui()` para componentes custom y relaciones.
- [ ] `$search` en migración para filtros de API.
- [ ] Evitar tablas reservadas (`superadmin`, `migrations`) para scaffold público.
- [ ] Ejecutar `php dash migrate` y validar `app/Controllers` + `routes` + `Views`.

---

> Nota: Este manual refleja 100% el comportamiento de `app/Console/ScaffoldGenerator.php`, `app/Core/Blueprint.php`, `dash` CLI y migraciones de `database/migrations` en AgroApp (abril 2026). Cualquier cambio en estos componentes requiere actualizar este documento.
