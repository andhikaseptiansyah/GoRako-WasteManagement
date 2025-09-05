<?php
// verify_pin.php
header('Content-Type: application/json');

require_once 'db_connection.php';

$response = ['isValid' => false, 'message' => 'Invalid request.'];

// Pastikan koneksi database berhasil dibuat dan tidak ada error koneksi aktif.
if ($conn->connect_error) {
    error_log("[verify_pin.php] Koneksi database gagal di awal skrip: " . $conn->connect_error);
    $response = ['isValid' => false, 'message' => 'Koneksi database tidak tersedia.'];
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);

    $dropPointId = $data['dropPointId'] ?? null;
    $enteredPin = $data['pin'] ?? null; // Perhatikan: 'name' sudah tidak diharapkan di sini

    error_log("[verify_pin.php] Menerima dropPointId: " . ($dropPointId ?? 'null'));

    if ($dropPointId && $enteredPin) {
        $stmt = $conn->prepare("SELECT pin_hash FROM drop_points WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $dropPointId);
            $stmt->execute();
            $stmt->bind_result($pinHashFromDb);
            $stmt->fetch();
            $stmt->close();

            error_log("[verify_pin.php] Hash PIN tersimpan dari DB untuk dropPointId " . $dropPointId . ": " . ($pinHashFromDb ? 'DITEMUKAN' : 'TIDAK_DITEMUKAN'));

            if ($pinHashFromDb && password_verify($enteredPin, $pinHashFromDb)) {
                $response = ['isValid' => true, 'message' => 'PIN berhasil diverifikasi.'];
            } else {
                $response = ['isValid' => false, 'message' => 'PIN salah untuk lokasi ini.'];
                error_log("[verify_pin.php] Verifikasi PIN gagal untuk dropPointId " . $dropPointId . ".");
            }
        } else {
            error_log("[verify_pin.php] Gagal menyiapkan statement untuk verifikasi PIN (drop_points): " . $conn->error);
            $response = ['isValid' => false, 'message' => 'Kesalahan database saat verifikasi PIN (Prepare).'];
        }
    } else {
        error_log("[verify_pin.php] dropPointId atau enteredPin tidak ada dalam permintaan. Data diterima: " . json_encode($data));
        $response = ['isValid' => false, 'message' => 'ID lokasi atau PIN tidak disediakan.'];
    }
} else {
    error_log("[verify_pin.php] Metode permintaan tidak valid. Diharapkan POST, menerima " . $_SERVER['REQUEST_METHOD']);
    $response = ['isValid' => false, 'message' => 'Metode permintaan tidak valid.'];
}

echo json_encode($response);
?>