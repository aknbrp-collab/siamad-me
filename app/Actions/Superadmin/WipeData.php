<?php

namespace App\Actions\Superadmin;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\DataGroups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Delete the chosen groups of data (data-manage D52), with what they need, in one transaction: rows are
 * deleted in foreign-key order, references from rows that stay to deleted accounts are given to the
 * Superadmin who deletes (or go with the account), and the files of deleted rows are removed afterwards.
 * Run by `siakad:wipe` after a backup of the current state.
 */
class WipeData
{
    /**
     * @param  list<string>  $groups
     * @return array<string, int> rows deleted per group (accounts counted with their group)
     */
    public function handle(array $groups, User $actor): array
    {
        $groups = DataGroups::withNeeds($groups);
        $tables = array_values(collect($groups)->flatMap(fn (string $group) => DataGroups::TABLES[$group])->unique()->all());
        $userIds = array_values(DataGroups::users($groups)->all());
        $counts = array_intersect_key(DataGroups::counts(), array_flip($groups));
        $files = $this->files($tables);

        DB::transaction(function () use ($tables, $userIds, $actor) {
            $this->releaseAccounts($userIds, $tables, $actor);

            foreach ($this->deletionOrder($tables) as $table) {
                $this->clearSelfReferences($table);
                DB::table($table)->delete();
            }

            foreach (array_chunk($userIds, 500) as $chunk) {
                DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $chunk)->delete();
                DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                DB::table('users')->whereIn('id', $chunk)->delete();
            }
        });

        // After the commit: a failed transaction leaves every file in place.
        Storage::disk('local')->delete($files);

        // Written last, so it stays even when the log itself was deleted.
        AuditLog::create([
            'user_id' => $actor->id,
            'auditable_type' => 'Data',
            'auditable_id' => 'wipe',
            'event' => 'data.deleted',
            'new_values' => ['groups' => $groups, 'rows' => $counts, 'files' => count($files)],
            'ip_address' => request()->ip(),
        ]);

        return $counts;
    }

    /**
     * Tables in an order where every table comes before the tables it points at.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function deletionOrder(array $tables): array
    {
        $pointsAt = [];
        foreach ($tables as $table) {
            $pointsAt[$table] = collect(Schema::getForeignKeys($table))
                ->pluck('foreign_table')
                ->filter(fn (string $target) => $target !== $table && in_array($target, $tables, true))
                ->unique()->values()->all();
        }

        $order = [];
        $remaining = $pointsAt;

        while ($remaining !== []) {
            // Tables nothing remaining points at can go now.
            $free = array_keys(array_filter($remaining, fn ($unused, string $table) => ! collect($remaining)->contains(fn (array $targets) => in_array($table, $targets, true)), ARRAY_FILTER_USE_BOTH));

            if ($free === []) {
                throw new \RuntimeException('The tables point at each other in a circle: '.implode(', ', array_keys($remaining)));
            }

            foreach ($free as $table) {
                $order[] = $table;
                unset($remaining[$table]);
            }
        }

        return $order;
    }

    /**
     * Rows that point at other rows of the same table (joined classes, replies) let go first.
     */
    private function clearSelfReferences(string $table): void
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['foreign_table'] === $table) {
                DB::table($table)->update([$key['columns'][0] => null]);
            }
        }
    }

    /**
     * References from rows that stay to accounts about to go: given to the Superadmin, or deleted with
     * the account (memberships). Cascading and nulling keys need nothing.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $deletedTables
     */
    private function releaseAccounts(array $userIds, array $deletedTables, User $actor): void
    {
        if ($userIds === []) {
            return;
        }

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, $deletedTables, true) || $table === 'users') {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] !== 'users' || in_array($key['on_delete'], ['cascade', 'set null'], true)) {
                    continue;
                }

                $column = $key['columns'][0];

                foreach (array_chunk($userIds, 500) as $chunk) {
                    in_array("{$table}.{$column}", DataGroups::BELONGS_TO_ACCOUNT, true)
                        ? DB::table($table)->whereIn($column, $chunk)->delete()
                        : DB::table($table)->whereIn($column, $chunk)->update([$column => $actor->id]);
                }
            }
        }
    }

    /**
     * The files of the rows to delete: every *_path column (photos, documents, proofs, scans …).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function files(array $tables): array
    {
        $paths = collect();

        foreach ($tables as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (str_ends_with($column, '_path')) {
                    $paths = $paths->merge(DB::table($table)->whereNotNull($column)->pluck($column));
                }
            }
        }

        return array_values($paths->filter()->map(fn ($path): string => (string) $path)->unique()->all());
    }
}
