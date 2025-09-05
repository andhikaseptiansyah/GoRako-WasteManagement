<?php
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// ... (require lainnya)
require_once 'db_connection.php';
require_once 'helpers.php';
$moduleProgressMapping = require 'module_mappings.php'; // Muat pemetaan
// ... (kode lainnya)


// Jika pengguna TIDAK login, arahkan mereka ke halaman login
if (!is_logged_in()) { // cite: 2
    redirect('login.php'); // Arahkan ke login.php jika belum login
}

$loggedInUserId = $_SESSION['user_id']; // cite: 2

// --- Definisi Mapping Modul ke Progress Modul ---
// Penting: Mapping ini HARUS KONSISTEN dengan yang digunakan di JavaScript modules.php
// Setiap ID modul (dari DB) harus memiliki target progress modul ID yang unik (1-10)
// Jika ada modul DB yang tidak dipetakan ke progress, itu tidak akan mempengaruhi progress bar.


// --- LOGIKA PENANGANAN AJAX (COMPLETE, LIKE/DISLIKE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { // cite: 2
    header('Content-Type: application/json'); // Set header untuk respons JSON
    $response = ['status' => 'error', 'message' => 'Invalid action']; // cite: 2

    // Common parameters for all actions
    $module_id = filter_var($_POST['module_id'] ?? null, FILTER_SANITIZE_NUMBER_INT); // cite: 2
    $module_type = filter_var($_POST['module_type'] ?? null, FILTER_SANITIZE_STRING); // cite: 2

    if ($module_id === null || $module_id <= 0 || !in_array($module_type, ['research', 'video'])) { // cite: 2
        $response = ['status' => 'error', 'message' => 'Missing or invalid module ID or type.']; // cite: 2
        echo json_encode($response); // cite: 2
        exit(); // cite: 2
    }

    // Get module details (points_reward, title) from the correct table
    $module_points = 0; // cite: 2
    $module_title = ''; // cite: 2
    $module_table = ($module_type === 'research') ? 'modules_research' : 'modules_video'; // cite: 2

    $stmt_get_details = $conn->prepare("SELECT points_reward, title FROM " . $module_table . " WHERE id = ? AND is_active = 1"); // cite: 2
    if ($stmt_get_details) { // cite: 2
        $stmt_get_details->bind_param("i", $module_id); // cite: 2
        if (!$stmt_get_details->execute()) { // cite: 2
             error_log("Failed to execute get_details statement: " . $stmt_get_details->error); // cite: 2
             $response = ['status' => 'error', 'message' => 'Database error during module details retrieval execution.']; // cite: 2
             echo json_encode($response); // cite: 2
             exit(); // cite: 2
        }
        $result_details = $stmt_get_details->get_result(); // cite: 2
        if ($row_details = $result_details->fetch_assoc()) { // cite: 2
            $module_points = $row_details['points_reward']; // cite: 2
            $module_title = $row_details['title']; // cite: 2
        } else {
             $response = ['status' => 'error', 'message' => 'Module not found or inactive.']; // cite: 2
             echo json_encode($response); // cite: 2
             exit(); // cite: 2
        }
        $stmt_get_details->close(); // cite: 2
    } else {
        error_log("Failed to prepare get_details query: " . $conn->error); // cite: 2
        $response = ['status' => 'error', 'message' => 'Database error during module details retrieval.']; // cite: 2
        echo json_encode($response); // cite: 2
        exit(); // cite: 2
    }

    if ($_POST['action'] === 'complete_module') { // cite: 2
        $target_progress_module_id = filter_var($_POST['target_progress_module_id'] ?? null, FILTER_SANITIZE_NUMBER_INT); // cite: 2

        // DEBUGGING: Tambahkan log untuk melihat nilai target_progress_module_id yang diterima
        error_log("DEBUG PHP module-detail (AJAX): POST target_progress_module_id received: " . ($_POST['target_progress_module_id'] ?? 'not set'));
        error_log("DEBUG PHP module-detail (AJAX): Filtered target_progress_module_id: " . $target_progress_module_id);
        error_log("DEBUG PHP module-detail (AJAX): module_type: " . $module_type . ", module_id: " . $module_id);
        error_log("DEBUG PHP module-detail (AJAX): Mapping check result (isset): " . (isset($moduleProgressMapping[$module_type][$module_id]) ? 'true' : 'false'));
        if (isset($moduleProgressMapping[$module_type][$module_id])) { // cite: 2
            error_log("DEBUG PHP module-detail (AJAX): Mapped target_progress_module_id from internal array: " . $moduleProgressMapping[$module_type][$module_id]);
        }


        // Fallback to internal mapping if targetProgressModuleId is not provided or invalid in POST
        // Also ensure the received module_id actually has a mapping in the internal array
        if (!$target_progress_module_id || !isset($moduleProgressMapping[$module_type][$module_id]) || $moduleProgressMapping[$module_type][$module_id] !== (int)$target_progress_module_id) { // cite: 2
            $target_progress_module_id_fallback = $moduleProgressMapping[$module_type][$module_id] ?? null; // cite: 2
            error_log("DEBUG PHP module-detail (AJAX): Falling back for targetProgressModuleId. Fallback value: " . ($target_progress_module_id_fallback ?? 'null'));
            $target_progress_module_id = $target_progress_module_id_fallback; // cite: 2
        }

        if (!$target_progress_module_id || $target_progress_module_id <= 0) { // cite: 2
            error_log("ERROR: Target progress module ID could not be determined. Module ID: " . ($module_id ?? 'N/A') . ", Type: " . ($module_type ?? 'N/A') . ", Received Target ID: " . ($_POST['target_progress_module_id'] ?? 'N/A') . ", Mapped ID (fallback attempt): " . ($moduleProgressMapping[$module_type][$module_id] ?? 'N/A'));
            $response = ['status' => 'error', 'message' => 'Target progress module ID could not be determined.']; // This is the error message you're seeing
            echo json_encode($response); // cite: 2
            exit(); // cite: 2
        }

        $conn->begin_transaction(); // cite: 2
        try {
            // 1. Check if the module is already completed by the user
            $stmt_check_existing_progress = $conn->prepare("SELECT is_completed, points_earned FROM user_module_progress WHERE user_id = ? AND module_type = ? AND module_id = ?"); // cite: 2
            if (!$stmt_check_existing_progress) { // cite: 2
                throw new Exception("Failed to prepare check_existing_progress statement: " . $conn->error); // cite: 2
            }
            $stmt_check_existing_progress->bind_param("isi", $loggedInUserId, $module_type, $module_id); // cite: 2
            $stmt_check_existing_progress->execute(); // cite: 2
            $result_existing_progress = $stmt_check_existing_progress->get_result(); // cite: 2
            $existing_progress = $result_existing_progress->fetch_assoc(); // cite: 2
            $stmt_check_existing_progress->close(); // cite: 2

            $already_completed = ($existing_progress && $existing_progress['is_completed'] == 1); // cite: 2
            $points_awarded_in_this_request = 0; // Initialize points to be awarded in this specific request

            if (!$already_completed) { // cite: 2
                // Module not completed yet, proceed with adding points and recording
                // 1. Record user progress in user_module_progress
                $stmt_progress = $conn->prepare("INSERT INTO user_module_progress (user_id, module_type, module_id, is_completed, points_earned, completed_at) VALUES (?, ?, ?, 1, ?, CURRENT_TIMESTAMP)"); // cite: 2
                if (!$stmt_progress) { // cite: 2
                    throw new Exception("Failed to prepare user_module_progress statement: " . $conn->error); // cite: 2
                }
                $stmt_progress->bind_param("isii", $loggedInUserId, $module_type, $module_id, $module_points); // cite: 2
                if (!$stmt_progress->execute()) { // cite: 2
                    throw new Exception("Failed to execute user_module_progress statement: " . $stmt_progress->error); // cite: 2
                }
                $stmt_progress->close(); // cite: 2

                // 2. Add entry to modules_completed (for history in profile)
                // Check if module is already in modules_completed to prevent duplicate history entries
                $stmt_check_history = $conn->prepare("SELECT id FROM modules_completed WHERE user_id = ? AND module_name = ? AND module_type = ?"); // cite: 2
                if (!$stmt_check_history) { // cite: 2
                    throw new Exception("Failed to prepare check_history statement for modules_completed: " . $conn->error); // cite: 2
                }
                $stmt_check_history->bind_param("iss", $loggedInUserId, $module_title, $module_type); // cite: 2
                if (!$stmt_check_history->execute()) { // cite: 2
                    throw new Exception("Failed to execute check_history statement for modules_completed: " . $stmt_check_history->error); // cite: 2
                }
                $result_check_history = $stmt_check_history->get_result(); // cite: 2

                if ($result_check_history->num_rows == 0) { // If not in history, insert it
                    $stmt_insert_history = $conn->prepare("INSERT INTO modules_completed (user_id, module_name, completion_date, points_earned, status, module_type) VALUES (?, ?, CURRENT_TIMESTAMP, ?, 'Selesai', ?)"); // cite: 2
                    if (!$stmt_insert_history) { // cite: 2
                        throw new Exception("Failed to prepare insert_history statement for modules_completed: " . $conn->error); // cite: 2
                    }
                    $stmt_insert_history->bind_param("isis", $loggedInUserId, $module_title, $module_points, $module_type); // cite: 2
                    if (!$stmt_insert_history->execute()) { // cite: 2
                        throw new Exception("Failed to execute insert_history statement for modules_completed: " . $stmt_insert_history->error); // cite: 2
                    }
                    $stmt_insert_history->close(); // cite: 2
                } else {
                    error_log("DEBUG: Modul '{$module_title}' (ID: {$module_id}, Tipe: {$module_type}) sudah ada di riwayat modules_completed untuk user {$loggedInUserId}."); // cite: 2
                }
                $stmt_check_history->close(); // cite: 2


                // 3. Add points to user's total points in 'users' table
                $stmt_update_user_points = $conn->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?"); // cite: 2
                if (!$stmt_update_user_points) { // cite: 2
                    throw new Exception("Failed to prepare update_user_points statement: " . $conn->error); // cite: 2
                }
                $stmt_update_user_points->bind_param("ii", $module_points, $loggedInUserId); // cite: 2
                if (!$stmt_update_user_points->execute()) { // cite: 2
                    throw new Exception("Failed to execute update_user_points statement: " . $stmt_update_user_points->error); // cite: 2
                }
                $stmt_update_user_points->close(); // cite: 2

                // Update total points in session
                $_SESSION['total_points'] = ($_SESSION['total_points'] ?? 0) + $module_points; // cite: 2
                $points_awarded_in_this_request = $module_points; // Set points actually awarded

                // START MODIFIKASI: Tambahkan entri ke points_history untuk modul yang selesai
                $points_history_description = "Penyelesaian modul: " . $module_title . " (Tipe: " . $module_type . ")"; // cite: 2
                $stmt_add_to_points_history = $conn->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, CURRENT_TIMESTAMP)"); // cite: 2
                if (!$stmt_add_to_points_history) { // cite: 2
                    throw new Exception("Failed to prepare insert statement for points_history: " . $conn->error); // cite: 2
                }
                $stmt_add_to_points_history->bind_param("isi", $loggedInUserId, $points_history_description, $module_points); // cite: 2
                if (!$stmt_add_to_points_history->execute()) { // cite: 2
                    throw new Exception("Failed to execute insert statement for points_history: " . $stmt_add_to_points_history->error); // cite: 2
                }
                $stmt_add_to_points_history->close(); // cite: 2
                // END MODIFIKASI

            } else {
                // Module already completed, no points added
                error_log("DEBUG: Modul '{$module_title}' (ID: {$module_id}, Tipe: {$module_type}) sudah diselesaikan sebelumnya oleh user {$loggedInUserId}. Tidak ada poin tambahan."); // cite: 2
            }

            $conn->commit(); // cite: 2
            $response = [ // cite: 2
                'status' => 'success', // cite: 2
                'message' => $already_completed ? 'Modul ini sudah selesai.' : 'Modul berhasil diselesaikan!', // cite: 2
                'points_earned' => $points_awarded_in_this_request, // Kirim poin yang BARU didapatkan
                'target_progress_module_id' => $target_progress_module_id, // cite: 2
                'module_type' => $module_type, // cite: 2
                'module_id' => $module_id, // Untuk referensi di JS
                'new_total_points_session' => $_SESSION['total_points'] ?? 0, // Kirim total poin baru dari sesi
                'already_completed' => $already_completed // Flag ini penting untuk feedback di frontend
            ];

        } catch (Exception $e) { // cite: 2
            $conn->rollback(); // cite: 2
            $response = ['status' => 'error', 'message' => 'Gagal menyelesaikan modul: ' . $e->getMessage()]; // cite: 2
            error_log("Complete module error for user {$loggedInUserId}, module {$module_id} ({$module_type}): " . $e->getMessage()); // cite: 2
        }
    } elseif ($_POST['action'] === 'submit_feedback') { // cite: 2
        // ... (Kode feedback yang sudah ada, tidak berubah)
        $feedback_type = filter_var($_POST['feedback_type'] ?? null, FILTER_SANITIZE_STRING); // 'like' or 'dislike'
        $old_feedback_type = filter_var($_POST['old_feedback_type'] ?? null, FILTER_SANITIZE_STRING); // Previous feedback, if any

        if (!in_array($feedback_type, ['like', 'dislike']) && !empty($feedback_type)) { // empty string is allowed for old_feedback_type
            $response = ['status' => 'error', 'message' => 'Invalid feedback type.']; // cite: 2
            echo json_encode($response); // cite: 2
            exit(); // cite: 2
        }
         if (!in_array($old_feedback_type, ['like', 'dislike', '']) && !is_null($old_feedback_type)) { // cite: 2
            $response = ['status' => 'error', 'message' => 'Invalid old feedback type.']; // cite: 2
            echo json_encode($response); // cite: 2
            exit(); // cite: 2
        }

        $conn->begin_transaction(); // cite: 2
        try {
            $like_change = 0; // cite: 2
            $dislike_change = 0; // cite: 2
            $final_user_feedback_state = null; // Default to no feedback from user

            // Determine changes based on old and new feedback
            if ($feedback_type === $old_feedback_type) { // User clicked same button again (unliking/undisliking)
                if ($old_feedback_type === 'like') { // cite: 2
                    $like_change = -1; // cite: 2
                } elseif ($old_feedback_type === 'dislike') { // cite: 2
                    $dislike_change = -1; // cite: 2
                }
                // Delete user's feedback record
                $stmt_user_feedback_delete = $conn->prepare("DELETE FROM user_module_feedback WHERE user_id = ? AND module_type = ? AND module_id = ?"); // cite: 2
                if (!$stmt_user_feedback_delete) { // cite: 2
                     throw new Exception("Failed to prepare delete user_module_feedback: " . $conn->error); // cite: 2
                }
                $stmt_user_feedback_delete->bind_param("isi", $loggedInUserId, $module_type, $module_id); // cite: 2
                if (!$stmt_user_feedback_delete->execute()) { // cite: 2
                     throw new Exception("Failed to execute delete user_module_feedback: " . $stmt_user_feedback_delete->error); // cite: 2
                }
                $stmt_user_feedback_delete->close(); // cite: 2
                $final_user_feedback_state = null; // No feedback after delete

            } else { // User is liking, disliking, or changing from like to dislike/dislike to like
                // If there was an old feedback, revert its count
                if ($old_feedback_type === 'like') { // cite: 2
                    $like_change = -1; // cite: 2
                } elseif ($old_feedback_type === 'dislike') { // cite: 2
                    $dislike_change = -1; // cite: 2
                }

                // Apply new feedback count
                if ($feedback_type === 'like') { // cite: 2
                    $like_change += 1; // cite: 2
                } elseif ($feedback_type === 'dislike') { // cite: 2
                    $dislike_change += 1; // cite: 2
                }

                // Insert or update user's feedback
                $stmt_user_feedback = $conn->prepare("INSERT INTO user_module_feedback (user_id, module_type, module_id, feedback_type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE feedback_type = VALUES(feedback_type)"); // cite: 2
                if (!$stmt_user_feedback) { // cite: 2
                    throw new Exception("Failed to prepare user_module_feedback statement: " . $conn->error); // cite: 2
                }
                $stmt_user_feedback->bind_param("isis", $loggedInUserId, $module_type, $module_id, $feedback_type); // cite: 2
                if (!$stmt_user_feedback->execute()) { // cite: 2
                    throw new Exception("Failed to execute user_module_feedback statement: " . $stmt_user_feedback->error); // cite: 2
                }
                $stmt_user_feedback->close(); // cite: 2
                $final_user_feedback_state = $feedback_type; // cite: 2
            }

            // Update module_feedback table with net changes (uses ON DUPLICATE KEY UPDATE for first entry)
            $stmt_feedback_module = $conn->prepare("INSERT INTO module_feedback (module_type, module_id, likes_count, dislikes_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE likes_count = likes_count + ?, dislikes_count = dislikes_count + ?"); // cite: 2
            if (!$stmt_feedback_module) { // cite: 2
                throw new Exception("Failed to prepare module_feedback statement: " . $conn->error); // cite: 2
            }
            $stmt_feedback_module->bind_param("siiiii", $module_type, $module_id, $like_change, $dislike_change, $like_change, $dislike_change); // cite: 2
            if (!$stmt_feedback_module->execute()) { // cite: 2
                throw new Exception("Failed to execute module_feedback statement: " . $stmt_feedback_module->error); // cite: 2
            }
            $stmt_feedback_module->close(); // cite: 2

            $conn->commit(); // cite: 2

            // Fetch updated counts for response
            $likes_count = 0; // cite: 2
            $dislikes_count = 0; // cite: 2
            $stmt_get_counts = $conn->prepare("SELECT likes_count, dislikes_count FROM module_feedback WHERE module_type = ? AND module_id = ?"); // cite: 2
            if (!$stmt_get_counts) { // cite: 2
                throw new Exception("Failed to prepare get_counts statement: " . $conn->error); // cite: 2
            }
            $stmt_get_counts->bind_param("si", $module_type, $module_id); // cite: 2
            $stmt_get_counts->execute(); // cite: 2
            $result_counts = $stmt_get_counts->get_result(); // cite: 2
            if ($row_counts = $result_counts->fetch_assoc()) { // cite: 2
                $likes_count = $row_counts['likes_count']; // cite: 2
                $dislikes_count = $row_counts['dislikes_count']; // cite: 2
            }
            $stmt_get_counts->close(); // cite: 2

            $response = [ // cite: 2
                'status' => 'success', // cite: 2
                'message' => 'Feedback submitted', // cite: 2
                'likes' => max(0, $likes_count), // Ensure counts don't go below zero
                'dislikes' => max(0, $dislikes_count), // Ensure counts don't go below zero
                'user_feedback' => $final_user_feedback_state // 'like', 'dislike', or null
            ];

        } catch (Exception $e) { // cite: 2
            $conn->rollback(); // cite: 2
            $response = ['status' => 'error', 'message' => 'Gagal memberikan feedback: ' . $e->getMessage()]; // cite: 2
            error_log("Submit feedback error: " . $e->getMessage()); // cite: 2
        }
    } else {
         $response = ['status' => 'error', 'message' => 'Invalid or unknown action.']; // cite: 2
    }

    echo json_encode($response); // cite: 2
    exit(); // cite: 2
}

