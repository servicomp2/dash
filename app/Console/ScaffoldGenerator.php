<?php
declare(strict_types=1);

namespace App\Console;

use App\Core\Blueprint;
use Medoo\Medoo;

class ScaffoldGenerator {
    private Medoo $db;

    public function __construct(Medoo $db) {
        $this->db = $db;
    }

    public function generateFromJson(string $path): void {
        if (!file_exists($path)) exit("❌ Error: JSON file not found at $path.\n");
        $json = json_decode(file_get_contents($path), true);
        if (!$json || !isset($json['tableName'], $json['fields'])) {
            exit("❌ Error: Invalid JSON schema.\n");
        }
        $this->runScaffold($json['tableName'], $json['fields']);
    }

    // Tablas internas del sistema que NO deben generar controladores CRUD públicos
    private const RESERVED_TABLES = ['superadmin', 'migrations'];

    public function generateFromDb(string $tableName, ?object $migrationInstance = null, ?Blueprint $blueprint = null): void {
        // Saltar andamiaje para tablas reservadas del sistema
        if (in_array($tableName, self::RESERVED_TABLES)) {
            echo "⚠️  Tabla reservada '{$tableName}' omitida del andamiaje público (correcto).\n";
            return;
        }
        try {
            $columns = $this->db->query("DESCRIBE {$tableName}")->fetchAll();
            if (!$columns) exit("❌ Error: Table '{$tableName}' not found or has no columns.\n");
        } catch (\Exception $e) {
            exit("❌ Error Database: " . $e->getMessage() . "\n");
        }

        // Si no hay instancia de migración, buscar la más reciente para leer $search
        if (!$migrationInstance) {
            $migDir = __DIR__ . '/../../database/migrations';
            $pattern = $migDir . '/*_Create' . ucfirst($tableName) . 'Table.php';
            $files = glob($pattern);
            if (!empty($files)) {
                sort($files);
                $latestFile = end($files);
                require_once $latestFile;
                $migClass = 'Create' . ucfirst($tableName) . 'Table';
                if (class_exists($migClass)) {
                    $migrationInstance = new $migClass();
                }
            }
        }

        $uiMetadata = $blueprint ? $blueprint->getUiMetadata() : ($migrationInstance instanceof Blueprint ? [] : []);
        if (!$uiMetadata && $blueprint) {
            $uiMetadata = $blueprint->getUiMetadata();
        }
        // If migration has blueprint, get UI from it
        if ($migrationInstance && method_exists($migrationInstance, 'up') && !$blueprint) {
            try {
                $bp = new Blueprint($tableName);
                $migrationInstance->up($bp);
                $uiMetadata = $bp->getUiMetadata();
            } catch (\Throwable $e) {
                $uiMetadata = [];
            }
        } elseif ($blueprint) {
            $uiMetadata = $blueprint->getUiMetadata();
        } else {
            $uiMetadata = [];
        }

        $fields = [];
        foreach ($columns as $col) {
            $name = $col['Field'];
            if (in_array($name, ['id', 'uuid', 'slug', 'created_at', 'updated_at', 'deleted_at'])) continue;

            $sqlType = strtolower($col['Type']);
            $type = 'string';
            if (str_contains($sqlType, 'int')) $type = 'integer';
            elseif (str_contains($sqlType, 'decimal') || str_contains($sqlType, 'float') || str_contains($sqlType, 'double')) $type = 'decimal';
            elseif (str_contains($sqlType, 'text')) $type = 'text';
            elseif (str_contains($sqlType, 'date') || str_contains($sqlType, 'time')) $type = 'date';
            elseif (str_contains($sqlType, 'bool') || $sqlType === 'tinyint(1)') $type = 'boolean';

            $ui = $uiMetadata[$name] ?? [];

            // Auto-detect UI component from SQL type & column name
            if (!isset($ui['component'])) {
                if ($sqlType === 'tinyint(1)' || $type === 'boolean') $ui['component'] = 'switch';
                elseif (str_contains($sqlType, 'text')) $ui['component'] = 'textarea';
                elseif ($name === 'email' || str_ends_with($name, '_email')) $ui['component'] = 'email';
                elseif ($name === 'password' || str_ends_with($name, '_password')) $ui['component'] = 'password';
                elseif (str_ends_with($name, '_id')) $ui['component'] = 'select';
            }

            $fields[] = [
                'name'       => $name,
                'type'       => $type,
                'sqlType'    => $sqlType,
                'searchable' => in_array($type, ['string', 'text']),
                'ui'         => $ui
            ];
        }

        // Detección automática de Relaciones (Foreign Keys)
        try {
            $fks = $this->db->query("
                SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = '{$tableName}'
                AND TABLE_SCHEMA = '{$_ENV['DB_NAME']}'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ")->fetchAll();

            foreach ($fields as &$field) {
                foreach ($fks as $fk) {
                    if ($field['name'] === $fk['COLUMN_NAME']) {
                        $field['ui']['component'] = $field['ui']['component'] ?? 'select';
                        $field['ui']['options']['relationship'] = [
                            'table' => $fk['REFERENCED_TABLE_NAME'],
                            'value' => $fk['REFERENCED_COLUMN_NAME'],
                            'label' => 'name'
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail if information_schema isn't accessible
        }

        // Determinar Primary Key (API usa uuid si existe, Web admin siempre usa id)
        $pk = 'id';
        $pkType = 'integer';
        foreach ($columns as $col) {
            if ($col['Field'] === 'uuid') {
                $pk = 'uuid';
                $pkType = 'string';
                break;
            }
        }

        // Leer $search desde la migración (parámetros de búsqueda exacta para API)
        $searchableParams = $migrationInstance->search ?? [];

        // Detectar si existe columna slug
        $hasSlug = false;
        $slugSource = null;
        foreach ($columns as $col) {
            if ($col['Field'] === 'slug') $hasSlug = true;
            if (!$slugSource && in_array(strtolower($col['Field']), ['name', 'title', 'label', 'titulo', 'nombre'])) {
                $slugSource = $col['Field'];
            }
        }

        $hasUuid = false;
        foreach ($columns as $col) {
            if ($col['Field'] === 'uuid') {
                $hasUuid = true;
                break;
            }
        }

        $this->runScaffold($tableName, $fields, $pk, $pkType, $searchableParams, $hasSlug, $slugSource, $hasUuid);
    }

    private function runScaffold(string $tableName, array $fields, string $pk = 'id', string $pkType = 'integer', array $searchableParams = [], bool $hasSlug = false, ?string $slugSource = null, bool $hasUuid = false): void {
        echo "🚀 Iniciando Scaffold para: $tableName \n";
        $className = ucfirst($tableName);
        
        // buildMigration is skipped here. It must exist before scaffolding.
        
        $this->buildApiController($tableName, $className, $fields, $pk, $pkType, $searchableParams, $hasSlug, $slugSource);
        $this->buildWebController($tableName, $className, $fields, $pk, $hasSlug, $slugSource, $hasUuid);
        $this->buildApiRoute($tableName, $className);
        $this->buildWebRoute($tableName, $className, $pkType);
        $this->buildViews($tableName, $className, $fields, $pk);
        
        echo "✅ Andamiaje completado para $tableName.\n";
    }

    private function getStub(string $name): string {
        $path = __DIR__ . '/../../stubs/' . $name;
        if (!file_exists($path)) throw new \Exception("Stub not found: $name");
        return file_get_contents($path);
    }

    public function generateMigrationOnly(string $tableName): void {
        $className = ucfirst($tableName);
        $dir = __DIR__ . '/../../database/migrations';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('migration.stub');
        // Campos de ejemplo
        $code = "        \$table->string('name');\n";

        $stub = str_replace(['{{className}}', '{{tableName}}', '{{authBoolean}}', '{{migrationFields}}', '{{searchFields}}'], 
                            ["Create{$className}Table", $tableName, 'true', $code, "'name'"], $stub);
                            
        $timestamp = date('Ymd_His');
        $file = "{$dir}/{$timestamp}_Create{$className}Table.php";
        file_put_contents($file, $stub);
        echo "✅ Migración vacía generada en: {$file}\n";
    }

    public function generateAlterMigration(string $tableName): void {
        $className = ucfirst($tableName);
        $dir = __DIR__ . '/../../database/migrations';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('alter_migration.stub');

        $stub = str_replace(['{{className}}', '{{tableName}}'], 
                            ["Alter{$className}Table", $tableName], $stub);
                            
        $timestamp = date('Ymd_His');
        $file = "{$dir}/{$timestamp}_Alter{$className}Table.php";
        file_put_contents($file, $stub);
        echo "✅ Migración de alteración generada en: {$file}\n";
    }

    public function generateAuth(): void {
        echo "🚀 Generando Módulo Base de Seguridad Estrícta (Auth)...\n";
        
        // 1. Migración maestra superadmin
        $tableName = 'superadmin';
        $className = 'Superadmin';
        $dirMig = __DIR__ . '/../../database/migrations';
        if (!is_dir($dirMig)) mkdir($dirMig, 0777, true);
        
        $stubMig = $this->getStub('migration.stub');
        $code = "        \$table->string('name', 150)->nullable();\n" . 
                "        \$table->string('email', 150)->unique();\n" . 
                "        \$table->string('password', 255)->nullable();\n";
        $stubMig = str_replace(['{{className}}', '{{tableName}}', '{{authBoolean}}', '{{migrationFields}}', '{{searchFields}}'], 
                            ["Create{$className}Table", $tableName, 'true', $code, "'email', 'name'"], $stubMig);
        
        $timestamp = date('Ymd_His');
        file_put_contents("{$dirMig}/{$timestamp}_Create{$className}Table.php", $stubMig);
        echo "✅ Migración de Auth con contraseña generada (Tabla superadmin).\n";
        
        // 2. Controlador de autenticación con emisor JWT
        $dirApi = __DIR__ . '/../../app/Controllers/Api';
        if (!is_dir($dirApi)) mkdir($dirApi, 0777, true);
        $authC = <<<PHP
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use OpenApi\Attributes as OA;
use Firebase\JWT\JWT;

class AuthController {

    #[OA\Post(
        path: "/api/v1/auth/login",
        summary: "Iniciar sesión con email y contraseña",
        tags: ["Auth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: "object",
                    required: ["email", "password"],
                    properties: [
                        new OA\Property(property: "email", type: "string", example: "admin@dash.com"),
                        new OA\Property(property: "password", type: "string", example: "supersecret")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Autenticación correcta y token JWT devuelto"),
            new OA\Response(response: 401, description: "Credenciales inválidas")
        ]
    )]
    public function login(): void {
        global \$db;
        \$input = json_decode(file_get_contents('php://input'), true);
        \$email = trim(\$input['email'] ?? '');
        \$password = trim(\$input['password'] ?? '');

        if (!filter_var(\$email, FILTER_VALIDATE_EMAIL) || \$password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Email o contraseña inválidos']);
            return;
        }

        \$user = \$db->get('superadmin', '*', ['email' => \$email, 'deleted_at' => null]);
        if (!\$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Email o contraseña incorrectos']);
            return;
        }

        \$storedPassword = \$user['password'] ?? null;
        if (!\$storedPassword || !password_verify(\$password, \$storedPassword)) {
            http_response_code(401);
            echo json_encode(['error' => 'Email o contraseña incorrectos']);
            return;
        }

        \$payload = [
            'iss' => "DashAPI",
            'sub' => \$user['uuid'] ?? \$user['id'],
            'type' => 'superadmin',
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24) // 24H
        ];

        \$jwt = JWT::encode(\$payload, \$_ENV['JWT_SECRET'], \$_ENV['JWT_ALGO'] ?? 'HS256');
        echo json_encode(['token' => \$jwt, 'user' => ['name' => \$user['name'], 'email' => \$user['email']]]);
    }
}
PHP;
        file_put_contents("{$dirApi}/AuthController.php", $authC);
        echo "✅ Controlador de Autenticación con contraseña guardado.\n";
        
        $this->injectRequire(__DIR__ . '/../../routes/api.php', "    \$router->post('/auth/login', 'Api\\AuthController@login');\n");
        echo "✅ Ruta de login montada en el router.\n";
    }

    private function buildMigration(string $tableName, string $className, array $fields): void {
        $dir = __DIR__ . '/../../database/migrations';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('migration.stub');
        $code = "";
        foreach ($fields as $field) {
            $type = $field['type'] === 'text' ? 'text' : ($field['type'] === 'integer' ? 'integer' : 'string'); // Blueprint abstraction
            $code .= "        \$table->{$type}('{$field['name']}');\n";
        }

        $stub = str_replace(['{{className}}', '{{tableName}}', '{{authBoolean}}', '{{migrationFields}}', '{{searchFields}}'], 
                            ["Create{$className}Table", $tableName, 'true', $code, "'name'"], $stub);
                            
        $timestamp = date('Ymd_His');
        file_put_contents("{$dir}/{$timestamp}_Create{$className}Table.php", $stub);
        echo " - Migración generada.\n";
    }

    private function buildApiController(string $tableName, string $className, array $fields, string $pk = 'id', string $pkType = 'integer', array $searchableParams = [], bool $hasSlug = false, ?string $slugSource = null): void {
        $dir = __DIR__ . '/../../app/Controllers/Api';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('controller_api.stub');

        $swaggerProps = "";
        $searchFields = [];
        
        $queryParameters = "";
        $searchParamsList = "";
        foreach ($searchableParams as $param) {
            $queryParameters .= "            new OA\Parameter(name: \"{$param}\", in: \"query\", description: \"Filtro exacto de campo {$param}\", required: false, schema: new OA\Schema(type: \"string\")),\n";
            $searchParamsList .= "'{$param}', ";
        }
        $queryParameters = rtrim($queryParameters, ",\n");
        $searchParamsList = rtrim($searchParamsList, ", ");

        foreach ($fields as $field) {
            if ($field['name'] === 'id' || str_ends_with($field['name'], '_id') || in_array($field['name'], ['created_at', 'updated_at', 'deleted_at'])) continue;
            
            $swaggerType = $field['type'] === 'integer' ? 'integer' : 'string';
            $defaultEx = $swaggerType === 'integer' ? '0' : '""';
            $swaggerProps .= "                        new OA\Property(property: '{$field['name']}', type: '{$swaggerType}', example: {$defaultEx}),\n";

            if ($field['type'] === 'string' || $field['type'] === 'text') {
                $searchFields[] = "                '{$field['name']}[~]' => \$params['q']";
            }
        }
        $swaggerProps = rtrim($swaggerProps, ",\n") . "\n";
        $searchCode = empty($searchFields) ? "                // No hay campos buscables" : implode(",\n", $searchFields);

        $autoFields = "";
        if ($hasSlug && $slugSource) {
            $autoFields = "        if (empty(\$input['slug'])) \$input['slug'] = \\App\\Utils\\Slug::create(\$input['{$slugSource}'] ?? '');\n";
        }

        $showSearchFallbackCode = "";
        foreach ($searchableParams as $param) {
            $showSearchFallbackCode .= "        \$where['OR']['{$param}'] = \$identifier;\n";
        }

        $stub = str_replace(
            ['{{className}}', '{{tableName}}', '{{swaggerProperties}}', '{{searchFields}}', '{{pk}}', '{{pkType}}', '{{queryParameters}}', '{{searchParamsList}}', '{{autoFields}}', '{{showSearchFallbackCode}}'], 
            [$className, $tableName, $swaggerProps, $searchCode, $pk, $pkType, $queryParameters, $searchParamsList, $autoFields, $showSearchFallbackCode], 
            $stub
        );
        file_put_contents("{$dir}/{$className}Controller.php", $stub);
        echo " - Api Controller generado con Swagger y llave primaria $pk.\n";
    }

    private function buildWebController(string $tableName, string $className, array $fields, string $pk = 'id', bool $hasSlug = false, ?string $slugSource = null, bool $hasUuid = false): void {
        $dir = __DIR__ . '/../../app/Controllers/Web';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('controller_web.stub');

        // --- Auto field injection (uuid, slug, booleans, passwords) ---
        $autoFields = "";
        if ($hasUuid) {
            $autoFields .= "        if (!isset(\$input['uuid']) && \$_SERVER['REQUEST_METHOD'] === 'POST' && str_contains(\$_SERVER['REQUEST_URI'], 'store')) {\n" . 
                           "            \$input['uuid'] = \\App\\Utils\\Guid::v4();\n" . 
                           "        }\n";
        }
        if ($hasSlug && $slugSource) {
            $autoFields .= "        if (empty(\$input['slug'])) \$input['slug'] = \\App\\Utils\\Slug::create(\$input['{$slugSource}'] ?? '');\n";
        }
        foreach ($fields as $f) {
            if (($f['ui']['component'] ?? '') === 'switch') {
                $autoFields .= "        \$input['{$f['name']}'] = isset(\$input['{$f['name']}']) ? 1 : 0;\n";
            } elseif (($f['ui']['component'] ?? '') === 'password') {
                $autoFields .= "        if (isset(\$input['{$f['name']}'])) {\n" .
                               "            if (\$input['{$f['name']}'] === '') {\n" .
                               "                unset(\$input['{$f['name']}']);\n" .
                               "            } else {\n" .
                               "                \$input['{$f['name']}'] = password_hash(\$input['{$f['name']}'], PASSWORD_DEFAULT);\n" .
                               "            }\n" .
                               "        }\n";
            }
        }
        $stub = str_replace('{{autoFields}}', $autoFields, $stub);

        // --- Search WHERE OR block for text columns ---
        $searchOrParts = [];
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', ['string', 'text']) || str_contains(strtolower($f['sqlType'] ?? ''), 'char')) {
                $searchOrParts[] = "'{$f['name']}[~]' => \$q";
            }
        }
        if (empty($searchOrParts)) {
            $stub = str_replace('{{searchWhereOr}}', "['id[~]' => \$q]", $stub);
        } else {
            $stub = str_replace('{{searchWhereOr}}', '[' . implode(', ', $searchOrParts) . ']', $stub);
        }

        // --- Context blocks for relationship selects ---
        $createContextLines = [];
        $editContextLines   = [];
        foreach ($fields as $f) {
            $ui = $f['ui'] ?? [];
            if (($ui['component'] ?? '') === 'select' && isset($ui['options']['relationship'])) {
                $rel = $ui['options']['relationship'];
                $line = "        \$context['context_{$f['name']}'] = \$db->select('{$rel['table']}', '*');";
                $createContextLines[] = $line;
                $editContextLines[]   = $line;
            }
        }

        if (!empty($createContextLines)) {
            $createBlock = "        global \$db;\n        \$context = [];\n" . implode("\n", $createContextLines) . "\n";
            $stub = str_replace('{{createContextBlock}}', $createBlock, $stub);
            $stub = str_replace('{{createContextVar}}', '$context', $stub);
        } else {
            $stub = str_replace('{{createContextBlock}}', '', $stub);
            $stub = str_replace('{{createContextVar}}', '[]', $stub);
        }

        if (!empty($editContextLines)) {
            $editBlock = "        global \$db;\n        \$context = [];\n" . implode("\n", $editContextLines) . "\n";
            $stub = str_replace('{{editContextBlock}}', $editBlock, $stub);
            $stub = str_replace('{{editContextVar}}', '$context', $stub);
        } else {
            $stub = str_replace('{{editContextBlock}}', '', $stub);
            $stub = str_replace('{{editContextVar}}', '[]', $stub);
        }

        $stub = str_replace(['{{className}}', '{{tableName}}'], [$className, $tableName], $stub);
        file_put_contents("{$dir}/{$className}Controller.php", $stub);
        echo " - Web Controller generado.\n";
    }

    private function buildApiRoute(string $tableName, string $className): void {
        $dir = __DIR__ . '/../../routes/api_modules';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('route_api.stub');
        $authMid = "    \$router->before('GET|POST|PUT|DELETE', '.*', function() { \\App\\Middleware\\Guard::auth(); });\n" . 
                   "    \$router->before('GET|POST|PUT|DELETE', '', function() { \\App\\Middleware\\Guard::auth(); });\n";

        $stub = str_replace(['{{cleanRoute}}', '{{className}}', '{{authMiddleware}}'], 
                            [$tableName, $className, $authMid], $stub);
        file_put_contents("{$dir}/{$tableName}.php", $stub);
        
        $this->injectRequire(__DIR__ . '/../../routes/api.php', "require_once __DIR__ . '/api_modules/{$tableName}.php';");
        echo " - API Route inyectada.\n";
    }

    private function buildWebRoute(string $tableName, string $className, string $pkType = 'integer'): void {
        $dir = __DIR__ . '/../../routes/web_modules';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $stub = $this->getStub('route_web.stub');
        // Web admin ALWAYS uses numeric id — simpler, no UUID regex edge-cases
        $stub = str_replace(['{{cleanRoute}}', '{{className}}', '{{pkRegex}}'], [$tableName, $className, '(\d+)'], $stub);
        file_put_contents("{$dir}/{$tableName}.php", $stub);
        
        $webFile = __DIR__ . '/../../routes/web.php';
        if (!file_exists($webFile)) {
            file_put_contents($webFile, "<?php\n/** @var \\Bramus\\Router\\Router \$router */\n\n");
            // Inject to index.php
            $indexFile = __DIR__ . '/../../public/index.php';
            $indexC = file_get_contents($indexFile);
            $injectWeb = "\nif (file_exists(__DIR__ . '/../routes/web.php')) {\n    require_once __DIR__ . '/../routes/web.php';\n}\n";
            file_put_contents($indexFile, str_replace('$router->run();', $injectWeb . "\n\$router->run();", $indexC));
        }

        $this->injectRequire($webFile, "require_once __DIR__ . '/web_modules/{$tableName}.php';");
        echo " - Web Route inyectada.\n";
    }

    private function buildViews(string $tableName, string $className, array $fields, string $pk = 'id'): void {
        $dir = __DIR__ . '/../../app/Views/' . $tableName;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        // INDEX: build column list (skip password, large text fields from the table display)
        $displayFields = array_filter($fields, fn($f) => !in_array($f['ui']['component'] ?? '', ['password', 'textarea']));
        $colNames = array_column(array_values($displayFields), 'name');
        $colsArrayCode = "['" . implode("', '", $colNames) . "']";

        $stubIndex = $this->getStub('views/index.twig');
        $stubIndex = str_replace(['{{className}}', '{{tableName}}'], [$className, $tableName], $stubIndex);
        $stubIndex = "{% set columns = {$colsArrayCode} %}\n" . $stubIndex;
        file_put_contents("{$dir}/index.twig", $stubIndex);

        // BUILD FORM FIELDS (shared for create & edit)
        $formFields = "";
        foreach ($fields as $f) {
            $n       = $f['name'];
            $label   = ucwords(str_replace('_', ' ', $n));
            $ui      = $f['ui'] ?? [];
            $comp    = $ui['component'] ?? 'text';
            $options = $ui['options'] ?? [];
            $colCss  = "col-md-6"; // default 2-column layout

            // Full-width for textareas and large fields
            if (in_array($comp, ['textarea', 'password'])) $colCss = "col-12";

            if ($comp === 'textarea') {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <textarea name="{$n}" class="form-control" rows="4">{{ record.{$n}|default('') }}</textarea>
                        </div>\n
HTML;
            } elseif ($comp === 'switch') {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold d-block">{$label}</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="{$n}" id="sw_{$n}" value="1" {{ record.{$n} ? 'checked' : '' }}>
                                <label class="form-check-label" for="sw_{$n}">Activo</label>
                            </div>
                        </div>\n
HTML;
            } elseif ($comp === 'password') {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <input type="password" name="{$n}" class="form-control" autocomplete="new-password" placeholder="Dejar vacío para no cambiar">
                            <div class="form-text text-muted">Dejar vacío para conservar el valor actual.</div>
                        </div>\n
HTML;
            } elseif ($comp === 'select') {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <select name="{$n}" class="form-select">
                                <option value="">— Seleccione —</option>
HTML;
                if (isset($options['options'])) {
                    foreach ($options['options'] as $val => $lab) {
                        $formFields .= "                                <option value=\"{$val}\" {{ record.{$n} == '{$val}' ? 'selected' : '' }}>{$lab}</option>\n";
                    }
                } elseif (isset($options['relationship'])) {
                    $formFields .= <<<HTML
                                {% for item in context_{$n} %}
                                <option value="{{ item.id }}" {{ record.{$n} == item.id ? 'selected' : '' }}>{{ item.name|default(item.title|default(item.id)) }}</option>
                                {% endfor %}
HTML;
                }
                $formFields .= <<<HTML
                            </select>
                        </div>\n
HTML;
            } elseif ($comp === 'email') {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <input type="email" name="{$n}" class="form-control" value="{{ record.{$n}|default('') }}" placeholder="correo@ejemplo.com">
                        </div>\n
HTML;
            } elseif (in_array($comp, ['date', 'datetime-local', 'time'])) {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <input type="{$comp}" name="{$n}" class="form-control" value="{{ record.{$n}|default('') }}">
                        </div>\n
HTML;
            } elseif (in_array($comp, ['number', 'tel'])) {
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <input type="{$comp}" name="{$n}" class="form-control" value="{{ record.{$n}|default('') }}">
                        </div>\n
HTML;
            } else {
                // generic text
                $formFields .= <<<HTML
                        <div class="{$colCss}">
                            <label class="form-label fw-semibold">{$label}</label>
                            <input type="text" name="{$n}" class="form-control" value="{{ record.{$n}|default('') }}">
                        </div>\n
HTML;
            }
        }

        $stubCreate = $this->getStub('views/create.twig');
        $stubCreate = str_replace(['{{className}}', '{{tableName}}', '{{formFields}}'], [$className, $tableName, $formFields], $stubCreate);
        file_put_contents("{$dir}/create.twig", $stubCreate);

        $stubEdit = $this->getStub('views/edit.twig');
        $stubEdit = str_replace(['{{className}}', '{{tableName}}', '{{formFields}}'], [$className, $tableName, $formFields], $stubEdit);
        file_put_contents("{$dir}/edit.twig", $stubEdit);

        // Copy generic layout once if doesn't exist in root Views
        $layoutDest = __DIR__ . '/../../app/Views/layout.twig';
        if (!file_exists($layoutDest)) {
            $layoutStr = $this->getStub('views/layout.twig');
            $layoutStr = str_replace('{{menuItems}}', '', $layoutStr);
            file_put_contents($layoutDest, $layoutStr);
        }

        // Add to Sidebar using the anchor comment
        $layoutDest = __DIR__ . '/../../app/Views/layout.twig';
        if (file_exists($layoutDest)) {
            $layoutC = file_get_contents($layoutDest);
            $anchor = '{# ===== módulos dinámicos aquí ===== #}';
            $menuItem = <<<HTML
                        <li class="nav-item">
                            <a class="nav-link" href="/admin/{$tableName}">
                                <i class="bi bi-table me-2"></i> {$className}
                            </a>
                        </li>
HTML;
            if (!str_contains($layoutC, "/admin/{$tableName}")) {
                $newLayout = str_replace($anchor, $anchor . "\n" . $menuItem, $layoutC);
                file_put_contents($layoutDest, $newLayout);
            }
        }

        echo " - Twig Views generadas.\n";
    }

    private function injectRequire(string $targetFile, string $requireStmt): void {
        $c = file_get_contents($targetFile);
        if (!str_contains($c, $requireStmt)) {
            if (str_contains($c, '});')) {
                // api.php case, before });
                $pos = strrpos($c, '});');
                if ($pos !== false) {
                    $c = substr_replace($c, "    {$requireStmt}\n", $pos, 0);
                    file_put_contents($targetFile, $c);
                }
            } else {
                // web.php case, append
                file_put_contents($targetFile, "\n" . $requireStmt, FILE_APPEND);
            }
        }
    }
    public function generateUserAuth(string $tableName = 'users'): void {
        echo "🚀 Generando Sistema de Usuarios y Roles (App Auth)...\n";
        
        $className = ucfirst($tableName);
        $dirMig = __DIR__ . '/../../database/migrations';
        if (!is_dir($dirMig)) mkdir($dirMig, 0777, true);
        
        // 1. Migración de ROLES
        $roleClass = 'Roles';
        $roleTable = 'roles';
        $stubRole = $this->getStub('migration.stub');
        $roleCode = "        \$table->string('name', 50)->unique();\n" . 
                    "        \$table->string('slug', 50)->unique();\n";
        $stubRole = str_replace(['{{className}}', '{{tableName}}', '{{authBoolean}}', '{{migrationFields}}', '{{searchFields}}'], 
                            ["Create{$roleClass}Table", $roleTable, 'false', $roleCode, "'name', 'slug'"], $stubRole);
        
        $timestamp1 = date('Ymd_His', time());
        file_put_contents("{$dirMig}/{$timestamp1}_Create{$roleClass}Table.php", $stubRole);
        echo "✅ Migración de Roles generada.\n";

        // 2. Migración de USUARIOS
        $stubMig = $this->getStub('migration.stub');
        $code = "        \$table->int('role_id')->nullable();\n" .
                "        \$table->string('name', 150)->nullable();\n" . 
                "        \$table->string('email', 150)->unique();\n" . 
                "        \$table->string('password', 255)->nullable();\n" .
                "        \$table->foreign('role_id', 'roles');\n";
        $stubMig = str_replace(['{{className}}', '{{tableName}}', '{{authBoolean}}', '{{migrationFields}}', '{{searchFields}}'], 
                            ["Create{$className}Table", $tableName, 'true', $code, "'email', 'name'"], $stubMig);
        
        $timestamp2 = date('Ymd_His', time() + 1);
        file_put_contents("{$dirMig}/{$timestamp2}_Create{$className}Table.php", $stubMig);
        echo "✅ Migración de Usuarios con contraseña generada (Tabla $tableName).\n";

        // 3. Controlador de autenticación para USUARIOS
        $dirApi = __DIR__ . '/../../app/Controllers/Api';
        if (!is_dir($dirApi)) mkdir($dirApi, 0777, true);
        
        $userAuthC = <<<PHP
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use OpenApi\Attributes as OA;
use Firebase\JWT\JWT;

class UserAuthController {

    #[OA\Post(
        path: "/api/v1/user/auth/login",
        summary: "Iniciar sesión de usuario con email y contraseña",
        tags: ["UserAuth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: "object",
                    required: ["email", "password"],
                    properties: [
                        new OA\Property(property: "email", type: "string", example: "user@example.com"),
                        new OA\Property(property: "password", type: "string", example: "secret123")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Autenticación de usuario correcta"),
            new OA\Response(response: 401, description: "Credenciales inválidas")
        ]
    )]
    public function login(): void {
        global \$db;
        \$input = json_decode(file_get_contents('php://input'), true);
        \$email = trim(\$input['email'] ?? '');
        \$password = trim(\$input['password'] ?? '');

        if (!filter_var(\$email, FILTER_VALIDATE_EMAIL) || \$password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Email o contraseña inválidos']);
            return;
        }

        \$user = \$db->get('{$tableName}', '*', ['email' => \$email, 'deleted_at' => null]);
        if (!\$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Email o contraseña incorrectos']);
            return;
        }

        \$storedPassword = \$user['password'] ?? null;
        if (!\$storedPassword || !password_verify(\$password, \$storedPassword)) {
            http_response_code(401);
            echo json_encode(['error' => 'Email o contraseña incorrectos']);
            return;
        }

        \$payload = [
            'iss' => "DashAPI",
            'sub' => \$user['uuid'] ?? \$user['id'],
            'type' => 'user',
            'role' => \$user['role_id'],
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24 * 7) // 7 días para usuarios finales
        ];

        \$jwt = JWT::encode(\$payload, \$_ENV['JWT_SECRET'], \$_ENV['JWT_ALGO'] ?? 'HS256');
        echo json_encode(['token' => \$jwt, 'user' => ['name' => \$user['name'], 'email' => \$user['email']]]);
    }
}
PHP;
        file_put_contents("{$dirApi}/UserAuthController.php", $userAuthC);
        echo "✅ Controlador UserAuthController con contraseña generado.\n";
        
        // 4. Inyectar Rutas
        $routeCode = "\n    // Auth de Usuarios Finales (App)\n" . 
                    "    \$router->post('/user/auth/login', 'Api\\UserAuthController@login');\n";
        $this->injectRequire(__DIR__ . '/../../routes/api.php', $routeCode);
        echo "✅ Ruta de usuario inyectada en api.php.\n";
    }
}

