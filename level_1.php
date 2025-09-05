<?php
// level_1.php

// Sertakan koneksi database
require_once 'db_connection.php'; //

// Sertakan fungsi helper (ini akan menyediakan fungsi saveGameResult, is_logged_in, redirect, sendJsonResponse)
require_once 'helpers.php'; //

// Tangani pengiriman data skor dari JavaScript (menggunakan AJAX/Fetch API)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'save_score') { //
    // Ambil user_id dari sesi, bukan dari POST, untuk keamanan
    // Jika user_id belum ada di sesi, berarti pengguna belum login dengan benar.
    if (!is_logged_in()) { //
        // Log kesalahan atau kirim respons JSON error jika user_id tidak ditemukan
        sendJsonResponse(['status' => 'error', 'message' => 'User ID tidak ditemukan. Harap login kembali.'], 401); //
        exit; // Penting untuk keluar setelah mengirim respons JSON //
    }
    $userId = $_SESSION['user_id']; // Gunakan ID pengguna dari sesi //

    $scoreToSave = isset($_POST['score']) ? intval($_POST['score']) : 0; //
    $gameStatus = isset($_POST['status']) ? $_POST['status'] : 'lost'; // 'won' or 'lost' //
    $levelPlayed = isset($_POST['level']) ? intval($_POST['level']) : 1; // Untuk level_1.php, ini harus 1. //

    // Panggil fungsi helper untuk menyimpan hasil game
    $response = saveGameResult($conn, $userId, $scoreToSave, $gameStatus, $levelPlayed); //

    // Setelah menyimpan skor, kirim respons JSON
    sendJsonResponse($response); // Menggunakan fungsi sendJsonResponse dari helpers.php //
}

