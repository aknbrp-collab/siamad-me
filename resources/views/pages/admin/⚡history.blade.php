<?php

use App\Enums\SemesterStatus;
use App\Imports\History\GradeHistory;
use App\Imports\History\HistoryImporter;
use App\Imports\History\PlacementHistory;
use App\Imports\Importers;
use App\Models\Semester;
use App\Support\MaintenanceMode;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Previous-year data')] class extends Component {
    use WithFileUploads;

    private const TYPES = ['placements' => PlacementHistory::class, 'grades' => GradeHistory::class];

    private const MAX_ROWS = 2000;

    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    #[Url]
    public string $type = 'placements';

    public $file = null;

    public ?int $validCount = null;

    /** @var list<array{row: int, id: string, messages: list<string>}> */
    public array $rowErrors = [];

    public ?int $importedCount = null;

    public function mount(): void
    {
        if (! isset(self::TYPES[$this->type])) {
            $this->type = 'placements';
        }

        $this->semesterId ??= $this->semesters->first()?->id;
    }

    /**
     * Closed semesters, newest first: previous-year data goes there.
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->where('status', SemesterStatus::Closed)
            ->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId);
    }

    #[Computed]
    public function importer(): HistoryImporter
    {
        return app(self::TYPES[$this->type]);
    }

    #[Computed]
    public function open(): bool
    {
        return MaintenanceMode::allowsEditing();
    }

    public function updatedType(): void
    {
        $this->resetResults();
        $this->reset('file');
    }

    public function updatedSemesterId(): void
    {
        $this->resetResults();
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

    public function template(): BinaryFileResponse
    {
        $importer = $this->importer;
        $header = fn (string $column) => $column.(str_starts_with($importer->columns()[$column], __('Required')) ? '*' : '');

        return SpreadsheetDownload::make("template-{$this->type}.xlsx", [
            array_combine(array_map($header, array_keys($importer->columns())), array_values($importer->example())),
        ]);
    }

    /**
     * Read the upload and check every row against the semester. When
     * $import is set, each valid row is imported right after its check, so
     * a later row sees what an earlier one created.
     *
     * @return array{valid: int, imported: int, errors: list<array{row: int, id: string, messages: list<string>}>}
     */
    private function run(bool $import): array
    {
        abort_unless($this->open, 403);
        $semester = $this->semester;
        abort_if($semester === null, 404);

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']]);

        $extension = strtolower($this->file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $rows = Importers::read($this->file->getRealPath(), $extension);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no data rows.')]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('Import at most :max rows at a time.', ['max' => self::MAX_ROWS])]);
        }

        $importer = $this->importer;
        $seen = [];
        $valid = 0;
        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $key = $importer->key($row);
            $checked = $importer->check($semester, $row);
            $messages = $checked['errors'];

            if ($key !== '' && isset($seen[$key])) {
                $messages[] = __('Duplicate of row :row in this file.', ['row' => $seen[$key]]);
            }
            $seen[$key] ??= $line;

            if ($messages !== []) {
                $errors[] = ['row' => $line, 'id' => trim(($row['nim'] ?? '').' '.($row['subject_code'] ?? '')), 'messages' => $messages];

                continue;
            }

            $valid++;

            if ($import) {
                $importer->import($semester, $checked['input'], auth()->user());
                $imported++;
            }
        }

        return ['valid' => $valid, 'imported' => $imported, 'errors' => $errors];
    }

    public function check(): void
    {
        $this->resetResults();
        $result = $this->run(false);
        $this->validCount = $result['valid'];
        $this->rowErrors = $result['errors'];
    }

    public function import(): void
    {
        $this->resetResults();
        $result = $this->run(true);
        $this->importedCount = $result['imported'];
        $this->rowErrors = $result['errors'];
        $this->reset('file');
        unset($this->semesters);

        Flux::toast(variant: $result['imported'] > 0 ? 'success' : 'warning', text: trans_choice(':count row imported.|:count rows imported.', $result['imported']));
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Previous-year data')" :subtitle="__('Class placements and final grades of closed semesters, from your offline records, so KHS, transcripts and IPK are complete.')" />

    @unless ($this->open)
        <flux:callout icon="lock-closed" color="amber" class="mb-6" data-test="history-locked"
            :heading="__('Maintenance mode is off')"
            :text="__('Closed semesters are read-only. Ask the Superadmin to turn maintenance mode on (with the reason) while you enter previous-year data.')" />
    @endunless

    @if ($this->semesters->isEmpty())
        <flux:text>{{ __('There is no closed semester yet. While maintenance mode is on, add the previous academic year on the Academic years page: its semesters are created closed.') }}</flux:text>
    @else
        <div class="space-y-6">
            <div class="flex flex-wrap items-end gap-4">
                <flux:select wire:model.live="semesterId" :label="__('Semester')" class="sm:max-w-60">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:radio.group wire:model.live="type" variant="segmented">
                    <flux:radio value="placements" :label="__('Class placements')" />
                    <flux:radio value="grades" :label="__('Final grades')" />
                </flux:radio.group>
            </div>

            <flux:text class="text-sm">
                {{ $type === 'placements'
                    ? __('Import the class placements first: grades are matched to the class each mahasantri was in.')
                    : __('Each grade is stored as final. Rows for a subject and class without a class offering create it, with the muhadhir given in teacher_nidm.') }}
            </flux:text>

            <flux:card class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading>{{ __('1. Fill in the template') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="template">{{ __('Download template') }}</flux:button>
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
                <input type="file" wire:model="file" accept=".xlsx,.csv" @disabled(! $this->open)
                    class="block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:error name="file" />
                <div wire:loading wire:target="file" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>
                <flux:button wire:click="check" :disabled="! $file || ! $this->open" icon="clipboard-document-check">{{ __('Check file') }}</flux:button>
            </flux:card>

            @if ($validCount !== null)
                <flux:card class="space-y-4">
                    <flux:heading>{{ __('3. Import') }}</flux:heading>
                    <flux:text>
                        {{ trans_choice(':count row is ready to import.|:count rows are ready to import.', $validCount) }}
                        @if ($rowErrors !== [])
                            {{ trans_choice(':count row has errors and will be skipped.|:count rows have errors and will be skipped.', count($rowErrors)) }}
                        @endif
                    </flux:text>
                    <flux:button variant="primary" wire:click="import" :disabled="$validCount === 0" icon="arrow-up-tray" data-test="run-history-import">
                        {{ trans_choice('Import :count row|Import :count rows', $validCount) }}
                    </flux:button>
                </flux:card>
            @endif

            @if ($importedCount !== null)
                <flux:callout variant="success" icon="check-circle" :text="trans_choice(':count row imported.|:count rows imported.', $importedCount)" />
            @endif

            @if ($rowErrors !== [])
                <div>
                    <flux:heading class="mb-2">{{ __('Rows with errors') }}</flux:heading>
                    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($rowErrors as $error)
                            <div wire:key="row-error-{{ $error['row'] }}" class="flex gap-4 px-3 py-2 text-sm">
                                <span class="w-20 shrink-0 text-zinc-500">{{ __('Row :row', ['row' => $error['row']]) }}</span>
                                <span class="w-36 shrink-0 font-mono">{{ $error['id'] ?: '—' }}</span>
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
    @endif
</section>
