<?php
// soal_quiz.php
session_start(); // <<<--- PENTING: Mulai sesi di awal file

// Sertakan file koneksi database dan helper functions
// Pastikan file ini mendefinisikan $conn, getDBConnection(), clean_input(), sendJsonResponse(), dan is_logged_in()
require_once 'db_connection.php';
require_once 'helpers.php'; // Menyediakan clean_input() dan fungsi lainnya

// --- Bagian POST untuk menyimpan hasil kuis ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') { //
    // Periksa apakah pengguna sudah login
    if (!is_logged_in()) { //
        sendJsonResponse(['error' => 'Anda harus login untuk menyimpan hasil kuis.'], 401); //
    }
    $loggedInUserId = $_SESSION['user_id']; // Dapatkan ID pengguna dari sesi

    // Mendekode JSON dari body request
    $data = json_decode(file_get_contents('php://input'), true); //

    // Validasi data yang diterima dari client
    if (!isset($data['quizId'], $data['score'], $data['totalQuestions']) || //
        !is_numeric($data['quizId']) || !is_numeric($data['score']) || !is_numeric($data['totalQuestions'])) { //
        sendJsonResponse(['error' => 'Data tidak valid atau tidak lengkap. (Kode: Q1)'], 400); //
    }

    $quizId = intval($data['quizId']); // Pastikan integer
    $score = intval($data['score']);    // Pastikan integer
    $totalQuestions = intval($data['totalQuestions']); // Pastikan integer

    // Dapatkan koneksi database
    $conn_post = getDBConnection(); //

    // --- Cek apakah pengguna sudah menyelesaikan kuis ini sebelumnya ---
    // Gunakan quiz_results table for check and then for insert
    // Juga ambil informasi allow_retake dari quiz
    $allowRetakeFromDB = false; //
    $stmt_allow_retake = $conn_post->prepare("SELECT allow_retake FROM quizzes WHERE id = ?"); //
    if($stmt_allow_retake){ //
        $stmt_allow_retake->bind_param("i", $quizId); //
        $stmt_allow_retake->execute(); //
        $stmt_allow_retake->bind_result($allowRetakeValue); //
        $stmt_allow_retake->fetch(); //
        $stmt_allow_retake->close(); //
        $allowRetakeFromDB = (bool)$allowRetakeValue; //
    }

    $checkStmt = $conn_post->prepare("SELECT COUNT(*) FROM quiz_results WHERE quiz_id = ? AND user_id = ?"); //
    if (!$checkStmt) { //
        error_log("Gagal menyiapkan statement pengecekan hasil kuis: " . $conn_post->error); //
        sendJsonResponse(['error' => 'Terjadi kesalahan internal server saat menyiapkan pengecekan kuis. (Kode: Q2)'], 500); //
    }
    $checkStmt->bind_param("ii", $quizId, $loggedInUserId); //
    $checkStmt->execute(); //
    $checkStmt->bind_result($count); //
    $checkStmt->fetch(); //
    $checkStmt->close(); //

    // Jika sudah pernah dikerjakan DAN tidak boleh diulang
    if ($count > 0 && !$allowRetakeFromDB) { //
        sendJsonResponse(['error' => 'Anda sudah menyelesaikan kuis ini. Kuis hanya dapat dikerjakan satu kali per akun.'], 409); // Conflict
    }

    // --- Simpan hasil quiz utama ---
    // Fetch points_per_question from quizzes table
    $quizPointsPerQuestion = 0; //
    $stmt_fetch_points = $conn_post->prepare("SELECT points_per_question FROM quizzes WHERE id = ?"); //
    if ($stmt_fetch_points) { //
        $stmt_fetch_points->bind_param("i", $quizId); //
        $stmt_fetch_points->execute(); //
        $stmt_fetch_points->bind_result($quizPointsPerQuestion); //
        $stmt_fetch_points->fetch(); //
        $stmt_fetch_points->close(); //
    } else { //
        error_log("Failed to fetch points_per_question for quiz ID: $quizId. Error: " . $conn_post->error); //
        // Fallback to a default if not found
        $quizPointsPerQuestion = 10; //
    }

    $pointsEarned = 0; //
    // Calculate points based on the quiz's points_per_question
    // if ($totalQuestions > 0) { //
    //     // Assuming score passed from frontend is the raw correct answers count * points_per_question
    //     // Or, if score is just correct count, then: $pointsEarned = $score * $quizPointsPerQuestion;
    //     $pointsEarned = $score; // If $score from JS is already the calculated total points
    // } else { //
    //     // If no questions, no points
    //     $pointsEarned = 0; //
    // }

    // Dapatkan poin per soal dari database dan kalikan dengan skor yang benar
    // Ini lebih aman daripada mengandalkan poin yang dihitung dari frontend
    if ($totalQuestions > 0) {
        $pointsEarned = $score * $quizPointsPerQuestion;
    } else {
        $pointsEarned = 0;
    }


    // Jika boleh diulang, gunakan ON DUPLICATE KEY UPDATE untuk memperbarui skor tertinggi atau yang terbaru
    if ($allowRetakeFromDB) { //
        // Option 1: Update existing record (e.g., with higher score) or insert new if none
        $stmt = $conn_post->prepare("INSERT INTO quiz_results (quiz_id, user_id, score, total_questions, points_earned, timestamp) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE score = GREATEST(score, VALUES(score)), total_questions = VALUES(total_questions), points_earned = GREATEST(points_earned, VALUES(points_earned)), timestamp = NOW()");
        // NOTE: This ON DUPLICATE KEY UPDATE assumes a UNIQUE constraint on (quiz_id, user_id) in quiz_results.
        // If no unique constraint, it will always insert new rows on retake, which might also be desired.
        // If always insert new rows: change to normal INSERT and remove the if-else for $count > 0 for non-retake quizzes above.
    } else { //
        // Default behavior: simple insert
        $stmt = $conn_post->prepare("INSERT INTO quiz_results (quiz_id, user_id, score, total_questions, points_earned, timestamp) VALUES (?, ?, ?, ?, ?, NOW())"); //
    }

    if (!$stmt) { //
        error_log("Gagal menyiapkan statement insert hasil kuis (allow_retake: $allowRetakeFromDB): " . $conn_post->error); //
        sendJsonResponse(['error' => 'Terjadi kesalahan internal server saat menyiapkan penyimpanan hasil kuis. (Kode: Q3)'], 500); //
    }
    $stmt->bind_param("iiiii", $quizId, $loggedInUserId, $score, $totalQuestions, $pointsEarned); // // Changed to 'iiiii' for 5 integers

    if (!$stmt->execute()) { //
        error_log("Gagal menyimpan hasil quiz utama: " . $stmt->error); //
        sendJsonResponse(['error' => 'Gagal menyimpan hasil quiz utama: ' . $stmt->error], 500); //
    }

    $resultId = $conn_post->insert_id; // Will be 0 if ON DUPLICATE KEY UPDATE and no new row inserted
    $stmt->close(); //

    // --- Simpan jawaban detail (jika ada) ---
    // For retakeable quizzes, you might want to clear old answers first or track attempts.
    // Current logic will simply add new answers.
    if (isset($data['answers']) && is_array($data['answers'])) { //
        // Sebelum menyimpan jawaban baru, hapus jawaban lama untuk hasil kuis ini jika `allow_retake` true
        // Ini memastikan hanya jawaban dari percobaan terakhir yang tersimpan untuk hasil kuis yang bisa diulang.
        if ($allowRetakeFromDB && $resultId === 0) { // Jika terjadi UPDATE (bukan INSERT) karena ON DUPLICATE KEY
            // Jika $resultId adalah 0, berarti terjadi UPDATE pada record yang sudah ada.
            // Kita perlu mendapatkan quiz_results.id dari record yang diupdate.
            $stmt_get_result_id = $conn_post->prepare("SELECT id FROM quiz_results WHERE quiz_id = ? AND user_id = ?");
            if ($stmt_get_result_id) {
                $stmt_get_result_id->bind_param("ii", $quizId, $loggedInUserId);
                $stmt_get_result_id->execute();
                $stmt_get_result_id->bind_result($updatedResultId);
                $stmt_get_result_id->fetch();
                $stmt_get_result_id->close();
                $resultId = $updatedResultId; // Gunakan ID yang ditemukan
            }
            if ($resultId > 0) { // Pastikan kita punya resultId valid sebelum menghapus
                 $stmt_delete_old_answers = $conn_post->prepare("DELETE FROM quiz_answers WHERE result_id = ?");
                 if ($stmt_delete_old_answers) {
                     $stmt_delete_old_answers->bind_param("i", $resultId);
                     $stmt_delete_old_answers->execute();
                     $stmt_delete_old_answers->close();
                 } else {
                     error_log("Failed to prepare delete old answers statement: " . $conn_post->error);
                 }
            }
        }


        $insertAnswerStmt = $conn_post->prepare("INSERT INTO quiz_answers (result_id, question_id, question_text, user_answer, correct_answer, is_correct) VALUES (?, ?, ?, ?, ?, ?)"); //
        if (!$insertAnswerStmt) { //
            error_log("Gagal menyiapkan statement insert detail jawaban: " . $conn_post->error); //
        } else { //
            foreach ($data['answers'] as $answer) { //
                $qId = intval($answer['questionId']); //
                $qText = clean_input($answer['questionText']); //
                $uAnswer = clean_input($answer['userAnswer'] ?? ''); //
                $cAnswer = clean_input($answer['correctAnswer']); //
                $isCorrect = intval($answer['isCorrect']); //

                $insertAnswerStmt->bind_param("iisssi", //
                    $resultId, //
                    $qId, //
                    $qText, //
                    $uAnswer, //
                    $cAnswer, //
                    $isCorrect //
                );
                if (!$insertAnswerStmt->execute()) { //
                    error_log("Gagal menyimpan detail jawaban (result_id: $resultId, q_id: $qId): " . $insertAnswerStmt->error); //
                }
            }
            $insertAnswerStmt->close(); //
        }
    }

    // Update user's total_points in the users table
    // For retakeable quizzes, decide if you add new points every time or only if score improves.
    // Current logic adds points earned from this attempt.
    $stmtUpdateUser = $conn_post->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?"); //
    if ($stmtUpdateUser) { //
        $stmtUpdateUser->bind_param("ii", $pointsEarned, $loggedInUserId); //
        $stmtUpdateUser->execute(); //
        $stmtUpdateUser->close(); //
    } else { //
        error_log("Failed to prepare user points update statement: " . $conn_post->error); //
    }

    // Record the transaction in points_history
    $description = "Selesaikan Kuis ID: " . $quizId . " (Skor: " . $score . ") "; //
    $stmtPointsHistory = $conn_post->prepare("INSERT INTO points_history (user_id, description, points_amount, transaction_date) VALUES (?, ?, ?, NOW())"); //
    if ($stmtPointsHistory) { //
        $stmtPointsHistory->bind_param("isi", $loggedInUserId, $description, $pointsEarned); //
        $stmtPointsHistory->execute(); //
        $stmtPointsHistory->close(); //
    } else { //
        error_log("Failed to prepare points_history statement: " . $conn_post->error); //
    }

    $conn_post->close(); //
    sendJsonResponse(['success' => true, 'resultId' => $resultId, 'message' => 'Hasil kuis berhasil disimpan dan poin telah ditambahkan!', 'points_awarded' => $pointsEarned]); //
}


