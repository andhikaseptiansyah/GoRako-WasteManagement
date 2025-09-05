<?php
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php'; // Sertakan koneksi database
require_once 'helpers.php'; // Sertakan file helper Anda (untuk clean_input, is_admin_logged_in, redirect)

// Periksa apakah admin sudah login. Jika tidak, arahkan kembali ke halaman login admin.
if (!is_admin_logged_in()) {
    redirect('admin_login.php');
    exit();
}

$message = ''; // Variabel untuk menyimpan pesan sukses/error
$message_type = ''; // 'success' atau 'error'

// Tangani pengiriman formulir saat tombol 'Create Quiz' ditekan
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['quiz_data'])) {
    $quiz_data_json = $_POST['quiz_data'];

    // --- PERBAIKAN: Validasi hasil json_decode ---
    $quiz_data = json_decode($quiz_data_json, true);

    if ($quiz_data === null) { // Jika decode gagal atau hasilnya null
        $json_error_msg = json_last_error_msg();
        // Cek apakah ada error parsing JSON atau stringnya memang kosong/null dari awal
        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = "Data kuis tidak valid (format JSON rusak). Error: " . $json_error_msg;
            $message_type = "error";
            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Gagal decode JSON - " . $json_error_msg . " | Raw JSON: " . substr($quiz_data_json, 0, 500) . "...");
        } else {
            // Ini bisa terjadi jika $_POST['quiz_data'] kosong atau "null" string
            $message = "Data kuis kosong atau tidak lengkap. Harap isi formulir dengan benar.";
            $message_type = "error";
            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Data POST quiz_data kosong/null. Raw: " . substr($quiz_data_json, 0, 500) . "...");
        }
    } elseif (empty(trim($quiz_data['title'] ?? '')) || !isset($quiz_data['questions']) || !is_array($quiz_data['questions']) || count($quiz_data['questions']) === 0) {
        // Menggunakan ?? '' untuk menghindari warning jika 'title' tidak ada
        $message = "Judul kuis dan setidaknya satu pertanyaan harus diisi.";
        $message_type = "error";
        error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Validasi Data Utama Gagal - " . $message);
    } else {
        // Data POST valid, lanjutkan proses transaksi
        $conn->begin_transaction();

        try {
            // 1. Simpan detail kuis ke tabel 'quizzes'
            $stmt_quiz = $conn->prepare("INSERT INTO quizzes (title, description, difficulty, time_limit_minutes, category, passing_score, randomize_question_order, randomize_answer_options, show_results_immediate, show_explanations, point_method, points_per_question, allow_retake, num_questions_to_show) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt_quiz) {
                throw new mysqli_sql_exception("Gagal menyiapkan statement kuis: " . $conn->error);
            }

            // Pastikan semua offset diakses dengan null coalescing operator (??) untuk mencegah warning
            $randomizeQuestionOrder = (int)($quiz_data['settings']['randomizeQuestionOrder'] ?? 0);
            $randomizeAnswerOptions = (int)($quiz_data['settings']['randomizeAnswerOptions'] ?? 0);
            $showResultsImmediate = (int)($quiz_data['settings']['showResultsImmediate'] ?? 0);
            $showExplanations = (int)($quiz_data['settings']['showExplanations'] ?? 0);
            $allowRetake = (int)($quiz_data['settings']['allowRetake'] ?? 0);
            $numQuestionsToShow = (int)($quiz_data['numQuestionsToShow'] ?? 0);

            $stmt_quiz->bind_param("sssisiibbssiii",
                clean_input($quiz_data['title'] ?? ''),
                clean_input($quiz_data['description'] ?? ''),
                clean_input($quiz_data['difficulty'] ?? 'Beginner'),
                $quiz_data['timeLimit'] ?? 10,
                clean_input($quiz_data['category'] ?? 'Waste Segregation'),
                $quiz_data['passingScore'] ?? 0,
                $randomizeQuestionOrder,
                $randomizeAnswerOptions,
                $showResultsImmediate,
                $showExplanations,
                clean_input($quiz_data['pointDistribution']['method'] ?? 'equal'),
                $quiz_data['pointDistribution']['pointsPerQuestion'] ?? 0,
                $allowRetake,
                $numQuestionsToShow
            );

            $stmt_quiz->execute();
            $quiz_id = $conn->insert_id;
            $stmt_quiz->close();

            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Kuis Berhasil Dibuat dengan ID: " . $quiz_id);

            // 2. Simpan pertanyaan-pertanyaan kuis ke tabel 'quiz_questions'
            $stmt_question = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, options, correct_answer_index, explanation) VALUES (?, ?, ?, ?, ?)");

            if (!$stmt_question) {
                throw new mysqli_sql_exception("Gagal menyiapkan statement pertanyaan: " . $conn->error);
            }

            foreach ($quiz_data['questions'] as $index => $question) {
                // --- Validasi dan Pembersihan Opsi Jawaban ---
                if (!isset($question['options']) || !is_array($question['options'])) {
                    error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Pertanyaan " . ($index + 1) . " tidak memiliki opsi jawaban yang valid (bukan array).");
                    throw new Exception("Pertanyaan " . ($index + 1) . " tidak memiliki opsi jawaban yang valid.");
                }

                $clean_options = [];
                foreach ($question['options'] as $opt) {
                    // Pastikan $opt adalah array dan memiliki kunci 'text' sebelum mengakses
                    if (is_array($opt) && isset($opt['text'])) {
                        $clean_options[] = ['text' => trim($opt['text'])];
                    } else {
                        // Logging lebih detail untuk opsi yang bermasalah
                        $opt_debug = is_array($opt) ? json_encode($opt) : var_export($opt, true);
                        error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Opsi jawaban tidak valid untuk pertanyaan " . ($index + 1) . " (tidak ada kunci 'text' atau bukan array). Data Opsi: " . $opt_debug);
                        throw new Exception("Opsi jawaban tidak valid untuk pertanyaan " . ($index + 1) . ".");
                    }
                }

                // Periksa jika ada opsi yang kosong setelah trim atau kurang dari 2 opsi
                $has_empty_option = array_reduce($clean_options, function($carry, $item) {
                    return $carry || empty($item['text']);
                }, false);

                if (count($clean_options) < 2 || $has_empty_option) {
                    error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Pertanyaan " . ($index + 1) . " memiliki kurang dari 2 opsi atau ada opsi yang kosong.");
                    throw new Exception("Pertanyaan " . ($index + 1) . " harus memiliki setidaknya dua opsi jawaban yang tidak kosong.");
                }

                $options_json = json_encode($clean_options);
                if ($options_json === false) {
                    error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Gagal meng-encode opsi ke JSON untuk pertanyaan " . ($index + 1) . ": " . json_last_error_msg());
                    throw new Exception("Gagal memproses opsi jawaban untuk pertanyaan " . ($index + 1) . ".");
                }
                // --- Akhir Validasi Opsi Jawaban ---

                // Validasi indeks jawaban benar
                // Menggunakan ?? -1 untuk mencegah warning jika correctAnswerIndex tidak ada
                $correctAnswerIndex = $question['correctAnswerIndex'] ?? -1;
                if ($correctAnswerIndex === -1 || $correctAnswerIndex >= count($clean_options)) {
                    error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Indeks jawaban benar tidak valid untuk pertanyaan " . ($index + 1) . " (Index: {$correctAnswerIndex}, Options Count: ".count($clean_options).").");
                    throw new Exception("Indeks jawaban benar tidak valid untuk pertanyaan " . ($index + 1) . ".");
                }

                error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Memasukkan Pertanyaan " . ($index + 1) . " untuk Kuis ID " . $quiz_id . ": " . clean_input($question['questionText'] ?? ''));
                error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Opsi JSON: " . $options_json);
                error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Indeks Jawaban Benar: " . ($question['correctAnswerIndex'] ?? 'N/A'));

                $stmt_question->bind_param("isisi",
                    $quiz_id,
                    clean_input($question['questionText'] ?? ''),
                    $options_json,
                    $correctAnswerIndex, // Gunakan nilai yang sudah divalidasi
                    clean_input($question['explanation'] ?? '')
                );

                if (!$stmt_question->execute()) {
                    throw new mysqli_sql_exception("Gagal mengeksekusi statement pertanyaan " . ($index + 1) . ": " . $stmt_question->error);
                }
            }
            $stmt_question->close();

            $conn->commit();
            $message = "Kuis berhasil dibuat dan disimpan ke database!";
            $message_type = "success";
            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Semua data kuis dan pertanyaan berhasil disimpan.");

        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $message = "Gagal membuat kuis (SQL Error): " . $e->getMessage();
            $message_type = "error";
            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: SQL Exception - " . $e->getMessage());
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Gagal membuat kuis (General Error): " . $e->getMessage();
            $message_type = "error";
            error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: General Exception - " . $e->getMessage());
        }
    }
}

