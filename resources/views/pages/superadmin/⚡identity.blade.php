<?php

use App\Enums\PrintDocument;
use App\Models\Setting;
use App\Support\Branding;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Identity')] class extends Component {
    use WithFileUploads;

    public string $institutionName = '';
    public ?string $shortName = null;
    public ?string $foundation = null;
    public ?string $address = null;
    public ?string $city = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $website = null;
    public ?string $licence = null;
    public ?string $head = null;

    /** @var array<string, TemporaryUploadedFile|null> */
    public array $uploads = ['app_logo' => null, 'institution_logo' => null, 'arabic_name' => null];

    /**
     * Setting keys mapped to the component properties holding them.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'institution.name' => 'institutionName',
        'institution.short_name' => 'shortName',
        'institution.foundation' => 'foundation',
        'institution.address' => 'address',
        'institution.city' => 'city',
        'institution.phone' => 'phone',
        'institution.email' => 'email',
        'institution.website' => 'website',
        'institution.licence' => 'licence',
        'institution.head' => 'head',
    ];

    public function mount(): void
    {
        foreach (self::FIELDS as $key => $property) {
            $this->{$property} = Setting::get($key);
        }
    }

    /**
     * @return array<string, array{label: string, hint: string}>
     */
    public function images(): array
    {
        return [
            'app_logo' => ['label' => __('App logo'), 'hint' => __('Sidebar, login page and the icon of the installed app. Square works best.')],
            'institution_logo' => ['label' => __('Institution logo'), 'hint' => __('Left side of the letterhead on every PDF.')],
            'arabic_name' => ['label' => __('Name in Arabic calligraphy'), 'hint' => __('Above the institution name on the letterhead. A wide image with a transparent background.')],
        ];
    }

    public function save(): void
    {
        $this->validate([
            'institutionName' => ['required', 'string', 'max:255'],
            'shortName' => ['nullable', 'string', 'max:30'],
            'foundation' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'licence' => ['nullable', 'string', 'max:255'],
            'head' => ['nullable', 'string', 'max:150'],
        ]);

        foreach (self::FIELDS as $key => $property) {
            $value = is_string($this->{$property}) ? (trim($this->{$property}) ?: null) : $this->{$property};

            if (Setting::get($key) !== $value) {
                Setting::set($key, $value);
            }
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function updatedUploads(mixed $file, string $image): void
    {
        abort_unless(array_key_exists($image, Branding::IMAGES), 404);

        $this->validate(["uploads.{$image}" => ['image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        try {
            $path = Branding::storeImage($this->uploads[$image], 'branding');
        } catch (ValidationException $exception) {
            $this->addError("uploads.{$image}", $exception->getMessage());

            return;
        }

        Setting::set(Branding::IMAGES[$image], $path);
        $this->uploads[$image] = null;
        Flux::toast(variant: 'success', text: __('Image saved.'));
    }

    public function removeImage(string $image): void
    {
        abort_unless(array_key_exists($image, Branding::IMAGES), 404);

        // The file stays on disk (old PDFs may be explained by it); the setting is cleared.
        Setting::set(Branding::IMAGES[$image], null);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Identity')" :subtitle="__('Details, logos and the name in Arabic calligraphy of the institution, as shown in SIAKAD and on every printed document.')">
        <x-slot:actions>
            <flux:button icon="eye" :href="route('superadmin.identity.preview', ['document' => PrintDocument::Transcript->value])" target="_blank">{{ __('Preview letterhead') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Institution') }}</flux:legend>
            <div class="space-y-6">
                <div class="grid gap-6 sm:grid-cols-3">
                    <flux:input wire:model="institutionName" :label="__('Name')" class="sm:col-span-2" required />
                    <flux:input wire:model="shortName" :label="__('Short name')" placeholder="MA Contoh" />
                </div>
                <flux:input wire:model="foundation" :label="__('Foundation (yayasan)')" />
                <flux:input wire:model="licence" :label="__('Operating licence (SK)')" :placeholder="__('e.g. SK Dirjen Pendis No. …')" />
                <flux:textarea wire:model="address" :label="__('Address')" rows="2" />
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="city" :label="__('City')" :description:trailing="__('Printed before the date in signature blocks.')" />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <flux:input wire:model="email" :label="__('Email')" type="email" />
                    <flux:input wire:model="website" :label="__('Website')" />
                </div>
                <flux:input wire:model="head" :label="__('Head of the institution (Mudir)')" class="sm:max-w-md" />
            </div>
        </flux:fieldset>

        <flux:button variant="primary" type="submit" data-test="save-identity">{{ __('Save') }}</flux:button>
    </form>

    <flux:separator class="my-8" />

    <flux:heading size="lg">{{ __('Images') }}</flux:heading>
    <flux:text class="mt-1 mb-4">{{ __('PNG with a transparent background looks best; JPG and WebP are accepted. Large images are scaled down.') }}</flux:text>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($this->images() as $image => $info)
            @php($preview = Branding::dataUri(Branding::imagePath($image)))
            <div wire:key="image-{{ $image }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="font-medium">{{ $info['label'] }}</div>
                <div class="flex h-24 items-center justify-center rounded-md bg-zinc-50 p-2 dark:bg-white">
                    @if ($preview)
                        <img src="{{ $preview }}" alt="{{ $info['label'] }}" class="max-h-20 max-w-full object-contain">
                    @else
                        <span class="text-xs text-zinc-500">{{ __('Not set') }}</span>
                    @endif
                </div>
                <flux:text class="text-xs">{{ $info['hint'] }}</flux:text>
                <div class="mt-auto flex items-center gap-2">
                    <label class="cursor-pointer">
                        <span class="inline-flex items-center gap-1 rounded-md border border-zinc-200 px-2.5 py-1.5 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-700">
                            <flux:icon.arrow-up-tray class="size-4" /> {{ $preview ? __('Replace') : __('Upload') }}
                        </span>
                        <input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp" wire:model="uploads.{{ $image }}" data-test="upload-{{ $image }}">
                    </label>
                    @if ($preview)
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeImage('{{ $image }}')" wire:confirm="{{ __('Remove this image?') }}" :aria-label="__('Remove')" />
                    @endif
                    <span wire:loading wire:target="uploads.{{ $image }}" class="text-xs text-zinc-500">{{ __('Uploading…') }}</span>
                </div>
                <flux:error name="uploads.{{ $image }}" />
            </div>
        @endforeach
    </div>
</section>
