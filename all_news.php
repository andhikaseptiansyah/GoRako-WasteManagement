<?php
require_once 'db_connection.php'; // Sertakan koneksi database dan fungsi helper
require_once 'helpers.php';       // Pastikan helpers.php ada (is_logged_in, redirect)

// Jika pengguna TIDAK login, arahkan mereka ke halaman login
if (!is_loggedin()) {
    redirect('login.php'); // Arahkan ke login.php jika belum login
}

// Pada titik ini, pengguna sudah login. Anda dapat mengakses variabel sesi:
$loggedInUserId = $_SESSION['user_id'];
$loggedInUsername = $_SESSION['username'];

// Ambil data pengguna untuk personalisasi UI
$userData = [];
$userSettings = [];

$query = "SELECT id, username, profile_picture, theme_preference, accent_color, font_size_preference
          FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $userData = $result->fetch_assoc();
        $userSettings['theme_preference'] = $userData['theme_preference'] ?? 'system';
        $userSettings['accent_color'] = $userData['accent_color'] ?? '#10b981';
        $userSettings['font_size_preference'] = $userData['font_size_preference'] ?? 'medium';
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan kueri data pengguna: " . $conn->error);
}

// Konversi data PHP ke JSON untuk JavaScript
$jsUserSettings = json_encode($userSettings);

