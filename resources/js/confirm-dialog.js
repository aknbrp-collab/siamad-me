// A styled dialog for every wire:confirm (v7 D46) instead of the browser's confirm() / prompt() box.
// Livewire's own confirm directive sets el.__livewire_confirm; the directive.init hook runs after it, so
// the hook is replaced here for all wire:confirm attributes without touching them.

// Messages that remove, withdraw or end something get the red style (English and Indonesian).
const DANGER = /^(remove|delete|withdraw|sign out|end |hapus|keluarkan|tarik|akhiri|batalkan)/i;

function dialog() {
    return document.getElementById('confirm-dialog');
}

// "Close this group? Its discussion stays readable." → title and explanation.
function split(message) {
    const match = message.match(/^(.+?[?.!])\s+([\s\S]+)$/);

    return match ? [match[1], match[2]] : [message, ''];
}

function ask({ message, expected, danger }) {
    const el = dialog();

    // Without the dialog on the page (another layout), the browser's own box.
    if (!el || typeof el.showModal !== 'function') {
        return Promise.resolve(expected ? window.prompt(message) === expected : window.confirm(message));
    }

    const [title, body] = split(message);
    const ok = el.querySelector('[data-ok]');
    const cancel = el.querySelector('[data-cancel]');
    const field = el.querySelector('[data-prompt]');
    const input = el.querySelector('[data-prompt-input]');
    const hint = el.querySelector('[data-prompt-hint]');

    el.dataset.tone = danger ? 'danger' : 'normal';
    el.querySelector('[data-title]').textContent = title;
    el.querySelector('[data-body]').textContent = body;
    el.querySelector('[data-body]').hidden = body === '';

    field.hidden = !expected;
    input.value = '';
    ok.disabled = Boolean(expected);
    hint.textContent = expected ? hint.dataset.text.replace(':word', expected) : '';
    input.oninput = () => {
        ok.disabled = input.value.trim() !== expected;
    };

    el.returnValue = '';
    el.showModal();
    // The safe choice has the focus for a risky action; typing for a prompt.
    (expected ? input : danger ? cancel : ok).focus();

    return new Promise((resolve) => {
        const backdrop = (event) => {
            if (event.target === el) {
                el.close('cancel');
            }
        };
        el.addEventListener('click', backdrop);
        el.addEventListener('close', () => {
            el.removeEventListener('click', backdrop);
            resolve(el.returnValue === 'ok' && (!expected || input.value.trim() === expected));
        }, { once: true });
    });
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm') {
            return;
        }

        let message = directive.expression.replaceAll('\\n', '\n') || 'Are you sure?';
        let expected = null;

        if (directive.modifiers.includes('prompt')) {
            [message, expected] = message.split('|');
        }

        const tone = el.dataset.confirmTone;
        const danger = tone ? tone === 'danger' : DANGER.test(message.trim());

        el.__livewire_confirm = (action, instead) => {
            // The click is over before the answer comes: stop its other handlers now, act on "yes".
            instead();
            ask({ message, expected, danger }).then((yes) => {
                if (yes) {
                    action();
                }
            });
        };
    });
});
