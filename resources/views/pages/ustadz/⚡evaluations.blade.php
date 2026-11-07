<?php

use App\Actions\Evaluations\ManageEvaluations;
use App\Models\EvaluationPeriod;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('My evaluation')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public function mount(): void
    {
        abort_if(auth()->user()->teacher === null, 403);
        $this->semesterId ??= $this->semesters->first()?->id;
    }

    /**
     * Semesters whose evaluation period has closed: results are shown only then.
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        $closed = EvaluationPeriod::query()->whereDate('closes_on', '<', today())->pluck('semester_id');

        return Semester::query()->with('academicYear')->whereKey($closed)->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function results(): \Illuminate\Support\Collection
    {
        $semester = $this->semesters->firstWhere('id', $this->semesterId);

        return $semester === null ? collect() : app(ManageEvaluations::class)->results($semester, auth()->user()->teacher);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('My evaluation')" :subtitle="__('What your mahasantri said about your classes kuliah, after the evaluation period closes. Answers are anonymous, and a class shows results only with at least :min answers.', ['min' => ManageEvaluations::MIN_RESPONSES])">
        @if ($this->semesters->isNotEmpty())
            <x-slot:actions>
                <flux:select wire:model.live="semesterId" class="w-60">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-slot:actions>
        @endif
    </x-page-header>

    @forelse ($this->results as $result)
        <div wire:key="result-{{ $result['offering']->id }}" class="mb-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ $result['offering']->subject_name }} · {{ $result['offering']->class_name }}</flux:heading>
            <x-evaluation-result :result="$result" class="mt-2" />
        </div>
    @empty
        <flux:callout icon="information-circle" :text="$this->semesters->isEmpty() ? __('No evaluation has closed yet.') : __('You taught no class kuliah in this semester.')" />
    @endforelse
</section>
