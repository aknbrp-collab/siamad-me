<?php

namespace App\Console\Commands;

use App\Actions\Superadmin\WipeData;
use App\Enums\Role;
use App\Models\User;
use App\Support\Backups;
use App\Support\DataGroups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

#[Signature('siakad:wipe {groups* : Groups to delete: akademik keuangan surat artikel orang dasar staf log} {--by= : The Superadmin who deletes (id)}')]
#[Description('Delete groups of data (with what they need) after a backup of the current state')]
class WipeCommand extends Command
{
    /** The state of a run, next to the backups (the Data page reads it). */
    public const STATE = '.wipe.json';

    public function handle(WipeData $wipe): int
    {
        $actor = User::query()->find((int) $this->option('by'));
        /** @var list<string> $asked */
        $asked = array_values((array) $this->argument('groups'));
        $unknown = array_diff($asked, array_keys(DataGroups::TABLES));

        if ($actor === null || ! $actor->hasRole(Role::Superadmin)) {
            $this->components->error('--by must be a Superadmin.');

            return self::FAILURE;
        }

        if ($asked === [] || $unknown !== []) {
            $this->components->error('Unknown groups: '.implode(', ', $unknown ?: ['none given']));

            return self::FAILURE;
        }

        $groups = DataGroups::withNeeds($asked);
        self::write(['status' => 'running', 'step' => 'safety_backup', 'groups' => $groups, 'by' => $actor->name, 'started_at' => now()->toIso8601String(), 'message' => null, 'counts' => null, 'safety_backup' => null]);

        try {
            if (Artisan::call('siakad:backup', ['--by' => $actor->id]) !== 0) {
                throw new RuntimeException('The backup of the current state failed, so nothing was deleted: '.trim(Artisan::output()));
            }
            $safety = Backups::all()->first()['name'] ?? null;
            self::update(['step' => 'deleting', 'safety_backup' => $safety]);

            $counts = $wipe->handle($groups, $actor);

            self::update(['status' => 'done', 'step' => 'done', 'counts' => $counts, 'finished_at' => now()->toIso8601String()]);
            $this->components->info('Deleted: '.json_encode($counts).". The state before is in backup {$safety}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            self::update(['status' => 'failed', 'message' => $exception->getMessage(), 'finished_at' => now()->toIso8601String()]);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        $path = Backups::root().DIRECTORY_SEPARATOR.self::STATE;
        $data = File::exists($path) ? json_decode(File::get($path), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * A run started less than three hours ago that has not finished.
     */
    public static function running(): bool
    {
        $state = self::state();

        return ($state['status'] ?? null) === 'running' && now()->subHours(3)->lt((string) $state['started_at']);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function write(array $state): void
    {
        File::ensureDirectoryExists(Backups::root());
        File::put(Backups::root().DIRECTORY_SEPARATOR.self::STATE, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function update(array $changes): void
    {
        self::write([...(self::state() ?? []), ...$changes]);
    }
}