// PHP untuk menangani permintaan GET (saat halaman dimuat pertama kali)
if ($_SERVER["REQUEST_METHOD"] == "GET") { //
    // Pastikan user sudah login
    if (!is_logged_in()) { //
        redirect('login.php'); // Ganti 'login.php' dengan halaman login Anda //
    }

    // Ambil user_id dari sesi untuk digunakan di sisi klien (JavaScript)
    $userId = $_SESSION['user_id']; //
    $level = isset($_GET['level']) ? intval($_GET['level']) : 1; // Ambil level dari URL //

    // Tutup koneksi database setelah query GET jika ini bukan permintaan POST
    $conn->close(); //
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Go Green Hero: Petualangan Edukasi Sampah! (Level 1)</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <style>
        /* --- CSS Anda dari Level_1.html tetap di sini --- */
        /* Pastikan semua CSS dari file HTML asli Anda disalin ke sini */
        :root {
            --primary-bg-color: #6A1B9A;
            --primary-gradient: linear-gradient(135deg, #8E24AA, #4A148C);
            --secondary-color: #A2E0A2;
            --accent-green: #4CAF50;
            --accent-green-dark: #2E8B57;
            --text-color-light: #ffffff;
            --text-color-dark: #333333;
            --card-bg: rgba(255, 255, 255, 0.15);
            --card-border: rgba(255, 255, 255, 0.3);
            --shadow-light: 0 4px 15px rgba(0, 0, 0, 0.2);
            --shadow-soft: 0 2px 10px rgba(0, 0, 0, 0.1);
            --profile-bg-light: #f0fff0;
            --profile-bg-dark: #e0ffe0;
            --profile-green: #388E3C;
            --profile-green-dark: #1B5E20;
        }

        body {
            margin: 0;
            overflow: hidden;
            touch-action: none;
            background-color: #5A7E9C;
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
            font-size: 14px;
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

        #game-world {
            position: absolute;
            top: 0;
            left: 0;
            width: 8000px;
            height: 100%;
            transition: transform 0.05s linear;
        }

        .ground-brick-segment {
            position: absolute;
            bottom: 0px;
            width: 90px;
            height: 90px;
            background-color: #A0522D;
            border: 1px solid #8B4513;
            box-sizing: border-box;
            z-index: 1;
        }
        .ground-grass-top-segment {
            position: absolute;
            bottom: 89px;
            width: 90px;
            height: 20px;
            background-color: #7CFC00;
            border-top: 1px solid #556B2F;
            box-sizing: border-box;
            z-index: 2;
        }

        .house {
            position: absolute;
            bottom: 90px;
            width: 150px;
            height: 120px;
            background-color: #B06500;
            border: 4px solid #8B4513;
            box-sizing: border-box;
            z-index: 5;
            position: relative;
            overflow: visible;
        }

        .house::before {
            content: '';
            position: absolute;
            top: -60px;
            left: -20px;
            width: 190px;
            height: 70px;
            background-color: #8B0000;
            border: 4px solid #550000;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            transform: skewX(-15deg);
            transform-origin: bottom left;
            z-index: 6;
        }

        .house::after {
            content: '';
            position: absolute;
            top: -60px;
            left: 170px;
            width: 20px;
            height: 70px;
            background-color: #660000;
            transform: skewY(30deg);
            transform-origin: bottom right;
            z-index: 6;
        }

        .house-door {
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 70px;
            background-color: #552D00;
            border: 2px solid #331A00;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            z-index: 7;
        }

        .house-window {
            position: absolute;
            top: 20px;
            width: 30px;
            height: 30px;
            background-color: #87CEEB;
            border: 2px solid #333;
            box-sizing: border-box;
            z-index: 7;
        }
        .house-window.left { left: 20px; }
        .house-window.right { right: 20px; }


        #player {
            width: 96px;
            height: 144px;
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

        .cloud {
            position: absolute;
            background-color: #AECADF;
            border-radius: 50%;
            box-shadow: 0 0 0 9px rgba(174,202,223,0.5);
            z-index: 0;
        }
        .cloud.type-1 {
            width: 140px; height: 80px;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .cloud.type-1::before { content: ''; position: absolute; width: 80px; height: 60px; background-color: inherit; border-radius: 50%; top: -20px; left: 60px; }
        .cloud.type-1::after { content: ''; position: absolute; width: 110px; height: 70px; background-color: inherit; border-radius: 50%; top: 10px; left: -35px; }

        .cloud.type-2 {
            width: 160px; height: 100px;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .cloud.type-2::before { content: ''; position: absolute; width: 100px; height: 80px; background-color: inherit; border-radius: 50%; top: -25px; left: 80px; }
        .cloud.type-2::after { content: ''; position: absolute; width: 130px; height: 90px; background-color: inherit; border-radius: 50%; top: 20px; left: -40px; }

        .smoke {
            position: absolute;
            border-radius: 50%;
            filter: blur(5px);
            z-index: 1;
            animation: smoke-drift 15s ease-in-out infinite alternate;
        }
        .smoke.type-1 {
            background-color: rgba(180, 200, 210, 0.5);
            width: 200px; height: 120px;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .smoke.type-1::before { content: ''; position: absolute; width: 120px; height: 90px; background-color: inherit; border-radius: 50%; top: -30px; left: 90px; }
        .smoke.type-1::after { content: ''; position: absolute; width: 160px; height: 100px; background-color: inherit; border-radius: 50%; top: 20px; left: -50px; }

        .smoke.type-2 {
            background-color: rgba(170, 190, 200, 0.5);
            width: 250px; height: 150px;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .smoke.type-2::before { content: ''; position: absolute; width: 150px; height: 120px; background-color: inherit; border-radius: 50%; top: -40px; left: 110px; }
        .smoke.type-2::after { content: ''; position: absolute; width: 200px; height: 130px; background-color: inherit; border-radius: 50%; top: 30px; left: -60px; }
        
        @keyframes smoke-drift {
            0% { transform: translateX(0) scale(1); opacity: 0.5; }
            50% { transform: translateX(30px) scale(1.02); opacity: 0.55; }
            100% { transform: translateX(0) scale(1); opacity: 0.5; }
        }

        .trash {
            width: 64px;
            height: 64px;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            position: absolute;
            z-index: 10;
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }
        .collect-particle {
            position: absolute;
            width: 20px;
            height: 20px;
            background-color: #7CFC00;
            border-radius: 50%;
            opacity: 1;
            transform: scale(0);
            z-index: 11;
            animation: collect-anim 0.5s ease-out forwards;
            pointer-events: none;
            box-shadow: 0 0 8px 4px rgba(124, 252, 0, 0.5);
        }
        @keyframes collect-anim {
            0% { transform: scale(0) translateY(0); opacity: 1; }
            50% { transform: scale(1.5) translateY(-30px); opacity: 0.8; }
            100% { transform: scale(0) translateY(-60px); opacity: 0; }
        }


        .bird {
            position: absolute;
            width: 40px;
            height: 20px;
            background-color: #333;
            border-radius: 50%;
            z-index: 4;
            animation: fly-wings 1.5s linear infinite alternate;
        }
        .bird::before, .bird::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 10px;
            background-color: #333;
            border-radius: 50% 50% 0 0;
            top: 5px;
        }
        .bird::before { left: -10px; transform: rotate(-30deg); transform-origin: 100% 50%; }
        .bird::after { right: -10px; transform: rotate(30deg); transform-origin: 0% 50%; }

        @keyframes fly-wings {
            0% { transform: translateY(0px) scaleY(1); }
            50% { transform: translateY(-5px) scaleY(0.8); }
            100% { transform: translateY(0px) scaleY(1); }
        }
        .bird.move-across-world {
            animation: bird-move-across-world var(--bird-speed, 20s) linear infinite;
        }
        @keyframes bird-move-across-world {
            from { transform: translateX(0); }
            to { transform: translateX(8000px); }
        }

        .obstacle-spikes {
            position: absolute;
            bottom: 0px;
            width: 90px;
            height: 50px;
            background-image: url('assets/images/spikes.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            z-index: 3;
        }

        .puddle {
            position: absolute;
            bottom: 0px;
            width: 150px;
            height: 20px;
            background-color: #4682B4;
            border-radius: 50% / 100% 100% 0 0;
            box-shadow: inset 0 -5px 10px rgba(0,0,0,0.3);
            z-index: 3;
        }

        .floating-platform {
            position: absolute;
            background-color: #8B4513;
            border: 2px solid #556B2F;
            border-radius: 5px;
            z-index: 4;
            width: 180px;
            height: 30px;
            box-sizing: border-box;
            animation: float-platform 3s ease-in-out infinite alternate;
        }

        @keyframes float-platform {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .tree {
            position: absolute;
            bottom: 90px;
            width: 80px;
            height: 120px;
            background-color: #8B4513;
            border-radius: 10px;
            z-index: 5;
            box-sizing: border-box;
        }
        .tree::before {
            content: '';
            position: absolute;
            top: -60px;
            left: -20px;
            width: 120px;
            height: 100px;
            background-color: #228B22;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            box-shadow: 
                inset 0 0 10px rgba(0,0,0,0.3),
                -10px 5px 0 #32CD32,
                20px 10px 0 #006400;
            z-index: 7;
        }
        .tree::after {
            content: '';
            position: absolute;
            top: -90px;
            left: 10px;
            width: 60px;
            height: 60px;
            background-color: #228B22;
            border-radius: 50%;
            box-shadow: inset 0 0 8px rgba(0,0,0,0.3);
            z-index: 8;
        }


        .bird-on-ground {
            position: absolute;
            bottom: 90px;
            width: 60px;
            height: 40px;
            background-color: #6B8E23;
            border-radius: 50%;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            z-index: 6;
            animation: move-across-ground var(--move-speed, 12s) linear forwards;
        }
        .bird-on-ground::before, .bird::after {
            content: '';
            position: absolute;
            width: 15px;
            height: 10px;
            background-color: #4B6F00;
            border-radius: 50%;
            top: 5px;
        }
        .bird::before { left: -5px; transform: rotate(-20deg); }
        .bird::after { right: -5px; transform: rotate(20deg); }
        
        .obstacle-car {
            position: absolute;
            bottom: 90px;
            width: 150px;
            height: 80px;
            background-color: #DC143C;
            border: 3px solid #8B0000;
            border-radius: 15px;
            box-sizing: border-box;
            z-index: 6;
            animation: move-across-ground var(--move-speed, 15s) linear forwards;
            position: relative;
        }
        .obstacle-car::before, .obstacle-car::after {
            content: '';
            position: absolute;
            bottom: -15px;
            width: 30px;
            height: 30px;
            background-color: #333;
            border: 2px solid #000;
            border-radius: 50%;
        }
        .obstacle-car::before { left: 15px; }
        .obstacle-car::after { right: 15px; }

        .finish-pole {
            position: absolute;
            bottom: 90px;
            width: 60px;
            height: 300px;
            background-color: #A9A9A9;
            border: 5px solid #696969;
            border-radius: 8px;
            z-index: 5;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding-top: 20px;
            box-sizing: border-box;
            box-shadow: 5px 5px 10px rgba(0,0,0,0.5);
        }
        .finish-pole::before {
            content: 'FINISH!';
            position: absolute;
            top: 10px;
            left: 60px;
            width: 150px;
            height: 70px;
            background-color: #FFD700;
            border: 5px solid #DAA520;
            font-family: 'Press Start 2P', cursive;
            font-size: 24px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 5px 5px 0 rgba(0,0,0,0.7);
            transform-origin: 0 0;
            animation: flag-wave 1s ease-in-out infinite alternate;
        }

        @keyframes flag-wave {
            0% { transform: rotate(0deg); }
            50% { transform: rotate(8deg); }
            100% { transform: rotate(0deg); }
        }


        @keyframes move-across-ground {
            from { transform: translateX(0); }
            to { transform: translateX(-8000px); }
        }

        #top-left-info-group {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        #score, #timer {
            background-color: rgba(255, 255, 255, 0.7);
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
            font-size: 16px;
            box-sizing: border-box;
            white-space: nowrap;
            border: 2px solid #333;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
            text-shadow: 1px 1px #fff;
        }
        #score { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }
        #timer { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }


        #message {
            position: absolute;
            top: 10px;
            right: 80px;
            background-color: rgba(0, 0, 0, 0.3);
            color: #FFD700;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 14px;
            z-index: 100;
            max-width: calc(100% - 300px);
            text-align: center;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            border: 2px solid #fff;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
        }
        #message.show {
            opacity: 1;
        }


        #game-over-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            font-size: 28px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 200;
            display: none;
            padding: 10px;
            box-sizing: border-box;
        }
        #game-over-screen p {
            font-size: 24px;
            margin-bottom: 20px;
            text-shadow: 2px 2px #000;
            max-width: 90%;
        }
        #game-over-screen button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 25px;
            font-size: 20px;
            border: 4px solid #228B22;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #game-over-screen button:hover {
            background-color: #5cb85c;
            transform: translateY(-2px);
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
        }
        #game-over-screen button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 2px 2px 0 rgba(0,0,0,0.7);
        }

        #settings-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.7);
            width: 50px;
            height: 50px;
            border-radius: 8px;
            font-family: 'Press Start 2P', cursive;
            font-size: 32px;
            cursor: pointer;
            z-index: 101;
            border: 2px solid #333;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #333;
            text-shadow: 1px 1px #fff;
            line-height: 1;
            transition: background-color 0.1s ease;
        }
        #settings-button:hover {
            background-color: rgba(255, 255, 255, 0.9);
        }
        #settings-button:active {
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
            transform: translateY(1px);
        }
        @media only screen and (max-width: 768px) {
            #settings-button {
                top: 5px;
                right: 5px;
                width: 40px;
                height: 40px;
                font-size: 28px;
                padding: 0;
            }
            #message {
                top: 5px;
                right: 55px;
                max-width: calc(100% - 55px - 5px - 5px - 50px);
                font-size: 12px;
                padding: 5px 8px;
            }
            #top-left-info-group {
                top: 5px;
                left: 5px;
                gap: 3px;
            }
            #score, #timer {
                padding: 4px 8px;
                font-size: 12px;
            }
        }
        @media only screen and (max-width: 768px) and (orientation: landscape) {
            #top-left-info-group {
                top: 3px;
                left: 3px;
                gap: 3px;
            }
            #score, #timer {
                padding: 3px 6px;
                font-size: 10px;
            }
            #message {
                top: 3px;
                right: 50px;
                max-width: calc(100% - 50px - 3px - 3px - 50px);
                font-size: 10px;
                padding: 3px 6px;
            }
            #settings-button {
                top: 3px;
                right: 3px;
                width: 35px;
                height: 35px;
                font-size: 24px;
            }
            #game-over-screen p {
                font-size: 18px;
            }
            #game-over-screen button {
                padding: 8px 15px;
                font-size: 16px;
            }
            #settings-menu h2 {
                font-size: 28px;
            }
            #settings-menu button {
                padding: 8px 20px;
                font-size: 18px;
                margin: 8px 0;
            }
            #pause-confirm-dialog {
                padding: 10px;
                font-size: 16px;
            }
            #pause-confirm-dialog p {
                font-size: 18px;
                margin-bottom: 10px;
            }
            #pause-confirm-dialog button {
                padding: 6px 12px;
                font-size: 14px;
            }
        }


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
            font-size: 36px;
            margin-bottom: 30px;
            text-shadow: 4px 4px #000;
        }
        #settings-menu button {
            background-color: #007BFF;
            color: white;
            padding: 12px 30px;
            font-size: 24px;
            border: 4px solid #0056b3;
            border-radius: 8px;
            cursor: pointer;
            margin: 10px 0;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #settings-menu button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
        }
        #settings-menu button:active {
            background-color: #004085;
            transform: translateY(0);
            box-shadow: 2px 2px 0 rgba(0,0,0,0.7);
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

        #close-settings-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 28px;
            font-family: Arial, sans-serif;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 260;
            border: 2px solid white;
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5);
            line-height: 1;
            transition: background-color 0.1s ease;
        }
        #close-settings-button:hover {
            background-color: rgba(255, 255, 255, 0.4);
        }
        #close-settings-button:active {
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
            transform: translateY(1px);
        }
        @media (max-width: 768px) {
            #close-settings-button {
                width: 30px;
                height: 30px;
                font-size: 20px;
                top: 5px;
                right: 5px;
            }
        }


        #pause-confirm-dialog {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.95);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            z-index: 300;
            display: none;
            border: 5px solid #FFD700;
            box-shadow: 0 0 15px rgba(255,215,0,0.8);
            font-size: 20px;
        }
        #pause-confirm-dialog p {
            margin-bottom: 20px;
            font-size: 22px;
            text-shadow: 2px 2px #FFD700;
        }
        #pause-confirm-dialog .dialog-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        #pause-confirm-dialog button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            font-size: 18px;
            border: 3px solid #228B22;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #pause-confirm-dialog button:hover {
            background-color: #5cb85c;
            transform: translateY(-1px);
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5);
        }
        #pause-confirm-dialog button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
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


        #mobile-controls {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 90px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 150;
            padding: 8px 15px;
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
            padding: 10px 18px;
            border: 3px solid #000;
            border-radius: 8px;
            font-family: 'Press Start 2P', cursive;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            width: 60px;
            height: 60px;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            flex-shrink: 0;
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5);
            transition: background-color 0.1s ease;
            touch-action: none;
        }

        .mobile-btn:active {
            background-color: #e0c200;
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5);
            transform: translateY(1px);
        }

        #left-btn, #right-btn {
            margin: 0 5px;
        }

        #action-buttons {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-right: 15px;
        }
        #direction-buttons {
            display: flex;
            flex-direction: row;
            margin-left: 15px;
        }

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
            font-size: 28px;
            color: white;
            z-index: 300;
            text-align: center;
        }
        #loading-screen p {
            margin-bottom: 15px;
        }
        /* --- CSS End --- */
    </style>
