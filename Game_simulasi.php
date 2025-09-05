<?php
// Game_simulasi.php

// PASTIKAN config.php dimuat pertama kali untuk konstanta seperti CURRENT_USER_ID
require_once 'config.php';
require_once 'db_connection.php'; // Membutuhkan db_connection.php
require_once 'game_data.php'; // Contains GameConfig, WasteTypes, AllMachines, ProductIcons, and getUpgradeCost()

// Aktifkan pelaporan error MySQLi untuk debugging yang lebih baik
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Periksa apakah koneksi database berhasil
if ($conn->connect_error) {
    die("<h1>Kesalahan Fatal: Koneksi Database Gagal</h1><p>Mohon periksa pengaturan database Anda di `db_connection.php` dan pastikan server MySQL berjalan.</p><p>Detail: " . htmlspecialchars($conn->connect_error) . "</p>");
}

// Fetch initial game state for the user
$user_id = CURRENT_USER_ID;

// --- Debugging koneksi sebelum prepare statement (baik untuk dipertahankan) ---
if (!isset($conn) || !$conn) {
    die("<h1>Fatal Error: Objek koneksi database (\$conn) tidak tersedia atau bernilai null.</h1><p>Ini biasanya berarti db_connection.php gagal membuat koneksi atau \$conn tidak di-scope dengan benar.</p>");
}

if (!($conn instanceof mysqli)) {
    die("<h1>Fatal Error: \$conn bukan objek MySQLi yang valid.</h1><p>Diharapkan objek MySQLi, tetapi mendapatkan tipe lain. Periksa db_connection.php untuk masalah koneksi.</p><p>Tipe: " . gettype($conn) . "</p>");
}

if ($conn->connect_error) {
    die("<h1>Fatal Error: Koneksi MySQLi melaporkan kesalahan *setelah* inisialisasi.</h1><p>Ini seharusnya tidak terjadi jika db_connection.php berfungsi. Detail: " . htmlspecialchars($conn->connect_error) . "</p>");
}

// Check if the connection is actually active
if (mysqli_ping($conn) === false) {
    die("<h1>Fatal Error: Koneksi database tidak aktif (ping gagal).</h1><p>Koneksi mungkin terputus atau kredensial tidak valid. Detail: " . htmlspecialchars(mysqli_error($conn)) . "</p>");
}
// --- Akhir Debugging koneksi ---

// --- Fetch user's total points and last login reward date ---
$userTotalPoints = 0; // Default jika user_id tidak ditemukan atau total_points null
$lastLoginRewardDate = null; // Default

// Mengubah 'user_id' menjadi 'id' karena kolom ID utama di tabel 'users' adalah 'id'
$stmtUser = $conn->prepare("SELECT total_points, last_login_reward_date FROM users WHERE id = ?");
if ($stmtUser === false) {
    die("<h1>Kesalahan Fatal Saat Menyiapkan Kueri User</h1><p>Detail MySQLi: " . htmlspecialchars($conn->error) . "</p><p>SQL yang dicoba: SELECT total_points, last_login_reward_date FROM users WHERE id = ?</p>");
}

$stmtUser->bind_param("i", $user_id);
if ($stmtUser->execute() === false) {
    die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'users'</h1><p>Detail: " . htmlspecialchars($stmtUser->error) . "</p>");
}
$resultUser = $stmtUser->get_result();
if ($resultUser === false) {
    die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'users'</h1><p>Detail: " . htmlspecialchars($stmtUser->error) . "</p>");
}
if ($resultUser->num_rows > 0) {
    $userData = $resultUser->fetch_assoc();
    $userTotalPoints = $userData['total_points'];
    $lastLoginRewardDate = $userData['last_login_reward_date'];
}
$stmtUser->close();
// --- AKHIR BAGIAN USER DATA ---


