<?php
// game.php - Gabungan HTML, CSS, JavaScript, dan PHP untuk interaksi Database

session_start(); // Memulai sesi PHP

// Asumsi db_connection.php dan helpers.php ada di lokasi yang sama
// dan berisi fungsi is_logged_in() dan redirect()
require_once 'db_connection.php';
require_once 'helpers.php';

// Periksa apakah pengguna sudah login
if (!is_logged_in()) {
    redirect('login.php'); // Arahkan ke login.php jika belum login
}

$loggedInUserId = $_SESSION['user_id']; // Ambil user ID dari sesi
$loggedInUsername = $_SESSION['username'] ?? 'Anonymous'; // Ambil username dari sesi

// --- Ambil total poin pengguna dari database ---
$currentTotalPoints = 0;
$userProfilePic = 'https://via.placeholder.com/150/007bff/ffffff?text=User'; // Default profile pic
$stmt = $conn->prepare("SELECT total_points, profile_picture_url FROM users WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $stmt->bind_result($currentTotalPoints, $profilePicFromDb);
    $stmt->fetch();
    $stmt->close();
    if ($profilePicFromDb) {
        $userProfilePic = $profilePicFromDb;
    }
}

// --- Logika Penanganan AJAX (save_score) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'save_score' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        $levelToSave = filter_var($_GET['level_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
        $recheckGamePlayedToday = false;
        if ($levelToSave !== null) {
            $stmt_recheck_save = $conn->prepare("SELECT COUNT(*) FROM game_scores WHERE user_id = ? AND level_id = ? AND DATE(played_at) = CURDATE()");
            if ($stmt_recheck_save) {
                $stmt_recheck_save->bind_param("ii", $loggedInUserId, $levelToSave);
                $stmt_recheck_save->execute();
                $stmt_recheck_save->bind_result($count_recheck_save);
                $stmt_recheck_save->fetch();
                $stmt_recheck_save->close();
                if ($count_recheck_save > 0) {
                    $recheckGamePlayedToday = true;
                }
            }
        }

        if ($recheckGamePlayedToday) {
            echo json_encode(['success' => false, 'message' => 'Anda hanya bisa memainkan level game ini sekali sehari.']);
            $conn->close();
            exit();
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $score = isset($data['score']) ? (int)$data['score'] : 0;
        $playerName = isset($data['playerName']) ? $conn->real_escape_string($data['playerName']) : $loggedInUsername;

        if ($levelToSave === null) {
            echo json_encode(['success' => false, 'message' => 'ID level game tidak ditemukan, skor tidak dapat disimpan.']);
            $conn->close();
            exit();
        }

        if ($score >= 0) {
            $conn->begin_transaction();
            try {
                // 1. Simpan skor game
                $stmt = $conn->prepare("INSERT INTO game_scores (user_id, level_id, player_name, score) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $loggedInUserId, $levelToSave, $playerName, $score);
                if (!$stmt->execute()) {
                    throw new Exception('Gagal menyimpan skor game: ' . $stmt->error);
                }
                $stmt->close();

                // 2. Tambahkan poin ke total poin pengguna
                $newTotalPoints = $currentTotalPoints + $score;
                $stmt = $conn->prepare("UPDATE users SET total_points = ? WHERE id = ?");
                $stmt->bind_param("ii", $newTotalPoints, $loggedInUserId);
                if (!$stmt->execute()) {
                    throw new Exception('Gagal memperbarui total poin: ' . $stmt->error);
                }
                $stmt->close();
                $_SESSION['total_points'] = $newTotalPoints; // Update sesi

                // 3. Cek dan berikan pencapaian (contoh sederhana)
                // Ini akan memanggil fungsi di bawah ini. Pastikan untuk mengimplementasikan logika kriteria di dalamnya.
                checkAndAwardAchievements($conn, $loggedInUserId, $newTotalPoints);

                // 4. Cek dan update progres tantangan (contoh sederhana)
                // Ini akan memanggil fungsi di bawah ini.
                updateChallengeProgress($conn, $loggedInUserId, $levelToSave, $score);


                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Skor dan poin berhasil disimpan!',
                    'newTotalPoints' => $newTotalPoints
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Transaction failed: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat menyimpan data: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Skor tidak valid.']);
        }
        $conn->close();
        exit();
    }
}

// --- Fungsi Helper PHP untuk Fitur Baru ---
function checkAndAwardAchievements($conn, $userId, $currentTotalPoints) {
    // Contoh: Award 'Penyortir Pemula' jika total_points >= 100
    $achievementId = 1; // ID pencapaian 'Penyortir Pemula' di tabel 'achievements'
    $criteriaValue = 100;

    if ($currentTotalPoints >= $criteriaValue) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
        if (!$stmt) { error_log("Achievement check prepare failed: " . $conn->error); return; }
        $stmt->bind_param("ii", $userId, $achievementId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count == 0) { // Jika belum diberikan
            $stmt = $conn->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)");
            if (!$stmt) { error_log("Achievement insert prepare failed: " . $conn->error); return; }
            $stmt->bind_param("ii", $userId, $achievementId);
            if ($stmt->execute()) {
                error_log("Achievement awarded: Penyortir Pemula to User ID: " . $userId);
            } else {
                error_log("Failed to insert achievement: " . $stmt->error);
            }
            $stmt->close();
            // Anda bisa menyimpan notifikasi ini ke sesi atau tabel terpisah untuk ditampilkan ke pengguna
        }
    }
    // Tambahkan logika untuk pencapaian lain di sini
    // Contoh: Award 'Master Daur Ulang' jika total_points >= 500
    $achievementId = 2; // Asumsi ID 2 untuk 'Master Daur Ulang'
    $criteriaValue = 500;
    if ($currentTotalPoints >= $criteriaValue) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
        if (!$stmt) { error_log("Achievement check prepare failed: " . $conn->error); return; }
        $stmt->bind_param("ii", $userId, $achievementId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        if ($count == 0) {
            $stmt = $conn->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)");
            if (!$stmt) { error_log("Achievement insert prepare failed: " . $conn->error); return; }
            $stmt->bind_param("ii", $userId, $achievementId);
            if ($stmt->execute()) {
                error_log("Achievement awarded: Master Daur Ulang to User ID: " . $userId);
            } else {
                error_log("Failed to insert achievement: " . $stmt->error);
            }
            $stmt->close();
        }
    }
}

