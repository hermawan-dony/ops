// Service Worker for framas Transport PWA
self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(self.clients.claim());
});

// A basic fetch handler is required for Chrome's "Add to Home Screen" prompt
self.addEventListener('fetch', (e) => {
  e.respondWith(fetch(e.request));
});
