<?php

use App\Actions\Superadmin\ManageSystem;
use App\Enums\PrintDocument;
use App\Models\Setting;
use App\Livewire\BrandImagesPage;
use Flux\Flux;
use Livewire\Attributes\Title;

new #[Title('Identity')] class extends BrandImagesPage {

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
     * The institution's images, printed on documents. The app's own logo is on Tampilan aplikasi (v6 D36).
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public function images(): array
    {
        return [
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

        app(ManageSystem::class)->save(ManageSystem::IDENTITY, [
            'name' => $this->institutionName,
            'short_name' => $this->shortName,
            'foundation' => $this->foundation,
            'address' => $this->address,
            'city' => $this->city,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'licence' => $this->licence,
            'head' => $this->head,
        ]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('Identity')" :subtitle="__('Details, logo and the name in Arabic calligraphy of the institution, printed on every document (KHS, letters, receipts).')">
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

    <x-brand-images :images="$this->images()" columns="sm:grid-cols-2" />

    <flux:text class="mt-6 text-sm">
        {{ __('The app name, logo and icon, and the login page are on') }}
        <flux:link :href="route('superadmin.appearance')" wire:navigate>{{ __('App appearance') }}</flux:link>.
    </flux:text>
</section>
