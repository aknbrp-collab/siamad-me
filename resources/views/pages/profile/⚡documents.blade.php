<?php

use App\Actions\Documents\UploadDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EducationLevel;
use App\Enums\Role;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('My documents')] class extends Component {
    use WithFileUploads;

    /** @var array<int|string, mixed> Pending upload per document id. */
    public array $uploads = [];

    /** @var array<int|string, array<string, mixed>> Ijazah details per document id. */
    public array $details = [];

    public function mount(): void
    {
        $this->fillDetails();
    }

    #[Computed]
    public function isStudent(): bool
    {
        return ActiveRole::get() === Role::Student;
    }

    /**
     * KTP and KK first, then the ijazah (several for a muhadhir).
     *
     * @return Collection<int, StudentDocument|TeacherDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        $owner = $this->isStudent ? auth()->user()->student : auth()->user()->teacher;

        return $owner?->documents()->orderByRaw("case when type = 'ijazah' then 1 else 0 end")->orderBy('id')->get() ?? collect();
    }

    /**
     * @return list<EducationLevel>
     */
    #[Computed]
    public function levels(): array
    {
        return $this->isStudent ? EducationLevel::forStudents() : EducationLevel::cases();
    }

    public function upload(int $id, UploadDocument $uploadDocument): void
    {
        $this->resetErrorBag();

        $document = $this->documents->firstWhere('id', $id);
        abort_if($document === null, 404);

        if ($document->status === DocumentStatus::Verified) {
            Flux::toast(variant: 'warning', text: __('This document is already verified.'));

            return;
        }

        $this->validate(["uploads.{$id}" => UploadDocument::rules()], [], ["uploads.{$id}" => __('file')]);

        try {
            $uploadDocument->handle($document, $this->uploads[$id], $this->details[$id] ?? []);
        } catch (ValidationException $exception) {
            // Show the action's errors under this document's fields.
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ["details.{$id}.{$key}" => $messages])->all());
        }

        unset($this->uploads[$id], $this->documents);
        $this->fillDetails();
        Flux::toast(variant: 'success', text: __('Uploaded. The academic office will verify it.'));
    }

    public function addIjazah(UploadDocument $uploadDocument): void
    {
        $this->resetErrorBag();
        abort_if($this->isStudent || auth()->user()->teacher === null, 403);

        $uploadDocument->addIjazah(auth()->user()->teacher);

        unset($this->documents);
        $this->fillDetails();
    }

    public function removeIjazah(int $id, UploadDocument $uploadDocument): void
    {
        $this->resetErrorBag();
        $document = $this->documents->firstWhere('id', $id);
        abort_if($this->isStudent || ! $document instanceof TeacherDocument, 404);

        $uploadDocument->removeIjazah($document);

        unset($this->documents, $this->details[$id]);
    }

    private function fillDetails(): void
    {
        foreach ($this->documents->where('type', DocumentType::Ijazah) as $document) {
            $this->details[$document->id] ??= [
                'education_level' => $document->education_level?->value,
                'institution' => $document->institution ?? '',
                'graduation_year' => $document->graduation_year,
                'degree_title' => $document->degree_title ?? '',
            ];
        }
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My documents')" :subtitle="$this->isStudent
        ? __('Every mahasantri must upload these documents. The academic office verifies each one.')
        : __('Please upload these documents so the academic office can complete your file.')" />

    <div class="flex flex-col gap-4">
        @forelse ($this->documents as $document)
            @php($isIjazah = $document->type === DocumentType::Ijazah)
            @php($locked = $document->status === DocumentStatus::Verified)
            <flux:card wire:key="document-{{ $document->id }}" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading>{{ $document->title() }}</flux:heading>
                        @if ($document->details() !== '')
                            <flux:text class="text-sm">{{ $document->details() }}</flux:text>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:badge :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
                        @if ($isIjazah && ! $this->isStudent && $document->file_path === null && $this->documents->where('type', DocumentType::Ijazah)->count() > 1)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeIjazah({{ $document->id }})" :aria-label="__('Remove')" />
                        @endif
                    </div>
                </div>

                @if ($document->status === DocumentStatus::Rejected && $document->rejection_note)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Please upload again')" :text="$document->rejection_note" />
                @endif

                @if ($document->file_path)
                    <flux:text class="text-sm">
                        <flux:link :href="route('files.show', [$this->isStudent ? 'student-document' : 'teacher-document', $document->id])" target="_blank">
                            {{ $document->original_name ?? __('View file') }}
                        </flux:link>
                        · {{ __('uploaded :time', ['time' => $document->uploaded_at?->diffForHumans()]) }}
                    </flux:text>
                @endif

                @unless ($locked)
                    @if ($isIjazah)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:select wire:model="details.{{ $document->id }}.education_level" :label="__('Level')" :placeholder="__('Choose…')" required>
                                @foreach ($this->levels as $level)
                                    <flux:select.option :value="$level->value">{{ $level->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="details.{{ $document->id }}.graduation_year" type="number" min="1950" :max="now()->year" :label="__('Graduation year')" required />
                            <div class="sm:col-span-2">
                                <flux:input wire:model="details.{{ $document->id }}.institution" :label="__('Institution')" :placeholder="$this->isStudent ? __('e.g. MA Al-Hikmah Kediri') : __('e.g. Universitas Al-Azhar, Kairo')" required />
                            </div>
                            @unless ($this->isStudent)
                                <div class="sm:col-span-2">
                                    <flux:input wire:model="details.{{ $document->id }}.degree_title" :label="__('Gelar from this ijazah')" placeholder="Lc., M.Pd.I." :description:trailing="__('Optional. Your printed title is set in your profile.')" />
                                </div>
                            @endunless
                        </div>
                    @endif

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input type="file" wire:model="uploads.{{ $document->id }}" accept=".pdf,.jpg,.jpeg,.png"
                            class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                        <flux:button size="sm" variant="primary" icon="arrow-up-tray"
                            wire:click="upload({{ $document->id }})"
                            wire:loading.attr="disabled" wire:target="uploads.{{ $document->id }}"
                            data-test="upload-{{ $document->type->value }}">
                            {{ $document->file_path ? __('Replace') : __('Upload') }}
                        </flux:button>
                    </div>
                    <flux:text class="text-xs">{{ __('PDF, JPG or PNG, up to :size MB.', ['size' => \App\Support\Upload::maxMb()]) }}</flux:text>
                    <flux:error :name="'uploads.'.$document->id" />
                @endunless
            </flux:card>
        @empty
            <flux:text>{{ __('No documents are required for your account.') }}</flux:text>
        @endforelse

        @unless ($this->isStudent || $this->documents->isEmpty())
            <div>
                <flux:button icon="plus" wire:click="addIjazah" data-test="add-ijazah">{{ __('Add another ijazah') }}</flux:button>
                <flux:text class="mt-1 text-xs">{{ __('One per level, e.g. your S1 and S2.') }}</flux:text>
                <flux:error name="ijazah" />
            </div>
        @endunless
    </div>
</section>
