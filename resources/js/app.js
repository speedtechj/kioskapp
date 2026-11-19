// Livewire provides Alpine. Do not load a second Alpine instance.
if ('serviceWorker' in navigator && (location.pathname.startsWith('/menu/') || location.pathname.startsWith('/takeout/'))) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
