<?php
require_once 'db_connection.php'; // Sertakan koneksi database dan fungsi helper
require_once 'helpers.php';        // Pastikan helpers.php ada (is_logged_in, redirect)

// Muat pemetaan modul terpusat
// Ini adalah perubahan penting untuk konsistensi
$moduleProgressMapping = require 'module_mappings.php';

// Pastikan sesi dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Jika pengguna TIDAK login, arahkan mereka ke halaman login
if (!is_logged_in()) { // cite: 1
    redirect('login.php'); // Arahkan ke login.php jika belum login
}

// Pada titik ini, pengguna sudah login. Anda dapat mengakses variabel sesi:
$loggedInUserId = $_SESSION['user_id']; // cite: 1
$loggedInUsername = $_SESSION['username']; // cite: 1
$loggedInUserPoints = $_SESSION['total_points'] ?? 0; // Menggunakan session total_points

// Ambil data pengguna, termasuk gambar profil dan preferensi tema/tampilan
$userData = []; // cite: 1
$userSettings = []; // Variabel baru untuk menyimpan pengaturan tampilan

$query = "SELECT id, username, email, profile_picture,
                    theme_preference, accent_color, font_size_preference, total_points
            FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
if ($stmt) { // cite: 1
    $stmt->bind_param("i", $loggedInUserId); // cite: 1
    $stmt->execute(); // cite: 1
    $result = $stmt->get_result(); // cite: 1
    if ($result->num_rows > 0) { // cite: 1
        $userData = $result->fetch_assoc(); // cite: 1
        // Remove theme_preference as it's no longer used for dark/light mode
        $userSettings['theme_preference'] = 'light'; // Default to light, as dark mode is removed
        $userSettings['accent_color'] = $userData['accent_color'] ?? '#10b981'; // Warna default sesuai index.php
        // Pastikan $_SESSION['total_points'] diperbarui dari DB jika sesi belum terbaru atau jika baru login
        // Ambil nilai dari DB, dan update session jika ada perbedaan atau session belum ada.
        if (!isset($_SESSION['total_points']) || $_SESSION['total_points'] !== $userData['total_points']) { // cite: 1
             $_SESSION['total_points'] = $userData['total_points'] ?? 0; // cite: 1
        }
        $loggedInUserPoints = $_SESSION['total_points']; // Pastikan ini mengambil dari sesi yang sudah diperbarui
    }
    $stmt->close(); // cite: 1
} else {
    error_log("Gagal menyiapkan kueri data pengguna: " . $conn->error); // cite: 1
}

// Konversi data PHP ke JSON untuk JavaScript
$jsUserSettings = json_encode($userSettings); // cite: 1

// --- Fungsi untuk Mengambil Data Modul ---

/**
 * Mengambil modul video aktif dengan batasan.
 * @param mysqli $conn Objek koneksi database.
 * @param int $limit Batasan jumlah modul yang diambil.
 * @return array Array berisi data modul video.
 */
function getLimitedActiveVideoModules($conn, $limit = 100) {
    $videos = []; // cite: 1
    // Pastikan thumbnail_url diambil di sini
    $sql = "SELECT id, title, description, video_type, video_url, duration_minutes, points_reward, thumbnail_url
            FROM modules_video WHERE is_active = 1 ORDER BY created_at DESC LIMIT ?"; // cite: 1
    $stmt = $conn->prepare($sql); // cite: 1
    if ($stmt) { // cite: 1
        $stmt->bind_param("i", $limit); // cite: 1
        $stmt->execute(); // cite: 1
        $result = $stmt->get_result(); // cite: 1
        while($row = $result->fetch_assoc()) { // cite: 1
            $videos[] = $row; // cite: 1
        }
        $stmt->close(); // cite: 1
    } else {
        error_log("Failed to prepare video modules query: " . $conn->error); // cite: 1
    }
    return $videos; // cite: 1
}

/**
 * Mengambil modul riset aktif dengan batasan.
 * @param mysqli $conn Objek koneksi database.
 * @param int $limit Batasan jumlah modul yang diambil.
 * @return array Array berisi data modul riset.
 */
function getLimitedActiveResearchModules($conn, $limit = 100) {
    $research = []; // cite: 1
    // Jika Anda menambahkan thumbnail untuk modul riset, tambahkan 'thumbnail_url' di SELECT ini
    $sql = "SELECT id, title, description, content_type, content_url, text_content, estimated_minutes, points_reward
            FROM modules_research WHERE is_active = 1 ORDER BY created_at DESC LIMIT ?"; // cite: 1
    $stmt = $conn->prepare($sql); // cite: 1
    if ($stmt) { // cite: 1
        $stmt->bind_param("i", $limit); // cite: 1
        $stmt->execute(); // cite: 1
        $result = $stmt->get_result(); // cite: 1
        while($row = $result->fetch_assoc()) { // cite: 1
            $research[] = $row; // cite: 1
        }
        $stmt->close(); // cite: 1
    } else {
        error_log("Failed to prepare research modules query: " . $conn->error); // cite: 1
    }
    return $research; // cite: 1
}

/**
 * Mengambil modul yang telah diselesaikan oleh pengguna.
 * @param mysqli $conn Objek koneksi database.
 * @param int $userId ID pengguna.
 * @return array Asosiatif array dengan kunci 'video' dan 'research', berisi ID modul yang diselesaikan.
 */
function getUserCompletedModules($conn, $userId) {
    $completedModules = ['video' => [], 'research' => []]; // cite: 1
    $stmt = $conn->prepare("SELECT module_type, module_id FROM user_module_progress WHERE user_id = ? AND is_completed = 1"); // cite: 1
    if ($stmt) { // cite: 1
        $stmt->bind_param("i", $userId); // cite: 1
        $stmt->execute(); // cite: 1
        $result = $stmt->get_result(); // cite: 1
        while($row = $result->fetch_assoc()) { // cite: 1
            $completedModules[$row['module_type']][] = $row['module_id']; // cite: 1
        }
        $stmt->close(); // cite: 1
    } else {
        error_log("Failed to prepare user completed modules query: " . $conn->error); // cite: 1
    }
    return $completedModules; // cite: 1
}

/**
 * Mengambil semua level game aktif.
 * @param mysqli $conn Objek koneksi database.
 * @return array Array berisi data level game.
 */
function getAllActiveGameLevels($conn) {
    $gameLevels = []; // cite: 1
    $sql = "SELECT id, level_name, description, duration_seconds, points_per_correct_sort FROM game_levels WHERE is_active = 1 ORDER BY id ASC"; // cite: 1
    $result = $conn->query($sql); // cite: 1
    if ($result) { // cite: 1
        while($row = $result->fetch_assoc()) { // cite: 1
            $gameLevels[] = $row; // cite: 1
        }
    } else {
        error_log("Failed to retrieve game levels: " . $conn->error); // cite: 1
    }
    return $gameLevels; // cite: 1
}


// Fungsi untuk mengambil berita dari Currents API dihapus karena seksi berita dihapus dari tampilan.
/*
function getLatestNewsFromAPI() {
    // ... kode API Currents ...
}
*/

// Panggil fungsi untuk mengambil data
$limitedVideoModules = getLimitedActiveVideoModules($conn); // cite: 1
$limitedResearchModules = getLimitedActiveResearchModules($conn); // cite: 1
$userCompletedModules = getUserCompletedModules($conn, $loggedInUserId); // cite: 1
// $latestNews = getLatestNewsFromAPI(); // Baris ini dihapus karena seksi berita dihapus
$activeGameLevels = getAllActiveGameLevels($conn); // NEW: Ambil semua level game aktif

// Siapkan data untuk JavaScript contentMapping secara dinamis
$jsContentMapping = []; // cite: 1
foreach ($limitedResearchModules as $module) { // cite: 1
    // Tambahkan pengecekan isset sebelum mengakses array mapping
    $progressId = $moduleProgressMapping['research'][$module['id']] ?? null; // cite: 1
    if ($progressId !== null) { // cite: 1
        $jsContentMapping["research-{$module['id']}"] = [
            'progressModuleId' => $progressId, // cite: 1
            'points' => $module['points_reward'], // cite: 1
            'title' => $module['title'], // cite: 1
            'sourceType' => 'read', // cite: 1
            'duration' => htmlspecialchars($module['estimated_minutes']) . ' menit membaca', // cite: 1
            'moduleType' => 'research' // Tambahkan tipe modul untuk konsistensi
        ];
    } else {
        error_log("DEBUG modules.php: Research module ID {$module['id']} not mapped in \$moduleProgressMapping."); // cite: 1
    }
}
foreach ($limitedVideoModules as $module) { // cite: 1
    // Tambahkan pengecekan isset sebelum mengakses array mapping
    $progressId = $moduleProgressMapping['video'][$module['id']] ?? null; // cite: 1
    if ($progressId !== null) { // cite: 1
        $total_seconds = $module['duration_minutes'] * 60; // cite: 1
        $duration_formatted = sprintf('%02d:%02d', floor($total_seconds/60), $total_seconds % 60); // cite: 1
        $jsContentMapping["video-{$module['id']}"] = [
            'progressModuleId' => $progressId, // cite: 1
            'points' => $module['points_reward'], // cite: 1
            'title' => $module['title'], // cite: 1
            'sourceType' => 'video', // cite: 1
            'duration' => $duration_formatted, // cite: 1
            'moduleType' => 'video' // Tambahkan tipe modul untuk konsistensi
        ];
    } else {
        error_log("DEBUG modules.php: Video module ID {$module['id']} not mapped in \$moduleProgressMapping."); // cite: 1
    }
}

// Konversi mapping ke JSON
$jsContentMappingJson = json_encode($jsContentMapping);


