<?php
require_once 'db_connection.php'; // Include the database connection and helper functions
require_once 'helpers.php';       // Pastikan helpers.php ada (is_logged_in, redirect)

// If user is NOT logged in, redirect them to the login page
if (!is_logged_in()) {
    redirect('login.php'); // Redirect to login.php if not logged in
}

// At this point, the user is logged in. You can access session variables:
$loggedInUserId = $_SESSION['user_id'];
$loggedInUsername = $_SESSION['username'];

// Fetch user data, including profile picture, theme/appearance preferences, and TOTAL POINTS
$userData = [];
$userSettings = []; // New variable to hold appearance settings
$loggedInUserPoints = 0; // Initialize points

// UBAH: Query untuk mengambil data pengguna, sekarang mengambil kolom 'total_points'
$query = "SELECT id, username, email, profile_picture,
                 theme_preference, accent_color, font_size_preference, total_points  -- Mengambil total_points
          FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $userData = $result->fetch_assoc();
        // Remove theme_preference as it's no longer used for dark/light mode
        $userSettings['theme_preference'] = 'light'; // Default to light, as dark mode is removed
        $userSettings['accent_color'] = $userData['accent_color'] ?? '#007bff';
        $userSettings['font_size_preference'] = $userData['font_size_preference'] ?? 'medium';
        $loggedInUserPoints = $userData['total_points'] ?? 0; // UBAH: Mengambil nilai dari 'total_points'
    }
    $stmt->close();
} else {
    error_log("Failed to prepare user data query: " . $conn->error);
}


// Data sampah Jawa Barat tahun 2024
$jawaBaratWasteData = [
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Bogor', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Cianjur', 'Rumah Tangga (ton)' => 828.63, 'Perkantoran (ton)' => 109.09, 'Pasar (ton)' => 21.39, 'Perniagaan (ton)' => 12.30, 'Fasilitas Publik (ton)' => 32.91, 'Kawasan (ton)' => 62.87, 'Lain (ton)' => 978.00],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Bandung', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Garut', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Tasikmalaya', 'Rumah Tangga (ton)' => 406.20, 'Perkantoran (ton)' => 60.00, 'Pasar (ton)' => 260.00, 'Perniagaan (ton)' => 106.00, 'Fasilitas Publik (ton)' => 11.00, 'Kawasan (ton)' => 60.00, 'Lain (ton)' => 28.00],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Ciamis', 'Rumah Tangga (ton)' => 245.50, 'Perkantoran (ton)' => 20.04, 'Pasar (ton)' => 85.17, 'Perniagaan (ton)' => 85.17, 'Fasilitas Publik (ton)' => 25.05, 'Kawasan (ton)' => 20.04, 'Lain (ton)' => 20.04],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Cirebon', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Sumedang', 'Rumah Tangga (ton)' => 109.88, 'Perkantoran (ton)' => null, 'Pasar (ton)' => 24.01, 'Perniagaan (ton)' => 1.41, 'Fasilitas Publik (ton)' => 0.83, 'Kawasan (ton)' => 6.28, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Indramayu', 'Rumah Tangga (ton)' => 954.67, 'Perkantoran (ton)' => 13.64, 'Pasar (ton)' => 85.24, 'Perniagaan (ton)' => 23.87, 'Fasilitas Publik (ton)' => 19.32, 'Kawasan (ton)' => 18.18, 'Lain (ton)' => 21.59],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Purwakarta', 'Rumah Tangga (ton)' => 139.00, 'Perkantoran (ton)' => 3.00, 'Pasar (ton)' => 13.00, 'Perniagaan (ton)' => 3.00, 'Fasilitas Publik (ton)' => 2.00, 'Kawasan (ton)' => null, 'Lain (ton)' => 3.00],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Karawang', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kab. Bekasi', 'Rumah Tangga (ton)' => 711.99, 'Perkantoran (ton)' => null, 'Pasar (ton)' => 163.06, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => 31.00],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kota Bogor', 'Rumah Tangga (ton)' => 394.59, 'Perkantoran (ton)' => 75.54, 'Pasar (ton)' => 90.78, 'Perniagaan (ton)' => 113.30, 'Fasilitas Publik (ton)' => 22.66, 'Kawasan (ton)' => 37.77, 'Lain (ton)' => 20.39],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kota Bandung', 'Rumah Tangga (ton)' => 60.00, 'Perkantoran (ton)' => 4.00, 'Pasar (ton)' => 10.00, 'Perniagaan (ton)' => 6.00, 'Fasilitas Publik (ton)' => 13.30, 'Kawasan (ton)' => 5.00, 'Lain (ton)' => 1.70],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kota Cirebon', 'Rumah Tangga (ton)' => 104.41, 'Perkantoran (ton)' => 1.27, 'Pasar (ton)' => 8.01, 'Perniagaan (ton)' => 58.00, 'Fasilitas Publik (ton)' => 22.09, 'Kawasan (ton)' => 13.89, 'Lain (ton)' => 8.68],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kota Cimahi', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
    ['Tahun' => 2024, 'Provinsi' => 'Jawa Barat', 'Kabupaten/Kota' => 'Kota Tasikmalaya', 'Rumah Tangga (ton)' => null, 'Perkantoran (ton)' => null, 'Pasar (ton)' => null, 'Perniagaan (ton)' => null, 'Fasilitas Publik (ton)' => null, 'Kawasan (ton)' => null, 'Lain (ton)' => null],
];


