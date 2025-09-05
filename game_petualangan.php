<?php
// game_petualangan.php

// Pastikan sesi dimulai di sini sebagai baris PHP pertama
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include helper functions first as they contain session_start() logic
require_once 'helpers.php';

// Include database connection (which also ensures session_start() via helper)
require_once 'db_connection.php';

// Fungsi untuk mendapatkan data pengguna
function getUserData($conn, $userId) {
    // Menggunakan 'full_name' dari tabel users, bukan 'name'
    // --- START: MODIFIED TO INCLUDE profile_picture ---
    $stmt = $conn->prepare("SELECT full_name, email, total_points, profile_picture FROM users WHERE id = ?");
    // --- END: MODIFIED TO INCLUDE profile_picture ---
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    return $userData;
}

// Fungsi untuk mendapatkan riwayat poin
function getPointsHistory($conn, $userId) {
    // Menggunakan 'points_amount' dan 'transaction_date' dari tabel points_history
    $stmt = $conn->prepare("SELECT description, points_amount, transaction_date FROM points_history WHERE user_id = ? ORDER BY transaction_date DESC LIMIT 10");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $pointsHistory = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $pointsHistory;
}

// Fungsi untuk mendapatkan riwayat permainan
// Asumsi 'game_history' di sini sebenarnya merujuk pada 'game_scores'
function getGameHistory($conn, $userId) {
    // Menggunakan tabel game_scores karena tidak ada tabel game_history di dump SQL
    $stmt = $conn->prepare("SELECT game_name AS description, played_at AS completed_at FROM game_scores WHERE user_id = ? ORDER BY played_at DESC LIMIT 10");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $gameHistory = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $gameHistory;
}

