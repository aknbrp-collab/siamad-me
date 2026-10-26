<?php

use App\Actions\People\UpdateOwnProfile;
use App\Enums\Gender;
use App\Enums\Role;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\ActiveRole;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('My profile')] class extends Component {
    use WithFileUploads;

    /** Which profile this is: student, teacher or guardian (from the active role). */
    #[Locked]
    public string $kind = '';

    public string $name = '';
    public ?string $email = null;
    public ?string $gender = null;
    public ?string $nik = null;
    public ?string $title = null;
    public ?string $birth_place = null;
    public ?string $birth_date = null;
    public ?string $phone = null;
    public ?string $address = null;
    public $photo = null;
    public ?string $father_name = null;
    public string $father_is_alive = '';
    public ?string $mother_name = null;
    public string $mother_is_alive = '';
    public ?string $guardian_name = null;
    public ?string $expertise = null;
    public ?string $scopus_id = null;
    public ?string $google_scholar_url = null;
    public ?string $relationship = null;

    public function mount(): void
    {
        $this->kind = match (ActiveRole::get()) {
            Role::Student => 'student',
            Role::Teacher => 'teacher',
            Role::Guardian => 'guardian',
            default => abort(403),
        };

        $profile = $this->profile();
        abort_if($profile === null, 404);

        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;

        if ($profile instanceof Guardian) {
            $this->fill($profile->only(['relationship', 'phone']));

            return;
        }

        $this->fill($profile->only(['birth_place', 'phone', 'address']));
        $this->birth_date = $profile->birth_date?->format('Y-m-d');
        $this->gender = $profile->gender?->value;

        if ($profile instanceof Student) {
            $this->fill($profile->only(['father_name', 'mother_name', 'guardian_name']));
            $this->father_is_alive = $this->fromBool($profile->father_is_alive);
            $this->mother_is_alive = $this->fromBool($profile->mother_is_alive);
        } else {
            $this->fill($profile->only(['nik', 'title', 'expertise', 'scopus_id', 'google_scholar_url']));
        }
    }

    public function profile(): Student|Teacher|Guardian|null
    {
        $user = auth()->user();

        return match ($this->kind) {
            'student' => $user->student?->loadMissing('studyProgram'),
            'teacher' => $user->teacher,
            'guardian' => $user->guardian?->loadMissing('students.user'),
            default => null,
        };
    }

    public function save(UpdateOwnProfile $update): void
    {
        $this->resetErrorBag();
        $profile = $this->profile();
        abort_if($profile === null, 404);

        $personal = [
            'birth_place' => $this->birth_place ?: null,
            'birth_date' => $this->birth_date ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'photo' => $this->photo,
        ];

        match (true) {
            $profile instanceof Student => $update->student($profile, [
                ...$personal,
                'father_name' => $this->father_name ?: null,
                'father_is_alive' => $this->toBool($this->father_is_alive),
                'mother_name' => $this->mother_name ?: null,
                'mother_is_alive' => $this->toBool($this->mother_is_alive),
                'guardian_name' => $this->guardian_name ?: null,
            ]),
            $profile instanceof Teacher => $update->teacher($profile, [
                ...$personal,
                'name' => trim($this->name),
                'email' => $this->email ?: null,
                'nik' => $this->nik ?: null,
                'title' => $this->title ?: null,
                'gender' => $this->gender,
                'expertise' => $this->expertise ?: null,
                'scopus_id' => $this->scopus_id ?: null,
                'google_scholar_url' => $this->google_scholar_url ?: null,
            ]),
            default => $update->guardian($profile, [
                'name' => trim($this->name),
                'email' => $this->email ?: null,
                'relationship' => $this->relationship ?: null,
                'phone' => $this->phone ?: null,
            ]),
        };

        $this->photo = null;
        Flux::toast(variant: 'success', text: __('Profile saved.'));
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

@php($profile = $this->profile())

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My profile')" :subtitle="match ($kind) {
        'student' => __('Keep your personal and family details up to date. Academic data is managed by the academic office.'),
        'teacher' => __('Keep your details up to date. Your NIDM is your login ID and is managed by the academic office.'),
        default => __('Keep your contact details up to date so the institution can reach you.'),
    }" />

    {{-- What the academic office manages: shown, not editable. --}}
    <flux:card class="mb-8">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[auto_1fr]" data-test="read-only">
            @if ($profile instanceof \App\Models\Student)
                <dt class="text-zinc-500">{{ __('NIM') }}</dt><dd class="font-mono">{{ $profile->nim }}</dd>
                <dt class="text-zinc-500">{{ __('Full name') }}</dt><dd>{{ auth()->user()->name }}</dd>
                <dt class="text-zinc-500">{{ __('Gender') }}</dt><dd>{{ $profile->gender?->label() ?? '—' }}</dd>
                <dt class="text-zinc-500">{{ __('Study program') }}</dt><dd>{{ $profile->studyProgram?->name ?? '—' }}</dd>
                <dt class="text-zinc-500">{{ __('Entry year') }}</dt><dd>{{ $profile->entry_year }}</dd>
                <dt class="text-zinc-500">{{ __('Status') }}</dt><dd>{{ $profile->status->label() }}</dd>
            @elseif ($profile instanceof \App\Models\Teacher)
                <dt class="text-zinc-500">{{ __('NIDM') }}</dt><dd class="font-mono">{{ $profile->nidm }}</dd>
                <dt class="text-zinc-500">{{ __('Status') }}</dt><dd>{{ $profile->status->label() }}</dd>
            @else
                <dt class="text-zinc-500">{{ __('Code') }}</dt><dd class="font-mono">{{ $profile->code }}</dd>
                <dt class="text-zinc-500">{{ __('Mahasantri') }}</dt>
                <dd>{{ $profile->students->map(fn ($student) => $student->user->name.' ('.$student->nim.')')->join(', ') ?: '—' }}</dd>
            @endif
        </dl>
        <flux:text class="mt-4 text-sm">{{ __('Contact the academic office if any of this is wrong.') }}</flux:text>
    </flux:card>

    <form wire:submit="save" class="space-y-8">
        @if ($kind !== 'student')
            <flux:fieldset>
                <flux:legend>{{ $kind === 'teacher' ? __('Identity') : __('Wali santri') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Full name')" required />
                    <flux:input wire:model="email" :label="__('Email')" type="email" :description:trailing="__('Optional. Used to reset your password yourself.')" />
                    @if ($kind === 'teacher')
                        <flux:input wire:model="title" :label="__('Title (gelar)')" placeholder="Lc., M.A." />
                        <flux:input wire:model="nik" :label="__('NIK')" :description:trailing="__('Optional, 16 digits.')" />
                        <flux:select wire:model="gender" :label="__('Gender')" required>
                            @foreach (Gender::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:input wire:model="relationship" :label="__('Relationship')" placeholder="Ayah / Ibu / Wali" />
                        <flux:input wire:model="phone" :label="__('Phone')" />
                    @endif
                </div>
            </flux:fieldset>
        @endif

        @if ($kind !== 'guardian')
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
                            @elseif ($profile->photo_path)
                                <img src="{{ route('files.show', [$kind.'-photo', $profile->id]) }}" alt="" class="size-12 rounded-full object-cover">
                            @endif
                            <input type="file" wire:model="photo" accept="image/*" class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                        </div>
                        <flux:error name="photo" />
                    </div>
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" class="sm:col-span-2" />
                </div>
            </flux:fieldset>
        @endif

        @if ($kind === 'student')
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
        @endif

        @if ($kind === 'teacher')
            <flux:fieldset>
                <flux:legend>{{ __('Academic profile') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="expertise" :label="__('Expertise (keahlian)')" class="sm:col-span-2" />
                    <flux:input wire:model="scopus_id" :label="__('Scopus author ID')" />
                    <flux:input wire:model="google_scholar_url" :label="__('Google Scholar profile URL')" type="url" />
                </div>
            </flux:fieldset>
        @endif

        <flux:button type="submit" variant="primary" data-test="save-profile">{{ __('Save') }}</flux:button>
    </form>
</section>
