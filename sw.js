// Minimal service worker: makes the site installable. Never caches pages
// (they contain private business data); it only passes requests through.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  e.respondWith(
    fetch(e.request).catch(() => new Response(
      '<h2 style="font-family:sans-serif;text-align:center;margin-top:30vh">No internet connection</h2>',
      { headers: { 'Content-Type': 'text/html' } }
    ))
  );
});
