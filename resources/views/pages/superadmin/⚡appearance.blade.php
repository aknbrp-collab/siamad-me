<?php

use App\Actions\Superadmin\ManageSystem;
use App\Livewire\BrandImagesPage;
use App\Models\Setting;
use App\Support\Branding;
use Flux\Flux;
use Livewire\Attributes\Title;

new #[Title('App appearance')] class extends BrandImagesPage {

    public ?string $appName = null;

    public ?string $shortName = null;

    public ?string $loginTitle = null;

    public ?string $loginText = null;

    /**
     * Setting keys mapped to the component properties holding them (v6 D36).
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'app.name' => 'appName',
        'app.short_name' => 'shortName',
        'app.login_title' => 'loginTitle',
        'app.login_text' => 'loginText',
    ];

    public function mount(): void
    {
        foreach (self::FIELDS as $key => $property) {
            $value = Setting::get($key);
            $this->{$property} = is_string($value) ? $value : null;
        }
    }

    /**
     * @return array<string, array{label: string, hint: string, max?: int, empty?: string}>
     */
    public function images(): array
    {
        return [
            'app_logo' => ['label' => __('App logo'), 'hint' => __('Sidebar and login page. Square works best.'), 'empty' => __('Built-in logo')],
            'app_icon' => ['label' => __('App icon'), 'hint' => __('Browser tab and the home-screen icon of the installed app. Square, at least 512 px. Empty: made from the app logo.'), 'empty' => __('From the app logo')],
            'login_background' => ['label' => __('Login background'), 'hint' => __('A photo behind the login form, e.g. the campus. Wide, at least 1600 px. Empty: plain background.'), 'max' => 2000],
        ];
    }

    public function save(): void
    {
        $this->validate([
            'appName' => ['nullable', 'string', 'max:100'],
            'shortName' => ['nullable', 'string', 'max:20'],
            'loginTitle' => ['nullable', 'string', 'max:100'],
            'loginText' => ['nullable', 'string', 'max:300'],
        ]);

        app(ManageSystem::class)->save(ManageSystem::APPEARANCE, [
            'app_name' => $this->appName,
            'short_name' => $this->shortName,
            'login_title' => $this->loginTitle,
            'login_text' => $this->loginText,
        ]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('App appearance')" :subtitle="__('How SIAKAD itself looks: its name, logo and icon, and the login page. Documents (KHS, letters, receipts) keep using the institution on Identity.')" />

    <div class="grid gap-8 lg:grid-cols-[1fr_18rem]">
        <form wire:submit="save" class="space-y-8">
            <flux:fieldset>
                <flux:legend>{{ __('Name') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-3">
                    <flux:input wire:model="appName" :label="__('App name')" :placeholder="config('siakad.app_name')" class="sm:col-span-2"
                        :description:trailing="__('Browser tab, login page, installed app and emails.')" />
                    <flux:input wire:model="shortName" :label="__('Short name')" :placeholder="Setting::get('institution.short_name') ?: 'SIAKAD'"
                        :description:trailing="__('Sidebar and home screen.')" />
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Login page') }}</flux:legend>
                <div class="space-y-6">
                    <flux:input wire:model="loginTitle" :label="__('Welcome title')" :placeholder="__('Log in to SIAKAD')" />
                    <flux:textarea wire:model="loginText" :label="__('Welcome text')" :placeholder="__('Enter your ID number and password below to log in')" rows="2" />
                </div>
            </flux:fieldset>

            <flux:text class="text-sm">{{ __('Leave a field empty to use the default shown in grey. The theme colours stay green.') }}</flux:text>
            <flux:button variant="primary" type="submit" data-test="save-appearance">{{ __('Save') }}</flux:button>
        </form>

        {{-- How the login page will look (after saving). --}}
        <div>
            <flux:heading size="sm" class="mb-2">{{ __('Login page preview') }}</flux:heading>
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800" data-test="login-preview">
                @if ($background = Branding::loginBackgroundUrl())
                    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ $background }}')"></div>
                @endif
                <div class="relative flex flex-col items-center gap-1 rounded-lg bg-white/95 p-4 text-center shadow dark:bg-zinc-900/95">
                    <x-app-logo-mark size="size-10" />
                    <span class="text-xs text-zinc-500">{{ Branding::appName() }}</span>
                    <span class="mt-2 font-semibold">{{ Branding::loginTitle() }}</span>
                    <span class="text-xs text-zinc-500">{{ Branding::loginText() }}</span>
                </div>
            </div>
        </div>
    </div>

    <flux:separator class="my-8" />

    <flux:heading size="lg">{{ __('Images') }}</flux:heading>
    <flux:text class="mt-1 mb-4">{{ __('PNG with a transparent background looks best; JPG and WebP are accepted. Large images are scaled down.') }}</flux:text>
    <x-brand-images :images="$this->images()" />
</section>
