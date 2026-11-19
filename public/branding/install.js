(() => {
    const sheet = document.querySelector('#brand-install');
    if (!sheet) return;

    const ios = /iP(ad|hone|od)/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const android = /Android/i.test(navigator.userAgent);
    const standalone = () => navigator.standalone || matchMedia('(display-mode: standalone)').matches;
    if ((!ios && !android) || standalone()) return;

    const staff = sheet.hasAttribute('data-staff');
    const title = sheet.querySelector('#install-title');
    const install = sheet.querySelector('#do-install');
    const skip = sheet.querySelector('#skip-install');
    const launchers = document.querySelectorAll(staff ? '[data-staff-install]' : '#install-reopen');
    let promptEvent = null;
    let installed = false;

    function refresh() {
        title.textContent = ios ? 'Add to Home Screen' : title.dataset.androidTitle;
        sheet.querySelector('#ios-help').hidden = !ios;
        sheet.querySelector('#android-help').hidden = !android;
        sheet.querySelector('#android-fallback').hidden = !android;
        install.hidden = !android || !promptEvent;
        skip.classList.toggle('primary', install.hidden);
    }

    function show() {
        if (sheet.open || installed || standalone()) return;
        refresh();
        sheet.showModal();
    }

    window.addEventListener('beforeinstallprompt', event => {
        if (!android) return;
        event.preventDefault();
        promptEvent = event;
        refresh();
    });
    install.addEventListener('click', async () => {
        const event = promptEvent;
        if (!event) return;
        promptEvent = null;
        refresh();
        try {
            await event.prompt();
            if ((await event.userChoice).outcome === 'accepted' && sheet.open) sheet.close();
        } catch {
            // The browser-menu instructions remain visible if the prompt fails.
        }
    });
    window.addEventListener('appinstalled', () => {
        installed = true;
        if (sheet.open) sheet.close();
        launchers.forEach(button => { button.hidden = true; });
    });
    sheet.querySelectorAll('[data-close-install]').forEach(button => {
        button.addEventListener('click', () => sheet.close());
    });
    launchers.forEach(button => {
        button.hidden = false;
        button.addEventListener('click', show);
    });
    if (staff) return;

    const key = 'gather-install-seen-' + sheet.dataset.key;
    try {
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, '1');
    } catch {
        // Storage can be unavailable in an embedded browser.
    }
    show();
})();