</head>
<body>
    <div id="loading-screen">
        <p>Memuat Game...</p>
    </div>

    <div id="game-viewport">
        <div id="top-left-info-group">
            <div id="score">Skor: 0</div>
            <div id="timer">Waktu: 60</div>
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
            <button id="restart-button">Mulai Ulang</button>
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

    <script>
        /* --- JavaScript Start --- */
        const player = document.getElementById('player');
        const gameViewport = document.getElementById('game-viewport');
        const gameWorld = document.getElementById('game-world');
        const scoreDisplay = document.getElementById('score');
        const messageDisplay = document.getElementById('message');
        const timerDisplay = document.getElementById('timer');
        const gameOverScreen = document.getElementById('game-over-screen');
        const gameOverMessage = document.getElementById('game-over-message');
        const restartButton = document.getElementById('restart-button');

        const loadingScreen = document.getElementById('loading-screen');

        const upBtn = document.getElementById('up-btn');
        const leftBtn = document.getElementById('left-btn');
        const rightBtn = document.getElementById('right-btn');

        // Settings Menu Elements
        const settingsButton = document.getElementById('settings-button');
        const settingsMenu = document.getElementById('settings-menu');
        const closeSettingsButton = document.getElementById('close-settings-button'); // Tombol Silang
        const pauseGameButton = document.getElementById('pause-game-button');
        const quitGameButton = document.getElementById('quit-game-button');

        // Pause Confirmation Dialog elements
        const pauseConfirmDialog = document.getElementById('pause-confirm-dialog');
        const pauseConfirmYes = document.getElementById('pause-confirm-yes');
        const pauseConfirmNo = document.getElementById('pause-confirm-no');


        const playerWidth = 96;
        const playerHeight = 144;
        const baseGroundHeight = 90;

        let playerXInWorld = 100;
        let playerY = baseGroundHeight;
        let isJumping = false;
        let score = 0;

        const gravity = 1;
        let velocityY = 0;
        let currentCarriedTrash = null;

        const cameraFollowOffset = 300;
        let cameraX = 0;

        let gameTime = 60;
        let gameTimerInterval = null;
        let gameActive = false;
        let gamePaused = false; // true = game dijeda (timer, movement, dll berhenti)
        let settingsMenuOpen = false; // true = menu pengaturan terbuka (timer dll tetap jalan)

        let currentWorldTrashCollected = 0;

        const trashTypes = [
            { name: 'plastik', img: 'assets/images/trash_plastik.png', message: 'Botol plastik bisa didaur ulang menjadi serat pakaian atau perabot baru! Kurangi sampah plastik di laut ya.' },
            { name: 'kertas', img: 'assets/images/trash_kertas.png', message: 'Kertas bekasmu bisa jadi buku atau kotak kemasan lagi! Pisahkan dari sampah basah!' },
            { name: 'kaleng', img: 'assets/images/trash_kaleng.png', message: 'Kaleng aluminium dan baja sangat berharga untuk didaur ulang. Hemat energi dan sumber daya alam!' },
            { name: 'kaca', img: 'assets/images/trash_kaca.png', message: 'Kaca bisa didaur ulang berkali-kali tanpa mengurangi kualitas! Pastikan bersih dan tidak pecah ya.' },
            { name: 'organik', img: 'assets/images/trash_organik.png', message: 'Sisa makanan dan daun kering bisa jadi kompos subur untuk tanaman. Jangan dibuang ke TPA!' },
            { name: 'baterai', img: 'assets/images/trash_baterai.png', message: 'Baterai mengandung zat berbahaya! Jangan dibuang sembarangan, kumpulkan di tempat khusus daur ulang.' }
        ];

        let carsSpawned = 0;
        const maxCars = 2;
        let groundBirdsSpawned = 0;
        const maxGroundBirds = 2;
        let obstacleSpawnInterval = null;

        const pressedKeys = {};

        let activeTrash = [];
        let activeMovingObstacles = [];
        let activeLevelElements = [];

        let animationFrameId;

        // --- DEFINISI DUNIA / LEVEL --- (Tidak berubah)
        const worlds = [
            {
                name: "Pinggiran Kota Hijau",
                background: '#5A7E9C',
                elements: [
                    { type: 'cloud', class: 'type-1', top: 80, left: 150 }, { type: 'cloud', class: 'type-2', top: 150, left: 800 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 1400 }, { type: 'cloud', class: 'type-2', top: 160, left: 2000 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 2600 }, { type: 'cloud', class: 'type-2', top: 150, left: 3200 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 3800 }, { type: 'cloud', class: 'type-2', top: 160, left: 4400 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 5000 }, { type: 'cloud', class: 'type-2', top: 150, left: 5600 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 6200 }, { type: 'cloud', class: 'type-2', top: 150, left: 6800 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 7400 },
                    { type: 'smoke', class: 'type-1', top: 200, left: 500 }, { type: 'smoke', class: 'type-2', top: 100, left: 1000 },
                    { type: 'smoke', class: 'type-1', top: 150, left: 1700 }, { type: 'smoke', class: 'type-2', top: 80, left: 2400 },
                    { type: 'smoke', class: 'type-1', top: 220, left: 3000 }, { type: 'smoke', class: 'type-2', top: 130, left: 3700 },
                    { type: 'smoke', class: 'type-1', top: 180, left: 4500 }, { type: 'smoke', class: 'type-2', top: 90, left: 5200 },
                    { type: 'smoke', class: 'type-1', top: 210, left: 6000 }, { type: 'smoke', class: 'type-2', top: 140, left: 6700 },
                    { type: 'smoke', class: 'type-1', top: 160, left: 7500 },
                    { type: 'house', left: 400 }, { type: 'house', left: 1500 }, { type: 'house', left: 3000 },
                    { type: 'house', left: 4800 }, { type: 'house', left: 6000 }, { type: 'house', left: 7200 },
                    { type: 'bird-flying', top: 200, left: 100, speed: 25 }, { type: 'bird-flying', top: 250, left: 600, speed: 20, delay: -5 },
                    { type: 'bird-flying', top: 180, left: 1200, speed: 30, delay: -10 }, { type: 'bird-flying', top: 220, left: 1800, speed: 22 },
                    { type: 'bird-flying', top: 200, left: 2500, speed: 28, delay: -7 }, { type: 'bird-flying', top: 230, left: 3000, speed: 20 },
                    { type: 'bird-flying', top: 190, left: 3700, speed: 26, delay: -3 }, { type: 'bird-flying', top: 210, left: 4400, speed: 23 },
                    { type: 'bird-flying', top: 240, left: 5000, speed: 29, delay: -8 }, { type: 'bird-flying', top: 170, left: 5600, speed: 21 },
                    { type: 'bird-flying', top: 200, left: 6300, speed: 25 }, { type: 'bird-flying', top: 250, left: 7000, speed: 20, delay: -5 },
                    { type: 'obstacle-spikes', left: 700 }, { type: 'floating-platform', left: 655, bottom: 200, width: 180 }, 
                    { type: 'puddle', left: 2200 }, { type: 'floating-platform', left: 2165, bottom: 150, width: 200 }, 
                    { type: 'obstacle-spikes', left: 3500 }, { type: 'floating-platform', left: 3455, bottom: 200, width: 180 }, 
                    { type: 'puddle', left: 4000 }, { type: 'floating-platform', left: 3965, bottom: 150, width: 200 }, 
                    { type: 'obstacle-spikes', left: 5500 }, { type: 'floating-platform', left: 5455, bottom: 200, width: 180 }, 
                    { type: 'puddle', left: 6800 }, { type: 'floating-platform', left: 6765, bottom: 150, width: 200 },
                    { type: 'tree', left: 950 }, { type: 'tree', left: 1100 }, { type: 'tree', left: 2500 },
                    { type: 'tree', left: 4200 }, { type: 'tree', left: 5800 }, { type: 'tree', left: 7400 },
                    { type: 'finish-pole', left: 7800 }
                ]
            },
            {
                name: "Taman Indah",
                background: '#87CEEB',
                elements: [
                    { type: 'cloud', class: 'type-1', top: 100, left: 200 }, { type: 'cloud', class: 'type-2', top: 150, left: 900 },
                    { type: 'cloud', class: 'type-1', top: 120, left: 1800 }, { type: 'cloud', class: 'type-2', top: 160, left: 2500 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 3300 }, { type: 'cloud', class: 'type-2', top: 150, left: 4000 },
                    { type: 'cloud', class: 'type-1', top: 120, left: 4900 }, { type: 'cloud', class: 'type-2', top: 160, left: 5600 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 6400 }, { type: 'cloud', class: 'type-2', top: 150, left: 7100 },
                    { type: 'smoke', class: 'type-1', top: 180, left: 300 }, { type: 'smoke', class: 'type-2', top: 90, left: 700 },
                    { type: 'smoke', class: 'type-1', top: 140, left: 1500 }, { type: 'smoke', class: 'type-2', top: 70, left: 2200 },
                    { type: 'smoke', class: 'type-1', top: 200, left: 2800 }, { type: 'smoke', class: 'type-2', top: 110, left: 3500 },
                    { type: 'smoke', class: 'type-1', top: 160, left: 4200 }, { type: 'smoke', class: 'type-2', top: 80, left: 5000 },
                    { type: 'smoke', class: 'type-1', top: 190, left: 5700 }, { type: 'smoke', class: 'type-2', top: 120, left: 6500 },
                    { type: 'smoke', class: 'type-1', top: 170, left: 7200 },
                    { type: 'tree', left: 500 }, { type: 'tree', left: 1300 }, { type: 'tree', left: 2800 },
                    { type: 'tree', left: 4500 }, { type: 'tree', left: 6200 },
                    { type: 'floating-platform', left: 800, bottom: 180, width: 180 },
                    { type: 'floating-platform', left: 2200, bottom: 180, width: 180 },
                    { type: 'floating-platform', left: 3800, bottom: 180, width: 180 },
                    { type: 'floating-platform', left: 5400, bottom: 180, width: 180 },
                    { type: 'floating-platform', left: 7000, bottom: 180, width: 180 },
                    { type: 'bird-flying', top: 200, left: 400, speed: 20 }, { type: 'bird-flying', top: 250, left: 1000, speed: 18, delay: -3 },
                    { type: 'bird-flying', top: 180, left: 1600, speed: 22 }, { type: 'bird-flying', top: 220, left: 2300, speed: 19, delay: -6 },
                    { type: 'bird-flying', top: 200, left: 3000, speed: 25 }, { type: 'bird-flying', top: 230, left: 3700, speed: 21 },
                    { type: 'bird-flying', top: 190, left: 4500, speed: 27, delay: -4 }, { type: 'bird-flying', top: 210, left: 5200, speed: 20 },
                    { type: 'bird-flying', top: 240, left: 6000, speed: 23, delay: -9 }, { type: 'bird-flying', top: 170, left: 6700, speed: 18 },
                    { type: 'bird-flying', top: 200, left: 7400, speed: 22 },
                    { type: 'finish-pole', left: 7800 }
                ]
            }
        ];
        let currentWorldIndex = 0;

        function initializeLevelElements() {
            activeLevelElements.forEach(el => el.remove());
            activeLevelElements = [];
            
            document.body.style.backgroundColor = worlds[currentWorldIndex].background;

            worlds[currentWorldIndex].elements.forEach(elDef => {
                const element = document.createElement('div');
                element.style.position = 'absolute';

                if (elDef.bottom !== undefined) {
                    element.style.bottom = elDef.bottom + 'px';
                } else if (elDef.top !== undefined) {
                    element.style.top = elDef.top + 'px';
                } else {
                    element.style.bottom = baseGroundHeight + 'px';
                }

                element.style.left = elDef.left + 'px';

                if (elDef.type === 'cloud') {
                    element.classList.add('cloud', elDef.class);
                } else if (elDef.type === 'smoke') {
                    element.classList.add('smoke', elDef.class);
                }
                else if (elDef.type === 'bird-flying') {
                    element.classList.add('bird');
                    element.style.setProperty('--bird-speed', `${elDef.speed}s`);
                    if (elDef.delay) element.style.animationDelay = `${elDef.delay}s`;
                    element.classList.add('move-across-world');
                } 
                else if (elDef.type === 'obstacle-spikes') { 
                    element.classList.add('obstacle-spikes');
                    element.dataset.hazard = 'true'; 
                } else if (elDef.type === 'puddle') { 
                    element.classList.add('puddle');
                    element.dataset.hazard = 'true'; 
                } else if (elDef.type === 'floating-platform') {
                    element.classList.add('floating-platform');
                    element.dataset.platform = 'true';
                    if (elDef.width) element.style.width = elDef.width + 'px';
                    if (elDef.height) element.style.height = elDef.height + 'px';
                } else if (elDef.type === 'tree') {
                    element.classList.add('tree');
                    element.dataset.platform = 'true';
                }
                else if (elDef.type === 'finish-pole') {
                    element.classList.add('finish-pole');
                    element.dataset.finish = 'true';
                }
                else if (elDef.type === 'house') {
                    element.classList.add('house');
                    const door = document.createElement('div');
                    door.classList.add('house-door');
                    element.appendChild(door);

                    const windowLeft = document.createElement('div');
                    windowLeft.classList.add('house-window', 'left');
                    element.appendChild(windowLeft);

                    const windowRight = document.createElement('div');
                    windowRight.classList.add('house-window', 'right');
                    element.appendChild(windowRight);
                }
                gameWorld.appendChild(element);
                activeLevelElements.push(element);
            });
        }

        function createGround() {
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            const worldWidth = gameWorld.offsetWidth;
            const segmentWidth = 90;
            const numSegments = Math.ceil(worldWidth / segmentWidth);

            if (numSegments === 0 || worldWidth === 0) {
                console.warn("gameWorld.offsetWidth is 0 or too small. Ground will not be created on this attempt.");
                return;
            }

            for (let i = 0; i < numSegments; i++) {
                const segmentX = i * segmentWidth;
                
                const segmentHeight = baseGroundHeight;

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

        let playerAnimationState = 'stand';
        let playerDirection = 'right';

        function updatePlayerAnimation() {
            let imgPath = '';
            if (playerAnimationState === 'jump') {
                imgPath = 'assets/images/mario_jump.png';
            } else if (playerAnimationState === 'walk') {
                imgPath = 'assets/images/mario_walk_right_1.png';
            } else { // stand
                imgPath = 'assets/images/mario_stand.png';
            }
            player.style.backgroundImage = `url('${imgPath}')`;
            player.style.transform = `scaleY(1) ${playerDirection === 'left' ? 'scaleX(-1)' : 'scaleX(1)'}`; 
        }


        function playLandingAnimation() {
            player.style.transform = `scaleY(0.9) ${playerDirection === 'left' ? 'scaleX(-1)' : 'scaleX(1)'}`; 
            setTimeout(() => {
                player.style.transform = `scaleY(1) ${playerDirection === 'left' ? 'scaleX(-1)' : 'scaleX(1)'}`; 
            }, 100);
        }

        function updatePlayerAndCameraPosition() {
            let playerXInViewport = playerXInWorld - cameraX;

            if (playerXInViewport > gameViewport.offsetWidth - playerWidth - cameraFollowOffset) {
                cameraX = playerXInWorld - (gameViewport.offsetWidth - playerWidth - cameraFollowOffset);
            }
            else if (playerXInViewport < cameraFollowOffset) {
                cameraX = playerXInWorld - cameraFollowOffset;
            }

            if (cameraX < 0) cameraX = 0;
            const maxCameraX = gameWorld.offsetWidth - gameViewport.offsetWidth;
            if (cameraX > maxCameraX) {
                cameraX = maxCameraX;
            }

            player.style.left = (playerXInWorld - cameraX) + 'px';
            player.style.bottom = playerY + 'px';

            gameWorld.style.transform = `translateX(${-cameraX}px)`;

            if (currentCarriedTrash) {
                currentCarriedTrash.style.left = (playerXInWorld - cameraX + playerWidth / 2 - currentCarriedTrash.offsetWidth / 2) + 'px';
                currentCarriedTrash.style.bottom = (playerY + playerHeight + 5) + 'px';
            }
        }

        function jump() {
            if (!isJumping && gameActive && !gamePaused) {
                isJumping = true;
                velocityY = 25;
                playerAnimationState = 'jump';
                updatePlayerAnimation();
            }
        }

        function takeDamage() {
            if (!gameActive) return;
            player.classList.add('invincible');
            setTimeout(() => {
                player.classList.remove('invincible');
            }, 1000);

            endGame(false, 'damage_hazard');
        }

        function createMovingObstacle() {
            if (!gameActive || gamePaused) return;

            let obstacle;
            let speed;
            let spawnX = gameWorld.offsetWidth;
            
            const currentGroundBirdsCount = activeMovingObstacles.filter(obj => obj.classList.contains('bird-on-ground')).length;
            const currentCarsCount = activeMovingObstacles.filter(obj => obj.classList.contains('obstacle-car')).length;

            const availableObstacles = [];
            if (currentGroundBirdsCount < maxGroundBirds) {
                availableObstacles.push('ground-bird');
            }
            if (currentCarsCount < maxCars) {
                availableObstacles.push('car');
            }

            if (availableObstacles.length === 0) {
                return;
            }

            const typeToSpawn = availableObstacles[(Math.random() * availableObstacles.length) | 0];

            if (typeToSpawn === 'ground-bird') {
                obstacle = document.createElement('div');
                obstacle.classList.add('bird-on-ground');
                obstacle.dataset.hazard = 'true';
                speed = Math.random() * (7 - 3) + 3;
            } else if (typeToSpawn === 'car') {
                obstacle = document.createElement('div');
                obstacle.classList.add('obstacle-car');
                obstacle.dataset.hazard = 'true';
                speed = Math.random() * (6 - 2) + 2;
            }
            
            if (!obstacle) return;

            obstacle.style.left = spawnX + 'px';
            obstacle.style.setProperty('--move-speed', `${speed}s`); 
            gameWorld.appendChild(obstacle);
            activeMovingObstacles.push(obstacle);

            obstacle.addEventListener('animationend', () => {
                const index = activeMovingObstacles.indexOf(obstacle);
                if (index > -1) {
                    activeMovingObstacles.splice(index, 1);
                }
                obstacle.remove();
            });
        }

        let initialGroundAndLevelSetupDone = false;

        function gameLoop() {
            // Hentikan pemrosesan game jika game tidak aktif atau dijeda
            if (!gameActive || gamePaused) {
                animationFrameId = requestAnimationFrame(gameLoop);
                return;
            }

            if (!initialGroundAndLevelSetupDone) {
                console.log("First game loop frame. Initializing ground and level elements.");
                document.getElementById('game-viewport').style.display = 'block';
                createGround();
                initializeLevelElements();

                if (document.querySelectorAll('.ground-brick-segment').length > 0) {
                    initialGroundAndLevelSetupDone = true;
                    console.log("Ground and level elements successfully created.");
                } else {
                    console.warn("Ground or level elements not fully created on first loop. Retrying next frame.");
                    animationFrameId = requestAnimationFrame(gameLoop);
                    return;
                }
            }

            const playerMoveSpeed = 5;

            let movingHorizontally = false;

            if (pressedKeys['ArrowRight'] || pressedKeys['d']) {
                playerXInWorld += playerMoveSpeed;
                if (playerXInWorld + playerWidth > gameWorld.offsetWidth) {
                    playerXInWorld = gameWorld.offsetWidth - playerWidth;
                }
                playerDirection = 'right';
                if (!isJumping) playerAnimationState = 'walk';
                movingHorizontally = true;
            } else if (pressedKeys['ArrowLeft'] || pressedKeys['a']) {
                playerXInWorld -= playerMoveSpeed;
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

            let currentGroundHeight = baseGroundHeight;

            activeLevelElements.forEach(el => {
                if (el.dataset.platform === 'true') {
                    const platformWorldX = parseInt(el.style.left);
                    const platformBottom = parseInt(el.style.bottom);
                    const platformWidth = parseInt(el.offsetWidth);
                    const platformHeight = parseInt(el.offsetHeight);
                    const platformTopSurfaceY = platformBottom + platformHeight;

                    const playerPreviousY = playerY - velocityY;

                    if (playerXInWorld + playerWidth > platformWorldX &&
                        playerXInWorld < platformWorldX + platformWidth &&
                        playerPreviousY >= platformTopSurfaceY &&
                        playerY <= platformTopSurfaceY) {
                        
                        if (velocityY < 0) {
                            currentGroundHeight = platformTopSurfaceY;
                        }
                    }
                }
            });

            if (isJumping) {
                playerY += velocityY;
                velocityY -= gravity;

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

                        if (playerXInWorld + playerWidth > platformWorldX &&
                            playerXInWorld < platformWorldX + platformWidth &&
                            playerY === platformTopSurfaceY) {
                            shouldBeFalling = false;
                        }
                    }
                });

                if (playerY > baseGroundHeight && shouldBeFalling) {
                     isJumping = true;
                     velocityY = -gravity;
                     playerAnimationState = 'jump';
                     updatePlayerAnimation();
                } else if (playerY < baseGroundHeight && !shouldBeFalling) {
                    playerY = baseGroundHeight;
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
            // Waktu game tidak berhenti saat hanya membuka pengaturan
        }

        function hideSettingsMenu() {
            settingsMenuOpen = false;
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none'; // Pastikan dialog konfirmasi juga tersembunyi
            document.getElementById('mobile-controls').style.display = 'flex';
            settingsButton.style.display = 'block';
            // Waktu game tidak berhenti saat hanya menutup pengaturan
        }

        // Fungsi yang dipanggil saat user KONFIRMASI jeda (klik "Yes" di dialog)
        function confirmPause() {
            if (!gameActive || gamePaused) return; // Pastikan game aktif dan belum dijeda
            gamePaused = true;
            clearInterval(gameTimerInterval); // Hentikan timer
            gameTimerInterval = null; // Penting: Setel ke null agar bisa dimulai lagi dengan benar
            if (obstacleSpawnInterval) {
                clearInterval(obstacleSpawnInterval);
                obstacleSpawnInterval = null; // Penting: Setel ke null
            }
            cancelAnimationFrame(animationFrameId); // Hentikan game loop
            pauseGameButton.textContent = "Lanjutkan Game"; // Ubah teks tombol Jeda
            pauseGameButton.classList.add('resume-state'); // Tambah kelas untuk gaya
            hideSettingsMenu(); // Sembunyikan dialog dan menu pengaturan
        }

        // Fungsi ini dipanggil saat tombol "Jeda" di menu pengaturan diklik
        function askToPause() {
            if (!gameActive) return;

            if (gamePaused) {
                // Jika game sudah dijeda, berarti tombol ini sekarang berfungsi sebagai "Lanjutkan Game"
                resumeGame();
            } else {
                // Jika game belum dijeda, tampilkan dialog konfirmasi
                pauseConfirmDialog.style.display = 'flex';
                // Biarkan game loop dan timer berjalan di latar belakang saat dialog muncul
            }
        }
        
        // Fungsi ini dipanggil saat game dilanjutkan (dari tombol 'Lanjutkan Game' / 'Jeda' yang sudah berubah)
        function resumeGame() {
            if (!gameActive) return; // Jika game tidak aktif, keluar

            // Pastikan game memang dalam keadaan dijeda (agar tidak melanjutkan yang tidak dijeda)
            if (!gamePaused) {
                hideSettingsMenu(); // Jika tidak dijeda, hanya sembunyikan menu
                return;
            }

            gamePaused = false;
            startGameTimer(); // Lanjutkan timer
            startMovingObstacleSpawning(); // Lanjutkan spawning rintangan
            pauseGameButton.textContent = "Jeda"; // Ubah teks tombol Jeda kembali
            pauseGameButton.classList.remove('resume-state'); // Hapus kelas untuk gaya
            hideSettingsMenu(); // Sembunyikan menu pengaturan
            animationFrameId = requestAnimationFrame(gameLoop); // Lanjutkan game loop
        }


        // --- Keyboard Controls ---
        document.addEventListener('keydown', (e) => {
            if (!gameActive) return;
            if (e.repeat) return;

            // Handle 'Escape' key for settings menu/pause dialog
            if (e.code === 'Escape') {
                if (pauseConfirmDialog.style.display === 'flex') { // Jika dialog konfirmasi jeda terbuka
                    hideSettingsMenu(); // Sembunyikan dialog dan menu pengaturan
                } else if (settingsMenuOpen) { // Jika menu pengaturan terbuka (tanpa dialog konfirmasi jeda)
                    if (gamePaused) { // Jika game sedang dijeda dari tombol 'Jeda' (berubah jadi Lanjutkan)
                        resumeGame(); // Langsung lanjutkan game
                    } else { // Jika game tidak dijeda (hanya menu pengaturan yang terbuka)
                        hideSettingsMenu(); // Hanya tutup menu
                    }
                } else { // Jika menu pengaturan tertutup
                    showSettingsMenu(); // Buka menu pengaturan
                }
                return;
            }

            if (gamePaused || settingsMenuOpen) return; // Mencegah pergerakan pemain jika dijeda atau menu terbuka

            pressedKeys[e.code] = true;
            if (e.key === 'a') pressedKeys['a'] = true;
            if (e.key === 'd') pressedKeys['d'] = true;
            if (e.key === 'w') pressedKeys['w'] = true;


            if (e.code === 'Space' || e.key === 'w' || e.key === 'ArrowUp') {
                jump();
            }
        });

        document.addEventListener('keyup', (e) => {
            if (!gameActive || gamePaused || settingsMenuOpen) return;
            pressedKeys[e.code] = false;
            if (e.key === 'a') pressedKeys['a'] = false;
            if (e.key === 'd') pressedKeys['d'] = false;
            if (e.key === 'w') pressedKeys['w'] = false;
        });

        // --- Mobile Controls Event Listeners ---
        rightBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (!gamePaused && !settingsMenuOpen) pressedKeys['ArrowRight'] = true;
        });
        rightBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            pressedKeys['ArrowRight'] = false;
        });

        leftBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (!gamePaused && !settingsMenuOpen) pressedKeys['ArrowLeft'] = true;
        });
        leftBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            pressedKeys['ArrowLeft'] = false;
        });

        upBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            jump();
        });

        // Settings Button Event Listeners
        settingsButton.addEventListener('click', showSettingsMenu);
        closeSettingsButton.addEventListener('click', hideSettingsMenu); // Event listener for 'X' button
        pauseGameButton.addEventListener('click', askToPause); // Tombol jeda kini memanggil askToPause
        quitGameButton.addEventListener('click', () => {
            if (confirm("Apakah Anda yakin ingin keluar dari game?")) {
                // Di sini kita akan menambahkan panggilan ke endGame dengan status kalah
                endGame(false, 'quit_game');
            }
        });

        // Pause Confirmation Dialog event listeners
        pauseConfirmYes.addEventListener('click', confirmPause);
        pauseConfirmNo.addEventListener('click', hideSettingsMenu); // Jika "No", hanya sembunyikan dialog dan lanjutkan game

        // Fungsi untuk menampilkan pesan notifikasi
        let messageTimeout;
        function showMessage(text) {
            messageDisplay.textContent = text;
            messageDisplay.classList.add('show');
            clearTimeout(messageTimeout);
            messageTimeout = setTimeout(() => {
                messageDisplay.classList.remove('show');
            }, 3000);
        }

        // Fungsi untuk membuat partikel saat sampah diambil
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


        // Membuat sampah
        function createTrash(xPos = null) {
            console.log(`--- Memanggil createTrash ---`);
            const trashType = trashTypes[(Math.random() * trashTypes.length) | 0];
            const trash = document.createElement('div');
            trash.classList.add('trash');
            trash.classList.add(trashType.name);
            trash.style.backgroundImage = `url('${trashType.img}')`;

            // Add random rotation and slight size variation
            trash.style.transform = `rotate(${Math.random() * 30 - 15}deg)`;
            const randomSizeFactor = 0.8 + (Math.random() * 0.4);
            trash.style.width = `${64 * randomSizeFactor}px`;
            trash.style.height = `${64 * randomSizeFactor}px`;


            let spawnX;
            const viewportWidth = gameViewport.offsetWidth;

            if (xPos !== null) {
                spawnX = xPos;
            } else {
                if (viewportWidth === 0) {
                    console.warn("gameViewport.offsetWidth is 0, cannot calculate spawnX for trash.");
                    return;
                }

                spawnX = cameraX + viewportWidth + 100 + (Math.random() * (gameWorld.offsetWidth - (cameraX + viewportWidth + 100) - trash.offsetWidth - 50));
                spawnX = Math.min(spawnX, gameWorld.offsetWidth - trash.offsetWidth - 50); 
                spawnX = Math.max(spawnX, cameraX + viewportWidth / 2); 
            }

            // Always make trash floating
            let trashBottomY = Math.floor(Math.random() * (400 - 200 + 1)) + 200;

            const platformsAndObstacles = [...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('house') && !el.classList.contains('smoke')), ...activeMovingObstacles];
            let isOverlapping = false;
            let attempts = 0;
            const maxAttempts = 50; 
            const spawnPadding = 70; 

            if (platformsAndObstacles.length > 0) { 
                let initialCheckPerformed = false;
                while(!initialCheckPerformed || (isOverlapping && attempts < maxAttempts)) {
                    initialCheckPerformed = true;
                    isOverlapping = false; 

                    const tempTrashLeft = spawnX;
                    const tempTrashRight = spawnX + trash.offsetWidth;
                    let tempTrashBottom = trashBottomY;
                    const tempTrashTop = tempTrashBottom + trash.offsetHeight;

                    for (const elem of platformsAndObstacles) {
                        if (!(elem instanceof Element)) continue; 

                        const elemWorldX = parseInt(elem.style.left);
                        let elemWorldY;
                        if (elem.style.bottom) {
                            elemWorldY = parseInt(elem.style.bottom);
                        } else if (elem.style.top) {
                            elemWorldY = gameWorld.offsetHeight - parseInt(elem.style.top) - elem.offsetHeight;
                        } else {
                            elemWorldY = 0;
                        }

                        const elemWidth = elem.offsetWidth;
                        let elemHeight = elem.offsetHeight;

                        if (elem.classList.contains('obstacle-spikes')) { 
                            elemWorldY = 0; 
                            elemHeight = baseGroundHeight + 20; 
                        }
                        else if (elem.classList.contains('puddle')) {
                            elemWorldY = 0; 
                            elemHeight = parseInt(elem.style.height); 
                        }
                        else if (elem.classList.contains('tree')) {
                            elemHeight = parseInt(elem.style.height);
                        }


                        if (tempTrashLeft < elemWorldX + elemWidth + spawnPadding &&
                            tempTrashRight > elemWorldX - spawnPadding &&
                            tempTrashBottom < elemWorldY + elemHeight + spawnPadding &&
                            tempTrashTop > elemWorldY - spawnPadding) {

                            isOverlapping = true;
                            attempts++;
                            spawnX = cameraX + viewportWidth + 100 + (Math.random() * 2000); 
                            spawnX = Math.min(spawnX, gameWorld.offsetWidth - trash.offsetWidth - 50);
                            spawnX = Math.max(spawnX, cameraX + viewportWidth / 2);
                            trashBottomY = Math.floor(Math.random() * (400 - 200 + 1)) + 200;
                            break;
                        }
                    }
                }
            }
            
            if (isOverlapping && attempts >= maxAttempts) {
                console.warn(`Gagal menemukan posisi sampah bebas setelah ${maxAttempts} percobaan. Sampah tidak dibuat.`);
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

            // Kolisi dengan sampah
            for (let i = activeTrash.length - 1; i >= 0; i--) {
                const trash = activeTrash[i];
                if (trash.dataset.collected === 'true' || trash.style.opacity === '0') continue; 

                const trashWorldX = parseInt(trash.style.left);
                const trashWorldY = parseInt(trash.style.bottom);

                if (playerWorldX < trashWorldX + trash.offsetWidth &&
                    playerXInWorld + playerWidth > trashWorldX &&
                    playerY < trashWorldY + trash.offsetHeight && 
                    playerY + playerHeight > trashWorldY) {

                    currentCarriedTrash = trash;
                    showMessage(trash.dataset.message);
                    score += 1;
                    currentWorldTrashCollected += 1;
                    scoreDisplay.textContent = 'Skor: ' + score;

                    const trashXInViewport = trashWorldX - cameraX;
                    createCollectParticle(trashXInViewport + trash.offsetWidth / 2, trashWorldY + trash.offsetWidth / 2); // Menggunakan offsetWidth untuk partikel

                    currentCarriedTrash.style.opacity = '0';
                    currentCarriedTrash.dataset.collected = 'true';
                    
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


            const allCollidableElements = [...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('house') && !el.classList.contains('smoke')), ...activeMovingObstacles];
            allCollidableElements.forEach(platform => {
                const platformRect = platform.getBoundingClientRect();
                const gameWorldRect = gameWorld.getBoundingClientRect();

                const platformWorldX = (platformRect.left - gameWorldRect.left);
                let platformWorldY;
                if (platform.style.bottom) {
                    platformWorldY = parseInt(platform.style.bottom);
                } else if (platform.style.top) {
                    platformWorldY = gameWorld.offsetHeight - parseInt(platform.style.top) - platform.offsetHeight;
                } else {
                    platformWorldY = 0;
                }

                const platformWidth = platform.offsetWidth;
                let platformHeight = platform.offsetHeight;
                
                if (platform.dataset.hazard === 'true' || platform.classList.contains('bird-on-ground') || platform.classList.contains('obstacle-car') || platform.classList.contains('puddle')) { 
                    const playerCenterX = playerXInWorld + playerWidth / 2;
                    const playerBottomY = playerY;

                    const obstacleCenterX = platformWorldX + platformWidth / 2;
                    const obstacleBottomY = platformWorldY;

                    const collisionToleranceX = 20;
                    const collisionToleranceY = 10;

                    if (playerCenterX > obstacleCenterX - platformWidth / 2 + collisionToleranceX &&
                        playerCenterX < obstacleCenterX + platformWidth / 2 - collisionToleranceX &&
                        playerBottomY < obstacleBottomY + platformHeight - collisionToleranceY &&
                        playerBottomY + playerHeight > obstacleBottomY + collisionToleranceY) {
                            
                            takeDamage(); 
                            return;
                        }
                }
                else if (platform.dataset.finish === 'true') {
                    const finishPoleWorldX = parseInt(platform.style.left);
                    const finishPoleWidth = platform.offsetWidth;
                    const finishPoleHeight = platform.offsetHeight;
                    const finishPoleBottom = parseInt(platform.style.bottom);

                    if (playerXInWorld + playerWidth > finishPoleWorldX &&
                        playerXInWorld < finishPoleWorldX + finishPoleWidth &&
                        playerY < finishPoleBottom + finishPoleHeight &&
                        playerY + playerHeight > finishPoleBottom) {
                        
                        endGame(true, 'finish_pole'); 
                        return;
                    }
                }
                
                let platformTopSurfaceY = platformWorldY + platformHeight;
                if (platform.classList.contains('tree')) { 
                    platformTopSurfaceY = platformWorldY + platform.offsetHeight; 
                }


                if (platform.dataset.platform === 'true') {
                    const playerRect = {
                        left: playerXInWorld,
                        right: playerXInWorld + playerWidth,
                        bottom: playerCurrentY,
                        top: playerCurrentY + playerHeight
                    };
                    const platformRectWorld = {
                        left: platformWorldX,
                        right: platformWorldX + platformWidth,
                        bottom: platformWorldY,
                        top: platformWorldY + platformHeight
                    };

                    if (playerRect.bottom < platformRectWorld.top && playerRect.top > platformRectWorld.bottom) {
                        const playerMoveSpeed = 5; 

                        if (playerRect.right > platformRectWorld.left && playerPreviousY >= platformTopSurfaceY - 5 && playerRect.right - platformRectWorld.left < playerMoveSpeed + 5) {
                            playerXInWorld = platformRectWorld.left - playerWidth;
                            pressedKeys['ArrowRight'] = false;
                            pressedKeys['d'] = false;
                        }
                        else if (playerRect.left < platformRectWorld.right && playerPreviousY >= platformTopSurfaceY - 5 && platformRectWorld.right - playerRect.left < playerMoveSpeed + 5) {
                            playerXInWorld = platformRectWorld.right;
                            pressedKeys['ArrowLeft'] = false;
                            pressedKeys['a'] = false;
                        }
                    }
                }
                
                if (platform.dataset.platform === 'true') { 
                    if (velocityY < 0) { 
                        if (playerXInWorld + playerWidth > platformWorldX &&
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
                    else if (velocityY > 0) {
                        const playerTopY = playerCurrentY + playerHeight;
                        const playerPreviousTopY = playerPreviousY + playerHeight;

                        if (playerXInWorld + playerWidth > platformWorldX &&
                            playerXInWorld < platformWorldX + platformWidth &&
                            playerPreviousTopY <= platformWorldY &&
                            playerTopY >= platformWorldY) {

                            playerY = platformWorldY - playerHeight;
                            velocityY = -1;
                            playerAnimationState = 'jump';
                            updatePlayerAnimation();
                        }
                    }
                }
            });
        }

        function startGameTimer() {
            // Hanya mulai jika belum berjalan, game aktif, dan tidak dijeda
            if (gameTimerInterval === null && gameActive && !gamePaused) {
                gameTimerInterval = setInterval(() => {
                    gameTime--;
                    timerDisplay.textContent = 'Waktu: ' + gameTime;
                    if (gameTime <= 0) {
                        clearInterval(gameTimerInterval);
                        gameTimerInterval = null; // Setel ke null setelah selesai
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

        // Fungsi endGame yang dimodifikasi untuk mengirim skor ke PHP
        async function endGame(won, reason) { //
            gameActive = false; //
            gamePaused = false; //
            settingsMenuOpen = false; // Pastikan semua state disetel ulang //
            clearInterval(gameTimerInterval); //
            gameTimerInterval = null; // Pastikan ini disetel ulang //
            if (obstacleSpawnInterval) { //
                clearInterval(obstacleSpawnInterval); //
                obstacleSpawnInterval = null; // Pastikan ini disetel ulang //
            }
            cancelAnimationFrame(animationFrameId); //

            player.style.display = 'none'; //
            activeTrash.forEach(t => t.remove()); activeTrash = []; //
            activeMovingObstacles.forEach(o => o.remove()); activeMovingObstacles = []; //
            activeLevelElements.forEach(el => { //
                if (el && typeof el.remove === 'function' && el.parentNode === gameWorld) { // Cek apakah elemen valid dan masih di DOM //
                    el.remove(); //
                }
            });
            activeLevelElements = []; //
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove()); //

            document.getElementById('mobile-controls').style.display = 'none'; //
            settingsButton.style.display = 'none'; //
            settingsMenu.style.display = 'none'; //
            pauseConfirmDialog.style.display = 'none'; // Sembunyikan dialog konfirmasi //

            let statusMessage = ''; //
            let gameStatus = 'lost'; // Default status //

            if (won) { //
                if (reason === 'collected_all_trash') { //
                     statusMessage = `Hebat! Kamu berhasil mengumpulkan semua sampah! Skor Akhir: ${score}! Bumi jadi bersih!`; //
                } else if (reason === 'finish_pole') { //
                    statusMessage = `Selamat! Kamu berhasil mencapai garis finish dan membersihkan bumi! Skor Akhir: ${score}!`; //
                }
                gameStatus = 'won'; // Set status menjadi 'won' jika berhasil //
                gameOverScreen.style.backgroundColor = 'rgba(0, 128, 0, 0.8)'; //
            } else { //
                if (reason === 'time') { //
                    statusMessage = `Waktu habis! Kamu mengumpulkan ${score} sampah. Coba lagi!`; //
                } else if (reason === 'damage_hazard') { //
                    statusMessage = `Game Over! Kamu menyentuh bahaya! Kamu mengumpulkan ${score} sampah. Coba lagi!`; //
                } else if (reason === 'quit_game') { // Menambahkan alasan keluar game //
                    statusMessage = `Game Dihentikan. Kamu mengumpulkan ${score} sampah.`; //
                }
                else { //
                    statusMessage = `Game Over! Kamu mengumpulkan ${score} sampah. Coba lagi!`; //
                }
                gameStatus = 'lost'; // Set status menjadi 'lost' //
                gameOverScreen.style.backgroundColor = 'rgba(128, 0, 0, 0.8)'; //
            }

            gameOverMessage.textContent = statusMessage; //
            gameOverScreen.style.display = 'flex'; //

            // Mengirim skor ke PHP
            try { //
                // Dapatkan ID level dan user_id dari parameter URL PHP
                const urlParams = new URLSearchParams(window.location.search); //
                const currentLevel = urlParams.get('level'); // Ambil level dari URL //
                const userId = <?php echo $userId; ?>; // Ambil user_id dari variabel PHP //

                if (!userId) { // Seharusnya tidak terjadi karena sudah dicek di PHP, tapi sebagai fallback //
                    console.error('User ID tidak ditemukan. Tidak dapat menyimpan skor.'); //
                    setTimeout(() => { //
                        window.location.href = 'login.php'; // Redirect ke login jika user ID tidak ada //
                    }, 3000); //
                    return; //
                }

                const response = await fetch(window.location.href, { // Mengirim POST ke file level.php ini sendiri //
                    method: 'POST', //
                    headers: { //
                        'Content-Type': 'application/x-www-form-urlencoded', //
                    },
                    body: `action=save_score&user_id=${userId}&score=${score}&status=${gameStatus}&level=${currentLevel}`, //
                });
                const data = await response.json(); //
                console.log('Server response:', data); //
                if (data.status === 'success') { //
                    console.log('Skor berhasil disimpan ke database.'); //
                } else { //
                    console.error('Gagal menyimpan skor ke database:', data.message); //
                }
            } catch (error) { //
                console.error('Error saat mengirim skor ke server:', error); //
            } finally { //
                // Selalu redirect kembali ke game_petualangan.php setelah mencoba menyimpan skor
                setTimeout(() => { // Memberi sedikit waktu agar user melihat pesan Game Over //
                    window.location.href = 'game_petualangan.php'; // Redirect ke halaman utama //
                }, 3000); // Redirect setelah 3 detik //
            }
        }

        function resetGame(resetWorldIndex = true) {
            for (const key in pressedKeys) {
                if (pressedKeys.hasOwnProperty(key)) {
                    pressedKeys[key] = false;
                }
            }

            cancelAnimationFrame(animationFrameId);
            clearInterval(gameTimerInterval);
            gameTimerInterval = null; // Penting: Setel ke null saat reset
            if (obstacleSpawnInterval) {
                clearInterval(obstacleSpawnInterval);
                obstacleSpawnInterval = null; // Penting: Setel ke null saat reset
            }
            
            player.style.display = 'none';
            activeTrash.forEach(t => t.remove()); activeTrash = [];
            activeMovingObstacles.forEach(o => o.remove()); activeMovingObstacles = [];
            activeLevelElements.forEach(el => el.remove());
            activeLevelElements = [];
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            playerXInWorld = 100;
            playerY = baseGroundHeight; 
            isJumping = false;
            score = 0;
            gameTime = 60;
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
            player.style.transform = 'scaleY(1) scaleX(1)'; 
            
            // Reset tampilan tombol jeda ke default
            pauseGameButton.textContent = "Jeda";
            pauseGameButton.classList.remove('resume-state');
            pauseGameButton.style.backgroundColor = '#FFC107';
            pauseGameButton.style.borderColor = '#D39E00';


            initialGroundAndLevelSetupDone = false;
            document.getElementById('game-viewport').style.display = 'block';
            
            currentWorldIndex = 0; // Setel kembali ke level pertama

            initializeLevelElements(); 
            createGround(); 
            updatePlayerAnimation();

            carsSpawned = 0;
            groundBirdsSpawned = 0;
            // nextObstacleType = 'ground-bird'; // Ini tidak digunakan lagi

            scoreDisplay.textContent = 'Skor: ' + score;
            timerDisplay.textContent = 'Waktu: ' + gameTime;
            showMessage(`Selamat datang di Go Green Hero! Kumpulkan sampah dan raih finish!`); 
            player.style.display = 'block';

            gameOverScreen.style.display = 'none';
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none';
            document.getElementById('mobile-controls').style.display = 'flex';
            settingsButton.style.display = 'block';

            startGameTimer();
            startMovingObstacleSpawning();
            
            // Modified trash spawning to ensure 25 items are distributed before the finish pole
            const numberOfTrashItems = 25;
            const finishPoleX = 7800; // X coordinate of the finish pole
            const spawnAreaWidth = finishPoleX - 200; // Spawn trash between 200px and just before the finish pole
            const spacing = spawnAreaWidth / (numberOfTrashItems + 1); // Even spacing

            for (let i = 0; i < numberOfTrashItems; i++) {
                const xPos = 200 + (i * spacing) + (Math.random() * (spacing * 0.5) - (spacing * 0.25)); // Add some randomness
                createTrash(xPos); 
            }

            animationFrameId = requestAnimationFrame(gameLoop);
        }

        restartButton.addEventListener('click', () => { //
            // Daripada memanggil resetGame lokal, langsung redirect ke game_petualangan.php
            // agar game dimulai ulang dari halaman utama dan data terbaru termuat
            window.location.href = 'game_petualangan.php'; //
        });

        function startMovingObstacleSpawning() {
            if (obstacleSpawnInterval === null && gameActive && !gamePaused) { // Periksa null
                obstacleSpawnInterval = setInterval(() => {
                    if (gameActive && !gamePaused && (activeMovingObstacles.filter(obj => obj.classList.contains('bird-on-ground')).length < maxGroundBirds ||
                                    activeMovingObstacles.filter(obj => obj.classList.contains('obstacle-car')).length < maxCars)) {
                        createMovingObstacle();
                    } else if (!gameActive || gamePaused) {
                        clearInterval(obstacleSpawnInterval);
                        obstacleSpawnInterval = null;
                    }
                }, Math.random() * (10000 - 5000) + 5000);
            }
        }

        const imageAssets = [
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
            'assets/images/spikes.png'
        ];
        const audioAssets = []; // Anda bisa tambahkan file audio di sini

        let assetsLoadedCount = 0;
        const totalAssets = imageAssets.length + audioAssets.length;

        function assetLoaded() {
            assetsLoadedCount++;
            if (assetsLoadedCount >= totalAssets) {
                console.log("All assets loaded. Starting game.");
                loadingScreen.style.display = 'none';
                resetGame(true); // Memulai game setelah semua aset dimuat
            }
        }

        function preloadAssets() {
            if (totalAssets === 0) {
                 console.log("No assets to load. Starting game directly.");
                 loadingScreen.style.display = 'none';
                 resetGame(true);
                 return;
            }

            imageAssets.forEach(src => {
                const img = new Image();
                img.onload = assetLoaded;
                img.onerror = (e) => {
                    console.error(`Gagal memuat gambar: ${src}`, e);
                    assetLoaded(); // Tetap panggil assetLoaded meskipun error agar tidak stuck
                };
                img.src = src;
            });

            audioAssets.forEach(src => {
                const audio = new Audio();
                audio.addEventListener('canplaythrough', assetLoaded, { once: true });
                audio.onerror = (e) => {
                    console.error(`Gagal memuat audio: ${src}`, e);
                    assetLoaded(); // Tetap panggil assetLoaded meskipun error agar tidak stuck
                };
                audio.src = src;
            });
        }

        preloadAssets();

        /* --- JavaScript End --- */
    </script>
</body>
</html>