<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Puts a pg_dump (custom format) back into the database (data-manage D51). Tables the dump does not have
 * (made by newer migrations) are dropped first, so the migrations run afterwards start from the backup's
 * state; then pg_restore replaces everything else in one transaction (all or nothing). Tests swap this
 * class for a fake in the container.
 */
class DatabaseRestore
{
    public function restore(string $dump): void
    {
        $name = (string) config('siakad.backup.connection');
        $connection = config("database.connections.{$name}");

        if (($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Restoring needs PostgreSQL (the current connection is '.($connection['driver'] ?? 'unknown').').');
        }

        $inDump = $this->tablesIn($dump, $connection);
        $schema = (string) ($connection['search_path'] ?? 'public');
        $current = DB::connection($name)->table('pg_tables')->where('schemaname', $schema)->pluck('tablename');

        foreach ($current->diff($inDump) as $table) {
            DB::connection($name)->statement('drop table if exists "'.str_replace('"', '""', $table).'" cascade');
        }

        DB::connection($name)->disconnect();

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->run([
                Restores::pgRestore(),
                '--clean', '--if-exists', '--no-owner', '--single-transaction', '--exit-on-error',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$connection['database'],
                $dump,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_restore failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * The tables a dump holds (from its table of contents).
     *
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function tablesIn(string $dump, array $connection): array
    {
        $result = Process::timeout(120)->run([Restores::pgRestore(), '--list', $dump]);

        if ($result->failed()) {
            throw new RuntimeException('pg_restore cannot read the dump: '.trim($result->errorOutput() ?: $result->output()));
        }

        // Lines like "215; 1259 16390 TABLE public users siakad".
        preg_match_all('/^\d+;\s+\d+\s+\d+\s+TABLE\s+\S+\s+(\S+)\s/m', $result->output(), $matches);

        return array_values(array_unique($matches[1]));
    }
}
