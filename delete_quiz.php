<?php
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'helpers.php'; // Sertakan file helper Anda

// Periksa apakah admin sudah login.
if (!is_admin_logged_in()) {
    echo "error: Unauthorized access.";
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "GoRako";

// Buat koneksi
$conn = new mysqli($servername, $username, $password, $dbname);

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $quizId = intval($_POST['id']);

    if ($quizId <= 0) {
        echo "error: Invalid Quiz ID.";
        exit();
    }

    $conn->begin_transaction();
    try {
        // Delete related questions first (CASCADE DELETE should handle options if foreign keys are set up correctly)
        $stmt = $conn->prepare("DELETE FROM questions WHERE quiz_id = ?");
        $stmt->bind_param("i", $quizId);
        $stmt->execute();
        $stmt->close();

        // Then delete the quiz itself
        $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
        $stmt->bind_param("i", $quizId);
        $stmt->execute();
        $stmt->close();

        // Record admin activity
        $adminId = $_SESSION['admin_id'] ?? 0; // Ganti dengan ID admin yang sebenarnya jika ada
        $activityDescription = "Admin menghapus kuis dengan ID: " . $quizId;
        $stmtActivity = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description) VALUES (?, ?)");
        $stmtActivity->bind_param("is", $adminId, $activityDescription);
        $stmtActivity->execute();
        $stmtActivity->close();

        $conn->commit();
        echo "success";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error deleting quiz: " . $e->getMessage());
        echo "error: " . $e->getMessage();
    }
} else {
    echo "error: Invalid request.";
}

$conn->close();
?>