<?php

use App\Actions\Evaluations\ManageEvaluations;
use App\Enums\Role;
use App\Models\EvaluationPeriod;
use App\Models\EvaluationQuestion;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Teaching evaluation')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    /** results | setup */
    #[Url(except: 'results')]
    public string $tab = 'results';

    /** The class kuliah whose result is open. */
    #[Url]
    public ?int $open = null;

    public string $opensOn = '';

    public string $closesOn = '';

    public string $commentPrompt = '';

    public bool $requiredForKhs = false;

    public ?int $editingId = null;

    public string $group = '';

    public string $question = '';

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');

        if (! $this->canManage) {
            $this->tab = 'results';
        }

        $this->fillPeriod();
    }

    public function updatedSemesterId(): void
    {
        $this->open = null;
        unset($this->period, $this->questions, $this->results);
        $this->cancelQuestion();
        $this->fillPeriod();
    }

    private function fillPeriod(): void
    {
        $period = $this->period;
        $this->opensOn = $period?->opens_on->format('Y-m-d') ?? '';
        $this->closesOn = $period?->closes_on->format('Y-m-d') ?? '';
        $this->commentPrompt = $period->comment_prompt ?? ManageEvaluations::DEFAULT_PROMPT;
        $this->requiredForKhs = $period->required_for_khs ?? false;
    }

    /**
     * Admin and Superadmin set up the evaluation; the academic monitor only reads results.
     */
    #[Computed]
    public function canManage(): bool
    {
        return in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId);
    }

    #[Computed]
    public function period(): ?EvaluationPeriod
    {
        return EvaluationPeriod::query()->where('semester_id', $this->semesterId)->first();
    }

    /**
     * @return Collection<int, EvaluationQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return EvaluationQuestion::query()->where('semester_id', $this->semesterId)->withExists('scores')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function results(): \Illuminate\Support\Collection
    {
        return $this->semester === null ? collect() : app(ManageEvaluations::class)->results($this->semester);
    }

    /**
     * Each muhadhir's classes, answers and average over the classes that show results.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, classes: int, responses: int, average: float|null}>
     */
    #[Computed]
    public function teachers(): \Illuminate\Support\Collection
    {
        return $this->results
            ->groupBy(fn (array $result) => $result['offering']->teacher_id)
            ->map(function ($results) {
                $averages = $results->pluck('average')->filter(fn ($average) => $average !== null);

                return [
                    'name' => (string) $results->first()['offering']->teacher->user->name,
                    'classes' => $results->count(),
                    'responses' => (int) $results->sum('responses'),
                    'average' => $averages->isEmpty() ? null : round((float) $averages->avg(), 2),
                ];
            })
            ->sortBy('name')->values();
    }

    private function ensureManager(): void
    {
        abort_unless($this->canManage, 403);
    }

    public function savePeriod(ManageEvaluations $evaluations): void
    {
        $this->ensureManager();
        abort_if($this->semester === null, 404);

        $evaluations->savePeriod($this->semester, [
            'opens_on' => $this->opensOn,
            'closes_on' => $this->closesOn,
            'comment_prompt' => $this->commentPrompt,
            'required_for_khs' => $this->requiredForKhs,
        ]);

        unset($this->period);
        Flux::toast(variant: 'success', text: __('Evaluation period saved.'));
    }

    public function prepare(ManageEvaluations $evaluations): void
    {
        $this->ensureManager();
        abort_if($this->semester === null, 404);

        $count = $evaluations->prepareQuestions($this->semester);

        unset($this->questions);
        Flux::toast(variant: 'success', text: trans_choice(':count statement added.|:count statements added.', $count));
    }

    public function editQuestion(int $id): void
    {
        $this->ensureManager();
        $question = $this->questions->firstWhere('id', $id);
        abort_if($question === null, 404);

        $this->editingId = $question->id;
        $this->group = $question->group;
        $this->question = $question->question;
    }

    public function cancelQuestion(): void
    {
        $this->reset('editingId', 'group', 'question');
        $this->resetErrorBag();
    }

    public function saveQuestion(ManageEvaluations $evaluations): void
    {
        $this->ensureManager();
        abort_if($this->semester === null, 404);

        if ($this->editingId !== null) {
            $question = $this->questions->firstWhere('id', $this->editingId);
            abort_if($question === null, 404);
            $evaluations->updateQuestion($question, $this->group, $this->question);
        } else {
            $evaluations->addQuestion($this->semester, $this->group, $this->question);
        }

        $group = $this->group;
        $this->cancelQuestion();
        // Keep the group for the next statement in it.
        $this->group = $group;
        unset($this->questions);
    }

    public function toggle(int $id, ManageEvaluations $evaluations): void
    {
        $this->ensureManager();
        $question = $this->questions->firstWhere('id', $id);
        abort_if($question === null, 404);

        $evaluations->setActive($question, ! $question->is_active);
        unset($this->questions);
    }

    public function move(int $id, int $direction, ManageEvaluations $evaluations): void
    {
        $this->ensureManager();
        $question = $this->questions->firstWhere('id', $id);
        abort_if($question === null, 404);

        $evaluations->move($question, $direction < 0 ? -1 : 1);
        unset($this->questions);
    }

    public function export(): BinaryFileResponse
    {
        abort_if($this->semester === null, 404);

        $rows = $this->results->map(function (array $result) {
            $row = [
                __('Subject') => $result['offering']->subject_name,
                __('Class') => $result['offering']->class_name,
                __('Muhadhir') => $result['offering']->teacher->user->name,
                __('Answered') => $result['responses'],
                __('Mahasantri') => $result['enrolled'],
                __('Average') => $result['average'],
            ];

            foreach ($result['questions'] as $index => $question) {
                $row[($index + 1).'. '.$question['question']->question] = $question['average'];
            }

            $row[__('Comments')] = implode("\n", $result['comments']);

            return $row;
        });

        return SpreadsheetDownload::make('evaluasi-muhadhir-'.$this->semesterId.'.xlsx', $rows);
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('Teaching evaluation')" :subtitle="__('Mahasantri rate each class kuliah at the end of the semester, anonymously. Results show for classes with at least :min answers.', ['min' => ManageEvaluations::MIN_RESPONSES])">
        <x-slot:actions>
            <flux:select wire:model.live="semesterId" class="w-60">
                @foreach ($this->semesters as $semester)
                    <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($tab === 'results')
                <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->canManage)
        <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-4 w-full sm:w-auto">
            <flux:radio value="results" icon="chart-bar" :label="__('Results')" />
            <flux:radio value="setup" icon="adjustments-horizontal" :label="__('Questions & period')" />
        </flux:radio.group>
    @endif

    <flux:text class="mb-4" data-test="period-status">
        @if ($this->period)
            {{ __('Period') }}: {{ $this->period->rangeLabel() }} ·
            @if ($this->period->isOpen())
                <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
            @elseif ($this->period->hasClosed())
                <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
            @else
                <flux:badge size="sm" color="amber">{{ __('Not open yet') }}</flux:badge>
            @endif
        @else
            {{ __('No evaluation period is set for this semester.') }}
        @endif
    </flux:text>

    @if ($tab === 'setup' && $this->canManage)
        <form wire:submit="savePeriod" class="mb-8 space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="period-form">
            <flux:heading>{{ __('Period') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="opensOn" :label="__('Opens on')" />
                <flux:input type="date" wire:model="closesOn" :label="__('Closes on')" />
            </div>
            <flux:input wire:model="commentPrompt" :label="__('Closing open question')" maxlength="255" />
            <flux:checkbox wire:model="requiredForKhs" :label="__('Required for the KHS')"
                :description="__('While the period is open, mahasantri see their KHS of this semester only after evaluating all their classes.')" />
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>

        <div class="mb-3 flex items-center justify-between gap-2">
            <flux:heading>{{ __('Statements (score 1–5)') }}</flux:heading>
            @if ($this->questions->isEmpty())
                <flux:button size="sm" icon="document-duplicate" wire:click="prepare" data-test="prepare-questions">{{ __('Copy from the previous semester') }}</flux:button>
            @endif
        </div>

        @error('questions') <flux:text class="mb-2 text-red-600">{{ $message }}</flux:text> @enderror
        @error('question') <flux:text class="mb-2 text-red-600">{{ $message }}</flux:text> @enderror

        <div class="mb-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->questions as $item)
                <div wire:key="question-{{ $item->id }}" @class(['flex items-start gap-3 px-4 py-2', 'opacity-50' => ! $item->is_active])>
                    <span class="w-6 shrink-0 pt-0.5 text-xs text-zinc-500 tabular-nums">{{ $loop->iteration }}.</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-zinc-500">{{ $item->group }}</span>
                        <span class="block text-sm">{{ $item->question }}</span>
                    </span>
                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="move({{ $item->id }}, -1)" :aria-label="__('Move up')" />
                        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="move({{ $item->id }}, 1)" :aria-label="__('Move down')" />
                        @unless ($item->scores_exists)
                            <flux:button size="xs" variant="ghost" icon="pencil" wire:click="editQuestion({{ $item->id }})" :aria-label="__('Edit')" />
                        @endunless
                        <flux:button size="xs" variant="ghost" wire:click="toggle({{ $item->id }})">{{ $item->is_active ? __('Turn off') : __('Turn on') }}</flux:button>
                    </div>
                </div>
            @empty
                <flux:text class="p-4">{{ __('No statements yet. Copy them from the previous semester, or add them below.') }}</flux:text>
            @endforelse
        </div>

        <form wire:submit="saveQuestion" class="grid gap-3 rounded-lg border border-dashed border-zinc-300 p-4 sm:grid-cols-[14rem_1fr_auto] sm:items-end dark:border-zinc-600" data-test="question-form">
            <flux:input wire:model="group" :label="__('Group')" :placeholder="__('e.g. Penguasaan materi')" maxlength="100" />
            <flux:input wire:model="question" :label="$editingId ? __('Change statement') : __('New statement')" maxlength="500" />
            <div class="flex gap-2">
                @if ($editingId)
                    <flux:button type="button" wire:click="cancelQuestion">{{ __('Cancel') }}</flux:button>
                @endif
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save') : __('Add') }}</flux:button>
            </div>
        </form>
    @else
        @if ($this->teachers->isNotEmpty())
            <flux:heading class="mb-2">{{ __('Per muhadhir') }}</flux:heading>
            <div class="mb-6 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('Muhadhir') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Classes') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Answered') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Average') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->teachers as $teacher)
                            <tr>
                                <td class="px-3 py-1.5">{{ $teacher['name'] }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ $teacher['classes'] }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ $teacher['responses'] }}</td>
                                <td class="px-3 py-1.5 text-end font-medium tabular-nums">{{ $teacher['average'] !== null ? number_format($teacher['average'], 2, ',', '.') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <flux:heading class="mb-2">{{ __('Per class kuliah') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->results as $result)
                @php($offering = $result['offering'])
                <div wire:key="result-{{ $offering->id }}">
                    <button type="button" wire:click="$set('open', {{ $open === $offering->id ? 'null' : $offering->id }})" data-test="offering-{{ $offering->id }}"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ $offering->subject_name }} · {{ $offering->class_name }}</span>
                            <span class="block text-xs text-zinc-500">{{ $offering->teacher->user->name }}</span>
                        </span>
                        <span class="text-xs text-zinc-500 tabular-nums">{{ $result['responses'] }}/{{ $result['enrolled'] }}</span>
                        <span class="w-12 text-end text-sm font-medium tabular-nums">{{ $result['average'] !== null ? number_format($result['average'], 2, ',', '.') : '—' }}</span>
                        <flux:icon :icon="$open === $offering->id ? 'chevron-up' : 'chevron-down'" variant="micro" class="text-zinc-400" />
                    </button>
                    @if ($open === $offering->id)
                        <x-evaluation-result :result="$result" class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700" />
                    @endif
                </div>
            @empty
                <flux:text class="p-4">{{ __('No classes kuliah in this semester.') }}</flux:text>
            @endforelse
        </div>
    @endif
</section>
