<?php
// helpers.php

// Pastikan sesi dimulai jika belum dimulai.
// Ini harus menjadi baris PHP pertama di file apapun yang mengandalkan sesi,
// atau setidaknya sebelum output HTML.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Membersihkan dan mensterilkan data input untuk mencegah XSS.
 * Catatan: Untuk mencegah SQL Injection, SELALU gunakan Prepared Statements.
 * @param string|null $data String input yang akan dibersihkan. Menerima null.
 * @return string String yang sudah dibersihkan. Mengembalikan string kosong jika input null.
 */
function clean_input($data) {
    if (!isset($data)) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Memeriksa apakah pengguna saat ini sudah login sebagai user biasa.
 * Diasumsikan 'user_id' diatur dalam sesi setelah login berhasil.
 * @return bool True jika user login (session 'user_id' ada dan tidak kosong), false jika tidak.
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Memeriksa apakah pengguna saat ini adalah admin.
 * Diasumsikan 'admin_id' diatur dalam sesi setelah login admin berhasil.
 * @return bool True jika admin login (session 'admin_id' ada dan tidak kosong), false jika tidak.
 */
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Mengarahkan pengguna ke URL yang ditentukan dan menghentikan eksekusi script.
 * Penting: Fungsi ini harus dipanggil SEBELUM ada output HTML atau spasi apapun ke browser.
 * @param string $url URL untuk dialihkan.
 * @return void Tidak mengembalikan nilai, karena script akan dihentikan.
 */
function redirect($url) {
    $url = filter_var($url, FILTER_SANITIZE_URL);

    if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, '/') !== 0 && strpos($url, './') !== 0 && strpos($url, '../') !== 0 && !preg_match('/^[a-zA-Z0-9_-]+\.php(\?.*)?$/', $url)) {
        error_log("Invalid or unsupported redirect URL format attempted: " . $url);
        $url = '/'; // Default ke homepage atau URL yang aman
    }

    header("Location: " . $url);
    exit();
}

/**
 * Mengatur pesan kilat (flash message) dalam sesi.
 * Pesan ini akan tersedia untuk satu permintaan HTTP berikutnya (setelah redirect atau refresh).
 * @param string $type Jenis pesan (misalnya, 'success', 'error', 'info', 'warning').
 * @param string $message Isi pesan yang akan ditampilkan kepada pengguna.
 * @return void
 */
function set_flash_message($type, $message) {
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

/**
 * Mengambil dan menghapus pesan kilat (flash message) dari sesi.
 * Pesan akan dihapus setelah dibaca untuk memastikan hanya ditampilkan sekali.
 * @return array|null Sebuah array yang berisi 'type' dan 'message', atau null jika tidak ada pesan yang tersedia.
 */
function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Fungsi helper untuk mengirim respons JSON yang konsisten.
 * Berguna untuk API endpoints atau AJAX requests.
 * @param array $data Data PHP yang akan di-encode ke JSON.
 * @param int $statusCode Kode status HTTP (default 200 OK).
 * @return void
 */
function sendJsonResponse($data, $statusCode = 200) {
    if (ob_get_contents()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Menyimpan hasil game ke database dan memperbarui total poin pengguna.
 * Memberikan poin berdasarkan status kemenangan atau kekalahan.
 * @param mysqli $conn Objek koneksi database.
 * @param int $userId ID pengguna.
 * @param int $scoreToSave Skor yang dicapai dalam game.
 * @param string $gameStatus Status game ('won' atau 'lost').
 * @param int $levelPlayed Level game yang dimainkan.
 * @return array Respons status operasi.
 */
function saveGameResult($conn, $userId, $scoreToSave, $gameStatus, $levelPlayed) {
    // Tentukan poin yang akan diberikan berdasarkan status game
    $pointsAwarded = 0;
    $description = '';
    $game_name = "Go Green Hero"; // Nama game yang konsisten

    if ($gameStatus === 'won') {
        $pointsAwarded = $scoreToSave; // Berikan skor aktual untuk kemenangan
        $description = "Game Level {$levelPlayed} Selesai (Menang)";
    } else { // gameStatus adalah 'lost'
        $pointsAwarded = 1; // Berikan 1 poin untuk kekalahan
        $description = "Game Level {$levelPlayed} Selesai (Kalah) - Partisipasi";
    }

    // Mulai transaksi database untuk memastikan atomisitas
    $conn->begin_transaction();

    try {
        // 1. Simpan skor game ke tabel game_scores
        // Pastikan kolom 'level_played', 'score', 'game_status', 'game_name', 'played_at' ada di tabel 'game_scores'
        $stmtGameScores = $conn->prepare("INSERT INTO game_scores (user_id, level_played, score, game_status, game_name, played_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$stmtGameScores) {
            throw new Exception("Gagal menyiapkan pernyataan untuk game_scores: " . $conn->error);
        }
        $stmtGameScores->bind_param("iiiss", $userId, $levelPlayed, $scoreToSave, $gameStatus, $game_name);
        $stmtGameScores->execute();
        if ($stmtGameScores->affected_rows === 0) {
            error_log("Gagal menyisipkan skor game ke game_scores untuk user_id: {$userId}. Error: " . $stmtGameScores->error);
        }
        $stmtGameScores->close();

        // 2. Perbarui total_points pengguna
        $stmtUpdateUser = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
        if (!$stmtUpdateUser) {
            throw new Exception("Gagal menyiapkan pernyataan untuk pembaruan pengguna: " . $conn->error);
        }
        $stmtUpdateUser->bind_param("ii", $pointsAwarded, $userId);
        $stmtUpdateUser->execute();
        if ($stmtUpdateUser->affected_rows === 0) {
            error_log("Gagal memperbarui total poin untuk user_id: {$userId}. Error: " . $stmtUpdateUser->error);
        }
        $stmtUpdateUser->close();

        // 3. Rekam poin di points_history
        // Pastikan kolom 'points_amount', 'description', 'transaction_date' ada di tabel 'points_history'
        $stmtPointsHistory = $conn->prepare("INSERT INTO points_history (user_id, points_amount, description, transaction_date) VALUES (?, ?, ?, NOW())");
        if (!$stmtPointsHistory) {
            throw new Exception("Gagal menyiapkan pernyataan untuk points_history: " . $conn->error);
        }
        $stmtPointsHistory->bind_param("iis", $userId, $pointsAwarded, $description);
        $stmtPointsHistory->execute();
        if ($stmtPointsHistory->affected_rows === 0) {
            error_log("Gagal menyisipkan ke points_history untuk user_id: {$userId}. Error: " . $stmtPointsHistory->error);
        }
        $stmtPointsHistory->close();

        // Commit transaksi jika semua operasi berhasil
        $conn->commit();

        // Set flash message untuk redirect kembali ke halaman utama game_petualangan.php
        $messageType = ($gameStatus === 'won') ? 'success' : 'info';
        set_flash_message($messageType, "Game Level {$levelPlayed} selesai! Kamu " . (($gameStatus === 'won') ? 'menang' : 'kalah') . " dengan skor {$scoreToSave} dan mendapatkan {$pointsAwarded} poin.");

        return ['status' => 'success', 'message' => 'Skor dan poin berhasil disimpan!', 'points_awarded' => $pointsAwarded];
    } catch (Exception $e) {
        // Rollback transaksi jika terjadi kesalahan
        $conn->rollback();
        error_log("Kesalahan dalam saveGameResult untuk UserID {$userId}: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Gagal menyimpan skor dan poin: ' . $e->getMessage()];
    }
}
?>