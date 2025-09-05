<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/php-error.log'); // Sesuaikan path ini
// ... sisa kode Anda
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'helpers.php'; // Sertakan file helper Anda yang berisi fungsi is_admin_logged_in() dan redirect()
require_once 'db_connection.php'; // Sertakan koneksi database

// ******************************************************************
// PENTING: Periksa apakah admin sudah login. Jika tidak, arahkan kembali ke halaman login admin.
if (!is_admin_logged_in()) {
    redirect('admin_login.php');
    exit();
}
// ******************************************************************

// Dapatkan ID pengguna admin yang sedang login
$loggedInAdminId = $_SESSION['admin_id'] ?? null;

// Jika ID admin tidak ditemukan di sesi, meskipun sudah is_admin_logged_in,
// ada kemungkinan masalah sesi atau setup, bisa diarahkan ulang atau tampilkan error.
if ($loggedInAdminId === null) {
    error_log("Kesalahan sesi: admin_id tidak ditemukan di sesi setelah verifikasi login di dashboard_admin.php.");
    redirect('admin_login.php?error=session_issue');
    exit();
}

// Fungsi untuk membuat direktori jika belum ada
function ensureDirExists($dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true)) { // Perlu disesuaikan dengan hak akses yang lebih ketat di produksi
            throw new Exception("Failed to create directory: " . $dir);
        }
    }
}

// Fungsi untuk menerjemahkan kode error upload file
function getFileUploadErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return "Ukuran file melebihi batas yang ditentukan di php.ini.";
        case UPLOAD_ERR_FORM_SIZE:
            return "Ukuran file melebihi batas maksimum formulir.";
        case UPLOAD_ERR_PARTIAL:
            return "File hanya terunggah sebagian.";
        case UPLOAD_ERR_NO_FILE:
            return "Tidak ada file yang diunggah.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Direktori sementara tidak ada.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Gagal menulis file ke disk.";
        case UPLOAD_ERR_EXTENSION:
            return "Ekstensi PHP menghentikan unggahan file.";
        default:
            return "Kesalahan unggah file tidak diketahui.";
    }
}

/**
 * Membaca, memperbarui, dan menulis ulang file module_mappings.php.
 * Ini adalah fungsi KRUSIAL untuk pembaruan otomatis.
 *
 * @param string $moduleType 'research' atau 'video'
 * @param int $moduleId ID modul baru dari database
 * @param int $targetProgressModuleId ID progres modul yang ingin dipetakan (1-10)
 * @return bool True jika berhasil, false jika gagal.
 */
function updateModuleMappingsFile($moduleType, $moduleId, $targetProgressModuleId) {
    $filePath = 'module_mappings.php';

    // Cek apakah file ada dan dapat dibaca
    if (!file_exists($filePath) || !is_readable($filePath)) {
        error_log("Error: module_mappings.php tidak ditemukan atau tidak dapat dibaca di: " . $filePath);
        return false;
    }

    // Menggunakan output buffering untuk "menangkap" return value dari include
    // Ini adalah cara aman untuk membaca array dari file PHP yang mengembalikan array.
    ob_start();
    $currentMappings = include $filePath;
    ob_end_clean();

    // Pastikan $currentMappings adalah array
    if (!is_array($currentMappings)) {
        error_log("Error: Isi module_mappings.php tidak mengembalikan array yang valid.");
        return false;
    }

    // Pastikan tipe modul ada di mappings
    if (!isset($currentMappings[$moduleType])) {
        $currentMappings[$moduleType] = [];
    }

    // Tambahkan atau perbarui mapping
    // Hanya tambahkan jika ID modul belum ada atau jika kita ingin memperbaruinya.
    // Asumsi: setiap module_id hanya terpetakan ke SATU targetProgressModuleId.
    $currentMappings[$moduleType][$moduleId] = (int)$targetProgressModuleId;

    // Persiapkan konten baru untuk file
    $newContent = "<?php\n// module_mappings.php\nreturn " . var_export($currentMappings, true) . ";\n?>";

    // Tulis kembali ke file. Gunakan FILE_APPEND jika ingin menambahkan, tapi di sini kita menimpa.
    // Tambahkan LOCK_EX untuk mencegah race condition (multiple simultaneous writes)
    if (file_put_contents($filePath, $newContent, LOCK_EX) === false) {
        error_log("Error: Gagal menulis ke module_mappings.php. Periksa hak akses file.");
        return false;
    }

    // Opsional: Coba ubah hak akses setelah menulis (jika defaultnya terlalu terbuka)
    // chmod($filePath, 0644);

    return true;
}


// Fungsi pembantu untuk menghapus entri dari module_mappings.php
// Digunakan saat modul dihapus.
function removeModuleFromMappingsFile($moduleType, $moduleId) {
    $filePath = 'module_mappings.php';

    if (!file_exists($filePath) || !is_readable($filePath)) {
        error_log("Error: module_mappings.php tidak ditemukan atau tidak dapat dibaca untuk penghapusan mapping.");
        return false;
    }

    ob_start();
    $currentMappings = include $filePath;
    ob_end_clean();

    if (!is_array($currentMappings)) {
        error_log("Error: Isi module_mappings.php tidak mengembalikan array yang valid saat mencoba menghapus mapping.");
        return false;
    }

    if (isset($currentMappings[$moduleType]) && isset($currentMappings[$moduleType][$moduleId])) {
        unset($currentMappings[$moduleType][$moduleId]);
    } else {
        // Modul tidak ditemukan di mapping, mungkin sudah dihapus atau tidak pernah ada. Bukan error.
        return true;
    }

    $newContent = "<?php\n// module_mappings.php\nreturn " . var_export($currentMappings, true) . ";\n?>";

    if (file_put_contents($filePath, $newContent, LOCK_EX) === false) {
        error_log("Error: Gagal menulis ke module_mappings.php saat menghapus mapping. Periksa hak akses file.");
        return false;
    }

    return true;
}


