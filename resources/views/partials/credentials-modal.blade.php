{{-- Requires the ManagesAccount trait on the component. --}}
<flux:modal name="credentials" class="w-full max-w-md" wire:close="forgetCredentials" :dismissible="false">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Login credentials') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Give these to the user. The temporary password is shown only now; they must change it at first login.') }}</flux:text>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
            <dt class="text-sm text-zinc-500">{{ __('Name') }}</dt>
            <dd class="font-medium">{{ $credentialName }}</dd>
            <dt class="text-sm text-zinc-500">{{ __('Login ID') }}</dt>
            <dd class="font-mono font-medium" data-test="credential-username">{{ $credentialUsername }}</dd>
            <dt class="text-sm text-zinc-500">{{ __('Temporary password') }}</dt>
            <dd class="font-mono text-lg font-semibold tracking-wider" data-test="credential-password">{{ $credentialPassword }}</dd>
        </dl>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:button icon="arrow-down-tray" wire:click="downloadCredentials">{{ __('Download') }}</flux:button>
            <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
        </div>
    </div>
</flux:modal>