// --- Bagian GET untuk memuat data kuis dari database ---
$quizDetails = null; //
$quizQuestions = []; //

if (isset($_GET['quiz_id'])) { //
    // Sanitize the requested quiz ID
    $requestedQuizId = clean_input($_GET['quiz_id']); //
    // Ensure it's an integer before querying
    if (!is_numeric($requestedQuizId)) { //
        header("Location: index.php?error=invalid_quiz_id_format"); //
        exit(); //
    }
    $requestedQuizId = intval($requestedQuizId); // Convert to int

    $conn_get = getDBConnection(); // Get a new connection for GET request

    // Fetch quiz details
    // Tambahkan 'allow_retake' dan 'num_questions_to_show' ke SELECT statement
    $stmt_quiz = $conn_get->prepare("SELECT id, title, description, time_limit_minutes, category, points_per_question, passing_score, allow_retake, num_questions_to_show, randomize_question_order FROM quizzes WHERE id = ?"); //
    if ($stmt_quiz) { //
        $stmt_quiz->bind_param("i", $requestedQuizId); //
        $stmt_quiz->execute(); //
        $result_quiz = $stmt_quiz->get_result(); //
        $quizDetails = $result_quiz->fetch_assoc(); //
        $stmt_quiz->close(); //
    } else { //
        error_log("Failed to prepare quiz details statement: " . $conn_get->error); //
    }

    if ($quizDetails) { //
        // Fetch questions and their options
        // Using LEFT JOIN to get all questions, and their options if they are multiple_choice
        $stmt_questions_and_options = $conn_get->prepare("
            SELECT
                qq.id AS question_id,
                qq.question_text,
                qq.question_type,
                qo.option_text,
                qo.is_correct
            FROM
                quiz_questions qq
            LEFT JOIN
                question_options qo ON qq.id = qo.question_id
            WHERE
                qq.quiz_id = ?
            ORDER BY
                qq.id ASC, qo.id ASC;
        ");
        
        if ($stmt_questions_and_options) {
            $stmt_questions_and_options->bind_param("i", $quizDetails['id']);
            $stmt_questions_and_options->execute();
            $result_questions_and_options = $stmt_questions_and_options->get_result();
            
            $allFetchedQuestionsRaw = [];
            while ($row = $result_questions_and_options->fetch_assoc()) {
                $allFetchedQuestionsRaw[] = $row;
            }
            $stmt_questions_and_options->close();

            // Group options by question
            $groupedQuestions = [];
            foreach ($allFetchedQuestionsRaw as $row) {
                $questionId = $row['question_id'];
                if (!isset($groupedQuestions[$questionId])) {
                    $groupedQuestions[$questionId] = [
                        'db_question_id' => $row['question_id'],
                        'question' => $row['question_text'],
                        'question_type' => $row['question_type'], // Add question_type
                        'options' => [],
                        'correct_answer' => null, // Will store the correct option text for multiple choice
                        'correct_answers_array' => [], // For checkboxes (multiple correct)
                        'explanation' => '' // Placeholder for explanation (if you add this column back to quiz_questions)
                    ];
                }

                if ($row['question_type'] === 'multiple_choice' && $row['option_text'] !== null) {
                    $groupedQuestions[$questionId]['options'][] = $row['option_text'];
                    if ($row['is_correct'] == 1) {
                        // For multiple correct answers (checkboxes), store all correct options
                        $groupedQuestions[$questionId]['correct_answers_array'][] = $row['option_text'];
                    }
                }
                // For essay type, options will remain empty
            }

            $allFetchedQuestions = array_values($groupedQuestions); // Convert associative array to indexed

            // For backward compatibility with frontend expecting 'answer' instead of 'correct_answers_array'
            // and assuming first correct answer is "the" answer for display purposes if only one is expected.
            // If your frontend is updated to handle `correct_answers_array`, you can remove this.
            foreach ($allFetchedQuestions as &$q) {
                if ($q['question_type'] === 'multiple_choice') {
                    // Set 'answer' to the first correct option for compatibility, or null if none
                    $q['answer'] = !empty($q['correct_answers_array']) ? $q['correct_answers_array'][0] : null;
                } else {
                    $q['answer'] = null; // Essay questions don't have a single 'answer' in this context
                }
            }
            unset($q); // Unset the reference

            // LOGIKA BARU: Tentukan jumlah pertanyaan yang akan ditampilkan
            $num_questions_to_show = (int)($quizDetails['num_questions_to_show'] ?? 0); //
            $randomize_question_order = (bool)($quizDetails['randomize_question_order'] ?? false); // Ambil setting randomization

            $questionsToDisplay = $allFetchedQuestions; //

            // Jika jumlah pertanyaan yang harus ditampilkan lebih dari 0 dan kurang dari total yang ada
            if ($num_questions_to_show > 0 && $num_questions_to_show < count($allFetchedQuestions)) { //
                if ($randomize_question_order) { //
                    shuffle($questionsToDisplay); // Acak sebelum mengambil slice
                }
                $quizQuestions = array_slice($questionsToDisplay, 0, $num_questions_to_show); //
            } else { //
                // Jika 0 (tampilkan semua) atau lebih dari total, tampilkan semua
                if ($randomize_question_order) { //
                    shuffle($questionsToDisplay); // Acak semua jika akan menampilkan semua
                }
                $quizQuestions = $questionsToDisplay; //
            }


        } else { //
            error_log("Failed to prepare quiz questions statement: " . $conn_get->error); //
        }
    } else { //
        // Quiz not found or ID was invalid, redirect with error
        header("Location: index.php?error=quiz_not_found"); // Redirect to index or a dedicated error page
        exit(); //
    }

    $conn_get->close(); //

} else { //
    // If no quiz_id is provided in the URL, redirect to index
    header("Location: index.php?error=no_quiz_id_provided"); //
    exit(); //
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kuis Edukasi Sampah - <?php echo htmlspecialchars($quizDetails['title'] ?? 'Memuat...'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"></noscript>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f7f0; /* Light background */
            /* subtle pattern from trash icons */
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%23d1d5db" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 0 0 1 2 2v2"></path></svg>');
            background-size: 40px;
        }

        /* Custom scrollbar for sidebar */
        .sidebar::-webkit-scrollbar {
            width: 8px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Animation for question transition */
        .question-fade-enter {
            opacity: 0;
            transform: translateY(20px);
        }
        .question-fade-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }

        /* Overlay for mobile sidebar */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40; /* Below sidebar, above content */
            display: none; /* Hidden by default */
        }
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                height: 100%;
                z-index: 50; /* Ensure sidebar is above content */
                transition: transform 0.3s ease-in-out;
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .overlay.active {
                display: block;
            }
        }
        .progress-bar-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
            height: 10px;
            margin-top: 10px;
        }
        .progress-bar {
            height: 100%;
            width: 0%;
            background-color: #10B981; /* green-500 */
            border-radius: 5px;
            transition: width 0.5s ease-out;
        }

        /* Custom styles for landing page background with parallax */
        .landing-bg {
            background-image: url('https://images.unsplash.com/photo-1576135850937-259166f21759?q=80&w=2070&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D');
            background-size: cover;
            background-position: center;
            background-attachment: fixed; /* Parallax effect */
            position: relative;
            z-index: 0;
        }
        .landing-bg::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.65); /* Darker overlay for better text readability */
            z-index: -1;
        }

        /* Text shadow for readability on background images */
        .text-shadow-outline {
            text-shadow: 2px 2px 4px rgba(0,0,0,0.7);
        }

        /* Custom button glow effect */
        .btn-glow {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        /* Custom style for selected option button */
        .option-selected {
            background-color: #A7F3D0 !important; /* Warna hijau yang lebih lembut */
            color: #166534 !important; /* Warna teks hijau tua */
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.2); /* Efek bayangan halus */
            transform: scale(1.02); /* Efek sedikit membesar */
            transition: all 0.2s ease-in-out; /* Transisi yang lebih cepat */
            border: 2px solid #16A34A; /* Border untuk penekanan */
        }

        /* Styles for correct/incorrect answers in review mode */
        .option-correct {
            background-color: #D1FAE5 !important; /* Latar belakang hijau sangat muda */
            border: 2px solid #10B981 !important; /* Border hijau */
        }
        .option-incorrect {
            background-color: #FEE2E2 !important; /* Latar belakang merah sangat muda */
            border: 2px solid #EF4444 !important; /* Border merah */
        }

        /* Media queries for responsiveness */
        @media (min-width: 768px) and (max-width: 1023px) {
            #options-container {
                grid-template-columns: repeat(1, 1fr); /* Satu kolom di tablet */
            }
        }

        @media (min-width: 1024px) {
            #options-container {
                grid-template-columns: repeat(2, 1fr); /* Dua kolom di desktop besar */
            }
        }
    </style>