// Data awal untuk JavaScript (kosong atau dari DB jika ada fitur edit)
$initial_quiz_data = [
    'title' => "",
    'description' => "",
    'difficulty' => "Beginner",
    'timeLimit' => 10,
    'category' => "Waste Segregation",
    'passingScore' => 70,
    'questions' => [], // Dimulai dengan array kosong, JS akan menambahkan default
    'settings' => [
        'randomizeQuestionOrder' => true,
        'randomizeAnswerOptions' => true,
        'showResultsImmediate' => true,
        'showExplanations' => true,
        'allowRetake' => false
    ],
    'pointDistribution' => [
        'method' => "equal",
        'pointsPerQuestion' => 10
    ],
    'numQuestionsToShow' => 0
];

// Logika untuk Mode Edit Kuis
if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_quiz_id = intval($_GET['edit_id']);

    // Ambil data kuis
    $stmt_edit_quiz = $conn->prepare("SELECT title, description, difficulty, time_limit_minutes, category, passing_score, randomize_question_order, randomize_answer_options, show_results_immediate, show_explanations, point_method, points_per_question, allow_retake, num_questions_to_show FROM quizzes WHERE id = ?");
    if ($stmt_edit_quiz) {
        $stmt_edit_quiz->bind_param("i", $edit_quiz_id);
        $stmt_edit_quiz->execute();
        $result_edit_quiz = $stmt_edit_quiz->get_result();
        if ($quiz_to_edit = $result_edit_quiz->fetch_assoc()) {
            $initial_quiz_data['title'] = $quiz_to_edit['title'];
            $initial_quiz_data['description'] = $quiz_to_edit['description'];
            $initial_quiz_data['difficulty'] = $quiz_to_edit['difficulty'];
            $initial_quiz_data['timeLimit'] = $quiz_to_edit['time_limit_minutes'];
            $initial_quiz_data['category'] = $quiz_to_edit['category'];
            $initial_quiz_data['passingScore'] = $quiz_to_edit['passing_score'];
            $initial_quiz_data['settings']['randomizeQuestionOrder'] = (bool)$quiz_to_edit['randomize_question_order'];
            $initial_quiz_data['settings']['randomizeAnswerOptions'] = (bool)$quiz_to_edit['randomize_answer_options'];
            $initial_quiz_data['settings']['showResultsImmediate'] = (bool)$quiz_to_edit['show_results_immediate'];
            $initial_quiz_data['settings']['showExplanations'] = (bool)$quiz_to_edit['show_explanations'];
            $initial_quiz_data['settings']['allowRetake'] = (bool)$quiz_to_edit['allow_retake'];
            $initial_quiz_data['pointDistribution']['method'] = $quiz_to_edit['point_method'];
            $initial_quiz_data['pointDistribution']['pointsPerQuestion'] = $quiz_to_edit['points_per_question'];
            $initial_quiz_data['numQuestionsToShow'] = $quiz_to_edit['num_questions_to_show'];

            // Ambil pertanyaan-pertanyaan terkait
            $stmt_edit_questions = $conn->prepare("SELECT question_text, options, correct_answer_index, explanation FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
            if ($stmt_edit_questions) {
                $stmt_edit_questions->bind_param("i", $edit_quiz_id);
                $stmt_edit_questions->execute();
                $result_edit_questions = $stmt_edit_questions->get_result();
                $questions_from_db = [];
                while ($row_q = $result_edit_questions->fetch_assoc()) {
                    $options_decoded = json_decode($row_q['options'], true);
                    if (is_array($options_decoded)) { // Pastikan hasil decode adalah array
                        $questions_from_db[] = [
                            'questionText' => $row_q['question_text'],
                            'options' => $options_decoded,
                            'correctAnswerIndex' => $row_q['correct_answer_index'],
                            'explanation' => $row_q['explanation']
                        ];
                    } else {
                        // Log error jika ada masalah decoding opsi dari DB saat mode edit
                        error_log("[".date("Y-m-d H:i:s")."] Membuat_quiz.php: Error decoding options for question from DB (Quiz ID: {$edit_quiz_id}, Question Text: {$row_q['question_text']}). Raw: {$row_q['options']}");
                    }
                }
                $initial_quiz_data['questions'] = $questions_from_db;
                $stmt_edit_questions->close();
            }
        } else {
            $message = "Kuis tidak ditemukan untuk diedit.";
            $message_type = "error";
        }
        $stmt_edit_quiz->close();
    }
}

