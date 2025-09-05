<?php
// api.php
header('Content-Type: application/json');
session_start(); // Pastikan sesi dimulai untuk mengakses $_SESSION
require_once 'config.php'; // Membutuhkan config.php untuk CURRENT_USER_ID
require_once 'db_connection.php';
require_once 'game_data.php'; // This now provides getUpgradeCost()

// Aktifkan pelaporan error MySQLi untuk debugging yang lebih baik
// Ini akan membuat MySQLi melempar pengecualian (exceptions) pada error database,
// yang akan memberikan pesan error yang lebih detail.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$response = ['success' => false, 'message' => 'Invalid action.', 'messageType' => 'error'];

// Pastikan koneksi database berhasil sebelum melanjutkan
if ($conn->connect_error) {
    error_log("API: Koneksi database gagal di api.php: " . $conn->connect_error);
    $response = ['success' => false, 'message' => 'Kesalahan server: Tidak dapat terhubung ke database.', 'messageType' => 'error'];
    echo json_encode($response);
    exit();
}


// Helper function to fetch current game state from DB
function getGameStateFromDB($conn, $user_id) {
    $gameState = [
        'playerMoney' => 0,
        'currentDay' => 1,
        'inventory' => [],
        'currentWastePile' => [],
        'isGameOver' => false,
        'messageLog' => [],
        'ownedMachines' => [],
        'machineLevels' => []
    ];

    // Game State
    $stmt = $conn->prepare("SELECT player_money, current_day, is_game_over FROM game_state WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $dbData = $result->fetch_assoc();
    $stmt->close();
    if ($dbData) {
        $gameState['playerMoney'] = $dbData['player_money'];
        $gameState['currentDay'] = $dbData['current_day'];
        $gameState['isGameOver'] = (bool)$dbData['is_game_over'];
    }

    // Inventory
    $stmt = $conn->prepare("SELECT item_name, quantity FROM user_inventory WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gameState['inventory'][$row['item_name']] = $row['quantity'];
    }
    $stmt->close();

    // Owned Machines and Levels
    $stmt = $conn->prepare("SELECT machine_name, machine_level FROM user_machines WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gameState['ownedMachines'][] = $row['machine_name'];
        $gameState['machineLevels'][$row['machine_name']] = $row['machine_level'];
    }
    $stmt->close();

    // Current Waste Pile
    $stmt = $conn->prepare("SELECT waste_type FROM daily_waste WHERE user_id = ? ORDER BY waste_order ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gameState['currentWastePile'][] = $row['waste_type'];
    }
    $stmt->close();

    // Message Log (ambil semua yang ada di DB, client akan membatasi tampilan)
    $stmt = $conn->prepare("SELECT log_message, log_type, timestamp FROM message_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT " . GameConfig['MESSAGE_LOG_MAX_ENTRIES']);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gameState['messageLog'][] = [
            'message' => $row['log_message'],
            'type' => $row['log_type'],
            'timestamp' => $row['timestamp'] // Keep as string for JS Date parsing
        ];
    }
    $stmt->close();

    return $gameState;
}

