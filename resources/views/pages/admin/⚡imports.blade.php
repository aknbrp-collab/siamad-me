<?php

use App\Imports\Importer;
use App\Imports\Importers;
use App\Support\CreatedAccount;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Import')] class extends Component {
    use WithFileUploads;

    private const MAX_ROWS = 1000;

    #[Url]
    public string $type = 'students';

    public $file = null;

    /** Result of checking the file: null until checked. */
    public ?int $validCount = null;

    /** @var list<array{row: int, id: string, messages: list<string>}> */
    public array $rowErrors = [];

    public ?int $importedCount = null;

    public function mount(): void
    {
        if (! isset(Importers::TYPES[$this->type])) {
            $this->type = 'students';
        }
    }

    #[Computed]
    public function importer(): Importer
    {
        return Importers::make($this->type);
    }

    public function updatedType(): void
    {
        $this->resetResults();
        $this->reset('file');
    }

    public function updatedFile(): void
    {
        $this->resetResults();
    }

    private function resetResults(): void
    {
        $this->reset('validCount', 'rowErrors', 'importedCount');
        $this->resetErrorBag();
    }

    /**
     * Read the upload and validate every row with the same rules as the form.
     *
     * @return list<array{row: int, id: string, input: array<string, mixed>, errors: list<string>}>
     */
    private function checkRows(): array
    {
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']]);

        $extension = strtolower($this->file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $rows = Importers::read($this->file->getRealPath(), $extension);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no data rows.')]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('Import at most :max rows at a time.', ['max' => self::MAX_ROWS])]);
        }

        $key = $this->importer->keyColumn();
        $seen = [];
        $checked = [];

        foreach ($rows as $index => $row) {
            $input = $this->importer->toInput($row);
            $id = (string) ($input[$key] ?? '');
            $errors = [];

            try {
                $this->importer->validate($input);
            } catch (ValidationException $e) {
                $errors = array_values(array_unique(array_merge(...array_values($e->errors()))));
            }

            $normalized = mb_strtolower($id);
            if ($normalized !== '' && isset($seen[$normalized])) {
                $errors[] = __('Duplicate of row :row in this file.', ['row' => $seen[$normalized]]);
            }
            $seen[$normalized] ??= $index + 2;

            // +2: spreadsheet rows are 1-based and row 1 is the header.
            $checked[] = ['row' => $index + 2, 'id' => $id, 'input' => $input, 'errors' => $errors];
        }

        return $checked;
    }

    public function check(): void
    {
        $this->resetResults();

        $checked = $this->checkRows();

        $this->validCount = count(array_filter($checked, fn ($row) => $row['errors'] === []));
        $this->rowErrors = array_values(array_map(
            fn ($row) => ['row' => $row['row'], 'id' => $row['id'], 'messages' => $row['errors']],
            array_filter($checked, fn ($row) => $row['errors'] !== []),
        ));
    }

    /**
     * Create the valid rows and, for accounts, hand back the credentials
     * sheet. Rows with errors are skipped; fix them and import the file again.
     */
    public function import(): ?BinaryFileResponse
    {
        $accounts = [];
        $created = 0;
        $failed = [];

        foreach ($this->checkRows() as $row) {
            if ($row['errors'] !== []) {
                continue;
            }

            try {
                $account = $this->importer->create($row['input']);
                $created++;

                if ($account !== null) {
                    $accounts[] = $account;
                }
            } catch (ValidationException $e) {
                // Changed since the check (e.g. created in the meantime).
                $failed[] = ['row' => $row['row'], 'id' => $row['id'], 'messages' => array_merge(...array_values($e->errors()))];
            }
        }

        $this->importedCount = $created;
        $this->validCount = null;
        $this->rowErrors = $failed;
        $this->reset('file');

        if ($created === 0) {
            Flux::toast(variant: 'warning', text: __('Nothing was imported.'));

            return null;
        }

        if (! $this->importer->createsAccounts()) {
            Flux::toast(variant: 'success', text: trans_choice(':count row imported.|:count rows imported.', $created));

            return null;
        }

        Flux::toast(variant: 'success', text: trans_choice(':count account created. The credentials sheet is downloading.|:count accounts created. The credentials sheet is downloading.', count($accounts)));

        return SpreadsheetDownload::credentials("credentials-{$this->type}-".now()->format('Ymd-His').'.xlsx', $accounts);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Import')" :subtitle="__('Create many mahasantri or ustadz accounts, or subjects, from an Excel or CSV file.')" />

    <div class="space-y-8">
        <div class="space-y-4">
            <flux:radio.group wire:model.live="type" variant="segmented">
                @foreach (Importers::TYPES as $key => $class)
                    <flux:radio :value="$key" :label="Importers::make($key)->label()" />
                @endforeach
            </flux:radio.group>

            <flux:card class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading>{{ __('1. Fill in the template') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('admin.imports.template', $type)">{{ __('Download template') }}</flux:button>
                </div>
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[minmax(10rem,auto)_1fr]">
                    @foreach ($this->importer->columns() as $column => $description)
                        <dt class="font-mono text-zinc-700 dark:text-zinc-300">{{ $column }}</dt>
                        <dd class="text-zinc-500 dark:text-zinc-400">{{ $description }}</dd>
                    @endforeach
                </dl>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading>{{ __('2. Upload and check') }}</flux:heading>
                <input type="file" wire:model="file" accept=".xlsx,.csv"
                    class="block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:error name="file" />
                <div wire:loading wire:target="file" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>
                <flux:button wire:click="check" :disabled="! $file" icon="clipboard-document-check">{{ __('Check file') }}</flux:button>
            </flux:card>
        </div>

        @if ($validCount !== null)
            <flux:card class="space-y-4">
                <flux:heading>{{ __('3. Import') }}</flux:heading>
                <flux:text>
                    {{ trans_choice(':count row is ready to import.|:count rows are ready to import.', $validCount) }}
                    @if ($rowErrors !== [])
                        {{ trans_choice(':count row has errors and will be skipped.|:count rows have errors and will be skipped.', count($rowErrors)) }}
                    @endif
                </flux:text>
                @if ($this->importer->createsAccounts())
                    <flux:callout icon="key" :text="__('Importing downloads a credentials sheet with each new login ID and temporary password. It is not stored anywhere: keep it safe and hand it out.')" />
                @endif
                <flux:button variant="primary" wire:click="import" :disabled="$validCount === 0" icon="arrow-up-tray" data-test="run-import">
                    {{ trans_choice('Import :count row|Import :count rows', $validCount) }}
                </flux:button>
            </flux:card>
        @endif

        @if ($importedCount !== null)
            <flux:callout variant="success" icon="check-circle"
                :text="$this->importer->createsAccounts()
                    ? trans_choice(':count account was created.|:count accounts were created.', $importedCount)
                    : trans_choice(':count row imported.|:count rows imported.', $importedCount)" />
        @endif

        @if ($rowErrors !== [])
            <div>
                <flux:heading class="mb-2">{{ __('Rows with errors') }}</flux:heading>
                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($rowErrors as $error)
                        <div wire:key="row-error-{{ $error['row'] }}" class="flex gap-4 px-3 py-2 text-sm">
                            <span class="w-20 shrink-0 text-zinc-500">{{ __('Row :row', ['row' => $error['row']]) }}</span>
                            <span class="w-32 shrink-0 font-mono">{{ $error['id'] ?: '—' }}</span>
                            <ul class="list-inside list-disc text-red-600 dark:text-red-400">
                                @foreach ($error['messages'] as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
