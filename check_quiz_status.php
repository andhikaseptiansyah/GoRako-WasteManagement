<?php
// check_quiz_status.php
session_start(); // Memastikan sesi dimulai
require_once 'db_connection.php'; // Menyertakan koneksi database
require_once 'helpers.php';       // Menyertakan fungsi helper

// Pastikan metode permintaan adalah GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(['error' => 'Invalid request method.'], 405); // Method Not Allowed
}

// Periksa apakah pengguna sudah login
if (!is_logged_in()) {
    // Kembalikan error JSON untuk permintaan AJAX
    sendJsonResponse(['error' => 'Anda harus login untuk memeriksa status kuis.', 'completed' => false, 'allow_retake' => false], 401);
}

// Validasi dan sanitasi quizId
if (!isset($_GET['quizId']) || !is_numeric($_GET['quizId'])) {
    sendJsonResponse(['error' => 'ID Kuis tidak valid atau tidak diberikan.'], 400); // Bad Request
}

$quizId = intval($_GET['quizId']);
$userId = $_SESSION['user_id']; // Dapatkan ID pengguna dari sesi

global $conn; // Akses koneksi global dari db_connection.php

try {
    // Ambil informasi allow_retake dari tabel quizzes
    $allowRetake = false;
    $stmt_quiz_info = $conn->prepare("SELECT allow_retake FROM quizzes WHERE id = ?");
    if (!$stmt_quiz_info) {
        throw new Exception("Failed to prepare quiz info statement: " . $conn->error);
    }
    $stmt_quiz_info->bind_param("i", $quizId);
    $stmt_quiz_info->execute();
    $stmt_quiz_info->bind_result($allowRetakeValue);
    $stmt_quiz_info->fetch();
    $stmt_quiz_info->close();
    $allowRetake = (bool)$allowRetakeValue; // Konversi tinyint(1) ke boolean


    // Cek apakah user telah menyelesaikan kuis ini sebelumnya
    $completed = false;
    $stmt_completion = $conn->prepare("SELECT COUNT(*) FROM quiz_results WHERE quiz_id = ? AND user_id = ?");
    if (!$stmt_completion) {
        throw new Exception("Failed to prepare completion check statement: " . $conn->error);
    }
    $stmt_completion->bind_param("ii", $quizId, $userId);
    $stmt_completion->execute();
    $stmt_completion->bind_result($count);
    $stmt_completion->fetch();
    $stmt_completion->close();

    $completed = ($count > 0);

    // Kirim status completed DAN allow_retake
    sendJsonResponse([
        'completed' => $completed,
        'allow_retake' => $allowRetake, // Kirim informasi ini ke frontend
        'message' => $completed ? 'Kuis ini sudah selesai Anda kerjakan.' : 'Kuis ini belum selesai Anda kerjakan.'
    ]);

} catch (Exception $e) {
    // Log error sebenarnya untuk debugging
    error_log("Error in check_quiz_status.php: " . $e->getMessage());
    // Kirim pesan error umum ke klien untuk menghindari eksposur informasi sensitif
    sendJsonResponse(['error' => 'Terjadi kesalahan internal server saat memeriksa status kuis. Mohon coba lagi nanti.', 'completed' => false, 'allow_retake' => false], 500);
} finally {
    // Secara default, $conn global yang diinisialisasi oleh db_connection.php
    // akan ditutup secara otomatis di akhir eksekusi skrip.
    // Jika Anda menggunakan getDBConnection() untuk setiap query dan ingin menutupnya secara eksplisit,
    // Anda bisa menambahkan $conn->close(); di sini, tetapi pastikan itu adalah objek koneksi yang benar.
}
?>