$initial_quiz_data_json = json_encode($initial_quiz_data);

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Create Waste Education Quiz</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        /* General Styles */
        :root {
            --primary-color: #4CAF50; /* Green */
            --primary-hover-color: #45a049;
            --secondary-color: #f0f2f5;
            --secondary-hover-color: #e0e2e5;
            --accent-blue: #1890ff;
            --accent-blue-light: #e6f7ff;
            --accent-blue-dark: #096dd9;
            --danger-color: #ff4d4f;
            --danger-hover-color: #cf1322;
            --text-dark: #333;
            --text-medium: #555;
            --text-light: #777;
            --border-light: #eee;
            --border-medium: #ddd;
            --bg-light: #f9f9f9;
            --bg-body: #f0f2f5;
            --border-radius-base: 8px;
            --border-radius-large: 12px;
            --box-shadow-base: 0 4px 20px rgba(0, 0, 0, 0.08);
            --box-shadow-hover: 0 6px 25px rgba(0, 0, 0, 0.12);
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: var(--bg-body);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 20px;
            box-sizing: border-box;
            line-height: 1.6;
            color: var(--text-medium);
        }

        .container {
            background-color: #fff;
            border-radius: var(--border-radius-large);
            box-shadow: var(--box-shadow-base);
            width: 100%;
            max-width: 900px;
            padding: 30px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        /* Header */
        .quiz-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .quiz-header .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.125rem; /* 18px */
            font-weight: 600;
            color: var(--text-dark);
        }

        .quiz-header .logo img {
            width: 24px;
            height: 24px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius-base);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease; /* Smooth transition for all properties */
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: var(--text-medium);
            border: 1px solid var(--border-medium);
        }

        .btn-secondary:hover {
            background-color: var(--secondary-hover-color);
            border-color: var(--border-medium);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            background-color: var(--danger-hover-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-info {
            background-color: var(--accent-blue);
            color: white;
        }
        .btn-info:hover {
            background-color: var(--accent-blue-dark);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-sample {
            background-color: var(--accent-blue-light);
            color: var(--accent-blue);
            border: 1px solid var(--accent-blue); /* Stronger border for sample button */
            align-self: flex-end;
            padding: 10px 15px;
            border-radius: var(--border-radius-base);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-top: 20px;
        }

        .btn-sample:hover {
            background-color: var(--accent-blue);
            color: white;
        }

        /* Quiz Progress Steps */
        .quiz-progress {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            padding: 0 20px;
        }

        .quiz-progress::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 20px;
            right: 20px;
            height: 2px;
            background-color: var(--border-light);
            transform: translateY(-50%);
            z-index: 0;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
            cursor: pointer;
            transition: transform 0.2s ease; /* Subtle hover effect */
        }
        .progress-step:hover:not(.active) {
            transform: translateY(-2px);
        }


        .progress-step .step-number {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--border-medium);
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            font-size: 1rem; /* 16px */
            transition: all 0.3s ease;
        }

        .quiz-progress .progress-step:nth-child(-n + var(--active-step-num)) .step-number {
            background-color: var(--primary-color);
        }
        .progress-step.active .step-number {
            background-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.3); /* Stronger focus-like ring */
        }

        .progress-step .step-name {
            font-size: 0.875rem; /* 14px */
            color: var(--text-light);
            font-weight: 500;
        }

        .quiz-progress .progress-step:nth-child(-n + var(--active-step-num)) .step-name {
            color: var(--text-dark);
            font-weight: 600;
        }

        /* Quiz Content Sections */
        .quiz-content {
            background-color: #fff;
            border-radius: var(--border-radius-large);
            padding: 30px;
            border: 1px solid var(--border-light);
        }

        .quiz-section {
            display: none;
            opacity: 0;
            transform: translateY(10px); /* Slight slide up effect */
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }

        .quiz-section.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .quiz-section h2 {
            font-size: 1.5rem; /* 24px */
            font-weight: 700; /* Bolder for section titles */
            color: var(--text-dark);
            margin-bottom: 25px;
            letter-spacing: -0.02em; /* Slight tightening */
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600; /* Bolder labels */
            color: var(--text-medium);
            font-size: 0.9375rem; /* 15px */
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-medium);
            border-radius: var(--border-radius-base);
            font-size: 1rem; /* 16px */
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .form-group input[disabled],
        .form-group select[disabled],
        .form-group textarea[disabled] {
            background-color: var(--bg-body); /* Lighter grey for disabled */
            cursor: not-allowed;
            opacity: 0.7;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
            outline: none;
        }

        /* Validation Styling */
        .form-group input.is-invalid,
        .form-group textarea.is-invalid,
        .form-group select.is-invalid {
            border-color: var(--danger-color); /* Red border for invalid input */
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.875rem; /* 14px */
            margin-top: 5px;
            min-height: 1em; /* Prevent layout shift if no error */
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row .half-width {
            flex: 1;
        }

        /* Navigation Buttons within sections */
        .navigation-buttons {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .navigation-buttons.right-aligned {
            justify-content: flex-end;
        }

        .placeholder-content {
            background-color: var(--bg-light);
            border: 1px dashed var(--border-medium);
            padding: 30px;
            border-radius: var(--border-radius-base);
            text-align: center;
            color: var(--text-light);
            font-style: italic;
            margin-top: 20px;
            min-height: 100px; /* Ensure visual space */
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Quiz Questions Section Specific Styles */
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .question-header .question-count {
            font-size: 0.9375rem; /* 15px */
            font-weight: 500;
            color: var(--text-light);
        }

        #questions-list {
            min-height: 200px;
            /* Placeholder for potential scrollable area if many questions visible at once */
        }

        .question-block {
            background-color: #fff;
            border: 1px solid var(--border-light);
            border-radius: var(--border-radius-large);
            padding: 25px;
            margin-bottom: 25px;
            position: relative;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04); /* Soft shadow */
            transition: all 0.2s ease;
        }
        .question-block:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); /* Slightly lift on hover */
        }

        .question-block-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 5px; /* Tighter gap */
        }
        .question-block-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.25rem; /* 20px for icons */
            color: var(--text-light);
            padding: 5px; /* Comfortable click area */
            border-radius: 50%; /* Make it circular */
            transition: all 0.2s ease;
            display: flex; /* For Material Icons alignment */
            align-items: center;
            justify-content: center;
        }
        .question-block-actions button:hover {
            color: var(--text-dark);
            background-color: var(--secondary-color);
        }
        .question-block-actions .delete-question-btn:hover {
            color: var(--danger-color);
            background-color: rgba(255, 77, 79, 0.1); /* Light red background using rgba from --danger-color */
        }


        .question-block h3 {
            font-size: 1.125rem; /* 18px */
            font-weight: 600;
            color: var(--text-dark);
            margin-top: 0;
            margin-bottom: 15px;
        }

        .answer-options-list {
            /* Container for dynamically added options */
            /* Added basic flex properties for consistency with option-item */
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .answer-options-list .option-item {
            display: flex;
            align-items: center;
            margin-bottom: 0; /* Margin handled by gap on parent */
            gap: 10px;
            background-color: var(--bg-light); /* Slight background for each option */
            padding: 8px 12px;
            border-radius: var(--border-radius-base);
            border: 1px solid var(--border-light);
            transition: all 0.2s ease;
        }
        .answer-options-list .option-item:focus-within {
            border-color: var(--primary-color); /* Highlight active option */
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
        }

        .answer-options-list input[type="radio"] {
            margin-right: 0;
            transform: scale(1.1); /* Slightly larger radio buttons */
            cursor: pointer;
        }
        .answer-options-list input[type="text"] {
            flex-grow: 1;
            padding: 8px 12px;
            border-radius: 6px;
            background-color: transparent; /* Make text input background transparent */
            border: 1px solid transparent; /* Transparent border, will be visible on invalid */
        }
        .answer-options-list input[type="text"]:focus {
            border-color: var(--border-medium); /* Show border on focus */
            box-shadow: none; /* Remove default box shadow to keep it clean */
        }
        .answer-options-list input[type="text"].is-invalid {
            border-color: var(--danger-color);
        }

        .answer-options-list .remove-option-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--danger-color);
            font-size: 1.5rem; /* Larger X icon */
            line-height: 1;
            padding: 0 5px;
            transition: color 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .answer-options-list .remove-option-btn:hover {
            color: var(--danger-hover-color);
        }

        .add-option-btn {
            background-color: var(--accent-blue-light);
            color: var(--accent-blue);
            border: 1px solid var(--accent-blue);
            padding: 8px 15px;
            border-radius: var(--border-radius-base);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-top: 10px;
        }
        .add-option-btn:hover {
            background-color: var(--accent-blue);
            color: white;
        }


        .question-pagination {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 30px;
            margin-bottom: 20px;
        }

        .question-pagination .page-number {
            width: 36px; /* Slightly larger circles */
            height: 36px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            border: 1px solid var(--border-medium);
            color: var(--text-medium);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .question-pagination .page-number.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .question-pagination .page-number:hover:not(.active) {
            background-color: var(--secondary-color);
            border-color: var(--primary-color); /* Primary color border on hover */
            color: var(--primary-color);
        }

        /* Review & Points Section Specific Styles */
        .section-title {
            font-size: 1.25rem; /* 20px */
            font-weight: 700;
            color: var(--text-dark);
            margin-top: 30px;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 10px;
        }

        .point-distribution .radio-group {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .point-distribution .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
            color: var(--text-medium);
            font-weight: 500;
        }

        .point-distribution input[type="radio"] {
            margin-right: 8px;
            transform: scale(1.1);
            cursor: pointer;
        }

        .points-per-question {
            width: 100px; /* Fixed width */
            text-align: center; /* Center align number */
        }

        .total-quiz-points {
            background-color: #e6ffe6; /* Light green background */
            border: 1px solid #aaffaa;
            padding: 15px;
            border-radius: var(--border-radius-base);
            font-weight: 600;
            color: var(--text-dark);
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Subtle shadow */
        }

        .total-quiz-points span:last-child {
            font-size: 1.25rem; /* 20px */
            color: var(--primary-color);
            font-weight: 700;
        }

        .quiz-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .quiz-summary-item {
            background-color: var(--bg-light);
            padding: 15px;
            border-radius: var(--border-radius-base);
            border: 1px solid var(--border-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03); /* Very subtle shadow */
        }

        .quiz-summary-item label {
            font-size: 0.875rem; /* 14px */
            color: var(--text-light);
            margin-bottom: 5px;
            display: block;
            font-weight: 500;
        }

        .quiz-summary-item p {
            font-size: 1rem; /* 16px */
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }

        .quiz-settings .checkbox-group {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            cursor: pointer;
            color: var(--text-medium);
            font-weight: 500;
        }
        .quiz-settings .checkbox-group label {
            cursor: pointer;
            margin-bottom: 0; /* Override default label margin */
            font-weight: 500;
        }

        .quiz-settings input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.1);
            cursor: pointer;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .quiz-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                justify-content: space-around;
            }

            .quiz-progress {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                padding: 0;
                margin-bottom: 20px;
            }

            .quiz-progress::before {
                display: none; /* Hide line for vertical layout */
            }

            .progress-step {
                flex-direction: row;
                gap: 15px;
                width: 100%;
                justify-content: flex-start;
                padding-left: 10px;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }

            .navigation-buttons {
                flex-direction: column;
                align-items: center;
            }

            .navigation-buttons button {
                width: 100%;
            }

            .btn-sample {
                width: 100%;
                margin-top: 15px;
            }

            .quiz-summary-grid {
                grid-template-columns: 1fr;
            }

            .point-distribution .radio-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="quiz-header">
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="window.location.href='dashboard_admin.php#kelola-quiz'">Kembali ke Dashboard</button>
            </div>
        </header>

        <div class="quiz-progress">
            <div class="progress-step" id="step-1" aria-current="page">
                <span class="step-number">1</span>
                <span class="step-name">Quiz Details</span>
            </div>
            <div class="progress-step" id="step-2">
                <span class="step-number">2</span>
                <span class="step-name">Questions</span>
            </div>
            <div class="progress-step" id="step-3">
                <span class="step-number">3</span>
                <span class="step-name">Review & Points</span>
            </div>
        </div>

        <div class="quiz-content">
            <?php if (!empty($message)): ?>
                <div class="alert <?php echo $message_type; ?>" style="padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; background-color: <?php echo $message_type === 'success' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $message_type === 'success' ? '#155724' : '#721c24'; ?>; border: 1px solid <?php echo $message_type === 'success' ? '#c3e6cb' : '#f5c6cb'; ?>;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form id="quiz-creation-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="quiz_data" id="quiz-data-hidden-input">

                <div class="quiz-section" id="quiz-details-section">
                    <h2>Quiz Details</h2>
                    <div class="form-group">
                        <label for="quiz-title">Quiz Title</label>
                        <input type="text" id="quiz-title" placeholder="Enter a title for your quiz" aria-label="Quiz Title">
                        <div class="error-message" id="error-quiz-title"></div>
                    </div>
                    <div class="form-group">
                        <label for="quiz-description">Description</label>
                        <textarea id="quiz-description" placeholder="Provide a brief description of the quiz" aria-label="Quiz Description"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group half-width">
                            <label for="difficulty-level">Difficulty Level</label>
                            <select id="difficulty-level" aria-label="Difficulty Level">
                                <option>Beginner</option>
                                <option>Intermediate</option>
                                <option>Advanced</option>
                            </select>
                        </div>
                        <div class="form-group half-width">
                            <label for="category">Category</label>
                            <select id="category" aria-label="Category">
                                <option>Waste Segregation</option>
                                <option>Recycling</option>
                                <option>Sustainability</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group half-width">
                            <label for="time-limit">Time Limit (minutes)</label>
                            <input type="number" id="time-limit" value="10" min="1" aria-label="Time Limit in minutes">
                            <div class="error-message" id="error-time-limit"></div>
                        </div>
                        <div class="form-group half-width">
                            <label for="passing-score">Passing Score (%)</label>
                            <input type="number" id="passing-score" value="70" min="0" max="100" aria-label="Passing Score percentage">
                            <div class="error-message" id="error-passing-score"></div>
                        </div>
                    </div>
                    <div class="navigation-buttons right-aligned">
                        <button type="button" class="btn btn-primary" id="next-button-1">Next: Add Questions</button>
                    </div>
                </div>

                <div class="quiz-section" id="questions-section">
                    <div class="question-header">
                        <h2>Quiz Questions</h2>
                        <span class="question-count"><span id="current-q-num">1</span>/<span id="total-q-num">0</span> Questions</span>
                    </div>

                    <div id="questions-list">
                        </div>

                    <button type="button" class="btn btn-info" id="add-question-btn">Add New Question</button>
                    <div class="error-message" id="error-questions"></div>

                    <div class="question-pagination" id="question-pagination-container">
                        </div>

                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" id="prev-button-2">Back to Details</button>
                        <button type="button" class="btn btn-primary" id="next-button-2">Next: Review & Points</button>
                    </div>
                </div>

                <div class="quiz-section" id="review-points-section">
                    <h2>Review & Points</h2>

                    <div class="section-title">Point Distribution</div>
                    <div class="point-distribution">
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="point-assignment" value="equal" id="equal-points" checked aria-label="Equal points for all questions">
                                Equal points for all questions
                            </label>
                            <label>
                                <input type="radio" name="point-assignment" value="custom" id="custom-points" aria-label="Custom points per question">
                                Custom points per question
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="points-per-question">Points per Question</label>
                            <input type="number" id="points-per-question" class="points-per-question" value="10" min="1" aria-label="Points per question">
                            <div class="error-message" id="error-points-per-question"></div>
                        </div>
                        <div class="total-quiz-points">
                            <span>Total Quiz Points:</span>
                            <span id="total-points-display">0</span>
                        </div>
                    </div>

                    <div class="section-title">Quiz Summary</div>
                    <div class="quiz-summary-grid">
                        <div class="quiz-summary-item">
                            <label>Quiz Title</label>
                            <p id="summary-quiz-title">-</p>
                        </div>
                        <div class="quiz-summary-item">
                            <label>Category</label>
                            <p id="summary-category">-</p>
                        </div>
                        <div class="quiz-summary-item">
                            <label>Difficulty</label>
                            <p id="summary-difficulty">-</p>
                        </div>
                        <div class="quiz-summary-item">
                            <label>Time Limit</label>
                            <p id="summary-time-limit">-</p>
                        </div>
                        <div class="quiz-summary-item">
                            <label>Total Questions</label>
                            <p id="summary-total-questions">-</p>
                        </div>
                        <div class="quiz-summary-item">
                            <label>Passing Score</label>
                            <p id="summary-passing-score">-</p>
                        </div>
                    </div>

                    <div class="section-title">Quiz Settings</div>
                    <div class="quiz-settings">
                        <div class="checkbox-group">
                            <input type="checkbox" id="randomize-question-order" checked aria-label="Randomize question order">
                            <label for="randomize-question-order">Randomize question order</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="randomize-answer-options" checked aria-label="Randomize answer options">
                            <label for="randomize-answer-options">Randomize answer options</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="show-results-immediate" checked aria-label="Show results immediately after completion">
                            <label for="show-results-immediate">Show results immediately after completion</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="show-explanations" checked aria-label="Show explanations for correct answers">
                            <label for="show-explanations">Show explanations for correct answers</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="allow-retake" checked aria-label="Allow quiz retake">
                            <label for="allow-retake">Allow retake (users can take this quiz multiple times)</label>
                        </div>
                        <div class="form-group">
                            <label for="num-questions-to-show">Number of Questions to Show (0 for all)</label>
                            <input type="number" id="num-questions-to-show" value="0" min="0" aria-label="Number of questions to show">
                            <div class="error-message" id="error-num-questions-to-show"></div>
                        </div>
                    </div>

                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" id="prev-button-3">Back to Questions</button>
                        <button type="submit" class="btn btn-primary" id="create-quiz-button">Create Quiz</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="fill-sample-questions">
            <button class="btn btn-sample" id="fill-sample-btn">Fill Sample Questions</button>
        </div>
    </div>

    <template id="question-block-template">
        <div class="question-block">
            <div class="question-block-actions">
                <button type="button" class="delete-question-btn" title="Delete Question" aria-label="Delete Question">
                    <span class="material-icons">delete</span>
                </button>
            </div>
            <h3>Question <span class="question-number-display"></span></h3>
            <div class="form-group">
                <label>Question</label>
                <textarea class="question-text-input" placeholder="Enter your question" aria-label="Question text"></textarea>
                <div class="error-message"></div>
            </div>
            <div class="form-group">
                <label>Answer Options</label>
                <div class="answer-options-list">
                    </div>
                <button type="button" class="add-option-btn btn-secondary">Add Option</button>
                <div class="error-message"></div>
            </div>
            <div class="form-group">
                <label>Explanation (Optional)</label>
                <textarea class="explanation-input" placeholder="Explain why the correct answer is right" aria-label="Explanation"></textarea>
            </div>
             <div class="error-message general-q-error" style="margin-top: 15px;"></div> </div>
    </template>

    <template id="answer-option-template">
        <div class="option-item">
            <input type="radio" class="answer-radio" aria-label="Select as correct answer">
            <input type="text" class="answer-option-input" placeholder="Enter answer option" aria-label="Answer option text">
            <button type="button" class="remove-option-btn" aria-label="Remove option">&times;</button>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const progressSteps = document.querySelectorAll('.progress-step');
            const quizSections = document.querySelectorAll('.quiz-section');
            const nextButtons = document.querySelectorAll('[id^="next-button-"]');
            const prevButtons = document.querySelectorAll('[id^="prev-button-"]');
            const createQuizButton = document.getElementById('create-quiz-button');
            const quizProgress = document.querySelector('.quiz-progress');
            const quizCreationForm = document.getElementById('quiz-creation-form');
            const quizDataHiddenInput = document.getElementById('quiz-data-hidden-input');

            let currentStep = 0; // 0: Details, 1: Questions, 2: Review

            let quizData = <?php echo $initial_quiz_data_json; ?>;

            // --- PERBAIKAN: Memastikan semua properti quizData memiliki nilai default yang valid di JavaScript ---
            // Ini penting untuk mencegah `undefined` atau `null` masuk ke JSON.stringify
            quizData.title = quizData.title ?? "";
            quizData.description = quizData.description ?? "";
            quizData.difficulty = quizData.difficulty ?? "Beginner";
            quizData.timeLimit = quizData.timeLimit ?? 10;
            quizData.category = quizData.category ?? "Waste Segregation";
            quizData.passingScore = quizData.passingScore ?? 70;
            quizData.questions = quizData.questions ?? []; // Pastikan ini array

            quizData.settings = quizData.settings ?? {};
            quizData.settings.randomizeQuestionOrder = quizData.settings.randomizeQuestionOrder ?? true;
            quizData.settings.randomizeAnswerOptions = quizData.settings.randomizeAnswerOptions ?? true;
            quizData.settings.showResultsImmediate = quizData.settings.showResultsImmediate ?? true;
            quizData.settings.showExplanations = quizData.settings.showExplanations ?? true;
            quizData.settings.allowRetake = quizData.settings.allowRetake ?? false;

            quizData.pointDistribution = quizData.pointDistribution ?? {};
            quizData.pointDistribution.method = quizData.pointDistribution.method ?? "equal";
            quizData.pointDistribution.pointsPerQuestion = quizData.pointDistribution.pointsPerQuestion ?? 10;

            quizData.numQuestionsToShow = quizData.numQuestionsToShow ?? 0;

            // Untuk setiap pertanyaan yang ada, pastikan strukturnya juga valid
            quizData.questions.forEach(q => {
                q.questionText = q.questionText ?? "";
                q.options = q.options ?? []; // Pastikan ini array
                q.options.forEach(opt => {
                    opt.text = opt.text ?? ""; // Pastikan setiap opsi memiliki properti text
                });
                q.correctAnswerIndex = q.correctAnswerIndex ?? -1;
                q.explanation = q.explanation ?? "";
            });
            // --- AKHIR PERBAIKAN INISIALISASI quizData ---


            let currentQuestionIndex = 0; // For managing current question view in step 2

            // --- Elements ---
            // Quiz Details
            const quizTitleInput = document.getElementById('quiz-title');
            const quizDescriptionTextarea = document.getElementById('quiz-description');
            const difficultyLevelSelect = document.getElementById('difficulty-level');
            const timeLimitInput = document.getElementById('time-limit');
            const categorySelect = document.getElementById('category');
            const passingScoreInput = document.getElementById('passing-score');
            const errorQuizTitle = document.getElementById('error-quiz-title');
            const errorTimeLimit = document.getElementById('error-time-limit');
            const errorPassingScore = document.getElementById('error-passing-score');

            // Questions
            const currentQNumSpan = document.getElementById('current-q-num');
            const totalQNumSpan = document.getElementById('total-q-num');
            const questionsListContainer = document.getElementById('questions-list');
            const addQuestionBtn = document.getElementById('add-question-btn');
            const questionPaginationContainer = document.getElementById('question-pagination-container');
            const errorQuestions = document.getElementById('error-questions');
            const questionBlockTemplate = document.getElementById('question-block-template');
            const answerOptionTemplate = document.getElementById('answer-option-template');


            // Review & Points
            const equalPointsRadio = document.getElementById('equal-points');
            const customPointsRadio = document.getElementById('custom-points');
            const pointsPerQuestionInput = document.getElementById('points-per-question');
            const totalPointsDisplay = document.getElementById('total-points-display');
            const summaryQuizTitle = document.getElementById('summary-quiz-title');
            const summaryCategory = document.getElementById('summary-category');
            const summaryDifficulty = document.getElementById('summary-difficulty');
            const summaryTimeLimit = document.getElementById('summary-time-limit');
            const summaryTotalQuestions = document.getElementById('summary-total-questions');
            const summaryPassingScore = document.getElementById('summary-passing-score');
            const randomizeQuestionOrderCheckbox = document.getElementById('randomize-question-order');
            const randomizeAnswerOptionsCheckbox = document.getElementById('randomize-answer-options');
            const showResultsImmediateCheckbox = document.getElementById('show-results-immediate');
            const showExplanationsCheckbox = document.getElementById('show-explanations');

            const allowRetakeCheckbox = document.getElementById('allow-retake');
            const numQuestionsToShowInput = document.getElementById('num-questions-to-show');
            const errorNumQuestionsToShow = document.getElementById('error-num-questions-to-show');

            const fillSampleBtn = document.getElementById('fill-sample-btn');

            // --- Persist Data (localStorage) ---
            const LOCAL_STORAGE_KEY = 'goRakoQuizData';

            function saveQuizData() {
                localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(quizData));
            }

            function populateUIFromQuizData() {
                quizTitleInput.value = quizData.title;
                quizDescriptionTextarea.value = quizData.description;
                difficultyLevelSelect.value = quizData.difficulty;
                timeLimitInput.value = quizData.timeLimit;
                categorySelect.value = quizData.category;
                passingScoreInput.value = quizData.passingScore;

                equalPointsRadio.checked = quizData.pointDistribution.method === 'equal';
                customPointsRadio.checked = quizData.pointDistribution.method === 'custom';
                pointsPerQuestionInput.value = quizData.pointDistribution.pointsPerQuestion;

                randomizeQuestionOrderCheckbox.checked = quizData.settings.randomizeQuestionOrder;
                randomizeAnswerOptionsCheckbox.checked = quizData.settings.randomizeAnswerOptions;
                showResultsImmediateCheckbox.checked = quizData.settings.showResultsImmediate;
                showExplanationsCheckbox.checked = quizData.settings.showExplanations;

                allowRetakeCheckbox.checked = quizData.settings.allowRetake;
                numQuestionsToShowInput.value = quizData.numQuestionsToShow;

                renderQuestions();
                calculateTotalPoints();
            }

            // --- Core Functions ---
            function showStep(stepIndex) {
                quizSections.forEach((section) => {
                    section.classList.remove('active');
                    section.setAttribute('aria-hidden', 'true');
                });
                progressSteps.forEach((step) => {
                    step.classList.remove('active');
                    step.removeAttribute('aria-current');
                });

                quizSections[stepIndex].classList.add('active');
                quizSections[stepIndex].setAttribute('aria-hidden', 'false');
                quizProgress.style.setProperty('--active-step-num', stepIndex + 1);
                progressSteps[stepIndex].classList.add('active');
                progressSteps[stepIndex].setAttribute('aria-current', 'page');

                currentStep = stepIndex;

                if (currentStep === 1) {
                    renderQuestions();
                } else if (currentStep === 2) {
                    updateQuizSummary();
                }
            }

            function validateInput(inputElement, errorElement, checkValue = true) {
                let isValid = true;
                inputElement.classList.remove('is-invalid');
                errorElement.textContent = '';

                if (checkValue) {
                    if (inputElement.type === 'text' || inputElement.tagName === 'TEXTAREA') {
                        if (!inputElement.value.trim()) {
                            inputElement.classList.add('is-invalid');
                            errorElement.textContent = 'This field cannot be empty.';
                            isValid = false;
                        }
                    } else if (inputElement.type === 'number') {
                        const value = parseFloat(inputElement.value);
                        if (isNaN(value) || value < parseFloat(inputElement.min || -Infinity) || value > parseFloat(inputElement.max || Infinity)) {
                            inputElement.classList.add('is-invalid');
                            errorElement.textContent = `Please enter a number between ${inputElement.min || '-'} and ${inputElement.max || '-'}.`;
                            isValid = false;
                        }
                    }
                }
                return isValid;
            }

            function validateStep(step) {
                let isValid = true;

                quizSections[step].querySelectorAll('.error-message').forEach(el => el.textContent = '');
                quizSections[step].querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

                if (step === 0) {
                    const titleValid = validateInput(quizTitleInput, errorQuizTitle);
                    const timeLimitValid = validateInput(timeLimitInput, errorTimeLimit);
                    const passingScoreValid = validateInput(passingScoreInput, errorPassingScore);

                    isValid = titleValid && timeLimitValid && passingScoreValid;

                } else if (step === 1) {
                    if (quizData.questions.length === 0) {
                        errorQuestions.textContent = 'Please add at least one question.';
                        isValid = false;
                    } else {
                        // Validate all questions in the data model
                        quizData.questions.forEach((q, qIndex) => {
                            if (!q.questionText || q.questionText.trim() === '') {
                                errorQuestions.textContent = 'All questions must have text.';
                                isValid = false;
                                return;
                            }

                            if (q.options.length < 2) {
                                errorQuestions.textContent = 'Each question must have at least two answer options.';
                                isValid = false;
                                return;
                            }
                            // --- PERBAIKAN: Validasi opsi jawaban di JavaScript ---
                            const hasEmptyOption = q.options.some(opt => !opt.text || opt.text.trim() === '');
                            if (hasEmptyOption) {
                                errorQuestions.textContent = 'All answer options must have text.';
                                isValid = false;
                                return;
                            }
                            // --- AKHIR PERBAIKAN ---

                            if (q.correctAnswerIndex === -1 || q.correctAnswerIndex >= q.options.length) {
                                errorQuestions.textContent = 'Each question must have a correct answer selected.';
                                isValid = false;
                                return;
                            }
                        });

                        // If currently displayed question has input errors, highlight them
                        const currentQBlock = questionsListContainer.querySelector(`.question-block[data-q-index="${currentQuestionIndex}"]`);
                        if (currentQBlock) {
                            const qTextInput = currentQBlock.querySelector('.question-text-input');
                            let qBlockValid = validateInput(qTextInput, qTextInput.nextElementSibling, true);

                            const answerOptionInputs = currentQBlock.querySelectorAll('.answer-option-input');
                            answerOptionInputs.forEach(input => {
                                qBlockValid = validateInput(input, input.closest('.option-item').querySelector('.error-message'), true) && qBlockValid;
                            });

                            const anyRadioChecked = currentQBlock.querySelector('.answer-radio:checked');
                            if (!anyRadioChecked) {
                                const answerOptionsListEl = currentQBlock.querySelector('.answer-options-list');
                                const answerOptionsError = answerOptionsListEl.nextElementSibling;
                                answerOptionsError.textContent = (answerOptionsError.textContent ? answerOptionsError.textContent + ' ' : '') + 'Please select a correct answer.';
                                qBlockValid = false;
                            }

                            if (!qBlockValid) {
                                const qErrorDisplay = currentQBlock.querySelector('.general-q-error');
                                qErrorDisplay.textContent = 'Please fix errors in this question.';
                                isValid = false;
                            }
                        }
                    }
                } else if (step === 2) {
                    if (quizData.pointDistribution.method === 'equal') {
                        const pointsValid = validateInput(pointsPerQuestionInput, errorPointsPerQuestion);
                        isValid = isValid && pointsValid;
                    }
                    const numQuestionsValid = validateInput(numQuestionsToShowInput, errorNumQuestionsToShow, true);
                    isValid = isValid && numQuestionsValid;

                    // Ensure numQuestionsToShow is not greater than total questions if not 0
                    if (quizData.numQuestionsToShow > 0 && quizData.numQuestionsToShow > quizData.questions.length) {
                        errorNumQuestionsToShow.textContent = `Number of questions to show cannot be more than total questions (${quizData.questions.length}).`;
                        isValid = false;
                    }
                }
                return isValid;
            }

            function goToNextStep() {
                if (!validateStep(currentStep)) {
                    return;
                }
                saveQuizData();
                if (currentStep < quizSections.length - 1) {
                    currentStep++;
                    showStep(currentStep);
                }
            }

            function goToPrevStep() {
                saveQuizData();
                if (currentStep > 0) {
                    currentStep--;
                    showStep(currentStep);
                }
            }

            // --- Quiz Questions Management ---
            function renderQuestions() {
                questionsListContainer.innerHTML = '';
                questionPaginationContainer.innerHTML = '';

                if (quizData.questions.length === 0) {
                    questionsListContainer.innerHTML = '<div class="placeholder-content"><p>Click "Add New Question" to start adding questions.</p></div>';
                    currentQNumSpan.textContent = 0;
                    totalQNumSpan.textContent = 0;
                    return;
                }

                totalQNumSpan.textContent = quizData.questions.length;
                currentQNumSpan.textContent = currentQuestionIndex + 1;

                const q = quizData.questions[currentQuestionIndex];
                const questionBlockClone = document.importNode(questionBlockTemplate.content, true);
                const questionBlock = questionBlockClone.querySelector('.question-block');
                const questionNumberDisplay = questionBlock.querySelector('.question-number-display');
                const questionTextInput = questionBlock.querySelector('.question-text-input');
                const answerOptionsList = questionBlock.querySelector('.answer-options-list');
                const explanationInput = questionBlock.querySelector('.explanation-input');

                questionBlock.dataset.qIndex = currentQuestionIndex;
                questionNumberDisplay.textContent = currentQuestionIndex + 1;
                questionTextInput.value = q.questionText;
                explanationInput.value = q.explanation;

                q.options.forEach((option, optIndex) => {
                    const optionClone = document.importNode(answerOptionTemplate.content, true);
                    const optionItem = optionClone.querySelector('.option-item');
                    const radio = optionClone.querySelector('.answer-radio');
                    const textInput = optionClone.querySelector('.answer-option-input');
                    const removeBtn = optionClone.querySelector('.remove-option-btn');

                    radio.name = `answer-q${currentQuestionIndex}`;
                    radio.value = optIndex;
                    radio.checked = q.correctAnswerIndex === optIndex;
                    textInput.value = option.text;

                    textInput.dataset.optIndex = optIndex;
                    removeBtn.dataset.optIndex = optIndex;

                    const optionErrorDiv = document.createElement('div');
                    optionErrorDiv.classList.add('error-message');
                    optionItem.appendChild(optionErrorDiv);

                    answerOptionsList.appendChild(optionClone);
                });

                questionsListContainer.appendChild(questionBlockClone);

                for (let i = 0; i < quizData.questions.length; i++) {
                    const pageNumberSpan = document.createElement('span');
                    pageNumberSpan.classList.add('page-number');
                    if (i === currentQuestionIndex) {
                        pageNumberSpan.classList.add('active');
                    }
                    pageNumberSpan.textContent = i + 1;
                    pageNumberSpan.dataset.qIndex = i;
                    questionPaginationContainer.appendChild(pageNumberSpan);
                }
                saveQuizData();
            }

            function addQuestion() {
                quizData.questions.push({
                    questionText: "",
                    options: [{text: ""}, {text: ""}],
                    correctAnswerIndex: -1,
                    explanation: ""
                });
                currentQuestionIndex = quizData.questions.length - 1;
                renderQuestions();
                errorQuestions.textContent = '';
                saveQuizData();
            }

            function deleteQuestion(index) {
                if (quizData.questions.length <= 1) {
                    alert('You must have at least one question in the quiz.');
                    return;
                }
                if (confirm('Are you sure you want to delete this question?')) {
                    quizData.questions.splice(index, 1);
                    if (quizData.questions.length > 0) {
                        if (currentQuestionIndex >= quizData.questions.length) {
                            currentQuestionIndex = quizData.questions.length - 1;
                        }
                    } else {
                        currentQuestionIndex = 0;
                    }
                    renderQuestions();
                    saveQuizData();
                }
            }

            function addOption(qIndex) {
                if (quizData.questions[qIndex]) {
                    quizData.questions[qIndex].options.push({text: ""});
                    renderQuestions();
                    saveQuizData();
                }
            }

            function removeOption(qIndex, optIndex) {
                if (quizData.questions[qIndex].options.length <= 2) {
                    alert('A question must have at least two answer options.');
                    return;
                }
                if (confirm('Are you sure you want to remove this option?')) {
                    if (quizData.questions[qIndex]) {
                        quizData.questions[qIndex].options.splice(optIndex, 1);
                        if (quizData.questions[qIndex].correctAnswerIndex === optIndex) {
                            quizData.questions[qIndex].correctAnswerIndex = -1;
                        } else if (quizData.questions[qIndex].correctAnswerIndex > optIndex) {
                            quizData.questions[qIndex].correctAnswerIndex--;
                        }
                        renderQuestions();
                        saveQuizData();
                    }
                }
            }


            // --- Review & Points Management ---
            function calculateTotalPoints() {
                const numQuestions = quizData.questions.length;
                if (quizData.pointDistribution.method === 'equal') {
                    const points = parseInt(pointsPerQuestionInput.value) || 0;
                    quizData.pointDistribution.pointsPerQuestion = points;
                    totalPointsDisplay.textContent = (points * numQuestions).toString();
                    pointsPerQuestionInput.disabled = false;
                } else {
                    totalPointsDisplay.textContent = 'Custom';
                    pointsPerQuestionInput.disabled = true;
                }
                saveQuizData();
            }

            function updateQuizSummary() {
                summaryQuizTitle.textContent = quizData.title || '-';
                summaryCategory.textContent = quizData.category || '-';
                summaryDifficulty.textContent = quizData.difficulty || '-';
                summaryTimeLimit.textContent = `${quizData.timeLimit} minutes` || '-';
                summaryTotalQuestions.textContent = quizData.questions.length.toString();
                summaryPassingScore.textContent = `${quizData.passingScore}%` || '-';

                equalPointsRadio.checked = quizData.pointDistribution.method === 'equal';
                customPointsRadio.checked = quizData.pointDistribution.method === 'custom';
                pointsPerQuestionInput.value = quizData.pointDistribution.pointsPerQuestion;

                calculateTotalPoints();
            }

            // --- Event Listeners (Global and Delegation) ---

            // Navigation buttons
            nextButtons.forEach(button => {
                button.addEventListener('click', goToNextStep);
            });
            prevButtons.forEach(button => {
                button.addEventListener('click', goToPrevStep);
            });

            // Progress step click
            progressSteps.forEach((step, index) => {
                step.addEventListener('click', () => {
                    if (index < currentStep) {
                        showStep(index);
                    } else if (index === currentStep + 1 && validateStep(currentStep)) {
                        showStep(index);
                    } else if (index === currentStep) {
                        showStep(index);
                    }
                });
            });

            // Quiz Details Input Changes
            quizTitleInput.addEventListener('input', (e) => {
                quizData.title = e.target.value.trim();
                validateInput(e.target, errorQuizTitle, false);
                saveQuizData();
            });
            quizDescriptionTextarea.addEventListener('input', (e) => { quizData.description = e.target.value.trim(); saveQuizData(); });
            difficultyLevelSelect.addEventListener('change', (e) => { quizData.difficulty = e.target.value; saveQuizData(); });
            timeLimitInput.addEventListener('input', (e) => {
                quizData.timeLimit = parseInt(e.target.value) || 0;
                validateInput(e.target, errorTimeLimit, false);
                saveQuizData();
            });
            categorySelect.addEventListener('change', (e) => { quizData.category = e.target.value; saveQuizData(); });
            passingScoreInput.addEventListener('input', (e) => {
                quizData.passingScore = parseInt(e.target.value) || 0;
                validateInput(e.target, errorPassingScore, false);
                saveQuizData();
            });


            // --- GLOBAL EVENT DELEGATION FOR QUESTIONS LIST ---
            questionsListContainer.addEventListener('input', (e) => {
                const target = e.target;
                const questionBlock = target.closest('.question-block');
                if (!questionBlock) return;
                const qIndex = parseInt(questionBlock.dataset.qIndex);

                if (target.classList.contains('question-text-input')) {
                    quizData.questions[qIndex].questionText = target.value;
                    validateInput(target, target.nextElementSibling, false);
                } else if (target.classList.contains('answer-option-input')) {
                    const optIndex = parseInt(target.dataset.optIndex);
                    if (quizData.questions[qIndex].options[optIndex]) {
                        // --- PERBAIKAN: Trim opsi jawaban saat input ---
                        quizData.questions[qIndex].options[optIndex].text = target.value.trim();
                        // --- AKHIR PERBAIKAN ---
                        validateInput(target, target.closest('.option-item').querySelector('.error-message'), false);
                    }
                } else if (target.classList.contains('explanation-input')) {
                    quizData.questions[qIndex].explanation = target.value;
                }
                saveQuizData();
            });

            questionsListContainer.addEventListener('change', (e) => {
                const target = e.target;
                const questionBlock = target.closest('.question-block');
                if (!questionBlock) return;
                const qIndex = parseInt(questionBlock.dataset.qIndex);

                if (target.classList.contains('answer-radio')) {
                    const optIndex = parseInt(target.value);
                    quizData.questions[qIndex].correctAnswerIndex = optIndex;
                    const answerOptionsListEl = questionBlock.querySelector('.answer-options-list');
                    answerOptionsListEl.nextElementSibling.textContent = '';
                }
                saveQuizData();
            });

            questionsListContainer.addEventListener('click', (e) => {
                const target = e.target;
                const questionBlock = target.closest('.question-block');

                if (target.closest('.delete-question-btn')) {
                    if (questionBlock) {
                        const qIndex = parseInt(questionBlock.dataset.qIndex);
                        deleteQuestion(qIndex);
                    }
                } else if (target.classList.contains('remove-option-btn')) {
                    if (questionBlock) {
                        const optIndex = parseInt(target.dataset.optIndex);
                        const qIndex = parseInt(questionBlock.dataset.qIndex);
                        removeOption(qIndex, optIndex);
                    }
                } else if (target.classList.contains('add-option-btn')) {
                    if (questionBlock) {
                        const qIndex = parseInt(questionBlock.dataset.qIndex);
                        addOption(qIndex);
                    }
                }
            });
            // --- END GLOBAL EVENT DELEGATION ---


            addQuestionBtn.addEventListener('click', addQuestion);

            questionPaginationContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('page-number')) {
                    const clickedIndex = parseInt(e.target.dataset.qIndex);
                    if (validateStep(currentStep)) {
                        currentQuestionIndex = clickedIndex;
                        renderQuestions();
                    } else {
                        alert('Please fix errors in the current question before navigating to another question.');
                    }
                }
            });


            // Review & Points Input Changes
            equalPointsRadio.addEventListener('change', () => {
                quizData.pointDistribution.method = 'equal';
                calculateTotalPoints();
            });
            customPointsRadio.addEventListener('change', () => {
                quizData.pointDistribution.method = 'custom';
                calculateTotalPoints();
            });
            pointsPerQuestionInput.addEventListener('input', (e) => {
                calculateTotalPoints();
                validateInput(e.target, errorPointsPerQuestion, false);
            });

            // Quiz Settings Checkboxes
            randomizeQuestionOrderCheckbox.addEventListener('change', (e) => { quizData.settings.randomizeQuestionOrder = e.target.checked; saveQuizData(); });
            randomizeAnswerOptionsCheckbox.addEventListener('change', (e) => { quizData.settings.randomizeAnswerOptions = e.target.checked; saveQuizData(); });
            showResultsImmediateCheckbox.addEventListener('change', (e) => { quizData.settings.showResultsImmediate = e.target.checked; saveQuizData(); });
            showExplanationsCheckbox.addEventListener('change', (e) => { quizData.settings.showExplanations = e.target.checked; saveQuizData(); });

            // NEW: allowRetake and numQuestionsToShow event listeners
            allowRetakeCheckbox.addEventListener('change', (e) => {
                quizData.settings.allowRetake = e.target.checked;
                saveQuizData();
            });
            numQuestionsToShowInput.addEventListener('input', (e) => {
                quizData.numQuestionsToShow = parseInt(e.target.value) || 0;
                validateInput(e.target, errorNumQuestionsToShow, false);
                saveQuizData();
            });


            // Create Quiz button - now triggers form submission
            createQuizButton.addEventListener('click', (e) => {
                if (validateStep(currentStep)) {
                    // --- PERBAIKAN: Logging JSON string sebelum dikirim ---
                    try {
                        quizDataHiddenInput.value = JSON.stringify(quizData);
                        console.log("JSON string dikirim:", quizDataHiddenInput.value); // Debugging
                    } catch (jsonError) {
                        e.preventDefault(); // Mencegah submit jika stringify gagal
                        alert('Error saat mempersiapkan data kuis: ' + jsonError.message + '. Harap periksa input Anda.');
                        console.error("JSON.stringify failed:", jsonError);
                    }
                    // --- AKHIR PERBAIKAN ---
                } else {
                    e.preventDefault();
                    alert('Please fix the errors before creating the quiz.');
                }
            });

            // Fill Sample Questions (for quick testing)
            fillSampleBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to load sample questions? This will overwrite existing unsaved data.')) {
                    quizData = {
                        title: "Basic Waste Segregation",
                        description: "Test your knowledge on how to properly sort and and segregate waste materials for recycling and disposal.",
                        difficulty: "Beginner",
                        timeLimit: 15,
                        category: "Waste Segregation",
                        passingScore: 75,
                        questions: [
                            {
                                questionText: "Which of the following is an organic waste material?",
                                options: [{text:"Plastic bottle"}, {text:"Fruit peels"}, {text:"Glass jar"}, {text:"Metal can"}],
                                correctAnswerIndex: 1,
                                explanation: "Fruit peels are biodegradable and come from living organisms, categorizing them as organic waste."
                            },
                            {
                                questionText: "What color bin is typically used for plastic recycling?",
                                options: [{text:"Red"}, {text:"Green"}, {text:"Yellow"}, {text:"Blue"}],
                                correctAnswerIndex: 2,
                                explanation: "Yellow bins are commonly designated for plastic waste in many recycling systems."
                            },
                            {
                                questionText: "Which item should NOT be placed in a paper recycling bin?",
                                options: [{text:"Newspapers"}, {text:"Cardboard boxes"}, {text:"Greasy pizza boxes"}, {text:"Magazines"}],
                                correctAnswerIndex: 2,
                                explanation: "Grease contaminates paper, making it unsuitable for recycling."
                            },
                            {
                                questionText: "Composting is best for which type of waste?",
                                options: [{text:"Batteries"}, {text:"Food scraps"}, {text:"Electronic devices"}, {text:"Construction debris"}],
                                correctAnswerIndex: 1,
                                explanation: "Composting is a natural process that breaks down organic materials like food scraps into nutrient-rich soil."
                            },
                            {
                                questionText: "What does 'Reduce, Reuse, Recycle' prioritize?",
                                options: [{text:"Recycling everything"}, {text:"Reducing consumption first"}, {text:"Buying new products"}, {text:"Throwing away more"}],
                                correctAnswerIndex: 1,
                                explanation: "The 'Reduce' principle encourages minimizing waste generation as the primary step."
                            },
                            {
                                questionText: "Which of these is considered hazardous waste?",
                                options: [{text:"Empty plastic bottles"}, {text:"Used batteries"}, {text:"Old newspapers"}, {text:"Glass jars"}],
                                correctAnswerIndex: 1,
                                explanation: "Used batteries contain chemicals that are harmful to the environment if not disposed of properly."
                            },
                            {
                                questionText: "Why is proper waste segregation important?",
                                options: [{text:"To make trash cans look tidy"}, {text:"To increase landfill size"}, {text:"To facilitate recycling and reduce pollution"}, {text:"To make garbage collection easier"}],
                                correctAnswerIndex: 2,
                                explanation: "Proper segregation ensures that recyclable materials can be processed efficiently and hazardous waste does not contaminate other waste streams."
                            },
                            {
                                questionText: "Which material is generally NOT recyclable in standard curbside programs?",
                                options: [{text:"Aluminum cans"}, {text:"Glass bottles"}, {text:"Ceramic dishes"}, {text:"Plastic water bottles"}],
                                correctAnswerIndex: 2,
                                explanation: "Ceramic dishes often have a different melting point and composition than glass bottles, making them unsuitable for standard glass recycling."
                            }
                        ], // Penutup array questions
                        settings: {
                            randomizeQuestionOrder: true,
                            randomizeAnswerOptions: true,
                            showResultsImmediate: true,
                            showExplanations: true,
                            allowRetake: true
                        },
                        pointDistribution: {
                            method: "equal",
                            pointsPerQuestion: 10
                        },
                        numQuestionsToShow: 5
                    }; // Penutup quizData
                    populateUIFromQuizData();
                    saveQuizData();
                }
            });


            // --- Initialization ---
            // If quizData.questions is empty (from PHP's initial_quiz_data_json), add a default question.
            // This is for a fresh "create quiz" page.
            if (quizData.questions.length === 0) {
                addQuestion();
            } else {
                 populateUIFromQuizData(); // If PHP provided data (e.g., from an edit operation), populate UI
            }
            showStep(currentStep); // Show the current step (or the first if new)
        });
    </script>
</body>
</html>