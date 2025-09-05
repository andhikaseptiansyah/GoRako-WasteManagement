<?php
// koneksi.php (atau inlined di sini seperti yang Anda berikan)
$host = "localhost";
$username = "root"; // Ganti dengan username database Anda
$password = "";     // Ganti dengan password database Anda
$database = "GoRako"; // Pastikan nama database sama dengan yang Anda buat di atas

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Start session to access user_id
session_start();

// Helper function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to save quiz result and award points
function saveQuizResult($quizId, $score, $totalQuestions) {
    global $conn;

    if (!is_logged_in()) {
        error_log("Attempt to save quiz result without login.");
        return false; // Or handle as unauthorized
    }
    $userId = $_SESSION['user_id'];

    // --- Calculate Points Earned from Quiz Score ---
    $pointsEarned = 0;
    if ($score >= 80) { // Example: 10 points for scoring 80% or higher
        $pointsEarned = 10;
    } else if ($score >= 60) { // Example: 5 points for scoring 60% or higher
        $pointsEarned = 5;
    }

    // Save quiz result into quiz_results table, including points_earned
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

    // Update user's total_points in the users table
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

    // Record the transaction in points_history (optional but recommended)
    $description = "Selesaikan Kuis #" . $quizId . " dengan skor " . $score . "%";
    $stmtPointsHistory = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, NOW())");
    if (!$stmtPointsHistory) {
        error_log("Failed to prepare points_history statement: " . $conn->error);
        return false;
    }
    $stmtPointsHistory->bind_param("isi", $userId, $description, $pointsEarned);
    if (!$stmtPointsHistory->execute()) {
        error_log("Error saving points history: " . $stmtPointsHistory->error);
        $stmtPointsHistory->close();
        return false;
    }
    $stmtPointsHistory->close();

    return true; // All operations successful
}

// Fungsi untuk menyimpan hasil scan sampah (Sekarang hanya menyimpan, klasifikasi dilakukan di tempat lain)
function saveScanResult($userId, $predictedClass, $probability) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO scan_results (user_id, predicted_class, probability, timestamp) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("ssd", $userId, $predictedClass, $probability); // s: string, s: string, d: double (float)
    if ($stmt->execute()) {
        return true;
    } else {
        error_log("Error saving scan result: " . $stmt->error);
        return false;
    }
}

// NEW FUNCTION: Classify image using OpenAI
function classifyImageWithOpenAI($base64ImageData) {
    // Pastikan Anda mendapatkan API Key OpenAI dengan benar dan AMANKAN!
    // JANGAN PERNAH HARDCODE API KEY DI CLIENT-SIDE JAVASCRIPT!
    // Untuk pengembangan, bisa di sini. Untuk produksi, pertimbangkan variabel lingkungan atau sistem config.
    $openaiApiKey = 'sk-proj-YOUR_OPENAI_API_KEY'; // GANTI DENGAN KUNCI API OpenAI Anda yang sebenarnya!
    $model = 'gpt-4o'; // Atau 'gpt-4-vision-preview'

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey,
    ];

    // Prompt yang jelas dan instruktif untuk OpenAI
    $promptText = 'Apa jenis sampah pada gambar ini? Klasifikasikan hanya sebagai satu dari kategori berikut: "Plastik", "Kertas", "Organik", "Logam", "Kaca", atau "Tidak Dikenali". Berikan juga alasan singkat untuk klasifikasi Anda. Format respons Anda secara ketat sebagai objek JSON seperti ini: {"classification": "KATEGORI_SAMPAH", "reason": "ALASAN_SINGKAT"}.';


    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $promptText,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/jpeg;base64,' . $base64ImageData,
                            'detail' => 'low' // Bisa 'high' untuk detail lebih tinggi, tapi lebih mahal dan lambat
                        ],
                    ],
                ],
            ],
        ],
        'max_tokens' => 300, // Batasi panjang respons untuk menghindari biaya berlebihan dan respons yang tidak relevan
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("cURL Error: " . $error);
        return ['success' => false, 'error' => 'Gagal terhubung ke API OpenAI: ' . $error];
    }

    if ($httpCode !== 200) {
        error_log("OpenAI API Error (HTTP " . $httpCode . "): " . $response);
        return ['success' => false, 'error' => 'OpenAI API mengembalikan kesalahan HTTP ' . $httpCode . ': ' . $response];
    }

    $responseData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Decode Error: " . json_last_error_msg() . " Response: " . $response);
        return ['success' => false, 'error' => 'Gagal mengurai respons JSON dari OpenAI.'];
    }

    if (!isset($responseData['choices'][0]['message']['content'])) {
        error_log("Unexpected OpenAI response structure: " . print_r($responseData, true));
        return ['success' => false, 'error' => 'Struktur respons OpenAI tidak terduga.'];
    }

    $content = trim($responseData['choices'][0]['message']['content']);

    // Coba parse konten sebagai JSON
    $parsedContent = json_decode($content, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($parsedContent['classification'])) {
        // Jika berhasil diurai sebagai JSON dan memiliki kunci 'classification'
        $classification = $parsedContent['classification'];
        $reason = isset($parsedContent['reason']) ? $parsedContent['reason'] : 'Tidak ada alasan spesifik diberikan.';

        // Lakukan pembersihan atau normalisasi kategori jika diperlukan
        $classification = ucfirst(strtolower($classification)); // Misal: "plastik" -> "Plastik"
        $validCategories = ["Plastik", "Kertas", "Organik", "Logam", "Kaca"];
        if (!in_array($classification, $validCategories)) {
            $classification = "Tidak Dikenali"; // Default jika kategori tidak valid
        }

        // OpenAI tidak memberikan "probabilitas" numerik seperti Teachable Machine.
        // Anda bisa menganggap "kepercayaan" sebagai 1.0 jika klasifikasi berhasil,
        // atau mengestimasi berdasarkan respons (tapi itu kompleks).
        // Untuk tujuan ini, kita akan menggunakan nilai dummy jika berhasil diklasifikasikan.
        $probability = ($classification === "Tidak Dikenali") ? 0.0 : 0.95; // Contoh probabilitas default jika dikenali

        return [
            'success' => true,
            'predicted_class' => $classification,
            'probability' => $probability,
            'reason' => $reason
        ];
    } else {
        // Jika OpenAI tidak merespons dalam format JSON yang diharapkan,
        // coba analisis teks biasa (fallback). Ini lebih rapuh.
        $predictedClass = "Tidak Dikenali";
        $reason = "Tidak dapat mengurai respons OpenAI atau kategori tidak cocok. Respons mentah: " . $content;
        $probability = 0.0; // Probabilitas 0 karena tidak berhasil diurai atau dikenali dengan yakin

        // Coba cari kata kunci dalam respons teks
        $keywords = [
            "Plastik" => ["plastik", "botol", "kantong"],
            "Kertas" => ["kertas", "kardus", "buku"],
            "Organik" => ["organik", "makanan", "daun", "sisa"],
            "Logam" => ["logam", "kaleng", "besi"],
            "Kaca" => ["kaca", "botol kaca", "gelas"],
        ];

        foreach ($keywords as $category => $terms) {
            foreach ($terms as $term) {
                if (stripos($content, $term) !== false) {
                    $predictedClass = $category;
                    $reason = "Dikenali melalui kata kunci: " . $term;
                    $probability = 0.8; // Berikan probabilitas lebih rendah untuk deteksi kata kunci
                    break 2; // Keluar dari kedua loop
                }
            }
        }

        return [
            'success' => true, // Menganggap ini sukses sejauh API merespons
            'predicted_class' => $predictedClass,
            'probability' => $probability,
            'reason' => $reason
        ];
    }
}

