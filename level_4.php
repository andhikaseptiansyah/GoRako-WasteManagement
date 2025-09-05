<?php
// level_4.php

// Mulai sesi di awal skrip
session_start();

// Sertakan koneksi database
require_once 'db_connection.php';

// Sertakan fungsi helper (ini akan menyediakan fungsi saveGameResult, is_logged_in, redirect, sendJsonResponse)
require_once 'helpers.php';

// Aktifkan pelaporan kesalahan PHP untuk pengembangan
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Tangani pengiriman data skor dari JavaScript (menggunakan AJAX/Fetch API)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'save_score') {
    // Ambil user_id dari sesi, bukan dari POST, untuk keamanan
    if (!is_logged_in()) {
        sendJsonResponse(['status' => 'error', 'message' => 'User ID tidak ditemukan. Harap login kembali.'], 401);
        exit;
    }
    // Pastikan user_id adalah integer. Jika sesi bisa menyimpan non-integer, validasi lebih lanjut diperlukan.
    $userId = (int) $_SESSION['user_id'];

    // Validasi dan sanitasi input dari POST
    // Gunakan filter_var untuk validasi dan sanitasi yang lebih robust
    $scoreToSave = isset($_POST['score']) ? filter_var($_POST['score'], FILTER_VALIDATE_INT) : 0;
    $gameStatus = isset($_POST['status']) ? htmlspecialchars($_POST['status'], ENT_QUOTES, 'UTF-8') : 'lost';
    // Gunakan currentLevel dari JavaScript, yang sudah disetel ke 3 di client.
    // Jika Anda ingin memastikan, ambil juga dari POST tapi tetap validasi
    $levelPlayed = isset($_POST['level']) ? filter_var($_POST['level'], FILTER_VALIDATE_INT) : 3;

    // Periksa apakah input valid setelah sanitasi
    if ($scoreToSave === false || $levelPlayed === false) {
        sendJsonResponse(['status' => 'error', 'message' => 'Input skor atau level tidak valid.'], 400);
        exit;
    }

    // Pastikan gameStatus adalah salah satu dari nilai yang diharapkan
    if (!in_array($gameStatus, ['won', 'lost'])) {
        $gameStatus = 'lost'; // Default ke 'lost' jika tidak valid
    }

    // Panggil fungsi helper untuk menyimpan hasil game
    $response = saveGameResult($conn, $userId, $scoreToSave, $gameStatus, $levelPlayed);

    // Setelah menyimpan skor, kirim respons JSON
    sendJsonResponse($response); // Menggunakan fungsi sendJsonResponse dari helpers.php
    exit; // Pastikan skrip berhenti setelah mengirimkan respons JSON
}

// NEW: Endpoint untuk Leaderboard
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] == 'get_leaderboard') {
    $sql = "SELECT u.username, gr.score, gr.status FROM game_results gr JOIN users u ON gr.user_id = u.user_id ORDER BY gr.score DESC LIMIT 10"; // Ambil 10 teratas
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $leaderboard = [];
        while ($row = $result->fetch_assoc()) {
            $leaderboard[] = $row;
        }
        sendJsonResponse(['status' => 'success', 'leaderboard' => $leaderboard]);
        $stmt->close();
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Gagal menyiapkan query leaderboard: ' . $conn->error]);
    }
    $conn->close();
    exit;
}

// NEW: Endpoint untuk Statistik Pemain
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] == 'get_player_stats') {
    if (!is_logged_in()) {
        sendJsonResponse(['status' => 'error', 'message' => 'Tidak login.'], 401);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];

    // Query untuk statistik pemain
    $sqlStats = "SELECT COUNT(*) as total_games, SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as games_won, SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as games_lost, SUM(score) as total_trash_collected FROM game_results WHERE user_id = ?";
    $stmtStats = $conn->prepare($sqlStats);
    if ($stmtStats) {
        $stmtStats->bind_param("i", $userId);
        $stmtStats->execute();
        $resultStats = $stmtStats->get_result();
        $stats = $resultStats->fetch_assoc();

        sendJsonResponse(['status' => 'success', 'stats' => $stats]);
        $stmtStats->close();
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Gagal menyiapkan query statistik: ' . $conn->error]);
    }
    $conn->close();
    exit;
}