$conn->close(); // Tutup koneksi database setelah semua data diambil
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Semua Berita</title>
    <meta name="description" content="Baca semua berita dan artikel terbaru tentang lingkungan dan keberlanjutan di GoRako.">
    <meta name="keywords" content="GoRako, berita lingkungan, artikel keberlanjutan, daur ulang, edukasi hijau">

    <link rel="icon" href="images/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" href="https://fonts.gstatic.com/s/poppins/v20/pxiByp8kv8JHgMjkFdXHzg.woff2" as="font" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Global Styles & Reset (Copied from modules.php for consistency) */
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
            font-size: 16px;
            --primary-app-color: #10b981;
            --secondary-app-color: #3f51b5;
            --text-app-color: #374151;
            --background-app-color: #f8fafc;
        }

        html.dark-mode {
            background: linear-gradient(135deg, #2c3034 0%, #212529 50%, #1a1a1a 100%);
            color: #f8f9fa;
            --text-app-color: #f8f9fa;
            --background-app-color: #212529;
        }
        html.font-small { font-size: 14px; }
        html.font-medium { font-size: 16px; }
        html.font-large { font-size: 18px; }

        body {
            --primary-color-from-settings: var(--primary-app-color);
        }
        /* Buttons, links, etc. using accent color */
        .cta-button, .btn-primary, .feature-cta {
             background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .nav-link:hover {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .profile-dropdown-toggle:hover {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .module-card .module-action-button.primary {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .module-card .module-action-button.primary:hover {
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%), var(--primary-color-from-settings));
        }
        .module-card .module-completed-badge {
            background-color: var(--primary-color-from-settings);
        }
        .module-card .module-info i {
            color: var(--primary-color-from-settings);
        }
        .news-article-card .read-more-button {
            background: linear-gradient(135deg, var(--primary-color-from-settings), color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%));
        }
        .news-article-card .read-more-button:hover {
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color-from-settings) 80%, black 20%), var(--primary-color-from-settings));
        }


        /* Navbar Styles */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            padding: 1rem 0;
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
            font-size: 2rem;
            font-weight: 700;
            color: #16610E;
            text-decoration: none;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
            letter-spacing: -0.8px;
            transition: all 0.3s ease;
        }

        .logo-image {
            width: 70px;
            height: 80px;
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .logo:hover .logo-image {
            transform: rotate(8deg) scale(1.0);
            filter: brightness(1.1);
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
            z-index: 1;
        }

        .nav-link:hover {
            transform: translateY(-3px) scale(1.0);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.2);
            transform: skewX(-20deg);
            transition: none;
            opacity: 0;
            z-index: -1;
        }

        @keyframes shimmerEffect {
            0% { transform: skewX(-20deg) translateX(-100%); opacity: 0; }
            50% { transform: skewX(-20deg) translateX(0%); opacity: 1; }
            100% { transform: skewX(-20deg) translateX(100%); opacity: 0; }
        }

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
            transition: background-color 0.3s ease, color 0.3s ease;
            white-space: nowrap;
        }

        .dropdown-menu a:hover {
            background-color: #e0f2fe;
        }

        .cta-button {
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
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }

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
        }

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
            object-fit: cover;
            flex-shrink: 0;
        }

        .profile-dropdown-toggle .profile-text {
            font-weight: 700;
            margin-right: 5px;
        }

        .profile-dropdown-menu {
            left: unset;
            right: 0;
            min-width: 200px;
            padding: 0.5rem 0;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .profile-dropdown-menu li:not(:last-child) {
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }

        .profile-dropdown-menu a {
            display: block;
            padding: 0.8rem 1.5rem;
            color: #374151;
            font-weight: 400;
            text-align: left;
        }

        .profile-dropdown-menu a:hover {
            background-color: #e0f2fe;
        }

        .logout-button {
            background-color: #dc3545;
            color: white;
            text-align: center;
            padding: 0.8rem 1.5rem;
            border-radius: 0 0 10px 10px;
            margin-top: 10px;
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: block;
        }

        .logout-button:hover {
            background-color: #c82333;
            color: white;
        }

        /* General Section Styling */
        main {
            padding: 3rem 0;
            background-color: var(--background-app-color);
            position: relative; /* Needed for absolute positioning of background elements */
            overflow: hidden; /* Hide overflow from background elements */
        }
        .container-modules {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            position: relative; /* Ensure modules are above background elements */
            z-index: 1;
        }

        /* Animated Background Elements */
        .background-element {
            position: absolute;
            opacity: 0.1; /* Subtle opacity */
            pointer-events: none;
            will-change: transform, opacity;
            transition: transform 0.3s ease-out; /* Smooth follow on scroll */
            z-index: 0;
        }

        /* Color variants for background elements */
        .background-element.green-dark { color: #2D4F2B; }
        .background-element.green-primary { color: #10b981; }
        .background-element.green-light { color: #4CAF50; }
        .background-element.blue-light { color: #3f51b5; }
        .background-element.gray-light { color: #9E9E9E; }


        /* Define initial positions and animation for background elements */
        .bg-element-1 { top: 10%; left: 5%; font-size: 3rem; animation: floatRotate 20s infinite ease-in-out; }
        .bg-element-2 { top: 30%; right: 10%; font-size: 3.5rem; animation: floatRotate 25s infinite reverse ease-in-out; }
        .bg-element-3 { bottom: 20%; left: 15%; font-size: 3.2rem; animation: floatRotate 18s infinite ease-in-out; }
        .bg-element-4 { top: 50%; left: 0%; font-size: 2.8rem; animation: floatRotate 22s infinite reverse ease-in-out; }
        .bg-element-5 { bottom: 5%; right: 20%; font-size: 3.8rem; animation: floatRotate 28s infinite ease-in-out; }
        .bg-element-6 { top: 5%; right: 25%; font-size: 3.1rem; animation: floatRotate 19s infinite reverse ease-in-out; }
        .bg-element-7 { bottom: 30%; left: 5%; font-size: 2.9rem; animation: floatRotate 23s infinite ease-in-out; }
        .bg-element-8 { top: 70%; right: 15%; font-size: 3.6rem; animation: floatRotate 26s infinite reverse ease-in-out; }
        .bg-element-9 { top: 40%; left: 30%; font-size: 3.3rem; animation: floatRotate 21s infinite ease-in-out; }
        .bg-element-10 { bottom: 10%; left: 40%; font-size: 2.7rem; animation: floatRotate 24s infinite reverse ease-in-out; }
        .bg-element-11 { top: 15%; right: 5%; font-size: 3.4rem; animation: floatRotate 22s infinite ease-in-out 0.5s; }
        .bg-element-12 { top: 60%; left: 10%; font-size: 3.0rem; animation: floatRotate 27s infinite reverse ease-in-out 1s; }
        .bg-element-13 { bottom: 25%; right: 10%; font-size: 3.7rem; animation: floatRotate 20s infinite ease-in-out 1.5s; }
        .bg-element-14 { top: 20%; left: 20%; font-size: 2.5rem; animation: floatRotate 23s infinite reverse ease-in-out 2s; }
        .bg-element-15 { bottom: 15%; left: 30%; font-size: 3.9rem; animation: floatRotate 29s infinite ease-in-out 2.5s; }

        @keyframes floatRotate {
            0% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(15px, -15px) rotate(90deg); }
            50% { transform: translate(0, -30px) rotate(180deg); }
            75% { transform: translate(-15px, -15px) rotate(270deg); }
            100% { transform: translate(0, 0) rotate(360deg); }
        }


        .section-header {
            text-align: center;
            margin-bottom: 3rem;
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .section-header.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .section-header h2 {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-app-color);
            background: linear-gradient(135deg, #2D4F2B, #16610E);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-header p {
            font-size: 1.1rem;
            color: #6b7280;
            max-width: 700px;
            margin: 0 auto;
        }

        /* Module Grid (general for all types of cards) */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Default: max 3 per row on large screens */
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .news-article-card { /* Renamed from module-card for clarity */
            background: var(--background-app-color);
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease, transform 0.1s linear; /* Added transform transition for tilt */
            transform-style: preserve-3d; /* Needed for 3D transform */
            opacity: 0;
            transform: translateY(30px);
        }
        .news-article-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .news-image {
            width: 100%;
            height: 200px; /* Consistent height for images */
            object-fit: cover;
            background-color: #f0f0f0;
            border-bottom: 1px solid #eee;
        }
        .news-image.placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #ccc;
            height: 200px; /* Ensure placeholder has same height */
        }
        .news-content {
            padding: 1.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .news-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-app-color);
            margin-bottom: 0.8rem;
            line-height: 1.3;
            max-height: 3.9em; /* Approx 3 lines of text */
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3; /* Limit to 3 lines */
            -webkit-box-orient: vertical;
        }
        .news-source-date {
            font-size: 0.85rem;
            color: #999;
            margin-bottom: 1rem;
        }
        .read-more-button {
            padding: 0.8rem 1.2rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            color: white;
            text-decoration: none;
            text-align: center;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .no-news-placeholder, .no-modules-placeholder {
            text-align: center;
            padding: 3rem;
            background-color: #f5f5f5;
            border-radius: 15px;
            color: #777;
            font-style: italic;
            grid-column: 1 / -1; /* Span full width in grid */
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        /* Modal Styles (for research text content) - kept for consistency, not directly used in this file */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
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
            background: var(--background-app-color);
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-50px);
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
            position: relative;
        }
        .modal-overlay.active .modal-content {
            transform: translateY(0);
            opacity: 1;
        }
        .modal-close-button {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: #888;
            transition: color 0.2s ease;
        }
        .modal-close-button:hover {
            color: #333;
        }
        .modal-content h3 {
            font-size: 1.8rem;
            color: var(--text-app-color);
            margin-bottom: 1.5rem;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .modal-content p {
            font-size: 1rem;
            color: #555;
            line-height: 1.7;
            margin-bottom: 1rem;
            white-space: pre-wrap;
        }

        /* Toast Notification */
        .toast-notification {
            visibility: hidden;
            min-width: 280px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1001;
            right: 30px;
            bottom: 30px;
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            opacity: 0;
            transition: opacity 0.5s, transform 0.5s;
            transform: translateY(20px);
        }
        .toast-notification.show {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }
        .toast-notification.success { background-color: #28a745; }
        .toast-notification.error { background-color: #dc3545; }
        .toast-notification.info { background-color: #17a2b8; }
        .toast-notification.warning { background-color: #ffc107; }

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
            justify-content: center;
        }
        .social-icons a {
            color: #a0aec0;
            font-size: 1.5rem;
            transition: color 0.3s ease, transform 0.3s ease;
        }
        .social-icons a:hover {
            color: #fff;
            transform: translateY(-2px);
        }

        .footer-bottom {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: #a0aec0;
            font-size: 0.85rem;
        }

        /* Animations */
        @keyframes fadeInSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .modules-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
            .section-header h2 {
                font-size: 2.5rem;
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
                width: 38px;
                height: 38px;
            }
            .nav-menu {
                flex-direction: column;
                position: fixed;
                top: 60px;
                left: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(15px);
                padding: 1.5rem;
                height: calc(100vh - 60px);
                overflow-y: auto;
                transform: translateX(100%);
                opacity: 0;
                visibility: hidden;
                transition: transform 0.3s ease-out, opacity 0.3s ease-out;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            }
            .nav-menu.active {
                transform: translateX(0);
                opacity: 1;
                visibility: visible;
            }
            .nav-menu li {
                width: 100%;
                text-align: center;
                margin-bottom: 1rem;
            }
            .nav-menu li:last-child {
                margin-bottom: 0;
            }
            .nav-link {
                width: 100%;
                justify-content: center;
                padding: 0.8rem 0;
            }
            .nav-menu.active .profile-nav-item {
                display: block;
                width: 100%;
                text-align: center;
                margin-top: 1rem;
            }
            .nav-menu.active .profile-dropdown-toggle {
                justify-content: center;
            }
            .nav-menu.active .profile-dropdown-menu {
                position: static;
                width: 100%;
                box-shadow: none;
                border: none;
                background: rgba(0,0,0,0.05);
                padding: 0.5rem 0;
                margin-top: 0.5rem;
                transform: translateY(0);
                opacity: 1;
            }
            .dropdown-menu {
                position: static;
                width: 100%;
                box-shadow: none;
                border: none;
                background: rgba(0,0,0,0.05);
                padding: 0.5rem 0;
                margin-top: 0.5rem;
                transform: translateY(0);
                opacity: 1;
            }
            .nav-item.dropdown:hover .dropdown-menu {
                display: block;
                opacity: 1;
            }
            .mobile-toggle {
                display: flex;
            }
            main {
                padding: 2rem 0;
            }
            .container-modules {
                padding: 0 1rem;
            }
            .section-header h2 {
                font-size: 2rem;
            }
            .section-header p {
                font-size: 1rem;
            }
            .modules-grid {
                grid-template-columns: 1fr; /* 1 card per row on mobile */
            }
            .news-content {
                padding: 1rem;
            }
            .news-title {
                font-size: 1.2rem;
            }
            .news-source-date {
                font-size: 0.8rem;
            }
            .read-more-button {
                width: 100%;
            }
            .modal-content {
                padding: 1.5rem;
            }
            .modal-content h3 {
                font-size: 1.5rem;
            }
            .modal-content p {
                font-size: 0.9rem;
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
        }

        @media (max-width: 480px) {
            .section-header h2 {
                font-size: 1.8rem;
            }
            .news-title {
                font-size: 1.1rem;
            }
            .news-image {
                height: 150px;
            }
            .news-image.placeholder {
                height: 150px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" role="navigation" aria-label="Main navigation">
        <div class="nav-container">
            <h1>
                <a href="index.php" class="logo">
                    <img src="images/favicon.png" alt="GoRako Logo" class="logo-image">
                    GoRako
                </a>
            </h1>

            <ul class="nav-menu" id="navMenu">
                <li><a href="index.php" class="nav-link">Home</a></li>
                <li><a href="modules.php" class="nav-link">Modul Edukasi</a></li>
                <li><a href="service_quiz.php" class="nav-link">Service</a></li>
                <?php if (isset($loggedInUserId)): ?>
                    <li class="nav-item dropdown profile-nav-item">
                        <a href="#" class="nav-link profile-dropdown-toggle">
                            <img src="<?php echo htmlspecialchars($userData['profile_picture'] ?? 'images/default_profile.png'); ?>" alt="Avatar Pengguna" class="profile-avatar">
                            <span class="profile-text"><?php echo htmlspecialchars($loggedInUsername); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </a>
                        <ul class="dropdown-menu profile-dropdown-menu">
                            <li><a href="profile.php">Profil Saya</a></li>
                            <li><a href="settings.php">Pengaturan</a></li>
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

    <main>
        <i class="fas fa-leaf background-element green-dark bg-element-1"></i>
        <i class="fas fa-recycle background-element green-primary bg-element-2"></i>
        <i class="fas fa-seedling background-element green-light bg-element-3"></i>
        <i class="fas fa-leaf background-element blue-light bg-element-4"></i>
        <i class="fas fa-recycle background-element gray-light bg-element-5"></i>
        <i class="fas fa-seedling background-element green-dark bg-element-6"></i>
        <i class="fas fa-leaf background-element green-primary bg-element-7"></i>
        <i class="fas fa-recycle background-element green-light bg-element-8"></i>
        <i class="fas fa-seedling background-element blue-light bg-element-9"></i>
        <i class="fas fa-leaf background-element gray-light bg-element-10"></i>
        <i class="fas fa-recycle background-element green-dark bg-element-11"></i>
        <i class="fas fa-seedling background-element green-primary bg-element-12"></i>
        <i class="fas fa-leaf background-element green-light bg-element-13"></i>
        <i class="fas fa-recycle background-element blue-light bg-element-14"></i>
        <i class="fas fa-seedling background-element gray-light bg-element-15"></i>
        <i class="fas fa-leaf background-element green-dark bg-element-16"></i>
        <i class="fas fa-recycle background-element green-primary bg-element-17"></i>
        <i class="fas fa-seedling background-element green-light bg-element-18"></i>
        <i class="fas fa-leaf background-element blue-light bg-element-19"></i>
        <i class="fas fa-recycle background-element gray-light bg-element-20"></i>


        <div class="container-modules">
            <section id="all-news-section">
                <div class="section-header">
                    <h2>Semua Berita Lingkungan</h2>
                    <p>Temukan informasi dan artikel terbaru dari berbagai sumber untuk memperkaya wawasan Anda.</p>
                </div>
                <div class="modules-grid" id="news-articles-grid">
                    <div class="no-news-placeholder">
                        <p>Memuat berita...</p>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <h3>GoRako</h3>
                <p>Platform edukasi dan pengelolaan sampah untuk lingkungan yang lebih bersih dan berkelanjutan.</p>
                <h4>Ikuti Kami</h4>
                <div class="social-icons">
                    <a href="https://wa.me/6281234567890" target="_blank" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                    <a href="https://instagram.com/gorako_app" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div class="footer-links">
                <h4>Tautan Cepat</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="modules.php">Modul Edukasi</a></li>
                    <li><a href="service_quiz.php">Service</a></li>
                    <li><a href="profile.php">Profil</a></li>
                </ul>
            </div>
            <div class="footer-contact">
                <h4>Informasi Kontak</h4>
                <p><i class="fas fa-envelope"></i> info@gorako.com</p>
                <p><i class="fas fa-phone-alt"></i> +62 812 3456 7890</p>
                <p><i class="fas fa-map-marker-alt"></i> Jakarta, Indonesia</p>
            </div>
        </div>
        <div class="footer-bottom">
            © 2025 GoRako. Hak Cipta Dilindungi Undang-Undang.
        </div>
    </footer>

    <div id="toastNotification" class="toast-notification"></div>

    <script>
        // Data preferensi pengguna dari PHP
        const userAppearanceSettings = <?= $jsUserSettings ?>;
        const loggedInUserId = <?= $loggedInUserId ?>; // For AJAX purposes

        document.addEventListener('DOMContentLoaded', function() {
            const htmlElement = document.documentElement;

            // --- Apply Theme and Appearance ---
            function applyTheme(theme) {
                if (theme === 'dark') {
                    htmlElement.classList.add('dark-mode');
                } else if (theme === 'light') {
                    htmlElement.classList.remove('dark-mode');
                } else { // 'system'
                    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                        htmlElement.classList.add('dark-mode');
                    } else {
                        htmlElement.classList.remove('dark-mode');
                    }
                }
                // Apply accent color to dynamic elements
                document.body.style.setProperty('--primary-color-from-settings', userAppearanceSettings.accent_color);
            }

            function applyFontSize(size) {
                htmlElement.classList.remove('font-small', 'font-medium', 'font-large');
                htmlElement.classList.add(`font-${size}`);
            }

            // Load theme preference from localStorage first, then from PHP
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                applyTheme(savedTheme);
            } else {
                applyTheme(userAppearanceSettings.theme_preference);
            }

            // Load font size preference from localStorage first, then from PHP
            const savedFontSize = localStorage.getItem('font-size');
            if (savedFontSize) {
                applyFontSize(savedFontSize);
            } else {
                applyFontSize(userAppearanceSettings.font_size_preference);
            }

            // Apply accent color (this is already done inside applyTheme, but can be separate if needed)
            document.body.style.setProperty('--primary-color-from-settings', userAppearanceSettings.accent_color);


            // --- Navbar Mobile Toggle (Copied from index.php) ---
            const mobileToggle = document.getElementById('mobileToggle');
            const navMenu = document.getElementById('navMenu');
            const navLinks = document.querySelectorAll('.nav-link');

            if (mobileToggle && navMenu) {
                mobileToggle.addEventListener('click', () => {
                    navMenu.classList.toggle('active');
                    const spans = mobileToggle.querySelectorAll('span');
                    if (navMenu.classList.contains('active')) {
                        spans[0].style.transform = 'rotate(45deg) translate(5px, 5px)';
                        spans[1].style.opacity = '0';
                        spans[2].style.transform = 'rotate(-45deg) translate(7px, -6px)';
                        document.body.style.overflowY = 'hidden';
                    } else {
                        spans[0].style.transform = 'none';
                        spans[1].style.opacity = '1';
                        spans[2].style.transform = 'none';
                        document.body.style.overflowY = 'auto';
                    }
                    navMenu.setAttribute('aria-expanded', navMenu.classList.contains('active'));
                });
            }

            if (navLinks.length > 0) {
                navLinks.forEach(link => {
                    link.addEventListener('click', () => {
                        if (navMenu && navMenu.classList.contains('active')) {
                            navMenu.classList.remove('active');
                            if (mobileToggle) {
                                const spans = mobileToggle.querySelectorAll('span');
                                spans[0].style.transform = 'none';
                                spans[1].style.opacity = '1';
                                spans[2].style.transform = 'none';
                            }
                            navMenu.setAttribute('aria-expanded', 'false');
                            document.body.style.overflowY = 'auto';
                        }
                    });
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

            // --- Ripple Effect for Buttons (Copied from index.php) ---
            document.body.addEventListener('click', function(e) {
                const target = e.target.closest('.module-action-button, .cta-button, .read-more-button');
                if (target && !target.disabled) {
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


            // --- Intersection Observer for Scroll Animations ---
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
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.section-header').forEach((header) => {
                globalObserver.observe(header);
            });

            // Select all news-article-card elements for animation and tilt
            document.querySelectorAll('.news-article-card').forEach((card, index) => {
                card.style.transitionDelay = `${index * 0.1}s`; // Staggered animation
                globalObserver.observe(card);

                // Add mousemove listener for tilt effect
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    card.addEventListener('mousemove', function(e) {
                        const rect = this.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        const centerX = rect.width / 2;
                        const centerY = rect.height / 2;
                        const rotateX = (y - centerY) / 20; // Adjust divisor for more/less tilt
                        const rotateY = (centerX - x) / 20; // Adjust divisor for more/less tilt
                        this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
                    });

                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg)';
                    });
                }
            });

            // --- Toast Notification Function ---
            function showToast(message, type = 'info', duration = 3000) {
                const toast = document.getElementById("toastNotification");
                toast.textContent = message;
                toast.className = `toast-notification show ${type}`;
                setTimeout(function(){
                    toast.classList.remove("show");
                }, duration);
            }

            // --- Fetch News Articles ---
            async function fetchNews() {
                const newsGrid = document.getElementById('news-articles-grid');
                newsGrid.innerHTML = `
                    <div class="no-news-placeholder" style="grid-column: 1 / -1;">
                        <p>Memuat berita...</p>
                    </div>
                `; // Show loading indicator, span full width

                try {
                    const response = await fetch('fetch_news.php'); // Call your PHP script
                    const data = await response.json();

                    newsGrid.innerHTML = ''; // Clear loading indicator

                    if (data.status === 'success' && data.articles && data.articles.length > 0) {
                        data.articles.forEach((article, index) => {
                            const articleCard = document.createElement('div');
                            articleCard.classList.add('news-article-card', 'animate-on-scroll');
                            articleCard.style.transitionDelay = `${index * 0.1}s`; // Staggered animation

                            const publishedAt = new Date(article.publishedAt).toLocaleDateString('id-ID', {
                                year: 'numeric', month: 'long', day: 'numeric'
                            });

                            // Truncate title to ensure it's not too long
                            const displayTitle = article.title.length > 70 ? article.title.substring(0, 67) + '...' : article.title;

                            articleCard.innerHTML = `
                                ${article.urlToImage ? `<img src="${htmlspecialchars(article.urlToImage)}" alt="${htmlspecialchars(article.title)} Thumbnail" class="news-image" onerror="this.onerror=null;this.src='https://placehold.co/600x400/cccccc/333333?text=Gambar+Tidak+Tersedia';">` : `<div class="news-image placeholder"><i class="fas fa-newspaper"></i></div>`}
                                <div class="news-content">
                                    <h3 class="news-title">${htmlspecialchars(displayTitle)}</h3>
                                    <p class="news-source-date">${htmlspecialchars(article.source.name)} - ${publishedAt}</p>
                                    <a href="${htmlspecialchars(article.url)}" target="_blank" class="read-more-button">Baca Selengkapnya <i class="fas fa-arrow-right"></i></a>
                                </div>
                            `;
                            newsGrid.appendChild(articleCard);
                            globalObserver.observe(articleCard); // Observe new elements for animation
                        });
                    } else {
                        newsGrid.innerHTML = `
                            <div class="no-news-placeholder" style="grid-column: 1 / -1;">
                                <p>Tidak ada berita lingkungan yang tersedia saat ini.</p>
                                <p>Silakan pastikan News API Anda telah dikonfigurasi di file <code>fetch_news.php</code> dan <code>news_settings</code>.</p>
                            </div>
                        `;
                    }
                } catch (error) {
                    console.error('Error fetching news:', error);
                    newsGrid.innerHTML = `
                        <div class="no-news-placeholder" style="grid-column: 1 / -1;">
                            <p>Gagal memuat berita. Terjadi masalah jaringan atau konfigurasi API.</p>
                        </div>
                    `;
                }
            }

            // Call fetchNews when the page loads
            fetchNews();

            // Utility for HTML escaping (basic, match PHP's htmlspecialchars)
            function htmlspecialchars(str) {
                if (typeof str !== 'string' && str !== null) { // Handle null explicitly
                    return str;
                }
                str = String(str); // Ensure it's a string
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return str.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
        });
    </script>
</body>
</html>