// --- AJAX POST Request Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Quiz Result Submission
    if (isset($_POST['quiz_id'])) {
        if (!is_logged_in()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Anda harus login untuk menyimpan hasil kuis.']);
            exit;
        }

        $quizId = $_POST['quiz_id'];
        $score = $_POST['score'];
        $totalQuestions = $_POST['total_questions'];

        if (saveQuizResult($quizId, $score, $totalQuestions)) {
            echo json_encode(['success' => true, 'message' => 'Hasil quiz berhasil disimpan dan poin telah ditambahkan!']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Gagal menyimpan hasil quiz atau menambahkan poin.']);
        }
        exit; // Important to exit after handling AJAX request
    }

    // Handle Scan Result Submission (MODIFIED FOR OPENAI INTEGRATION)
    if (isset($_POST['image_data_base64'])) { // Ini akan menjadi input baru dari JS
        if (!is_logged_in()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Anda harus login untuk memindai sampah.']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $base64ImageData = $_POST['image_data_base64'];

        // Panggil fungsi klasifikasi OpenAI
        $openaiResult = classifyImageWithOpenAI($base64ImageData);

        if ($openaiResult['success']) {
            $predictedClass = $openaiResult['predicted_class'];
            $probability = $openaiResult['probability'];
            $reason = $openaiResult['reason'];

            if (saveScanResult($userId, $predictedClass, $probability)) {
                $scanPoints = 0;
                // Hanya berikan poin jika kelas dikenali (bukan "Tidak Dikenali")
                if ($predictedClass !== "Tidak Dikenali") {
                    $scanPoints = 10; // Contoh: 10 poin untuk setiap scan yang berhasil dikenali
                }

                // Update user's total_points in the users table
                $stmtUpdateUser = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
                if ($stmtUpdateUser) {
                    $stmtUpdateUser->bind_param("ii", $scanPoints, $userId);
                    $stmtUpdateUser->execute();
                    $stmtUpdateUser->close();

                    // Record in points_history
                    $description = "Scan sampah: " . $predictedClass;
                    $stmtPointsHistory = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, NOW())");
                    if ($stmtPointsHistory) {
                        $stmtPointsHistory->bind_param("isi", $userId, $description, $scanPoints);
                        $stmtPointsHistory->execute();
                        $stmtPointsHistory->close();
                    } else {
                        error_log("Failed to prepare points_history statement for scan: " . $conn->error);
                    }
                } else {
                    error_log("Failed to prepare user points update statement for scan: " . $conn->error);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Hasil scan berhasil disimpan dan poin telah ditambahkan!',
                    'predicted_class' => $predictedClass, // Kirim kembali hasil prediksi ke frontend
                    'probability' => $probability,
                    'reason' => $reason,
                    'points_awarded' => $scanPoints,
                    'new_total_points' => $user_current_points + $scanPoints // Pastikan ini akurat
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Gagal menyimpan hasil scan ke database.']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $openaiResult['error']]);
        }
        exit;
    }

    // NEW: Handle Reward Exchange Request
    if (isset($_POST['action']) && $_POST['action'] === 'exchange_reward') {
        if (!is_logged_in()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Anda harus login untuk menukar hadiah.']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $rewardId = (int)$_POST['reward_id'];

        $conn->begin_transaction();
        try {
            // 1. Get reward details and check stock/points
            $stmt_reward = $conn->prepare("SELECT name, points_needed, stock FROM rewards WHERE id = ? FOR UPDATE"); // FOR UPDATE to lock row
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

            // 2. Get user's current points
            $stmt_user = $conn->prepare("SELECT total_points FROM users WHERE id = ? FOR UPDATE"); // FOR UPDATE to lock row
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

            // 3. Deduct points from user
            $new_user_points = $user['total_points'] - $reward['points_needed'];
            $stmt_update_user_points = $conn->prepare("UPDATE users SET total_points = ? WHERE id = ?");
            $stmt_update_user_points->bind_param("ii", $new_user_points, $userId);
            if (!$stmt_update_user_points->execute()) {
                throw new Exception("Gagal mengurangi poin pengguna: " . $stmt_update_user_points->error);
            }
            $stmt_update_user_points->close();

            // 4. Decrease reward stock
            $new_reward_stock = $reward['stock'] - 1;
            $stmt_update_reward_stock = $conn->prepare("UPDATE rewards SET stock = ? WHERE id = ?");
            $stmt_update_reward_stock->bind_param("ii", $new_reward_stock, $rewardId);
            if (!$stmt_update_reward_stock->execute()) {
                throw new Exception("Gagal mengurangi stok hadiah: " . $stmt_update_reward_stock->error);
            }
            $stmt_update_reward_stock->close();

            // 5. Record the transaction in orders table
            $order_status = 'Pending'; // Default status for new orders
            $stmt_insert_order = $conn->prepare("INSERT INTO orders (user_id, reward_id, order_date, status) VALUES (?, ?, NOW(), ?)");
            $stmt_insert_order->bind_param("iis", $userId, $rewardId, $order_status);
            if (!$stmt_insert_order->execute()) {
                throw new Exception("Gagal mencatat pesanan: " . $stmt_insert_order->error);
            }
            $stmt_insert_order->close();

            // 6. Record transaction in points_history (points spent)
            $history_description = "Tukar hadiah \"" . $reward['name'] . "\"";
            $stmtPointsHistory = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, NOW())");
            $points_deducted_amount = -$reward['points_needed']; // Negative value for deduction
            $stmtPointsHistory->bind_param("isi", $userId, $history_description, $points_deducted_amount);
            if (!$stmtPointsHistory->execute()) {
                error_log("Error saving points history for reward exchange: " . $stmtPointsHistory->error);
                // Don't throw exception here, as core transaction is done, but log for debugging
            }
            $stmtPointsHistory->close();

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Hadiah berhasil ditukar!',
                'new_total_points' => $new_user_points,
                'new_stock' => $new_reward_stock,
                'points_deducted' => $reward['points_needed'] // Send deducted points back
            ]);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400); // Bad request or client error
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

// --- Initial Data Fetch for Page Load ---

// Fetch initial scan data for the pie chart
$initialWasteData = [];
$checkTable = $conn->query("SHOW TABLES LIKE 'scan_results'");
if ($checkTable && $checkTable->num_rows > 0) {
    $stmt = $conn->prepare("SELECT predicted_class, COUNT(*) as count FROM scan_results GROUP BY predicted_class");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $initialWasteData[$row['predicted_class']] = (int)$row['count'];
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare statement for fetching scan results: " . $conn->error);
    }
} else {
    error_log("Table 'scan_results' does not exist. Please create it first. Using default data for chart.");
}
$initialWasteDataJson = json_encode($initialWasteData);

// Fetch quizzes from the database
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

// NEW: Fetch rewards from the database for dynamic display
$rewards_from_db = [];
$sql_rewards = "SELECT id, name, description, points_needed, stock, category, image_url FROM rewards ORDER BY name ASC";
$result_rewards = $conn->query($sql_rewards);

if ($result_rewards) {
    while ($row = $result_rewards->fetch_assoc()) {
        // Map DB fields to match frontend expectations
        $reward_item = [
            'id' => $row['id'],
            'title' => $row['name'],
            'description' => $row['description'],
            'points' => (int)$row['points_needed'],
            'stock' => (int)$row['stock'],
            'image' => $row['image_url'],
            'category' => 'other', // Default to 'other' if not matched
            'icon' => 'fas fa-gift' // Default icon
        ];

        // Map category from DB to predefined categories for filtering and icon
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
            case 'donation':
                $reward_item['category'] = 'experience'; // Or a new category 'donation'
                $reward_item['icon'] = "fas fa-hand-holding-heart";
                break;
            // 'other' category is already default
        }
        $rewards_from_db[] = $reward_item;
    }
} else {
    error_log("Error fetching rewards: " . $conn->error);
}

// Fetch user's current points (if logged in)
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

// Fetch transaction history for the logged-in user
$transaction_history_from_db = [];
if (is_logged_in()) {
    $userId = $_SESSION['user_id'];
    $stmt_history = $conn->prepare("SELECT description, points_amount, transaction_date FROM points_history WHERE user_id = ? ORDER BY transaction_date DESC");
    if ($stmt_history) {
        $stmt_history->bind_param("i", $userId);
        $stmt_history->execute();
        $result_history = $stmt_history->get_result();
        while ($row = $result_history->fetch_assoc()) {
            $type = 'bonus'; // Default type
            if (strpos($row['description'], 'Selesaikan Kuis #') === 0) {
                $type = 'quiz';
            } elseif (strpos($row['description'], 'Scan sampah:') === 0) {
                $type = 'scan';
            } elseif (strpos($row['description'], 'Tukar hadiah "') === 0) {
                 $type = 'exchange';
            }
            $transaction_history_from_db[] = [
                'type' => $type,
                'description' => $row['description'], // Keep full description
                'points' => (int)$row['points_amount'],
                'date' => $row['transaction_date']
            ];
        }
        $stmt_history->close();
    } else {
        error_log("Failed to prepare transaction history fetch statement: " . $conn->error);
    }
}

