<?php
// submit_recycling.php

// PENTING: Perbaiki nama file koneksi database
include 'db_connection.php'; // SEBELUMNYA: db_connect.php -> Perbaikan: db_connection.php

// Mengatur header untuk memberitahu klien bahwa respons adalah JSON
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Terjadi kesalahan tidak diketahui.'];

// Pastikan koneksi database berhasil dibuat dan tidak ada error koneksi aktif.
// Ini sangat penting karena script ini akan diakses langsung oleh AJAX.
if ($conn === null || $conn->connect_error) {
    error_log("[submit_recycling.php] Koneksi database gagal di awal skrip: " . ($conn ? $conn->connect_error : 'objek koneksi null'));
    $response = ['success' => false, 'message' => 'Koneksi database tidak tersedia. Mohon coba lagi nanti.'];
    echo json_encode($response);
    exit(); // Hentikan eksekusi jika koneksi database gagal
}

// Memeriksa apakah request menggunakan metode POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitasi dan validasi input
    $user_id = filter_var($_POST['userId'] ?? null, FILTER_VALIDATE_INT);
    $drop_point_id = filter_var($_POST['dropPointId'] ?? null, FILTER_VALIDATE_INT);
    // bank_officer_name tidak lagi relevan untuk verifikasi PIN, tetapi tetap disimpan jika diperlukan untuk data riwayat
    $bank_officer_name = htmlspecialchars(trim($_POST['bankOfficerName'] ?? '')); // Masih bisa disimpan untuk record, tapi tidak untuk PIN verification
    $pin = trim($_POST['pin'] ?? ''); // TIDAK MENGGUNAKAN htmlspecialchars() pada PIN
    $waste_types = $_POST['wasteType'] ?? []; // Array jenis sampah
    $waste_weight = filter_var($_POST['wasteWeight'] ?? 0, FILTER_VALIDATE_FLOAT);
    $waste_photo_base64 = $_POST['wastePhotoBase64'] ?? null; // Data gambar base64
    $qr_code_value = htmlspecialchars(trim($_POST['qrCodeValue'] ?? ''));
    $offline_sync = filter_var($_POST['offlineSync'] ?? false, FILTER_VALIDATE_BOOLEAN); // Flag untuk sinkronisasi offline

    // Validasi dasar: Memastikan semua input penting tidak kosong atau tidak valid
    if (!$user_id || !$drop_point_id || empty($pin) || empty($waste_types) || $waste_weight <= 0) {
        $response['message'] = "Semua bidang wajib (User ID, Drop Point ID, PIN, Jenis Sampah, Berat Sampah) harus diisi dengan benar.";
        echo json_encode($response);
        $conn->close();
        exit();
    }
    // Validasi PIN 6 digit
    if (strlen($pin) !== 6) {
        $response['message'] = "PIN harus 6 digit.";
        echo json_encode($response);
        $conn->close();
        exit();
    }


    // --- Verifikasi PIN Lokasi Bank Sampah ---
    // PIN sekarang diverifikasi terhadap tabel drop_points
    $sql_verify_pin = "SELECT pin_hash, name FROM drop_points WHERE id = ?"; // Perbaikan: Ambil pin_hash DAN nama lokasi
    $stmt_verify_pin = $conn->prepare($sql_verify_pin);
    if ($stmt_verify_pin === false) {
        error_log("[submit_recycling.php] Error preparing PIN verification (drop_points): " . $conn->error);
        $response['message'] = "Kesalahan server saat verifikasi PIN (persiapan query).";
        echo json_encode($response);
        $conn->close();
        exit();
    }
    $stmt_verify_pin->bind_param("i", $drop_point_id);
    $stmt_verify_pin->execute();
    $stmt_verify_pin->bind_result($pin_hash_from_db, $drop_point_name); // Bind juga nama lokasi
    $stmt_verify_pin->fetch();
    $stmt_verify_pin->close();

    // Memverifikasi PIN yang dimasukkan dengan hash PIN yang disimpan
    if ($pin_hash_from_db === null || !password_verify($pin, $pin_hash_from_db)) {
        error_log("[submit_recycling.php] Verifikasi PIN gagal untuk dropPoint ID: " . $drop_point_id . " - PIN yang dimasukkan: " . $pin);
        $response['message'] = "PIN lokasi bank sampah tidak valid.";
        echo json_encode($response);
        $conn->close();
        exit();
    }
    // --- Akhir Verifikasi PIN ---

    // Menghitung poin yang didapat sesuai logika yang Anda berikan
    $earned_points = 0; // Inisialisasi
    if ($waste_weight < 1) {
        $earned_points = 50;
    } else if ($waste_weight >= 1 && $waste_weight <= 10) {
        $earned_points = 100;
    } else if ($waste_weight >= 11 && $waste_weight <= 20) {
        $earned_points = 130;
    } else if ($waste_weight >= 21 && $waste_weight <= 40) {
        $earned_points = 150;
    } else if ($waste_weight >= 41 && $waste_weight <= 60) {
        $earned_points = 170;
    } else if ($waste_weight > 60) {
        $earned_points = 200;
    }


    // Memasukkan data penukaran sampah ke tabel 'submissions'
    // Perbaikan: Tambahkan drop_point_name ke INSERT statement dan bind_param
    $sql_insert_submission = "INSERT INTO submissions (user_id, drop_point_id, drop_point_name, bank_officer_name, waste_types, waste_weight_kg, waste_photo_base64, qr_code_value, earned_points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_insert_submission = $conn->prepare($sql_insert_submission);

    if ($stmt_insert_submission === false) {
        error_log("[submit_recycling.php] Error preparing submission insertion: " . $conn->error);
        $response['message'] = "Kesalahan server saat menyiapkan data penukaran (persiapan query).";
        echo json_encode($response);
        $conn->close();
        exit();
    }

    $waste_types_json = json_encode($waste_types);
    // Perbaikan: Tambahkan drop_point_name sebagai parameter string 's'
    // 'iisdsssi' -> 'issdsssi' (user_id, drop_point_id, drop_point_name, bank_officer_name, waste_types_json, waste_weight, waste_photo_base64, qr_code_value, earned_points)
    // Cek kembali: user_id (i), drop_point_id (i), drop_point_name (s), bank_officer_name (s), waste_types_json (s), waste_weight (d), waste_photo_base64 (s), qr_code_value (s), earned_points (i)
    $stmt_insert_submission->bind_param("iissdsssi", $user_id, $drop_point_id, $drop_point_name, $bank_officer_name, $waste_types_json, $waste_weight, $waste_photo_base64, $qr_code_value, $earned_points);

    // Mengeksekusi prepared statement untuk memasukkan data penukaran
    if ($stmt_insert_submission->execute()) {
        // Jika data penukaran berhasil disimpan, perbarui total poin pengguna
        // Perbaikan: Gunakan nama kolom 'total_points' yang konsisten
        $sql_update_points = "UPDATE users SET total_points = total_points + ? WHERE id = ?";
        $stmt_update_points = $conn->prepare($sql_update_points);
        if ($stmt_update_points === false) {
            error_log("[submit_recycling.php] Error preparing points update: " . $conn->error);
            $response['message'] = "Penukaran sampah berhasil dicatat, tetapi gagal memperbarui poin pengguna (persiapan query).";
            $response['success'] = true; // Set success to true if submission itself was okay
        } else {
            $stmt_update_points->bind_param("ii", $earned_points, $user_id);

            if ($stmt_update_points->execute()) {
                // Setelah poin diperbarui, ambil total poin terbaru pengguna dari database
                // Perbaikan: Gunakan nama kolom 'total_points' yang konsisten
                $sql_get_new_points = "SELECT total_points FROM users WHERE id = ?";
                $stmt_get_new_points = $conn->prepare($sql_get_new_points);
                if ($stmt_get_new_points === false) {
                     error_log("[submit_recycling.php] Error preparing get new points: " . $conn->error);
                     $new_total_points = 'N/A'; // Handle jika query gagal
                } else {
                    $stmt_get_new_points->bind_param("i", $user_id);
                    $stmt_get_new_points->execute();
                    $stmt_get_new_points->bind_result($new_total_points);
                    $stmt_get_new_points->fetch();
                    $stmt_get_new_points->close();
                }

                $response['success'] = true;
                $response['message'] = "Penukaran sampah berhasil dicatat!";
                $response['earnedPoints'] = $earned_points;
                $response['newTotalPoints'] = $new_total_points;
            } else {
                error_log("[submit_recycling.php] Gagal eksekusi update poin: " . $stmt_update_points->error);
                $response['message'] = "Gagal memperbarui poin pengguna: " . $stmt_update_points->error;
            }
            $stmt_update_points->close();
        }
    } else {
        error_log("[submit_recycling.php] Gagal eksekusi insert penukaran: " . $stmt_insert_submission->error);
        $response['message'] = "Gagal mencatat penukaran sampah: " . $stmt_insert_submission->error;
    }
    $stmt_insert_submission->close();
} else {
    // Jika request bukan metode POST
    $response['message'] = "Metode request tidak valid.";
}

// Mengirim respons JSON kembali ke klien
echo json_encode($response);
$conn->close(); // Menutup koneksi database
?>