// NEW: Fungsi untuk mendapatkan jumlah game yang dimenangkan
function getWonGamesCount($conn, $userId) {
    // Asumsi: game dianggap 'menang' jika score > 0. Sesuaikan kondisi ini dengan logika game Anda.
    $stmt = $conn->prepare("SELECT COUNT(*) AS won_games_count FROM game_scores WHERE user_id = ? AND score > 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data['won_games_count'];
}


// --- PHP Logic for data fetching ---
// Pastikan user sudah login
if (!is_logged_in()) {
    // Redirect ke halaman login jika user belum login
    // set_flash_message('error', 'Anda harus login untuk mengakses halaman ini.');
    redirect('login.php'); // Ganti 'login.php' dengan halaman login Anda
}

$userId = $_SESSION['user_id']; // Mengambil user ID dari sesi


// Dapatkan User ID untuk digunakan di JavaScript
$currentUserId = is_logged_in() ? $_SESSION['user_id'] : 'null'; // 'null' jika tidak login


// Fetch user data
$userData = getUserData($conn, $userId);
if (!$userData) {
    // Handle case where user data not found (e.g., user_id in session is invalid)
    // set_flash_message('error', 'Data pengguna tidak ditemukan. Silakan login kembali.');
    redirect('logout.php'); // Atau redirect ke halaman login
}

// Menggunakan 'full_name' yang sudah dikoreksi dari database
$userName = htmlspecialchars($userData['full_name']);
$userEmail = htmlspecialchars($userData['email']);
$totalPoints = htmlspecialchars($userData['total_points']);
// --- START: Retrieve profile_picture from user data ---
$profilePicture = htmlspecialchars($userData['profile_picture']); // Get the profile picture path
// --- END: Retrieve profile_picture from user data ---

// Fetch points history
$pointsHistory = getPointsHistory($conn, $userId);

// Fetch game history
$gameHistory = getGameHistory($conn, $userId);

// NEW: Fetch won games count
$wonGamesCount = getWonGamesCount($conn, $userId);

// Close the database connection (from db_connection.php)
$conn->close();

// Ambil pesan kilat jika ada
$flashMessage = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako: Eco Learning Game</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <style>
        /* Your CSS styles go here */
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
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-image: url('assets/images/bg1.png');
            background-size: cover;
            background-repeat: no-repeat;
            background-position: center center;
            background-attachment: fixed;
            color: var(--text-color-light);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 5%;
            background-color: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(5px);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .logo h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: 700;
            color: var(--text-color-light);
        }

        .logo p {
            margin: -5px 0 0 0;
            font-size: 0.8em;
            font-weight: 300;
            opacity: 0.8;
        }

        .header-buttons {
            display: flex; /* Make buttons align in a row */
            gap: 10px; /* Space between buttons */
            align-items: center;
        }

        .home-btn {
            background-color: var(--secondary-color);
            color: var(--text-color-dark);
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .home-btn:hover {
            background-color: #8CCF8C;
            box-shadow: var(--shadow-light);
            transform: translateY(-2px);
        }
        .home-btn:active {
            transform: translateY(2px);
            box-shadow: 0 1px 5px rgba(0,0,0,0.2);
            transition: all 0.1s ease-in-out;
        }

        /* Styles for the User Profile Display (NOT a button) */
        .user-profile-display {
            background-color: var(--secondary-color);
            color: var(--text-color-dark);
            border: none; /* No border for static display */
            padding: 8px 15px;
            border-radius: 25px;
            cursor: default; /* Not clickable */
            font-size: 0.95em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-soft);
            /* Remove hover/active effects */
            transition: none; /* No transition for static element */
        }

        .user-profile-display .avatar {
            width: 35px;
            height: 35px;
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            flex-shrink: 0; /* Prevent avatar from shrinking */
        }

        .user-profile-display .avatar span {
            font-size: 24px;
            color: var(--text-color-dark);
        }
        /* --- NEW: Style for actual profile image --- */
        .user-profile-display .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        /* --- END NEW --- */

        .user-profile-display .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.2;
        }

        .user-profile-display .user-info .user-name {
            font-size: 0.9em;
            font-weight: 700;
            white-space: nowrap;
        }

        .user-profile-display .user-info .user-points {
            font-size: 0.8em;
            font-weight: 400;
            color: #555;
        }

        /* Flash Message Styles */
        .flash-message {
            padding: 15px 20px;
            margin: 20px auto;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            max-width: 600px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            position: relative;
            animation: fadeIn 0.5s ease-out;
        }

        .flash-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .flash-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .flash-message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .flash-message .close-flash {
            position: absolute;
            top: 8px;
            right: 12px;
            cursor: pointer;
            font-size: 1.2em;
            font-weight: bold;
            color: inherit;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .flash-message .close-flash:hover {
            opacity: 1;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-section {
            text-align: center;
            padding: 80px 5%;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-image: linear-gradient(to bottom, rgba(0,0,0,0.05), rgba(0,0,0,0.3));
            background-size: cover;
            background-position: center center;
        }

        .hero-section h1 {
            font-size: 3.5em;
            margin-bottom: 10px;
            font-weight: 700;
            color: var(--text-color-light);
            text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.8);
        }

        .hero-section h2 {
            font-size: 1.5em;
            font-weight: 400;
            opacity: 0.9;
            max-width: 800px;
            margin-bottom: 40px;
            text-shadow: 1px 1px 4px rgba(0, 0, 0, 0.7);
        }

        .hero-section p {
            font-size: 1.2em;
            font-weight: 600;
            margin-bottom: 20px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }

        .level-description {
            font-size: 1em;
            opacity: 0.8;
            max-width: 600px;
            margin-top: 10px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }

        .level-cards-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-top: 40px;
            width: 100%;
            max-width: 1200px;
            flex-direction: row;
        }

        .level-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 15px 20px;
            width: 120px;
            text-align: center;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, color 0.3s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: unset;
        }

        .level-card:hover {
            transform: translateY(-10px) scale(1.02);
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            color: var(--secondary-color);
        }
        .level-card.active {
            border: 4px solid var(--accent-green);
            background: rgba(255, 255, 255, 0.35);
            transform: translateY(-5px) scale(1.01);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
            color: var(--secondary-color);
        }
        .level-card.active .icon {
            color: var(--accent-green-dark);
        }

        .level-card .icon {
            font-size: 2em;
            margin-bottom: 5px;
            color: var(--secondary-color);
            transition: color 0.3s ease;
        }

        .level-card h3 {
            margin: 0;
            font-size: 1em;
            font-weight: 600;
        }

        .features-section {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 30px;
            padding: 60px 5%;
            background: rgba(0, 0, 0, 0.4);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .feature-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            flex: 1 1 300px;
            max-width: 380px;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease;
        }

        .feature-box:hover {
            transform: translateY(-5px);
        }

        .feature-box .icon {
            font-size: 3.5em;
            margin-bottom: 15px;
            color: var(--secondary-color);
        }

        .feature-box h3 {
            font-size: 1.4em;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .feature-box p {
            font-size: 0.95em;
            opacity: 0.8;
        }

        .stats-section {
            background-color: rgba(0, 0, 0, 0.5);
            padding: 40px 5%;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stats-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 40px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .stat-item {
            flex: 1 1 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .stat-item .value {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 5px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        .stat-item .label {
            font-size: 0.9em;
            opacity: 0.8;
        }

        footer {
            text-align: center;
            padding: 20px;
            font-size: 0.8em;
            opacity: 0.7;
            background-color: rgba(0, 0, 0, 0.5);
            margin-top: auto;
        }

        /* Integrated Profile Section Styles */
        #integratedProfileSection {
            display: none; /* Always hidden now */
            margin: 20px auto;
            width: 90%;
            max-width: 1000px;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 25px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5);
            color: var(--text-color-light);
            padding: 25px;
            box-sizing: border-box;
            opacity: 0;
            transform: translateY(-20px);
            transition: opacity 0.4s ease-out, transform 0.4s ease-out;
            position: relative;
            z-index: 999;
        }

        #integratedProfileSection.show {
            display: flex;
            opacity: 1;
            transform: translateY(0);
        }

        #integratedProfileSection .close-button-inline {
            position: absolute;
            top: 15px;
            right: 20px;
            color: var(--text-color-light);
            font-size: 2em;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease, transform 0.2s ease;
            z-index: 10;
        }

        #integratedProfileSection .close-button-inline:hover {
            color: var(--secondary-color);
            transform: rotate(90deg);
        }

        #integratedProfileSection .profile-body {
            display: flex;
            flex-direction: column;
            width: 100%;
            gap: 25px;
        }

        #integratedProfileSection .profile-info-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            box-shadow: var(--shadow-soft);
            text-align: center;
            flex-shrink: 0;
        }

        .profile-pic { /* Adjusted for reusability with main display */
            width: 100px;
            height: 100px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            border: 4px solid var(--secondary-color);
            box-shadow: 0 0 0 5px rgba(255, 255, 255, 0.3);
            flex-shrink: 0; /* Prevent avatar from shrinking */
        }
        .profile-pic img { /* Style for the actual image inside .profile-pic */
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-pic span { /* Style for the fallback icon */
            font-size: 60px;
            color: var(--text-color-light);
        }


        #integratedProfileSection .profile-details p {
            margin: 5px 0;
            font-size: 1.1em;
            color: var(--text-color-light);
        }

        #integratedProfileSection .profile-details strong {
            color: var(--secondary-color);
            font-weight: 600;
        }

        #integratedProfileSection .history-sections-wrapper {
            display: flex;
            flex-direction: column;
            gap: 20px;
            flex-grow: 1;
            width: 100%;
        }

        #integratedProfileSection .history-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--shadow-soft);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #integratedProfileSection .history-section h3 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.6em;
            color: var(--secondary-color);
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 8px;
            flex-shrink: 0;
        }

        #integratedProfileSection .history-list {
            list-style: none;
            padding: 0;
            overflow-y: auto;
            flex-grow: 1;
            padding-right: 5px;
            max-height: 200px;
        }

        #integratedProfileSection .history-list::-webkit-scrollbar {
            width: 8px;
        }
        #integratedProfileSection .history-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        #integratedProfileSection .history-list::-webkit-scrollbar-thumb {
            background: var(--accent-green);
            border-radius: 10px;
        }
        #integratedProfileSection .history-list::-webkit-scrollbar-thumb:hover {
            background: var(--accent-green-dark);
        }

        #integratedProfileSection .history-list li {
            background-color: rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px 18px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.98em;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.2s ease, background-color 0.2s ease;
            color: var(--text-color-light);
        }

        #integratedProfileSection .history-list li:last-child {
            margin-bottom: 0;
        }

        #integratedProfileSection .history-list li:hover {
            transform: translateX(5px);
            background-color: rgba(255, 255, 255, 0.15);
        }

        #integratedProfileSection .history-list li small {
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.8);
        }

        /* Responsive adjustments for larger screens (desktop/tablet landscape) */
        @media (min-width: 769px) {
            #integratedProfileSection {
                flex-direction: row;
                padding: 30px;
            }
            #integratedProfileSection .profile-body {
                flex-direction: row;
                gap: 30px;
            }
            #integratedProfileSection .profile-info-section {
                flex-basis: 40%;
                margin-right: 0;
            }
            #integratedProfileSection .history-sections-wrapper {
                flex-basis: 60%;
                flex-direction: column;
            }
        }


        /* Mobile Portrait (Max-width 768px, typical for phones/small tablets in portrait) */
        @media (max-width: 768px) and (orientation: portrait) {
            header {
                flex-direction: column;
                gap: 15px;
                padding: 15px 5%;
            }

            .logo {
                align-items: center;
            }

            .logo h1 {
                font-size: 1.8em;
            }

            .header-buttons {
                flex-direction: column;
                width: 100%;
            }

            .home-btn, .user-profile-display {
                width: 100%;
                justify-content: center;
            }

            #integratedProfileSection {
                width: 95%;
                padding: 20px;
                margin-top: 15px;
            }

            #integratedProfileSection .profile-body {
                flex-direction: column;
                gap: 20px;
            }

            /* .profile-pic (shared style) */
            .profile-pic {
                width: 90px;
                height: 90px;
            }
            .profile-pic span {
                font-size: 60px;
            }

            #integratedProfileSection .history-sections-wrapper {
                gap: 15px;
            }

            #integratedProfileSection .history-section h3 {
                font-size: 1.4em;
            }

            #integratedProfileSection .history-list {
                max-height: 150px;
            }
        }

        /* Mobile Landscape (Max-width 768px, and in landscape orientation) */
        @media (max-width: 768px) and (orientation: landscape) {
            header {
                flex-direction: row;
                padding: 10px 3%;
                gap: 10px;
            }

            .logo h1 {
                font-size: 1.5em;
            }

            .header-buttons {
                flex-direction: row;
                gap: 10px;
            }

            .home-btn, .user-profile-display {
                width: auto;
                padding: 8px 15px;
                font-size: 0.9em;
            }

            .user-profile-display .avatar {
                width: 30px;
                height: 30px;
            }
            .user-profile-display .avatar span {
                font-size: 20px;
            }
            .user-profile-display .user-info .user-name {
                font-size: 0.8em;
            }
            .user-profile-display .user-info .user-points {
                font-size: 0.7em;
            }

            #integratedProfileSection {
                width: 95%;
                padding: 15px;
                margin-top: 10px;
                flex-direction: row;
            }

            #integratedProfileSection .profile-body {
                flex-direction: row;
                gap: 15px;
            }
            #integratedProfileSection .profile-info-section {
                flex-basis: 35%;
                padding: 15px;
            }
            /* .profile-pic (shared style) */
            .profile-pic {
                width: 70px;
                height: 70px;
            }
            .profile-pic span {
                font-size: 45px;
            }
            .profile-details p {
                font-size: 0.85em;
            }

            #integratedProfileSection .history-sections-wrapper {
                flex-basis: 65%;
                gap: 10px;
            }
            #integratedProfileSection .history-section {
                padding: 12px;
            }
            #integratedProfileSection .history-section h3 {
                font-size: 1.1em;
            }
            #integratedProfileSection .history-list {
                max-height: 120px;
                font-size: 0.75em;
            }
            #integratedProfileSection .history-list li {
                padding: 6px 10px;
            }
        }

        /* Very small screens (e.g., iPhone 5/SE in portrait) */
        @media (max-width: 375px) and (orientation: portrait) {
            .logo h1 {
                font-size: 1.6em;
            }
            .home-btn, .user-profile-display {
                padding: 8px 15px;
                font-size: 0.9em;
            }
            .hero-section h1 {
                font-size: 1.8em;
            }
            .hero-section h2 {
                font-size: 0.9em;
            }
            .level-card {
                width: 75px;
                padding: 8px 10px;
            }
            .level-card .icon {
                font-size: 1.6em;
            }
            .level-card h3 {
                font-size: 0.8em;
            }

            /* .profile-pic (shared style) */
            .profile-pic {
                width: 80px;
                height: 80px;
            }
            .profile-pic span {
                font-size: 50px;
            }
            #integratedProfileSection .profile-details p {
                font-size: 1em;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">
            <h1>GoRako</h1>
            <p>Eco Learning Game</p>
        </div>
        <div class="header-buttons">
            <a href="index.php" class="home-btn">
                <span class="material-symbols-outlined">home</span>
                Beranda
            </a>
            <div class="user-profile-display">
                <div class="avatar">
                    <?php if (!empty($profilePicture)): ?>
                        <img src="<?php echo $profilePicture; ?>" alt="Profil <?php echo $userName; ?>">
                    <?php else: ?>
                        <span class="material-symbols-outlined">person</span>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?php echo $userName; ?></span>
                    <span class="user-points"><?php echo $totalPoints; ?> Poin</span>
                </div>
            </div>
        </div>
    </header>

    <main>
        <?php if ($flashMessage): ?>
            <div class="flash-message <?php echo htmlspecialchars($flashMessage['type']); ?>">
                <?php echo htmlspecialchars($flashMessage['message']); ?>
                <span class="close-flash">&times;</span>
            </div>
        <?php endif; ?>

        <section id="integratedProfileSection">
            <span class="close-button-inline">&times;</span>
            <div class="profile-body">
                <div class="profile-info-section">
                    <div class="profile-pic">
                        <?php if (!empty($profilePicture)): ?>
                            <img src="<?php echo $profilePicture; ?>" alt="Profil <?php echo $userName; ?>">
                        <?php else: ?>
                            <span class="material-symbols-outlined">person</span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-details">
                        <p><strong>Nama:</strong> <?php echo $userName; ?></p>
                        <p><strong>Email:</strong> <?php echo $userEmail; ?></p>
                        <p><strong>Total Poin:</strong> <?php echo $totalPoints; ?></p>
                    </div>
                </div>

                <div class="history-sections-wrapper">
                    <div class="history-section">
                        <h3>Riwayat Poin Didapat</h3>
                        <ul class="history-list">
                            <?php if (!empty($pointsHistory)): ?>
                                <?php foreach ($pointsHistory as $entry): ?>
                                    <li><?php echo htmlspecialchars($entry['description']); ?>: +<?php echo htmlspecialchars($entry['points_amount']); ?> <small> (<?php echo htmlspecialchars($entry['transaction_date']); ?>)</small></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li>Tidak ada riwayat poin.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="history-section">
                        <h3>Riwayat Permainan</h3>
                        <ul class="history-list">
                            <?php if (!empty($gameHistory)): ?>
                                <?php foreach ($gameHistory as $entry): ?>
                                    <li><?php echo htmlspecialchars($entry['description']); ?> <small> (<?php echo htmlspecialchars($entry['completed_at']); ?>)</small></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li>Tidak ada riwayat permainan.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
        <section class="hero-section">
            <p>Pilih Level Permainan</p>
            <h2 class="level-description">Pilih level untuk memulai petualangan belajarmu tentang pengelolaan sampah yang benar!</h2>
            <div class="level-cards-container">
                <div class="level-card" data-level="1">
                    <span class="icon material-symbols-outlined">grade</span>
                    <h3>Level 1</h3>
                </div>
                <div class="level-card" data-level="2">
                    <span class="icon material-symbols-outlined">recycling</span>
                    <h3>Level 2</h3>
                </div>
                <div class="level-card" data-level="3">
                    <span class="icon material-symbols-outlined">filter_alt</span>
                    <h3>Level 3</h3>
                </div>
                <div class="level-card" data-level="4">
                    <span class="icon material-symbols-outlined">grass</span>
                    <h3>Level 4</h3>
                </div>
            </div>
        </section>

        <section class="features-section">
            <div class="feature-box">
                <span class="icon material-symbols-outlined">stadia_controller</span>
                <h3>Interaktif</h3>
                <p>Belajar dengan cara yang menyenangkan melalui game interaktif yang engaging.</p>
            </div>
            <div class="feature-box">
                <span class="icon material-symbols-outlined">menu_book</span>
                <h3>Edukatif</h3>
                <p>Materi pembelajaran yang komprehensif tentang pengelolaan sampah.</p>
            </div>
        </section>

        <section class="stats-section">
            <h2>Statistik Pengguna</h2>
            <div class="stats-container">
                <div class="stat-item">
                    <div class="value"><?php echo htmlspecialchars($wonGamesCount); ?></div> <div class="label">Total Game</div>
                </div>
                <div class="stat-item">
                    <div class="value">4</div> <div class="label">Level Tersedia</div>
                </div>
                <div class="stat-item">
                    <div class="value">24/7</div>
                    <div class="label">Akses Kapan Saja</div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <p>&copy; 2025 GoRako. All rights reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const levelCards = document.querySelectorAll('.level-card');
            const levelDescription = document.querySelector('.level-description');
            const originalDescription = levelDescription ? levelDescription.textContent : "";

            const levelMessages = {
                1: "Di Level 1, kamu akan belajar dasar-dasar pemilahan sampah. Kenali jenis-jenis sampah utama!",
                2: "Level 2 akan mengajarkanmu tentang proses dan pentingnya daur ulang. Ubah sampah jadi berkah!",
                3: "Fokus Level 3 adalah memilah sampah dengan cepat dan tepat. Ketepatan adalah kunci!",
                4: "Di Level 4, kamu akan memahami bagaimana sampah organik bisa menjadi pupuk yang subur. Hijaukan bumi!"
            };

            function updateLevelDisplay(cardElement, descriptionText) {
                if (levelDescription) {
                    levelDescription.textContent = descriptionText;
                }
                levelCards.forEach(c => c.classList.remove('active'));
                if (cardElement) {
                    cardElement.classList.add('active');
                }
            }

            levelCards.forEach(card => {
                card.addEventListener('click', () => {
                    const level = card.dataset.level;
                    updateLevelDisplay(card, levelMessages[level]);

                    const userId = <?php echo $currentUserId; ?>;
                    if (userId !== null) {
                        const levelFile = `level_${level}.php`;
                        fetch(levelFile, { method: 'HEAD' })
                            .then(response => {
                                if (response.ok) {
                                    window.location.href = `${levelFile}?user_id=${userId}&level=${level}`;
                                } else {
                                    showMessage(`Level ${level} belum tersedia.`);
                                }
                            })
                            .catch(error => {
                                console.error('Error checking level file:', error);
                                showMessage(`Terjadi kesalahan saat memuat Level ${level}.`);
                            });
                    } else {
                        window.location.href = 'login.php';
                    }
                });

                card.addEventListener('mouseenter', () => {
                    const level = card.dataset.level;
                    updateLevelDisplay(card, levelMessages[level]);
                });

                card.addEventListener('mouseleave', () => {
                    let activeCard = document.querySelector('.level-card.active');
                    if (!activeCard || activeCard === card) {
                         if (levelDescription) {
                            levelDescription.textContent = originalDescription;
                            if (activeCard) {
                                activeCard.classList.remove('active');
                            }
                        }
                    }
                });
            });

            if (levelDescription && !document.querySelector('.level-card.active')) {
                levelDescription.textContent = originalDescription;
            }

            const flashMessageElement = document.querySelector('.flash-message');
            if (flashMessageElement) {
                const closeFlashBtn = flashMessageElement.querySelector('.close-flash');
                closeFlashBtn.addEventListener('click', () => {
                    flashMessageElement.style.display = 'none';
                });
            }

            // The integrated profile section remains hidden by default and is not toggled by the profile display element.
            // The close button for it will also not work unless there's another way to make the section visible.

            function showMessage(msg) {
                alert(msg);
            }
        });
    </script>
</body>
</html>