<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InspectDatabase extends Command
{
    protected $signature = 'db:inspect
        {tablesArg?* : Positional table names (optional)}
        {--tables=* : Limit to specific tables}
        {--sample=5 : Number of sample rows to show per table}
        {--json : Output JSON instead of tables}
    ';

    protected $description = 'Inspect database tables, columns, and sample rows (SQLite/MySQL/PostgreSQL supported)';

    public function handle()
    {
        try {
            $driver = DB::getDriverName();
            $tablesOpt = array_filter(array_map('strval', (array) $this->option('tables')));
            $tablesArg = array_filter(array_map('strval', (array) $this->argument('tablesArg') ?? []));
            $sample = (int) $this->option('sample');
            $asJson = (bool) $this->option('json');

            $this->info("DB driver: {$driver}");

            $tables = $this->listTables($driver);
            // Merge filters from positional args and --tables option
            $filters = array_values(array_unique(array_merge($tablesOpt, $tablesArg)));
            $tables = array_values(array_filter($tables, function ($t) use ($filters) {
                if (empty($filters)) return true;
                return in_array($t, $filters, true);
            }));

            if ($asJson) {
                $out = [];
            } else {
                $this->info("\nTables (filtered):");
                foreach ($tables as $t) { $this->line("- {$t}"); }
                $this->line('');
            }

            foreach ($tables as $table) {
                // Columns
                $columns = $this->listColumns($driver, $table);

                // Sample rows
                $rows = [];
                try {
                    $rows = DB::table($table)->limit($sample)->get()->map(function ($r) {
                        return (array) $r;
                    })->toArray();
                } catch (\Throwable $e) {
                    // Table may be empty or have permissions issues
                    $rows = [ ['error' => $e->getMessage()] ];
                }

                if ($asJson) {
                    $out[$table] = [
                        'columns' => $columns,
                        'sample' => $rows,
                    ];
                } else {
                    $this->info("=== {$table} ===");
                    $this->table(
                        ['Name', 'Type', 'Nullable', 'Default', 'Primary Key'],
                        $columns
                    );
                    $this->line('Sample rows:');
                    if (empty($rows)) {
                        $this->warn('[no rows]');
                    } else {
                        // Render a simple table for samples
                        $headers = array_keys((array) ($rows[0] ?? []));
                        $this->table($headers, array_map(function ($r) use ($headers) {
                            return array_map(fn($h) => $r[$h] ?? null, $headers);
                        }, $rows));
                    }
                    $this->line('');
                }
            }

            // Extra: show migrations
            if (Schema::hasTable('migrations')) {
                if ($asJson) {
                    $out['migrations'] = DB::table('migrations')->get()->toArray();
                } else {
                    $this->info("Applied migrations:");
                    $migrations = DB::table('migrations')->get();
                    $this->table(['Migration', 'Batch'], $migrations->map(function ($m) {
                        return ['Migration' => $m->migration, 'Batch' => $m->batch];
                    })->toArray());
                }
            }

            if ($asJson) {
                $this->line(json_encode($out, JSON_PRETTY_PRINT));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function listTables(string $driver): array
    {
        switch ($driver) {
            case 'sqlite':
                $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                return array_map(fn($r) => $r->name, $rows);
            case 'mysql':
                $rows = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name");
                return array_map(fn($r) => $r->name, $rows);
            case 'pgsql':
                $rows = DB::select("SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
                return array_map(fn($r) => $r->name, $rows);
            default:
                // Fallback to Schema facade when possible
                return Schema::getConnection()->getDoctrineSchemaManager()
                    ? Schema::getAllTables()
                    : [];
        }
    }

    private function listColumns(string $driver, string $table): array
    {
        switch ($driver) {
            case 'sqlite':
                $cols = DB::select("PRAGMA table_info({$table})");
                return array_map(function ($c) {
                    return [
                        'Name' => $c->name,
                        'Type' => $c->type,
                        'Nullable' => $c->notnull ? 'NO' : 'YES',
                        'Default' => $c->dflt_value ?? 'NULL',
                        'Primary Key' => $c->pk ? 'YES' : 'NO',
                    ];
                }, $cols);
            case 'mysql':
                $cols = DB::select("SELECT COLUMN_NAME as name, COLUMN_TYPE as type, IS_NULLABLE as nullable, COLUMN_DEFAULT as dflt, COLUMN_KEY as colkey FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION", [$table]);
                return array_map(function ($c) {
                    return [
                        'Name' => $c->name,
                        'Type' => $c->type,
                        'Nullable' => $c->nullable,
                        'Default' => $c->dflt ?? 'NULL',
                        'Primary Key' => ($c->colkey === 'PRI') ? 'YES' : 'NO',
                    ];
                }, $cols);
            case 'pgsql':
                $cols = DB::select("SELECT column_name as name, data_type as type, is_nullable as nullable, column_default as dflt FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position", [$table]);
                // Primary key info
                $pkCols = DB::select("SELECT a.attname AS col FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = ?::regclass AND i.indisprimary", [$table]);
                $pkSet = array_flip(array_map(fn($r) => $r->col, $pkCols));
                return array_map(function ($c) use ($pkSet) {
                    return [
                        'Name' => $c->name,
                        'Type' => $c->type,
                        'Nullable' => strtoupper($c->nullable) === 'YES' ? 'YES' : 'NO',
                        'Default' => $c->dflt ?? 'NULL',
                        'Primary Key' => array_key_exists($c->name, $pkSet) ? 'YES' : 'NO',
                    ];
                }, $cols);
            default:
                // Best-effort fallback
                $cols = Schema::getColumnListing($table);
                return array_map(fn($c) => [
                    'Name' => $c,
                    'Type' => 'unknown',
                    'Nullable' => 'unknown',
                    'Default' => 'unknown',
                    'Primary Key' => 'unknown',
                ], $cols);
        }
    }
}
