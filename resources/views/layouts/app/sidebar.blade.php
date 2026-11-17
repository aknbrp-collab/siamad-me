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

            {{-- Only the menu scrolls; the logo stays at the top and the theme switch and user menu at the bottom (v7 D45). --}}
            <div class="sidebar-scroll -mx-4 min-h-0 flex-1 overflow-y-auto overscroll-contain px-4" data-test="sidebar-menu">
            @if ($activeRole)
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="bell" :href="route('notifications')" :current="request()->routeIs('notifications')"
                        :badge="$unreadNotifications ?: null" wire:navigate data-test="notifications-link">
                        {{ __('Notifications') }}
                    </flux:sidebar.item>
                    {{-- Counts named by navigation items (`badge`), worked out only when an item asks. --}}
                    @php($badge = fn (string $name) => match ($name) {
                        'bimbingan' => array_sum(app(\App\Actions\Discussions\ManageDiscussions::class)->unread(auth()->user(), \App\Enums\DiscussionKind::Bimbingan)),
                        'lpppk' => array_sum(app(\App\Actions\Discussions\ManageDiscussions::class)->unreadLpppk(auth()->user())),
                        'feedback' => \App\Actions\Feedback\ManageFeedback::newCount(),
                        'evaluation' => auth()->user()->student ? app(\App\Actions\Evaluations\ManageEvaluations::class)->pendingCount(auth()->user()->student) : 0,
                        default => 0,
                    })
                    @foreach ($activeRole->navigation() as $group)
                        <flux:sidebar.group :heading="$group['heading']" class="grid">
                            @foreach ($group['items'] as $item)
                                <flux:sidebar.item
                                    :icon="$item['icon']"
                                    :href="route($item['route'])"
                                    :current="request()->routeIs(...explode('|', $item['match']))"
                                    :badge="$item['badge'] ? ($badge($item['badge']) ?: null) : null"
                                    wire:navigate
                                >
                                    {{ $item['label'] }}
                                </flux:sidebar.item>
                            @endforeach
                        </flux:sidebar.group>
                    @endforeach
                </flux:sidebar.nav>
            @endif
            </div>

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

        <x-confirm-dialog />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