// PHP untuk menangani permintaan GET (saat halaman dimuat pertama kali)
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Pastikan user sudah login
    if (!is_logged_in()) {
        redirect('login.php'); // Ganti 'login.php' dengan halaman login Anda
        exit; // Pastikan skrip berhenti setelah redirect
    }

    // Ambil user_id dari sesi untuk digunakan di sisi klien (JavaScript)
    $userId = (int) $_SESSION['user_id'];
    $level = isset($_GET['level']) ? filter_var($_GET['level'], FILTER_VALIDATE_INT) : 3; // Ambil level dari URL
    if ($level === false) {
        $level = 3; // Default jika level dari URL tidak valid
    }
    // Tutup koneksi database jika ini bukan permintaan POST (untuk pemuatan halaman awal)
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Go Green Hero: Petualangan Edukasi Sampah! (Level 3)</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <style>
        /* --- CSS Start --- */
        body {
            margin: 0;
            overflow: hidden;
            touch-action: none;
            /* MODIFIED: Base background color, sky image now handled by #sky-background */
            background-color: #DDA0DD; /* A soft purple from the generated image for base */
            font-family: 'Press Start 2P', cursive;
            color: #333;
            width: 100vw;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            image-rendering: pixelated;
            image-rendering: -moz-crisp-edges;
            image-rendering: crisp-edges;
        }

        #game-viewport {
            width: 100vw;
            height: 100vh;
            position: relative;
            overflow: hidden;
            touch-action: none;
            background-color: transparent;
            display: none;
        }

        /* NEW: Sky Background Layer */
        #sky-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('image_generation_content/0'); /* NEW: Using the generated image */
            background-size: cover; /* Default for portrait/desktop */
            background-repeat: no-repeat;
            background-position: bottom center;
            z-index: 0; /* Behind game-world and player */
            /* filter: brightness(0.8); Adjust brightness if needed */
        }

        @media (orientation: landscape) {
            #sky-background {
                /* For landscape, fill height and center horizontally */
                background-size: auto 100%; /* Fit height, width auto */
                background-position: center bottom; /* Center horizontally, align bottom */
            }
        }
        /* End NEW: Sky Background Layer */


        #game-world {
            position: absolute;
            top: 0;
            left: 0;
            width: 15000px; /* MODIFIED: Diperlebar dunianya */
            height: 100%;
            transition: transform 0.05s linear;
            z-index: 1; /* Above sky-background */
        }

        .ground-brick-segment {
            position: absolute;
            bottom: 0px;
            width: 60px;
            height: 60px;
            /* MODIFIED: Adjusted color to blend with new background image's ground */
            background-color: #556B2F; /* Darker green/brown for ground */
            border: 1px solid #334A1F; /* Darker border */
            box-sizing: border-box;
            z-index: 1;
        }
        .ground-grass-top-segment {
            position: absolute;
            bottom: 59px;
            width: 60px;
            height: 12px;
            /* MODIFIED: Adjusted color to blend with new background image's grass */
            background-color: #70AD47; /* A more muted green grass */
            border-top: 1px solid #556B2F;
            box-sizing: border-box;
            z-index: 2;
        }

        #player {
            width: 106px;
            height: 159px;
            background-image: url('assets/images/mario_stand.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            position: absolute;
            z-index: 10;
            transition: opacity 0.1s linear;
            transform-origin: center bottom;
        }
        #player.invincible {
            animation: player-flash 0.2s steps(1) infinite alternate;
        }
        @keyframes player-flash {
            from { opacity: 1; }
            to { opacity: 0.3; }
        }

        /* MODIFIED: Removed specific cloud/smoke styles as they are now part of the background image or explicitly removed from elements */
        /* .cloud { ... } */
        /* .smoke { ... } */

        /* Existing styles for other elements remain */
        .trash {
            width: 80px;
            height: 80px;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            position: absolute;
            z-index: 10;
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }
        .collect-particle {
            position: absolute;
            width: 25px;
            height: 25px;
            background-color: #7CFC00;
            border-radius: 50%;
            opacity: 1;
            transform: scale(0);
            z-index: 11;
            animation: collect-anim 0.5s ease-out forwards;
            pointer-events: none;
            box-shadow: 0 0 10px 5px rgba(124, 252, 0, 0.5);
        }
        @keyframes collect-anim {
            0% { transform: scale(0) translateY(0); opacity: 1; }
            50% { transform: scale(1.8) translateY(-40px); opacity: 0.8; }
            100% { transform: scale(0) translateY(-80px); opacity: 0; }
        }

        .bird {
            position: absolute;
            width: 25px;
            height: 12px;
            background-color: #333;
            border-radius: 50%;
            z-index: 4;
            animation: fly-wings 1.5s linear infinite alternate;
        }
        .bird::before, .bird::after {
            content: '';
            position: absolute;
            width: 12px;
            height: 6px;
            background-color: #333;
            border-radius: 50% 50% 0 0;
            top: 3px;
        }
        .bird::before { left: -6px; transform: rotate(-30deg); transform-origin: 100% 50%; }
        .bird::after { right: -6px; transform: rotate(30deg); transform-origin: 0% 50%; }

        @keyframes fly-wings {
            0% { transform: translateY(0px) scaleY(1); }
            50% { transform: translateY(-3px) scaleY(0.8); }
            100% { transform: translateY(0px) scaleY(1); }
        }
        .bird.move-across-world {
            animation: bird-move-across-world var(--bird-speed, 20s) linear infinite;
        }
        @keyframes bird-move-across-world {
            from { transform: translateX(0); }
            to { transform: translateX(15000px); } /* MODIFIED: Disesuaikan dengan panjang dunia baru */
        }

        .obstacle-spikes {
            position: absolute;
            width: 100px;
            height: 60px;
            background-image: url('assets/images/spikes.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            z-index: 3;
        }

        .puddle {
            position: absolute;
            width: 180px;
            height: 25px;
            background-color: #4682B4;
            border-radius: 50% / 100% 100% 0 0;
            box-shadow: inset 0 -8px 15px rgba(0,0,0,0.3);
            z-index: 3;
        }

        .horizontal-pipe {
            position: absolute;
            background-color: #A0522D;
            border: 4px solid #8B4513;
            border-radius: 8px;
            box-sizing: border-box;
            z-index: 5;
            height: 40px;
        }
        .horizontal-pipe::before {
            content: '';
            position: absolute;
            top: -10px;
            left: -5px;
            width: calc(100% + 10px);
            height: 15px;
            background-color: #CD853F;
            border: 4px solid #8B4513;
            border-radius: 5px;
            box-sizing: border-box;
            z-index: 6;
        }


        .pipe {
            position: absolute;
            width: 100px;
            height: 140px;
            background-color: #8B8B8B;
            border: 4px solid #696969;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            box-sizing: border-box;
            z-index: 5;
            overflow: visible;
        }

        .pipe::before {
            content: '';
            position: absolute;
            top: -18px;
            left: -8px;
            width: 116px;
            height: 28px;
            background-color: #A9A9A9;
            border: 4px solid #696969;
            border-radius: 10px;
            box-sizing: border-box;
            z-index: 6;
        }

        .finish-pole {
            position: absolute;
            bottom: 60px;
            width: 70px;
            height: 280px;
            background-color: #A9A9A9;
            border: 6px solid #696969;
            border-radius: 10px;
            z-index: 5;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding-top: 25px;
            box-sizing: border-box;
            box-shadow: 6px 6px 12px rgba(0,0,0,0.5);
        }
        .finish-pole::before {
            content: 'FINISH!';
            position: absolute;
            top: 12px;
            left: 70px;
            width: 180px;
            height: 80px;
            background-color: #FFD700;
            border: 6px solid #DAA520;
            font-family: 'Press Start 2P', cursive;
            font-size: 28px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 7px 7px 0 rgba(0,0,0,0.7);
            transform-origin: 0 0;
            animation: flag-wave 1s ease-in-out infinite alternate;
        }

        @keyframes flag-wave {
            0% { transform: rotate(0deg); }
            50% { transform: rotate(8deg); }
            100% { transform: rotate(0deg); }
        }

        @keyframes move-across-world {
            from { transform: translateX(0); }
            to { transform: translateX(15000px); } /* MODIFIED: Disesuaikan dengan lebar dunia baru */
        }

        #top-left-info-group {
            position: absolute;
            top: 15px;
            left: 15px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        #score, #timer {
            background-color: rgba(255, 255, 255, 0.7);
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
            box-sizing: border-box;
            white-space: nowrap;
            border: 2px solid #333;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5);
            text-shadow: 1px 1px #fff;
        }
        #score { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }
        #timer { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }

        #message {
            position: absolute;
            top: 15px;
            right: 90px;
            background-color: rgba(0, 0, 0, 0.3);
            color: #FFD700;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            z-index: 100;
            max-width: calc(100% - 300px);
            text-align: center;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            border: 2px solid #fff;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5);
        }
        #message.show {
            opacity: 1;
        }

        #settings-button {
            position: absolute;
            top: 15px;
            right: 15px;
            transform: translateY(0);
            background-color: rgba(255, 255, 255, 0.7);
            width: 45px;
            height: 45px;
            border-radius: 8px;
            font-family: 'Press Start 2P', cursive;
            font-size: 30px;
            cursor: pointer;
            z-index: 101;
            border: 2px solid #333;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #333;
            text-shadow: 1px 1px #fff;
            transition: background-color 0.1s ease;
            line-height: 1;
        }
        #settings-button:hover {
            background-color: rgba(255, 255, 255, 0.9);
        }
        #settings-button:active {
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5);
            transform: translateY(1px);
        }
        @media only screen and (max-width: 768px) {
            #settings-button {
                top: 10px;
                right: 10px;
                width: 40px;
                height: 40px;
                font-size: 24px;
                padding: 0;
            }
            #message {
                top: 10px;
                right: 60px;
                max-width: calc(100% - 60px - 10px - 10px - 50px);
                font-size: 12px;
                padding: 5px 8px;
            }
            #top-left-info-group {
                top: 10px;
                left: 10px;
                gap: 5px;
            }
            #score, #timer {
                padding: 6px 10px;
                font-size: 14px;
            }
        }
        @media only screen and (max-width: 768px) and (orientation: landscape) {
            #top-left-info-group {
                top: 5px;
                left: 5px;
                gap: 5px;
            }
            #score, #timer {
                padding: 4px 8px;
                font-size: 12px;
            }
            #message {
                top: 5px;
                right: 50px;
                max-width: calc(100% - 50px - 10px - 5px - 5px - 50px);
                font-size: 10px;
                padding: 4px 6px;
            }
            #settings-button {
                top: 5px;
                right: 5px;
                width: 30px;
                height: 30px;
                font-size: 20px;
            }
            #game-over-screen p {
                font-size: 20px;
            }
            #game-over-screen button {
                padding: 10px 20px;
                font-size: 16px;
            }
            #settings-menu h2 {
                font-size: 28px;
            }
            #settings-menu button {
                padding: 10px 20px;
                font-size: 16px;
                margin: 8px 0;
            }
            #pause-confirm-dialog {
                padding: 10px;
                font-size: 14px;
            }
            #pause-confirm-dialog p {
                font-size: 16px;
                margin-bottom: 10px;
            }
            #pause-confirm-dialog button {
                padding: 6px 12px;
                font-size: 14px;
            }
        }

        #game-over-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            font-size: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 200;
            display: none;
            padding: 20px;
            box-sizing: border-box;
        }
        #game-over-screen p {
            font-size: 36px;
            margin-bottom: 30px;
            text-shadow: 4px 4px #000;
            max-width: 80%;
        }
        #game-over-screen button {
            background-color: #4CAF50;
            color: white;
            padding: 18px 35px;
            font-size: 28px;
            border: 6px solid #228B22;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 30px;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #game-over-screen button:hover {
            background-color: #5cb85c;
            transform: translateY(-3px);
            box-shadow: 9px 9px 0 rgba(0,0,0,0.7);
        }
        #game-over-screen button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 3px 3px 0 rgba(0,0,0,0.7);
        }

        /* Settings Menu */
        #settings-menu {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 250;
            display: none;
        }
        #settings-menu h2 {
            font-size: 48px;
            margin-bottom: 50px;
            text-shadow: 6px 6px #000;
        }
        #settings-menu button {
            background-color: #007BFF;
            color: white;
            padding: 18px 40px;
            font-size: 32px;
            border: 6px solid #0056b3;
            border-radius: 12px;
            cursor: pointer;
            margin: 15px 0;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #settings-menu button:hover {
            background-color: #0056b3;
            transform: translateY(-3px);
            box-shadow: 9px 9px 0 rgba(0,0,0,0.7);
        }
        #settings-menu button:active {
            background-color: #004085;
            transform: translateY(0);
            box-shadow: 3px 3px 0 rgba(0,0,0,0.7);
        }
        #settings-menu button#pause-game-button {
            background-color: #FFC107;
            border-color: #D39E00;
        }
        #settings-menu button#pause-game-button:hover {
            background-color: #D39E00;
        }
        #settings-menu button#pause-game-button:active {
            background-color: #A07800;
        }
        #settings-menu button#pause-game-button.resume-state {
            background-color: #4CAF50;
            border-color: #228B22;
        }
        #settings-menu button#pause-game-button.resume-state:hover {
            background-color: #5cb85c;
        }
        #settings-menu button#pause-game-button.resume-state:active {
            background-color: #3e8e41;
        }

        #settings-menu button#quit-game-button {
            background-color: #DC3545;
            border-color: #B02A37;
        }
        #settings-menu button#quit-game-button:hover {
            background-color: #B02A37;
        }
        #settings-menu button#quit-game-button:active {
            background-color: #8D1F2A;
        }

        /* NEW: Styles for new menu buttons */
        #settings-menu button#sound-settings-button,
        #settings-menu button#how-to-play-button,
        #settings-menu button#credits-button,
        #settings-menu button#leaderboard-button,
        #settings-menu button#game-stats-button,
        #settings-menu button#trash-collection-button,
        #settings-menu button#back-to-level-select-button {
            background-color: #007BFF;
            border-color: #0056b3;
        }

        #settings-menu button#sound-settings-button:hover,
        #settings-menu button#how-to-play-button:hover,
        #settings-menu button#credits-button:hover,
        #settings-menu button#leaderboard-button:hover,
        #settings-menu button#game-stats-button:hover,
        #settings-menu button#trash-collection-button:hover,
        #settings-menu button#back-to-level-select-button:hover {
            background-color: #0056b3;
        }
        #settings-menu button#sound-settings-button:active,
        #settings-menu button#how-to-play-button:active,
        #settings-menu button#credits-button:active,
        #settings-menu button#leaderboard-button:active,
        #settings-menu button#game-stats-button:active,
        #settings-menu button#trash-collection-button:active,
        #settings-menu button#back-to-level-select-button:active {
            background-color: #004085;
        }

        #close-settings-button {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 36px;
            font-family: Arial, sans-serif;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 260;
            border: 2px solid white;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
            line-height: 1;
            transition: background-color 0.1s ease;
        }
        #close-settings-button:hover {
            background-color: rgba(255, 255, 255, 0.4);
        }
        #close-settings-button:active {
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
            transform: translateY(2px);
        }
        @media (max-width: 768px) {
            #close-settings-button {
                width: 40px;
                height: 40px;
                font-size: 28px;
                top: 10px;
                right: 10px;
            }
        }

        /* Pause Confirmation Dialog */
        #pause-confirm-dialog {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.95);
            color: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            z-index: 300;
            display: none;
            border: 8px solid #FFD700;
            box-shadow: 0 0 20px rgba(255,215,0,0.8);
            font-size: 30px;
        }
        #pause-confirm-dialog p {
            margin-bottom: 30px;
            font-size: 32px;
            text-shadow: 4px 4px #FFD700;
        }
        #pause-confirm-dialog .dialog-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        #pause-confirm-dialog button {
            background-color: #4CAF50;
            color: white;
            padding: 15px 30px;
            font-size: 24px;
            border: 4px solid #228B22;
            border-radius: 10px;
            cursor: pointer;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #pause-confirm-dialog button:hover {
            background-color: #5cb85c;
            transform: translateY(-2px);
            box-shadow: 6px 6px 0 rgba(0,0,0,0.5);
        }
        #pause-confirm-dialog button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5);
        }
        #pause-confirm-dialog button.no-button {
            background-color: #DC3545;
            border-color: #B02A37;
        }
        #pause-confirm-dialog button.no-button:hover {
            background-color: #B02A37;
        }
        #pause-confirm-dialog button.no-button:active {
            background-color: #8D1F2A;
        }

        /* NEW: General Modal Styles */
        .game-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 400; /* Lebih tinggi dari settings-menu */
            display: none; /* Sembunyikan secara default */
        }

        .modal-content {
            background-color: #333; /* Warna gelap */
            color: white;
            padding: 30px 40px;
            border-radius: 15px;
            text-align: center;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 0 20px rgba(255,215,0,0.8);
            border: 5px solid #FFD700;
            font-family: 'Press Start 2P', cursive;
            box-sizing: border-box;
            max-height: 90vh; /* MODIFIED: Tambahkan max-height agar bisa discroll di perangkat kecil */
            overflow-y: auto; /* MODIFIED: Aktifkan scrollbar vertikal jika konten terlalu panjang */
        }

        .modal-content h2 {
            font-size: 32px;
            margin-bottom: 25px;
            color: #FFD700;
            text-shadow: 3px 3px #000;
        }

        .modal-content p {
                font-size: 18px;
                line-height: 1.5;
                margin-bottom: 15px;
                text-align: justify; /* NEW: Added for better text readability in modals */
                -webkit-hyphens: auto; /* NEW: Enable hyphens for text */
                -moz-hyphens: auto;
                hyphens: auto;
                word-break: break-word; /* NEW: Break long words */
            }


        .modal-content button {
            background-color: #007BFF;
            color: white;
            padding: 12px 25px;
            font-size: 20px;
            border: 4px solid #0056b3;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .modal-content button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
        }
        .modal-content button:active {
            background-color: #004085;
            transform: translateY(0);
            box-shadow: 2px 2px 0 rgba(0,0,0,0.7);
        }

        /* Styling untuk input range suara */
        .modal-content input[type="range"] {
            width: 80%;
            -webkit-appearance: none;
            height: 10px;
            background: #d3d3d3;
            outline: none;
            opacity: 0.7;
            transition: opacity .2s;
            border-radius: 5px;
            margin-top: 10px;
            margin-bottom: 20px;
        }

        .modal-content input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 25px;
            height: 25px;
            background: #FFD700;
            cursor: pointer;
            border-radius: 50%;
            border: 2px solid #DAA520;
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5);
        }

        /* NEW: Styling untuk konten leaderboard dan statistik */
        .modal-content table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 16px;
        }

        .modal-content table th,
        .modal-content table td {
            border: 2px solid #FFD700;
            padding: 10px;
            text-align: left;
        }

        .modal-content table th {
            background-color: #DAA520;
            color: #333;
            text-shadow: 1px 1px #fff;
        }

        /* NEW: Styling untuk koleksi sampah */
        .modal-content .trash-item-display {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .modal-content .trash-item-display img {
            width: 60px;
            height: 60px;
            margin-right: 15px;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }

        .modal-content .trash-item-display div {
            flex-grow: 1;
            text-align: left;
        }

        .modal-content .trash-item-display strong {
            display: block;
            font-size: 20px;
            margin-bottom: 5px;
            color: #7CFC00; /* Warna hijau untuk nama sampah */
        }

        .modal-content .trash-item-display span {
            font-size: 14px;
            color: #ccc;
        }


        /* --- Mobile Controls --- */
        #mobile-controls {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 120px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 150;
            padding: 10px 20px;
            box-sizing: border-box;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        @media (min-width: 769px) {
            #mobile-controls {
                display: none;
            }
        }

        .mobile-btn {
            background-color: #FFD700;
            color: #333;
            padding: 15px 25px;
            border: 4px solid #000;
            border-radius: 10px;
            font-family: 'Press Start 2P', cursive;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            width: 80px;
            height: 80px;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            flex-shrink: 0;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
            transition: background-color 0.1s ease;
            touch-action: none;
        }

        .mobile-btn:active {
            background-color: #e0c200;
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
            transform: translateY(2px);
        }

        #left-btn, #right-btn {
            margin: 0 10px;
        }

        #action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-right: 20px;
        }
        #direction-buttons {
            display: flex;
            flex-direction: row;
            margin-left: 20px;
        }

        /* --- Loading Screen --- */
        #loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #5A7E9C;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-size: 36px;
            color: white;
            z-index: 300;
            text-align: center;
        }
        #loading-screen p {
            margin-bottom: 20px;
        }
        /* --- CSS End --- */
    </style>