</head>
<body class="flex min-h-screen relative">

    <div id="sidebar-overlay" class="overlay" aria-label="Tutup menu samping"></div>

    <aside id="sidebar" class="sidebar w-64 bg-green-700 text-white p-6 flex flex-col justify-between shadow-xl overflow-y-auto md:relative md:transform-none" aria-label="Daftar Pertanyaan Kuis">
        <div>
            <h2 class="text-3xl font-extrabold mb-8 border-b border-green-600 pb-4">Daftar Soal</h2>
            <ul id="question-list" role="navigation" aria-label="Navigasi Pertanyaan">
                </ul>
        </div>
        <div class="mt-8 pt-4 border-t border-green-600">
            <p class="text-sm text-gray-200">Kuis Edukasi Sampah</p>
            <p class="text-xs text-gray-300">&copy; 2024</p>
        </div>
    </aside>

    <main id="main-content" class="flex-1 flex flex-col relative z-10">
        <button id="menu-toggle" class="md:hidden absolute top-4 left-4 bg-green-600 text-white p-3 rounded-full shadow-lg z-20" aria-label="Buka menu navigasi">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        <section id="landing-page" class="flex-1 flex flex-col justify-center items-center rounded-xl shadow-lg border-b-4 border-green-500 text-center text-white landing-bg p-8 md:p-12 lg:p-16" role="region" aria-label="Halaman Sambutan Kuis">
            <div class="max-w-4xl w-full">
                <h1 class="text-4xl md:text-6xl font-extrabold mb-6 animate-pulse text-shadow-outline leading-tight" data-aos="fade-up" data-aos-duration="1000">Uji Pengetahuanmu tentang <br><span class="text-green-300">Pengelolaan Sampah!</span></h1>
                <p class="text-lg md:text-xl max-w-2xl mx-auto mb-8 text-shadow-outline" data-aos="fade-up" data-aos-delay="200">Seberapa peduli kamu terhadap bumi? Mari beraksi untuk lingkungan yang lebih bersih dan sehat!</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-12 text-shadow-outline">
                    <div data-aos="fade-right" data-aos-delay="400">
                        <h3 class="text-2xl font-bold mb-3 text-green-200">Apa yang Akan Kamu Pelajari?</h3>
                        <ul class="list-disc list-inside text-left mx-auto max-w-xs md:max-w-none" id="landing-quiz-description">
                            </ul>
                    </div>
                    <div data-aos="fade-left" data-aos-delay="600">
                        <h3 class="text-2xl font-bold mb-3 text-green-200">Aturan Main Kuis:</h3>
                        <ul class="list-disc list-inside text-left mx-auto max-w-xs md:max-w-none" id="quiz-rules-list">
                            <li>Jumlah pertanyaan: <span id="rule-total-questions"></span></li>
                            <li>Waktu pengerjaan: <span id="rule-time-limit"></span> menit.</li>
                            <li>Setiap jawaban benar bernilai <span id="rule-points-per-question"></span> poin.</li>
                            <li>Skor kelulusan: <span id="rule-passing-score"></span>%.</li>
                            <li>Kuis hanya dapat dikerjakan satu kali.</li>
                        </ul>
                    </div>
                </div>
                <button id="start-quiz-btn" class="mt-12 bg-green-600 text-white px-12 py-5 rounded-full font-bold text-xl hover:bg-green-700 transition duration-300 ease-in-out shadow-lg transform hover:scale-105 btn-glow" data-aos="zoom-in" data-aos-delay="800" aria-label="Mulai Kuis Sekarang">
                    Mulai Kuis Sekarang!
                </button>
            </div>
        </section>

        <div id="quiz-container" class="hidden flex-1 flex-col p-6 sm:p-8 md:p-10 lg:p-12" role="main" aria-label="Area Kuis">
            <header class="flex flex-col sm:flex-row justify-between items-center bg-white p-4 sm:p-6 rounded-xl shadow-lg mb-8 border-b-4 border-green-500" role="banner">
                <div class="flex items-center text-lg sm:text-xl font-semibold text-green-700 mb-4 sm:mb-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 mr-3 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Waktu Tersisa: <span id="timer" class="ml-2 font-bold text-green-600" aria-live="polite" aria-atomic="true"></span>
                </div>
                <div class="flex items-center text-lg sm:text-xl font-semibold text-green-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 mr-3 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Skor: <span id="score" class="ml-2 font-bold text-green-600" aria-live="polite" aria-atomic="true">0</span>
                </div>
                <div class="progress-bar-container" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" aria-label="Progress Waktu Kuis">
                    <div id="time-progress-bar" class="progress-bar"></div>
                </div>
            </header>

            <section id="quiz-area" class="flex-1 bg-white p-6 sm:p-8 rounded-xl shadow-lg flex flex-col border-t-4 border-green-500" role="form" aria-live="polite">
                <p class="text-sm text-gray-500 mb-2" id="question-progress" aria-live="polite"></p>
                <h3 class="text-xl md:text-2xl lg:text-3xl font-bold mb-8 text-gray-800 transition-all duration-300 ease-out" id="question-text" aria-live="polite" aria-atomic="true"></h3>
                <div id="options-container" class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 flex-1" role="radiogroup" aria-labelledby="question-text">
                    </div>
                 <div id="question-progress-bar-container" class="w-full bg-gray-200 rounded-full h-2.5 mt-4 hidden">
                    <div id="question-progress-bar" class="bg-blue-600 h-2.5 rounded-full" style="width: 0%"></div>
                </div>
            </section>

            <footer class="mt-8 flex justify-between" role="contentinfo">
                <button id="prev-btn" class="bg-gray-300 text-gray-800 px-6 py-3 rounded-xl font-semibold hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-opacity-50 transition duration-200 ease-in-out shadow" aria-label="Pertanyaan Sebelumnya">
                    Sebelumnya
                </button>
                <button id="next-btn" class="bg-green-600 text-white px-6 py-3 rounded-xl font-semibold hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50 transition duration-200 ease-in-out shadow" aria-label="Pertanyaan Selanjutnya">
                    Selanjutnya
                </button>
            </footer>
        </div>
    </main>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init(); // Initialize AOS library

        // PHP-injected quiz data
        const quizDataFromDB = <?php echo json_encode($quizQuestions); ?>;
        const quizDetailsFromDB = <?php echo json_encode($quizDetails); ?>;

        document.addEventListener('DOMContentLoaded', () => {
            // State manajemen kuis
            let quizState = {
                currentQuestionIndex: 0,
                score: 0,
                // Use time_limit_minutes from DB, convert to seconds, default to 15 if not set
                timeLeft: (quizDetailsFromDB.time_limit_minutes || 15) * 60,
                timerInterval: null,
                userAnswers: [], // Array untuk menyimpan jawaban pengguna (untuk multiple choice, akan menyimpan array opsi yang dipilih)
                quizCompleted: false, // Flag untuk melacak apakah kuis sudah selesai
                shuffledQuestions: [],
                quizId: quizDetailsFromDB.id, // Use the ID from DB
                allowRetake: quizDetailsFromDB.allow_retake || false // <--- Ambil nilai allow_retake dari DB
            };

            const initialTime = quizState.timeLeft; // Waktu awal untuk perhitungan progress bar

            // DOM Elements
            const dom = {
                landingPage: document.getElementById('landing-page'),
                quizContainer: document.getElementById('quiz-container'),
                startQuizBtn: document.getElementById('start-quiz-btn'),
                questionText: document.getElementById('question-text'),
                optionsContainer: document.getElementById('options-container'),
                scoreDisplay: document.getElementById('score'),
                timerDisplay: document.getElementById('timer'),
                prevBtn: document.getElementById('prev-btn'),
                nextBtn: document.getElementById('next-btn'),
                questionList: document.getElementById('question-list'),
                questionProgress: document.getElementById('question-progress'),
                sidebar: document.getElementById('sidebar'),
                menuToggle: document.getElementById('menu-toggle'),
                sidebarOverlay: document.getElementById('sidebar-overlay'),
                timeProgressBar: document.getElementById('time-progress-bar'),
                questionProgressBarContainer: document.getElementById('question-progress-bar-container'),
                questionProgressBar: document.getElementById('question-progress-bar'),
                // Landing page dynamic elements
                landingQuizDescription: document.getElementById('landing-quiz-description'),
                ruleTotalQuestions: document.getElementById('rule-total-questions'),
                ruleTimeLimit: document.getElementById('rule-time-limit'),
                rulePointsPerQuestion: document.getElementById('rule-points-per-question'),
                rulePassingScore: document.getElementById('rule-passing-score')
            };

            // Preload images (currently only one, but scalable)
            function preloadImages(urls) {
                urls.forEach(url => {
                    const img = new Image();
                    img.src = url;
                });
            }
            // Add other image URLs here if you use more images dynamically
            preloadImages(['https://images.unsplash.com/photo-1576135850937-259166f21759?q=80&w=2070&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D']);

            // --- Populate Landing Page with Quiz Details ---
            function populateLandingPage() {
                if (quizDetailsFromDB) {
                    // Update main title if available in details
                    const mainQuizTitle = dom.landingPage.querySelector('h1 span');
                    if (mainQuizTitle) mainQuizTitle.textContent = quizDetailsFromDB.title;

                    // Update description
                    dom.landingQuizDescription.innerHTML = ''; // Clear existing
                    // Ensure quizDetailsFromDB.description is treated as string for split()
                    const descParts = (String(quizDetailsFromDB.description) || '').split('\n');
                    descParts.forEach(part => {
                        if (part.trim() !== '') {
                            const li = document.createElement('li');
                            li.textContent = part.trim();
                            dom.landingQuizDescription.appendChild(li);
                        }
                    });

                    // Update rules
                    dom.ruleTotalQuestions.textContent = quizDataFromDB.length;
                    dom.ruleTimeLimit.textContent = quizDetailsFromDB.time_limit_minutes;
                    dom.rulePointsPerQuestion.textContent = quizDetailsFromDB.points_per_question;
                    dom.rulePassingScore.textContent = quizDetailsFromDB.passing_score || 'N/A'; // Assuming passing_score exists in DB details
                }
            }
            populateLandingPage(); // Call on load

            // --- Memeriksa status kuis dari server saat halaman dimuat ---
            function checkQuizCompletionStatusFromServer() {
                // Ensure quizState.quizId is valid before making the fetch call
                if (!quizState.quizId) {
                    console.error("Quiz ID not set, cannot check completion status.");
                    disableStartQuizButton();
                    return;
                }

                fetch(`check_quiz_status.php?quizId=${quizState.quizId}`)
                    .then(response => {
                        if (!response.ok) {
                            if (response.status === 401) {
                                alert("Anda harus login untuk mengakses kuis ini.");
                                disableStartQuizButton();
                                return Promise.reject("User not logged in");
                            }
                            return response.json().then(err => Promise.reject(err.error || "Unknown error checking quiz status"));
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.completed) {
                            // Jika kuis sudah selesai DAN allow_retake dari DB adalah false
                            if (!quizDetailsFromDB.allow_retake) {
                                quizState.quizCompleted = true; // Tandai sebagai selesai dan tidak bisa diulang
                                disableStartQuizButton();
                                const rulesList = document.getElementById('quiz-rules-list');
                                if (rulesList) {
                                    let lastLi = rulesList.querySelector('li:last-child');
                                    if (!lastLi) {
                                        lastLi = document.createElement('li');
                                        rulesList.appendChild(lastLi);
                                    }
                                    lastLi.innerHTML = '<span class="font-bold text-red-300">Anda telah menyelesaikan kuis ini. Kuis hanya dapat dikerjakan satu kali.</span>';
                                }
                            } else {
                                // Jika kuis sudah selesai TAPI allow_retake dari DB adalah true
                                quizState.quizCompleted = false; // Reset status agar bisa mulai lagi
                                dom.startQuizBtn.disabled = false;
                                dom.startQuizBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                                dom.startQuizBtn.textContent = "Ulangi Kuis!"; // Ubah teks tombol
                                dom.startQuizBtn.classList.add('btn-glow');

                                const rulesList = document.getElementById('quiz-rules-list');
                                if (rulesList) {
                                     let lastLi = rulesList.querySelector('li:last-child');
                                     if (lastLi && lastLi.textContent.includes('Kuis hanya dapat dikerjakan satu kali')) {
                                         lastLi.remove(); // Hapus pesan "satu kali" jika ada
                                     }
                                     const newLi = document.createElement('li');
                                     newLi.innerHTML = '<span class="font-bold text-blue-300">Anda telah menyelesaikan kuis ini. Anda dapat mengulanginya.</span>';
                                     rulesList.appendChild(newLi);
                                }
                            }
                        } else {
                            // Kuis belum selesai
                            quizState.quizCompleted = false;
                            dom.startQuizBtn.disabled = false;
                            dom.startQuizBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                            dom.startQuizBtn.textContent = "Mulai Kuis Sekarang!";
                            dom.startQuizBtn.classList.add('btn-glow');
                        }
                    })
                    .catch(error => {
                        console.error('Error checking quiz completion status:', error);
                        alert(`Terjadi kesalahan saat memeriksa status kuis: ${error}. Silakan coba lagi nanti.`);
                        disableStartQuizButton();
                    });
            }

            checkQuizCompletionStatusFromServer();

            function disableStartQuizButton() {
                dom.startQuizBtn.disabled = true;
                dom.startQuizBtn.classList.add('opacity-50', 'cursor-not-allowed');
                dom.startQuizBtn.textContent = "Kuis Selesai"; // Teks default jika tidak bisa diulang
                dom.startQuizBtn.classList.remove('btn-glow');
                const quizRulesList = document.getElementById('quiz-rules-list');
                if (quizRulesList) {
                    let lastLi = quizRulesList.querySelector('li:last-child');
                    // Hanya tambahkan/ubah pesan jika belum ada atau berbeda
                    if (!lastLi || !lastLi.textContent.includes('Anda telah menyelesaikan kuis ini.')) {
                        if (lastLi) lastLi.remove(); // Hapus pesan lama jika ada
                        lastLi = document.createElement('li');
                        lastLi.innerHTML = '<span class="font-bold text-red-300">Anda telah menyelesaikan kuis ini. Kuis hanya dapat dikerjakan satu kali.</span>';
                        quizRulesList.appendChild(lastLi);
                    }
                }
            }


            function showQuiz() {
                // Jika kuis sudah selesai DAN TIDAK boleh diulang (berdasarkan setting DB)
                if (quizState.quizCompleted && !quizDetailsFromDB.allow_retake) {
                    alert("Anda sudah menyelesaikan kuis ini dan tidak bisa mengulanginya.");
                    return;
                }
                // Jika kuis sudah selesai TAPI boleh diulang (berdasarkan setting DB): Reset state untuk mulai baru
                if (quizState.quizCompleted && quizDetailsFromDB.allow_retake) {
                    quizState.score = 0;
                    quizState.timeLeft = (quizDetailsFromDB.time_limit_minutes || 15) * 60; // Reset timer
                    quizState.userAnswers = new Array(quizDataFromDB.length).fill(null); // Reset jawaban
                    quizState.quizCompleted = false; // Izinkan mulai lagi
                    // Tidak perlu memanggil checkQuizCompletionStatusFromServer di sini, karena kita sudah memulai kuis
                }


                // Pastikan quizDataFromDB tidak kosong sebelum memulai
                if (quizDataFromDB.length === 0) {
                    alert("Maaf, kuis ini belum memiliki pertanyaan. Silakan coba kuis lain.");
                    return;
                }
                
                // `quizQuestions` (dari PHP) sudah diacak dan dislice jika `num_questions_to_show` diatur.
                // Jadi, cukup gunakan `quizDataFromDB` secara langsung.
                quizState.shuffledQuestions = [...quizDataFromDB]; // Buat salinan dari data pertanyaan yang sudah diatur PHP

                dom.landingPage.classList.add('hidden');
                dom.quizContainer.classList.remove('hidden');
                dom.quizContainer.classList.add('flex');
                dom.questionProgressBarContainer.classList.remove('hidden');
                startTimer();
                generateQuestionList();
                displayQuestion();
            }

            // Fungsi ini sekarang tidak diperlukan karena pengacakan dilakukan di PHP
            // function shuffleArray(array) {
            //     for (let i = array.length - 1; i > 0; i--) {
            //         const j = Math.floor(Math.random() * (i + 1));
            //         [array[i], array[j]] = [array[j], array[i]];
            //     }
            //     return array;
            // }

            function updateQuestionProgressBar() {
                const progress = ((quizState.currentQuestionIndex + 1) / quizState.shuffledQuestions.length) * 100;
                dom.questionProgressBar.style.width = `${progress}%`;
            }

            function displayQuestion() {
                dom.questionText.classList.remove('question-fade-enter-active');
                dom.optionsContainer.classList.remove('question-fade-enter-active');

                setTimeout(() => {
                    const question = quizState.shuffledQuestions[quizState.currentQuestionIndex];
                    dom.questionProgress.textContent = `Soal ${quizState.currentQuestionIndex + 1} dari ${quizState.shuffledQuestions.length}`;
                    dom.questionText.textContent = question.question;
                    dom.optionsContainer.innerHTML = '';

                    // Jika tipe soalnya adalah pilihan ganda
                    if (question.question_type === 'multiple_choice') {
                        question.options.forEach((optionText, optionIndex) => {
                            const button = document.createElement('button');
                            button.textContent = optionText;
                            button.classList.add(
                                'bg-gray-100', 'text-gray-800', 'p-4', 'rounded-lg', 'font-medium', 'text-lg',
                                'hover:bg-green-100', 'hover:text-green-800', 'focus:outline-none',
                                'focus:ring-2', 'focus:ring-green-400', 'focus:ring-opacity-75',
                                'text-left', 'transition', 'duration-200', 'ease-in-out', 'shadow-md',
                                'transform', 'hover:scale-102'
                            );
                            button.setAttribute('role', 'option'); // Change from radio to option
                            button.setAttribute('aria-selected', 'false'); // Initial state for selection

                            // Check if this option was previously selected by the user
                            const selectedOptionsForThisQuestion = quizState.userAnswers[quizState.currentQuestionIndex] || [];
                            if (selectedOptionsForThisQuestion.includes(optionText)) {
                                button.classList.add('option-selected');
                                button.setAttribute('aria-selected', 'true');
                            }

                            if (quizState.quizCompleted) {
                                button.disabled = true;
                                button.classList.add('opacity-50', 'cursor-not-allowed');
                            } else {
                                button.addEventListener('click', (event) => {
                                    // Toggle selection for multiple choices
                                    if (button.classList.contains('option-selected')) {
                                        button.classList.remove('option-selected');
                                        button.setAttribute('aria-selected', 'false');
                                        // Remove from user's selected answers
                                        const currentAnswers = quizState.userAnswers[quizState.currentQuestionIndex] || [];
                                        quizState.userAnswers[quizState.currentQuestionIndex] = currentAnswers.filter(ans => ans !== optionText);
                                    } else {
                                        button.classList.add('option-selected');
                                        button.setAttribute('aria-selected', 'true');
                                        // Add to user's selected answers
                                        const currentAnswers = quizState.userAnswers[quizState.currentQuestionIndex] || [];
                                        quizState.userAnswers[quizState.currentQuestionIndex] = [...currentAnswers, optionText];
                                    }
                                    if ('vibrate' in navigator) {
                                        navigator.vibrate(50);
                                    }
                                    updateSidebarHighlight(); // Update sidebar status
                                });
                            }
                            dom.optionsContainer.appendChild(button);
                        });
                    } else if (question.question_type === 'essay') {
                        // Untuk soal esai, tampilkan textarea
                        const textarea = document.createElement('textarea');
                        textarea.classList.add(
                            'w-full', 'p-4', 'border', 'border-gray-300', 'rounded-lg', 'text-gray-800', 'text-lg',
                            'focus:outline-none', 'focus:ring-2', 'focus:ring-green-400', 'focus:ring-opacity-75',
                            'shadow-md', 'resize-y', 'min-h-[150px]'
                        );
                        textarea.placeholder = "Tulis jawaban Anda di sini...";
                        textarea.value = quizState.userAnswers[quizState.currentQuestionIndex] || ''; // Load previous answer
                        
                        if (quizState.quizCompleted) {
                            textarea.disabled = true;
                            textarea.classList.add('opacity-50', 'cursor-not-allowed');
                        } else {
                            textarea.addEventListener('input', (event) => {
                                quizState.userAnswers[quizState.currentQuestionIndex] = event.target.value;
                                updateSidebarHighlight();
                            });
                        }
                        dom.optionsContainer.appendChild(textarea);
                    }
                    

                    dom.questionText.classList.add('question-fade-enter', 'question-fade-enter-active');
                    dom.optionsContainer.classList.add('question-fade-enter', 'question-fade-enter-active');

                    updateSidebarHighlight();
                    updateNavigationButtons();
                    updateQuestionProgressBar();
                }, 100);
            }

            // Fungsi selectAnswer tidak lagi dibutuhkan secara langsung karena event listener di dalam displayQuestion mengelola selection
            // function selectAnswer(selectedOption, clickedButton) { ... }

            function calculateScore() {
                let finalScore = 0;
                const pointsPerQuestion = quizDetailsFromDB.points_per_question || 10;
                
                quizState.shuffledQuestions.forEach((q, index) => {
                    const userAnswer = quizState.userAnswers[index]; // Ini bisa string atau array
                    
                    if (q.question_type === 'multiple_choice') {
                        // Logika untuk Multiple Choice (checkboxes)
                        const correctOptions = q.correct_answers_array || [];
                        const userSelectedOptions = userAnswer || []; // Pastikan ini adalah array

                        // Cek apakah semua jawaban benar yang seharusnya dipilih, memang dipilih
                        // DAN tidak ada jawaban salah yang ikut dipilih
                        const isCorrect = correctOptions.length > 0 && // Pastikan ada jawaban benar yang didefinisikan
                                        userSelectedOptions.length === correctOptions.length &&
                                        userSelectedOptions.every(ans => correctOptions.includes(ans));

                        if (isCorrect) {
                            finalScore++; // Hitung sebagai satu soal benar
                        }
                    } else if (q.question_type === 'essay') {
                        // Untuk soal esai, skor harus dinilai manual atau berdasarkan kriteria lain.
                        // Untuk saat ini, kita bisa memberikan 0 poin atau logika sederhana lainnya.
                        // Misalnya, jika ada teks di jawaban esai, anggap "mungkin" benar (bukan rekomendasi untuk produksi)
                        // Atau biarkan 0, karena biasanya esai dinilai offline.
                        // Dalam kasus ini, kita tidak menghitung skor untuk esai secara otomatis.
                    }
                });
                return finalScore; // Mengembalikan jumlah soal yang benar
            }

            function formatTime(seconds) {
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = seconds % 60;
                return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
            }

            function startTimer() {
                dom.timerDisplay.textContent = formatTime(quizState.timeLeft);
                quizState.timerInterval = setInterval(() => {
                    if (quizState.quizCompleted) {
                        clearInterval(quizState.timerInterval);
                        return;
                    }
                    quizState.timeLeft--;
                    dom.timerDisplay.textContent = formatTime(quizState.timeLeft);

                    const percentage = (quizState.timeLeft / initialTime) * 100;
                    dom.timeProgressBar.style.width = `${percentage}%`;
                    dom.timeProgressBar.setAttribute('aria-valuenow', percentage);

                    if (quizState.timeLeft <= 60) {
                        dom.timerDisplay.classList.add('text-red-500');
                        dom.timerDisplay.classList.remove('text-green-600');
                        dom.timeProgressBar.classList.add('bg-red-500');
                        dom.timeProgressBar.classList.remove('bg-green-500');
                    } else {
                        dom.timerDisplay.classList.remove('text-red-500');
                        dom.timerDisplay.classList.add('text-green-600');
                        dom.timeProgressBar.classList.remove('bg-red-500');
                        dom.timeProgressBar.classList.add('bg-green-500');
                    }

                    if (quizState.timeLeft <= 0) {
                        clearInterval(quizState.timerInterval);
                        endQuiz(true);
                    }
                }, 1000);
            }

            function endQuiz(timeUp = false) {
                clearInterval(quizState.timerInterval);
                quizState.quizCompleted = true; // Tandai kuis selesai di state JS

                const correctAnswersCount = calculateScore(); // Ini sekarang adalah jumlah soal yang benar
                const finalPointsEarned = correctAnswersCount * (quizDetailsFromDB.points_per_question || 10); // Hitung total poin

                dom.scoreDisplay.textContent = finalPointsEarned; // Tampilkan total poin

                const quizDataToSend = {
                    quizId: quizState.quizId,
                    score: correctAnswersCount, // Kirim jumlah jawaban benar
                    totalQuestions: quizState.shuffledQuestions.length,
                    answers: []
                };

                quizState.shuffledQuestions.forEach((q, index) => {
                    const userAnswer = quizState.userAnswers[index];
                    let isCorrect = false;
                    let userAnswerText = ''; // Akan menyimpan string untuk user_answer di DB
                    let correctAnswerText = ''; // Akan menyimpan string untuk correct_answer di DB

                    if (q.question_type === 'multiple_choice') {
                        const correctOptions = q.correct_answers_array || [];
                        const userSelectedOptions = userAnswer || [];

                        isCorrect = correctOptions.length > 0 &&
                                    userSelectedOptions.length === correctOptions.length &&
                                    userSelectedOptions.every(ans => correctOptions.includes(ans));
                        
                        userAnswerText = userSelectedOptions.join('; '); // Join multiple answers with a semicolon
                        correctAnswerText = correctOptions.join('; '); // Join multiple correct answers
                    } else if (q.question_type === 'essay') {
                        userAnswerText = userAnswer || '';
                        correctAnswerText = 'Esai (dinilai manual)'; // Placeholder untuk esai
                        isCorrect = 0; // Untuk esai, is_correct default 0, dinilai offline
                    }


                    quizDataToSend.answers.push({
                        questionId: q.db_question_id,
                        questionText: q.question,
                        userAnswer: userAnswerText,
                        correctAnswer: correctAnswerText,
                        isCorrect: isCorrect ? 1 : 0
                    });
                });

                fetch('soal_quiz.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(quizDataToSend)
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(errorData => {
                            if (response.status === 409) {
                                alert(`Kuis selesai! Skor Anda: ${finalPointsEarned}. ${errorData.error}`);
                            } else {
                                alert(`Kuis selesai! Skor Anda: ${finalPointsEarned}. Gagal menyimpan hasil: ${errorData.error || 'Terjadi kesalahan tidak dikenal.'}`);
                            }
                            return Promise.reject(errorData.error);
                        }).catch(() => {
                            alert(`Terjadi kesalahan saat menyimpan hasil kuis: ${response.statusText || response.status}. Mungkin respons bukan JSON.`);
                            return Promise.reject("Non-JSON response for quiz submission.");
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Hasil kuis berhasil disimpan di database:', data.resultId);
                        alert(`Kuis selesai! Skor Anda: ${finalPointsEarned} poin. Hasil telah disimpan.`);
                    } else {
                        console.error('Gagal menyimpan hasil kuis (respons sukses=false):', data.error);
                        alert(`Kuis selesai! Skor Anda: ${finalPointsEarned} poin. Gagal menyimpan hasil: ${data.error}`);
                    }
                })
                .catch(error => {
                    console.error('Terjadi kesalahan saat mengirim hasil kuis:', error);
                });

                let quizResultHtml = `
                    <div class="text-center p-8">
                        <h2 class="text-4xl font-extrabold text-green-700 mb-4 animate-bounce">Kuis Selesai!</h2>
                        ${timeUp ? '<p class="text-red-500 text-xl font-semibold mb-4">Waktu Anda Habis!</p>' : ''}
                        <p class="text-2xl text-gray-700 mb-6">Skor akhir Anda adalah: <span class="font-bold text-green-600 text-5xl">${finalPointsEarned}</span> poin</p>
                        <div class="flex flex-col sm:flex-row justify-center space-y-4 sm:space-y-0 sm:space-x-4">
                            <button id="review-answers-btn" class="bg-blue-600 text-white px-8 py-4 rounded-xl font-semibold hover:bg-blue-700 transition duration-200 shadow-md" aria-label="Tinjau Jawaban Anda">
                                Tinjau Jawaban
                            </button>
                        </div>
                        <p class="mt-4 text-gray-500 italic" id="quiz-completion-message"></p>
                    </div>
                `;
                dom.quizContainer.querySelector('#quiz-area').innerHTML = quizResultHtml;

                // Update completion message based on allowRetake
                const completionMessageElement = document.getElementById('quiz-completion-message');
                if (completionMessageElement) {
                    if (quizDetailsFromDB.allow_retake) {
                        completionMessageElement.innerHTML = 'Anda telah menyelesaikan kuis ini. Anda dapat mengulanginya.';
                    } else {
                        completionMessageElement.innerHTML = 'Anda telah menyelesaikan kuis ini. Kuis hanya dapat dikerjakan satu kali.';
                    }
                }


                document.getElementById('review-answers-btn').addEventListener('click', reviewAnswers);

                dom.prevBtn.style.display = 'none';
                dom.nextBtn.style.display = 'none';
                dom.questionProgress.style.display = 'none';
                dom.timeProgressBar.parentElement.style.display = 'none';
                dom.questionProgressBarContainer.style.display = 'none';

                // Call disableStartQuizButton to update landing page button text if returning there
                // This might be redundant if the user is immediately redirected or stays on results page.
                // If they go back to landing, `checkQuizCompletionStatusFromServer` will handle it.
            }

            function reviewAnswers() {
                let reviewHtml = `
                    <div class="p-8">
                        <h2 class="text-3xl font-bold text-green-700 mb-6 text-center">Tinjauan Jawabanmu</h2>
                        <div class="space-y-6">
                `;

                quizState.shuffledQuestions.forEach((q, index) => {
                    const userAnswer = quizState.userAnswers[index]; // Ini bisa string atau array
                    let isQuestionCorrect = false; // Status benar/salah untuk seluruh pertanyaan

                    let resultClass = 'bg-gray-100 border-gray-300 text-gray-800'; // Default for essay or unattempted
                    let icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4.293 18.707l-.707.707M1.025 12H1m16.975 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';


                    let optionsReviewHtml = '';
                    if (q.question_type === 'multiple_choice') {
                        const correctOptions = q.correct_answers_array || [];
                        const userSelectedOptions = userAnswer || [];

                        isQuestionCorrect = correctOptions.length > 0 &&
                                            userSelectedOptions.length === correctOptions.length &&
                                            userSelectedOptions.every(ans => correctOptions.includes(ans));

                        resultClass = isQuestionCorrect ? 'bg-green-100 border-green-500 text-green-800' : 'bg-red-100 border-red-500 text-red-800';
                        icon = isQuestionCorrect ?
                            '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' :
                            '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';


                        q.options.forEach(option => {
                            let optionClass = 'bg-gray-100 text-gray-800';
                            const isThisOptionCorrect = correctOptions.includes(option);
                            const isThisOptionSelected = userSelectedOptions.includes(option);

                            if (isThisOptionCorrect) {
                                optionClass = 'bg-green-200 text-green-800 option-correct'; // Always highlight correct answers
                            }
                            if (isThisOptionSelected && !isThisOptionCorrect) {
                                optionClass = 'bg-red-200 text-red-800 option-incorrect'; // If selected but incorrect
                            } else if (isThisOptionSelected && isThisOptionCorrect) {
                                optionClass = 'bg-green-200 text-green-800 option-correct'; // If selected and correct, keep correct style
                            }

                            optionsReviewHtml += `
                                <li class="p-2 rounded-md ${optionClass} mb-1 transition duration-150">
                                    ${option}
                                    ${isThisOptionCorrect ? '<span class="ml-2 text-xs font-bold">(Benar)</span>' : ''}
                                    ${isThisOptionSelected && !isThisOptionCorrect ? '<span class="ml-2 text-xs font-bold">(Pilihanmu)</span>' : ''}
                                </li>
                            `;
                        });
                    } else if (q.question_type === 'essay') {
                        optionsReviewHtml = `
                            <p class="text-gray-700"><strong>Jawaban Anda:</strong> ${userAnswer || 'Tidak Dijawab'}</p>
                            <p class="text-gray-700"><strong>Catatan:</strong> Jawaban esai biasanya memerlukan penilaian manual.</p>
                        `;
                        // For essay, we don't mark correct/incorrect automatically here,
                        // so leave `resultClass` as default gray unless there's an offline score.
                        resultClass = 'bg-blue-100 border-blue-500 text-blue-800'; // Mark essay as info/blue
                        icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                    }


                    reviewHtml += `
                        <div class="p-4 rounded-lg border-l-4 ${resultClass} shadow-sm">
                            <p class="font-bold mb-2">Soal ${index + 1}: ${q.question}</p>
                            <ul class="list-none p-0 mt-3">
                                ${optionsReviewHtml}
                            </ul>
                            <p class="text-xs italic text-gray-600 mt-2">Penjelasan: ${q.explanation || 'Tidak ada penjelasan.'}</p>
                            <p class="text-sm mt-2">${icon} ${q.question_type === 'multiple_choice' ? (isQuestionCorrect ? 'Benar!' : 'Salah.') : 'Status (Esai): Menunggu penilaian manual.'}</p>
                        </div>
                    `;
                });

                reviewHtml += `
                        </div>
                        <div class="text-center mt-8">
                            <button id="back-to-landing-btn" class="bg-gray-600 text-white px-8 py-4 rounded-xl font-semibold hover:bg-gray-700 transition duration-200 shadow-md" aria-label="Kembali ke Beranda">
                                Kembali ke Beranda
                            </button>
                        </div>
                    </div>
                `;
                dom.quizContainer.querySelector('#quiz-area').innerHTML = reviewHtml;
                document.getElementById('back-to-landing-btn').addEventListener('click', () => {
                    dom.quizContainer.classList.add('hidden');
                    dom.quizContainer.classList.remove('flex');
                    dom.landingPage.classList.remove('hidden');
                    checkQuizCompletionStatusFromServer(); // Panggil ini untuk update tombol landing page
                });
            }

            function generateQuestionList() {
                dom.questionList.innerHTML = '';
                quizState.shuffledQuestions.forEach((_, index) => {
                    const listItem = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = '#';
                    link.textContent = `Soal ${index + 1}`;
                    link.classList.add('block', 'p-3', 'rounded-lg', 'text-lg', 'font-medium', 'hover:bg-green-600', 'transition', 'duration-150', 'ease-in-out', 'mb-2', 'flex', 'items-center');
                    link.setAttribute('role', 'link');
                    link.setAttribute('aria-controls', 'quiz-area');

                    const statusIcon = document.createElement('span');
                    statusIcon.classList.add('ml-auto');

                    const userAnswerForQuestion = quizState.userAnswers[index];
                    let isAnswered = false;

                    if (Array.isArray(userAnswerForQuestion)) { // Multiple choice
                        isAnswered = userAnswerForQuestion.length > 0;
                    } else if (typeof userAnswerForQuestion === 'string') { // Essay
                        isAnswered = userAnswerForQuestion.trim().length > 0;
                    }

                    if (isAnswered) {
                        statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>';
                    } else if (index < quizState.currentQuestionIndex) {
                         statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/></svg>';
                    }
                    else {
                        statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4.293 18.707l-.707.707M1.025 12H1m16.975 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                    }
                    link.appendChild(statusIcon);

                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (!quizState.quizCompleted) {
                            quizState.currentQuestionIndex = index;
                            displayQuestion();
                            if (window.innerWidth < 768) {
                                dom.sidebar.classList.remove('active');
                                dom.sidebarOverlay.classList.remove('active');
                            }
                        } else {
                            console.log("Quiz completed, navigation only for review purposes.");
                        }
                    });
                    listItem.appendChild(link);
                    dom.questionList.appendChild(listItem);
                });
            }

            function updateSidebarHighlight() {
                Array.from(dom.questionList.children).forEach((li, index) => {
                    const link = li.querySelector('a');
                    const statusIcon = link.querySelector('span');

                    if (index === quizState.currentQuestionIndex) {
                        link.classList.add('bg-green-600', 'font-bold', 'shadow-inner');
                        link.setAttribute('aria-current', 'true');
                    } else {
                        link.classList.remove('bg-green-600', 'font-bold', 'shadow-inner');
                        link.removeAttribute('aria-current');
                    }

                    const userAnswerForQuestion = quizState.userAnswers[index];
                    let isAnswered = false;
                    if (Array.isArray(userAnswerForQuestion)) { // Multiple choice
                        isAnswered = userAnswerForQuestion.length > 0;
                    } else if (typeof userAnswerForQuestion === 'string') { // Essay
                        isAnswered = userAnswerForQuestion.trim().length > 0;
                    }

                    if (isAnswered) {
                        statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>';
                    } else if (index < quizState.currentQuestionIndex) {
                         statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/></svg>';
                    } else {
                        statusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4.293 18.707l-.707.707M1.025 12H1m16.975 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                    }
                });
            }

            function updateNavigationButtons() {
                dom.prevBtn.disabled = quizState.currentQuestionIndex === 0 || quizState.quizCompleted;
                dom.nextBtn.textContent = (quizState.currentQuestionIndex === quizState.shuffledQuestions.length - 1) ? 'Selesai' : 'Selanjutnya';
                dom.nextBtn.disabled = quizState.quizCompleted;

                if (dom.prevBtn.disabled) {
                    dom.prevBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    dom.prevBtn.classList.remove('hover:bg-gray-400', 'shadow');
                } else {
                    dom.prevBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    dom.prevBtn.classList.add('hover:bg-gray-400', 'shadow');
                }

                if (dom.nextBtn.disabled) {
                    dom.nextBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    dom.nextBtn.classList.remove('hover:bg-green-700', 'hover:bg-blue-700', 'shadow');
                } else {
                    if (dom.nextBtn.textContent === 'Selesai') {
                        dom.nextBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                        dom.nextBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                    } else {
                        dom.nextBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                        dom.nextBtn.classList.add('bg-green-600', 'hover:bg-green-700');
                    }
                    dom.nextBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    dom.nextBtn.classList.add('shadow');
                }
            }

            dom.startQuizBtn.addEventListener('click', () => {
                showQuiz();
            });

            dom.prevBtn.addEventListener('click', () => {
                if (quizState.currentQuestionIndex > 0 && !quizState.quizCompleted) {
                    quizState.currentQuestionIndex--;
                    displayQuestion();
                }
            });

            dom.nextBtn.addEventListener('click', () => {
                if (!quizState.quizCompleted) {
                    if (quizState.currentQuestionIndex < quizState.shuffledQuestions.length - 1) {
                        quizState.currentQuestionIndex++;
                        displayQuestion();
                    } else {
                        showConfirmEndQuizModal();
                    }
                }
            });

            function showConfirmEndQuizModal() {
                const modalHtml = `
                    <div id="confirm-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div class="bg-white rounded-lg p-8 shadow-xl max-w-sm w-full text-center">
                            <h3 class="text-2xl font-bold text-gray-800 mb-4">Selesaikan Kuis?</h3>
                            <p class="text-gray-600 mb-6">Anda telah mencapai akhir kuis. Apakah Anda yakin ingin menyelesaikan dan melihat hasil Anda?</p>
                            <div class="flex justify-center space-x-4">
                                <button id="cancel-end-quiz" class="bg-gray-300 text-gray-800 px-6 py-3 rounded-xl font-semibold hover:bg-gray-400 transition duration-200">Batal</button>
                                <button id="confirm-end-quiz" class="bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold hover:bg-blue-700 transition duration-200">Selesaikan</button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', modalHtml);

                document.getElementById('cancel-end-quiz').addEventListener('click', () => {
                    document.getElementById('confirm-modal').remove();
                });

                document.getElementById('confirm-end-quiz').addEventListener('click', () => {
                    document.getElementById('confirm-modal').remove();
                    endQuiz();
                });
            }


            dom.menuToggle.addEventListener('click', () => {
                dom.sidebar.classList.toggle('active');
                dom.sidebarOverlay.classList.toggle('active');
            });

            dom.sidebarOverlay.addEventListener('click', () => {
                dom.sidebar.classList.remove('active');
                dom.sidebarOverlay.classList.remove('active');
            });

            dom.timerDisplay.textContent = formatTime(quizState.timeLeft);
            
            // Inisialisasi userAnswers array dengan array kosong untuk setiap pertanyaan
            // Agar bisa menyimpan array opsi yang dipilih untuk setiap soal pilihan ganda
            quizState.userAnswers = new Array(quizDataFromDB.length).fill(null).map((_, i) => {
                // Inisialisasi dengan array kosong jika multiple_choice, atau null jika essay
                return quizDataFromDB[i].question_type === 'multiple_choice' ? [] : null;
            });
        });
    </script>
</body>
</html>