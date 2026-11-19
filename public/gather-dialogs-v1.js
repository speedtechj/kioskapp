// The original Livewire listener runs only after the user confirms.
const modal = document.getElementById('gather-confirm');
if (modal) {
    const title = modal.querySelector('[data-title]');
    const context = modal.querySelector('[data-context]');
    const message = modal.querySelector('[data-message]');
    const back = modal.querySelector('.gd-back');
    const commit = modal.querySelector('.gd-commit');
    let pending = null;
    let replay = null;

    function intercept(event) {
        const source = event.type === 'submit'
            ? event.target.closest('form[data-gather-title]')
            : event.target.closest('button[data-gather-title]');
        if (!source || replay === source) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (modal.open) return;
        pending = { source, submitter: event.submitter, type: event.type, signature: signature(source) };
        title.textContent = source.dataset.gatherTitle;
        context.textContent = source.dataset.context || '';
        context.hidden = !context.textContent;
        message.textContent = source.dataset.message;
        back.textContent = source.dataset.back || 'Go back';
        commit.textContent = source.dataset.commit;
        modal.showModal();
        back.focus();
    }
    function signature(source) {
        return [source.dataset.context, source.dataset.message, source.getAttribute('wire:click')].join('|');
    }
    document.addEventListener('click', intercept, true);
    document.addEventListener('submit', intercept, true);
    back.addEventListener('click', () => modal.close());
    modal.addEventListener('close', () => { pending = null; });
    commit.addEventListener('click', () => {
        const action = pending;
        pending = null;
        modal.close();
        if (!action || !action.source.isConnected || signature(action.source) !== action.signature) return;
        replay = action.source;
        try {
            if (action.type === 'submit') action.source.requestSubmit(action.submitter || undefined);
            else action.source.click();
        } finally { replay = null; }
    });
}