</head>
<body>
    <div id="loading-screen">
        <p>Memuat Game...</p>
    </div>

    <div id="game-viewport">
        <div id="sky-background"></div> <div id="top-left-info-group">
            <div id="score">Skor: 0</div>
            <div id="timer">Waktu: 180</div>
        </div>

        <div id="game-world">
        </div>

        <div id="player"></div>

        <div id="message">Kumpulkan sampah!</div>

        <button id="settings-button">☰</button>

        <div id="settings-menu">
            <h2>Pengaturan</h2>
            <button id="close-settings-button">X</button>
            <button id="pause-game-button">Jeda</button>
            <button id="sound-settings-button">Pengaturan Suara</button>
            <button id="how-to-play-button">Cara Bermain</button>
            <button id="credits-button">Kredit</button>
            <button id="leaderboard-button">Papan Peringkat</button>
            <button id="game-stats-button">Statistik Game</button>
            <button id="trash-collection-button">Koleksi Sampah</button>
            <button id="back-to-level-select-button">Pilih Level</button>
            <button id="quit-game-button">Keluar Game</button>
        </div>

        <div id="pause-confirm-dialog">
            <p>Apakah kamu ingin jeda atau istirahat?</p>
            <div class="dialog-buttons">
                <button id="pause-confirm-yes" class="yes-button">Yes</button>
                <button id="pause-confirm-no" class="no-button">No</button>
            </div>
        </div>

        <div id="game-over-screen">
            <p id="game-over-message"></p>
            <button id="restart-button">Mulai Uulang</button>
        </div>

        <div id="mobile-controls">
            <div id="direction-buttons">
                <button id="left-btn" class="mobile-btn">&lt;</button>
                <button id="right-btn" class="mobile-btn">&gt;</button>
            </div>
            <div id="action-buttons">
                <button id="up-btn" class="mobile-btn">⬆️</button>
            </div>
        </div>
    </div>

    <div id="sound-settings-modal" class="game-modal">
        <div class="modal-content">
            <h2>Pengaturan Suara</h2>
            <p>Volume Musik: <input type="range" id="music-volume" min="0" max="1" step="0.1" value="0.5"></p>
            <p>Volume Efek Suara: <input type="range" id="sfx-volume" min="0" max="1" step="0.1" value="0.7"></p>
            <button id="close-sound-settings-modal">Tutup</button>
        </div>
    </div>

    <div id="how-to-play-modal" class="game-modal">
        <div class="modal-content">
            <h2>Cara Bermain</h2>
            <p>Gunakan tombol panah **Kiri/Kanan** atau **A/D** untuk bergerak.</p>
            <p>Gunakan **Spasi** atau tombol panah **Atas/W** untuk melompat.</p>
            <p>Kumpulkan semua sampah dan hindari bahaya (duri, genangan air).</p>
            <p>Capai garis finish untuk memenangkan level!</p>
            <p>Selamatkan Bumi!</p>
            <button id="close-how-to-play-modal">Tutup</button>
        </div>
    </div>

    <div id="credits-modal" class="game-modal">
        <div class="modal-content">
            <h2>Kredit</h2>
            <p><strong>Pengembang Game:</strong> [Nama Anda/Tim Anda]</p>
            <p><strong>Aset Grafis:</strong> [Sumber Aset, misal: OpenGameArt.org, aset kustom]</p>
            <p><strong>Desain Suara:</strong> [Sumber Suara]</p>
            <p><strong>Ide & Konsep:</strong> [Sumber Ide]</p>
            <p>Terima kasih sudah bermain!</p>
            <button id="close-credits-modal">Tutup</button>
        </div>
    </div>

    <div id="leaderboard-modal" class="game-modal">
        <div class="modal-content">
            <h2>Papan Peringkat</h2>
            <div id="leaderboard-content">
                <p>Memuat papan peringkat...</p>
                </div>
            <button id="close-leaderboard-modal">Tutup</button>
        </div>
    </div>

    <div id="game-stats-modal" class="game-modal">
        <div class="modal-content">
            <h2>Statistik Game</h2>
            <div id="game-stats-content">
                <p>Total Game Dimainkan: <span id="stat-total-games">0</span></p>
                <p>Total Sampah Dikumpulkan: <span id="stat-total-trash">0</span></p>
                <p>Game Dimenangkan: <span id="stat-games-won">0</span></p>
                <p>Game Kalah: <span id="stat-games-lost">0</span></p>
                <p>Rasio Kemenangan: <span id="stat-win-ratio">0%</span></p>
                </div>
            <button id="close-game-stats-modal">Tutup</button>
        </div>
    </div>

    <div id="trash-collection-modal" class="game-modal">
        <div class="modal-content">
            <h2>Koleksi Sampah</h2>
            <div id="trash-collection-content">
                <p>Memuat koleksi sampah...</p>
                </div>
            <button id="close-trash-collection-modal">Tutup</button>
        </div>
    </div>
    <script>
        /* --- JavaScript Start --- */
        // DOM Elements
        const player = document.getElementById('player');
        const gameViewport = document.getElementById('game-viewport');
        const gameWorld = document.getElementById('game-world');
        // NEW: Reference to the new sky background element
        const skyBackground = document.getElementById('sky-background');
        const scoreDisplay = document.getElementById('score');
        const messageDisplay = document.getElementById('message');
        const timerDisplay = document.getElementById('timer');
        const gameOverScreen = document.getElementById('game-over-screen');
        const gameOverMessage = document.getElementById('game-over-message');
        const restartButton = document.getElementById('restart-button');
        const loadingScreen = document.getElementById('loading-screen');

        // Mobile Control Buttons
        const upBtn = document.getElementById('up-btn');
        const leftBtn = document.getElementById('left-btn');
        const rightBtn = document.getElementById('right-btn');

        // Settings Menu Elements
        const settingsButton = document.getElementById('settings-button');
        const settingsMenu = document.getElementById('settings-menu');
        const closeSettingsButton = document.getElementById('close-settings-button');
        const pauseGameButton = document.getElementById('pause-game-button');
        const quitGameButton = document.getElementById('quit-game-button');

        // Pause Confirmation Dialog Elements
        const pauseConfirmDialog = document.getElementById('pause-confirm-dialog');
        const pauseConfirmYes = document.getElementById('pause-confirm-yes');
        const pauseConfirmNo = document.getElementById('pause-confirm-no');

        // NEW: New Menu Buttons
        const soundSettingsButton = document.getElementById('sound-settings-button');
        const howToPlayButton = document.getElementById('how-to-play-button');
        const creditsButton = document.getElementById('credits-button');
        const leaderboardButton = document.getElementById('leaderboard-button');
        const gameStatsButton = document.getElementById('game-stats-button');
        const trashCollectionButton = document.getElementById('trash-collection-button');
        const backToLevelSelectButton = document.getElementById('back-to-level-select-button');

        // NEW: Modal Dialogs Elements
        const soundSettingsModal = document.getElementById('sound-settings-modal');
        const howToPlayModal = document.getElementById('how-to-play-modal');
        const creditsModal = document.getElementById('credits-modal');
        const leaderboardModal = document.getElementById('leaderboard-modal');
        const gameStatsModal = document.getElementById('game-stats-modal');
        const trashCollectionModal = document.getElementById('trash-collection-modal');

        const closeSoundSettingsModalBtn = document.getElementById('close-sound-settings-modal');
        const closeHowToPlayModalBtn = document.getElementById('close-how-to-play-modal');
        const closeCreditsModalBtn = document.getElementById('close-credits-modal');
        const closeLeaderboardModalBtn = document.getElementById('close-leaderboard-modal');
        const closeGameStatsModalBtn = document.getElementById('close-game-stats-modal');
        const closeTrashCollectionModalBtn = document.getElementById('close-trash-collection-modal');

        // NEW: Modal Content Elements for dynamic loading
        const leaderboardContent = document.getElementById('leaderboard-content');
        const statTotalGames = document.getElementById('stat-total-games');
        const statTotalTrash = document.getElementById('stat-total-trash');
        const statGamesWon = document.getElementById('stat-games-won');
        const statGamesLost = document.getElementById('stat-games-lost');
        const statWinRatio = document.getElementById('stat-win-ratio');
        const trashCollectionContent = document.getElementById('trash-collection-content');

        // NEW: Audio Controls (if you implement audio)
        const musicVolumeControl = document.getElementById('music-volume');
        const sfxVolumeControl = document.getElementById('sfx-volume');
        // Example audio elements (replace with actual audio files)
        // const backgroundMusic = new Audio('assets/audio/game_music.mp3');
        // backgroundMusic.loop = true;
        // backgroundMusic.volume = 0.5; // Default volume
        // const collectSound = new Audio('assets/audio/collect.wav');
        // collectSound.volume = 0.7; // Default volume
        // const jumpSound = new Audio('assets/audio/jump.wav');
        // jumpSound.volume = 0.7;
        // const damageSound = new Audio('assets/audio/damage.wav');
        // damageSound.volume = 0.7;


        // --- Game Constants ---
        const PLAYER_WIDTH = 106;
        const PLAYER_HEIGHT = 159;
        const BASE_GROUND_HEIGHT = 60; // Base height of the solid ground
        const GRAVITY = 1;
        const JUMP_VELOCITY = 24;
        const PLAYER_MOVE_SPEED = 7;
        const CAMERA_FOLLOW_OFFSET = 300;
        const GAME_DURATION_LEVEL_3 = 240; // MODIFIED: 4 minutes for longer world
        const TRASH_PARTICLE_COLOR = '#7CFC00';
        const TRASH_SPAWN_PADDING = 50;
        const MAX_TRASH_SPAWN_ATTEMPTS = 50;
        const PLAYER_COLLISION_TOLERANCE_X = 25;
        const PLAYER_COLLISION_TOLERANCE_Y = 12;
        const GAME_WORLD_WIDTH = 15000; // MODIFIED: Lebar dunia game baru (15000px)

        // --- Game State Variables ---
        let playerXInWorld = 100;
        let playerY = BASE_GROUND_HEIGHT;
        let isJumping = false;
        let score = 0;
        let velocityY = 0;
        let currentCarriedTrash = null;
        let cameraX = 0;
        let gameTime = GAME_DURATION_LEVEL_3;
        let gameTimerInterval = null;
        let gameActive = false;
        let gamePaused = false;
        let settingsMenuOpen = false;
        let currentWorldTrashCollected = 0;
        let obstacleSpawnInterval = null; // Unused but kept for consistency if future levels add it back
        const pressedKeys = {};
        let activeTrash = [];
        let activeMovingObstacles = []; // Unused but kept for consistency
        let activeLevelElements = [];
        let animationFrameId;
        let initialGroundAndLevelSetupDone = false;
        let playerAnimationState = 'stand'; // 'stand', 'walk', 'jump'
        let playerDirection = 'right'; // 'left', 'right'
        let collectedTrashTypes = new Set(); // NEW: Menyimpan jenis sampah yang sudah dikumpulkan

        // Player Animation Frames (for walking)
        // MODIFIED: Removed mario_walk_right_2.png and mario_walk_left_2.png
        const walkFramesRight = ['assets/images/mario_walk_right_1.png'];
        const walkFramesLeft = ['assets/images/mario_walk_left_1.png'];
        let currentWalkFrame = 0;
        let walkAnimationInterval = null;


        // --- Asset Definitions ---
        const TRASH_TYPES = [
            { name: 'plastik', img: 'assets/images/trash_plastik.png', message: 'Botol plastik bisa didaur ulang menjadi serat pakaian atau perabot baru! Kurangi sampah plastik di laut ya.' },
            { name: 'kertas', img: 'assets/images/trash_kertas.png', message: 'Kertas bekasmu bisa jadi buku atau kotak kemasan lagi! Pisahkan dari sampah basah!' },
            { name: 'kaleng', img: 'assets/images/trash_kaleng.png', message: 'Kaleng aluminium dan baja sangat berharga untuk didaur ulang. Hemat energi dan sumber daya alam!' },
            { name: 'kaca', img: 'assets/images/trash_kaca.png', message: 'Kaca bisa didaur ulang berkali-kali tanpa mengurangi kualitas! Pastikan bersih dan tidak pecah ya.' },
            { name: 'organik', img: 'assets/images/trash_organik.png', message: 'Sisa makanan dan daun kering bisa jadi kompos subur untuk tanaman. Jangan dibuang ke TPA!' },
            { name: 'baterai', img: 'assets/images/trash_baterai.png', message: 'Baterai mengandung zat berbahaya! Jangan dibuang sembarangan, kumpulkan di tempat khusus daur ulang.' }
        ];

        // MODIFIED: Updated IMAGE_ASSETS to remove mario_walk_2.png files and add new sky background
        const IMAGE_ASSETS = [
            'assets/images/mario_stand.png',
            'assets/images/mario_jump.png',
            'assets/images/mario_walk_right_1.png',
            'assets/images/mario_walk_left_1.png',
            'assets/images/trash_plastik.png',
            'assets/images/trash_kertas.png',
            'assets/images/trash_kaleng.png',
            'assets/images/trash_kaca.png',
            'assets/images/trash_organik.png',
            'assets/images/trash_baterai.png',
            'assets/images/spikes.png',
            'image_generation_content/0' // NEW: Add the new generated sky image as an asset
        ];

        // NEW: Audio Assets (add your audio files here)
        const AUDIO_ASSETS = {
            // backgroundMusic: 'assets/audio/game_music.mp3',
            // collectSound: 'assets/audio/collect.wav',
            // jumpSound: 'assets/audio/jump.wav',
            // damageSound: 'assets/audio/damage.wav'
        };

        // --- World Definition (Level 3 specific) ---
        const WORLDS = [
            // World 0 (Pinggiran Kota Hijau) - Not used for Level 3 but kept for reference
            {
                name: "Pinggiran Kota Hijau",
                background: '#5A7E9C', // This will be overridden by body background-image/sky-background
                elements: [
                    // MODIFIED: Removed clouds and smoke from this world definition
                    /* { type: 'cloud', class: 'type-1', top: 80, left: 150 }, */
                    /* ... other clouds ... */
                    /* { type: 'smoke', class: 'type-1', top: 200, left: 500 }, */
                    /* ... other smoke ... */

                    { type: 'bird-flying', top: 200, left: 100, speed: 25 },
                    { type: 'bird-flying', top: 250, left: 600, speed: 20, delay: -5 },
                    { type: 'bird-flying', top: 180, left: 1200, speed: 30, delay: -10 },
                    { type: 'bird-flying', top: 220, left: 1800, speed: 22 },
                    { type: 'bird-flying', top: 200, left: 2500, speed: 28, delay: -7 },
                    { type: 'bird-flying', top: 230, left: 3000, speed: 20 },
                    { type: 'bird-flying', top: 190, left: 3700, speed: 26, delay: -3 },
                    { type: 'bird-flying', top: 210, left: 4400, speed: 23 },
                    { type: 'bird-flying', top: 240, left: 5000, speed: 29, delay: -8 },
                    { type: 'bird-flying', top: 170, left: 5600, speed: 21 },
                    { type: 'bird-flying', top: 200, left: 6300, speed: 25 },
                    { type: 'bird-flying', top: 250, left: 7000, speed: 20, delay: -5 },
                    // Tambahan burung untuk dunia yang lebih panjang
                    { type: 'bird-flying', top: 210, left: 8000, speed: 24 },
                    { type: 'bird-flying', top: 260, left: 8700, speed: 19, delay: -4 },
                    { type: 'bird-flying', top: 190, left: 9500, speed: 26 },
                    { type: 'bird-flying', top: 220, left: 10200, speed: 21, delay: -7 },
                    { type: 'bird-flying', top: 200, left: 11000, speed: 27 },


                    // Obstacles (jarak diatur lebih jauh)
                    { type: 'obstacle-spikes', left: 700 },
                    { type: 'puddle', left: 1500 },
                    { type: 'obstacle-spikes', left: 2300 },
                    { type: 'puddle', left: 3200 },
                    { type: 'obstacle-spikes', left: 4000 },
                    { type: 'puddle', left: 4900 },
                    { type: 'obstacle-spikes', left: 5700 },
                    { type: 'puddle', left: 6600 },
                    { type: 'obstacle-spikes', left: 7400 },
                    { type: 'puddle', left: 7800 },
                    // Obstacles tambahan untuk dunia yang lebih panjang
                    { type: 'obstacle-spikes', left: 8500 },
                    { type: 'puddle', left: 9300 },
                    { type: 'obstacle-spikes', left: 10000 },
                    { type: 'puddle', left: 10800 },

                    // Horizontal pipes for World 0 - Adjusting for length and height
                    { type: 'horizontal-pipe', left: 950, width: 280, height: 40, bottom: 180 },
                    { type: 'horizontal-pipe', left: 1600, width: 350, height: 50, bottom: 250 },
                    { type: 'horizontal-pipe', left: 2900, width: 250, height: 35, bottom: 200 },
                    { type: 'horizontal-pipe', left: 4100, width: 300, height: 45, bottom: 280 },
                    { type: 'horizontal-pipe', left: 5900, width: 270, height: 40, bottom: 210 },
                    { type: 'horizontal-pipe', left: 7500, width: 320, height: 50, bottom: 260 },
                    // Horizontal pipes tambahan untuk dunia yang lebih panjang
                    { type: 'horizontal-pipe', left: 8300, width: 200, height: 30, bottom: 150 },
                    { type: 'horizontal-pipe', left: 9000, width: 300, height: 40, bottom: 220 },
                    { type: 'horizontal-pipe', left: 9800, width: 260, height: 38, bottom: 190 },
                    { type: 'horizontal-pipe', left: 10500, width: 340, height: 55, bottom: 290 },


                    // Pipa abu-abu (jumlahnya dikurangi menjadi 5) untuk World 0
                    { type: 'pipe', left: 450 },
                    { type: 'pipe', left: 1800 },
                    { type: 'pipe', left: 3300 },
                    { type: 'pipe', left: 5100 },
                    { type: 'pipe', left: 6600 },
                    // Tambahan pipa abu-abu untuk dunia yang lebih panjang
                    { type: 'pipe', left: 8800 },
                    { type: 'pipe', left: 10300 },

                    { type: 'finish-pole', left: 14800 } /* MODIFIED: Sesuaikan posisi finish-pole dengan dunia baru */
                ]
            },
            // World 1: Taman Indah (Used for Level 3)
            {
                name: "Taman Indah",
                background: '#87CEEB', // This will be overridden by body background-image/sky-background
                elements: [
                    // MODIFIED: Removed clouds and smoke from this world definition as they are now part of the background image
                    /* { type: 'cloud', class: 'type-1', top: 100, left: 200 }, */
                    /* ... other clouds ... */
                    /* { type: 'smoke', class: 'type-1', top: 180, left: 300 }, */
                    /* ... other smoke ... */

                    // Horizontal Pipes (replacing trees), dengan ketinggian yang lebih bervariasi dan lebih tinggi
                    { type: 'horizontal-pipe', left: 500, width: 250, height: 40, bottom: 180 },
                    { type: 'horizontal-pipe', left: 1300, width: 300, height: 35, bottom: 250 },
                    { type: 'horizontal-pipe', left: 2800, width: 280, height: 45, bottom: 120 },
                    { type: 'horizontal-pipe', left: 3500, width: 350, height: 50, bottom: 300 }, // Sangat tinggi
                    { type: 'horizontal-pipe', left: 4500, width: 270, height: 40, bottom: 200 },
                    { type: 'horizontal-pipe', left: 5500, width: 320, height: 45, bottom: 160 },
                    { type: 'horizontal-pipe', left: 6700, width: 290, height: 50, bottom: 280 },
                    // Horizontal pipes tambahan untuk dunia yang lebih panjang
                    { type: 'horizontal-pipe', left: 7500, width: 200, height: 30, bottom: 150 },
                    { type: 'horizontal-pipe', left: 8200, width: 300, height: 40, bottom: 220 },
                    { type: 'horizontal-pipe', left: 9000, width: 260, height: 38, bottom: 190 },
                    { type: 'horizontal-pipe', left: 9700, width: 340, height: 55, bottom: 290 },
                    { type: 'horizontal-pipe', left: 10500, width: 280, height: 40, bottom: 230 },
                    { type: 'horizontal-pipe', left: 11200, width: 310, height: 48, bottom: 170 },
                    { type: 'horizontal-pipe', left: 12000, width: 250, height: 40, bottom: 200 },
                    { type: 'horizontal-pipe', left: 13500, width: 300, height: 45, bottom: 260 },


                    // Pipa abu-abu (jumlah dikurangi menjadi 5)
                    { type: 'pipe', left: 800 },
                    { type: 'pipe', left: 2200 },
                    { type: 'pipe', left: 3800 },
                    { type: 'pipe', left: 5400 },
                    { type: 'pipe', left: 7000 },
                    // Pipa abu-abu tambahan untuk dunia yang lebih panjang
                    { type: 'pipe', left: 8800 },
                    { type: 'pipe', left: 10300 },
                    { type: 'pipe', left: 11800 },
                    { type: 'pipe', left: 13000 },

                    // Flying Birds
                    { type: 'bird-flying', top: 200, left: 400, speed: 20 },
                    { type: 'bird-flying', top: 250, left: 1000, speed: 18, delay: -3 },
                    { type: 'bird-flying', top: 180, left: 1600, speed: 22 },
                    { type: 'bird-flying', top: 220, left: 2300, speed: 19, delay: -6 },
                    { type: 'bird-flying', top: 200, left: 3000, speed: 25 },
                    { type: 'bird-flying', top: 230, left: 3700, speed: 21 },
                    { type: 'bird-flying', top: 190, left: 4500, speed: 27, delay: -4 },
                    { type: 'bird-flying', top: 210, left: 5200, speed: 20 },
                    { type: 'bird-flying', top: 240, left: 6000, speed: 23, delay: -9 },
                    { type: 'bird-flying', top: 170, left: 6700, speed: 18 },
                    { type: 'bird-flying', top: 200, left: 7400, speed: 22 },
                    { type: 'bird-flying', top: 220, left: 7800, speed: 20, delay: -2 },
                    // Tambahan burung untuk dunia yang lebih panjang
                    { type: 'bird-flying', top: 210, left: 8500, speed: 24 },
                    { type: 'bird-flying', top: 260, left: 9300, speed: 19, delay: -4 },
                    { type: 'bird-flying', top: 190, left: 10000, speed: 26 },
                    { type: 'bird-flying', top: 220, left: 10700, speed: 21, delay: -7 },
                    { type: 'bird-flying', top: 200, left: 11400, speed: 27 },
                    { type: 'bird-flying', top: 230, left: 12200, speed: 22 },
                    { type: 'bird-flying', top: 180, left: 13000, speed: 25 },
                    { type: 'bird-flying', top: 200, left: 13800, speed: 20 },


                    // Obstacles (Spikes and Puddles), jarak diatur lebih jauh lagi
                    { type: 'obstacle-spikes', left: 600 },
                    { type: 'puddle', left: 1600 },
                    { type: 'obstacle-spikes', left: 2500 },
                    { type: 'puddle', left: 3400 },
                    { type: 'obstacle-spikes', left: 4200 },
                    { type: 'puddle', left: 5100 },
                    { type: 'obstacle-spikes', left: 5900 },
                    { type: 'puddle', left: 6800 },
                    { type: 'obstacle-spikes', left: 7500 },
                    { type: 'puddle', left: 7700 },
                    // Obstacles tambahan untuk dunia yang lebih panjang
                    { type: 'obstacle-spikes', left: 8400 },
                    { type: 'puddle', left: 9200 },
                    { type: 'obstacle-spikes', left: 10100 },
                    { type: 'puddle', left: 10900 },
                    { type: 'obstacle-spikes', left: 11500 },
                    { type: 'puddle', left: 12400 },
                    { type: 'obstacle-spikes', left: 13200 },
                    { type: 'puddle', left: 14000 },

                    { type: 'finish-pole', left: 14800 } /* MODIFIED: Sesuaikan posisi finish-pole dengan dunia baru */
                ]
            }
        ];
        let currentWorldIndex = 1; // Set to 1 for "Taman Indah" (Level 3)

        // --- Game Functions ---

        function updatePlayerAnimation() {
            let imgPath = '';
            if (playerAnimationState === 'jump') {
                imgPath = 'assets/images/mario_jump.png';
            } else if (playerAnimationState === 'walk') {
                // MODIFIED: walkFramesRight/Left now only has one frame
                // Menghapus animasi walk jika hanya ada 1 frame
                if (walkAnimationInterval !== null && walkFramesRight.length === 1) {
                    clearInterval(walkAnimationInterval);
                    walkAnimationInterval = null;
                } else if (walkAnimationInterval === null && walkFramesRight.length > 1) { // Only start interval if there's more than one frame
                    walkAnimationInterval = setInterval(() => {
                        currentWalkFrame = (currentWalkFrame + 1) % walkFramesRight.length; // Cycle between available frames
                        updatePlayerAnimation(); // Call itself to update image
                    }, 150); // Adjust speed of animation
                }
                imgPath = playerDirection === 'right' ? walkFramesRight[currentWalkFrame] : walkFramesLeft[currentWalkFrame];
            } else { // stand
                imgPath = 'assets/images/mario_stand.png';
                clearInterval(walkAnimationInterval); // Stop walk animation when standing
                walkAnimationInterval = null;
                currentWalkFrame = 0; // Reset frame
            }
            player.style.backgroundImage = `url('${imgPath}')`;
            player.style.transform = `scaleY(1)`; // No need for scaleX(-1) with separate images
        }

        function playLandingAnimation() {
            player.style.transform = `scaleY(0.9)`; // Shrink slightly on landing
            setTimeout(() => {
                player.style.transform = `scaleY(1)`; // Return to normal size
            }, 100);
        }

        function initializeLevelElements() {
            // Remove existing elements from previous level/reset
            activeLevelElements.forEach(el => {
                if (el && typeof el.remove === 'function' && el.parentNode === gameWorld) {
                    el.remove();
                }
            });
            activeLevelElements = [];

            // MODIFIED: background color is now managed by body CSS or #sky-background
            // document.body.style.backgroundColor = WORLDS[currentWorldIndex].background;

            WORLDS[currentWorldIndex].elements.forEach(elDef => {
                const element = document.createElement('div');
                element.style.position = 'absolute';

                // Set bottom based on BASE_GROUND_HEIGHT for most elements
                if (elDef.type === 'obstacle-spikes' || elDef.type === 'puddle' || elDef.type === 'pipe' || elDef.type === 'finish-pole') {
                    element.style.bottom = BASE_GROUND_HEIGHT + 'px';
                } else if (elDef.type === 'horizontal-pipe') { // Horizontal pipes can have custom height and bottom
                    element.style.bottom = (elDef.bottom !== undefined ? elDef.bottom : BASE_GROUND_HEIGHT) + 'px';
                }
                else if (elDef.bottom !== undefined) {
                    element.style.bottom = elDef.bottom + 'px';
                } else if (elDef.top !== undefined) {
                    element.style.top = elDef.top + 'px';
                }

                element.style.left = elDef.left + 'px';

                // MODIFIED: Removed cloud and smoke element creation here, as they are now part of the background image
                /* if (elDef.type === 'cloud') {
                    element.classList.add('cloud', elDef.class);
                } else if (elDef.type === 'smoke') {
                    element.classList.add('smoke', elDef.class);
                } else */ if (elDef.type === 'bird-flying') {
                    element.classList.add('bird');
                    element.style.setProperty('--bird-speed', `${elDef.speed}s`);
                    if (elDef.delay) element.style.animationDelay = `${elDef.delay}s`;
                    element.classList.add('move-across-world');
                } else if (elDef.type === 'obstacle-spikes') {
                    element.classList.add('obstacle-spikes');
                    element.dataset.hazard = 'true';
                } else if (elDef.type === 'puddle') {
                    element.classList.add('puddle');
                    element.dataset.hazard = 'true';
                } else if (elDef.type === 'pipe') {
                    element.classList.add('pipe');
                    element.dataset.platform = 'true';
                    if (elDef.height) element.style.height = elDef.height + 'px';
                } else if (elDef.type === 'horizontal-pipe') { // Handle new horizontal pipe
                    element.classList.add('horizontal-pipe');
                    element.dataset.platform = 'true'; // These are also platforms
                    if (elDef.width) element.style.width = elDef.width + 'px';
                    if (elDef.height) element.style.height = elDef.height + 'px';
                }
                else if (elDef.type === 'finish-pole') {
                    element.classList.add('finish-pole');
                    element.dataset.finish = 'true';
                }
                gameWorld.appendChild(element);
                activeLevelElements.push(element);
            });
        }

        function createGround() {
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            // Use the new GAME_WORLD_WIDTH constant
            const worldWidth = GAME_WORLD_WIDTH;
            const segmentWidth = 60;
            const numSegments = Math.ceil(worldWidth / segmentWidth);

            if (numSegments === 0 || worldWidth === 0) {
                console.warn("gameWorld.offsetWidth is 0 or too small. Ground will not be created on this attempt.");
                return;
            }

            for (let i = 0; i < numSegments; i++) {
                const segmentX = i * segmentWidth;
                const segmentHeight = BASE_GROUND_HEIGHT;

                const brick = document.createElement('div');
                brick.classList.add('ground-brick-segment');
                brick.style.left = segmentX + 'px';
                brick.style.height = segmentHeight + 'px';
                gameWorld.appendChild(brick);

                const grass = document.createElement('div');
                grass.classList.add('ground-grass-top-segment');
                grass.style.left = segmentX + 'px';
                grass.style.bottom = segmentHeight - 1 + 'px';
                gameWorld.appendChild(grass);

                grass.dataset.height = Math.round(segmentHeight);
            }
        }

        function updatePlayerAndCameraPosition() {
            let playerXInViewport = playerXInWorld - cameraX;

            if (playerXInViewport > gameViewport.offsetWidth - PLAYER_WIDTH - CAMERA_FOLLOW_OFFSET) {
                cameraX = playerXInWorld - (gameViewport.offsetWidth - PLAYER_WIDTH - CAMERA_FOLLOW_OFFSET);
            } else if (playerXInViewport < CAMERA_FOLLOW_OFFSET) {
                cameraX = playerXInWorld - CAMERA_FOLLOW_OFFSET;
            }

            if (cameraX < 0) cameraX = 0;
            // Use the new GAME_WORLD_WIDTH constant for maxCameraX
            const maxCameraX = GAME_WORLD_WIDTH - gameViewport.offsetWidth;
            if (cameraX > maxCameraX) {
                cameraX = maxCameraX;
            }

            player.style.left = (playerXInWorld - cameraX) + 'px';
            player.style.bottom = playerY + 'px';

            gameWorld.style.transform = `translateX(${-cameraX}px)`;

            if (currentCarriedTrash) {
                currentCarriedTrash.style.left = (playerXInWorld - cameraX + PLAYER_WIDTH / 2 - currentCarriedTrash.offsetWidth / 2) + 'px';
                currentCarriedTrash.style.bottom = (playerY + PLAYER_HEIGHT + 5) + 'px';
            }
        }

        function jump() {
            if (!isJumping && gameActive && !gamePaused) {
                isJumping = true;
                velocityY = JUMP_VELOCITY;
                playerAnimationState = 'jump';
                updatePlayerAnimation();
                // if (jumpSound) jumpSound.play();
            }
        }

        function takeDamage() {
            if (!gameActive || player.classList.contains('invincible')) return; // Prevent multiple damage
            // if (damageSound) damageSound.play();
            player.classList.add('invincible');
            setTimeout(() => {
                player.classList.remove('invincible');
            }, 1000); // 1 second invincibility frames

            endGame(false, 'damage_hazard');
        }

        function gameLoop() {
            if (!gameActive || gamePaused) {
                animationFrameId = requestAnimationFrame(gameLoop);
                return;
            }

            if (!initialGroundAndLevelSetupDone) {
                document.getElementById('game-viewport').style.display = 'block';
                createGround();
                initializeLevelElements();

                if (document.querySelectorAll('.ground-brick-segment').length > 0) {
                    initialGroundAndLevelSetupDone = true;
                } else {
                    animationFrameId = requestAnimationFrame(gameLoop);
                    return;
                }
            }

            let movingHorizontally = false;

            if (pressedKeys['ArrowRight'] || pressedKeys['d']) {
                playerXInWorld += PLAYER_MOVE_SPEED;
                if (playerXInWorld + PLAYER_WIDTH > GAME_WORLD_WIDTH) {
                    playerXInWorld = GAME_WORLD_WIDTH - PLAYER_WIDTH;
                }
                playerDirection = 'right';
                if (!isJumping) playerAnimationState = 'walk';
                movingHorizontally = true;
            } else if (pressedKeys['ArrowLeft'] || pressedKeys['a']) {
                playerXInWorld -= PLAYER_MOVE_SPEED;
                if (playerXInWorld < 0) {
                    playerXInWorld = 0;
                }
                playerDirection = 'left';
                if (!isJumping) playerAnimationState = 'walk';
                movingHorizontally = true;
            } else {
                if (!isJumping) {
                    playerAnimationState = 'stand';
                }
            }

            let currentGroundHeight = BASE_GROUND_HEIGHT;

            activeLevelElements.forEach(el => {
                if (el.dataset.platform === 'true') {
                    const platformWorldX = parseInt(el.style.left);
                    const platformBottom = parseInt(el.style.bottom);
                    const platformWidth = parseInt(el.offsetWidth);
                    const platformHeight = parseInt(el.offsetHeight);
                    const platformTopSurfaceY = platformBottom + platformHeight;

                    const playerPreviousY = playerY - velocityY;

                    // Only consider platforms that are below the player's top and above player's bottom
                    if (playerXInWorld + PLAYER_WIDTH > platformWorldX &&
                        playerXInWorld < platformWorldX + platformWidth &&
                        playerPreviousY >= platformTopSurfaceY && // Player was above or at platform top
                        playerY <= platformTopSurfaceY) { // Player is now at or below platform top

                        if (velocityY < 0) { // If player is falling
                            currentGroundHeight = Math.max(currentGroundHeight, platformTopSurfaceY); // Take the highest platform
                        }
                    }
                }
            });

            if (isJumping) {
                playerY += velocityY;
                velocityY -= GRAVITY;

                if (playerY <= currentGroundHeight && velocityY <= 0) {
                    playerY = currentGroundHeight;
                    isJumping = false;
                    velocityY = 0;
                    playerAnimationState = 'stand';
                    updatePlayerAnimation();
                    playLandingAnimation();
                }
            } else {
                let shouldBeFalling = true;
                activeLevelElements.forEach(el => {
                    if (el.dataset.platform === 'true') {
                        const platformWorldX = parseInt(el.style.left);
                        const platformBottom = parseInt(el.style.bottom);
                        const platformWidth = parseInt(el.offsetWidth);
                        const platformHeight = parseInt(el.offsetHeight);
                        const platformTopSurfaceY = platformBottom + platformHeight;

                        if (playerXInWorld + PLAYER_WIDTH > platformWorldX &&
                            playerXInWorld < platformWorldX + platformWidth &&
                            playerY === platformTopSurfaceY) {
                            shouldBeFalling = false;
                        }
                    }
                });

                if (playerY > BASE_GROUND_HEIGHT && shouldBeFalling) {
                     isJumping = true;
                     velocityY = -GRAVITY;
                     playerAnimationState = 'jump';
                     updatePlayerAnimation();
                } else if (playerY < BASE_GROUND_HEIGHT && !shouldBeFalling) {
                    playerY = BASE_GROUND_HEIGHT;
                    velocityY = 0;
                    isJumping = false;
                    playerAnimationState = 'stand';
                    updatePlayerAnimation();
                    playLandingAnimation();
                } else if (playerY === currentGroundHeight && !movingHorizontally) {
                    playerAnimationState = 'stand';
                }
            }

            updatePlayerAnimation();
            updatePlayerAndCameraPosition();
            checkCollisions();
            checkGameEnd();
            animationFrameId = requestAnimationFrame(gameLoop);
        }

        // --- Game Pause/Resume Functionality ---
        function showSettingsMenu() {
            settingsMenuOpen = true;
            settingsMenu.style.display = 'flex';
            document.getElementById('mobile-controls').style.display = 'none';
            settingsButton.style.display = 'none';
            // if (backgroundMusic) backgroundMusic.pause();
        }

        function hideSettingsMenu() {
            settingsMenuOpen = false;
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none';
            // NEW: Ensure all modals are hidden when closing settings
            soundSettingsModal.style.display = 'none';
            howToPlayModal.style.display = 'none';
            creditsModal.style.display = 'none';
            leaderboardModal.style.display = 'none';
            gameStatsModal.style.display = 'none';
            trashCollectionModal.style.display = 'none';

            // Show controls/settings button only if game is active
            if (gameActive && !gamePaused) {
                document.getElementById('mobile-controls').style.display = 'flex';
                settingsButton.style.display = 'block';
                // if (backgroundMusic) backgroundMusic.play();
            } else if (gameActive && gamePaused) { // If paused from within game, keep settings button visible
                 settingsButton.style.display = 'block';
            }
        }

        function confirmPause() {
            if (!gameActive || gamePaused) return;
            gamePaused = true;
            clearInterval(gameTimerInterval);
            gameTimerInterval = null;
            if (obstacleSpawnInterval) {
                clearInterval(obstacleSpawnInterval);
                obstacleSpawnInterval = null;
            }
            clearInterval(walkAnimationInterval);
            walkAnimationInterval = null;
            cancelAnimationFrame(animationFrameId);
            pauseGameButton.textContent = "Lanjutkan Game";
            pauseGameButton.classList.add('resume-state');
            hideSettingsMenu();
            // if (backgroundMusic) backgroundMusic.pause();
        }

        function askToPause() {
            if (!gameActive) return;

            if (gamePaused) {
                resumeGame();
            } else {
                pauseConfirmDialog.style.display = 'flex';
            }
        }

        function resumeGame() {
            if (!gameActive || !gamePaused) return;

            gamePaused = false;
            startGameTimer();
            pauseGameButton.textContent = "Jeda";
            pauseGameButton.classList.remove('resume-state');
            hideSettingsMenu();
            animationFrameId = requestAnimationFrame(gameLoop);
            // if (backgroundMusic) backgroundMusic.play();
        }

        // NEW: Modal Functions
        function showModal(modalElement) {
            hideSettingsMenu(); // Hide settings menu first
            modalElement.style.display = 'flex';
            // Optionally, pause the game when any modal is shown
            // if (!gamePaused) {
            //    confirmPause(); // This will stop gameLoop and timer
            // }
        }

        function hideModal(modalElement) {
            modalElement.style.display = 'none';
            showSettingsMenu(); // Show settings menu again after closing modal
            // Optionally, resume the game if it was paused by the modal
            // if (gamePaused && !settingsMenuOpen) { // Check if not opened another modal
            //     resumeGame();
            // }
        }

        // NEW: Functions for specific new menu items
        function showSoundSettings() {
            showModal(soundSettingsModal);
            // Initializing sliders with actual or default values
            // if (musicVolumeControl && backgroundMusic) musicVolumeControl.value = backgroundMusic.volume;
            // if (sfxVolumeControl && collectSound) sfxVolumeControl.value = collectSound.volume; // Assuming all SFX share a volume
        }

        function showHowToPlay() {
            showModal(howToPlayModal);
        }

        function showCredits() {
            showModal(creditsModal);
        }

        async function showLeaderboard() {
            showModal(leaderboardModal);
            leaderboardContent.innerHTML = '<p>Memuat papan peringkat...</p>'; // Reset content

            try {
                const response = await fetch('level_4.php?action=get_leaderboard'); // Fetch from this same PHP file
                const data = await response.json();

                if (data.status === 'success') {
                    if (data.leaderboard && data.leaderboard.length > 0) {
                        let tableHtml = '<table><thead><tr><th>Rank</th><th>Pemain</th><th>Skor</th><th>Status</th></tr></thead><tbody>';
                        data.leaderboard.forEach((entry, index) => {
                            tableHtml += `<tr><td>${index + 1}</td><td>${htmlspecialchars(entry.username)}</td><td>${entry.score}</td><td>${entry.status === 'won' ? 'Menang' : 'Kalah'}</td></tr>`;
                        });
                        tableHtml += '</tbody></table>';
                        leaderboardContent.innerHTML = tableHtml;
                    } else {
                        leaderboardContent.innerHTML = '<p>Belum ada data papan peringkat.</p>';
                    }
                } else {
                    leaderboardContent.innerHTML = `<p>Gagal memuat papan peringkat: ${htmlspecialchars(data.message)}</p>`;
                    console.error('Error fetching leaderboard:', data.message);
                }
            } catch (error) {
                leaderboardContent.innerHTML = '<p>Terjadi kesalahan saat berkomunikasi dengan server.</p>';
                console.error('Network error fetching leaderboard:', error);
            }
        }

        async function showGameStats() {
            showModal(gameStatsModal);
            // Anda perlu memastikan $userId tersedia di PHP dan dikirimkan ke JavaScript
            const userId = <?php echo $userId; ?>; // Ambil user ID dari PHP

            try {
                const response = await fetch(`level_4.php?action=get_player_stats&user_id=${userId}`); // Fetch from this same PHP file
                const data = await response.json();

                if (data.status === 'success' && data.stats) {
                    const stats = data.stats;
                    statTotalGames.textContent = stats.total_games || 0;
                    statTotalTrash.textContent = stats.total_trash_collected || 0;
                    statGamesWon.textContent = stats.games_won || 0;
                    statGamesLost.textContent = stats.games_lost || 0;

                    const totalGames = stats.total_games || 0;
                    const gamesWon = stats.games_won || 0;
                    const winRatio = totalGames > 0 ? ((gamesWon / totalGames) * 100).toFixed(2) : 0;
                    statWinRatio.textContent = `${winRatio}%`;
                } else {
                    console.error('Gagal memuat statistik pemain:', data.message);
                    statTotalGames.textContent = 'N/A';
                    statTotalTrash.textContent = 'N/A';
                    statGamesWon.textContent = 'N/A';
                    statGamesLost.textContent = 'N/A';
                    statWinRatio.textContent = 'N/A';
                }
            } catch (error) {
                console.error('Error fetching player stats:', error);
                statTotalGames.textContent = 'Error';
                statTotalTrash.textContent = 'Error';
                statGamesWon.textContent = 'Error';
                statGamesLost.textContent = 'Error';
                statWinRatio.textContent = 'Error';
            }
        }

        function showTrashCollection() {
            showModal(trashCollectionModal);
            trashCollectionContent.innerHTML = ''; // Kosongkan konten sebelumnya

            if (collectedTrashTypes.size === 0) {
                trashCollectionContent.innerHTML = '<p>Belum ada sampah yang terkumpul. Mulai kumpulkan!</p>';
                return;
            }

            // Urutkan jenis sampah agar tampil rapi
            const sortedTrashTypes = Array.from(collectedTrashTypes).sort();

            sortedTrashTypes.forEach(type => {
                const trashDef = TRASH_TYPES.find(t => t.name === type);
                if (trashDef) {
                    const trashItemDiv = document.createElement('div');
                    trashItemDiv.classList.add('trash-item-display');
                    const img = document.createElement('img');
                    img.src = trashDef.img;
                    img.alt = trashDef.name;
                    const textDiv = document.createElement('div');
                    const nameStrong = document.createElement('strong');
                    nameStrong.textContent = trashDef.name.charAt(0).toUpperCase() + trashDef.name.slice(1); // Kapitalisasi
                    const messageSpan = document.createElement('span');
                    messageSpan.textContent = trashDef.message;

                    textDiv.appendChild(nameStrong);
                    textDiv.appendChild(messageSpan);
                    trashItemDiv.appendChild(img);
                    trashItemDiv.appendChild(textDiv);
                    trashCollectionContent.appendChild(trashItemDiv);
                }
            });
        }

        function backToLevelSelection() {
            if (confirm("Apakah Anda yakin ingin kembali ke pemilihan level? Progres game ini akan hilang.")) {
                // Hentikan game sebelum redirect
                gameActive = false;
                gamePaused = false;
                clearInterval(gameTimerInterval);
                clearInterval(walkAnimationInterval);
                cancelAnimationFrame(animationFrameId);
                // if (backgroundMusic) backgroundMusic.pause();
                window.location.href = 'game_petualangan.php'; // Ganti dengan URL halaman pemilihan level Anda
            }
        }

        // --- Event Listeners ---
        document.addEventListener('keydown', (e) => {
            if (!gameActive) return;
            if (e.repeat) return;

            // NEW: Handle Escape key for modals first
            if (e.code === 'Escape') {
                if (soundSettingsModal.style.display === 'flex') {
                    hideModal(soundSettingsModal);
                } else if (howToPlayModal.style.display === 'flex') {
                    hideModal(howToPlayModal);
                } else if (creditsModal.style.display === 'flex') {
                    hideModal(creditsModal);
                } else if (leaderboardModal.style.display === 'flex') {
                    hideModal(leaderboardModal);
                } else if (gameStatsModal.style.display === 'flex') {
                    hideModal(gameStatsModal);
                } else if (trashCollectionModal.style.display === 'flex') {
                    hideModal(trashCollectionModal);
                } else if (pauseConfirmDialog.style.display === 'flex') {
                    hideSettingsMenu(); // Close pause dialog and show settings
                } else if (settingsMenuOpen) {
                    if (gamePaused) { // If settings open and game is paused, resume game
                        resumeGame();
                    } else { // If settings open and game is not paused, just hide settings
                        hideSettingsMenu();
                    }
                } else { // No menu/modal open, show settings
                    showSettingsMenu();
                }
                return;
            }

            // MODIFIED: Only allow game controls if no menu/modal is open
            if (gamePaused || settingsMenuOpen || soundSettingsModal.style.display === 'flex' ||
                howToPlayModal.style.display === 'flex' || creditsModal.style.display === 'flex' ||
                leaderboardModal.style.display === 'flex' || gameStatsModal.style.display === 'flex' ||
                trashCollectionModal.style.display === 'flex') {
                return;
            }

            if (e.code === 'ArrowRight' || e.key === 'd') {
                pressedKeys['ArrowRight'] = true;
            } else if (e.code === 'ArrowLeft' || e.key === 'a') {
                pressedKeys['ArrowLeft'] = true;
            }

            if (e.code === 'Space' || e.key === 'w' || e.key === 'ArrowUp') {
                jump();
            }
        });

        document.addEventListener('keyup', (e) => {
            // MODIFIED: Check if any menu/modal is open before processing game controls
            if (!gameActive || gamePaused || settingsMenuOpen || soundSettingsModal.style.display === 'flex' ||
                howToPlayModal.style.display === 'flex' || creditsModal.style.display === 'flex' ||
                leaderboardModal.style.display === 'flex' || gameStatsModal.style.display === 'flex' ||
                trashCollectionModal.style.display === 'flex') {
                return;
            }
            if (e.code === 'ArrowRight' || e.key === 'd') {
                pressedKeys['ArrowRight'] = false;
            } else if (e.code === 'ArrowLeft' || e.key === 'a') {
                pressedKeys['ArrowLeft'] = false;
            }
        });

        // Mobile Controls Event Listeners
        if (rightBtn) {
            rightBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                // MODIFIED: Check for game state
                if (!gamePaused && !settingsMenuOpen && soundSettingsModal.style.display !== 'flex' &&
                    howToPlayModal.style.display !== 'flex' && creditsModal.style.display !== 'flex' &&
                    leaderboardModal.style.display !== 'flex' && gameStatsModal.style.display !== 'flex' &&
                    trashCollectionModal.style.display !== 'flex') {
                    pressedKeys['ArrowRight'] = true;
                }
            });
            rightBtn.addEventListener('touchend', (e) => {
                e.preventDefault();
                pressedKeys['ArrowRight'] = false;
            });
        }

        if (leftBtn) {
            leftBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                // MODIFIED: Check for game state
                if (!gamePaused && !settingsMenuOpen && soundSettingsModal.style.display !== 'flex' &&
                    howToPlayModal.style.display !== 'flex' && creditsModal.style.display !== 'flex' &&
                    leaderboardModal.style.display !== 'flex' && gameStatsModal.style.display !== 'flex' &&
                    trashCollectionModal.style.display !== 'flex') {
                    pressedKeys['ArrowLeft'] = true;
                }
            });
            leftBtn.addEventListener('touchend', (e) => {
                e.preventDefault();
                pressedKeys['ArrowLeft'] = false;
            });
        }

        if (upBtn) {
            upBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                // MODIFIED: Check for game state
                if (!gamePaused && !settingsMenuOpen && soundSettingsModal.style.display !== 'flex' &&
                    howToPlayModal.style.display !== 'flex' && creditsModal.style.display !== 'flex' &&
                    leaderboardModal.style.display !== 'flex' && gameStatsModal.style.display !== 'flex' &&
                    trashCollectionModal.style.display !== 'flex') {
                    jump();
                }
            });
        }

        // Settings Button Event Listeners
        settingsButton.addEventListener('click', showSettingsMenu);
        closeSettingsButton.addEventListener('click', hideSettingsMenu);
        pauseGameButton.addEventListener('click', askToPause);
        quitGameButton.addEventListener('click', () => {
            if (confirm("Apakah Anda yakin ingin keluar dari game?")) {
                endGame(false, 'quit_game');
            }
        });

        // Pause Confirmation Dialog event listeners
        pauseConfirmYes.addEventListener('click', confirmPause);
        pauseConfirmNo.addEventListener('click', hideSettingsMenu);

        // NEW: Event Listeners for new menu items
        if (soundSettingsButton) soundSettingsButton.addEventListener('click', showSoundSettings);
        if (howToPlayButton) howToPlayButton.addEventListener('click', showHowToPlay);
        if (creditsButton) creditsButton.addEventListener('click', showCredits);
        if (leaderboardButton) leaderboardButton.addEventListener('click', showLeaderboard);
        if (gameStatsButton) gameStatsButton.addEventListener('click', showGameStats);
        if (trashCollectionButton) trashCollectionButton.addEventListener('click', showTrashCollection);
        if (backToLevelSelectButton) backToLevelSelectButton.addEventListener('click', backToLevelSelection);

        // NEW: Event Listeners for closing modals
        if (closeSoundSettingsModalBtn) closeSoundSettingsModalBtn.addEventListener('click', () => hideModal(soundSettingsModal));
        if (closeHowToPlayModalBtn) closeHowToPlayModalBtn.addEventListener('click', () => hideModal(howToPlayModal));
        if (closeCreditsModalBtn) closeCreditsModalBtn.addEventListener('click', () => hideModal(creditsModal));
        if (closeLeaderboardModalBtn) closeLeaderboardModalBtn.addEventListener('click', () => hideModal(leaderboardModal));
        if (closeGameStatsModalBtn) closeGameStatsModalBtn.addEventListener('click', () => hideModal(gameStatsModal));
        if (closeTrashCollectionModalBtn) closeTrashCollectionModalBtn.addEventListener('click', () => hideModal(trashCollectionModal));

        // NEW: Event Listeners for audio controls (if you add audio)
        // if (musicVolumeControl) {
        //     musicVolumeControl.addEventListener('input', (e) => {
        //         if (backgroundMusic) backgroundMusic.volume = parseFloat(e.target.value);
        //     });
        // }
        // if (sfxVolumeControl) {
        //     sfxVolumeControl.addEventListener('input', (e) => {
        //         if (collectSound) collectSound.volume = parseFloat(e.target.value);
        //         if (jumpSound) jumpSound.volume = parseFloat(e.target.value);
        //         if (damageSound) damageSound.volume = parseFloat(e.target.value);
        //     });
        // }


        // --- Utility Functions ---

        let messageTimeout;
        function showMessage(text) {
            messageDisplay.textContent = text;
            messageDisplay.classList.add('show');
            clearTimeout(messageTimeout);
            messageTimeout = setTimeout(() => {
                messageDisplay.classList.remove('show');
            }, 3000);
        }

        function createCollectParticle(x, y) {
            const particle = document.createElement('div');
            particle.classList.add('collect-particle');
            particle.style.left = x + 'px';
            particle.style.bottom = y + 'px';
            gameViewport.appendChild(particle);

            particle.addEventListener('animationend', () => {
                particle.remove();
            });
        }

        function createTrash(xPos = null) {
            const trashType = TRASH_TYPES[(Math.random() * TRASH_TYPES.length) | 0];
            const trash = document.createElement('div');
            trash.classList.add('trash');
            trash.classList.add(trashType.name);
            trash.style.backgroundImage = `url('${trashType.img}')`;

            const randomSizeFactor = 0.8 + (Math.random() * 0.2);
            trash.style.width = `${80 * randomSizeFactor}px`;
            trash.style.height = `${80 * randomSizeFactor}px`;
            trash.style.transform = `rotate(${Math.random() * 30 - 15}deg)`;

            let spawnX;
            const viewportWidth = gameViewport.offsetWidth;

            if (xPos !== null) {
                spawnX = xPos;
            } else {
                if (viewportWidth === 0) {
                    console.warn("gameViewport.offsetWidth is 0, cannot calculate spawnX for trash.");
                    return;
                }
                // Spawn trash slightly off-screen to the right, or within the world if it's smaller than viewport
                spawnX = cameraX + viewportWidth + TRASH_SPAWN_PADDING + (Math.random() * (GAME_WORLD_WIDTH - (cameraX + viewportWidth + TRASH_SPAWN_PADDING) - trash.offsetWidth - 30));
                spawnX = Math.min(spawnX, GAME_WORLD_WIDTH - trash.offsetWidth - 30);
                spawnX = Math.max(spawnX, cameraX); // Ensure it's not before the camera
            }

            let trashBottomY = Math.floor(Math.random() * (400 - 250 + 1)) + 250;

            const platformsAndObstacles = [
                // MODIFIED: Removed cloud and smoke from elements check as they are no longer elements to collide with
                ...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('smoke')),
                ...activeMovingObstacles // Keep this even if empty for future use
            ];
            let isOverlapping = false;
            let attempts = 0;

            // NEW: More robust overlap check for trash placement
            while (attempts < MAX_TRASH_SPAWN_ATTEMPTS) {
                isOverlapping = false;

                const tempTrashLeft = spawnX;
                const tempTrashRight = spawnX + trash.offsetWidth;
                let tempTrashBottom = trashBottomY;
                const tempTrashTop = tempTrashBottom + trash.offsetHeight;

                // Check overlap with ground (BASE_GROUND_HEIGHT)
                if (tempTrashBottom < BASE_GROUND_HEIGHT + TRASH_SPAWN_PADDING) { // If trash is too low
                    isOverlapping = true;
                }

                if (!isOverlapping) { // Only check platforms if not overlapping ground
                    for (const elem of platformsAndObstacles) {
                        if (!(elem instanceof Element)) continue;

                        const elemWorldX = parseInt(elem.style.left);
                        let elemWorldY = parseInt(elem.style.bottom || 0); // Default to 0 if bottom not set

                        const elemWidth = elem.offsetWidth;
                        let elemHeight = elem.offsetHeight;

                        // For spikes/puddles, consider them as ground level obstacles
                        if (elem.classList.contains('obstacle-spikes') || elem.classList.contains('puddle')) {
                            elemWorldY = BASE_GROUND_HEIGHT;
                            elemHeight = parseInt(elem.style.height); // Use explicit height
                        }

                        // Check for collision with existing elements
                        if (tempTrashLeft < elemWorldX + elemWidth + TRASH_SPAWN_PADDING &&
                            tempTrashRight > elemWorldX - TRASH_SPAWN_PADDING &&
                            tempTrashBottom < elemWorldY + elemHeight + TRASH_SPAWN_PADDING &&
                            tempTrashTop > elemWorldY - TRASH_SPAWN_PADDING) {
                            isOverlapping = true;
                            break; // Stop checking, found overlap
                        }
                    }
                }


                if (isOverlapping) {
                    attempts++;
                    // Recalculate a new random spawnX and trashBottomY
                    spawnX = cameraX + viewportWidth + TRASH_SPAWN_PADDING + (Math.random() * (GAME_WORLD_WIDTH - (cameraX + viewportWidth + TRASH_SPAWN_PADDING) - trash.offsetWidth - 30));
                    spawnX = Math.min(spawnX, GAME_WORLD_WIDTH - trash.offsetWidth - 50);
                    spawnX = Math.max(spawnX, cameraX + viewportWidth / 2); // Ensure it spawns somewhat ahead of player
                    trashBottomY = Math.floor(Math.random() * (400 - BASE_GROUND_HEIGHT + 1)) + BASE_GROUND_HEIGHT + TRASH_SPAWN_PADDING; // NEW: Ensure trash is above ground
                } else {
                    break; // Found a non-overlapping spot
                }
            }

            if (isOverlapping && attempts >= MAX_TRASH_SPAWN_ATTEMPTS) {
                console.warn(`Gagal menemukan posisi sampah bebas setelah ${MAX_TRASH_SPAWN_ATTEMPTS} percobaan. Sampah tidak dibuat.`);
                return;
            }

            trash.style.left = spawnX + 'px';
            trash.style.bottom = trashBottomY + 'px';

            trash.dataset.type = trashType.name;
            trash.dataset.message = trashType.message;

            gameWorld.appendChild(trash);
            activeTrash.push(trash);
        }

        function checkCollisions() {
            const playerWorldX = playerXInWorld;
            const playerCurrentY = playerY;
            const playerPreviousY = playerY - velocityY;

            // Collision with trash
            for (let i = activeTrash.length - 1; i >= 0; i--) {
                const trash = activeTrash[i];
                if (trash.dataset.collected === 'true' || trash.style.opacity === '0') continue;

                const trashWorldX = parseInt(trash.style.left);
                const trashWorldY = parseInt(trash.style.bottom);

                if (playerWorldX < trashWorldX + trash.offsetWidth &&
                    playerXInWorld + PLAYER_WIDTH > trashWorldX &&
                    playerY < trashWorldY + trash.offsetHeight &&
                    playerY + PLAYER_HEIGHT > trashWorldY) {

                    currentCarriedTrash = trash;
                    showMessage(trash.dataset.message);
                    score += 1;
                    currentWorldTrashCollected += 1;
                    scoreDisplay.textContent = 'Skor: ' + score;
                    // if (collectSound) collectSound.play();

                    const trashXInViewport = trashWorldX - cameraX;
                    createCollectParticle(trashXInViewport + trash.offsetWidth / 2, trashWorldY + trash.offsetHeight / 2);

                    currentCarriedTrash.style.opacity = '0';
                    currentCarriedTrash.dataset.collected = 'true';
                    collectedTrashTypes.add(trash.dataset.type); // NEW: Add to collected types

                    setTimeout(() => {
                        const index = activeTrash.indexOf(trash);
                        if (index > -1) {
                            activeTrash.splice(index, 1);
                        }
                        trash.remove();
                    }, 300);

                    currentCarriedTrash = null;
                    return;
                }
            }

            // Collision with platforms and hazards
            // MODIFIED: Removed cloud and smoke from elements check as they are no longer elements to collide with
            const allCollidableElements = [...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('smoke')), ...activeMovingObstacles];
            allCollidableElements.forEach(platform => {
                const platformWorldX = parseInt(platform.style.left);
                let platformWorldY = parseInt(platform.style.bottom || 0); // Default to 0 if bottom not set

                const platformWidth = platform.offsetWidth;
                let platformHeight = platform.offsetHeight;

                // Adjust for specific element types
                if (platform.classList.contains('obstacle-spikes') || platform.classList.contains('puddle')) {
                    platformWorldY = BASE_GROUND_HEIGHT;
                }

                if (platform.dataset.hazard === 'true') {
                    const playerCenterX = playerXInWorld + PLAYER_WIDTH / 2;
                    const playerBottomY = playerY;

                    const obstacleCenterX = platformWorldX + platformWidth / 2;
                    const obstacleBottomY = platformWorldY;

                    if (playerCenterX > obstacleCenterX - platformWidth / 2 + PLAYER_COLLISION_TOLERANCE_X &&
                        playerCenterX < obstacleCenterX + platformWidth / 2 - PLAYER_COLLISION_TOLERANCE_X &&
                        playerBottomY < obstacleBottomY + platformHeight - PLAYER_COLLISION_TOLERANCE_Y &&
                        playerBottomY + PLAYER_HEIGHT > obstacleBottomY + PLAYER_COLLISION_TOLERANCE_Y) {
                            takeDamage();
                            return;
                        }
                } else if (platform.dataset.finish === 'true') {
                    const finishPoleWorldX = parseInt(platform.style.left);
                    const finishPoleWidth = platform.offsetWidth;
                    const finishPoleHeight = platform.offsetHeight;
                    const finishPoleBottom = parseInt(platform.style.bottom);

                    if (playerXInWorld + PLAYER_WIDTH > finishPoleWorldX &&
                        playerXInWorld < finishPoleWorldX + finishPoleWidth &&
                        playerY < finishPoleBottom + finishPoleHeight &&
                        playerY + PLAYER_HEIGHT > finishPoleBottom) {

                        endGame(true, 'finish_pole');
                        return;
                    }
                }

                let platformTopSurfaceY = platformWorldY + platformHeight;
                // For pipes and horizontal-pipes, the top surface is the full height of the element
                if (platform.classList.contains('pipe') || platform.classList.contains('horizontal-pipe')) {
                    platformTopSurfaceY = platformWorldY + platform.offsetHeight;
                }


                // Horizontal collision with solid platforms
                if (platform.dataset.platform === 'true') {
                    const playerRect = {
                        left: playerXInWorld,
                        right: playerXInWorld + PLAYER_WIDTH,
                        bottom: playerCurrentY,
                        top: playerCurrentY + PLAYER_HEIGHT
                    };
                    const platformRectWorld = {
                        left: platformWorldX,
                        right: platformWorldX + platformWidth,
                        bottom: platformWorldY,
                        top: platformWorldY + platformHeight
                    };

                    if (playerRect.bottom < platformRectWorld.top && playerRect.top > platformRectWorld.bottom) {
                        // If moving right and hitting left side of platform
                        if (playerRect.right > platformRectWorld.left && playerRect.left < platformRectWorld.left) {
                            playerXInWorld = platformRectWorld.left - PLAYER_WIDTH;
                            pressedKeys['ArrowRight'] = false;
                            pressedKeys['d'] = false;
                        }
                        // If moving left and hitting right side of platform
                        else if (playerRect.left < platformRectWorld.right && playerRect.right > platformRectWorld.right) {
                            playerXInWorld = platformRectWorld.right;
                            pressedKeys['ArrowLeft'] = false;
                            pressedKeys['a'] = false;
                        }
                    }
                }

                // Landing and head-bump logic
                if (platform.dataset.platform === 'true') {
                    // If falling and landing on platform
                    if (velocityY < 0) {
                        if (playerXInWorld + PLAYER_WIDTH > platformWorldX &&
                            playerXInWorld < platformWorldX + platformWidth &&
                            playerPreviousY >= platformTopSurfaceY &&
                            playerCurrentY <= platformTopSurfaceY) {

                            playerY = platformTopSurfaceY;
                            velocityY = 0;
                            isJumping = false;
                            playerAnimationState = 'stand';
                            updatePlayerAnimation();
                            playLandingAnimation();
                        }
                    }
                    // If jumping up and hitting bottom of platform
                    else if (velocityY > 0) {
                        const playerTopY = playerCurrentY + PLAYER_HEIGHT;
                        const playerPreviousTopY = playerPreviousY + PLAYER_HEIGHT;

                        if (playerXInWorld + PLAYER_WIDTH - PLAYER_COLLISION_TOLERANCE_X > platformWorldX &&
                            playerXInWorld + PLAYER_COLLISION_TOLERANCE_X < platformWorldX + platformWidth &&
                            playerPreviousTopY <= platformWorldY &&
                            playerTopY >= platformWorldY) {

                            playerY = platformWorldY - PLAYER_HEIGHT;
                            velocityY = -1; // Reverse velocity to simulate falling
                            playerAnimationState = 'jump'; // Keep jump animation for falling
                            updatePlayerAnimation();
                        }
                    }
                }
            });
        }

        function startGameTimer() {
            if (gameTimerInterval === null && gameActive && !gamePaused) {
                gameTimerInterval = setInterval(() => {
                    gameTime--;
                    timerDisplay.textContent = 'Waktu: ' + gameTime;
                    if (gameTime <= 0) {
                        clearInterval(gameTimerInterval);
                        gameTimerInterval = null;
                        endGame(false, 'time');
                    }
                }, 1000);
            }
        }

        function checkGameEnd() {
            if (gameTime <= 0) {
                endGame(false, 'time');
            }
        }

        async function endGame(won, reason) {
            gameActive = false;
            gamePaused = false;
            settingsMenuOpen = false;
            clearInterval(gameTimerInterval);
            gameTimerInterval = null;
            if (obstacleSpawnInterval) {
                clearInterval(obstacleSpawnInterval);
                obstacleSpawnInterval = null;
            }
            clearInterval(walkAnimationInterval);
            walkAnimationInterval = null;
            cancelAnimationFrame(animationFrameId);

            player.style.display = 'none';
            activeTrash.forEach(t => t.remove()); activeTrash = [];
            activeMovingObstacles.forEach(o => o.remove()); activeMovingObstacles = [];
            activeLevelElements.forEach(el => {
                if (el && typeof el.remove === 'function' && el.parentNode === gameWorld) {
                    el.remove();
                }
            });
            activeLevelElements = [];
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            document.getElementById('mobile-controls').style.display = 'none';
            settingsButton.style.display = 'none';
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none';
            // NEW: Hide all modals on game end
            soundSettingsModal.style.display = 'none';
            howToPlayModal.style.display = 'none';
            creditsModal.style.display = 'none';
            leaderboardModal.style.display = 'none';
            gameStatsModal.style.display = 'none';
            trashCollectionModal.style.display = 'none';

            // if (backgroundMusic) backgroundMusic.pause();

            let statusMessage = '';
            let gameStatus = 'lost';

            if (won) {
                if (reason === 'collected_all_trash') {
                     statusMessage = `Hebat! Kamu berhasil mengumpulkan semua sampah! Skor Akhir: ${score}! Bumi jadi bersih!`;
                } else if (reason === 'finish_pole') {
                    statusMessage = `Selamat! Kamu berhasil mencapai garis finish dan membersihkan bumi! Skor Akhir: ${score}!`;
                }
                gameStatus = 'won';
                gameOverScreen.style.backgroundColor = 'rgba(0, 128, 0, 0.8)';
            } else {
                if (reason === 'time') {
                    statusMessage = `Waktu habis! Kamu mengumpulkan ${score} sampah. Coba lagi!`;
                } else if (reason === 'damage_hazard') {
                    statusMessage = `Game Over! Kamu menyentuh bahaya! Kamu mengumpulkan ${score} sampah. Coba lagi!`;
                } else if (reason === 'quit_game') {
                    statusMessage = `Game Dihentikan. Kamu mengumpulkan ${score} sampah. Coba lagi!`;
                } else {
                    statusMessage = `Game Over! Kamu mengumpulkan ${score} sampah. Coba lagi!`;
                }
                gameStatus = 'lost';
                gameOverScreen.style.backgroundColor = 'rgba(128, 0, 0, 0.8)';
            }

            gameOverMessage.textContent = statusMessage;
            gameOverScreen.style.display = 'flex';

            try {
                const urlParams = new URLSearchParams(window.location.search);
                const currentLevel = urlParams.get('level') || 3;
                const userId = <?php echo $userId; ?>;

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    // user_id diambil dari sesi di sisi server, tidak perlu dikirim dari client
                    body: `action=save_score&score=${score}&status=${gameStatus}&level=${currentLevel}`,
                });
                const data = await response.json();
                console.log('Server response:', data);
                if (data.status === 'success') {
                    console.log('Skor berhasil disimpan ke database.');
                } else {
                    console.error('Gagal menyimpan skor ke database:', data.message);
                }
            } catch (error) {
                console.error('Error saat mengirim skor ke server:', error);
            } finally {
                setTimeout(() => {
                    window.location.href = 'game_petualangan.php';
                }, 3000);
            }
        }

        function resetGame() {
            // Reset pressed keys state
            for (const key in pressedKeys) {
                if (Object.prototype.hasOwnProperty.call(pressedKeys, key)) {
                    pressedKeys[key] = false;
                }
            }

            cancelAnimationFrame(animationFrameId);
            clearInterval(gameTimerInterval);
            gameTimerInterval = null;
            if (obstacleSpawnInterval) {
                clearInterval(obstacleSpawnInterval);
                obstacleSpawnInterval = null;
            }
            clearInterval(walkAnimationInterval);
            walkAnimationInterval = null;

            player.style.display = 'none';
            activeTrash.forEach(t => t.remove()); activeTrash = [];
            activeMovingObstacles.forEach(o => o.remove()); activeMovingObstacles = [];
            activeLevelElements.forEach(el => el.remove());
            activeLevelElements = [];
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            // Reset game state variables
            playerXInWorld = 100;
            playerY = BASE_GROUND_HEIGHT;
            isJumping = false;
            score = 0;
            gameTime = GAME_DURATION_LEVEL_3;
            currentWorldTrashCollected = 0;
            velocityY = 0;
            currentCarriedTrash = null;
            cameraX = 0;
            gameActive = true;
            gamePaused = false;
            settingsMenuOpen = false;
            player.style.opacity = '1';
            playerAnimationState = 'stand';
            playerDirection = 'right';
            player.style.transform = 'scaleY(1)'; // Reset transform for player
            collectedTrashTypes.clear(); // NEW: Clear collected trash types

            // Reset pause button visual state
            pauseGameButton.textContent = "Jeda";
            pauseGameButton.classList.remove('resume-state');
            pauseGameButton.style.backgroundColor = '#FFC107'; // Ensure default color
            pauseGameButton.style.borderColor = '#D39E00'; // Ensure default border color

            initialGroundAndLevelSetupDone = false;
            document.getElementById('game-viewport').style.display = 'block';

            currentWorldIndex = 1; // Level 3 always uses world index 1

            initializeLevelElements();
            createGround();
            updatePlayerAnimation(); // Initial player animation state

            scoreDisplay.textContent = 'Skor: ' + score;
            timerDisplay.textContent = 'Waktu: ' + gameTime;
            showMessage(`Selamat datang di Go Green Hero! Kumpulkan sampah dan raih finish!`);
            player.style.display = 'block';

            gameOverScreen.style.display = 'none';
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none';
            // NEW: Ensure all modals are hidden on reset
            soundSettingsModal.style.display = 'none';
            howToPlayModal.style.display = 'none';
            creditsModal.style.display = 'none';
            leaderboardModal.style.display = 'none';
            gameStatsModal.style.display = 'none';
            trashCollectionModal.style.display = 'none';

            document.getElementById('mobile-controls').style.display = 'flex';
            settingsButton.style.display = 'block';

            startGameTimer();
            // if (backgroundMusic) backgroundMusic.play();

            const numberOfTrashItems = 60; // MODIFIED: Menjadi 60 sampah
            // Gunakan konstanta GAME_WORLD_WIDTH untuk penempatan sampah
            const finishPoleX = GAME_WORLD_WIDTH - 200; // Finish pole 200px sebelum akhir dunia
            const spawnAreaEnd = finishPoleX - 200; // Akhir area spawn 200px sebelum finish pole
            const spawnAreaStart = 150; // Mulai area spawn 150px dari awal level
            const availableSpawnRange = spawnAreaEnd - spawnAreaStart;
            const approximateSpacing = availableSpawnRange / numberOfTrashItems;

            for (let i = 0; i < numberOfTrashItems; i++) {
                const xPos = spawnAreaStart + (i * approximateSpacing) + (Math.random() * (approximateSpacing * 0.5) - (approximateSpacing * 0.25));
                createTrash(xPos);
            }

            animationFrameId = requestAnimationFrame(gameLoop);
        }

        restartButton.addEventListener('click', () => {
            window.location.href = 'game_petualangan.php';
        });

        // NEW: Utility function for HTML escaping (important for displaying user-generated or fetched content)
        function htmlspecialchars(str) {
            let div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // Asset Preloading
        let assetsLoadedCount = 0;
        // MODIFIED: Include audio assets in total count if you add them
        const totalAssets = IMAGE_ASSETS.length + Object.keys(AUDIO_ASSETS).length;

        function assetLoaded() {
            assetsLoadedCount++;
            if (assetsLoadedCount >= totalAssets) {
                console.log("All assets loaded. Starting game.");
                loadingScreen.style.display = 'none';
                resetGame(); // Start the game after assets are loaded
            }
        }

        function preloadAssets() {
            if (totalAssets === 0) {
                 console.log("No assets to load. Starting game directly.");
                 loadingScreen.style.display = 'none';
                 resetGame();
                 return;
            }

            // Preload Images
            IMAGE_ASSETS.forEach(src => {
                const img = new Image();
                img.onload = assetLoaded;
                img.onerror = (e) => {
                    console.error(`Gagal memuat gambar: ${src}`, e);
                    assetLoaded(); // Still call assetLoaded even on error to prevent blocking
                };
                img.src = src;
            });

            // NEW: Preload Audio (if any)
            Object.values(AUDIO_ASSETS).forEach(src => {
                const audio = new Audio();
                audio.addEventListener('canplaythrough', assetLoaded, { once: true });
                audio.addEventListener('error', (e) => {
                    console.error(`Gagal memuat audio: ${src}`, e);
                    assetLoaded(); // Still call assetLoaded even on error to prevent blocking
                });
                audio.src = src;
                audio.load(); // Start loading
            });
        }

        preloadAssets();

        /* --- JavaScript End --- */
    </script>
</body>
</html>