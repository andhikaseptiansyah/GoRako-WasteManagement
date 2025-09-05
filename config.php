<?php
// config.php

// Database connection details (dapat diatur di sini atau di db_connection.php)
// Untuk konsistensi, kita akan definisikan CURRENT_USER_ID di sini
// dan biarkan detail koneksi di db_connection.php

// Pastikan session sudah dimulai sebelum mengakses $_SESSION
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Gunakan ID pengguna dari sesi jika tersedia.
// Jika tidak ada user ID dalam sesi (misalnya, belum login),
// atur ke nilai default (misal: 1) atau arahkan ke halaman login jika diperlukan.
// Dalam aplikasi nyata, Anda HARUS memastikan pengguna terautentikasi.
define('CURRENT_USER_ID', $_SESSION['user_id'] ?? 1); //

// Ini bisa juga berisi konstanta GameConfig jika Anda ingin memisahkannya
// dari game_data.php, tapi untuk saat ini biarkan game_data.php yang menanganinya.

?>