// --- LOGIKA PENGAMBILAN DATA UNTUK TAMPILAN HALAMAN ---

$moduleId = filter_var($_GET['id'] ?? null, FILTER_SANITIZE_NUMBER_INT); // cite: 2
$moduleType = filter_var($_GET['type'] ?? null, FILTER_SANITIZE_STRING); // 'research' or 'video'

// DEBUGGING: Log nilai yang diterima via GET
error_log("DEBUG PHP module-detail (GET): module_id: " . ($moduleId ?? 'not set') . ", module_type: " . ($moduleType ?? 'not set') . ", targetProgressModuleId: " . ($_GET['targetProgressModuleId'] ?? 'not set'));

// Retrieve the targetProgressModuleId from the URL (passed from modules.php)
$targetProgressModuleId = filter_var($_GET['targetProgressModuleId'] ?? null, FILTER_SANITIZE_NUMBER_INT); // cite: 2

// Fallback to internal mapping if targetProgressModuleId is not provided or invalid in GET
if (!$targetProgressModuleId || !isset($moduleProgressMapping[$moduleType][$moduleId])) { // cite: 2
    $targetProgressModuleId_fallback = $moduleProgressMapping[$moduleType][$moduleId] ?? null; // cite: 2
    error_log("DEBUG PHP module-detail (GET): Falling back for targetProgressModuleId. Fallback value: " . ($targetProgressModuleId_fallback ?? 'null'));
    $targetProgressModuleId = $target_progress_module_id_fallback; // cite: 2
}

