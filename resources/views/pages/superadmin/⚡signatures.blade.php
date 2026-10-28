<?php

use App\Enums\PrintDocument;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Support\Branding;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Signatures & stamps')] class extends Component {
    use WithFileUploads;

    // Signatory form.
    public ?int $signatoryId = null;
    public string $name = '';
    public string $position = '';
    public ?string $office = null;
    public ?TemporaryUploadedFile $signature = null;
    public bool $signatoryActive = true;

    // Stamp form.
    public ?int $stampId = null;
    public string $stampName = '';
    public ?TemporaryUploadedFile $stampImage = null;
    public bool $stampActive = true;

    /**
     * Default per document: [signatory id|null, stamp id|null, print images].
     *
     * @var array<string, array{signatory: int|string|null, stamp: int|string|null, images: bool}>
     */
    public array $defaults = [];

    public function mount(): void
    {
        foreach (PrintDocument::withDefaults() as $document) {
            $key = 'print.'.$document->value;
            $this->defaults[$document->value] = [
                'signatory' => Setting::get($key.'.signatory'),
                'stamp' => Setting::get($key.'.stamp'),
                'images' => (bool) Setting::get($key.'.images', true),
            ];
        }
    }

    /**
     * @return Collection<int, Signatory>
     */
    #[Computed]
    public function signatories(): Collection
    {
        return Signatory::query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Stamp>
     */
    #[Computed]
    public function stamps(): Collection
    {
        return Stamp::query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function createSignatory(): void
    {
        $this->reset('signatoryId', 'name', 'position', 'office', 'signature');
        $this->signatoryActive = true;
        $this->resetErrorBag();
        Flux::modal('signatory-form')->show();
    }

    public function editSignatory(int $id): void
    {
        $signatory = Signatory::query()->findOrFail($id);
        $this->signatoryId = $signatory->id;
        $this->name = $signatory->name;
        $this->position = $signatory->position;
        $this->office = $signatory->office;
        $this->signatoryActive = $signatory->is_active;
        $this->signature = null;
        $this->resetErrorBag();
        Flux::modal('signatory-form')->show();
    }

    public function saveSignatory(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:150'],
            'office' => ['nullable', 'string', 'max:150'],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $attributes = ['name' => trim($this->name), 'position' => trim($this->position), 'office' => trim((string) $this->office) ?: null, 'is_active' => $this->signatoryActive];

        if ($this->signature !== null) {
            $attributes['signature_path'] = Branding::storeImage($this->signature, 'branding/signatures', 600);
        }

        Signatory::query()->updateOrCreate(['id' => $this->signatoryId], $attributes);

        Flux::modal('signatory-form')->close();
        unset($this->signatories);
        Flux::toast(variant: 'success', text: __('Signatory saved.'));
    }

    public function createStamp(): void
    {
        $this->reset('stampId', 'stampName', 'stampImage');
        $this->stampActive = true;
        $this->resetErrorBag();
        Flux::modal('stamp-form')->show();
    }

    public function editStamp(int $id): void
    {
        $stamp = Stamp::query()->findOrFail($id);
        $this->stampId = $stamp->id;
        $this->stampName = $stamp->name;
        $this->stampActive = $stamp->is_active;
        $this->stampImage = null;
        $this->resetErrorBag();
        Flux::modal('stamp-form')->show();
    }

    public function saveStamp(): void
    {
        $this->validate([
            'stampName' => ['required', 'string', 'max:150'],
            'stampImage' => [$this->stampId ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $attributes = ['name' => trim($this->stampName), 'is_active' => $this->stampActive];

        if ($this->stampImage !== null) {
            $attributes['image_path'] = Branding::storeImage($this->stampImage, 'branding/stamps', 600);
        }

        Stamp::query()->updateOrCreate(['id' => $this->stampId], $attributes);

        Flux::modal('stamp-form')->close();
        unset($this->stamps);
        Flux::toast(variant: 'success', text: __('Stamp saved.'));
    }

    public function saveDefaults(): void
    {
        $signatories = Signatory::query()->pluck('id')->all();
        $stamps = Stamp::query()->pluck('id')->all();

        foreach (PrintDocument::withDefaults() as $document) {
            $choice = $this->defaults[$document->value] ?? [];
            $key = 'print.'.$document->value;
            $signatory = in_array((int) ($choice['signatory'] ?? 0), $signatories, true) ? (int) $choice['signatory'] : null;
            $stamp = in_array((int) ($choice['stamp'] ?? 0), $stamps, true) ? (int) $choice['stamp'] : null;

            foreach (['signatory' => $signatory, 'stamp' => $stamp, 'images' => (bool) ($choice['images'] ?? true)] as $part => $value) {
                if (Setting::get($key.'.'.$part, $part === 'images' ? true : null) !== $value) {
                    Setting::set($key.'.'.$part, $value);
                }
            }
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Signatures & stamps')" :subtitle="__('Who signs printed documents, their signature images and the stamps. Each document has a default; staff can choose another one, or print without, when they print.')" />

    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Signatories') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="createSignatory">{{ __('Add') }}</flux:button>
            </div>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->signatories as $signatory)
                    @php($image = Branding::dataUri($signatory->signature_path))
                    <div wire:key="signatory-{{ $signatory->id }}" @class(['flex items-center gap-3 px-4 py-3', 'opacity-60' => ! $signatory->is_active])>
                        <div class="flex h-12 w-20 shrink-0 items-center justify-center rounded bg-white">
                            @if ($image)<img src="{{ $image }}" alt="" class="max-h-11 max-w-full object-contain">@else<span class="text-[10px] text-zinc-400">{{ __('No image') }}</span>@endif
                        </div>
                        <div class="min-w-0 flex-1 text-sm">
                            <div class="font-medium">{{ $signatory->name }}</div>
                            <div class="text-zinc-500">{{ $signatory->position }}@if ($signatory->office) · {{ $signatory->office }}@endif</div>
                        </div>
                        @unless ($signatory->is_active)<flux:badge size="sm">{{ __('Inactive') }}</flux:badge>@endunless
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editSignatory({{ $signatory->id }})" :aria-label="__('Edit')" />
                    </div>
                @empty
                    <flux:text class="p-4 text-sm">{{ __('No signatories yet.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Stamps') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="createStamp">{{ __('Add') }}</flux:button>
            </div>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->stamps as $stamp)
                    @php($image = Branding::dataUri($stamp->image_path))
                    <div wire:key="stamp-{{ $stamp->id }}" @class(['flex items-center gap-3 px-4 py-3', 'opacity-60' => ! $stamp->is_active])>
                        <div class="flex size-12 shrink-0 items-center justify-center rounded bg-white">
                            @if ($image)<img src="{{ $image }}" alt="" class="max-h-11 max-w-full object-contain">@endif
                        </div>
                        <div class="min-w-0 flex-1 text-sm font-medium">{{ $stamp->name }}</div>
                        @unless ($stamp->is_active)<flux:badge size="sm">{{ __('Inactive') }}</flux:badge>@endunless
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editStamp({{ $stamp->id }})" :aria-label="__('Edit')" />
                    </div>
                @empty
                    <flux:text class="p-4 text-sm">{{ __('No stamps yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    <flux:separator class="my-8" />

    <flux:heading size="lg">{{ __('Defaults per document') }}</flux:heading>
    <flux:text class="mt-1 mb-4">{{ __('Used when mahasantri and wali print their own documents, and preselected when staff print.') }}</flux:text>

    <form wire:submit="saveDefaults">
        <div class="overflow-x-auto">
            <flux:table class="table-stack">
                <flux:table.columns>
                    <flux:table.column>{{ __('Document') }}</flux:table.column>
                    <flux:table.column>{{ __('Signed by') }}</flux:table.column>
                    <flux:table.column>{{ __('Stamp') }}</flux:table.column>
                    <flux:table.column>{{ __('Print images') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach (PrintDocument::withDefaults() as $document)
                        <flux:table.row :key="$document->value">
                            <flux:table.cell class="font-medium">{{ $document->label() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:select wire:model="defaults.{{ $document->value }}.signatory" size="sm">
                                    <flux:select.option value="">{{ __('— blank line ":title" —', ['title' => $document->fallbackTitle()]) }}</flux:select.option>
                                    @foreach ($this->signatories->where('is_active', true) as $signatory)
                                        <flux:select.option :value="$signatory->id">{{ $signatory->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:select wire:model="defaults.{{ $document->value }}.stamp" size="sm">
                                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                                    @foreach ($this->stamps->where('is_active', true) as $stamp)
                                        <flux:select.option :value="$stamp->id">{{ $stamp->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:switch wire:model="defaults.{{ $document->value }}.images" :aria-label="__('Print images')" />
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="ghost" icon="eye" :href="route('superadmin.identity.preview', ['document' => $document->value])" target="_blank">{{ __('Preview') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
        <flux:text class="mt-2 text-xs">{{ __('Save first, then preview: the preview uses the saved defaults.') }}</flux:text>
        <flux:button type="submit" variant="primary" class="mt-4" data-test="save-defaults">{{ __('Save defaults') }}</flux:button>
    </form>

    <flux:modal name="signatory-form" class="w-full max-w-md">
        <form wire:submit="saveSignatory" class="space-y-5">
            <flux:heading size="lg">{{ $signatoryId ? __('Edit signatory') : __('Add signatory') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="position" :label="__('Position')" :placeholder="__('e.g. Mudir, Bendahara')" required />
            <flux:input wire:model="office" :label="__('Office (optional)')" :placeholder="__('e.g. Bagian Keuangan')" />
            <div>
                <flux:label>{{ __('Signature image') }}</flux:label>
                <input type="file" wire:model="signature" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-sm">
                <flux:text class="mt-1 text-xs">{{ __('A scan of the signature on white or transparent; PNG with transparency prints best. Leave empty to keep the current one.') }}</flux:text>
                <flux:error name="signature" />
            </div>
            <flux:checkbox wire:model="signatoryActive" :label="__('Active (can be chosen when printing)')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-signatory">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="stamp-form" class="w-full max-w-md">
        <form wire:submit="saveStamp" class="space-y-5">
            <flux:heading size="lg">{{ $stampId ? __('Edit stamp') : __('Add stamp') }}</flux:heading>
            <flux:input wire:model="stampName" :label="__('Name')" :placeholder="__('e.g. Stempel Bagian Keuangan')" required />
            <div>
                <flux:label>{{ __('Stamp image') }}</flux:label>
                <input type="file" wire:model="stampImage" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-sm">
                <flux:error name="stampImage" />
            </div>
            <flux:checkbox wire:model="stampActive" :label="__('Active (can be chosen when printing)')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-stamp">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