// Convert PHP data to JSON for JavaScript
$jsUserSettings = json_encode($userSettings);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="GoRako: Platform cerdas untuk daur ulang sampah, edukasi lingkungan, dan hadiah menarik. Mulai hidup lebih hijau sekarang!">
    <meta name="keywords" content="GoRako, daur ulang sampah, aplikasi daur ulang, edukasi lingkungan, pilah sampah, hadiah daur ulang, gaya hidup hijau">
    <meta name="author" content="Tim GoRako">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.gorako.com/index.php">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="theme-color" content="#10b981">

    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.gorako.com/index.php">
    <meta property="og:title" content="GoRako - Mulai Hidup Lebih Hijau">
    <meta property="og:description" content="GoRako: Platform cerdas untuk daur ulang sampah, edukasi lingkungan, dan hadiah menarik. Mulai hidup lebih hijau sekarang!">
    <meta property="og:image" content="https://www.gorako.com/images/gorako-social.jpg">

    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://www.gorako.com/index.php">
    <meta property="twitter:title" content="GoRako - Mulai Hidup Lebih Hijau">
    <meta property="twitter:description" content="GoRako: Platform cerdas untuk daur ulang sampah, edukasi lingkungan, dan hadiah menarik. Mulai hidup lebih hijau sekarang!">
    <meta property="twitter:image" content="https://www.gorako.com/images/gorako-social.jpg">

    <title>GoRako - Mulai Hidup Lebih Hijau</title>

    <link rel="icon" href="images/favicon.png" type="image/png">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" href="https://fonts.gstatic.com/s/poppins/v20/pxiByp8kv8JHgMjkFdXHzg.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="logo.png" as="image"> <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

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

        .profile-dropdown-menu li:not(:last-child) {
            border-bottom: 1px solid #eee; /* Separator */
            padding-bottom: 5px;
            margin-bottom: 5px;
        }

        .profile-dropdown-menu a {
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


        /* Hero Section (Index Page Specific) */
        .hero {
            min-height: calc(100vh - 80px);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #e0f2fe 0%, #b3e5fc 100%); /* Lighter blue gradient */
            padding: 2rem;
        }

        /* Custom Floating Background Elements for Hero */
        .hero .bg-element { /* General class for parallax */
            position: absolute;
            z-index: 0;
            pointer-events: none;
            will-change: transform; /* Hint browser for animation */
        }
        .hero .bg-circle-1 {
            width: 250px; height: 250px;
            background: rgba(16, 185, 129, 0.1); /* Greenish circle */
            border-radius: 50%;
            top: 10%; left: -5%;
            animation: floatBubble 20s infinite ease-in-out;
            filter: blur(50px); /* Soft blur effect */
        }
        .hero .bg-circle-2 {
            width: 300px; height: 300px;
            background: rgba(59, 130, 246, 0.1); /* Bluish circle */
            border-radius: 50%;
            bottom: 15%; right: -8%;
            animation: floatBubble 25s infinite ease-in-out reverse;
            filter: blur(60px);
        }
        .hero .bg-recycle-icon {
            font-size: 8rem;
            color: rgba(46, 204, 113, 0.1); /* Faint green recycle icon */
            top: 20%; right: 10%;
            animation: rotateFade 30s infinite linear;
        }
        .hero .bg-leaf-icon {
            font-size: 6rem;
            color: rgba(22, 97, 14, 0.1); /* Faint dark green leaf icon */
            bottom: 10%; left: 15%;
            animation: rotateFade 25s infinite linear reverse;
        }

        @keyframes floatBubble {
            0%, 100% { transform: translateY(0) translateX(0); }
            50% { transform: translateY(-30px) translateX(30px); }
        }
        @keyframes rotateFade {
            0% { transform: rotate(0deg) scale(0.8); opacity: 0.8; }
            50% { transform: rotate(180deg) scale(1.0); opacity: 0.9; }
            100% { transform: rotate(360deg) scale(0.8); opacity: 0.8; }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            /* Animasi diatur oleh JS untuk staggered effect */
        }

        .hero-content h1 {
            font-size: 4.2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1.5rem;
            /* background: linear-gradient(135deg, #005662, #00838f, #00acc1); */ /* Overridden by specific selector now */
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 3px 3px 8px rgba(0,0,0,0.2); /* Stronger shadow */
            opacity: 0; /* Tersembunyi untuk animasi */
            transform: translateY(20px);
        }

        .hero-content p {
            font-size: 1.4rem;
            color: #4f5b62; /* Darker text for readability */
            margin-bottom: 2.5rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.05);
            opacity: 0; /* Tersembunyi untuk animasi */
            transform: translateY(20px);
        }

        .hero-buttons {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            opacity: 0; /* Tersembunyi untuk animasi */
            transform: translateY(20px);
        }

        /* Buttons with enhanced hover for hero section */
        .btn-primary, .btn-secondary {
            padding: 1rem 2rem;
            border-radius: 30px; /* More rounded */
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.8rem; /* Increased gap */
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            font-size: 1.1rem; /* Slightly larger text */
        }

        .btn-primary {
            /* background: linear-gradient(135deg, #10b981, #059669); */ /* Overridden by specific selector now */
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #3f51b5, #283593); /* Darker blue */
            color: white;
        }

        .btn-primary:hover {
            /* transform: translateY(-5px) scale(1.02); */ /* Perubahan di sini */
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4), 0 0 15px rgba(16, 185, 129, 0.5); /* Reduced shadow + glow */
        }

        .btn-secondary:hover {
            /* transform: translateY(-5px) scale(1.02); */ /* Perubahan di sini */
            box-shadow: 0 10px 25px rgba(63, 81, 181, 0.4), 0 0 15px rgba(63, 81, 181, 0.5); /* Reduced shadow + glow */
        }

        /* Shimmer effect for primary/secondary buttons on hover */
        .btn-primary::before, .btn-secondary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.2); /* White shimmer */
            transition: left 0.4s ease;
            transform: skewX(-20deg); /* Angled shimmer */
            z-index: 1; /* Above text/icon but below main button content for ripple */
        }

        .btn-primary:hover::before, .btn-secondary:hover::before {
            left: 100%;
        }
        /* Ensure text and icon are above shimmer */
        .btn-primary i, .btn-primary span,
        .btn-secondary i, .btn-secondary span {
            position: relative;
            z-index: 2;
        }

        /* Wavy divider style */
        .wavy-divider {
            width: 100%;
            height: 50px; /* Adjust height as needed */
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 50" preserveAspectRatio="none"><path fill="%23FFFFFF" d="M0,30 C200,60 300,0 500,30 C700,60 800,0 1000,30 L1000,50 L0,50 Z"></path></svg>') repeat-x bottom;
            transform: translateY(-1px); /* Avoid gap with section above */
            z-index: 1;
            position: relative;
        }
        .wavy-divider.top {
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 50" preserveAspectRatio="none"><path fill="%23FFFFFF" d="M0,20 C200,-10 300,70 500,20 C700,-30 800,70 1000,20 L1000,0 L0,0 Z"></path></svg>') repeat-x top;
            transform: translateY(1px);
        }

        /* Features Section */
        .features-section {
            padding: 5rem 0;
            background-color: #FFFFFF;
            position: relative;
            overflow: hidden;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(ellipse at 10% 90%, rgba(0, 174, 239, 0.03) 0%, transparent 50%),
                        radial-gradient(ellipse at 90% 10%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .features-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
        }

        .section-header {
            margin-bottom: 4rem;
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .section-header.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Existing section header for other sections (features, waste data, questions, cta) */
        .section-header h2 { /* This is the general section-header h2 */
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #1f2937;
            background: linear-gradient(135deg, #2D4F2B, #16610E);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-header p { /* This is the general section-header p */
            font-size: 1.1rem;
            color: #6b7280;
            max-width: 700px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr); /* 1 row, 4 features */
            gap: 3rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05), 0 15px 30px rgba(0, 0, 0, 0.08); /* Layered shadow */
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
            opacity: 0;
            transform: translateY(40px);
        }

        .feature-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08), 0 20px 40px rgba(0, 0, 0, 0.12); /* Slightly reduced stronger shadow on hover */
            border-color: rgba(16, 185, 129, 0.3);
        }

        .feature-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #cffafe, #e0f2fe);
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2.5rem auto;
            box-shadow: 0 10px 30px rgba(0, 174, 239, 0.2);
            position: relative;
            transition: all 0.4s ease;
        }

        .feature-icon i {
            font-size: 3rem;
            /* background: linear-gradient(135deg, #00AEEF, #0284c7); */ /* Overridden by specific selector now */
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: all 0.4s ease;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.05) rotate(5deg); /* Reduced scale and rotation */
            box-shadow: 0 15px 35px rgba(0, 174, 239, 0.25); /* Slightly reduced shadow */
        }

        .feature-card:hover .feature-icon i {
            transform: scale(1.05); /* Reduced scale */
        }

        .feature-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            line-height: 1.3;
        }

        .feature-description {
            font-size: 1.05rem;
            color: #6b7280;
            margin-bottom: 2.5rem;
        }

        .feature-cta {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            /* background: linear-gradient(135deg, #10b981, #059669); */ /* Overridden by specific selector now */
            color: white;
            padding: 0.8rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .feature-cta:hover {
            transform: translateY(-2px); /* Reduced lift */
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); /* Slightly reduced shadow */
        }
        .feature-cta i { /* Micro-interaksi ikon panah */
            transition: transform 0.3s ease;
        }
        .feature-cta:hover i {
            transform: translateX(3px); /* Reduced movement */
        }

        /* New GoRako Explanation Section Styles (Optimized for teens + video) */
        .explanation-section {
            padding: 5rem 0;
            background-color: #e2e8f0; /* Soft gray background */
            color: #2D4F2B;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s ease-out;
        }

        .explanation-section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .explanation-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            flex-wrap: wrap; /* Allows wrapping on smaller screens */
            align-items: center;
            justify-content: center;
            gap: 4rem; /* Increased gap */
        }

        .explanation-content {
            flex: 1;
            min-width: 300px; /* Ensure content doesn't get too narrow */
            text-align: left;
            padding-right: 2rem; /* Add some spacing if video is next */
            animation: fadeInRight 1s ease-out forwards; /* Animation for text */
        }
        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .explanation-content h2 {
            font-size: 3.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            color: #16610E; /* Darker green */
            text-shadow: 1px 1px 3px rgba(0,0,0,0.1);
        }

        .explanation-content p {
            font-size: 1.15rem;
            margin-bottom: 1.5rem;
            color: #374151;
        }

        .explanation-content ul {
            list-style: none;
            margin-bottom: 2rem;
        }

        .explanation-content ul li {
            font-size: 1.05rem;
            color: #374151;
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            opacity: 0; /* Hidden for staggered animation */
            transform: translateX(-20px);
            animation: fadeInListItem 0.5s ease-out forwards;
        }
        .explanation-content ul li:nth-child(1) { animation-delay: 0.3s; }
        .explanation-content ul li:nth-child(2) { animation-delay: 0.5s; }
        .explanation-content ul li:nth-child(3) { animation-delay: 0.7s; }
        .explanation-content ul li:nth-child(4) { animation-delay: 0.9s; }

        @keyframes fadeInListItem {
            to { opacity: 1; transform: translateX(0); }
        }

        .explanation-content ul li i {
            /* color: #059669; */ /* Overridden by specific selector now */
            margin-right: 10px;
            font-size: 1.3em;
            filter: drop-shadow(0 0 5px rgba(5, 150, 105, 0.5)); /* Subtle shadow */
        }

        .explanation-video {
            flex: 1;
            min-width: 350px; /* Ensure video container doesn't get too narrow */
            background-color: #fff;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
            padding-top: 56.25%; /* 16:9 Aspect Ratio */
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeInLeft 1s ease-out forwards; /* Animation for video */
        }

        @keyframes fadeInLeft {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .explanation-video:hover {
            transform: translateY(-5px) scale(1.01); /* Reduced lift and scale */
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15); /* Reduced shadow */
        }

        .explanation-video iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 20px; /* Match container border-radius */
        }

        /* Section for Waste Data Display */
        .waste-data-section {
            padding: 5rem 0;
            background-color: #f8fafc; /* Light background */
            text-align: center;
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s ease-out;
        }

        .waste-data-section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .waste-data-section .section-header {
            margin-bottom: 2rem;
        }

        .waste-data-table-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            overflow-x: auto; /* Enable horizontal scrolling for table */
            -webkit-overflow-scrolling: touch; /* For smoother scrolling on iOS */
        }

        .waste-data-table {
            width: 100%; /* Ensure table takes full width of container */
            border-collapse: collapse;
            margin-top: 2rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            overflow: hidden; /* Ensures rounded corners apply to content */
            min-width: 800px; /* Minimum width for the table to prevent squishing on small screens */
        }

        .waste-data-table th, .waste-data-table td {
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            text-align: left;
            font-size: 0.95rem;
            /* white-space: nowrap; */ /* Prevent text wrapping in cells, but can cause overflow if removed entirely. Handled by min-width on table. */
        }
         /* Specific adjustments for smaller screens to allow text wrapping for long headers */
        @media (max-width: 768px) {
            .waste-data-table th, .waste-data-table td {
                padding: 10px 8px; /* Reduce padding */
                font-size: 0.85rem; /* Reduce font size */
                white-space: normal; /* Allow text wrapping in cells on smaller screens */
            }
        }


        .waste-data-table thead {
            background-color: #e0f2fe; /* Light blue header */
            color: #2D4F2B;
        }

        .waste-data-table tbody tr:nth-child(even) {
            background-color: #f0f4f8; /* Zebra striping */
        }

        .waste-data-table tbody tr:hover {
            background-color: #e6f7ff; /* Hover effect */
            transition: background-color 0.2s ease;
        }

        .waste-data-table td {
            color: #4b5563;
        }

        /* --- Performance Section Enhancements --- */
        .performance-section {
            padding: 6rem 0; /* Increased padding for more breathing room */
            background: linear-gradient(180deg, #f0f4f8 0%, #e2e8f0 100%); /* Subtle gradient background */
            text-align: center;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s ease-out;
        }

        .performance-section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .performance-section .section-header {
            margin-bottom: 4rem; /* More space below header */
        }

        .performance-section .section-header h2 {
            font-size: 3.5rem; /* Larger, more impactful heading */
            font-weight: 800; /* Extra bold */
            margin-bottom: 1.2rem;
            background: linear-gradient(135deg, #10b981, #059669); /* Keep the green gradient */
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.04em; /* Tighter letter spacing */
            line-height: 1.1;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.1); /* Subtle text shadow */
        }


        .performance-section .section-header p {
            font-size: 1.15rem; /* Slightly larger body text */
            color: #4b5563;
            max-width: 900px; /* Wider for better readability */
            margin: 0 auto 3.5rem auto;
            line-height: 1.7;
        }

        .performance-section .section-header p a {
            color: var(--primary-color-from-settings); /* Use dynamic accent color */
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 0.2em 0.5em;
            border-radius: 5px;
            background-color: rgba(var(--primary-color-from-settings-rgb, 0, 123, 255), 0.08); /* Dynamic background */
        }

        .performance-section .section-header p a:hover {
            color: color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%);
            background-color: rgba(var(--primary-color-from-settings-rgb, 0, 123, 255), 0.15);
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2.5rem; /* Slightly larger gap */
            max-width: 1300px; /* Wider grid */
            margin: 0 auto;
            padding: 0 2rem;
        }

        .performance-card {
            background-color: #ffffff;
            border-radius: 18px; /* More rounded corners */
            padding: 2.5rem; /* Increased padding */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08), 0 15px 40px rgba(0, 0, 0, 0.05); /* Softer, layered shadow */
            text-align: center;
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.4s ease, border-color 0.4s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Distribute content nicely */
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.3); /* Subtle border */
        }

        .performance-card:hover {
            transform: translateY(-12px) scale(1.01); /* More pronounced lift and slight scale */
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15), 0 25px 60px rgba(0, 0, 0, 0.1); /* Stronger shadow on hover */
            border-color: rgba(var(--primary-color-from-settings-rgb, 0, 123, 255), 0.5); /* Accent border on hover */
        }

        /* New: Card-specific accent colors for subtle visual cues */
        .performance-card.data-card-accent-neutral {
            /* No specific background/border change, relies on default */
        }

        .performance-card.data-card-accent-positive {
            /* Subtle green tint for positive metrics */
            background: linear-gradient(135deg, #e6ffe6, #d0f8d0);
            border-color: #a3d9a3;
        }

        .performance-card.data-card-accent-strong-positive {
            /* More pronounced green tint for very positive metrics */
            background: linear-gradient(135deg, #d4ffd4, #aaffaa);
            border-color: #7ad77a;
        }

        .performance-card.data-card-accent-negative {
            /* Subtle red tint for negative metrics */
            background: linear-gradient(135deg, #ffe6e6, #f8d0d0);
            border-color: #d9a3a3;
        }


        /* Inner elements of the card */
        .performance-card .card-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #f0f4f8, #e2e8f0); /* Light background for icon circle */
            border-radius: 50%; /* Circle shape */
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.05), 0 5px 15px rgba(0,0,0,0.1); /* Inner and outer shadow */
            transition: all 0.3s ease;
            flex-shrink: 0; /* Prevent icon from shrinking */
        }

        .performance-card:hover .card-icon {
            transform: rotate(5deg) scale(1.05);
            background: linear-gradient(135deg, #e6f7ff, #cceeff); /* Lighter on hover */
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.08), 0 8px 20px rgba(0,0,0,0.15);
        }


        .performance-card .card-icon i {
            font-size: 3rem; /* Adjusted size */
            color: var(--primary-color-from-settings); /* Use accent color for icons */
            transition: all 0.3s ease;
        }

        /* Specific icon colors (override if needed, but accent color is dynamic) */
        .performance-card.data-card-accent-neutral .card-icon i { color: #6b7280; } /* Gray for neutral */

        .performance-card.data-card-accent-positive .card-icon i,
        .performance-card.data-card-accent-strong-positive .card-icon i { color: #4CAF50; } /* Green for positive */

        .performance-card.data-card-accent-negative .card-icon i { color: #F44336; } /* Red for negative */


        .performance-card h3.card-title {
            font-size: 1.5rem; /* Larger, clearer title */
            font-weight: 700;
            margin-bottom: 1rem;
            color: #1f2937;
            line-height: 1.3;
        }

        .performance-card .card-value {
            font-size: 3.8rem; /* Much larger value for impact */
            font-weight: 900; /* Extremely bold */
            color: var(--primary-color-from-settings); /* Use accent color for main value */
            margin-bottom: 0.5rem;
            letter-spacing: -0.05em; /* Tighter spacing for numbers */
            position: relative;
            display: inline-block; /* For potential future animation on value change */
        }


        /* Specific value colors, overriding accent if needed */
        .performance-card.data-card-accent-neutral .card-value { color: #374151; }

        .performance-card.data-card-accent-positive .card-value,
        .performance-card.data-card-accent-strong-positive .card-value { color: #28a745; } /* Strong green */

        .performance-card.data-card-accent-negative .card-value { color: #dc3545; } /* Strong red */


        .performance-card .value-unit {
            font-size: 0.8em; /* Unit size relative to value */
            font-weight: 600;
            opacity: 0.7;
            margin-left: 5px; /* Space between number and unit */
            display: inline-block;
            vertical-align: super; /* Slightly raised for percentages/units */
            color: #6b7280;
        }

        .performance-card .card-description {
            font-size: 0.95rem;
            color: #6b7280;
            line-height: 1.6;
            margin-top: 1rem; /* Space from value */
            flex-grow: 1; /* Allows description to take available space */
        }

        /* New: Metric indicator for a subtle visual line below the value */
        .card-metric-indicator {
            width: 60px;
            height: 4px;
            background-color: var(--primary-color-from-settings); /* Use accent color */
            margin: 1.5rem auto 0.5rem auto; /* Position below content */
            border-radius: 2px;
            opacity: 0.7;
            transition: width 0.3s ease, opacity 0.3s ease, background-color 0.3s ease;
        }

        .performance-card:hover .card-metric-indicator {
            width: 100px; /* Expand on hover */
            opacity: 1;
        }

        /* Specific indicator colors */
        .performance-card.data-card-accent-neutral .card-metric-indicator { background-color: #9e9e9e; }
        .performance-card.data-card-accent-positive .card-metric-indicator,
        .performance-card.data-card-accent-strong-positive .card-metric-indicator { background-color: #28a745; }
        .performance-card.data-card-accent-negative .card-metric-indicator { background-color: #dc3545; }

        /* Section for New Questions Design (FAQ Accordion Style) */
        .questions-section {
            padding: 5rem 0;
            background-color: #f0f4f8;
            text-align: center;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s ease-out;
        }

        .questions-section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .questions-section .section-header {
            margin-bottom: 2rem;
        }

        .questions-list {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            text-align: left;
        }

        .faq-item {
            background-color: #e6e6e6;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            transform-style: preserve-3d;
        }

        .faq-item:hover {
            transform: translateY(-3px) scale(1.0); /* Removed scale, reduced lift */
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1); /* Reduced shadow */
        }

        .faq-item summary {
            background: linear-gradient(135deg, #537D5D, #6b7280);
            color: white;
            padding: 18px 25px;
            font-weight: 600;
            font-size: 1.15rem;
            cursor: pointer;
            outline: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            transition: all 0.3s ease;
            user-select: none;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }

        .faq-item summary:hover {
            filter: brightness(1.1);
        }

        .faq-item summary::-webkit-details-marker {
            display: none;
        }
        .faq-item summary::marker {
            display: none;
        }

        .faq-item summary i.faq-icon {
            font-size: 0.9em;
            margin-left: 15px;
            transition: transform 0.3s ease;
        }

        .faq-item[open] summary i.faq-icon {
            transform: rotate(180deg);
        }

        .faq-item[open] summary {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            background: linear-gradient(135deg, #4b6e51, #5d8763);
        }

        .faq-content {
            padding: 20px 25px;
            background-color: white;
            border-top: 1px solid #ddd;
            color: #4b5563;
            font-size: 1rem;
            line-height: 1.6;
            animation: fadeIn 0.5s ease-out;
        }

        .faq-content p {
            margin-bottom: 10px;
        }
        .faq-content p:last-child {
            margin-bottom: 0;
        }

        /* Call to Action Section */
        .cta-section {
            padding: 5rem 0;
            background: linear-gradient(135deg, #16610E, #2D4F2B);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: -10%;
            left: -10%;
            width: 120%;
            height: 120%;
            background: radial-gradient(circle at 15% 85%, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: rotateBackground 15s linear infinite;
            pointer-events: none;
        }

        @keyframes rotateBackground {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .cta-section-content {
            position: relative;
            z-index: 2;
            max-width: 900px;
            margin: 0 auto;
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .cta-section-content.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .cta-section h2 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            text-shadow: 2px 2px 5px rgba(0,0,0,0.3);
        }

        .cta-section p {
            font-size: 1.3rem;
            margin-bottom: 3rem;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            opacity: 0.9;
        }

        .cta-button-lg {
            background: white;
            /* color: #10b981; */ /* Overridden by specific selector now */
            padding: 1.2rem 2.5rem;
            border-radius: 30px;
            font-size: 1.2rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.4s ease;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .cta-button-lg:hover {
            transform: translateY(-5px) scale(1.02); /* Reduced lift and scale */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35); /* Slightly reduced shadow */
            /* color: #059669; */ /* Overridden by specific selector now */
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
            .performance-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            /* Responsive adjustments for performance section */
            @media (max-width: 768px) {
                .performance-section .section-header h2 {
                    font-size: 2.8rem;
                }
                .performance-section .section-header p {
                    font-size: 1rem;
                }
                .performance-card {
                    padding: 2rem;
                }
                .performance-card .card-icon {
                    width: 70px;
                    height: 70px;
                    margin-bottom: 1rem;
                }
                .performance-card .card-icon i {
                    font-size: 2.5rem;
                }
                .performance-card h3.card-title {
                    font-size: 1.3rem;
                }
                .performance-card .card-value {
                    font-size: 3rem;
                }
                .performance-card p.card-description {
                    font-size: 0.9rem;
                }
            }

            @media (max-width: 480px) {
                .performance-section .section-header h2 {
                    font-size: 2.2rem;
                }
                .performance-section .section-header p {
                    font-size: 0.9rem;
                }
                .performance-grid {
                    gap: 1.5rem;
                }
                .performance-card {
                    padding: 1.5rem;
                }
                .performance-card .card-icon {
                    width: 60px;
                    height: 60px;
                    margin-bottom: 0.8rem;
                }
                .performance-card .card-icon i {
                    font-size: 2rem;
                }
                .performance-card h3.card-title {
                    font-size: 1.1rem;
                }
                .performance-card .card-value {
                    font-size: 2.5rem;
                }
                .performance-card .value-unit {
                    font-size: 0.7em;
                }
                .performance-card p.card-description {
                    font-size: 0.85rem;
                }
            }
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

        /* Reduce motion for accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        .explanation-video-element {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* This is crucial for filling the space without distortion */
            border: none; /* remove iframe's default border if any */
            border-radius: 20px; /* Match container border-radius */
        }

        /* Styles from contoh product.html */
        .gradient-bg {
            background: linear-gradient(135deg, #10b981 0%, #059669 50%, #0d9488 100%);
        }
        .card-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .text-shadow {
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .glass-effect {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="navbar" role="navigation" aria-label="Main navigation">
        <div class="nav-container">
            <h1>
                <a href="#hero-section" class="logo">
                    <img src="logo.png" alt="GoRako Logo" class="logo-image">
                    GoRako
                </a>
            </h1>

            <ul class="nav-menu" id="navMenuDesktop">
                <li><a href="index.php" class="nav-link">Home</a></li>
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
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Pengaturan</a></li>
                    <li><a href="leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a></li>
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
        <section class="hero" id="hero-section">
            <div class="bg-circle-1 bg-element" data-parallax-speed="0.2"></div>
            <div class="bg-circle-2 bg-element" data-parallax-speed="0.15"></div>
            <i class="fas fa-recycle bg-recycle-icon bg-element" data-parallax-speed="0.1"></i>
            <i class="fas fa-leaf bg-leaf-icon bg-element" data-parallax-speed="0.05"></i>

            <div class="hero-content">
                <h1 class="animate-on-load">Mulai Hidup Lebih Hijau dengan GoRako!</h1>
                <p class="animate-on-load">GoRako adalah platform cerdas yang membantu Anda mengelola sampah, belajar daur ulang, dan mendapatkan hadiah menarik. Bergabunglah dengan kami menuju lingkungan yang lebih bersih dan berkelanjutan.</p>
                <div class="hero-buttons animate-on-load">
                    <a href="modules.php" class="btn-primary">
                        <i class="fas fa-rocket"></i>
                        Edukasi
                    </a>
                    <a href="service_quiz.php" class="btn-secondary">
                        <i class="fas fa-lightbulb"></i>
                        Pelajari Fitur
                    </a>
                </div>
            </div>
        </section>

        <div class="wavy-divider"></div>

        <section class="performance-section" id="performance-section">
            <div class="features-container">
                <div class="section-header">
                    <h2>Statistik Kinerja Pengelolaan Sampah 2024</h2>
                    <p>Ringkasan capaian pengurangan dan penanganan sampah rumah tangga dan sejenisnya dari 317 Kabupaten/kota se-Indonesia. <br>
                       Sumber: <a href="https://sipsn.kemenlh.go.id/sipsn/" target="_blank">sipsn.kemenlh.go.id/sipsn/</a></p>
                </div>
                <div class="performance-grid">
                    <div class="performance-card data-card-accent-neutral">
                        <div class="card-icon"><i class="fas fa-box-open"></i></div>
                        <h3 class="card-title">Timbulan Sampah Nasional</h3>
                        <div class="card-value">34.21<span class="value-unit"> Juta ton/tahun</span></div>
                        <p class="card-description">Total sampah yang dihasilkan oleh 317 Kabupaten/kota se-Indonesia.</p>
                        <div class="card-metric-indicator"></div> </div>

                    <div class="performance-card data-card-accent-positive">
                        <div class="card-icon"><i class="fas fa-minus-circle"></i></div>
                        <h3 class="card-title">Pengurangan Sampah</h3>
                        <div class="card-value">13.24<span class="value-unit">%</span></div>
                        <p class="card-description">(4.53 Juta ton/tahun) sampah berhasil dikurangi.</p>
                        <div class="card-metric-indicator"></div>
                    </div>

                    <div class="performance-card data-card-accent-positive">
                        <div class="card-icon"><i class="fas fa-recycle"></i></div>
                        <h3 class="card-title">Penanganan Sampah</h3>
                        <div class="card-value">46.51<span class="value-unit">%</span></div>
                        <p class="card-description">(15.91 Juta ton/tahun) sampah berhasil ditangani.</p>
                        <div class="card-metric-indicator"></div>
                    </div>

                    <div class="performance-card data-card-accent-strong-positive">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                        <h3 class="card-title">Sampah Terkelola</h3>
                        <div class="card-value">59.74<span class="value-unit">%</span></div>
                        <p class="card-description">(20.44 Juta ton/tahun) dari total sampah terkelola dengan baik.</p>
                        <div class="card-metric-indicator"></div>
                    </div>

                    <div class="performance-card data-card-accent-negative">
                        <div class="card-icon"><i class="fas fa-times-circle"></i></div>
                        <h3 class="card-title">Sampah Tidak Terkelola</h3>
                        <div class="card-value">40.26<span class="value-unit">%</span></div>
                        <p class="card-description">(13.77 Juta ton/tahun) masih belum tertangani.</p>
                        <div class="card-metric-indicator"></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="wavy-divider top" style="background-color: #f0f4f8;"></div>

        <section class="waste-data-section" id="waste-data-section">
            <div class="features-container">
                <div class="section-header">
                    <h2>Data Sampah Jawa Barat Tahun 2024</h2>
                    <p>Berikut adalah data timbulan sampah berdasarkan sumber dan kabupaten/kota di Jawa Barat pada tahun 2024.</p>
                </div>
                <div class="waste-data-table-container">
                    <table class="waste-data-table">
                        <thead>
                            <tr>
                                <th>Tahun</th>
                                <th>Provinsi</th>
                                <th>Kabupaten/Kota</th>
                                <th>Rumah Tangga (ton)</th>
                                <th>Perkantoran (ton)</th>
                                <th>Pasar (ton)</th>
                                <th>Perniagaan (ton)</th>
                                <th>Fasilitas Publik (ton)</th>
                                <th>Kawasan (ton)</th>
                                <th>Lain (ton)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jawaBaratWasteData as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['Tahun']) ?></td>
                                <td><?= htmlspecialchars($row['Provinsi']) ?></td>
                                <td><?= htmlspecialchars($row['Kabupaten/Kota']) ?></td>
                                <td><?= htmlspecialchars($row['Rumah Tangga (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Perkantoran (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Pasar (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Perniagaan (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Fasilitas Publik (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Kawasan (ton)'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['Lain (ton)'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="produk" class="py-32 bg-gradient-to-br from-slate-50 via-white to-green-50 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-full">
                <div class="absolute top-20 left-10 w-72 h-72 bg-green-100 rounded-full opacity-20 blur-3xl"></div>
                <div class="absolute bottom-20 right-10 w-96 h-96 bg-blue-100 rounded-full opacity-20 blur-3xl"></div>
                <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-80 h-80 bg-purple-100 rounded-full opacity-10 blur-3xl"></div>
            </div>
            
            <div class="container mx-auto px-6 relative z-10">
                <div class="text-center mb-20">
                    <div class="inline-block mb-6">
                        <span class="bg-gradient-to-r from-green-600 to-emerald-600 text-white px-6 py-2 rounded-full text-sm font-semibold tracking-wide uppercase">
                            Premium Collection
                        </span>
                    </div>
                    <h3 class="text-6xl font-black bg-gradient-to-r from-slate-800 via-green-700 to-emerald-600 bg-clip-text text-transparent mb-8 leading-tight">
                        Contoh Produk Edukasi<br>
                        <span class="text-5xl">Ramah Lingkungan</span>
                    </h3>
                    <p class="text-xl text-slate-600 max-w-4xl mx-auto leading-relaxed mb-8">
                        Koleksi eksklusif produk edukasi berkualitas premium yang dirancang khusus untuk membangun kesadaran lingkungan dengan cara yang menyenangkan dan efektif
                    </p>
                    <div class="flex justify-center">
                        <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-500 rounded-full"></div>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-10">
                    <div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-red-500 text-white px-3 py-1 rounded-full text-xs font-bold">Discount</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af1.jpg" alt="Spons Sponge Cuci Piring Wajan Panci dari Serabut Kelapa Alami Murah" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Spons Sponge Cuci Piring dari Serabut Kelapa Alami Murah</h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Manfaat: Alternatif spons sintetis yang susah terurai.

Ramah lingkungan: Produk lokal, alami, dan komposabel.

</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 950</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 1.000</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Buku Edukasi Lingkungan', 150000, 'https://s.shopee.co.id/2LNOAvCY0E')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>

                    <div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-blue-500 text-white px-3 py-1 rounded-full text-xs font-bold">NEW</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af2.jpg" alt="Kain Lap | Handuk Serbaguna 30 x 30 cm | Handuk Wajah | Katun Orange | Cleaning Cloth | Kain Serbet Dapur | Face Towel" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Kain Lap | Handuk Serbaguna 30 x 30 cm | Handuk Wajah </h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Manfaat: Mengurangi konsumsi tisu sekali pakai.

Ramah lingkungan: Bisa dicuci dan dipakai berulang kali.</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 15.200</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 16.800</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Puzzle Ekosistem', 120000, 'https://s.shopee.co.id/1g7hNYd8df')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>
                    
<div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-purple-500 text-white px-3 py-1 rounded-full text-xs font-bold">PREMIUM</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af4.jpg" alt="Sikat Gigi Bambu Bamboo Toothbrush Eco Bau Mulut Friendly Atasi Karang Gigi Biodegradable 074-2" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-purple-100 text-purple-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Sikat Gigi Bambu Bamboo Toothbrush Eco Bau Mulut Friendly Atasi Karang Gigi Biodegradable 074-2</h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Manfaat: Alternatif dari sikat gigi plastik.

Ramah lingkungan: Gagangnya bisa terurai secara alami.

</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 3.000</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 2.000</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Board Game Eco Heroes', 250000, 'https://s.shopee.co.id/10s0YiH6fA?share_channel_code=1')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>
                    
                     <div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-blue-500 text-white px-3 py-1 rounded-full text-xs font-bold">NEW</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af5.jpg" alt="Tas Kain Spunbond Kotak / Handle Box Goodie Bag Ukuran S.= 20x25x9 cm Kantong Belanja Lipat Serbaguna" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Tas Kain Spunbond Kotak / Handle Box Goodie Bag Ukuran S.= 20x25x9 cm Kantong Belanja Lipat Serbaguna, Isi 20</h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Manfaat: Menggantikan kantong plastik saat belanja.

Ramah lingkungan: Terbuat dari kain katun, kanvas, atau bahan daur ulang.</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 20.000</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 22.800</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Puzzle Ekosistem', 120000, 'https://s.shopee.co.id/5VKPuglWTT')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>
                    
                    <div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-purple-500 text-white px-3 py-1 rounded-full text-xs font-bold">PREMIUM</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af3.jpg" alt="Tumbler Minum Stainless 890ml Custom Termos Kopi Tahan Panas & Dingin Premium BPA Free Insulated Besar" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-purple-100 text-purple-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Tumbler Minum Stainless 890ml Custom Termos Kopi Tahan Panas & Dingin Premium BPA Free Insulated Besar</h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Manfaat: Mengurangi botol plastik sekali pakai.

Ramah lingkungan: Bisa digunakan jangka panjang, banyak yang dari bahan daur ulang.

</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 68.000</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 250.000</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Board Game Eco Heroes', 250000, 'https://s.shopee.co.id/801kue29pM')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>
                     
                     <div class="group bg-white rounded-3xl shadow-xl overflow-hidden card-hover border border-gray-100 relative">
                        <div class="absolute top-4 right-4 z-10">
                            <span class="bg-blue-500 text-white px-3 py-1 rounded-full text-xs font-bold">NEW</span>
                        </div>
                        <div class="overflow-hidden">
                            <img src="/afiliate/af6.jpg" alt="Beeswax Wrap Pembungkus Makanan Food Grade Natural Pengganti Plastic Cling Wrap Zero Waste Eco Friendly Ramah Lingkungan" class="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-500">
                        </div>
                        <div class="p-8">
                            <div class="flex items-center mb-3">
                                <span class="text-2xl mr-2">📚</span>
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">BESTSELLER</span>
                            </div>
                            <h4 class="text-2xl font-bold text-slate-800 mb-3 group-hover:text-green-600 transition-colors">Beeswax Wrap Pembungkus Makanan Food Grade Natural Pengganti Plastic Cling Wrap Zero Waste Eco Friendly Ramah Lingkungan</h4>
                            <p class="text-slate-600 mb-6 leading-relaxed">Kain katun dilapisi lilin lebah untuk membungkus makanan, pengganti plastik wrap.

Manfaat: Bisa dicuci & dipakai berulang kali, tahan air.

</p>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl font-black text-green-600">Rp 35.000</span>
                                    <span class="text-lg text-slate-400 line-through">Rp 35.800</span>
                                </div>
                            </div>
                            <button onclick="showShopeeNotification('Puzzle Ekosistem', 120000, 'https://s.shopee.co.id/6ppncHmHoJ')" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-4 rounded-2xl font-bold text-lg hover:from-green-700 hover:to-emerald-700 transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl">
                                🛒 Beli Sekarang
                            </button>
                        </div>
                    </div>
                    
                </div>
            </div>
        </section>
        <div class="wavy-divider top" style="background-color: #f8fafc;"></div>

        <section class="features-section" id="features-section">
            <div class="features-container">
                <div class="section-header">
                    <h2>Fitur Utama GoRako</h2>
                    <p>Kami hadir dengan berbagai fitur inovatif untuk memudahkan Anda berkontribusi pada lingkungan yang lebih baik.</p>
                </div>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h3 class="feature-title">Lokasi Bank Sampah</h3>
                        <p class="feature-description">
                            Temukan lokasi bank sampah terdekat di sekitar Anda dengan mudah. Buang sampah pilah Anda di tempat yang tepat.
                        </p>
                        <a href="service_quiz.php#map-section" class="feature-cta">
                            Cari Lokasi <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                        <h3 class="feature-title">Game Edukasi Interaktif</h3>
                        <p class="feature-description">
                            Belajar tentang daur ulang dan lingkungan dengan cara yang menyenangkan melalui berbagai game interaktif kami.
                        </p>
                        <a href="modules.php#games" class="feature-cta">
                            Mainkan Sekarang <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-gem"></i>
                        </div>
                        <h3 class="feature-title">Tukar Poin & Hadiah</h3>
                        <p class="feature-description">
                            Dapatkan poin dari setiap sampah yang Anda pilah dengan benar dan tukarkan dengan berbagai hadiah menarik dari mitra kami.
                        </p>
                        <a href="service_quiz.php#rewards-section" class="feature-cta">
                            Dapatkan Hadiah <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-book-reader"></i>
                        </div>
                        <h3 class="feature-title">Kuis Edukatif Interaktif</h3>
                        <p class="feature-description">
                            Uji pengetahuan Anda tentang daur ulang dan pengelolaan sampah melalui kuis interaktif yang seru dan informatif.
                        </p>
                        <a href="service_quiz.php#quiz-section" class="feature-cta">
                            Ikut Kuis <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <div class="wavy-divider top" style="background-color: #e2e8f0;"></div>

        <section class="explanation-section" id="explanation-section">
            <div class="explanation-container">
                <div class="explanation-content">
                    <h2>GoRako: Petualangan Menuju Bumi yang Keren!</h2>
                    <p>Bosannya ngelihat sampah di mana-mana? Yuk, ikutan GoRako! Aplikasi ini bikin kamu jadi pahlawan lingkungan tanpa ribet. Dengan GoRako, kamu bisa:</p>
                    <ul>
                        <li><i class="fas fa-check-circle"></i> Temukan Bank Sampah Terdekat: Dengan GoRako, kamu bisa langsung tahu di mana lokasi bank sampah terdekat untuk membuang sampah pilahmu! Anti bingung!</li>
                        <li><i class="fas fa-check-circle"></i> Main Game Edukasi Seru: Belajar tentang lingkungan dan daur ulang jadi makin asyik dengan berbagai game edukasi interaktif yang menantang!</li>
                        <li><i class="fas fa-check-circle"></i> Kumpulkan Poin, Raih Hadiah: Setiap sampah yang kamu pilah benar, poin langsung ngalir! Tukarkan dengan voucher game, diskon jajan, atau pulsa!</li>
                        <li><i class="fas fa-check-circle"></i> Kuis Seru, Makin Pintar Daur Ulang: Ikuti kuis interaktif yang bikin kamu makin jago pilah sampah, plus dapat poin ekstra!</li>
                    </ul>
                    <p>GoRako itu aplikasi keren yang bikin kamu peduli lingkungan sambil tetap asyik dan bisa dapat banyak benefit! Yuk, #MulaiDariKita #GoRakoBikinBeda!</p>
                </div>
               <div class="explanation-video">
            <video autoplay loop muted playsinline class="explanation-video-element"
                   title="Video Penjelasan GoRako: Mulai Hidup Lebih Hijau" frameborder="0"
                   allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                   referrerPolicy="strict-origin-when-cross-origin" allowfullscreen>
                <source src="logo_gorako.mp4" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
            </div>
        </section>

        <div class="wavy-divider" style="background-color: #f0f4f8;"></div>

        <section class="questions-section" id="questions-section">
            <div class="features-container">
                <div class="section-header">
                    <h2><i class="fas fa-lightbulb" style="color: #6b7280; margin-right: 10px;"></i> Pertanyaan Seputar Sampah</h2>
                    <p>Temukan jawaban atas pertanyaan umum tentang pengelolaan dan daur ulang sampah di sini.</p>
                </div>
                <div class="questions-list">
                    <details class="faq-item">
                        <summary>
                            Apa itu sampah organik? <i class="fas fa-chevron-down faq-icon"></i>
                        </summary>
                        <div class="faq-content">
                            <p>Sampah organik adalah jenis sampah yang berasal dari sisa makhluk hidup dan mudah terurai secara alami, seperti sisa makanan, kulit buah, sayuran, dan daun kering. Sampah ini dapat diolah menjadi kompos atau pupuk. </p>
                        </div>
                    </details>

                    <details class="faq-item">
                        <summary>
                            Bagaimana cara memilah sampah anorganik? <i class="fas fa-chevron-down faq-icon"></i>
                        </summary>
                        <div class="faq-content">
                            <p>Sampah anorganik adalah sampah yang sulit terurai, seperti plastik, kertas, dan logam. Untuk memilahnya, pisahkan berdasarkan jenis material (misalnya: plastik botol, kertas kardus, kaleng aluminium). Pastikan sampah bersih sebelum dibuang.</p>
                        </div>
                    </details>

                    <details class="faq-item">
                        <summary>
                            Mengapa daur ulang itu penting? <i class="fas fa-chevron-down faq-icon"></i>
                        </summary>
                        <div class="faq-content">
                            <p>Daur ulang penting untuk mengurangi volume sampah di TPA, menghemat sumber daya alam, mengurangi polusi (air dan udara), serta menghemat energi yang digunakan untuk produksi barang baru dari bahan mentah. </p>
                        </div>
                    </details>

                    <details class="faq-item">
                        <summary>
                            Apakah semua jenis plastik bisa didaur ulang? <i class="fas fa-chevron-down faq-icon"></i>
                        </summary>
                        <div class="faq-content">
                            <p>Tidak semua jenis plastik bisa didaur ulang. Umumnya, plastik dengan kode 1 (PET) dan 2 (HDPE) lebih mudah didaur ulang. Plastik berlapis atau plastik dengan kode lain mungkin lebih sulit atau tidak dapat didaur ulang di fasilitas biasa. Selalu periksa kode daur ulang pada kemasan.</p>
                        </div>
                    </details>

                    <details class="faq-item">
                        <summary>
                            Bagaimana cara mendapatkan poin di GoRako? <i class="fas fa-chevron-down faq-icon"></i>
                        </summary>
                        <div class="faq-content">
                            <p>Anda bisa mendapatkan poin di GoRako dengan menggunakan fitur "Pindai Sampah Cerdas" dan mengirimkan data sampah yang berhasil teridentifikasi. Ikut serta dalam "Kuis Edukatif Interaktif" juga bisa memberikan Anda poin tambahan!</p>
                        </div>
                    </details>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <div class="cta-section-content">
                <h2>Siap Bergabung dengan Gerakan GoRako?</h2>
                <p>Mulailah perjalanan Anda menuju pengelolaan sampah yang lebih cerdas dan dapatkan berbagai manfaatnya sekarang juga!</p>
                <a href="service_quiz.php" class="cta-button-lg">
                    Mulai GoRako Sekarang <i class="fas fa-angle-right"></i>
                </a>
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

    <div id="shopeeNotificationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4 text-center">
            <h3 class="text-2xl font-bold mb-4 text-slate-800">Perhatian!</h3>
            <p class="text-gray-600 mb-6">Anda akan diarahkan ke platform Shopee untuk menyelesaikan pembelian produk ini. Pastikan Anda memiliki akun Shopee atau bersedia membuatnya.</p>
            <div class="flex gap-4">
                <button id="continueToShopeeBtn" class="flex-1 bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                    Lanjutkan ke Shopee
                </button>
                <button onclick="closeShopeeNotificationModal()" class="flex-1 bg-gray-300 text-gray-700 py-3 rounded-lg font-semibold hover:bg-gray-400 transition-colors">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <div id="purchaseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4">
            <h3 class="text-2xl font-bold mb-4">Konfirmasi Pembelian</h3>
            <div id="productDetails" class="mb-6">
                </div>
            <p class="text-gray-600 mb-6">Pembelian ini akan diverifikasi setelah Anda menyelesaikan transaksi di Shopee. Poin akan ditambahkan setelah verifikasi.</p>
            <div class="flex gap-4">
                <button onclick="closePurchaseModal()" class="flex-1 bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                    Oke, Mengerti
                </button>
            </div>
        </div>
    </div>

    <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4 text-center">
            <div class="text-6xl mb-4">🎉</div>
            <h3 class="text-2xl font-bold mb-4">Terima Kasih!</h3>
            <p class="text-gray-600 mb-6">Pesanan Anda telah berhasil diproses. Tim kami akan segera menghubungi Anda untuk konfirmasi pengiriman.</p>
            <button onclick="closeSuccessModal()" class="bg-green-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                Tutup
            </button>
        </div>
    </div>

    <script>
        // Data preferensi pengguna dari PHP
        const userAppearanceSettings = <?= $jsUserSettings ?>;

        document.addEventListener('DOMContentLoaded', function() {
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
            const totalPointsDisplayNavbar = document.getElementById('total-points-display-value');
            const profileDropdownPoints = document.getElementById('profile-dropdown-points-value');


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

            const wasteDataSection = document.querySelector('.waste-data-section');
            if (wasteDataSection) {
                globalObserver.observe(wasteDataSection);
            }

            const performanceSection = document.querySelector('.performance-section');
            if (performanceSection) {
                globalObserver.observe(performanceSection);
            }


            // Staggered animation for hero section content
            const heroTitle = document.querySelector('.hero-content h1');
            const heroParagraph = document.querySelector('.hero-content p');
            const heroButtons = document.querySelector('.hero-buttons');

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
            function showToast(message) {
                const toast = document.getElementById("toastNotification");
                toast.textContent = message;
                toast.classList.add("show");
                setTimeout(function(){
                    toast.classList.remove("show");
                }, 3000);
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

            // Poin ini penting: Mendengarkan event dari localStorage
            window.addEventListener('storage', (event) => {
                if (event.key === 'total_points_updated') {
                    const newTotalPoints = JSON.parse(event.newValue);
                    updateTotalPointsDisplay(newTotalPoints);
                }
            });

            updateTotalPointsDisplay(<?php echo $loggedInUserPoints; ?>);

            // Product-related functions from contoh product.html and new Shopee integration
            let currentProduct = {};
            let shopeeRedirectUrl = '';

            window.scrollToProducts = function() { // Made global
                document.getElementById('produk').scrollIntoView({ behavior: 'smooth' });
            }

            window.showContact = function() { // Made global
                alert('Hubungi kami di:\n📧 info@ecolearn.id\n📱 +62 812-3456-7890\n\nAtau kunjungi toko kami di Jakarta!');
            }

            window.showShopeeNotification = function(name, price, shopeeUrl) {
                currentProduct = { name, price };
                shopeeRedirectUrl = shopeeUrl;

                document.getElementById('shopeeNotificationModal').classList.remove('hidden');
                document.getElementById('shopeeNotificationModal').classList.add('flex');
            }

            window.closeShopeeNotificationModal = function() {
                document.getElementById('shopeeNotificationModal').classList.add('hidden');
                document.getElementById('shopeeNotificationModal').classList.remove('flex');
            }

            document.getElementById('continueToShopeeBtn').addEventListener('click', function() {
                closeShopeeNotificationModal();
                window.open(shopeeRedirectUrl, '_blank'); // Open Shopee link in a new tab

                // Immediately hide the purchaseModal after redirecting
                document.getElementById('purchaseModal').classList.add('hidden');
                document.getElementById('purchaseModal').classList.remove('flex');
            });


            window.closePurchaseModal = function() { // Modified existing closeModal to be specific
                document.getElementById('purchaseModal').classList.add('hidden');
                document.getElementById('purchaseModal').classList.remove('flex');
            }

            window.closeSuccessModal = function() { // Existing
                document.getElementById('successModal').classList.add('hidden');
                document.getElementById('successModal').classList.remove('flex');
            }

            // Close modals when clicking outside
            document.getElementById('shopeeNotificationModal').addEventListener('click', function(e) {
                if (e.target === this) closeShopeeNotificationModal();
            });

            document.getElementById('purchaseModal').addEventListener('click', function(e) {
                if (e.target === this) closePurchaseModal(); // Changed to closePurchaseModal
            });

            document.getElementById('successModal').addEventListener('click', function(e) {
                if (e.target === this) closeSuccessModal();
            });
        });
    </script>
</body>
</html>