// Critical check for the targetProgressModuleId
if ($targetProgressModuleId === null || $targetProgressModuleId <= 0) {
    // This condition will cause the content to be generic error message.
    // The specific error message "Target progress module ID could not be determined." is sent via AJAX POST.
    // For initial page load, we just render a generic error content.
    $moduleData = null; // cite: 2
    $contentHtml = '<p>Modul tidak ditemukan. Pastikan ID dan tipe modul benar dan valid. (Kesalahan ID Progres)</p>'; // cite: 2
}


$moduleData = null; // cite: 2
$contentHtml = '<p>Konten modul tidak tersedia.</p>'; // Default content if not found
$isCompleted = false; // cite: 2
$feedbackCounts = ['likes_count' => 0, 'dislikes_count' => 0]; // cite: 2
$userFeedbackType = null; // 'like' or 'dislike' or null

// Validate module ID and type early
if ($moduleId === null || $moduleId <= 0 || !in_array($moduleType, ['research', 'video'])) { // cite: 2
    $contentHtml = '<p>Modul tidak ditemukan. Pastikan ID dan tipe modul benar dan valid.</p>'; // cite: 2
} else {
    if ($moduleType === 'research') { // Research module
        $stmt = $conn->prepare("SELECT title, description, content_type, content_url, text_content, estimated_minutes, points_reward FROM modules_research WHERE id = ? AND is_active = 1"); // cite: 2
        if ($stmt) { // cite: 2
            $stmt->bind_param("i", $moduleId); // cite: 2
            $stmt->execute(); // cite: 2
            $result = $stmt->get_result(); // cite: 2
            $moduleData = $result->fetch_assoc(); // cite: 2
            $stmt->close(); // cite: 2

            if ($moduleData) { // cite: 2
                // Generate content based on content_type
                switch ($moduleData['content_type']) { // cite: 2
                    case 'text': // cite: 2
                        $contentHtml = '<p>' . nl2br(htmlspecialchars($moduleData['description'])) . '</p>'; // cite: 2
                        if (!empty($moduleData['text_content'])) { // cite: 2
                            // Sanitasi dengan htmlspecialchars dan nl2br
                            $contentHtml .= '<h3>Isi Lengkap Modul:</h3><div class="module-text-content">' . nl2br(htmlspecialchars($moduleData['text_content'])) . '</div>'; // cite: 2
                        } else {
                            $contentHtml .= '<p>Isi teks modul tidak tersedia.</p>'; // cite: 2
                        }
                        break;
                    case 'url': // cite: 2
                        $contentHtml = '<p>' . nl2br(htmlspecialchars($moduleData['description'])) . '</p>'; // cite: 2
                        if (!empty($moduleData['content_url']) && filter_var($moduleData['content_url'], FILTER_VALIDATE_URL)) { // cite: 2
                            // Gunakan FILTER_VALIDATE_URL untuk keamanan ekstra
                            $safe_url = htmlspecialchars($moduleData['content_url']); // cite: 2
                            $contentHtml .= '<p>Baca selengkapnya di: <a href="' . $safe_url . '" target="_blank" rel="noopener noreferrer">' . $safe_url . '</a></p>'; // cite: 2
                        } else {
                            $contentHtml .= '<p>URL konten tidak tersedia atau tidak valid.</p>'; // cite: 2
                        }
                        break;
                    case 'pdf': // cite: 2
                        $contentHtml = '<p>' . nl2br(htmlspecialchars($moduleData['description'])) . '</p>'; // cite: 2
                        if (!empty($moduleData['content_url']) && file_exists($moduleData['content_url'])) { // cite: 2
                            $safe_pdf_url = htmlspecialchars($moduleData['content_url']); // cite: 2
                            $contentHtml .= '<p>Unduh file PDF: <a href="' . $safe_pdf_url . '" target="_blank" rel="noopener noreferrer">Lihat PDF</a></p>'; // cite: 2
                            // Parameter iframe untuk tampilan yang bersih
                            $pdf_url_for_iframe = $safe_pdf_url . '#toolbar=0&navpanes=0&scrollbar=0&view=fitH'; // cite: 2
                            $contentHtml .= '<div class="pdf-viewer-wrapper"><iframe src="' . $pdf_url_for_iframe . '" style="width:100%; height:600px;" frameborder="0" allowfullscreen></iframe></div>'; // cite: 2
                        } else {
                            $contentHtml .= '<p>File PDF tidak tersedia atau tidak ditemukan di server.</p>'; // cite: 2
                        }
                        break;
                    default:
                        $contentHtml = '<p>Tipe konten tidak dikenal atau tidak tersedia.</p>'; // cite: 2
                }
            } else {
                $contentHtml = '<p>Modul riset tidak ditemukan atau tidak aktif.</p>'; // cite: 2
            }
        } else {
            error_log("Failed to prepare research module query: " . $conn->error); // cite: 2
            $contentHtml = '<p>Terjadi kesalahan saat memuat modul riset dari database.</p>'; // cite: 2
        }
    } elseif ($moduleType === 'video') { // cite: 2
        $stmt = $conn->prepare("SELECT title, description, video_type, video_url, duration_minutes, points_reward, thumbnail_url FROM modules_video WHERE id = ? AND is_active = 1"); // cite: 2
        if ($stmt) { // cite: 2
            $stmt->bind_param("i", $moduleId); // cite: 2
            $stmt->execute(); // cite: 2
            $result = $stmt->get_result(); // cite: 2
            $moduleData = $result->fetch_assoc(); // cite: 2
            $stmt->close(); // cite: 2

            if ($moduleData) { // cite: 2
                $video_src = ''; // cite: 2
                if ($moduleData['video_type'] === 'youtube' && !empty($moduleData['video_url'])) { // cite: 2
                    $youtube_id = ''; // cite: 2
                    // Regex yang lebih kuat untuk mengekstrak ID YouTube
                    $pattern = '/(?:https?:\/\/)?(?:www\.)?(?:m\.)?(?:youtube\.com|youtu\.be)\/(?:watch\?v=|embed\/|v\/|)([a-zA-Z0-9_-]{11})(?:\S+)?/'; // cite: 2
                    if (preg_match($pattern, $moduleData['video_url'], $matches)) { // cite: 2
                        $youtube_id = htmlspecialchars($matches[1]); // cite: 2
                    }
                    if ($youtube_id) { // cite: 2
                        $video_src = "https://www.youtube.com/embed/" . $youtube_id . "?rel=0&autoplay=0"; // Mengganti ke format embed yang umum
                    }
                } elseif ($moduleData['video_type'] === 'vimeo' && !empty($moduleData['video_url'])) { // cite: 2
                    $vimeo_id = ''; // cite: 2
                    $pattern = '/(?:https?:\/\/)?(?:www\.)?(?:player\.)?(?:vimeo\.com)\/(?:video\/|channels\/\w+\/|groups\/\w+\/videos\/|album\/\d+\/video\/|)\/?(\d+)(?:[?&].*)?/'; // cite: 2
                    if (preg_match($pattern, $moduleData['video_url'], $matches)) { // cite: 2
                        $vimeo_id = htmlspecialchars($matches[1]); // cite: 2
                    }
                    if ($vimeo_id) { // cite: 2
                        $video_src = "https://player.vimeo.com/video/" . $vimeo_id; // cite: 2
                    }
                } elseif ($moduleData['video_type'] === 'upload' && !empty($moduleData['video_url']) && file_exists($moduleData['video_url'])) { // cite: 2
                    $video_src = htmlspecialchars($moduleData['video_url']); // cite: 2
                }

                if ($video_src) { // cite: 2
                    $contentHtml = '<p>' . nl2br(htmlspecialchars($moduleData['description'])) . '</p>'; // cite: 2
                    $contentHtml .= '<div class="video-wrapper">'; // cite: 2
                    if ($moduleData['video_type'] === 'upload') { // cite: 2
                        $mime_type = 'video/mp4'; // Default, idealnya deteksi MIME yang lebih akurat
                        if (function_exists('mime_content_type') && file_exists($moduleData['video_url'])) { // cite: 2
                            $mime_type = mime_content_type($moduleData['video_url']); // cite: 2
                        } else {
                            $ext = strtolower(pathinfo($moduleData['video_url'], PATHINFO_EXTENSION)); // cite: 2
                            if ($ext === 'webm') $mime_type = 'video/webm'; // cite: 2
                            else if ($ext === 'ogg') $mime_type = 'video/ogg'; // cite: 2
                        }
                        $contentHtml .= '<video controls loading="lazy" style="width:100%;height:100%;"><source src="' . $video_src . '" type="' . htmlspecialchars($mime_type) . '">Browser Anda tidak mendukung tag video.</video>'; // cite: 2
                    } else {
                        $contentHtml .= '<iframe src="' . $video_src . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>'; // cite: 2
                    }
                    $contentHtml .= '</div>'; // cite: 2
                } else {
                    $contentHtml = '<p>Video tidak dapat dimuat atau URL/file tidak valid. Mohon hubungi administrator.</p>'; // cite: 2
                }
            } else {
                $contentHtml = '<p>Modul video tidak ditemukan atau tidak aktif.</p>'; // cite: 2
            }
        } else {
            error_log("Failed to prepare video module query: " . $conn->error); // cite: 2
            $contentHtml = '<p>Terjadi kesalahan saat memuat modul video dari database.</p>'; // cite: 2
        }
    }
}

