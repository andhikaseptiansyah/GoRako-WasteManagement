<?php
// level_2.php

// Sertakan koneksi database
require_once 'db_connection.php'; //

// Sertakan fungsi helper (ini akan menyediakan fungsi saveGameResult, is_logged_in, redirect, sendJsonResponse)
require_once 'helpers.php'; //

// Tangani pengiriman data skor dari JavaScript (menggunakan AJAX/Fetch API)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'save_score') { //
    // Ambil user_id dari sesi, bukan dari POST, untuk keamanan
    if (!is_logged_in()) { //
        sendJsonResponse(['status' => 'error', 'message' => 'User ID tidak ditemukan. Harap login kembali.'], 401); //
        exit; //
    }
    $userId = $_SESSION['user_id']; // Gunakan ID pengguna dari sesi //

    $scoreToSave = isset($_POST['score']) ? intval($_POST['score']) : 0; //
    $gameStatus = isset($_POST['status']) ? $_POST['status'] : 'lost'; // 'won' or 'lost' //
    $levelPlayed = isset($_POST['level']) ? intval($_POST['level']) : 2; // PENTING: Setel ini ke 2 untuk Level 2! //

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
    $level = isset($_GET['level']) ? intval($_GET['level']) : 2; // Ambil level dari URL //

    // Tutup koneksi database jika ini bukan permintaan POST (untuk pemuatan halaman awal)
    $conn->close(); //
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Go Green Hero: Petualangan Edukasi Sampah! (Level 2)</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <style>
        /* --- CSS Start --- */
        body {
            margin: 0;
            overflow: hidden; /* Sembunyikan scrollbar */
            touch-action: none; /* Mencegah tindakan sentuhan default pada seluruh body */
            background-color: #5A7E9C; /* Warna langit biru berkabut */
            font-family: 'Press Start 2P', cursive;
            color: #333;
            /* Game akan memenuhi seluruh viewport */
            width: 100vw;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center; /* Pusatkan game jika ada sisa ruang */
            image-rendering: pixelated; /* Penting untuk menjaga pixel art tetap tajam */
            image-rendering: -moz-crisp-edges;
            image-rendering: crisp-edges;
        }

        #game-viewport {
            /* Full desktop */
            width: 100vw;
            height: 100vh;
            position: relative;
            overflow: hidden;
            touch-action: none; /* Mencegah tindakan sentuhan default pada viewport game */
            background-color: transparent; /* Langit sudah di body */
            display: none; /* Sembunyikan default, tampilkan setelah game mulai */
        }

        #game-world {
            position: absolute;
            top: 0;
            left: 0;
            /* Lebar dunia game, ini akan lebih lebar dari viewport */
            width: 8000px; /* Diperpanjang kembali dari 5000px */
            height: 100%;
            transition: transform 0.05s linear; /* Animasi smooth pergerakan kamera */
        }

        /* GROUND - Dibuat dengan CSS Murni, mengisi seluruh game-world */
        .ground-brick-segment {
            position: absolute;
            bottom: 0px;
            width: 60px; /* Lebih kecil */
            height: 60px; /* Lebih kecil */
            background-color: #A0522D;
            border: 1px solid #8B4513;
            box-sizing: border-box;
            z-index: 1;
        }
        .ground-grass-top-segment {
            position: absolute;
            bottom: 59px; /* Pas di atas bata, sedikit tumpang tindih (59 = 60-1) */
            width: 60px; /* Lebih kecil */
            height: 12px; /* Lebih kecil */
            background-color: #7CFC00;
            border-top: 1px solid #556B2F;
            box-sizing: border-box;
            z-index: 2;
        }

        /* PLAYER */
        #player {
            width: 106px; /* Diperbesar lagi */
            height: 159px; /* Diperbesar lagi */
            background-image: url('assets/images/mario_stand.png'); /* PASTIKAN FILE INI ADA */
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            position: absolute; /* Posisi relatif ke viewport */
            z-index: 10;
            transition: opacity 0.1s linear;
            transform-origin: center bottom; /* Untuk animasi mendarat dan flip */
        }
        /* Player damage flash */
        #player.invincible {
            animation: player-flash 0.2s steps(1) infinite alternate;
        }
        @keyframes player-flash {
            from { opacity: 1; }
            to { opacity: 0.3; }
        }

        /* CLOUDS - Dibuat dengan CSS Murni */
        .cloud {
            position: absolute;
            background-color: #AECADF; /* Warna biru lebih terang dari langit */
            border-radius: 50%;
            box-shadow: 0 0 0 6px rgba(174,202,223,0.5); /* Sesuaikan shadow dengan warna baru */
            z-index: 0;
        }
        .cloud.type-1 {
            width: 90px; height: 50px; /* Lebih kecil */
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .cloud.type-1::before { content: ''; position: absolute; width: 50px; height: 40px; background-color: inherit; border-radius: 50%; top: -12px; left: 40px; } /* Lebih kecil */
        .cloud.type-1::after { content: ''; position: absolute; width: 70px; height: 45px; background-color: inherit; border-radius: 50%; top: 6px; left: -25px; } /* Lebih kecil */

        .cloud.type-2 {
            width: 100px; height: 60px; /* Lebih kecil */
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .cloud.type-2::before { content: ''; position: absolute; width: 60px; height: 50px; background-color: inherit; border-radius: 50%; top: -15px; left: 50px; } /* Lebih kecil */
        .cloud.type-2::after { content: ''; position: absolute; width: 80px; height: 55px; background-color: inherit; border-radius: 50%; top: 12px; left: -30px; } /* Lebih kecil */

        /* Smoke elements */
        .smoke {
            position: absolute;
            border-radius: 50%;
            filter: blur(3px); /* Memberikan efek asap yang lebih lembut */
            z-index: 1; /* Di atas awan, tapi di bawah objek lain */
            animation: smoke-drift 15s ease-in-out infinite alternate; /* Animasi bergeser perlahan */
        }
        .smoke.type-1 {
            background-color: rgba(180, 200, 210, 0.5); /* Semi-transparan, sangat terang biru-abu */
            width: 120px; height: 70px; /* Lebih kecil */
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .smoke.type-1::before { content: ''; position: absolute; width: 70px; height: 50px; background-color: inherit; border-radius: 50%; top: -18px; left: 50px; } /* Lebih kecil */
        .smoke.type-1::after { content: ''; position: absolute; width: 100px; height: 60px; background-color: inherit; border-radius: 50%; top: 12px; left: -30px; } /* Lebih kecil */

        .smoke.type-2 {
            background-color: rgba(170, 190, 200, 0.5); /* Sedikit lebih gelap dari type-1 */
            width: 150px; height: 90px; /* Lebih kecil */
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
        }
        .smoke.type-2::before { content: ''; position: absolute; width: 90px; height: 70px; background-color: inherit; border-radius: 50%; top: -25px; left: 70px; } /* Lebih kecil */
        .smoke.type-2::after { content: ''; position: absolute; width: 120px; height: 80px; background-color: inherit; border-radius: 50%; top: 20px; left: -40px; } /* Lebih kecil */

        @keyframes smoke-drift {
            0% { transform: translateX(0) scale(1); opacity: 0.5; }
            50% { transform: translateX(20px) scale(1.01); opacity: 0.55; } /* Sedikit disesuaikan */
            100% { transform: translateX(0) scale(1); opacity: 0.5; }
        }

        /* TRASH (menggunakan gambar icon) */
        .trash {
            width: 80px; /* Diperbesar lagi */
            height: 80px; /* Diperbesar lagi */
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            position: absolute;
            z-index: 10;
            transition: opacity 0.3s ease-out, transform 0.3s ease-out; /* Untuk efek saat diambil */
        }
        /* Particle effect for collected trash */
        .collect-particle {
            position: absolute;
            width: 25px; /* Diperbesar lagi */
            height: 25px; /* Diperbesar lagi */
            background-color: #7CFC00; /* Green glow */
            border-radius: 50%;
            opacity: 1;
            transform: scale(0);
            z-index: 11;
            animation: collect-anim 0.5s ease-out forwards;
            pointer-events: none; /* Penting agar tidak menghalangi interaksi */
            box-shadow: 0 0 10px 5px rgba(124, 252, 0, 0.5); /* Diperbesar lagi */
        }
        @keyframes collect-anim {
            0% { transform: scale(0) translateY(0); opacity: 1; }
            50% { transform: scale(1.8) translateY(-40px); opacity: 0.8; } /* Disesuaikan */
            100% { transform: scale(0) translateY(-80px); opacity: 0; } /* Disesuaikan */
        }


        /* BIRDS - Dibuat dengan CSS Murni dan Animasi */
        .bird {
            position: absolute;
            width: 25px; /* Lebih kecil */
            height: 12px; /* Lebih kecil */
            background-color: #333;
            border-radius: 50%;
            z-index: 4;
            animation: fly-wings 1.5s linear infinite alternate;
        }
        .bird::before, .bird::after {
            content: '';
            position: absolute;
            width: 12px; /* Lebih kecil */
            height: 6px; /* Lebih kecil */
            background-color: #333;
            border-radius: 50% 50% 0 0;
            top: 3px; /* Disesuaikan */
        }
        .bird::before { left: -6px; transform: rotate(-30deg); transform-origin: 100% 50%; } /* Disesuaikan */
        .bird::after { right: -6px; transform: rotate(30deg); transform-origin: 0% 50%; } /* Disesuaikan */

        @keyframes fly-wings {
            0% { transform: translateY(0px) scaleY(1); }
            50% { transform: translateY(-3px) scaleY(0.8); } /* Disesuaikan */
            100% { transform: translateY(0px) scaleY(1); }
        }
        .bird.move-across-world {
            animation: bird-move-across-world var(--bird-speed, 20s) linear infinite;
        }
        @keyframes bird-move-across-world {
            from { transform: translateX(0); }
            to { transform: translateX(8000px); } /* Diperpanjang dari 5000px */
        }

        /* --- OBSTACLES --- */
        .obstacle-spikes {
            position: absolute;
            /* bottom: 0px; This property will be set by JS to baseGroundHeight */
            width: 100px; /* Diperbesar lagi */
            height: 60px; /* Diperbesar lagi */
            background-image: url('assets/images/spikes.png');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            z-index: 3;
        }

        /* Puddle element */
        .puddle {
            position: absolute;
            /* bottom: 0px; This property will be set by JS to baseGroundHeight */
            width: 180px; /* Diperbesar lagi */
            height: 25px; /* Diperbesar lagi */
            background-color: #4682B4; /* Warna biru kotor/abu-abu */
            border-radius: 50% / 100% 100% 0 0; /* Bentuk seperti genangan */
            box-shadow: inset 0 -8px 15px rgba(0,0,0,0.3); /* Disesuaikan */
            z-index: 3; /* Di atas ground, tapi di bawah pemain */
        }

        /* Tree element (CSS-drawn) */
        .tree {
            position: absolute;
            /* bottom: 60px; This property will be set by JS to baseGroundHeight */
            width: 90px; /* Tetap sama */
            height: 130px; /* Tetap sama */
            background-color: #8B4513; /* Trunk color (brown) */
            border-radius: 10px; /* Slightly rounded trunk */
            z-index: 5;
            box-sizing: border-box;
        }
        .tree::before { /* Tree top (leaves) */
            content: '';
            position: absolute;
            top: -65px; /* Position above the trunk (adjusted) */
            left: -25px; /* Extend leaves to the left (adjusted) */
            width: 140px; /* Tetap sama */
            height: 110px; /* Tetap sama */
            background-color: #228B22; /* Leaves color (forest green) */
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%; /* Irregular blob shape */
            box-shadow:
                inset 0 0 10px rgba(0,0,0,0.3), /* Inner shadow for depth */
                -12px 6px 0 #32CD32, /* Lighter green blob on left */
                25px 12px 0 #006400; /* Darker green blob on right */
            z-index: 7; /* On top of trunk */
        }
        .tree::after { /* Second blob of leaves for more fullness */
            content: '';
            position: absolute;
            top: -95px; /* Higher up (adjusted) */
            left: 15px; /* Centered on the trunk (adjusted) */
            width: 70px; /* Tetap sama */
            height: 70px; /* Tetap sama */
            background-color: #228B22; /* Same color as main leaves */
            border-radius: 50%;
            box-shadow: inset 0 0 8px rgba(0,0,0,0.3);
            z-index: 8; /* On top of main leaves for layering */
        }

        /* Pipe element */
        .pipe {
            position: absolute;
            /* bottom: 60px; This property will be set by JS to baseGroundHeight */
            width: 100px; /* Dikurangi sedikit lagi */
            height: 140px; /* Dikurangi sedikit lagi */
            background-color: #8B8B8B; /* Grey color for pipe */
            border: 4px solid #696969; /* Dikit lebih besar */
            border-top-left-radius: 10px; /* Dikit lebih besar */
            border-top-right-radius: 10px; /* Dikit lebih besar */
            box-sizing: border-box;
            z-index: 5;
            overflow: visible; /* To allow the top opening to be visible */
        }

        .pipe::before { /* Top opening of the pipe */
            content: '';
            position: absolute;
            top: -18px; /* Extends above the main pipe body (adjusted) */
            left: -8px; /* Wider than the pipe body (adjusted) */
            width: 116px; /* Dikurangi sedikit lagi */
            height: 28px; /* Dikurangi sedikit lagi */
            background-color: #A9A9A9; /* Lighter grey for the opening */
            border: 4px solid #696969; /* Dikit lebih besar */
            border-radius: 10px; /* Dikit lebih besar */
            box-sizing: border-box;
            z-index: 6;
        }

        /* --- MOVING OBJECTS --- */
        .bird-on-ground {
            position: absolute;
            bottom: 60px; /* Di atas ground datar (baseGroundHeight = 60px) */
            width: 40px; /* Lebih kecil */
            height: 25px; /* Lebih kecil */
            background-color: #6B8E23; /* Warna burung darat */
            border-radius: 50%;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center bottom;
            z-index: 6;
            animation: move-across-ground var(--bird-speed, 12s) linear forwards; /* Menggunakan --bird-speed */
        }
        .bird-on-ground::before, .bird::after {
            content: '';
            position: absolute;
            width: 10px; /* Lebih kecil */
            height: 6px; /* Lebih kecil */
            background-color: #4B6F00; /* Warna sayap burung darat */
            border-radius: 50%;
            top: 3px;
        }
        .bird-on-ground::before { left: -3px; transform: rotate(-20deg); } /* Disesuaikan */
        .bird-on-ground::after { right: -3px; transform: rotate(20deg); } /* Disesuaikan */

        .obstacle-car {
            position: absolute;
            bottom: 60px; /* Di atas ground datar (baseGroundHeight = 60px) */
            width: 180px; /* Diperbesar lagi */
            height: 90px; /* Diperbesar lagi */
            background-color: #DC143C; /* Warna mobil (merah) */
            border: 4px solid #8B0000; /* Diperbesar lagi */
            border-radius: 18px; /* Diperbesar lagi */
            box-sizing: border-box;
            z-index: 6;
            animation: move-across-ground var(--move-speed, 15s) linear forwards;
            position: relative;
        }
        .obstacle-car::before, .obstacle-car::after { /* Roda mobil */
            content: '';
            position: absolute;
            bottom: -20px; /* Di bawah badan mobil (adjusted) */
            width: 40px; /* Diperbesar lagi */
            height: 40px; /* Diperbesar lagi */
            background-color: #333;
            border: 3px solid #000; /* Diperbesar lagi */
            border-radius: 50%;
        }
        .obstacle-car::before { left: 20px; } /* Disesuaikan */
        .obstacle-car::after { right: 20px; } /* Disesuaikan */

        /* Finish Pole element */
        .finish-pole {
            position: absolute;
            bottom: 60px; /* Di atas ground datar (baseGroundHeight = 60px) */
            width: 70px; /* Diperbesar lagi */
            height: 280px; /* Diperbesar lagi */
            background-color: #A9A9A9; /* Warna tiang (abu-abu gelap) */
            border: 6px solid #696969; /* Diperbesar lagi */
            border-radius: 10px; /* Sudut lebih membulat */
            z-index: 5;
            display: flex;
            flex-direction: column; /* Untuk menata bendera dan teks vertikal */
            justify-content: flex-start;
            align-items: center;
            padding-top: 25px; /* Disesuaikan */
            box-sizing: border-box;
            box-shadow: 6px 6px 12px rgba(0,0,0,0.5); /* Tambah shadow */
        }
        .finish-pole::before { /* Bendera / Tanda Finish */
            content: 'FINISH!';
            position: absolute;
            top: 12px; /* Disesuaikan */
            left: 70px; /* Di samping tiang, disesuaikan dengan lebar tiang */
            width: 180px; /* Diperbesar lagi */
            height: 80px; /* Diperbesar lagi */
            background-color: #FFD700; /* Warna bendera (emas) */
            border: 6px solid #DAA520; /* Diperbesar lagi */
            font-family: 'Press Start 2P', cursive;
            font-size: 28px; /* Diperbesar lagi */
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 7px 7px 0 rgba(0,0,0,0.7); /* Shadow bendera lebih kuat */
            transform-origin: 0 0;
            animation: flag-wave 1s ease-in-out infinite alternate;
        }

        @keyframes flag-wave {
            0% { transform: rotate(0deg); }
            50% { transform: rotate(8deg); } /* Gelombang lebih kuat */
            100% { transform: rotate(0deg); }
        }

        @keyframes move-across-ground {
            from { transform: translateX(0); }
            to { transform: translateX(8000px); } /* Mengubah arah animasi agar bergerak ke kiri, disesuaikan dengan lebar baru */
        }

        /* Container for Score and Timer */
        #top-left-info-group {
            position: absolute;
            top: 15px; /* Disesuaikan */
            left: 15px; /* Disesuaikan */
            z-index: 100;
            display: flex;
            flex-direction: column; /* Stack score and timer vertically */
            gap: 6px; /* Space between score and timer */
        }

        #score, #timer { /* Apply common styles to both */
            background-color: rgba(255, 255, 255, 0.7);
            padding: 8px 12px; /* Disesuaikan */
            border-radius: 6px; /* Disesuaikan */
            font-weight: bold;
            font-size: 16px; /* Disesuaikan */
            box-sizing: border-box; /* Include padding in width/height */
            white-space: nowrap; /* Prevent text wrapping */
            /* Pixel art border for UI elements */
            border: 2px solid #333; /* Disesuaikan */
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            text-shadow: 1px 1px #fff; /* Disesuaikan */
        }
        /* Outline for score and timer text */
        #score { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }
        #timer { -webkit-text-stroke: 1px #000; text-stroke: 1px #000; color: #333; }


        #message {
            position: absolute;
            top: 15px; /* Adjusted to be slightly lower for better visibility */
            right: 90px; /* Sesuaikan agar tidak tumpang tindih dengan tombol ikon */
            background-color: rgba(0, 0, 0, 0.3); /* Ubah opacity menjadi 0.3 (lebih transparan) */
            color: #FFD700; /* Ubah warna teks menjadi kuning keemasan */
            padding: 8px 12px; /* Disesuaikan */
            border-radius: 6px; /* Disesuaikan */
            font-size: 14px; /* Disesuaikan */
            z-index: 100;
            max-width: calc(100% - 300px); /* Disesuaikan */
            text-align: center; /* Teks di tengah notifikasi */
            opacity: 0; /* Awalnya tersembunyi */
            transition: opacity 0.5s ease-in-out; /* Animasi fade */
            /* Pixel art border for message */
            border: 2px solid #fff; /* Disesuaikan */
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5); /* Disesuaikan */
        }
        #message.show {
            opacity: 1; /* Tampilkan notifikasi */
        }

        /* NEW: Settings Icon Button */
        #settings-button {
            position: absolute;
            top: 15px; /* Disesuaikan */
            right: 15px; /* Disesuaikan */
            transform: translateY(0); /* Remove vertical centering */
            background-color: rgba(255, 255, 255, 0.7);
            width: 45px; /* Disesuaikan */
            height: 45px; /* Disesuaikan */
            border-radius: 8px; /* Disesuaikan */
            font-family: 'Press Start 2P', cursive;
            font-size: 30px; /* Disesuaikan */
            cursor: pointer;
            z-index: 101;
            border: 2px solid #333; /* Disesuaikan */
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            display: flex; /* Untuk memusatkan ikon */
            justify-content: center;
            align-items: center;
            color: #333; /* Warna ikon */
            text-shadow: 1px 1px #fff; /* Disesuaikan */
            transition: background-color 0.1s ease;
            line-height: 1; /* Pastikan ikon terpusat vertikal */
        }
        #settings-button:hover {
            background-color: rgba(255, 255, 255, 0.9);
        }
        #settings-button:active {
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            transform: translateY(1px); /* Adjusted for new position */
        }
        /* Media query for small screens: adjust size and position */
        @media only screen and (max-width: 768px) {
            #settings-button {
                top: 10px; /* Positioned at the top */
                right: 10px; /* Fixed distance from right */
                width: 40px; /* Lebih kecil */
                height: 40px; /* Lebih kecil */
                font-size: 24px; /* Disesuaikan untuk mobile */
                padding: 0;
            }
            #message {
                top: 10px;
                right: 60px; /* Sesuaikan jarak dari tombol ikon */
                max-width: calc(100% - 60px - 10px - 10px - 50px); /* Re-calculate max-width for message */
                font-size: 12px; /* Lebih kecil */
                padding: 5px 8px;
            }
            #top-left-info-group {
                top: 10px;
                left: 10px;
                gap: 5px;
            }
            #score, #timer {
                padding: 6px 10px;
                font-size: 14px; /* Lebih kecil */
            }
        }
        @media only screen and (max-width: 768px) and (orientation: landscape) {
            /* NEW: Landscape adjustments for UI elements */
            #top-left-info-group {
                top: 5px;
                left: 5px;
                gap: 5px;
            }
            #score, #timer {
                padding: 4px 8px; /* Lebih kecil */
                font-size: 12px; /* Lebih kecil */
            }
            #message {
                top: 5px;
                right: 50px; /* Sesuaikan jarak dari tombol ikon */
                max-width: calc(100% - 50px - 10px - 5px - 5px - 50px); /* Re-calculate max-width */
                font-size: 10px; /* Lebih kecil */
                padding: 4px 6px;
            }
            #settings-button {
                top: 5px; /* Positioned at the top */
                right: 5px; /* Fixed distance from right */
                width: 30px; /* Lebih kecil */
                height: 30px; /* Lebih kecil */
                font-size: 20px; /* Disesuaikan untuk landscape mobile */
            }
            #game-over-screen p {
                font-size: 18px; /* Adjusted: Even smaller font for landscape mobile */
                margin-bottom: 15px; /* Adjusted: Reduced margin-bottom */
            }
            #game-over-screen button {
                padding: 8px 16px; /* Adjusted: Even smaller padding for landscape mobile */
                font-size: 14px; /* Adjusted: Even smaller font for landscape mobile */
                margin-top: 10px; /* Adjusted: Reduced margin-top further for landscape */
            }
            #settings-menu h2 {
                font-size: 28px; /* Disesuaikan */
            }
            #settings-menu button {
                padding: 10px 20px; /* Disesuaikan */
                font-size: 16px; /* Disesuaikan */
                margin: 8px 0; /* Disesuaikan */
            }
            #pause-confirm-dialog {
                padding: 10px; /* Disesuaikan */
                font-size: 14px; /* Disesuaikan */
            }
            #pause-confirm-dialog p {
                font-size: 16px; /* Disesuaikan */
                margin-bottom: 10px; /* Disesuaikan */
            }
            #pause-confirm-dialog button {
                padding: 6px 12px; /* Disesuaikan */
                font-size: 14px; /* Disesuaikan */
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
            font-size: 40px; /* Disesuaikan */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 200;
            display: none;
            /* Improved styling for game over screen */
            padding: 20px; /* Disesuaikan */
            box-sizing: border-box;
        }
        #game-over-screen p {
            font-size: 36px; /* Larger font for message */
            margin-bottom: 30px; /* Disesuaikan */
            text-shadow: 4px 4px #000; /* Text shadow for retro feel */
            max-width: 80%; /* Ensure text wraps nicely */
        }
        #game-over-screen button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 25px; /* Adjusted: Smaller padding */
            font-size: 20px; /* Adjusted: Smaller font size */
            border: 6px solid #228B22;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 20px; /* Adjusted: Reduced margin-top */
            font-family: 'Press Start 2P', cursive;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7);
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #game-over-screen button:hover {
            background-color: #5cb85c;
            transform: translateY(-3px); /* Disesuaikan */
            box-shadow: 9px 9px 0 rgba(0,0,0,0.7); /* Disesuaikan */
        }
        #game-over-screen button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 3px 3px 0 rgba(0,0,0,0.7); /* Disesuaikan */
        }

        /* Settings Menu */
        #settings-menu {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9); /* Lebih gelap untuk fokus */
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 250; /* Di atas game over screen */
            display: none; /* Sembunyikan default */
        }
        #settings-menu h2 {
            font-size: 48px; /* Disesuaikan */
            margin-bottom: 50px; /* Disesuaikan */
            text-shadow: 6px 6px #000; /* Disesuaikan */
        }
        #settings-menu button {
            background-color: #007BFF; /* Biru untuk default */
            color: white;
            padding: 18px 40px; /* Disesuaikan */
            font-size: 32px; /* Disesuaikan */
            border: 6px solid #0056b3; /* Disesuaikan */
            border-radius: 12px; /* Disesuaikan */
            cursor: pointer;
            margin: 15px 0; /* Disesuaikan */
            font-family: 'Press Start 2P', cursive;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.7); /* Disesuaikan */
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #settings-menu button:hover {
            background-color: #0056b3;
            transform: translateY(-3px); /* Disesuaikan */
            box-shadow: 9px 9px 0 rgba(0,0,0,0.7); /* Disesuaikan */
        }
        #settings-menu button:active {
            background-color: #004085;
            transform: translateY(0);
            box-shadow: 3px 3px 0 rgba(0,0,0,0.7); /* Disesuaikan */
        }
        /* Style for the Jeda button (new) */
        #settings-menu button#pause-game-button {
            background-color: #FFC107; /* Warna kuning/oranye untuk Jeda */
            border-color: #D39E00;
        }
        #settings-menu button#pause-game-button:hover {
            background-color: #D39E00;
        }
        #settings-menu button#pause-game-button:active {
            background-color: #A07800;
        }
        /* Style when Jeda button becomes Lanjutkan Game */
        #settings-menu button#pause-game-button.resume-state { /* Class baru untuk keadaan Lanjutkan Game */
            background-color: #4CAF50; /* Hijau untuk Lanjutkan Game */
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

        /* NEW: Close Settings Button (X) */
        #close-settings-button {
            position: absolute;
            top: 20px; /* Disesuaikan */
            right: 20px; /* Disesuaikan */
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            width: 50px; /* Disesuaikan */
            height: 50px; /* Disesuaikan */
            border-radius: 50%; /* Bentuk bulat */
            font-size: 36px; /* Disesuaikan */
            font-family: Arial, sans-serif; /* Gunakan font umum untuk 'X' agar konsisten */
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 260; /* Di atas menu */
            border: 2px solid white; /* Disesuaikan */
            box-shadow: 3px 3px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            line-height: 1;
            transition: background-color 0.1s ease;
        }
        #close-settings-button:hover {
            background-color: rgba(255, 255, 255, 0.4);
        }
        #close-settings-button:active {
            box-shadow: 1px 1px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            transform: translateY(2px); /* Disesuaikan */
        }
        /* Adjust for smaller screens */
        @media (max-width: 768px) {
            #close-settings-button {
                width: 40px; /* Lebih kecil */
                height: 40px; /* Lebih kecil */
                font-size: 28px; /* Lebih kecil */
                top: 10px; /* Lebih kecil */
                right: 10px; /* Lebih kecil */
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
            padding: 40px; /* Disesuaikan */
            border-radius: 15px; /* Disesuaikan */
            text-align: center;
            z-index: 300; /* Di atas settings menu */
            display: none;
            border: 8px solid #FFD700; /* Disesuaikan */
            box-shadow: 0 0 20px rgba(255,215,0,0.8); /* Disesuaikan */
            font-size: 30px; /* Disesuaikan */
        }
        #pause-confirm-dialog p {
            margin-bottom: 30px; /* Disesuaikan */
            font-size: 32px; /* Disesuaikan */
            text-shadow: 4px 4px #FFD700; /* Disesuaikan */
        }
        #pause-confirm-dialog .dialog-buttons {
            display: flex;
            justify-content: center;
            gap: 20px; /* Disesuaikan */
        }
        #pause-confirm-dialog button {
            background-color: #4CAF50;
            color: white;
            padding: 15px 30px; /* Disesuaikan */
            font-size: 24px; /* Disesuaikan */
            border: 4px solid #228B22; /* Disesuaikan */
            border-radius: 10px; /* Disesuaikan */
            cursor: pointer;
            font-family: 'Press Start 2P', cursive;
            box-shadow: 4px 4px 0 rgba(0,0,0,0.5); /* Disesuaikan */
            transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        #pause-confirm-dialog button:hover {
            background-color: #5cb85c;
            transform: translateY(-2px); /* Disesuaikan */
            box-shadow: 6px 6px 0 rgba(0,0,0,0.5); /* Disesuaikan */
        }
        #pause-confirm-dialog button:active {
            background-color: #3e8e41;
            transform: translateY(0);
            box-shadow: 2px 2px 0 rgba(0,0,0,0.5); /* Disesuaikan */
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


        /* --- Mobile Controls --- */
        #mobile-controls {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 120px;
            display: flex; /* Changed from none to flex */
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

        /* Hide mobile controls on larger screens */
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
            touch-action: none; /* Mencegah tindakan sentuhan default pada tombol mobile */
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
            font-size: 36px; /* Disesuaikan */
            color: white;
            z-index: 300;
            text-align: center;
        }
        #loading-screen p {
            margin-bottom: 20px; /* Disesuaikan */
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
            <div id="timer">Waktu: 120</div>
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

        // Mobile button declarations (pastikan hanya ada satu deklarasi ini di seluruh skrip)
        const upBtn = document.getElementById('up-btn');
        const leftBtn = document.getElementById('left-btn');
        const rightBtn = document.getElementById('right-btn');

        // Settings Menu Elements
        const settingsButton = document.getElementById('settings-button');
        const settingsMenu = document.getElementById('settings-menu');
        const closeSettingsButton = document.getElementById('close-settings-button');
        const pauseGameButton = document.getElementById('pause-game-button');
        const quitGameButton = document.getElementById('quit-game-button');

        // Pause Confirmation Dialog elements
        const pauseConfirmDialog = document.getElementById('pause-confirm-dialog');
        const pauseConfirmYes = document.getElementById('pause-confirm-yes');
        const pauseConfirmNo = document.getElementById('pause-confirm-no');


        const playerWidth = 106; // Diperbesar lagi
        const playerHeight = 159; // Diperbesar lagi
        const baseGroundHeight = 60; // Adjusted

        let playerXInWorld = 100;
        let playerY = baseGroundHeight;
        let isJumping = false;
        let score = 0;

        const gravity = 1;
        let velocityY = 0;
        let currentCarriedTrash = null;

        const cameraFollowOffset = 300; // Disesuaikan untuk skala lebih besar
        let cameraX = 0;

        let gameTime = 120; // Waktu level 2 adalah 120 detik (2 menit)
        let gameTimerInterval = null;
        let gameActive = false;
        let gamePaused = false;
        let settingsMenuOpen = false;

        let currentWorldTrashCollected = 0;

        const trashTypes = [
            { name: 'plastik', img: 'assets/images/trash_plastik.png', message: 'Botol plastik bisa didaur ulang menjadi serat pakaian atau perabot baru! Kurangi sampah plastik di laut ya.' },
            { name: 'kertas', img: 'assets/images/trash_kertas.png', message: 'Kertas bekasmu bisa jadi buku atau kotak kemasan lagi! Pisahkan dari sampah basah!' },
            { name: 'kaleng', img: 'assets/images/trash_kaleng.png', message: 'Kaleng aluminium dan baja sangat berharga untuk didaur ulang. Hemat energi dan sumber daya alam!' },
            { name: 'kaca', img: 'assets/images/trash_kaca.png', message: 'Kaca bisa didaur ulang berkali-kali tanpa mengurangi kualitas! Pastikan bersih dan tidak pecah ya.' },
            { name: 'organik', img: 'assets/images/trash_organik.png', message: 'Sisa makanan dan daun kering bisa jadi kompos subur untuk tanaman. Jangan dibuang ke TPA!' },
            { name: 'baterai', img: 'assets/images/trash_baterai.png', message: 'Baterai mengandung zat berbahaya! Jangan dibuang sembarangan, kumpulkan di tempat khusus daur ulang.' }
        ];

        // Removed maxCars and maxGroundBirds as per user request
        // let carsSpawned = 0;
        // const maxCars = 2;
        // let groundBirdsSpawned = 0;
        // const maxGroundBirds = 2;
        let obstacleSpawnInterval = null;

        const pressedKeys = {};

        let activeTrash = [];
        let activeMovingObstacles = [];
        let activeLevelElements = [];

        let animationFrameId;

        // --- DEFINISI DUNIA / LEVEL --- (Disalin dari level_2.html asli)
        const worlds = [
            // World 0: Pinggiran Kota Hijau
            {
                name: "Pinggiran Kota Hijau",
                background: '#5A7E9C',
                elements: [
                    { type: 'cloud', class: 'type-1', top: 80, left: 150 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 800 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 1400 },
                    { type: 'cloud', class: 'type-2', top: 160, left: 2000 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 2600 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 3200 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 3800 },
                    { type: 'cloud', class: 'type-2', top: 160, left: 4400 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 5000 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 5600 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 6200 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 6800 },
                    { type: 'cloud', class: 'type-1', top: 110, left: 7400 },

                    { type: 'smoke', class: 'type-1', top: 200, left: 500 },
                    { type: 'smoke', class: 'type-2', top: 100, left: 1000 },
                    { type: 'smoke', class: 'type-1', top: 150, left: 1700 },
                    { type: 'smoke', class: 'type-2', top: 80, left: 2400 },
                    { type: 'smoke', class: 'type-1', top: 220, left: 3000 },
                    { type: 'smoke', class: 'type-2', top: 130, left: 3700 },
                    { type: 'smoke', class: 'type-1', top: 180, left: 4500 },
                    { type: 'smoke', class: 'type-2', top: 90, left: 5200 },
                    { type: 'smoke', class: 'type-1', top: 210, left: 6000 },
                    { type: 'smoke', class: 'type-2', top: 140, left: 6700 },
                    { type: 'smoke', class: 'type-1', top: 160, left: 7500 },

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

                    // Lebih banyak rintangan (total 10-12) - Menambahkan jarak antara pipa dan genangan air
                    { type: 'obstacle-spikes', left: 700 },
                    { type: 'puddle', left: 1300 },
                    { type: 'obstacle-spikes', left: 2000 },
                    { type: 'puddle', left: 2900 }, /* Disesuaikan jarak */
                    { type: 'obstacle-spikes', left: 3700 }, /* Disesuaikan jarak */
                    { type: 'puddle', left: 4600 }, /* Disesuaikan jarak */
                    { type: 'obstacle-spikes', left: 5400 }, /* Disesuaikan jarak */
                    { type: 'puddle', left: 6300 }, /* Disesuaikan jarak */
                    { type: 'obstacle-spikes', left: 7100 }, /* Disesuaikan jarak */
                    { type: 'puddle', left: 7700 }, /* Disesuaikan jarak */


                    { type: 'tree', left: 950 },
                    { type: 'tree', left: 1100 },
                    { type: 'tree', left: 2500 },
                    { type: 'tree', left: 4200 },
                    { type: 'tree', left: 5800 },
                    { type: 'tree', left: 7400 },

                    { type: 'pipe', left: 450 }, /* Disesuaikan jarak */
                    { type: 'pipe', left: 1800 }, /* Disesuaikan jarak */
                    { type: 'pipe', left: 3300 }, /* Disesuaikan jarak */
                    { type: 'pipe', left: 5100 }, /* Disesuaikan jarak */
                    { type: 'pipe', left: 6600 }, /* Disesuaikan jarak */

                    { type: 'finish-pole', left: 7800 }
                ]
            },
            // World 1: Taman Indah (jika digunakan, sesuaikan juga)
            {
                name: "Taman Indah",
                background: '#87CEEB',
                elements: [
                    { type: 'cloud', class: 'type-1', top: 100, left: 200 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 900 },
                    { type: 'cloud', class: 'type-1', top: 120, left: 1800 },
                    { type: 'cloud', class: 'type-2', top: 160, left: 2500 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 3300 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 4000 },
                    { type: 'cloud', class: 'type-1', top: 120, left: 4900 },
                    { type: 'cloud', class: 'type-2', top: 160, left: 5600 },
                    { type: 'cloud', class: 'type-1', top: 100, left: 6400 },
                    { type: 'cloud', class: 'type-2', top: 150, left: 7100 },

                    { type: 'smoke', class: 'type-1', top: 180, left: 300 },
                    { type: 'smoke', class: 'type-2', top: 90, left: 700 },
                    { type: 'smoke', class: 'type-1', top: 140, left: 1500 },
                    { type: 'smoke', class: 'type-2', top: 70, left: 2200 },
                    { type: 'smoke', class: 'type-1', top: 200, left: 2800 },
                    { type: 'smoke', class: 'type-2', top: 110, left: 3500 },
                    { type: 'smoke', class: 'type-1', top: 160, left: 4200 },
                    { type: 'smoke', class: 'type-2', top: 80, left: 5000 },
                    { type: 'smoke', class: 'type-1', top: 190, left: 5700 },
                    { type: 'smoke', class: 'type-2', top: 120, left: 6500 },
                    { type: 'smoke', class: 'type-1', top: 170, left: 7200 },

                    { type: 'tree', left: 500 },
                    { type: 'tree', left: 1300 },
                    { type: 'tree', left: 2800 },
                    { type: 'tree', left: 4500 },
                    { type: 'tree', left: 6200 },

                    { type: 'pipe', left: 800 },
                    { type: 'pipe', left: 2200 },
                    { type: 'pipe', left: 3800 },
                    { type: 'pipe', left: 5400 },
                    { type: 'pipe', left: 7000 },

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

                    { type: 'finish-pole', left: 7800 }
                ]
            }
        ];
        let currentWorldIndex = 0;

        function initializeLevelElements() {
            // Ensure activeLevelElements is an array before trying to iterate
            if (!Array.isArray(activeLevelElements)) {
                activeLevelElements = []; // Initialize if it's not an array
            }

            // Remove existing elements from previous level/reset
            activeLevelElements.forEach(el => {
                // Defensive check to ensure el is an actual DOM element before calling remove
                if (el && typeof el.remove === 'function' && el.parentNode === gameWorld) {
                    el.remove();
                }
            });
            activeLevelElements = []; // Clear the array after removing elements

            document.body.style.backgroundColor = worlds[currentWorldIndex].background;

            worlds[currentWorldIndex].elements.forEach(elDef => { // This 'elDef' is correctly used here
                const element = document.createElement('div');
                element.style.position = 'absolute';

                // Set bottom based on baseGroundHeight for most elements
                if (elDef.type === 'obstacle-spikes' || elDef.type === 'puddle' || elDef.type === 'tree' || elDef.type === 'pipe' || elDef.type === 'finish-pole') {
                    element.style.bottom = baseGroundHeight + 'px';
                } else if (elDef.bottom !== undefined) {
                    element.style.bottom = elDef.bottom + 'px';
                } else if (elDef.top !== undefined) {
                    element.style.top = elDef.top + 'px';
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
                } else if (elDef.type === 'tree') {
                    element.classList.add('tree');
                    element.dataset.platform = 'true';
                }
                else if (elDef.type === 'pipe') {
                    element.classList.add('pipe');
                    element.dataset.platform = 'true'; // Pipes should also be solid platforms
                    if (elDef.height) element.style.height = elDef.height + 'px'; // Allow custom height for pipes
                }
                else if (elDef.type === 'finish-pole') { // Corrected from el.type to elDef.type
                    element.classList.add('finish-pole');
                    element.dataset.finish = 'true';
                }
                gameWorld.appendChild(element);
                activeLevelElements.push(element);
            });
        }

        function createGround() {
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            const worldWidth = gameWorld.offsetWidth;
            const segmentWidth = 60; // Adjusted
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
                // Menggunakan gambar terpisah untuk kiri dan kanan
                imgPath = playerDirection === 'right' ? 'assets/images/mario_walk_right_1.png' : 'assets/images/mario_walk_left_1.png';
            } else { // stand
                imgPath = 'assets/images/mario_stand.png';
            }
            player.style.backgroundImage = `url('${imgPath}')`;
            // Tidak perlu membalik jika sudah menggunakan gambar terpisah
            player.style.transform = `scaleY(1) scaleX(1)`;
        }

        function playLandingAnimation() {
            // Sesuaikan transform dengan tanpa scaleX(-1) jika menggunakan gambar terpisah
            player.style.transform = `scaleY(0.9) scaleX(1)`;
            setTimeout(() => {
                player.style.transform = `scaleY(1) scaleX(1)`;
            }, 100);
        }

        function updatePlayerAndCameraPosition() {
            // This needs to use the playerXInWorld variable, not calculate it from offsetLeft
            // as offsetLeft is relative to the *positioned parent*, which is the viewport.
            // playerXInWorld is the true position in the game world.

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
                velocityY = 24; // Mengembalikan nilai ke 24 (atau bisa mencoba 20, 22, dll. untuk ketinggian yang berbeda)
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
            // This function is now a no-op as per user request to remove moving obstacles
            // You can add other types of moving obstacles here if needed in the future.
            // The existing `bird-flying` elements are static CSS animations, not spawned here.
            return;
        }

        let initialGroundAndLevelSetupDone = false;

        function gameLoop() {
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

            const playerMoveSpeed = 7; // Mengembalikan nilai ke 7

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
            document.getElementById('mobile-controls').style.display = 'none'; // Sembunyikan kontrol mobile saat menu settings muncul
            settingsButton.style.display = 'none';
            // Waktu game tidak berhenti saat hanya membuka pengaturan
        }

        function hideSettingsMenu() {
            settingsMenuOpen = false;
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none'; // Pastikan dialog konfirmasi juga tersembunyi
            document.getElementById('mobile-controls').style.display = 'flex'; // Tampilkan kembali kontrol mobile
            settingsButton.style.display = 'block';
            // Waktu game tidak berhenti saat hanya menutup pengaturan
        }

        // Fungsi yang dipanggil saat user KONFIRMASI jeda (klik "Yes" di dialog)
        function confirmPause() {
            if (!gameActive || gamePaused) return; // Pastikan game aktif dan belum dijeda
            gamePaused = true;
            clearInterval(gameTimerInterval); // Hentikan timer
            gameTimerInterval = null; // Penting: Setel ke null agar bisa dimulai lagi dengan benar
            if (obstacleSpawnInterval) { // Check if interval exists before clearing
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
            // startMovingObstacleSpawning(); // Removed as no moving obstacles are spawned now
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

            // Periksa tombol panah dan tombol A/D untuk gerakan horizontal
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
            if (!gameActive || gamePaused || settingsMenuOpen) return;
            // Periksa tombol panah dan tombol A/D untuk gerakan horizontal
            if (e.code === 'ArrowRight' || e.key === 'd') {
                pressedKeys['ArrowRight'] = false;
            } else if (e.code === 'ArrowLeft' || e.key === 'a') {
                pressedKeys['ArrowLeft'] = false;
            }

            // Ini tidak perlu disetel false untuk lompatan, karena lompatan adalah event sesaat.
            // if (e.key === 'w') pressedKeys['w'] = false;
        });

        // --- Mobile Controls Event Listeners ---
        // Mobile button declarations (pastikan hanya ada satu deklarasi ini di seluruh skrip)
        // Dideklarasikan di bagian paling atas skrip.

        // Re-adding mobile button event listeners
        if (rightBtn) { // Add defensive check
            rightBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                if (!gamePaused && !settingsMenuOpen) pressedKeys['ArrowRight'] = true;
            });
            rightBtn.addEventListener('touchend', (e) => {
                e.preventDefault();
                pressedKeys['ArrowRight'] = false;
            });
        }

        if (leftBtn) { // Add defensive check
            leftBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                if (!gamePaused && !settingsMenuOpen) pressedKeys['ArrowLeft'] = true;
            });
            leftBtn.addEventListener('touchend', (e) => {
                e.preventDefault();
                pressedKeys['ArrowLeft'] = false;
            });
        }

        if (upBtn) { // Add defensive check
            upBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                jump();
            });
        }


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
            const randomSizeFactor = 0.8 + (Math.random() * 0.2); // Adjusted for larger base size
            trash.style.width = `${80 * randomSizeFactor}px`; // Adjusted base size
            trash.style.height = `${80 * randomSizeFactor}px`;


            let spawnX;
            const viewportWidth = gameViewport.offsetWidth;

            if (xPos !== null) {
                spawnX = xPos;
            } else {
                if (viewportWidth === 0) {
                    console.warn("gameViewport.offsetWidth is 0, cannot calculate spawnX for trash.");
                    return;
                }

                spawnX = cameraX + viewportWidth + 80 + (Math.random() * (gameWorld.offsetWidth - (cameraX + viewportWidth + 80) - trash.offsetWidth - 30)); // Adjusted padding
                spawnX = Math.min(spawnX, gameWorld.offsetWidth - trash.offsetWidth - 30); // Adjusted padding
                spawnX = Math.max(spawnX, cameraX + viewportWidth / 2);
            }

            // Always make trash floating
            let trashBottomY = Math.floor(Math.random() * (400 - 250 + 1)) + 250; // Disesuaikan agar lompatan bisa meraihnya, dan di atas ground/platform

            const platformsAndObstacles = [...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('pipe') && !el.classList.contains('tree') && !el.classList.contains('smoke')), ...activeMovingObstacles];
            let isOverlapping = false;
            let attempts = 0;
            const maxAttempts = 50;
            const spawnPadding = 50; // Adjusted padding

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
                            elemWorldY = baseGroundHeight; // Adjusted for new ground height
                            elemHeight = parseInt(elem.style.height); // Use actual height for collision
                        }
                        else if (elem.classList.contains('puddle')) {
                            elemWorldY = baseGroundHeight; // Adjusted for new ground height
                            elemHeight = parseInt(elem.style.height);
                        }
                        else if (elem.classList.contains('tree') || elem.classList.contains('pipe')) {
                            elemHeight = parseInt(elem.style.height);
                        }


                        if (tempTrashLeft < elemWorldX + elemWidth + spawnPadding &&
                            tempTrashRight > elemWorldX - spawnPadding &&
                            tempTrashBottom < elemWorldY + elemHeight + spawnPadding &&
                            tempTrashTop > elemWorldY - spawnPadding) {

                            isOverlapping = true;
                            attempts++;
                            spawnX = cameraX + viewportWidth + 80 + (Math.random() * 1500); // Adjusted random range
                            spawnX = Math.min(spawnX, gameWorld.offsetWidth - trash.offsetWidth - 50);
                            spawnX = Math.max(spawnX, cameraX + viewportWidth / 2);
                            trashBottomY = Math.floor(Math.random() * (400 - 250 + 1)) + 250; // Disesuaikan agar lompatan bisa meraihnya, dan di atas ground/platform
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
                    createCollectParticle(trashXInViewport + trash.offsetWidth / 2, trashWorldY + trash.offsetHeight / 2);


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


            const allCollidableElements = [...activeLevelElements.filter(el => !el.classList.contains('cloud') && !el.classList.contains('smoke')), ...activeMovingObstacles];
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

                    const collisionToleranceX = 25; // Diperbesar lagi
                    const collisionToleranceY = 12; // Diperbesar lagi

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
                // For trees and pipes, the top surface is the full height of the element
                if (platform.classList.contains('tree') || platform.classList.contains('pipe')) {
                    platformTopSurfaceY = platformWorldY + platform.offsetHeight;
                }


                // Kolisi samping dengan platform (untuk mencegah melewati platform padat)
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

                    // Check for horizontal collision
                    if (playerRect.bottom < platformRectWorld.top && playerRect.top > platformRectWorld.bottom) {
                        const playerMoveSpeed = 7; // Mengembalikan nilai ke 7

                        // If moving right and hitting left side of platform
                        if (playerRect.right > platformRectWorld.left && playerRect.left < platformRectWorld.left) {
                            playerXInWorld = platformRectWorld.left - playerWidth;
                            pressedKeys['ArrowRight'] = false;
                            pressedKeys['d'] = false;
                        }
                        // If moving left and hitting right side of platform
                        else if (playerRect.left < platformRectWorld.right && playerRect.right > platformRectWorld.right) {
                            playerXInWorld = platformRectWorld.right; /* Corrected: player should be pushed to the right of the platform */
                            pressedKeys['ArrowLeft'] = false;
                            pressedKeys['a'] = false;
                        }
                    }
                }

                // Logika pendaratan dan head-bump
                if (platform.dataset.platform === 'true') {
                    // Jika pemain sedang jatuh dan mendarat di platform
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
                    // Jika pemain sedang melompat ke atas dan menabrak bagian bawah platform
                    else if (velocityY > 0) {
                        const playerTopY = playerCurrentY + playerHeight;
                        const playerPreviousTopY = playerPreviousY + playerHeight;

                        const toleranceX = 15; // Diperbesar lagi
                        if (playerXInWorld + playerWidth - toleranceX > platformWorldX &&
                            playerXInWorld + toleranceX < platformWorldX + platformWidth &&
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
            activeLevelElements = []; // Clear the array after removing elements //
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove()); //

            document.getElementById('mobile-controls').style.display = 'none'; // Sembunyikan kontrol mobile saat game over //
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
                    statusMessage = `Game Dihentikan. Kamu mengumpulkan ${score} sampah. Coba lagi!`; //
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
                // Dapatkan ID level dari URL jika ada, atau default ke 2 untuk level ini
                const urlParams = new URLSearchParams(window.location.search); //
                const currentLevel = urlParams.get('level'); // PENTING: Default ke 2 di sini! //
                const userId = <?php echo $userId; ?>; // Ambil user_id dari variabel PHP //


                const response = await fetch(window.location.href, { // Mengirim POST ke file level_2.php ini sendiri //
                    method: 'POST', //
                    headers: { //
                        'Content-Type': 'application/x-www-form-urlencoded', //
                    },
                    // user_id akan diganti dengan ID pengguna aktual dari sesi/login
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
            activeLevelElements = []; // Clear the array after removing elements
            document.querySelectorAll('.ground-brick-segment, .ground-grass-top-segment').forEach(el => el.remove());

            playerXInWorld = 100;
            playerY = baseGroundHeight;
            isJumping = false;
            score = 0;
            gameTime = 120; // Waktu level 2 adalah 120 detik (2 menit)
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

            currentWorldIndex = 0;

            initializeLevelElements();
            createGround();
            updatePlayerAnimation();

            // Removed ground birds and cars as they are no longer obstacles
            // carsSpawned = 0;
            // groundBirdsSpawned = 0;
            // nextObstacleType = 'ground-bird';

            scoreDisplay.textContent = 'Skor: ' + score;
            timerDisplay.textContent = 'Waktu: ' + gameTime;
            showMessage(`Selamat datang di Go Green Hero! Kumpulkan sampah dan raih finish!`);
            player.style.display = 'block';

            gameOverScreen.style.display = 'none';
            settingsMenu.style.display = 'none';
            pauseConfirmDialog.style.display = 'none';
            document.getElementById('mobile-controls').style.display = 'flex'; // Pastikan mobile controls tampil saat reset
            settingsButton.style.display = 'block';

            startGameTimer();
            // Removed startMovingObstacleSpawning() as no moving obstacles are spawned now
            // startMovingObstacleSpawning();

            // Anda bisa menyesuaikan jumlah sampah untuk level 2 di sini
            const numberOfTrashItems = 35; // Mengubah jumlah sampah menjadi 35
            const finishPoleX = 7800; // X coordinate of the finish pole (assuming it's fixed)
            // Calculate spawn area to distribute trash evenly before the finish pole
            const spawnAreaEnd = finishPoleX - 200; // End 200px before finish pole
            const spawnAreaStart = 150; // Start 150px into the level
            const availableSpawnRange = spawnAreaEnd - spawnAreaStart;
            const approximateSpacing = availableSpawnRange / numberOfTrashItems;

            for (let i = 0; i < numberOfTrashItems; i++) {
                // Distribute trash with some randomness around the approximate spacing
                const xPos = spawnAreaStart + (i * approximateSpacing) + (Math.random() * (approximateSpacing * 0.5) - (approximateSpacing * 0.25));
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
            // This function is now empty as no more moving obstacles (cars, ground birds) are spawned.
            // If you want to add new types of moving obstacles, re-implement this function.
            // The existing `bird-flying` elements are static CSS animations, not spawned here.
        }

        const imageAssets = [
            'assets/images/mario_stand.png',
            'assets/images/mario_jump.png',
            'assets/images/mario_walk_right_1.png',
            'assets/images/mario_walk_left_1.png', // Pastikan file ini ada dan menghadap ke kiri
            'assets/images/trash_plastik.png',
            'assets/images/trash_kertas.png',
            'assets/images/trash_kaleng.png',
            'assets/images/trash_kaca.png',
            'assets/images/trash_organik.png',
            'assets/images/trash_baterai.png',
            'assets/images/spikes.png'
        ];
        const audioAssets = [];
    
        let assetsLoadedCount = 0;
        const totalAssets = imageAssets.length + audioAssets.length;

        function assetLoaded() {
            assetsLoadedCount++;
            if (assetsLoadedCount >= totalAssets) {
                console.log("All assets loaded. Starting game.");
                loadingScreen.style.display = 'none';
                resetGame(true);
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
                    assetLoaded();
                };
                img.src = src;
            });

            audioAssets.forEach(src => {
                const audio = new Audio();
                audio.addEventListener('canplaythrough', assetLoaded, { once: true });
                audio.onerror = (e) => {
                    console.error(`Gagal memuat audio: ${src}`, e);
                    assetLoaded();
                };
                audio.src = src;
            });
        }

        preloadAssets();

        /* --- JavaScript End --- */
    </script>
</body>
</html>