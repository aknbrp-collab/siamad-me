// Stop a file larger than the upload limit before Livewire sends it: the
// server (PHP) would refuse it with an unclear "failed to upload" message.
const meta = document.querySelector('meta[name="upload-max-kb"]');

if (meta) {
    window.addEventListener('change', (event) => {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
            return;
        }

        // data-max-kb on the input: a larger limit for that field only (a backup to restore).
        const maxBytes = Number(input.dataset.maxKb || meta.content) * 1024;

        if (!Array.from(input.files ?? []).some((file) => file.size > maxBytes)) {
            return;
        }

        // Capture phase on window: Livewire's own listener on the input never runs.
        event.stopImmediatePropagation();
        input.value = '';

        if (window.Flux?.toast) {
            window.Flux.toast({ variant: 'danger', text: meta.dataset.message });
        } else {
            window.alert(meta.dataset.message);
        }
    }, true);
}

// A file that did not reach the server (the network, or PHP refusing it: v7 D42) used to leave the
// screen unchanged; say so, also on screens whose error line sits elsewhere.
if (meta) {
    window.addEventListener('livewire-upload-error', () => {
        if (window.Flux?.toast) {
            window.Flux.toast({ variant: 'danger', text: meta.dataset.failed });
        }
    });
}
