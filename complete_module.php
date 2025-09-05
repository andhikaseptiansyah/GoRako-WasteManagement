<?php
require_once 'db_connection.php';
require_once 'helpers.php';

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit;
}

$loggedInUserId = $_SESSION['user_id'];

// Get data from POST request
$moduleId = filter_input(INPUT_POST, 'moduleId', FILTER_VALIDATE_INT);
$moduleType = filter_input(INPUT_POST, 'moduleType', FILTER_SANITIZE_STRING); // 'video' or 'research'
// IMPORTANT: DO NOT TRUST pointsReward from client-side POST directly. Fetch it from DB.
$targetProgressModuleId = filter_input(INPUT_POST, 'targetProgressModuleId', FILTER_VALIDATE_INT); // The corresponding progress module ID

if (!$moduleId || !$moduleType || !$targetProgressModuleId) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data. Missing module ID, type, or target progress ID.']);
    exit;
}

// --- Fetch module details (points_reward and title) from the database ---
$module_points = 0;
$module_title = '';
$module_table = ($moduleType === 'research') ? 'modules_research' : 'modules_video';

$stmt_get_details = $conn->prepare("SELECT points_reward, title FROM " . $module_table . " WHERE id = ? AND is_active = 1");
if ($stmt_get_details) {
    $stmt_get_details->bind_param("i", $moduleId);
    if (!$stmt_get_details->execute()) {
        error_log("Failed to execute get_details statement: " . $stmt_get_details->error);
        echo json_encode(['success' => false, 'message' => 'Database error during module details retrieval execution.']);
        exit();
    }
    $result_details = $stmt_get_details->get_result();
    if ($row_details = $result_details->fetch_assoc()) {
        $module_points = $row_details['points_reward'];
        $module_title = $row_details['title'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Module not found or inactive.']);
        exit();
    }
    $stmt_get_details->close();
} else {
    error_log("Failed to prepare get_details query: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Database error during module details retrieval.']);
    exit();
}

$conn->begin_transaction();
try {
    // 1. Check if the module is already completed by the user
    $stmt_check_existing_progress = $conn->prepare("SELECT is_completed, points_earned FROM user_module_progress WHERE user_id = ? AND module_type = ? AND module_id = ?");
    if (!$stmt_check_existing_progress) {
        throw new Exception("Failed to prepare check_existing_progress statement: " . $conn->error);
    }
    $stmt_check_existing_progress->bind_param("isi", $loggedInUserId, $moduleType, $moduleId);
    $stmt_check_existing_progress->execute();
    $result_existing_progress = $stmt_check_existing_progress->get_result();
    $existing_progress = $result_existing_progress->fetch_assoc();
    $stmt_check_existing_progress->close();

    $already_completed = ($existing_progress && $existing_progress['is_completed'] == 1);
    $points_already_earned_for_this_module = $existing_progress['points_earned'] ?? 0; // The points recorded for this module completion in progress table
    $points_awarded_in_this_request = 0; // Track points specifically awarded in THIS transaction

    // Only proceed with point awarding and history recording if not already completed
    if (!$already_completed) {
        // Update user_module_progress: Use ON DUPLICATE KEY UPDATE to handle race conditions if somehow two requests hit at once,
        // but primarily, this is for initial insert. Update points_earned and completed_at.
        $stmt_progress = $conn->prepare("INSERT INTO user_module_progress (user_id, module_type, module_id, is_completed, points_earned, completed_at) VALUES (?, ?, ?, 1, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE is_completed = 1, points_earned = VALUES(points_earned), completed_at = CURRENT_TIMESTAMP");
        if (!$stmt_progress) {
            throw new Exception("Failed to prepare statement for progress: " . $conn->error);
        }
        $stmt_progress->bind_param("isii", $loggedInUserId, $moduleType, $moduleId, $module_points);
        if (!$stmt_progress->execute()) {
            throw new Exception("Failed to execute statement for progress: " . $stmt_progress->error);
        }
        $stmt_progress->close();

        // Add entry to modules_completed (for history in profile)
        // Check if already in history to prevent duplicate entries if user re-completes later somehow
        $stmt_check_history = $conn->prepare("SELECT id FROM modules_completed WHERE user_id = ? AND module_name = ? AND module_type = ?");
        if (!$stmt_check_history) {
            throw new Exception("Failed to prepare check_history statement for modules_completed: " . $conn->error);
        }
        $stmt_check_history->bind_param("iss", $loggedInUserId, $module_title, $moduleType);
        $stmt_check_history->execute();
        $result_check_history = $stmt_check_history->get_result();
        if ($result_check_history->num_rows == 0) {
            $stmt_insert_history = $conn->prepare("INSERT INTO modules_completed (user_id, module_name, completion_date, points_earned, status, module_type) VALUES (?, ?, CURRENT_TIMESTAMP, ?, 'Selesai', ?)");
            if (!$stmt_insert_history) {
                throw new Exception("Failed to prepare insert_history statement for modules_completed: " . $conn->error);
            }
            $stmt_insert_history->bind_param("isis", $loggedInUserId, $module_title, $module_points, $moduleType);
            if (!$stmt_insert_history->execute()) {
                throw new Exception("Failed to execute insert_history statement for modules_completed: " . $stmt_insert_history->error);
            }
            $stmt_insert_history->close();
        }
        $stmt_check_history->close();

        // Update user's total points in 'users' table
        $stmt_update_user_points = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
        if (!$stmt_update_user_points) {
            throw new Exception("Failed to prepare statement for points update: " . $conn->error);
        }
        $stmt_update_user_points->bind_param("ii", $module_points, $loggedInUserId);
        if (!$stmt_update_user_points->execute()) {
            throw new Exception("Failed to execute statement for points update: " . $stmt_update_user_points->error);
        }
        $stmt_update_user_points->close();

        // Add entry to points_history
        $points_history_description = "Penyelesaian modul: " . $module_title . " (Tipe: " . $moduleType . ")";
        $stmt_add_to_points_history = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
        if (!$stmt_add_to_points_history) {
            throw new Exception("Failed to prepare insert statement for points_history: " . $conn->error);
        }
        $stmt_add_to_points_history->bind_param("isi", $loggedInUserId, $points_history_description, $module_points);
        if (!$stmt_add_to_points_history->execute()) {
            throw new Exception("Failed to execute insert statement for points_history: " . $stmt_add_to_points_history->error);
        }
        $stmt_add_to_points_history->close();

        // Set points awarded for this request
        $points_awarded_in_this_request = $module_points;

        // Update session total points
        $_SESSION['total_points'] = ($_SESSION['total_points'] ?? 0) + $module_points;

    } else {
        error_log("DEBUG: Modul '{$module_title}' (ID: {$moduleId}, Tipe: {$moduleType}) sudah diselesaikan sebelumnya oleh user {$loggedInUserId}. Tidak ada poin tambahan.");
    }

    $conn->commit();

    // Fetch updated user points for immediate display (this is fine)
    $updatedPoints = 0;
    $stmt = $conn->prepare("SELECT total_points FROM users WHERE id = ?");
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $updatedPoints = $row['total_points'];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => $already_completed ? 'Modul ini sudah selesai.' : 'Modul berhasil diselesaikan dan poin diberikan!',
        'newPoints' => $updatedPoints, // Send the user's new total points
        'pointsAwarded' => $points_awarded_in_this_request, // Send points awarded in THIS request
        'targetProgressModuleId' => $targetProgressModuleId,
        'moduleType' => $moduleType,
        'moduleId' => $moduleId, // Add module ID for localStorage key in JS
        'alreadyCompleted' => $already_completed // Flag for client-side to know if points were new
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Module completion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error completing module: ' . $e->getMessage()]);
}

$conn->close();
?>