function updateChallengeProgress($conn, $userId, $levelId, $score) {
    // Example: Challenge 'Complete Level X with score Y'
    // This function will automatically update based on game play.
    $stmt = $conn->prepare("SELECT id, name, description, type, target_value, reward_points FROM challenges WHERE is_active = 1 AND DATE(start_date) <= CURDATE() AND DATE(end_date) >= CURDATE()");
    if (!$stmt) { error_log("Challenge progress select prepare failed: " . $conn->error); return; }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($challenge = $result->fetch_assoc()) {
        $isCompleted = false;
        $currentProgress = 0;

        // Fetch current progress for the user and challenge
        $checkStmt = $conn->prepare("SELECT is_completed, current_progress FROM user_challenge_progress WHERE user_id = ? AND challenge_id = ?");
        if ($checkStmt) {
            $checkStmt->bind_param("ii", $userId, $challenge['id']);
            $checkStmt->execute();
            $checkStmt->bind_result($isCompletedDb, $currentProgressDb);
            $checkStmt->fetch();
            $checkStmt->close();
            $isCompleted = (bool)$isCompletedDb;
            $currentProgress = (int)$currentProgressDb;
        } else {
            error_log("Challenge progress check prepare failed: " . $conn->error);
        }

        if ($isCompleted) {
            continue; // Already completed, skip
        }

        $shouldUpdate = false;
        $newProgress = $currentProgress;

        if ($challenge['type'] === 'score_target') {
            // For score target, if the current game score meets or exceeds, update progress
            // Note: This simple example assumes a single game score contributes to the target.
            // For cumulative scores, you'd need to sum up scores from `game_scores` for that challenge's criteria.
            if ($score >= $challenge['target_value']) {
                $shouldUpdate = true;
                $newProgress = $score; // Set progress to this score if it meets target
            } else {
                // If it's a score target challenge, and user didn't meet the target this round,
                // you might want to update current progress if this score is higher than previous best for this challenge.
                if ($score > $currentProgress) {
                    $newProgress = $score;
                    // We only update if the new score is higher. Completion is checked separately.
                    $updateStmt = $conn->prepare("INSERT INTO user_challenge_progress (user_id, challenge_id, current_progress, is_completed, completed_at) VALUES (?, ?, ?, FALSE, NULL) ON DUPLICATE KEY UPDATE current_progress = VALUES(current_progress), is_completed = VALUES(is_completed), completed_at = VALUES(completed_at)");
                    if ($updateStmt) {
                        $updateStmt->bind_param("iii", $userId, $challenge['id'], $newProgress);
                        $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        error_log("Failed to update challenge progress (score_target, no completion): " . $conn->error);
                    }
                }
            }
        } elseif ($challenge['type'] === 'complete_level' && $levelId == $challenge['target_value']) {
            // If the challenge is to complete a specific level and this is that level
            $shouldUpdate = true;
            $newProgress = 1; // Mark as 1 for completed level
        }

        if ($shouldUpdate) {
            $isCompletedNow = false;
            if ($challenge['type'] === 'score_target' && $newProgress >= $challenge['target_value']) {
                $isCompletedNow = true;
            } elseif ($challenge['type'] === 'complete_level' && $newProgress >= 1) { // Assuming 1 means completed
                $isCompletedNow = true;
            }

            if ($isCompletedNow) {
                // Mark as completed and give points
                $updateStmt = $conn->prepare("INSERT INTO user_challenge_progress (user_id, challenge_id, current_progress, is_completed, completed_at) VALUES (?, ?, ?, TRUE, NOW()) ON DUPLICATE KEY UPDATE current_progress = VALUES(current_progress), is_completed = VALUES(is_completed), completed_at = VALUES(completed_at)");
                if ($updateStmt) {
                    $updateStmt->bind_param("iii", $userId, $challenge['id'], $newProgress);
                    if ($updateStmt->execute()) {
                        // Add reward points to user's total points
                        $updateUserPointsStmt = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
                        if ($updateUserPointsStmt) {
                            $updateUserPointsStmt->bind_param("ii", $challenge['reward_points'], $userId);
                            $updateUserPointsStmt->execute();
                            $updateUserPointsStmt->close();
                            $_SESSION['total_points'] += $challenge['reward_points']; // Update session
                            error_log("Challenge completed: " . $challenge['name'] . " for User ID: " . $userId . ". Reward: " . $challenge['reward_points']);
                        } else {
                            error_log("Failed to update user points for challenge: " . $conn->error);
                        }
                    } else {
                        error_log("Failed to update challenge progress (completion): " . $updateStmt->error);
                    }
                    $updateStmt->close();
                } else {
                    error_log("Failed to prepare challenge progress update (completion): " . $conn->error);
                }
            }
        }
    }
    $stmt->close();
}


