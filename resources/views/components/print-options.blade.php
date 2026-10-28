{{-- The print dialog for staff, opened by <x-print-button>. One per page (in the app layout). --}}
@php
    $signatories = \App\Models\Signatory::query()->where('is_active', true)->orderBy('name')->get();
    $stamps = \App\Models\Stamp::query()->where('is_active', true)->orderBy('name')->get();
    $defaults = collect(\App\Enums\PrintDocument::cases())->mapWithKeys(function ($document) {
        $marks = \App\Support\PrintMarks::defaults($document);

        return [$document->value => [
            'signatory' => (string) ($marks->signatory?->id ?? ''),
            'stamp' => (string) ($marks->stamp?->id ?? ''),
            'marks' => $marks->images ? 'with' : 'without',
            'label' => $document->label(),
        ]];
    });
@endphp

<div x-data="{
        url: '',
        label: '',
        marks: 'with',
        signatory: '',
        stamp: '',
        defaults: @js($defaults),
        open(detail) {
            const preset = { ...(this.defaults[detail.document] ?? { signatory: '', stamp: '', marks: 'with', label: '' }) };
            if (detail.preset) {
                preset.signatory = String(detail.preset.signatory ?? '');
                preset.stamp = String(detail.preset.stamp ?? '');
                preset.marks = detail.preset.marks ? 'with' : 'without';
            }
            this.url = detail.url;
            this.label = preset.label;
            this.marks = preset.marks;
            this.signatory = preset.signatory;
            this.stamp = preset.stamp;
            $flux.modal('print-options').show();
        },
        print() {
            const target = new URL(this.url, window.location.origin);
            target.searchParams.set('marks', this.marks === 'with' ? 1 : 0);
            target.searchParams.set('signatory', this.signatory || 0);
            target.searchParams.set('stamp', this.stamp || 0);
            window.open(target.toString(), '_blank');
            $flux.modal('print-options').close();
        },
    }" x-on:print-options.window="open($event.detail)">
    <flux:modal name="print-options" class="w-full max-w-md">
        <form x-on:submit.prevent="print" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Print') }}</flux:heading>
                <flux:text class="mt-1" x-text="label"></flux:text>
            </div>

            <flux:radio.group x-model="marks" :label="__('Signature and stamp')">
                <flux:radio value="with" :label="__('With signature and stamp images')" />
                <flux:radio value="without" :label="__('Without (sign and stamp by hand)')" />
            </flux:radio.group>

            <flux:select x-model="signatory" :label="__('Signed by')">
                <flux:select.option value="">{{ __('— blank signature line —') }}</flux:select.option>
                @foreach ($signatories as $signatory)
                    <flux:select.option :value="$signatory->id">{{ $signatory->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div x-show="marks === 'with'">
                <flux:select x-model="stamp" :label="__('Stamp')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($stamps as $stamp)
                        <flux:select.option :value="$stamp->id">{{ $stamp->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="printer" data-test="print-confirm">{{ __('Print') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