$conn->close(); // Tutup koneksi database setelah semua data diambil
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Belajar Kelola Sampah, Selamatkan Bumi</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preload" href="logo.png" as="image">
    <style>
        /* Global Styles & Reset */
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
            color: #374151;
            padding-top: 80px; /* Space for fixed navbar */
            position: relative;
            /* Default font size, overridden by JS if preference exists */
            font-size: 16px;
            /* Theme colors, overridden by JS if preference exists */
            --primary-app-color: #10b981; /* Default app primary color */
            --secondary-app-color: #3f51b5; /* Default app secondary color */
            --text-app-color: #374151;
            --background-app-color: #f8fafc;
        }

        /* Classes for font size from settings.php */
        html.font-small { font-size: 14px; }
        html.font-medium { font-size: 16px; }
        html.font-large { font-size: 18px; }

        /* Apply custom accent color from CSS variable */
        body {
            /* Fallback to default if custom color not set by JS */
            --primary-color-from-settings: var(--primary-app-color);
        }
        /* Override primary button and link colors with custom primary-color-from-settings */
        .cta-button, .btn-primary, .feature-cta {
             background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .nav-link:hover {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .profile-dropdown-toggle:hover {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .hero-content h1 {
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%), var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, white 20%));
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        /* Adjust any other elements that should follow the accent color */
        .feature-icon i {
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color-from-settings) 80%, white 20%), var(--primary-color-from-settings));
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .explanation-content ul li i {
            color: var(--primary-color-from-settings);
        }
        .cta-button-lg {
            color: var(--primary-color-from-settings);
        }
        .cta-button-lg:hover {
            color: color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%);
        }
        .profile-dropdown-toggle img {
            border-color: var(--primary-color-from-settings);
        }
        .profile-dropdown-menu a:hover {
            color: var(--primary-color-from-settings);
        }
        .chat-button, .chat-input button { /* For chat widget */
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .chat-header {
             background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .chat-message.user-message {
            background: var(--primary-color-from-settings);
        }
        .chat-input input:focus, .chat-input textarea:focus {
            border-color: var(--primary-color-from-settings);
        }
        .quick-replies button:hover {
            background: var(--primary-color-from-settings);
        }

        /* Navbar Styles (Synchronized with Service_Quiz.html but with logo image) */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.15); /* Stronger shadow */
            z-index: 1000;
            padding: 1rem 0; /* Consistent padding */
            transition: all 0.4s ease;
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
            font-size: 2rem; /* Larger text to match logo */
            font-weight: 700;
            color: #16610E;
            text-decoration: none;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
            letter-spacing: -0.8px;
            transition: all 0.3s ease;
        }

        /* Jika menggunakan gambar logo, sesuaikan ini */
        .logo-image {
            width: 70px;
            height: 80px;
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .logo:hover .logo-image {
            transform: rotate(8deg) scale(1.0); /* Reduced scale */
            filter: brightness(1.1) drop-shadow(0 0 8px rgba(16, 185, 129, 0.7)); /* Ditambahkan: Efek drop-shadow untuk logo */
        }

        .logo:hover {
            color: #10b981;
            text-shadow: 1px 1px 5px rgba(16, 185, 129, 0.5);
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 2.5rem;
            align-items: center;
        }

        .nav-menu li {
            position: relative;
        }

        .nav-link {
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
            z-index: 1; /* Pastikan teks di atas shimmer */
        }

        .nav-link:hover {
            /* color: #FFFFFF; */ /* Overridden by specific selector now */
            /* background: linear-gradient(135deg, #10b981, #059669); */ /* Overridden by specific selector now */
            transform: translateY(-3px) scale(1.0); /* Reduced scale to 1.0 (no scaling) */
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%; /* Mulai dari kiri luar */
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.15); /* Dikurangi intensitas shimmer */
            transform: skewX(-20deg);
            transition: none; /* Transisi dikendalikan oleh animation */
            opacity: 0;
            z-index: -1; /* Di bawah teks */
        }

        .nav-link:hover::before {
            opacity: 1;
            animation: shimmerEffect 0.6s forwards;
        }

        @keyframes shimmerEffect {
            0% { transform: skewX(-20deg) translateX(-100%); opacity: 0; }
            50% { transform: skewX(-20deg) translateX(0%); opacity: 1; }
            100% { transform: skewX(-20deg) translateX(100%); opacity: 0; }
        }

        /* Dropdown Menu Styles */
        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background-color: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(15px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            padding: 0.8rem 0;
            list-style: none;
            min-width: 180px;
            z-index: 1010;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .nav-item.dropdown:hover .dropdown-menu {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-menu li {
            position: relative;
        }

        .dropdown-menu a {
            display: block;
            padding: 0.8rem 1.5rem;
            color: #4b5563;
            text-decoration: none;
            font-weight: 400;
            white-space: nowrap;
        }

        .dropdown-menu a:hover {
            background-color: #e0f2fe;
            /* color: #007bff; */ /* Overridden by specific selector now */
        }


        .cta-button {
            /* background: linear-gradient(135deg, #10b981, #059669); */ /* Overridden by specific selector now */
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            position: relative;
            overflow: hidden;
        }

        .cta-button:hover {
            transform: translateY(-3px); /* Reduced vertical movement */
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4); /* Slightly reduced shadow */
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
            position: relative; /* Added for smoother animation */
        }
        /* Ensure the hamburger icon transforms correctly */
        .mobile-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }
        .mobile-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .mobile-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -6px);
        }


        /* NEW: Profile Thumbnail in Navbar (for logged out state, originally from prompt) */
        .profile-thumbnail-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px; /* Adjust size as needed */
            height: 50px; /* Adjust size as needed */
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #10b981; /* Green border */
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
            transition: all 0.3s ease;
        }

        .profile-thumbnail-link:hover {
            transform: scale(1.08);
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.6);
        }

        .profile-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover; /* Ensure image covers the area */
        }

        /* NEW: Profile Dropdown Menu Styling */
        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #1f2937;
            cursor: pointer;
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .profile-dropdown-toggle:hover {
            /* color: #FFFFFF; */ /* Overridden by specific selector now */
            /* background: linear-gradient(135deg, #10b981, #059669); */ /* Overridden by specific selector now */
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .profile-dropdown-toggle:hover::before {
            opacity: 1;
            animation: shimmerEffect 0.6s forwards;
        }

        .profile-dropdown-toggle img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            /* border: 2px solid #007bff; */ /* Overridden by specific selector now */
            object-fit: cover;
            flex-shrink: 0; /* Prevent image from shrinking */
        }

        .profile-dropdown-toggle .profile-text {
            font-weight: 700;
            margin-right: 5px; /* Spacing before the arrow icon */
        }

        .profile-dropdown-menu {
            left: unset; /* Override default dropdown-menu left */
            right: 0; /* Align to the right of the parent li */
            min-width: 200px; /* Adjust width as needed */
            padding: 0.5rem 0;
            display: flex;
            flex-direction: column;
            gap: 5px; /* Space between items */
        }

        .nav-item.dropdown:hover .dropdown-menu {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-menu li {
            position: relative;
        }

        .dropdown-menu a {
            display: block;
            padding: 0.8rem 1.5rem;
            color: #4b5563;
            text-decoration: none;
            font-weight: 400;
            white-space: nowrap;
        }

        .dropdown-menu a:hover {
            background-color: #e0f2fe;
            /* color: #007bff; */ /* Overridden by specific selector now */
        }


        .logout-button {
            background-color: #dc3545; /* Red color */
            color: white;
            text-align: center;
            padding: 0.8rem 1.5rem;
            border-radius: 0 0 10px 10px; /* Rounded bottom corners */
            margin-top: 10px; /* Space from above items */
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: block; /* Make it a block element to take full width */
        }

        .logout-button:hover {
            background-color: #c82333; /* Darker red on hover */
            color: white; /* Ensure text stays white on hover */
        }


        /* NEW: Points Display in Navbar */
        .points-display-item {
            display: flex;
            align-items: center;
            margin-right: 1.5rem; /* Space between points and profile/login */
        }

        .points-link {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #f0f4f8, #e2e8f0);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            color: #374151;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .points-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color-from-settings) 10%, white 90%), color-mix(in srgb, var(--primary-color-from-settings) 5%, white 95%));
            color: var(--primary-color-from-settings);
        }

        .points-icon {
            font-size: 1.2rem;
            color: #ffc107; /* Gold color for points */
            transition: transform 0.3s ease; /* Ditambahkan: Transisi untuk ikon poin */
        }

        .points-link:hover .points-icon {
            transform: rotate(10deg) scale(1.1);
        }

        .points-value {
            font-size: 0.95rem;
            white-space: nowrap;
        }

        /* ===== Mobile Sidebar Menu Styles (New) ===== */
        .mobile-sidebar {
            position: fixed;
            top: 0;
            right: -320px; /* Start off-screen to the right */
            width: 300px; /* Fixed width as per image */
            height: 100vh;
            background: rgba(255, 255, 255, 0.98); /* Always light background for sidebar */
            backdrop-filter: blur(15px);
            box-shadow: -8px 0 20px rgba(0, 0, 0, 0.15); /* Shadow on the left edge */
            z-index: 1100; /* Higher than navbar */
            display: flex; /* IMPORTANT: make sure it's a flex container */
            flex-direction: column;
            transition: right 0.4s ease-out; /* Slide in/out animation */
            padding: 1.5rem 0; /* Vertical padding */
            overflow-y: auto; /* Enable scrolling for long menus */
            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
        }

        .mobile-sidebar.active {
            right: 0; /* Slide into view */
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem 1.5rem; /* Padding at the bottom for separation */
            border-bottom: 1px solid #eee; /* Separator line */
            margin-bottom: 1.5rem;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: #16610E; /* Fixed light mode color */
            text-decoration: none;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        .sidebar-logo-image {
            width: 45px;
            height: 45px;
            margin-right: 10px;
        }

        .sidebar-close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: #6b7280; /* Neutral color */
            transition: transform 0.2s ease, color 0.2s ease;
        }
        .sidebar-close-btn:hover {
            color: #dc3545; /* Red on hover for close */
            transform: rotate(90deg);
        }

        .sidebar-menu {
            list-style: none;
            padding: 0 1rem; /* Padding for the menu items themselves */
        }

        .sidebar-menu li {
            margin-bottom: 0.5rem; /* Space between menu items */
            border-radius: 8px; /* Rounded corners for list items */
            transition: background-color 0.2s ease;
        }

        .sidebar-menu li:last-of-type { /* Target last visible li, including profile section */
            margin-bottom: 0;
        }

        .sidebar-menu a {
            display: flex; /* Flexbox for icon and text alignment */
            align-items: center;
            padding: 0.75rem 1rem; /* Padding inside each menu item */
            color: #4b5563; /* Default text color for light mode */
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            font-size: 1.05rem; /* Consistent font size */
        }
        .sidebar-menu a i {
            margin-right: 15px; /* Space between icon and text */
            min-width: 20px; /* Ensure icons align if they have different widths */
            text-align: center;
            color: #6b7280; /* Default icon color */
        }

        .sidebar-menu a:hover {
            background-color: #e0f2fe; /* Light blue on hover */
            color: var(--primary-color-from-settings); /* Accent color on hover */
        }
        .sidebar-menu a:hover i {
            color: var(--primary-color-from-settings); /* Accent color on hover */
        }

        /* Specific styles for profile/logout in sidebar if logged in */
        .sidebar-profile-section {
            padding: 1rem 1rem 0.5rem; /* Reduced bottom padding */
            border-top: 1px solid #eee;
            margin-top: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.3rem; /* Reduced gap */
        }

        .sidebar-profile-section a { /* Apply to direct links like Profile, Settings */
            padding: 0.6rem 1rem; /* Smaller padding for nested links */
            font-size: 0.95rem; /* Smaller font for nested links */
            color: #4b5563; /* Default text color for light mode */
        }
        .sidebar-profile-section a i {
            color: #6b7280; /* Consistent icon color for profile links */
        }
        .sidebar-profile-section a:hover {
            background-color: rgba(var(--primary-color-from-settings-rgb, 16, 185, 129), 0.08); /* Lighter hover for nested */
            color: var(--primary-color-from-settings);
        }

        .sidebar-profile-section .points-info {
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            justify-content: flex-start;
            color: var(--primary-color-from-settings);
            font-weight: 600;
        }

        .sidebar-logout-button {
            margin: 1.5rem 1.5rem 0;
            padding: 0.8rem 1.5rem;
            border-radius: 12px;
            background-color: #dc3545;
            color: white;
            text-align: center;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.3s ease;
            display: block;
        }
        .sidebar-logout-button:hover {
            background-color: #c82333;
        }

        .sidebar-theme-toggle { /* This will now be completely hidden or removed in HTML */
            display: none; /* Hide the theme toggle in the sidebar */
        }

        /* Overlay for sidebar */
        body.sidebar-active::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5); /* Dark overlay */
            backdrop-filter: blur(5px); /* Optional blur */
            z-index: 1050; /* Between main content and sidebar */
            transition: opacity 0.4s ease;
            opacity: 1; /* Make it visible when sidebar-active is on body */
            pointer-events: auto; /* Allow clicks to close sidebar */
        }
        /* Ensure the overlay is hidden when sidebar is not active */
        body:not(.sidebar-active)::before {
            opacity: 0;
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-content h1 {
                font-size: 3rem;
            }
            .features-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            .explanation-container {
                flex-direction: column;
                gap: 2rem;
            }
            .explanation-content {
                padding-right: 0;
                text-align: center;
            }
            .explanation-content ul {
                padding: 0 1rem; /* Adjust padding for centered list */
            }
        }

        @media (max-width: 768px) {
            body {
                padding-top: 60px;
            }
            .navbar {
                padding: 0.8rem 0;
            }
            .logo {
                font-size: 1.6rem;
            }
            .logo-image {
                width: 38px; /* Further adjusted for smaller screens */
                height: 38px;
            }
            .nav-menu { /* Hide desktop menu */
                display: none;
            }
            .mobile-toggle { /* Show mobile toggle */
                display: flex;
            }

            .hero-banner { /* Added specific padding for mobile hero */
                padding: 100px 4% 80px; /* Reduced vertical padding for mobile */
                min-height: unset; /* Allow it to shrink if content is short */
            }
            .hero-banner h1 {
                font-size: 2.5rem;
            }
            .hero-banner p {
                font-size: 1.1rem;
            }
            .hero-banner .btn-group {
                flex-direction: column;
                gap: 1rem;
            }
            .btn-primary, .btn-secondary {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
            .section-header h2 {
                font-size: 2.5rem;
            }
            .section-header p {
                font-size: 1rem;
            }
            .cta-section h2 {
                font-size: 2.5rem;
            }
            .cta-section p {
                font-size: 1.1rem;
            }
            .cta-button-lg {
                padding: 1rem 2rem;
                font-size: 1rem;
            }
            .footer-content {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .footer-brand, .footer-links, .footer-contact {
                min-width: unset;
                width: 100%;
            }
            .footer-links ul {
                padding: 0;
            }
            .explanation-content {
                padding-right: 0;
                text-align: center;
            }
            .explanation-content h2 {
                font-size: 2.5rem;
            }
            .explanation-content p, .explanation-content ul li {
                font-size: 1rem;
            }
            .explanation-content ul {
                padding: 0; /* Remove padding on smaller screens */
            }

            /* Responsive adjustments for points display in desktop nav-menu (now hidden) */
            .points-display-item {
                width: 100%; /* Take full width in mobile menu context (if nav-menu were visible) */
                margin-right: 0;
                margin-bottom: 1rem;
                justify-content: center;
            }
            /* Specific adjustments for profile dropdown in mobile sidebar */
            .profile-dropdown-menu .points-info {
                border-bottom: none; /* Remove separator for better flow */
                padding-bottom: 0;
                margin-bottom: 0.5rem;
            }

            .profile-dropdown-menu a {
                padding: 0.8rem 1rem; /* Adjust padding for nested links */
            }

            .profile-dropdown-menu li:not(:last-child) {
                border-bottom: 1px dashed rgba(0,0,0,0.1); /* Dashed separator for sub-items */
                padding-bottom: 8px;
                margin-bottom: 8px;
            }

            .logout-button {
                margin-top: 15px; /* More space above logout */
                border-radius: 8px; /* Less rounded for consistency within expanded menu */
                width: calc(100% - 2rem); /* Match nested menu width */
                margin-left: auto;
                margin-right: auto;
            }

            /* Ensure text and icons align in mobile menu */
            .nav-menu .nav-link,
            .nav-menu .profile-dropdown-toggle {
                justify-content: center; /* Center items in mobile menu */
            }
        }

        @media (max-width: 480px) {
            .hero-banner h1 { /* Adjusted for very small screens */
                font-size: 2em;
            }
            .hero-banner p { /* Adjusted for very small screens */
                font-size: 1em;
            }
            .section-header h2 {
                font-size: 2em;
            }
            .feature-card {
                padding: 1.5rem;
            }
            .features-grid {
                grid-template-columns: 1fr;
            }
            .faq-item summary {
                font-size: 1rem;
                padding: 15px 20px;
            }
            .faq-content {
                font-size: 0.9rem;
                padding: 15px 20px;
            }
            .explanation-content h2 {
                font-size: 2rem;
            }
            .explanation-video {
                min-width: unset; /* Allow video to shrink further */
            }
            .social-icons {
                justify-content: center;
            }
            .mobile-sidebar {
                width: 250px; /* Even narrower on very small screens */
            }
        }
        /* Ripple Effect Base Styles */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
            pointer-events: none;
        }
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Scroll to top button */
        #backToTopBtn {
            display: none; /* Hidden by default */
            position: fixed; /* Fixed position */
            bottom: 30px; /* Place at bottom */
            right: 30px; /* Place at right */
            z-index: 999; /* High z-index to be on top */
            border: none; /* Remove borders */
            outline: none; /* Remove outline */
            background-color: #10b981; /* Set a background color */
            color: white; /* Text color */
            cursor: pointer; /* Add a mouse pointer on hover */
            padding: 15px; /* Some padding */
            border-radius: 50%; /* Rounded corners */
            font-size: 1.2rem; /* Increase font size */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s, transform 0.3s, opacity 0.3s;
            opacity: 0;
        }

        #backToTopBtn:hover {
            background-color: #059669; /* Add a darker background on hover */
            transform: translateY(-2px); /* Reduced lift */
        }
        #backToTopBtn.show {
            opacity: 1;
            display: block;
        }

        /* Toast notification (basic style) */
        .toast-notification {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1001;
            right: 30px;
            top: 100px; /* Adjust as needed */
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            opacity: 0;
            transition: opacity 0.5s, visibility 0s linear 0.5s; /* Delay visibility change */
        }
        .toast-notification.show {
            visibility: visible;
            opacity: 1;
            transition: opacity 0.5s;
        }
        /* Reduce motion for accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        /* Specific styles for modules.php page */
        :root {
            --primary-green: #4CAF50; /* A bit darker, more vibrant green */
            --dark-green: #388E3C;
            --light-green: #e8f5e9; /* Very light green */
            --dark-gray: #212121;
            --medium-gray: #616161;
            --light-gray: #f5f5f5;
            --white: #ffffff;
            --blue-accent: #2196F3; /* More vibrant blue */
            --shadow-light: 0 4px 15px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);

            /* NEW COLORS FOR MODULE HEADER */
            --module-header-green: #81C784; /* Light green header */
            --module-header-blue: #64B5F6; /* Light blue header */
            --module-header-orange: #FFB74D; /* Light orange header */
            --module-header-purple: #BA68C8; /* Light purple header */
            --module-header-red: #EF5350; /* Light red header */
            --module-header-yellow: #FFD54F; /* Light yellow header */
            --module-header-teal: #4DB6AC; /* Light teal header */
            --module-header-indigo: #7986CB; /* Light indigo header */
            --module-header-pink: #F06292; /* Light pink header */
            --module-header-cyan: #4DD0E1; /* Light cyan header */
            --icon-color-white: #FFFFFF;
        }
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            line-height: 1.7;
            color: var(--medium-gray);
            overflow-x: hidden; /* Prevent horizontal scroll on small screens */
            padding-top: 80px; /* Space for fixed navbar */
        }

        /* Global base styling */
        a {
            text-decoration: none;
            color: inherit;
        }
        /* --- Top Utility Bar (Back & Points) - NOT A FULL HEADER --- */
        /* This section is removed as per user request */

        /* --- Global Sections Styling --- */
        section {
            padding: 100px 4%;
            margin-top: 0;
            position: relative;
            z-index: 1;
        }

        #home {
            padding-top: 50px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        h2 {
            font-size: 3em;
            color: var(--dark-gray);
            text-align: center;
            margin-bottom: 80px;
            font-weight: 800;
            position: relative;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background-color: var(--primary-green);
            border-radius: 2px;
        }

        /* --- Buttons --- */
        .btn {
            display: inline-block;
            padding: 14px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
            cursor: pointer;
            font-size: 1.1em;
            box-shadow: var(--shadow-light);
        }

        .btn-primary {
            background-color: var(--primary-green);
            color: var(--white);
            border: 2px solid var(--primary-green);
        }

        .btn-primary:hover {
            background-color: var(--dark-green);
            border-color: var(--dark-green);
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark-gray);
            border: 2px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background-color: var(--light-gray);
            border-color: var(--light-gray);
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .btn-small {
            padding: 8px 15px;
            font-size: 0.9em;
            border-radius: 6px;
        }

        /* --- Hero Banner --- */
        .hero-banner {
            background: linear-gradient(to bottom right, var(--light-green) 0%, var(--white) 100%);
            text-align: center;
            padding: 180px 4% 120px;
            margin-top: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 80vh;
            position: relative;
            overflow: hidden;
        }

        .hero-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23cbe9cb' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM24 2V0h-2v2h-4v2h4v4h2V4h4V2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM12 2V0H10v2H6v2h4v4h2V4h4V2h-4zm0 14v-4H10v4H6v2h4v4h2v-4h4v-2h-4zm0 14v-4H10v4H6v2h4v4h2v-4h4v-2h-4zm0 14v-4H10v4H6v2h4v4h2v-4h4v-2h-4zm0 14v-4H10v4H6v2h4v4h2v-4h4v-2h-4zM0 2V0H-2v2H-6v2h4v4h2V4h4V2h-4zm0 14v-4H-2v4H-6v2h4v4h2v-4h4v-2h-4zm0 14v-4H-2v4H-6v2h4v4h2v-4h4v-2h-4zm0 14v-4H-2v4H-6v2h4v4h2v-4h4v-2h-4zm0 14v-4H-2v4H-6v2h4v4h2v-4h4v-2h-4zM48 2V0h-2v2h-4v2h4v4h2V4h4V2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0 14v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            background-size: 30px 30px;
            opacity: 0.7;
            z-index: -1;
            animation: moveBackground 30s linear infinite;
        }

        @keyframes moveBackground {
            0% { background-position: 0 0; }
            100% { background-position: 600px 600px; }
        }

        .hero-banner h1 {
            font-size: 4.2em;
            color: var(--dark-gray);
            margin-bottom: 25px;
            line-height: 1.1;
            font-weight: 800;
            max-width: 900px;
            letter-spacing: -1px;
        }

        .hero-banner p {
            font-size: 1.3em;
            color: var(--medium-gray);
            max-width: 800px;
            margin-bottom: 50px;
            line-height: 1.6;
        }

        .hero-banner .btn-group {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        /* --- Learning Progress ---*/
        .learning-progress {
            background-color: var(--light-green);
            padding-bottom: 100px; /* Add more padding for grid spacing */
        }

        .learning-progress .progress-bar-wrapper {
            background-color: var(--white);
            border-radius: 12px;
            padding: 25px 35px;
            margin-bottom: 60px;
            box-shadow: var(--shadow-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .learning-progress .progress-bar-wrapper:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .learning-progress .progress-bar {
            flex-grow: 1;
            height: 12px;
            background-color: var(--light-gray);
            border-radius: 6px;
            margin-right: 25px;
            overflow: hidden;
            position: relative;
        }

        .learning-progress .progress-fill {
            height: 100%;
            width: 0%;
            background-color: var(--primary-green);
            border-radius: 6px;
            transition: width 0.5s ease-out; /* Smooth fill animation */
        }

        .learning-progress .progress-text {
            font-weight: 700;
            color: var(--dark-gray);
            font-size: 1.1em;
        }

        /* Module Grid for Progress - 5 items per row */
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); /* Default for responsiveness */
            gap: 20px;
            justify-content: center;
        }

        @media (min-width: 1024px) { /* On larger screens, force 5 columns */
            .module-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }
        @media (min-width: 769px) and (max-width: 1023px) { /* On medium screens, force 3-4 columns */
            .module-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }
        }

        .learning-progress .module-card {
            height: 180px;
            padding: 20px;
            perspective: 1000px; /* For 3D transform */
            transform-style: preserve-3d;
            box-shadow:
                0 5px 15px rgba(0, 0, 0, 0.1), /* Base shadow */
                0 10px 20px rgba(0, 0, 0, 0.08); /* Deeper shadow for 3D feel */
            transition: transform 0.4s ease, box-shadow 0.4s ease;
            position: relative;
            background-color: var(--white); /* Ensure background is set */
            border-radius: 18px;
            overflow: hidden; /* For pseudo-elements */
            border: 1px solid rgba(255,255,255,0.2); /* Subtle white border for highlight */
        }

        .learning-progress .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(0,0,0,0.02) 100%);
            transform: translateZ(-2px); /* Push slightly back for depth */
            pointer-events: none;
            transition: transform 0.4s ease, opacity 0.4s ease;
            z-index: 0; /* Ensure it's behind content */
        }
         .learning-progress .module-card::after { /* Second pseudo-element for more depth/border */
            content: '';
            position: absolute;
            top: -2px; /* Slightly offset */
            left: -2px; /* Slightly offset */
            right: -2px;
            bottom: -2px;
            border-radius: 20px; /* Slightly larger border-radius */
            border: 2px solid rgba(255,255,255,0.1); /* Light border for outer glow/depth */
            transform: translateZ(-3px); /* Further back */
            pointer-events: none;
            transition: transform 0.4s ease;
            z-index: 0;
        }

        .learning-progress .module-card:hover {
            transform: translateY(-10px) rotateX(3deg) rotateY(3deg); /* More pronounced 3D tilt */
            box-shadow:
                0 15px 30px rgba(0, 0, 0, 0.2),
                0 25px 40px rgba(0, 0, 0, 0.15),
                0 0 0 8px rgba(0, 0, 0, 0.05); /* Outer glow */
        }
        .learning-progress .module-card:hover::before {
            transform: translateZ(-4px);
        }
         .learning-progress .module-card:hover::after {
            transform: translateZ(-5px);
        }

        .learning-progress .module-card h3 {
            font-size: 1.3em;
            z-index: 1; /* Ensure text is above pseudo-elements */
            position: relative;
        }
        .learning-progress .module-card p {
            font-size: 0.85em;
            z-index: 1;
            position: relative;
        }

        .module-card .module-placeholder {
            font-size: 3em;
            color: var(--primary-green);
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border: 2px dashed var(--light-green);
            border-radius: 10px;
            margin-top: 15px;
            transition: all 0.3s ease;
            cursor: default;
            z-index: 1; /* Ensure placeholder is above 3D layers */
            position: relative;
            padding: 10px; /* Added padding to placeholder */
        }
        .module-card .module-placeholder .plus-icon {
            font-size: 2em;
            line-height: 1;
            margin-bottom: 10px;
            color: var(--medium-gray);
        }

        .module-card:not(.completed) .module-placeholder:hover {
            background-color: var(--light-green);
            transform: scale(1.03);
        }

        .module-card.completed .module-placeholder {
            display: none;
        }
        .module-card:not(.completed) .module-placeholder {
            display: flex;
        }

        .module-card .progress-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
            z-index: 1; /* Ensure buttons are above 3D layers */
            position: relative;
        }
        .module-card .progress-actions .btn {
            box-shadow: none;
        }

        .module-card .module-status {
            display: none;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--dark-gray);
            margin-top: auto;
            justify-content: flex-end;
            font-size: 0.9em;
            z-index: 1; /* Ensure status is above 3D layers */
            position: relative;
            animation: fadeInStatus 0.5s ease-in-out; /* Animation for status */
        }
        .module-card .module-status svg {
            fill: var(--medium-gray);
            width: 20px;
            height: 20px;
        }
        .module-card .module-status.video-completed svg {
            fill: var(--blue-accent);
        }
        .module-card .module-status.read-completed svg {
            fill: var(--primary-green);
        }
        @keyframes fadeInStatus {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- Education Modules (different from learning progress, these are standalone modules) --- */
        .education-modules {
            background-color: var(--light-gray);
            padding: 80px 4%;
        }

        .module-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        .module-cards-grid .hidden-item {
            display: none;
        }

        .edu-module-card {
            background-color: var(--white);
            border-radius: 10px;
            box-shadow: var(--shadow-light);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: auto;
            transition: all 0.3s ease;
        }

        .edu-module-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .edu-module-card .module-header {
            display: flex;
            align-items: center;
            color: var(--icon-color-white);
            padding: 15px 20px;
        }

        .edu-module-card .module-header .module-icon {
            font-size: 1.5em;
            margin-right: 15px;
            display: flex;
            align-items: center;
        }
        .edu-module-card .module-header .module-icon svg {
             fill: var(--icon-color-white);
             width: 24px;
             height: 24px;
        }


        .edu-module-card .module-header h3 {
            font-size: 1.3em;
            font-weight: 600;
            margin: 0;
        }

        .edu-module-card .card-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .edu-module-card .card-content p {
            font-size: 0.9em;
            color: var(--medium-gray);
            margin-bottom: 15px;
            line-height: 1.5;
            flex-grow: 1;
        }

        .edu-module-card .info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8em;
            color: var(--medium-gray);
            padding: 0 20px 15px;
            margin-top: auto;
        }

        .edu-module-card .info span {
            display: flex;
            align-items: center;
            margin-right: 10px;
        }

        .edu-module-card .info span svg {
            fill: var(--medium-gray);
            width: 16px;
            height: 16px;
            margin-right: 5px;
        }
        .edu-module-card .info .points {
            font-weight: 700;
            color: var(--primary-green);
        }

        .edu-module-card .btn {
            background-color: var(--primary-green);
            color: var(--white);
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            text-align: center;
            text-decoration: none;
            display: block;
            width: calc(100% - 40px);
            margin: 15px auto 20px;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.2s ease, box-shadow 0.2s ease;
            box-shadow: var(--shadow-light);
        }

        .edu-module-card .btn:hover {
            background-color: var(--dark-green);
            box-shadow: var(--shadow-hover);
        }

        /* Warna header dan ikon untuk setiap modul */
        .edu-module-card:nth-child(1) .module-header { background-color: var(--module-header-green); }
        .edu-module-card:nth-child(2) .module-header { background-color: var(--module-header-blue); }
        .edu-module-card:nth-child(3) .module-header { background-color: var(--module-header-orange); }
        .edu-module-card:nth-child(4) .module-header { background-color: var(--module-header-purple); }
        .edu-module-card:nth-child(5) .module-header { background-color: var(--module-header-red); }
        .edu-module-card:nth-child(6) .module-header { background-color: var(--module-header-yellow); }
        .edu-module-card:nth-child(7) .module-header { background-color: var(--module-header-teal); }
        .edu-module-card:nth-child(8) .module-header { background-color: var(--module-header-indigo); }
        .edu-module-card:nth-child(9) .module-header { background-color: var(--module-header-pink); }
        .edu-module-card:nth-child(10) .module-header { background-color: var(--module-header-cyan); }


        /* --- Education Videos --- */
        .education-videos {
            background-color: var(--white);
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 35px;
        }
        .video-grid .hidden-item {
            display: none;
        }

        .video-card {
            background-color: var(--white);
            border-radius: 18px;
            box-shadow: var(--shadow-light);
            overflow: hidden;
            position: relative;
            text-align: center;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .video-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .video-card .thumbnail {
            width: 100%;
            height: 220px;
            background-color: var(--light-gray); /* Background for the icon */
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
            /* Tambahkan style untuk menampung ikon YouTube */
            font-size: 6rem; /* Ukuran ikon */
            color: #FF0000; /* Warna merah YouTube */
            transition: background-color 0.3s ease;
        }
        .video-card:hover .thumbnail {
            background-color: rgba(255, 0, 0, 0.1); /* Sedikit perubahan warna hover */
        }

        .video-card .thumbnail img { /* Sembunyikan elemen gambar thumbnail */
            display: none;
        }
        .video-card .thumbnail .youtube-icon {
            color: #FF0000; /* Warna ikon YouTube */
            font-size: 6rem; /* Ukuran ikon YouTube */
            z-index: 5; /* Pastikan di bawah play button tapi di atas background */
            position: absolute;
        }

        .video-card .play-button {
            position: absolute;
            background-color: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            width: 70px;
            height: 70px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: transform 0.3s ease, background-color 0.3s ease;
            z-index: 10;
        }

        .video-card .play-button:hover {
            transform: scale(1.15);
            background-color: var(--primary-green);
        }

        .video-card .play-button svg {
            fill: var(--white);
            width: 30px;
            height: 30px;
        }


        .video-card .video-info-overlay {
            position: absolute;
            bottom: 15px;
            left: 15px;
            right: 15px;
            display: flex;
            justify-content: space-between;
            color: var(--white);
            font-size: 0.9em;
            background-color: rgba(0, 0, 0, 0.5);
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: 600;
            backdrop-filter: blur(3px);
        }

        .video-card .card-content {
            padding: 25px;
            text-align: left;
            flex-grow: 1;
        }

        .video-card h3 {
            font-size: 1.5em;
            color: var(--dark-gray);
            margin-top: 0;
            margin-bottom: 12px;
            font-weight: 700;
            line-height: 1.3;
        }

        .video-card p {
            font-size: 0.95em;
            color: var(--medium-gray);
            line-height: 1.6;
            margin-bottom: 20px;
            word-break: break-word; /* Ensure long words break */
        }

        .video-card .video-points {
            font-weight: 800;
            color: var(--primary-green);
            font-size: 1.1em;
            margin-top: auto;
        }
        .video-card .complete-item {
            margin: 0 25px 25px;
            display: block;
            padding: 12px 15px;
            font-size: 1em;
            border-radius: 8px;
            box-shadow: none;
            transition: all 0.2s ease;
            background-color: var(--primary-green);
            color: var(--white);
            border: 2px solid var(--primary-green);
        }

        .video-card .complete-item:hover {
            background-color: var(--dark-green);
            box-shadow: var(--shadow-hover);
        }

        /* Styles for "Lihat Semua" buttons */
        .show-more-btn-container {
            text-align: center;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid var(--light-gray);
        }
        .show-more-btn {
            background-color: var(--blue-accent);
            color: var(--white);
            border: 2px solid var(--blue-accent);
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
        }
        .show-more-btn:hover {
            background-color: #1976D2;
            border-color: #1976D2;
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        /* --- Eco Heroes Game Section --- */
        .eco-heroes-game {
            background: linear-gradient(135deg, #e6ffe6 0%, #ffffff 100%); /* Soft green to white background */
            padding: 80px 4%;
            text-align: center;
            border-radius: 15px; /* Rounded corners for the section */
            margin: 50px auto; /* Center the section with some margin */
            max-width: 1400px; /* Wider section */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }

        .game-levels-grid { /* New grid for game levels */
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .game-card { /* New card style for game levels */
            background-color: var(--white);
            border-radius: 15px;
            box-shadow: var(--shadow-light);
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .game-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .game-card h3 {
            font-size: 1.8em;
            color: var(--dark-gray);
            margin-bottom: 10px;
        }

        .game-card p {
            font-size: 1em;
            color: var(--medium-gray);
            margin-bottom: 15px;
        }

        .game-card .game-info-details {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .game-card .game-info-details span {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9em;
            color: var(--medium-gray);
            font-weight: 600;
        }

        .game-card .game-info-details span i {
            color: var(--primary-green);
        }

        .game-card .btn {
            margin-top: 20px;
            width: 100%;
            max-width: 250px;
            align-self: center; /* Center the button in flex column */
        }


        .game-content {
            flex: 1;
            min-width: 300px;
            max-width: 600px;
            text-align: left;
        }

        .game-title {
            font-size: 3.5em; /* Larger title */
            color: var(--dark-gray);
            margin-bottom: 25px;
            line-height: 1.1;
            font-weight: 800;
            background: linear-gradient(90deg, var(--primary-green), var(--dark-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-align: left;
        }

        .game-description {
            font-size: 1.1em;
            color: var(--medium-gray);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .game-features {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 40px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.05em;
            color: var(--dark-gray);
            font-weight: 500;
        }

        .feature-icon-check {
            color: var(--primary-green); /* Green checkmark */
            font-size: 1.3em;
        }

        .game-start-button {
            display: inline-block;
            padding: 18px 40px;
            font-size: 1.3em;
            font-weight: 700;
            border-radius: 50px; /* More rounded button */
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: var(--white);
            border: none;
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.4);
            transition: all 0.3s ease;
        }

        .game-start-button:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(76, 175, 80, 0.6);
            background: linear-gradient(135deg, var(--dark-green), #2e7d32);
        }

        .game-visuals {
            position: relative;
            width: 300px; /* Size of the visual container */
            height: 300px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0; /* Prevent shrinking on smaller screens */
            margin: 0 auto; /* Center for smaller screens */
        }

        .badge-planet {
            position: absolute;
            background: linear-gradient(45deg, #2ecc71, #27ae60); /* Vibrant green for badge */
            color: white;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.8em;
            font-weight: 800;
            text-shadow: 2px 2px 5px rgba(0,0,0,0.2);
            box-shadow: 0 10px 20px rgba(46, 204, 113, 0.5);
            animation: pulse 2s infinite ease-in-out;
            z-index: 1;
        }

        .sticker-fun {
            position: absolute;
            background-color: #f1c40f; /* Yellow sticker */
            color: #2c3e50;
            padding: 10px 25px;
            border-radius: 50px;
            font-size: 1.2em;
            font-weight: 700;
            transform: rotate(15deg);
            top: 10px; /* Position relative to .game-visuals */
            right: 10px;
            box-shadow: 0 5px 15px rgba(241, 196, 15, 0.4);
            z-index: 2;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); box-shadow: 0 12px 25px rgba(46, 204, 113, 0.6); }
            100% { transform: scale(1); }
        }

        /* Responsive adjustments for game section */
        @media (max-width: 992px) {
            .game-title {
                font-size: 3em;
            }
            .eco-heroes-game {
                padding: 60px 3%;
                margin: 30px auto;
            }
            .game-container {
                flex-direction: column;
                gap: 30px;
            }
            .game-content {
                text-align: center;
            }
            .game-title {
                text-align: center;
            }
            .game-features {
                align-items: center;
            }
            .game-visuals {
                margin-top: 30px;
            }
        }

        @media (max-width: 768px) {
            .game-title {
                font-size: 2.5em;
            }
            .game-description {
                font-size: 1em;
            }
            .feature-item {
                font-size: 0.95em;
            }
            .game-start-button {
                font-size: 1.1em;
                padding: 15px 30px;
            }
            .badge-planet {
                width: 200px;
                height: 200px;
                font-size: 1.5em;
            }
            .sticker-fun {
                padding: 8px 20px;
                font-size: 1em;
            }
            .game-levels-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .game-title {
                font-size: 2em;
            }
            .game-description {
                font-size: 0.9em;
            }
            .feature-item {
                font-size: 0.85em;
            }
            .game-start-button {
                font-size: 1em;
                padding: 12px 25px;
            }
            .badge-planet {
                width: 180px;
                height: 180px;
                font-size: 1.3em;
            }
            .sticker-fun {
                padding: 6px 15px;
                font-size: 0.9em;
                top: 5px;
                right: 5px;
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

        .social-icons {
            margin-top: 1rem;
            display: flex;
            gap: 15px;
            justify-content: center; /* Center for mobile/small screens */
        }
        .social-icons a {
            color: #a0aec0;
            font-size: 1.5rem;
            transition: color 0.3s ease, transform 0.3s ease;
        }
        .social-icons a:hover {
            color: #fff;
            transform: translateY(-2px); /* Reduced lift */
        }

        .footer-bottom {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: #a0aec0;
            font-size: 0.85rem;
        }

        /* Animations */
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes fadeInSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Ripple Effect Base Styles */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
            pointer-events: none;
        }
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Scroll to top button */
        #backToTopBtn {
            display: none; /* Hidden by default */
            position: fixed; /* Fixed position */
            bottom: 30px; /* Place at bottom */
            right: 30px; /* Place at right */
            z-index: 999; /* High z-index to be on top */
            border: none; /* Remove borders */
            outline: none; /* Remove outline */
            background-color: #10b981; /* Set a background color */
            color: white; /* Text color */
            cursor: pointer; /* Add a mouse pointer on hover */
            padding: 15px; /* Some padding */
            border-radius: 50%; /* Rounded corners */
            font-size: 1.2rem; /* Increase font size */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s, transform 0.3s, opacity 0.3s;
            opacity: 0;
        }

        #backToTopBtn:hover {
            background-color: #059669; /* Add a darker background on hover */
            transform: translateY(-2px); /* Reduced lift */
        }
        #backToTopBtn.show {
            opacity: 1;
            display: block;
        }

        /* Toast notification (basic style) */
        .toast-notification {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1001;
            right: 30px;
            top: 100px; /* Adjust as needed */
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            opacity: 0;
            transition: opacity 0.5s, visibility 0s linear 0.5s; /* Delay visibility change */
        }
        .toast-notification.show {
            visibility: visible;
            opacity: 1;
            transition: opacity 0.5s;
        }
        /* Reduce motion for accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        /* Specific styles for rr.html content */
        :root {
            --color-white: #ffffff;
            --color-light-green: #e0ffe0; /* Latar belakang badge dan ilustrasi */
            --color-bright-green: #28a745; /* Warna teks GoRako, tombol, ikon */
            --color-text-dark: #333;
            --color-text-light: #555;
            --color-background-soft: #f8f8fa; /* Latar belakang body utama */
            --color-border-radius: 20px; /* Radius untuk elemen utama */
        }

        .rr-section {
            /* Pastikan background dari section ini sesuai dengan background body modules.php */
            background-color: var(--background-app-color); /* Menggunakan variabel dari modules.php */
            padding: 80px 4%; /* Match other sections' padding */
            margin: 50px auto; /* Same margin as game section */
            max-width: 1400px; /* Wider section */
            border-radius: 15px; /* Rounded corners for the section */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }

        .rr-section .main-container {
            display: flex;
            background-color: var(--color-white); /* Tetap putih agar desain asli terjaga */
            border-radius: var(--color-border-radius);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
            min-height: 550px;
        }


        .rr-section .left-panel {
            flex: 1;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }


        .rr-section .badge {
            background-color: var(--color-light-green);
            color: var(--color-bright-green);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 20px;
            align-self: flex-start;
        }


        .rr-section .hero-title {
            font-size: 2.8em;
            font-weight: 700;
            line-height: 1.2;
            margin: 0 0 10px 0;
            color: var(--color-text-dark);
        }

        .rr-section .hero-title .gorako-highlight {
            color: var(--color-bright-green);
        }

        .rr-section .sub-headline {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--color-bright-green);
            margin: 0 0 15px 0;
        }


        .rr-section .hero-description {
            font-size: 1.05em;
            color: var(--color-text-light);
            margin: 0 0 30px 0;
            max-width: 450px;
        }


        .rr-section .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px 30px;
            margin-top: 0;
        }

        .rr-section .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            color: var(--color-text-dark);
            font-weight: 500;
        }

        .rr-section .feature-item svg {
            width: 24px;
            height: 24px;
            fill: var(--color-bright-green);
        }


        .rr-section .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background-color: var(--color-bright-green);
            color: var(--color-white);
            padding: 15px 35px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 1.1em;
            font-weight: 600;
            margin-top: 30px;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            align-self: flex-start;
        }


        .rr-section .cta-button:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }

        .rr-section .cta-button svg {
            width: 20px;
            height: 20px;
            fill: var(--color-white);
        }

        .rr-section .right-panel {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: var(--color-light-green);
            padding: 30px;
        }


        .rr-section .illustration-container {
            width: 100%;
            max-width: 380px;
            height: 380px;
            border-radius: 50%;
            background-color: var(--color-white);
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }


        .rr-section .illustration-svg .main-board {
            fill: #f0f0f0;
            stroke: #ccc;
        }

        .rr-section .illustration-svg .recycle-icon {
            fill: var(--color-bright-green);
            stroke: var(--color-bright-green);
        }

        .rr-section .illustration-svg .misc-dot {
            fill: #ffeb3b;
            stroke: #ffeb3b;
        }
        .rr-section .illustration-svg .misc-bottle {
            fill: #6a8cff;
            stroke: #6a8cff;
        }
        .rr-section .illustration-svg .misc-triangle {
            fill: #333;
            stroke: #333;
        }
        /* Responsive Adjustments for rr-section */
        @media (max-width: 768px) {
            .rr-section .main-container {
                flex-direction: column;
                padding: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .rr-section .left-panel, .rr-section .right-panel {
                padding: 30px 25px;
            }

            .rr-section .hero-title {
                font-size: 2em;
                margin-bottom: 5px;
            }
            .rr-section .sub-headline {
                font-size: 1.1em;
                margin-bottom: 10px;
            }

            .rr-section .hero-description {
                font-size: 0.95em;
                margin-bottom: 25px;
            }

            .rr-section .features-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .rr-section .cta-button {
                width: 100%;
                text-align: center;
                justify-content: center;
            }

            .rr-section .right-panel {
                order: -1;
                border-radius: 0;
            }
            .rr-section .left-panel {
                border-radius: 0;
            }
            .rr-section .illustration-container {
                width: 280px;
                height: 280px;
            }
        }

        /* Game Invitation Toast Notification Styles */
        .game-invitation-toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary-app-color); /* Uses main app color */
            color: var(--white);
            padding: 15px 25px;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            z-index: 1002; /* Above other toasts */
            opacity: 0;
            visibility: hidden;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideOutDownGameToast 0.5s forwards; /* Default to hidden state */
            max-width: 90%; /* Responsive width */
            text-align: center;
        }

        .game-invitation-toast.show {
            animation: slideInUpGameToast 0.5s forwards;
            visibility: visible;
        }

        .game-toast-content {
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            flex-wrap: wrap; /* Allow content to wrap on smaller screens */
            justify-content: center; /* Center content when wrapped */
        }

        .game-toast-icon {
            font-size: 2.2rem;
            line-height: 1; /* Aligns icon better */
            color: #ffc107; /* Gold color for icon */
            animation: bounceInGameToast 1s ease-out; /* Bouncing animation for icon */
        }

        .game-toast-message {
            flex-grow: 1;
            font-size: 1.1em;
            font-weight: 500;
            margin-right: 15px; /* Space before CTA */
            line-height: 1.3;
        }
        .game-toast-message p {
            margin: 0;
            padding: 0;
        }
        .game-toast-message strong {
            font-weight: 700;
        }

        .game-toast-cta {
            flex-shrink: 0; /* Prevent button from shrinking */
            padding: 10px 20px;
            font-size: 0.95em;
            font-weight: 600;
            border-radius: 25px;
            text-decoration: none;
            box-shadow: none; /* No extra shadow, main toast has it */
            white-space: nowrap; /* Keep button text on one line */
            background-color: var(--white); /* White background for contrast */
            color: var(--primary-app-color); /* Text color from app primary */
        }

        .game-toast-cta:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        .game-toast-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
            margin-left: 10px; /* Space from CTA */
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }
        .game-toast-close:hover {
            opacity: 1;
        }

        @keyframes slideInUpGameToast {
            from {
                transform: translateX(-50%) translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideOutDownGameToast {
            from {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            to {
                transform: translateX(-50%) translateY(100%);
                opacity: 0;
            }
        }

        @keyframes bounceInGameToast {
            0%, 20%, 40%, 60%, 80%, 100% {
                transition-timing-function: cubic-bezier(0.215, 0.610, 0.355, 1.000);
            }
            0% { opacity: 0; transform: scale3d(0.3, 0.3, 0.3); }
            20% { transform: scale3d(1.1, 1.1, 1.1); }
            40% { transform: scale3d(0.9, 0.9, 0.9); }
            60% { opacity: 1; transform: scale3d(1.03, 1.03, 1.03); }
            80% { transform: scale3d(0.97, 0.97, 0.97); }
            100% { opacity: 1; transform: scale3d(1, 1, 1); }
        }

        @media (max-width: 600px) {
            .game-invitation-toast {
                flex-direction: column;
                padding: 15px;
                gap: 10px;
                bottom: 10px;
            }
            .game-toast-content {
                flex-direction: column;
                gap: 10px;
            }
            .game-toast-message {
                margin-right: 0;
            }
            .game-toast-cta {
                width: 100%;
                justify-content: center;
                margin-top: 5px;
            }
            .game-toast-close {
                position: absolute;
                top: 10px;
                right: 10px;
                margin-left: 0;
            }
        }

    </style>
</head>
<body>

    <nav class="navbar" role="navigation" aria-label="Main navigation">
        <div class="nav-container">
            <h1>
                <a href="index.php#hero-section" class="logo">
                    <img src="logo.png" alt="GoRako Logo" class="logo-image">
                    GoRako
                </a>
            </h1>

            <ul class="nav-menu" id="navMenuDesktop"> <li><a href="index.php" class="nav-link">Home</a></li>
                <li><a href="modules.php" class="nav-link">Edukasi</a></li>
                <li><a href="service_quiz.php" class="nav-link">Service</a></li>
                <?php if (is_logged_in()): ?>
                    <li class="nav-item points-display-item">
                        <a href="profile.php#points" class="nav-link points-link" title="Total Poin Anda">
                            <i class="fas fa-coins points-icon"></i>
                            <span class="points-value" id="total-points-display-value"><?php echo htmlspecialchars($loggedInUserPoints); ?> Poin</span>
                        </a>
                    </li>
                    <li class="nav-item dropdown profile-nav-item">
                        <a href="#" class="nav-link profile-dropdown-toggle">
                            <img src="<?php echo htmlspecialchars($userData['profile_picture'] ?? 'images/default_profile.png'); ?>" alt="Avatar Pengguna" class="profile-avatar">
                            <span class="profile-text"><?php echo htmlspecialchars($loggedInUsername); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </a>
                        <ul class="dropdown-menu profile-dropdown-menu">
                            <li>
                                <span class="points-info">
                                    <i class="fas fa-coins"></i> Poin Anda: <span id="profile-dropdown-points-value"><?php echo htmlspecialchars($loggedInUserPoints); ?></span>
                                </span>
                            </li>
                            <li><a href="profile.php">Profil Saya</a></li>
                            <li><a href="settings.php">Pengaturan</a></li>
                            <li><a href="leaderboard.php">Leaderboard</a></li>
                            <li><a href="logout.php" class="logout-button">Keluar</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><a href="login.php" class="cta-button">Mulai Aplikasi</a></li>
                <?php endif; ?>
            </ul>

            <div class="mobile-toggle" id="mobileToggle" aria-label="Toggle mobile navigation">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </nav>

    <div class="mobile-sidebar" id="mobileSidebar" aria-hidden="true">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-logo">
                <img src="logo.png" alt="GoRako Logo" class="sidebar-logo-image">
                GoRako
            </a>
            <button class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Tutup menu">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <ul class="sidebar-menu">
            <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
            <li><a href="modules.php"><i class="fas fa-book-open"></i> Edukasi</a></li>
            <li><a href="service_quiz.php"><i class="fas fa-tools"></i> Service</a></li>
            <?php if (is_logged_in()): ?>
                <li class="sidebar-profile-section">
                    <span class="points-info">
                        <i class="fas fa-coins"></i> Poin Anda: <?php echo htmlspecialchars($loggedInUserPoints); ?>
                    </span>
                    <a href="profile.php"><i class="fas fa-user-circle"></i> Profil Saya</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Pengaturan</a>
                    <a href="leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a>
                </li>
            <?php endif; ?>
        </ul>

        <?php if (is_logged_in()): ?>
            <a href="logout.php" class="sidebar-logout-button">Keluar</a>
        <?php else: ?>
            <a href="login.php" class="sidebar-logout-button">Masuk</a>
        <?php endif; ?>
    </div>
    <main>
        <section id="home" class="hero-banner">
            <div class="container">
                <h1>Belajar Kelola Sampah</h1>
                <p>Tingkatkan pengetahuan dan keterampilan pengelolaan sampah melalui modul interaktif dan dapatkan poin untuk setiap pembelajaran yang kamu selesaikan.</p>
                <div class="btn-group">
                    <a href="#learning-progress" class="btn btn-primary">Mulai Belajar</a>
                </div>
            </div>
        </section>

        <section id="learning-progress" class="learning-progress">
            <div class="container">
                <h2>Progres Modul Anda</h2>
                <div class="progress-bar-wrapper">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="progress-text">Modul Selesai: <span id="modules-completed-count">0</span>/10</div>
                </div>

                <div class="module-grid" id="progress-module-grid">
                    <div class="module-card" data-progress-id="1">
                        <h3>Modul 1</h3>
                        <p class="module-description">Pahami jenis-jenis sampah dan dampaknya terhadap lingkungan.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="1">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="2">
                        <h3>Modul 2</h3>
                        <p class="module-description">Pelajari Reduce, Reuse, Recycle dalam pengelolaan sampah.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="2">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="3">
                        <h3>Modul 3</h3>
                        <p class="module-description">Panduan praktis untuk memilah sampah rumah tangga.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="3">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="4">
                        <h3>Modul 4</h3>
                        <p class="module-description">Teknik membuat kompos dan proses daur ulang material.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="4">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="5">
                        <h3>Modul 5</h3>
                        <p class="module-description">Penanganan khusus untuk sampah bahan berbahaya dan beracun.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="5">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="6">
                        <h3>Modul 6</h3>
                        <p class="module-description">Memahami peraturan dan kebijakan terkait pengelolaan sampah.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="6">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="7">
                        <h3>Modul 7</h3>
                        <p class="module-description">Manajemen sampah di perkotaan dan pedesaan.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="7">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="8">
                        <h3>Modul 8</h3>
                        <p class="module-description">Peran masyarakat dalam pengelolaan sampah berkelanjutan.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="8">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="9">
                        <h3>Modul 9</h3>
                        <p class="module-description">Masa depan pengelolaan sampah: inovasi dan tantangan.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="9">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                    <div class="module-card" data-progress-id="10">
                        <h3>Modul 10</h3>
                        <p class="module-description">Praktik terbaik pengelolaan sampah di tingkat global.</p>
                        <div class="module-placeholder">
                            <span class="plus-icon">+</span>
                            <div class="progress-actions">
                                <a href="#" class="btn btn-primary btn-small start-progress-module" data-target-progress-module="10">Mulai Belajar</a>
                            </div>
                        </div>
                        <div class="module-status module-status-read" style="display: none;">
                            <i class="fas fa-book-reader"></i>
                            Modul Dibaca!
                        </div>
                        <div class="module-status module-status-video" style="display: none;">
                            <i class="fas fa-video"></i>
                            Video Selesai!
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="education-modules" class="education-modules">
            <div class="container">
                <h2>Modul Research</h2>
                <div class="module-cards-grid" id="edu-module-grid">
                    <?php if (count($limitedResearchModules) > 0): ?>
                        <?php $card_index = 0; ?>
                        <?php foreach ($limitedResearchModules as $module): ?>
                            <?php
                                $card_index++;
                                $hidden_class = ($card_index > 5) ? 'hidden-item' : '';
                                $read_duration = htmlspecialchars($module['estimated_minutes']) . ' menit membaca';
                                $is_completed = in_array($module['id'], $userCompletedModules['research']) ? 'true' : 'false';
                                $complete_btn_text = ($is_completed === 'true') ? 'Selesai Dibaca' : 'Mulai Baca';
                                $btn_disabled_style = ($is_completed === 'true') ? 'pointer-events: none; opacity: 0.7; background-color: var(--dark-green); border-color: var(--dark-green);' : '';

                                // Dapatkan target progress module ID dari mapping PHP
                                $target_progress_id_for_this_module = $moduleProgressMapping['research'][$module['id']] ?? null;

                                // DEBUGGING: Log jika modul tidak dipetakan
                                if ($target_progress_id_for_this_module === null) {
                                    error_log("DEBUG modules.php (HTML): Research module ID {$module['id']} has no mapping to progress module. Link will be incomplete.");
                                    // Beri nilai default atau lewati modul jika tidak ada mapping
                                    $target_progress_id_for_this_module = 'NOT_MAPPED'; // Atau atur ke 0, tergantung penanganan di module-detail.php
                                }
                            ?>
                            <div class="edu-module-card <?php echo $hidden_class; ?>"
                                 data-type="research"
                                 data-id="<?php echo htmlspecialchars($module['id']); ?>"
                                 data-points="<?php echo htmlspecialchars($module['points_reward']); ?>"
                                 data-target-progress-module="<?php echo htmlspecialchars($target_progress_id_for_this_module); ?>"
                                 data-duration="<?php echo htmlspecialchars($read_duration); ?>"
                                 data-title="<?php echo htmlspecialchars($module['title']); ?>">
                                <div class="module-header">
                                    <div class="module-icon">
                                        <i class="fas fa-book"></i> </div>
                                    <h3><?php echo htmlspecialchars($module['title']); ?></h3>
                                </div>
                                <div class="card-content">
                                    <p><?php echo htmlspecialchars($module['description']); ?></p>
                                    <div class="info">
                                        <span>
                                            <i class="fas fa-clock"></i>
                                            <?php echo $read_duration; ?>
                                        </span>
                                        <span class="points">
                                            <i class="fas fa-coins"></i>
                                            <?php echo htmlspecialchars($module['points_reward']); ?> poin
                                        </span>
                                    </div>
                                </div>
                                <a href="module-detail.php?id=<?php echo htmlspecialchars($module['id']); ?>&type=research&targetProgressModuleId=<?php echo htmlspecialchars($target_progress_id_for_this_module); ?>&title=<?php echo urlencode($module['title']); ?>&duration=<?php echo urlencode($read_duration); ?>&points=<?php echo urlencode($module['points_reward']); ?>"
                                   class="btn btn-primary complete-item" style="<?php echo $btn_disabled_style; ?>" data-completed="<?php echo $is_completed; ?>">
                                    <?php echo $complete_btn_text; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; width: 100%; grid-column: 1 / -1;">Belum ada modul riset yang tersedia.</p>
                    <?php endif; ?>
                </div>
                <div class="show-more-btn-container">
                    <button class="btn btn-secondary show-more-btn" data-target="edu-module-grid" data-initial-display="5">Lihat Semua Modul</button>
                </div>
            </div>
        </section>

        <section id="education-videos" class="education-videos">
            <div class="container">
                <h2>Modul Video</h2>
                <div class="video-grid" id="video-grid">
                    <?php if (count($limitedVideoModules) > 0): ?>
                        <?php $card_index = 0; ?>
                        <?php foreach ($limitedVideoModules as $video): ?>
                            <?php
                                $card_index++;
                                $hidden_class = ($card_index > 3) ? 'hidden-item' : ''; // Display initial 3
                                // Perbaikan/penekanan: Ganti thumbnail_src dengan ikon YouTube.
                                // Baris thumbnail_src = ... yang lama dihapus atau dikomentari.

                                // Format duration from minutes to HH:MM
                                $total_seconds = $video['duration_minutes'] * 60;
                                $duration_formatted = sprintf('%02d:%02d', floor($total_seconds/60), $total_seconds % 60);

                                $is_completed = in_array($video['id'], $userCompletedModules['video']) ? 'true' : 'false';
                                $complete_btn_text = ($is_completed === 'true') ? 'Sudah Ditonton' : 'Tonton Video';
                                $btn_disabled_style = ($is_completed === 'true') ? 'pointer-events: none; opacity: 0.7; background-color: var(--dark-green); border-color: var(--dark-green);' : '';

                                // Dapatkan target progress module ID dari mapping PHP
                                $target_progress_id_for_this_video = $moduleProgressMapping['video'][$video['id']] ?? null;

                                // DEBUGGING: Log jika modul tidak dipetakan
                                if ($target_progress_id_for_this_video === null) {
                                    error_log("DEBUG modules.php (HTML): Video module ID {$video['id']} has no mapping to progress module. Link will be incomplete.");
                                    // Beri nilai default atau lewati modul jika tidak ada mapping
                                    $target_progress_id_for_this_video = 'NOT_MAPPED'; // Atau atur ke 0
                                }
                            ?>
                            <div class="video-card <?php echo $hidden_class; ?>"
                                 data-type="video"
                                 data-id="<?php echo htmlspecialchars($video['id']); ?>"
                                 data-points="<?php echo htmlspecialchars($video['points_reward']); ?>"
                                 data-target-progress-module="<?php echo htmlspecialchars($target_progress_id_for_this_video); ?>"
                                 data-duration="<?php echo htmlspecialchars($duration_formatted); ?>"
                                 data-title="<?php echo htmlspecialchars($video['title']); ?>"
                                 data-thumbnail-src=""> <div class="thumbnail">
                                    <i class="fab fa-youtube youtube-icon"></i>
                                    <div class="play-button">
                                        <i class="fas fa-play"></i> </div>
                                    <div class="video-info-overlay">
                                        <span><?php echo $duration_formatted; ?></span>
                                        <span>N/A Tayangan</span> </div>
                                </div>
                                <div class="card-content">
                                    <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($video['description']); ?></p>
                                    <div class="video-points">
                                        <i class="fas fa-coins"></i>
                                        <?php echo htmlspecialchars($video['points_reward']); ?> poin
                                    </div>
                                </div>
                                <a href="module-detail.php?id=<?php echo htmlspecialchars($video['id']); ?>&type=video&targetProgressModuleId=<?php echo htmlspecialchars($target_progress_id_for_this_video); ?>&title=<?php echo urlencode($video['title']); ?>&duration=<?php echo urlencode($duration_formatted); ?>&points=<?php echo urlencode($video['points_reward']); ?>"
                                   class="btn btn-primary complete-item" style="<?php echo $btn_disabled_style; ?>" data-completed="<?php echo $is_completed; ?>">
                                    <?php echo $complete_btn_text; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; width: 100%; grid-column: 1 / -1;">Belum ada modul video yang tersedia.</p>
                    <?php endif; ?>
                </div>
                <div class="show-more-btn-container">
                    <button class="btn btn-secondary show-more-btn" data-target="video-grid" data-initial-display="3">Lihat Semua Modul</button>
                </div>
            </div>
        </section>

        <section id="eco-heroes-game" class="eco-heroes-game">
            <div class="container">
                <h2 class="game-title">GoRako: Trash Sorting Game Levels</h2>
                <?php if (empty($activeGameLevels)): ?>
                    <p class="game-description" style="text-align: center;">Maaf, belum ada level game yang tersedia saat ini. Silakan kembali lagi nanti!</p>
                <?php else: ?>
                    <p class="game-description" style="text-align: center;">Pilih level game yang ingin kamu mainkan dan uji kemampuanmu dalam memilah sampah!</p>
                    <div class="game-levels-grid">
                        <?php foreach ($activeGameLevels as $level): ?>
                            <div class="game-card">
                                <h3><?php echo htmlspecialchars($level['level_name']); ?></h3>
                                <p><?php echo htmlspecialchars($level['description']); ?></p>
                                <div class="game-info-details">
                                    <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($level['duration_seconds']); ?> detik</span>
                                    <span><i class="fas fa-coins"></i> <?php echo htmlspecialchars($level['points_per_correct_sort']); ?> poin/sortir</span>
                                </div>
                                <a href="game.php?level_id=<?php echo htmlspecialchars($level['id']); ?>" class="btn btn-primary">Start Level</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="rr-learn-section" class="rr-section">
            <div class="container">
                <div class="main-container">
                    <div class="left-panel">
                        <div class="badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-book-open">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                            </svg>
                            Platform Edukasi Digital
                        </div>

                        <h1 class="hero-title">Bermain Game Petualangan dengan <span class="gorako-highlight">GoRako</span></h1>

                        <p class="sub-headline">Game Petualangan Menyelamatkan Dunia</p>

                        <p class="hero-description">Bergabunglah dengan Gorako Game dalam petualangan seru untuk menyelamatkan dunia dari sampah! Lompat, berlari, dan kumpulkan sampah untuk didaur ulang sambil belajar tentang pentingnya menjaga lingkungan.</p>

                        <div class="features-grid">
                            <div class="feature-item">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-file-text">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <line x1="10" y1="9" x2="8" y2="9"></line>
                                </svg>
                                Materi Lengkap Berupa Game
                            </div>
                            <div class="feature-item">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-award">
                                    <circle cx="12" cy="8" r="7"></circle>
                                    <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>
                                </svg>
                                Dapat Point
                            </div>
                            <div class="feature-item">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-activity">
                                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                                </svg>
                                Kumpulkan dan pilah sampah dengan benar
                            </div>
                        </div>

                        <a href="game_petualangan.php" class="cta-button">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-send">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            Mulai Belajar Sekarang
                        </a>
                    </div>

                    <div class="right-panel">
                        <div class="illustration-container">
                            <svg class="illustration-svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet">
                                <rect x="20" y="30" width="60" height="40" rx="5" class="main-board"></rect>
                                <path d="M48 45 L52 45 L52 40 L50 38 L48 40 L48 45 Z M45 48 L40 48 L38 50 L40 52 L45 52 L45 48 Z M55 48 L60 48 L62 50 L60 52 L55 52 L55 48 Z M50 62 L48 60 L48 55 L52 55 L52 60 L50 62 Z" class="recycle-icon"></path>
                                <circle cx="50" cy="50" r="1" fill="#fff"></circle>

                                <circle cx="85" cy="75" r="5" class="misc-dot"></circle>
                                <path d="M85 70 L85 65 C88 62 90 65 90 70 L85 70 Z" class="misc-dot" stroke-width="1"></path>
                                <rect x="75" y="10" width="10" height="30" rx="5" class="misc-bottle"></rect>
                                <rect x="77" y="5" width="6" height="5" rx="3" class="misc-bottle"></rect>
                                <polygon points="10 20, 20 10, 30 20" class="misc-triangle" fill="#333" stroke="#333" stroke-width="0"></polygon>
                                <rect x="10" y="70" width="15" height="20" rx="5" fill="#4CAF50" stroke="#4CAF50" stroke-width="0"></rect>
                                <path d="M12 70 C12 65 23 65 23 70" fill="none" stroke="#4CAF50" stroke-width="2"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        </main>

     <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <h3>GoRako</h3>
                <p>Platform edukasi dan pengelolaan sampah untuk lingkungan yang lebih bersih dan berkelanjutan.</p>
                <h4>Ikuti Kami</h4>
                <div class="social-icons">
                    <a href="https://www.instagram.com/go.rako?igsh=Z2czNmVoejNubXl5" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div class="footer-contact">
                <h4>Informasi Kontak</h4>
                <p><i class="fas fa-envelope"></i> gorako.16.edukasi.sampah@gmail.com</p>
                <p><i class="fas fa-map-marker-alt"></i> Cikarang, Indonesia</p>
            </div>
        </div>
        <div class="footer-bottom">
            © 2025 GoRako. Hak Cipta Dilindungi Undang-Undang.
        </div>
    </footer>

    <button onclick="topFunction()" id="backToTopBtn" title="Kembali ke atas">↑</button>
    <div id="toastNotification" class="toast-notification"></div>

    <div id="gameInvitationToast" class="game-invitation-toast">
        <div class="game-toast-content">
            <span class="game-toast-icon"><i class="fas fa-gamepad"></i></span>
            <div class="game-toast-message">
                <p>Sudah siap menguji kemampuan daur ulangmu?</p>
                <p>Mainkan **GoRako Trash Sorting Game** sekarang!</p>
            </div>
            <a href="game.php" class="game-toast-cta btn-primary">Main Sekarang!</a>
            <button class="game-toast-close" aria-label="Tutup notifikasi"><i class="fas fa-times"></i></button>
        </div>
    </div>


    <script>
        // Data preferensi pengguna dari PHP
        const userAppearanceSettings = <?= $jsUserSettings ?>;
        // Data mapping konten modul ke progress modul dari PHP
        const globalContentMapping = <?= $jsContentMappingJson ?>;

        document.addEventListener('DOMContentLoaded', () => {
            // --- Theme and Appearance Application on Page Load ---
            const htmlElement = document.documentElement;

            // Mengambil nilai RGB dari accent_color untuk digunakan dalam rgba()
            function hexToRgb(hex) {
                const shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
                hex = hex.replace(shorthandRegex, function(m, r, g, b) {
                    return r + r + g + g + b + b;
                });
                const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
                return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '0, 123, 255'; // Default blue if invalid
            }

            // Removed applyTheme function as dark mode is no longer supported
            // Only apply font size and accent color
            function applyFontSize(size) {
                htmlElement.classList.remove('font-small', 'font-medium', 'font-large');
                htmlElement.classList.add(`font-${size}`);
            }

            function applyAccentColor(color) {
                document.body.style.setProperty('--primary-color-from-settings', color);
                document.body.style.setProperty('--primary-color-from-settings-rgb', hexToRgb(color)); // Menyimpan nilai RGB
            }

            // Load font size preference from localStorage first, then from PHP
            const savedFontSize = localStorage.getItem('font-size');
            if (savedFontSize) {
                applyFontSize(savedFontSize);
            } else {
                applyFontSize(userAppearanceSettings.font_size_preference);
            }

            // Apply accent color from PHP
            applyAccentColor(userAppearanceSettings.accent_color);


            // Mobile menu toggle (now specifically for the sidebar)
            const mobileToggle = document.getElementById('mobileToggle');
            const mobileSidebar = document.getElementById('mobileSidebar');
            const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
            const sidebarLinks = document.querySelectorAll('.sidebar-menu a'); // Links inside the sidebar

            if (mobileToggle && mobileSidebar && sidebarCloseBtn) {
                mobileToggle.addEventListener('click', () => {
                    mobileSidebar.classList.add('active'); // Add 'active' to show sidebar
                    mobileToggle.classList.add('active'); // Add 'active' to transform hamburger to 'X'
                    document.body.classList.add('sidebar-active'); // Add class to body for overlay
                    document.body.style.overflowY = 'hidden'; // Prevent scrolling when sidebar is open
                    mobileSidebar.setAttribute('aria-hidden', 'false'); // For accessibility
                });

                sidebarCloseBtn.addEventListener('click', () => {
                    mobileSidebar.classList.remove('active'); // Remove 'active' to hide sidebar
                    mobileToggle.classList.remove('active'); // Remove 'active' to transform 'X' back to hamburger
                    document.body.classList.remove('sidebar-active'); // Remove body class for overlay
                    document.body.style.overflowY = 'auto'; // Restore scrolling
                    mobileSidebar.setAttribute('aria-hidden', 'true'); // For accessibility
                });

                // Close sidebar when clicking on a link inside it
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', () => {
                        mobileSidebar.classList.remove('active');
                        mobileToggle.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                        document.body.style.overflowY = 'auto';
                        mobileSidebar.setAttribute('aria-hidden', 'true');
                    });
                });

                // Close sidebar when clicking outside it (on the overlay)
                document.body.addEventListener('click', function(event) {
                    // Check if the click is outside the sidebar and not on the toggle button
                    // AND the sidebar is currently active
                    if (mobileSidebar.classList.contains('active') &&
                        !mobileSidebar.contains(event.target) &&
                        !mobileToggle.contains(event.target)) {

                        mobileSidebar.classList.remove('active');
                        mobileToggle.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                        document.body.style.overflowY = 'auto';
                        mobileSidebar.setAttribute('aria-hidden', 'true');
                    }
                });
            }


            // Navbar scroll effect
            const navbar = document.querySelector('.navbar');
            if (navbar) {
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 50) {
                        navbar.style.background = 'rgba(255, 255, 255, 0.98)';
                        navbar.style.boxShadow = '0 2px 30px rgba(0, 0, 0, 0.15)';
                    } else {
                        navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                        navbar.style.boxShadow = '0 4px 25px rgba(0, 0, 0, 0.15)';
                    }
                });
            }

            // Ripple effect for buttons (menggunakan event delegation)
            document.body.addEventListener('click', function(e) {
                const target = e.target.closest('.btn-primary, .btn-secondary, .cta-button, .feature-cta, .cta-button-lg');
                if (target) {
                    const rect = target.getBoundingClientRect();
                    const circle = document.createElement('span');
                    const diameter = Math.max(target.clientWidth, target.clientHeight);
                    const radius = diameter / 2;

                    circle.style.width = circle.style.height = `${diameter}px`;
                    circle.style.left = `${e.clientX - rect.left - radius}px`;
                    circle.style.top = `${e.clientY - rect.top - radius}px`;
                    circle.classList.add('ripple');

                    const existingRipple = target.querySelector('.ripple');
                    if (existingRipple) {
                        existingRipple.remove();
                    }
                    target.appendChild(circle);

                    setTimeout(() => circle.remove(), 600);
                }
            });


            // Intersection Observer for scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const globalObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        if (!entry.target.classList.contains('visible')) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                            entry.target.classList.add('visible');
                        }
                    } else {
                        // Optional: reset element when out of viewport for re-animation on scroll back
                        // entry.target.style.opacity = '0';
                        // entry.target.style.transform = 'translateY(30px)';
                        // entry.target.classList.remove('visible');
                    }
                });
            }, observerOptions);

            const sectionHeaders = document.querySelectorAll('.section-header');
            sectionHeaders.forEach((header) => {
                globalObserver.observe(header);
            });

            const featureCards = document.querySelectorAll('.feature-card');
            featureCards.forEach((card, index) => {
                card.style.transitionDelay = `${index * 0.1}s`;
                globalObserver.observe(card);

                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    card.addEventListener('mousemove', function(e) {
                        const rect = this.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        const centerX = rect.width / 2;
                        const centerY = rect.height / 2;
                        const rotateX = (y - centerY) / 25;
                        const rotateY = (centerX - x) / 25;
                        this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-10px) scale(1.01)`;
                    });

                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0) scale(1)';
                    });
                }
            });

            const ctaSectionContent = document.querySelector('.cta-section-content');
            if (ctaSectionContent) {
                globalObserver.observe(ctaSectionContent);
            }

            const questionsSection = document.querySelector('.questions-section');
            if (questionsSection) {
                globalObserver.observe(questionsSection);
            }

            const explanationSection = document.querySelector('.explanation-section');
            if (explanationSection) {
                globalObserver.observe(explanationSection);
            }

            // Staggered animation for hero section content
            const heroTitle = document.querySelector('.hero-banner h1');
            const heroParagraph = document.querySelector('.hero-banner p');
            const heroButtons = document.querySelector('.hero-banner .btn-group');

            if (heroTitle) {
                heroTitle.style.animation = 'fadeInSlideUp 0.8s ease-out forwards';
            }
            if (heroParagraph) {
                heroParagraph.style.animation = 'fadeInSlideUp 0.8s ease-out 0.3s forwards';
            }
            if (heroButtons) {
                heroButtons.style.animation = 'fadeInSlideUp 0.8s ease-out 0.6s forwards';
            }

            const allFeatureCards = document.querySelectorAll('.feature-card');
            allFeatureCards.forEach(card => {
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Parallax Effect for Hero Background Elements
            const parallaxElements = document.querySelectorAll('.hero .bg-element');
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                window.addEventListener('scroll', function() {
                    let scrollPosition = window.pageYOffset;
                    parallaxElements.forEach(element => {
                        const speed = parseFloat(element.dataset.parallaxSpeed);
                        const yPos = -(scrollPosition * speed);
                        element.style.transform = `translateY(${yPos}px)`;
                    });
                });
            }

            // Back to top button functionality
            const backToTopBtn = document.getElementById("backToTopBtn");

            window.onscroll = function() {scrollFunction()};

            function scrollFunction() {
                if (document.body.scrollTop > 200 || document.documentElement.scrollTop > 200) {
                    backToTopBtn.classList.add('show');
                } else {
                    backToTopBtn.classList.remove('show');
                }
            }

            window.topFunction = function() {
                window.scrollTo({top: 0, behavior: 'smooth'});
                document.documentElement.scrollTo({top: 0, behavior: 'smooth'});
            }

            // Basic Toast Notification Function
            function showToast(message, type = 'info') {
                const toast = document.getElementById("toastNotification");
                // Reset class list to ensure only current type is applied
                toast.className = 'toast-notification';
                toast.textContent = message;
                toast.classList.add(type);
                toast.classList.add("show");
                setTimeout(function(){
                    toast.classList.remove("show");
                }, 3000);
            }

            const moduleProgressCards = document.querySelectorAll('.learning-progress .module-card');
            const modulesCompletedCountSpan = document.getElementById('modules-completed-count');
            const progressBarFill = document.querySelector('.progress-fill');
            const totalModulesInProgress = moduleProgressCards.length;
            const totalPointsDisplayNavbar = document.getElementById('total-points-display-value');
            const profileDropdownPoints = document.getElementById('profile-dropdown-points-value');


            let completedProgressModules = new Set();
            let totalActualCompletedModules = 0;

            function initializeProgressAndPoints() {
                completedProgressModules.clear();

                const currentTime = Date.now();
                const today = new Date();
                today.setHours(0, 0, 0, 0); // Set today to midnight (start of the day)

                // Pertama: Periksa dan reset item yang kadaluarsa di localStorage
                for (const key in globalContentMapping) {
                    const completedAt = localStorage.getItem(`${key}-completed-at`);
                    if (completedAt) {
                        const timestamp = parseInt(completedAt, 10);
                        const completedDate = new Date(timestamp);
                        completedDate.setHours(0, 0, 0, 0); // Set completed date to midnight

                        // Check if the completed date is before today's date
                        // This means at least one midnight has passed since completion
                        if (completedDate.getTime() < today.getTime()) {
                            localStorage.removeItem(`${key}-completed`);
                            localStorage.removeItem(`${key}-completed-at`);
                            // Juga reset status sumber jika ada
                            const progressModuleId = globalContentMapping[key]?.progressModuleId;
                            if (progressModuleId) {
                                localStorage.removeItem(`progress-module-${progressModuleId}-source`);
                            }
                        }
                    }
                }

                // Kedua: Hitung progres berdasarkan item yang tidak kadaluarsa
                for (const key in globalContentMapping) {
                    if (localStorage.getItem(key + '-completed') === 'true') { // Fixed: using string concatenation for key
                        const itemDetails = globalContentMapping[key];
                        // Pastikan itemDetails memiliki progressModuleId yang valid
                        if (itemDetails && itemDetails.progressModuleId) {
                            completedProgressModules.add(itemDetails.progressModuleId);

                            // Set/update the source for the progress card in localStorage
                            // Prioritize 'research' if both are completed for the same progress ID
                            const existingSource = localStorage.getItem(`progress-module-${itemDetails.progressModuleId}-source`);
                            if (!existingSource || (existingSource === 'video' && itemDetails.moduleType === 'research')) {
                                localStorage.setItem(`progress-module-${itemDetails.progressModuleId}-source`, itemDetails.moduleType);
                            }
                        }
                    }
                }

                totalActualCompletedModules = completedProgressModules.size;

                // Update the state of progress module cards based on completedProgressModules
                moduleProgressCards.forEach(card => {
                    const progressModuleId = parseInt(card.dataset.progressId, 10);
                    const startButton = card.querySelector('.start-progress-module');
                    const modulePlaceholder = card.querySelector('.module-placeholder');
                    const moduleDescription = card.querySelector('.module-description');
                    const statusRead = card.querySelector('.module-status-read');
                    const statusVideo = card.querySelector('.module-status-video');

                    // Reset all status displays (before applying new ones)
                    if (statusRead) statusRead.style.display = 'none';
                    if (statusVideo) statusVideo.style.display = 'none';
                    statusRead.classList.remove('read-completed');
                    statusVideo.classList.remove('video-completed');

                    if (completedProgressModules.has(progressModuleId)) {
                        card.classList.add('completed');
                        if (modulePlaceholder) modulePlaceholder.style.display = 'none';
                        if (moduleDescription) moduleDescription.style.display = 'block';
                        if (startButton) startButton.style.display = 'none';

                        const finalSource = localStorage.getItem(`progress-module-${progressModuleId}-source`);
                        if (finalSource === 'research' && statusRead) {
                            statusRead.style.display = 'flex';
                            statusRead.classList.add('read-completed');
                        } else if (finalSource === 'video' && statusVideo) {
                            statusVideo.style.display = 'flex';
                            statusVideo.classList.add('video-completed');
                        }
                    } else {
                        card.classList.remove('completed');
                        if (modulePlaceholder) modulePlaceholder.style.display = 'flex';
                        if (moduleDescription) moduleDescription.style.display = 'none'; // Ensure description is hidden if not completed
                        if (startButton) startButton.style.display = 'inline-block';
                    }
                });

                updateProgressBar();
                updateCompleteItemButtons();
            }

            // Call initialize on load
            initializeProgressAndPoints();

            // Check for URL hash and scroll if present (for returning from detail page)
            if (window.location.hash) {
                const targetSection = document.querySelector(window.location.hash);
                if (targetSection) {
                    const navbarOffset = document.querySelector('.navbar').offsetHeight;
                    const elementPosition = targetSection.getBoundingClientRect().top + window.pageYOffset;
                    let offsetPosition = elementPosition - navbarOffset;

                    if (window.location.hash === '#home') {
                        offsetPosition = 0;
                    }

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: "smooth"
                    });
                }
            }

            function updateCompleteItemButtons() {
                document.querySelectorAll('.complete-item').forEach(button => {
                    const card = button.closest('.edu-module-card') || button.closest('.video-card');
                    const itemId = card.dataset.id;
                    const itemType = card.dataset.type;
                    const mapKey = `${itemType}-${itemId}`;

                    if (localStorage.getItem(mapKey + '-completed') === 'true') {
                        button.textContent = (itemType === 'research' ? 'Selesai Dibaca' : 'Sudah Ditonton');
                        button.style.pointerEvents = 'none';
                        button.style.opacity = '0.7';
                        button.style.backgroundColor = 'var(--dark-green)';
                        button.style.borderColor = 'var(--dark-green)';
                    } else {
                        button.textContent = (itemType === 'research' ? 'Mulai Baca' : 'Tonton Video');
                        button.style.pointerEvents = 'auto';
                        button.style.opacity = '1';
                        // Reset background and border to default (might be overridden by other styles if not specific)
                        button.style.backgroundColor = '';
                        button.style.borderColor = '';
                    }
                });
            }

            document.querySelectorAll('.learning-progress .module-card .start-progress-module').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetProgressModuleId = parseInt(this.dataset.targetProgressModule, 10);

                    let targetContentElement = null;
                    let contentToPass = null; // Ini akan berisi detail modul dari globalContentMapping

                    // Temukan modul yang terkait dengan progressModuleId ini
                    // Prioritaskan research jika ada, lalu video
                    for (const key in globalContentMapping) {
                        const item = globalContentMapping[key];
                        if (item.progressModuleId === targetProgressModuleId) {
                            // Find the actual DOM element for the module card
                            const currentContentElement = document.querySelector(`[data-type="${item.moduleType}"][data-id="${key.split('-')[1]}"]`);
                            if (currentContentElement) {
                                // Prefer research modules if available for this progress ID
                                if (item.moduleType === 'research') {
                                    targetContentElement = currentContentElement;
                                    contentToPass = item;
                                    break;
                                }
                                // If research not found yet, take video
                                if (!targetContentElement && item.moduleType === 'video') {
                                    targetContentElement = currentContentElement;
                                    contentToPass = item;
                                }
                            }
                        }
                    }

                    if (targetContentElement && contentToPass) {
                        // Perbaiki URL untuk mengarah ke module-detail.php dengan semua parameter yang diperlukan
                        const urlParams = new URLSearchParams();
                        urlParams.append('id', targetContentElement.dataset.id);
                        urlParams.append('type', targetContentElement.dataset.type);
                        urlParams.append('title', encodeURIComponent(contentToPass.title));
                        urlParams.append('duration', encodeURIComponent(contentToPass.duration));
                        urlParams.append('points', encodeURIComponent(contentToPass.points));
                        urlParams.append('targetProgressModuleId', encodeURIComponent(targetProgressModuleId));

                        const detailUrl = `module-detail.php?${urlParams.toString()}`;

                        // Jika modul tersembunyi, tampilkan dulu, lalu scroll
                        if (targetContentElement.classList.contains('hidden-item')) {
                            const showMoreBtn = document.querySelector(`.show-more-btn[data-target="${targetContentElement.closest('.module-cards-grid, .video-grid').id}"]`);
                            if (showMoreBtn && showMoreBtn.textContent.includes('Lihat Semua')) {
                                showMoreBtn.click();
                            }
                            // Setelah ditampilkan, scroll dan navigasi
                            setTimeout(() => {
                                targetContentElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                // Setelah scroll, navigasi ke halaman detail
                                setTimeout(() => {
                                    window.location.href = detailUrl;
                                }, 500);
                            }, 300);
                        } else {
                            // Langsung scroll dan navigasi jika tidak tersembunyi
                            targetContentElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(() => {
                                window.location.href = detailUrl;
                            }, 300);
                        }

                    } else {
                        // Debugging tambahan jika contentToPass atau targetContentElement null
                        console.error('ERROR (start-progress-module): Content or target element not found for progress ID', targetProgressModuleId, 'globalContentMapping:', globalContentMapping);
                        showToast('Konten pembelajaran untuk modul ini belum tersedia atau disembunyikan. Silakan hubungi administrator.', 'info');
                    }
                });
            });

            // Event listener untuk tombol 'complete-item' pada modul edukasi dan video
            document.querySelectorAll('.complete-item').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (this.dataset.completed === 'true') {
                        showToast(`Anda sudah menyelesaikan modul ini.`, 'info');
                        return;
                    }

                    const card = this.closest('.edu-module-card') || button.closest('.video-card');
                    const itemId = card.dataset.id;
                    const itemType = card.dataset.type;
                    const title = card.dataset.title;
                    const duration = card.dataset.duration;
                    const points = card.dataset.points;
                    const targetProgressModuleId = card.dataset.targetProgressModule;

                    if (!targetProgressModuleId || targetProgressModuleId === 'NOT_MAPPED' || parseInt(targetProgressModuleId, 10) <= 0) {
                        console.error('Error: targetProgressModuleId not found or not mapped for this module.', {itemId, itemType, targetProgressModuleId});
                        showToast('Error: ID progres modul tidak dapat ditentukan. Silakan hubungi administrator.', 'error');
                        return;
                    }

                    const urlParams = new URLSearchParams();
                    urlParams.append('id', itemId);
                    urlParams.append('type', itemType);
                    urlParams.append('title', encodeURIComponent(title));
                    urlParams.append('duration', encodeURIComponent(duration));
                    urlParams.append('points', encodeURIComponent(points));
                    urlParams.append('targetProgressModuleId', encodeURIComponent(targetProgressModuleId));

                    window.location.href = `module-detail.php?${urlParams.toString()}`;
                });
            });

            function updateProgressBar() {
                const percentage = (totalActualCompletedModules / totalModulesInProgress) * 100;
                progressBarFill.style.width = `${percentage}%`;
                modulesCompletedCountSpan.textContent = totalActualCompletedModules;
            }

            // Fungsi untuk memperbarui tampilan poin di navbar dan dropdown profil
            function updateTotalPointsDisplay(newTotalPoints) {
                if (totalPointsDisplayNavbar) {
                    totalPointsDisplayNavbar.textContent = `${newTotalPoints} Poin`;
                }
                if (profileDropdownPoints) {
                    profileDropdownPoints.textContent = newTotalPoints;
                }
            }


            // Moved observer and related functions to inside DOMContentLoaded to ensure elements exist
            const modulePageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in-up');
                    } else {
                        // Optional: Reset element when it leaves viewport if you want re-animation
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.module-card, .edu-module-card, .video-card, .progress-bar-wrapper, .eco-heroes-game, .game-card, .rr-section').forEach(el => {
                el.classList.add('fade-in-hidden');
                modulePageObserver.observe(el);
            });

            // Initial hide logic for dynamically loaded modules
            const eduModuleGrid = document.getElementById('edu-module-grid');
            const videoGrid = document.getElementById('video-grid');

            if (eduModuleGrid) {
                Array.from(eduModuleGrid.children).forEach((card, index) => {
                    if (index >= 5) {
                        card.classList.add('hidden-item');
                        card.style.display = 'none';
                    }
                });
            }

            if (videoGrid) {
                Array.from(videoGrid.children).forEach((card, index) => {
                    if (index >= 3) {
                        card.classList.add('hidden-item');
                        card.style.display = 'none';
                    }
                    });
            }


            document.querySelectorAll('.show-more-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const targetGridId = this.dataset.target;
                    const initialDisplayCount = parseInt(this.dataset.initialDisplay, 10);
                    const grid = document.getElementById(targetGridId);
                    const allItems = Array.from(grid.children);

                    if (this.textContent.includes('Lihat Semua')) {
                        allItems.forEach(item => {
                            if (item.classList.contains('hidden-item')) {
                                item.style.display = 'flex';
                                item.classList.remove('hidden-item');
                                modulePageObserver.observe(item);
                            }
                        });
                        this.textContent = 'Sembunyikan Beberapa';
                    } else {
                        allItems.forEach((item, index) => {
                            if (index >= initialDisplayCount) {
                                item.style.display = 'none';
                                item.classList.add('hidden-item');
                                modulePageObserver.unobserve(item);
                            }
                        });
                        grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        this.textContent = `Lihat Semua ${targetGridId === 'edu-module-grid' ? 'Modul' : 'Video'}`;
                    }
                });
            });

            // Poin ini penting: Mendengarkan event dari localStorage
            window.addEventListener('storage', (event) => {
                if (event.key === 'total_points_updated') {
                    const newTotalPoints = JSON.parse(event.newValue);
                    updateTotalPointsDisplay(newTotalPoints);
                    initializeProgressAndPoints();
                }
            });

            updateTotalPointsDisplay(<?php echo $loggedInUserPoints; ?>);

            // --- Game Invitation Toast Notification Logic ---
            const gameInvitationToast = document.getElementById('gameInvitationToast');
            const gameToastCloseBtn = gameInvitationToast.querySelector('.game-toast-close');
            const LAST_SHOWN_KEY = 'lastGameInvitationShown';
            const SHOW_INTERVAL = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

            function showGameInvitation() {
                const lastShown = localStorage.getItem(LAST_SHOWN_KEY);
                const now = Date.now();

                // Only show if it hasn't been shown recently
                if (!lastShown || (now - parseInt(lastShown, 10) > SHOW_INTERVAL)) {
                    gameInvitationToast.classList.add('show');
                    localStorage.setItem(LAST_SHOWN_KEY, now.toString());

                    // Auto-hide after 10 seconds if not interacted with
                    setTimeout(() => {
                        hideGameInvitation();
                    }, 10000); // 10 seconds
                }
            }

            function hideGameInvitation() {
                gameInvitationToast.classList.remove('show');
                // You might want to update LAST_SHOWN_KEY here too if user closes it early
                // localStorage.setItem(LAST_SHOWN_KEY, Date.now().toString());
            }

            // Close button event listener
            if (gameToastCloseBtn) {
                gameToastCloseBtn.addEventListener('click', hideGameInvitation);
            }

            // Show game invitation after a delay (e.g., 5 seconds after page load)
            setTimeout(() => {
                showGameInvitation();
            }, 5000);
        });
    </script>
</body>
</html>