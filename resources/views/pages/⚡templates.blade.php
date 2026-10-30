<?php

use App\Models\DocumentTemplate;
use App\Support\LetterDocuments;
use App\Support\Templates;
use App\Support\TeachingTemplates;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Templates')] class extends Component {
    use WithFileUploads;

    /** @var array<string, mixed> Pending upload per slot key (dots replaced by dashes). */
    public array $uploads = [];

    /**
     * Slots grouped for the page, each with its current version.
     *
     * @return Collection<string, Collection<string, array{key: string, label: string, format: string, current: DocumentTemplate|null}>>
     */
    #[Computed]
    public function groups(): Collection
    {
        $current = DocumentTemplate::query()->with('uploader')->get()->groupBy('key')->map(fn ($versions) => $versions->sortByDesc('version')->first());

        return collect(Templates::slots())
            ->map(fn (array $slot, string $key) => ['key' => $key, 'label' => $slot['label'], 'format' => $slot['format'], 'group' => $slot['group'], 'source' => $slot['source'], 'current' => $current->get($key)])
            ->groupBy('group', preserveKeys: true);
    }

    public function upload(string $key): void
    {
        $this->resetErrorBag();
        $field = str_replace('.', '-', $key);

        try {
            Templates::upload($key, $this->uploads[$field] ?? null, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(["uploads.{$field}" => collect($exception->errors())->flatten()->all()]);
        }

        unset($this->uploads[$field], $this->groups);
        Flux::toast(variant: 'success', text: __('Template replaced. Downloads now use your file.'));
    }

    public function revert(string $key): void
    {
        Templates::revert($key, auth()->user());

        unset($this->groups);
        Flux::toast(variant: 'success', text: __('Back to the built-in template.'));
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Templates')" :subtitle="__('Every template people download from SIAKAD. Each has a designed built-in version; download it, adjust it in Excel or Word, and upload it as a replacement. You can always go back to the built-in one.')" />

    <div class="space-y-8">
        @foreach ($this->groups as $group => $slots)
            <div wire:key="group-{{ $loop->index }}">
                <flux:heading size="lg" class="mb-3">{{ $group }}</flux:heading>
                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($slots as $slot)
                        @php($field = str_replace('.', '-', $slot['key']))
                        @php($custom = $slot['current']?->file_path !== null)
                        <div wire:key="slot-{{ $field }}" class="space-y-3 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="me-auto">
                                    <div class="font-medium">{{ $slot['label'] }} <span class="text-xs uppercase text-zinc-500">.{{ $slot['format'] }}</span></div>
                                    <flux:text class="text-xs">
                                        @if ($custom)
                                            {{ __('Replacement :name, uploaded by :by :time', ['name' => $slot['current']->original_name, 'by' => $slot['current']->uploader->name, 'time' => $slot['current']->created_at->diffForHumans()]) }}
                                        @else
                                            {{ $slot['source'] === 'letter' ? __('Built-in PDF layout: the download is a Word starting point') : __('Built-in template') }}
                                        @endif
                                    </flux:text>
                                </div>
                                <flux:badge size="sm" :color="$custom ? 'sky' : 'zinc'">{{ $custom ? __('Replaced') : __('Built-in') }}</flux:badge>
                                <flux:button size="sm" icon="arrow-down-tray" :href="route('templates.show', $slot['key'])" data-test="download-{{ $field }}">{{ __('Download') }}</flux:button>
                                @if ($custom)
                                    <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="revert('{{ $slot['key'] }}')" wire:confirm="{{ __('Go back to the built-in template?') }}">{{ __('Use built-in') }}</flux:button>
                                @endif
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <input type="file" wire:model="uploads.{{ $field }}" accept=".{{ $slot['format'] }}"
                                    class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                                <flux:button size="sm" icon="arrow-up-tray" wire:click="upload('{{ $slot['key'] }}')" wire:loading.attr="disabled" wire:target="uploads.{{ $field }}" data-test="upload-{{ $field }}">{{ __('Upload replacement') }}</flux:button>
                            </div>
                            <flux:error :name="'uploads.'.$field" />
                        </div>
                    @endforeach
                </div>
                @if ($slots->first()['source'] === 'letter')
                    <flux:text class="mt-2 text-xs">
                        {{ __('A letter type without a Word template uses the built-in PDF layout. With one, new letters are filled from it and turned into PDF when LibreOffice is installed (otherwise they download as Word); letters already issued keep their version. Placeholders:') }}
                        {{ collect(LetterDocuments::placeholders())->map(fn ($label, $name) => '${'.$name.'} '.$label)->join(' · ') }}.
                    </flux:text>
                @elseif ($slots->first()['format'] === 'xlsx')
                    <flux:text class="mt-2 text-xs">{{ __('Keep the column names in the first row: SIAKAD reads them. Colours, widths and extra sheets are yours to change.') }}</flux:text>
                @else
                    <flux:text class="mt-2 text-xs">
                        {{ __('Placeholders filled in for each class:') }}
                        {{ collect(TeachingTemplates::placeholders())->map(fn ($label, $name) => '${'.$name.'} '.$label)->join(' · ') }}.
                        {{ __('Journal: put these in one table row; it is repeated for every meeting:') }}
                        {{ collect(TeachingTemplates::journalRow())->map(fn ($label, $name) => '${'.$name.'} '.$label)->join(' · ') }}.
                    </flux:text>
                @endif
            </div>
        @endforeach
    </div>
</section>