// --- Main Logic for Displaying Content Based on 'view' Parameter ---
$view = $_GET['view'] ?? 'game'; // Default view adalah game
$level_id_from_url = filter_var($_GET['level_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);

// Game Logic (hanya dieksekusi jika view adalah 'game')
$gamePlayedToday = false;
$gameLevelConfig = [
    'waste_data' => [], 'duration_seconds' => 60,
    'points_per_correct_sort' => 10, 'level_name' => 'Default Level', 'level_id' => null
];

if ($view === 'game') {
    $currentPlayingLevelId = $level_id_from_url;
    if ($currentPlayingLevelId !== null) {
        $stmt_check_game_played = $conn->prepare("SELECT COUNT(*) FROM game_scores WHERE user_id = ? AND level_id = ? AND DATE(played_at) = CURDATE()");
        if ($stmt_check_game_played) {
            $stmt_check_game_played->bind_param("ii", $loggedInUserId, $currentPlayingLevelId);
            $stmt_check_game_played->execute(); // Fix: Call execute on the correct statement object
            $stmt_check_game_played->bind_result($count);
            $stmt_check_game_played->fetch();
            $stmt_check_game_played->close();
            if ($count > 0) {
                $gamePlayedToday = true;
            }
        } else {
            error_log("Failed to prepare game played check query: " . $conn->error);
            // Consider setting gamePlayedToday to true to prevent game from loading if check fails critically
            $gamePlayedToday = true;
        }
    } else {
        $gamePlayedToday = true; // Jika tidak ada level_id, game tidak bisa dimainkan tanpa validasi
    }

    $sql_level = "SELECT id, level_name, item_config_json, duration_seconds, points_per_correct_sort FROM game_levels WHERE is_active = 1";
    if ($level_id_from_url) {
        $sql_level .= " AND id = ? LIMIT 1";
    } else {
        $sql_level .= " ORDER BY id ASC LIMIT 1"; // Default to first active level if no ID specified
    }

    $stmt_level = $conn->prepare($sql_level);
    if ($stmt_level) {
        if ($level_id_from_url) {
            $stmt_level->bind_param("i", $level_id_from_url);
        }
        $stmt_level->execute();
        $result_level = $stmt_level->get_result();
        if ($result_level->num_rows > 0) {
            $row_level = $result_level->fetch_assoc();
            if ($row_level) {
                $gameLevelConfig['level_id'] = $row_level['id'];
                $gameLevelConfig['level_name'] = htmlspecialchars($row_level['level_name']);
                $gameLevelConfig['duration_seconds'] = (int)$row_level['duration_seconds'];
                $gameLevelConfig['points_per_correct_sort'] = (int)$row_level['points_per_correct_sort'];
                $itemConfig = json_decode($row_level['item_config_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($itemConfig)) {
                    $parsedWasteData = [];
                    foreach ($itemConfig as $item) {
                        $imageUrl = '';
                        switch ($item['type']) {
                            case 'paper': $imageUrl = 'https://www.pngplay.com/wp-content/uploads/7/Newspaper-Transparent-PNG.png'; break;
                            case 'plastic': $imageUrl = 'https://png.pngtree.com/png-clipart/20250106/original/pngtree-beautiful-hand-painted-plastic-bag-png-element-png-image_5460258.png'; break;
                            case 'organic': $imageUrl = 'https://png.pngtree.com/png-clipart/20230817/original/pngtree-organic-garbage-isolated-on-white-background-fruit-isolated-carrot-vector-picture-image_10950880.png'; break;
                            default: $imageUrl = 'https://via.placeholder.com/100?text=Unknown';
                        }
                        for ($i = 0; $i < ($item['quantity'] ?? 1); $i++) {
                            $parsedWasteData[] = ['id' => uniqid($item['type'] . '_'), 'type' => $item['type'], 'image' => $imageUrl];
                        }
                    }
                    $gameLevelConfig['waste_data'] = $parsedWasteData;
                }
            } else { $gamePlayedToday = true; $gameLevelConfig['level_name'] = 'Tidak Ada Level Game Tersedia (Data Level Kosong)'; }
        } else { $gamePlayedToday = true; $gameLevelConfig['level_name'] = 'Tidak Ada Level Game Tersedia'; }
        $stmt_level->close();
    } else { error_log("Failed to prepare game level query: " . $conn->error); $gamePlayedToday = true; $gameLevelConfig['level_name'] = 'Error Memuat Level Game'; }

    // Re-check gamePlayedToday if the actual level loaded is different from URL or default
    if (($level_id_from_url === null && $gameLevelConfig['level_id'] !== null) || ($level_id_from_url !== null && $level_id_from_url !== $gameLevelConfig['level_id'])) {
        $currentPlayingLevelId = $gameLevelConfig['level_id'];
        if ($currentPlayingLevelId !== null) {
            $stmt_recheck = $conn->prepare("SELECT COUNT(*) FROM game_scores WHERE user_id = ? AND level_id = ? AND DATE(played_at) = CURDATE()");
            if ($stmt_recheck) {
                $stmt_recheck->bind_param("ii", $loggedInUserId, $currentPlayingLevelId);
                $stmt_recheck->execute(); // Corrected this line
                $stmt_recheck->bind_result($count_recheck);
                $stmt_recheck->fetch();
                $stmt_recheck->close();
                if ($count_recheck > 0) {
                    $gamePlayedToday = true;
                } else {
                    $gamePlayedToday = false;
                }
            }
        }
    }
}
// END Game Logic

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Lingkungan - <?php echo ucfirst($view); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* CSS START */
        /* GLOBAL RESET & BASIC HTML/BODY */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%; /* Ensure body takes full height */
            overflow-x: hidden; /* Prevent horizontal scrolling globally */
            font-family: 'Poppins', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #e0f2f7 0%, #c1e3ed 50%, #9adbe6 100%);
            color: #333;
        }

        .app-wrapper {
            display: flex;
            width: 95%;
            max-width: 1400px;
            min-height: 85vh;
            background-color: #fff;
            border-radius: 25px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            overflow: hidden; /* Important for containing sidebar/content */
            border: 2px solid #a7d9de;
        }

        /* Navigasi Utama (Sidebar) - Simplified and Modernized */
        .main-nav {
            flex: 0 0 280px;
            background: linear-gradient(to bottom, #4CAF50, #2E7D32);
            padding: 35px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-right: 1px solid rgba(255,255,255,0.2);
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }
        .main-nav::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><g fill="%23ffffff" fill-opacity="0.08"><path d="M50 0 L100 50 L50 100 L0 50 Z" /><path d="M50 10 L90 50 L50 90 L10 50 Z" /><path d="M50 20 L80 50 L50 80 L20 50 Z" /></g></svg>');
            background-size: 50px;
            opacity: 0.2;
            z-index: -1;
        }

        .main-nav .nav-btn {
            background-color: transparent; /* Changed to transparent for a cleaner look */
            color: white;
            padding: 18px 25px;
            width: 85%;
            border: none;
            border-radius: 12px;
            font-size: 1.15em;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease;
            text-decoration: none;
            text-align: left;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            box-shadow: none; /* Removed initial shadow */
            white-space: nowrap;
            box-sizing: border-box;
            position: relative; /* For the active indicator */
        }
        .main-nav .nav-btn:hover {
            background-color: rgba(255, 255, 255, 0.15); /* Slightly less opaque on hover */
            transform: translateX(8px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2); /* Added shadow on hover */
        }
        .main-nav .nav-btn.active {
            background-color: #FFC107;
            color: #333;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            font-weight: 700;
            transform: translateX(0);
            position: relative;
        }
        .main-nav .nav-btn.active::before { /* Active indicator */
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 80%;
            background-color: #333; /* Darker color for contrast */
            border-radius: 0 5px 5px 0;
        }
        .main-nav .nav-btn.active i {
            color: #333;
        }
        .main-nav .nav-btn i {
            margin-right: 12px;
            font-size: 1.3em;
            color: #e0e0e0;
        }


        .app-content {
            flex-grow: 1;
            padding: 35px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            overflow-y: auto; /* Allow vertical scrolling for content */
            background-color: #fcfdfe;
            width: 100%; /* Ensure it takes available width */
            box-sizing: border-box; /* Include padding in width calculation */
        }

        .header {
            width: 100%;
            margin-bottom: 30px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .warning {
            background-color: #ffe082;
            padding: 12px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            color: #4a4a4a;
            font-size: 1.1em;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            max-width: 100%;
            box-sizing: border-box;
        }

        /* --- Game Specific Styles --- */
        .info-board {
            display: flex;
            justify-content: center;
            width: 100%;
            font-size: 1.3em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .score-display, .timer-display, .item-count {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            color: #1a237e;
            padding: 15px 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            min-width: 160px;
            flex-basis: auto;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            border: 1px solid #90caf9;
        }
        .item-count {
            background: linear-gradient(135deg, #ffe0b2, #ffcc80);
            color: #e65100;
            border: 1px solid #ffab40;
        }
        .info-board span {
            font-size: 1.3em;
            font-weight: 800;
        }
        .info-board i {
            font-size: 1.1em;
            color: rgba(0,0,0,0.3);
        }

        .waste-items {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            min-height: 180px;
            width: 100%;
            margin-bottom: 50px;
            padding: 25px;
            border: 3px dashed #b0e0eb;
            border-radius: 20px;
            background-color: #f7fcfd;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.05);
            max-width: 100%;
            box-sizing: border-box;
        }

        .waste-item {
            width: 120px;
            height: 120px;
            background-color: #ffffff;
            border-radius: 25px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            border: 2px solid #e8f5e9;
            flex-shrink: 0;
        }

        .waste-item.selected-waste {
            border: 3px solid #2196F3;
            transform: scale(1.05);
            box-shadow: 0 12px 30px rgba(33, 150, 243, 0.4);
        }

        .waste-item:active {
            transform: scale(1.1);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .waste-item img {
            max-width: 80%;
            max-height: 80%;
            pointer-events: none;
        }

        .bins-container {
            display: flex;
            justify-content: space-around;
            width: 100%;
            gap: 40px;
            margin-bottom: 40px;
            flex-wrap: wrap;
            max-width: 100%;
            box-sizing: border-box;
        }

        .bin {
            border: 4px solid transparent;
            border-radius: 30px;
            width: 220px;
            height: 250px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-weight: 700;
            font-size: 1.4em;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
            background-color: #ffffff;
            transform: skewY(-2deg) rotateZ(1deg);
            transform-origin: bottom center;
            transition: all 0.3s ease;
            cursor: pointer;
            flex-shrink: 0;
            box-sizing: border-box;
        }
        .bin:nth-child(even) {
             transform: skewY(2deg) rotateZ(-1deg);
        }

        .bin i {
            font-size: 5em;
            margin-bottom: 20px;
        }

        /* Specific bin colors (more vibrant and thematic) */
        .bin.organic { background: linear-gradient(135deg, #dcedc8, #aed581); border-color: #8bc34a; color: #33691e; }
        .bin.organic i { color: #689f38; }
        .bin.plastic { background: linear-gradient(135deg, #bbdefb, #90caf9); border-color: #2196f3; color: #0d47a1; }
        .bin.plastic i { color: #1976d2; }
        .bin.paper { background: linear-gradient(135deg, #fff9c4, #fff59d); border-color: #ffeb3b; color: #f57f17; }
        .bin.paper i { color: #fbc02d; }

        .bin:hover {
            transform: scale(1.03) skewY(0deg) rotateZ(0deg);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            border-color: #607d8b;
        }

        .feedback-icon {
            position: absolute;
            font-size: 5em;
            opacity: 0;
            animation: fadePop 0.7s forwards;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            z-index: 1;
        }

        .feedback-icon.correct { color: #4CAF50; }
        .feedback-icon.wrong { color: #f44336; }

        @keyframes fadePop {
            0% { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
            50% { opacity: 1; transform: translate(-50%, -50%) scale(1.5); }
            100% { opacity: 0; transform: translate(-50%, -50%) scale(1.2); }
        }

        .game-buttons {
            display: flex;
            gap: 25px;
            margin-top: 35px;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .game-buttons .btn {
            background: linear-gradient(45deg, #4CAF50, #66BB6A);
            color: white;
            padding: 20px 40px;
            border: none;
            border-radius: 15px;
            font-size: 1.4em;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            text-decoration: none;
            text-align: center;
            white-space: nowrap;
            letter-spacing: 0.5px;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .game-buttons .btn:hover {
            background: linear-gradient(45deg, #388E3C, #4CAF50);
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.4);
        }

        .game-buttons .btn:active {
            transform: translateY(0);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.2);
        }

        .game-disabled-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 100;
            border-radius: 25px;
            flex-direction: column;
            gap: 25px;
            text-align: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .game-disabled-overlay p {
            color: white;
            font-size: 2.2em;
            font-weight: 700;
            text-shadow: 3px 3px 6px rgba(0,0,0,0.7);
            text-align: center;
            margin-bottom: 20px;
            max-width: 100%;
            box-sizing: border-box;
        }
        .game-disabled-overlay .back-btn {
            background-color: #2196F3;
            color: white;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 5px 10px rgba(0,0,0,0.2);
            box-sizing: border-box;
        }
        .game-disabled-overlay .back-btn:hover {
            background-color: #1976D2;
            transform: translateY(-3px);
        }

        /* Modal Styles - Redesigned for Simplicity and Modernity */
        .modal {
            display: none;
            position: fixed;
            z-index: 101;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.7); /* Lebih gelap untuk fokus */
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            backdrop-filter: blur(8px); /* Efek blur lebih kuat */
        }

        .modal-content {
            background-color: #ffffff; /* Putih bersih */
            margin: auto;
            padding: 35px; /* Sedikit lebih lega */
            border-radius: 25px; /* Lebih membulat */
            box-shadow: 0 25px 50px rgba(0,0,0,0.4); /* Bayangan lebih kuat dan menyebar */
            width: 90%;
            max-width: 550px; /* Lebar maksimum sedikit ditingkatkan */
            text-align: center;
            animation-name: animatetop;
            animation-duration: 0.5s; /* Animasi sedikit lebih lambat */
            position: relative;
            border: none;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 25px; /* Jarak antar elemen lebih besar */
        }

        @keyframes animatetop {
            from {top: -200px; opacity: 0; transform: scale(0.8);} /* Mulai lebih tinggi, lebih kecil */
            to {top: 0; opacity: 1; transform: scale(1);}
        }

        .modal-content h2 {
            font-size: 2.8em; /* Ukuran judul lebih besar */
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 800; /* Lebih tebal */
            text-shadow: 2px 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px; /* Jarak antara emoji dan teks */
        }

        .modal-content p {
            font-size: 1.25em; /* Ukuran teks normal lebih besar */
            color: #444;
            margin-bottom: 8px; /* Jarak antar baris skor/poin */
        }

        .modal-content p span {
            font-weight: 800; /* Lebih tebal untuk skor/poin */
            font-size: 1.3em; /* Skor/poin lebih besar dari teks normal */
            color: #4CAF50; /* Warna skor tetap hijau */
            text-shadow: 1px 1px 3px rgba(0,0,0,0.1); /* Sedikit bayangan untuk skor */
            display: inline-block; /* Agar bisa menerapkan transform */
            transition: transform 0.2s ease-out;
        }
        .modal-content p span#newTotalPointsDisplay {
            color: #FFC107; /* Warna total poin tetap kuning-oranye */
        }

        #gameOverMessage {
            font-style: italic;
            color: #666; /* Warna lebih gelap untuk keterbacaan */
            margin-top: 15px;
            font-size: 1.05em; /* Sedikit lebih besar */
            line-height: 1.5;
        }

        #educationalNotification {
            margin-top: 30px; /* Jarak lebih besar dari pesan game over */
            padding: 25px; /* Padding lebih besar */
            background-color: #e8f5e9; /* Latar belakang hijau lembut */
            border-left: 8px solid #4CAF50; /* Border kiri lebih tebal */
            text-align: left;
            border-radius: 15px; /* Lebih membulat */
            box-shadow: inset 0 3px 8px rgba(0,0,0,0.1); /* Bayangan dalam yang lebih jelas */
            max-width: 100%;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        #educationalNotification h4 {
            color: #2E7D32; /* Warna hijau gelap untuk heading */
            margin-top: 0;
            margin-bottom: 0;
            font-size: 1.3em; /* Ukuran heading fakta lebih besar */
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #educationalNotification h4 i {
            font-size: 1.5em; /* Ukuran ikon di heading fakta */
            color: #4CAF50; /* Warna ikon fakta */
        }
        #educationalNotification p {
            color: #333; /* Warna teks fakta lebih gelap untuk keterbacaan */
            font-size: 1em; /* Ukuran teks fakta */
            line-height: 1.6;
            margin-bottom: 0;
        }


        .modal-buttons {
            display: grid; /* Menggunakan grid untuk tata letak yang lebih fleksibel */
            grid-template-columns: 1fr; /* Default ke satu kolom */
            gap: 20px; /* Jarak antar tombol lebih besar */
            margin-top: 30px; /* Jarak dari atas */
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .modal-buttons .btn {
            padding: 18px 30px; /* Padding lebih besar */
            font-size: 1.2em; /* Ukuran font tombol lebih besar */
            font-weight: 700; /* Lebih tebal */
            min-width: unset; /* Hapus min-width sebelumnya */
            flex-grow: 1; /* Tetap fleksibel */
            border: none;
            border-radius: 12px; /* Border radius tombol */
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 6px 15px rgba(0,0,0,0.2); /* Bayangan standar */
            text-decoration: none; /* Hapus underline untuk <a> */
            color: white; /* Warna teks default untuk tombol */
        }

        /* Tombol "Lihat Panduan" */
        .modal-buttons a[href*="?view=education"] {
            background: linear-gradient(45deg, #2196F3, #64B5F6); /* Gradien biru */
        }
        .modal-buttons a[href*="?view=education"]:hover {
            background: linear-gradient(45deg, #1976D2, #2196F3);
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }

        /* Tombol "Keluar ke Menu" */
        .modal-buttons .btn-exit {
            background: linear-gradient(45deg, #f44336, #e57373); /* Gradien merah */
        }
        .modal-buttons .btn-exit:hover {
            background: linear-gradient(45deg, #d32f2f, #f44336);
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }

        .modal-buttons .btn:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
        }

        /* Responsiveness - Portrait Mode (Default for smaller screens) */
        @media (max-width: 992px) {
            .app-wrapper {
                flex-direction: column;
                width: 98%;
                min-height: unset;
                height: auto;
                border-radius: 15px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            }
            .main-nav {
                flex-direction: row; /* NAV BUTTONS IN A ROW FOR TOP NAVIGATION */
                flex: none;
                width: 100%;
                padding: 15px 10px;
                justify-content: center;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
                box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                flex-wrap: wrap; /* Allow buttons to wrap to next line if needed */
                gap: 8px;
                border-radius: 15px 15px 0 0;
                background: linear-gradient(to right, #4CAF50, #2E7D32);
            }
            .main-nav::before {
                background-size: 40px;
            }
            .main-nav .nav-btn {
                width: auto;
                text-align: center;
                flex-grow: 1;
                margin-bottom: 0;
                padding: 10px 15px;
                font-size: 0.9em;
                box-shadow: none;
                white-space: nowrap; /* Keep text in one line */
                overflow: hidden; /* Hide overflowing text */
                text-overflow: ellipsis; /* Add ellipsis if text is too long */
                flex-basis: calc(50% - 16px); /* Adjusted for 2 columns with 8px gap */
                max-width: calc(50% - 16px); /* Ensure it fits well */
            }
            .main-nav .nav-btn:hover {
                transform: none;
            }
            .main-nav .nav-btn.active {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            }
            .main-nav .nav-btn.active::before { /* Active indicator for small screens */
                left: 50%; /* Center the indicator */
                bottom: 0;
                top: auto;
                transform: translateX(-50%);
                width: 80%;
                height: 4px; /* Thinner indicator */
                border-radius: 5px 5px 0 0;
            }

            .app-content {
                padding: 20px;
                max-height: unset;
            }
            .content-section {
                margin: 0 auto;
                max-height: unset;
                padding: 25px;
                border-radius: 15px;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            }
            /* Education List Responsiveness */
            .education-list {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
            }
            .education-list-item {
                padding: 18px;
            }
            .edu-icon-wrapper {
                width: 60px;
                height: 60px;
                margin-bottom: 15px;
            }
            .edu-icon-wrapper i {
                font-size: 2.2em;
            }
            .education-list-item h4 {
                font-size: 1.2em;
                margin-bottom: 8px;
            }
            .education-list-item p {
                font-size: 0.9em;
                max-height: 100px;
            }
            .read-more-link {
                font-size: 0.85em;
            }

            /* Profile specific */
            .profile-container {
                grid-template-columns: 1fr;
            }
            .profile-card {
                padding: 20px;
                border-radius: 15px;
            }
            .profile-header-card {
                margin: -20px -20px 20px -20px;
                border-radius: 15px 15px 0 0;
                padding: 30px 15px;
            }
            .profile-picture {
                width: 120px;
                height: 120px;
                margin-top: -70px;
                margin-bottom: 15px;
            }
            .profile-username { font-size: 2em; }
            .profile-points { font-size: 1.5em; padding: 8px 15px; }
            .profile-points i { font-size: 1.2em; }
            .profile-section-title { font-size: 1.6em; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; }
            .profile-section-title::after { width: 50px; height: 3px; }
            .history-list li, .achievements-list li {
                padding: 15px 20px;
                border-radius: 10px;
                margin-bottom: 10px;
            }
            .history-list li span, .achievements-list li.achievement-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .history-list li span:last-child { text-align: left; }
            .achievements-list li.achievement-item img {
                margin-bottom: 10px;
            }
            .achievements-list li.achievement-item .achievement-date {
                margin-top: 5px;
            }

            /* Modal for small screens */
            .modal-content {
                padding: 25px; /* Adjust padding for smaller screens */
                border-radius: 15px;
                max-width: 400px; /* Adjust max-width for small phones */
            }
            .modal-content h2 {
                font-size: 1.8em;
                margin-bottom: 10px;
            }
            .modal-content p {
                font-size: 1em;
            }
            .modal-buttons {
                gap: 10px;
                margin-top: 20px;
            }
            .modal-buttons .btn {
                padding: 10px 20px;
                font-size: 1em;
                max-width: 100%; /* Ensure full width on single column */
            }
            #educationalNotification {
                padding: 15px;
                border-radius: 10px;
            }
            #educationalNotification h4 {
                font-size: 1.1em;
                margin-bottom: 8px;
            }
            #educationalNotification p {
                font-size: 0.9em;
            }
        }

        /* Penyesuaian khusus untuk mode potret pada layar yang lebih kecil (mis. smartphone) */
        @media (max-width: 768px) {
            body { padding: 5px; }
            .app-wrapper { border-radius: 10px; }
            .main-nav { padding: 10px 5px; gap: 5px; }
            .main-nav .nav-btn { font-size: 0.8em; padding: 8px 12px; }
            .app-content { padding: 15px; }
            .warning { font-size: 0.9em; padding: 7px 10px; }
            .info-board { flex-direction: column; gap: 8px; font-size: 1.1em;}
            .score-display, .timer-display, .item-count { font-size: 1em; padding: 10px 15px; min-width: unset; width: 100%; margin: 0 auto; }
            .waste-item { width: 80px; height: 80px; border-radius: 15px; }

            /* Ini bagian penting untuk membuat bins lebih kecil dan satu baris */
            .bins-container {
                flex-direction: row; /* Pastikan dalam satu baris */
                justify-content: space-around; /* Ratakan antar item */
                gap: 5px; /* Kurangi gap antar bins lagi untuk potret sempit */
                margin-bottom: 15px;
                padding: 0 5px; /* Tambahkan sedikit padding horizontal */
            }
            .bin {
                width: 30%; /* Berikan lebar persentase untuk responsivitas */
                max-width: 100px; /* Batasi lebar maksimum */
                height: 110px; /* Sesuaikan tinggi */
                font-size: 0.75em; /* Perkecil ukuran font teks */
                border-radius: 12px;
                transform: none !important;
                border-width: 2px;
                padding: 5px; /* Kurangi padding dalam bin */
            }
            .bin i {
                font-size: 2.5em; /* Perkecil ukuran ikon */
                margin-bottom: 5px;
            }
            .bin p {
                font-size: 0.85em;
                line-height: 1.1;
                white-space: nowrap; /* Pertahankan teks dalam satu baris jika mungkin */
                overflow: hidden; /* Sembunyikan jika overflow */
                text-overflow: ellipsis; /* Tambahkan ellipsis jika teks terlalu panjang */
            }


            .game-buttons { gap: 10px; margin-top: 20px;}
            .game-buttons .btn { padding: 10px 20px; font-size: 0.9em; border-radius: 10px; }
            .game-disabled-overlay p { font-size: 1.8em; }
            .game-disabled-overlay .back-btn { padding: 10px 20px; font-size: 0.9em; }

            /* Modal for very small screens */
            .modal-content {
                padding: 20px; /* Further reduce padding */
                border-radius: 12px;
                max-width: 95%; /* Allow it to take more width */
            }
            .modal-content h2 {
                font-size: 1.6em;
            }
            .modal-content p {
                font-size: 0.9em;
            }
            .modal-buttons {
                flex-direction: column; /* Stack buttons vertically */
                gap: 8px;
            }
            .modal-buttons .btn {
                width: 100%; /* Full width buttons */
                max-width: 100%;
                padding: 10px 15px;
                font-size: 0.9em;
            }
            #educationalNotification {
                padding: 12px;
                border-radius: 8px;
            }
            #educationalNotification h4 {
                font-size: 1em;
            }
            #educationalNotification p {
                font-size: 0.8em;
            }

            /* Profile specific */
            .profile-header-card { padding: 25px 15px; }
            .profile-picture { width: 100px; height: 100px; margin-top: -60px; margin-bottom: 10px;}
            .profile-username { font-size: 1.8em; }
            .profile-points { font-size: 1.3em; padding: 6px 12px;}
            .profile-points i { font-size: 1em; }
            .profile-section-title { font-size: 1.5em; margin-top: 25px; margin-bottom: 12px; padding-bottom: 8px;}
            .profile-section-title::after { width: 40px; height: 2px; }
            .history-list li, .achievements-list li {
                padding: 12px 15px;
                border-radius: 8px;
            }
            .history-list li span, .achievements-list li.achievement-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .history-list li span:last-child { text-align: left; }
            .achievements-list li.achievement-item img {
                margin-bottom: 10px;
            }
            .achievements-list li.achievement-item .achievement-date {
                margin-top: 5px;
            }
        }

        /* --- LANDSCAPE OPTIMIZATION FOR MOBILE DEVICES (<= 992px width and landscape orientation) --- */
        @media (max-width: 992px) and (orientation: landscape) {
            .modal-buttons {
                grid-template-columns: 1fr; /* Force single column on landscape if space is limited */
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <div class="main-nav">
            <a href="?view=game" class="nav-btn <?php echo ($view === 'game' ? 'active' : ''); ?>"><i class="fas fa-gamepad"></i> Eco-Heroes Game</a>
            <a href="modules.php" class="nav-btn"><i class="fas fa-door-open"></i> Keluar Game</a>
        </div>

        <div class="app-content">
            <?php if ($view === 'game'): ?>
                <?php if ($gamePlayedToday): ?>
                    <div class="game-disabled-overlay">
                        <p>Anda sudah memainkan game level ini hari ini. Silakan coba lagi besok!</p>
                        <?php if ($gameLevelConfig['level_id'] === null || empty($gameLevelConfig['waste_data'])): ?>
                            <p style="font-size: 1.2em; margin-top: 10px;">Tidak ada level game yang dapat dimainkan atau item sampah belum dikonfigurasi.</p>
                        <?php endif; ?>
                        <a href="?view=game&level_id=<?php echo $gameLevelConfig['level_id'] ? ($gameLevelConfig['level_id'] + 1) : ''; ?>" class="back-btn">Coba Level Berikutnya</a>
                        <a href="?view=game" class="back-btn">Pilih Level Lain (refresh game)</a>
                        </div>
                <?php endif; ?>

                <div class="header">
                    <p class="warning">Pilih setiap item sampah, lalu klik tempat sampah yang benar untuk mendapatkan poin! Cepat, waktu terus berjalan!</p>
                    <div class="info-board">
                        <div class="score-display"><i class="fas fa-star"></i> Skor: <span id="score">0</span></div>
                        <div class="timer-display"><i class="fas fa-clock"></i> Waktu: <span id="timer">60</span>s</div>
                        <div class="item-count"><i class="fas fa-trash-alt"></i> Sisa Sampah: <span id="remainingItems">0</span></div>
                    </div>
                    <p style="font-weight: 600; margin-top: 20px; font-size: 1.3em;">Level: <span id="game-level-name"><?php echo $gameLevelConfig['level_name']; ?></span></p>
                </div>

                <div class="main-game-area">
                    <div class="waste-items" id="wasteItems">
                    </div>

                    <div class="bins-container">
                        <div class="bin organic" data-bin-type="organic">
                            <i class="fas fa-leaf"></i>
                            <p>Organik</p>
                        </div>
                        <div class="bin plastic" data-bin-type="plastic">
                            <i class="fas fa-recycle"></i>
                            <p>Plastik</p>
                        </div>
                        <div class="bin paper" data-bin-type="paper">
                            <i class="fas fa-newspaper"></i>
                            <p>Kertas</p>
                        </div>
                    </div>

                    <div class="game-buttons">
                        <?php if (!$gamePlayedToday): ?>
                            <?php endif; ?>
                        </div>
                </div>
            <?php elseif ($view === 'education'): ?>
                <div class="content-section">
                    <h2>Halaman Tidak Ditemukan</h2>
                    <p>Silakan gunakan menu navigasi di atas.</p>
                </div>
            <?php elseif ($view === 'profile'): ?>
                <div class="content-section">
                    <h2>Halaman Tidak Ditemukan</h2>
                    <p>Silakan gunakan menu navigasi di atas.</p>
                </div>
            <?php elseif ($view === 'challenges'): ?>
                <div class="content-section">
                    <h2>Halaman Tidak Ditemukan</h2>
                    <p>Silakan gunakan menu navigasi di atas.</p>
                </div>
            <?php else: ?>
                <div class="content-section">
                    <h2>Halaman Tidak Ditemukan</h2>
                    <p>Silakan gunakan menu navigasi di atas.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="gameOverModal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>🎉 Game Selesai! 🎉</h2>
            <p>Selamat! Skor Anda: <span id="finalScoreDisplay" style="font-weight: 700; color: #4CAF50;">0</span></p>
            <p>Total Poin Anda Sekarang: <span id="newTotalPointsDisplay" style="font-weight: 700; color: #FFC107;">0</span></p>
            <p id="gameOverMessage" style="font-style: italic; color: #777; margin-top: 10px;"></p>

            <div id="educationalNotification">
                <h4><i class="fas fa-lightbulb"></i> Tahukah Kamu? Fakta Menarik Lingkungan!</h4>
                <p id="factContent"></p>
            </div>

            <div class="modal-buttons">
                <a href="modules.php" class="btn btn-exit"><i class="fas fa-door-open"></i> Keluar ke Menu</a>
            </div>
        </div>
    </div>
    <audio id="correctSound" src="https://www.soundjay.com/buttons/beep-07.mp3"></audio>
    <audio id="wrongSound" src="https://www.soundjay.com/misc/fail-buzzer-01.mp3"></audio>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const appContainer = document.getElementById('appContainer'); // This element doesn't exist in the provided HTML, check if it's meant to be something else.
            const gameViewElements = {
                wasteItemsContainer: document.getElementById('wasteItems'),
                bins: document.querySelectorAll('.bin'),
                scoreDisplay: document.getElementById('score'),
                timerDisplay: document.getElementById('timer'),
                remainingItemsDisplay: document.getElementById('remainingItems'),
                // playAgainBtn: document.getElementById('playAgainBtn'), // Dihapus
                gameLevelNameDisplay: document.getElementById('game-level-name'),
            };

            const correctSound = document.getElementById('correctSound');
            const wrongSound = document.getElementById('wrongSound');

            const gameOverModal = document.getElementById('gameOverModal');
            const finalScoreDisplay = document.getElementById('finalScoreDisplay');
            const newTotalPointsDisplay = document.getElementById('newTotalPointsDisplay');
            const gameOverMessage = document.getElementById('gameOverMessage');
            // const modalPlayAgainBtn = document.getElementById('modalPlayAgainBtn'); // Dihapus
            const educationalNotification = document.getElementById('educationalNotification');
            const factContent = document.getElementById('factContent');

            const gamePlayedToday = <?php echo json_encode($gamePlayedToday); ?>;
            const gameLevelConfig = <?php echo json_encode($gameLevelConfig); ?>;
            const currentView = "<?php echo $view; ?>";

            let score = 0;
            let timeLeft = gameLevelConfig.duration_seconds;
            let pointsPerCorrectSort = gameLevelConfig.points_per_correct_sort;
            let timerInterval;
            let gameEnded = false;
            let wasteData = gameLevelConfig.waste_data;

            let selectedWaste = null; // Ini akan menyimpan item sampah yang sedang dipilih

            const educationalFacts = [
                "🎉 Hebat! Dengan memilah sampah, Anda membantu bumi kita tetap bersih dan lestari. Terus belajar dan bermain ya!",
                "🌱 Setiap sampah organik yang dipilah bisa menjadi pupuk kompos yang menyuburkan tanah, mengurangi sampah TPA hingga 30%!",
                "♻️ Botol plastik yang Anda pilah bisa didaur ulang menjadi serat pakaian atau bahkan furnitur baru. Luar biasa!",
                "🌳 Mendaur ulang 1 ton kertas menyelamatkan 17 pohon! Kontribusi Anda sangat berarti bagi hutan kita.",
                "💡 Sampah elektronik seperti baterai mengandung zat berbahaya. Memilahnya dengan benar mencegah pencemaran lingkungan.",
                "🌍 Sampah kaca bisa didaur ulang berkali-kali tanpa mengurangi kualitasnya. Ini adalah siklus yang tak terbatas!",
                "💧 Menghemat air adalah kunci. Dengan daur ulang, kita juga mengurangi energi yang dibutuhkan untuk memproduksi barang baru.",
                "🦋 Keanekaragaman hayati terlindungi saat kita menjaga lingkungan dari sampah. Hewan dan tumbuhan akan berterima kasih!"
            ];

            if (!Array.isArray(wasteData)) {
                wasteData = [];
            }

            function shuffleArray(array) {
                for (let i = array.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [array[i], array[j]] = [array[j], array[i]];
                }
            }

            function initGame() {
                gameOverModal.style.display = 'none';

                if (currentView !== 'game') return;

                if (gamePlayedToday || wasteData.length === 0) {
                    // Logika tampilan overlay saat game tidak bisa dimainkan
                    // Pastikan overlay di HTML sudah menangani ini
                    return;
                }

                score = 0;
                timeLeft = gameLevelConfig.duration_seconds;
                pointsPerCorrectSort = gameLevelConfig.points_per_correct_sort; // Ensure this is reset for each game
                gameEnded = false;
                selectedWaste = null; // Reset item yang dipilih
                gameViewElements.scoreDisplay.textContent = score;
                gameViewElements.timerDisplay.textContent = timeLeft;
                gameViewElements.gameLevelNameDisplay.textContent = gameLevelConfig.level_name;
                gameViewElements.wasteItemsContainer.innerHTML = '';
                // if (gameViewElements.playAgainBtn) gameViewElements.playAgainBtn.style.display = 'none'; // Dihapus

                shuffleArray(wasteData);
                wasteData.forEach(waste => {
                    createWasteItem(waste.id, waste.type, waste.image);
                });

                // Hapus draggable dan cursor grab, tambahkan event listener klik
                document.querySelectorAll('.waste-item').forEach(item => {
                    item.removeAttribute('draggable'); // Hapus atribut draggable
                    item.style.cursor = 'pointer'; // Ubah kursor menjadi pointer
                    item.addEventListener('click', selectWasteItem); // Tambahkan event listener klik
                });

                gameViewElements.remainingItemsDisplay.textContent = gameViewElements.wasteItemsContainer.children.length;

                startTimer();
            }

            function createWasteItem(id, type, imageUrl) {
                const wasteItem = document.createElement('div');
                wasteItem.classList.add('waste-item');
                wasteItem.dataset.type = type;
                wasteItem.id = id;

                const img = document.createElement('img');
                img.src = imageUrl;
                img.alt = id.replace('_', ' ');
                wasteItem.appendChild(img);

                gameViewElements.wasteItemsContainer.appendChild(wasteItem);
            }

            function startTimer() {
                clearInterval(timerInterval);
                timerInterval = setInterval(() => {
                    timeLeft--;
                    gameViewElements.timerDisplay.textContent = timeLeft;

                    if (timeLeft <= 0) {
                        clearInterval(timerInterval);
                        endGame();
                    }
                }, 1000);
            }

            function endGame() {
                if (gameEnded) return;
                gameEnded = true;

                // Hapus highlight jika ada sampah yang masih terpilih
                if (selectedWaste) {
                    selectedWaste.classList.remove('selected-waste');
                    selectedWaste = null;
                }

                // Nonaktifkan semua item sampah dan ubah kursor
                document.querySelectorAll('.waste-item').forEach(item => {
                    item.removeEventListener('click', selectWasteItem);
                    item.style.cursor = 'default';
                });

                // if (gameViewElements.playAgainBtn) gameViewElements.playAgainBtn.style.display = 'block'; // Dihapus

                saveScore(score);
            }

            async function saveScore(finalScore) {
                const levelId = gameLevelConfig.level_id;

                if (gamePlayedToday || levelId === null) {
                    console.warn("Skipping score save: Game already played today or level ID is null. Displaying modal without saving.");
                    displayGameOverModal(finalScore, <?php echo json_encode($currentTotalPoints); ?>, "Permainan sudah dimainkan hari ini atau level tidak valid.");
                    return;
                }

                let playerName = prompt("Game Selesai! Masukkan nama Anda untuk menyimpan skor:", "<?php echo htmlspecialchars($loggedInUsername); ?>");
                if (playerName === null || playerName.trim() === "") {
                    playerName = "Anonymous";
                } else {
                    playerName = playerName.trim().substring(0, 100);
                }

                try {
                    const response = await fetch(`?action=save_score&level_id=${levelId}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ score: finalScore, playerName: playerName })
                    });
                    const data = await response.json();

                    if (data.success) {
                        console.log(data.message);
                        displayGameOverModal(finalScore, data.newTotalPoints, data.message);
                        localStorage.setItem('total_points_updated', JSON.stringify(data.newTotalPoints));
                        // No immediate redirect, allow user to close modal
                    } else {
                        console.error("Error saving score:", data.message);
                        displayGameOverModal(finalScore, <?php echo json_encode($currentTotalPoints); ?>, "Gagal menyimpan skor: " + data.message);
                    }
                } catch (error) {
                    console.error("Network error when saving score:", error);
                    displayGameOverModal(finalScore, <?php echo json_encode($currentTotalPoints); ?>, "Terjadi kesalahan jaringan saat menyimpan skor.");
                }
            }

            function displayGameOverModal(finalScr, newTotalPts, message) {
                finalScoreDisplay.textContent = finalScr;
                newTotalPointsDisplay.textContent = newTotalPts;

                // Customize the message based on score or other criteria
                if (finalScr > 0) {
                    gameOverMessage.textContent = "Kerja bagus! Setiap skor adalah langkah kecil menuju bumi yang lebih baik.";
                } else {
                    gameOverMessage.textContent = "Jangan menyerah! Setiap usaha memilah sampah itu penting. Coba lagi untuk skor yang lebih baik!";
                }

                // Randomly select and display an educational fact
                const randomIndex = Math.floor(Math.random() * educationalFacts.length);
                factContent.textContent = educationalFacts[randomIndex];

                gameOverModal.style.display = 'flex';
            }

            // --- New Click-based Interaction Functions ---
            function selectWasteItem(e) {
                if (timeLeft <= 0 || gamePlayedToday || gameEnded || wasteData.length === 0) {
                    return;
                }

                const clickedWaste = e.target.closest('.waste-item');
                if (!clickedWaste) return;

                // Hapus highlight jika ada sampah yang masih terpilih
                if (selectedWaste && selectedWaste !== clickedWaste) {
                    selectedWaste.classList.remove('selected-waste');
                }

                // Toggle pemilihan item sampah
                if (selectedWaste === clickedWaste) {
                    clickedWaste.classList.remove('selected-waste');
                    selectedWaste = null;
                } else {
                    clickedWaste.classList.add('selected-waste');
                    selectedWaste = clickedWaste;
                }
            }

            gameViewElements.bins.forEach(bin => {
                bin.addEventListener('click', (e) => {
                    if (timeLeft <= 0 || gamePlayedToday || gameEnded || wasteData.length === 0) {
                        return;
                    }

                    if (!selectedWaste) {
                        // Beri tahu pengguna untuk memilih sampah terlebih dahulu
                        alert("Silakan pilih item sampah terlebih dahulu!");
                        return;
                    }

                    const droppedOnBin = e.target.closest('.bin');
                    if (!droppedOnBin) return;

                    const wasteType = selectedWaste.dataset.type;
                    const binType = droppedOnBin.dataset.binType;

                    if (wasteType === binType) {
                        score += pointsPerCorrectSort;
                        gameViewElements.scoreDisplay.textContent = score;
                        correctSound.play();

                        const correctIcon = document.createElement('i');
                        correctIcon.classList.add('fas', 'fa-check-circle', 'feedback-icon', 'correct');
                        droppedOnBin.appendChild(correctIcon);
                        setTimeout(() => correctIcon.remove(), 600);

                        selectedWaste.remove(); // Hapus item yang benar dari DOM
                        selectedWaste = null; // Reset item yang dipilih
                        gameViewElements.remainingItemsDisplay.textContent = gameViewElements.wasteItemsContainer.children.length;

                        if (gameViewElements.wasteItemsContainer.children.length === 0) {
                            clearInterval(timerInterval);
                            endGame();
                        }
                    } else {
                        wrongSound.play();
                        const wrongIcon = document.createElement('i');
                        wrongIcon.classList.add('fas', 'fa-times-circle', 'feedback-icon', 'wrong');
                        droppedOnBin.appendChild(wrongIcon);
                        setTimeout(() => wrongIcon.remove(), 600);
                    }

                    // Hapus kelas 'selected-waste' dari semua item setelah mencoba membuang
                    document.querySelectorAll('.waste-item').forEach(item => item.classList.remove('selected-waste'));
                });
            });

            // --- Modal and Game Buttons ---
            // If playAgainBtn was here, it's removed now.
            // If modalPlayAgainBtn was here, it's removed now.

            // Initialize the game only if the current view is 'game'
            if (currentView === 'game') {
                initGame();
            }
        });
    </script>
</body>
</html>