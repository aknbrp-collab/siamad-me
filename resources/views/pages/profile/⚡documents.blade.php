<?php

use App\Actions\Documents\UploadDocument;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\StudentDocument;
use App\Models\TeacherDocument;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('My documents')] class extends Component {
    use WithFileUploads;

    /** @var array<string, mixed> Pending upload per document type. */
    public array $uploads = [];

    #[Computed]
    public function isStudent(): bool
    {
        return ActiveRole::get() === Role::Student;
    }

    /**
     * @return Collection<int, StudentDocument|TeacherDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        $owner = $this->isStudent ? auth()->user()->student : auth()->user()->teacher;

        return $owner?->documents()->orderBy('id')->get() ?? collect();
    }

    public function upload(string $type, UploadDocument $uploadDocument): void
    {
        $this->resetErrorBag();

        $document = $this->documents->first(fn ($document) => $document->type->value === $type);
        abort_if($document === null, 404);

        if ($document->status === DocumentStatus::Verified) {
            Flux::toast(variant: 'warning', text: __('This document is already verified.'));

            return;
        }

        $this->validate(["uploads.{$type}" => UploadDocument::rules()], [], ["uploads.{$type}" => __('file')]);

        $uploadDocument->handle($document, $this->uploads[$type]);

        unset($this->uploads[$type], $this->documents);
        Flux::toast(variant: 'success', text: __('Uploaded. The academic office will verify it.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('My documents')" :subtitle="$this->isStudent
        ? __('Every mahasantri must upload these documents. The academic office verifies each one.')
        : __('Please upload these documents so the academic office can complete your file.')" />

    <div class="flex flex-col gap-4">
        @forelse ($this->documents as $document)
            <flux:card wire:key="document-{{ $document->id }}" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>{{ $document->type->label() }}</flux:heading>
                    <flux:badge :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
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

                @unless ($document->status === DocumentStatus::Verified)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input type="file" wire:model="uploads.{{ $document->type->value }}" accept=".pdf,.jpg,.jpeg,.png"
                            class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                        <flux:button size="sm" variant="primary" icon="arrow-up-tray"
                            wire:click="upload('{{ $document->type->value }}')"
                            wire:loading.attr="disabled" wire:target="uploads.{{ $document->type->value }}"
                            data-test="upload-{{ $document->type->value }}">
                            {{ $document->file_path ? __('Replace') : __('Upload') }}
                        </flux:button>
                    </div>
                    <flux:text class="text-xs">{{ __('PDF, JPG or PNG, up to 2 MB.') }}</flux:text>
                    <flux:error :name="'uploads.'.$document->type->value" />
                @endunless
            </flux:card>
        @empty
            <flux:text>{{ __('No documents are required for your account.') }}</flux:text>
        @endforelse
    </div>
</section>