// Get completion status (only if moduleData is found)
if ($moduleData) { // cite: 2
    $stmt_check_completed = $conn->prepare("SELECT is_completed FROM user_module_progress WHERE user_id = ? AND module_type = ? AND module_id = ?"); // cite: 2
    if ($stmt_check_completed) { // cite: 2
        $stmt_check_completed->bind_param("isi", $loggedInUserId, $moduleType, $moduleId); // cite: 2
        $stmt_check_completed->execute(); // cite: 2
        $result_completed = $stmt_check_completed->get_result(); // cite: 2
        if ($result_completed->num_rows > 0 && $result_completed->fetch_assoc()['is_completed'] == 1) { // cite: 2
            $isCompleted = true; // cite: 2
        }
        $stmt_check_completed->close(); // cite: 2
    }
}

// Get feedback counts (only if moduleData is found)
if ($moduleData) { // cite: 2
    $stmt_get_feedback_counts = $conn->prepare("SELECT likes_count, dislikes_count FROM module_feedback WHERE module_type = ? AND module_id = ?"); // cite: 2
    if ($stmt_get_feedback_counts) { // cite: 2
        $stmt_get_feedback_counts->bind_param("si", $moduleType, $moduleId); // cite: 2
        $stmt_get_feedback_counts->execute(); // cite: 2
        $result_feedback_counts = $stmt_get_feedback_counts->get_result(); // cite: 2
        if ($row_feedback = $result_feedback_counts->fetch_assoc()) { // cite: 2
            $feedbackCounts = $row_feedback; // cite: 2
        }
        $stmt_get_feedback_counts->close(); // cite: 2
    }

    // Get user's specific feedback (only if moduleData is found)
    $stmt_get_user_feedback = $conn->prepare("SELECT feedback_type FROM user_module_feedback WHERE user_id = ? AND module_type = ? AND module_id = ?"); // cite: 2
    if ($stmt_get_user_feedback) { // cite: 2
        $stmt_get_user_feedback->bind_param("isi", $loggedInUserId, $moduleType, $moduleId); // cite: 2
        $stmt_get_user_feedback->execute(); // cite: 2
        $result_user_feedback = $stmt_get_user_feedback->get_result(); // cite: 2
        if ($row_user_feedback = $result_user_feedback->fetch_assoc()) { // cite: 2
            $userFeedbackType = $row_user_feedback['feedback_type']; // cite: 2
        }
        $stmt_get_user_feedback->close(); // cite: 2
    }
}