// Close database connection at the end of the script execution
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

        /* Nav Link Hover Color Added */
        .nav-link:hover {
            color: #10b981; /* Green hover color */
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
            transform: none; /* DIUBAH: Hapus transformasi translateY */
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .cta-button:active { /* Tambahkan atau sesuaikan untuk :active */
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); /* Sesuaikan bayangan jika perlu */
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
             /* Dynamic effect for hero title */
            position: relative;
            display: inline-block;
        }
        /* Hero title animation effect */
        .hero-content h1::before {
            content: attr(data-text); /* Use data-text attribute for animation */
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            color: #10b981; /* Highlight color */
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
            transform: none; /* DIUBAH: Hapus transformasi translateY */
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .btn-primary:active { /* Tambahkan atau sesuaikan untuk :active */
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); /* Sesuaikan bayangan jika perlu */
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
            transform: none; /* DIUBAH: Hapus transformasi translateY */
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary:active { /* Tambahkan atau sesuaikan untuk :active */
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); /* Sesuaikan bayangan jika perlu */
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
            content: '🌿'; /* Ikon daun sebagai representasi 3D sederhana */
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 4rem;
            z-index: 2;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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
            background-color: #FFFFFF; /* Diubah menjadi putih */
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
            transform-style: preserve-3d; /* Penting untuk efek 3D */
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
            transform-style: preserve-3d; /* Untuk ikon 3D */
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
            transform: none; /* DIUBAH: Hapus scale dan translateY */
            box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);
        }

        /* Modified: Remove scale on active for quiz buttons */
        .quiz-btn:active {
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
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

        /* Scan Section Specific Styles */
        :root {
            --primary-green: #2ECC71;
            --dark-green: #27AE60;
            --bg-color: #F8F9FA;
            --card-bg: #FFFFFF;
            --text-dark: #34495E;
            --text-light: #7F8C8D;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --green-gradient: linear-gradient(45deg, var(--primary-green), var(--dark-green));
            --green-gradient-hover: linear-gradient(45deg, var(--dark-green), var(--primary-green));
        }
        .scan-section-wrapper {
            padding: 20px;
            background-color: #FFFFFF; /* Diubah menjadi putih */
        }

        .scan-container {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            margin-top: 20px;
        }
        .column {
            flex: 1;
            min-width: 300px;
        }
        .upload-box, .how-it-works, .results-chart-container {
            background-color: var(--card-bg);
            border-radius: 20px;
            padding: 40px;
            box-shadow: var(--shadow);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        .upload-box:hover, .how-it-works:hover, .results-chart-container:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-green);
        }
        .camera-icon {
            font-size: 80px;
            color: var(--primary-green);
            margin-bottom: 30px;
            transition: var(--transition);
        }
        .upload-box:hover .camera-icon {
            transform: scale(1.05);
            color: var(--dark-green);
        }
        .upload-text {
            text-align: center;
            margin-bottom: 30px;
            color: var(--text-light);
            font-weight: 400;
        }
        .button-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            width: 100%;
        }
        .btn {
            padding: 15px 30px;
            border-radius: 50px;
            border: none;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            color: white;
            background: var(--primary-green);
        }
        .btn-upload { background: var(--primary-green); }
        .btn-camera { background: var(--dark-green); }
        .btn-submit {
            background: var(--green-gradient);
            background-size: 200% auto;
            animation: pulse-green 2s infinite;
        }
        /* Animasi loading untuk tombol */
        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .btn.loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            margin-left: 10px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes pulse-green {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .btn:hover {
            transform: none; /* DIUBAH: Hapus transformasi translateY */
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.15);
            background: var(--green-gradient-hover);
            background-size: 200% auto;
        }
        /* Modified: Remove scale on active for general buttons */
        .btn:active {
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .btn .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        /* DIHAPUS: Animas ripple effect, karena ini yang membuat "membesar" */
        /*@keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }*/
        .preview-container {
            width: 100%;
            max-height: 400px; /* Diperbesar sedikit */
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 30px;
            display: none;
            position: relative;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            background-color: #ecf0f1; /* Background untuk video/gambar */
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            min-height: 200px; /* Tambahkan tinggi minimum */
        }
        .preview-container img,
        .preview-container video {
            width: 100%;
            height: auto; /* Ubah ke auto untuk menjaga aspek rasio */
            max-height: 100%;
            object-fit: contain;
            background-color: #ecf0f1;
            object-position: center;
        }
        .close-preview {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(0, 0, 0, 0.4);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 18px;
            z-index: 5; /* Pastikan di atas gambar/video */
        }
        .close-preview:hover {
            background-color: rgba(0, 0, 0, 0.7);
            transform: rotate(90deg) scale(1.1);
        }
        .camera-options {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
            align-self: center; /* Pusatkan tombol kamera */
        }
        .camera-options button {
            padding: 8px 15px;
            border: 1px solid #ccc;
            border-radius: 20px;
            background-color: #f0f0f0;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .camera-options button.active {
            background-color: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }


        .section-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 30px;
            color: var(--text-dark);
            text-align: center;
            position: relative;
        }
        .section-title::after {
            content: '';
            display: block;
            width: 60px;
            height: 4px;
            background: var(--primary-green);
            margin: 15px auto 0;
            border-radius: 2px;
        }
        .steps {
            display: flex;
            flex-direction: column;
            gap: 25px;
            width: 100%;
        }
        .step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s ease-out;
        }
        .step.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .step-number {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--primary-green);
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.2);
        }
        .step-content {
            flex: 1;
        }
        .step-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-dark);
        }
        .step-description {
            color: var(--text-light);
            font-size: 14px;
        }
        .step-icon {
            margin-right: 10px;
            color: var(--primary-green); /* Use currentColor to inherit color from parent */
            transition: var(--transition);
        }
        .hidden {
            display: none !important;
        }
        #fileInput {
            display: none;
        }
        .chart-wrapper {
            position: relative;
            width: 100%;
            max-width: 400px;
            height: 300px;
            margin: 20px auto 0 auto;
        }
        .chart-legend {
            list-style: none;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        .chart-legend li {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 500;
        }
        .legend-color-box {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            margin-right: 8px;
            border: 1px solid rgba(0,0,0,0.08);
        }
        .scan-result-display {
            margin-top: 20px;
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-green);
            text-align: center;
            background-color: rgba(46, 204, 113, 0.08);
            padding: 10px 20px;
            border-radius: 10px;
            border: 1px solid var(--primary-green);
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        .scan-result-display.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .scan-result-display span {
            color: var(--dark-green);
        }
        /* Responsive styles for scan section */
        @media (max-width: 768px) {
            .scan-container { flex-direction: column; }
            .column { width: 100%; }
            .upload-box, .how-it-works, .results-chart-container { padding: 30px; }
            .button-container { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .section-title { font-size: 24px; }
            .chart-wrapper { height: 250px; }
        }
        /* Animation delays for steps */
        .step:nth-child(1) { transition-delay: 0.1s; }
        .step:nth-child(2) { transition-delay: 0.2s; }
        .step:nth-child(3) { transition-delay: 0.3s; }

        /* Points and Rewards Section Specific Styles */
        .points-section-wrapper {
            padding: 20px;
            background-color: #FFFFFF; /* Diubah menjadi putih */
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
            /* Animation for points update */
            transition: all 0.3s ease-out;
            display: inline-block; /* Required for transform */
        }
        .points-number.animated {
            transform: scale(1.1);
            color: yellow; /* Temporary flash color */
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
            transform-style: preserve-3d; /* Untuk efek 3D */
            cursor: pointer; /* Tambahkan cursor pointer */
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
            margin-bottom: 15px;
            line-height: 1.5;
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
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            transform: translateY(-50px); /* Initial transform for animation */
            opacity: 0; /* Initial opacity for animation */
            transition: all 0.3s ease;
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
        /* Modified: Remove scale on hover for modal confirm button */
        .modal-btn.confirm:hover {
            background: #5a9e5d;
            transform: none; /* DIUBAH: Hapus transformasi translateY */
        }
        .modal-btn.confirm:active { /* Tambahkan atau sesuaikan untuk :active */
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
        }

        .modal-btn.cancel { background: #f5f5f5; color: #666; }
        .modal-btn.cancel:hover { background: #e0e0e0; }
        .modal-btn.cancel:active { /* Tambahkan atau sesuaikan untuk :active */
            transform: none; /* DIUBAH: Pastikan tidak ada transformasi saat diklik */
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

        /* Category Card Specific Styles */
        .categories-section-wrapper { /* Tambahkan ini untuk mewarnai bagian kategori */
            padding: 20px;
            background-color: #FFFFFF; /* Diubah menjadi putih */
        }
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            margin-bottom: 50px;
        }
        .category-card {
            background: white;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease forwards;
            transform-style: preserve-3d; /* Untuk efek 3D */
            cursor: pointer; /* Tambahkan cursor pointer */
        }
        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color), var(--card-color-light));
        }
        .category-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .category-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .category-card:hover .category-icon { transform: scale(1.1); }
        .category-title {
            font-size: 1.4rem;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
        }
        .category-description {
            font-size: 0.95rem;
            color: #666;
            line-height: 1.6;
        }
        .plastik { --card-color: #87ceeb; --card-color-light: #b0e0e6; }
        .plastik .category-icon { color: #87ceeb; }
        .kertas { --card-color: #deb887; --card-color-light: #f5deb3; }
        .kertas .category-icon { color: #deb887; }
        .organik { --card-color: #90ee90; --card-color-light: #98fb98; }
        .organik .category-icon { color: #90ee90; }
        .logam { --card-color: #c0c0c0; --card-color-light: #d3d3d3; }
        .logam .category-icon { color: #c0c0c0; }
        @media (max-width: 1024px) { .categories-grid { grid-template-columns: repeat(2, 1fr); gap: 25px; } }
        @media (max-width: 768px) {
            .categories-grid { grid-template-columns: repeat(2, 1fr); gap: 20px; }
            .category-icon { font-size: 2.5rem; }
            .category-title { font-size: 1.2rem; }
        }
        @media (max-width: 480px) { .categories-grid { grid-template-columns: 1fr; gap: 20px; } }

        /* New section for Reward Redemption Explanation */
        .redemption-explanation-section {
            padding: 5rem 0;
            background-color: #f8fafc; /* Light background for contrast */
            text-align: center;
        }

        .redemption-explanation-section .container {
            max-width: 1200px; /* Increased max-width to accommodate 4 columns */
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
            grid-template-columns: repeat(4, 1fr); /* Changed to 4 columns */
            gap: 30px;
            margin-top: 3rem;
            justify-content: center; /* Center items if they don't fill the row */
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
            z-index: 1; /* Ensure content is above background effects */
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
            transform: rotateY(15deg) scale(1.1); /* Enhanced 3D rotation and scale */
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
        @media (max-width: 1200px) { /* Adjust breakpoint for 4 columns */
            .redemption-steps-grid {
                grid-template-columns: repeat(2, 1fr); /* Back to 2 columns on smaller desktop/large tablet */
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
                grid-template-columns: 1fr; /* Single column on mobile */
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

        .footer-brand, .footer-links, .footer-contact {
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
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1001; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Black w/ opacity */
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
            max-height: 80vh; /* Max height for scrollability */
            overflow-y: auto; /* Enable scrolling */
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
            color: #4CAF50; /* Green for points earned */
        }

        .history-item.minus .history-item-points {
            color: #F44336; /* Red for points spent */
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
                                        // Example logic to pick an icon based on category or default
                                        $icon_class = 'fas fa-question-circle'; // Default icon
                                        if (isset($quiz['category'])) {
                                            switch (strtolower($quiz['category'])) {
                                                case 'waste segregation':
                                                    $icon_class = 'fas fa-seedling';
                                                    break;
                                                case 'recycling':
                                                    $icon_class = 'fas fa-recycle';
                                                    break;
                                                case 'energy':
                                                    $icon_class = 'fas fa-bolt';
                                                    break;
                                                case 'environmental impact':
                                                    $icon_class = 'fas fa-globe-americas';
                                                    break;
                                                default:
                                                    $icon_class = 'fas fa-book'; // Another generic icon
                                                    break;
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

    <section class="scan-section-wrapper" id="scan-section">
        <div class="container">
            <div class="scan-container">
                <div class="column">
                    <div class="upload-box">
                        <div class="preview-container hidden" id="previewContainer">
                            <div id="processingSpinner" style="display:none; margin-bottom: 20px;">
                                <i class="fas fa-spinner fa-spin fa-3x" style="color: var(--primary-green);"></i>
                                <p style="margin-top: 10px; color: var(--text-light);">Memproses gambar...</p>
                            </div>
                            <img id="previewImage" class="preview-image hidden" src="#" alt="Pratinjau Sampah" loading="lazy">
                            <button class="close-preview" id="closePreview" aria-label="Tutup Pratinjau">&times;</button>
                        </div>

                        <i class="fas fa-camera camera-icon" id="initialCameraIcon"></i>
                        <p class="upload-text" id="initialUploadText">Unggah gambar atau gunakan kamera untuk memindai sampah</p>
                        <div class="button-container" id="initialButtonContainer">
                            <button class="btn btn-upload" id="uploadBtn" aria-label="Unggah Gambar Sampah">
                                <i class="fas fa-upload"></i> Unggah Gambar
                            </button>
                            <button class="btn btn-camera" id="cameraBtn" aria-label="Gunakan Kamera untuk Memindai">
                                <i class="fas fa-camera"></i> Gunakan Kamera
                            </button>
                        </div>
                        <div class="camera-options hidden" id="cameraOptions">
                            <button id="frontCameraBtn" class="active" aria-label="Gunakan Kamera Depan">Depan</button>
                            <button id="backCameraBtn" aria-label="Gunakan Kamera Belakang">Belakang</button>
                        </div>
                        <input type="file" id="fileInput" accept="image/*">

                        <p id="scanLoading" class="scan-result-display hidden" style="color: #007BFF;">Memuat Model AI...</p>
                        <p id="scanResult" class="scan-result-display hidden">Hasil Scan: <span id="scannedWasteType"></span></p>
                        <button class="btn btn-submit hidden" id="submitScanBtn" style="margin-top: 20px;" aria-label="Kirim Data Sampah dan Dapatkan Poin">
                            <i class="fas fa-paper-plane"></i> Kirim Data Sampah
                        </button>
                    </div>
                </div>
                <div class="column">
                    <div class="how-it-works">
                        <h2 class="section-title">Bagaimana Cara Kerjanya?</h2>
                        <div class="steps">
                            <div class="step">
                                <span class="step-number">1</span>
                                <div class="step-content">
                                    <h3 class="step-title"><i class="fas fa-camera-retro step-icon"></i> Ambil Foto Sampah</h3>
                                    <p class="step-description">Gunakan kamera atau unggah gambar sampah yang ingin Anda identifikasi.</p>
                                </div>
                            </div>
                            <div class="step">
                                <span class="step-number">2</span>
                                <div class="step-content">
                                    <h3 class="step-title"><i class="fas fa-brain step-icon"></i> Proses AI</h3>
                                    <p class="step-description">Sistem kami akan menganalisis gambar menggunakan kecerdasan buatan canggih.</p>
                                </div>
                            </div>
                            <div class="step">
                                <span class="step-number">3</span>
                                <div class="step-content">
                                    <h3 class="step-title"><i class="fas fa-chart-pie step-icon"></i> Dapatkan Hasil</h3>
                                    <p class="step-description">Anda akan menerima informasi detail tentang jenis sampah dan cara pembuangannya.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <br>
        </div>
    </section>

    <section class="categories-section-wrapper" id="categories-section">
        <div class="container">
            <div class="categories-grid">
                <div class="category-card plastik" data-category="Plastik" tabindex="0" role="button" aria-label="Informasi Kategori Sampah Plastik">
                    <div class="category-icon">
                        <i class="fas fa-recycle"></i>
                    </div>
                    <h3 class="category-title">Plastik</h3>
                    <p class="category-description">
                        Sampah tidak terurai seperti botol plastik, kantong plastik, dan kemasan.
                    </p>
                </div>

                <div class="category-card kertas" data-category="Kertas" tabindex="0" role="button" aria-label="Informasi Kategori Sampah Kertas">
                    <div class="category-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3 class="category-title">Kertas</h3>
                    <p class="category-description">
                        Sampah dari bahan kertas seperti koran, kardus, dan majalah.
                    </p>
                </div>

                <div class="category-card organik" data-category="Organik" tabindex="0" role="button" aria-label="Informasi Kategori Sampah Organik">
                    <div class="category-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3 class="category-title">Organik</h3>
                    <p class="category-description">
                        Sampah yang dapat terurai seperti sisa makanan dan daun.
                    </p>
                </div>

                <div class="category-card logam" data-category="Logam" tabindex="0" role="button" aria-label="Informasi Kategori Sampah Logam">
                    <div class="category-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3 class="category-title">Logam</h3>
                    <p class="category-description">
                        Sampah dari bahan logam seperti kaleng, besi, dan alumunium.
                    </p>
                </div>
            </div>
        </div>

        <div class="column results-chart-full-width">
            <div class="results-chart-container">
                <h2 class="section-title">Statistik Sampah Anda</h2>
                <div class="chart-wrapper">
                    <canvas id="wastePieChart" aria-label="Grafik Pie Statistik Sampah"></canvas>
                </div>
                <ul id="chartLegend" class="chart-legend" role="list" aria-label="Legenda Grafik Sampah"></ul>
            </div>
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
                        <button class="action-btn" onclick="showHistory()" aria-label="Lihat Riwayat Transaksi">
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
                    <option value="other">Lain-lain</option> </select>
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
            <span class="close-button" onclick="closeModal('historyModal')">&times;</span>
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
                    <a href="#" aria-label="Whatsapp"><i class="fab fa-whatsapp"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    </div>
            </div>
            <div class="footer-links">
                <h4>Tautan Cepat</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="#scan-section">Edukasi</a></li>
                    <li><a href="Service_Quiz.php">Service</a></li>

                </ul>
            </div>
            <div class="footer-contact">
                <h4>Informasi Kontak</h4>
                <p><i class="fas fa-envelope"></i> info@gorako.com</p>
                <p><i class="fas fa-phone-alt"></i> +62 812 3456 7890</p>
                <p><i class="fas fa-map-marker-alt"></i> Jakarta, Indonesia</p>
            </div>
        </div>
        <div class="footer-bottom">
            © 2025 GoRako. Hak Cipta Dilindungi Undang-Undang.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Variabel global untuk menampung data awal dari PHP
        let initialWasteDataFromDB = <?php echo $initialWasteDataJson; ?>;
        // console.log("Data awal dari PHP:", initialWasteDataFromDB); // Debugging

        // Define categoriesInfo globally or within the relevant scope
        const categoriesInfo = {
            'Plastik': {
                icon: 'fas fa-recycle',
                title: 'Informasi Sampah Plastik',
                description: 'Sampah plastik adalah salah satu jenis sampah yang paling sulit terurai. Ini termasuk botol plastik, kantong plastik, kemasan makanan, dan barang-barang plastik lainnya. Daur ulang plastik sangat penting untuk mengurangi polusi lingkungan dan menghemat sumber daya alam. Plastik dapat diolah menjadi bijih plastik, serat, hingga bahan baku baru untuk produk lain.'
            },
            'Kertas': {
                icon: 'fas fa-file-alt',
                title: 'Informasi Sampah Kertas',
                description: 'Sampah kertas meliputi koran, majalah, kardus, buku, dan kemasan kertas. Kertas adalah salah satu bahan yang paling mudah didaur ulang dan proses daur ulangnya menghemat banyak pohon, air, dan energi. Kertas bekas dapat diolah menjadi bubur kertas dan dicetak ulang menjadi produk kertas baru.'
            },
            'Organik': {
                icon: 'fas fa-leaf',
                title: 'Informasi Sampah Organik',
                description: 'Sampah organik adalah sisa-sisa bahan hayati yang dapat terurai secara alami, seperti sisa makanan (buah, sayur, nasi), daun kering, ranting, dan rumput. Sampah organik sangat baik untuk dijadikan kompos atau pupuk, yang bermanfaat untuk menyuburkan tanah dan mengurangi kebutuhan akan pupuk kimia.'
            },
            'Logam': {
                icon: 'fas fa-bolt',
                title: 'Informasi Sampah Logam',
                description: 'Sampah logam mencakup kaleng minuman, kaleng makanan, besi tua, aluminium foil, dan barang-barang dari logam lainnya. Logam adalah salah satu material yang paling efisien untuk didaur ulang karena dapat didaur ulang berkali-kali tanpa mengurangi kualitasnya, menghemat energi yang besar, dan mengurangi emisi gas rumah kaca.'
            },
            'Kaca': {
                icon: 'fas fa-glass-whiskey', // Changed icon for Glass
                title: 'Informasi Sampah Kaca',
                description: 'Sampah kaca meliputi botol kaca, stoples, dan pecahan kaca. Seperti logam, kaca juga dapat didaur ulang berkali-kali tanpa kehilangan kualitasnya. Daur ulang kaca mengurangi kebutuhan akan bahan baku baru, menghemat energi, dan meminimalkan sampah di tempat pembuangan akhir.'
            }
        };


        document.addEventListener('DOMContentLoaded', async function() {
            // --- Hapus Teachable Machine Model Setup ---
            // Baris-baris berikut TIDAK DIPERLUKAN LAGI karena klasifikasi dilakukan oleh OpenAI
            // const TEACHABLE_MACHINE_URL = "https://teachablemachine.withgoogle.com/models/eJ4mxt7qS/";
            // let model, maxPredictions;
            // let currentStream = null;
            // let currentFacingMode = "environment"; // "user" for front camera, "environment" for back camera

            const scanLoading = document.getElementById('scanLoading');
            const processingSpinner = document.getElementById('processingSpinner'); // New spinner element

            // initTeachableMachine tidak lagi memuat model TM
            async function initOpenAIIntegration() {
                scanLoading.textContent = "Sistem AI siap memproses gambar.";
                scanLoading.classList.remove('hidden');
                scanLoading.classList.add('visible');
                // Tidak ada model yang dimuat di frontend, jadi langsung anggap siap
                console.log("Integrasi OpenAI siap.");
                setTimeout(() => { // Sembunyikan pesan setelah beberapa saat
                    scanLoading.classList.add('hidden');
                    scanLoading.classList.remove('visible');
                }, 3000);
                showToastMessage("Sistem AI siap digunakan!", "success");
            }


            // --- Waste Data & Chart Setup ---
            // Sesuaikan kategori ini agar SAMA PERSIS dengan NAMA KELAS yang diharapkan dari OpenAI
            const defaultWasteData = {
                'Plastik': 0,
                'Kertas': 0,
                'Organik': 0,
                'Logam': 0,
                'Kaca' : 0, // Mengubah dari 'Glass' ke 'Kaca'
            };

            // Memuat data waste: 우선적으로 PHP 데이터 -> 로컬 스토리지 -> 기본값
            let wasteData = {};
            if (typeof initialWasteDataFromDB !== 'undefined' && Object.keys(initialWasteDataFromDB).length > 0) {
                // Merge PHP data with default to ensure all categories exist, even if count is 0
                wasteData = { ...defaultWasteData, ...initialWasteDataFromDB };
            } else {
                // Fallback to local storage if PHP data is empty or not available
                wasteData = loadDataFromLocalStorage('wasteData', defaultWasteData);
            }


            const wasteColors = {
                'Plastik': '#87ceeb',
                'Kertas': '#deb887',
                'Organik': '#90ee90',
                'Logam': '#c0c0c0',
                'Kaca':'#901E3E', // Mengubah dari 'Glass' ke 'Kaca'
            };

            let wastePieChart;

            // --- Rewards Data & Functions ---
            // NEW: Load rewards from PHP
            // rewards array will contain objects with {id, title, description, points, stock, category, image, icon}
            const rewards = <?php echo json_encode($rewards_from_db); ?>;

            // NEW: Load user points from PHP, fallback to 0 if PHP data is null
            let userPoints = <?php echo json_encode($user_current_points); ?>;
            if (userPoints === null) { // Handle case where user is not logged in or points are null
                userPoints = 0;
            }

            let currentExchange = null;
            let currentRewardDetails = null; // Untuk menyimpan detail reward yang sedang ditampilkan di modal

            // NEW: Load transaction history from PHP
            let transactionHistory = <?php echo json_encode($transaction_history_from_db); ?>;
            if (transactionHistory === null) { // Handle case where user is not logged in or history is null
                transactionHistory = [];
            }


            function saveDataToLocalStorage(key, data) {
                try {
                    localStorage.setItem(key, JSON.stringify(data));
                } catch (e) {
                    console.error("Error saving to localStorage:", e);
                }
            }

            function loadDataFromLocalStorage(key, defaultValue) {
                const data = localStorage.getItem(key);
                try {
                    return data ? JSON.parse(data) : defaultValue;
                } catch (e) {
                    console.error("Error parsing localStorage data for key:", key, e);
                    return defaultValue;
                }
            }

            function displayRewards(rewardsToShow) {
                const grid = document.getElementById('rewardsGrid');
                if (!grid) return;
                grid.innerHTML = '';

                if (rewardsToShow.length === 0) {
                    grid.innerHTML = '<p style="text-align: center; grid-column: 1 / -1; color: #6b7280; font-style: italic;">Tidak ada hadiah yang tersedia atau cocok dengan filter.</p>';
                    return;
                }

                rewardsToShow.forEach(reward => {
                    const canAfford = userPoints >= reward.points;
                    const isAvailable = reward.stock > 0;
                    const isEnabled = canAfford && isAvailable;

                    const rewardCard = document.createElement('div');
                    rewardCard.className = 'reward-card fade-in';
                    rewardCard.setAttribute('tabindex', '0'); // Added for accessibility
                    rewardCard.setAttribute('role', 'button'); // Added for accessibility
                    rewardCard.setAttribute('aria-label', `Hadiah: ${reward.title}. Harga: ${reward.points} poin.`); // Added for accessibility
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

                document.querySelectorAll('.exchange-btn').forEach(btn => {
                    btn.onclick = function(e) {
                        e.stopPropagation(); // Mencegah event card klik terpicu
                        const rewardId = this.dataset.rewardId;
                        showExchangeModal(parseInt(rewardId));
                    };
                });

                // Add click listener to the card itself to show details
                document.querySelectorAll('.reward-card').forEach(card => {
                    card.addEventListener('click', function() {
                        const rewardId = this.querySelector('.exchange-btn').dataset.rewardId;
                        showRewardDetailsModal(parseInt(rewardId));
                    });
                     // Added keyboard accessibility for reward cards
                    card.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            const rewardId = this.querySelector('.exchange-btn').dataset.rewardId;
                            showRewardDetailsModal(parseInt(rewardId));
                        }
                    });
                });
            }

            function filterRewards() {
                const searchInput = document.getElementById('searchInput');
                const filterSelect = document.getElementById('filterSelect');
                if (!searchInput || !filterSelect) return;

                const searchTerm = searchInput.value.toLowerCase();
                const filterCategory = filterSelect.value;

                let filteredRewards = rewards.filter(reward => {
                    const matchesSearch = reward.title.toLowerCase().includes(searchTerm) ||
                                        reward.description.toLowerCase().includes(searchTerm);
                    const matchesCategory = filterCategory === 'all' || reward.category === filterCategory;

                    return matchesSearch && matchesCategory;
                });
                displayRewards(filteredRewards);
            }

            // Function to handle the actual exchange via AJAX to PHP backend
            async function performExchangeBackend(rewardId) {
                try {
                    const formData = new URLSearchParams();
                    formData.append('action', 'exchange_reward');
                    formData.append('reward_id', rewardId);

                    const response = await fetch('service_quiz.php', { // Send to the same PHP file
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! Status: ${response.status}. Response: ${errorText}`);
                    }

                    const result = await response.json(); // PHP will respond with JSON

                    if (result.success) { // If backend reports success
                        // Update local UI based on successful backend response
                        const oldPoints = userPoints;
                        userPoints = result.new_total_points; // Get updated points from backend
                        const pointsDeducted = result.points_deducted; // Points deducted

                        // Update stock locally based on backend response
                        const updatedReward = rewards.find(r => r.id === rewardId);
                        if (updatedReward) {
                            updatedReward.stock = result.new_stock;
                        }

                        // Add to transaction history
                        transactionHistory.push({ //
                            type: 'exchange',
                            description: `Tukar hadiah "${updatedReward.title}"`, // Changed to description
                            points: -pointsDeducted, // Points deducted are negative for exchange
                            date: new Date().toISOString()
                        });
                        saveDataToLocalStorage('transactionHistory', transactionHistory);

                        updatePointsDisplay(oldPoints, userPoints);
                        saveDataToLocalStorage('userPoints', userPoints);
                        displayRewards(rewards); // Re-render all rewards to update stock status

                        showToastMessage(`Berhasil menukar ${updatedReward.title}! Poin Anda sekarang: ${userPoints}`, 'success');
                        return true;
                    } else {
                        throw new Error(result.error || 'Unknown error from server'); // Display error message from backend
                    }
                } catch (error) {
                    console.error("Gagal melakukan penukaran hadiah:", error);
                    showToastMessage(`Gagal menukar hadiah: ${error.message}`, "error", 5000);
                    return false;
                }
            }

            function confirmExchange() {
                if (!currentExchange) return;

                // Call backend function
                performExchangeBackend(currentExchange.id).then(success => {
                    if (success) {
                        closeModal('confirmExchangeModal');
                    }
                });
            }

            function showExchangeModal(rewardId) {
                const reward = rewards.find(r => r.id === rewardId);
                if (!reward) return;

                const canAfford = userPoints >= reward.points;
                const isAvailable = reward.stock > 0;

                if (!canAfford) {
                    showToastMessage('Poin Anda tidak cukup untuk menukar hadiah ini.', 'error');
                    return;
                }
                if (!isAvailable) {
                    showToastMessage('Maaf, stok hadiah ini sudah habis.', 'error');
                    return;
                }

                currentExchange = reward;

                const modalText = document.getElementById('confirmExchangeModalText');
                if (!modalText) return;
                modalText.innerHTML = `
                    <p><strong>Hadiah:</strong> ${reward.title}</p>
                    <p><strong>Harga:</strong> ${reward.points} poin</p>
                    <p><strong>Sisa poin Anda:</strong> ${userPoints - reward.points} poin</p>
                    <br>
                    <p>Apakah Anda yakin ingin menukar ${reward.points} poin untuk mendapatkan <strong>${reward.title}</strong>?</p>
                `;

                const confirmModal = document.getElementById('confirmExchangeModal');
                confirmModal.style.display = 'flex';
                requestAnimationFrame(() => { // Trigger transition
                    confirmModal.classList.add('active');
                    confirmModal.focus(); // Focus on modal for accessibility
                });
            }

            function showRewardDetailsModal(rewardId) {
                const reward = rewards.find(r => r.id === rewardId);
                if (!reward) return;

                currentRewardDetails = reward; // Simpan untuk tombol tukar di modal detail

                document.getElementById('rewardDetailsIcon').innerHTML = `<i class="${reward.icon}"></i>`;
                document.getElementById('rewardDetailsTitle').textContent = reward.title;
                document.getElementById('rewardDetailsDescription').textContent = reward.description;

                const exchangeBtn = document.getElementById('rewardDetailsExchangeBtn');
                const canAfford = userPoints >= reward.points;
                const isAvailable = reward.stock > 0;

                exchangeBtn.textContent = isAvailable ? `Tukar Sekarang (${reward.points} poin)` : 'Stok Habis';
                exchangeBtn.disabled = !canAfford || !isAvailable;
                exchangeBtn.onclick = () => {
                    closeModal('rewardDetailsModal'); // Tutup modal detail dulu
                    showExchangeModal(reward.id); // Kemudian buka modal konfirmasi
                };

                const rewardDetailsModal = document.getElementById('rewardDetailsModal');
                rewardDetailsModal.style.display = 'flex';
                requestAnimationFrame(() => { // Trigger transition
                    rewardDetailsModal.classList.add('active');
                    rewardDetailsModal.focus(); // Focus on modal for accessibility
                });
            }

            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.remove('active');
                    modal.querySelector('.modal-content, .quiz-modal-content, .history-modal-content').style.transform = 'translateY(-50px)';
                    modal.querySelector('.modal-content, .quiz-modal-content, .history-modal-content').style.opacity = '0';
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 300); // Allow time for exit animation
                }
                if (modalId === 'confirmExchangeModal') currentExchange = null;
                if (modalId === 'rewardDetailsModal') currentRewardDetails = null;
                if (modalId === 'categoryDetailsModal') currentCategoryDetails = null;
            }

            function updatePointsDisplay(oldPoints = userPoints, newPoints = userPoints) {
                const userPointsElement = document.getElementById('userPoints');
                if (userPointsElement) {
                    userPointsElement.classList.add('animated');
                    userPointsElement.textContent = newPoints;

                    setTimeout(() => {
                        userPointsElement.classList.remove('animated');
                    }, 500); // Remove animation class after half a second
                }
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

                // Ensure only one toast is active at a time
                clearTimeout(toastMessage.timer);
                toastMessage.timer = setTimeout(() => {
                    toastMessage.style.animation = 'toastFadeOut 0.5s ease-in forwards';
                    setTimeout(() => {
                        toastMessage.style.display = 'none';
                    }, 500); // Wait for fade out animation
                }, duration);
            }

            function showHistory() {
                const historyModal = document.getElementById('historyModal');
                const historyList = document.getElementById('transactionHistoryList');
                const historyEmptyMessage = document.getElementById('historyEmptyMessage');
                historyList.innerHTML = ''; // Clear previous entries

                if (transactionHistory.length > 0) {
                    historyEmptyMessage.style.display = 'none';
                    // Sort by date, newest first (if not already sorted by PHP)
                    transactionHistory.sort((a, b) => new Date(b.date) - new Date(a.date));

                    transactionHistory.forEach(item => {
                        const date = new Date(item.date).toLocaleString('id-ID', {
                            year: 'numeric', month: 'short', day: 'numeric',
                            hour: '2-digit', minute: '2-digit' // Simplified to 2-digit minutes
                        });
                        const li = document.createElement('li');
                        li.className = `history-item ${item.points > 0 ? 'plus' : 'minus'}`;

                        let itemDetailsHtml = item.description; // Default to raw description
                        // if (item.type === 'scan') {
                        //     itemDetailsHtml = `Scan sampah: ${item.wasteType || ''}`; // Use wasteType from data if available
                        // } else if (item.type === 'quiz') {
                        //     itemDetailsHtml = `Selesaikan Kuis: ${item.quizTitle || 'ID Tidak Dikenali'}`; // Use quizTitle if available
                        // } else if (item.type === 'exchange') {
                        //     itemDetailsHtml = `Tukar hadiah: ${item.reward || ''}`; // Use reward name if available
                        // }


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
                requestAnimationFrame(() => { // Trigger transition
                    historyModal.classList.add('active');
                    historyModal.querySelector('.history-modal-content').focus();
                });
            }

            function showAllRewards() {
                const searchInput = document.getElementById('searchInput');
                const filterSelect = document.getElementById('filterSelect');
                if (searchInput) searchInput.value = '';
                if (filterSelect) filterSelect.value = 'all';
                filterRewards();

                document.querySelector('.rewards-section').scrollIntoView({
                    behavior: 'smooth'
                });
            }

            // --- API Configuration ---
            // Fungsi untuk mengirim gambar ke API PHP yang akan memanggil OpenAI
            async function sendImageToOpenAIBackend(base64ImageData) {
                try {
                    const formData = new URLSearchParams();
                    formData.append('image_data_base64', base64ImageData); // Kirim data base64
                    // Tidak perlu user_id di sini karena sudah ditangani oleh session di PHP

                    const response = await fetch('service_quiz.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! Status: ${response.status}. Response: ${errorText}`);
                    }

                    const result = await response.json();

                    if (result.success) {
                        console.log("Hasil klasifikasi dari OpenAI (via PHP):", result);
                        return result; // Mengembalikan hasil dari API jika berhasil
                    } else {
                        throw new Error(`Server reported error: ${result.error || 'Unknown error'}`);
                    }
                } catch (error) {
                    console.error("Gagal mengirim gambar ke server PHP untuk klasifikasi OpenAI:", error);
                    showToastMessage(`Gagal klasifikasi: ${error.message}`, "error", 5000);
                    return null;
                }
            }


            // --- Elements (getting references) ---
            const uploadBtn = document.getElementById('uploadBtn');
            const cameraBtn = document.getElementById('cameraBtn');
            const fileInput = document.getElementById('fileInput');
            const previewContainer = document.getElementById('previewContainer');
            const previewImage = document.getElementById('previewImage');
            const closePreview = document.getElementById('closePreview');
            const scanResultDisplay = document.getElementById('scanResult');
            const scannedWasteTypeSpan = document.getElementById('scannedWasteType');
            const submitScanBtn = document.getElementById('submitScanBtn');
            const steps = document.querySelectorAll('.step');
            const mobileToggle = document.getElementById('mobileToggle');
            const navMenu = document.getElementById('navMenu');
            const quizButtons = document.querySelectorAll('.quiz-btn');
            const quizHeader = document.querySelector('.quiz-header');
            const navLinks = document.querySelectorAll('.nav-link');
            const navbar = document.querySelector('.navbar');
            const primarySecondaryButtons = document.querySelectorAll('.btn-primary, .btn-secondary, .cta-button');
            const categoryCards = document.querySelectorAll('.category-card');

            // Elements for controlling initial UI in scan section
            const initialCameraIcon = document.getElementById('initialCameraIcon');
            const initialUploadText = document.getElementById('initialUploadText');
            const initialButtonContainer = document.getElementById('initialButtonContainer');
            const cameraOptions = document.getElementById('cameraOptions');
            const frontCameraBtn = document.getElementById('frontCameraBtn');
            const backCameraBtn = document.getElementById('backCameraBtn');

            // --- Inisialisasi Pie Chart ---
            const ctx = document.getElementById('wastePieChart').getContext('2d');
            if (typeof Chart !== 'undefined') {
                wastePieChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: Object.keys(wasteData),
                        datasets: [{
                            data: Object.values(wasteData),
                            backgroundColor: Object.values(wasteColors),
                            hoverOffset: 10,
                            borderColor: '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%', // Membuat donut chart
                        plugins: {
                            legend: { display: false }, // Sembunyikan legend bawaan Chart.js
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed !== null) {
                                            const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                            const percentage = (total > 0) ? (context.parsed / total * 100).toFixed(1) + '%' : '0%';
                                            label += `${context.parsed} item (${percentage})`;
                                        }
                                        return label;
                                    }
                                },
                                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                                titleFont: { size: 16 },
                                bodyFont: { size: 14 },
                                padding: 12,
                                borderRadius: 8
                            },
                            title: {
                                display: true,
                                text: 'Distribusi Sampah Berdasarkan Kategori',
                                font: { size: 18, weight: 'bold' },
                                color: '#333'
                            }
                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true
                        }
                    }
                });
            } else {
                console.error("Chart.js is not defined. Make sure the script is loaded correctly.");
            }
            updatePieChart(); // Panggil ini setelah wasteData diinisialisasi

            // --- Fungsi Pembantu Chart ---
            function updatePieChart() {
                if (wastePieChart) {
                    // Filter out categories with 0 items for display in chart and legend
                    const labels = Object.keys(wasteData).filter(type => wasteData[type] > 0);
                    const data = labels.map(type => wasteData[type]);
                    const colors = labels.map(type => wasteColors[type]);

                    // If all data is 0, add a "No Data" slice for better UX
                    if (data.every(val => val === 0) || data.length === 0) {
                        wastePieChart.data.labels = ['Tidak Ada Data'];
                        wastePieChart.data.datasets[0].data = [1]; // A dummy value to show a full circle
                        wastePieChart.data.datasets[0].backgroundColor = ['#cccccc'];
                        wastePieChart.options.plugins.tooltip.enabled = false; // Disable tooltip for no data
                    } else {
                        wastePieChart.data.labels = labels;
                        wastePieChart.data.datasets[0].data = data;
                        wastePieChart.data.datasets[0].backgroundColor = colors;
                        wastePieChart.options.plugins.tooltip.enabled = true; // Enable tooltip when there's data
                    }

                    wastePieChart.update();
                    updateCustomLegend();
                    saveDataToLocalStorage('wasteData', wasteData); // Simpan data statistik ke local storage
                }
            }


            function updateCustomLegend() {
                const legendContainer = document.getElementById('chartLegend');
                if (!legendContainer) return;
                legendContainer.innerHTML = '';
                // Hanya tampilkan kategori yang memiliki item > 0
                Object.keys(wasteData).forEach(type => {
                    if (wasteData[type] > 0) {
                        const li = document.createElement('li');
                        const colorBox = document.createElement('span');
                        colorBox.className = 'legend-color-box';
                        colorBox.style.backgroundColor = wasteColors[type];
                        li.appendChild(colorBox);
                        li.appendChild(document.createTextNode(`${type}: ${wasteData[type]} item`));
                        legendContainer.appendChild(li);
                    }
                });
            }

            // --- Fungsi OpenAI Prediction (melalui PHP) ---
            async function predictImage(imageElement) {
                scanResultDisplay.classList.add('hidden');
                scanResultDisplay.classList.remove('visible');
                submitScanBtn.classList.add('hidden');
                submitScanBtn.disabled = true; // Disable submit button during scan

                scanLoading.textContent = "Mengirim gambar ke AI...";
                scanLoading.classList.remove('hidden');
                scanLoading.classList.add('visible');
                processingSpinner.style.display = 'block'; // Show spinner

                try {
                    console.log("Memulai pengiriman gambar untuk klasifikasi OpenAI...");
                    const canvas = document.createElement('canvas');
                    canvas.width = imageElement.naturalWidth || imageElement.videoWidth || 640; // Default width
                    canvas.height = imageElement.naturalHeight || imageElement.videoHeight || 480; // Default height
                    const context = canvas.getContext('2d');
                    context.drawImage(imageElement, 0, 0, canvas.width, canvas.height);
                    const imageDataURL = canvas.toDataURL('image/jpeg', 0.9); // Kualitas gambar 0.9

                    // Hapus prefix "data:image/jpeg;base64,"
                    const base64Data = imageDataURL.split(',')[1];

                    const openaiResponse = await sendImageToOpenAIBackend(base64Data);

                    if (openaiResponse && openaiResponse.success) {
                        const predictedClass = openaiResponse.predicted_class;
                        const probability = openaiResponse.probability; // Dari backend
                        const reason = openaiResponse.reason; // Dari backend

                        // Update UI dengan hasil dari OpenAI
                        scannedWasteTypeSpan.innerHTML = `<strong>${predictedClass}</strong> <small style="font-size: 0.8em; color: #555;">(${ (probability * 100).toFixed(1) }%)</small><br><small style="font-size: 0.8em; color: #555;">${reason}</small>`;
                        scanResultDisplay.classList.remove('hidden');
                        scanResultDisplay.classList.add('visible');

                        if (predictedClass !== 'Tidak Dikenali') {
                            submitScanBtn.classList.remove('hidden');
                            submitScanBtn.disabled = false;
                            // Set data attributes for submission
                            submitScanBtn.dataset.predictedClass = predictedClass;
                            submitScanBtn.dataset.probability = probability;
                        } else {
                            submitScanBtn.classList.add('hidden');
                            submitScanBtn.disabled = true;
                        }

                    } else {
                        scannedWasteTypeSpan.textContent = openaiResponse ? openaiResponse.error : "Gagal mengklasifikasikan sampah.";
                        scanResultDisplay.classList.remove('hidden');
                        scanResultDisplay.classList.add('visible');
                        submitScanBtn.classList.add('hidden');
                        submitScanBtn.disabled = true;
                    }

                } catch (error) {
                    console.error("Error selama klasifikasi OpenAI:", error);
                    scannedWasteTypeSpan.textContent = "Error saat memindai.";
                    scanResultDisplay.classList.remove('hidden');
                    scanResultDisplay.classList.add('visible');
                    submitScanBtn.classList.add('hidden');
                    submitScanBtn.disabled = true;
                    showToastMessage("Terjadi kesalahan saat memindai. Coba lagi.", "error");
                } finally {
                    scanLoading.classList.add('hidden');
                    scanLoading.classList.remove('visible');
                    processingSpinner.style.display = 'none'; // Hide spinner
                }
            }


            // --- Fungsi untuk Mengatur Ulang UI Pemindaian ---
            function resetScanUI() {
                if (currentStream) {
                    currentStream.getTracks().forEach(track => track.stop());
                    currentStream = null;
                }
                const videoElement = previewContainer.querySelector('video');
                if (videoElement) videoElement.remove();
                const captureButton = previewContainer.querySelector('.capture-btn');
                if (captureButton) captureButton.remove();

                previewContainer.classList.add('hidden');
                previewImage.src = '#';
                previewImage.classList.add('hidden');

                scanResultDisplay.classList.add('hidden');
                scanResultDisplay.classList.remove('visible');
                submitScanBtn.classList.add('hidden');
                submitScanBtn.disabled = true;
                scanLoading.classList.add('hidden');
                scanLoading.classList.remove('visible');
                processingSpinner.style.display = 'none'; // Hide spinner

                initialCameraIcon.classList.remove('hidden');
                initialUploadText.classList.remove('hidden');
                initialButtonContainer.classList.remove('hidden');
                cameraOptions.classList.add('hidden'); // Sembunyikan opsi kamera
            }

            let currentStream = null; // Defined here to be accessible
            let currentFacingMode = "environment"; // "user" for front camera, "environment" for back camera

            // --- Fungsi untuk Memulai Kamera ---
            async function startCamera(facingMode) {
                resetScanUI();
                currentFacingMode = facingMode;

                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: currentFacingMode,
                                width: { ideal: 1280 },
                                height: { ideal: 720 }
                            }
                        });
                        currentStream = stream;

                        initialCameraIcon.classList.add('hidden');
                        initialUploadText.classList.add('hidden');
                        initialButtonContainer.classList.add('hidden');
                        previewImage.classList.add('hidden');
                        cameraOptions.classList.remove('hidden'); // Tampilkan opsi kamera

                        const video = document.createElement('video');
                        video.style.width = '100%';
                        video.style.maxWidth = '500px';
                        video.autoplay = true;
                        video.playsInline = true; // Penting untuk iOS
                        video.srcObject = stream;
                        previewContainer.appendChild(video);
                        previewContainer.classList.remove('hidden');

                        // Tambahkan tombol tangkap foto setelah video dimuat
                        video.onloadedmetadata = () => {
                            const captureBtn = document.createElement('button');
                            captureBtn.textContent = 'Ambil Foto';
                            captureBtn.classList.add('btn', 'btn-camera', 'capture-btn');
                            captureBtn.style.marginTop = '20px';
                            previewContainer.appendChild(captureBtn);

                            captureBtn.addEventListener('click', async () => {
                                captureBtn.classList.add('loading'); // Show loading state
                                processingSpinner.style.display = 'block'; // Show spinner during capture/predict

                                const canvas = document.createElement('canvas');
                                canvas.width = video.videoWidth;
                                canvas.height = video.videoHeight;
                                const context = canvas.getContext('2d');
                                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                                const imageDataURL = canvas.toDataURL('image/jpeg', 0.9); // Kualitas gambar 0.9

                                previewImage.src = imageDataURL;
                                previewImage.classList.remove('hidden');
                                video.remove();
                                captureBtn.remove();

                                if (currentStream) {
                                    currentStream.getTracks().forEach(track => track.stop());
                                    currentStream = null;
                                }

                                initialCameraIcon.classList.remove('hidden'); // Tampilkan lagi ikon awal
                                initialUploadText.classList.remove('hidden'); // Tampilkan lagi teks awal
                                initialButtonContainer.classList.remove('hidden'); // Tampilkan lagi tombol awal
                                cameraOptions.classList.add('hidden'); // Sembunyikan opsi kamera

                                captureBtn.classList.remove('loading'); // Remove loading state
                                await predictImage(previewImage);
                            });
                        };

                    } catch (error) {
                        console.error('Error mengakses kamera:', error);
                        showToastMessage('Tidak dapat mengakses kamera. Pastikan Anda memberikan izin dan peramban mendukung.', 'error', 7000);
                        resetScanUI();
                    }
                } else {
                    showToastMessage('Peramban Anda tidak mendukung akses kamera.', 'error');
                    resetScanUI();
                }
            }


            // --- Event Listeners untuk Scan Section ---
            uploadBtn.addEventListener('click', () => {
                resetScanUI();
                fileInput.click();
            });

            fileInput.addEventListener('change', async (event) => {
                const file = event.target.files[0];
                if (file) {
                    scanLoading.textContent = "Mengunggah gambar..."; // Feedback loading upload
                    scanLoading.classList.remove('hidden');
                    scanLoading.classList.add('visible');
                    processingSpinner.style.display = 'block'; // Show spinner

                    const reader = new FileReader();
                    reader.onload = async function(e) {
                        previewImage.src = e.target.result;
                        previewImage.classList.remove('hidden');
                        previewContainer.classList.remove('hidden');

                        initialCameraIcon.classList.add('hidden');
                        initialUploadText.classList.add('hidden');
                        initialButtonContainer.classList.add('hidden');
                        cameraOptions.classList.add('hidden'); // Sembunyikan opsi kamera jika mengunggah

                        await predictImage(previewImage); // Panggil predictImage dengan gambar pratinjau
                        scanLoading.classList.add('hidden'); // Sembunyikan setelah prediksi
                        scanLoading.classList.remove('visible');
                        processingSpinner.style.display = 'none'; // Hide spinner
                    };
                    reader.readAsDataURL(file);
                }
            });

            closePreview.addEventListener('click', () => {
                resetScanUI();
            });

            cameraBtn.addEventListener('click', function() {
                startCamera(currentFacingMode); // Mulai kamera dengan mode terakhir yang dipilih
            });

            frontCameraBtn.addEventListener('click', () => {
                frontCameraBtn.classList.add('active');
                backCameraBtn.classList.remove('active');
                startCamera("user");
            });

            backCameraBtn.addEventListener('click', () => {
                backCameraBtn.classList.add('active');
                frontCameraBtn.classList.remove('active');
                startCamera("environment");
            });

            submitScanBtn.addEventListener('click', async () => {
                submitScanBtn.classList.add('loading'); // Tampilkan loading pada tombol

                const type = submitScanBtn.dataset.predictedClass; // Ambil dari data attribute
                const probability = parseFloat(submitScanBtn.dataset.probability); // Ambil dari data attribute

                if (type && type !== 'Tidak Dikenali') {
                    // Send data to API (PHP backend) which will then save to DB and update points
                    const apiResult = await sendImageToOpenAIBackend(previewImage.src.split(',')[1]); // Re-send base64 data to PHP

                    if (apiResult && apiResult.success) {
                        // Update wasteData locally for chart
                        if (wasteData.hasOwnProperty(apiResult.predicted_class)) {
                            wasteData[apiResult.predicted_class]++;
                        } else {
                            // If a new category from prediction is not in defaultWasteData
                            wasteData[apiResult.predicted_class] = 1;
                        }

                        const oldPoints = userPoints;
                        userPoints = apiResult.new_total_points; // Get updated points from backend

                        // Note: Transaction history is ideally managed server-side and fetched on page load or on specific events.
                        // For now, we manually add to local history but rely on PHP for definitive history.
                        transactionHistory.push({
                            type: 'scan',
                            description: `Scan sampah: ${apiResult.predicted_class}`,
                            points: apiResult.points_awarded,
                            date: new Date().toISOString()
                        });
                        saveDataToLocalStorage('transactionHistory', transactionHistory);

                        updatePieChart();
                        updatePointsDisplay(oldPoints, userPoints); // Animasi poin
                        showToastMessage(`Berhasil menambahkan sampah '${apiResult.predicted_class}' dan +${apiResult.points_awarded} poin! Total poin: ${userPoints}`, 'success');
                        resetScanUI();
                    } else {
                        showToastMessage("Gagal menyimpan hasil scan ke API. Coba lagi.", "error", 5000);
                        resetScanUI(); // Reset UI even if API fails or no result
                    }

                } else {
                    showToastMessage("Sampah tidak dikenali. Tidak ada poin yang diberikan. Coba lagi dengan gambar yang lebih jelas.", "info", 5000);
                    resetScanUI();
                }
                submitScanBtn.classList.remove('loading'); // Sembunyikan loading
            });

            // --- Animasi Tambahan dan Fungsionalitas Umum ---
            // Mobile Menu Toggle
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
                     // Ensure accessibility for mobile menu
                    navMenu.setAttribute('aria-expanded', navMenu.classList.contains('active'));
                });
            }

            // Close mobile menu when clicking on a link
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

            // Navbar scroll effect
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

            // Quiz button functionality - NOW REDIRECTS TO Soal_Quiz.html
            // Re-fetch quiz buttons after they are dynamically loaded
            const updatedQuizButtons = document.querySelectorAll('.quiz-btn');
            if (updatedQuizButtons.length > 0) {
                updatedQuizButtons.forEach((button) => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        // Redirect to Soal_Quiz.php
                        window.location.href = 'soal_quiz.php?quiz_id=' + this.dataset.quizId; // Pass quiz_id to the quiz page
                    });
                });
            }


            // Quiz card hover effects (enhanced 3D feel)
            // Need to apply this to dynamically loaded cards as well.
            // Best to run this after the cards are rendered.
            function applyQuizCardEffects() {
                const quizCards = document.querySelectorAll('.quiz-card'); // Re-select cards after they are loaded
                if (quizCards.length > 0) {
                    quizCards.forEach(card => {
                        card.addEventListener('mousemove', function(e) {
                            const rect = this.getBoundingClientRect();
                            const x = e.clientX - rect.left;
                            const y = e.clientY - rect.top;
                            const centerX = rect.width / 2;
                            const centerY = rect.height / 2;
                            const rotateX = (y - centerY) / 20;
                            const rotateY = (centerX - x) / 20;
                            this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-10px)`;
                        });

                        card.addEventListener('mouseenter', function() {
                            const icon = this.querySelector('.quiz-icon');
                            if (icon) {
                                icon.style.transform = 'scale(1.1) rotate(5deg) translateZ(20px)';
                                icon.style.transition = 'all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                            }
                        });

                        card.addEventListener('mouseleave', function() {
                            const icon = this.querySelector('.quiz-icon');
                            if (icon) {
                                icon.style.transform = 'scale(1) rotate(0deg) translateZ(0)';
                            }
                            this.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateY(0px)';
                        });
                    });
                }
            }
            applyQuizCardEffects(); // Apply initially

            // Kategori Card Details
            if (categoryCards.length > 0) {
                categoryCards.forEach(card => {
                    card.addEventListener('click', function() {
                        const categoryName = this.dataset.category;
                        showCategoryDetailsModal(categoryName);
                    });
                     // Added keyboard accessibility for category cards
                    card.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            const categoryName = this.dataset.category;
                            showCategoryDetailsModal(categoryName);
                        }
                    });
                });
            }

            function showCategoryDetailsModal(categoryName) {
                const category = categoriesInfo[categoryName];
                if (!category) return;

                document.getElementById('categoryDetailsIcon').innerHTML = `<i class="${category.icon}"></i>`;
                document.getElementById('categoryDetailsTitle').textContent = category.title;
                document.getElementById('categoryDetailsDescription').textContent = category.description;

                const categoryDetailsModal = document.getElementById('categoryDetailsModal');
                categoryDetailsModal.style.display = 'flex';
                requestAnimationFrame(() => { // Trigger transition
                    categoryDetailsModal.classList.add('active');
                    categoryDetailsModal.focus(); // Focus on modal for accessibility
                });
            }


            // Intersection Observer for scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const globalObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        entry.target.classList.add('visible');
                    } else {
                        // Opsi: reset elemen saat keluar dari viewport jika diinginkan
                        // entry.target.style.opacity = '0';
                        // entry.target.style.transform = 'translateY(30px)';
                        // entry.target.classList.remove('visible');
                    }
                });
            }, observerOptions);

            // Observe quiz cards for scroll animation
            // Re-observe after dynamic content loaded
            const quizCardsForObservation = document.querySelectorAll('.quiz-card');
            if (quizCardsForObservation.length > 0) {
                quizCardsForObservation.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                    globalObserver.observe(card);
                });
            }

            // Observe quiz header
            if (quizHeader) {
                quizHeader.style.opacity = '0';
                quizHeader.style.transform = 'translateY(20px)';
                quizHeader.style.transition = 'all 0.8s ease';
                globalObserver.observe(quizHeader);
            }

            // Observe steps
            if (steps.length > 0) {
                steps.forEach((step, index) => {
                    step.style.opacity = '0';
                    step.style.transform = 'translateY(20px)';
                    step.style.transition = `all 0.6s ease ${index * 0.1}s`;
                    globalObserver.observe(step);
                });
            }

            // Observe category cards
            if (categoryCards.length > 0) {
                categoryCards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                    globalObserver.observe(card);
                });
            }

            // Observe reward cards
            const initialRewardCards = document.querySelectorAll('.reward-card'); // Use a new variable for initial observation
            if (initialRewardCards.length > 0) {
                initialRewardCards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                    globalObserver.observe(card);
                });
            }

            // Observe redemption explanation cards
            const redemptionStepCards = document.querySelectorAll('.redemption-step-card');
            if (redemptionStepCards.length > 0) {
                redemptionStepCards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    card.style.transition = `all 0.6s ease ${index * 0.1}s`;
                    globalObserver.observe(card);
                });
            }

            // Initial setup for rewards section
            displayRewards(rewards); // Initial render using data from PHP
            updatePointsDisplay(); // Initial display of points from PHP

            // Event listeners for search and filter rewards
            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterSelect');
            if (searchInput) searchInput.addEventListener('input', filterRewards);
            if (filterSelect) filterSelect.addEventListener('change', filterRewards);

            // Event listener for modal (click outside modal)
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(this.id);
                    }
                });
                 // Added keyboard accessibility for closing modals
                modal.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        closeModal(this.id);
                    }
                });
            });


            // Event listener for userPoints (click effect)
            const userPointsElement = document.getElementById('userPoints');
            if (userPointsElement) {
                userPointsElement.addEventListener('click', function() {
                    this.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 200);
                });
            }

            // Easter egg for bonus points
            document.addEventListener('keydown', function(e) {
                // Konami code for bonus points (Ctrl+Shift+P)
                if (e.ctrlKey && e.shiftKey && e.key === 'P') {
                    const oldPoints = userPoints;
                    userPoints += 100;
                    transactionHistory.push({
                        type: 'bonus',
                        description: 'Bonus Kode Konami',
                        points: 100,
                        date: new Date().toISOString()
                    });
                    saveDataToLocalStorage('transactionHistory', transactionHistory);

                    updatePointsDisplay(oldPoints, userPoints);
                    saveDataToLocalStorage('userPoints', userPoints);
                    showToastMessage('Bonus 100 poin! ✨', "success");
                }
            });

            // Inisialisasi integrasi OpenAI saat DOM selesai dimuat
            await initOpenAIIntegration();
        }); // End of DOMContentLoaded
    </script>
</body>
</html>