<?php

use App\Models\GradeScale;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Grade scale')] class extends Component {
    /** @var list<array{letter: string, min_score: string|int|float|null, point: string|int|float|null}> */
    public array $bands = [];

    public function mount(): void
    {
        $this->bands = GradeScale::ordered()
            ->map(fn (GradeScale $band) => ['letter' => $band->letter, 'min_score' => $band->min_score, 'point' => $band->point])
            ->all();
    }

    public function addBand(): void
    {
        $this->bands[] = ['letter' => '', 'min_score' => null, 'point' => null];
    }

    public function removeBand(int $index): void
    {
        unset($this->bands[$index]);
        $this->bands = array_values($this->bands);
    }

    public function save(): void
    {
        $this->validate([
            'bands' => ['required', 'array', 'min:2'],
            'bands.*.letter' => ['required', 'string', 'max:3', 'distinct:ignore_case'],
            'bands.*.min_score' => ['required', 'numeric', 'between:0,100', 'distinct'],
            'bands.*.point' => ['required', 'numeric', 'between:0,4'],
        ], [], ['bands.*.letter' => __('letter'), 'bands.*.min_score' => __('minimum score'), 'bands.*.point' => __('point')]);

        if (collect($this->bands)->min(fn (array $band) => (float) $band['min_score']) > 0) {
            throw ValidationException::withMessages(['bands' => __('One band must start at 0 so every score gets a grade.')]);
        }

        DB::transaction(function () {
            $keep = [];

            foreach ($this->bands as $band) {
                $model = GradeScale::query()->updateOrCreate(
                    ['letter' => trim($band['letter'])],
                    ['min_score' => $band['min_score'], 'point' => $band['point']],
                );
                $keep[] = $model->id;
            }

            GradeScale::query()->whereKeyNot($keep)->get()->each->delete();
        });

        $this->mount();
        Flux::toast(variant: 'success', text: __('Grade scale saved. Grades finalised earlier keep their letter and point.'));
    }
}; ?>

<section class="w-full max-w-2xl">
    <x-page-header :title="__('Grade scale')" :subtitle="__('How final scores (0–100) become letters and grade points. Used when grades are finalised.')" />

    <form wire:submit="save" class="space-y-4">
        <div class="space-y-2">
            <div class="grid grid-cols-[5rem_1fr_1fr_2.5rem] gap-2 px-1 text-sm font-medium text-zinc-500">
                <span>{{ __('Letter') }}</span><span>{{ __('From score') }}</span><span>{{ __('Point') }}</span><span></span>
            </div>
            @foreach ($bands as $index => $band)
                <div wire:key="band-{{ $index }}" class="grid grid-cols-[5rem_1fr_1fr_2.5rem] items-start gap-2">
                    <flux:input wire:model="bands.{{ $index }}.letter" size="sm" />
                    <flux:input wire:model="bands.{{ $index }}.min_score" size="sm" type="number" step="0.01" min="0" max="100" />
                    <flux:input wire:model="bands.{{ $index }}.point" size="sm" type="number" step="0.01" min="0" max="4" />
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBand({{ $index }})" :aria-label="__('Remove')" />
                </div>
            @endforeach
        </div>

        @error('bands')
            <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
        @enderror
        @if ($errors->has('bands.*'))
            <flux:callout variant="danger" icon="exclamation-triangle" :text="$errors->first('bands.*')" />
        @endif

        <div class="flex gap-2">
            <flux:button size="sm" icon="plus" wire:click="addBand">{{ __('Add band') }}</flux:button>
            <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
