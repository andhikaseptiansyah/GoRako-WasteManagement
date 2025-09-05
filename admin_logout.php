<?php
// Pastikan sesi dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'helpers.php'; // Pastikan helpers.php ada untuk fungsi redirect()

// Hapus semua variabel sesi
$_SESSION = array();

// Hancurkan sesi.
// Ini akan menghancurkan cookie sesi juga.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Terakhir, hancurkan sesi
session_destroy();

// Opsional: Set flash message sebelum redirect (gunakan helpers.php)
set_flash_message('success', 'Anda telah berhasil logout dari dashboard admin.');

// Redirect ke halaman login admin
redirect('admin_login.php');
exit();
?>