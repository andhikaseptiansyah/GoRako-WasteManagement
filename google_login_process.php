<?php
// google_login_process.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'vendor/autoload.php'; // Pastikan path ini benar
require_once 'db_connection.php'; // Include koneksi database dan helper functions

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Ambil data POST (ID token)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['id_token'])) { //
    $response['message'] = 'ID token tidak ditemukan.'; //
    echo json_encode($response); //
    exit(); //
}

$id_token = $data['id_token']; //

try {
    $client = new Google\Client(); //
    // Set Client ID Anda di sini (HARUS SAMA dengan yang di frontend login.php)
    // Ganti dengan Client ID Anda dari Google Cloud Console
    $client->setClientId("996281670343-t2nc2sof7o80gloimevl64q4epckek2n.apps.googleusercontent.com");

    // Verifikasi ID token
    $payload = $client->verifyIdToken($id_token);

    if ($payload) { //
        // Token berhasil diverifikasi
        $google_id = $payload['sub']; // ID unik pengguna Google
        $email = $payload['email']; //

        // Prioritaskan nama lengkap, fallback ke email jika nama tidak tersedia
        $username = $payload['name'] ?? $email; // Menggunakan 'name' sebagai username
        
        // Data tambahan jika diperlukan di masa depan (misal: kolom terpisah di DB)
        $first_name = $payload['given_name'] ?? ''; //
        $last_name = $payload['family_name'] ?? ''; //

        // 1. Cek apakah pengguna sudah terdaftar di database kita berdasarkan email atau google_id
        $stmt = $conn->prepare("SELECT id, username, email, google_id FROM users WHERE email = ? OR google_id = ?");
        $stmt->bind_param("ss", $email, $google_id); //
        $stmt->execute(); //
        $result = $stmt->get_result(); //

        if ($result->num_rows > 0) { //
            // Pengguna sudah ada, lakukan login
            $user = $result->fetch_assoc(); //
            $_SESSION['user_id'] = $user['id']; //
            $_SESSION['username'] = $user['username']; // Pastikan menggunakan username dari DB jika sudah ada
            
            // Regenerate session ID untuk keamanan
            session_regenerate_id(true); //

            // Update last_login dan google_id jika belum ada (misal, user daftar manual lalu login via Google)
            // Ini akan memperbarui record yang ada dengan google_id
            if (empty($user['google_id'])) {
                $conn->query("UPDATE users SET google_id = '$google_id', last_login = NOW() WHERE id = " . $user['id']);
            } else {
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']); //
            }

            $response['success'] = true; //
            $response['message'] = 'Login berhasil.'; //
        } else {
            // Pengguna baru, lakukan registrasi
            // Jika user hanya login via Google, password di database bisa diisi string acak
            // Password ini tidak akan digunakan untuk login password-based, hanya sebagai placeholder.
            $hashed_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); // Password acak tidak terpakai

            $stmt_insert = $conn->prepare("INSERT INTO users (username, email, google_id, password, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt_insert->bind_param("ssss", $username, $email, $google_id, $hashed_password); //

            if ($stmt_insert->execute()) { //
                $_SESSION['user_id'] = $conn->insert_id; //
                $_SESSION['username'] = $username; //

                // Regenerate session ID untuk keamanan
                session_regenerate_id(true); //

                $response['success'] = true; //
                $response['message'] = 'Registrasi dan login berhasil.'; //
            } else {
                $response['message'] = 'Gagal menyimpan data pengguna: ' . $conn->error; //
                error_log('Google Register Error: ' . $conn->error); // Log error untuk debugging
            }
        }
    } else {
        // Token tidak valid
        $response['message'] = 'ID token Google tidak valid.'; //
        error_log('Google Login Error: Invalid ID token payload. Token: ' . $id_token); // Log token yang gagal
    }
} catch (Exception $e) {
    $response['message'] = 'Kesalahan saat memverifikasi token Google: ' . $e->getMessage(); //
    error_log('Google Login Exception: ' . $e->getMessage() . ' | Token: ' . ($id_token ?? 'N/A')); // Log error dan token
}

echo json_encode($response); //
?>