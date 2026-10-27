@php
    $activeRole = \App\Support\ActiveRole::get();
    $unreadNotifications = auth()->user()->unreadNotifications()->count();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <div class="brand-bar fixed inset-x-0 top-0 z-40 h-1"></div>
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @if ($activeRole)
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="bell" :href="route('notifications')" :current="request()->routeIs('notifications')"
                        :badge="$unreadNotifications ?: null" wire:navigate data-test="notifications-link">
                        {{ __('Notifications') }}
                    </flux:sidebar.item>
                    @foreach ($activeRole->navigation() as $group)
                        <flux:sidebar.group :heading="$group['heading']" class="grid">
                            @foreach ($group['items'] as $item)
                                <flux:sidebar.item
                                    :icon="$item['icon']"
                                    :href="route($item['route'])"
                                    :current="request()->routeIs(...explode('|', $item['match']))"
                                    wire:navigate
                                >
                                    {{ $item['label'] }}
                                </flux:sidebar.item>
                            @endforeach
                        </flux:sidebar.group>
                    @endforeach
                </flux:sidebar.nav>
            @endif

            <flux:spacer />

            <x-theme-switch class="hidden lg:flex" data-test="theme-switch" />

            <x-desktop-user-menu class="hidden lg:block" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <x-theme-switch class="me-1" />

            <flux:button variant="ghost" :href="route('notifications')" wire:navigate :aria-label="__('Notifications')" class="relative">
                <flux:icon.bell class="size-5" />
                @if ($unreadNotifications)
                    <span class="absolute -end-0.5 -top-0.5 min-w-4 rounded-full bg-red-500 px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                @endif
            </flux:button>

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <x-user-menu-items />
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @if ($activeRole?->isStaff())
            <x-print-options />
        @endif

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
