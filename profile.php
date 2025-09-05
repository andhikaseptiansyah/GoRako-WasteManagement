<?php
require_once 'helpers.php';       // Load helper functions (including session_start(), is_logged_in(), redirect(), etc.)
require_once 'db_connection.php'; // Load database connection

// Fungsi helper untuk mengambil data riwayat dari database
// DIMODIFIKASI untuk mendukung JOIN khusus riwayat kuis dan game dan exchanges
function fetchUserHistory($conn, $userId, $tableName, $selectColumns, $orderByColumn) {
    $history = [];
    $query = "";
    $stmt = null;

    if ($tableName === 'quiz_results') {
        // Query khusus untuk quiz_results yang membutuhkan JOIN ke tabel quizzes
        $query = "SELECT qr.score, qr.timestamp, qr.points_earned, q.title AS quiz_name
                  FROM quiz_results qr
                  JOIN quizzes q ON qr.quiz_id = q.id
                  WHERE qr.user_id = ?
                  ORDER BY qr.`timestamp` DESC";
        $stmt = $conn->prepare($query);
        if (!$stmt) { // *** Penanganan Error PREPARE ***
            error_log("Gagal menyiapkan pernyataan riwayat kuis ($tableName): " . $conn->error . " Query: " . $query);
            return [];
        }
    } else if ($tableName === 'game_scores') {
        // MODIFIED: Menghapus 'gs.points_earned' karena kolom tersebut tidak ada di tabel game_scores.
        // Asumsi: jika tidak ada kolom 'game_name' di game_scores, gunakan string statis atau kombinasikan data lain
        $query = "SELECT gs.score, gs.played_at, 'Game Played' AS description
                  FROM game_scores gs
                  WHERE gs.user_id = ?
                  ORDER BY gs.played_at DESC";
        $stmt = $conn->prepare($query);
        if (!$stmt) { // *** Penanganan Error PREPARE ***
            error_log("Gagal menyiapkan pernyataan riwayat game ($tableName): " . $conn->error . " Query: " . $query);
            return [];
        }
    } else if ($tableName === 'submissions') { // MODIFIED: Query khusus untuk riwayat Bank Sampah (submissions)
        // Kolom yang diambil: earned_points, submission_date, waste_weight_kg, drop_point_name
        $query = "SELECT earned_points, submission_date, waste_weight_kg, drop_point_name
                  FROM submissions
                  WHERE user_id = ?
                  ORDER BY submission_date DESC";
        $stmt = $conn->prepare($query);
        if (!$stmt) { // *** Penanganan Error PREPARE ***
            error_log("Gagal menyiapkan pernyataan riwayat Bank Sampah ($tableName): " . $conn->error . " Query: " . $query);
            return [];
        }
    } else if ($tableName === 'exchanges') { // NEW: Query khusus untuk riwayat Penukaran Hadiah (exchanges)
        // Mengambil deskripsi (pesan_tambahan), nama hadiah, poin digunakan, jumlah item, email penerima, dan tanggal
        $query = "SELECT e.pesan_tambahan, r.name AS reward_name, e.points_used, e.jumlah_item, e.email_penerima, e.checkout_date, e.status AS exchange_status
                  FROM exchanges e
                  JOIN rewards r ON e.reward_id = r.id
                  WHERE e.user_id = ?
                  ORDER BY e.checkout_date DESC";
        $stmt = $conn->prepare($query);
        if (!$stmt) { // *** Penanganan Error PREPARE ***
            error_log("Gagal menyiapkan pernyataan riwayat penukaran ($tableName): " . $conn->error . " Query: " . $query);
            return [];
        }
    }
    else {
        // Query generik untuk tabel lain (misal points_history, modules_completed)
        // Menggunakan backticks untuk nama tabel dan kolom untuk menghindari konflik dengan kata kunci SQL
        // Pastikan $selectColumns memiliki format yang benar dan tidak ada JOIN tersembunyi yang diperlukan
        $query = "SELECT " . implode(", ", $selectColumns) . " FROM `$tableName` WHERE user_id = ? ORDER BY `$orderByColumn` DESC";
        $stmt = $conn->prepare($query);
        if (!$stmt) { // *** Penanganan Error PREPARE ***
            error_log("Gagal menyiapkan pernyataan riwayat generik dari $tableName: " . $conn->error . " Query: " . $query);
            return [];
        }
    }

    if ($stmt) { // Pastikan $stmt berhasil dibuat sebelum dieksekusi
        // Periksa apakah bind_param berhasil, meskipun jarang gagal jika tipe data benar
        if (!$stmt->bind_param("i", $userId)) {
            error_log("Gagal mengikat parameter untuk query $tableName: " . $stmt->error);
            $stmt->close();
            return [];
        }

        // Periksa apakah execute berhasil
        if (!$stmt->execute()) {
            error_log("Gagal mengeksekusi pernyataan riwayat $tableName: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
        }
        $stmt->close();
    }
    return $history;
}

// Jika user TIDAK login, arahkan mereka ke halaman login
if (!is_logged_in()) {
    redirect('login.php');
}

$loggedInUserId = $_SESSION['user_id'];
$loggedInUsername = $_SESSION['username']; // Pastikan ini diset saat login

// --- FETCH DATA PENGGUNA ---
$userData = [];
$totalPoints = 0;
$quizzesCompleted = 0;
$gamesPlayed = 0;
$bankSampahLocations = 0;

// Pastikan koneksi database tersedia sebelum melakukan operasi
if (!$conn || $conn->connect_error) {
    error_log("[profile.php] Koneksi database tidak tersedia atau error saat fetching data pengguna: " . ($conn ? $conn->connect_error : 'null $conn'));
    set_flash_message('error', 'Koneksi database tidak tersedia. Mohon coba lagi nanti.');
    redirect('logout.php');
}

// Ambil data pengguna dan total poin dari tabel users (mengambil 'total_points')
$userQuery = "SELECT id, username, email, profile_picture, total_points FROM users WHERE id = ?";
$stmt = $conn->prepare($userQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    if (!$stmt->execute()) { // Periksa eksekusi
        error_log("Gagal mengeksekusi pernyataan pengguna: " . $stmt->error . " Query: " . $userQuery);
        set_flash_message('error', 'Terjadi kesalahan sistem saat mengambil data profil.');
        redirect('logout.php');
    }
    $userResult = $stmt->get_result();
    if ($userResult->num_rows > 0) {
        $userData = $userResult->fetch_assoc();
        $totalPoints = $userData['total_points'] ?? 0;
    } else {
        set_flash_message('error', 'Data pengguna tidak ditemukan. Silakan login kembali.');
        redirect('logout.php');
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan pengguna (userQuery): " . $conn->error . " Query: " . $userQuery);
    set_flash_message('error', 'Terjadi kesalahan sistem saat mengambil data profil. (Kode: UP-001)');
    redirect('logout.php');
}

// Logika Level: Level ditentukan oleh TOTAL poin saat ini
// Level 1: 0-49 poin
// Level 2: 50-99 poin
// Level 3: 100-149 poin
// dst.
// Formula: floor(total_points / 50) + 1
$userLevel = floor($totalPoints / 50) + 1;

// Poin yang dibutuhkan untuk mencapai level berikutnya (berdasarkan total_points saat ini)
// Contoh: Jika userLevel 1 (0-49 poin), target untuk level 2 adalah 50.
// Jika userLevel 2 (50-99 poin), target untuk level 3 adalah 100.
$pointsNeededForNextLevel = ($userLevel * 50);
$nextLevel = $userLevel + 1; // Untuk tampilan "Level Selanjutnya (Level X)"

// Perhitungan Progress Bar: berapa persen dari 50 poin yang sudah didapatkan di level saat ini (menggunakan total_points saat ini)
$pointsIntoCurrentLevel = $totalPoints - (($userLevel - 1) * 50); // Poin yang sudah terkumpul DI DALAM level saat ini
$pointsPerLevel = 50; // Jumlah poin yang dibutuhkan untuk setiap level

$progressPercentage = ($pointsIntoCurrentLevel / $pointsPerLevel) * 100;
if ($progressPercentage > 100) $progressPercentage = 100; // Pastikan tidak lebih dari 100%
if ($progressPercentage < 0) $progressPercentage = 0; // Pastikan tidak kurang dari 0%

// Menentukan nama gelar berdasarkan rentang level
$currentLevelName = '';
if ($userLevel >= 1 && $userLevel <= 10) {
    $currentLevelName = 'Detektif Sampah Junior';
} elseif ($userLevel >= 11 && $userLevel <= 25) {
    $currentLevelName = 'Pahlawan Daur Ulang';
} elseif ($userLevel >= 26 && $userLevel <= 50) {
    $currentLevelName = 'Master Pengelola Sampah';
} elseif ($userLevel >= 51 && $userLevel <= 100) {
    $currentLevelName = 'Pemimpin Revolusi Hijau';
} elseif ($userLevel >= 101) {
    $currentLevelName = 'Master Daur Ulang Nusantara';
} else {
    $currentLevelName = 'Level ' . $userLevel; // Default jika level tidak masuk rentang
}


// Ambil jumlah kuis yang diselesaikan
$countQuizQuery = "SELECT COUNT(*) AS total_quizzes_completed FROM quiz_results WHERE user_id = ?";
$stmt = $conn->prepare($countQuizQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    if (!$stmt->execute()) {
        error_log("Gagal mengeksekusi pernyataan hitung kuis: " . $stmt->error . " Query: " . $countQuizQuery);
    } else {
        $countResult = $stmt->get_result();
        if ($countResult->num_rows > 0) {
            $quizzesCompleted = $countResult->fetch_assoc()['total_quizzes_completed'];
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan hitung kuis (countQuizQuery): " . $conn->error . " Query: " . $countQuizQuery);
}

// Ambil jumlah game yang dimainkan
$countGamesQuery = "SELECT COUNT(*) AS total_games_played FROM game_scores WHERE user_id = ?";
$stmt = $conn->prepare($countGamesQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    if (!$stmt->execute()) {
        error_log("Gagal mengeksekusi pernyataan hitung game: " . $stmt->error . " Query: " . $countGamesQuery);
    } else {
        $countResult = $stmt->get_result();
        if ($countResult->num_rows > 0) {
            $gamesPlayed = $countResult->fetch_assoc()['total_games_played'];
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan hitung game (countGamesQuery): " . $conn->error . " Query: " . $countGamesQuery);
}

// Ambil jumlah lokasi bank sampah berbeda yang dikunjungi
$countBankSampahQuery = "SELECT COUNT(DISTINCT drop_point_name) AS total_locations FROM submissions WHERE user_id = ?";
$stmt = $conn->prepare($countBankSampahQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    if (!$stmt->execute()) {
        error_log("Gagal mengeksekusi pernyataan hitung bank sampah: " . $stmt->error . " Query: " . $countBankSampahQuery);
    } else {
        $countResult = $stmt->get_result();
        if ($countResult->num_rows > 0) {
            $bankSampahLocations = $countResult->fetch_assoc()['total_locations'];
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan hitung lokasi bank sampah (countBankSampahQuery): " . $conn->error . " Query: " . $countBankSampahQuery);
}

// Ambil rata-rata skor kuis (tambahan untuk ringkasan)
$avgQuizScore = 0;
if ($quizzesCompleted > 0) {
    $avgScoreQuery = "SELECT AVG(score) AS average_score FROM quiz_results WHERE user_id = ?";
    $stmt = $conn->prepare($avgScoreQuery);
    if ($stmt) {
        $stmt->bind_param("i", $loggedInUserId);
        if (!$stmt->execute()) {
            error_log("Gagal mengeksekusi pernyataan rata-rata skor kuis: " . $stmt->error . " Query: " . $avgScoreQuery);
        } else {
            $avgResult = $stmt->get_result();
            if ($avgResult->num_rows > 0) {
                $avgQuizScore = round($avgResult->fetch_assoc()['average_score']);
            }
        }
        $stmt->close();
    } else {
        error_log("Gagal menyiapkan pernyataan rata-rata skor kuis (avgScoreQuery): " . $conn->error . " Query: " . $avgScoreQuery);
    }
}

// Ambil skor tertinggi game (tambahan untuk ringkasan)
$highestGameScore = 0;
if ($gamesPlayed > 0) {
    $highestScoreQuery = "SELECT MAX(score) AS highest_score FROM game_scores WHERE user_id = ?";
    $stmt = $conn->prepare($highestScoreQuery);
    if ($stmt) {
        $stmt->bind_param("i", $loggedInUserId);
        if (!$stmt->execute()) {
            error_log("Gagal mengeksekusi pernyataan skor tertinggi game: " . $stmt->error . " Query: " . $highestScoreQuery);
        } else {
            $highestResult = $stmt->get_result();
            if ($highestResult->num_rows > 0) {
                $highestGameScore = $highestResult->fetch_assoc()['highest_score'];
            }
        }
        $stmt->close();
    } else {
        error_log("Gagal menyiapkan pernyataan skor tertinggi game (highestScoreQuery): " . $conn->error . " Query: " . $highestScoreQuery);
    }
}

// Ambil tanggal terakhir kunjungan bank sampah
$lastBankSampahVisit = 'Belum ada kunjungan';
$lastVisitQuery = "SELECT submission_date FROM submissions WHERE user_id = ? ORDER BY submission_date DESC LIMIT 1";
$stmt = $conn->prepare($lastVisitQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    if (!$stmt->execute()) {
        error_log("Gagal mengeksekusi pernyataan kunjungan bank sampah terakhir: " . $stmt->error . " Query: " . $lastVisitQuery);
    } else {
        $lastVisitResult = $stmt->get_result();
        if ($lastVisitResult->num_rows > 0) {
            $lastVisitDate = new DateTime($lastVisitResult->fetch_assoc()['submission_date']);
            $now = new DateTime();
            $interval = $now->diff($lastVisitDate);
            if ($interval->days == 0) {
                $lastBankSampahVisit = 'Hari ini';
            } elseif ($interval->days == 1) {
                $lastBankSampahVisit = '1 hari lalu';
            } else {
                $lastBankSampahVisit = $interval->days . ' hari lalu';
            }
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan kunjungan bank sampah terakhir (lastVisitQuery): " . $conn->error . " Query: " . $lastVisitQuery);
}


// --- FETCH RIWAYAT MENGGUNAKAN FUNGSI HELPER ---
$pointsHistory = [];
$quizHistory = [];
$rewardsHistory = [];
$modulesHistory = [];
$gameHistory = [];
$bankSampahHistory = [];

// Pastikan $conn masih valid sebelum memanggil fungsi helper
if ($conn && !$conn->connect_error) {
    $pointsHistory = fetchUserHistory($conn, $loggedInUserId, 'points_history', ['description', 'transaction_date', 'points_amount'], 'transaction_date');
    $quizHistory = fetchUserHistory($conn, $loggedInUserId, 'quiz_results', [], 'timestamp');
    $rewardsHistory = fetchUserHistory($conn, $loggedInUserId, 'exchanges', [], 'checkout_date'); // NEW: Fetch rewards history
    $modulesHistory = fetchUserHistory($conn, $loggedInUserId, 'modules_completed', ['module_name', 'completion_date', 'points_earned', 'status'], 'completion_date');
    $gameHistory = fetchUserHistory($conn, $loggedInUserId, 'game_scores', [], 'played_at');
    $bankSampahHistory = fetchUserHistory($conn, $loggedInUserId, 'submissions', [], 'submission_date');
}


// Tutup koneksi database di akhir skrip
if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Pengguna - <?php echo htmlspecialchars($userData['username'] ?? 'Pengguna'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            /* Color Palettes */
            --primary-gradient: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%); /* Green shades */
            --secondary-gradient: linear-gradient(135deg, #8BC34A 0%, #689F38 100%); /* Lighter green shades */
            --white: #ffffff;
            --light-gray: #e8f5e9; /* Very light green-gray */
            --text-color: #212121; /* Dark gray for text */
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --border-radius: 12px;
            --green: #4CAF50; /* Primary green */
            --blue: #1976D2; /* Darker blue for accents */
            --purple: #673AB7; /* Keeping purple for specific icons/elements if desired */
            --orange: #FF9800; /* Keeping orange for specific icons/elements if desired */
            --gold: #FFD700;
            --red-alert: #D32F2F;
            --yellow-warning: #FFEB3B;

            /* Spacing */
            --card-padding: 25px;
            --gap-large: 30px;
            --gap-medium: 20px;
            --gap-small: 10px;

            /* Dark Mode Colors */
            --dark-bg: #1A237E; /* Dark Blue */
            --dark-card-bg: #283593; /* Slightly lighter dark blue for cards */
            --dark-text: #E3F2FD; /* Light blue for text on dark bg */
            --dark-light-gray: #3F51B5; /* Medium dark blue for elements */
            --dark-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-gray);
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s, color 0.3s;
        }

        /* Dark Mode Styles */
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--dark-text);
        }
        body.dark-mode .summary-card,
        body.dark-mode .tab-navigation,
        body.dark-mode .tab-content-wrapper,
        body.dark-mode .module-card,
        body.dark-mode .location-card,
        body.dark-mode .game-card {
            background-color: var(--dark-card-bg);
            box-shadow: var(--dark-shadow);
            color: var(--dark-text);
        }
        body.dark-mode .summary-card h3,
        body.dark-mode .module-card h4,
        body.dark-mode .location-card h4,
        body.dark-mode .game-card h4 {
            color: var(--dark-text);
        }
        body.dark-mode .summary-card .value {
            color: var(--dark-text);
        }
        body.dark-mode .progress-bar-container {
            background-color: var(--dark-light-gray);
        }
        body.dark-mode .tab-button {
            color: var(--dark-text);
        }
        body.dark-mode .tab-button.active,
        body.dark-mode .tab-button:hover {
            background-color: var(--dark-light-gray);
        }
        body.dark-mode .content-item {
            border-bottom-color: #4a4a6e; /* Adjusted for dark blue context */
        }
        body.dark-mode .content-item:hover {
            background-color: #3a3f7a; /* Adjusted for dark blue context */
        }
        body.dark-mode .quiz-bar-item {
            background-color: var(--dark-light-gray);
        }
        body.dark-mode .quiz-bar-item:hover {
            background-color: #3a3f7a; /* Adjusted for dark blue context */
        }
        body.dark-mode .module-card.locked-card {
            background-color: #4a4f8a; /* Adjusted for dark blue context */
        }
        body.dark-mode .empty-state {
            color: var(--dark-text);
        }
        body.dark-mode .empty-state .material-icons {
            color: #7986CB; /* Lighter dark blue for icon */
        }
        body.dark-mode .activity-table {
            background-color: var(--dark-card-bg);
        }
        body.dark-mode .activity-table th {
            background-color: #3949AB; /* Darker blue for table headers */
            color: var(--dark-text);
        }
        body.dark-mode .activity-table tbody tr:nth-child(even) {
            background-color: #2F3E9B; /* Slightly different shade for even rows */
        }
        body.dark-mode .activity-table tbody tr:hover {
            background-color: #3F51B5;
        }
        body.dark-mode .activity-table th, body.dark-mode .activity-table td {
            border-bottom-color: #4a4f8a;
        }
        body.dark-mode .search-filter-container {
            background-color: var(--dark-card-bg);
            box-shadow: var(--dark-shadow);
        }
        body.dark-mode .search-filter-container input[type="text"],
        body.dark-mode .search-filter-container select {
            background-color: #3F51B5;
            border-color: #5C6BC0;
            color: var(--dark-text);
        }
        body.dark-mode .search-filter-container input[type="text"]::placeholder {
            color: #BBDEFB;
        }
        body.dark-mode .search-filter-container input[type="text"]:focus,
        body.dark-mode .search-filter-container select:focus {
            border-color: var(--green); /* Green for focus */
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2); /* Green shadow */
            outline: none;
        }
        body.dark-mode .load-more-button {
            background: var(--secondary-gradient);
            color: var(--white);
        }


        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--gap-medium);
        }

        /* Header Profil */
        .profile-header {
            background: var(--primary-gradient);
            color: var(--white);
            padding: 40px var(--gap-medium);
            border-bottom-left-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--shadow);
            position: relative; /* Added for theme-switcher positioning */
            z-index: 1;
            margin-bottom: var(--gap-medium);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .profile-main-info {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: var(--gap-medium);
            flex-wrap: wrap;
        }

        .profile-picture {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--white);
            box-shadow: 0 4px 10px rgba(0,0,0,0.25);
        }

        .profile-name-level-bio {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .profile-name {
            font-size: 2.5em;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }

        .profile-level {
            font-size: 1.1em;
            font-weight: 600;
            margin-top: 5px;
            color: rgba(255, 255, 255, 0.9);
        }

        .profile-bio {
            font-size: 0.9em;
            opacity: 0.8;
            margin-top: 5px;
        }

        .profile-badge {
            background-color: var(--gold); /* Keeping gold for badge, as it's a standard "achievement" color */
            color: #333;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: var(--gap-small);
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: transform 0.2s;
        }
        .profile-badge:hover {
            transform: translateY(-2px);
        }

        /* Utility for theme switcher */
        .theme-switcher {
            position: absolute;
            top: var(--gap-medium);
            right: var(--gap-medium);
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            padding: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        .theme-switcher:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .theme-switcher .material-icons {
            font-size: 1.5em;
            color: var(--white);
        }
        body.dark-mode .theme-switcher {
            background: rgba(0, 0, 0, 0.2);
        }
        body.dark-mode .theme-switcher:hover {
            background: rgba(0, 0, 0, 0.3);
        }

        /* Informasi Ringkas Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: var(--gap-medium);
            margin-top: -80px;
            position: relative;
            z-index: 2;
        }

        .summary-card {
            background-color: var(--white);
            padding: var(--card-padding);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.2s ease-in-out, background-color 0.3s, box-shadow 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .summary-card:hover {
            transform: translateY(-5px);
        }

        .summary-card .card-title-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: var(--gap-small);
        }

        .summary-card .card-title-icon .material-icons {
            font-size: 1.8em;
            color: var(--green); /* Changed to green */
        }

        .summary-card h3 {
            margin: 0;
            font-size: 1.1em;
            color: #555;
            font-weight: 500;
        }
        body.dark-mode .summary-card h3 {
            color: var(--dark-text);
        }

        .summary-card .value {
            font-size: 2em;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: var(--gap-small);
        }

        .progress-bar-container {
            width: 100%;
            background-color: var(--light-gray);
            border-radius: 5px;
            height: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-bar {
            height: 100%;
            background: var(--secondary-gradient);
            border-radius: 5px;
            width: 0;
            transition: width 1s ease-out;
        }

        .progress-text {
            font-size: 0.9em;
            text-align: right;
            margin-top: 5px;
            font-weight: 500;
            color: #666;
        }
        body.dark-mode .progress-text {
            color: var(--dark-text);
        }

        /* Tab Navigasi */
        .tab-navigation {
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-top: var(--gap-large);
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            padding: var(--gap-small);
            position: sticky;
            top: 0;
            z-index: 10;
            transition: background-color 0.3s, box-shadow 0.3s;
        }

        .tab-button {
            background: none;
            border: none;
            padding: 12px 20px;
            font-size: 1em;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            border-radius: 8px;
            position: relative;
            transition: color 0.3s, background-color 0.3s;
            margin: 5px;
            outline: none;
        }

        .tab-button::after {
            content: '';
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: 0;
            width: 0;
            height: 3px;
            background: var(--green); /* Changed to green */
            border-radius: 2px;
            transition: width 0.3s ease-in-out;
        }

        .tab-button.active,
        .tab-button:hover {
            color: var(--green); /* Changed to green */
            background-color: #e8f5e9; /* Light green hover/active */
        }
        body.dark-mode .tab-button.active,
        body.dark-mode .tab-button:hover {
            background-color: var(--dark-light-gray);
            color: var(--dark-text);
        }
        body.dark-mode .tab-button.active::after,
        body.dark-mode .tab-button:hover::after {
            background: var(--white); /* White underline in dark mode */
        }


        .tab-button.active::after,
        .tab-button:hover::after {
            width: 80%;
        }


        /* Konten Per Tab */
        .tab-content-wrapper {
            margin-top: var(--gap-medium);
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: var(--card-padding);
            transition: background-color 0.3s, box-shadow 0.3s;
        }

        .tab-pane {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }

        .tab-pane.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .content-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .content-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s ease-in-out;
            cursor: pointer;
        }
        body.dark-mode .content-item {
            border-bottom-color: #4a4a6e;
        }

        .content-item:last-child {
            border-bottom: none;
        }

        .content-item:hover {
            background-color: #f9f9f9;
        }
        body.dark-mode .content-item:hover {
            background-color: #3a3a5a;
        }

        .item-icon {
            font-size: 1.8em;
            min-width: 30px;
            text-align: center;
        }

        .item-text {
            flex-grow: 1;
        }

        .item-text strong {
            display: block;
            font-size: 1.1em;
            margin-bottom: 3px;
        }

        .item-meta {
            font-size: 0.9em;
            color: #777;
        }
        body.dark-mode .item-meta {
            color: #b0b0b0;
        }

        .item-score {
            font-weight: 600;
            color: var(--green);
            flex-shrink: 0;
        }

        /* Specific colors for icons */
        .icon-green { color: var(--green); }
        .icon-blue { color: var(--blue); } /* Retaining for quiz icon */
        .icon-purple { color: var(--purple); } /* Retaining for game icon */
        .icon-orange { color: var(--orange); } /* Retaining for location icon */
        .icon-gold { color: var(--gold); } /* For rewards/exchange */


        /* Quiz History - Horizontal Bars */
        .quiz-bar-item {
            background-color: var(--light-gray);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            transition: transform 0.2s ease-in-out, background-color 0.2s;
            cursor: pointer;
        }

        .quiz-bar-item:hover {
            transform: translateX(5px);
            background-color: #e9ecef;
        }
        body.dark-mode .quiz-bar-item:hover {
            background-color: #3a3a5a;
        }

        .quiz-bar-item strong {
            font-size: 1.1em;
        }

        .quiz-bar-item .meta {
            font-size: 0.9em;
            color: #777;
        }
        body.dark-mode .quiz-bar-item .meta {
            color: #b0b0b0;
        }

        /* Modules - Cards */
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--gap-medium);
        }

        .module-card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: var(--card-padding);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 120px;
            transition: transform 0.2s ease-in-out, background-color 0.3s, box-shadow 0.3s;
            position: relative;
        }

        .module-card:hover {
            transform: translateY(-5px);
        }

        .module-card h4 {
            margin: 0 0 var(--gap-small);
            font-size: 1.2em;
            font-weight: 600;
        }

        .module-status {
            font-size: 0.9em;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 20px;
            align-self: flex-start;
        }

        .status-completed {
            background-color: #d4edda;
            color: var(--green);
        }

        .status-in-progress {
            background-color: #fff3cd;
            color: var(--yellow-warning);
        }

        .status-locked {
            background-color: #f8d7da;
            color: var(--red-alert);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .module-card .lock-icon {
            position: absolute;
            top: var(--gap-small);
            right: var(--gap-small);
            font-size: 1.5em;
            color: #aaa;
        }
        body.dark-mode .module-card .lock-icon {
            color: #6a6a8e;
        }

        .module-card.locked-card {
            opacity: 0.7;
            cursor: not-allowed;
            background-color: #f8f8f8; /* Lighter background for locked */
        }
        body.dark-mode .module-card.locked-card {
            background-color: #3a3a5a;
        }

        .module-card.locked-card:hover {
            transform: none;
        }


        /* Bank Sampah & Game History - Cards */
        .location-card, .game-card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: var(--card-padding);
            display: flex;
            flex-direction: column;
            gap: var(--gap-small);
            transition: transform 0.2s ease-in-out, background-color 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }

        .location-card:hover, .game-card:hover {
            transform: translateY(-5px);
        }

        .location-card h4, .game-card h4 {
            margin: 0;
            font-size: 1.2em;
            font-weight: 600;
        }

        .location-card p, .game-card p {
            margin: 0;
            font-size: 0.95em;
            color: #666;
        }
        body.dark-mode .location-card p,
        body.dark-mode .game-card p {
            color: #b0b0b0;
        }

        .location-meta {
            font-size: 0.85em;
            color: #888;
        }
        body.dark-mode .location-meta {
            color: #a0a0a0;
        }

        /* Call to Action Buttons */
        .cta-button {
            background: var(--secondary-gradient);
            color: var(--white);
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            margin-top: var(--gap-small);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .cta-button .material-icons {
            font-size: 1.2em;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            font-size: 1.1em;
            display: none; /* Hidden by default, managed by JS */
        }
        body.dark-mode .empty-state {
            color: var(--dark-text);
        }
        .empty-state .material-icons {
            font-size: 3em;
            color: #ccc;
            margin-bottom: var(--gap-small);
        }
        body.dark-mode .empty-state .material-icons {
            color: #7986CB; /* Lighter dark blue for icon */
        }

        /* New styles from profile.php's original CSS that are still relevant */
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            border-radius: 10px;
            overflow: hidden; /* Pastikan konten tidak meluber dari border-radius */
        }

        .activity-table th, .activity-table td {
            padding: 15px 20px; /* Default padding for larger screens */
            text-align: left;
            border-bottom: 1px solid #eee; /* Light gray border */
            /* Removed white-space: nowrap; to allow titles to wrap by default */
        }
        body.dark-mode .activity-table th, body.dark-mode .activity-table td {
            border-bottom: 1px solid #4a4f8a; /* Dark blue border */
        }


        .activity-table th {
            background-color: #f5f5f5;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            font-size: 0.9em;
            cursor: pointer;
            position: relative;
        }

        .activity-table th:hover {
            background-color: #e8e8e8;
        }

        .activity-table th .fas {
            margin-left: 5px;
            font-size: 0.8em;
            color: #999;
        }

        .activity-table tbody tr:nth-child(even) {
            background-color: #fcfcfc;
        }

        .activity-table tbody tr {
            transition: background-color 0.3s ease;
            cursor: pointer;
        }

        .activity-table tbody tr:hover {
            background-color: #f0f0f0;
        }

        .activity-table tbody tr:last-child td {
            border-bottom: none;
        }

        .points-earned.positive {
            color: var(--green);
            font-weight: 700;
        }

        .points-earned.negative {
            color: var(--red-alert);
            font-weight: 700;
        }

        /* Tambahan CSS untuk responsivitas tabel */
        /* Mengatur lebar kolom agar lebih proporsional, terutama pada layar yang lebih besar */
        .activity-table th:nth-child(1),
        .activity-table td:nth-child(1) {
            width: 40%; /* Menambah lebar untuk Deskripsi/Nama */
            /* Hapus max-width agar bisa melebar lebih jauh jika ada ruang */
            white-space: normal; /* Izinkan teks membungkus */
            word-break: break-word; /* Memecah kata jika terlalu panjang */
        }

        .activity-table th:nth-child(2),
        .activity-table td:nth-child(2) {
            width: 25%; /* Sesuaikan lebar tanggal */
            white-space: nowrap; /* Tanggal biasanya pendek, nowrap lebih baik */
        }
        .activity-table th:nth-child(3),
        .activity-table td:nth-child(3) {
            width: 20%; /* Poin Dihasilkan */
            white-space: nowrap; /* Poin biasanya pendek, nowrap lebih baik */
        }
        /* New column widths for rewards history */
        .activity-table.rewards-history-table th:nth-child(1),
        .activity-table.rewards-history-table td:nth-child(1) { /* Hadiah */
            width: 30%; /* Menambah lebar untuk Hadiah */
            white-space: normal; /* Allow wrapping for long reward names */
            word-break: break-word;
        }
        .activity-table.rewards-history-table th:nth-child(2),
        .activity-table.rewards-history-table td:nth-child(2) { /* Tanggal */
            width: 15%; /* Sesuaikan lebar tanggal */
            white-space: nowrap;
        }
        .activity-table.rewards-history-table th:nth-child(3),
        .activity-table.rewards-history-table td:nth-child(3) { /* Poin Digunakan */
            width: 15%;
            white-space: nowrap;
        }
        .activity-table.rewards-history-table th:nth-child(4),
        .activity-table.rewards-history-table td:nth-child(4) { /* Jumlah */
            width: 8%;
            white-space: nowrap;
        }
        .activity-table.rewards-history-table th:nth-child(5),
        .activity-table.rewards-history-table td:nth-child(5) { /* Email */
            width: 15%; /* Sesuaikan lebar email */
            font-size: 0.8em; /* Make email smaller to fit */
            white-space: nowrap; /* Prefer single line */
            overflow: hidden;   /* Hide overflow to prevent visual breakage */
            text-overflow: ellipsis; /* Add ellipsis to show it's truncated, as 'no clipping' is impossible without unlimited width */
            min-width: 100px; /* Give it a reasonable minimum width */
        }
        .activity-table.rewards-history-table th:nth-child(6),
        .activity-table.rewards-history-table td:nth-child(6) { /* Status */
            width: 17%; /* Sesuaikan lebar status */
            white-space: nowrap;
        }


        /* Responsif */
        @media (max-width: 768px) {
            .profile-header {
                padding: 30px 15px;
            }

            .profile-main-info {
                flex-direction: column;
                gap: var(--gap-small);
            }

            .profile-picture {
                width: 70px;
                height: 70px;
            }

            .profile-name-level-bio {
                align-items: center;
            }

            .profile-name {
                font-size: 2em;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                margin-top: var(--gap-medium);
            }

            .tab-navigation {
                flex-direction: column;
                align-items: stretch;
            }

            .tab-button {
                margin: 5px 0;
            }

            .module-grid {
                grid-template-columns: 1fr;
            }
            .theme-switcher {
                top: 15px;
                right: 15px;
                padding: 6px;
            }
            .theme-switcher .material-icons {
                font-size: 1.3em;
            }
            .search-filter-container {
                flex-direction: column;
                gap: 10px;
            }

            .search-filter-container input[type="text"],
            .search-filter-container select {
                width: 100%;
                min-width: unset;
            }
            .activity-table th, .activity-table td {
                padding: 10px 10px; /* Kurangi padding pada layar kecil */
                font-size: 0.85em; /* Kecilkan ukuran font sedikit */
                white-space: normal; /* Default untuk semua sel, izinkan membungkus */
            }

            /* Responsive table container for horizontal scrolling */
            .table-responsive {
                width: 100%;
                overflow-x: auto; /* Enables horizontal scrolling */
                -webkit-overflow-scrolling: touch; /* Improves scrolling on iOS */
            }

            /* Pastikan kolom judul membungkus */
            .activity-table td:nth-child(1) { /* This applies to descriptions/names across most tables */
                white-space: normal;
                word-break: break-word; /* Memecah kata jika terlalu panjang */
                min-width: 150px; /* Berikan min-width agar tidak terlalu sempit di mobile */
            }

            /* Specific adjustments for rewards history table on mobile */
            .activity-table.rewards-history-table th,
            .activity-table.rewards-history-table td {
                min-width: 70px; /* Adjust min-width for columns on small screens */
            }

            .activity-table.rewards-history-table td:nth-child(1) { /* Hadiah */
                min-width: 100px; /* Give more space for the reward name on mobile */
                white-space: normal; /* Allow wrapping */
                word-break: break-word;
            }

            .activity-table.rewards-history-table td:nth-child(5) { /* Email */
                font-size: 0.65em; /* Even smaller font for email on very small screens */
                min-width: 80px; /* Ensure enough space for email */
            }

            /* Ensure other critical data stays on one line where possible, allowing horizontal scroll */
            .activity-table.rewards-history-table td:nth-child(2), /* Tanggal Penukaran */
            .activity-table.rewards-history-table td:nth-child(3), /* Poin Digunakan */
            .activity-table.rewards-history-table td:nth-child(4), /* Jumlah */
            .activity-table.rewards-history-table td:nth-child(6) { /* Status */
                white-space: nowrap; /* Keep these on one line */
            }

            /* General table cell adjustments for mobile */
            .activity-table th, .activity-table td {
                padding: 8px 5px; /* Reduce padding further for very small screens */
            }
        }
    </style>
</head>
<body>
    <div class="profile-header">
        <div class="theme-switcher" onclick="toggleDarkMode()" title="Toggle Dark/Light Mode">
            <span class="material-icons">dark_mode</span>
        </div>
        <div class="profile-main-info">
            <img src="<?php echo htmlspecialchars($userData['profile_picture'] ?? 'https://i.pravatar.cc/150?img=68'); ?>" alt="Foto Profil <?php echo htmlspecialchars($userData['username'] ?? 'Pengguna'); ?>" class="profile-picture">
            <div class="profile-name-level-bio">
                <h1 class="profile-name"><?php echo htmlspecialchars($userData['username'] ?? 'Pengguna'); ?></h1>
                <p class="profile-level" id="profileLevel">Level <?php echo $userLevel; ?>: <?php echo htmlspecialchars($currentLevelName); ?></p>
                <p>Member GoRako</p>
            </div>
            <div class="profile-badge" title="Peringkat tertinggi dalam kategori ini">
                <span class="material-icons">emoji_events</span>
                GoRako Warrior
            </div>
        </div>
    </div>

    <div class="container">
        <div class="summary-grid">
            <div class="summary-card">
                <div class="card-title-icon">
                    <span class="material-icons icon-green">star</span>
                    <h3>Total Poin</h3>
                </div>
                <div class="value" id="totalPointsDisplay"><?php echo number_format($totalPoints); ?> poin</div>
                <p>Poin untuk Level Selanjutnya (Level <?php echo $nextLevel; ?>): <span id="pointsForNextLevelTarget" style="font-weight: 600;"><?php echo number_format($pointsNeededForNextLevel); ?></span></p>
                <div class="progress-bar-container">
                    <div class="progress-bar" id="progressBarPoints" data-progress="<?php echo round($progressPercentage); ?>"></div>
                </div>
                <p class="progress-text" id="progressText"><?php echo round($progressPercentage); ?>%</p>
                <button class="cta-button" onclick="window.location.href='modules.php';"><span class="material-icons">trending_up</span> Dapatkan Poin Lain</button>
            </div>
            <div class="summary-card">
                <div class="card-title-icon">
                    <span class="material-icons icon-blue">quiz</span>
                    <h3>Total Quiz</h3>
                </div>
                <div class="value"><?php echo number_format($quizzesCompleted); ?></div>
                <p>Rata-rata skor: <span style="font-weight: 600; color: var(--blue);"><?php echo number_format($avgQuizScore); ?>%</span></p>
                <button class="cta-button"><span class="material-icons">add_task</span> Mulai Quiz Baru</button>
            </div>
            <div class="summary-card">
                <div class="card-title-icon">
                    <span class="material-icons icon-purple">sports_esports</span>
                    <h3>Game Dimainkan</h3>
                </div>
                <div class="value"><?php echo number_format($gamesPlayed); ?></div>
                <p>Skor tertinggi: <span style="font-weight: 600; color: var(--purple);"><?php echo number_format($highestGameScore); ?></span></p>
                <button class="cta-button"><span class="material-icons">play_circle</span> Mainkan Game Lain</button>
            </div>
            <div class="summary-card">
                <div class="card-title-icon">
                    <span class="material-icons icon-orange">location_on</span>
                    <h3>Bank Sampah</h3>
                </div>
                <div class="value"><?php echo number_format($bankSampahLocations); ?> lokasi berbeda</div>
                <p>Terakhir kunjungan: <?php echo htmlspecialchars($lastBankSampahVisit); ?></p>
                <button class="cta-button"><span class="material-icons">place</span> Cari Bank Sampah</button>
            </div>
        </div>

        <div class="tab-navigation">
            <button class="tab-button active" data-tab="riwayat-poin" onclick="openTab(event, 'riwayat-poin')">Riwayat Poin</button>
            <button class="tab-button" data-tab="riwayat-quiz" onclick="openTab(event, 'riwayat-quiz')">Riwayat Quiz</button>
            <button class="tab-button" data-tab="modul-pembelajaran" onclick="openTab(event, 'modul-pembelajaran')">Modul</button>
            <button class="tab-button" data-tab="bank-sampah" onclick="openTab(event, 'bank-sampah')">Bank Sampah</button>
            <button class="tab-button" data-tab="riwayat-game" onclick="openTab(event, 'riwayat-game')">Game</button>
            <button class="tab-button" data-tab="riwayat-penukaran" onclick="openTab(event, 'riwayat-penukaran')">Penukaran</button> </div>

        <div class="tab-content-wrapper">
            <div id="riwayat-poin" class="tab-pane active">
                <h2>Riwayat Poin</h2>
                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive"> <table class="activity-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="description">Deskripsi Aktivitas</th>
                                    <th data-sort-by="date">Tanggal Aktivitas</th>
                                    <th data-sort-by="points">Poin Dihasilkan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pointsHistory)): ?>
                                    <?php foreach ($pointsHistory as $record): ?>
                                        <tr data-points-type="<?php echo ($record['points_amount'] >= 0) ? 'positive' : 'negative'; ?>"
                                            data-detail="Poin: <?php echo htmlspecialchars(($record['points_amount'] >= 0 ? '+' : '') . number_format($record['points_amount'])); ?>">
                                            <td><?php echo htmlspecialchars($record['description']); ?></td>
                                            <td><?php echo date('d F Y', strtotime($record['transaction_date'])); ?></td>
                                            <td class="points-earned <?php echo ($record['points_amount'] >= 0) ? 'positive' : 'negative'; ?>">
                                                <?php echo ($record['points_amount'] >= 0 ? '+' : '') . number_format($record['points_amount']); ?> poin
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 20px;">Tidak ada riwayat poin.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="riwayat-quiz" class="tab-pane">
                <h2>Riwayat Quiz</h2>
                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive"> <table class="activity-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="name">Nama Kuis</th>
                                    <th data-sort-by="date">Tanggal Selesai</th>
                                    <th data-sort-by="score">Skor</th>
                                    <th data-sort-by="points">Poin Dihasilkan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($quizHistory)): ?>
                                    <?php foreach ($quizHistory as $record): ?>
                                        <tr data-score="<?php echo htmlspecialchars($record['score']); ?>" data-detail="Skor: <?php echo htmlspecialchars($record['score']); ?>% | Poin: +<?php echo number_format($record['points_earned']); ?>">
                                            <td><?php echo htmlspecialchars($record['quiz_name']); ?></td>
                                            <td><?php echo date('d F Y', strtotime($record['timestamp'])); ?></td>
                                            <td><?php echo htmlspecialchars($record['score']); ?>%</td>
                                            <td class="points-earned positive">+<?php echo number_format($record['points_earned']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 20px;">Tidak ada riwayat kuis.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

            <div id="modul-pembelajaran" class="tab-pane">
                <h2>Modul Pembelajaran</h2>
                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive"> <table class="activity-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="name">Nama Modul</th>
                                    <th data-sort-by="date">Tanggal Selesai</th>
                                    <th data-sort-by="points">Poin Dihasilkan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($modulesHistory)): ?>
                                    <?php foreach ($modulesHistory as $record): ?>
                                        <tr data-status="<?php echo htmlspecialchars($record['status']); ?>" data-detail="Status: <?php echo htmlspecialchars(ucfirst($record['status'])); ?> | Poin: +<?php echo number_format($record['points_earned']); ?>">
                                            <td><?php echo htmlspecialchars($record['module_name']); ?></td>
                                            <td><?php echo date('d F Y', strtotime($record['completion_date'])); ?></td>
                                            <td class="points-earned positive">+<?php echo number_format($record['points_earned']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 20px;">Tidak ada riwayat modul.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="bank-sampah" class="tab-pane">
                <h2>Bank Sampah</h2>

                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive"> <table class="activity-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="date">Tanggal Submit</th>
                                    <th data-sort-by="drop_point_name">Nama Bank Sampah</th>
                                    <th data-sort-by="weight">Total Kg</th>
                                    <th data-sort-by="points">Poin Dihasilkan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($bankSampahHistory)): ?>
                                    <?php foreach ($bankSampahHistory as $record): ?>
                                        <tr data-drop-point="<?php echo htmlspecialchars($record['drop_point_name']); ?>" data-detail="Total Kg: <?php echo htmlspecialchars(number_format($record['waste_weight_kg'], 2)); ?> kg | Lokasi: <?php echo htmlspecialchars($record['drop_point_name']); ?> | Poin: +<?php echo number_format($record['earned_points']); ?>">
                                            <td><?php echo date('d F Y H:i', strtotime($record['submission_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($record['drop_point_name']); ?></td>
                                            <td><?php echo htmlspecialchars(number_format($record['waste_weight_kg'], 2)); ?> kg</td>
                                            <td class="points-earned positive">+<?php echo number_format($record['earned_points']); ?> poin</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 20px;">Tidak ada riwayat penukaran sampah.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="empty-state" id="emptyBankSampah">
                    <span class="material-icons">location_off</span>
                    <p>Anda belum memiliki riwayat kunjungan bank sampah.</p>
                    <button class="cta-button"><span class="material-icons">add_location_alt</span> Tambahkan Lokasi Bank Sampah</button>
                </div>
            </div>

            <div id="riwayat-game" class="tab-pane">
                <h2>Riwayat Game</h2>
                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive"> <table class="activity-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="description">Deskripsi</th>
                                    <th data-sort-by="date">Tanggal Main</th>
                                    <th data-sort-by="score">Skor</th>
                                    </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($gameHistory)): ?>
                                    <?php foreach ($gameHistory as $record): ?>
                                        <tr data-points-type="<?php echo ($record['score'] >= 0) ? 'positive' : 'negative'; ?>" data-detail="Skor: <?php echo htmlspecialchars(number_format($record['score'])); ?>">
                                            <td><?php echo htmlspecialchars($record['description'] ?? 'Game Played'); ?></td>
                                            <td><?php echo date('d F Y H:i', strtotime($record['played_at'])); ?></td>
                                            <td class="points-earned <?php echo ($record['score'] >= 0) ? 'positive' : 'negative'; ?>"><?php echo number_format($record['score']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 20px;">Tidak ada riwayat game.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="empty-state" id="emptyGameHistory">
                    <span class="material-icons">casino</span>
                    <p>Anda belum memainkan game apapun.</p>
                    <button class="cta-button"><span class="material-icons">play_arrow</span> Mainkan Game Pertama Anda</button>
                </div>
            </div>

            <div id="riwayat-penukaran" class="tab-pane">
                <h2>Riwayat Penukaran Hadiah</h2>
                <div style="position: relative;">
                    <div class="loading-overlay"><div class="loader"></div></div>
                    <div class="table-responsive">
                        <table class="activity-table rewards-history-table" data-rows-per-page="5">
                            <thead>
                                <tr>
                                    <th data-sort-by="reward_name">Hadiah</th>
                                    <th data-sort-by="date">Tanggal Penukaran</th>
                                    <th data-sort-by="points_used">Poin Digunakan</th>
                                    <th data-sort-by="jumlah_item">Jumlah</th>
                                    <th data-sort-by="email_penerima">Email</th>
                                    <th data-sort-by="status">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rewardsHistory)): ?>
                                    <?php foreach ($rewardsHistory as $record): ?>
                                        <tr data-status="<?php echo htmlspecialchars($record['exchange_status']); ?>"
                                            data-detail="Hadiah: <?php echo htmlspecialchars($record['reward_name']); ?> (x<?php echo htmlspecialchars($record['jumlah_item']); ?>) | Poin: -<?php echo number_format($record['points_used']); ?> | Email: <?php echo htmlspecialchars($record['email_penerima']); ?> | Status: <?php echo htmlspecialchars(ucfirst($record['exchange_status'])); ?>">
                                            <td><?php echo htmlspecialchars($record['reward_name']); ?></td>
                                            <td><?php echo date('d F Y H:i', strtotime($record['checkout_date'])); ?></td>
                                            <td class="points-earned negative">-<?php echo number_format($record['points_used']); ?></td>
                                            <td><?php echo htmlspecialchars($record['jumlah_item']); ?></td>
                                            <td><?php echo htmlspecialchars($record['email_penerima']); ?></td>
                                            <td>
                                                <?php
                                                    $statusText = ucfirst($record['exchange_status']);
                                                    $statusClass = '';
                                                    switch($record['exchange_status']) {
                                                        case 'pending': $statusClass = 'text-orange-600'; break;
                                                        case 'approved': $statusClass = 'text-blue-600'; break;
                                                        case 'sent': $statusClass = 'text-green-600'; break;
                                                        case 'rejected': $statusClass = 'text-red-600'; break;
                                                        default: $statusClass = 'text-gray-600'; break;
                                                    }
                                                ?>
                                                <span class="<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px;">Tidak ada riwayat penukaran hadiah.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="empty-state" id="emptyRiwayatPenukaran">
                    <span class="material-icons">card_giftcard</span>
                    <p>Anda belum menukarkan hadiah apapun.</p>
                    <button class="cta-button" onclick="window.location.href='rewards.php';"><span class="material-icons">redeem</span> Tukar Hadiah Sekarang</button>
                </div>
            </div>
            </div>
    </div>

    <script>
        // --- Tab Switching Logic (from profile.html, slightly modified) ---
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-pane");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tab-button");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
                tablinks[i].setAttribute('aria-selected', 'false');
            }

            const targetTabContent = document.getElementById(tabName);
            targetTabContent.style.display = "block";
            evt.currentTarget.className += " active";
            evt.currentTarget.setAttribute('aria-selected', 'true');

            // Simulate loading and then apply filter/pagination for the newly active tab
            simulateLoading(targetTabContent);

            const table = targetTabContent.querySelector('.activity-table');
            const searchInput = targetTabContent.querySelector('input[type="text"]');
            const filterSelect = targetTabContent.querySelector('select');

            if (table) {
                // Ensure originalRows are stored before filtering/pagination
                // Only store if it's currently empty or has the "no data" message
                const currentRowsInTableBody = Array.from(table.tBodies[0].children);
                if (!table.dataset.originalRows || table.dataset.originalRows === '[]' || (currentRowsInTableBody.length === 1 && currentRowsInTableBody[0].textContent.includes('Tidak ada'))) {
                    const initialRows = Array.from(table.tBodies[0].children).map(row => row.outerHTML);
                    if (initialRows.length === 1 && initialRows[0].includes('colspan="')) { // Check for "no data" message
                         table.dataset.originalRows = '[]'; // Set to empty array if no real data
                    } else {
                        table.dataset.originalRows = JSON.stringify(initialRows);
                    }
                }
                applyFilterAndPagination(table, searchInput, filterSelect, true);
            }

            // Check for empty state on tab switch
            const emptyStateElementId = 'empty' + tabName.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join('').replace('Riwayat', '').replace('Pembelajaran', 'Module');
            const finalEmptyStateId = emptyStateElementId.includes('BankSampah') ? 'emptyBankSampah' : (emptyStateElementId.includes('Game') ? 'emptyGameHistory' : (emptyStateElementId.includes('Penukaran') ? 'emptyRiwayatPenukaran' : null)); // Added for rewards

            const emptyStateElement = finalEmptyStateId ? document.getElementById(finalEmptyStateId) : null;
            const tableContainer = targetTabContent.querySelector('.table-responsive');
            const searchFilterContainer = targetTabContent.querySelector('.search-filter-container');
            const paginationContainer = targetTabContent.querySelector('.pagination-container');

            if (emptyStateElement) {
                const tableRef = targetTabContent.querySelector('.activity-table');
                // Check if the original data was truly empty (i.e., PHP passed no data)
                const isOriginalDataEmpty = tableRef && (tableRef.dataset.originalRows === '[]' || JSON.parse(tableRef.dataset.originalRows).length === 0);

                if (isOriginalDataEmpty) {
                    emptyStateElement.style.display = 'block';
                    if (tableContainer) tableContainer.style.display = 'none';
                    if (searchFilterContainer) searchFilterContainer.style.display = 'none';
                    if (paginationContainer) paginationContainer.style.display = 'none';
                } else {
                    emptyStateElement.style.display = 'none';
                    if (tableContainer) tableContainer.style.display = 'block';
                    // Check if a search/filter container exists for this tab before showing it
                    if (searchFilterContainer) searchFilterContainer.style.display = 'flex';
                    if (paginationContainer) paginationContainer.style.display = 'block';
                }
            }
        }

        // Toggle Dark Mode
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            const icon = document.querySelector('.theme-switcher .material-icons');
            if (document.body.classList.contains('dark-mode')) {
                icon.textContent = 'light_mode';
                localStorage.setItem('theme', 'dark');
            } else {
                icon.textContent = 'dark_mode';
                localStorage.setItem('theme', 'light');
            }
        }

        // Apply saved theme on load
        function applySavedTheme() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                document.querySelector('.theme-switcher .material-icons').textContent = 'light_mode';
            } else {
                document.querySelector('.theme-switcher .material-icons').textContent = 'dark_mode';
            }
        }

        // --- Tooltip Logic ---
        let currentTooltipElement = null;

        function setupTooltips() {
            // Remove existing listeners to prevent duplicates
            document.querySelectorAll('.activity-table tbody tr').forEach(row => {
                row.removeEventListener('mouseenter', handleRowMouseEnter);
                row.removeEventListener('mouseleave', handleRowMouseLeave);
                row.addEventListener('mouseenter', handleRowMouseEnter);
                row.addEventListener('mouseleave', handleRowMouseLeave);
            });
        }

        function handleRowMouseEnter(event) {
            const row = event.currentTarget;
            const detail = row.dataset.detail;
            if (detail) {
                if (currentTooltipElement) {
                    currentTooltipElement.remove();
                }

                const tooltip = document.createElement('div');
                tooltip.className = 'activity-tooltip';
                tooltip.textContent = detail;
                document.body.appendChild(tooltip);
                currentTooltipElement = tooltip;

                const rect = row.getBoundingClientRect();
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

                // Position the tooltip above the row, centered horizontally
                tooltip.style.left = `${rect.left + rect.width / 2}px`;
                tooltip.style.top = `${rect.top + scrollTop - tooltip.offsetHeight - 10}px`;

                // Adjust left position to keep tooltip within viewport
                const tooltipRect = tooltip.getBoundingClientRect();
                if (tooltipRect.right > window.innerWidth) {
                    tooltip.style.left = `${window.innerWidth - tooltipRect.width - 10}px`;
                }
                if (tooltipRect.left < 0) {
                    tooltip.style.left = `10px`;
                }

                tooltip.classList.add('visible');
            }
        }

        function handleRowMouseLeave() {
            if (currentTooltipElement) {
                currentTooltipElement.classList.remove('visible');
                // Give a small delay before removing to allow for smooth transition
                setTimeout(() => {
                    if (currentTooltipElement) { // Check if it's still the same tooltip
                        currentTooltipElement.remove();
                        currentTooltipElement = null;
                    }
                }, 200);
            }
        }


        // --- Search, Filter, Pagination, and Sort Logic (adapted) ---
        const rowsPerPage = 5; // Default rows to display per page

        function applyFilterAndPagination(tableElement, searchInput, filterSelect, resetPage = false) {
            const tableBody = tableElement.tBodies[0];
            let allRows = [];

            // Reconstruct original rows from stored HTML
            if (tableElement.dataset.originalRows && tableElement.dataset.originalRows !== '[]') {
                JSON.parse(tableElement.dataset.originalRows).forEach(rowHtml => {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = rowHtml;
                    allRows.push(tempDiv.firstChild);
                });
            } else {
                 // If originalRows not set or empty, assume no data
                 allRows = [];
            }


            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const filterValue = filterSelect ? filterSelect.value : 'all';
            const parentId = tableElement.closest('.tab-pane').id;

            let filteredRows = allRows.filter(row => {
                const cells = row.children;
                let rowContent = ''; // Generic content to search through
                // Collect all text content from visible cells for searching
                Array.from(cells).forEach(cell => {
                    rowContent += cell.textContent.toLowerCase() + ' ';
                });

                let matchesSearch = rowContent.includes(searchTerm);
                let matchesFilter = true;

                // Specific filter logic for each tab
                if (parentId === 'riwayat-poin') {
                    const pointsType = row.dataset.pointsType;
                    if (filterValue !== 'all' && pointsType !== filterValue) {
                        matchesFilter = false;
                    }
                } else if (parentId === 'riwayat-quiz') {
                    const score = parseInt(row.dataset.score);
                    if (filterValue === 'high-score' && (isNaN(score) || score <= 80)) {
                        matchesFilter = false;
                    } else if (filterValue === 'low-score' && (isNaN(score) || score > 80)) {
                        matchesFilter = false;
                    }
                } else if (parentId === 'modul-pembelajaran') {
                    const status = row.dataset.status;
                    if (filterValue !== 'all' && status !== filterValue) {
                        matchesFilter = false;
                    }
                } else if (parentId === 'bank-sampah') {
                    const dropPoint = row.dataset.dropPoint;
                    if (filterValue !== 'all' && dropPoint !== filterValue) {
                        matchesFilter = false;
                    }
                } else if (parentId === 'riwayat-game') {
                    const pointsType = row.dataset.pointsType; // Assuming pointsType refers to score positivity/negativity for games
                    if (filterValue !== 'all' && pointsType !== filterValue) {
                        matchesFilter = false;
                    }
                } else if (parentId === 'riwayat-penukaran') { // NEW: Rewards history filter
                    const status = row.dataset.status;
                    if (filterValue !== 'all' && status !== filterValue) {
                        matchesFilter = false;
                    }
                }

                return matchesSearch && matchesFilter;
            });

            // Apply sorting before pagination
            const currentSortColumn = tableElement.dataset.sortColumn;
            const currentSortDirection = tableElement.dataset.sortDirection || 'asc';
            if (currentSortColumn) {
                filteredRows.sort((a, b) => {
                    let valA, valB;
                    const headers = Array.from(tableElement.tHead.querySelector('tr').children);
                    const headerIndex = headers.findIndex(th => th.dataset.sortBy === currentSortColumn);

                    if (headerIndex !== -1) {
                        valA = a.children[headerIndex] ? a.children[headerIndex].textContent.trim() : '';
                        valB = b.children[headerIndex] ? b.children[headerIndex].textContent.trim() : '';
                    }

                    // Special handling for numeric values (points, score, weight, jumlah_item) and dates
                    if (['points', 'score', 'weight', 'points_used', 'jumlah_item'].includes(currentSortColumn)) { // Added points_used and jumlah_item
                        valA = parseFloat(valA.replace(/[^\d.-]/g, '')); // Extract number
                        valB = parseFloat(valB.replace(/[^\d.-]/g, ''));
                    } else if (currentSortColumn === 'date') {
                        // Handle date format "DD MonthYYYY HH:MM" or "DD MonthYYYY"
                        const parseDate = (dateString) => {
                            const months = {
                                'Januari': 0, 'Februari': 1, 'Maret': 2, 'April': 3, 'Mei': 4, 'Juni': 5,
                                'Juli': 6, 'Agustus': 7, 'September': 8, 'Oktober': 9, 'November': 10, 'Desember': 11
                            };
                            const parts = dateString.split(' ');
                            let day = parseInt(parts[0]);
                            let month = months[parts[1]];
                            let year = parseInt(parts[2]);
                            let hour = 0, minute = 0;
                            if (parts.length > 3 && parts[3].includes(':')) { // If time is present
                                const timeParts = parts[3].split(':');
                                hour = parseInt(timeParts[0]);
                                minute = parseInt(timeParts[1]);
                            }
                            return new Date(year, month, day, hour, minute);
                        };
                        valA = parseDate(valA);
                        valB = parseDate(valB);
                    }
                    // Default string comparison for other columns

                    if (valA < valB) return currentSortDirection === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSortDirection === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            if (resetPage) {
                tableElement.dataset.currentPage = 1;
            }

            let currentPage = parseInt(tableElement.dataset.currentPage || 1);
            const startIndex = 0;
            const endIndex = currentPage * rowsPerPage;

            tableBody.innerHTML = ''; // Clear current display

            const tabPane = tableElement.closest('.tab-pane');
            // Adjusted for new tab 'riwayat-penukaran'
            const emptyStateElementId = 'empty' + tabPane.id.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join('').replace('Riwayat', '').replace('Pembelajaran', 'Module');
            const finalEmptyStateId = emptyStateElementId.includes('BankSampah') ? 'emptyBankSampah' : (emptyStateElementId.includes('Game') ? 'emptyGameHistory' : (emptyStateElementId.includes('Penukaran') ? 'emptyRiwayatPenukaran' : null)); // Added for rewards

            const emptyStateElement = finalEmptyStateId ? document.getElementById(finalEmptyStateId) : null;
            const tableContainer = tabPane.querySelector('.table-responsive');
            const searchFilterContainer = tabPane.querySelector('.search-filter-container');
            const paginationContainer = tabPane.querySelector('.pagination-container');


            if (filteredRows.length === 0) {
                // If no filtered rows, show "Tidak ada data yang ditemukan" or the specific empty state
                const colspan = tableElement.tHead.querySelector('tr').children.length;
                const noDataRow = document.createElement('tr');
                noDataRow.innerHTML = `<td colspan="${colspan}" style="text-align: center; padding: 20px;">Tidak ada data yang ditemukan.</td>`;
                tableBody.appendChild(noDataRow);

                // Show/hide empty state and other elements
                if (emptyStateElement && tableElement.dataset.originalRows === '[]') { // Only show specific empty state if original data was truly empty
                    emptyStateElement.style.display = 'block';
                    if (tableContainer) tableContainer.style.display = 'none';
                    if (searchFilterContainer) searchFilterContainer.style.display = 'none';
                    if (paginationContainer) paginationContainer.style.display = 'none';
                } else { // If original data existed but filtered to none, show table with "no data found"
                    if (emptyStateElement) emptyStateElement.style.display = 'none';
                    if (tableContainer) tableContainer.style.display = 'block';
                    // Check if searchFilterContainer exists for this tab
                    if (searchFilterContainer) searchFilterContainer.style.display = 'flex';
                    if (paginationContainer) paginationContainer.style.display = 'none'; // Hide load more if no results
                }

            } else {
                if (emptyStateElement) emptyStateElement.style.display = 'none';
                if (tableContainer) tableContainer.style.display = 'block';
                // Check if searchFilterContainer exists for this tab
                if (searchFilterContainer) searchFilterContainer.style.display = 'flex';
                if (paginationContainer) paginationContainer.style.display = 'block';

                for (let i = startIndex; i < Math.min(endIndex, filteredRows.length); i++) {
                    tableBody.appendChild(filteredRows[i]);
                }
            }

            const loadMoreButton = tableElement.closest('.tab-pane').querySelector('.load-more-button');
            if (loadMoreButton) {
                if (endIndex >= filteredRows.length) {
                    loadMoreButton.style.display = 'none'; // Hide if all loaded
                } else {
                    loadMoreButton.style.display = 'block'; // Show if more to load
                }
            }
            setupTooltips(); // Re-attach tooltips to new rows after updating table content
        }

        // --- Event Listeners for Search, Filter, Load More, Sort ---
        document.querySelectorAll('.search-filter-container').forEach(container => {
            const tabPane = container.closest('.tab-pane');
            const table = tabPane.querySelector('.activity-table');
            const searchInput = container.querySelector('input[type="text"]');
            const filterSelect = container.querySelector('select');

            if (searchInput) {
                searchInput.addEventListener('keyup', () => applyFilterAndPagination(table, searchInput, filterSelect, true));
            }
            if (filterSelect) {
                filterSelect.addEventListener('change', () => applyFilterAndPagination(table, searchInput, filterSelect, true));
            }
        });

        document.querySelectorAll('.load-more-button').forEach(button => {
            button.addEventListener('click', () => {
                const tabPane = button.closest('.tab-pane');
                const table = tabPane.querySelector('.activity-table');
                let currentPage = parseInt(table.dataset.currentPage || 1);
                table.dataset.currentPage = currentPage + 1;
                const searchInput = tabPane.querySelector('input[type="text"]');
                const filterSelect = tabPane.querySelector('select');
                applyFilterAndPagination(table, searchInput, filterSelect);
            });
        });

        document.querySelectorAll('.activity-table th[data-sort-by]').forEach(header => {
            header.addEventListener('click', () => {
                const table = header.closest('.activity-table');
                const sortBy = header.dataset.sortBy;
                let sortDirection = table.dataset.sortDirection || 'asc';

                if (table.dataset.sortColumn === sortBy) {
                    sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';
                } else {
                    sortDirection = 'asc';
                }

                table.dataset.sortColumn = sortBy;
                table.dataset.sortDirection = sortDirection;

                table.querySelectorAll('th i.fas').forEach(icon => icon.remove());

                const sortIcon = document.createElement('i');
                sortIcon.classList.add('fas', sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                header.appendChild(sortIcon);

                const searchInput = table.closest('.tab-pane').querySelector('input[type="text"]');
                const filterSelect = table.closest('.tab-pane').querySelector('select');
                applyFilterAndPagination(table, searchInput, filterSelect, true);
            });
        });


        // --- Loading Overlay Simulation ---
        function simulateLoading(tabContentElement) {
            const loadingOverlay = tabContentElement.closest('.tab-pane').querySelector('.loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = 'flex';

                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                }, 800); // Adjust this delay for real data loading
            }
        }


        // Animate progress bar on load & Initial Setup
        document.addEventListener("DOMContentLoaded", function() {
            applySavedTheme(); // Apply saved theme

            // Trigger the click event on the first tab to initialize its content
            const initialActiveTabButton = document.querySelector('.tab-button.active');
            if (initialActiveTabButton) {
                // Manually call openTab for the initial active button.
                // This will also handle the initial applyFilterAndPagination and empty state check.
                openTab({ currentTarget: initialActiveTabButton }, initialActiveTabButton.dataset.tab);
            } else {
                // Fallback: if no active tab found, activate the first one
                const tabButtons = document.querySelectorAll('.tab-button');
                if (tabButtons.length > 0) {
                    openTab({ currentTarget: tabButtons[0] }, tabButtons[0].dataset.tab);
                }
            }

            // Animate progress bar
            const progressBar = document.getElementById('progressBarPoints');
            if (progressBar) {
                const targetWidth = progressBar.getAttribute('data-progress');
                progressBar.style.width = targetWidth + '%';
            }
        });
    </script>
</body>
</html>