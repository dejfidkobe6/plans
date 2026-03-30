// BeSix Plans – Service Worker
// Strategie: cache-first pro statické assety, network-first pro API
const CACHE = 'besix-plans-v1';

const PRECACHE = [
  './',
  './index.html',
  './besix_logo_bila.png',
  // CDN knihovny – fabric, three.js, pdf.js, jspdf
  'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js',
  'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js',
  'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js',
  'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
  // Google Fonts CSS (font soubory se cachují automaticky při prvním načtení)
  'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Barlow+Condensed:wght@300;400;500;600;700&display=swap',
];

// ── Install: předcachovat klíčové zdroje ──────────────────────
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then(cache =>
      Promise.allSettled(PRECACHE.map(url =>
        cache.add(url).catch(() => { /* CDN může selhat offline – nevadí */ })
      ))
    ).then(() => self.skipWaiting())
  );
});

// ── Activate: smazat staré cache verze ────────────────────────
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: obsluha požadavků ───────────────────────────────────
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // API volání → network-first, offline = chybová odpověď
  if (url.pathname.startsWith('/api/') || url.pathname.includes('api/')) {
    e.respondWith(
      fetch(e.request).catch(() =>
        new Response(
          JSON.stringify({ error: 'Jste offline. Akce bude dostupná po obnovení připojení.' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        )
      )
    );
    return;
  }

  // uploads/ obrázky → cache-first (výkresy uložené na serveru)
  if (url.pathname.startsWith('/uploads/') || url.pathname.includes('/uploads/')) {
    e.respondWith(
      caches.open(CACHE).then(cache =>
        cache.match(e.request).then(cached => {
          if (cached) return cached;
          return fetch(e.request).then(resp => {
            if (resp.ok) cache.put(e.request, resp.clone());
            return resp;
          }).catch(() => new Response('', { status: 503 }));
        })
      )
    );
    return;
  }

  // CDN zdroje a fonty → cache-first
  if (url.origin !== location.origin) {
    e.respondWith(
      caches.match(e.request).then(cached => {
        if (cached) return cached;
        return fetch(e.request).then(resp => {
          if (resp.ok) {
            caches.open(CACHE).then(c => c.put(e.request, resp.clone()));
          }
          return resp;
        }).catch(() => new Response('', { status: 503 }));
      })
    );
    return;
  }

  // index.html + lokální soubory → network-first, fallback na cache
  e.respondWith(
    fetch(e.request).then(resp => {
      if (resp.ok) {
        caches.open(CACHE).then(c => c.put(e.request, resp.clone()));
      }
      return resp;
    }).catch(() =>
      caches.match(e.request).then(cached =>
        cached || new Response('Offline – načtěte stránku po obnovení připojení.', {
          status: 503,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        })
      )
    )
  );
});