// --- LOGIKA PENGAMBILAN DATA UNTUK MODUL TERBARU LAINNYA ---
$recentModules = [];
try {
    // Fetch recent modules from both research and video tables
    // Using UNION ALL to combine results from two tables
    $stmt_recent_modules = $conn->prepare("
        (SELECT id, title, description, estimated_minutes AS duration_minutes, points_reward, 'research' AS module_type, created_at FROM modules_research WHERE is_active = 1)
        UNION ALL
        (SELECT id, title, description, duration_minutes, points_reward, 'video' AS module_type, created_at FROM modules_video WHERE is_active = 1)
        ORDER BY created_at DESC
        LIMIT 4
    "); // Increased limit to ensure 3 are displayed even if one is the current module
    if ($stmt_recent_modules) {
        $stmt_recent_modules->execute();
        $result_recent_modules = $stmt_recent_modules->get_result();
        while ($row = $result_recent_modules->fetch_assoc()) {
            // Exclude the current module from the "other recent modules" list
            if (!($row['id'] == $moduleId && $row['module_type'] == $moduleType) && count($recentModules) < 3) {
                $recentModules[] = $row;
            }
        }
        $stmt_recent_modules->close();
    } else {
        error_log("Failed to prepare recent modules query: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Error fetching recent modules for sidebar: " . $e->getMessage());
}


// Tutup koneksi database di akhir skrip
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="detail-module-title"><?php echo htmlspecialchars($moduleData['title'] ?? 'Detail Modul'); ?> - GoRako</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS Variables */
        :root {
            --primary-color: #4CAF50; /* GoRako Green */
            --primary-dark-color: #388E3C;
            --secondary-color: #8BC34A; /* Lighter green accent */
            --text-dark: #212121;
            --text-medium: #424242; /* Slightly darker than 616161 for better contrast */
            --text-light: #757575;
            --link-color: #2196F3;
            --link-hover-color: #1976D2;
            --background-light: #f5f5f5;
            --card-background: white;
            --shadow-light: rgba(0, 0, 0, 0.08); /* Softer shadow */
            --shadow-medium: rgba(0, 0, 0, 0.12);
            --border-light: #e0e0e0;
            --red-color: #F44336;
            --blue-color: #2196F3;
            --active-border-color: var(--primary-color); /* Color for active sidebar item */
            --skeleton-color: #e0e0e0; /* Color for skeleton loader */
            --skeleton-highlight: #f0f0f0; /* Highlight for skeleton animation */

            /* New Variables for Recent Modules Card (now mostly white) */
            --recent-module-bg: white; /* White background for recent module cards */
            --recent-module-text-color: var(--text-dark); /* Dark text for recent module cards */
            --recent-module-border: #e0e0e0; /* Subtle border for recent module cards */
            --recent-module-shadow: rgba(0, 0, 0, 0.05); /* Lighter shadow for white cards */
            --recent-module-hover-bg: #f5f5f5; /* Light grey on hover */
        }

        /* Base & Reset Styles */
        html {
            scroll-behavior: smooth; /* Smooth scrolling for internal links */
        }
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--background-light);
            color: var(--text-medium); /* Default text color */
            line-height: 1.7; /* Slightly increased line height for readability */
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility; /* Enhance text rendering */
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
        h1, h2, h3, h4, h5, h6 {
            color: var(--text-dark);
            font-weight: 700;
            margin-top: 0;
            letter-spacing: -0.02em; /* Slight letter spacing for headings */
        }
        p {
            margin-bottom: 1em; /* Consistent paragraph spacing */
        }
        a {
            text-decoration: none;
            color: var(--link-color);
            transition: color 0.2s ease, transform 0.2s ease;
        }
        a:hover {
            color: var(--link-hover-color);
            transform: translateY(-1px); /* Subtle lift on hover */
        }
        a:focus, button:focus, input:focus, textarea:focus {
            outline: 2px solid var(--link-color);
            outline-offset: 3px;
            border-color: var(--primary-color); /* Highlight border on focus for inputs */
        }

        /* Breadcrumbs */
        .breadcrumbs {
            max-width: 1280px; /* Wider content area */
            margin: 20px auto 0; /* Adjusted margin for top of page */
            padding: 0 4%;
            font-size: 0.9em;
            color: var(--text-light);
        }
        .breadcrumbs a {
            color: var(--primary-color);
        }
        .breadcrumbs span {
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Main Layout (Grid) */
        .main-layout-wrapper {
            display: grid;
            grid-template-columns: 1fr; /* Single column after sidebar removal */
            gap: 30px; /* Increased space between grid items */
            max-width: 1280px;
            margin: 25px auto;
            padding: 0 4%;
            align-items: start;
        }

        /* Card Base Style */
        .card {
            background-color: var(--card-background);
            border-radius: 12px; /* Slightly more rounded corners */
            box-shadow: 0 6px 20px var(--shadow-light); /* More pronounced, softer shadow */
            padding: 30px; /* Increased padding */
        }

        /* Main Content Area */
        .main-content {
            grid-column: 1; /* Occupy the first column */
        }
        .main-content-card {
            margin-bottom: 25px;
        }
        .main-content h1 {
            font-size: 3em; /* Larger main title */
            margin-bottom: 25px;
            line-height: 1.2;
            color: var(--primary-dark-color); /* Main title more prominent green */
        }
        .module-content {
            font-size: 1.08em; /* Slightly larger base font */
            line-height: 1.75; /* Improved readability */
            color: var(--text-medium);
            transition: opacity 0.3s ease-out; /* Smooth transition for content loading */
        }
        .module-content.loading {
            opacity: 0; /* Hide content while loading */
        }
        .module-content img {
            max-width: 100%;
            height: auto;
            border-radius: 10px; /* Consistent with card radius */
            margin: 30px auto;
            display: block;
            box-shadow: 0 4px 15px var(--shadow-light); /* Softer shadow for images */
        }
        .module-content ul {
            list-style-type: disc;
            margin-left: 28px; /* Slightly more indent */
            padding: 0;
        }
        .module-content li {
            margin-bottom: 8px;
        }
        /* Style for rendered text content within a module */
        .module-text-content {
            background-color: #f9f9f9; /* Light background for text block */
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            margin-bottom: 20px;
            line-height: 1.6;
            color: var(--text-dark);
            white-space: pre-wrap; /* Preserve whitespace and line breaks */
            word-wrap: break-word; /* Break long words */
        }


        /* Skeleton Loader Styles */
        .skeleton-loader {
            display: block;
            background-color: var(--skeleton-color);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
            animation: pulse 1.5s infinite ease-in-out;
            margin-bottom: 10px;
        }
        .skeleton-loader.text-line {
            height: 1.2em;
            width: 100%;
        }
        .skeleton-loader.text-line.short {
            width: 70%;
        }
        .skeleton-loader.text-line.long {
            width: 90%;
        }
        .skeleton-loader.heading {
            height: 2.5em;
            width: 60%;
            margin-bottom: 20px;
        }
        .skeleton-loader.image {
            width: 100%;
            height: 200px; /* Or match expected image height */
            margin: 30px auto;
            border-radius: 10px;
        }
        .skeleton-wrapper {
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, var(--skeleton-highlight), transparent);
            animation: loading-wave 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.8; }
            50% { opacity: 1; }
            100% { opacity: 0.8; }
        }

        @keyframes loading-wave {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Module Info (Duration, Points) */
        .module-info {
            margin-top: 35px; /* More space from content */
            padding-top: 25px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            font-size: 0.95em; /* Slightly larger */
            color: var(--text-dark);
        }
        .module-info span {
            display: flex;
            align-items: center;
            gap: 10px; /* More space for icons */
            font-weight: 600;
        }
        .module-info svg {
            fill: var(--primary-color);
            width: 22px; /* Larger icons */
            height: 22px;
        }

        /* Completion Button */
        .completion-button-container {
            text-align: center;
            margin-top: 50px; /* More space from info section */
        }
        .btn-complete {
            background-color: var(--primary-color);
            color: white;
            padding: 18px 40px; /* Larger button */
            border-radius: 10px;
            font-weight: 700;
            font-size: 1.2em; /* Larger font */
            transition: background-color 0.3s ease, transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2); /* More prominent shadow for button */
            border: none;
            cursor: pointer;
            min-width: 280px;
        }
        .btn-complete:hover {
            background-color: var(--primary-dark-color);
            transform: translateY(-4px); /* More pronounced lift */
            box-shadow: 0 12px 25px rgba(0,0,0,0.25);
        }
        .btn-complete:active {
            transform: translateY(0); /* Press down effect */
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn-complete[aria-disabled="true"] {
            cursor: not-allowed;
            background-color: var(--primary-dark-color);
            opacity: 0.7;
            transform: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        /* Like/Dislike Section */
        .feedback-section {
            display: flex;
            gap: 20px; /* More space between buttons */
            margin-top: 40px;
            justify-content: center;
            padding-top: 25px;
            border-top: 1px solid var(--border-light);
        }
        .feedback-button {
            background-color: var(--background-light);
            border: 1px solid var(--border-light);
            color: var(--text-medium);
            padding: 12px 25px; /* Larger padding */
            border-radius: 30px; /* More pill-like */
            display: flex;
            align-items: center;
            gap: 10px; /* More space for icons */
            font-size: 1.05em;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
        }
        .feedback-button svg {
            fill: var(--text-medium);
            width: 22px; /* Larger icons */
            height: 22px;
            transition: fill 0.2s ease, transform 0.2s ease; /* Icon transition */
        }
        .feedback-button:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            box-shadow: 0 4px 12px var(--shadow-light);
        }
        .feedback-button:hover svg {
            fill: var(--primary-color);
            transform: scale(1.1); /* Slight grow effect on icon */
        }
        .feedback-button.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .feedback-button.active svg {
            fill: white;
        }
        .feedback-button.like:hover {
            border-color: var(--blue-color);
            color: var(--blue-color);
        }
        .feedback-button.like:hover svg {
            fill: var(--blue-color);
        }
        .feedback-button.dislike:hover {
            border-color: var(--red-color);
            color: var(--red-color);
        }
        .feedback-button.dislike:hover svg {
            fill: var(--red-color);
        }

        /* Video Wrapper */
        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            max-width: 100%;
            background: #000;
            margin-bottom: 30px;
            border-radius: 10px; /* Consistent with card radius */
            box-shadow: 0 4px 15px var(--shadow-light);
        }
        .video-wrapper iframe, .video-wrapper video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .pdf-viewer-wrapper {
             width: 100%;
             height: 600px; /* Set a default height, can be adjusted */
             margin-bottom: 30px;
             border-radius: 10px;
             overflow: hidden;
             box-shadow: 0 4px 15px var(--shadow-light);
        }
        .pdf-viewer-wrapper iframe {
            width: 100%;
            height: 100%;
            border: none;
        }


        /* Notification Toast */
        .toast-notification {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--primary-color);
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.5s ease-in-out, transform 0.3s ease-out;
            text-align: center;
            font-weight: 600;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        .toast-notification.error { /* Added style for error toast */
            background-color: var(--red-color);
        }

        /* NEW: Recent Module Card Styles */
        .recent-modules-container {
            display: flex;
            flex-direction: column;
            gap: 15px; /* Spacing between cards */
        }

        .recent-module-card {
            background-color: var(--recent-module-bg); /* White background */
            border-radius: 12px;
            box-shadow: 0 6px 15px var(--recent-module-shadow); /* Lighter shadow */
            padding: 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease; /* Add background-color to transition */
            border: 1px solid var(--recent-module-border); /* Subtle border */
        }

        .recent-module-card:hover {
            transform: translateY(-5px); /* Lift effect */
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); /* Stronger but still light shadow on hover */
            background-color: var(--recent-module-hover-bg); /* Light grey on hover */
        }

        .recent-module-card a {
            display: block; /* Make the entire card clickable */
            color: var(--recent-module-text-color); /* Dark text color for content */
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .recent-module-card a:hover .recent-module-title {
            color: var(--primary-color); /* Green text on hover for title */
        }

        .recent-module-title {
            font-weight: 700;
            font-size: 1.15em;
            margin-bottom: 8px;
            color: var(--recent-module-text-color); /* Ensure title is dark */
            transition: color 0.2s ease; /* Add transition for title color */
        }

        .recent-module-description {
            font-size: 0.9em;
            color: var(--text-medium); /* Use a readable grey for description */
            margin-bottom: 15px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2; /* Limit description to 2 lines */
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .recent-module-info {
            display: flex;
            align-items: center;
            font-size: 0.85em;
            color: var(--text-light); /* Light grey for info text */
            gap: 15px; /* Space between info items */
        }

        .recent-module-info span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .recent-module-info i {
            color: var(--primary-color); /* Green icons */
            font-size: 1em;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .main-layout-wrapper {
                grid-template-columns: 1fr; /* Single column layout */
                padding: 0 4%;
                gap: 25px; /* Adjusted gap for mobile */
            }
            .main-content {
                grid-column: 1 / -1; /* Span full width */
            }
            .card {
                padding: 20px; /* Slightly less padding on mobile */
            }
            .main-content h1 {
                font-size: 2.5em;
            }
            .btn-complete {
                padding: 16px 30px;
                font-size: 1.1em;
            }
        }

        @media (max-width: 768px) {
            .breadcrumbs, .main-layout-wrapper {
                padding-left: 3%;
                padding-right: 3%;
            }
            h1 {
                font-size: 2em;
            }
            .module-content {
                font-size: 0.95em;
                line-height: 1.6;
            }
            .module-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                font-size: 0.9em;
            }
            .btn-complete {
                padding: 14px 25px;
                font-size: 1em;
            }
            .feedback-button {
                padding: 10px 20px;
                font-size: 0.95em;
            }
            .recent-module-card {
                padding: 15px; /* Reduce padding for smaller screens */
            }
            .recent-module-title {
                font-size: 1.05em;
            }
            .recent-module-description {
                font-size: 0.85em;
            }
            .recent-module-info {
                font-size: 0.8em;
            }
        }

        @media (max-width: 480px) {
            .breadcrumbs {
                margin: 15px auto 0;
                padding: 0 2%;
                font-size: 0.85em;
            }
            .main-layout-wrapper {
                padding: 0 2%;
                gap: 20px;
            }
            .card {
                padding: 18px;
            }
            h1 {
                font-size: 1.8em;
                margin-bottom: 20px;
            }
            .module-info span {
                font-size: 0.8em;
            }
            .btn-complete {
                width: 100%;
                font-size: 0.95em;
                padding: 12px 20px;
            }
            .feedback-section {
                flex-direction: column;
                gap: 10px;
                padding-top: 15px;
            }
            .feedback-button {
                width: 100%;
                justify-content: center;
                font-size: 0.9em;
                padding: 10px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="breadcrumbs">
        <a href="modules.php">Beranda</a> > <a href="modules.php#education-modules">Modul Edukasi</a> > <span id="current-module-breadcrumb"><?php echo htmlspecialchars($moduleData['title'] ?? 'Memuat...'); ?></span>
    </div>

    <div class="main-layout-wrapper">
        <div class="main-content">
            <div class="card main-content-card">
                <h1 id="module-detail-heading"><?php echo htmlspecialchars($moduleData['title'] ?? 'Memuat Konten...'); ?></h1>
                <article class="module-content" id="module-detail-content">
                    <?php if ($moduleData === null): ?>
                    <div class="skeleton-loader-container" id="module-skeleton-loader">
                        <div class="skeleton-loader heading"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line long"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line short"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader image"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line long"><div class="skeleton-wrapper"></div></div>
                        <div class="skeleton-loader text-line short"><div class="skeleton-wrapper"></div></div>
                    </div>
                    <p>Modul tidak dapat dimuat. Pastikan modul tersedia dan aktif.</p>
                    <?php else: ?>
                        <?php echo $contentHtml; ?>
                    <?php endif; ?>
                </article>
                <div class="module-info">
                    <span id="module-detail-duration">
                        <i class="fas fa-clock"></i>
                        <?php
                            if ($moduleData) {
                                if ($moduleType === 'research') {
                                    echo htmlspecialchars($moduleData['estimated_minutes']) . ' menit membaca';
                                } elseif ($moduleType === 'video') {
                                    $duration_in_seconds = (int)$moduleData['duration_minutes'] * 60;
                                    echo gmdate("i:s", $duration_in_seconds) . ' menit menonton'; // Format MM:SS
                                }
                            } else {
                                echo 'XX menit';
                            }
                        ?>
                    </span>
                    <span id="module-detail-points">
                        <i class="fas fa-coins"></i>
                        <?php echo htmlspecialchars($moduleData['points_reward'] ?? 'XX'); ?> poin
                    </span>
                </div>
                <div class="completion-button-container">
                    <button class="btn-complete" id="complete-detail-module-btn"
                        <?php echo $isCompleted ? 'aria-disabled="true" style="pointer-events: none; opacity: 0.7; background-color: var(--primary-dark-color); border-color: var(--primary-dark-color);"' : ''; ?>>
                        <?php echo $isCompleted ? 'Sudah Selesai' : 'Selesai Membaca Modul Ini'; ?>
                    </button>
                </div>

                <div class="feedback-section">
                    <button class="feedback-button like <?php echo ($userFeedbackType === 'like' ? 'active' : ''); ?>" data-type="like" id="module-like-btn">
                        <i class="fas fa-thumbs-up"></i>
                        <span id="module-like-count"><?php echo htmlspecialchars($feedbackCounts['likes_count']); ?></span> Suka
                    </button>
                    <button class="feedback-button dislike <?php echo ($userFeedbackType === 'dislike' ? 'active' : ''); ?>" data-type="dislike" id="module-dislike-btn">
                        <i class="fas fa-thumbs-down"></i>
                        <span id="module-dislike-count"><?php echo htmlspecialchars($feedbackCounts['dislikes_count']); ?></span> Tidak Suka
                    </button>
                </div>
            </div>
        </div>
        <div class="md:w-1/3 mt-6 md:mt-0">
            <div class="card">
                <h3 class="text-2xl font-bold text-dark-green mb-5">Modul Lainnya</h3>
                <div class="recent-modules-container">
                    <?php if (!empty($recentModules)): ?>
                        <?php foreach ($recentModules as $recentModule): ?>
                            <div class="recent-module-card">
                                <a href="module-detail.php?id=<?php echo htmlspecialchars($recentModule['id']); ?>&type=<?php echo htmlspecialchars($recentModule['module_type']); ?>&targetProgressModuleId=<?php echo htmlspecialchars($moduleProgressMapping[$recentModule['module_type']][$recentModule['id']] ?? ''); ?>">
                                    <p class="recent-module-title"><?php echo htmlspecialchars($recentModule['title']); ?></p>
                                    <p class="recent-module-description">
                                        <?php echo htmlspecialchars($recentModule['description']); ?>
                                    </p>
                                    <div class="recent-module-info">
                                        <span>
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo htmlspecialchars($recentModule['duration_minutes']); ?> menit</span>
                                        </span>
                                        <span>
                                            <i class="fas fa-coins"></i>
                                            <span><?php echo htmlspecialchars($recentModule['points_reward']); ?> poin</span>
                                        </span>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-text-light p-3">Tidak ada modul lainnya saat ini.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-gray-800 text-white py-6 mt-8">
        <div class="container flex flex-col md:flex-row justify-between items-center text-sm">
            </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const moduleId = params.get('id');
            const moduleType = params.get('type');
            // Pastikan targetProgressModuleId diambil dengan benar.
            // Ini adalah ID yang diharapkan oleh modules.php untuk memperbarui progress bar 1-10.
            const targetProgressModuleId = params.get('targetProgressModuleId'); // Ini benar, mengambil dari URL

            const elements = {
                detailModuleTitle: document.getElementById('detail-module-title'),
                moduleDetailHeading: document.getElementById('module-detail-heading'),
                moduleDetailContent: document.getElementById('module-detail-content'),
                moduleDetailDuration: document.getElementById('module-detail-duration'),
                moduleDetailPoints: document.getElementById('module-detail-points'),
                completeDetailModuleBtn: document.getElementById('complete-detail-module-btn'),
                currentModuleBreadcrumb: document.getElementById('current-module-breadcrumb'),
                moduleSkeletonLoader: document.getElementById('module-skeleton-loader'),
                moduleLikeBtn: document.getElementById('module-like-btn'),
                moduleDislikeBtn: document.getElementById('module-dislike-btn'),
                moduleLikeCount: document.getElementById('module-like-count'),
                moduleDislikeCount: document.getElementById('module-dislike-count')
            };

            // Initial UI state for feedback based on PHP fetched data
            let currentLikes = parseInt(elements.moduleLikeCount.textContent);
            let currentDislikes = parseInt(elements.moduleDislikeCount.textContent);
            let userFeedbackType = "<?php echo htmlspecialchars($userFeedbackType ?? ''); ?>"; // Will be 'like', 'dislike', or empty string

            // Sembunyikan skeleton loader jika konten sudah dimuat oleh PHP
            if (elements.moduleSkeletonLoader && elements.moduleDetailContent && <?php echo ($moduleData !== null) ? 'true' : 'false'; ?>) {
                elements.moduleSkeletonLoader.style.display = 'none';
                elements.moduleDetailContent.classList.remove('loading');
            }


            const updateModuleFeedbackUI = () => {
                elements.moduleLikeCount.textContent = currentLikes;
                elements.moduleDislikeCount.textContent = currentDislikes;

                elements.moduleLikeBtn.classList.remove('active');
                elements.moduleDislikeBtn.classList.remove('active');

                if (userFeedbackType === 'like') {
                    elements.moduleLikeBtn.classList.add('active');
                } else if (userFeedbackType === 'dislike') {
                    elements.moduleDislikeBtn.classList.add('active');
                }
            };

            const showNotification = (message, type = 'success', duration = 3000) => {
                const notificationDiv = document.createElement('div');
                notificationDiv.classList.add('toast-notification', type);
                notificationDiv.textContent = message;
                document.body.appendChild(notificationDiv);

                setTimeout(() => {
                    notificationDiv.classList.add('show');
                }, 10);

                setTimeout(() => {
                    notificationDiv.classList.remove('show');
                    notificationDiv.style.transform = 'translate(-50%, -50%) scale(0.9)';
                    setTimeout(() => {
                        notificationDiv.remove();
                    }, 500);
                }, duration);
            };

            const handleModuleFeedback = async (event) => {
                const button = event.currentTarget;
                const type = button.dataset.type; // 'like' or 'dislike'
                const oldFeedbackType = userFeedbackType; // Store current user feedback before potential change

                let newLikes = currentLikes;
                let newDislikes = currentDislikes;
                let newUserFeedbackType = null; // Default to no feedback from user after interaction

                // Logic to calculate optimistic UI update
                if (oldFeedbackType === type) { // User is removing their previous feedback
                    if (type === 'like') newLikes--;
                    else newDislikes--;
                } else { // User is changing or adding feedback
                    if (oldFeedbackType === 'like') newLikes--;
                    else if (oldFeedbackType === 'dislike') newDislikes--;

                    if (type === 'like') newLikes++;
                    else newDislikes++;

                    newUserFeedbackType = type; // Set new feedback type
                }

                // Optimistic UI update
                currentLikes = Math.max(0, newLikes); // Ensure counts don't go below zero
                currentDislikes = Math.max(0, newDislikes); // Ensure counts don't go below zero
                userFeedbackType = newUserFeedbackType; // Update local state for userFeedbackType
                updateModuleFeedbackUI(); // Apply optimistic UI changes

                try {
                    const formData = new FormData();
                    formData.append('action', 'submit_feedback');
                    formData.append('module_id', moduleId);
                    formData.append('module_type', moduleType);
                    formData.append('feedback_type', type);
                    formData.append('old_feedback_type', oldFeedbackType); // Send old feedback to server

                    const response = await fetch('module-detail.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.status === 'success') {
                        // Update UI with actual counts from server response to ensure consistency
                        currentLikes = data.likes;
                        currentDislikes = data.dislikes;
                        userFeedbackType = data.user_feedback; // Server confirms the final state of user's feedback
                        updateModuleFeedbackUI();
                        // showNotification(data.message); // Commented out as per original. Can re-enable if desired.
                    } else {
                        console.error("Server error on feedback:", data.message);
                        showNotification(`Error: ${data.message}`, 'error');
                        // On error, revert UI to original state by re-fetching from server (or reloading)
                        // For simplicity, we might just reload or revert to an approximate previous state.
                        // For a robust system, re-fetching feedback counts is ideal.
                        // location.reload(); // Simple but effective fallback
                    }
                } catch (error) {
                    console.error('Error submitting feedback:', error);
                    showNotification('Terjadi kesalahan jaringan saat mengirim feedback.', 'error');
                }
            };


            const setupCompletionButton = () => {
                const completeButton = elements.completeDetailModuleBtn;
                const isCompletedByPHP = completeButton.getAttribute('aria-disabled') === 'true';

                if (completeButton && !isCompletedByPHP) { // Only apply timer if not already completed
                    const MIN_VIEW_DURATION_SECONDS = 180; // 3 minutes
                    let secondsElapsed = 0;
                    let timerInterval;

                    // Function to update button text with countdown
                    const updateButtonCountdown = () => {
                        const remainingTime = MIN_VIEW_DURATION_SECONDS - secondsElapsed;
                        const minutes = Math.floor(remainingTime / 60);
                        const seconds = remainingTime % 60;
                        const displayTime = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        completeButton.textContent = `Mohon Tunggu (${displayTime})`;
                    };

                    // Initial state: disabled
                    completeButton.setAttribute('aria-disabled', 'true');
                    completeButton.style.pointerEvents = 'none';
                    completeButton.style.opacity = '0.7';
                    completeButton.style.backgroundColor = 'var(--primary-dark-color)';
                    completeButton.style.borderColor = 'var(--primary-dark-color)';
                    updateButtonCountdown(); // Set initial countdown text

                    // Start timer
                    timerInterval = setInterval(() => {
                        secondsElapsed++;
                        if (secondsElapsed >= MIN_VIEW_DURATION_SECONDS) {
                            clearInterval(timerInterval);
                            completeButton.textContent = (moduleType === 'research' ? 'Selesai Membaca Modul Ini' : 'Selesai Menonton Modul Ini');
                            completeButton.removeAttribute('aria-disabled');
                            completeButton.style.pointerEvents = '';
                            completeButton.style.opacity = '';
                            completeButton.style.backgroundColor = '';
                            completeButton.style.borderColor = '';
                        } else {
                            updateButtonCountdown();
                        }
                    }, 1000);

                    completeButton.addEventListener('click', async () => {
                        // Check aria-disabled again to ensure it's truly enabled by the timer
                        if (completeButton.getAttribute('aria-disabled') !== 'true') {
                            // Optimistic UI update for button state
                            completeButton.textContent = 'Menyelesaikan...';
                            completeButton.setAttribute('aria-disabled', 'true');
                            completeButton.style.pointerEvents = 'none';
                            completeButton.style.opacity = '0.7';
                            completeButton.style.backgroundColor = 'var(--primary-dark-color)';
                            completeButton.style.borderColor = 'var(--primary-dark-color)';

                            // DEBUGGING: Log nilai variabel sebelum dikirim ke server
                            console.log('DEBUG JS module-detail: Attempting to complete module with:');
                            console.log('DEBUG JS module-detail: moduleId:', moduleId);
                            console.log('DEBUG JS module-detail: moduleType:', moduleType);
                            console.log('DEBUG JS module-detail: targetProgressModuleId:', targetProgressModuleId);


                            try {
                                const formData = new FormData();
                                formData.append('action', 'complete_module');
                                formData.append('module_id', moduleId);
                                formData.append('module_type', moduleType);
                                // FIX yang sudah diterapkan: Memastikan targetProgressModuleId dikirim
                                if (targetProgressModuleId) {
                                    formData.append('target_progress_module_id', targetProgressModuleId);
                                } else {
                                    // Ini akan membantu debugging jika targetProgressModuleId kosong di sisi klien
                                    console.error("DEBUG JS module-detail: targetProgressModuleId is NULL or invalid! Cannot send completion request.");
                                    showNotification('Error: Progress ID tidak ditemukan. Coba lagi atau hubungi admin.', 'error');
                                    // Segera kembalikan state tombol jika ID tidak ada di sisi klien
                                    completeButton.textContent = (moduleType === 'research' ? 'Selesai Membaca Modul Ini' : 'Selesai Menonton Modul Ini');
                                    completeButton.removeAttribute('aria-disabled');
                                    completeButton.style.pointerEvents = '';
                                    completeButton.style.opacity = '';
                                    completeButton.style.backgroundColor = '';
                                    completeButton.style.borderColor = '';
                                    return; // Hentikan eksekusi lebih lanjut
                                }


                                const response = await fetch('module-detail.php', {
                                    method: 'POST',
                                    body: formData
                                });

                                const data = await response.json();

                                if (data.status === 'success') {
                                    // Update button text and show notification based on whether points were newly earned
                                    completeButton.textContent = (moduleType === 'research' ? 'Sudah Selesai' : 'Sudah Ditonton'); // Consistent completed text
                                    if (data.points_earned > 0) { // Check if new points were actually awarded
                                        showNotification(`Selamat! Anda telah menyelesaikan ${elements.moduleDetailHeading.textContent}. Anda mendapatkan ${data.points_earned} poin!`, 'success');
                                    } else {
                                        // Message when module was already completed
                                        showNotification(data.message, 'info'); // e.g., "Modul ini sudah selesai."
                                    }

                                    // Perbarui localStorage untuk `modules.php`
                                    localStorage.setItem(`${moduleType}-${moduleId}-completed`, 'true');
                                    localStorage.setItem(`${moduleType}-${moduleId}-completed-at`, Date.now().toString());

                                    // Simpan juga source untuk progress card (progress-module-ID-source)
                                    localStorage.setItem(`progress-module-${data.target_progress_module_id}-source`, data.module_type);

                                    // **********************************************
                                    // NEW: Notify other tabs/windows about point update via localStorage
                                    localStorage.setItem('total_points_updated', JSON.stringify(data.new_total_points_session));
                                    // **********************************************

                                    // Redirect ke modules.php untuk memperbarui progres dan poin
                                    setTimeout(() => {
                                        // Gunakan parameter targetProgressModuleId untuk menyorot modul yang sesuai di modules.php
                                        window.location.href = `modules.php#learning-progress`; // Atau gunakan #module-${targetProgressModuleId}
                                    }, 3500); // Beri waktu notifikasi terlihat
                                } else {
                                    // Revert button state on error
                                    completeButton.textContent = (moduleType === 'research' ? 'Selesai Membaca Modul Ini' : 'Selesai Menonton Modul Ini');
                                    completeButton.removeAttribute('aria-disabled');
                                    completeButton.style.pointerEvents = '';
                                    completeButton.style.opacity = '';
                                    completeButton.style.backgroundColor = '';
                                    completeButton.style.borderColor = '';

                                    showNotification(`Error: ${data.message}`, 'error');
                                }
                            } catch (error) {
                                console.error('Error completing module:', error);
                                // Revert button state on network error
                                completeButton.textContent = (moduleType === 'research' ? 'Selesai Membaca Modul Ini' : 'Selesai Menonton Modul Ini');
                                completeButton.removeAttribute('aria-disabled');
                                completeButton.style.pointerEvents = '';
                                completeButton.style.opacity = '';
                                completeButton.style.backgroundColor = '';
                                completeButton.style.borderColor = '';
                                showNotification('Terjadi kesalahan jaringan saat menyelesaikan modul.', 'error');
                            }
                        }
                    });
                } else if (isCompletedByPHP) {
                    // If already completed, just update the text (it's already disabled by PHP)
                    completeButton.textContent = (moduleType === 'research' ? 'Sudah Selesai' : 'Sudah Ditonton');
                }
            };

            // Initialize all page components
            updateModuleFeedbackUI(); // Set initial state of feedback buttons
            setupCompletionButton();

            // Add event listeners for module like/dislike buttons
            if (elements.moduleLikeBtn) elements.moduleLikeBtn.addEventListener('click', handleModuleFeedback);
            if (elements.moduleDislikeBtn) elements.moduleDislikeBtn.addEventListener('click', handleModuleFeedback);
        });
    </script>
</body>
</html>