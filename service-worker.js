// service-worker.js

// Event 'push' akan di-trigger ketika notifikasi push diterima dari server
self.addEventListener('push', function(event) {
    // Mendapatkan data dari notifikasi push
    const data = event.data.json();
    console.log('Push received:', data);

    // Menentukan judul dan opsi notifikasi
    const title = data.title || 'EcoPoint Notification';
    const options = {
        body: data.body || 'Anda memiliki pembaruan baru dari EcoPoint!',
        icon: 'https://via.placeholder.com/48x48?text=EP' // Icon untuk notifikasi
    };

    // Menampilkan notifikasi. event.waitUntil memastikan Service Worker tetap aktif
    // sampai notifikasi ditampilkan
    event.waitUntil(self.registration.showNotification(title, options));
});

// Event 'notificationclick' akan di-trigger ketika pengguna mengklik notifikasi
self.addEventListener('notificationclick', function(event) {
    console.log('[Service Worker] Notification click received.');
    event.notification.close(); // Menutup notifikasi setelah diklik

    // Opsional: Buka jendela/tab baru atau fokus pada yang sudah ada
    // event.waitUntil memastikan Service Worker tetap aktif
    event.waitUntil(
        clients.openWindow('/') // Mengarahkan pengguna ke halaman utama atau URL tertentu
    );
});

// Event 'install' di-trigger saat Service Worker pertama kali diinstal
self.addEventListener('install', function(event) {
    console.log('[Service Worker] Installing Service Worker ...', event);
    self.skipWaiting(); // Memaksa Service Worker baru untuk langsung aktif
});

// Event 'activate' di-trigger saat Service Worker diaktifkan
self.addEventListener('activate', function(event) {
    console.log('[Service Worker] Activating Service Worker ...', event);
    // claims all clients in the scope of this service worker
    event.waitUntil(clients.claim());
});