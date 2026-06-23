// Get base path from service worker location
function getBasePath() {
  const swUrl = new URL(self.location.href);
  return swUrl.pathname.substring(0, swUrl.pathname.lastIndexOf('/') + 1);
}

const BASE_PATH = getBasePath();
const CACHE_NAME = 'network-events-v5'; // Incremented version to bust cache

// Dynamically generate asset paths based on BASE_PATH
const ASSETS_TO_CACHE = [
  BASE_PATH,
  BASE_PATH + 'assets/css/all.min.css',
  BASE_PATH + 'assets/css/bootstrap.min.css',
  BASE_PATH + 'assets/css/style.css',
  BASE_PATH + 'assets/js/bootstrap.bundle.min.js',
  BASE_PATH + 'assets/js/script.js',
  BASE_PATH + 'assets/icons/icon-192.png',
  BASE_PATH + 'assets/icons/icon-512.png',
  BASE_PATH + 'manifest.php',
  BASE_PATH + 'assets/js/chart.js'
];

self.addEventListener('install', event => {
  console.log('[SW] Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('[SW] Caching app shell');
        return Promise.allSettled(
          ASSETS_TO_CACHE.map(url =>
            cache.add(url).catch(err => {
              console.warn('[SW] Failed to cache:', url, err);
            })
          )
        );
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  console.log('[SW] Activating...');
  const cacheWhitelist = [CACHE_NAME];
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (!cacheWhitelist.includes(cacheName)) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  
  // Don't cache API calls
  if (url.pathname.includes('/api/')) {
    return;
  }

  // Determine if the request is for a static asset
  const isAsset = url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|eot|ico)$/i);

  if (isAsset) {
    // Cache First, then Network for assets
    event.respondWith(
      caches.match(event.request).then(response => {
        return response || fetch(event.request).then(networkResponse => {
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseToCache));
          }
          return networkResponse;
        });
      })
    );
  } else {
    // Network First, then Cache for HTML and dynamic requests (e.g. .php or extensionless)
    event.respondWith(
      fetch(event.request).then(networkResponse => {
        // Only cache valid 200 responses. Do not cache redirects or errors.
        if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic' && !networkResponse.redirected) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseToCache));
        }
        return networkResponse;
      }).catch(() => {
        console.log('[SW] Network error, fallback to cache for:', event.request.url);
        return caches.match(event.request).then(response => {
          if (response) return response;
          // Offline fallback
          if (event.request.mode === 'navigate') {
            return caches.match(BASE_PATH);
          }
        });
      })
    );
  }
});
