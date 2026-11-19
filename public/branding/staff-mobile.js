if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/sw.js').catch(error => {
        console.warn('Gather service worker registration failed', error);
    });
}
