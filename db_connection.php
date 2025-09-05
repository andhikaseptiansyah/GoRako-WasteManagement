<?php
// db_connection.php

// Pastikan sesi dimulai HANYA SEKALI di sini.
// Ini harus menjadi baris PHP pertama di file yang paling awal dimuat (e.g., db_connection.php atau index.php).
// Fungsi session_status() menghindari error jika sudah dimulai di tempat lain.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection details
$servername = "localhost";
$username = "root";
$password = ""; // Umumnya kosong di XAMPP, jika ada password, masukkan di sini
$dbname = "gorako"; // Pastikan ini sesuai dengan nama database Anda

// Nonaktifkan pelaporan error langsung ke output HTML untuk produksi
// Aktifkan logging error ke file
ini_set('display_errors', 0); // Matikan tampilan error di browser
ini_set('log_errors', 1);     // Aktifkan logging error ke file

// Tentukan lokasi log error PHP. Pastikan direktori ini writable oleh user web server.
// Gunakan __DIR__ untuk path absolut yang lebih robust
$error_log_path = __DIR__ . '/php_error.log'; //
ini_set('error_log', $error_log_path); //

// Buat koneksi global
// Gunakan variabel global $conn yang akan di-include di file lain
$conn = new mysqli($servername, $username, $password, $dbname); //

// Cek koneksi
if ($conn->connect_error) {
    // Log error ke file untuk debugging backend
    error_log("Koneksi database gagal: " . $conn->connect_error); //

    // Tampilkan pesan kesalahan fatal yang jelas di browser
    die("
    <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; max-width: 800px; margin: 50px auto; text-align: left;'>
        <h2 style='color: #721c24; margin-top: 0;'>&#x26A0; Kesalahan Koneksi Database Fatal &#x26A0;</h2>
        <p>Aplikasi tidak dapat terhubung ke database MySQL. Ini adalah masalah kritis yang harus segera diperbaiki.</p>
        <p>Silakan periksa langkah-langkah berikut:</p>
        <ol>
            <li><strong>Pastikan MySQL Berjalan:</strong> Buka <strong>XAMPP Control Panel</strong> Anda dan pastikan modul <strong>MySQL</strong> memiliki status <strong style='color: green;'>Running</strong>. Jika tidak, coba mulai.</li>
            <li><strong>Verifikasi Nama Database:</strong> Pastikan database dengan nama '<strong><code>" . htmlspecialchars($dbname) . "</code></strong>' sudah dibuat di phpMyAdmin Anda (biasanya di <code>http://localhost/phpmyadmin/</code>). Pastikan tidak ada typo.</li>
            <li><strong>Periksa Kredensial:</strong>
                <ul>
                    <li><strong>Server:</strong> <code>" . htmlspecialchars($servername) . "</code> (biasanya 'localhost' atau '127.0.0.1')</li>
                    <li><strong>Username:</strong> <code>" . htmlspecialchars($username) . "</code> (biasanya 'root')</li>
                    <li><strong>Password:</strong> " . ($password ? '<code>*****</code> (pastikan sesuai)' : '<code>[kosong]</code>') . "</li>
                </ul>
            </li>
            <li><strong>Cek Log Error PHP:</strong> Untuk detail teknis lebih lanjut, periksa file log di jalur berikut:<br><code>" . htmlspecialchars($error_log_path) . "</code></li>
        </ol>
        <p style='font-size: 0.9em; margin-top: 20px;'><strong>Detail Error Teknis:</strong><br><code>" . htmlspecialchars($conn->connect_error) . "</code></p>
    </div>
    "); //
} else {
    // Jika koneksi berhasil, atur charset untuk menghindari masalah encoding
    $conn->set_charset("utf8mb4"); //
}

/**
 * Mendapatkan objek koneksi database baru.
 * Berguna jika Anda membutuhkan koneksi terpisah dalam skenario tertentu,
 * meskipun sebagian besar skrip akan menggunakan $conn global.
 * @return mysqli|null Mengembalikan objek mysqli jika berhasil, null jika gagal.
 */
function getDBConnection() {
    global $servername, $username, $password, $dbname; // Pastikan ini juga global
    $new_conn = new mysqli($servername, $username, $password, $dbname); //

    if ($new_conn->connect_error) {
        error_log("Failed to get new DB connection: " . $new_conn->connect_error .
                  " (Server: $servername, DB: $dbname)"); //
        return null;
    }
    $new_conn->set_charset("utf8mb4"); //
    return $new_conn; //
}
?>