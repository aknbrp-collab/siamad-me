<?php

use App\Actions\People\CreateStudent;
use App\Actions\People\UpdateStudent;
use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Livewire\PersonForm;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

new #[Title('Mahasantri')] class extends PersonForm {
    use WithFileUploads;

    public ?Student $student = null;

    // Field names match the action's validation keys so errors show inline.
    public string $nim = '';
    public string $name = '';
    public ?string $email = null;
    public ?int $study_program_id = null;
    public ?int $entry_year = null;
    public ?string $gender = null;
    public ?string $birth_place = null;
    public ?string $birth_date = null;
    public ?string $address = null;
    public ?string $phone = null;
    public ?string $father_name = null;
    public string $father_is_alive = '';
    public ?string $mother_name = null;
    public string $mother_is_alive = '';
    public ?string $guardian_name = null;
    public string $status = 'active';
    public ?string $graduated_at = null;
    public $photo = null;

    public function mount(?Student $student = null): void
    {
        $this->entry_year = now()->year;
        $this->study_program_id = StudyProgram::query()->orderBy('code')->value('id');

        if ($student?->exists) {
            $this->student = $student->load(['user', 'documents', 'guardians.user']);
            $this->fill([
                ...$student->only(['nim', 'entry_year', 'study_program_id', 'birth_place', 'address', 'phone', 'father_name', 'mother_name', 'guardian_name']),
                'name' => $student->user->name,
                'email' => $student->user->email,
                'gender' => $student->gender->value,
                'birth_date' => $student->birth_date?->format('Y-m-d'),
                'father_is_alive' => $this->fromBool($student->father_is_alive),
                'mother_is_alive' => $this->fromBool($student->mother_is_alive),
                'status' => $student->status->value,
                'graduated_at' => $student->graduated_at?->format('Y-m-d'),
            ]);
        }
    }

    /**
     * @return Collection<int, StudyProgram>
     */
    #[Computed]
    public function programs(): Collection
    {
        return StudyProgram::query()->orderBy('code')->get();
    }

    protected function accountUser(): ?User
    {
        return $this->student?->user;
    }

    protected function editRoute(): ?string
    {
        return $this->student ? route('admin.students.edit', $this->student) : null;
    }

    public function save(CreateStudent $createStudent, UpdateStudent $updateStudent): void
    {
        $this->resetErrorBag();

        $input = [
            'nim' => trim($this->nim),
            'name' => trim($this->name),
            'email' => $this->email ?: null,
            'study_program_id' => $this->study_program_id,
            'entry_year' => $this->entry_year,
            'gender' => $this->gender,
            'birth_place' => $this->birth_place ?: null,
            'birth_date' => $this->birth_date ?: null,
            'address' => $this->address ?: null,
            'phone' => $this->phone ?: null,
            'father_name' => $this->father_name ?: null,
            'father_is_alive' => $this->toBool($this->father_is_alive),
            'mother_name' => $this->mother_name ?: null,
            'mother_is_alive' => $this->toBool($this->mother_is_alive),
            'guardian_name' => $this->guardian_name ?: null,
            'photo' => $this->photo,
        ];

        if ($this->student === null) {
            $account = $createStudent->handle($input);
            $this->student = $account->model->load(['user', 'documents', 'guardians.user']);
            $this->photo = null;
            $this->justCreated = true;
            $this->showCredentials($account);

            return;
        }

        $updateStudent->handle($this->student, [
            ...$input,
            'status' => $this->status,
            'graduated_at' => $this->status === StudentStatus::Graduated->value ? $this->graduated_at : null,
        ]);

        $this->student->refresh()->load(['user', 'documents', 'guardians.user']);
        $this->photo = null;
        Flux::toast(variant: 'success', text: __('Mahasantri saved.'));
    }


    private function toBool(string $value): ?bool
    {
        return $value === '' ? null : $value === '1';
    }

    private function fromBool(?bool $value): string
    {
        return $value === null ? '' : ($value ? '1' : '0');
    }
}; ?>

