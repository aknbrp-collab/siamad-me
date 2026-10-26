<?php

use App\Actions\Teaching\ImportScores;
use App\Actions\Teaching\ManageGrades;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Models\GradeScale;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Grades')] class extends Component {
    use WithFileUploads;

    public CourseOffering $offering;

    /** @var list<array{id: int|null, name: string, weight: int|string|null}> */
    public array $components = [];

    /** @var array<int|string, array<int|string, string|null>> enrollment id => component id => score */
    public array $scores = [];

    public $file = null;

    /** @var list<array{row: int, nim: string, name: string, status: string, errors: list<string>}>|null */
    public ?array $importPreview = null;

    public function mount(CourseOffering $offering): void
    {
        abort_unless($offering->teacher()->value('user_id') === auth()->id(), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
        $this->loadComponents();
        $this->loadScores();
    }

    private function loadComponents(): void
    {
        $this->components = $this->offering->components()->get()
            ->map(fn (GradeComponent $component) => ['id' => $component->id, 'name' => $component->name, 'weight' => $component->weight])
            ->all();

        if ($this->components === []) {
            $this->components = [
                ['id' => null, 'name' => 'Tugas', 'weight' => 20],
                ['id' => null, 'name' => 'UTS', 'weight' => 30],
                ['id' => null, 'name' => 'UAS', 'weight' => 50],
            ];
        }
    }

    private function loadScores(): void
    {
        $this->scores = [];

        foreach ($this->enrollments as $enrollment) {
            foreach ($this->savedComponents as $component) {
                $score = $enrollment->scores->firstWhere('grade_component_id', $component->id)?->score;
                $this->scores[$enrollment->id][$component->id] = $score === null ? null : rtrim(rtrim($score, '0'), '.');
            }
        }
    }

    #[Computed]
    public function editable(): bool
    {
        return ! $this->offering->semester->isLocked() && ! $this->offering->fresh()->isFinalized();
    }

    /**
     * @return Collection<int, GradeComponent>
     */
    #[Computed]
    public function savedComponents(): Collection
    {
        return $this->offering->components()->get();
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    #[Computed]
    public function enrollments(): Collection
    {
        return $this->offering->enrollments()->with(['student.user', 'scores'])->get()->sortBy('student.user.name')->values();
    }

    #[Computed]
    public function weightTotal(): int
    {
        return (int) array_sum(array_map(fn (array $row) => (int) $row['weight'], $this->components));
    }

    /**
     * Live final score per enrolment from the scores being edited.
     *
     * @return array<int, array{score: float|null, letter: string|null}>
     */
    #[Computed]
    public function preview(): array
    {
        $scale = GradeScale::ordered();
        $grades = app(ManageGrades::class);
        $result = [];

        foreach ($this->enrollments as $enrollment) {
            $total = 0.0;
            $complete = $this->savedComponents->isNotEmpty();

            foreach ($this->savedComponents as $component) {
                $score = $grades->parseScore($this->scores[$enrollment->id][$component->id] ?? '');

                if ($score === null) {
                    $complete = false;
                    break;
                }

                $total += $score * $component->weight / 100;
            }

            $result[$enrollment->id] = $complete
                ? ['score' => round($total, 2), 'letter' => GradeScale::resolve(round($total, 2), $scale)['letter']]
                : ['score' => null, 'letter' => null];
        }

        return $result;
    }

    public function addComponent(): void
    {
        $this->components[] = ['id' => null, 'name' => '', 'weight' => null];
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    public function saveComponents(ManageGrades $grades): void
    {
        $this->resetErrorBag();

        $grades->saveComponents($this->offering, $this->components);

        unset($this->savedComponents, $this->enrollments, $this->preview);
        $this->loadComponents();
        $this->loadScores();
        Flux::toast(variant: 'success', text: __('Grade components saved.'));
    }

    public function saveScores(ManageGrades $grades): void
    {
        $this->resetErrorBag();

        $grades->saveScores($this->offering, $this->scores);

        unset($this->enrollments);
        Flux::toast(variant: 'success', text: __('Scores saved.'));
    }

    public function updatedFile(): void
    {
        $this->importPreview = null;
    }

    public function checkImport(ImportScores $import): void
    {
        $this->resetErrorBag();
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048']]);

        $this->importPreview = array_map(
            fn (array $row) => ['row' => $row['row'], 'nim' => $row['nim'], 'name' => $row['name'], 'status' => $row['status'], 'errors' => $row['errors']],
            $import->preview($this->offering, $this->file->getRealPath(), $this->extension()),
        );
    }

    public function applyImport(ImportScores $import): void
    {
        $this->resetErrorBag();

        $changed = $import->apply($this->offering, $this->file->getRealPath(), $this->extension());

        $this->reset('file', 'importPreview');
        unset($this->enrollments, $this->preview);
        $this->loadScores();
        Flux::modal('import-scores')->close();
        Flux::toast(variant: 'success', text: trans_choice('Scores updated for :count mahasantri.|Scores updated for :count mahasantri.', $changed));
    }

    public function finalize(ManageGrades $grades): void
    {
        $this->resetErrorBag();

        // Save what is on screen first so nothing typed is lost.
        $grades->saveScores($this->offering, $this->scores);
        $grades->finalize($this->offering, auth()->user());

        $this->offering->refresh();
        unset($this->enrollments, $this->editable);
        Flux::toast(variant: 'success', text: __('Grades finalised. They now appear on the KHS.'));
    }

    private function extension(): string
    {
        return strtolower($this->file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="grades" />

    @foreach (['grades', 'semester', 'scores'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    @if ($offering->isFinalized())
        <flux:callout icon="lock-closed" color="green" class="mb-6"
            :heading="__('Grades finalised')"
            :text="__('Finalised on :date. Ask the Admin to unlock them if a correction is needed.', ['date' => $offering->grades_finalized_at->translatedFormat('j M Y H:i')])" />
    @elseif ($offering->grades_note)
        <flux:callout icon="information-circle" class="mb-6" :heading="__('Unlocked for correction')" :text="$offering->grades_note" />
    @endif

    <div class="space-y-8">
        @if ($this->editable)
            <flux:card class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>{{ __('Grade components') }}</flux:heading>
                    <flux:badge :color="$this->weightTotal === 100 ? 'green' : 'red'">{{ __('Total :total%', ['total' => $this->weightTotal]) }}</flux:badge>
                </div>
                <div class="space-y-2">
                    @foreach ($components as $index => $component)
                        <div wire:key="component-{{ $index }}" class="flex items-start gap-2">
                            <flux:input wire:model.live.debounce.300ms="components.{{ $index }}.name" size="sm" :placeholder="__('Name')" class="flex-1" />
                            <flux:input wire:model.live.debounce.300ms="components.{{ $index }}.weight" size="sm" type="number" min="1" max="100" class="w-24" />
                            <span class="pt-1.5 text-sm text-zinc-500">%</span>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeComponent({{ $index }})" :aria-label="__('Remove')" />
                        </div>
                    @endforeach
                </div>
                @if ($errors->has('components.*'))
                    <flux:text class="text-sm text-red-600">{{ $errors->first('components.*') }}</flux:text>
                @endif
                @error('components')
                    <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
                @enderror
                <div class="flex gap-2">
                    <flux:button size="sm" icon="plus" wire:click="addComponent">{{ __('Add component') }}</flux:button>
                    <flux:button size="sm" variant="primary" wire:click="saveComponents" data-test="save-components">{{ __('Save components') }}</flux:button>
                </div>
            </flux:card>
        @endif

        @if ($this->savedComponents->isEmpty())
            <flux:text>{{ __('Save the grade components to start entering scores.') }}</flux:text>
        @else
            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <flux:heading size="lg" class="me-auto">{{ __('Scores') }}</flux:heading>
                    @if ($this->editable)
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('ustadz.offerings.grades.template', $offering)">{{ __('Excel template') }}</flux:button>
                        <flux:modal.trigger name="import-scores">
                            <flux:button size="sm" icon="arrow-up-tray">{{ __('Import') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ __('Mahasantri') }}</th>
                                @foreach ($this->savedComponents as $component)
                                    <th class="px-2 py-2 text-center font-medium">{{ $component->name }} <span class="font-normal text-zinc-500">{{ $component->weight }}%</span></th>
                                @endforeach
                                <th class="px-3 py-2 text-end font-medium">{{ __('Final') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->enrollments as $enrollment)
                                <tr wire:key="row-{{ $enrollment->id }}">
                                    <td class="px-3 py-1.5">
                                        <div class="font-medium">{{ $enrollment->student->user->name }}</div>
                                        <div class="font-mono text-xs text-zinc-500">{{ $enrollment->student->nim }}</div>
                                    </td>
                                    @foreach ($this->savedComponents as $component)
                                        <td class="px-2 py-1.5 text-center">
                                            @if ($this->editable)
                                                <input type="text" inputmode="decimal" wire:model.blur="scores.{{ $enrollment->id }}.{{ $component->id }}"
                                                    class="w-20 rounded-md border border-zinc-300 bg-white px-2 py-1 text-center tabular-nums dark:border-zinc-600 dark:bg-zinc-900"
                                                    aria-label="{{ $component->name }} {{ $enrollment->student->user->name }}">
                                                @error("scores.{$enrollment->id}.{$component->id}")
                                                    <div class="text-xs text-red-600">{{ $message }}</div>
                                                @enderror
                                            @else
                                                <span class="tabular-nums">{{ $scores[$enrollment->id][$component->id] ?? '—' }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-1.5 text-end tabular-nums">
                                        @if ($offering->isFinalized())
                                            {{ $enrollment->final_score }} <flux:badge size="sm">{{ $enrollment->final_letter }}</flux:badge>
                                        @elseif ($this->preview[$enrollment->id]['score'] !== null)
                                            {{ number_format($this->preview[$enrollment->id]['score'], 2) }}
                                            <flux:badge size="sm" color="zinc">{{ $this->preview[$enrollment->id]['letter'] }}</flux:badge>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->editable)
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button variant="primary" wire:click="saveScores" data-test="save-scores">{{ __('Save scores') }}</flux:button>
                        <flux:button icon="lock-closed" wire:click="finalize"
                            wire:confirm="{{ __('Finalise the grades? They will be locked and appear on the KHS.') }}"
                            data-test="finalize-grades">{{ __('Finalise grades') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <flux:modal name="import-scores" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import scores from Excel') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Use the downloaded template. Blank cells leave a score unchanged.') }}</flux:text>
            </div>
            <input type="file" wire:model="file" accept=".xlsx,.csv"
                class="block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
            <flux:error name="file" />

            @if ($importPreview)
                <div class="max-h-72 divide-y divide-zinc-200 overflow-y-auto rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($importPreview as $row)
                        <div wire:key="preview-{{ $row['row'] }}" class="flex items-start gap-3 px-3 py-1.5">
                            <span class="w-12 shrink-0 text-zinc-500">{{ $row['row'] }}</span>
                            <span class="flex-1">{{ $row['name'] ?: $row['nim'] }}
                                @foreach ($row['errors'] as $error)
                                    <span class="block text-xs text-red-600">{{ $error }}</span>
                                @endforeach
                            </span>
                            <flux:badge size="sm" :color="['new' => 'green', 'changed' => 'blue', 'unchanged' => 'zinc', 'error' => 'red'][$row['status']]">{{ __(ucfirst($row['status'])) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                @if ($importPreview === null)
                    <flux:button wire:click="checkImport" :disabled="! $file">{{ __('Check file') }}</flux:button>
                @else
                    <flux:button variant="primary" wire:click="applyImport"
                        :disabled="collect($importPreview)->whereIn('status', ['new', 'changed'])->isEmpty()">
                        {{ __('Apply :count changes', ['count' => collect($importPreview)->whereIn('status', ['new', 'changed'])->count()]) }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</section>
