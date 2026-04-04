<?php
declare(strict_types=1);

namespace App\Core;

class Blueprint {
    private string $tableName;
    private array $columns = [];
    private array $indexes = [];
    private array $foreignKeys = [];
    private string $mode = 'create'; // 'create' o 'alter'
    private array $pendingAlter = []; // Para coleccionar ADD, DROP, MODIFY, RENAME
    private array $uiMetadata = [];
    private string $lastColumnName = '';
    private bool $isDropTable = false;
    private array $currentForeign = []; // Para construir foreign keys con chaining

    public function __construct(string $tableName) {
        $this->tableName = $tableName;
    }

    /**
     * Activa el modo ALTER para esta tabla.
     */
    public function alter(): self {
        $this->mode = 'alter';
        return $this;
    }

    // --- 1. TIPOS NUMÉRICOS ---
    public function tinyInt(string $name) { $this->addColumn($name, "TINYINT"); return $this; }
    public function smallInt(string $name) { $this->addColumn($name, "SMALLINT"); return $this; }
    public function mediumInt(string $name) { $this->addColumn($name, "MEDIUMINT"); return $this; }
    public function int(string $name) { $this->addColumn($name, "INT"); return $this; }
    public function bigInt(string $name) { $this->addColumn($name, "BIGINT"); return $this; }
    public function decimal(string $name, int $p = 10, int $s = 2) { $this->addColumn($name, "DECIMAL($p,$s)"); return $this; }
    public function float(string $name) { $this->addColumn($name, "FLOAT"); return $this; }
    public function double(string $name) { $this->addColumn($name, "DOUBLE"); return $this; }
    public function bit(string $name, int $len = 1) { $this->addColumn($name, "BIT($len)"); return $this; }
    public function boolean(string $name) { $this->addColumn($name, "TINYINT(1)"); return $this; }
    public function id() { $this->addColumn('id', "INT AUTO_INCREMENT PRIMARY KEY"); return $this; }

    // --- 2. CADENAS Y BINARIOS ---
    public function char(string $name, int $len = 255) { $this->addColumn($name, "CHAR($len)"); return $this; }
    public function string(string $name, int $len = 255) { $this->addColumn($name, "VARCHAR($len)"); return $this; }
    public function tinyText(string $name) { $this->addColumn($name, "TINYTEXT"); return $this; }
    public function text(string $name) { $this->addColumn($name, "TEXT"); return $this; }
    public function mediumText(string $name) { $this->addColumn($name, "MEDIUMTEXT"); return $this; }
    public function longText(string $name) { $this->addColumn($name, "LONGTEXT"); return $this; }
    public function binary(string $name, int $len = 255) { $this->addColumn($name, "BINARY($len)"); return $this; }
    public function varBinary(string $name, int $len = 255) { $this->addColumn($name, "VARBINARY($len)"); return $this; }
    public function blob(string $name) { $this->addColumn($name, "BLOB"); return $this; }
    public function longBlob(string $name) { $this->addColumn($name, "LONGBLOB"); return $this; }
    public function enum(string $name, array $allowed) { 
        $list = "'" . implode("','", $allowed) . "'";
        $this->addColumn($name, "ENUM($list)"); return $this; 
    }