// Game State
$stmt = $conn->prepare("SELECT player_money, current_day, is_game_over FROM game_state WHERE user_id = ?");
if ($stmt === false) {
    die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri 'game_state'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
}
$stmt->bind_param("i", $user_id);
if ($stmt->execute() === false) {
    die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'game_state'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
$result = $stmt->get_result();
if ($result === false) {
    die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'game_state'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
$gameStateDB = $result->fetch_assoc();
$stmt->close();

$initialGameState = []; // Inisialisasi untuk memastikan variabel ada
$currentWastePile = []; // Inisialisasi untuk memastikan variabel ada
$initialGameState['rewardGivenThisLoad'] = false; // BARU: Indikator reward untuk pemuatan ini

// Inisialisasi awal jika TIDAK ADA state game yang ditemukan untuk user ini
if (!$gameStateDB) {
    // Inisialisasi data game state dasar
    $stmt = $conn->prepare("INSERT INTO game_state (user_id, player_money, current_day, is_game_over) VALUES (?, ?, ?, ?)");
    if ($stmt === false) {
        die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri INSERT 'game_state'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
    }
    // Menggunakan poin dari tabel users sebagai starting money
    $playerMoney = $userTotalPoints;
    $currentDay = 1;
    $isGameOver_val = 0; // Mengubah literal '0' menjadi variabel
    $stmt->bind_param("iiii", $user_id, $playerMoney, $currentDay, $isGameOver_val); // Menggunakan variabel
    if ($stmt->execute() === false) {
        die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri INSERT 'game_state'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
    }
    $stmt->close();
    $gameStateDB = ['player_money' => $playerMoney, 'current_day' => $currentDay, 'is_game_over' => $isGameOver_val];

    // Berikan mesin awal
    $stmt = $conn->prepare("INSERT INTO user_machines (user_id, machine_name, machine_level) VALUES (?, ?, ?)");
    if ($stmt === false) {
        die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri INSERT 'user_machines'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
    }
    $initialMachineName = 'Penghancur Plastik';
    $initialMachineLevel = 1;
    $stmt->bind_param("isi", $user_id, $initialMachineName, $initialMachineLevel);
    if ($stmt->execute() === false) {
        die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri INSERT 'user_machines'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
    }
    $stmt->close();

    // Hapus sampah lama (jika ada karena suatu alasan) sebelum membuat yang baru untuk inisialisasi game
    $stmt_clear_waste = $conn->prepare("DELETE FROM daily_waste WHERE user_id = ?");
    if ($stmt_clear_waste === false) {
        die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri DELETE 'daily_waste'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
    }
    $stmt_clear_waste->bind_param("i", $user_id);
    if ($stmt_clear_waste->execute() === false) {
        die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri DELETE 'daily_waste'</h1><p>Detail: " . htmlspecialchars($stmt_clear_waste->error) . "</p>");
    }
    $stmt_clear_waste->close();

    // Generate sampah awal SAAT PERTAMA KALI GAME DIMUAT
    $numWaste = rand(GameConfig['DAILY_WASTE_COUNT_MIN'], GameConfig['DAILY_WASTE_COUNT_MAX']); // Menggunakan rentang dari config
    $wasteTypesList = array_keys(WasteTypes);

    for ($i = 0; $i < $numWaste; $i++) {
        $randomType = $wasteTypesList[array_rand($wasteTypesList)];
        $currentWastePile[] = $randomType; // Tambahkan ke array untuk tampilan awal

        // Simpan juga ke DB agar data konsisten setelah init pertama kali
        $stmt_waste = $conn->prepare("INSERT INTO daily_waste (user_id, waste_type, waste_order) VALUES (?, ?, ?)");
        if ($stmt_waste === false) {
            die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri INSERT 'daily_waste'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
        }
        $stmt_waste->bind_param("isi", $user_id, $randomType, $i);
        if ($stmt_waste->execute() === false) {
            die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri INSERT 'daily_waste'</h1><p>Detail: " . htmlspecialchars($stmt_waste->error) . "</p>");
        }
        $stmt_waste->close();
    }
    // Update $initialGameState['currentWastePile'] untuk tampilan awal yang benar
    $initialGameState['currentWastePile'] = $currentWastePile;

    // Tambahkan pesan log awal untuk sampah yang tiba
    $stmt_log = $conn->prepare("INSERT INTO message_log (user_id, log_message, log_type, timestamp) VALUES (?, ?, ?, ?)");
    if ($stmt_log === false) {
        die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri INSERT 'message_log'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
    }
    $initialMessage = "Selamat datang di Pabrik Daur Ulang Anda! " . $numWaste . " unit sampah baru telah tiba.";
    $currentTimestamp = date('Y-m-d H:i:s');
    $stmt_log->bind_param("isss", $user_id, $initialMessage, 'info', $currentTimestamp);
    if ($stmt_log->execute() === false) {
        die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri INSERT 'message_log'</h1><p>Detail: " . htmlspecialchars($stmt_log->error) . "</p>");
    }
    $stmt_log->close();
    // rewardGivenThisLoad akan tetap false karena ini adalah inisialisasi pertama

} else {
    // --- Cek dan Berikan Reward Login Harian jika game_state sudah ada ---
    $today = new DateTime();
    $lastRewardDateObj = $lastLoginRewardDate ? new DateTime($lastLoginRewardDate) : null;

    $rewardAmount = 50; // Jumlah poin reward harian
    // $initialGameState['rewardGivenThisLoad'] akan diatur di sini jika reward diberikan

    if (!$lastRewardDateObj || $lastRewardDateObj->format('Y-m-d') !== $today->format('Y-m-d')) {
        $gameStateDB['player_money'] += $rewardAmount;

        // Update game_state dengan poin baru
        $stmtUpdateGameState = $conn->prepare("UPDATE game_state SET player_money = ? WHERE user_id = ?");
        if ($stmtUpdateGameState === false) {
            die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri UPDATE 'game_state' (reward)</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
        }
        $stmtUpdateGameState->bind_param("ii", $gameStateDB['player_money'], $user_id);
        if ($stmtUpdateGameState->execute() === false) {
            die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri UPDATE 'game_state' (reward)</h1><p>Detail: " . htmlspecialchars($stmtUpdateGameState->error) . "</p>");
        }
        $stmtUpdateGameState->close();

        // Update last_login_reward_date dan total_points di tabel users
        $currentTimestamp = date('Y-m-d H:i:s');
        $newTotalUserPoints = $userTotalPoints + $rewardAmount; // Poin global user juga bertambah
        $stmtUpdateUser = $conn->prepare("UPDATE users SET last_login_reward_date = ?, total_points = ? WHERE id = ?");
        if ($stmtUpdateUser === false) {
            die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri UPDATE 'users' (reward)</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
        }
        $stmtUpdateUser->bind_param("sii", $currentTimestamp, $newTotalUserPoints, $user_id);
        if ($stmtUpdateUser->execute() === false) {
            die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri UPDATE 'users' (reward)</h1><p>Detail: " . htmlspecialchars($stmtUpdateUser->error) . "</p>");
        }
        $stmtUpdateUser->close();

        // Tambahkan pesan ke log
        $stmt_log = $conn->prepare("INSERT INTO message_log (user_id, log_message, log_type, timestamp) VALUES (?, ?, ?, ?)");
        if ($stmt_log === false) {
            die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri INSERT 'message_log' (reward)</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
        }
        $logMessage = "Reward Login Harian! Anda mendapatkan <b>+" . $rewardAmount . " Poin</b>.";
        $stmt_log->bind_param("isss", $user_id, $logMessage, 'success', $currentTimestamp);
        if ($stmt_log->execute() === false) {
            die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri INSERT 'message_log' (reward)</h1><p>Detail: " . htmlspecialchars($stmt_log->error) . "</p>");
        }
        $stmt_log->close();
        $initialGameState['rewardGivenThisLoad'] = true; // Set true karena reward diberikan
    }
    // --- AKHIR BAGIAN Cek dan Berikan Reward Login Harian ---
}


// Inventory
$inventory = [];
$stmt = $conn->prepare("SELECT item_name, quantity FROM user_inventory WHERE user_id = ?");
if ($stmt === false) {
    die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri 'user_inventory'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
}
$stmt->bind_param("i", $user_id);
if ($stmt->execute() === false) {
    die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'user_inventory'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
$result = $stmt->get_result();
if ($result === false) {
    die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'user_inventory'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
while ($row = $result->fetch_assoc()) {
    $inventory[$row['item_name']] = $row['quantity'];
}
$stmt->close();

// Owned Machines and Levels
$ownedMachines = [];
$machineLevels = [];
$stmt = $conn->prepare("SELECT machine_name, machine_level FROM user_machines WHERE user_id = ?");
if ($stmt === false) {
    die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri 'user_machines'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
}
$stmt->bind_param("i", $user_id);
if ($stmt->execute() === false) {
    die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'user_machines'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
$result = $stmt->get_result();
if ($result === false) {
    die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'user_machines'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
while ($row = $result->fetch_assoc()) {
    $ownedMachines[] = $row['machine_name'];
    $machineLevels[$row['machine_name']] = $row['machine_level'];
}
$stmt->close();

// Current Waste Pile (jika sudah diinisialisasi di atas, ini akan di-override jika ada dari DB)
// Jika game state sudah ada, maka $currentWastePile akan diambil dari DB di sini
if (empty($currentWastePile)) { // Hanya ambil dari DB jika tidak diisi oleh inisialisasi game baru
    $currentWastePile = [];
    $sql = "SELECT waste_type FROM daily_waste WHERE user_id = ? ORDER BY waste_order ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri 'daily_waste'</h1><p>Mohon periksa skema tabel `daily_waste` Anda.</p><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
    }
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute() === false) {
        die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'daily_waste'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
    }
    $result = $stmt->get_result();
    if ($result === false) {
        die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'daily_waste'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
    }
    while ($row = $result->fetch_assoc()) {
        $currentWastePile[] = $row['waste_type'];
    }
    $stmt->close();
}


// Message Log
$messageLog = [];
$stmt = $conn->prepare("SELECT log_message, log_type, timestamp FROM message_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT " . GameConfig['MESSAGE_LOG_MAX_ENTRIES']);
if ($stmt === false) {
    die("<h1>Kesalahan Fatal: Gagal menyiapkan kueri 'message_log'</h1><p>Detail: " . htmlspecialchars($conn->error) . "</p>");
}
$stmt->bind_param("i", $user_id);
if ($stmt->execute() === false) {
    die("<h1>Kesalahan Fatal: Gagal mengeksekusi kueri 'message_log'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
$result = $stmt->get_result();
if ($result === false) {
    die("<h1>Kesalahan Fatal: Gagal mendapatkan hasil kueri 'message_log'</h1><p>Detail: " . htmlspecialchars($stmt->error) . "</p>");
}
while ($row = $result->fetch_assoc()) {
    $messageLog[] = [
        'message' => $row['log_message'],
        'type' => $row['log_type'],
        'timestamp' => $row['timestamp'] // Keep as string for JS Date parsing
    ];
}
$stmt->close();

$conn->close();

// Pass PHP data to JavaScript
$initialGameState['playerMoney'] = $gameStateDB['player_money'];
$initialGameState['currentDay'] = $gameStateDB['current_day'];
$initialGameState['inventory'] = $inventory;
$initialGameState['currentWastePile'] = $currentWastePile;
$initialGameState['isGameOver'] = (bool)$gameStateDB['is_game_over'];
$initialGameState['messageLog'] = $messageLog;
$initialGameState['ownedMachines'] = $ownedMachines;
$initialGameState['machineLevels'] = $machineLevels;
// $initialGameState['rewardGivenThisLoad'] sudah diatur di atas.

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>♻️ Simulasi Daur Ulang Plastik</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Nunito:wght@400;600&display=swap" rel="stylesheet">
    <style>
        /* --- CSS Internal --- */
        :root {
            --primary-green: #28a745;
            --dark-green: #1e7e34;
            --light-green: #e9f5ee;
            --accent-blue: #007bff;
            --light-blue: #e0f7fa;
            --dark-blue-text: #0056b3;
            --background-color: #f0f8f0;
            --card-bg: #ffffff;
            --shadow-light: rgba(0, 0, 0, 0.1);
            --shadow-medium: rgba(0, 0, 0, 0.15);
            --border-color: #dee2e6;
            --error-red: #dc3545;
            --success-green: #28a745;
            --info-yellow: #ffc107;
            --soft-yellow-bg: #fffbe6;
            --soft-red-bg: #ffe6e6;
            --soft-green-bg: #e6ffe6;
        }

        body {
            font-family: 'Nunito', sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            background-color: var(--background-color);
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
            position: relative;
            color: #333;
            line-height: 1.6;
        }

        .game-container {
            background-color: var(--card-bg);
            border-radius: 16px; /* Slightly more rounded */
            box-shadow: 0 10px 25px var(--shadow-medium); /* Deeper shadow */
            padding: 35px;
            width: 100%;
            max-width: 1300px; /* Wider container */
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px; /* Increased gap */
            text-align: center;
            z-index: 1;
        }

        h1 {
            font-family: 'Poppins', sans-serif;
            color: var(--primary-green);
            margin-bottom: 25px;
            font-size: 3.2em; /* Larger title */
            grid-column: 1 / -1;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            letter-spacing: -0.5px;
        }

        h2, h3, h4 {
            font-family: 'Poppins', sans-serif;
            color: var(--dark-green);
            margin-bottom: 15px;
            font-weight: 600;
        }
        h2 { font-size: 2.2em; }
        h3 { font-size: 1.6em; }
        h4 { font-size: 1.2em; }

        p {
            color: #555;
            line-height: 1.7;
        }

        .game-info {
            display: flex;
            justify-content: space-around;
            gap: 25px; /* Increased gap */
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .info-box {
            background-color: var(--light-blue);
            border-radius: 10px;
            padding: 20px 30px;
            flex: 1;
            min-width: 180px; /* Wider info boxes */
            box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.08);
            border: 1px solid #cceeff;
        }

        .info-box p {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5em; /* Larger numbers */
            font-weight: 700;
            color: var(--dark-blue-text);
            margin: 8px 0 0;
            text-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .message-area {
            background-color: var(--soft-yellow-bg);
            border: 1px solid var(--info-yellow);
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 30px;
            font-style: italic;
            color: #925300;
            font-weight: 600;
            text-align: center;
        }
        .message-area.error-message {
            background-color: var(--soft-red-bg);
            border-color: var(--error-red);
            color: #b30000;
        }
        .message-area.success-message {
            background-color: var(--soft-green-bg);
            border-color: var(--success-green);
            color: #1a6021;
        }

        .message-log-container {
            max-height: 120px; /* Taller log */
            overflow-y: auto;
            background-color: #f8fff8; /* Even lighter green */
            border: 1px solid var(--primary-green);
            border-radius: 10px;
            padding: 12px;
            font-size: 0.9em;
            text-align: left;
            margin-top: -20px; /* Closer to message area */
            margin-bottom: 30px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
        }
        .message-log-container p {
            margin: 4px 0;
            color: #666;
            font-size: 0.85em;
        }
        .message-log-container p.log-error {
            color: var(--error-red);
        }
        .message-log-container p.log-success {
            color: var(--success-green);
        }


        .game-area {
            display: grid;
            grid-template-columns: 1.2fr 2fr; /* Adjusted proportions */
            gap: 30px;
            background-color: #fcfdfc; /* Whiter background */
            border-radius: 12px;
            padding: 25px;
            box-shadow: inset 0 0 12px rgba(0, 0, 0, 0.04);
            position: relative;
        }

        .waste-input-area, .processing-area, .inventory-area {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 10px var(--shadow-light);
        }

        .waste-pile {
            min-height: 150px; /* Taller waste pile */
            border: 2px dashed var(--accent-blue);
            background-color: #f0f8ff; /* Lighter blue */
            padding: 20px;
            border-radius: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px; /* Increased gap */
            justify-content: center;
            align-items: flex-start;
        }

        .waste-item {
            background-color: #dbeaff; /* Softer blue */
            border: 1px solid #a8cafa;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer; /* Change to pointer, no longer grab */
            font-size: 1em; /* Slightly larger */
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.1s ease-in-out, border 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            font-weight: 600;
            color: #333;
        }
        .waste-item:hover {
            transform: translateY(-3px);
            background-color: #c4daff;
        }
        .waste-item.selected {
            border: 3px solid var(--error-red); /* Stronger red for selection */
            box-shadow: 0 0 12px rgba(220, 53, 69, 0.7);
            transform: scale(1.06);
        }
        .waste-item .waste-icon {
            margin-right: 8px; /* More space for icon */
            font-size: 1.5em; /* Larger icon */
            line-height: 1;
        }

        .machines-grid {
            /* Default for larger screens and portrait */
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .machine {
            background-color: #fcfdfc;
            border: 1px solid #d0eeff; /* Lighter blue border */
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 10px var(--shadow-light);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 220px; /* Ensure enough space */
        }

        .machine-slot {
            min-height: 100px; /* Taller slot */
            border: 2px dashed #90caf9;
            background-color: #e3f2fd;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-style: italic;
            color: #999;
            padding: 15px;
            cursor: pointer;
            transition: background-color 0.2s ease, border 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
            position: relative;
            flex-grow: 1;
        }
        .machine-slot:hover {
            background-color: #c8e6c9;
            border-color: var(--primary-green);
        }
        .machine-slot.active-target {
            border: 3px solid var(--success-green);
            background-color: var(--soft-green-bg);
            box-shadow: 0 0 15px rgba(40, 167, 69, 0.6);
        }
        .machine-slot.hovered {
            background-color: #a5d6a7;
            border-color: var(--dark-green);
        }
        .machine-slot .machine-icon {
            font-size: 4em; /* Larger machine icons */
            line-height: 1;
            margin-bottom: 8px;
            display: block;
        }
        .machine-slot:has(.machine-icon) {
            font-style: normal;
            color: #333;
        }
        .machine-slot:has(.machine-icon) .default-slot-text {
            display: none;
        }
        .machine-slot .level-display {
            font-size: 0.9em;
            font-weight: bold;
            color: var(--dark-blue-text);
            margin-top: 5px;
        }

        .upgrade-button {
            background-color: #ffc107; /* Orange yellow */
            color: #333; /* Darker text for contrast */
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 0.95em;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 15px;
            width: 100%;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .upgrade-button:hover {
            background-color: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .upgrade-button:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .upgrade-button:disabled {
            background-color: #cccccc;
            color: #888;
            cursor: not-allowed;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }


        /* Machine Shop Styles (Pop-up) */
        .machine-shop-area {
            position: fixed;
            top: 0;
            right: -55%; /* Wider slide-out */
            width: 55%;
            height: 100%;
            background-color: var(--card-bg);
            border-left: 3px solid var(--primary-green);
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.3);
            padding: 35px;
            box-sizing: border-box;
            z-index: 950;
            transition: right 0.5s ease-out;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .machine-shop-area.active {
            right: 0;
        }
        .machine-shop-area .shop-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }
        .machine-shop-area .shop-header h3 {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            color: var(--primary-green);
            font-size: 2.5em;
        }
        .machine-shop-area .close-button {
            background: none;
            border: none;
            font-size: 3.2em; /* Larger X */
            color: #888;
            cursor: pointer;
            transition: color 0.2s ease, transform 0.3s ease;
            line-height: 1;
            padding: 0;
        }
        .machine-shop-area .close-button:hover {
            color: var(--error-red);
            transform: rotate(90deg) scale(1.15);
        }

        .shop-machines-grid {
            /* Default for larger screens and portrait */
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 25px;
        }
        .shop-machine-item {
            background-color: #f0f8ff; /* Light blue for shop items */
            border: 1px solid #cceeff;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 10px var(--shadow-light);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .shop-machine-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px var(--shadow-medium);
        }
        .shop-machine-item .machine-icon {
            font-size: 5.5em; /* Even larger icons in shop */
            margin-bottom: 20px;
            color: #007bff;
        }
        .shop-machine-item h4 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 10px;
            color: var(--dark-blue-text);
            font-size: 1.5em;
        }
        .shop-machine-item p {
            font-size: 1em;
            color: #666;
            flex-grow: 1;
            margin-bottom: 20px;
        }
        .shop-machine-item button {
            background-color: var(--primary-green);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 20px;
            width: 100%;
            font-weight: 600;
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }
        .shop-machine-item button:hover {
            background-color: var(--dark-green);
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.2);
        }
        .shop-machine-item button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px (0,0,0,0.1);
        }
        .shop-machine-item button:disabled {
            background-color: #bdbdbd;
            cursor: not-allowed;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }

        /* Shop Guide Styling */
        .shop-guide {
            background-color: #e6f7ff; /* Very light blue */
            border: 1px solid #b3e0ff;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 35px;
            font-size: 1em;
            line-height: 1.7;
            text-align: left;
            box-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.05);
        }
        .shop-guide h4 {
            font-family: 'Poppins', sans-serif;
            color: #0056b3; /* Darker blue for guide title */
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.4em;
            text-align: center;
        }
        .shop-guide ul {
            list-style: none; /* Remove default bullet */
            padding-left: 0;
            margin: 0;
        }
        .shop-guide li {
            margin-bottom: 12px;
            padding-left: 28px; /* Space for custom bullet */
            position: relative;
        }
        .shop-guide li:before {
            content: '✔️'; /* Custom bullet */
            position: absolute;
            left: 0;
            color: var(--success-green);
            font-size: 1.1em;
            line-height: 1.6;
        }
        .shop-guide .highlight {
            font-weight: bold;
            color: #e67e22; /* More prominent orange highlight */
        }
        .shop-guide .machine-name-in-guide {
            font-weight: bold;
            color: #34495e; /* Darker gray for machine names */
        }
        .shop-guide .output-product-in-guide {
            font-weight: bold;
            color: var(--dark-green);
        }


        /* Shop Button Icon */
        #shop-button {
            background-color: var(--accent-blue); /* Blue button */
            color: white;
            border: none;
            padding: 20px;
            border-radius: 50%;
            font-size: 2.8em; /* Larger icon */
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            position: fixed;
            bottom: 35px;
            right: 35px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
            line-height: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 75px;
            height: 75px;
            z-index: 900;
        }
        #shop-button:hover {
            background-color: #0056b3;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.35);
        }
        #shop-button:active {
            transform: translateY(0);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.2);
        }

        /* Overlay for background when shop is open */
        .shop-overlay-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7); /* Darker backdrop */
            z-index: 900;
            display: none;
            transition: opacity 0.5s ease-out;
            opacity: 0;
        }
        .shop-overlay-backdrop.active {
            display: block;
            opacity: 1;
        }


        /* --- Animasi Mesin --- */
        @keyframes shredderAnimation {
            0% { transform: scale(1); }
            25% { transform: scale(0.97) rotate(1.5deg); }
            50% { transform: scale(1.03) rotate(-1.5deg); }
            75% { transform: scale(0.97) rotate(1.5deg); }
            100% { transform: scale(1); }
        }
        @keyframes melterAnimation {
            0% { background-color: #f0f8ff; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
            50% { background-color: #ffe0b2; box-shadow: 0 0 30px rgba(255,152,0,0.9); /* More intense orange glow */ }
            100% { background-color: #f0f8ff; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
        }
        @keyframes pressAnimation {
            0% { transform: translateY(0); }
            50% { transform: translateY(8px); }
            100% { transform: translateY(0); }
        }
        @keyframes washAnimation {
            0% { transform: rotate(0deg); background-color: #e3f2fd; }
            50% { transform: rotate(5deg); background-color: #b2ebf2; }
            100% { transform: rotate(0deg); background-color: #e3f2fd; }
        }
        @keyframes dryAnimation {
            0% { transform: translateX(0); opacity: 1; }
            25% { transform: translateX(5px); opacity: 0.9; }
            50% { transform: translateX(-5px); opacity: 1; }
            75% { transform: translateX(5px); opacity: 0.9; }
            100% { transform: translateX(0); opacity: 1; }
        }

        .machine-slot.is-processing.Penghancur-Plastik {
            animation: shredderAnimation 0.8s ease-in-out forwards;
        }
        .machine-slot.is-processing.Pelebur---Pencetak-Pelet {
            animation: melterAnimation 0.8s ease-in-out forwards;
        }
        .machine-slot.is-processing.Mesin-Cetak-Produk-Jadi {
            animation: pressAnimation 0.8s ease-in-out forwards;
        }
        .machine-slot.is-processing.Mesin-Cuci-Plastik {
            animation: washAnimation 0.8s ease-in-out forwards;
        }
        .machine-slot.is-processing.Mesin-Pengering-Plastik {
            animation: dryAnimation 0.8s ease-in-out forwards;
        }
        .machine-slot.is-processing.Pencetak-Botol-Baru,
        .machine-slot.is-processing.Pencetak-Papan-Komposit {
            animation: pressAnimation 0.8s ease-in-out forwards;
        }


        /* --- Animasi Sampah Masuk dan Hancur --- */
        .animated-waste {
            position: absolute;
            background-color: #a7d9f7;
            border: 1px solid #7cbcdb;
            padding: 8px 12px;
            border-radius: 6px;
            pointer-events: none;
            z-index: 10;
            opacity: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.8em; /* Larger animated waste icon */
            font-weight: bold;
            color: #333;
        }

        @keyframes shrinkAndFade {
            0% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            50% { transform: translate(-50%, -50%) scale(0.6); opacity: 0.7; }
            100% { transform: translate(-50%, -50%) scale(0); opacity: 0; }
        }

        .animated-waste.shredding {
            animation: shrinkAndFade 0.4s ease-out forwards;
            animation-delay: 0.3s; /* Adjusted delay */
        }

        .product-item {
            background-color: #d4edda;
            border: 1px solid var(--success-green);
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 1em;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
            font-weight: 600;
            color: #333;
            transition: background-color 0.1s ease;
            cursor: pointer; /* Change to pointer */
        }
        .product-item:hover {
            background-color: #c0f0c0;
            cursor: pointer;
        }
        .product-item.selected {
            border: 3px solid var(--error-red);
            box-shadow: 0 0 12px rgba(220, 53, 69, 0.7);
            transform: scale(1.02);
        }

        .product-item button {
            background-color: var(--success-green);
            color: white;
            border: none;
            padding: 7px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85em;
            transition: background-color 0.2s ease, transform 0.1s ease;
            margin-left: 12px;
            font-weight: 600;
        }
        .product-item button:hover {
            background-color: var(--dark-green);
            transform: translateY(-1px);
        }
        .product-item button:active {
            transform: translateY(0);
        }
        .product-item button.process-again-btn {
            background-color: #f39c12;
        }
        .product-item button.process-again-btn:hover {
            background-color: #e67e22;
        }

        .product-item .product-icon {
            vertical-align: middle;
            margin-right: 10px;
            font-size: 1.8em; /* Larger icon */
            line-height: 1;
        }

        #next-day-btn {
            background-color: var(--accent-blue);
            color: white;
            border: none;
            padding: 18px 35px;
            border-radius: 10px;
            font-size: 1.3em;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 30px;
            font-weight: 700;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        #next-day-btn:hover {
            background-color: #0056b3;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
        }
        #next-day-btn:active {
            transform: translateY(0);
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }

        .game-over-overlay, .confirm-overlay, .other-games-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85); /* Darker overlay */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            flex-direction: column;
            color: white;
            font-size: 2.2em; /* Larger text */
            text-align: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .game-over-overlay.active, .confirm-overlay.active, .other-games-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .game-over-overlay h2, .other-games-overlay h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 3em;
            color: #ffeb3b; /* Yellow for attention */
            margin-bottom: 20px;
            text-shadow: 0 0 15px rgba(255,255,0,0.5);
        }

        .game-over-overlay p, .confirm-overlay p, .other-games-overlay p {
            font-size: 0.8em;
            max-width: 600px;
            margin-bottom: 30px;
            color: #f0f0f0;
        }

        .game-over-overlay button, .confirm-overlay button, .other-games-overlay button {
            background-color: var(--error-red);
            color: white;
            border: none;
            padding: 18px 35px;
            border-radius: 10px;
            font-size: 0.9em;
            cursor: pointer;
            margin-top: 25px;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            font-weight: 600;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
        }

        .game-over-overlay button:hover, .confirm-overlay button:hover, .other-games-overlay button:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.25);
        }
        .game-over-overlay button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }


        .confirm-overlay .button-group {
            display: flex;
            gap: 25px;
            margin-top: 30px;
        }
        .confirm-overlay .button-group button {
            background-color: var(--success-green);
        }
        .confirm-overlay .button-group button:hover {
            background-color: var(--dark-green);
        }
        .confirm-overlay .button-group button.cancel {
            background-color: var(--error-red);
        }
        .confirm-overlay .button-group button.cancel:hover {
            background-color: #c0392b;
        }

        /* Other Games Overlay Specifics */
        .other-games-overlay .game-links {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }
        .other-games-overlay .game-links a {
            background-color: #007bff;
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.8em;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
        }
        .other-games-overlay .game-links a:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.25);
        }
        .other-games-overlay .close-other-games-btn {
            background-color: #6c757d;
        }
        .other-games-overlay .close-other-games-btn:hover {
            background-color: #5a6268;
        }


        /* Media query untuk tampilan yang lebih kecil dari 900px (baik portrait maupun landscape) */
        @media (max-width: 900px) {
            .game-container {
                padding: 20px;
                gap: 20px;
            }
            h1 {
                font-size: 2.5em;
            }
            .game-info {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            .info-box {
                min-width: unset;
                padding: 15px 20px;
            }
            .info-box p {
                font-size: 2em;
            }
            .message-area {
                padding: 12px;
            }
            .message-log-container {
                max-height: 100px;
            }
            .game-area {
                grid-template-columns: 1fr; /* Default to single column for portrait small screens */
                gap: 20px;
                padding: 15px;
            }
            .waste-item, .product-item {
                font-size: 0.9em;
                padding: 8px 12px;
            }
            .waste-item .waste-icon, .product-item .product-icon {
                font-size: 1.3em;
            }
            .machines-grid {
                /* Untuk layar <= 900px, secara default 1 atau 2 kolom dalam portrait */
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
            .machine {
                min-height: 180px;
            }
            .machine-slot .machine-icon {
                font-size: 3em;
            }
            .machine-slot .level-display {
                font-size: 0.9em; /* Tetap 0.9em atau sesuaikan jika perlu */
            }
            #shop-button {
                bottom: 20px;
                right: 20px;
                width: 60px;
                height: 60px;
                font-size: 2.2em;
            }
            .machine-shop-area {
                width: 80%;
                right: -80%;
                padding: 25px;
            }
            .machine-shop-area .shop-header h3 {
                font-size: 2em;
            }
            .machine-shop-area .close-button {
                font-size: 2.5em;
            }
            .shop-machines-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            .shop-machine-item .machine-icon {
                font-size: 4em;
            }
            .game-over-overlay, .confirm-overlay, .other-games-overlay {
                font-size: 1.5em;
                padding: 20px;
            }
            .game-over-overlay h2, .other-games-overlay h2 {
                font-size: 2.2em;
            }
            .game-over-overlay p, .confirm-overlay p, .other-games-overlay p {
                font-size: 0.8em;
                margin-bottom: 20px;
            }
            .game-over-overlay button, .confirm-overlay button, .other-games-overlay button {
                padding: 12px 25px;
                font-size: 0.8em;
            }
            .confirm-overlay .button-group {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Media query untuk tampilan yang lebih kecil dari 500px */
        @media (max-width: 500px) {
            h1 {
                font-size: 2em;
            }
            .game-info {
                gap: 10px;
            }
            .info-box p {
                font-size: 1.8em;
            }
            .message-area {
                padding: 12px;
            }
            .waste-pile {
                padding: 10px;
                min-height: 100px;
            }
            .waste-item {
                font-size: 0.8em;
                padding: 6px 10px;
            }
            .waste-item .waste-icon {
                font-size: 1em;
            }
            .machine-shop-area {
                width: 95%;
                right: -95%;
            }
            .shop-guide {
                padding: 15px;
            }
            .shop-guide h4 {
                font-size: 1.2em;
            }
            .shop-guide li {
                font-size: 0.9em;
            }
            #next-day-btn {
                padding: 12px 25px;
                font-size: 1em;
            }
            .game-over-overlay, .confirm-overlay, .other-games-overlay {
                font-size: 1.2em;
            }
            .game-over-overlay h2, .other-games-overlay h2 {
                font-size: 1.8em;
            }
            .game-over-overlay p, .confirm-overlay p, .other-games-overlay p {
                font-size: 0.7em;
                margin-bottom: 15px;
            }
            .game-over-overlay button, .confirm-overlay button, .other-games-overlay button {
                font-size: 0.8em;
                padding: 12px 25px;
            }
        }

        /* --- Mode Landscape (OVERRIDE untuk semua lebar jika orientasi landscape) --- */
        @media screen and (orientation: landscape) {
            body {
                padding: 10px;
            }

            /* Sembunyikan pesan peringatan jika sudah dalam landscape */
            .portrait-warning {
                display: none !important;
            }
            /* Tampilkan konten game utama jika sudah dalam landscape */
            .game-container {
                display: flex !important; /* Gunakan flexbox untuk tata letak umum di landscape */
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                align-items: flex-start;
                gap: 15px;
                padding: 15px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
                max-width: 100%; /* Pastikan mengambil lebar penuh */
            }

            h1 {
                font-size: 1.8em;
                margin-bottom: 10px;
                flex-basis: 100%; /* Agar judul tetap di baris sendiri */
            }

            .game-info {
                flex-direction: row;
                justify-content: space-around;
                gap: 8px;
                margin-bottom: 10px;
                flex-basis: 100%;
            }

            .info-box {
                padding: 8px 12px;
                min-width: 80px;
                font-size: 0.8em;
            }
            .info-box p {
                font-size: 1.3em;
            }

            .message-area, .message-log-container {
                margin-bottom: 10px;
                flex-basis: 100%;
                font-size: 0.8em;
                padding: 10px;
            }
            .message-log-container {
                max-height: 70px;
            }

            .game-area {
                /* Gunakan display block atau flexbox untuk memastikan konten mengisi area */
                display: flex; /* Menggunakan flex untuk area game di landscape */
                flex-direction: row; /* Berdampingan: waste-input dan processing */
                flex-wrap: wrap; /* Izinkan wrap jika tidak muat */
                gap: 10px;
                padding: 10px;
                flex-grow: 1; /* Agar mengambil sisa ruang */
                min-height: 250px;
                width: 100%; /* Harus mengambil lebar penuh dari flex container utamanya */
            }

            /* Sesuaikan lebar masing-masing sub-area di dalam game-area */
            .waste-input-area {
                flex: 1 1 35%; /* Mengambil sekitar 35% lebar, boleh menyusut/bertumbuh */
                min-width: 150px; /* Minimal lebar untuk waste input */
                max-width: 300px;
            }
            .processing-area {
                flex: 1 1 60%; /* Mengambil sekitar 60% lebar, boleh menyusut/bertumbuh */
                min-width: 250px; /* Minimal lebar untuk processing area */
            }

            .waste-input-area, .processing-area, .inventory-area {
                padding: 10px;
                box-shadow: none;
            }

            .waste-pile {
                min-height: 60px;
                padding: 8px;
                gap: 6px;
            }

            .waste-item {
                font-size: 0.75em;
                padding: 5px 8px;
            }
            .waste-item .waste-icon {
                font-size: 1.1em;
            }

            /* MESIN AREA PEMROSESAN: 3 KOLOM DI LANDSCAPE */
            .machines-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Target 3 kolom */
                gap: 8px;
            }

            .machine {
                min-height: 120px;
                padding: 6px;
            }

            .machine h4 {
                font-size: 0.8em;
                margin-bottom: 4px;
            }

            .machine p {
                font-size: 0.65em;
                margin-bottom: 4px;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 2; /* Batasi 2 baris */
                -webkit-box-orient: vertical;
            }

            .machine-slot {
                min-height: 50px;
                padding: 6px;
            }

            .machine-slot .machine-icon {
                font-size: 1.6em;
                margin-bottom: 2px;
            }
            .machine-slot .level-display {
                font-size: 0.65em;
            }

            .upgrade-button {
                padding: 5px 8px;
                font-size: 0.7em;
                margin-top: 6px;
            }

            .inventory-area {
                flex-basis: 100%; /* Inventaris selalu di bawah di landscape */
                margin-top: 15px;
            }
            .product-item {
                font-size: 0.75em;
                padding: 6px 10px;
            }
            .product-item .product-icon {
                font-size: 1.1em;
            }
            .product-item button {
                padding: 5px 10px;
                font-size: 0.7em;
            }

            #next-day-btn {
                padding: 10px 20px;
                font-size: 0.9em;
                margin-top: 15px;
                width: 100%;
            }

            #shop-button {
                bottom: 10px;
                right: 10px;
                width: 45px;
                height: 45px;
                font-size: 1.8em;
                padding: 8px;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            }

            .machine-shop-area {
                width: 90%;
                right: -90%;
                padding: 15px;
            }
            .machine-shop-area.active {
                right: 0;
            }
            .machine-shop-area .shop-header h3 {
                font-size: 1.8em;
            }
            .machine-shop-area .close-button {
                font-size: 2em;
            }

            /* TOKO MESIN: 3 KOLOM DI LANDSCAPE */
            .shop-machines-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Target 3 kolom */
                gap: 8px;
            }
            .shop-machine-item {
                padding: 8px;
                min-height: 130px;
            }
            .shop-machine-item .machine-icon {
                font-size: 2.5em;
                margin-bottom: 8px;
            }
            .shop-machine-item h4 {
                font-size: 0.9em;
                margin-bottom: 4px;
            }
            .shop-machine-item p {
                font-size: 0.7em;
                margin-bottom: 8px;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 3; /* Batasi 3 baris */
                -webkit-box-orient: vertical;
            }
            .shop-machine-item button {
                padding: 6px 12px;
                font-size: 0.75em;
                margin-top: 8px;
            }

            .shop-guide {
                padding: 15px;
                font-size: 0.9em;
            }
            .shop-guide h4 {
                font-size: 1.2em;
            }
            .shop-guide li {
                font-size: 0.85em;
            }

            .game-over-overlay, .confirm-overlay, .other-games-overlay {
                font-size: 1em;
                padding: 10px;
            }
            .game-over-overlay h2, .other-games-overlay h2 {
                font-size: 1.5em;
            }
            .game-over-overlay p, .confirm-overlay p, .other-games-overlay p {
                font-size: 0.7em;
                margin-bottom: 15px;
            }
            .game-over-overlay button, .confirm-overlay button, .other-games-overlay button {
                padding: 8px 15px;
                font-size: 0.7em;
            }
        }

        /* Override untuk layar landscape yang sangat kecil (misal, HP yang sangat sempit dalam landscape) */
        @media screen and (orientation: landscape) and (max-width: 600px) {
            .machines-grid, .shop-machines-grid {
                grid-template-columns: repeat(auto-fit, minmax(60px, 1fr)); /* Bisa 2 atau 3 kolom tergantung ruang */
                gap: 5px;
            }
            .machine, .shop-machine-item {
                padding: 4px;
                min-height: 100px;
            }
            .machine h4, .shop-machine-item h4 {
                font-size: 0.7em;
            }
            .machine p, .shop-machine-item p {
                font-size: 0.6em;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 2; /* Mungkin perlu lebih sedikit baris */
                -webkit-box-orient: vertical;
            }
            .machine-slot .machine-icon, .shop-machine-item .machine-icon {
                font-size: 1.4em;
            }
            .machine-slot .level-display, .upgrade-button, .shop-machine-item button {
                font-size: 0.6em;
                padding: 3px 6px;
            }
        }
        @media screen and (orientation: landscape) and (max-width: 400px) {
            .machines-grid, .shop-machines-grid {
                grid-template-columns: repeat(auto-fit, minmax(50px, 1fr)); /* Mungkin hanya bisa 2 kolom */
            }
            .machine p, .shop-machine-item p {
                -webkit-line-clamp: 1; /* Hanya 1 baris deskripsi */
            }
        }

        /* --- Peringatan Mode Portrait (BARU) --- */
        @media screen and (orientation: portrait) {
            body {
                /* Untuk memastikan body menutupi seluruh layar dan pusat konten peringatan */
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                overflow: hidden; /* Sembunyikan scrollbar yang mungkin muncul */
                padding: 0; /* Pastikan tidak ada padding di body saat portrait */
            }

            .game-container, #shop-button {
                display: none; /* Sembunyikan semua elemen game utama */
            }

            .portrait-warning {
                display: flex; /* Tampilkan pesan peringatan */
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                font-family: 'Poppins', sans-serif;
                font-size: 1.8em;
                color: var(--error-red); /* Warna merah untuk peringatan */
                background-color: var(--soft-red-bg);
                padding: 30px;
                border: 2px solid var(--error-red);
                border-radius: 15px;
                max-width: 90%;
                margin: 20px; /* Sedikit margin dari tepi layar */
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            }
            .portrait-warning h2 {
                color: var(--error-red);
                margin-bottom: 15px;
                font-size: 1.5em; /* Ukuran H2 lebih kecil di peringatan */
            }
            .portrait-warning p {
                color: #555;
                font-size: 0.8em;
                margin-bottom: 10px;
            }
            .portrait-warning .rotate-icon {
                font-size: 3em; /* Ukuran ikon putar */
                margin-top: 15px;
                animation: rotate360 2s infinite linear; /* Animasi berputar */
            }
        }

        /* Animasi ikon putar */
        @keyframes rotate360 {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

    </style>
</head>
<body>
    <div class="portrait-warning">
        <h2>Mohon Putar Perangkat Anda!</h2>
        <p>Game ini dioptimalkan untuk tampilan landscape. Silakan putar perangkat Anda untuk pengalaman bermain terbaik.</p>
        <p class="rotate-icon">🔄</p>
    </div>

    <div class="game-container">
        <h1>♻️ Simulasi Daur Ulang Plastik</h1>

        <div class="game-info">
            <div class="info-box">
                <h2>Hari</h2>
                <p id="day"><?php echo htmlspecialchars($initialGameState['currentDay']); ?></p>
            </div>
            <div class="info-box">
                <h2>Poin</h2>
                <p id="money"><?php echo htmlspecialchars(number_format($initialGameState['playerMoney'])); ?> 🪙</p>
            </div>
            <div class="info-box">
                <h2>Sampah Menumpuk</h2>
                <p id="unprocessed-waste-count"><?php echo htmlspecialchars(count($initialGameState['currentWastePile'])); ?></p>
            </div>
        </div>

        <div class="message-area" id="message-area">
            <?php
            // BARU: Pesan awal yang lebih cerdas saat game dimuat
            if ($initialGameState['rewardGivenThisLoad']) {
                echo 'Reward Login Harian! Anda mendapatkan <b>+50 Poin</b>.';
            } else if ($initialGameState['currentDay'] === 1 && empty($gameStateDB)) { // Hanya untuk inisialisasi game baru
                echo 'Selamat datang di Pabrik Daur Ulang Anda! ' . count($initialGameState['currentWastePile']) . ' unit sampah baru telah tiba.';
            } else if (empty($initialGameState['currentWastePile'])) {
                echo 'Selamat datang kembali! Semua sampah sudah diolah. Tekan "Akhiri Hari" untuk menerima pengiriman baru.';
            } else {
                echo 'Game dimuat dari penyimpanan. Lanjutkan mengolah sampah Anda!';
            }
            ?>
        </div>
        <div class="message-log-container" id="message-log-container">
            <?php foreach ($initialGameState['messageLog'] as $log): ?>
                <p class="log-<?php echo htmlspecialchars($log['type']); ?>">[<?php echo date('H:i:s', strtotime($log['timestamp'])); ?>] <?php echo htmlspecialchars(strip_tags($log['message'])); ?></p>
            <?php endforeach; ?>
        </div>

        <div class="game-area">
            <div class="waste-input-area">
                <h3>Sampah Masuk</h3>
                <div id="waste-pile" class="waste-pile">
                    <?php foreach ($initialGameState['currentWastePile'] as $index => $wasteType): ?>
                        <div class="waste-item" data-type="<?php echo htmlspecialchars($wasteType); ?>" data-index="<?php echo $index; ?>">
                            <span class="waste-icon"><?php echo htmlspecialchars((defined('ProductIcons') && is_array(ProductIcons) && isset(ProductIcons[$wasteType])) ? ProductIcons[$wasteType] : (isset(WasteTypes[$wasteType]['icon']) ? WasteTypes[$wasteType]['icon'] : '❓')); ?></span><?php echo htmlspecialchars(isset(WasteTypes[$wasteType]['name']) ? WasteTypes[$wasteType]['name'] : $wasteType); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="processing-area">
                <h3>Area Pemrosesan</h3>
                <div class="machines-grid" id="processing-machines-grid">
                    <?php foreach ($initialGameState['ownedMachines'] as $machineName): ?>
                        <?php
                            if (!isset(AllMachines[$machineName]) || !is_array(AllMachines[$machineName])) {
                                error_log("Peringatan: Mesin '" . htmlspecialchars($machineName) . "' dari user_machines tidak ditemukan atau bukan array di AllMachines. Melewatkan mesin ini.");
                                continue;
                            }
                            $machineInfo = AllMachines[$machineName];
                            $machineLevel = $initialGameState['machineLevels'][$machineName] ?? 1;
                            $currentMoneyPerProcess = GameConfig['MONEY_PER_PROCESS'] * $machineLevel;

                            $machineDescription = $machineInfo['description'] ?? 'Deskripsi tidak tersedia.';
                            $tooltipText = $machineDescription . "\nPendapatan per penggunaan: " . $currentMoneyPerProcess . " Poin\n\nResep:\n";
                            $hasRecipes = false;

                            if (isset($machineInfo['recipes']) && is_array($machineInfo['recipes'])) {
                                foreach ($machineInfo['recipes'] as $inputType => $recipe) {
                                    if (is_array($recipe) && isset($recipe['output'], $recipe['success_rate'])) {
                                        $inputName = isset(WasteTypes[$inputType]['name']) ? WasteTypes[$inputType]['name'] : $inputType;
                                        $tooltipText .= "  • " . $inputName . " ➡️ " . $recipe['output'] . " (" . $recipe['success_rate'] . "% berhasil)\n";
                                        $hasRecipes = true;
                                    } else {
                                        error_log("Peringatan: Resep tidak valid untuk mesin '" . htmlspecialchars($machineName) . "', input '" . htmlspecialchars($inputType) . "'.");
                                    }
                                }
                            }
                            if (!$hasRecipes) {
                                $tooltipText .= '  (Tidak ada resep)';
                            }

                            $upgradeButtonText = 'Level Maks!';
                            $upgradeButtonDisabled = 'disabled';
                            $upgradeButtonCost = 0;
                            if ($machineLevel < GameConfig['UPGRADE_LEVEL_CAP']) {
                                $upgradeButtonCost = getUpgradeCost($machineLevel, GameConfig['UPGRADE_COST_TIERS']);
                                $upgradeButtonText = "Upgrade Lvl " . ($machineLevel + 1) . " (" . number_format($upgradeButtonCost) . " Poin)";
                                $upgradeButtonDisabled = ($initialGameState['playerMoney'] < $upgradeButtonCost) ? 'disabled' : '';
                                if ($upgradeButtonDisabled) {
                                    $upgradeButtonText .= ' (Poin tidak cukup)';
                                }
                            }
                        ?>
                        <div class="machine">
                            <h4><?php echo htmlspecialchars($machineName); ?></h4>
                            <p><?php echo htmlspecialchars($machineDescription); ?></p>
                            <div class="machine-slot" data-machine-name="<?php echo htmlspecialchars($machineName); ?>" title="<?php echo htmlspecialchars($tooltipText); ?>">
                                <span class="machine-icon"><?php echo htmlspecialchars($machineInfo['icon'] ?? '❓'); ?></span>
                                <span class="level-display">Level: <?php echo htmlspecialchars($machineLevel); ?></span>
                            </div>
                            <button class="upgrade-button" data-machine-name="<?php echo htmlspecialchars($machineName); ?>" data-current-level="<?php echo htmlspecialchars($machineLevel); ?>" data-upgrade-cost="<?php echo htmlspecialchars($upgradeButtonCost); ?>" <?php echo $upgradeButtonDisabled; ?>>
                                <?php echo htmlspecialchars($upgradeButtonText); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="inventory-area">
            <h3>Inventaris & Penjualan Produk</h3>
            <div id="inventory-list" class="inventory-list">
                <?php
                $hasDisplayableItem = false;
                foreach ($initialGameState['inventory'] as $item => $quantity):
                    if ($quantity > 0):
                        $hasDisplayableItem = true;
                        $itemSellValue = 0;
                        $canProcessFurther = false;
                        $isSellableProduct = false;

                        // Check if item can be processed further
                        // Ini akan mencari semua mesin yang menerima $item sebagai input
                        foreach (AllMachines as $machineInfo) {
                            if (is_array($machineInfo) && isset($machineInfo['recipes']) && is_array($machineInfo['recipes']) && isset($machineInfo['recipes'][$item])) {
                                if (is_array($machineInfo['recipes'][$item])) {
                                    $canProcessFurther = true;
                                    break;
                                }
                            }
                        }

                        // Check if item is a sellable product and get its value
                        // Ini mencari resep di semua mesin di mana $item adalah OUTPUT dan memiliki nilai jual
                        foreach (AllMachines as $machineName => $machineInfo) {
                            if (is_array($machineInfo) && isset($machineInfo['recipes']) && is_array($machineInfo['recipes'])) {
                                foreach ($machineInfo['recipes'] as $recipeInput => $recipe) {
                                    if (is_array($recipe) && isset($recipe['output']) && isset($recipe['value'])) { // Pastikan output dan value ada
                                        if ($recipe['output'] === $item && $recipe['value'] > 0) {
                                            $isSellableProduct = true;
                                            $itemSellValue = $recipe['value'];
                                            break 2; // Break both inner and outer loops once found
                                        }
                                    }
                                }
                            }
                            if ($isSellableProduct) break; // Break outer loop if already found
                        }

                        // Get product icon, with robust fallback
                        $productIcon = '❓'; // Default fallback
                        if (defined('ProductIcons') && is_array(ProductIcons) && isset(ProductIcons[$item])) {
                            $productIcon = ProductIcons[$item];
                        } else {
                            // Jika tidak ditemukan di ProductIcons, coba cari di WasteTypes jika item adalah tipe sampah asli
                            if (isset(WasteTypes[$item]) && isset(WasteTypes[$item]['icon'])) {
                                $productIcon = WasteTypes[$item]['icon'];
                            }
                            error_log("Peringatan: Ikon tidak ditemukan untuk item inventaris: " . $item);
                        }
                        
                        $productDivTitle = "Jumlah: " . $quantity . " unit";
                        if ($canProcessFurther && !$isSellableProduct) {
                            $productDivTitle .= ' | Dapat diolah lebih lanjut.';
                        }
                        if ($isSellableProduct && $itemSellValue > 0) {
                            $productDivTitle .= ' | Nilai jual: ' . $itemSellValue . ' Poin';
                        }
                ?>
                        <div class="product-item" data-type="<?php echo htmlspecialchars($item); ?>" data-quantity="<?php echo htmlspecialchars($quantity); ?>" title="<?php echo htmlspecialchars($productDivTitle); ?>">
                            <span><span class="product-icon"><?php echo htmlspecialchars($productIcon); ?></span> <?php echo htmlspecialchars($item); ?> (<?php echo htmlspecialchars($quantity); ?> unit)</span>
                            <?php if ($canProcessFurther && !$isSellableProduct): ?>
                                <button class="process-again-btn">Olah Lagi</button>
                            <?php endif; ?>
                            <?php if ($isSellableProduct && $itemSellValue > 0): ?>
                                <button class="sell-button">Jual (<?php echo number_format($itemSellValue); ?> Poin)</button>
                            <?php endif; ?>
                        </div>
                <?php
                    endif;
                endforeach;
                if (!$hasDisplayableItem): ?>
                    <p>Inventaris Anda kosong atau berisi residu yang tidak bisa dijual.</p>
                <?php endif; ?>
            </div>
            <button id="next-day-btn">Akhiri Hari</button>
        </div>
    </div>

    <div class="shop-overlay-backdrop" id="shop-overlay-backdrop"></div>

    <div class="machine-shop-area" id="machine-shop-area">
        <div class="shop-header">
            <h3>Toko Mesin Daur Ulang</h3>
            <button class="close-button" id="close-shop-button">✖️</button>
        </div>
        <div class="shop-guide">
            <h4>Panduan Alur Produksi:</h4>
            <ul>
                <li><span class="machine-name-in-guide">Penghancur Plastik</span> (<span class="machine-icon">🗜️</span>): Mesin awal Anda. Mengolah sampah mentah menjadi <span class="output-product-in-guide">Serpihan Bersih</span>. Tingkat keberhasilan lebih rendah jika sampah kotor.</li>
                <li>Untuk kualitas <span class="highlight">Lebih Baik</span>: Beli <span class="machine-name-in-guide">Mesin Cuci Plastik</span> (<span class="machine-icon">🚿</span>). Ini mengubah sampah mentah menjadi <span class="output-product-in-guide">Plastik Bersih</span>. Kemudian, olah Plastik Bersih ini di <span class="machine-in-guide">Penghancur Plastik</span> untuk hasil <span class="output-product-in-guide">Serpihan Super Bersih</span>.</li>
                <li>Untuk kualitas <span class="highlight">Pelet Lebih Tinggi</span>: Beli <span class="machine-name-in-guide">Mesin Pengering Plastik</span> (<span class="machine-icon">☀️</span>). Ini mengeringkan Serpihan (normal atau Super Bersih) menjadi <span class="output-product-in-guide">Serpihan Kering</span> atau <span class="output-product-in-guide">Sangat Kering</span>.</li>
                <li>Olah semua jenis serpihan (<span class="output-product-in-guide">Bersih</span>, <span class="output-product-in-guide">Kering</span>, <span class="output-product-in-guide">Super Bersih</span>, <span class="output-product-in-guide">Sangat Kering</span>) di <span class="machine-name-in-guide">Pelebur & Pencetak Pelet</span> (<span class="machine-icon">🔥</span>) untuk menghasilkan berbagai tingkat kualitas <span class="output-product-in-guide">Pelet</span>.</li>
                <li>Tahap akhir (Cetak Pelet menjadi Produk Jadi):
                    <ul>
                        <li><span class="machine-name-in-guide">Mesin Cetak Produk Jadi</span> (<span class="machine-icon">🔧</span>): Untuk produk umum seperti <span class="output-product-in-guide">Benang</span>, <span class="output-product-in-guide">Pipa</span>, <span class="output-product-in-guide">Kantong</span>, <span class="output-product-in-guide">Palet</span>, dan isian. Mesin ini juga memproses pelet kualitas Premium/Optimal untuk produk yang lebih mahal.</li>
                        <li><span class="machine-name-in-guide">Pencetak Botol Baru</span> (<span class="machine-icon">🍾</span>): Spesifik untuk mencetak <span class="output-product-in-guide">Botol</span> dari Pelet PET.</li>
                        <li><span class="machine-name-in-guide">Pencetak Papan Komposit</span> (<span class="machine-icon">🪵</span>): Spesifik untuk mencetak <span class="output-product-in-guide">Papan</span> dari Pelet LDPE/PP.</li>
                    </ul>
                </li>
                <li><span class="highlight">Tips:</span> Semakin tinggi kualitas bahan awal, semakin tinggi tingkat keberhasilan dan nilai jual produk akhir! Investasi pada mesin awal akan meningkatkan keuntungan jangka panjang.</li>
            </ul>
        </div>
        <div class="shop-machines-grid" id="machine-shop-grid">
            <?php
            $allMachinesOwned = true;
            foreach (AllMachines as $machineName => $machineInfo):
                if (!in_array($machineName, $initialGameState['ownedMachines'])):
                    $allMachinesOwned = false;

                    $cost = GameConfig['MACHINE_COSTS'][$machineName] ?? null;
                    if ($cost === null || !is_array($machineInfo) || !isset($machineInfo['icon']) || !isset($machineInfo['description'])) {
                        error_log("Peringatan: Data mesin tidak lengkap untuk '" . htmlspecialchars($machineName) . "' di toko. Melewatkan mesin ini.");
                        continue;
                    }

                    $buyButtonDisabled = ($initialGameState['playerMoney'] < $cost) ? 'disabled' : '';
                    $buyButtonText = "Beli - " . number_format($cost) . " Poin";
                    if ($buyButtonDisabled) {
                        $buyButtonText .= ' (Poin tidak cukup)';
                    }
            ?>
                    <div class="shop-machine-item">
                        <span class="machine-icon"><?php echo htmlspecialchars($machineInfo['icon']); ?></span>
                        <h4><?php echo htmlspecialchars($machineName); ?></h4>
                        <p><?php echo htmlspecialchars($machineInfo['description']); ?></p>
                        <button class="buy-button" data-machine-name="<?php echo htmlspecialchars($machineName); ?>" data-cost="<?php echo htmlspecialchars($cost); ?>" <?php echo $buyButtonDisabled; ?>>
                            <?php echo htmlspecialchars($buyButtonText); ?>
                        </button>
                    </div>
            <?php
                endif;
            endforeach;
            if ($allMachinesOwned):
            ?>
                <p>Semua mesin telah Anda miliki!</p>
            <?php endif; ?>
        </div>
    </div>

    <button id="shop-button" title="Buka Toko Mesin">🛒</button>

    <div class="other-games-overlay" id="other-games-overlay">
        <h2>🎉 Selesai Membangun Pabrik Daur Ulang Anda?</h2>
        <p>Terima kasih telah bermain! Apakah Anda ingin mencoba game edukasi lain untuk memperluas wawasan Anda tentang keberlanjutan?</p>
        <div class="game-links">
            <a href="https://example.com/game1" target="_blank">🌳 Game Edukasi Lingkungan (Contoh)</a>
            <a href="https://example.com/game2" target="_blank">💧 Game Pengelolaan Air (Contoh)</a>
        </div>
        <button id="close-other-games-btn" class="close-other-games-btn">Tutup Notifikasi</button>
    </div>


    <audio id="sfx-select" src="https://www.soundjay.com/buttons/button-1.mp3" preload="auto"></audio>
    <audio id="sfx-success" src="https://www.soundjay.com/misc/success-sound-effect.mp3" preload="auto"></audio>
    <audio id="sfx-fail" src="https://www.soundjay.com/misc/fail-trombone-01.mp3" preload="auto"></audio>
    <audio id="sfx-sell" src="https://www.soundjay.com/money/cash-register-01.mp3" preload="auto"></audio>
    <audio id="sfx-next-day" src="https://www.soundjay.com/buttons/button-3.mp3" preload="auto"></audio>
    <audio id="sfx-processing" src="https://www.soundjay.com/misc/machine-start-2.mp3" preload="auto"></audio>
    <audio id="sfx-shred" src="https://www.soundjay.com/misc/shredder-1.mp3" preload="auto"></audio>


    <script>
        // Game Data & Configuration (fetched from PHP via game_data.php for consistency)
        const GameConfig = <?php echo json_encode(GameConfig); ?>;
        const WasteTypes = <?php echo json_encode(WasteTypes); ?>;
        const AllMachines = <?php echo json_encode(AllMachines); ?>;
        const ProductIcons = <?php echo json_encode(ProductIcons); ?>;

        // Helper function to get upgrade cost (retained in JS for client-side UI updates)
        function getUpgradeCost(currentLevel, upgradeCostTiers) {
            const nextLevel = currentLevel + 1;
            for (const tier of upgradeCostTiers) {
                if (nextLevel >= tier.level_min && nextLevel <= tier.level_max) {
                    return tier.cost;
                }
            }
            return Infinity;
        }

        // --- Game State (initialized from PHP) ---
        const GameState = <?php echo json_encode($initialGameState); ?>;

        // Ensure message log timestamps are Date objects
        GameState.messageLog = GameState.messageLog.map(entry => ({
            ...entry,
            timestamp: new Date(entry.timestamp)
        }));

        // Variable to hold the currently selected item for processing
        GameState.selectedItem = null;

        // --- DOM Elements ---
        const DOMElements = {
            dayDisplay: document.getElementById('day'),
            moneyDisplay: document.getElementById('money'),
            unprocessedWasteCountDisplay: document.getElementById('unprocessed-waste-count'),
            messageArea: document.getElementById('message-area'),
            messageLogContainer: document.getElementById('message-log-container'),
            wastePileContainer: document.getElementById('waste-pile'),
            inventoryListContainer: document.getElementById('inventory-list'),
            nextDayBtn: document.getElementById('next-day-btn'),
            processingMachinesGrid: document.getElementById('processing-machines-grid'),
            machineShopArea: document.getElementById('machine-shop-area'),
            machineShopGrid: document.getElementById('machine-shop-grid'),
            shopButton: document.getElementById('shop-button'),
            shopOverlayBackdrop: document.getElementById('shop-overlay-backdrop'),
            closeShopButton: document.getElementById('close-shop-button'),
            otherGamesOverlay: document.getElementById('other-games-overlay'),
            closeOtherGamesBtn: document.getElementById('close-other-games-btn'),
            sfxSelect: document.getElementById('sfx-select'),
            sfxSuccess: document.getElementById('sfx-success'),
            sfxFail: document.getElementById('sfx-fail'),
            sfxSell: document.getElementById('sfx-sell'),
            sfxNextDay: document.getElementById('sfx-next-day'),
            sfxProcessing: document.getElementById('sfx-processing'),
            sfxShred: document.getElementById('sfx-shred')
        };

        // --- Audio Manager ---
        const AudioManager = {
            playSound: function(sfxElement) {
                if (sfxElement) {
                    sfxElement.currentTime = 0;
                    sfxElement.play().catch(e => console.error("Error playing sound:", e));
                }
            }
        };

        // --- AJAX Utility ---
        const AJAX = {
            post: function(url, data, callback) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            callback(response);
                        } catch (e) {
                            console.error("Failed to parse JSON response:", xhr.responseText, e);
                            UIManager.showMessage('Terjadi kesalahan data dari server.', 'error');
                        }
                    } else if (xhr.readyState === 4) {
                        console.error("AJAX Error:", xhr.status, xhr.statusText, xhr.responseText);
                        UIManager.showMessage(`Terjadi kesalahan server: ${xhr.status} ${xhr.statusText}`, 'error');
                    }
                };
                xhr.send(JSON.stringify(data));
            },
            get: function(url, callback) {
                const xhr = new XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            callback(response);
                        } catch (e) {
                            console.error("Failed to parse JSON response:", xhr.responseText, e);
                            UIManager.showMessage('Terjadi kesalahan data dari server.', 'error');
                        }
                    } else if (xhr.readyState === 4) {
                        console.error("AJAX Error:", xhr.status, xhr.statusText, xhr.responseText);
                        UIManager.showMessage(`Terjadi kesalahan server: ${xhr.status} ${xhr.statusText}`, 'error');
                    }
                };
                xhr.send();
            }
        };


        // --- UI Manager ---
        const UIManager = {
            updateDisplay: function() {
                DOMElements.dayDisplay.textContent = GameState.currentDay;
                DOMElements.moneyDisplay.textContent = `${GameState.playerMoney.toLocaleString()} 🪙`;
                DOMElements.unprocessedWasteCountDisplay.textContent = GameState.currentWastePile.length;

                // Render Sampah Masuk
                DOMElements.wastePileContainer.innerHTML = '';
                GameState.currentWastePile.forEach((wasteType, index) => {
                    const wasteDiv = document.createElement('div');
                    wasteDiv.classList.add('waste-item');
                    wasteDiv.dataset.type = wasteType;
                    wasteDiv.dataset.index = index.toString();
                    // Menggunakan WasteTypes untuk nama dan fallback untuk ikon jika ProductIcons tidak ada
                    const wasteIcon = (typeof ProductIcons !== 'undefined' && ProductIcons[wasteType]) ? ProductIcons[wasteType] : (WasteTypes[wasteType] && WasteTypes[wasteType].icon ? WasteTypes[wasteType].icon : '❓');
                    wasteDiv.innerHTML = `<span class="waste-icon">${wasteIcon}</span>${WasteTypes[wasteType]?.name || wasteType}`;
                    wasteDiv.title = WasteTypes[wasteType]?.description || '';
                    DOMElements.wastePileContainer.appendChild(wasteDiv);
                });

                // Render Inventaris
                DOMElements.inventoryListContainer.innerHTML = '';
                let hasDisplayableItem = false;

                for (const item in GameState.inventory) {
                    if (GameState.inventory[item] > 0) {
                        hasDisplayableItem = true;
                        const productDiv = document.createElement('div');
                        productDiv.classList.add('product-item');
                        productDiv.dataset.type = item;
                        productDiv.dataset.quantity = GameState.inventory[item];

                        let itemSellValue = 0;
                        let canProcessFurther = false;
                        let isSellableProduct = false;

                        for (const machineName in AllMachines) {
                            const machineInfo = AllMachines[machineName];
                            // Perbaikan: Tambahkan pengecekan tipe untuk machineInfo
                            if (machineInfo && typeof machineInfo === 'object' && machineInfo.recipes && typeof machineInfo.recipes === 'object' && machineInfo.recipes[item]) {
                                if (typeof machineInfo.recipes[item] === 'object' && machineInfo.recipes[item] !== null) {
                                    canProcessFurther = true;
                                }
                            }
                        }

                        for (const machineName in AllMachines) {
                            const machineInfo = AllMachines[machineName];
                            // Perbaikan: Tambahkan pengecekan tipe untuk machineInfo
                            if (machineInfo && typeof machineInfo === 'object' && machineInfo.recipes && typeof machineInfo.recipes === 'object') {
                                for (const recipeInput in machineInfo.recipes) {
                                    const recipe = machineInfo.recipes[recipeInput];
                                    // Perbaikan: Tambahkan pengecekan tipe untuk recipe
                                    if (typeof recipe === 'object' && recipe !== null && recipe.output && recipe.value !== undefined) { // Check for undefined value
                                        if (recipe.output === item && recipe.value > 0) {
                                            isSellableProduct = true;
                                            itemSellValue = recipe.value;
                                            break;
                                        }
                                    }
                                }
                            }
                            if (isSellableProduct) break;
                        }

                        // Perbaikan: Pastikan ProductIcons ada dan memiliki kunci $item
                        const productIcon = (typeof ProductIcons !== 'undefined' && ProductIcons[item]) ? ProductIcons[item] : '❓'; // This is the JS line reflecting the corrected PHP part
                        let iconHtml = `<span class="product-icon">${productIcon}</span>`;

                        productDiv.innerHTML = `<span>${iconHtml} ${item} (${GameState.inventory[item]} unit)</span>`;
                        productDiv.title = `Jumlah: ${GameState.inventory[item]} unit`;

                        if (canProcessFurther && !isSellableProduct) {
                            const processAgainBtn = document.createElement('button');
                            processAgainBtn.textContent = 'Olah Lagi';
                            processAgainBtn.classList.add('process-again-btn');
                            productDiv.appendChild(processAgainBtn);
                            productDiv.title += ' | Dapat diolah lebih lanjut.';
                        }

                        if (isSellableProduct && itemSellValue > 0) {
                            const sellBtn = document.createElement('button');
                            sellBtn.textContent = `Jual (${itemSellValue.toLocaleString()} Poin)`;
                            sellBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                GameLogic.sellProduct(item);
                            });
                            productDiv.appendChild(sellBtn);
                            productDiv.title += ` | Nilai jual: ${itemSellValue} Poin`;
                        }
                        DOMElements.inventoryListContainer.appendChild(productDiv);
                    }
                }
                if (!hasDisplayableItem) {
                    DOMElements.inventoryListContainer.innerHTML = '<p>Inventaris Anda kosong atau berisi residu yang tidak bisa dijual.</p>';
                }

                // Render owned machines in processing area
                DOMElements.processingMachinesGrid.innerHTML = '';
                GameState.ownedMachines.forEach(machineName => {
                    const machineInfo = AllMachines[machineName];
                    if (!machineInfo || typeof machineInfo !== 'object') return; // Added type check

                    const machineDiv = document.createElement('div');
                    machineDiv.classList.add('machine');
                    // Mengganti karakter khusus dalam ID untuk mencegah masalah DOM
                    machineDiv.id = `machine-${machineName.replace(/ /g, '-').replace(/&/g, 'AND').replace(/[^\w-]/g, '')}`;

                    machineDiv.innerHTML = `
                        <h4>${machineName}</h4>
                        <p>${machineInfo.description}</p>
                        <div class="machine-slot" data-machine-name="${machineName}"></div>
                    `;
                    DOMElements.processingMachinesGrid.appendChild(machineDiv);

                    const machineSlotElement = machineDiv.querySelector('.machine-slot');
                    if (machineInfo.icon) {
                        const iconSpan = document.createElement('span');
                        iconSpan.classList.add('machine-icon');
                        iconSpan.textContent = machineInfo.icon;
                        machineSlotElement.appendChild(iconSpan);
                    } else {
                        const span = document.createElement('span');
                        span.textContent = 'Slot Mesin';
                        machineSlotElement.appendChild(span);
                    }

                    // Display machine level
                    const machineLevel = GameState.machineLevels[machineName] || 1;
                    const levelDisplay = document.createElement('span');
                    levelDisplay.classList.add('level-display');
                    levelDisplay.textContent = `Level: ${machineLevel}`;
                    machineSlotElement.appendChild(levelDisplay);


                    // Update tooltip
                    let currentMoneyPerProcess = GameConfig.MONEY_PER_PROCESS * machineLevel;
                    let tooltipText = `${machineInfo.description}\nPendapatan per penggunaan: ${currentMoneyPerProcess} Poin\n\nResep:\n`;
                    let hasRecipes = false;
                    if (machineInfo.recipes && typeof machineInfo.recipes === 'object') { // Added type check
                        for (const inputType in machineInfo.recipes) {
                            const recipe = machineInfo.recipes[inputType];
                            // Perbaikan: Pengecekan tipe untuk recipe
                            if (typeof recipe === 'object' && recipe !== null && recipe.output && recipe.success_rate !== undefined) {
                                const inputName = WasteTypes[inputType] ? WasteTypes[inputType].name : inputType;
                                tooltipText += `  • ${inputName} ➡️ ${recipe.output} (${recipe.success_rate}% berhasil)\n`;
                                hasRecipes = true;
                            }
                        }
                    }
                    if (!hasRecipes) {
                        tooltipText += '  (Tidak ada resep)';
                    }
                    machineSlotElement.title = tooltipText.trim();

                    // Add Upgrade Button
                    const upgradeButton = document.createElement('button');
                    upgradeButton.classList.add('upgrade-button');
                    upgradeButton.dataset.machineName = machineName;
                    upgradeButton.dataset.currentLevel = machineLevel;

                    if (machineLevel < GameConfig['UPGRADE_LEVEL_CAP']) {
                        const nextLevelCost = getUpgradeCost(machineLevel, GameConfig['UPGRADE_COST_TIERS']);
                        upgradeButton.textContent = `Upgrade Lvl ${machineLevel + 1} (${nextLevelCost.toLocaleString()} Poin)`;
                        upgradeButton.dataset.upgradeCost = nextLevelCost;
                        if (GameState.playerMoney < nextLevelCost) {
                            upgradeButton.disabled = true;
                            upgradeButton.textContent += ' (Poin tidak cukup)';
                        }
                        upgradeButton.addEventListener('click', GameLogic.upgradeMachine);
                    } else {
                        upgradeButton.textContent = 'Level Maks!';
                        upgradeButton.disabled = true;
                    }
                    machineDiv.appendChild(upgradeButton);
                });


                // Render machines available for purchase in the shop area
                DOMElements.machineShopGrid.innerHTML = '';
                let allMachinesOwned = true;
                for (const machineName in AllMachines) {
                    if (!GameState.ownedMachines.includes(machineName)) {
                        allMachinesOwned = false;
                        const machineInfo = AllMachines[machineName];
                        const cost = GameConfig.MACHINE_COSTS[machineName];

                        // Perbaikan: Tambahkan pengecekan tipe untuk machineInfo
                        if (!machineInfo || typeof machineInfo !== 'object' || cost === undefined || cost === null) {
                            console.warn(`Mesin '${machineName}' tidak memiliki definisi lengkap atau biaya.`);
                            continue;
                        }

                        const shopItemDiv = document.createElement('div');
                        shopItemDiv.classList.add('shop-machine-item');

                        if (machineInfo.icon) {
                            const iconSpan = document.createElement('span');
                            iconSpan.classList.add('machine-icon');
                            iconSpan.textContent = machineInfo.icon;
                            shopItemDiv.appendChild(iconSpan);
                        }

                        const machineTitle = document.createElement('h4');
                        machineTitle.textContent = machineName;
                        shopItemDiv.appendChild(machineTitle);

                        const machineDesc = document.createElement('p');
                        machineDesc.textContent = machineInfo.description;
                        shopItemDiv.appendChild(machineDesc);

                        const buyButton = document.createElement('button');
                        buyButton.textContent = `Beli - ${cost.toLocaleString()} Poin`;
                        buyButton.dataset.machineName = machineName;
                        buyButton.dataset.cost = cost;

                        if (GameState.playerMoney < cost) {
                            buyButton.disabled = true;
                            buyButton.textContent += ' (Poin tidak cukup)';
                        }
                        buyButton.addEventListener('click', GameLogic.buyMachine);
                        shopItemDiv.appendChild(buyButton);

                        DOMElements.machineShopGrid.appendChild(shopItemDiv);
                    }
                }
                if (allMachinesOwned) {
                    DOMElements.machineShopGrid.innerHTML = '<p>Semua mesin telah Anda miliki!</p>';
                }

                this.updateMessageLog();
            },

            showMessage: function(msg, type = 'info') {
                const messageArea = DOMElements.messageArea;
                messageArea.innerHTML = msg;
                messageArea.className = 'message-area';
                if (type === 'error') {
                    messageArea.classList.add('error-message');
                } else if (type === 'success') {
                    messageArea.classList.add('success-message');
                }
                clearTimeout(messageArea.timer);
                messageArea.timer = setTimeout(() => {
                    messageArea.textContent = '';
                    messageArea.className = 'message-area';
                }, GameConfig.MESSAGE_DURATION);

                this.addMessageToLog(msg, type);
            },

            addMessageToLog: function(msg, type) {
                GameState.messageLog.unshift({ message: msg, type: type, timestamp: new Date() });
                if (GameState.messageLog.length > GameConfig.MESSAGE_LOG_MAX_ENTRIES) {
                    GameState.messageLog.pop();
                }
                this.updateMessageLog();
            },

            updateMessageLog: function() {
                DOMElements.messageLogContainer.innerHTML = '';
                GameState.messageLog.forEach(logEntry => {
                    const p = document.createElement('p');
                    const time = new Date(logEntry.timestamp).toLocaleTimeString();
                    p.innerHTML = `[${time}] ${logEntry.message}`; // Menggunakan innerHTML karena pesan bisa mengandung <b>
                    if (logEntry.type === 'error') {
                        p.classList.add('log-error');
                    } else if (logEntry.type === 'success') {
                        p.classList.add('log-success');
                    }
                    DOMElements.messageLogContainer.appendChild(p);
                });
            },

            clearSelectedItem: function() {
                if (GameState.selectedItem && GameState.selectedItem.element) {
                    GameState.selectedItem.element.classList.remove('selected');
                }
                GameState.selectedItem = null;
                this.resetMachineHighlights();
            },

            highlightDroppableMachines: function(itemType) {
                const currentMachineSlots = DOMElements.processingMachinesGrid.querySelectorAll('.machine-slot');
                currentMachineSlots.forEach(slot => {
                    const machineName = slot.dataset.machineName;
                    const machineInfo = AllMachines[machineName];
                    // Perbaikan: Pengecekan tipe untuk machineInfo dan recipes
                    if (machineInfo && typeof machineInfo === 'object' && machineInfo.recipes && typeof machineInfo.recipes === 'object' && machineInfo.recipes[itemType] && typeof machineInfo.recipes[itemType] === 'object' && machineInfo.recipes[itemType] !== null) {
                        slot.classList.add('active-target');
                        slot.removeEventListener('click', GameLogic.handleMachineClick); // Prevent duplicate listeners
                        slot.addEventListener('click', GameLogic.handleMachineClick);
                    } else {
                        slot.classList.remove('active-target');
                        slot.removeEventListener('click', GameLogic.handleMachineClick);
                    }
                });
            },

            resetMachineHighlights: function() {
                const currentMachineSlots = DOMElements.processingMachinesGrid.querySelectorAll('.machine-slot');
                currentMachineSlots.forEach(slot => {
                    slot.classList.remove('active-target');
                    slot.classList.remove('hovered');
                    slot.removeEventListener('click', GameLogic.handleMachineClick);
                });
            },

            showGameOver: function() {
                const overlay = DOMElements.gameoverOverlay || document.createElement('div');
                overlay.id = 'game-over-overlay';
                overlay.classList.add('game-over-overlay', 'active');
                overlay.innerHTML = `
                    <h2>GAME OVER</h2>
                    <p>Poin Anda telah habis. Pabrik daur ulang Anda bangkrut!</p>
                    <button id="restart-game-btn">Mulai Ulang Permainan</button>
                `;
                document.body.appendChild(overlay);
                DOMElements.gameoverOverlay = overlay;

                document.getElementById('restart-game-btn').addEventListener('click', () => {
                    GameLogic.resetGame();
                    overlay.classList.remove('active');
                });
            },

            showConfirmation: function(message, onConfirm) {
                const overlay = DOMElements.confirmOverlay || document.createElement('div');
                overlay.id = 'confirm-overlay';
                overlay.classList.add('confirm-overlay', 'active');
                overlay.innerHTML = `
                    <p>${message}</p>
                    <div class="button-group">
                        <button id="confirm-yes">Ya</button>
                        <button id="confirm-no" class="cancel">Tidak</button>
                    </div>
                `;
                document.body.appendChild(overlay);
                DOMElements.confirmOverlay = overlay;

                const confirmYesBtn = document.getElementById('confirm-yes');
                const confirmNoBtn = document.getElementById('confirm-no');

                // Hapus event listener lama sebelum menambahkan yang baru untuk mencegah duplikasi
                if (GameLogic.confirmYesHandler) {
                    confirmYesBtn.removeEventListener('click', GameLogic.confirmYesHandler);
                }
                if (GameLogic.confirmNoHandler) {
                    confirmNoBtn.removeEventListener('click', GameLogic.confirmNoHandler);
                }

                GameLogic.confirmYesHandler = () => {
                    overlay.classList.remove('active');
                    onConfirm(true);
                };
                GameLogic.confirmNoHandler = () => {
                    overlay.classList.remove('active');
                    onConfirm(false);
                };

                confirmYesBtn.addEventListener('click', GameLogic.confirmYesHandler);
                confirmNoBtn.addEventListener('click', GameLogic.confirmNoHandler);
            },

            toggleMachineShop: function() {
                const shopArea = DOMElements.machineShopArea;
                const backdrop = DOMElements.shopOverlayBackdrop;
                const shopButton = DOMElements.shopButton;

                if (shopArea.classList.contains('active')) {
                    shopArea.classList.remove('active');
                    backdrop.classList.remove('active');
                    shopButton.textContent = '🛒';
                    shopButton.title = 'Buka Toko Mesin';
                    UIManager.showMessage('Toko Mesin ditutup.', 'info');
                } else {
                    shopArea.classList.add('active');
                    backdrop.classList.add('active');
                    shopButton.textContent = '✖️';
                    shopButton.title = 'Tutup Toko Mesin';
                    UIManager.showMessage('Toko Mesin dibuka.', 'info');
                    AJAX.get('api.php?action=getGameState', (response) => {
                        if (response.success) {
                            // Only update relevant game state parts that shop might affect
                            GameState.playerMoney = response.gameState.playerMoney;
                            GameState.ownedMachines = response.gameState.ownedMachines;
                            GameState.machineLevels = response.gameState.machineLevels;
                            UIManager.updateDisplay();
                            // BARU: Selalu update total_points_updated di localStorage setelah refresh dari server
                            localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                        } else {
                             UIManager.showMessage('Gagal memuat data toko mesin dari server.', 'error');
                        }
                    });
                }
            },

            showOtherGamesNotification: function() {
                if (GameState.playerMoney >= GameConfig.SHOW_OTHER_GAMES_THRESHOLD && GameState.currentDay > 5) {
                    const overlay = DOMElements.otherGamesOverlay;
                    overlay.classList.add('active');
                }
            },

            hideOtherGamesNotification: function() {
                const overlay = DOMElements.otherGamesOverlay;
                overlay.classList.remove('active');
            }
        };

        // --- Game Logic ---
        const GameLogic = {
            init: function() {
                if (GameState.isGameOver) {
                    UIManager.showGameOver();
                }
                UIManager.updateDisplay();
                GameLogic.setupEventListeners();

                // BARU: Initial localStorage update on game load to sync points if needed
                localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));

                // --- Perbaikan Pesan Awal saat Inisialisasi ---
                if (GameState.rewardGivenThisLoad) {
                     UIManager.showMessage('Reward Login Harian! Anda mendapatkan <b>+50 Poin</b>.', 'success');
                     setTimeout(() => {
                        UIManager.showMessage('Selamat datang kembali! Lanjutkan mengolah sampah Anda.', 'info');
                     }, GameConfig.MESSAGE_DURATION + 500); // Tampilkan pesan info setelah pesan reward
                } else if (GameState.currentDay === 1 && GameState.ownedMachines.length === 1 && GameState.ownedMachines[0] === 'Penghancur Plastik' && GameState.currentWastePile.length > 0) {
                     // Ini hanya akan muncul jika kondisi awal game pertama kali dimainkan terpenuhi
                     UIManager.showMessage('Selamat datang! Anda telah memiliki mesin <b>Penghancur Plastik</b> pertama Anda. Mari mulai mendaur ulang!.', 'success');
                     setTimeout(() => {
                         UIManager.showMessage('Untuk meningkatkan kualitas daur ulang Anda, pertimbangkan untuk membeli <b>Mesin Cuci Plastik</b> atau <b>Mesin Pengering Plastik</b> dari Toko Mesin (ikon keranjang belanja di kanan bawah).', 'info');
                     }, GameConfig.MESSAGE_DURATION + 500);
                } else if (GameState.currentWastePile.length > 0) {
                    UIManager.showMessage('Game dimuat dari penyimpanan. Lanjutkan mengolah sampah Anda!', 'info');
                } else {
                    UIManager.showMessage('Selamat datang kembali! Semua sampah sudah diolah. Tekan "Akhiri Hari" untuk menerima pengiriman baru.', 'info');
                }
                // --- AKHIR Perbaikan Pesan Awal ---

                UIManager.showOtherGamesNotification();
            },

            setupEventListeners: function() {
                DOMElements.nextDayBtn.addEventListener('click', () => {
                    GameLogic.confirmEndDay();
                });
                DOMElements.shopButton.addEventListener('click', UIManager.toggleMachineShop);
                DOMElements.shopOverlayBackdrop.addEventListener('click', UIManager.toggleMachineShop);
                DOMElements.closeShopButton.addEventListener('click', UIManager.toggleMachineShop);
                DOMElements.closeOtherGamesBtn.addEventListener('click', UIManager.hideOtherGamesNotification);

                // --- Delegated Event Listeners for Dynamic Elements (Click Only) ---
                // Waste Pile (ONLY click for selection)
                DOMElements.wastePileContainer.addEventListener('click', (e) => {
                    const clickedWasteItem = e.target.closest('.waste-item');
                    if (clickedWasteItem) {
                        GameLogic.selectItem(clickedWasteItem);
                    } else {
                        UIManager.clearSelectedItem();
                    }
                });

                // Inventory List (ONLY click for selection or sell/process again)
                DOMElements.inventoryListContainer.addEventListener('click', (e) => {
                    const productItem = e.target.closest('.product-item');
                    if (productItem) {
                        const sellButton = e.target.closest('.sell-button');
                        const processAgainButton = e.target.closest('.process-again-btn');

                        if (sellButton) {
                            GameLogic.sellProduct(productItem.dataset.type);
                        } else if (processAgainButton) {
                            GameLogic.selectItem(productItem);
                        } else {
                            GameLogic.selectItem(productItem);
                        }
                    } else {
                        UIManager.clearSelectedItem();
                    }
                });

                // Processing Machine Grid (ONLY click for processing item)
                DOMElements.processingMachinesGrid.addEventListener('click', (e) => {
                    const machineSlot = e.target.closest('.machine-slot');
                    if (machineSlot) {
                        GameLogic.handleMachineClick(e);
                    } else {
                        UIManager.clearSelectedItem();
                    }
                });

                // Global click listener to clear selection if clicking outside game elements
                document.addEventListener('click', (e) => {
                    const isClickOutsideGame = !e.target.closest('.game-container') &&
                                               !e.target.closest('.machine-shop-area') &&
                                               !e.target.closest('#shop-button') &&
                                               !e.target.closest('.game-over-overlay') &&
                                               !e.target.closest('.confirm-overlay') &&
                                               !e.target.closest('.other-games-overlay');

                    if (isClickOutsideGame && GameState.selectedItem) {
                        UIManager.clearSelectedItem();
                    }
                });
            },

            // Unified selectItem function for both waste and inventory
            selectItem: function(element) {
                if (GameState.isGameOver) return;

                UIManager.clearSelectedItem();
                AudioManager.playSound(DOMElements.sfxSelect);

                element.classList.add('selected');
                GameState.selectedItem = {
                    type: element.dataset.type,
                    source: element.classList.contains('waste-item') ? 'waste-pile' : 'inventory',
                    index: element.classList.contains('waste-item') ? parseInt(element.dataset.index) : null,
                    element: element
                };

                UIManager.highlightDroppableMachines(GameState.selectedItem.type);
                // Menggunakan WasteTypes untuk nama dan fallback jika tidak ada
                UIManager.showMessage(`Anda memilih <b>${WasteTypes[GameState.selectedItem.type]?.name || GameState.selectedItem.type}</b>. Sekarang, <b>klik mesin</b> yang cocok untuk memprosesnya.`, 'info');
            },

            handleMachineClick: function(event) {
                if (GameState.isGameOver) return;
                if (!GameState.selectedItem) {
                    UIManager.showMessage('Pilih sampah atau produk yang ingin diolah terlebih dahulu!', 'error');
                    return;
                }

                const targetMachineName = event.currentTarget.dataset.machineName;
                const itemType = GameState.selectedItem.type;

                const machineInfo = AllMachines[targetMachineName];
                // Perbaikan: Tambahkan pengecekan tipe untuk machineInfo dan recipes
                if (!machineInfo || typeof machineInfo !== 'object' || !machineInfo.recipes || typeof machineInfo.recipes !== 'object' || !machineInfo.recipes[itemType] || typeof machineInfo.recipes[itemType] === 'object' && machineInfo.recipes[itemType] === null) {
                    UIManager.showMessage(`Mesin <b>${targetMachineName}</b> tidak bisa memproses <b>${WasteTypes[itemType]?.name || itemType}</b>. Pilih mesin yang sesuai.`, 'error');
                    UIManager.clearSelectedItem();
                    return;
                }

                GameLogic.sendProcessRequest(itemType, targetMachineName, GameState.selectedItem.source, GameState.selectedItem.index, GameState.selectedItem.element);
            },

            sendProcessRequest: function(itemType, machineName, source, index, elementToAnimate) {
                const targetMachineSlotElement = document.querySelector(`.machine-slot[data-machine-name="${machineName}"]`);

                if (targetMachineSlotElement) {
                    GameLogic.animateWasteToMachine(elementToAnimate, targetMachineSlotElement, itemType, machineName);
                } else {
                    console.error("Target machine slot element not found for animation.");
                    UIManager.clearSelectedItem();
                    UIManager.updateDisplay();
                }

                AJAX.post('api.php', {
                    action: 'processItem',
                    itemType: itemType,
                    machineName: machineName,
                    source: source,
                    index: index
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, response.messageType);
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.updateDisplay();
                        GameLogic.checkGameOver();
                        // BARU: Selalu update total_points_updated di localStorage setelah transaksi
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                    } else {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, 'error');
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.updateDisplay();
                    }
                    UIManager.clearSelectedItem();
                });
            },

            sellProduct: function(productName) {
                if (GameState.isGameOver) return;
                UIManager.clearSelectedItem();

                AJAX.post('api.php', {
                    action: 'sellProduct',
                    productName: productName
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, response.messageType);
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.updateDisplay();
                        GameLogic.checkGameOver();
                        // BARU: Selalu update total_points_updated di localStorage setelah transaksi
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                    } else {
                        UIManager.showMessage(response.message, 'error');
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                    }
                });
            },

            buyMachine: function(event) {
                if (GameState.isGameOver) return;
                const machineName = event.currentTarget.dataset.machineName;
                const cost = parseInt(event.currentTarget.dataset.cost);

                AJAX.post('api.php', {
                    action: 'buyMachine',
                    machineName: machineName,
                    cost: cost
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, response.messageType);
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.updateDisplay();
                        // BARU: Selalu update total_points_updated di localStorage setelah transaksi
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                    } else {
                        UIManager.showMessage(response.message, 'error');
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                    }
                });
            },

            upgradeMachine: function(event) {
                if (GameState.isGameOver) return;
                const machineName = event.currentTarget.dataset.machineName;
                const currentLevel = parseInt(event.currentTarget.dataset.currentLevel);
                const upgradeCost = parseInt(event.currentTarget.dataset.upgradeCost);

                AJAX.post('api.php', {
                    action: 'upgradeMachine',
                    machineName: machineName,
                    currentLevel: currentLevel,
                    upgradeCost: upgradeCost
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, response.messageType);
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.updateDisplay();
                        // BARU: Selalu update total_points_updated di localStorage setelah transaksi
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                    } else {
                        UIManager.showMessage(response.message, 'error');
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                    }
                });
            },

            confirmEndDay: function() {
                if (GameState.isGameOver) return;

                let message = `Apakah Anda yakin ingin mengakhiri <b>Hari ${GameState.currentDay}</b>?`;
                if (GameState.currentWastePile.length > 0) {
                    const disposalCost = GameConfig.DISPOSAL_COST_PER_UNIT * GameState.currentWastePile.length; // Hitung total biaya pembuangan
                    message += `<br>Anda memiliki <b>${GameState.currentWastePile.length}</b> unit sampah belum diolah. Ini akan dikenakan biaya <b>${disposalCost.toLocaleString()} Poin</b>.`;
                } else {
                    message += `<br>Semua sampah sudah diolah. Tidak ada biaya pembuangan. Bagus!`;
                }
                message += `<br>Biaya pemeliharaan pabrik harian sebesar <b>${GameConfig.DAILY_MAINTENANCE_COST.toLocaleString()} Poin</b> juga akan dikenakan.`;

                UIManager.showConfirmation(message, (confirmed) => {
                    if (confirmed) {
                        GameLogic.endDay();
                    } else {
                        UIManager.showMessage('Hari tidak berakhir. Lanjutkan mengolah sampah dan maksimalkan Poin Anda!', 'info');
                    }
                });
            },

            endDay: function() {
                if (GameState.isGameOver) return;

                AJAX.post('api.php', {
                    action: 'endDay'
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);
                        UIManager.showMessage(response.message, response.messageType);
                        if (response.sound) AudioManager.playSound(DOMElements[response.sound]);
                        UIManager.clearSelectedItem();
                        UIManager.updateDisplay();
                        GameLogic.checkGameOver();
                        UIManager.showOtherGamesNotification();
                        // BARU: Selalu update total_points_updated di localStorage setelah transaksi
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                         // Update game history in DB
                        GameLogic.updateGameHistory('Simulasi Daur Ulang Plastik', response.pointsChange, response.message, 'end_day');
                    } else {
                        UIManager.showMessage(response.message, 'error');
                    }
                });
            },

            checkGameOver: function() {
                if (GameState.playerMoney <= GameConfig.GAME_OVER_MONEY_THRESHOLD && !GameState.isGameOver) {
                    GameState.isGameOver = true;
                    UIManager.showGameOver();
                    // Log game over to history
                    GameLogic.updateGameHistory('Simulasi Daur Ulang Plastik', GameState.playerMoney, 'Game Over: Poin Habis', 'game_over');
                }
            },

            resetGame: function() {
                AJAX.post('api.php', {
                    action: 'resetGame'
                }, (response) => {
                    if (response.success) {
                        Object.assign(GameState, response.gameState);

                        UIManager.showMessage(response.message, response.messageType);
                        UIManager.clearSelectedItem();
                        UIManager.hideOtherGamesNotification();
                        UIManager.updateDisplay();
                        // BARU: Tambahkan pesan awal yang sama seperti inisialisasi pertama kali
                        UIManager.showMessage('Selamat datang! Anda telah memiliki mesin <b>Penghancur Plastik</b> pertama Anda. Mari mulai mendaur ulang!.', 'success');
                        setTimeout(() => {
                            UIManager.showMessage('Untuk meningkatkan kualitas daur ulang Anda, pertimbangkan untuk membeli <b>Mesin Cuci Plastik</b> atau <b>Mesin Pengering Plastik</b> dari Toko Mesin (ikon keranjang belanja di kanan bawah).', 'info');
                        }, GameConfig.MESSAGE_DURATION + 500);
                        // BARU: Selalu update total_points_updated di localStorage setelah reset
                        localStorage.setItem('total_points_updated', JSON.stringify(GameState.playerMoney));
                        // Log game reset to history
                        GameLogic.updateGameHistory('Simulasi Daur Ulang Plastik', GameState.playerMoney, 'Game Reset', 'reset');
                    } else {
                        UIManager.showMessage(response.message, 'error');
                    }
                });
            },

            // NEW: Function to update game history
            updateGameHistory: function(gameName, pointsChange, description, type) {
                AJAX.post('api.php', {
                    action: 'updateGameHistory',
                    gameName: gameName,
                    pointsChange: pointsChange,
                    description: description,
                    type: type
                }, (response) => {
                    if (response.success) {
                        console.log("Game history updated successfully:", response.message);
                    } else {
                        console.error("Failed to update game history:", response.message);
                    }
                });
            },

            animateWasteToMachine: function(originalElement, targetMachineSlot, itemType, machineName) {
                // Perbaikan: Gunakan ProductIcons untuk ikon sampah yang dianimasikan
                const wasteIcon = (typeof ProductIcons !== 'undefined' && ProductIcons[itemType]) ? ProductIcons[itemType] : '❓';

                const originalRect = originalElement.getBoundingClientRect();
                const targetRect = targetMachineSlot.getBoundingClientRect();
                const gameAreaRect = DOMElements.processingMachinesGrid.closest('.game-area').getBoundingClientRect();

                const animatedWaste = document.createElement('div');
                animatedWaste.classList.add('animated-waste');
                animatedWaste.textContent = wasteIcon;

                animatedWaste.style.left = (originalRect.left - gameAreaRect.left) + 'px';
                animatedWaste.style.top = (originalRect.top - gameAreaRect.top) + 'px';
                animatedWaste.style.width = originalRect.width + 'px';
                animatedWaste.style.height = originalRect.height + 'px';
                animatedWaste.style.lineHeight = originalRect.height + 'px';
                animatedWaste.style.textAlign = 'center';

                DOMElements.processingMachinesGrid.closest('.game-area').appendChild(animatedWaste);

                // Memaksa reflow untuk memastikan posisi awal diterapkan sebelum transisi
                animatedWaste.offsetWidth;

                const targetX = (targetRect.left + targetRect.width / 2) - gameAreaRect.left;
                const targetY = (targetRect.top + targetRect.height / 2) - gameAreaRect.top;

                animatedWaste.style.transition = `left ${GameConfig.TRAVEL_ANIMATION_DURATION}ms ease-in-out, top ${GameConfig.TRAVEL_ANIMATION_DURATION}ms ease-in-out, width ${GameConfig.TRAVEL_ANIMATION_DURATION}ms ease-in-out, height ${GameConfig.TRAVEL_ANIMATION_DURATION}ms ease-in-out, border-radius ${GameConfig.TRAVEL_ANIMATION_DURATION}ms ease-in-out`;
                animatedWaste.style.left = targetX + 'px';
                animatedWaste.style.top = targetY + 'px';
                animatedWaste.style.width = '40px';
                animatedWaste.style.height = '40px';
                animatedWaste.style.borderRadius = '50%';
                animatedWaste.style.transform = 'translate(-50%, -50%)';

                setTimeout(() => {
                    animatedWaste.classList.add('shredding');
                    // Mengganti sfx-shred dengan sfx-processing jika ada animasi lain yang cocok
                    AudioManager.playSound(DOMElements.sfxShred || DOMElements.sfxProcessing);

                    // Sanitasi nama mesin untuk kelas CSS
                    const sanitizedMachineName = machineName.replace(/ /g, '-').replace(/&/g, 'AND').replace(/[^\w-]/g, '');
                    targetMachineSlot.classList.add('is-processing', sanitizedMachineName);

                    setTimeout(() => {
                        animatedWaste.remove();
                        targetMachineSlot.classList.remove('is-processing', sanitizedMachineName);
                    }, GameConfig.SHRED_ANIMATION_DURATION);

                }, GameConfig.TRAVEL_ANIMATION_DURATION);
            }
        };

        // --- Inisialisasi Game saat window dimuat ---
        window.onload = () => {
            GameLogic.init();
        };
    </script>
</body>
</html>