// --- LOGIKA PENANGANAN AJAX UNTUK MODUL EDUKASI & QUIZ & REWARD & GAME & BANK SAMPAH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json'); // Perbaikan: Mengubah ke application/json
    // error_log("AJAX request received: " . print_r($_POST, true)); // Log seluruh POST data

    try { // Menambahkan try-catch block umum untuk AJAX handler

        if ($_POST['action'] === 'add_module') {
            $module_type = filter_var($_POST['module_type'] ?? '', FILTER_SANITIZE_STRING);
            $title = filter_var($_POST['title'] ?? '', FILTER_SANITIZE_STRING);
            $description = filter_var($_POST['description'] ?? '', FILTER_SANITIZE_STRING);
            $points_reward = filter_var($_POST['points_reward'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
            $is_active = 1; // Default to active
            $created_by = $loggedInAdminId;

            if (empty($module_type) || empty($title) || empty($description) || $points_reward < 0) {
                throw new Exception("Missing or invalid required fields.");
            }

            $conn->begin_transaction(); // Mulai transaksi
            try {
                $inserted_module_id = null; // Variabel untuk menyimpan ID modul yang baru ditambahkan

                if ($module_type === 'research') {
                    $content_type = filter_var($_POST['content_type'] ?? '', FILTER_SANITIZE_STRING);
                    $estimated_minutes = filter_var($_POST['estimated_minutes'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
                    $content_url = null;
                    $text_content = null;

                    if (empty($content_type) || $estimated_minutes < 1) {
                        throw new Exception("Missing or invalid research module fields.");
                    }

                    if ($content_type === 'text') {
                        $text_content = $_POST['text_content'] ?? '';
                        if (empty(trim($text_content))) {
                            throw new Exception("Isi teks tidak boleh kosong untuk tipe modul 'Teks Langsung'.");
                        }
                    } elseif ($content_type === 'url') {
                        $content_url = filter_var($_POST['content_url'] ?? '', FILTER_SANITIZE_URL);
                        if (empty($content_url) || !filter_var($content_url, FILTER_VALIDATE_URL)) {
                            throw new Exception("URL tidak valid atau kosong untuk tipe modul 'URL Eksternal'.");
                        }
                    } elseif ($content_type === 'pdf') {
                        $target_dir = "uploads/pdfs/";
                        ensureDirExists($target_dir);

                        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] == UPLOAD_ERR_OK) {
                            $file_ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
                            if ($file_ext !== 'pdf') { // Perbaikan: Pastikan ekstensi juga diperiksa
                                throw new Exception("Tipe file tidak valid. Hanya file PDF yang diizinkan.");
                            }
                            // Verifikasi MIME type lebih lanjut
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime_type = finfo_file($finfo, $_FILES['pdf_file']['tmp_name']);
                            finfo_close($finfo);
                            if ($mime_type !== 'application/pdf') {
                                throw new Exception("Tipe MIME file tidak valid. Hanya file PDF yang diizinkan. Terdeteksi: " . $mime_type);
                            }

                            $file_name = uniqid('pdf_') . '.' . $file_ext;
                            $target_file = $target_dir . $file_name;

                            if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $target_file)) {
                                throw new Exception("Gagal mengunggah file PDF.");
                            }
                            $content_url = $target_file;
                        } else {
                            throw new Exception("Kesalahan unggah file PDF: " . getFileUploadErrorMessage($_FILES['pdf_file']['error']) . " (Code " . $_FILES['pdf_file']['error'] . ")");
                        }
                    } else {
                        throw new Exception("Tipe konten tidak valid untuk modul riset.");
                    }

                    $stmt = $conn->prepare("INSERT INTO modules_research (title, description, content_type, content_url, text_content, estimated_minutes, points_reward, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new Exception("Gagal menyiapkan pernyataan untuk modules_research: " . $conn->error);
                    }
                    $stmt->bind_param("sssssiiii", $title, $description, $content_type, $content_url, $text_content, $estimated_minutes, $points_reward, $is_active, $created_by);

                } elseif ($module_type === 'video') {
                    $video_type = filter_var($_POST['video_type'] ?? '', FILTER_SANITIZE_STRING);
                    $duration_minutes = filter_var($_POST['duration_minutes'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
                    // $thumbnail_url = filter_var($_POST['thumbnail_url'] ?? '', FILTER_SANITIZE_URL); // OLD: for URL thumbnail
                    $thumbnail_url = null; // Initialize thumbnail_url to null
                    $video_url = null;

                    if (empty($video_type) || $duration_minutes < 1) {
                        throw new Exception("Bidang modul video tidak ada atau tidak valid.");
                    }

                    // Handle thumbnail upload
                    $target_thumbnail_dir = "uploads/thumbnails/";
                    ensureDirExists($target_thumbnail_dir);
                    if (isset($_FILES['thumbnail_file']) && $_FILES['thumbnail_file']['error'] == UPLOAD_ERR_OK) {
                        $allowed_image_mime_types = ['image/jpeg', 'image/png', 'image/gif'];
                        $allowed_image_extensions = ['jpg', 'jpeg', 'png', 'gif'];

                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime_type = finfo_file($finfo, $_FILES['thumbnail_file']['tmp_name']);
                        finfo_close($finfo);

                        $file_ext = strtolower(pathinfo($_FILES['thumbnail_file']['name'], PATHINFO_EXTENSION));

                        if (!in_array($mime_type, $allowed_image_mime_types) || !in_array($file_ext, $allowed_image_extensions)) {
                            throw new Exception("Tipe file thumbnail tidak valid. Hanya JPG, PNG, GIF yang diizinkan. Terdeteksi: " . $mime_type . " (ext: ." . $file_ext . ")");
                        }

                        $file_name = uniqid('thumb_') . '.' . $file_ext;
                        $target_thumbnail_file = $target_thumbnail_dir . $file_name;

                        if (!move_uploaded_file($_FILES['thumbnail_file']['tmp_name'], $target_thumbnail_file)) {
                            throw new Exception("Gagal mengunggah file thumbnail.");
                        }
                        $thumbnail_url = $target_thumbnail_file;
                    } else if ($_FILES['thumbnail_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                         throw new Exception("Kesalahan unggah file thumbnail: " . getFileUploadErrorMessage($_FILES['thumbnail_file']['error']) . " (Code " . $_FILES['thumbnail_file']['error'] . ")");
                    }


                    if ($video_type === 'youtube' || $video_type === 'vimeo') {
                        $video_url = filter_var($_POST['video_url'] ?? '', FILTER_SANITIZE_URL);
                        if (empty($video_url) || !filter_var($video_url, FILTER_VALIDATE_URL)) {
                            throw new Exception("URL tidak valid atau kosong untuk tipe modul YouTube/Vimeo.");
                        }
                    } elseif ($video_type === 'upload') {
                        $target_dir = "uploads/videos/";
                        ensureDirExists($target_dir);

                         if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] == UPLOAD_ERR_OK) {
                            $allowed_video_mime_types = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
                            $allowed_video_extensions = ['mp4', 'webm', 'ogg', 'mov']; // Perbaikan: Cek ekstensi juga

                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime_type = finfo_file($finfo, $_FILES['video_file']['tmp_name']);
                            finfo_close($finfo);

                            $file_ext = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));

                            if (!in_array($mime_type, $allowed_video_mime_types) || !in_array($file_ext, $allowed_video_extensions)) {
                                throw new Exception("Tipe file video tidak valid. Hanya MP4, WebM, Ogg, Quicktime yang diizinkan. Terdeteksi: " . $mime_type . " (ext: ." . $file_ext . ")");
                            }

                            $file_name = uniqid('vid_') . '.' . $file_ext;
                            $target_file = $target_dir . $file_name;

                            if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $target_file)) {
                                throw new Exception("Gagal mengunggah file video.");
                            }
                            $video_url = $target_file;
                        } else {
                            throw new Exception("Kesalahan unggah file video: " . getFileUploadErrorMessage($_FILES['video_file']['error']) . " (Code " . $_FILES['video_file']['error'] . ")");
                        }
                    } else {
                        throw new Exception("Tipe video tidak valid untuk modul video.");
                    }

                    $stmt = $conn->prepare("INSERT INTO modules_video (title, description, video_type, video_url, duration_minutes, points_reward, thumbnail_url, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new Exception("Gagal menyiapkan pernyataan untuk modules_video: " . $conn->error);
                    }
                    $stmt->bind_param("ssssiiisi", $title, $description, $video_type, $video_url, $duration_minutes, $points_reward, $thumbnail_url, $is_active, $created_by);
                } else {
                    throw new Exception("Tipe modul tidak valid.");
                }

                if ($stmt->execute()) {
                    $inserted_module_id = $conn->insert_id; // Dapatkan ID modul yang baru saja disisipkan
                    $conn->commit(); // Commit transaksi jika berhasil

                    // --- BAGIAN BARU: PEMBARUAN OTOMATIS module_mappings.php ---
                    // Logika untuk menentukan targetProgressModuleId secara otomatis (contoh sederhana)
                    // Anda mungkin perlu menyesuaikan ini dengan logika penentuan progres Anda yang sebenarnya.
                    // Misalnya, Anda mungkin punya kolom `progress_slot_id` di tabel modules_research/video
                    // atau ingin mengalokasikan slot progres secara sekuensial.
                    // Untuk demo, kita akan memetakan modul baru ke slot progres yang sama dengan ID modulnya,
                    // asalkan slot progres tersebut belum terpakai oleh modul lain.
                    // Ini memerlukan pembacaan ulang mappings dan pemeriksaan ketersediaan slot.

                    // Strategi sederhana: Cari slot progres terkecil (1-10) yang belum dipetakan oleh modul lain.
                    $currentMappingsForAllocation = null;
                    ob_start();
                    $currentMappingsForAllocation = include 'module_mappings.php';
                    ob_end_clean();

                    $usedProgressSlots = [];
                    foreach ($currentMappingsForAllocation['research'] as $dbId => $progressId) {
                        $usedProgressSlots[] = $progressId;
                    }
                    foreach ($currentMappingsForAllocation['video'] as $dbId => $progressId) {
                        $usedProgressSlots[] = $progressId;
                    }
                    $usedProgressSlots = array_unique($usedProgressSlots);
                    sort($usedProgressSlots);

                    $allocatedProgressId = null;
                    for ($i = 1; $i <= 10; $i++) {
                        if (!in_array($i, $usedProgressSlots)) {
                            $allocatedProgressId = $i;
                            break;
                        }
                    }

                    // Jika semua slot 1-10 sudah terisi, Anda harus memutuskan apa yang terjadi:
                    // 1. Modul baru tidak akan muncul di progress bar utama (hanya di daftar modul).
                    // 2. Admin harus mengedit module_mappings.php secara manual untuk mengganti modul lama.
                    // 3. Modul progres menjadi dinamis lebih dari 10. (Perlu perubahan di modules.php UI).

                    if ($allocatedProgressId !== null) {
                         if (!updateModuleMappingsFile($module_type, $inserted_module_id, $allocatedProgressId)) {
                             error_log("Gagal memperbarui module_mappings.php untuk modul ID: " . $inserted_module_id);
                             echo json_encode(['status' => 'error', 'message' => 'Modul ditambahkan, tetapi gagal memperbarui pemetaan progres otomatis.']);
                             exit(); // Keluar dengan error walaupun modul sudah di DB
                         }
                         $message_success = 'Modul berhasil ditambahkan dan dipetakan ke progres modul ' . $allocatedProgressId . '!';
                    } else {
                        // Jika tidak ada slot progres 1-10 yang tersedia
                        $message_success = 'Modul berhasil ditambahkan, tetapi semua slot progres (1-10) sudah terisi. Modul ini tidak akan muncul di progres bar utama.';
                    }


                    echo json_encode(['status' => 'success', 'message' => $message_success]); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi: " . $stmt->error);
                }
                $stmt->close();

            } catch (Exception $e) {
                $conn->rollback(); // Rollback transaksi jika ada error
                error_log("Add module error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();

        } elseif ($_POST['action'] === 'add_quiz') {
            $title = filter_var($_POST['title'] ?? '', FILTER_SANITIZE_STRING);
            $description = filter_var($_POST['description'] ?? '', FILTER_SANITIZE_STRING);
            $category = filter_var($_POST['category'] ?? '', FILTER_SANITIZE_STRING);
            $points_per_question = filter_var($_POST['points_per_question'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
            $is_active = 1; // Default to active
            $created_by = $loggedInAdminId;
            $questions_json = $_POST['questions_data'] ?? '[]'; // Data soal dari JS

            if (empty($title) || empty($description) || empty($category) || $points_per_question < 1) {
                throw new Exception("Bidang yang diperlukan untuk kuis tidak ada atau tidak valid.");
            }

            $questions_data = json_decode($questions_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Format data soal tidak valid: " . json_last_error_msg());
            }

            $conn->begin_transaction();
            try {
                // Insert quiz data
                $stmt = $conn->prepare("INSERT INTO quizzes (title, description, category, points_per_question, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan pernyataan untuk kuis: " . $conn->error);
                }
                $stmt->bind_param("sssiii", $title, $description, $category, $points_per_question, $is_active, $created_by);

                if (!$stmt->execute()) {
                    throw new Exception("Kesalahan database saat eksekusi tambah kuis: " . $stmt->error);
                }
                $quiz_id = $conn->insert_id; // Get the ID of the newly inserted quiz
                $stmt->close();

                // Insert questions
                if (!empty($questions_data)) {
                    $stmt_q = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type, created_at) VALUES (?, ?, ?, NOW())");
                    if (!$stmt_q) throw new Exception("Gagal menyiapkan pernyataan untuk quiz_questions: " . $conn->error);

                    $stmt_o = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                    if (!$stmt_o) throw new Exception("Gagal menyiapkan pernyataan untuk question_options: " . $conn->error);

                    foreach ($questions_data as $q) {
                        $question_text = filter_var($q['question_text'] ?? '', FILTER_SANITIZE_STRING);
                        $question_type = filter_var($q['question_type'] ?? 'multiple_choice', FILTER_SANITIZE_STRING); // Default to multiple_choice

                        if (empty($question_text)) {
                             throw new Exception("Teks soal tidak boleh kosong.");
                        }

                        $stmt_q->bind_param("iss", $quiz_id, $question_text, $question_type);
                        if (!$stmt_q->execute()) {
                            throw new Exception("Gagal menyisipkan soal: " . $stmt_q->error);
                        }
                        $question_id = $conn->insert_id;

                        if ($question_type === 'multiple_choice' && isset($q['options']) && is_array($q['options'])) {
                            $has_correct_answer = false;
                            if (empty($q['options'])) { // Validasi tambahan: setidaknya ada satu opsi
                                throw new Exception("Soal pilihan ganda harus memiliki setidaknya satu opsi.");
                            }
                            foreach ($q['options'] as $o) {
                                $option_text = filter_var($o['option_text'] ?? '', FILTER_SANITIZE_STRING);
                                $is_correct = filter_var($o['is_correct'] ?? 0, FILTER_SANITIZE_NUMBER_INT);

                                if (empty($option_text)) {
                                    throw new Exception("Teks opsi tidak boleh kosong untuk soal ID: " . $question_id);
                                }
                                if ($is_correct) $has_correct_answer = true;

                                $stmt_o->bind_param("isi", $question_id, $option_text, $is_correct);
                                if (!$stmt_o->execute()) {
                                    throw new Exception("Gagal menyisipkan opsi: " . $stmt_o->error);
                                }
                            }
                            // Validation: For multiple_choice, at least one correct answer must be selected.
                            if (!$has_correct_answer) {
                                throw new Exception("Soal pilihan ganda harus memiliki setidaknya satu jawaban benar.");
                            }
                        } elseif ($question_type === 'essay') {
                            // No options for essay, proceed
                        } else {
                            throw new Exception("Tipe soal tidak valid atau opsi tidak ada untuk soal: " . $question_text);
                        }
                    }
                    $stmt_q->close();
                    $stmt_o->close();
                } else {
                    throw new Exception("Kuis harus memiliki setidaknya satu soal."); // Memaksa minimal satu soal
                }

                $activity_description = "Menambah kuis baru: '" . $conn->real_escape_string($title) . "' (ID: {$quiz_id}) dengan " . count($questions_data) . " soal.";
                $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                $stmt_log->execute();
                $stmt_log->close();

                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Kuis berhasil ditambahkan!']); // Perbaikan: Respons JSON
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Add quiz error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'add_game_level') { // NEW ACTION FOR GAME LEVELS
            $level_name = filter_var($_POST['level_name'] ?? '', FILTER_SANITIZE_STRING);
            $description = filter_var($_POST['description'] ?? '', FILTER_SANITIZE_STRING);
            $item_config_json = $_POST['item_config_json'] ?? '[]'; // JSON string from JS
            $duration_seconds = filter_var($_POST['duration_seconds'] ?? 60, FILTER_SANITIZE_NUMBER_INT);
            $points_per_correct_sort = filter_var($_POST['points_per_correct_sort'] ?? 10, FILTER_SANITIZE_NUMBER_INT);
            $is_active = 1; // Default to active
            $created_by = $loggedInAdminId;

            if (empty($level_name) || empty($item_config_json) || $duration_seconds < 1 || $points_per_correct_sort < 1) {
                throw new Exception("Bidang yang diperlukan untuk level game tidak ada atau tidak valid.");
            }

            $item_config_array = json_decode($item_config_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Format JSON konfigurasi item tidak valid: " . json_last_error_msg());
            }
            if (empty($item_config_array)) {
                throw new Exception("Konfigurasi item tidak boleh kosong.");
            }


            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO game_levels (level_name, description, item_config_json, duration_seconds, points_per_correct_sort, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan pernyataan untuk game_levels: " . $conn->error);
                }
                $stmt->bind_param("sssiisi", $level_name, $description, $item_config_json, $duration_seconds, $points_per_correct_sort, $is_active, $created_by);

                if ($stmt->execute()) {
                    $level_id = $conn->insert_id;
                    $activity_description = "Menambah level game baru: '" . $conn->real_escape_string($level_name) . "' (ID: {$level_id}).";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Level game berhasil ditambahkan!']); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi tambah level game: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Add game level error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'delete_game_level') { // NEW ACTION FOR GAME LEVEL DELETION
            $level_id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
            if ($level_id === null) {
                throw new Exception("ID level game tidak ada untuk penghapusan.");
            }

            $conn->begin_transaction();
            try {
                $stmt_select_name = $conn->prepare("SELECT level_name FROM game_levels WHERE id = ?");
                if (!$stmt_select_name) throw new Exception("Gagal menyiapkan pilih nama level game: " . $conn->error);
                $stmt_select_name->bind_param("i", $level_id);
                $stmt_select_name->execute();
                $result_name = $stmt_select_name->get_result();
                $level_name = $result_name->fetch_assoc()['level_name'] ?? 'Unknown Game Level';
                $stmt_select_name->close();

                $stmt = $conn->prepare("DELETE FROM game_levels WHERE id = ?");
                if (!$stmt) throw new Exception("Gagal menyiapkan hapus level game: " . $conn->error);
                $stmt->bind_param("i", $level_id);
                if ($stmt->execute()) {
                    $activity_description = "Menghapus level game: '" . $conn->real_escape_string($level_name) . "' (ID: {$level_id}).";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Level game berhasil dihapus!']); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi hapus level game: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete game level error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'delete_module') {
            $module_id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
            $module_type = filter_var($_POST['type'] ?? '', FILTER_SANITIZE_STRING);

            if ($module_id === null || empty($module_type)) {
                throw new Exception("ID modul atau tipe modul tidak ada untuk penghapusan.");
            }

            $conn->begin_transaction();
            try {
                $file_to_delete = null;
                $thumbnail_to_delete = null; // New variable for thumbnail
                $row = null;

                if ($module_type === 'research') {
                    $stmt_select = $conn->prepare("SELECT content_url, content_type FROM modules_research WHERE id = ?");
                    if (!$stmt_select) throw new Exception("Gagal menyiapkan pilih modul riset: " . $conn->error);
                    $stmt_select->bind_param("i", $module_id);
                    $stmt_select->execute();
                    $result_select = $stmt_select->get_result();
                    $row = $result_select->fetch_assoc();
                    $stmt_select->close();

                    if ($row && $row['content_type'] === 'pdf' && !empty($row['content_url'])) {
                        $file_to_delete = $row['content_url'];
                    }

                    $stmt = $conn->prepare("DELETE FROM modules_research WHERE id = ?");
                    if (!$stmt) throw new Exception("Gagal menyiapkan hapus modul riset: " . $conn->error);
                    $stmt->bind_param("i", $module_id);
                } elseif ($module_type === 'video') {
                    $stmt_select = $conn->prepare("SELECT video_url, video_type, thumbnail_url FROM modules_video WHERE id = ?"); // Added thumbnail_url
                    if (!$stmt_select) throw new Exception("Gagal menyiapkan pilih modul video: " . $conn->error);
                    $stmt_select->bind_param("i", $module_id);
                    $stmt_select->execute();
                    $result_select = $stmt_select->get_result();
                    $row = $result_select->fetch_assoc();
                    $stmt_select->close();

                    if ($row) {
                        if ($row['video_type'] === 'upload' && !empty($row['video_url'])) {
                            $file_to_delete = $row['video_url'];
                        }
                        if (!empty($row['thumbnail_url'])) { // Check for thumbnail to delete
                            $thumbnail_to_delete = $row['thumbnail_url'];
                        }
                    }

                    $stmt = $conn->prepare("DELETE FROM modules_video WHERE id = ?");
                    if (!$stmt) throw new Exception("Gagal menyiapkan hapus modul video: " . $conn->error);
                    $stmt->bind_param("i", $module_id);
                } else {
                    throw new Exception("Tipe modul tidak valid untuk penghapusan.");
                }

                if ($stmt->execute()) {
                    if ($file_to_delete && file_exists($file_to_delete)) {
                        if (!unlink($file_to_delete)) {
                            error_log("Gagal menghapus file: " . $file_to_delete);
                        }
                    }
                    if ($thumbnail_to_delete && file_exists($thumbnail_to_delete)) { // Delete thumbnail file
                        if (!unlink($thumbnail_to_delete)) {
                            error_log("Gagal menghapus file thumbnail: " . $thumbnail_to_delete);
                        }
                    }
                    $conn->commit();

                    // --- BAGIAN BARU: PEMBARUAN OTOMATIS module_mappings.php SETELAH PENGHAPUSAN ---
                    // Setelah modul dihapus, kita perlu menghapus entrinya dari module_mappings.php
                    if (!removeModuleFromMappingsFile($module_type, $module_id)) {
                        error_log("Gagal menghapus modul dari module_mappings.php untuk modul ID: " . $module_id);
                        // Ini adalah error non-fatal, modul di DB sudah terhapus
                    }
                    // --- AKHIR BAGIAN BARU ---

                    echo json_encode(['status' => 'success', 'message' => 'Modul berhasil dihapus!']); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi hapus: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete module error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'delete_quiz') {
            $quiz_id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
            if ($quiz_id === null) {
                throw new Exception("ID kuis tidak ada untuk penghapusan.");
            }

            $conn->begin_transaction();
            try {
                $stmt_select_title = $conn->prepare("SELECT title FROM quizzes WHERE id = ?");
                if (!$stmt_select_title) throw new Exception("Gagal menyiapkan pilih judul kuis: " . $conn->error);
                $stmt_select_title->bind_param("i", $quiz_id);
                $stmt_select_title->execute();
                $result_title = $stmt_select_title->get_result();
                $quiz_title = $result_title->fetch_assoc()['title'] ?? 'Unknown Quiz';
                $stmt_select_title->close();

                // Delete options first, then questions, then quiz
                $stmt_options_sub = $conn->prepare("DELETE FROM question_options WHERE question_id IN (SELECT id FROM quiz_questions WHERE quiz_id = ?)");
                if (!$stmt_options_sub) throw new Exception("Gagal menyiapkan hapus opsi kuis (subquery): " . $conn->error);
                $stmt_options_sub->bind_param("i", $quiz_id);
                $stmt_options_sub->execute();
                $stmt_options_sub->close();


                $stmt_questions = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
                if (!$stmt_questions) throw new Exception("Gagal menyiapkan hapus pertanyaan kuis: " . $conn->error);
                $stmt_questions->bind_param("i", $quiz_id);
                $stmt_questions->execute();
                $stmt_questions->close();

                $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
                if (!$stmt) throw new Exception("Gagal menyiapkan hapus kuis: " . $conn->error);
                $stmt->bind_param("i", $quiz_id);
                if ($stmt->execute()) {
                    $activity_description = "Menghapus kuis: '" . $conn->real_escape_string($quiz_title) . "' (ID: {$quiz_id}) dan pertanyaan terkait";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Kuis berhasil dihapus!']); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi hapus kuis: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete quiz error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'delete_reward') {
            $reward_id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
            if ($reward_id === null) {
                throw new Exception("ID hadiah tidak ada untuk penghapusan.");
            }

            $conn->begin_transaction();
            try {
                $stmt_select_name = $conn->prepare("SELECT name FROM rewards WHERE id = ?");
                if (!$stmt_select_name) throw new Exception("Gagal menyiapkan pilih nama hadiah: " . $conn->error);
                $stmt_select_name->bind_param("i", $reward_id);
                $stmt_select_name->execute();
                $result_name = $stmt_select_name->get_result();
                $reward_name = $result_name->fetch_assoc()['name'] ?? 'Unknown Reward';
                $stmt_select_name->close();

                $stmt_select_image = $conn->prepare("SELECT image_url FROM rewards WHERE id = ?");
                if (!$stmt_select_image) throw new Exception("Gagal menyiapkan pilih gambar hadiah: " . $conn->error);
                $stmt_select_image->bind_param("i", $reward_id);
                $stmt_select_image->execute();
                $result_image = $stmt_select_image->get_result();
                $image_row = $result_image->fetch_assoc();
                $stmt_select_image->close();
                $image_to_delete = $image_row['image_url'] ?? null;

                $stmt = $conn->prepare("DELETE FROM rewards WHERE id = ?");
                if (!$stmt) throw new Exception("Gagal menyiapkan hapus hadiah: " . $conn->error);
                $stmt->bind_param("i", $reward_id);
                if ($stmt->execute()) {
                    if ($image_to_delete && file_exists($image_to_delete)) {
                        if (!unlink($image_to_delete)) {
                            error_log("Gagal menghapus file gambar hadiah: " . $image_to_delete);
                        }
                    }

                    $activity_description = "Menghapus hadiah: '" . $conn->real_escape_string($reward_name) . "' (ID: {$reward_id})";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Hadiah berhasil dihapus!']); // Perbaikan: Respons JSON
                } else {
                    throw new Exception("Kesalahan database saat eksekusi hapus hadiah: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete reward error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        } elseif ($_POST['action'] === 'update_exchange_status') { // Renamed action from 'update_order_status'
            $exchange_id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT); // Renamed variable
            $new_status = filter_var($_POST['status'] ?? '', FILTER_SANITIZE_STRING);

            if ($exchange_id === null || empty($new_status)) {
                throw new Exception("ID penukaran atau status tidak ada untuk pembaruan."); // Renamed message
            }

            // Make sure these statuses match the ones used in profile.php (e.g., 'pending', 'approved', 'sent', 'rejected')
            $allowed_statuses = ['pending', 'approved', 'sent', 'rejected', 'delivered']; // Added 'delivered'
            if (!in_array($new_status, $allowed_statuses)) {
                throw new Exception("Nilai status tidak valid.");
            }

            $conn->begin_transaction();
            try {
                // Update the 'exchanges' table
                $stmt = $conn->prepare("UPDATE exchanges SET status = ? WHERE id = ?");
                if (!$stmt) throw new Exception("Gagal menyiapkan pembaruan status penukaran: " . $conn->error); // Renamed message
                $stmt->bind_param("si", $new_status, $exchange_id);
                if ($stmt->execute()) {
                    $activity_description = "Mengubah status penukaran ID: {$exchange_id} menjadi '{$new_status}'"; // Renamed message
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Status penukaran berhasil diubah!']); // Renamed message
                } else {
                    throw new Exception("Kesalahan database saat eksekusi pembaruan status penukaran: " . $stmt->error); // Renamed message
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Update exchange status error: " . $e->getMessage()); // Renamed error log
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // Perbaikan: Respons JSON
            }
            exit();
        }
        // NEW: AJAX for adding Bank Sampah location
        elseif ($_POST['action'] === 'add_drop_point') {
            $name = filter_var($_POST['name'] ?? '', FILTER_SANITIZE_STRING);
            $address = filter_var($_POST['address'] ?? '', FILTER_SANITIZE_STRING);
            $raw_pin_code = filter_var($_POST['pin_code'] ?? '', FILTER_SANITIZE_STRING); // <--- Ambil PIN mentah dari form
            $description = filter_var($_POST['description'] ?? '', FILTER_SANITIZE_STRING);
            $latitude = filter_var($_POST['latitude'] ?? null, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $longitude = filter_var($_POST['longitude'] ?? null, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $waste_types_json = $_POST['waste_types_json'] ?? '[]'; // Array of waste types (e.g., ["Plastik", "Kertas"])
            $prices_json = $_POST['prices_json'] ?? '[]'; // Array of price strings (e.g., ["Plastik: Rp 3.000/kg"])

            // Ensure latitude and longitude are valid numbers
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                throw new Exception("Latitude dan Longitude harus berupa angka yang valid.");
            }
            // NEW: Validate and Hash PIN code
            if (empty($raw_pin_code)) {
                throw new Exception("PIN Bank Sampah tidak boleh kosong.");
            }
            // Tambahkan validasi panjang PIN (misal 6 digit)
            if (strlen($raw_pin_code) !== 6) {
                throw new Exception("PIN Bank Sampah harus 6 digit.");
            }
            // Hash PIN sebelum disimpan!
            $hashed_pin_for_db = password_hash($raw_pin_code, PASSWORD_DEFAULT); // <--- INI PENTING!


            if (empty($name) || empty($address) || empty($waste_types_json) || empty($prices_json)) {
                throw new Exception("Nama, Alamat, Jenis Sampah, dan Harga bank sampah tidak boleh kosong.");
            }

            $waste_types_decoded = json_decode($waste_types_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Format jenis sampah tidak valid: " . json_last_error_msg());
            }

            $prices_decoded = json_decode($prices_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Format harga tidak valid: " . json_last_error_msg());
            }

            // Convert prices_decoded array to a simple string if your DB column 'prices' is TEXT
            // Example: ["Plastik: Rp 3.000/kg", "Kertas: Rp 2.500/kg"] => "Plastik: Rp 3.000/kg, Kertas: Rp 2.500/kg"
            $prices_string = implode(', ', array_map(function($item) {
                return $item['type'] . ': ' . $item['price'];
            }, $prices_decoded));


            $conn->begin_transaction();
            try {
                // Modified INSERT statement to include latitude, longitude, and pin_code
                // PASTIKAN kolom 'pin_code' di tabel 'drop_points' diganti menjadi 'pin_hash' (VARCHAR 255)
                // Atau pastikan Anda menambahkan kolom 'pin_hash' dan menggunakan itu untuk menyimpan hash.
                // Jika Anda ingin tetap menyimpan PIN asli di 'pin_code' untuk tampilan admin (kurang aman),
                // maka query di bawah ini harus menyertakan $raw_pin_code untuk kolom 'pin_code'
                // DAN $hashed_pin_for_db untuk kolom 'pin_hash'.
                // Saya asumsikan Anda hanya ingin menyimpan hash di kolom 'pin_hash'.

                $stmt = $conn->prepare("INSERT INTO drop_points (name, address, pin_hash, description, latitude, longitude, hours, types, prices) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan pernyataan untuk drop_points: " . $conn->error);
                }
                // Default value for hours, you might want to add a field for this in the form
                $default_hours = "08.00–17.00";
                // Bind parameters, gunakan $hashed_pin_for_db untuk kolom pin_hash
                $stmt->bind_param("sssssdsss", $name, $address, $hashed_pin_for_db, $description, $latitude, $longitude, $default_hours, $waste_types_json, $prices_string);

                if ($stmt->execute()) {
                    $drop_point_id = $conn->insert_id;
                    $activity_description = "Menambah lokasi Bank Sampah baru: '" . $conn->real_escape_string($name) . "' (ID: {$drop_point_id}).";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Lokasi Bank Sampah berhasil ditambahkan!']);
                } else {
                    throw new Exception("Kesalahan database saat eksekusi tambah lokasi bank sampah: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Add drop point error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }
        // NEW: AJAX for deleting Bank Sampah location
        elseif ($_POST['action'] === 'delete_drop_point') {
            $id = filter_var($_POST['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
            if ($id === null) {
                throw new Exception("ID lokasi bank sampah tidak ada untuk penghapusan.");
            }

            $conn->begin_transaction();
            try {
                $stmt_select_name = $conn->prepare("SELECT name FROM drop_points WHERE id = ?");
                if (!$stmt_select_name) throw new Exception("Gagal menyiapkan pilih nama lokasi bank sampah: " . $conn->error);
                $stmt_select_name->bind_param("i", $id);
                $stmt_select_name->execute();
                $result_name = $stmt_select_name->get_result();
                $location_name = $result_name->fetch_assoc()['name'] ?? 'Unknown Location';
                $stmt_select_name->close();

                $stmt = $conn->prepare("DELETE FROM drop_points WHERE id = ?");
                if (!$stmt) throw new Exception("Gagal menyiapkan hapus lokasi bank sampah: " . $conn->error);
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $activity_description = "Menghapus lokasi Bank Sampah: '" . $conn->real_escape_string($location_name) . "' (ID: {$id}).";
                    $stmt_log = $conn->prepare("INSERT INTO admin_activities (admin_id, activity_description, activity_time) VALUES (?, ?, NOW())");
                    if (!$stmt_log) throw new Exception("Gagal menyiapkan log aktivitas admin: " . $conn->error);
                    $stmt_log->bind_param("is", $loggedInAdminId, $activity_description);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Lokasi Bank Sampah berhasil dihapus!']);
                } else {
                    throw new Exception("Kesalahan database saat eksekusi hapus lokasi bank sampah: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete drop point error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

    } catch (Exception $e) {
        // Ini adalah catch block untuk masalah yang terjadi sebelum transaksi dimulai atau action tidak valid
        error_log("AJAX pre-transaction error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => "Kesalahan umum: " . $e->getMessage()]);
        exit();
    }
}


// --- FUNGSI PHP UNTUK MENGAMBIL DATA DARI DATABASE ---

function getTotalUsers($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM users");
    $row = $result->fetch_assoc();
    return $row['total'];
}

// NEW FUNCTION: Get Total User Points
function getTotalUserPoints($conn) {
    $result = $conn->query("SELECT SUM(total_points) AS total_points FROM users");
    $row = $result->fetch_assoc();
    return $row['total_points'] ?? 0; // Return 0 if no users or no points
}

function getTotalQuizzes($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM quizzes");
    $row = $result->fetch_assoc();
    return $row['total'];
}

// NEW FUNCTION: Get Total Research Modules Count
function getTotalResearchModulesCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM modules_research");
    $row = $result->fetch_assoc();
    return $row['total'];
}

// NEW FUNCTION: Get Total Video Modules Count
function getTotalVideoModulesCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM modules_video");
    $row = $result->fetch_assoc();
    return $row['total'];
}

// MODIFIED: Changed to get claimed rewards from 'exchanges' table
function getClaimedRewardsCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM exchanges WHERE status = 'sent' OR status = 'delivered'"); // Assuming 'sent' or 'delivered' are claimed statuses
    $row = $result->fetch_assoc();
    return $row['total'];
}

// MODIFIED: Changed to get pending orders from 'exchanges' table
function getPendingOrdersCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM exchanges WHERE status = 'pending'");
    $row = $result->fetch_assoc();
    return $row['total'];
}

function getRecentUsers($conn, $limit = 5) {
    $users = [];
    $sql = "SELECT id, username, email, total_points, created_at FROM users ORDER BY created_at DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    return $users;
}

// MODIFIED: Changed to get recent exchanges from 'exchanges' table
function getRecentExchanges($conn, $limit = 5) { // Renamed function
    $exchanges = []; // Renamed variable
    // [MODIFIKASI DI SINI]: Tambahkan kolom email_penerima, nohp_penerima, pesan_tambahan
    $sql = "SELECT e.id, u.username AS user_name, r.name AS reward_name, e.checkout_date AS exchange_date, e.status, e.email_penerima, e.nohp_penerima, e.pesan_tambahan
            FROM exchanges e
            JOIN users u ON e.user_id = u.id
            JOIN rewards r ON e.reward_id = r.id
            ORDER BY e.checkout_date DESC LIMIT ?"; // Changed table and column names
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $exchanges[] = $row; // Renamed variable
    }
    $stmt->close();
    return $exchanges; // Renamed return variable
}

function getAllQuizzes($conn) {
    $quizzes = [];
    $sql = "SELECT id, title, description, category, points_per_question, created_at FROM quizzes ORDER BY created_at DESC";
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $quizzes[] = $row;
    }
    return $quizzes;
}

function getAllRewards($conn) {
    $rewards = [];
    $sql = "SELECT id, name, stock, points_needed, category, image_url FROM rewards ORDER BY name ASC";
    $result = $conn->query("SELECT id, name, stock, points_needed, category, image_url FROM rewards ORDER BY name ASC");
    while($row = $result->fetch_assoc()) {
        $rewards[] = $row;
    }
    return $rewards;
}

// MODIFIED: Changed to get full exchanges from 'exchanges' table
function getFullExchanges($conn) { // Renamed function
    $exchanges = []; // Renamed variable
    // [MODIFIKASI DI SINI]: Tambahkan kolom email_penerima, nohp_penerima, pesan_tambahan
    $sql = "SELECT e.id, u.username AS user_name, r.name AS reward_name, e.checkout_date AS exchange_date, e.status, e.email_penerima, e.nohp_penerima, e.pesan_tambahan
            FROM exchanges e
            JOIN users u ON e.user_id = u.id
            JOIN rewards r ON e.reward_id = r.id
            ORDER BY e.checkout_date DESC"; // Changed table and column names
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $exchanges[] = $row; // Renamed variable
    }
    return $exchanges; // Renamed return variable
}

function getFullUsers($conn) {
    $users = [];
    $sql = "SELECT id, username, email, total_points, created_at FROM users ORDER BY created_at DESC";
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    return $users;
}

function getAdminActivities($conn, $limit = 5) {
    $activities = [];
    $sql = "SELECT admin_id, activity_description, activity_time FROM admin_activities ORDER BY activity_time DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    $stmt->close();
    return $activities;
}

function getAllResearchModules($conn) {
    $modules = [];
    $sql = "SELECT id, title, description, content_type, content_url, text_content, estimated_minutes, points_reward, is_active FROM modules_research ORDER BY created_at DESC";
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    return $modules;
}

function getAllVideoModules($conn) {
    $modules = [];
    $sql = "SELECT id, title, description, video_type, video_url, duration_minutes, points_reward, thumbnail_url, is_active FROM modules_video ORDER BY created_at DESC";
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    return $modules;
}

// NEW FUNCTION: Get All Game Levels
function getAllGameLevels($conn) {
    $levels = [];
    $sql = "SELECT id, level_name, description, item_config_json, duration_seconds, points_per_correct_sort, is_active FROM game_levels ORDER BY id ASC";
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $levels[] = $row;
    }
    return $levels;
}

// NEW FUNCTION: Get All Drop Points (Bank Sampah Locations)
function getAllDropPoints($conn) {
    $drop_points = [];
    // Ensure latitude and longitude are selected here, and also the new pin_code
    // PENTING: Jika Anda sudah mengubah kolom 'pin_code' menjadi 'pin_hash', ubah juga di sini
    $sql = "SELECT id, name, address, pin_hash, latitude, longitude, description, types, prices FROM drop_points ORDER BY id DESC"; // Menggunakan pin_hash
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $drop_points[] = $row;
    }
    return $drop_points;
}

// NEW FUNCTION: Get Full User Game States
function getFullUserGameStates($conn) {
    $game_states = [];
    $sql = "SELECT
                u.id AS user_id,
                u.username,
                u.email,
                gs.player_money,
                gs.current_day,
                gs.is_game_over,
                MAX(la.activity_time) AS last_game_activity
            FROM users u
            LEFT JOIN game_state gs ON u.id = gs.user_id
            LEFT JOIN admin_activities la ON u.id = la.admin_id AND la.activity_description LIKE 'Game Simulasi Daur Ulang:%'
            GROUP BY u.id, u.username, u.email, gs.player_money, gs.current_day, gs.is_game_over
            ORDER BY u.username ASC";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $game_states[] = $row;
    }
    return $game_states;
}

// NEW FUNCTION: Get Recent Game-Specific Activities
function getRecentGameActivities($conn, $limit = 10) {
    $activities = [];
    // Assuming game activities are logged with a specific prefix, e.g., "Game Simulasi Daur Ulang:"
    $sql = "SELECT
                aa.admin_id,
                u.username AS user_name,
                aa.activity_description,
                aa.activity_time
            FROM admin_activities aa
            JOIN users u ON aa.admin_id = u.id -- Assuming admin_id in admin_activities refers to user_id in game context
            WHERE aa.activity_description LIKE 'Game Simulasi Daur Ulang:%'
            ORDER BY aa.activity_time DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare getRecentGameActivities statement: " . $conn->error);
    }
    return $activities;
}


// --- PANGGIL FUNGSI UNTUK MENGAMBIL DATA ---
$totalUsersCount = getTotalUsers($conn);
$totalUserPoints = getTotalUserPoints($conn); // NEW: Get total user points
$totalQuizzesCount = getTotalQuizzes($conn);
$totalResearchModulesCount = getTotalResearchModulesCount($conn); // NEW: Get total research modules
$totalVideoModulesCount = getTotalVideoModulesCount($conn); // NEW: Get total video modules
$totalModulesCount = $totalResearchModulesCount + $totalVideoModulesCount; // Calculate total modules
$claimedRewardsCount = getClaimedRewardsCount($conn); // MODIFIED
$pendingOrdersCount = getPendingOrdersCount($conn); // MODIFIED
$recentUsersData = getRecentUsers($conn);
$recentExchangesData = getRecentExchanges($conn); // Renamed variable and called renamed function
$allQuizzesData = getAllQuizzes($conn);
$allRewardsData = getAllRewards($conn);
$fullExchangesData = getFullExchanges($conn); // Renamed variable and called renamed function
$fullUsersData = getFullUsers($conn);
$adminActivitiesData = getAdminActivities($conn);
$allResearchModulesData = getAllResearchModules($conn);
$allVideoModulesData = getAllVideoModules($conn);
$allGameLevelsData = getAllGameLevels($conn); // NEW: Fetch all game levels
$allDropPointsData = getAllDropPoints($conn); // NEW: Fetch all drop points
$fullUserGameStatesData = getFullUserGameStates($conn); // NEW: Fetch user game states
$recentGameActivitiesData = getRecentGameActivities($conn, 10); // NEW: Fetch recent game activities


// Inisialisasi pesan dari URL (jika ada redirect dari membuat_reward.php)
$display_message = '';
$display_message_type = '';
if (isset($_GET['message']) && isset($_GET['type'])) {
    $display_message = htmlspecialchars($_GET['message']);
    $display_message_type = htmlspecialchars($_GET['type']);
    // Clear URL parameters after displaying message to prevent re-showing on refresh
    echo "<script>
            window.onload = function() {
                const url = new URL(window.location);
                url.searchParams.delete('message');
                url.searchParams.delete('type');
                window.history.replaceState({}, document.title, url.toString());
            };
          </script>";
}

// Tutup koneksi database di akhir skrip
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dashboard Administrasi untuk GoRako - Kelola Pengguna, Kuis, Hadiah, dan Pesanan.">
    <meta name="keywords" content="admin, dashboard, gorako, manajemen, kuis, hadiah, pengguna, pesanan">
    <title>GoRako Admin Dashboard - Profesional</title>
    <link rel="icon" href="https://img.icons8.com/plasticine/100/recycle-bin.png" type="image/png">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Sharp" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Warna GoRako & Variabel Umum */
        :root {
            --color-primary: #8BC34A; /* Hijau Daur Ulang */
            --color-danger: #EF5350; /* Merah untuk bahaya/cancel */
            --color-success: #66BB6A; /* Hijau Cerah */
            --color-warning: #FFCA28; /* Kuning untuk pending */
            --color-info-dark: #607D8B; /* Biru Abu-abu untuk teks muted */
            --color-info-light: #CFD8DC; /* Abu-abu terang untuk highlight */
            --color-dark: #37474F; /* Dark Blue Grey */
            --color-light: rgba(183, 204, 150, 0.18); /* Hijau muda transparan */
            --color-primary-variant: #689F38; /* Hijau lebih gelap */
            --color-dark-variant: #78909C; /* Abu-abu lebih terang */
            --color-background: #F0F4C3; /* Krem kekuningan untuk latar belakang */
            --color-white: #FFFFFF; /* Putih */

            --card-border-radius: 1.5rem;
            --border-radius-1: 0.4rem;
            --border-radius-2: 0.8rem;
            --border-radius-3: 1.2rem;

            --card-padding: 1.6rem;
            --padding-1: 1.2rem;

            /* Bayangan sedikit lebih halus dan modern */
            --box-shadow: 0 0.8rem 1.6rem rgba(0, 0, 0, 0.08);
        }

        /* Variabel Tema Gelap GoRako */
        .dark-theme-variables {
            --color-background: #263238; /* Dark Blue Grey */
            --color-white: #37474F; /* Darker Blue Grey */
            --color-dark: #ECEFF1; /* Light Blue Grey */
            --color-dark-variant: #B0BEC5; /* Lighter Blue Grey */
            --color-light: rgba(0, 0, 0, 0.3);
            --box-shadow: 0 0.8rem 1.6rem rgba(0, 0, 0, 0.3);
        }

        /* Gaya Global */
        * {
            margin: 0;
            padding: 0;
            outline: 0;
            appearance: none;
            border: 0;
            text-decoration: none;
            list-style: none;
            box-sizing: border-box;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease; /* Transisi halus untuk tema */
        }

        html {
            font-size: 14px;
            height: 100%; /* Memastikan HTML mengambil tinggi penuh */
        }

        body {
            width: 100vw;
            height: 100vh; /* Memastikan body mengambil tinggi penuh viewport */
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            background: var(--color-background);
            user-select: none;
            overflow: hidden; /* Mencegah scrollbar pada body */
            color: var(--color-dark);
        }

        .container {
            display: grid;
            width: 96%;
            max-width: 1600px; /* Batasi lebar maksimum agar tidak terlalu meregang pada layar ultra-wide */
            margin: 0 auto;
            gap: 1.8rem;
            grid-template-columns: 14rem auto 23rem;
            height: 100%; /* Kontainer juga mengambil tinggi penuh */
        }

        a {
            color: var(--color-dark);
        }

        img {
            display: block;
            width: 100%;
        }

        h1 {
            font-weight: 700;
            font-size: 1.8rem;
            color: var(--color-dark);
        }

        h2 {
            font-size: 1.4rem;
            color: var(--color-dark);
            margin-top: 2rem; /* Konsistensi spasi */
            margin-bottom: 1rem; /* Konsistensi spasi */
        }

        h3 {
            font-size: 0.87rem;
            color: var(--color-dark);
        }

        h4 {
            font-size: 0.8rem;
        }

        h5 {
            font-size: 0.77rem;
        }

        small {
            font-size: 0.75rem;
        }

        .profile-photo {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: 50%;
            overflow: hidden;
        }

        .profile-photo.large-photo {
            width: 5rem;
            height: 5rem;
            margin-bottom: 1rem;
        }

        .text-muted {
            color: var(--color-info-dark);
        }

        p {
            color: var(--color-dark-variant);
        }

        b {
            color: var(--color-dark);
        }

        .green {
            color: var(--color-primary);
        }
        .primary-text {
            color: var(--color-primary);
        }
        .danger-text {
            color: var(--color-danger);
        }
        .success-text {
            color: var(--color-success);
        }
        .warning-text {
            color: var(--color-warning);
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            transition: all 300ms ease;
            font-weight: 500;
            display: inline-flex; /* Untuk menempatkan ikon loading di samping teks */
            align-items: center;
            gap: 0.5rem;
        }
        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn:hover {
            opacity: 0.9;
        }
        /* Focus state yang lebih jelas */
        .btn:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, .theme-toggler:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }


        .primary-btn {
            background: var(--color-primary);
            color: var(--color-white);
        }

        .danger-btn {
            background: var(--color-danger);
            color: var(--color-white);
        }

        /* Spinner Animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .spinner {
            animation: spin 1s linear infinite;
            font-size: 1.2em; /* Sesuaikan ukuran spinner */
            display: none; /* Sembunyikan secara default */
        }
        .btn.loading .spinner {
            display: inline-block;
        }
        .btn.loading {
            cursor: not-allowed;
            opacity: 0.7;
        }


        /* SIDEBAR */
        aside {
            height: 100%; /* Sidebar mengambil tinggi penuh kontainer */
            background: var(--color-white);
            box-shadow: var(--box-shadow);
            overflow-y: hidden; /* HILANGKAN SCROLLBAR PADA SIDEBAR */
            padding-bottom: 0; /* Hapus padding bawah agar menu logout pas di bawah */
        }

        aside .top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1.4rem;
            padding: 0 1.2rem; /* Tambahkan padding agar logo tidak terlalu mepet tepi kiri */
        }

        aside .logo {
            display: flex;
            gap: 0.8rem;
            align-items: center;
        }

        aside .logo img {
            width: 2.5rem;
            height: 2.5rem;
        }

        aside .close {
            display: none;
        }

        aside .sidebar {
            display: flex;
            flex-direction: column;
            /* Tinggi dihitung berdasarkan tinggi parent minus tinggi .top (sekitar 4.6rem) dan padding-bottom */
            height: calc(100% - 4.6rem); /* Sesuaikan ini jika tinggi .top berubah */
            position: relative;
            padding-top: 1rem; /* Tambahkan padding di atas menu */
        }

        aside h3 {
            font-weight: 500;
        }

        aside .sidebar a {
            display: flex;
            color: var(--color-info-dark);
            margin-left: 1.2rem; /* Indentasi disesuaikan agar full kiri */
            gap: 1rem;
            align-items: center;
            position: relative;
            height: 3.5rem;
            transition: all 300ms ease;
        }

        aside .sidebar a span {
            font-size: 1.6rem;
            transition: all 300ms ease;
        }

        aside .sidebar a:last-child {
            /* Pastikan menu logout berada di bagian bawah */
            position: absolute;
            bottom: 2rem;
            width: 100%;
        }


        aside .sidebar a.active {
            background: var(--color-light);
            color: var(--color-primary);
            margin-left: 0; /* Active link menempel kiri */
        }

        aside .sidebar a.active::before {
            content: "";
            width: 6px;
            height: 100%;
            background: var(--color-primary);
        }

        aside .sidebar a.active span {
            color: var(--color-primary);
            margin-left: calc(1rem - 3px);
        }

        aside .sidebar a:hover {
            color: var(--color-primary);
        }

        aside .sidebar a:hover span {
            margin-left: 1rem;
        }

        aside .sidebar .message-count {
            background: var(--color-danger);
            color: var(--color-white);
            padding: 2px 10px;
            font-size: 11px;
            border-radius: var(--border-radius-1);
        }

        /* MAIN SECTION */
        main {
            margin-top: 1.4rem;
            overflow-y: auto; /* Memungkinkan konten utama di-scroll secara independen */
            padding-right: 1rem; /* Ruang di sisi kanan agar scrollbar tidak menempel tepi */
            padding-bottom: 2rem; /* Ruang di bagian bawah konten utama */
        }

        main .date {
            display: inline-block;
            background: var(--color-light);
            border-radius: var(--border-radius-1);
            margin-top: 1rem;
            padding: 0.5rem 1.6rem;
        }

        main .date input[type="date"] {
            background: transparent;
            color: var(--color-dark);
        }

        /* INSIGHTS (Kartu Statistik) */
        .insights {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.6rem;
            margin-top: 1rem;
        }

        .insights > div {
            background: var(--color-white);
            padding: var(--card-padding);
            border-radius: var(--card-border-radius);
            margin-top: 1rem;
            box-shadow: var(--box-shadow);
            transition: all 300ms ease, transform 0.2s ease; /* Tambah transform untuk hover */
        }

        .insights > div:hover {
            box-shadow: none;
            transform: translateY(-5px); /* Efek melayang saat hover */
        }

        .insights > div span {
            background: var(--color-primary);
            padding: 0.5rem;
            border-radius: 50%;
            color: var(--color-white);
            font-size: 2rem;
        }

        .insights > div.total-users span,
        .insights > div.total-points span {
            background: var(--color-primary);
        }

        .insights > div.total-quizzes span,
        .insights > div.completed-quizzes span {
            background: var(--color-warning);
        }

        .insights > div.claimed-rewards span,
        .insights > div.rewards-claimed span {
            background: var(--color-success);
        }

        .insights > div.pending-orders span {
            background: var(--color-danger);
        }

        .insights .middle {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .insights h1 {
            font-size: 2.2rem;
        }

        .insights small {
            margin-top: 1.6rem;
            display: block;
        }

        /* Kontainer Baru: Pengguna dan Pesanan berdampingan */
        .recent-data-split {
            display: grid;
            grid-template-columns: 1fr 1fr; /* Dua kolom, lebar sama */
            gap: 1.8rem; /* Jarak antar dua tabel */
            margin-top: 2rem;
        }

        .recent-data-split .recent-data {
            margin-top: 0; /* Hapus margin atas karena sudah ditangani oleh grid parent */
        }

        .recent-data {
            margin-top: 1.5rem;
        }

        .recent-data h3 {
            margin-bottom: 0.8rem;
        }

        .recent-data table {
            background: var(--color-white);
            width: 100%;
            border-radius: var(--card-border-radius);
            padding: var(--card-padding);
            text-align: left;
            box-shadow: var(--box-shadow);
            transition: all 300ms ease;
        }

        .recent-data table:hover {
            box-shadow: none;
        }

        .recent-data table th {
            padding-bottom: 1rem;
            color: var(--color-primary-variant);
        }

        .recent-data table tbody td {
            height: 2.8rem;
            border-bottom: 1px solid var(--color-info-light);
            color: var(--color-dark-variant);
        }

        .recent-data table tbody tr:last-child td {
            border-bottom: none;
        }

        .recent-data a {
            text-align: center;
            display: block;
            margin: 1rem auto;
            color: var(--color-primary);
        }

        /* Styling Form dalam Card */
        .card.form-section {
            background: var(--color-white);
            padding: var(--card-padding);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 1.5rem;
            transition: all 300ms ease;
        }
        .card.form-section:hover {
            box-shadow: none;
        }

        .card.form-section form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--color-dark-variant);
        }

        .card.form-section form input[type="text"],
        .card.form-section form input[type="number"],
        .card.form-section form input[type="url"],
        .card.form-section form input[type="file"],
        .card.form-section form select,
        .card.form-section form textarea {
            width: 100%;
            padding: 0.8rem;
            margin-bottom: 1rem;
            border: 1px solid var(--color-info-light);
            border-radius: var(--border-radius-1);
            background: var(--color-white);
            color: var(--color-dark);
            transition: border-color 0.3s ease;
        }
        .card.form-section form textarea {
            min-height: 80px;
            resize: vertical;
        }

        .card.form-section form input[type="text"]:focus,
        .card.form-section form input[type="number"]:focus,
        .card.form-section form input[type="url"]:focus,
        .card.form-section form input[type="file"]:focus,
        .card.form-section form select:focus,
        .card.form-section form textarea:focus {
            border-color: var(--color-primary);
            outline: none;
        }

        .card.form-section form button {
            width: auto;
            margin-top: 1rem;
        }
        /* Style for file input label */
        .file-input-label {
            display: block;
            width: 100%;
            padding: 0.8rem;
            background: var(--color-light);
            border-radius: var(--border-radius-1);
            text-align: center;
            cursor: pointer;
            margin-bottom: 1rem;
            color: var(--color-dark-variant);
            font-weight: 500;
            transition: background 0.3s ease;
        }
        .file-input-label:hover {
            background: var(--color-primary-variant);
            color: var(--color-white);
        }

        /* Styles for dynamic quiz questions */
        .question-item, .bank-sampah-type-item { /* Added bank-sampah-type-item */
            background: var(--color-light);
            padding: 1.2rem;
            border-radius: var(--border-radius-2);
            margin-bottom: 1.5rem;
            border: 1px solid var(--color-info-light);
        }

        .question-item .question-header, .bank-sampah-type-item .item-header { /* Added item-header */
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .question-item .question-header h4, .bank-sampah-type-item .item-header h4 { /* Added item-header h4 */
            color: var(--color-primary-variant);
        }

        .options-container > label, .waste-types-container > label { /* Added waste-types-container */
            font-weight: 600;
            color: var(--color-dark-variant);
            margin-bottom: 0.8rem; /* Space below this label */
            display: block; /* Make it block to take its own line */
        }

        .option-item, .type-price-item { /* Added type-price-item */
            display: flex; /* Use flexbox for horizontal alignment */
            align-items: center;
            gap: 10px; /* Space between elements in an option row */
            margin-bottom: 10px; /* Space between option rows */
            background: var(--color-white);
            padding: 8px 15px; /* Padding for each option item */
            border-radius: var(--border-radius-1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); /* Subtle shadow for options */
            position: relative; /* For absolute positioning of remove button if needed */
        }

        .option-item .correct-answer-toggle {
            display: flex;
            align-items: center;
            flex-shrink: 0; /* Don't shrink */
            width: 100px; /* Lebar tetap untuk "Jawaban Benar" */
            font-weight: 500;
            color: var(--color-dark-variant);
            gap: 5px; /* Space between text and icon */
        }

        .option-item .correct-answer-toggle .material-icons-sharp {
            font-size: 24px;
            color: var(--color-info-dark); /* Default color for unchecked */
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .option-item .correct-answer-toggle.checked .material-icons-sharp {
            color: var(--color-primary); /* Color when checked */
        }

        .option-item input[type="text"], .option-item select, .option-item input[type="number"],
        .type-price-item input[type="text"], .type-price-item select, .type-price-item input[type="number"] { /* Updated for game items and bank sampah items */
            flex-grow: 1; /* Allow input to take remaining space */
            margin-bottom: 0; /* Override default form input margin */
            border: 1px solid var(--color-info-light);
            padding: 0.6rem;
            border-radius: var(--border-radius-1);
            background: var(--color-background); /* Lighter background for input */
            color: var(--color-dark);
        }

        .option-item input[type="text"]:focus, .option-item select:focus, .option-item input[type="number"]:focus,
        .type-price-item input[type="text"]:focus, .type-price-item select:focus, .type-price-item input[type="number"]:focus { /* Updated for game items and bank sampah items */
            border-color: var(--color-primary);
            outline: none;
        }

        .option-item .remove-option-btn, .type-price-item .remove-type-btn { /* Added remove-type-btn */
            background: none;
            border: none;
            padding: 0;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--color-danger);
            transition: color 0.2s ease;
            flex-shrink: 0; /* Don't shrink */
        }
        .option-item .remove-option-btn:hover, .type-price-item .remove-type-btn:hover { /* Added remove-type-btn hover */
            color: #b33939;
        }

        .question-item .add-option-btn, .bank-sampah-type-item .add-type-btn { /* Added add-type-btn */
            background: var(--color-info-light);
            color: var(--color-dark-variant);
            padding: 0.6rem 1rem;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        .question-item .add-option-btn:hover, .bank-sampah-type-item .add-type-btn:hover { /* Added add-type-btn hover */
            background: var(--color-primary-variant);
            color: var(--color-white);
        }


        /* RIGHT SECTION */
        .right {
            margin-top: 1.4rem;
            overflow-y: auto; /* Memungkinkan bagian kanan di-scroll secara independen */
            padding-left: 1rem; /* Ruang di sisi kiri */
            padding-bottom: 2rem; /* Ruang di bagian bawah */
        }

        .right .top {
            display: flex;
            justify-content: end;
            gap: 2rem;
        }

        .right .top button {
            display: none;
        }

        .right .theme-toggler {
            background: var(--color-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 1.6rem;
            width: 4.2rem;
            cursor: pointer;
            border-radius: var(--border-radius-1);
        }

        .right .theme-toggler span {
            font-size: 1.2rem;
            width: 50%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .right .theme-toggler span.active {
            background: var(--color-primary);
            color: var(--color-white);
            border-radius: var(--border-radius-1);
        }

        .right .top .profile {
            display: flex;
            gap: 1rem;
            text-align: right;
            align-items: center;
        }

        /* PEMBARUAN TERBARU (Sidebar Kanan) */
        /* REMOVED: .right .recent-updates styles */


        /* ANALISIS PENJUALAN (Statistik GoRako) */
        .right .sales-analytics {
            margin-top: 2rem;
        }

        .right .sales-analytics h2 {
            margin-bottom: 0.8rem;
            color: var(--color-primary);
        }

        .right .sales-analytics .item {
            background: var(--color-white);
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.7rem;
            padding: 1.4rem var(--card-padding);
            border-radius: var(--border-radius-3);
            box-shadow: var(--box-shadow);
            transition: all 300ms ease;
        }

        .right .sales-analytics .item:hover {
            box-shadow: none;
        }

        .right .sales-analytics .item .right {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: flex-start;
            margin: 0;
            width: 100%;
        }

        .right .sales-analytics .item .icon {
            padding: 0.6rem;
            color: var(--color-white);
            border-radius: 50%;
            background: var(--color-primary);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .right .sales-analytics .item.offline .icon { /* Material Terkumpul */
            background: var(--color-info-dark);
        }

        .right .sales-analytics .item.customers .icon { /* Pengguna Baru */
            background: var(--color-success);
        }

        .right .sales-analytics .item .info h3 {
            margin-bottom: 0.2rem;
        }

        .right .sales-analytics .add-product {
            background-color: transparent;
            border: 2px dashed var(--color-primary);
            color: var(--color-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .right .sales-analytics .add-product div {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            cursor: pointer;
        }

        .right .sales-analytics .add-product div h3 {
            font-weight: 600;
        }

        /* MODAL STYLES */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--color-white);
            padding: 2rem;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            width: 90%;
            max-width: 500px;
            transform: translateY(-50px);
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
            position: relative;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-close-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: var(--color-info-dark);
            transition: color 0.2s ease;
        }
        .modal-close-btn:hover {
            color: var(--danger-color);
        }

        .modal-content h3 {
            margin-bottom: 1.5rem;
            color: var(--color-primary);
            font-size: 1.2rem;
        }
        .modal-content p {
            margin-bottom: 1.5rem;
        }
        .modal-content .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }


        /* TOAST NOTIFICATION STYLES */
        #toast-container {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1001;
            display: flex;
            flex-direction: column-reverse; /* Toast terbaru di atas */
            gap: 0.5rem;
        }

        .toast-message {
            background: var(--color-dark);
            color: var(--color-white);
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-1);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: opacity 0.4s ease-out, transform 0.4s ease-out;
            min-width: 250px;
            max-width: 350px;
        }

        .toast-message.show {
            opacity: 1;
            transform: translateX(0);
        }
        .toast-message.success { background: var(--color-success); }
        .toast-message.error { background: var(--color-danger); }
        .toast-message.warning { background: var(--color-warning); }
        .toast-message.info { background: var(--color-info-dark); }

        /* CUSTOM SCROLLBAR (untuk browser berbasis WebKit) */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px; /* untuk scrollbar horizontal */
        }

        ::-webkit-scrollbar-thumb {
            background-color: var(--color-primary-variant);
            border-radius: 10px;
            border: 2px solid var(--color-background);
        }

        ::-webkit-scrollbar-track {
            background: var(--color-info-light);
            border-radius: 10px;
        }

        /* MEDIA QUERIES */
        @media screen and (max-width: 1200px) {
            .container {
                width: 94%;
                grid-template-columns: 7rem auto 23rem;
            }

            aside .sidebar h3 {
                display: none;
            }

            aside .sidebar a {
                width: 5.6rem;
                margin-left: 0; /* Pastikan menu di layar kecil tidak ada margin kiri tambahan */
            }
            aside .sidebar a.active span {
                margin-left: calc(1rem - 3px); /* Pertahankan indentasi ikon aktif */
            }


            aside .sidebar a:last-child {
                position: relative;
                margin-top: 1.7rem;
            }

            .insights {
                grid-template-columns: repeat(2, 1fr); /* 2 kolom untuk tablet */
                gap: 1rem;
            }

            .recent-data-split {
                grid-template-columns: 1fr; /* Stack secara vertikal di layar lebih kecil */
            }

            .recent-data table {
                padding: 1rem;
            }

            .right {
                width: 94%;
                margin: 0 auto 4rem;
            }
        }

        /* MEDIA QUERIES LAYAR KECIL */
        @media screen and (max-width: 768px) {
            .container {
                width: 100%;
                grid-template-columns: 1fr;
            }

            aside {
                position: fixed;
                left: -100%;
                background: var(--color-white);
                width: 18rem;
                z-index: 3;
                box-shadow: 1rem 3rem 4rem var(--color-light);
                height: 100vh;
                padding-right: 2rem;
                display: none;
                animation: showMenu 400ms ease forwards;
            }

            @keyframes showMenu {
                to {
                    left: 0;
                }
            }

            aside .logo {
                margin-left: 1rem;
            }

            aside .logo h2 {
                display: inline;
            }

            aside .sidebar h3 {
                display: inline;
            }

            aside .sidebar a {
                width: 100%;
                height: 3.9rem;
            }

            aside .sidebar a:last-child {
                position: relative;
                margin-top: 1.7rem;
            }

            aside .close {
                display: inline-block;
                cursor: pointer;
            }

            main {
                margin-top: 8rem;
                padding: 0 1rem;
            }

            .insights {
                grid-template-columns: 1fr;
            }

            .recent-data-split {
                grid-template-columns: 1fr;
            }

            .recent-data table {
                overflow-x: auto;
                display: block;
                white-space: nowrap;
            }
            .recent-data table thead, .recent-data table tbody {
                display: table;
                width: 100%;
                table-layout: fixed; /* Memastikan lebar kolom tetap */
            }

            .right {
                width: 94%;
                margin: 0 auto 4rem;
                padding: 0 1rem;
            }

            .right .top {
                position: fixed;
                top: 0;
                left: 0;
                align-items: center;
                padding: 0 0.8rem;
                height: 4.6rem;
                background: var(--color-white);
                width: 100%;
                margin: 0;
                z-index: 2;
                box-shadow: 0 1rem 1rem var(--color-light);
            }

            .right .top .theme-toggler {
                width: 4.4rem;
                position: absolute;
                left: 66%;
            }

            .right .profile .info {
                display: none;
            }

            .right .top button {
                display: inline-block;
                background: transparent;
                cursor: pointer;
                color: var(--color-dark);
                position: absolute;
                left: 1rem;
            }

            .right .top button span {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <aside>
            <div class="top">
                <div class="logo">
                    <h2>Go<span class="green">Rako</span></h2>
                </div>
                <div class="close" id="close-btn">
                    <span class="material-icons-sharp">close</span>
                </div>
            </div>

            <div class="sidebar">
                <a href="#dashboard" class="active">
                    <span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="#kelola-quiz">
                    <span class="material-icons-sharp">quiz</span>
                    <h3>Kelola Kuis</h3>
                </a>
                <a href="#kelola-hadiah">
                    <span class="material-icons-sharp">card_giftcard</span>
                    <h3>Kelola Hadiah</h3>
                </a>
                <a href="#kelola-modul-edukasi">
                    <span class="material-icons-sharp">menu_book</span>
                    <h3>Kelola Modul Edukasi</h3>
                </a>
                <a href="#kelola-game">
                    <span class="material-icons-sharp">videogame_asset</span>
                    <h3>Kelola Game</h3>
                </a>
                <a href="#kelola-bank-sampah">
                    <span class="material-icons-sharp">location_on</span>
                    <h3>Kelola Bank Sampah</h3>
                </a>
                <a href="#monitor-game"> <span class="material-icons-sharp">gamepad</span>
                    <h3>Monitor Game</h3>
                </a>
                <a href="#pesanan-hadiah">
                    <span class="material-icons-sharp">redeem</span>
                    <h3>Pesanan Hadiah</h3>
                </a>
                <a href="#data-pengguna-full">
                    <span class="material-icons-sharp">people</span>
                    <h3>Data Pengguna (Lengkap)</h3>
                </a>
                <a href="#aktivitas-admin">
                    <span class="material-icons-sharp">history</span>
                    <h3>Aktivitas Admin</h3>
                </a>
                <a href="#pengaturan">
                    <span class="material-icons-sharp">settings</span>
                    <h3>Pengaturan</h3>
                </a>
                <a href="#keluar">
                    <span class="material-icons-sharp">logout</span>
                    <h3>Keluar</h3>
                </a>
            </div>
        </aside>

        <main>
            <h1>Dashboard Admin</h1>

            <?php if (!empty($display_message)): ?>
                <div class="alert <?php echo $display_message_type; ?>" style="padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; background-color: <?php echo $display_message_type === 'success' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $display_message_type === 'success' ? '#155724' : '#721c24'; ?>; border: 1px solid <?php echo $display_message_type === 'success' ? '#c3e6cb' : '#f5c6cb'; ?>;">
                    <?php echo $display_message; ?>
                </div>
            <?php endif; ?>

            <section id="dashboard" class="main-section">
                <div class="insights">
                    <div class="total-users">
                        <span class="material-icons-sharp">group</span>
                        <div class="middle">
                            <div class="left">
                                <h3>Total Pengguna</h3>
                                <h1 id="total-users-count"><?php echo $totalUsersCount; ?></h1>
                            </div>
                        </div>
                    </div>

                    <div class="total-quizzes">
                        <span class="material-icons-sharp">quiz</span>
                        <div class="middle">
                            <div class="left">
                                <h3>Jumlah Kuis</h3>
                                <h1 id="total-quizzes-count"><?php echo $totalQuizzesCount; ?></h1>
                            </div>
                        </div>
                    </div>

                    <div class="claimed-rewards">
                        <span class="material-icons-sharp">redeem</span>
                        <div class="middle">
                            <div class="left">
                                <h3>Hadiah Diklaim</h3>
                                <h1 id="claimed-rewards-count"><?php echo $claimedRewardsCount; ?></h1>
                            </div>
                        </div>
                    </div>

                    <div class="pending-orders">
                        <span class="material-icons-sharp">pending_actions</span>
                        <div class="middle">
                            <div class="left">
                                <h3>Pesanan Tertunda</h3>
                                <h1 id="pending-orders-count"><?php echo $pendingOrdersCount; ?></h1>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="recent-data-split">
                    <div class="recent-data">
                        <h2>Pengguna Terbaru</h2>
                        <table id="users-table">
                            <thead>
                                <tr>
                                    <th>Nama Pengguna</th>
                                    <th>Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentUsersData) > 0): ?>
                                    <?php foreach ($recentUsersData as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" style="text-align: center; padding: 1rem;">Tidak ada pengguna terbaru.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <a href="#data-pengguna-full">Lihat Semua Pengguna</a>
                    </div>

                    <div class="recent-data">
                        <h2>Penukaran Hadiah Terbaru</h2> <table id="exchanges-table"> <thead>
                                <tr>
                                    <th>ID Penukaran</th> <th>Pengguna</th>
                                    <th>Hadiah</th>
                                    <th>Email</th> <th>No. HP</th> <th>Pesan</th> <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentExchangesData) > 0): ?> <?php foreach ($recentExchangesData as $exchange): ?> <tr>
                                            <td><?php echo htmlspecialchars($exchange['id']); ?></td>
                                            <td><?php echo htmlspecialchars($exchange['user_name']); ?></td>
                                            <td><?php echo htmlspecialchars($exchange['reward_name']); ?></td>
                                            <td><?php echo htmlspecialchars($exchange['email_penerima']); ?></td> <td><?php echo htmlspecialchars($exchange['nohp_penerima']); ?></td> <td><?php echo htmlspecialchars($exchange['pesan_tambahan']); ?></td> <td class="<?php
                                                if ($exchange['status'] === 'pending') echo 'warning-text'; // Changed status values
                                                elseif ($exchange['status'] === 'sent' || $exchange['status'] === 'delivered') echo 'success-text';
                                                elseif ($exchange['status'] === 'rejected') echo 'danger-text';
                                                else echo 'primary-text';
                                            ?>"><?php echo htmlspecialchars(ucfirst($exchange['status'])); ?></td> <td>
                                                <select onchange="updateExchangeStatusAjax(<?php echo $exchange['id']; ?>, this.value)"> <option value="pending" <?php echo ($exchange['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="approved" <?php echo ($exchange['status'] === 'approved') ? 'selected' : ''; ?>>Disetujui</option>
                                                    <option value="sent" <?php echo ($exchange['status'] === 'sent') ? 'selected' : ''; ?>>Dikirim</option>
                                                    <option value="delivered" <?php echo ($exchange['status'] === 'delivered') ? 'selected' : ''; ?>>Diterima</option>
                                                    <option value="rejected" <?php echo ($exchange['status'] === 'rejected') ? 'selected' : ''; ?>>Ditolak</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" style="text-align: center; padding: 1rem;">Tidak ada penukaran hadiah terbaru.</td></tr> <?php endif; ?>
                            </tbody>
                        </table>
                        <a href="#pesanan-hadiah">Lihat Semua Penukaran</a> </div>
                </div>
            </section>

            <section id="kelola-quiz" class="main-section" style="display: none;">
                <h2>Kelola Kuis</h2>
                <div class="card form-section">
                    <h3>Tambah Kuis Baru</h3>
                    <form id="add-quiz-form">
                        <input type="hidden" name="action" value="add_quiz">

                        <label for="quiz-title">Judul Kuis:</label>
                        <input type="text" id="quiz-title" name="title" placeholder="Masukkan judul kuis" required>

                        <label for="quiz-description">Deskripsi:</label>
                        <textarea id="quiz-description" name="description" placeholder="Deskripsi singkat kuis" required></textarea>

                        <label for="quiz-category">Kategori:</label>
                        <input type="text" id="quiz-category" name="category" placeholder="Contoh: Pemilahan Sampah" required>

                        <label for="points-per-question">Poin per Soal:</label>
                        <input type="number" id="points-per-question" name="points_per_question" min="1" value="10" required>

                        <h3 style="margin-top: 2rem;">Daftar Soal Kuis</h3>
                        <div id="questions-container">
                            </div>
                        <button type="button" class="btn primary-btn btn-small" onclick="addQuestionField()">
                            <span class="material-icons-sharp">add_circle_outline</span> Tambah Soal
                        </button>
                        <p class="text-muted" style="margin-top: 1rem;">*Untuk pilihan ganda, tandai satu atau lebih opsi jawaban sebagai 'Jawaban Benar'.</p>

                        <button type="submit" class="btn primary-btn" id="add-quiz-btn">
                            Buat Kuis <span class="material-icons-sharp spinner">autorenew</span>
                        </button>
                    </form>
                </div>
                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Daftar Kuis</h3>
                    <table id="quiz-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Judul Kuis</th>
                                <th>Kategori</th>
                                <th>Poin Per Soal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allQuizzesData) > 0): ?>
                                <?php foreach ($allQuizzesData as $quiz): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($quiz['id']); ?></td>
                                        <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                                        <td><?php echo htmlspecialchars($quiz['category']); ?></td>
                                        <td><?php echo htmlspecialchars($quiz['points_per_question']); ?></td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="window.location.href='membuat_quiz.php?edit_id=<?php echo $quiz['id']; ?>'">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteQuizAjax(<?php echo $quiz['id']; ?>)">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 1rem;">Belum ada kuis.</td></tr>
                            <?php endif; ?>
                            </tbody>
                    </table>
                </div>
            </section>

            <section id="kelola-hadiah" class="main-section" style="display: none;">
                <h2>Kelola Hadiah</h2>
                <button class="btn primary-btn" onclick="window.location.href = 'membuat_reward.php'">
                    Tambah Hadiah Baru
                </button>
                <div class="recent-data">
                    <h3>Daftar Hadiah</h3>
                    <table id="rewards-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Hadiah</th>
                                <th>Poin</th>
                                <th>Stok</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allRewardsData) > 0): ?>
                                <?php foreach ($allRewardsData as $reward): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reward['id']); ?></td>
                                        <td><?php echo htmlspecialchars($reward['name']); ?></td>
                                        <td><?php echo htmlspecialchars($reward['points_needed']); ?></td>
                                        <td><?php echo htmlspecialchars($reward['stock']); ?></td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="window.location.href='membuat_reward.php?edit_id=<?php echo $reward['id']; ?>'">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteRewardAjax(<?php echo $reward['id']; ?>)">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 1rem;">Belum ada hadiah.</td></tr>
                            <?php endif; ?>
                            </tbody>
                    </table>
                </div>
            </section>

            <section id="kelola-modul-edukasi" class="main-section" style="display: none;">
                <h2>Kelola Modul Edukasi</h2>
                <div class="card form-section">
                    <h3>Tambah Modul Baru</h3>
                    <form id="add-module-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_module">
                        <label for="module-type">Jenis Modul:</label>
                        <select id="module-type" name="module_type" required onchange="toggleModuleFields()">
                            <option value="">Pilih Jenis Modul</option>
                            <option value="research">Modul Riset (Bacaan)</option>
                            <option value="video">Modul Video</option>
                        </select>

                        <label for="module-title">Judul Modul:</label>
                        <input type="text" id="module-title" name="title" placeholder="Masukkan judul modul" required>

                        <label for="module-description">Deskripsi:</label>
                        <textarea id="module-description" name="description" placeholder="Deskripsi singkat modul" required></textarea>

                        <label for="points-reward">Poin Hadiah:</label>
                        <input type="number" id="points-reward" name="points_reward" min="0" value="0" required>

                        <div id="research-fields" style="display: none;">
                            <label for="content-type">Tipe Konten:</label>
                            <select id="content-type" name="content_type" onchange="toggleResearchContentFields()">
                                <option value="">Pilih Tipe Konten</option>
                                <option value="text">Teks Langsung</option>
                                <option value="url">URL Eksternal</option>
                                <option value="pdf">Upload PDF</option>
                            </select>

                            <div id="text-content-field" style="display: none;">
                                <label for="text-content">Isi Teks Modul:</label>
                                <textarea id="text-content" name="text_content" placeholder="Masukkan isi teks modul"></textarea>
                            </div>

                            <div id="content-url-field" style="display: none;">
                                <label for="content-url">URL Konten Eksternal:</label>
                                <input type="url" id="content-url" name="content_url" placeholder="Contoh: https://example.com/artikel-sampah.html">
                            </div>

                            <div id="pdf-upload-field" style="display: none;">
                                <label for="pdf-file" class="file-input-label">Upload File PDF</label>
                                <input type="file" id="pdf-file" name="pdf_file" accept=".pdf" style="display:none;">
                                <small id="pdf-file-name" class="text-muted"></small>
                            </div>

                            <label for="estimated-minutes">Perkiraan Waktu Baca (menit):</label>
                            <input type="number" id="estimated-minutes" name="estimated_minutes" min="1" value="10">
                        </div>

                        <div id="video-fields" style="display: none;">
                            <label for="video-type">Sumber Video:</label>
                            <select id="video-type" name="video_type" onchange="toggleVideoSourceFields()">
                                <option value="">Pilih Sumber Video</option>
                                <option value="youtube">YouTube URL</option>
                                <option value="vimeo">Vimeo URL</option>
                                <option value="upload">Upload Video File</option>
                            </select>

                            <div id="video-url-field" style="display: none;">
                                <label for="video-url">URL Video (YouTube/Vimeo):</label>
                                <input type="url" id="video-url" name="video_url" placeholder="Contoh: https://www.youtube.com/watch?v=xxxxxxxxxxx">
                            </div>

                            <div id="video-upload-field" style="display: none;">
                                <label for="video-file" class="file-input-label">Upload File Video</label>
                                <input type="file" id="video-file" name="video_file" accept="video/mp4,video/webm,video/ogg,video/quicktime" style="display:none;">
                                <small id="video-file-name" class="text-muted"></small>
                            </div>

                            <label for="duration-minutes">Durasi Video (menit):</label>
                            <input type="number" id="duration-minutes" name="duration_minutes" min="1" value="5">

                            <div id="thumbnail-upload-field">
                                <label for="thumbnail-file" class="file-input-label">Upload Gambar Thumbnail (Opsional)</label>
                                <input type="file" id="thumbnail-file" name="thumbnail_file" accept="image/jpeg,image/png,image/gif" style="display:none;">
                                <small id="thumbnail-file-name" class="text-muted"></small>
                            </div>
                            </div>

                        <button type="submit" class="btn primary-btn" id="add-module-btn">
                            Tambah Modul <span class="material-icons-sharp spinner">autorenew</span>
                        </button>
                    </form>
                </div>

                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Daftar Modul Riset</h3>
                    <table id="research-modules-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Judul</th>
                                <th>Tipe Konten</th>
                                <th>Poin</th>
                                <th>Waktu (menit)</th>
                                <th>Aktif</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allResearchModulesData) > 0): ?>
                                <?php foreach ($allResearchModulesData as $module): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($module['id']); ?></td>
                                        <td><?php echo htmlspecialchars($module['title']); ?></td>
                                        <td><?php echo htmlspecialchars($module['content_type']); ?></td>
                                        <td><?php echo htmlspecialchars($module['points_reward']); ?></td>
                                        <td><?php echo htmlspecialchars($module['estimated_minutes']); ?></td>
                                        <td class="<?php echo $module['is_active'] ? 'success-text' : 'danger-text'; ?>">
                                            <?php echo $module['is_active'] ? 'Ya' : 'Tidak'; ?>
                                        </td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="showToast('Fitur edit modul riset akan datang!', 'info')">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteModuleAjax(<?php echo $module['id']; ?>, 'research')">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 1rem;">Belum ada modul riset.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Daftar Modul Video</h3>
                    <table id="video-modules-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Judul</th>
                                <th>Tipe Video</th>
                                <th>Poin</th>
                                <th>Durasi (menit)</th>
                                <th>Aktif</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allVideoModulesData) > 0): ?>
                                <?php foreach ($allVideoModulesData as $module): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($module['id']); ?></td>
                                        <td><?php echo htmlspecialchars($module['title']); ?></td>
                                        <td><?php echo htmlspecialchars($module['video_type']); ?></td>
                                        <td><?php echo htmlspecialchars($module['points_reward']); ?></td>
                                        <td><?php echo htmlspecialchars($module['duration_minutes']); ?></td>
                                        <td class="<?php echo $module['is_active'] ? 'success-text' : 'danger-text'; ?>">
                                            <?php echo $module['is_active'] ? 'Ya' : 'Tidak'; ?>
                                        </td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="showToast('Fitur edit modul video akan datang!', 'info')">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteModuleAjax(<?php echo $module['id']; ?>, 'video')">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 1rem;">Belum ada modul video.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="kelola-game" class="main-section" style="display: none;">
                <h2>Kelola Game</h2>
                <div class="card form-section">
                    <h3>Tambah Level Game Baru</h3>
                    <form id="add-game-level-form">
                        <input type="hidden" name="action" value="add_game_level">

                        <label for="game-level-name">Nama Level:</label>
                        <input type="text" id="game-level-name" name="level_name" placeholder="Contoh: Level 1 - Pemula" required>

                        <label for="game-level-description">Deskripsi Level:</label>
                        <textarea id="game-level-description" name="description" placeholder="Deskripsi singkat level game ini"></textarea>

                        <label for="game-duration-seconds">Durasi Permainan (detik):</label>
                        <input type="number" id="game-duration-seconds" name="duration_seconds" min="10" value="60" required>

                        <label for="game-points-per-correct-sort">Poin per Sortir Benar:</label>
                        <input type="number" id="game-points-per-correct-sort" name="points_per_correct_sort" min="1" value="10" required>

                        <h3 style="margin-top: 2rem;">Konfigurasi Item Sampah</h3>
                        <div id="game-items-container">
                            </div>
                        <button type="button" class="btn primary-btn btn-small" onclick="addGameItemField()">
                            <span class="material-icons-sharp">add_circle_outline</span> Tambah Item Sampah
                        </button>
                        <p class="text-muted" style="margin-top: 1rem;">*Tentukan jenis dan jumlah sampah untuk level ini (organik, plastik, kertas).</p>

                        <button type="submit" class="btn primary-btn" id="add-game-level-btn">
                            Tambah Level Game <span class="material-icons-sharp spinner">autorenew</span>
                        </button>
                    </form>
                </div>

                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Daftar Level Game</h3>
                    <table id="game-levels-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Level</th>
                                <th>Durasi (dtk)</th>
                                <th>Poin/Sortir</th>
                                <th>Item Config</th>
                                <th>Aktif</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allGameLevelsData) > 0): ?>
                                <?php foreach ($allGameLevelsData as $level): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($level['id']); ?></td>
                                        <td><?php echo htmlspecialchars($level['level_name']); ?></td>
                                        <td><?php echo htmlspecialchars($level['duration_seconds']); ?></td>
                                        <td><pre><?php echo htmlspecialchars(json_encode(json_decode($level['item_config_json']), JSON_PRETTY_PRINT)); ?></pre></td>
                                        <td class="<?php echo $level['is_active'] ? 'success-text' : 'danger-text'; ?>">
                                            <?php echo $level['is_active'] ? 'Ya' : 'Tidak'; ?>
                                        </td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="showToast('Fitur edit level game akan datang!', 'info')">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteGameLevelAjax(<?php echo $level['id']; ?>)">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 1rem;">Belum ada level game.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="kelola-bank-sampah" class="main-section" style="display: none;">
                <h2>Kelola Lokasi Bank Sampah</h2>
                <div class="card form-section">
                    <h3>Tambah Lokasi Bank Sampah Baru</h3>
                    <form id="add-drop-point-form">
                        <input type="hidden" name="action" value="add_drop_point">

                        <label for="dp-name">Nama Bank Sampah:</label>
                        <input type="text" id="dp-name" name="name" placeholder="Contoh: Bank Sampah Hijau Lestari" required>

                        <label for="dp-address">Alamat Lengkap:</label>
                        <textarea id="dp-address" name="address" placeholder="Contoh: Jl. Raya Kebersihan No. 12, Jakarta Pusat" required></textarea>

                        <label for="dp-pin-code">PIN Verifikasi (Untuk Pengguna):</label>
                        <input type="text" id="dp-pin-code" name="pin_code" placeholder="Contoh: 123456" required maxlength="6"> <label for="dp-latitude">Latitude:</label>
                        <input type="number" id="dp-latitude" name="latitude" placeholder="Contoh: -6.2088" step="any" required>

                        <label for="dp-longitude">Longitude:</label>
                        <input type="number" id="dp-longitude" name="longitude" placeholder="Contoh: 106.8456" step="any" required>

                        <label for="dp-description">Deskripsi (Opsional):</label>
                        <textarea id="dp-description" name="description" placeholder="Informasi tambahan tentang bank sampah ini"></textarea>

                        <h3 style="margin-top: 2rem;">Jenis Sampah Diterima & Harga</h3>
                        <div id="waste-types-container">
                            </div>
                        <button type="button" class="btn primary-btn btn-small" onclick="addWasteTypeField()">
                            <span class="material-icons-sharp">add_circle_outline</span> Tambah Jenis Sampah
                        </button>
                        <p class="text-muted" style="margin-top: 1rem;">*Tentukan jenis sampah yang diterima dan harga per kilogram.</p>

                        <button type="submit" class="btn primary-btn" id="add-drop-point-btn">
                            Tambah Lokasi <span class="material-icons-sharp spinner">autorenew</span>
                        </button>
                    </form>
                </div>

                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Daftar Lokasi Bank Sampah</h3>
                    <table id="drop-points-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama</th>
                                <th>Alamat</th>
                                <th>PIN Hash</th> <th>Latitude</th>
                                <th>Longitude</th>
                                <th>Jenis Sampah</th>
                                <th>Harga</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($allDropPointsData) > 0): ?>
                                <?php foreach ($allDropPointsData as $dp): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dp['id']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['name']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['address']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['pin_hash']); ?></td> <td><?php echo htmlspecialchars($dp['latitude']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['longitude']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['types']); ?></td>
                                        <td><?php echo htmlspecialchars($dp['prices']); ?></td>
                                        <td>
                                            <button class="btn primary-btn btn-small" onclick="showToast('Fitur edit lokasi bank sampah akan datang!', 'info')">Edit</button>
                                            <button class="btn danger-btn btn-small" onclick="confirmDeleteDropPointAjax(<?php echo $dp['id']; ?>)">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align: center; padding: 1rem;">Belum ada lokasi bank sampah.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="monitor-game" class="main-section" style="display: none;">
                <h2>Monitor Game Simulasi Daur Ulang</h2>
                <div class="recent-data">
                    <h3>Status Game Pengguna</h3>
                    <table id="user-game-states-table">
                        <thead>
                            <tr>
                                <th>ID Pengguna</th>
                                <th>Nama Pengguna</th>
                                <th>Email</th>
                                <th>Poin Game</th>
                                <th>Hari Game</th>
                                <th>Game Over?</th>
                                <th>Aktivitas Terakhir Game</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($fullUserGameStatesData) > 0): ?>
                                <?php foreach ($fullUserGameStatesData as $state): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($state['user_id']); ?></td>
                                        <td><?php echo htmlspecialchars($state['username']); ?></td>
                                        <td><?php echo htmlspecialchars($state['email']); ?></td>
                                        <td><?php echo number_format(htmlspecialchars($state['player_money'] ?? 0)); ?></td>
                                        <td><?php echo htmlspecialchars($state['current_day'] ?? 'N/A'); ?></td>
                                        <td class="<?php echo ($state['is_game_over'] ?? 0) ? 'danger-text' : 'success-text'; ?>">
                                            <?php echo ($state['is_game_over'] ?? 0) ? 'Ya' : 'Tidak'; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($state['last_game_activity'] ? date('Y-m-d H:i:s', strtotime($state['last_game_activity'])) : 'Tidak ada'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 1rem;">Tidak ada data game pengguna.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="recent-data" style="margin-top: 2rem;">
                    <h3>Aktivitas Game Terbaru</h3>
                    <div class="updates" id="game-activity-updates">
                        <?php if (count($recentGameActivitiesData) > 0): ?>
                            <?php foreach ($recentGameActivitiesData as $activity): ?>
                                <div class="update">
                                    <div class="profile-photo">
                                        <span class="material-icons-sharp">play_arrow</span> </div>
                                    <div class="message">
                                        <p><b><?php echo htmlspecialchars($activity['user_name']); ?></b> pada <b><?php echo date('Y-m-d H:i:s', strtotime($activity['activity_time'])); ?></b>: <?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="update">
                                <div class="profile-photo">
                                    <span class="material-icons-sharp">info</span>
                                </div>
                                <div class="message">
                                    <p>Belum ada aktivitas game terbaru.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <section id="pesanan-hadiah" class="main-section" style="display: none;">
                <h2>Penukaran Hadiah (Lengkap)</h2> <div class="recent-data">
                    <table id="full-exchanges-table"> <thead>
                            <tr>
                                <th>ID Penukaran</th> <th>Pengguna</th>
                                <th>Hadiah</th>
                                <th>Email</th> <th>No. HP</th> <th>Pesan</th> <th>Tanggal</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($fullExchangesData) > 0): ?> <?php foreach ($fullExchangesData as $exchange): ?> <tr>
                                        <td><?php echo htmlspecialchars($exchange['id']); ?></td>
                                        <td><?php echo htmlspecialchars($exchange['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($exchange['reward_name']); ?></td>
                                        <td><?php echo htmlspecialchars($exchange['email_penerima']); ?></td> <td><?php echo htmlspecialchars($exchange['nohp_penerima']); ?></td> <td><?php echo htmlspecialchars($exchange['pesan_tambahan']); ?></td> <td><?php echo date('Y-m-d H:i', strtotime($exchange['exchange_date'])); ?></td> <td class="<?php
                                            if ($exchange['status'] === 'pending') echo 'warning-text'; // Changed status values
                                            elseif ($exchange['status'] === 'sent' || $exchange['status'] === 'delivered') echo 'success-text';
                                            elseif ($exchange['status'] === 'rejected') echo 'danger-text';
                                            else echo 'primary-text';
                                        ?>"><?php echo htmlspecialchars(ucfirst($exchange['status'])); ?></td> <td>
                                            <select onchange="updateExchangeStatusAjax(<?php echo $exchange['id']; ?>, this.value)"> <option value="pending" <?php echo ($exchange['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                <option value="approved" <?php echo ($exchange['status'] === 'approved') ? 'selected' : ''; ?>>Disetujui</option>
                                                <option value="sent" <?php echo ($exchange['status'] === 'sent') ? 'selected' : ''; ?>>Dikirim</option>
                                                <option value="delivered" <?php echo ($exchange['status'] === 'delivered') ? 'selected' : ''; ?>>Diterima</option>
                                                <option value="rejected" <?php echo ($exchange['status'] === 'rejected') ? 'selected' : ''; ?>>Ditolak</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align: center; padding: 1rem;">Tidak ada penukaran hadiah.</td></tr> <?php endif; ?>
                            </tbody>
                    </table>
                </div>
            </section>

            <section id="data-pengguna-full" class="main-section" style="display: none;">
                <h2>Data Pengguna (Lengkap)</h2>
                <div class="recent-data">
                    <table id="full-users-table">
                        <thead>
                            <tr>
                                <th>ID Pengguna</th>
                                <th>Nama Pengguna</th>
                                <th>Email</th>
                                <th>Total Poin</th>
                                <th>Bergabung</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($fullUsersData) > 0): ?>
                                <?php foreach ($fullUsersData as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['total_points']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 1rem;">Tidak ada pengguna.</td></tr>
                            <?php endif; ?>
                            </tbody>
                    </table>
                </div>
            </section>

            <section id="aktivitas-admin" class="main-section" style="display: none;">
                <h2>Aktivitas Admin Terbaru</h2>
                <div class="recent-data">
                    <div class="updates" id="admin-activity-updates">
                        <?php if (count($adminActivitiesData) > 0): ?>
                            <?php foreach ($adminActivitiesData as $activity): ?>
                                <div class="update">
                                    <div class="profile-photo">
                                        <span class="material-icons-sharp">build</span>
                                    </div>
                                    <div class="message">
                                        <p><b><?php echo date('H:i', strtotime($activity['activity_time'])); ?></b>: <?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="update">
                                <div class="profile-photo">
                                    <span class="material-icons-sharp">info</span>
                                </div>
                                <div class="message">
                                    <p>Belum ada aktivitas admin terbaru.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                </div>
            </section>

            <section id="pengaturan" class="main-section" style="display: none;">
                <h2>Pengaturan Sistem</h2>
                <div class="card form-section">
                    <p>Halaman ini berisi pengaturan umum untuk aplikasi GoRako. Anda dapat mengelola berbagai opsi sistem di sini.</p>
                    <p>Contoh fungsionalitas: manajemen notifikasi, konfigurasi misi default, batasan pengguna, dan opsi branding.</p>
                    <button class="btn primary-btn">Simpan Pengaturan</button>
                </div>
            </section>

            <section id="keluar" class="main-section" style="display: none;">
                <h2>Keluar dari Dashboard</h2>
                <div class="card form-section">
                    <p>Anda akan keluar dari sesi admin Anda. Pastikan semua perubahan telah disimpan.</p>
                    <button class="btn danger-btn" onclick="confirmLogout()">Konfirmasi Keluar</button>
                </div>
            </section>

        </main>

        <div class="right">
            <div class="top">
                <button id="menu-btn" aria-label="Buka Menu Samping">
                    <span class="material-icons-sharp">menu</span>
                </button>
                <div class="theme-toggler" role="button" tabindex="0" aria-label="Toggle Tema Terang/Gelap">
                    <span class="material-icons-sharp active" aria-hidden="true">light_mode</span>
                    <span class="material-icons-sharp" aria-hidden="true">dark_mode</span>
                </div>
                <div class="profile">
                    <div class="info">
                        <p>Halo, <b>Admin GoRako</b></p>
                        <small class="text-muted">Administrator</small>
                    </div>
                    <br>
                </div>
            </div>
            <div class="sales-analytics">
                <h2>Statistik Utama</h2> <div class="item online">
                    <div class="icon">
                        <span class="material-icons-sharp">paid</span> </div>
                    <div class="right">
                        <div class="info">
                            <h3>TOTAL POIN PENGGUNA</h3>
                            <small class="text-muted">Keseluruhan</small>
                        </div>
                        <h3 class="primary-text"><?php echo number_format($totalUserPoints); ?></h3>
                    </div>
                </div>
                <div class="item customers">
                    <div class="icon">
                        <span class="material-icons-sharp">auto_stories</span> </div>
                    <div class="right">
                        <div class="info">
                            <h3>TOTAL MODUL EDUKASI</h3>
                            <small class="text-muted">Riset & Video</small>
                        </div>
                        <h3 class="success-text"><?php echo $totalModulesCount; ?></h3>
                    </div>
                </div>
                <div class="item offline">
                    <div class="icon">
                        <span class="material-icons-sharp">quiz</span> </div>
                    <div class="right">
                        <div class="info">
                            <h3>TOTAL KUIS</h3>
                            <small class="text-muted">Aktif & Nonaktif</small>
                        </div>
                        <h3 class="warning-text"><?php echo $totalQuizzesCount; ?></h3>
                    </div>
                </div>
                <div class="item add-product" onclick="showToast('Fitur penambahan misi baru akan datang!', 'info')">
                    <div>
                        <span class="material-icons-sharp">add_circle_outline</span>
                        <h3>Tambah Misi Baru</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="confirm-action-modal">
        <div class="modal-content">
            <button class="modal-close-btn" onclick="closeModal('confirm-action-modal')" aria-label="Tutup Modal Konfirmasi">
                <span class="material-icons-sharp">close</span>
            </button>
            <h3 id="confirm-modal-title">Konfirmasi Aksi</h3>
            <p id="confirm-modal-message">Apakah Anda yakin ingin melanjutkan aksi ini?</p>
            <div class="modal-actions">
                <button class="btn danger-btn" id="confirm-modal-cancel">Batal</button>
                <button class="btn primary-btn" id="confirm-modal-confirm">Konfirmasi</button>
            </div>
        </div>
    </div>

    <div id="toast-container"></div>

    <script>
        // --- DOM Elements ---
        const menuBtn = document.querySelector('#menu-btn');
        const closeBtn = document.querySelector('#close-btn');
        const aside = document.querySelector('aside');
        const themeToggler = document.querySelector('.theme-toggler');
        const toastContainer = document.getElementById('toast-container');

        // Modal Elements
        const confirmActionModal = document.getElementById('confirm-action-modal');
        const confirmModalTitle = document.getElementById('confirm-modal-title');
        const confirmModalMessage = document.getElementById('confirm-modal-message');
        const confirmModalCancelBtn = document.getElementById('confirm-modal-cancel');
        const confirmModalConfirmBtn = document.getElementById('confirm-modal-confirm');

        // Module Form Elements
        const addModuleForm = document.getElementById('add-module-form');
        const moduleTypeSelect = document.getElementById('module-type');
        const researchFields = document.getElementById('research-fields');
        const videoFields = document.getElementById('video-fields');
        const contentTypeSelect = document.getElementById('content-type');
        const textContentField = document.getElementById('text-content-field');
        const contentUrlField = document.getElementById('content-url-field');
        const pdfUploadField = document.getElementById('pdf-upload-field');
        const pdfFileName = document.getElementById('pdf-file-name');
        const videoTypeSelect = document.getElementById('video-type');
        const videoUrlField = document.getElementById('video-url-field');
        const videoUploadField = document.getElementById('video-upload-field');
        const videoFileName = document.getElementById('video-file-name');
        const addModuleBtn = document.getElementById('add-module-btn');

        // NEW: Thumbnail elements
        const thumbnailUploadField = document.getElementById('thumbnail-upload-field');
        const thumbnailFileName = document.getElementById('thumbnail-file-name');


        // Quiz form elements
        const addQuizForm = document.getElementById('add-quiz-form');
        const addQuizBtn = document.getElementById('add-quiz-btn');
        const questionsContainer = document.getElementById('questions-container');

        let questionCounter = 0; // Untuk ID unik setiap soal

        // Game Form Elements
        const addGameLevelForm = document.getElementById('add-game-level-form');
        const addGameLevelBtn = document.getElementById('add-game-level-btn');
        const gameItemsContainer = document.getElementById('game-items-container');

        let gameItemCounter = 0; // For unique IDs for game item rows

        // NEW: Bank Sampah Form Elements
        const addDropPointForm = document.getElementById('add-drop-point-form');
        const addDropPointBtn = document.getElementById('add-drop-point-btn');
        const wasteTypesContainer = document.getElementById('waste-types-container');

        let wasteTypeCounter = 0; // For unique IDs for waste type rows


        // --- Fungsionalitas Umum UI ---

        // Toggle Sidebar
        menuBtn.addEventListener('click', () => {
            aside.style.display = 'block';
        });

        closeBtn.addEventListener('click', () => {
            aside.style.display = 'none';
        });

        // Toggle Tema Terang/Gelap
        themeToggler.addEventListener('click', () => {
            document.body.classList.toggle('dark-theme-variables');
            themeToggler.querySelector('span:nth-child(1)').classList.toggle('active');
            themeToggler.querySelector('span:nth-child(2)').classList.toggle('active');
        });

        // Tampilkan Notifikasi Toast
        function showToast(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.classList.add('toast-message', type);
            toast.textContent = message;

            toastContainer.appendChild(toast);

            // Trigger reflow for animation
            void toast.offsetWidth; // Force reflow

            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove());
            }, duration);
        }

        // Buka Modal Umum (untuk konfirmasi saja, bukan lagi form input)
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.getElementById(modalId).querySelector('.modal-content').focus();
        }

        // Tutup Modal Umum
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // --- Modal Konfirmasi Universal ---
        function showCustomConfirm(title, message, confirmText, callback) {
            confirmModalTitle.textContent = title;
            confirmModalMessage.textContent = message;
            confirmModalConfirmBtn.textContent = confirmText;

            confirmModalConfirmBtn.onclick = null;
            confirmModalCancelBtn.onclick = null;

            confirmModalConfirmBtn.onclick = () => {
                callback();
                closeModal('confirm-action-modal');
            };
            confirmModalCancelBtn.onclick = () => {
                closeModal('confirm-action-modal');
            };

            openModal('confirm-action-modal');
        }

        // --- Fungsionalitas Data & Tampilan Dashboard (dari PHP) ---

        // Perbarui Status Penukaran Hadiah via AJAX (Renamed function)
        function updateExchangeStatusAjax(exchangeId, newStatus) { // Renamed function and variables
            showCustomConfirm(
                'Ubah Status Penukaran', // Changed title
                `Anda yakin ingin mengubah status penukaran ID ${exchangeId} menjadi "${newStatus}"?`, // Changed message
                'Ubah Status',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=update_exchange_status&id=${exchangeId}&status=${newStatus}` // Changed action name
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Update Exchange Status Response:", data); // Renamed log
                        if (data.status === "success") {
                            showToast(data.message || `Status penukaran ${exchangeId} berhasil diubah menjadi ${newStatus}.`, 'success'); // Changed message
                            location.reload();
                        } else {
                            showToast('Gagal mengubah status penukaran: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error'); // Changed message
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat memperbarui status penukaran.', 'error'); // Changed message
                    });
                }
            );
        }

        // Konfirmasi Hapus Kuis via AJAX
        function confirmDeleteQuizAjax(quizId) {
            showCustomConfirm(
                'Hapus Kuis',
                `Apakah Anda yakin ingin menghapus kuis dengan ID: ${quizId}? Ini akan menghapus semua pertanyaan dan opsi yang terkait. Aksi ini tidak dapat dibatalkan.`,
                'Hapus Kuis',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_quiz&id=${quizId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Delete Quiz Response:", data);
                        if (data.status === "success") {
                            showToast(data.message || 'Kuis berhasil dihapus!', 'success');
                            location.reload();
                        } else {
                            showToast('Gagal menghapus kuis: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat menghapus kuis.', 'error');
                    });
                }
            );
        }

        // Konfirmasi Hapus Hadiah via AJAX
        function confirmDeleteRewardAjax(rewardId) {
            showCustomConfirm(
                'Hapus Hadiah',
                `Apakah Anda yakin ingin menghapus hadiah dengan ID: ${rewardId}? Aksi ini tidak dapat dibatalkan.`,
                'Hapus Hadiah',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_reward&id=${rewardId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Delete Reward Response:", data);
                        if (data.status === "success") {
                            showToast(data.message || 'Hadiah berhasil dihapus!', 'success');
                            location.reload();
                        } else {
                            showToast('Gagal menghapus hadiah: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat menghapus hadiah.', 'error');
                    });
                }
            );
        }

        // Konfirmasi Hapus Modul Edukasi via AJAX
        function confirmDeleteModuleAjax(moduleId, moduleType) {
            showCustomConfirm(
                'Hapus Modul Edukasi',
                `Apakah Anda yakin ingin menghapus modul ${moduleType} dengan ID: ${moduleId}? Aksi ini tidak dapat dibatalkan.`,
                'Hapus Modul',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_module&id=${moduleId}&type=${moduleType}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Delete Module Response:", data);
                        if (data.status === "success") {
                            showToast(data.message || 'Modul berhasil dihapus!', 'success');
                            location.reload();
                        } else {
                            showToast('Gagal menghapus modul: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat menghapus modul.', 'error');
                    });
                }
            );
        }

        // Konfirmasi Hapus Level Game via AJAX
        function confirmDeleteGameLevelAjax(levelId) {
            showCustomConfirm(
                'Hapus Level Game',
                `Apakah Anda yakin ingin menghapus level game dengan ID: ${levelId}? Aksi ini tidak dapat dibatalkan.`,
                'Hapus Level',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_game_level&id=${levelId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Delete Game Level Response:", data);
                        if (data.status === "success") {
                            showToast(data.message || 'Level game berhasil dihapus!', 'success');
                            location.reload();
                        } else {
                            showToast('Gagal menghapus level game: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat menghapus level game.', 'error');
                    });
                }
            );
        }

        // NEW: Konfirmasi Hapus Lokasi Bank Sampah via AJAX
        function confirmDeleteDropPointAjax(dpId) {
            showCustomConfirm(
                'Hapus Lokasi Bank Sampah',
                `Apakah Anda yakin ingin menghapus lokasi Bank Sampah dengan ID: ${dpId}? Aksi ini tidak dapat dibatalkan.`,
                'Hapus Lokasi',
                () => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_drop_point&id=${dpId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log("Delete Drop Point Response:", data);
                        if (data.status === "success") {
                            showToast(data.message || 'Lokasi Bank Sampah berhasil dihapus!', 'success');
                            location.reload();
                        } else {
                            showToast('Gagal menghapus lokasi Bank Sampah: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Terjadi kesalahan saat menghapus lokasi Bank Sampah.', 'error');
                    });
                }
            );
        }


        // Konfirmasi Logout
        function confirmLogout() {
            showCustomConfirm(
                'Konfirmasi Keluar',
                'Anda yakin ingin keluar dari sesi administrator?',
                'Keluar',
                performLogout
            );
        }

        // Fungsi Logout
        function performLogout() {
            showToast('Anda telah berhasil keluar!', 'info');
            setTimeout(() => {
                window.location.href = 'admin_login.php';
            }, 1000);
        }

        // --- Fungsionalitas Form Penambahan Modul Edukasi ---

        // Toggle field berdasarkan jenis modul
        function toggleModuleFields() {
            const selectedType = moduleTypeSelect.value;
            researchFields.style.display = 'none';
            videoFields.style.display = 'none';

            // Hapus atribut 'required' dari semua elemen form di dalam kedua bagian
            researchFields.querySelectorAll('input, select, textarea').forEach(el => el.removeAttribute('required'));
            videoFields.querySelectorAll('input, select, textarea').forEach(el => el.removeAttribute('required'));

            // Reset nilai input file dan teks nama file
            pdfFileName.textContent = '';
            videoFileName.textContent = '';
            thumbnailFileName.textContent = ''; // NEW: Reset thumbnail file name

            const pdfFile = document.getElementById('pdf-file');
            if (pdfFile) pdfFile.value = '';
            const videoFile = document.getElementById('video-file');
            if (videoFile) videoFile.value = '';
            const thumbnailFile = document.getElementById('thumbnail-file'); // NEW: Reset thumbnail file input
            if (thumbnailFile) thumbnailFile.value = '';


            if (selectedType === 'research') {
                researchFields.style.display = 'block';
                // Set 'required' untuk elemen-elemen penting modul riset
                document.getElementById('content-type').setAttribute('required', 'true');
                document.getElementById('estimated-minutes').setAttribute('required', 'true');
                toggleResearchContentFields(); // Panggil ini untuk menangani sub-field
            } else if (selectedType === 'video') {
                videoFields.style.display = 'block';
                // Set 'required' untuk elemen-elemen penting modul video
                document.getElementById('video-type').setAttribute('required', 'true');
                document.getElementById('duration-minutes').setAttribute('required', 'true');
                // document.getElementById('thumbnail-file').setAttribute('required', 'true'); // Make thumbnail required if desired
                toggleVideoSourceFields(); // Panggil ini untuk menangani sub-field
            }
        }

        // Toggle field berdasarkan tipe konten riset
        function toggleResearchContentFields() {
            const selectedContentType = contentTypeSelect.value;
            textContentField.style.display = 'none';
            contentUrlField.style.display = 'none';
            pdfUploadField.style.display = 'none';

            // Hapus atribut 'required' dari semua input di sub-field riset
            const textContentInput = textContentField.querySelector('textarea');
            if (textContentInput) textContentInput.removeAttribute('required');
            const contentUrlInput = contentUrlField.querySelector('input');
            if (contentUrlInput) contentUrlInput.removeAttribute('required');
            const pdfFileInput = pdfUploadField.querySelector('input[type="file"]');
            if (pdfFileInput) pdfFileInput.removeAttribute('required');

            // Reset nilai input
            if (textContentInput) textContentInput.value = '';
            if (contentUrlInput) contentUrlInput.value = '';
            if (pdfFileInput) pdfFileInput.value = '';
            pdfFileName.textContent = ''; // Reset nama file yang ditampilkan

            if (selectedContentType === 'text') {
                textContentField.style.display = 'block';
                if (textContentInput) textContentInput.setAttribute('required', 'true');
            } else if (selectedContentType === 'url') {
                contentUrlField.style.display = 'block';
                if (contentUrlInput) contentUrlInput.setAttribute('required', 'true');
            } else if (selectedContentType === 'pdf') {
                pdfUploadField.style.display = 'block';
                if (pdfFileInput) pdfFileInput.setAttribute('required', 'true');
            }
        }

        // Toggle field berdasarkan sumber video
        function toggleVideoSourceFields() {
            const selectedVideoType = videoTypeSelect.value;
            videoUrlField.style.display = 'none';
            videoUploadField.style.display = 'none';

            // Hapus atribut 'required' dari semua input di sub-field video
            const videoUrlInput = videoUrlField.querySelector('input');
            if (videoUrlInput) videoUrlInput.removeAttribute('required');
            const videoFileInput = videoUploadField.querySelector('input[type="file"]');
            if (videoFileInput) videoFileInput.removeAttribute('required');

            // Reset nilai input
            if (videoUrlInput) videoUrlInput.value = '';
            if (videoFileInput) videoFileInput.value = '';
            videoFileName.textContent = ''; // Reset nama file yang ditampilkan

            // NEW: Thumbnail input is always present now, but its 'required' state depends on its own default
            // If you want thumbnail to be required only for specific video types, adjust here.
            // For now, it's optional as per initial HTML comment, so no 'required' handling needed here for it.


            if (selectedVideoType === 'youtube' || selectedVideoType === 'vimeo') {
                videoUrlField.style.display = 'block';
                if (videoUrlInput) videoUrlInput.setAttribute('required', 'true');
            }
            // Add a check for 'else if' before 'else' to properly handle 'upload' type.
            else if (selectedVideoType === 'upload') { //
                videoUploadField.style.display = 'block'; //
                if (videoFileInput) videoFileInput.setAttribute('required', 'true'); //
            }
        }

        // Update file name display
        document.getElementById('pdf-file').addEventListener('change', function() {
            pdfFileName.textContent = this.files.length > 0 ? this.files[0].name : '';
        });

        document.getElementById('video-file').addEventListener('change', function() {
            videoFileName.textContent = this.files.length > 0 ? this.files[0].name : '';
        });

        document.getElementById('thumbnail-file').addEventListener('change', function() { // NEW: Thumbnail file display
            thumbnailFileName.textContent = this.files.length > 0 ? this.files[0].name : '';
        });


        // Submit form penambahan modul
        addModuleForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addModuleBtn.classList.add('loading');

            const formData = new FormData(this);

            const spinnerIcon = addModuleBtn.querySelector('.spinner');
            if (spinnerIcon) {
                spinnerIcon.classList.add('material-icons-sharp');
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                addModuleBtn.classList.remove('loading');
                console.log("Add Module Parsed Response:", data);

                if (data.status === "success") {
                    showToast(data.message || 'Modul berhasil ditambahkan!', 'success');
                    addModuleForm.reset();
                    toggleModuleFields();
                    location.reload();
                } else {
                    showToast('Gagal menambahkan modul: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                addModuleBtn.classList.remove('loading');
                showToast('Terjadi kesalahan jaringan saat menambahkan modul.', 'error');
            });
        });

        // --- Fungsionalitas Form Penambahan Kuis (dengan Soal & Pilihan) ---

        // Fungsi untuk menambahkan field soal baru
        function addQuestionField() {
            questionCounter++;
            const questionDiv = document.createElement('div');
            questionDiv.classList.add('question-item');
            questionDiv.dataset.questionId = questionCounter; // Untuk identifikasi saat menghapus

            questionDiv.innerHTML = `
                <div class="question-header">
                    <h4>Soal #${questionCounter}</h4>
                    <button type="button" class="btn danger-btn btn-small" onclick="removeQuestionField(this)">
                        Hapus Soal <span class="material-icons-sharp">delete</span>
                    </button>
                </div>
                <label for="question-text-${questionCounter}">Teks Soal:</label>
                <textarea id="question-text-${questionCounter}" placeholder="Masukkan teks soal" required></textarea>

                <label for="question-type-${questionCounter}">Tipe Soal:</label>
                <select id="question-type-${questionCounter}" onchange="toggleOptionFields(this)" required>
                    <option value="multiple_choice" selected>Pilihan Ganda</option>
                    <option value="essay">Esai</option>
                </select>

                <div class="options-container" id="options-container-${questionCounter}">
                    <label style="margin-top: 1rem;">Opsi Pilihan Ganda:</label>
                    <button type="button" class="add-option-btn" onclick="addOptionField(this)">
                        <span class="material-icons-sharp">add</span> Tambah Opsi
                    </button>
                </div>
            `;
            questionsContainer.appendChild(questionDiv);

            // Tambahkan 2 opsi default saat soal baru dibuat
            addOptionField(questionDiv.querySelector('.add-option-btn'));
            addOptionField(questionDiv.querySelector('.add-option-btn'));
        }

        // Fungsi untuk menghapus field soal
        function removeQuestionField(button) {
            const questionDiv = button.closest('.question-item');
            showCustomConfirm(
                'Hapus Soal',
                `Anda yakin ingin menghapus soal #${questionDiv.dataset.questionId} ini?`,
                'Hapus',
                () => {
                    questionDiv.remove();
                    // Optional: re-index question numbers if desired
                    updateQuestionNumbers();
                }
            );
        }

        // Fungsi untuk re-index nomor soal setelah penghapusan
        function updateQuestionNumbers() {
            document.querySelectorAll('.question-item').forEach((item, index) => {
                const newIndex = index + 1;
                item.dataset.questionId = newIndex;
                item.querySelector('.question-header h4').textContent = `Soal #${newIndex}`;
                // Update IDs for input fields inside (optional but good practice if needed for validation)
                item.querySelector(`textarea[id^='question-text-']`).id = `question-text-${newIndex}`;
                item.querySelector(`select[id^='question-type-']`).id = `question-type-${newIndex}`;

                // Checkbox IDs remain unique per question
            });
            questionCounter = document.querySelectorAll('.question-item').length; // Reset counter
        }


        // Fungsi untuk menambahkan field opsi pilihan ganda
        function addOptionField(button) {
            const optionsContainer = button.closest('.options-container');
            const questionItem = button.closest('.question-item');
            const questionId = questionItem.dataset.questionId;
            const optionIndex = optionsContainer.querySelectorAll('.option-item').length + 1;
            // Generate a unique ID for the checkbox to ensure proper label association
            const uniqueCheckboxId = `correct-option-${questionId}-${optionIndex}-${Date.now()}`;

            const optionDiv = document.createElement('div');
            optionDiv.classList.add('option-item');
            // Modified innerHTML for the new layout with Material Icons checkbox
            optionDiv.innerHTML = `
                <div class="correct-answer-toggle" data-checkbox-id="${uniqueCheckboxId}">
                    Jawaban Benar <span class="material-icons-sharp">check_box_outline_blank</span>
                    <input type="checkbox" id="${uniqueCheckboxId}" style="display:none;" value="1">
                </div>
                <input type="text" placeholder="Masukkan opsi jawaban" required>
                <button type="button" class="remove-option-btn" onclick="removeOptionField(this)" title="Hapus Opsi">
                    <span class="material-icons-sharp">remove_circle</span>
                </button>
            `;
            optionsContainer.insertBefore(optionDiv, button); // Masukkan sebelum tombol "Tambah Opsi"

            // Add event listener to the custom checkbox toggle
            const toggleElement = optionDiv.querySelector('.correct-answer-toggle');
            const hiddenCheckbox = optionDiv.querySelector(`#${uniqueCheckboxId}`);

            toggleElement.addEventListener('click', () => {
                hiddenCheckbox.checked = !hiddenCheckbox.checked;
                if (hiddenCheckbox.checked) {
                    toggleElement.classList.add('checked');
                    toggleElement.querySelector('.material-icons-sharp').textContent = 'check_box';
                } else {
                    toggleElement.classList.remove('checked');
                    toggleElement.querySelector('.material-icons-sharp').textContent = 'check_box_outline_blank';
                }
            });
        }

        // Fungsi untuk menghapus field opsi
        function removeOptionField(button) {
            const optionDiv = button.closest('.option-item');
            const optionsContainer = button.closest('.options-container');

            if (optionsContainer.querySelectorAll('.option-item').length > 1) { // Pastikan minimal ada 1 opsi
                showCustomConfirm(
                    'Hapus Opsi',
                    'Anda yakin ingin menghapus opsi ini?',
                    'Hapus',
                    () => {
                        optionDiv.remove();
                    }
                );
            } else {
                showToast('Soal pilihan ganda harus memiliki setidaknya satu opsi.', 'warning');
            }
        }

        // Fungsi untuk toggle fields opsi berdasarkan tipe soal
        function toggleOptionFields(selectElement) {
            const questionDiv = selectElement.closest('.question-item');
            const optionsContainer = questionDiv.querySelector('.options-container');
            const addOptionBtn = optionsContainer.querySelector('.add-option-btn');

            if (selectElement.value === 'multiple_choice') {
                optionsContainer.style.display = 'block';
                addOptionBtn.style.display = 'inline-flex';
                // Set required for text inputs within options
                optionsContainer.querySelectorAll('input[type="text"]').forEach(el => el.setAttribute('required', 'true'));
                // Note: Checkbox required attribute is often tricky for groups. Validation will handle it on submit.
            } else { // 'essay'
                optionsContainer.style.display = 'none';
                addOptionBtn.style.display = 'none';
                // Remove required for all option inputs when switching to essay
                optionsContainer.querySelectorAll('.option-item input').forEach(el => el.removeAttribute('required'));
            }
        }


        // Submit form penambahan kuis
        if (addQuizForm) {
            addQuizForm.addEventListener('submit', function(e) {
                e.preventDefault();
                addQuizBtn.classList.add('loading');

                const questionsData = [];
                const questionItems = questionsContainer.querySelectorAll('.question-item');

                if (questionItems.length === 0) {
                    showToast('Harap tambahkan setidaknya satu soal ke kuis.', 'error');
                    allQuestionsValid = false; // Set this to false for immediate exit
                    addQuizBtn.classList.remove('loading');
                    return;
                }

                let allQuestionsValid = true;

                questionItems.forEach(questionItem => {
                    const questionText = questionItem.querySelector('textarea[placeholder="Masukkan teks soal"]').value.trim();
                    const questionType = questionItem.querySelector('select').value;
                    const options = [];
                    let correctOptionCount = 0; // Use count for checkboxes

                    if (questionText === '') {
                        showToast('Teks soal tidak boleh kosong.', 'error');
                        allQuestionsValid = false;
                        return; // Hentikan iterasi forEach jika ada error validasi
                    }

                    if (questionType === 'multiple_choice') {
                        const optionItems = questionItem.querySelectorAll('.options-container .option-item');

                        if (optionItems.length < 1) { // Kuis PG minimal 1 opsi
                             showToast('Soal pilihan ganda harus memiliki setidaknya satu opsi.', 'error');
                             allQuestionsValid = false;
                             return; // Hentikan iterasi forEach jika ada error validasi
                        }

                        optionItems.forEach(item => {
                            const optionText = item.querySelector('input[type="text"]').value.trim();
                            const isCorrect = item.querySelector('input[type="checkbox"]').checked ? 1 : 0; // Read from checkbox

                            if (optionText === '') {
                                showToast('Opsi jawaban tidak boleh kosong.', 'error');
                                allQuestionsValid = false;
                                return; // Hentikan iterasi forEach jika ada error validasi
                            }
                            if (isCorrect) {
                                correctOptionCount++;
                            }
                            options.push({
                                option_text: optionText,
                                is_correct: isCorrect
                            });
                        });

                        if (correctOptionCount === 0) {
                            showToast('Soal pilihan ganda harus memiliki setidaknya satu jawaban benar.', 'error');
                            allQuestionsValid = false;
                            return; // Hentikan iterasi forEach jika ada error validasi
                        }

                    } else if (questionType === 'essay') {
                        // For essay, no options needed, but can validate other fields if any
                    }


                    questionsData.push({
                        question_text: questionText,
                        question_type: questionType,
                        options: options
                    });
                });

                if (!allQuestionsValid) {
                    addQuizBtn.classList.remove('loading');
                    return; // Hentikan pengiriman form jika ada validasi gagal
                }

                const formData = new FormData(this);
                formData.append('questions_data', JSON.stringify(questionsData)); // Kirim data soal sebagai JSON string

                const spinnerIcon = addQuizBtn.querySelector('.spinner');
                if (spinnerIcon) {
                    spinnerIcon.classList.add('material-icons-sharp');
                }

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    addQuizBtn.classList.remove('loading');
                    console.log("Add Quiz Parsed Response:", data);

                    if (data.status === "success") {
                        showToast(data.message || 'Kuis berhasil ditambahkan!', 'success');
                        addQuizForm.reset();
                        questionsContainer.innerHTML = ''; // Kosongkan daftar soal
                        questionCounter = 0; // Reset counter soal
                        addQuestionField(); // Tambahkan 1 soal default kembali
                        location.reload(); // Reload to update the quiz list
                    } else {
                        showToast('Gagal menambahkan kuis: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    addQuizBtn.classList.remove('loading');
                    showToast('Terjadi kesalahan jaringan saat menambahkan kuis.', 'error');
                });
            });
        }

        // --- Fungsionalitas Form Penambahan Level Game ---

        // Fungsi untuk menambahkan field item sampah baru
        function addGameItemField() {
            gameItemCounter++;
            const itemDiv = document.createElement('div');
            itemDiv.classList.add('option-item'); // Reuse option-item styling
            itemDiv.dataset.itemId = gameItemCounter;

            itemDiv.innerHTML = `
                <label style="margin-right: 0.5rem; flex-shrink: 0;">Tipe:</label>
                <select class="item-type-select" required style="flex-grow: 1; max-width: 150px;">
                    <option value="">Pilih Tipe</option>
                    <option value="organic">Organik</option>
                    <option value="plastic">Plastik</option>
                    <option value="paper">Kertas</option>
                </select>
                <label style="margin-left: 1rem; margin-right: 0.5rem; flex-shrink: 0;">Jumlah:</label>
                <input type="number" class="item-quantity-input" min="1" value="1" required style="flex-grow: 1; max-width: 80px;">
                <button type="button" class="remove-option-btn" onclick="removeGameItemField(this)" title="Hapus Item">
                    <span class="material-icons-sharp">remove_circle</span>
                </button>
            `;
            gameItemsContainer.appendChild(itemDiv);
        }

        // Fungsi untuk menghapus field item sampah
        function removeGameItemField(button) {
            const itemDiv = button.closest('.option-item');
            if (gameItemsContainer.querySelectorAll('.option-item').length > 1) {
                showCustomConfirm(
                    'Hapus Item Sampah',
                    'Anda yakin ingin menghapus item sampah ini?',
                    'Hapus',
                    () => {
                        itemDiv.remove();
                    }
                );
            } else {
                showToast('Level game harus memiliki setidaknya satu jenis item sampah.', 'warning');
            }
        }

        // Submit form penambahan level game
        if (addGameLevelForm) {
            addGameLevelForm.addEventListener('submit', function(e) {
                e.preventDefault();
                addGameLevelBtn.classList.add('loading');

                const itemConfigs = [];
                const itemElements = gameItemsContainer.querySelectorAll('.option-item'); // Using option-item class

                if (itemElements.length === 0) {
                    showToast('Harap tambahkan setidaknya satu jenis item sampah untuk level ini.', 'error');
                    addGameLevelBtn.classList.remove('loading');
                    return;
                }

                let allItemsValid = true;
                itemElements.forEach(itemEl => {
                    const type = itemEl.querySelector('.item-type-select').value;
                    const quantity = parseInt(itemEl.querySelector('.item-quantity-input').value, 10);

                    if (!type || quantity < 1 || isNaN(quantity)) {
                        showToast('Tipe item dan jumlah harus valid.', 'error');
                        allItemsValid = false;
                        return; // Hentikan iterasi forEach jika ada error validasi
                    }
                    itemConfigs.push({ type: type, quantity: quantity });
                });

                if (!allItemsValid) {
                    addGameLevelBtn.classList.remove('loading');
                    return; // Hentikan pengiriman form jika ada validasi gagal
                }

                const formData = new FormData(this);
                formData.append('item_config_json', JSON.stringify(itemConfigs));

                const spinnerIcon = addGameLevelBtn.querySelector('.spinner');
                if (spinnerIcon) {
                    spinnerIcon.classList.add('material-icons-sharp');
                }

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    addGameLevelBtn.classList.remove('loading');
                    console.log("Add Game Level Parsed Response:", data);

                    if (data.status === "success") {
                        showToast(data.message || 'Level game berhasil ditambahkan!', 'success');
                        addGameLevelForm.reset();
                        gameItemsContainer.innerHTML = ''; // Clear item fields
                        gameItemCounter = 0; // Reset counter
                        addGameItemField(); // Add one default field back
                        location.reload(); // Reload to update the list
                    } else {
                        showToast('Gagal menambahkan level game: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    addGameLevelBtn.classList.remove('loading');
                    showToast('Terjadi kesalahan jaringan saat menambahkan level game.', 'error');
                });
            });
        }

        // --- NEW: Fungsionalitas Form Penambahan Lokasi Bank Sampah ---

        // Fungsi untuk menambahkan field jenis sampah dan harga
        function addWasteTypeField() {
            wasteTypeCounter++;
            const typePriceDiv = document.createElement('div');
            typePriceDiv.classList.add('type-price-item'); // Use new class for this type of item
            typePriceDiv.dataset.typeId = wasteTypeCounter;

            typePriceDiv.innerHTML = `
                <label style="margin-right: 0.5rem; flex-shrink: 0;">Jenis:</label>
                <input type="text" class="waste-type-input" placeholder="Contoh: Plastik" required style="flex-grow: 1; max-width: 150px;">
                <label style="margin-left: 1rem; margin-right: 0.5rem; flex-shrink: 0;">Harga/Kg:</label>
                <input type="text" class="waste-price-input" placeholder="Contoh: Rp 3.000" required style="flex-grow: 1; max-width: 100px;">
                <button type="button" class="remove-type-btn" onclick="removeWasteTypeField(this)" title="Hapus Jenis">
                    <span class="material-icons-sharp">remove_circle</span>
                </button>
            `;
            wasteTypesContainer.appendChild(typePriceDiv);
        }

        // Fungsi untuk menghapus field jenis sampah dan harga
        function removeWasteTypeField(button) {
            const typePriceDiv = button.closest('.type-price-item');
            if (wasteTypesContainer.querySelectorAll('.type-price-item').length > 1) {
                showCustomConfirm(
                    'Hapus Jenis Sampah',
                    'Anda yakin ingin menghapus jenis sampah ini?',
                    'Hapus',
                    () => {
                        typePriceDiv.remove();
                    }
                );
            } else {
                showToast('Lokasi bank sampah harus memiliki setidaknya satu jenis sampah yang diterima.', 'warning');
            }
        }

        // Submit form penambahan lokasi bank sampah
        if (addDropPointForm) {
            addDropPointForm.addEventListener('submit', function(e) {
                e.preventDefault();
                addDropPointBtn.classList.add('loading');

                const wasteTypesData = [];
                const pricesData = [];
                const typePriceElements = wasteTypesContainer.querySelectorAll('.type-price-item');

                if (typePriceElements.length === 0) {
                    showToast('Harap tambahkan setidaknya satu jenis sampah yang diterima.', 'error');
                    addDropPointBtn.classList.remove('loading');
                    return;
                }

                let allTypesValid = true;
                typePriceElements.forEach(itemEl => {
                    const type = itemEl.querySelector('.waste-type-input').value.trim();
                    const price = itemEl.querySelector('.waste-price-input').value.trim();

                    if (!type || !price) {
                        showToast('Jenis sampah dan harga tidak boleh kosong.', 'error');
                        allTypesValid = false;
                        return;
                    }
                    wasteTypesData.push(type);
                    pricesData.push({ type: type, price: price }); // Store as objects for easy JSON conversion
                });

                if (!allTypesValid) {
                    addDropPointBtn.classList.remove('loading');
                    return;
                }

                const formData = new FormData(this);
                formData.append('waste_types_json', JSON.stringify(wasteTypesData));
                formData.append('prices_json', JSON.stringify(pricesData)); // Send prices as JSON array of objects

                const spinnerIcon = addDropPointBtn.querySelector('.spinner');
                if (spinnerIcon) {
                    spinnerIcon.classList.add('material-icons-sharp');
                }

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    addDropPointBtn.classList.remove('loading');
                    console.log("Add Drop Point Parsed Response:", data);

                    if (data.status === "success") {
                        showToast(data.message || 'Lokasi Bank Sampah berhasil ditambahkan!', 'success');
                        addDropPointForm.reset();
                        // Reset latitude and longitude fields manually
                        document.getElementById('dp-latitude').value = '';
                        document.getElementById('dp-longitude').value = '';
                        // NEW: Reset PIN code field
                        document.getElementById('dp-pin-code').value = '';
                        wasteTypesContainer.innerHTML = ''; // Clear type fields
                        wasteTypeCounter = 0; // Reset counter
                        addWasteTypeField(); // Add one default field back
                        location.reload(); // Reload to update the list
                    } else {
                        showToast('Gagal menambahkan lokasi Bank Sampah: ' + (data.message || 'Terjadi kesalahan tidak diketahui.'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    addDropPointBtn.classList.remove('loading');
                    showToast('Terjadi kesalahan jaringan saat menambahkan lokasi Bank Sampah.', 'error');
                });
            });
        }


        // --- Navigasi Section ---
        function showSection(hash) {
            document.querySelectorAll('main section.main-section').forEach(section => {
                section.style.display = 'none';
            });

            document.querySelectorAll('aside .sidebar a').forEach(link => {
                link.classList.remove('active');
            });

            let targetSectionId;
            if (!hash || hash === '#' || hash === '#dashboard') {
                targetSectionId = 'dashboard';
            } else {
                targetSectionId = hash.substring(1);
            }

            const targetSection = document.getElementById(targetSectionId);
            if (targetSection) {
                targetSection.style.display = 'block';
                const activeLink = document.querySelector(`aside .sidebar a[href="#${targetSectionId}"]`);
                if (activeLink) {
                    activeLink.classList.add('active');
                }
                if (targetSectionId === 'kelola-modul-edukasi') {
                    toggleModuleFields();
                } else if (targetSectionId === 'kelola-quiz') {
                    if (questionsContainer.children.length === 0) {
                        addQuestionField();
                    }
                } else if (targetSectionId === 'kelola-game') {
                    if (gameItemsContainer.children.length === 0) {
                        addGameItemField();
                    }
                } else if (targetSectionId === 'kelola-bank-sampah') { // NEW: Init bank sampah form
                    if (wasteTypesContainer.children.length === 0) {
                        addWasteTypeField();
                    }
                }
                document.querySelector('main').scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                document.getElementById('dashboard').style.display = 'block';
                document.querySelector('aside .sidebar a[href="#dashboard"]').classList.add('active');
            }
        }

        // Tangani klik navigasi sidebar
        document.querySelectorAll('aside .sidebar a').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const hash = e.currentTarget.getAttribute('href');
                if (hash === '#keluar') {
                    confirmLogout();
                } else {
                    history.pushState(null, '', hash);
                    showSection(hash);
                }

                if (window.innerWidth <= 768) {
                    aside.style.display = 'none';
                }
            });
        });

        // Tangani tombol kembali/maju browser
        window.addEventListener('popstate', () => {
            showSection(location.hash);
        });

        // Muat data awal dan inisialisasi tampilan saat halaman dimuat
        document.addEventListener('DOMContentLoaded', () => {
            showSection(location.hash);
            toggleModuleFields();
            // Initial call for game items if #kelola-game is the initial hash
            if (location.hash === '#kelola-game' && gameItemsContainer.children.length === 0) {
                addGameItemField();
            }
            // NEW: Initial call for bank sampah items if #kelola-bank-sampah is the initial hash
            if (location.hash === '#kelola-bank-sampah' && wasteTypesContainer.children.length === 0) {
                addWasteTypeField();
            }

            const urlParams = new URLSearchParams(window.location.search);
            const message = urlParams.get('message');
            const type = urlParams.get('type');
            if (message && type) {
                showToast(message, type);
            }
        });
    </script>
</body>
</html>