(() => {
    const dialog = document.getElementById('cashier-install');
    if (!dialog) return;

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const android = /Android/i.test(navigator.userAgent);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const confirmation = dialog.querySelector('[data-install-confirm]');
    const fallback = dialog.querySelector('[data-install-fallback]');
    let installPrompt = null;
    const storageKey = `cashier-install-dismissed:${dialog.dataset.kiosk}`;

    function updateInstructions() {
        dialog.querySelector('[data-install-ios]').hidden = !ios;
        confirmation.hidden = !installPrompt || ios;
        fallback.hidden = ios || Boolean(installPrompt);
    }

    function dismiss() {
        try { localStorage.setItem(storageKey, '1'); } catch {}
    }

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
        updateInstructions();
    });

    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        document.documentElement.classList.remove('cashier-install-available');
        if (dialog.open) dialog.close();
    });

    if (standalone) return;
    document.documentElement.classList.add('cashier-install-available');
    updateInstructions();
    document.addEventListener('click', event => {
        if (event.target.closest('[data-cashier-install]') && !dialog.open) dialog.showModal();
        if (event.target.closest('[data-install-close]') && dialog.open) dialog.close();
    });
    dialog.addEventListener('close', dismiss);

    confirmation.addEventListener('click', async () => {
        if (!installPrompt) return;
        const prompt = installPrompt;
        installPrompt = null;
        updateInstructions();
        try {
            await prompt.prompt();
            if ((await prompt.userChoice).outcome === 'accepted' && dialog.open) dialog.close();
        } catch {
            updateInstructions();
        }
    });

    let seen = false;
    try { seen = localStorage.getItem(storageKey) === '1'; } catch {}
    if (dialog.hasAttribute('data-auto-show') && (ios || android) && !seen) dialog.showModal();
})();
