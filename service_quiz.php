<?php
// service_quiz.php

// Sertakan file koneksi database. Ini akan memulai sesi dan membuat koneksi $conn.
require_once 'db_connection.php'; // Pastikan db_connection.php ada dan berfungsi
// Memastikan session_start() sudah terpanggil di db_connection.php

// Sertakan file helper
require_once 'helpers.php'; // Pastikan helpers.php ada dan berfungsi

// Pastikan variabel koneksi database global dapat diakses
global $conn;

// Pastikan pengguna sudah login untuk halaman ini
if (!is_logged_in()) {
    redirect('login.php'); // Arahkan ke halaman login jika belum login
    exit; // Pastikan tidak ada eksekusi kode lebih lanjut setelah redirect
}

// Inisialisasi variabel sesi yang akan digunakan di halaman
$loggedInUserId = $_SESSION['user_id'];
$loggedInUsername = $_SESSION['username'];

/**
 * Fungsi untuk menyimpan hasil kuis dan memberikan poin.
 *
 * @param int $quizId ID kuis yang diselesaikan.
 * @param int $score Skor yang diperoleh pengguna.
 * @param int $totalQuestions Total pertanyaan dalam kuis.
 * @return bool True jika berhasil menyimpan, false jika gagal.
 */
function saveQuizResult($quizId, $score, $totalQuestions) {
    global $conn; // Mengakses objek koneksi database global

    if (!is_logged_in()) { // Memastikan pengguna masih login
        error_log("Attempt to save quiz result without login.");
        return false;
    }
    $userId = $_SESSION['user_id']; // Mengambil ID pengguna dari sesi

    // --- Menghitung Poin yang Diperoleh dari Skor Kuis ---
    $pointsEarned = 0;
    if ($score >= 80) { // Contoh: 10 poin untuk skor 80% atau lebih
        $pointsEarned = 10;
    } else if ($score >= 60) { // Contoh: 5 poin untuk skor 60% atau lebih
        $pointsEarned = 5;
    }

    // Menyimpan hasil kuis ke tabel quiz_results
    $stmt = $conn->prepare("INSERT INTO quiz_results (user_id, quiz_id, score, total_questions, points_earned, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        error_log("Failed to prepare quiz_results statement: " . $conn->error);
        return false;
    }
    $stmt->bind_param("iiiii", $userId, $quizId, $score, $totalQuestions, $pointsEarned);

    if (!$stmt->execute()) {
        error_log("Error saving quiz result: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();

    // Memperbarui total_points pengguna di tabel users
    $stmtUpdateUser = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
    if (!$stmtUpdateUser) {
        error_log("Failed to prepare user points update statement: " . $conn->error);
        return false;
    }
    $stmtUpdateUser->bind_param("ii", $pointsEarned, $userId);
    if (!$stmtUpdateUser->execute()) {
        error_log("Error updating user total points: " . $stmtUpdateUser->error);
        $stmtUpdateUser->close();
        return false;
    }
    $stmtUpdateUser->close();

    // Mencatat transaksi di points_history (opsional, tapi direkomendasikan untuk riwayat)
    $description = "Selesaikan Kuis #" . $quizId . " dengan skor " . $score . "%";
    $stmtPointsHistory = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, NOW())");
    if (!$stmtPointsHistory) {
        error_log("Failed to prepare points_history statement: " . $conn->error);
    }
    $stmtPointsHistory->bind_param("isi", $userId, $description, $pointsEarned);
    if (!$stmtPointsHistory->execute()) {
        error_log("Error saving points history: " . $stmtPointsHistory->error);
    }
    $stmtPointsHistory->close();

    return true; // Semua operasi berhasil
}

// --- Penanganan Permintaan AJAX POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tangani Pengiriman Hasil Kuis
    if (isset($_POST['quiz_id'])) {
        if (!is_logged_in()) {
            http_response_code(401); // Unauthorized
            echo json_encode(['success' => false, 'error' => 'Anda harus login untuk menyimpan hasil kuis.']);
            exit;
        }

        $quizId = $_POST['quiz_id'];
        $score = $_POST['score'];
        $totalQuestions = $_POST['total_questions'];

        if (saveQuizResult($quizId, $score, $totalQuestions)) {
            echo json_encode(['success' => true, 'message' => 'Hasil quiz berhasil disimpan dan poin telah ditambahkan!']);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(['success' => false, 'error' => 'Gagal menyimpan hasil quiz atau menambahkan poin.']);
        }
        exit; // Penting untuk keluar setelah menangani permintaan AJAX
    }

    // Tangani Permintaan Penukaran Hadiah
    if (isset($_POST['action']) && $_POST['action'] === 'exchange_reward') {
        if (!is_logged_in()) {
            http_response_code(401); // Unauthorized
            echo json_encode(['success' => false, 'error' => 'Anda harus login untuk menukar hadiah.']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $rewardId = (int)$_POST['reward_id'];

        // TIDAK ADA TRANSACTION DI SINI, HANYA VALIDASI DAN REDIRECT
        try {
            // 1. Dapatkan detail hadiah dan periksa stok/poin yang dibutuhkan
            $stmt_reward = $conn->prepare("SELECT id, name, points_needed, stock FROM rewards WHERE id = ?");
            if (!$stmt_reward) {
                throw new Exception("Failed to prepare reward statement: " . $conn->error);
            }
            $stmt_reward->bind_param("i", $rewardId);
            $stmt_reward->execute();
            $result_reward = $stmt_reward->get_result();
            $reward = $result_reward->fetch_assoc();
            $stmt_reward->close();

            if (!$reward) {
                throw new Exception("Hadiah tidak ditemukan.");
            }
            if ($reward['stock'] <= 0) {
                throw new Exception("Stok hadiah ini sudah habis.");
            }

            // 2. Dapatkan poin pengguna saat ini
            $stmt_user = $conn->prepare("SELECT total_points FROM users WHERE id = ?");
            if (!$stmt_user) {
                throw new Exception("Failed to prepare user points statement: " . $conn->error);
            }
            $stmt_user->bind_param("i", $userId);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            $user = $result_user->fetch_assoc();
            $stmt_user->close();

            if (!$user) {
                throw new Exception("Pengguna tidak ditemukan.");
            }
            if ($user['total_points'] < $reward['points_needed']) {
                throw new Exception("Poin Anda tidak cukup untuk menukar hadiah ini.");
            }

            // Jika semua validasi berhasil, beri tahu frontend untuk redirect ke checkout.php
            echo json_encode([
                'success' => true,
                'message' => 'Hadiah berhasil dipilih! Anda akan diarahkan ke halaman checkout.',
                'redirect_to_checkout' => true,
                'reward_id_for_checkout' => $rewardId
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(400); // Bad request atau kesalahan klien
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

// --- Pengambilan Data Awal untuk Pemuatan Halaman ---

// Mengambil data kuis dari database
$quizzes = [];
$sql_quizzes = "SELECT id, title, description, category FROM quizzes ORDER BY id ASC";
$result_quizzes = $conn->query($sql_quizzes);

if ($result_quizzes) {
    while ($row = $result_quizzes->fetch_assoc()) {
        $quizzes[] = $row;
    }
} else {
    error_log("Error fetching quizzes: " . $conn->error);
}

// Mengambil data hadiah dari database untuk tampilan dinamis
$rewards_from_db = [];
$sql_rewards = "SELECT id, name, description, points_needed, stock, category, image_url FROM rewards ORDER BY name ASC";
$result_rewards = $conn->query($sql_rewards);

if ($result_rewards) {
    while ($row = $result_rewards->fetch_assoc()) {
        // Memetakan bidang DB agar sesuai dengan ekspektasi frontend
        $reward_item = [
            'id' => (int)$row['id'], // Konversi ke int untuk konsistensi tipe dengan JS
            'title' => htmlspecialchars($row['name']), // Sanitasi untuk mencegah XSS
            'description' => htmlspecialchars($row['description']), // Sanitasi
            'points' => (int)$row['points_needed'],
            'stock' => (int)$row['stock'],
            'image' => htmlspecialchars($row['image_url']), // Sanitasi
            'category' => 'other', // Default 'other' jika tidak ada kecocokan
            'icon' => 'fas fa-gift' // Ikon default
        ];

        // Menyesuaikan ikon berdasarkan kategori
        switch (strtolower($row['category'])) {
            case 'physical product':
                $reward_item['category'] = 'product';
                $reward_item['icon'] = "fas fa-box";
                break;
            case 'digital product':
                $reward_item['category'] = 'product';
                $reward_item['icon'] = "fas fa-cloud-download-alt";
                break;
            case 'service voucher':
                $reward_item['category'] = 'voucher';
                $reward_item['icon'] = "fas fa-ticket-alt";
                break;
            case 'experience':
            case 'donation':
                $reward_item['category'] = 'experience';
                $reward_item['icon'] = "fas fa-hand-holding-heart";
                break;
            // 'other' category sudah menjadi default
        }
        $rewards_from_db[] = $reward_item;
    }
} else {
    error_log("Error fetching rewards: " . $conn->error);
}

// Mengambil poin pengguna saat ini (jika sudah login)
$user_current_points = 0;
if (is_logged_in()) {
    $userId = $_SESSION['user_id'];
    $stmt_points = $conn->prepare("SELECT total_points FROM users WHERE id = ?");
    if ($stmt_points) {
        $stmt_points->bind_param("i", $userId);
        $stmt_points->execute();
        $result_points = $stmt_points->get_result();
        if ($result_points->num_rows > 0) {
            $user_current_points = $result_points->fetch_assoc()['total_points'];
        }
        $stmt_points->close();
    } else {
        error_log("Failed to prepare user points fetch statement: " . $conn->error);
    }
}

// Mengambil riwayat transaksi untuk pengguna yang login
$transaction_history_from_db = [];
if (is_logged_in()) {
    $userId = $_SESSION['user_id'];
    $stmt_history = $conn->prepare("SELECT description, points_amount, transaction_date FROM points_history WHERE user_id = ? ORDER BY transaction_date DESC");
    if ($stmt_history) {
        $stmt_history->bind_param("i", $userId);
        $stmt_history->execute();
        $result_history = $stmt_history->get_result();
        while ($row = $result_history->fetch_assoc()) {
            $type = 'bonus';
            if (strpos($row['description'], 'Selesaikan Kuis #') === 0) {
                $type = 'quiz';
            } elseif (strpos($row['description'], 'Scan sampah:') === 0) {
                $type = 'scan';
            } elseif (strpos($row['description'], 'Tukar hadiah "') === 0) { // Ini akan mencatat penukaran yang berhasil di checkout.php
                $type = 'exchange';
            }
            $transaction_history_from_db[] = [
                'type' => $type,
                'description' => htmlspecialchars($row['description']), // Sanitasi
                'points' => (int)$row['points_amount'],
                'date' => $row['transaction_date']
            ];
        }
        $stmt_history->close();
    } else {
        error_log("Failed to prepare transaction history fetch statement: " . $conn->error);
    }
}

// --- Mengambil Titik Drop Off dari Database untuk Bagian Lokator Bank Sampah ---
$bank_sampah_locations_from_db = [];
$sql_bank_sampah = "SELECT id, name, address, types, prices, description FROM drop_points ORDER BY id ASC";
$result_bank_sampah = $conn->query($sql_bank_sampah);

if ($result_bank_sampah) {
    while ($row = $result_bank_sampah->fetch_assoc()) {
        $waste_types_decoded = json_decode($row['types'], true);
        
        $prices_array = [];
        if (!empty($row['prices'])) {
            $price_pairs = explode(', ', $row['prices']);
            foreach ($price_pairs as $pair) {
                if (strpos($pair, ':') !== false) {
                    list($type_name, $price_val) = explode(': ', $pair, 2);
                    $prices_array[trim($type_name)] = trim($price_val);
                }
            }
        }

        $final_waste_types_formatted = [];
        if (is_array($waste_types_decoded)) {
            foreach ($waste_types_decoded as $type) {
                $price_for_type = isset($prices_array[ucfirst($type)]) ? $prices_array[ucfirst($type)] : 'Harga Tidak Tersedia';
                $final_waste_types_formatted[] = [
                    'type' => htmlspecialchars(ucfirst($type)), // Sanitasi
                    'price' => htmlspecialchars($price_for_type) // Sanitasi
                ];
            }
        }

        $card_colors = ['green-card', 'blue-card', 'purple-card'];
        $random_color_key = array_rand($card_colors);
        $assigned_card_color = $card_colors[$random_color_key];

        $city = 'other';
        if (stripos($row['address'], 'jakarta') !== false) {
            $city = 'jakarta';
        } elseif (stripos( $row['address'], 'surabaya') !== false) {
            $city = 'surabaya';
        } elseif (stripos($row['address'], 'bandung') !== false) {
            $city = 'bandung';
        } elseif (stripos($row['address'], 'yogyakarta') !== false) {
            $city = 'yogyakarta';
        }

        $bank_sampah_locations_from_db[] = [
            'id' => (int)$row['id'],
            'name' => htmlspecialchars($row['name']),
            'description' => htmlspecialchars($row['description']),
            'address' => htmlspecialchars($row['address']),
            'city' => htmlspecialchars($city),
            'wasteTypes' => $final_waste_types_formatted,
            'mapLink' => 'bank_sampah.php?selected_dp_id=' . (int)$row['id'],
            'cardColorClass' => htmlspecialchars($assigned_card_color)
        ];
    }
} else {
    error_log("Error fetching bank sampah locations: " . $conn->error);
}

// Menutup koneksi database pada akhir eksekusi skrip
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Kelola Sampah dengan Cerdas</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* CSS Global & Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
            overflow-x: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%);
            min-height: 100vh;
            color: #2e7d32;
        }

        /* Navbar Styles */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            padding: 1rem 0;
            transition: all 0.3s ease;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #16610E;
            text-decoration: none;
        }

        .logo-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #16610E, #2D4F2B);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            position: relative;
        }

        .logo-icon::before {
            content: '';
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.9);
            clip-path: polygon(50% 0%, 0% 25%, 0% 75%, 50% 100%, 100% 75%, 100% 25%);
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-link {
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            transition: color 0.3s ease;
            position: relative;
        }

        .nav-link:hover {
            color: #10b981;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #10b981, #10b981);
            transition: width 0.3s ease;
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .cta-button {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .cta-button:hover {
            transform: none;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .cta-button:active {
            transform: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }


        /* Mobile Menu Toggle */
        .mobile-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            gap: 4px;
        }

        .mobile-toggle span {
            width: 25px;
            height: 3px;
            background: #374151;
            transition: all 0.3s ease;
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at center, rgba(59, 130, 246, 0.1) 0%, transparent 50%);
            animation: float 20s ease-in-out infinite;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at center, rgba(16, 185, 129, 0.08) 0%, transparent 50%);
            animation: float 25s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-content h1 {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #16610E, #2D4F2B,#374151);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: slideInLeft 1s ease-out;
            position: relative;
            display: inline-block;
        }
        .hero-content h1::before {
            content: attr(data-text);
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            color: #10b981;
            animation: typing 2s steps(20, end) forwards, blink-caret 0.75s step-end infinite;
        }

        @keyframes typing {
            from { width: 0 }
            to { width: 100% }
        }

        @keyframes blink-caret {
            from, to { border-color: transparent }
            50% { border-color: #10b981 }
        }


        .hero-content p {
            font-size: 1.2rem;
            color: #6b7280;
            margin-bottom: 2.5rem;
            animation: slideInLeft 1s ease-out 0.2s both;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            animation: slideInLeft 1s ease-out 0.4s both;
        }

        .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-primary:hover {
            transform: none;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .btn-primary:active {
            transform: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }


        .btn-secondary {
            background: linear-gradient(135deg, #2D4F2B, #16610E);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary:hover {
            transform: none;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary:active {
            transform: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .hero-visual {
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            animation: slideInRight 1s ease-out;
        }

        .hero-image {
            width: 100%;
            max-width: 500px;
            height: 400px;
            background: linear-gradient(135deg, #e0f2fe, #b3e5fc);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .hero-image::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-image::after {
            content: '🌿';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 4rem;
            z-index: 2;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-50%, -50%) scale(1.1); }
        }

        /* Floating elements */
        .floating-element {
            position: absolute;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 15px;
            padding: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .floating-element-1 {
            top: 20%;
            right: 10%;
            animation: floatUpDown 6s ease-in-out infinite;
        }

        .floating-element-2 {
            bottom: 20%;
            left: 10%;
            animation: floatUpDown 8s ease-in-out infinite reverse;
        }

        @keyframes floatUpDown {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        /* Quiz Section Styles */
        .quiz-section {
            padding: 5rem 0;
            background-color: #FFFFFF;
            position: relative;
        }

        .quiz-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(ellipse at 20% 50%, rgba(0, 174, 239, 0.03) 0%, transparent 50%),
                                     radial-gradient(ellipse at 80% 80%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .quiz-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            position: relative;
            z-index: 2;
        }

        .quiz-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .quiz-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .quiz-blue {
            color: #537D5D;
        }

        .quiz-black {
            color: #1f2937;
        }

        .quiz-description {
            font-size: 1.1rem;
            color: #6b7280;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .quiz-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.06);
            border: 2px solid rgba(255, 255, 255, 0.3);
            position: relative;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
        }

        .quiz-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0, 174, 239, 0.02) 0%, rgba(16, 185, 129, 0.02) 100%);
            opacity: 0;
            transition: opacity 0.4s ease;
            pointer-events: none;
        }

        .quiz-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.6s ease;
            pointer-events: none;
        }

        .quiz-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.12);
            border-color: rgba(0, 174, 239, 0.2);
        }

        .quiz-card:hover::before {
            opacity: 1;
        }

        .quiz-card:hover::after {
            left: 100%;
        }

        .quiz-badge {
            display: none;
        }

        .quiz-content {
            position: relative;
            z-index: 2;
        }

        .quiz-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #e0f2fe, #cffafe);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px rgba(0, 174, 239, 0.15);
            position: relative;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
        }

        .quiz-icon::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, #00AEEF, #10b981, #3b82f6);
            border-radius: 22px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .quiz-card:hover .quiz-icon::before {
            opacity: 1;
        }

        .quiz-icon i {
            font-size: 2.2rem;
            background: linear-gradient(135deg, #00AEEF, #0284c7);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: all 0.4s ease;
        }

        .quiz-card:hover .quiz-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 15px 35px rgba(0, 174, 239, 0.25);
        }

        .quiz-card:hover .quiz-icon i {
            transform: scale(1.1);
        }

        .quiz-card-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            line-height: 1.3;
            transition: color 0.3s ease;
        }

        .quiz-card:hover .quiz-card-title {
            color: #00AEEF;
        }

        .quiz-card-desc {
            color: #6b7280;
            line-height: 1.7;
            margin-bottom: 2.5rem;
            font-size: 1rem;
            transition: color 0.3s ease;
        }

        .quiz-card:hover .quiz-card-desc {
            color: #4b5563;
        }

        .quiz-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.25);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            font-size: 0.95rem;
        }

        .quiz-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #059669, #047857);
            transition: left 0.4s ease;
            z-index: 0;
        }

        .quiz-btn span,
        .quiz-btn i {
            position: relative;
            z-index: 1;
        }

        .quiz-btn:hover {
            transform: none;
            box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);
        }

        .quiz-btn:active {
            transform: none;
        }

        .quiz-btn i {
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-size: 0.9rem;
        }

        .quiz-btn:hover i {
            transform: translateX(5px) rotate(360deg);
        }

        /* Responsive Design for Quiz Section */
        @media (max-width: 768px) {
            .quiz-section {
                padding: 3rem 0;
            }

            .quiz-container {
                padding: 0 1rem;
            }

            .quiz-title {
                font-size: 2.2rem;
            }

            .quiz-description {
                font-size: 1rem;
            }

            .quiz-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .quiz-card {
                padding: 2rem;
            }

            .quiz-icon {
                width: 65px;
                height: 65px;
            }

            .quiz-icon i {
                font-size: 1.8rem;
            }

            .quiz-card-title {
                font-size: 1.2rem;
            }

            .quiz-card-desc {
                font-size: 0.9rem;
                margin-bottom: 2rem;
            }

            .quiz-btn {
                width: 100%;
                justify-content: center;
                padding: 1rem 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .quiz-title {
                font-size: 1.8rem;
            }

            .quiz-card {
                padding: 1.2rem;
            }
        }

        /* Responsive Design Navbar */
        @media (max-width: 768px) {
            .nav-menu {
                position: fixed;
                top: 100%;
                left: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(10px);
                flex-direction: column;
                padding: 2rem;
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .nav-menu.active {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }

            .mobile-toggle {
                display: flex;
            }

            .hero-container {
                grid-template-columns: 1fr;
                gap: 2rem;
                text-align: center;
                padding-top: 2rem;
            }

            .hero-content h1 {
                font-size: 2.5rem;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn-primary, .btn-secondary {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }

            .hero-image {
                height: 300px;
            }

            .floating-element {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .nav-container {
                padding: 0 1rem;
            }

            .hero-container {
                padding: 0 1rem;
            }

            .hero-content h1 {
                font-size: 2rem;
            }

            .hero-content p {
                font-size: 1rem;
            }
        }

        /* Points and Rewards Section Specific Styles */
        .points-section-wrapper {
            padding: 20px;
            background-color: #FFFFFF;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 20px 0;
        }
        .header h1 {
            font-size: 3rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        .header p {
            font-size: 1.2rem;
            color: #388e3c;
            font-weight: 400;
        }
        .points-section {
            background: linear-gradient(135deg, #66bb6a, #81c784);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .points-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }
        .points-display { position: relative; z-index: 2; }
        .points-number {
            font-size: 3.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            transition: all 0.3s ease-out;
            display: inline-block;
        }
        .points-number.animated {
            transform: scale(1.1);
            color: yellow;
        }
        .points-label {
            font-size: 1.3rem;
            color: rgba(255,255,255,0.9);
            margin-bottom: 30px;
        }
        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .action-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .search-filter {
            margin-bottom: 30px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
        }
        .search-box { position: relative; flex: 1; max-width: 400px; }
        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 50px;
            border: 2px solid #a5d6a7;
            border-radius: 50px;
            font-size: 1rem;
            outline: none;
            transition: all 0.3s ease;
            background: white;
        }
        .search-box input:focus {
            border-color: #66bb6a;
            box-shadow: 0 0 0 3px rgba(102, 187, 106, 0.1);
        }
        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #81c784;
        }
        .filter-dropdown {
            padding: 12px 20px;
            border: 2px solid #a5d6a7;
            border-radius: 50px;
            font-size: 1rem;
            outline: none;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .filter-dropdown:focus {
            border-color: #66bb6a;
            box-shadow: 0 0 0 3px rgba(102, 187, 106, 0.1);
        }
        .rewards-section h2 {
            text-align: center;
            font-size: 2.5rem;
            color: #1b5e20;
            margin-bottom: 30px;
            font-weight: 600;
        }
        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        .reward-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
            transform-style: preserve-3d;
            cursor: pointer;
        }
        .reward-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0,0,0,0.15);
            border-color: #66bb6a;
        }
        .reward-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #66bb6a, #81c784, #a5d6a7);
        }
        .reward-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .reward-icon {
            font-size: 3rem;
            color: #66bb6a;
            margin-bottom: 15px;
        }
        .reward-points {
            background: linear-gradient(135deg, #66bb6a, #81c784);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 4px 12px rgba(102, 187, 106, 0.3);
        }
        .reward-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1b5e20;
            margin-bottom: 10px;
        }
        .reward-description {
            color: #388e3c;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        .reward-stock {
            color: #757575;
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .reward-stock.low-stock {
            color: #f44336;
            font-weight: 500;
        }
        .exchange-btn {
            width: 100%;
            background: linear-gradient(135deg, #66bb6a, #81c784);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .exchange-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 187, 106, 0.4);
        }
        .exchange-btn:disabled {
            background: #bdbdbd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .exchange-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .exchange-btn:hover::before { left: 100%; }
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal.active {
            display: flex;
            opacity: 1;
        }
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            position: relative;
            transform: translateY(-50px);
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal.active .modal-content {
            transform: translateY(0);
            opacity: 1;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .modal-icon {
            font-size: 4rem;
            color: #66bb6a;
            margin-bottom: 15px;
        }
        .modal-title {
            font-size: 1.5rem;
            color: #1b5e20;
            margin-bottom: 10px;
        }
        .modal-text {
            color: #388e3c;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        .modal-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .modal-btn.confirm { background: #66bb6a; color: white; }
        .modal-btn.confirm:hover {
            background: #5a9e5d;
            transform: none;
        }
        .modal-btn.confirm:active {
            transform: none;
        }

        .modal-btn.cancel { background: #f5f5f5; color: #666; }
        .modal-btn.cancel:hover { background: #e0e0e0; }
        .modal-btn.cancel:active {
            transform: none;
        }

        /* Success Message (Toast) */
        .toast-message {
            display: none;
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #4caf50, #66bb6a);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            text-align: center;
            z-index: 1050;
            opacity: 0;
            animation: toastFadeIn 0.5s ease-out forwards;
            min-width: 250px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .toast-message.error {
            background: linear-gradient(135deg, #f44336, #ef5350);
        }
        .toast-message.info {
            background: linear-gradient(135deg, #2196f3, #42a5f5);
        }
        @keyframes toastFadeIn {
            from { opacity: 0; transform: translateX(-50%) translateY(20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @keyframes toastFadeOut {
            from { opacity: 1; transform: translateX(-50%) translateY(0); }
            to { opacity: 0; transform: translateX(-50%) translateY(20px); }
        }
        /* Responsive Design for Points & Rewards */
        @media (max-width: 768px) {
            .header h1 { font-size: 2.2rem; }
            .points-number { font-size: 2.5rem; }
            .rewards-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; align-items: center; }
            .search-filter { flex-direction: column; align-items: stretch; }
            .search-box { max-width: none; }
        }
        @media (max-width: 480px) {
            .container { padding: 15px; }
            .header h1 { font-size: 1.8rem; }
            .points-section { padding: 20px; }
            .modal-content { margin: 20% auto; padding: 20px; }
        }
        /* Animations */
        .fade-in { animation: fadeIn 0.5s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* New section for Reward Redemption Explanation */
        .redemption-explanation-section {
            padding: 5rem 0;
            background-color: #f8fafc;
            text-align: center;
        }

        .redemption-explanation-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .redemption-explanation-section h2 {
            font-size: 2.8rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 1.5rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.05);
        }

        .redemption-explanation-section p {
            font-size: 1.1rem;
            color: #388e3c;
            line-height: 1.8;
            margin-bottom: 2rem;
        }

        .redemption-steps-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            margin-top: 3rem;
            justify-content: center;
        }

        .redemption-step-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: left;
            border: 1px solid #e0e0e0;
            transform-style: preserve-3d;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .redemption-step-card::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: radial-gradient(circle, rgba(102, 187, 106, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(0);
            transition: transform 0.5s ease-out, width 0.5s ease-out, height 0.5s ease-out, opacity 0.5s ease-out;
            opacity: 0;
            z-index: -1;
        }

        .redemption-step-card:hover::before {
            transform: translate(-50%, -50%) scale(1.5);
            width: 150%;
            height: 150%;
            opacity: 1;
        }

        .redemption-step-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.15);
            border-color: #66bb6a;
        }

        .redemption-step-icon {
            font-size: 3.5rem;
            color: #66bb6a;
            margin-bottom: 20px;
            display: inline-block;
            transition: transform 0.4s ease-out;
        }

        .redemption-step-card:hover .redemption-step-icon {
            transform: rotateY(15deg) scale(1.1);
        }

        .redemption-step-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 10px;
        }

        .redemption-step-description {
            font-size: 1rem;
            color: #388e3c;
            line-height: 1.6;
        }

        /* Responsive for explanation section */
        @media (max-width: 1200px) {
            .redemption-steps-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .redemption-explanation-section h2 {
                font-size: 2.2rem;
            }
            .redemption-explanation-section p {
                font-size: 1rem;
            }
            .redemption-steps-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Footer */
        .footer {
            background-color: #1a202c;
            color: #cbd5e1;
            padding: 4rem 2rem;
            text-align: center;
            font-size: 0.9rem;
            line-height: 1.8;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 3rem;
            text-align: left;
        }

        .footer-brand {
            flex: 1;
            min-width: 250px;
        }

        .footer-brand h3 {
            font-size: 1.8rem;
            color: #fff;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .footer-brand p {
            color: #a0aec0;
        }
        .footer-brand .social-icons a {
            color: #a0aec0;
            font-size: 1.5rem;
            margin-right: 15px;
            transition: color 0.3s ease, transform 0.3s ease;
        }
        .footer-brand .social-icons a:hover {
            color: #10b981;
            transform: translateY(-3px);
        }


        .footer-links h4, .footer-contact h4 {
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 1.2rem;
            font-weight: 600;
        }

        .footer-links ul {
            list-style: none;
        }

        .footer-links ul li {
            margin-bottom: 0.8rem;
        }

        .footer-links ul li a, .footer-contact p {
            color: #a0aec0;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links ul li a:hover, .footer-contact p:hover {
            color: #fff;
        }

        .footer-bottom {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: #a0aec0;
            font-size: 0.85rem;
        }
        /* Quiz Modal styles */
        .quiz-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .quiz-modal.active {
            display: flex;
            opacity: 1;
        }

        .quiz-modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 80%;
            max-width: 600px;
            position: relative;
            animation: modalSlideIn 0.4s ease-out;
        }

        .quiz-modal-content h3 {
            color: #1b5e20;
            text-align: center;
            margin-bottom: 25px;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .quiz-question {
            font-size: 1.2rem;
            color: #388e3c;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .quiz-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }

        .quiz-option {
            background-color: #e8f5e9;
            border: 2px solid #a5d6a7;
            padding: 15px 20px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            color: #1b5e20;
            font-weight: 500;
            text-align: left;
        }

        .quiz-option:hover {
            background-color: #dcedc8;
            border-color: #66bb6a;
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .quiz-option.selected {
            background-color: #66bb6a;
            color: white;
            border-color: #388e3c;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102,187,106,0.3);
        }

        .quiz-option.correct {
            background-color: #4CAF50;
            color: white;
            border-color: #2E7D32;
        }

        .quiz-option.incorrect {
            background-color: #F44336;
            color: white;
            border-color: #D32F2F;
        }
        .quiz-modal-feedback {
            margin-top: 20px;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        .quiz-modal-feedback.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .quiz-modal-feedback.correct { background-color: #e8f5e9; color: #2E7D32; border: 1px solid #66bb6a; }
        .quiz-modal-feedback.incorrect { background-color: #ffebee; color: #D32F2F; border: 1px solid #F44336; }


        .quiz-modal-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }

        .quiz-modal-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(16,185,129,0.2);
        }

        .quiz-modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(16,185,129,0.3);
        }
        .quiz-modal-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        .quiz-modal .close-button {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 2rem;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .quiz-modal .close-button:hover,
        .quiz-modal .close-button:focus {
            color: #555;
            text-decoration: none;
            cursor: pointer;
        }

        /* History Modal Specific Styles */
        .history-modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideIn 0.4s ease-out;
        }

        .history-modal-content h3 {
            color: #1b5e20;
            text-align: center;
            margin-bottom: 25px;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .history-list {
            list-style: none;
            padding: 0;
        }

        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
            color: #388e3c;
            font-size: 1rem;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-item-details {
            flex-grow: 1;
            padding-right: 20px;
        }

        .history-item-date {
            font-size: 0.9em;
            color: #757575;
            text-align: right;
        }

        .history-item-points {
            font-weight: 600;
            color: #1b5e20;
            flex-shrink: 0;
        }

        .history-item.plus .history-item-points {
            color: #4CAF50;
        }

        .history-item.minus .history-item-points {
            color: #F44336;
        }

        .history-modal-empty {
            text-align: center;
            color: #757575;
            font-style: italic;
            padding: 30px;
        }
        .history-modal .close-button {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 2rem;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .history-modal .close-button:hover,
        .history-modal .close-button:focus {
            color: #555;
            text-decoration: none;
            cursor: pointer;
        }

        /* Bank Sampah Locator Section Styles */
        .bank-sampah-locator-section {
            padding: 5rem 0;
            background-color: #f8fafc;
            text-align: center;
            position: relative;
        }

        .bank-sampah-locator-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .locator-header {
            margin-bottom: 3rem;
        }

        .locator-header h2 {
            font-size: 3rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .locator-header p {
            font-size: 1.2rem;
            color: #388e3c;
            line-height: 1.6;
        }

        .search-filter-bank-sampah {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 3rem;
            flex-wrap: wrap;
        }

        .search-filter-bank-sampah .filter-dropdown,
        .search-filter-bank-sampah .search-bank-sampah-btn {
            padding: 0.8rem 1.5rem;
            border-radius: 30px;
            border: 2px solid #a5d6a7;
            font-size: 1rem;
            outline: none;
            background-color: white;
            transition: all 0.3s ease;
            cursor: pointer;
            color: #388e3c;
            font-weight: 500;
        }

        .search-filter-bank-sampah .filter-dropdown:focus {
            border-color: #66bb6a;
            box-shadow: 0 0 0 3px rgba(102, 187, 106, 0.1);
        }

        .search-bank-sampah-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .search-bank-sampah-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .bank-sampah-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2.5rem;
        }

        .bank-sampah-card {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            text-align: left;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
            opacity: 0;
            transform: translateY(30px);
        }

        .bank-sampah-card.loaded {
            opacity: 1;
            transform: translateY(0);
        }

        .bank-sampah-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: linear-gradient(90deg, var(--card-color-dark), var(--card-color-light));
            z-index: 1;
        }

        .bank-sampah-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border-color: var(--card-color-light);
        }
        .bank-sampah-card:hover::after {
            opacity: 1;
            transform: scale(2);
        }
        .bank-sampah-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(255,255,255,0.05) 0%, transparent 70%);
            opacity: 0;
            transition: all 0.5s ease-out;
            pointer-events: none;
            z-index: 0;
        }

        /* Card Colors */
        .bank-sampah-card.green-card {
            --card-color-dark: #388e3c;
            --card-color-light: #66bb6a;
        }
        .bank-sampah-card.blue-card {
            --card-color-dark: #1976d2;
            --card-color-light: #42a5f5;
        }
        .bank-sampah-card.purple-card {
            --card-color-dark: #7b1fa2;
            --card-color-light: #9c27b0;
        }

        .bank-sampah-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1b5e20;
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bank-sampah-name i {
            font-size: 1.5rem;
            color: var(--card-color-dark);
        }

        .bank-sampah-description {
            color: #555;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        .bank-sampah-address {
            font-size: 0.9rem;
            color: #777;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border-top: 1px solid #eee;
            padding-top: 1.5rem;
        }

        .bank-sampah-address i {
            color: var(--card-color-dark);
            font-size: 1rem;
            flex-shrink: 0;
        }

        .waste-types-list {
            list-style: none;
            padding: 0;
            margin-bottom: 2rem;
            border-top: 1px dashed #e0e0e0;
            padding-top: 1.5rem;
        }

        .waste-types-list li {
            font-size: 0.95rem;
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .waste-types-list li i {
            color: var(--card-color-dark);
            font-size: 1rem;
        }

        .bank-sampah-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .action-button {
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .action-button.location-btn {
            background: linear-gradient(135deg, #2196f3, #42a5f5);
            color: white;
            border: none;
        }

        .action-button.location-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 20px rgba(33, 150, 243, 0.5);
            background: linear-gradient(135deg, #42a5f5, #2196f3);
        }
        .action-button.location-btn i {
            transition: transform 0.3s ease;
        }
        .action-button.location-btn:hover i {
            transform: rotate(5deg) scale(1.1);
        }

        /* Ripple effect for buttons */
        .action-button .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.7);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }


        .no-locations-found {
            text-align: center;
            color: #777;
            font-style: italic;
            margin-top: 2rem;
            grid-column: 1 / -1;
            font-size: 1.1rem;
        }

        /* Responsive adjustments for Bank Sampah Locator */
        @media (max-width: 768px) {
            .bank-sampah-locator-section {
                padding: 3rem 0;
            }
            .locator-header h2 {
                font-size: 2.2rem;
            }
            .locator-header p {
                font-size: 1rem;
            }
            .search-filter-bank-sampah {
                flex-direction: column;
                gap: 1rem;
                padding: 0 1rem;
            }
            .search-filter-bank-sampah .filter-dropdown,
            .search-filter-bank-sampah .search-bank-sampah-btn {
                width: 100%;
            }
            .bank-sampah-grid {
                grid-template-columns: 1fr;
                padding: 0 1rem;
            }
            .bank-sampah-card {
                padding: 2rem;
            }
            .bank-sampah-name {
                font-size: 1.5rem;
            }
            .bank-sampah-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

    <section class="hero" id="hero-section">
        <div class="hero-container">
            <div class="hero-content">
                <h1 data-text="Kelola Sampah dengan Cerdas">Kelola Sampah dengan Cerdas</h1>
                <p>Platform edukasi dan pengelolaan sampah untuk lingkungan yang lebih bersih dan berkelanjutan.</p>

                <div class="hero-buttons">
                    <a href="index.php" class="btn-primary">
                        <i class="fas fa-arrow-right"></i>
                        Home
                    </a>
                    <a href="modules.php" class="btn-secondary">
                        <i class="fas fa-play"></i>
                        Edukasi
                    </a>
                </div>
            </div>

            <div class="hero-visual">
                <div class="hero-image"></div>
            </div>
        </div>

        <div class="floating-element floating-element-1">
            <i class="fas fa-recycle" style="color: #10b981; font-size: 1.5rem;"></i>
        </div>
        <div class="floating-element floating-element-2">
            <i class="fas fa-leaf" style="color: #059669; font-size: 1.5rem;"></i>
        </div>
    </section>

    <section class="quiz-section" id="quiz-section">
        <div class="quiz-container">
            <div class="quiz-header">
                <h2 class="quiz-title">
                    <span class="quiz-blue">Kuis</span>
                    <span class="quiz-black">Pengelolaan Sampah</span>
                </h2>
                <p class="quiz-description">
                    Uji pengetahuan Anda tentang pengelolaan sampah dengan kuis interaktif yang edukatif dan menyenangkan
                </p>
            </div>

            <div class="quiz-grid" id="quizGridContainer">
                <?php if (!empty($quizzes)): ?>
                    <?php foreach ($quizzes as $quiz): ?>
                        <div class="quiz-card">
                            <div class="quiz-content">
                                <div class="quiz-icon">
                                    <?php
                                        $icon_class = 'fas fa-question-circle';
                                        if (isset($quiz['category'])) {
                                            switch (strtolower($quiz['category'])) {
                                                case 'waste segregation': $icon_class = 'fas fa-seedling'; break;
                                                case 'recycling': $icon_class = 'fas fa-recycle'; break;
                                                case 'energy': $icon_class = 'fas fa-bolt'; break;
                                                case 'environmental impact': $icon_class = 'fas fa-globe-americas'; break;
                                                default: $icon_class = 'fas fa-book'; break;
                                            }
                                        }
                                    ?>
                                    <i class="<?php echo htmlspecialchars($icon_class); ?>"></i>
                                </div>
                                <h3 class="quiz-card-title"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                                <p class="quiz-card-desc"><?php echo htmlspecialchars($quiz['description']); ?></p>
                                <button class="quiz-btn" data-quiz-id="<?php echo htmlspecialchars($quiz['id']); ?>">
                                    <span>Mulai Kuis</span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; grid-column: 1 / -1; color: #6b7280; font-style: italic;">Belum ada kuis yang tersedia. Silakan cek nanti.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="bank-sampah-locator-section" id="bank-sampah-locator">
        <div class="container">
            <div class="locator-header">
                <h2><i class="fas fa-building"></i> Temukan Bank Sampah Terdekat</h2>
                <p>Cari lokasi bank sampah terdekat untuk menyalurkan sampah Anda dan berkontribusi pada lingkungan yang lebih bersih.</p>
            </div>

            <div class="bank-sampah-grid" id="bankSampahGrid">
                </div>
            <p class="no-locations-found" id="noLocationsMessage" style="display: none;">Tidak ada bank sampah yang ditemukan.</p>
        </div>
    </section>
    <section class="points-section-wrapper" id="rewards-section">
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-gem"></i> Tukar Sampah Jadi Hadiah</h1>
                <p>Bergabunglah dalam gerakan hijau dan dapatkan hadiah menarik!</p>
            </div>

            <div id="toastMessage" class="toast-message" role="alert" aria-live="polite">
                <span id="toastText"></span>
            </div>

            <div class="points-section">
                <div class="points-display">
                    <div class="points-number" id="userPoints" tabindex="0" aria-label="Total Green Points Anda"><?php echo htmlspecialchars($user_current_points); ?></div>
                    <div class="points-label">Poin Green Points Anda</div>
                    <div class="action-buttons">
                        <button class="action-btn" onclick="window.location.href='history_reward.php'" aria-label="Lihat Riwayat Transaksi">
    <i class="fas fa-history"></i>
    Riwayat Transaksi
</button>
                        <button class="action-btn" onclick="showAllRewards()" aria-label="Lihat Semua Hadiah yang Tersedia">
                            <i class="fas fa-gift"></i>
                            Tukar Poin
                        </button>
                    </div>
                </div>
            </div>

            <div class="search-filter">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Cari hadiah yang Anda inginkan..." aria-label="Cari Hadiah">
                </div>
                <select class="filter-dropdown" id="filterSelect" aria-label="Filter Hadiah Berdasarkan Kategori">
                    <option value="all">Semua Kategori</option>
                    <option value="voucher">Voucher & Diskon</option>
                    <option value="product">Produk Ramah Lingkungan</option>
                    <option value="experience">Pengalaman & Workshop</option>
                    <option value="other">Lain-lain</option>
                </select>
            </div>

            <div class="rewards-section">
                <h2><i class="fas fa-award"></i> Hadiah Tersedia</h2>
                <div class="rewards-grid" id="rewardsGrid">
                    </div>
            </div>
        </div>
    </section>

    <section class="redemption-explanation-section" id="redemption-explanation-section">
        <div class="container">
            <h2>Penukaran Hadiah yang Mudah dan Menarik</h2>
            <p>
                Nikmati pengalaman menukar poin Anda dengan hadiah-hadiah eksklusif dari GoRako. Setiap langkah dirancang untuk kenyamanan dan kepuasan Anda, dilengkapi dengan antarmuka yang intuitif dan efek visual yang memukau.
            </p>

            <div class="redemption-steps-grid">
                <div class="redemption-step-card">
                    <div class="redemption-step-icon"><i class="fas fa-hand-pointer"></i></div>
                    <h3 class="redemption-step-title">Pilih Hadiah Impian Anda</h3>
                    <p class="redemption-step-description">
                        Jelajahi berbagai pilihan hadiah menarik, mulai dari voucher diskon, produk ramah lingkungan, hingga pengalaman berharga. Cukup klik pada hadiah yang Anda inginkan untuk melihat detailnya. Kartu hadiah akan membesar dengan efek 3D yang halus, menunjukkan Anda stok tersisa dan harga poin.
                    </p>
                </div>

                <div class="redemption-step-card">
                    <div class="redemption-step-icon"><i class="fas fa-check-circle"></i></div>
                    <h3 class="redemption-step-title">Konfirmasi Penukaran dengan Percaya Diri</h3>
                    <p class="redemption-step-description">
                        Setelah memilih hadiah, jendela konfirmasi akan muncul dengan efek latar belakang buram yang elegan. Ini memastikan Anda memiliki semua informasi sebelum melanjutkan. Tombol konfirmasi dilengkapi dengan efek riak (ripple effect) yang interaktif, memberikan respons visual yang memuaskan saat Anda menekan.
                    </p>
                </div>

                <div class="redemption-step-card">
                    <div class="redemption-step-icon"><i class="fas fa-bell"></i></div>
                    <h3 class="redemption-step-title">Notifikasi Instan & Visual yang Menawan</h3>
                    <p class="redemption-step-description">
                        Segera setelah penukaran berhasil, Anda akan menerima notifikasi "toast message" yang muncul dengan animasi lembut dari bawah layar. Pesan ini disertai warna cerah (hijau untuk sukses, merah untuk kesalahan) dan ikon relevan, memberikan umpan balik instan yang jelas dan menyenangkan.
                    </p>
                </div>

                <div class="redemption-step-card">
                    <div class="redemption-step-icon"><i class="fas fa-chart-line"></i></div>
                    <h3 class="redemption-step-title">Poin Anda Otomatis Terbarui</h3>
                    <p class="redemption-step-description">
                        Jumlah Green Points Anda akan secara otomatis diperbarui di dashboard Anda, lengkap dengan animasi perubahan angka yang halus. Anda juga dapat melihat riwayat transaksi lengkap Anda, mencatat setiap penambahan poin dan penukaran hadiah dengan detail tanggal dan waktu.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div id="confirmExchangeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmExchangeModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="modal-title" id="confirmExchangeModalTitle">Konfirmasi Penukaran</div>
            </div>
            <div class="modal-text" id="confirmExchangeModalText">
            </div>
            <div class="modal-buttons">
                <button class="modal-btn confirm" onclick="confirmExchange()" aria-label="Konfirmasi Penukaran Hadiah">Ya, Tukar Sekarang</button>
                <button class="modal-btn cancel" onclick="closeModal('confirmExchangeModal')" aria-label="Batal Penukaran">Batal</button>
            </div>
        </div>
    </div>

    <div id="rewardDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rewardDetailsTitle">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon" id="rewardDetailsIcon"></div>
                <div class="modal-title" id="rewardDetailsTitle"></div>
            </div>
            <div class="modal-text" id="rewardDetailsDescription"></div>
            <div class="modal-buttons">
                <button class="modal-btn confirm" id="rewardDetailsExchangeBtn" aria-label="Tukar Hadiah Ini Sekarang">Tukar Sekarang</button>
                <button class="modal-btn cancel" onclick="closeModal('rewardDetailsModal')" aria-label="Tutup Detail Hadiah">Tutup</button>
            </div>
        </div>
    </div>

    <div id="categoryDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="categoryDetailsTitle">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon" id="categoryDetailsIcon"></div>
                <div class="modal-title" id="categoryDetailsTitle"></div>
            </div>
            <div class="modal-text" id="categoryDetailsDescription"></div>
            <div class="modal-buttons">
                <button class="modal-btn cancel" onclick="closeModal('categoryDetailsModal')" aria-label="Tutup Detail Kategori">Tutup</button>
            </div>
        </div>
    </div>

    <div id="historyModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <div class="history-modal-content">
            <span class="close-button" onclick="closeModal('historyModal')">×</span>
            <h3 id="historyModalTitle">Riwayat Transaksi</h3>
            <ul class="history-list" id="transactionHistoryList">
                </ul>
            <p class="history-modal-empty" id="historyEmptyMessage">Belum ada riwayat transaksi.</p>
        </div>
    </div>

 <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <h3>GoRako</h3>
                <p>Platform edukasi dan pengelolaan sampah untuk lingkungan yang lebih bersih dan berkelanjutan.</p>
                <h4>Ikuti Kami</h4>
                <div class="social-icons">
                    <a href="https://www.instagram.com/go.rako?igsh=Z2czNmVoejNubXl5" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div class="footer-contact">
                <h4>Informasi Kontak</h4>
                <p><i class="fas fa-envelope"></i> gorako.16.edukasi.sampah@gmail.com</p>
                <p><i class="fas fa-map-marker-alt"></i> Cikarang, Indonesia</p>
            </div>
        </div>
        <div class="footer-bottom">
            © 2025 GoRako. Hak Cipta Dilindungi Undang-Undang.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // --- Deklarasi Variabel Global yang Akan Diisi di DOMContentLoaded ---
        let rewardsDataGlobal = [];
        let userPointsGlobal = 0;
        let transactionHistoryGlobal = [];
        let currentExchange = null;
        let currentRewardDetails = null; // Ini juga perlu global


        // --- Fungsi Utilitas Global (Bisa dipanggil dari mana saja) ---

        const categoriesInfo = {
            'Plastik': { icon: 'fas fa-recycle', title: 'Kategori Sampah Plastik', description: 'Sampah plastik adalah salah satu jenis sampah yang paling umum dan membutuhkan waktu lama untuk terurai. Penting untuk memisahkannya untuk didaur ulang. Contohnya termasuk botol minuman, kemasan makanan, kantong plastik, dll. Daur ulang plastik membantu mengurangi polusi dan menghemat sumber daya.' },
            'Kertas': { icon: 'fas fa-file-alt', title: 'Kategori Sampah Kertas', description: 'Sampah kertas seperti koran, majalah, kardus, dan buku sangat cocok untuk didaur ulang. Mendaur ulang kertas mengurangi jumlah pohon yang harus ditebang dan menghemat energi serta air dalam proses produksi kertas baru.' },
            'Organik': { icon: 'fas fa-leaf', title: 'Kategori Sampah Organik', description: 'Sampah organik terdiri dari sisa-sisa makhluk hidup yang mudah terurai secara alami, seperti sisa makanan, daun, ranting, dan kulit buah. Sampah ini ideal untuk dijadikan kompos atau pupuk, yang sangat baik untuk menyuburkan tanah.' },
            'Logam': { icon: 'fas fa-bolt', title: 'Kategori Sampah Logam', description: 'Sampah logam meliputi kaleng minuman, kaleng makanan, potongan besi, aluminium foil, dan sejenisnya. Logam adalah material yang dapat didaur ulang berkali-kali tanpa kehilangan kualitasnya. Daur ulang logam sangat efisien dalam menghemat energi dibandingkan memproduksi logam baru dari bahan mentah.' },
            'Kaca': { icon: 'fas fa-wine-bottle', title: 'Kategori Sampah Kaca', description: 'Sampah kaca seperti botol, toples, dan pecahan kaca dapat didaur ulang sepenuhnya. Kaca daur ulang mengurangi kebutuhan akan bahan baku baru, menghemat energi, dan mengurangi emisi gas rumah kaca. Pastikan kaca bersih dan tidak pecah agar aman untuk didaur ulang.' },
            'Minyak Jelantah': { icon: 'fas fa-oil-can', title: 'Kategori Minyak Jelantah', description: 'Minyak jelantah adalah minyak goreng bekas yang sudah tidak layak pakai. Jangan dibuang ke saluran air! Daur ulang minyak jelantah dapat mencegah pencemaran lingkungan dan diolah menjadi biodiesel atau sabun.' }
        };

        function saveDataToLocalStorage(key, data) {
            try { localStorage.setItem(key, JSON.stringify(data)); }
            catch (e) { console.error("Error saving to localStorage:", e); }
        }

        function loadDataFromLocalStorage(key, defaultValue) {
            const data = localStorage.getItem(key);
            try { return data ? JSON.parse(data) : defaultValue; }
            catch (e) { console.error("Error parsing localStorage data for key:", key, e); return defaultValue; }
        }

        function showToastMessage(message, type = 'success', duration = 3000) {
            const toastMessage = document.getElementById('toastMessage');
            const toastText = document.getElementById('toastText');
            if (!toastMessage || !toastText) return;

            toastText.textContent = message;
            toastMessage.classList.remove('success', 'error', 'info');
            toastMessage.classList.add(type);
            toastMessage.style.display = 'block';
            toastMessage.style.animation = 'toastFadeIn 0.5s ease-out forwards';

            clearTimeout(toastMessage.timer);
            toastMessage.timer = setTimeout(() => {
                toastMessage.style.animation = 'toastFadeOut 0.5s ease-in forwards';
                setTimeout(() => { toastMessage.style.display = 'none'; }, 500);
            }, duration);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                const modalContent = modal.querySelector('.modal-content, .quiz-modal-content, .history-modal-content');
                if (modalContent) {
                    modalContent.style.transform = 'translateY(-50px)';
                    modalContent.style.opacity = '0';
                }
                modal.classList.remove('active');
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
            if (modalId === 'confirmExchangeModal') currentExchange = null;
            if (modalId === 'rewardDetailsModal') currentRewardDetails = null;
        }

        // --- Fungsi Hadiah & Penukaran (Global) ---
        async function performExchangeBackend(rewardId) {
            try {
                const formData = new URLSearchParams();
                formData.append('action', 'exchange_reward');
                formData.append('reward_id', rewardId);

                console.log("Mengirim request penukaran untuk Reward ID:", rewardId);
                const response = await fetch('service_quiz.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error("HTTP error during exchange:", response.status, errorText);
                    throw new Error(`HTTP error! Status: ${response.status}. Response: ${errorText}`);
                }

                const result = await response.json();
                console.log("Respon server setelah penukaran:", result);

                if (result.success) {
                    // Di sini kita TIDAK lagi mengurangi poin atau stok di sisi klien langsung,
                    // karena pengurangan sesungguhnya akan terjadi di checkout.php
                    // Kita hanya memberitahu pengguna bahwa dia akan diarahkan.
                    showToastMessage(result.message, 'success');

                    if (result.redirect_to_checkout && result.reward_id_for_checkout) {
                        console.log("Mengarahkan ke halaman checkout.php dengan reward_id:", result.reward_id_for_checkout);
                        // Redirect ke halaman checkout
                        window.location.href = `checkout.php?reward_id=${result.reward_id_for_checkout}`;
                    }
                    return true;
                } else {
                    console.error("Backend reported failure:", result.error);
                    throw new Error(result.error || 'Unknown error from server');
                }
            } catch (error) {
                console.error("Error in performExchangeBackend:", error);
                showToastMessage(`Gagal menukar hadiah: ${error.message}`, "error", 5000);
                return false;
            }
        }

        function confirmExchange() { // Fungsi ini sekarang global
            if (!currentExchange) {
                console.error("confirmExchange: currentExchange is null.");
                return;
            }
            console.log("Konfirmasi penukaran untuk Reward ID:", currentExchange.id);
            performExchangeBackend(currentExchange.id).then(success => {
                if (success) {
                    closeModal('confirmExchangeModal');
                    // Tidak ada update poin di sini lagi, karena poin akan berkurang di checkout.php
                    // dan history akan dicatat di checkout.php setelah transaksi selesai
                }
            });
        }
        
        function showExchangeModal(rewardId) { // Fungsi ini sekarang global
            const reward = rewardsDataGlobal.find(r => r.id === rewardId);
            if (!reward) {
                console.error("showExchangeModal: Reward not found for ID:", rewardId);
                showToastMessage("Hadiah tidak ditemukan. Silakan coba lagi.", "error");
                return;
            }

            const canAfford = userPointsGlobal >= reward.points;
            const isAvailable = reward.stock > 0;

            if (!canAfford) {
                showToastMessage('Poin Anda tidak cukup untuk menukar hadiah ini.', 'error');
                return;
            }
            if (!isAvailable) {
                showToastMessage('Maaf, stok hadiah ini sudah habis.', 'error');
                return;
            }

            currentExchange = reward; // Simpan hadiah yang akan ditukar

            const modalText = document.getElementById('confirmExchangeModalText');
            if (!modalText) { console.error("showExchangeModal: Element #confirmExchangeModalText not found."); return; }
            modalText.innerHTML = `
                <p><strong>Hadiah:</strong> ${reward.title}</p>
                <p><strong>Harga:</strong> ${reward.points} poin</p>
                <p><strong>Poin Anda Saat Ini:</strong> ${userPointsGlobal} poin</p>
                <br>
                <p>Anda akan diarahkan ke halaman checkout untuk menyelesaikan pesanan ini dan mengkonfirmasi pengurangan poin.</p>
                <br>
                <p>Apakah Anda yakin ingin menukar ${reward.points} poin untuk mendapatkan <strong>${reward.title}</strong>?</p>
            `;

            const confirmModal = document.getElementById('confirmExchangeModal');
            if (!confirmModal) { console.error("showExchangeModal: Element #confirmExchangeModal not found."); return; }

            confirmModal.style.display = 'flex';
            requestAnimationFrame(() => {
                confirmModal.classList.add('active');
                const firstFocusableElement = confirmModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusableElement) { firstFocusableElement.focus(); } else { confirmModal.focus(); }
            });
        }

        function showRewardDetailsModal(rewardId) { // Fungsi ini sekarang global
            const reward = rewardsDataGlobal.find(r => r.id === rewardId);
            if (!reward) {
                 console.error("showRewardDetailsModal: Reward not found for ID:", rewardId);
                 showToastMessage("Detail hadiah tidak dapat dimuat.", "error");
                 return;
            }

            currentRewardDetails = reward;

            document.getElementById('rewardDetailsIcon').innerHTML = `<i class="${reward.icon}"></i>`;
            document.getElementById('rewardDetailsTitle').textContent = reward.title;
            document.getElementById('rewardDetailsDescription').textContent = reward.description;

            const exchangeBtn = document.getElementById('rewardDetailsExchangeBtn');
            const canAfford = userPointsGlobal >= reward.points;
            const isAvailable = reward.stock > 0;

            exchangeBtn.textContent = isAvailable ? `Tukar Sekarang (${reward.points} poin)` : 'Stok Habis';
            exchangeBtn.disabled = !canAfford || !isAvailable;
            exchangeBtn.onclick = () => {
                closeModal('rewardDetailsModal');
                showExchangeModal(reward.id);
            };

            const rewardDetailsModal = document.getElementById('rewardDetailsModal');
            rewardDetailsModal.style.display = 'flex';
            requestAnimationFrame(() => {
                rewardDetailsModal.classList.add('active');
                const firstFocusableElement = rewardDetailsModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusableElement) { firstFocusableElement.focus(); } else { rewardDetailsModal.focus(); }
            });
        }
        
        function updatePointsDisplay(oldPoints = userPointsGlobal, newPoints = userPointsGlobal) { // Fungsi ini sekarang global
            const userPointsElement = document.getElementById('userPoints');
            if (userPointsElement) {
                userPointsElement.classList.add('animated');
                userPointsElement.textContent = newPoints;
                setTimeout(() => { userPointsElement.classList.remove('animated'); }, 500);
            }
        }

        function showHistory() { // Fungsi ini sekarang global
            const historyModal = document.getElementById('historyModal');
            const historyList = document.getElementById('transactionHistoryList');
            const historyEmptyMessage = document.getElementById('historyEmptyMessage');
            historyList.innerHTML = '';

            if (transactionHistoryGlobal.length > 0) {
                historyEmptyMessage.style.display = 'none';
                transactionHistoryGlobal.sort((a, b) => new Date(b.date) - new Date(a.date));
                
                transactionHistoryGlobal.forEach(item => {
                    const date = new Date(item.date).toLocaleString('id-ID', {
                        year: 'numeric', month: 'short', day: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                    const li = document.createElement('li');
                    li.className = `history-item ${item.points > 0 ? 'plus' : 'minus'}`;
                    
                    let itemDetailsHtml = item.description;
                    if (item.type === 'scan') {
                        itemDetailsHtml = `Scan sampah "${item.description.replace('Scan sampah: ', '')}"`;
                    } else if (item.type === 'quiz') {
                        itemDetailsHtml = item.description;
                    } else if (item.type === 'exchange') {
                        itemDetailsHtml = `Tukar hadiah "${item.description.replace('Tukar hadiah "', '').replace('"', '')}"`;
                    }

                    li.innerHTML = `
                        <div class="history-item-details">${itemDetailsHtml}</div>
                        <div class="history-item-points">${item.points > 0 ? '+' : ''}${item.points} poin</div>
                        <div class="history-item-date">${date}</div>
                    `;
                    historyList.appendChild(li);
                });
            } else {
                historyEmptyMessage.style.display = 'block';
            }

            historyModal.style.display = 'flex';
            requestAnimationFrame(() => {
                historyModal.classList.add('active');
                const firstFocusableElement = historyModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusableElement) { firstFocusableElement.focus(); } else { historyModal.focus(); }
            });
        }

        function showAllRewards() { // Fungsi ini sekarang global
            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterSelect');
            if (searchInput) searchInput.value = '';
            if (filterSelect) filterSelect.value = 'all';
            
            filterRewards(); // Panggil fungsi filter

            document.querySelector('.rewards-section').scrollIntoView({ behavior: 'smooth' });
        }

        function displayRewards(rewardsToShow) { // Fungsi ini sekarang global
            const grid = document.getElementById('rewardsGrid');
            if (!grid) return;
            grid.innerHTML = '';

            if (rewardsToShow.length === 0) {
                grid.innerHTML = '<p style="text-align: center; grid-column: 1 / -1; color: #6b7280; font-style: italic;">Tidak ada hadiah yang tersedia atau cocok dengan filter.</p>';
                return;
            }

            rewardsToShow.forEach(reward => {
                const canAfford = userPointsGlobal >= reward.points; // Gunakan global
                const isAvailable = reward.stock > 0;
                const isEnabled = canAfford && isAvailable;

                const rewardCard = document.createElement('div');
                rewardCard.className = 'reward-card fade-in';
                rewardCard.setAttribute('tabindex', '0');
                rewardCard.setAttribute('role', 'button');
                rewardCard.setAttribute('aria-label', `Hadiah: ${reward.title}. Harga: ${reward.points} poin. Stok: ${reward.stock} item.`);
                rewardCard.innerHTML = `
                    <div class="reward-header">
                        <div class="reward-icon">
                            <i class="${reward.icon}"></i>
                        </div>
                        <div class="reward-points">${reward.points} poin</div>
                    </div>
                    <div class="reward-title">${reward.title}</div>
                    <div class="reward-description">${reward.description}</div>
                    <div class="reward-stock ${reward.stock <= 5 && reward.stock > 0 ? 'low-stock' : ''} ${reward.stock === 0 ? 'hidden' : ''}">
                        <i class="fas fa-box"></i>
                        Tersisa ${reward.stock} item
                    </div>
                    <button class="exchange-btn" ${!isEnabled ? 'disabled' : ''} data-reward-id="${reward.id}"
                             aria-label="${!canAfford ? 'Poin Tidak Cukup' : (!isAvailable ? 'Stok Habis' : `Tukar Sekarang untuk ${reward.points} poin`)}">
                        ${!canAfford ? 'Poin Tidak Cukup' : (!isAvailable ? 'Stok Habis' : 'Tukar Sekarang')}
                    </button>
                `;
                grid.appendChild(rewardCard);
            });
            attachRewardCardEventListeners();
        }

        function attachRewardCardEventListeners() { // Fungsi ini sekarang global
            document.querySelectorAll('.exchange-btn').forEach(btn => {
                btn.removeEventListener('click', handleExchangeButtonClick);
                btn.addEventListener('click', handleExchangeButtonClick);
            });

            document.querySelectorAll('.reward-card').forEach(card => {
                card.removeEventListener('click', handleRewardCardClick);
                card.addEventListener('click', handleRewardCardClick);

                card.removeEventListener('keydown', handleRewardCardKeydown);
                card.addEventListener('keydown', handleRewardCardKeydown);
            });
        }

        // Handler event terpisah (juga bisa global atau di-scope yang tepat)
        function handleExchangeButtonClick(e) {
            e.stopPropagation();
            const rewardId = parseInt(this.dataset.rewardId);
            console.log("Tombol tukar diklik untuk reward ID:", rewardId);
            showExchangeModal(rewardId);
        }

        function handleRewardCardClick() {
            const rewardId = parseInt(this.querySelector('.exchange-btn').dataset.rewardId);
            console.log("Kartu diklik untuk reward ID:", rewardId);
            showRewardDetailsModal(rewardId);
        }

        function handleRewardCardKeydown(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const rewardId = parseInt(this.querySelector('.exchange-btn').dataset.rewardId);
                console.log("Keyboard event untuk reward ID:", rewardId);
                showRewardDetailsModal(rewardId);
            }
        }

        function filterRewards() { // Fungsi ini sekarang global
            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterSelect');
            if (!searchInput || !filterSelect) return;

            const searchTerm = searchInput.value.toLowerCase();
            const filterCategory = filterSelect.value;

            let filteredRewards = rewardsDataGlobal.filter(reward => { // Gunakan global
                const matchesSearch = reward.title.toLowerCase().includes(searchTerm) ||
                                     reward.description.toLowerCase().includes(searchTerm);
                const matchesCategory = filterCategory === 'all' || reward.category === filterCategory;

                return matchesSearch && matchesCategory;
            });
            displayRewards(filteredRewards);
        }

        function applyQuizCardEffects() { // Fungsi ini sekarang global
            const quizCards = document.querySelectorAll('.quiz-card');
            quizCards.forEach(card => {
                card.removeEventListener('mousemove', quizCardMouseMoveHandler);
                card.removeEventListener('mouseenter', quizCardMouseEnterHandler);
                card.removeEventListener('mouseleave', quizCardMouseLeaveHandler);

                card.addEventListener('mousemove', quizCardMouseMoveHandler);
                card.addEventListener('mouseenter', quizCardMouseEnterHandler);
                card.addEventListener('mouseleave', quizCardMouseLeaveHandler);
            });
        }

        function quizCardMouseMoveHandler(e) { // Fungsi ini sekarang global
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const rotateX = (y - centerY) / 20;
            const rotateY = (centerX - x) / 20;
            this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-10px)`;
        }

        function quizCardMouseEnterHandler() { // Fungsi ini sekarang global
            const icon = this.querySelector('.quiz-icon');
            if (icon) {
                icon.style.transform = 'scale(1.1) rotate(5deg) translateZ(20px)';
                icon.style.transition = 'all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
            }
        }

        function quizCardMouseLeaveHandler() { // Fungsi ini sekarang global
            const icon = this.querySelector('.quiz-icon');
            if (icon) {
                icon.style.transform = 'scale(1) rotate(0deg) translateZ(0)';
            }
            this.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateY(0px)';
        }

        function displayBankSampahLocations(locationsToShow) { // Fungsi ini sekarang global
            const grid = document.getElementById('bankSampahGrid');
            const noMessage = document.getElementById('noLocationsMessage');

            if (!grid) { console.error("displayBankSampahLocations: Element 'bankSampahGrid' not found."); return; }
            grid.innerHTML = '';

            if (locationsToShow.length === 0) {
                if (noMessage) { noMessage.style.display = 'block'; }
                return;
            } else {
                if (noMessage) { noMessage.style.display = 'none'; }
            }

            locationsToShow.forEach((location, index) => {
                const card = document.createElement('div');
                card.className = `bank-sampah-card ${location.cardColorClass} loaded`;
                card.innerHTML = `
                    <h3 class="bank-sampah-name"><i class="fas fa-building"></i> ${location.name}</h3>
                    <p class="bank-sampah-description">${location.description}</p>
                    <p class="bank-sampah-address"><i class="fas fa-map-marker-alt"></i> ${location.address}</p>
                    <ul class="waste-types-list">
                        ${location.wasteTypes.map(waste => `
                            <li><i class="fas fa-recycle"></i> ${waste.type}: ${waste.price}</li>
                        `).join('')}
                    </ul>
                    <div class="bank-sampah-actions">
                        <a href="${location.mapLink}" class="action-button location-btn" aria-label="Lihat Lokasi ${location.name} di Peta">
                            <i class="fas fa-map-marked-alt"></i> Lokasi Ini
                        </a>
                    </div>
                `;
                grid.appendChild(card);

                const locationBtn = card.querySelector('.location-btn');
                if (locationBtn) {
                    locationBtn.addEventListener('click', function(e) {
                        const rect = this.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        const ripple = document.createElement('span');
                        ripple.className = 'ripple';
                        ripple.style.left = `${x}px`;
                        ripple.style.top = `${y}px`;
                        this.appendChild(ripple);
                        ripple.addEventListener('animationend', () => { ripple.remove(); });
                    });
                }
            });
        }


        // --- DOMContentLoaded Event Listener ---
        document.addEventListener('DOMContentLoaded', async function() {
            // Inisialisasi data global dari PHP di sini
            rewardsDataGlobal = <?php echo json_encode($rewards_from_db); ?>;
            userPointsGlobal = <?php echo json_encode($user_current_points); ?>;
            if (userPointsGlobal === null) { userPointsGlobal = 0; }
            transactionHistoryGlobal = <?php echo json_encode($transaction_history_from_db); ?>;
            if (transactionHistoryGlobal === null) { transactionHistoryGlobal = []; }

            // --- Elements (getting references) ---
            const mobileToggle = document.getElementById('mobileToggle');
            const navMenu = document.getElementById('navMenu');
            const quizHeader = document.querySelector('.quiz-header'); // Deklarasi const quizHeader yang benar
            const navLinks = document.querySelectorAll('.nav-link');
            const navbar = document.querySelector('.navbar');

            // --- Fungsionalitas Umum & Animasi ---
            if (mobileToggle && navMenu) {
                mobileToggle.addEventListener('click', () => {
                    navMenu.classList.toggle('active');
                    const spans = mobileToggle.querySelectorAll('span');
                    if (navMenu.classList.contains('active')) {
                        spans[0].style.transform = 'rotate(45deg) translate(5px, 5px)';
                        spans[1].style.opacity = '0';
                        spans[2].style.transform = 'rotate(-45deg) translate(7px, -6px)';
                    } else {
                        spans[0].style.transform = 'none';
                        spans[1].style.opacity = '1';
                        spans[2].style.transform = 'none';
                    }
                    navMenu.setAttribute('aria-expanded', navMenu.classList.contains('active'));
                });
            }

            if (navLinks.length > 0) {
                navLinks.forEach(link => {
                    link.addEventListener('click', () => {
                        if (navMenu) navMenu.classList.remove('active');
                        if (mobileToggle) {
                            const spans = mobileToggle.querySelectorAll('span');
                            spans[0].style.transform = 'none';
                            spans[1].style.opacity = '1';
                            spans[2].style.transform = 'none';
                        }
                        if (navMenu) navMenu.setAttribute('aria-expanded', 'false');
                    });
                });
            }

            if (navbar) {
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 50) {
                        navbar.style.background = 'rgba(255, 255, 255, 0.98)';
                        navbar.style.boxShadow = '0 2px 30px rgba(0, 0, 0, 0.15)';
                    } else {
                        navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                        navbar.style.boxShadow = '0 2px 20px rgba(0, 0, 0, 0.1)';
                    }
                });
            }

            document.getElementById('quizGridContainer')?.addEventListener('click', function(e) {
                const button = e.target.closest('.quiz-btn');
                if (button && button.dataset.quizId) {
                    e.preventDefault();
                    window.location.href = 'soal_quiz.php?quiz_id=' + button.dataset.quizId;
                }
            });

            applyQuizCardEffects(); // Panggil di sini

            const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
            const globalObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        entry.target.classList.add('visible');
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.quiz-card').forEach((card, index) => {
                card.style.opacity = '0'; card.style.transform = 'translateY(30px)';
                card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                globalObserver.observe(card);
            });
            if (quizHeader) {
                quizHeader.style.opacity = '0'; quizHeader.style.transform = 'translateY(20px)';
                quizHeader.style.transition = 'all 0.8s ease';
                globalObserver.observe(quizHeader);
            }
            document.querySelectorAll('.redemption-step-card').forEach((card, index) => {
                card.style.opacity = '0'; card.style.transform = 'translateY(30px)';
                card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                globalObserver.observe(card);
            });

            displayRewards(rewardsDataGlobal); // Tampilkan hadiah awal
            updatePointsDisplay(userPointsGlobal, userPointsGlobal); // Perbarui tampilan poin awal

            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterSelect');
            if (searchInput) searchInput.addEventListener('input', filterRewards);
            if (filterSelect) filterSelect.addEventListener('change', filterRewards);

            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) { closeModal(this.id); }
                });
                modal.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') { closeModal(this.id); }
                });
            });

            const userPointsElement = document.getElementById('userPoints');
            if (userPointsElement) {
                userPointsElement.addEventListener('click', function() {
                    this.style.transform = 'scale(1.1)';
                    setTimeout(() => { this.style.transform = 'scale(1)'; }, 200);
                });
            }

            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.shiftKey && e.key === 'P') {
                    const oldPoints = userPointsGlobal;
                    userPointsGlobal += 100;
                    transactionHistoryGlobal.push({
                        type: 'bonus',
                        description: 'Bonus Kode Konami',
                        points: 100,
                        date: new Date().toISOString()
                    });
                    saveDataToLocalStorage('transactionHistory', transactionHistoryGlobal);

                    updatePointsDisplay(oldPoints, userPointsGlobal);
                    saveDataToLocalStorage('userPoints', userPointsGlobal);
                    showToastMessage('Bonus 100 poin! ✨', "success");
                }
            });
            
            // --- Fungsionalitas Lokator Bank Sampah ---
            const bankSampahLocations = <?php echo json_encode($bank_sampah_locations_from_db); ?>;
            displayBankSampahLocations(bankSampahLocations);

        }); // Akhir dari DOMContentLoaded
    </script>
</body>
</html>