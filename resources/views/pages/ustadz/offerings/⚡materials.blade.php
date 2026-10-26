<?php

use App\Actions\Lms\ManageMaterials;
use App\Models\CourseOffering;
use App\Models\Material;
use App\Support\OfferingAccess;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Materials')] class extends Component {
    use WithFileUploads;

    public CourseOffering $offering;

    public ?int $materialId = null;
    public string $title = '';
    public ?string $body = null;
    public ?string $url = null;
    public $file = null;

    public function mount(CourseOffering $offering): void
    {
        abort_unless(OfferingAccess::teaches(auth()->user(), $offering), 403);

        $this->offering = $offering->load(['classGroup', 'semester.academicYear']);
    }

    /**
     * @return Collection<int, Material>
     */
    #[Computed]
    public function materials(): Collection
    {
        return $this->offering->materials()->get();
    }

    public function create(): void
    {
        $this->reset('materialId', 'title', 'body', 'url', 'file');
        $this->resetErrorBag();
        Flux::modal('material-form')->show();
    }

    public function edit(int $id): void
    {
        $material = $this->offering->materials()->findOrFail($id);

        $this->materialId = $material->id;
        $this->title = $material->title;
        $this->body = $material->body;
        $this->url = $material->url;
        $this->file = null;
        $this->resetErrorBag();
        Flux::modal('material-form')->show();
    }

    public function save(ManageMaterials $materials): void
    {
        $this->resetErrorBag();

        $materials->save($this->offering, $this->materialId ? $this->offering->materials()->findOrFail($this->materialId) : null, [
            'title' => $this->title,
            'body' => $this->body ?: null,
            'url' => $this->url ?: null,
            'file' => $this->file,
        ]);

        Flux::modal('material-form')->close();
        unset($this->materials);
        Flux::toast(variant: 'success', text: __('Material saved. Mahasantri of this class can see it now.'));
    }

    public function delete(int $id, ManageMaterials $materials): void
    {
        $materials->delete($this->offering->materials()->findOrFail($id));

        unset($this->materials);
        Flux::toast(variant: 'success', text: __('Material removed.'));
    }
}; ?>

<section class="w-full">
    <x-offering-header :offering="$offering" current="materials" />

    @error('semester')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    @unless ($offering->semester->isLocked())
        <flux:button variant="primary" icon="plus" class="mb-4" wire:click="create">{{ __('Add material') }}</flux:button>
    @endunless

    <div class="flex flex-col gap-3">
        @forelse ($this->materials as $material)
            <flux:card wire:key="material-{{ $material->id }}" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="flex-1 space-y-1">
                    <flux:heading>{{ $material->title }}</flux:heading>
                    @if ($material->body)
                        <flux:text class="whitespace-pre-line text-sm">{{ $material->body }}</flux:text>
                    @endif
                    <div class="flex flex-wrap gap-3 text-sm">
                        @if ($material->file_path)
                            <flux:link :href="route('files.show', ['material', $material->id])" target="_blank">{{ $material->original_name }}</flux:link>
                        @endif
                        @if ($material->url)
                            <flux:link :href="$material->url" target="_blank">{{ $material->url }}</flux:link>
                        @endif
                    </div>
                    <flux:text class="text-xs">{{ $material->created_at->translatedFormat('j M Y') }}</flux:text>
                </div>
                @unless ($offering->semester->isLocked())
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $material->id }})" :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $material->id }})" wire:confirm="{{ __('Remove this material?') }}" :aria-label="__('Remove')" />
                    </div>
                @endunless
            </flux:card>
        @empty
            <flux:text>{{ __('No materials yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="material-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $materialId ? __('Edit material') : __('Add material') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="body" :label="__('Text')" rows="5" />
            <flux:input wire:model="url" :label="__('Link')" type="url" placeholder="https://" />
            <div>
                <flux:label>{{ __('File') }}</flux:label>
                <input type="file" wire:model="file" class="mt-2 block text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                <flux:text class="mt-1 text-xs">{{ __('PDF, Word, PowerPoint, Excel, images or zip, up to 10 MB.') }}</flux:text>
                <flux:error name="file" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
