<?php

use App\Actions\Teaching\ManageTeachingDocuments;
use App\Enums\TeachingDocumentKind;
use App\Enums\TeachingDocumentStatus;
use App\Models\CourseOffering;
use App\Models\TeachingDocument;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Teaching documents')] class extends Component {
    use WithFileUploads;

    public CourseOffering $offering;

    /** @var array<string, mixed> kind => upload */
    public array $files = ['rps' => null, 'journal' => null];

    public function mount(CourseOffering $offering): void
    {
        abort_unless($offering->teacher()->value('user_id') === auth()->id(), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }

    /**
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function members(): Collection
    {
        return app(ManageTeachingDocuments::class)->members($this->offering);
    }

    /**
     * Every version of each document, newest first.
     *
     * @return array<string, Collection<int, TeachingDocument>>
     */
    #[Computed]
    public function versions(): array
    {
        $documents = TeachingDocument::query()->with('reviewer')
            ->where('course_offering_id', $this->offering->teachingLeadId())
            ->orderByDesc('version')->get();

        return collect(TeachingDocumentKind::cases())
            ->mapWithKeys(fn (TeachingDocumentKind $kind) => [$kind->value => $documents->where('kind', $kind)->values()])
            ->all();
    }

    public function upload(string $kind, ManageTeachingDocuments $documents): void
    {
        $this->resetErrorBag();
        $type = TeachingDocumentKind::from($kind);

        try {
            $documents->upload($this->offering, $type, $this->files[$kind] ?? null, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->addError('files.'.$kind, $messages[0]);
            }

            return;
        }

        $this->files[$kind] = null;
        unset($this->versions);
        Flux::toast(variant: 'success', text: __('Uploaded. The academic office will review it.'));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="documents" />

    @if ($this->members->count() > 1)
        <flux:callout icon="link" class="mb-6"
            :text="__('Taught together with :classes: one RPS and one journal for all of them.', ['classes' => $this->members->reject(fn ($member) => $member->is($offering))->map(fn ($member) => $member->class_name)->join(', ')])" />
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (TeachingDocumentKind::cases() as $kind)
            @php($history = $this->versions[$kind->value])
            @php($latest = $history->first())
            <flux:card wire:key="doc-{{ $kind->value }}" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <flux:heading>{{ $kind->label() }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ $kind === TeachingDocumentKind::Rps
                                ? __('Upload the RPS of this subject for the semester (PDF or Word).')
                                : __('Fill in the journal on the Meetings tab, print it, sign it, and upload the signed recap at the end of the semester.') }}
                        </flux:text>
                    </div>
                    @if ($latest)
                        <flux:badge :color="$latest->status->color()">{{ $latest->status->label() }}</flux:badge>
                    @else
                        <flux:badge color="zinc">{{ __('Not uploaded') }}</flux:badge>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    {{-- A Word file with the class filled in (and, for the journal, its meetings), to finish and upload. --}}
                    <flux:button size="sm" icon="document-arrow-down" :href="route('teaching.template', [$offering, $kind->value])" data-test="template-{{ $kind->value }}">
                        {{ $kind === TeachingDocumentKind::Rps ? __('Download the RPS template') : __('Download the journal template') }}
                    </flux:button>
                    @if ($kind === TeachingDocumentKind::Journal)
                        <flux:button size="sm" icon="printer" :href="route('teaching.journal', $offering)" target="_blank">{{ __('Print the journal') }}</flux:button>
                    @endif
                </div>

                @if ($latest?->review_note)
                    <flux:callout :icon="$latest->status === TeachingDocumentStatus::Returned ? 'arrow-uturn-left' : 'chat-bubble-left'" :color="$latest->status->color()" :text="$latest->review_note" />
                @endif

                @unless ($offering->semester->isLocked())
                    <div class="space-y-2">
                        <input type="file" wire:model="files.{{ $kind->value }}" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="block w-full text-sm" data-test="file-{{ $kind->value }}">
                        <flux:error name="files.{{ $kind->value }}" />
                        <flux:button size="sm" variant="primary" icon="arrow-up-tray" wire:click="upload('{{ $kind->value }}')" :disabled="! ($files[$kind->value] ?? null)" data-test="upload-{{ $kind->value }}">
                            {{ $latest ? __('Upload a new version') : __('Upload') }}
                        </flux:button>
                    </div>
                @endunless

                @if ($history->isNotEmpty())
                    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($history as $document)
                            <div wire:key="version-{{ $document->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2">
                                <flux:link :href="route('files.show', ['teaching-document', $document->id])" target="_blank">{{ __('Version :version', ['version' => $document->version]) }}</flux:link>
                                <span class="text-xs text-zinc-500">{{ $document->created_at->translatedFormat('j M Y H:i') }}</span>
                                <flux:badge size="sm" :color="$document->status->color()" class="ms-auto">{{ $document->status->label() }}</flux:badge>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        @endforeach
    </div>
</section>
