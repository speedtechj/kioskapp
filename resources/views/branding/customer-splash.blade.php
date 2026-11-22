<style>
    .customer-splash[hidden] { display: none; }

    .customer-splash {
        position: fixed;
        z-index: 60;
        inset: 0;
        display: grid;
        place-items: center;
        background: #fff6e6;
        color: #123e3d;
        pointer-events: none;
        animation: customer-splash-exit 450ms ease-out both;
    }

    .customer-splash-brand {
        position: relative;
        display: grid;
        justify-items: center;
        gap: 12px;
        padding-inline: 18px;
        text-align: center;
        font-size: clamp(1.35rem, 6vw, 1.85rem);
        font-weight: 800;
        animation: customer-splash-enter 160ms ease-out both;
    }

    .customer-splash-brand img { border-radius: 24%; }

    .customer-splash-steam {
        position: absolute;
        top: -18px;
        left: calc(50% - 12px);
        height: 24px;
        width: 9px;
        border-left: 3px solid #d96516;
        border-radius: 75%;
        animation: customer-splash-steam 300ms ease-out both;
    }

    .customer-splash-steam + .customer-splash-steam {
        left: calc(50% + 5px);
        animation-delay: 45ms;
    }

    .customer-splash.is-ready { animation: customer-splash-finish 120ms ease-out both; }

    @keyframes customer-splash-exit {
        0%, 68% { opacity: 1; }
        to { opacity: 0; visibility: hidden; }
    }

    @keyframes customer-splash-enter {
        from { opacity: .5; transform: translateY(4px); }
        to { opacity: 1; transform: none; }
    }

    @keyframes customer-splash-steam {
        from { opacity: 0; transform: translateY(8px) rotate(12deg); }
        45% { opacity: .85; }
        to { opacity: 0; transform: translateY(-8px) rotate(-12deg); }
    }

    @keyframes customer-splash-finish {
        to { opacity: 0; visibility: hidden; }
    }

    @media (prefers-reduced-motion: reduce) {
        .customer-splash { display: none; }
    }
</style>
<div class="customer-splash" data-customer-splash data-kiosk="{{ $kiosk->getKey() }}" hidden aria-hidden="true">
    <div class="customer-splash-brand">
        <img data-icon="{{ $kiosk->appIcon(192) }}" width="96" height="96" decoding="async" alt="">
        <i class="customer-splash-steam"></i>
        <i class="customer-splash-steam"></i>
        <span>{{ $kiosk->appLabel() }}</span>
    </div>
</div>
<script>
    (() => {
        const splash = document.querySelector('[data-customer-splash]');
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        const mobile = window.matchMedia('(pointer: coarse)').matches;

        if (!standalone || !mobile || window.matchMedia('(prefers-reduced-motion: reduce)').matches
            || performance.getEntriesByType('navigation')[0]?.type === 'back_forward') {
            return;
        }

        const key = 'customer-splash:' + splash.dataset.kiosk;
        try {
            if (sessionStorage.getItem(key)) return;
            sessionStorage.setItem(key, 'shown');
        } catch {
            return;
        }

        const icon = splash.querySelector('img');
        icon.src = icon.dataset.icon;
        splash.addEventListener('animationend', event => {
            if (event.target === splash) splash.remove();
        });
        document.addEventListener('DOMContentLoaded', () => {
            splash.classList.add('is-ready');
        }, { once: true });
        window.addEventListener('pagehide', () => splash.remove(), { once: true });
        splash.hidden = false;
    })();
</script>