// Helper function to save game state to DB
function saveGameStateToDB($conn, $user_id, &$gameState) { // Pass $gameState by reference
    // Save main game state
    $stmt = $conn->prepare("UPDATE game_state SET player_money = ?, current_day = ?, is_game_over = ? WHERE user_id = ?");
    if ($stmt === false) {
        error_log("API: Failed to prepare game_state update: " . $conn->error);
        return;
    }
    $is_game_over_int = (int)$gameState['isGameOver'];
    $stmt->bind_param("iiii", $gameState['playerMoney'], $gameState['currentDay'], $is_game_over_int, $user_id);
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute game_state update: " . $stmt->error);
    }
    $stmt->close();

    // Save inventory
    // Clear existing inventory and re-insert (simpler for this case, for large inventories: update/insert/delete as needed)
    $stmt = $conn->prepare("DELETE FROM user_inventory WHERE user_id = ?");
    if ($stmt === false) {
        error_log("API: Failed to prepare user_inventory delete: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute user_inventory delete: " . $stmt->error);
    }
    $stmt->close();

    if (!empty($gameState['inventory'])) {
        $insertValues = [];
        $insertTypes = "";
        $insertParams = [];
        foreach ($gameState['inventory'] as $item_name => $quantity) {
            if ($quantity > 0) {
                $insertValues[] = "(?, ?, ?)";
                $insertTypes .= "isi";
                $insertParams[] = $user_id;
                $insertParams[] = $item_name;
                $insertParams[] = $quantity;
            }
        }
        if (!empty($insertValues)) {
            $sql = "INSERT INTO user_inventory (user_id, item_name, quantity) VALUES " . implode(",", $insertValues);
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("API: Failed to prepare user_inventory insert: " . $conn->error);
                return;
            }

            // Create an array of references for bind_param
            $refs = [];
            foreach ($insertParams as $key => $value) {
                $refs[$key] = &$insertParams[$key];
            }

            // Use call_user_func_array to bind_param with dynamic parameters
            call_user_func_array([$stmt, 'bind_param'], array_merge([$insertTypes], $refs));

            if ($stmt->execute() === false) {
                error_log("API: Failed to execute user_inventory insert: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Save machines
    $stmt = $conn->prepare("DELETE FROM user_machines WHERE user_id = ?");
    if ($stmt === false) {
        error_log("API: Failed to prepare user_machines delete: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute user_machines delete: " . $stmt->error);
    }
    $stmt->close();

    if (!empty($gameState['ownedMachines'])) {
        $insertValues = [];
        $insertTypes = "";
        $insertParams = [];
        foreach ($gameState['ownedMachines'] as $machine_name) {
            $level = $gameState['machineLevels'][$machine_name] ?? 1;
            $insertValues[] = "(?, ?, ?)";
            $insertTypes .= "isi";
            $insertParams[] = $user_id;
            $insertParams[] = $machine_name;
            $insertParams[] = $level;
        }
        if (!empty($insertValues)) {
            $sql = "INSERT INTO user_machines (user_id, machine_name, machine_level) VALUES " . implode(",", $insertValues);
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("API: Failed to prepare user_machines insert: " . $conn->error);
                return;
            }

            // Create an array of references for bind_param
            $refs = [];
            foreach ($insertParams as $key => $value) {
                $refs[$key] = &$insertParams[$key];
            }

            // Use call_user_func_array to bind_param with dynamic parameters
            call_user_func_array([$stmt, 'bind_param'], array_merge([$insertTypes], $refs));

            if ($stmt->execute() === false) {
                error_log("API: Failed to execute user_machines insert: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Save daily waste
    $stmt = $conn->prepare("DELETE FROM daily_waste WHERE user_id = ?");
    if ($stmt === false) {
        error_log("API: Failed to prepare daily_waste delete: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute daily_waste delete: " . $stmt->error);
    }
    $stmt->close();

    if (!empty($gameState['currentWastePile'])) {
        $insertValues = [];
        $insertTypes = "";
        $insertParams = [];
        foreach ($gameState['currentWastePile'] as $order => $waste_type) {
            $insertValues[] = "(?, ?, ?)";
            $insertTypes .= "isi";
            $insertParams[] = $user_id;
            $insertParams[] = $waste_type;
            $insertParams[] = $order;
        }
        if (!empty($insertValues)) {
            $sql = "INSERT INTO daily_waste (user_id, waste_type, waste_order) VALUES " . implode(",", $insertValues);
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("API: Failed to prepare daily_waste insert: " . $conn->error);
                return;
            }

            // Create an array of references for bind_param
            $refs = [];
            foreach ($insertParams as $key => $value) {
                $refs[$key] = &$insertParams[$key];
            }

            // Use call_user_func_array to bind_param with dynamic parameters
            call_user_func_array([$stmt, 'bind_param'], array_merge([$insertTypes], $refs));

            if ($stmt->execute() === false) {
                error_log("API: Failed to execute daily_waste insert: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Save message log (only add new ones, limit total in DB to avoid bloat)
    // Untuk menghindari memasukkan duplikat, kami menandai pesan baru di objek gameState
    foreach ($gameState['messageLog'] as &$log_entry) { // Gunakan referensi untuk memodifikasi array asli
        // Pastikan timestamp ada dan berupa string yang dapat diproses oleh DB
        // Periksa apakah timestamp adalah objek DateTime (misalnya dari getGameStateFromDB) dan konversi jika perlu
        if (isset($log_entry['timestamp']) && ($log_entry['timestamp'] instanceof DateTime)) {
            $log_entry['timestamp'] = $log_entry['timestamp']->format('Y-m-d H:i:s');
        } else if (!isset($log_entry['timestamp'])) {
            // Jika tidak ada timestamp, setel yang baru
            $log_entry['timestamp'] = date('Y-m-d H:i:s');
        }

        if (!isset($log_entry['is_new']) || $log_entry['is_new'] === true) { // Periksa apakah itu pesan baru
            $stmt = $conn->prepare("INSERT INTO message_log (user_id, log_message, log_type, timestamp) VALUES (?, ?, ?, ?)");
            if ($stmt === false) {
                error_log("API: Failed to prepare message_log insert: " . $conn->error);
                continue; // Skip this log entry but continue with others
            }
            $stmt->bind_param("isss", $user_id, $log_entry['message'], $log_entry['type'], $log_entry['timestamp']);
            if ($stmt->execute() === false) {
                error_log("API: Failed to execute message_log insert: " . $stmt->error);
            }
            $stmt->close();
            $log_entry['is_new'] = false; // Tandai sebagai sudah disimpan
        }
    }
    unset($log_entry); // Hentikan referensi

    // Bersihkan entri log lama untuk mempertahankan batas
    $stmt = $conn->prepare("DELETE FROM message_log WHERE user_id = ? AND timestamp < (SELECT timestamp FROM (SELECT timestamp FROM message_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT 1 OFFSET ?) as t)");
    if ($stmt === false) {
        error_log("API: Failed to prepare message_log cleanup: " . $conn->error);
        return;
    }
    $offset = GameConfig['MESSAGE_LOG_MAX_ENTRIES'];
    $stmt->bind_param("iii", $user_id, $user_id, $offset); // Corrected: third 'i' for offset
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute message_log cleanup: " . $stmt->error);
    }
    $stmt->close();
}

// Helper function to add message to log
function addMessageToLog($conn, $user_id, &$gameState, $message, $type = 'info') {
    $logEntry = [
        'message' => $message,
        'type' => $type,
        'timestamp' => date('Y-m-d H:i:s'),
        'is_new' => true // Tandai sebagai baru untuk tujuan penyimpanan
    ];
    array_unshift($gameState['messageLog'], $logEntry);
    if (count($gameState['messageLog']) > GameConfig['MESSAGE_LOG_MAX_ENTRIES']) {
        array_pop($gameState['messageLog']);
    }
}

/**
 * Updates the user's total_points in the 'users' table and session.
 * @param mysqli $conn The database connection object.
 * @param int $user_id The ID of the current user.
 * @param int $newTotalPoints The new total points value to set.
 */
function updateUsersTotalPoints($conn, $user_id, $newTotalPoints) {
    // Update the 'users' table
    $stmt = $conn->prepare("UPDATE users SET total_points = ? WHERE id = ?");
    if ($stmt === false) {
        error_log("API: Failed to prepare users total_points update: " . $conn->error);
        return;
    }
    $stmt->bind_param("ii", $newTotalPoints, $user_id);
    if ($stmt->execute() === false) {
        error_log("API: Failed to execute users total_points update: " . $stmt->error);
    }
    $stmt->close();

    // Update the session variable for immediate reflection
    $_SESSION['total_points'] = $newTotalPoints;
}

$user_id = CURRENT_USER_ID;
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if (isset($data['action'])) {
    $action = $data['action'];
    $gameState = getGameStateFromDB($conn, $user_id);

    if ($gameState['isGameOver'] && $action !== 'resetGame') {
        $response = ['success' => false, 'message' => 'Game Over! Please restart to continue.', 'messageType' => 'error'];
        echo json_encode($response);
        $conn->close();
        exit();
    }

    switch ($action) {
        case 'getGameState':
            $response = ['success' => true, 'gameState' => $gameState];
            break;

        case 'processItem':
            $itemType = $data['itemType'];
            $machineName = $data['machineName'];
            $source = $data['source'];
            $index = $data['index'] ?? null;

            // Validasi input tambahan: Pastikan itemType, machineName valid
            if (!isset(WasteTypes[$itemType]) && !array_key_exists($itemType, ProductIcons)) { // Cek apakah waste type atau product
                addMessageToLog($conn, $user_id, $gameState, "Input item tidak valid: " . htmlspecialchars($itemType), 'error');
                $response = ['success' => false, 'message' => "Input item tidak valid.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }
            if (!isset(AllMachines[$machineName])) {
                addMessageToLog($conn, $user_id, $gameState, "Mesin tidak valid: " . htmlspecialchars($machineName), 'error');
                $response = ['success' => false, 'message' => "Mesin tidak valid.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }
            if (!isset(AllMachines[$machineName]['recipes'][$itemType])) {
                addMessageToLog($conn, $user_id, $gameState, "Mesin " . htmlspecialchars($machineName) . " tidak memiliki resep untuk " . htmlspecialchars($itemType) . ".", 'error');
                $response = ['success' => false, 'message' => "Mesin <b>" . $machineName . "</b> tidak bisa memproses <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b>. Pilih mesin yang sesuai.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            // Check if item exists in inventory/waste pile
            if ($source === 'waste-pile') {
                // Validasi: pastikan index ada dan tipe item cocok
                if (!isset($gameState['currentWastePile'][$index]) || $gameState['currentWastePile'][$index] !== $itemType) {
                    addMessageToLog($conn, $user_id, $gameState, "Sampah tidak ditemukan atau indeks tidak cocok. Item: " . htmlspecialchars($itemType) . ", Index: " . htmlspecialchars($index), 'error');
                    $response = ['success' => false, 'message' => "Sampah tidak ditemukan atau indeks tidak cocok.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                    break;
                }
                // Remove from waste pile
                array_splice($gameState['currentWastePile'], $index, 1);
            } elseif ($source === 'inventory') {
                if (!isset($gameState['inventory'][$itemType]) || $gameState['inventory'][$itemType] <= 0) {
                    addMessageToLog($conn, $user_id, $gameState, "Item inventaris tidak ditemukan atau kuantitas nol. Item: " . htmlspecialchars($itemType), 'error');
                    $response = ['success' => false, 'message' => "Item inventaris tidak ditemukan atau kuantitas nol.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                    break;
                }
                $gameState['inventory'][$itemType]--;
                if ($gameState['inventory'][$itemType] === 0) {
                    unset($gameState['inventory'][$itemType]);
                }
            } else {
                addMessageToLog($conn, $user_id, $gameState, "Sumber item tidak valid: " . htmlspecialchars($source), 'error');
                $response = ['success' => false, 'message' => "Sumber item tidak valid.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            $recipe = AllMachines[$machineName]['recipes'][$itemType];
            $machineLevel = $gameState['machineLevels'][$machineName] ?? 1;
            $pointsEarned = GameConfig['MONEY_PER_PROCESS'] * $machineLevel;
            $isSuccess = (mt_rand(0, 100) / 100) <= ($recipe['success_rate'] / 100);

            $gameState['playerMoney'] += $pointsEarned;

            if ($isSuccess) {
                $output = $recipe['output'];
                $gameState['inventory'][$output] = ($gameState['inventory'][$output] ?? 0) + 1;
                if ($recipe['value'] > 0) {
                    addMessageToLog($conn, $user_id, $gameState, "✅ Berhasil! Mengolah <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> menjadi <b>" . $output . "</b>. Produk siap dijual untuk Poin!", 'success');
                    $response = ['success' => true, 'message' => "✅ Berhasil! Mengolah <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> menjadi <b>" . $output . "</b>. Produk siap dijual untuk Poin!", 'messageType' => 'success', 'sound' => 'sfxSuccess'];
                } else {
                    addMessageToLog($conn, $user_id, $gameState, "✅ Berhasil! Mengolah <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> menjadi <b>" . $output . "</b>. Lanjutkan pengolahan!", 'success');
                    $response = ['success' => true, 'message' => "✅ Berhasil! Mengolah <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> menjadi <b>" . $output . "</b>. Lanjutkan pengolahan!", 'messageType' => 'success', 'sound' => 'sfxSuccess'];
                }
            } else {
                if ($recipe['value'] > 0) {
                    $gameState['playerMoney'] -= GameConfig['DISPOSAL_COST_PER_UNIT'];
                    addMessageToLog($conn, $user_id, $gameState, "💔 Gagal! Pengolahan <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> tidak sempurna. Anda kehilangan <b>" . GameConfig['DISPOSAL_COST_PER_UNIT'] . " Poin</b> karena pembuangan.", 'error');
                    $response = ['success' => true, 'message' => "💔 Gagal! Pengolahan <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> tidak sempurna. Anda kehilangan <b>" . GameConfig['DISPOSAL_COST_PER_UNIT'] . " Poin</b> karena pembuangan.", 'messageType' => 'error', 'sound' => 'sfxFail']; // Note: success=true here as it's a valid game outcome
                } else {
                    addMessageToLog($conn, $user_id, $gameState, "💔 Gagal! Pengolahan <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> tidak sempurna. Material hilang.", 'error');
                    $response = ['success' => true, 'message' => "💔 Gagal! Pengolahan <b>" . ($WasteTypes[$itemType]['name'] ?? $itemType) . "</b> tidak sempurna. Material hilang.", 'messageType' => 'error', 'sound' => 'sfxFail'];
                }
            }
            $response['gameState'] = $gameState;
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        case 'sellProduct':
            $productName = $data['productName'];

            if (!isset($gameState['inventory'][$productName]) || $gameState['inventory'][$productName] <= 0) {
                addMessageToLog($conn, $user_id, $gameState, "Anda tidak memiliki <b>" . htmlspecialchars($productName) . "</b> untuk dijual.", 'error');
                $response = ['success' => false, 'message' => "Anda tidak memiliki <b>" . htmlspecialchars($productName) . "</b> untuk dijual.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            $productValue = 0;
            $foundSellValue = false;
            foreach (AllMachines as $machine) {
                foreach ($machine['recipes'] as $recipe) {
                    if (is_array($recipe) && isset($recipe['output']) && isset($recipe['value'])) { // Ensure $recipe is an array before accessing keys
                        if ($recipe['output'] === $productName && $recipe['value'] > 0) {
                            $productValue = $recipe['value'];
                            $foundSellValue = true;
                            break 2; // Break out of both inner loops
                        }
                    }
                }
            }

            if ($productValue > 0 && $foundSellValue) {
                $gameState['playerMoney'] += $productValue;
                $gameState['inventory'][$productName]--;
                if ($gameState['inventory'][$productName] === 0) {
                    unset($gameState['inventory'][$productName]);
                }
                addMessageToLog($conn, $user_id, $gameState, "💰 Berhasil menjual 1 unit <b>" . htmlspecialchars($productName) . "</b> seharga <b>" . $productValue . " Poin</b>!", 'success');
                $response = ['success' => true, 'message' => "💰 Berhasil menjual 1 unit <b>" . htmlspecialchars($productName) . "</b> seharga <b>" . $productValue . " Poin</b>!", 'messageType' => 'success', 'sound' => 'sfxSell', 'gameState' => $gameState];
            } else {
                addMessageToLog($conn, $user_id, $gameState, "🚫 Produk <b>" . htmlspecialchars($productName) . "</b> tidak memiliki nilai jual atau tidak ditemukan resep jualnya.", 'error');
                $response = ['success' => false, 'message' => "🚫 Produk <b>" . htmlspecialchars($productName) . "</b> tidak memiliki nilai jual atau tidak ditemukan resep jualnya.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
            }
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        case 'buyMachine':
            $machineName = $data['machineName'];
            $cost = $data['cost'];

            if (!isset(AllMachines[$machineName]) || !isset(GameConfig['MACHINE_COSTS'][$machineName]) || GameConfig['MACHINE_COSTS'][$machineName] !== $cost) {
                addMessageToLog($conn, $user_id, $gameState, "Mesin tidak valid atau biaya pembelian tidak cocok: " . htmlspecialchars($machineName) . " - " . htmlspecialchars($cost) . " Poin", 'error');
                $response = ['success' => false, 'message' => "Mesin tidak valid atau biaya pembelian tidak cocok.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            if ($gameState['playerMoney'] < $cost) {
                addMessageToLog($conn, $user_id, $gameState, "Poin tidak cukup untuk membeli <b>" . htmlspecialchars($machineName) . "</b>! Membutuhkan <b>" . $cost . " Poin</b>.", 'error');
                $response = ['success' => false, 'message' => "Poin tidak cukup untuk membeli <b>" . htmlspecialchars($machineName) . "</b>! Membutuhkan <b>" . $cost . " Poin</b>.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            if (in_array($machineName, $gameState['ownedMachines'])) {
                addMessageToLog($conn, $user_id, $gameState, "<b>" . htmlspecialchars($machineName) . "</b> sudah Anda miliki!", 'info');
                $response = ['success' => false, 'message' => "<b>" . htmlspecialchars($machineName) . "</b> sudah Anda miliki!", 'messageType' => 'info', 'gameState' => $gameState];
                break;
            }

            $gameState['playerMoney'] -= $cost;
            $gameState['ownedMachines'][] = $machineName;
            $gameState['machineLevels'][$machineName] = 1; // Set initial level to 1
            addMessageToLog($conn, $user_id, $gameState, "Anda berhasil membeli <b>" . htmlspecialchars($machineName) . "</b> seharga <b>" . $cost . " Poin</b>! Selamat berinvestasi untuk lingkungan!", 'success');
            $response = ['success' => true, 'message' => "Anda berhasil membeli <b>" . htmlspecialchars($machineName) . "</b> seharga <b>" . $cost . " Poin</b>! Selamat berinvestasi untuk lingkungan!", 'messageType' => 'success', 'sound' => 'sfxSuccess', 'gameState' => $gameState];
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        case 'upgradeMachine':
            $machineName = $data['machineName'];
            $currentLevel = (int)$data['currentLevel']; // Pastikan integer
            $upgradeCost = (int)$data['upgradeCost'];   // Pastikan integer

            if (!in_array($machineName, $gameState['ownedMachines'])) {
                addMessageToLog($conn, $user_id, $gameState, "Anda tidak memiliki mesin <b>" . htmlspecialchars($machineName) . "</b> untuk di-upgrade.", 'error');
                $response = ['success' => false, 'message' => "Anda tidak memiliki mesin <b>" . htmlspecialchars($machineName) . "</b> untuk di-upgrade.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            // Validasi level untuk mencegah manipulasi client-side
            if (($gameState['machineLevels'][$machineName] ?? 1) !== $currentLevel) {
                 addMessageToLog($conn, $user_id, $gameState, "Level mesin tidak cocok untuk upgrade: " . htmlspecialchars($machineName) . " (Actual: " . ($gameState['machineLevels'][$machineName] ?? 1) . ", Client: " . $currentLevel . ")", 'error');
                 $response = ['success' => false, 'message' => "Level mesin tidak cocok untuk upgrade. Coba muat ulang halaman.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                 break;
            }

            // Use the getUpgradeCost from game_data.php
            $expectedUpgradeCost = getUpgradeCost($currentLevel, GameConfig['UPGRADE_COST_TIERS']);
            if ($expectedUpgradeCost !== $upgradeCost) {
                addMessageToLog($conn, $user_id, $gameState, "Biaya upgrade tidak valid terdeteksi: " . htmlspecialchars($machineName) . " (Expected: " . $expectedUpgradeCost . ", Client: " . $upgradeCost . ")", 'error');
                $response = ['success' => false, 'message' => "Biaya upgrade tidak valid. Coba muat ulang halaman.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            if ($currentLevel >= GameConfig['UPGRADE_LEVEL_CAP']) {
                addMessageToLog($conn, $user_id, $gameState, "<b>" . htmlspecialchars($machineName) . "</b> sudah mencapai level maksimum (" . GameConfig['UPGRADE_LEVEL_CAP'] . ")!", 'info');
                $response = ['success' => false, 'message' => "<b>" . htmlspecialchars($machineName) . "</b> sudah mencapai level maksimum (" . GameConfig['UPGRADE_LEVEL_CAP'] . ")!", 'messageType' => 'info', 'gameState' => $gameState];
                break;
            }

            if ($gameState['playerMoney'] < $upgradeCost) {
                addMessageToLog($conn, $user_id, $gameState, "Poin tidak cukup untuk meng-upgrade <b>" . htmlspecialchars($machineName) . "</b>! Membutuhkan <b>" . $upgradeCost . " Poin</b>.", 'error');
                $response = ['success' => false, 'message' => "Poin tidak cukup untuk meng-upgrade <b>" . htmlspecialchars($machineName) . "</b>! Membutuhkan <b>" . $upgradeCost . " Poin</b>.", 'messageType' => 'error', 'sound' => 'sfxFail', 'gameState' => $gameState];
                break;
            }

            $gameState['playerMoney'] -= $upgradeCost;
            $gameState['machineLevels'][$machineName]++;
            addMessageToLog($conn, $user_id, $gameState, "Anda berhasil meng-upgrade <b>" . htmlspecialchars($machineName) . "</b> ke Level <b>" . $gameState['machineLevels'][$machineName] . "</b> seharga <b>" . $upgradeCost . " Poin</b>! Mesin Anda kini lebih efisien!", 'success');
            $response = ['success' => true, 'message' => "Anda berhasil meng-upgrade <b>" . htmlspecialchars($machineName) . "</b> ke Level <b>" . $gameState['machineLevels'][$machineName] . "</b> seharga <b>" . $upgradeCost . " Poin</b>! Mesin Anda kini lebih efisien!", 'messageType' => 'success', 'sound' => 'sfxSuccess', 'gameState' => $gameState];
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        case 'endDay':
            $soundToPlay = 'sfxNextDay';
            $response_message = "";
            $messageType = 'info';

            if (!empty($gameState['currentWastePile'])) {
                $disposalCost = count($gameState['currentWastePile']) * GameConfig['DISPOSAL_COST_PER_UNIT'];
                $gameState['playerMoney'] -= $disposalCost;
                addMessageToLog($conn, $user_id, $gameState, "Anda melewatkan <b>" . count($gameState['currentWastePile']) . "</b> unit sampah! Biaya pembuangan: <b>" . $disposalCost . " Poin</b>.", 'error');
                $response_message .= "Anda melewatkan <b>" . count($gameState['currentWastePile']) . "</b> unit sampah! Biaya pembuangan: <b>" . $disposalCost . " Poin</b>. ";
                $messageType = 'error';
                $soundToPlay = 'sfxFail'; // Play fail sound if waste is skipped
            } else {
                addMessageToLog($conn, $user_id, $gameState, "Semua sampah sudah diurus. Tidak ada biaya pembuangan. Kerja bagus!", 'success');
                $response_message .= "Semua sampah sudah diurus. Tidak ada biaya pembuangan. Kerja bagus! ";
                $messageType = 'success';
            }

            $gameState['playerMoney'] -= GameConfig['DAILY_MAINTENANCE_COST'];
            addMessageToLog($conn, $user_id, $gameState, "Biaya pemeliharaan pabrik harian: <b>" . GameConfig['DAILY_MAINTENANCE_COST'] . " Poin</b>.", 'info');
            $response_message .= "Biaya pemeliharaan pabrik harian: <b>" . GameConfig['DAILY_MAINTENANCE_COST'] . " Poin</b>.";


            $gameState['currentDay']++;

            // Generate new daily waste (fixed to 7 units as per GameConfig)
            $numWaste = mt_rand(GameConfig['DAILY_WASTE_COUNT_MIN'], GameConfig['DAILY_WASTE_COUNT_MAX']);
            $wasteTypes = array_keys(WasteTypes);
            for ($i = 0; $i < $numWaste; $i++) {
                $randomType = $wasteTypes[array_rand($wasteTypes)];
                $gameState['currentWastePile'][] = $randomType;
            }
            addMessageToLog($conn, $user_id, $gameState, "Hari baru! <b>" . $numWaste . "</b> unit sampah baru telah tiba di pabrik Anda. Mari kita mulai mengolah!", 'info');


            $response = ['success' => true, 'message' => $response_message, 'messageType' => $messageType, 'sound' => $soundToPlay, 'gameState' => $gameState];
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        case 'resetGame':
            // Clear all user data and re-initialize
            $stmt = $conn->prepare("DELETE FROM user_inventory WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM user_machines WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM daily_waste WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM message_log WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            // Reset main game state
            $gameState['playerMoney'] = GameConfig['STARTING_MONEY'];
            $gameState['currentDay'] = 1;
            $gameState['inventory'] = [];
            $gameState['currentWastePile'] = [];
            $gameState['isGameOver'] = false;
            $gameState['messageLog'] = [];
            $gameState['ownedMachines'] = ['Penghancur Plastik'];
            $gameState['machineLevels'] = ['Penghancur Plastik' => 1];

            addMessageToLog($conn, $user_id, $gameState, 'Permainan diulang. Memulai game baru.', 'info');
            addMessageToLog($conn, $user_id, $gameState, 'Anda telah memiliki mesin <b>Penghancur Plastik</b> pertama!', 'success');
            addMessageToLog($conn, $user_id, $gameState, 'Untuk meningkatkan kualitas daur ulang Anda, pertimbangkan untuk membeli <b>Mesin Cuci Plastik</b> atau <b>Mesin Pengering Plastik</b> dari Toko Mesin (ikon keranjang belanja di kanan bawah).', 'info');

            // Generate initial waste for the new game (fixed to 7 units as per GameConfig)
            $numWaste = mt_rand(GameConfig['DAILY_WASTE_COUNT_MIN'], GameConfig['DAILY_WASTE_COUNT_MAX']);
            $wasteTypes = array_keys(WasteTypes);
            for ($i = 0; $i < $numWaste; $i++) {
                $randomType = $wasteTypes[array_rand($wasteTypes)];
                $gameState['currentWastePile'][] = $randomType;
            }
            addMessageToLog($conn, $user_id, $gameState, "Hari baru! <b>" . $numWaste . "</b> unit sampah baru telah tiba di pabrik Anda. Mari kita mulai mengolah!", 'info');

            $response = ['success' => true, 'message' => 'Permainan diulang. Memulai game baru.', 'messageType' => 'info', 'gameState' => $gameState];
            updateUsersTotalPoints($conn, $user_id, $gameState['playerMoney']); // Update users table and session
            break;

        default:
            // Handled by initial $response value
            break;
    }

    // Always save the updated game state after any action
    saveGameStateToDB($conn, $user_id, $gameState);

    // Re-check game over state after saving (especially for processItem/endDay that can trigger it)
    if ($gameState['playerMoney'] <= GameConfig['GAME_OVER_MONEY_THRESHOLD'] && !$gameState['isGameOver']) {
        $gameState['isGameOver'] = true;
        addMessageToLog($conn, $user_id, $gameState, "GAME OVER! Poin Anda telah habis. Pabrik daur ulang Anda bangkrut!", 'error');
        saveGameStateToDB($conn, $user_id, $gameState); // Save game over state immediately
        $response['gameState']['isGameOver'] = true; // Update client's state immediately
        $response['message'] = "GAME OVER! Poin Anda telah habis. Pabrik daur ulang Anda bangkrut!";
        $response['messageType'] = 'error';
        $response['sound'] = 'sfxFail';
    }

}

echo json_encode($response);
$conn->close();
?>