    // --- 3. FECHA Y HORA ---
    public function date(string $name) { $this->addColumn($name, "DATE"); return $this; }
    public function dateTime(string $name, int $fsp = 0) { $this->addColumn($name, "DATETIME($fsp)"); return $this; }
    public function timestamp(string $name, int $fsp = 0) { $this->addColumn($name, "TIMESTAMP($fsp)"); return $this; }
    public function time(string $name) { $this->addColumn($name, "TIME"); return $this; }
    public function year(string $name) { $this->addColumn($name, "YEAR"); return $this; }
    public function timestamps() {
        $this->addColumn('created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $this->addColumn('updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }

    // --- 4. TIPOS ESPACIALES ---
    public function geometry(string $name) { $this->addColumn($name, "GEOMETRY"); return $this; }
    public function point(string $name) { $this->addColumn($name, "POINT"); return $this; }
    public function lineString(string $name) { $this->addColumn($name, "LINESTRING"); return $this; }
    public function polygon(string $name) { $this->addColumn($name, "POLYGON"); return $this; }

    // --- 5. ESPECIALES (JSON, INET, UUID) ---
    public function json(string $name) { $this->addColumn($name, "JSON"); return $this; }
    public function inet4(string $name) { $this->addColumn($name, "INET4"); return $this; }
    public function inet6(string $name) { $this->addColumn($name, "INET6"); return $this; }
    public function uuid(string $name = 'uuid') { $this->addColumn($name, "UUID"); return $this; }
    public function vector(string $name) { $this->addColumn($name, "VECTOR"); return $this; }

    // --- MODIFICADORES ---
    public function nullable() {
        if ($this->mode === 'create') {
            if (empty($this->columns)) return $this;
            $last = array_key_last($this->columns);
            $this->columns[$last] = str_replace("NOT NULL", "NULL", $this->columns[$last]);
        } else {
            if (empty($this->pendingAlter)) return $this;
            $last = array_key_last($this->pendingAlter);
            $this->pendingAlter[$last] = str_replace("NOT NULL", "NULL", $this->pendingAlter[$last]);
        }
        return $this;
    }

    public function unique() {
        if ($this->mode === 'create') {
            if (empty($this->columns)) return $this;
            $last = array_key_last($this->columns);
            $this->columns[$last] .= " UNIQUE";
        } else {
            if (empty($this->pendingAlter)) return $this;
            $last = array_key_last($this->pendingAlter);
            $this->pendingAlter[$last] .= " UNIQUE";
        }
        return $this;
    }

    public function default($value) {
        $formattedValue = is_string($value) ? "'$value'" : $value;
        if (is_bool($value)) $formattedValue = $value ? 1 : 0;

        if ($this->mode === 'create') {
            if (empty($this->columns)) return $this;
            $last = array_key_last($this->columns);
            $this->columns[$last] .= " DEFAULT $formattedValue";
        } else {
            if (empty($this->pendingAlter)) return $this;
            $last = array_key_last($this->pendingAlter);
            $this->pendingAlter[$last] .= " DEFAULT $formattedValue";
        }
        return $this;
    }

    public function after(string $column): self {
        if ($this->mode === 'alter' && !empty($this->pendingAlter)) {
            $last = array_key_last($this->pendingAlter);
            $this->pendingAlter[$last] .= " AFTER `$column`";
        }
        return $this;
    }

    public function autoIncrement() {
        if (empty($this->columns)) return $this;
        $last = array_key_last($this->columns);
        $this->columns[$last] = str_replace("NOT NULL", "AUTO_INCREMENT NOT NULL", $this->columns[$last]);
        return $this;
    }

    public function primaryKey() {
        if (empty($this->columns)) return $this;
        $last = array_key_last($this->columns);
        $this->columns[$last] .= " PRIMARY KEY";
        return $this;
    }

    public function softDeletes() { 
        $this->addColumn('deleted_at', "DATETIME");
        $this->nullable(); 
    }

    // --- ÍNDICES Y LLAVES FORÁNEAS ---
    public function index(string $column, ?string $name = null) {
        $name = $name ?? "idx_{$this->tableName}_{$column}";
        if ($this->mode === 'create') {
            $this->indexes[] = "INDEX `$name` (`$column`)";
        } else {
            $this->pendingAlter[] = "ADD INDEX `$name` (`$column`)";
        }
        return $this;
    }

    public function uniqueIndex(string $column, ?string $name = null) {
        $name = $name ?? "unique_{$this->tableName}_{$column}";
        if ($this->mode === 'create') {
            $this->indexes[] = "UNIQUE INDEX `$name` (`$column`)";
        } else {
            $this->pendingAlter[] = "ADD UNIQUE INDEX `$name` (`$column`)";
        }
        return $this;
    }

    public function foreign(string $column, string $refTable, string $refColumn = 'id', string $onDelete = 'RESTRICT') {
        $this->currentForeign = [
            'column' => $column,
            'refTable' => $refTable,
            'refColumn' => $refColumn,
            'onDelete' => $onDelete
        ];
        return $this;
    }

    public function references(string $refColumn): self {
        $this->currentForeign['refColumn'] = $refColumn;
        return $this;
    }

    public function onDelete(string $action): self {
        $this->currentForeign['onDelete'] = $action;
        $this->finalizeForeign();
        return $this;
    }

    private function finalizeForeign(): void {
        if (empty($this->currentForeign)) return;

        $fk = $this->currentForeign;
        $column = $fk['column'];
        $refTable = $fk['refTable'];
        $refColumn = $fk['refColumn'];
        $onDelete = $fk['onDelete'];

        if ($this->mode === 'create') {
            $this->index($column);
            $name = "fk_{$this->tableName}_{$column}_{$refTable}";
            $this->foreignKeys[] = "CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$refTable` (`$refColumn`) ON DELETE $onDelete";
        } else {
            $this->index($column);
            $name = "fk_{$this->tableName}_{$column}_{$refTable}";
            $this->pendingAlter[] = "ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$refTable` (`$refColumn`) ON DELETE $onDelete";
        }

        $this->currentForeign = [];
    }

    // --- NUEVOS MÉTODOS ALTER ---
    public function dropColumn(string $name): self {
        $this->mode = 'alter';
        $this->pendingAlter[] = "DROP COLUMN `$name`";
        return $this;
    }

    public function renameColumn(string $from, string $to): self {
        $this->mode = 'alter';
        $this->pendingAlter[] = "RENAME COLUMN `$from` TO `$to`";
        return $this;
    }

    public function modifyColumn(string $name, string $type): self {
        $this->mode = 'alter';
        $this->pendingAlter[] = "MODIFY COLUMN `$name` $type NOT NULL";
        return $this;
    }

    private function addColumn(string $name, string $type) {
        $this->lastColumnName = $name;
        if ($this->mode === 'create') {
            $this->columns[] = "`$name` $type NOT NULL";
        } else {
            $this->pendingAlter[] = "ADD COLUMN `$name` $type NOT NULL";
        }
    }

    /**
     * Define metadatos de UI para la última columna añadida.
     * @param string $component Tipo de componente (select, switch, textarea, etc.)
     * @param array $options Opciones extra (ej. 'options' => [...] para selects)
     */
    public function ui(string $component, array $options = []): self {
        if ($this->lastColumnName) {
            $this->uiMetadata[$this->lastColumnName] = [
                'component' => $component,
                'options' => $options
            ];
        }
        return $this;
    }

    public function getUiMetadata(): array {
        return $this->uiMetadata;
    }

    public function toSql(): string {
        if ($this->mode === 'alter') {
            if (empty($this->pendingAlter)) return "";
            return "ALTER TABLE `{$this->tableName}`\n  " . implode(",\n  ", $this->pendingAlter) . ";";
        }

        $definitions = array_merge($this->columns, $this->indexes, $this->foreignKeys);
        return "CREATE TABLE `{$this->tableName}` (\n  " . implode(",\n  ", $definitions) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }

    public function dropTable(): string {
        $this->isDropTable = true;
        return "DROP TABLE IF EXISTS `{$this->tableName}`;";
    }

    public function isAlter(): bool {
        return $this->mode === 'alter' || !empty($this->pendingAlter);
    }

    public function isDropTable(): bool {
        return $this->isDropTable;
    }
}