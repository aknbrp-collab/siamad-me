<?php

use App\Actions\People\SaveGuardian;
use App\Livewire\PersonForm;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Wali santri')] class extends PersonForm {
    public ?Guardian $guardian = null;

    public string $name = '';
    public ?string $email = null;
    public ?string $relationship = null;
    public ?string $phone = null;

    /** @var list<int> */
    public array $student_ids = [];

    public string $studentSearch = '';

    #[Url(as: 'student')]
    public ?int $presetStudent = null;

    public function mount(?Guardian $guardian = null): void
    {
        if ($guardian?->exists) {
            $this->guardian = $guardian->load(['user', 'students']);
            $this->name = $guardian->user->name;
            $this->email = $guardian->user->email;
            $this->relationship = $guardian->relationship;
            $this->phone = $guardian->phone;
            $this->student_ids = $guardian->students->pluck('id')->all();
        } elseif ($this->presetStudent && Student::query()->whereKey($this->presetStudent)->exists()) {
            $this->student_ids = [$this->presetStudent];
        }
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function linkedStudents(): Collection
    {
        return Student::query()->with('user')->whereKey($this->student_ids)->orderBy('nim')->get();
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (mb_strlen(trim($this->studentSearch)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($this->studentSearch)).'%';

        return Student::query()
            ->with('user')
            ->whereKeyNot($this->student_ids)
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))
            ->orderBy('nim')
            ->limit(10)
            ->get();
    }

    public function addStudent(int $id): void
    {
        $this->student_ids = array_values(array_unique([...$this->student_ids, $id]));
        $this->studentSearch = '';
    }

    public function removeStudent(int $id): void
    {
        $this->student_ids = array_values(array_diff($this->student_ids, [$id]));
    }

    protected function accountUser(): ?User
    {
        return $this->guardian?->user;
    }

    protected function editRoute(): ?string
    {
        return $this->guardian ? route('admin.guardians.edit', $this->guardian) : null;
    }

    public function save(SaveGuardian $saveGuardian): void
    {
        $this->resetErrorBag();

        $account = $saveGuardian->handle($this->guardian, [
            'name' => trim($this->name),
            'email' => $this->email ?: null,
            'relationship' => $this->relationship ?: null,
            'phone' => $this->phone ?: null,
            'student_ids' => $this->student_ids,
        ]);

        if ($account !== null) {
            $this->guardian = $account->model->load(['user', 'students']);
            $this->justCreated = true;
            $this->showCredentials($account);

            return;
        }

        $this->guardian->refresh()->load(['user', 'students']);
        Flux::toast(variant: 'success', text: __('Wali santri saved.'));
    }

}; ?>

<section class="w-full">
    <x-page-header :title="$guardian ? $guardian->user->name : __('Add wali santri')" :subtitle="$guardian ? __('Code :code', ['code' => $guardian->code]) : __('A login code (WS-…) and a temporary password are generated.')">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.guardians.index')" wire:navigate>{{ __('Wali santri') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <form wire:submit="save" class="space-y-8">
            <flux:fieldset>
                <flux:legend>{{ __('Guardian') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Full name')" required />
                    <flux:input wire:model="relationship" :label="__('Relationship')" placeholder="Ayah / Ibu / Wali" />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <flux:input wire:model="email" :label="__('Email')" type="email" :description:trailing="__('Optional.')" />
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Mahasantri') }}</flux:legend>
                <flux:text class="mb-3 text-sm">{{ __('The guardian sees only the mahasantri linked here.') }}</flux:text>

                <div class="mb-3 flex flex-wrap gap-2">
                    @forelse ($this->linkedStudents as $student)
                        <flux:badge wire:key="linked-{{ $student->id }}" size="lg">
                            {{ $student->nim }} · {{ $student->user->name }}
                            <flux:badge.close wire:click="removeStudent({{ $student->id }})" />
                        </flux:badge>
                    @empty
                        <flux:text class="text-sm">{{ __('None linked yet.') }}</flux:text>
                    @endforelse
                </div>

                <flux:input wire:model.live.debounce.300ms="studentSearch" icon="magnifying-glass" :placeholder="__('Find mahasantri by NIM or name…')" />
                @if ($this->candidates->isNotEmpty())
                    <div class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($this->candidates as $candidate)
                            <button type="button" wire:key="candidate-{{ $candidate->id }}" wire:click="addStudent({{ $candidate->id }})"
                                class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <flux:icon.plus class="size-4 text-zinc-400" />
                                <span class="font-mono text-zinc-500">{{ $candidate->nim }}</span>
                                <span class="font-medium">{{ $candidate->user->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <flux:error name="student_ids" />
            </flux:fieldset>

            <flux:button type="submit" variant="primary" data-test="save-guardian">{{ $guardian ? __('Save') : __('Create wali santri') }}</flux:button>
        </form>

        @if ($guardian)
            <div>
                <x-account-panel :user="$guardian->user" />
            </div>
        @endif
    </div>

    @include('partials.credentials-modal')
</section>
