<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class RagDatabaseService
{
    public const CONNECTION = 'pgsql_rag';

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   host?: string,
     *   port?: string|int,
     *   database?: string,
     *   username?: string,
     *   version?: string|null,
     *   pgvector?: bool,
     *   tables?: list<string>,
     *   schema_ready?: bool
     * }
     */
    public function connectionStatus(): array
    {
        $cfg = config('database.connections.'.self::CONNECTION, []);
        $base = [
            'host' => (string) ($cfg['host'] ?? ''),
            'port' => $cfg['port'] ?? '',
            'database' => (string) ($cfg['database'] ?? ''),
            'username' => (string) ($cfg['username'] ?? ''),
        ];

        try {
            $pdo = DB::connection(self::CONNECTION)->getPdo();
            $version = null;
            try {
                $version = (string) DB::connection(self::CONNECTION)->selectOne('SELECT version() AS v')->v;
            } catch (Throwable) {
            }

            $pgvector = false;
            try {
                $row = DB::connection(self::CONNECTION)->selectOne(
                    "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector') AS ok"
                );
                $pgvector = (bool) ($row->ok ?? false);
            } catch (Throwable) {
            }

            $tables = $this->listTables();

            return array_merge($base, [
                'ok' => true,
                'message' => 'Connected',
                'version' => $version,
                'pgvector' => $pgvector,
                'tables' => $tables,
                'schema_ready' => in_array('rag_documents', $tables, true)
                    && in_array('rag_chunks', $tables, true),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'ok' => false,
                'message' => $e->getMessage(),
                'version' => null,
                'pgvector' => false,
                'tables' => [],
                'schema_ready' => false,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT tablename
             FROM pg_catalog.pg_tables
             WHERE schemaname = 'public'
             ORDER BY tablename"
        );

        return array_values(array_map(fn ($r) => (string) $r->tablename, $rows));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tableColumns(string $table): array
    {
        $table = $this->assertSafeIdent($table);
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT
                c.ordinal_position AS pos,
                c.column_name AS name,
                c.data_type AS data_type,
                c.udt_name AS udt,
                c.is_nullable AS nullable,
                c.column_default AS default_value,
                c.character_maximum_length AS max_length
             FROM information_schema.columns c
             WHERE c.table_schema = 'public' AND c.table_name = ?
             ORDER BY c.ordinal_position",
            [$table]
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * @return array{ok: bool, message: string, row_count?: int, columns?: list<string>, rows?: list<array<string, mixed>>, affected?: int|null}
     */
    public function runSql(string $sql, int $limit = 200): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            return ['ok' => false, 'message' => 'Empty query'];
        }

        if (preg_match('/;\s*\S/s', $sql)) {
            return ['ok' => false, 'message' => 'Only one SQL statement at a time (no multiple statements)'];
        }

        $sql = rtrim($sql, "; \t\n\r");
        $blocked = '/\b(drop\s+database|drop\s+role|drop\s+user|alter\s+system|copy\s+|pg_read_file|pg_write_file|lo_import|lo_export)\b/i';
        if (preg_match($blocked, $sql)) {
            return ['ok' => false, 'message' => 'This statement is not allowed from the admin console'];
        }

        $isSelect = (bool) preg_match('/^\s*(with|select|show|explain|values)\b/i', $sql);

        try {
            if ($isSelect) {
                $wrapped = 'SELECT * FROM ('.$sql.') AS _rag_q LIMIT '.(int) max(1, min(1000, $limit));
                try {
                    $rows = DB::connection(self::CONNECTION)->select($wrapped);
                } catch (Throwable) {
                    // EXPLAIN / SHOW may not wrap — run raw
                    $rows = DB::connection(self::CONNECTION)->select($sql);
                    if (count($rows) > $limit) {
                        $rows = array_slice($rows, 0, $limit);
                    }
                }

                $columns = [];
                $assoc = [];
                foreach ($rows as $row) {
                    $arr = (array) $row;
                    if ($columns === []) {
                        $columns = array_keys($arr);
                    }
                    $assoc[] = $this->stringifyRow($arr);
                }

                return [
                    'ok' => true,
                    'message' => count($assoc).' row(s)',
                    'row_count' => count($assoc),
                    'columns' => $columns,
                    'rows' => $assoc,
                ];
            }

            $affected = DB::connection(self::CONNECTION)->affectingStatement($sql);

            return [
                'ok' => true,
                'message' => 'OK',
                'affected' => $affected,
                'row_count' => 0,
                'columns' => [],
                'rows' => [],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create RAG tables (Hebrew chunks). embedding as JSONB until pgvector is installed.
     */
    public function migrateSchema(): array
    {
        try {
            DB::connection(self::CONNECTION)->statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        } catch (Throwable) {
            // optional
        }

        $pgvector = false;
        try {
            DB::connection(self::CONNECTION)->statement('CREATE EXTENSION IF NOT EXISTS vector');
            $pgvector = true;
        } catch (Throwable) {
            $pgvector = false;
        }

        $schema = DB::connection(self::CONNECTION)->getSchemaBuilder();

        if (! $schema->hasTable('rag_documents')) {
            $schema->create('rag_documents', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id')->unique();
                $table->string('model', 64)->nullable()->index();
                $table->string('source_file', 512)->nullable();
                $table->string('language', 8)->default('he');
                $table->text('title')->nullable();
                $table->unsignedInteger('chunk_count')->default(0);
                $table->timestampTz('indexed_at')->nullable();
                $table->timestampsTz();
            });
        }

        if (! $schema->hasTable('rag_chunks')) {
            $schema->create('rag_chunks', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('document_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('model', 64)->nullable()->index();
                $table->integer('chunk_index');
                $table->integer('block_index')->nullable();
                $table->string('block_type', 32)->nullable();
                $table->text('content_he');
                $table->unsignedInteger('content_tokens')->nullable();
                $table->jsonb('embedding')->nullable();
                $table->string('embedding_model', 64)->nullable();
                $table->unsignedInteger('embedding_dim')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestampsTz();
                $table->unique(['document_id', 'chunk_index']);
            });

            DB::connection(self::CONNECTION)->statement(
                'ALTER TABLE rag_chunks
                 ADD CONSTRAINT rag_chunks_document_id_fkey
                 FOREIGN KEY (document_id) REFERENCES rag_documents(id) ON DELETE CASCADE'
            );
        }

        if (! $schema->hasTable('rag_meta')) {
            $schema->create('rag_meta', function ($table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestampsTz();
            });
        }

        DB::connection(self::CONNECTION)->table('rag_meta')->updateOrInsert(
            ['key' => 'schema_version'],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::connection(self::CONNECTION)->table('rag_meta')->updateOrInsert(
            ['key' => 'pgvector'],
            ['value' => $pgvector ? '1' : '0', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::connection(self::CONNECTION)->table('rag_meta')->updateOrInsert(
            ['key' => 'language'],
            ['value' => 'he', 'updated_at' => now(), 'created_at' => now()]
        );

        return [
            'ok' => true,
            'pgvector' => $pgvector,
            'message' => $pgvector
                ? 'RAG schema ready (pgvector available)'
                : 'RAG schema ready (embedding stored as JSONB — install pgvector later for ANN search)',
            'tables' => $this->listTables(),
        ];
    }

    /**
     * Preview rows for a table.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int}
     */
    public function previewTable(string $table, int $limit = 50, int $offset = 0): array
    {
        $table = $this->assertSafeIdent($table);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $total = (int) DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.$this->quoteIdent($table)
        )->c;

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT * FROM '.$this->quoteIdent($table).' ORDER BY 1 LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        $columns = [];
        $assoc = [];
        foreach ($rows as $row) {
            $arr = (array) $row;
            if ($columns === []) {
                $columns = array_keys($arr);
            }
            $assoc[] = $this->stringifyRow($arr);
        }

        if ($columns === []) {
            $columns = array_column($this->tableColumns($table), 'name');
        }

        return [
            'columns' => $columns,
            'rows' => $assoc,
            'total' => $total,
        ];
    }

    private function assertSafeIdent(string $name): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        return $name;
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function stringifyRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if ($v === null) {
                $out[$k] = 'NULL';
            } elseif (is_bool($v)) {
                $out[$k] = $v ? 'true' : 'false';
            } elseif (is_array($v) || is_object($v)) {
                $out[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $s = (string) $v;
                if (mb_strlen($s) > 500) {
                    $s = mb_substr($s, 0, 500).'…';
                }
                $out[$k] = $s;
            }
        }

        return $out;
    }
}
