<?php
// reset_weekly_points.php

// Sertakan koneksi database
require_once 'db_connection.php';

// Query untuk mereset kolom weekly_points untuk semua pengguna menjadi 0
$sql = "UPDATE users SET weekly_points = 0";

if ($conn->query($sql) === TRUE) {
    // Log bahwa reset berhasil (opsional, tapi bagus untuk debugging)
    $log_message = "Weekly points reset successfully on " . date('Y-m-d H:i:s') . "\n";
    file_put_contents('reset_log.txt', $log_message, FILE_APPEND);
    echo "Weekly points have been reset.";
} else {
    // Log error jika gagal
    $log_message = "Error resetting weekly points: " . $conn->error . " on " . date('Y-m-d H:i:s') . "\n";
    file_put_contents('reset_log.txt', $log_message, FILE_APPEND);
    echo "Error: " . $conn->error;
}

$conn->close();
?>