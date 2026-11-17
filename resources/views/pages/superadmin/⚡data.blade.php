<?php

use App\Console\Commands\WipeCommand;
use App\Support\BackgroundCommand;
use App\Support\Backups;
use App\Support\DataGroups;
use App\Support\Restores;
use App\Support\Upload;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Data & backups')] class extends Component {
    use WithFileUploads;

    /** A backup .zip to take in (D51). */
    public mixed $archive = null;

    /** The backup chosen to restore, and the confirmation. */
    public ?string $restoreName = null;
    public string $password = '';
    public string $phrase = '';

    /** The status link of the restore started from this page. */
    public ?string $restoreToken = null;

    /**
     * Groups ticked to delete (D52), and the confirmation.
     *
     * @var list<string>
     */
    public array $wipeGroups = [];
    public string $wipePassword = '';
    public string $wipePhrase = '';

    /**
     * Every backup, newest first (D50).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function backups(): Collection
    {
        return Backups::all();
    }

    /**
     * @return array{started_at: string, by: string|null}|null
     */
    #[Computed]
    public function running(): ?array
    {
        return Backups::running();
    }

    /**
     * @return array{ok: bool, message: string|null, finished_at: string}|null
     */
    #[Computed]
    public function last(): ?array
    {
        return Backups::last();
    }

    public function makeBackup(BackgroundCommand $background): void
    {
        if (Backups::running() !== null) {
            Flux::toast(variant: 'warning', text: __('A backup is being made already.'));

            return;
        }

        Backups::markRunning(auth()->user());
        Backups::audit('backup.started', now()->format('Y-m-d_His'));
        // In its own process: the page answers at once and shows the run until siakad:backup records its end.
        $background->start('siakad:backup', ['--by='.auth()->id()]);

        unset($this->running);
        Flux::toast(text: __('The backup is being made. This page shows it when it is ready.'));
    }

    public function deleteBackup(string $name): void
    {
        Backups::delete($name);
        Backups::audit('backup.deleted', $name);

        unset($this->backups);
        Flux::toast(variant: 'success', text: __('Backup deleted.'));
    }

    /**
     * The chosen backup and what stops it from being restored (D51).
     *
     * @return array{backup: array<string, mixed>, problems: list<string>}|null
     */
    #[Computed]
    public function restoreCheck(): ?array
    {
        if ($this->restoreName === null) {
            return null;
        }

        $backup = Backups::find($this->restoreName);
        $problems = Restores::problems($backup);

        if (! Restores::toolsReady()) {
            $problems[] = __('pg_restore cannot be run on this server (set BACKUP_PG_RESTORE).');
        }

        return ['backup' => $backup, 'problems' => $problems];
    }

    public function uploadArchive(): void
    {
        $this->resetErrorBag();
        $this->validate(['archive' => ['required', 'file', 'max:'.(Restores::maxMb() * 1024)]], [], ['archive' => __('backup file')]);

        $backup = Restores::import($this->archive, auth()->user());

        $this->reset('archive');
        unset($this->backups);
        Flux::toast(variant: 'success', text: __('Backup :name added. It can now be restored.', ['name' => $backup['name']]));
    }

    public function chooseRestore(string $name): void
    {
        Backups::find($name);
        $this->restoreName = $name;
        $this->reset('password', 'phrase');
        $this->resetErrorBag();
        unset($this->restoreCheck);
        Flux::modal('restore-form')->show();
    }

    public function startRestore(BackgroundCommand $background): void
    {
        $this->resetErrorBag();
        $check = $this->restoreCheck;
        abort_if($check === null, 404);

        if ($check['problems'] !== []) {
            throw ValidationException::withMessages(['restore' => $check['problems']]);
        }

        if (Backups::running() !== null || Restores::running()) {
            throw ValidationException::withMessages(['restore' => __('A backup or restore is running. Wait until it has finished.')]);
        }

        if (! Hash::check($this->password, (string) auth()->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The password is not right.')]);
        }

        if (trim($this->phrase) !== Restores::PHRASE) {
            throw ValidationException::withMessages(['phrase' => __('Type :phrase to confirm.', ['phrase' => Restores::PHRASE])]);
        }

        $this->restoreToken = Restores::begin((string) $this->restoreName, auth()->user()->name);
        Backups::audit('backup.restore_started', (string) $this->restoreName);
        // Its own process: SIAKAD goes down meanwhile, and this page follows it through the status link.
        $background->start('siakad:restore', [(string) $this->restoreName, '--by='.auth()->id()]);

        $this->reset('password', 'phrase');
        Flux::modal('restore-form')->close();
    }

    /**
     * Rows each group holds now.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function groupCounts(): array
    {
        return DataGroups::counts();
    }

    /**
     * The ticked groups with everything they need.
     *
     * @return list<string>
     */
    #[Computed]
    public function wipeSelection(): array
    {
        return DataGroups::withNeeds($this->wipeGroups);
    }

    /**
     * The last deletion (running, done or failed).
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function wipeState(): ?array
    {
        return WipeCommand::state();
    }

    public function confirmWipe(): void
    {
        $this->resetErrorBag();

        if ($this->wipeSelection === []) {
            throw ValidationException::withMessages(['wipeGroups' => __('Tick at least one group.')]);
        }

        $this->reset('wipePassword', 'wipePhrase');
        Flux::modal('wipe-form')->show();
    }

    public function startWipe(BackgroundCommand $background): void
    {
        $this->resetErrorBag();

        if ($this->wipeSelection === []) {
            throw ValidationException::withMessages(['wipe' => __('Tick at least one group.')]);
        }

        if (Backups::running() !== null || Restores::running() || WipeCommand::running()) {
            throw ValidationException::withMessages(['wipe' => __('A backup, restore or deletion is running. Wait until it has finished.')]);
        }

        if (! Hash::check($this->wipePassword, (string) auth()->user()->password)) {
            throw ValidationException::withMessages(['wipePassword' => __('The password is not right.')]);
        }

        if (trim($this->wipePhrase) !== 'HAPUS DATA') {
            throw ValidationException::withMessages(['wipePhrase' => __('Type :phrase to confirm.', ['phrase' => 'HAPUS DATA'])]);
        }

        // Shown at once; siakad:wipe carries it on (backup first, then the deletion).
        WipeCommand::write(['status' => 'running', 'step' => 'starting', 'groups' => $this->wipeSelection, 'by' => auth()->user()->name,
            'started_at' => now()->toIso8601String(), 'message' => null, 'counts' => null, 'safety_backup' => null]);
        $background->start('siakad:wipe', [...$this->wipeSelection, '--by='.auth()->id()]);

        $this->reset('wipePassword', 'wipePhrase', 'wipeGroups');
        unset($this->wipeState);
        Flux::modal('wipe-form')->close();
    }

    /**
     * Polled while a backup or deletion runs.
     */
    public function refreshState(): void
    {
        unset($this->backups, $this->running, $this->last, $this->wipeState, $this->groupCounts);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Data & backups')" :subtitle="__('Backups of the whole SIAKAD (database and uploaded files) to download, made every day and whenever you need one. Only the Superadmin sees this page.')" />

    <div @if ($this->running || ($this->wipeState['status'] ?? null) === 'running') wire:poll.3s="refreshState" @endif class="space-y-6">
        @if ($this->running)
            <flux:callout icon="arrow-path" color="sky" data-test="backup-running"
                :text="__('A backup is being made (started :time by :name). It can take a few minutes; this page updates by itself.', ['time' => \Illuminate\Support\Carbon::parse($this->running['started_at'])->diffForHumans(), 'name' => $this->running['by'] ?? '—'])" />
        @elseif ($this->last && ! $this->last['ok'])
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="backup-failed"
                :heading="__('The last backup failed')" :text="$this->last['message']" />
        @endif

        @if ($restoreToken)
            {{-- SIAKAD is down while it restores: the browser asks the status link, not Livewire. --}}
            <flux:card class="space-y-3" data-test="restore-progress"
                x-data="{ status: 'running', step: 'starting', message: null, safety: null,
                    async poll() {
                        try {
                            const response = await fetch(@js(route('restore.status', $restoreToken)), { cache: 'no-store' });
                            if (response.ok) { const s = await response.json(); this.status = s.status; this.step = s.step; this.message = s.message; this.safety = s.safety_backup; }
                        } catch (e) {}
                        if (this.status === 'running') { setTimeout(() => this.poll(), 2000); }
                    } }"
                x-init="poll()">
                <flux:heading size="lg">{{ __('Restoring :name', ['name' => $restoreName]) }}</flux:heading>
                <template x-if="status === 'running'">
                    <div class="flex items-center gap-2 text-sm">
                        <flux:icon.arrow-path class="size-4 animate-spin" />
                        <span x-text="{
                            starting: @js(__('Starting…')),
                            safety_backup: @js(__('Making a backup of the current state…')),
                            maintenance: @js(__('Taking SIAKAD offline…')),
                            database: @js(__('Restoring the database…')),
                            files: @js(__('Putting the files back…')),
                            migrate: @js(__('Bringing the database up to date…')),
                        }[step] ?? step"></span>
                    </div>
                </template>
                <template x-if="status === 'done'">
                    <div class="space-y-2" data-test="restore-done">
                        <flux:text>{{ __('Restored. SIAKAD is back online; everyone signs in again, you too.') }}</flux:text>
                        <flux:button variant="primary" :href="route('login')">{{ __('Sign in') }}</flux:button>
                    </div>
                </template>
                <template x-if="status === 'failed'">
                    <div class="space-y-1 text-sm text-red-600 dark:text-red-400" data-test="restore-failed">
                        <p class="font-medium">{{ __('The restore failed. SIAKAD is back online.') }}</p>
                        <p x-text="message"></p>
                        <p x-show="safety" x-text="@js(__('The state before the restore is in backup')) + ' ' + safety + '.'"></p>
                    </div>
                </template>
            </flux:card>
        @endif

        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-xl">
                    <flux:heading size="lg">{{ __('Backups') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">
                        {{ __('Every day at :time SIAKAD makes a backup and keeps it :days days. Backups you make here stay until you delete them. Each download is one .zip with the database, the uploaded files and a description; keep important ones somewhere other than this server.', ['time' => config('siakad.backup.time'), 'days' => config('siakad.backup.retention_days')]) }}
                    </flux:text>
                </div>
                <flux:button variant="primary" icon="circle-stack" wire:click="makeBackup" :disabled="(bool) $this->running" data-test="make-backup">{{ __('Make a backup now') }}</flux:button>
            </div>

            <div class="overflow-x-auto">
                <flux:table class="table-stack">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Made') }}</flux:table.column>
                        <flux:table.column>{{ __('By') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Records') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Size') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->backups as $backup)
                            <flux:table.row :key="$backup['name']" data-test="backup-{{ $backup['name'] }}">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $backup['created_at']->translatedFormat('j M Y, H:i') }}</div>
                                    <div class="font-mono text-xs text-zinc-500">{{ $backup['name'] }}</div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($backup['kind'] === Backups::MANUAL)
                                        <flux:badge size="sm" color="sky">{{ __('By hand') }}</flux:badge>
                                        <span class="text-sm">{{ $backup['made_by'] }}</span>
                                    @else
                                        <flux:badge size="sm">{{ __('Daily') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums text-sm">
                                    {{ $backup['manifest'] ? Number::format(array_sum($backup['manifest']['tables'] ?? [])) : '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums text-sm">{{ Number::fileSize($backup['size'], precision: 1) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-1">
                                        <flux:button size="sm" icon="arrow-down-tray" :href="route('superadmin.data.download', $backup['name'])" data-test="download-{{ $backup['name'] }}">{{ __('Download') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="chooseRestore('{{ $backup['name'] }}')" data-test="restore-{{ $backup['name'] }}">{{ __('Restore') }}</flux:button>
                                        @if ($backup['kind'] === Backups::MANUAL)
                                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteBackup('{{ $backup['name'] }}')"
                                                wire:confirm="{{ __('Delete this backup? It cannot be brought back.') }}" :aria-label="__('Delete')" data-test="delete-{{ $backup['name'] }}" />
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center">{{ __('No backups yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Restore from a file') }}</flux:heading>
            @php($phpLimit = min(array_filter([Upload::bytes((string) ini_get('upload_max_filesize')), Upload::bytes((string) ini_get('post_max_size'))]) ?: [PHP_INT_MAX]))
            <flux:text class="text-sm">
                {{ __('Upload a backup .zip downloaded from SIAKAD (here or on another server); it joins the list above, where you restore it. Up to :size MB.', ['size' => min(Restores::maxMb(), intdiv($phpLimit, 1048576))]) }}
                {{ __('A larger backup: copy its folder into :path on the server; it then appears in the list.', ['path' => Backups::root()]) }}
            </flux:text>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <input type="file" wire:model="archive" accept=".zip" data-max-kb="{{ Restores::maxMb() * 1024 }}" data-test="archive"
                    class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:button size="sm" icon="arrow-up-tray" wire:click="uploadArchive" wire:loading.attr="disabled" wire:target="archive,uploadArchive" data-test="upload-archive">{{ __('Upload') }}</flux:button>
            </div>
            <flux:error name="archive" />
        </flux:card>

        {{-- Deleting data by groups (D52). --}}
        <flux:card class="space-y-4 border-red-200 dark:border-red-500/30" data-test="wipe-card">
            <div>
                <flux:heading size="lg">{{ __('Delete data') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Tick what to delete. Groups that other data depends on are ticked with it. Superadmin accounts, the institution identity, appearance, settings and the backups always stay. A backup of the current state is made first.') }}
                </flux:text>
            </div>

            @php($state = $this->wipeState)
            @if (($state['status'] ?? null) === 'running')
                <flux:callout icon="arrow-path" color="sky" data-test="wipe-running"
                    :text="($state['step'] ?? '') === 'deleting' ? __('Deleting…') : __('Making a backup of the current state…')" />
            @elseif (($state['status'] ?? null) === 'done' && now()->subDay()->lt($state['finished_at'] ?? '2000-01-01'))
                <flux:callout icon="check-circle" color="green" data-test="wipe-done"
                    :text="__('Deleted :rows records (:groups). The state before is in backup :backup.', ['rows' => Number::format(array_sum($state['counts'] ?? [])), 'groups' => collect($state['groups'] ?? [])->map(fn ($group) => DataGroups::labels()[$group] ?? $group)->join(', '), 'backup' => $state['safety_backup'] ?? '—'])" />
            @elseif (($state['status'] ?? null) === 'failed')
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="wipe-failed" :heading="__('Nothing was deleted')" :text="$state['message']" />
            @endif

            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach (DataGroups::labels() as $group => $label)
                    @php($needed = in_array($group, $this->wipeSelection, true) && ! in_array($group, $wipeGroups, true))
                    <label wire:key="group-{{ $group }}" class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        {{-- A needed group is ticked by the others, not by its own box. --}}
                        @if ($needed)
                            <input type="checkbox" checked disabled wire:key="box-{{ $group }}-needed" class="mt-1 size-4 rounded accent-red-600" data-test="group-{{ $group }}">
                        @else
                            <input type="checkbox" wire:model.live="wipeGroups" value="{{ $group }}" wire:key="box-{{ $group }}-own" class="mt-1 size-4 rounded border-zinc-300 accent-red-600" data-test="group-{{ $group }}">
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="font-medium">{{ $label }}</span>
                            <span class="ms-1 text-sm tabular-nums text-zinc-500">{{ trans_choice(':count record|:count records', $this->groupCounts[$group] ?? 0, ['count' => Number::format($this->groupCounts[$group] ?? 0)]) }}</span>
                            @if ($needed)
                                <flux:badge size="sm" color="red" class="ms-1">{{ __('needed') }}</flux:badge>
                            @endif
                            <span class="block text-xs text-zinc-500">{{ DataGroups::descriptions()[$group] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <flux:error name="wipeGroups" />

            <div class="flex justify-end">
                <flux:button variant="danger" icon="trash" wire:click="confirmWipe" :disabled="$this->wipeSelection === [] || ($state['status'] ?? null) === 'running'" data-test="confirm-wipe">{{ __('Delete the ticked data…') }}</flux:button>
            </div>
        </flux:card>
    </div>

    <flux:modal name="wipe-form" class="w-full max-w-lg">
        <form wire:submit="startWipe" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Delete this data?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A backup of the current state is made first; then these records are deleted for good:') }}</flux:text>
            </div>
            <ul class="space-y-1 text-sm" data-test="wipe-summary">
                @foreach ($this->wipeSelection as $group)
                    <li class="flex justify-between gap-3"><span>{{ DataGroups::labels()[$group] }}</span><span class="tabular-nums">{{ Number::format($this->groupCounts[$group] ?? 0) }}</span></li>
                @endforeach
                <li class="flex justify-between gap-3 border-t border-zinc-200 pt-1 font-semibold dark:border-zinc-700">
                    <span>{{ __('Total') }}</span><span class="tabular-nums">{{ Number::format(collect($this->wipeSelection)->sum(fn ($group) => $this->groupCounts[$group] ?? 0)) }}</span>
                </li>
            </ul>
            <flux:input type="password" wire:model="wipePassword" :label="__('Your password')" autocomplete="current-password" required />
            <flux:input wire:model="wipePhrase" :label="__('Type :phrase to confirm', ['phrase' => 'HAPUS DATA'])" autocomplete="off" required data-test="wipe-phrase" />
            <flux:error name="wipe" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="trash" data-test="start-wipe">{{ __('Delete') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="restore-form" class="w-full max-w-lg">
        @if ($this->restoreCheck)
            @php($chosen = $this->restoreCheck['backup'])
            <form wire:submit="startRestore" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Restore this backup?') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Everything in SIAKAD is replaced by the backup of :date: all data, accounts and uploaded files. A backup of the current state is made first. SIAKAD is offline for a few minutes, and everyone signs in again afterwards.', ['date' => $chosen['created_at']->translatedFormat('j F Y, H:i')]) }}
                    </flux:text>
                </div>

                @if ($this->restoreCheck['problems'] !== [])
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('This backup cannot be restored')" data-test="restore-problems">
                        <flux:callout.text>
                            <ul class="list-disc ps-5">
                                @foreach ($this->restoreCheck['problems'] as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout icon="check-circle" color="green" data-test="restore-ok"
                        :text="__('Checked: complete and undamaged, :records records, from this SIAKAD or an older one.', ['records' => Number::format(array_sum($chosen['manifest']['tables'] ?? []))])" />
                    <flux:input type="password" wire:model="password" :label="__('Your password')" autocomplete="current-password" required />
                    <flux:input wire:model="phrase" :label="__('Type :phrase to confirm', ['phrase' => Restores::PHRASE])" autocomplete="off" required data-test="restore-phrase" />
                @endif
                <flux:error name="restore" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    @if ($this->restoreCheck['problems'] === [])
                        <flux:button type="submit" variant="danger" icon="arrow-uturn-left" data-test="start-restore">{{ __('Restore') }}</flux:button>
                    @endif
                </div>
            </form>
        @endif
    </flux:modal>
</section>