<section class="w-full">
    <x-page-header :title="$student ? $student->user->name : __('Add mahasantri')" :subtitle="$student ? __('NIM :nim', ['nim' => $student->nim]) : __('A login is created with the NIM as ID and a temporary password.')">
        <x-slot:actions>
            @if ($student)
                <flux:button size="sm" icon="academic-cap" :href="route('admin.students.record', $student)" wire:navigate>{{ __('KHS & transcript') }}</flux:button>
            @endif
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.students.index')" wire:navigate>{{ __('Mahasantri') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <form wire:submit="save" class="space-y-8">
            <flux:fieldset>
                <flux:legend>{{ __('Identity & login') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="nim" :label="__('NIM')" required />
                    <flux:input wire:model="name" :label="__('Full name')" required />
                    <flux:input wire:model="email" :label="__('Email')" type="email" :description:trailing="__('Optional.')" />
                    <flux:select wire:model="gender" :label="__('Gender')" :placeholder="__('Choose…')" required>
                        @foreach (Gender::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Academic') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="study_program_id" :label="__('Study program')" required>
                        @foreach ($this->programs as $program)
                            <flux:select.option :value="$program->id">{{ $program->code }} — {{ $program->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="entry_year" :label="__('Entry year')" type="number" min="1990" required />
                    @if ($student)
                        <flux:select wire:model.live="status" :label="__('Status')">
                            @foreach (StudentStatus::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @if ($status === StudentStatus::Graduated->value)
                            <flux:input wire:model="graduated_at" :label="__('Graduated on')" type="date" required />
                        @endif
                    @endif
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Personal') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="birth_place" :label="__('Birth place')" />
                    <flux:input wire:model="birth_date" :label="__('Birth date')" type="date" />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <div>
                        <flux:label>{{ __('Photo') }}</flux:label>
                        <div class="mt-2 flex items-center gap-3">
                            @if ($photo && method_exists($photo, 'temporaryUrl'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="" class="size-12 rounded-full object-cover">
                            @elseif ($student?->photo_path)
                                <img src="{{ route('files.show', ['student-photo', $student->id]) }}" alt="" class="size-12 rounded-full object-cover">
                            @endif
                            <input type="file" wire:model="photo" accept="image/*" class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                        </div>
                        <flux:error name="photo" />
                    </div>
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" class="sm:col-span-2" />
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Family') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-[1fr_10rem]">
                    <flux:input wire:model="father_name" :label="__('Father\'s name')" />
                    <flux:select wire:model="father_is_alive" :label="__('Father')">
                        <flux:select.option value="">{{ __('Unknown') }}</flux:select.option>
                        <flux:select.option value="1">{{ __('Alive') }}</flux:select.option>
                        <flux:select.option value="0">{{ __('Deceased') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="mother_name" :label="__('Mother\'s name')" />
                    <flux:select wire:model="mother_is_alive" :label="__('Mother')">
                        <flux:select.option value="">{{ __('Unknown') }}</flux:select.option>
                        <flux:select.option value="1">{{ __('Alive') }}</flux:select.option>
                        <flux:select.option value="0">{{ __('Deceased') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="guardian_name" :label="__('Wali (if not a parent)')" class="sm:col-span-2" />
                </div>
            </flux:fieldset>

            <flux:button type="submit" variant="primary" data-test="save-student">{{ $student ? __('Save') : __('Create mahasantri') }}</flux:button>
        </form>

        @if ($student)
            <div class="space-y-6">
                <x-account-panel :user="$student->user" />

                <flux:card class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ __('Documents') }}</flux:heading>
                        <flux:link :href="route('admin.documents', ['search' => $student->nim])" wire:navigate class="text-sm">{{ __('Review') }}</flux:link>
                    </div>
                    @foreach ($student->documents as $document)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $document->title() }}@if ($document->details() !== '') <span class="text-xs text-zinc-500">· {{ $document->details() }}</span>@endif</span>
                            <flux:badge size="sm" :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
                        </div>
                    @endforeach
                </flux:card>

                <flux:card class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ __('Wali santri') }}</flux:heading>
                        <flux:link :href="route('admin.guardians.create', ['student' => $student->id])" wire:navigate class="text-sm">{{ __('Add') }}</flux:link>
                    </div>
                    @forelse ($student->guardians as $guardian)
                        <flux:link :href="route('admin.guardians.edit', $guardian)" wire:navigate class="block text-sm">
                            {{ $guardian->user->name }} <span class="text-zinc-500">· {{ $guardian->relationship ?? __('Wali') }}</span>
                        </flux:link>
                    @empty
                        <flux:text class="text-sm">{{ __('No guardian account linked yet.') }}</flux:text>
                    @endforelse
                </flux:card>
            </div>
        @endif
    </div>

    @include('partials.credentials-modal')
</section>
