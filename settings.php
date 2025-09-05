<?php
session_start();
require_once 'db_connection.php'; // Pastikan db_connection.php ada dan berfungsi
require_once 'helpers.php';       // Memperbaiki kesalahan sintaks di sini

// Jika user TIDAK login, arahkan mereka ke halaman login
if (!is_logged_in()) {
    redirect('login.php');
}

$loggedInUserId = $_SESSION['user_id'];

// --- FETCH DATA PENGGUNA DARI DATABASE ---
$userSettings = [];
$loginActivities = [];

$userQuery = "SELECT id, username, email, full_name, phone_number, profile_picture,
                     theme_preference, accent_color, font_size_preference,
                     email_product_updates, email_announcements, push_new_messages,
                     push_event_reminders, sms_notifications, marketing_email,
                     two_factor_enabled, google_connected, slack_connected
              FROM users WHERE id = ?";
$stmt = $conn->prepare($userQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $userSettings = $result->fetch_assoc();
        // Set default values if columns are null (optional, but good for new users)
        $userSettings['full_name'] = $userSettings['full_name'] ?? '';
        $userSettings['phone_number'] = $userSettings['phone_number'] ?? '';
        $userSettings['profile_picture'] = $userSettings['profile_picture'] ?? 'https://via.placeholder.com/90/007bff/FFFFFF?text=JD';
        $userSettings['theme_preference'] = $userSettings['theme_preference'] ?? 'system';
        $userSettings['accent_color'] = $userSettings['accent_color'] ?? '#007bff';
        $userSettings['font_size_preference'] = $userSettings['font_size_preference'] ?? 'medium';

        // Convert tinyint/boolean from DB to boolean for JS
        $userSettings['email_product_updates'] = (bool)$userSettings['email_product_updates'];
        $userSettings['email_announcements'] = (bool)$userSettings['email_announcements'];
        $userSettings['push_new_messages'] = (bool)$userSettings['push_new_messages'];
        $userSettings['push_event_reminders'] = (bool)$userSettings['push_event_reminders'];
        $userSettings['sms_notifications'] = (bool)$userSettings['sms_notifications'];
        $userSettings['marketing_email'] = (bool)$userSettings['marketing_email'];
        $userSettings['two_factor_enabled'] = (bool)$userSettings['two_factor_enabled'];
        $userSettings['google_connected'] = (bool)$userSettings['google_connected'];
        $userSettings['slack_connected'] = (bool)$userSettings['slack_connected'];

    } else {
        // User data not found, redirect to logout
        set_flash_message('error', 'Data pengguna tidak ditemukan. Silakan login kembali.');
        redirect('logout.php');
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan pengguna: " . $conn->error);
    set_flash_message('error', 'Terjadi kesalahan sistem saat mengambil data profil.');
    redirect('logout.php');
}

// --- FETCH RIWAYAT LOGIN DARI DATABASE ---
$loginActivitiesQuery = "SELECT type, device, location, timestamp FROM login_activities WHERE user_id = ? ORDER BY timestamp DESC LIMIT 10";
$stmt = $conn->prepare($loginActivitiesQuery);
if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Format waktu untuk tampilan (sesuai format JS yang ada)
        $row['time'] = date('d F Y, H:i', strtotime($row['timestamp'])) . ' WIB';
        $loginActivities[] = $row;
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan aktivitas login: " . $conn->error);
}

// Convert PHP data to JSON for JavaScript
$jsUserSettings = json_encode($userSettings);
$jsLoginActivities = json_encode($loginActivities);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Akun</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Global Box-Sizing for consistent layout */
        html {
            box-sizing: border-box;
        }
        *, *::before, *::after {
            box-sizing: inherit;
        }

        /* CSS Variables for Theming */
        :root {
            --primary-color: #007bff;
            --primary-hover-color: #0056b3;
            --success-color: #28a745;
            --success-hover-color: #218838;
            --danger-color: #dc3545;
            --danger-hover-color: #c82333;
            --warning-color: #ffc107;

            --background-color: #f8f9fa;
            --surface-color: #ffffff;
            --border-color: #e9ecef;
            --text-color: #343a40;
            --light-text-color: #6c757d;
            --dialog-overlay-color: rgba(0, 0, 0, 0.6);

            /* Font size variables for live preview */
            --base-font-size: 16px; /* Default medium */
        }

        /* Dark Mode Variables (overrides) */
        html.dark-mode {
            --background-color: #212529;
            --surface-color: #2c3034;
            --border-color: #495057;
            --text-color: #f8f9fa;
            --light-text-color: #ced4da;
            --dialog-overlay-color: rgba(0, 0, 0, 0.8);
        }

        /* Font Size Overrides */
        html.font-small { --base-font-size: 14px; }
        html.font-medium { --base-font-size: 16px; }
        html.font-large { --base-font-size: 18px; }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
            font-size: var(--base-font-size); /* Apply base font size */
            transition: background-color 0.3s, color 0.3s, font-size 0.3s; /* Smooth theme and font transition */
        }

        .settings-container {
            display: flex;
            max-width: 1200px;
            margin: 40px auto;
            background-color: var(--surface-color);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: background-color 0.3s, box-shadow 0.3s;
            position: relative; /* Needed for mobile menu positioning */
        }

        /* Mobile Header for hamburger menu */
        .mobile-header {
            display: none; /* Hidden by default */
            width: 100%;
            background-color: var(--surface-color);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            position: fixed; /* Fix header at the top */
            top: 0;
            left: 0;
            z-index: 100; /* Ensure header is above everything */
        }
        .mobile-header h2 {
            margin: 0;
            font-size: 1.5em;
            color: var(--text-color);
        }
        .mobile-menu-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            outline-offset: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px; /* Size for the clickable area */
            height: 40px;
        }

        /* Hamburger Icon Styling */
        .hamburger-icon {
            width: 28px;
            height: 20px;
            position: relative;
            transform: rotate(0deg);
            transition: .5s ease-in-out;
            cursor: pointer;
        }
        .hamburger-icon span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: var(--text-color); /* Color of the hamburger lines */
            border-radius: 9px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        .hamburger-icon span:nth-child(1) { top: 0px; }
        .hamburger-icon span:nth-child(2) { top: 8px; }
        .hamburger-icon span:nth-child(3) { top: 16px; }

        /* Animation for closing/opening hamburger icon */
        .mobile-menu-toggle[aria-expanded="true"] .hamburger-icon span:nth-child(1) {
            top: 8px;
            transform: rotate(135deg);
        }
        .mobile-menu-toggle[aria-expanded="true"] .hamburger-icon span:nth-child(2) {
            opacity: 0;
            left: -60px; /* Moves off screen */
        }
        .mobile-menu-toggle[aria-expanded="true"] .hamburger-icon span:nth-child(3) {
            top: 8px;
            transform: rotate(-135deg);
        }


        .sidebar {
            width: 280px;
            padding: 30px 20px;
            border-right: 1px solid var(--border-color);
            flex-shrink: 0;
            transition: border-color 0.3s;
        }

        .sidebar h2 {
            margin-top: 0;
            margin-bottom: 30px;
            color: var(--text-color);
            font-size: 1.8em;
            font-weight: 600;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar nav ul li {
            margin-bottom: 8px;
        }

        .sidebar nav ul li a {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            text-decoration: none;
            color: var(--light-text-color);
            border-radius: 8px;
            transition: background-color 0.3s, color 0.3s, transform 0.2s;
            font-weight: 500;
            outline-offset: 2px; /* For better keyboard focus */
        }

        .sidebar nav ul li a:hover {
            background-color: var(--border-color);
            color: var(--text-color);
            transform: translateX(3px);
        }

        .sidebar nav ul li a.active {
            background-color: var(--primary-color);
            color: var(--surface-color);
            font-weight: 600;
        }

        .sidebar nav ul li a.active:hover {
            background-color: var(--primary-hover-color);
            color: var(--surface-color);
        }

        /* Style for the back button */
        .sidebar .back-button {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 12px 20px;
            margin-bottom: 20px; /* Space from other links */
            background-color: #6c757d; /* Neutral gray */
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
            text-align: left;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-top: 20px; /* Space from top */
        }

        .sidebar .back-button i {
            margin-right: 10px;
        }

        .sidebar .back-button:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }


        .content {
            flex-grow: 1;
            padding: 40px;
        }

        .setting-section {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }

        .setting-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h3 {
            font-size: 1.6em;
            margin-top: 0;
            margin-bottom: 25px;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
            transition: border-color 0.3s;
        }

        h4 {
            font-size: 1.2em;
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--text-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="tel"],
        .form-group select,
        .form-group input[type="color"] {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 1em; /* Relative to base-font-size */
            color: var(--text-color);
            background-color: var(--surface-color);
            transition: border-color 0.3s, box-shadow 0.3s, background-color 0.3s, color 0.3s;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="password"]:focus,
        .form-group input[type="tel"]:focus,
        .form-group select:focus,
        .form-group input[type="color"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
            outline: none;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.2s;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            outline-offset: 2px; /* For better keyboard focus */
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: var(--primary-hover-color);
            transform: translateY(-2px);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background-color: var(--success-hover-color);
            transform: translateY(-2px);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            background-color: var(--danger-hover-color);
            transform: translateY(-2px);
        }

        /* Spinner for loading state */
        .spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #fff;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Profile Photo Styling */
        .profile-photo-upload {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .profile-photo-upload img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: border-color 0.3s;
        }
        .profile-photo-upload input[type="file"] {
            display: none;
        }
        .custom-file-upload {
            background-color: var(--light-text-color);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s;
            outline-offset: 2px; /* For better keyboard focus */
        }
        .custom-file-upload:hover {
            background-color: #5a6268;
        }
        .remove-photo-btn {
            background-color: var(--background-color);
            color: var(--text-color);
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            border: 1px solid var(--border-color);
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
            outline-offset: 2px; /* For better keyboard focus */
        }
        .remove-photo-btn:hover {
            background-color: var(--border-color);
        }

        /* Appearance Tab Specifics */
        .theme-options label {
            margin-right: 25px;
            cursor: pointer;
            font-weight: normal;
        }
        .theme-options input[type="radio"] {
            margin-right: 8px;
            outline-offset: 2px; /* For better keyboard focus */
        }
        .color-palette {
            display: flex;
            gap: 10px;
        }
        .color-palette button {
            width: 45px;
            height: 45px;
            border: 3px solid transparent;
            border-radius: 50%;
            cursor: pointer;
            transition: border-color 0.3s, transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            flex-shrink: 0;
            outline-offset: 2px; /* For better keyboard focus */
        }
        .color-palette button.selected {
            border-color: var(--primary-color);
            transform: scale(1.1);
        }
        .color-palette button:hover {
             transform: scale(1.05);
        }
        input[type="color"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            width: 60px;
            height: 40px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            outline-offset: 2px; /* For better keyboard focus */
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: 1px solid var(--border-color); border-radius: 4px; }
        input[type="color"]::-moz-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-moz-color-swatch { border: 1px solid var(--border-color); border-radius: 4px; }

        /* Notifications Tab Specifics */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 28px;
        }
        .toggle-switch input { display: none; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 28px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--primary-color); }
        input:focus + .slider { box-shadow: 0 0 1px var(--primary-color); outline: 2px solid var(--primary-color); outline-offset: 2px;} /* Better focus for custom element */
        input:checked + .slider:before { transform: translateX(22px); }
        .notification-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border-color);
            transition: border-color 0.3s;
        }
        .notification-item:last-child { border-bottom: none; }
        .notification-item label { margin-bottom: 0; font-weight: normal; }

        /* Security Tab Specifics */
        .two-factor-settings {
            background-color: var(--background-color);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid var(--border-color);
            transition: background-color 0.3s, border-color 0.3s;
        }
        .two-factor-settings small {
            display: block;
            margin-top: 5px;
            color: var(--light-text-color);
            font-size: 0.9em;
        }

        /* Password Strength Indicator */
        .password-strength-indicator {
            height: 6px;
            background-color: #e0e0e0;
            border-radius: 3px;
            margin-top: 5px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            background-color: #ccc;
            border-radius: 3px;
            transition: width 0.3s ease-in-out, background-color 0.3s ease-in-out;
        }
        .strength-text {
            font-size: 0.85em;
            margin-top: 5px;
            font-weight: 500;
        }
        .strength-weak .strength-bar { width: 33%; background-color: var(--danger-color); }
        .strength-weak .strength-text { color: var(--danger-color); }
        .strength-medium .strength-bar { width: 66%; background-color: var(--warning-color); }
        .strength-medium .strength-text { color: var(--warning-color); }
        .strength-strong .strength-bar { width: 100%; background-color: var(--success-color); }
        .strength-strong .strength-text { color: var(--success-color); }

        .session-item {
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--surface-color);
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            transition: background-color 0.3s, border-color 0.3s, box-shadow 0.3s;
        }
        .session-item div { flex-grow: 1; }
        .session-item span { display: block; margin-bottom: 3px; }
        .session-item span:first-child { font-weight: 600; }
        .session-item span:last-child { font-size: 0.9em; color: var(--light-text-color); }
        .session-item button { margin-left: 20px; }

        /* Login Activity Item */
        .login-activity-item {
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: var(--surface-color);
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .login-activity-item strong { display: block; margin-bottom: 5px; }
        .login-activity-item span { font-size: 0.9em; color: var(--light-text-color); display: block; }


        /* Notification Message */
        .notification-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: var(--text-color);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            opacity: 0;
            transform: translateY(-20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        .notification-message.show { opacity: 1; transform: translateY(0); }
        .notification-message.success { background-color: var(--success-color); }
        .notification-message.error { background-color: var(--danger-color); }
        .notification-message.warning { background-color: var(--warning-color); }

        /* Confirmation Dialog (Modal) */
        .confirmation-dialog-overlay, .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--dialog-overlay-color);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .confirmation-dialog-overlay.show, .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .confirmation-dialog, .modal-content {
            background-color: var(--surface-color);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            max-width: 500px; /* Increased max-width for modal content */
            width: 90%;
            text-align: center;
            transform: scale(0.9);
            transition: transform 0.3s ease, background-color 0.3s;
        }
        .confirmation-dialog-overlay.show .confirmation-dialog,
        .modal-overlay.show .modal-content {
            transform: scale(1);
        }
        .confirmation-dialog h4, .modal-content h4 {
            margin-top: 0;
            font-size: 1.4em;
            text-align: left; /* Align modal title left */
        }
        .confirmation-dialog p, .modal-content p {
            margin-bottom: 25px;
            color: var(--light-text-color);
            text-align: left; /* Align modal text left */
        }
        .modal-content .modal-body {
            text-align: left; /* Ensure body content is left-aligned */
            margin-bottom: 20px;
        }
        .confirmation-dialog .dialog-actions, .modal-content .modal-actions {
            display: flex;
            justify-content: flex-end; /* Align buttons to the right */
            gap: 15px;
        }
        .confirmation-dialog .btn, .modal-content .btn {
            margin-top: 0;
        }
        /* QR code styling */
        .qr-code-placeholder {
            width: 150px;
            height: 150px;
            background-color: #eee;
            border: 1px solid #ccc;
            margin: 20px auto;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 0.8em;
            color: #666;
            text-align: center;
            flex-direction: column;
            gap: 5px;
        }
        html.dark-mode .qr-code-placeholder {
            background-color: #444;
            border-color: #666;
            color: #ccc;
        }

        /* --- New Effects CSS --- */
        /* Shake Effect for Forms on Error */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake-effect {
            animation: shake 0.5s;
        }

        /* Confetti Effect Container */
        .confetti-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none; /* Allows clicks to pass through */
            z-index: 999;
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background-color: #f00; /* Default color, overridden by JS */
            opacity: 0;
            animation: confetti-fall 3s ease-out forwards;
        }

        @keyframes confetti-fall {
            0% {
                opacity: 1;
                transform: translate(0, 0) rotate(0deg);
            }
            100% {
                opacity: 0;
                transform: translate(var(--confetti-end-x), var(--confetti-end-y)) rotate(var(--confetti-rotation-end));
            }
        }
        /* End New Effects CSS */


        /* Responsive Design */
        @media (max-width: 992px) {
            .settings-container {
                flex-direction: column;
                margin: 0; /* Remove margin for full width on mobile */
                border-radius: 0; /* Remove border radius */
                box-shadow: none; /* Remove shadow */
                min-height: 100vh; /* Make sure it takes full height */
            }

            .mobile-header {
                display: flex; /* Show mobile header */
            }

            .sidebar {
                position: fixed; /* Make sidebar float */
                top: 0;
                left: -280px; /* Hide sidebar initially */
                height: 100%;
                width: 280px; /* Base width */
                max-width: 85vw; /* Max width relative to viewport for very small screens */
                padding: 30px 20px;
                background-color: var(--surface-color);
                border-right: 1px solid var(--border-color);
                box-shadow: 2px 0 10px rgba(0,0,0,0.2);
                transition: left 0.3s ease-in-out; /* Smooth slide transition */
                z-index: 10; /* Ensure sidebar is above content */
            }

            .sidebar.open {
                left: 0; /* Slide sidebar into view */
            }

            .mobile-sidebar-overlay {
                display: none; /* Hidden by default */
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5); /* Semi-transparent overlay */
                z-index: 9; /* Below sidebar, above content */
                opacity: 0;
                transition: opacity 0.3s ease-in-out;
            }

            .mobile-sidebar-overlay.show {
                display: block;
                opacity: 1;
            }

            .sidebar h2 {
                text-align: left; /* Keep title left-aligned in drawer */
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 1px solid var(--border-color);
            }
            .sidebar nav ul {
                flex-direction: column; /* Stack navigation items vertically */
                align-items: flex-start; /* Align links to the left */
                gap: 0; /* Remove gap */
            }
            .sidebar nav ul li {
                width: 100%; /* Full width list item */
            }
            .sidebar nav ul li a {
                padding: 12px 10px; /* Adjust padding */
                justify-content: flex-start; /* Align text to left */
            }
            
            .content {
                padding: 30px 20px;
                padding-top: 80px; /* Make space for fixed mobile header */
            }
        }

        @media (max-width: 576px) {
            .settings-container { margin: 0; }
            .profile-photo-upload { flex-direction: column; align-items: flex-start; }
            .profile-photo-upload .photo-actions { display: flex; gap: 10px; width: 100%; }
            .profile-photo-upload .custom-file-upload,
            .profile-photo-upload .remove-photo-btn { flex-grow: 1; text-align: center; }
            .session-item { flex-direction: column; align-items: flex-start; gap: 10px; }
            .session-item button { margin-left: 0; width: 100%; }
            .btn { width: 100%; box-sizing: border-box; }
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <div class="mobile-header">
            <h2>Pengaturan</h2>
            <button class="mobile-menu-toggle" aria-label="Buka menu navigasi" aria-expanded="false">
                <div class="hamburger-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>
        </div>

        <div class="mobile-sidebar-overlay"></div>

        <div class="sidebar">
            <h2>Pengaturan</h2>
            <nav aria-label="Main settings navigation">
                <ul role="tablist">
                    <li><a href="#" id="account-tab" class="active" role="tab" aria-controls="account-settings" aria-selected="true">Account</a></li>
                    <li><a href="#" id="appearance-tab" role="tab" aria-controls="appearance-settings" aria-selected="false">Appearance</a></li>
                    <li><a href="#" id="notifications-tab" role="tab" aria-controls="notifications-settings" aria-selected="false">Notifications</a></li>
                    <li><a href="#" id="security-tab" role="tab" aria-controls="security-settings" aria-selected="false">Security</a></li>
                    </ul>
            </nav>
            <button class="back-button" onclick="window.history.back()"><i class="fas fa-arrow-left"></i> Kembali</button>
        </div>

        <div class="content">
            <div id="account-settings" class="setting-section active" role="tabpanel" aria-labelledby="account-tab" tabindex="-1">
                <h3>Pengaturan Akun</h3>
                <form id="account-form" enctype="multipart/form-data"> <div class="form-group">
                        <label for="profile-photo-input">Foto Profil:</label>
                        <div class="profile-photo-upload">
                            <img id="profile-photo-preview" src="<?= htmlspecialchars($userSettings['profile_picture']) ?>" alt="Foto Profil Saat Ini">
                            <div class="photo-actions">
                                <label for="profile-photo-input" class="custom-file-upload" tabindex="0">Ganti Foto</label>
                                <input type="file" id="profile-photo-input" name="profile_picture" accept="image/png, image/jpeg, image/gif">
                                <button type="button" class="remove-photo-btn" id="remove-profile-photo">Hapus Foto</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="full-name">Nama Lengkap:</label>
                        <input type="text" id="full-name" name="full_name" value="<?= htmlspecialchars($userSettings['full_name']) ?>" required aria-required="true">
                    </div>
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($userSettings['username']) ?>" required pattern="^[a-zA-Z0-9_]{3,16}$" title="Username hanya boleh mengandung huruf, angka, dan underscore (_), minimal 3 karakter, maksimal 16 karakter." aria-describedby="username-help">
                        <small id="username-help">Hanya huruf, angka, dan underscore (_).</small>
                    </div>
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($userSettings['email']) ?>" required aria-required="true">
                    </div>
                    <div class="form-group">
                        <label for="phone-number">Nomor Telepon:</label>
                        <input type="tel" id="phone-number" name="phone_number" value="<?= htmlspecialchars($userSettings['phone_number']) ?>" pattern="^\+?[0-9]{7,15}$" title="Format nomor telepon tidak valid." aria-describedby="phone-help">
                        <small id="phone-help">Contoh: +628123456789</small>
                    </div>
                    <button type="submit" class="btn btn-success" data-form-id="account-form">Simpan Perubahan</button>

                    <h4 style="margin-top: 30px;">Manajemen Data</h4>
                    <button type="button" class="btn btn-primary" id="export-data-btn">Ekspor Data Akun (JSON)</button>

                    <h4 style="margin-top: 30px; color: var(--danger-color);">Penonaktifan Akun</h4>
                    <p style="font-size: 0.9em; color: var(--light-text-color);">
                        Menonaktifkan akun Anda akan menyembunyikan profil Anda dari publik dan menonaktifkan akses Anda ke fitur tertentu. Anda dapat mengaktifkan kembali akun Anda kapan saja dengan masuk kembali.
                    </p>
                    <button type="button" class="btn btn-danger" id="deactivate-account-btn">Nonaktifkan Akun</button>
                </form>
            </div>

            <div id="appearance-settings" class="setting-section" role="tabpanel" aria-labelledby="appearance-tab" tabindex="-1">
                <h3>Tampilan</h3>
                <form id="appearance-form">
                    <div class="form-group">
                        <label>Tema:</label>
                        <div class="theme-options">
                            <label><input type="radio" name="theme" value="light" id="theme-light"> Light</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="accent-color-picker">Warna Aksen:</label>
                        <input type="color" id="accent-color-picker" value="<?= htmlspecialchars($userSettings['accent_color']) ?>">
                        <small>Pilih warna kustom untuk aksen UI Anda.</small>
                    </div>
                    <div class="form-group">
                        <label>Pilihan Warna Cepat:</label>
                        <div class="color-palette">
                            <button type="button" style="background-color: #007bff;" data-color="#007bff" class="selected" aria-label="Biru"></button>
                            <button type="button" style="background-color: #28a745;" data-color="#28a745" aria-label="Hijau"></button>
                            <button type="button" style="background-color: #ffc107;" data-color="#ffc107" aria-label="Kuning"></button>
                            <button type="button" style="background-color: #17a2b8;" data-color="#17a2b8" aria-label="Biru Muda"></button>
                            <button type="button" style="background-color: #6f42c1;" data-color="#6f42c1" aria-label="Ungu"></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="font-size-select">Ukuran Font:</label>
                        <select id="font-size-select" name="font_size_preference">
                            <option value="small">Kecil</option>
                            <option value="medium" selected>Sedang</option>
                            <option value="large">Besar</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success" data-form-id="appearance-form">Simpan Perubahan</button>
                </form>
            </div>

            <div id="notifications-settings" class="setting-section" role="tabpanel" aria-labelledby="notifications-tab" tabindex="-1">
                <h3>Notifikasi</h3>
                <form id="notifications-form">
                    <div class="form-group">
                        <label>Notifikasi Email:</label>
                        <div class="notification-item">
                            <span>Pembaruan Produk & Fitur</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="email-notif-product" name="email_product_updates" <?= $userSettings['email_product_updates'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['email_product_updates'] ? 'true' : 'false' ?>">
                                <span class="slider" tabindex="0"></span>
                            </label>
                        </div>
                        <div class="notification-item">
                            <span>Pengumuman Penting</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="email-notif-announcement" name="email_announcements" <?= $userSettings['email_announcements'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['email_announcements'] ? 'true' : 'false' ?>">
                                <span class="slider" tabindex="0"></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Push Notification:</label>
                        <div class="notification-item">
                            <span>Pesan Baru</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="push-notif-message" name="push_new_messages" <?= $userSettings['push_new_messages'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['push_new_messages'] ? 'true' : 'false' ?>">
                                <span class="slider" tabindex="0"></span>
                            </label>
                        </div>
                        <div class="notification-item">
                            <span>Pengingat Acara</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="push-notif-event" name="push_event_reminders" <?= $userSettings['push_event_reminders'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['push_event_reminders'] ? 'true' : 'false' ?>">
                                <span class="slider" tabindex="0"></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="notification-item">
                        <label for="sms-notif">Notifikasi SMS (Verifikasi & Peringatan)</label>
                        <label class="toggle-switch">
                            <input type="checkbox" id="sms-notif" name="sms_notifications" <?= $userSettings['sms_notifications'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['sms_notifications'] ? 'true' : 'false' ?>">
                            <span class="slider" tabindex="0"></span>
                        </label>
                    </div>
                    <div class="notification-item">
                        <label for="marketing-email">Email Marketing & Penawaran</label>
                        <label class="toggle-switch">
                            <input type="checkbox" id="marketing-email" name="marketing_email" <?= $userSettings['marketing_email'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['marketing_email'] ? 'true' : 'false' ?>">
                            <span class="slider" tabindex="0"></span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-success" data-form-id="notifications-form">Simpan Perubahan</button>
                </form>
            </div>

            <div id="security-settings" class="setting-section" role="tabpanel" aria-labelledby="security-tab" tabindex="-1">
                <h3>Keamanan</h3>
                <form id="security-form">
                    <h4>Ganti Password</h4>
                    <div class="form-group">
                        <label for="current-password">Password Saat Ini:</label>
                        <input type="password" id="current-password" name="current_password" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="new-password">Password Baru:</label>
                        <input type="password" id="new-password" name="new_password" required pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Password harus setidaknya 8 karakter, mengandung setidaknya satu huruf besar, satu huruf kecil, dan satu angka." autocomplete="new-password" aria-describedby="password-help password-strength-text">
                        <small id="password-help">Minimal 8 karakter, kombinasi huruf besar, kecil, dan angka.</small>
                        <div class="password-strength-indicator">
                            <div class="strength-bar" id="password-strength-bar"></div>
                        </div>
                        <div class="strength-text" id="password-strength-text"></div>
                    </div>
                    <div class="form-group">
                        <label for="confirm-password">Konfirmasi Password Baru:</label>
                        <input type="password" id="confirm-password" name="confirm_password" required autocomplete="new-password" aria-describedby="confirm-password-error">
                        <span id="confirm-password-error" style="color: var(--danger-color); display: none;">Password baru dan konfirmasi password tidak cocok.</span>
                    </div>
                    <button type="submit" class="btn btn-primary" data-form-id="security-form">Ubah Password</button>

                    <h4>Autentikasi Dua Faktor</h4>
                    <div class="two-factor-settings">
                        <div class="notification-item" style="border-bottom: none;">
                            <label for="two-factor-auth">Aktifkan Autentikasi Dua Faktor</label>
                            <label class="toggle-switch">
                                <input type="checkbox" id="two-factor-auth" name="two_factor_enabled" <?= $userSettings['two_factor_enabled'] ? 'checked' : '' ?> role="switch" aria-checked="<?= $userSettings['two_factor_enabled'] ? 'true' : 'false' ?>">
                                <span class="slider" tabindex="0"></span>
                            </label>
                        </div>
                        <small>Tambahkan lapisan keamanan ekstra pada akun Anda.</small>
                        <button type="button" class="btn btn-primary" style="margin-top: 15px;" id="configure-2fa-btn" <?= $userSettings['two_factor_enabled'] ? 'disabled' : '' ?>>Konfigurasi 2FA</button>
                    </div>

                    <h4>Manajemen Sesi</h4>
                    <p>Sesi aktif Anda:</p>
                    <div class="session-item">
                        <div>
                            <span>Chrome di Windows (Saat ini)</span>
                            <span>Jakarta, Indonesia</span>
                            <span><?= date('d F Y, H:i', time()) ?> WIB</span>
                        </div>
                    </div>
                    <div id="other-sessions-list">
                        <div class="session-item">
                            <div>
                                <span>Safari di iPhone</span>
                                <span>Bandung, Indonesia</span>
                                <span>18 Juni 2025, 15:00 WIB</span>
                            </div>
                            <button type="button" class="btn btn-danger btn-small">Keluar</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-danger" id="logout-all-sessions">Keluar dari Semua Sesi Lain</button>

                    <h4 style="margin-top: 30px;">Aktivitas Login Terbaru</h4>
                    <div class="login-activity-list">
                        </div>
                </form>
            </div>

            </div>
    </div>

    <div id="notification-message" class="notification-message" role="status" aria-live="polite"></div>

    <div id="confirmation-dialog-overlay" class="confirmation-dialog-overlay" role="dialog" aria-modal="true" aria-labelledby="confirmation-dialog-title">
        <div class="confirmation-dialog">
            <h4 id="confirmation-dialog-title">Konfirmasi Tindakan</h4>
            <p id="confirmation-dialog-message">Apakah Anda yakin ingin melakukan tindakan ini?</p>
            <div class="dialog-actions">
                <button type="button" class="btn btn-primary" id="confirm-dialog-yes">Ya</button>
                <button type="button" class="btn remove-photo-btn" id="confirm-dialog-no">Tidak</button>
            </div>
        </div>
    </div>

    <div id="2fa-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="2fa-modal-title">
        <div class="modal-content">
            <h4 id="2fa-modal-title">Konfigurasi Autentikasi Dua Faktor</h4>
            <div class="modal-body">
                <p>Untuk mengaktifkan 2FA, pindai kode QR di bawah ini dengan aplikasi autentikator pilihan Anda (misalnya Google Authenticator, Authy).</p>
                <div class="qr-code-placeholder">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=otpauth://totp/MyApp:<?= urlencode($userSettings['email']) ?>?secret=<?= $userSettings['two_factor_secret'] ?? 'GENERATE_NEW_SECRET_HERE' ?>" alt="Kode QR 2FA" style="width: 100%; height: auto;">
                    <small>Simulasi QR Code (Secret dan QR harus dibuat di backend)</small>
                </div>
                <p>Atau, masukkan kunci secara manual:</p>
                <div class="form-group">
                    <label for="2fa-secret-key">Kunci Rahasia:</label>
                    <input type="text" id="2fa-secret-key" value="<?= $userSettings['two_factor_secret'] ?? 'GENERATE_NEW_SECRET_HERE' ?>" readonly>
                    <button type="button" class="btn btn-primary btn-small" style="margin-top: 5px; width: auto;">Salin Kunci</button>
                </div>
                <div class="form-group">
                    <label for="2fa-verification-code">Kode Verifikasi:</label>
                    <input type="text" id="2fa-verification-code" placeholder="Masukkan 6 digit kode">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-success" id="2fa-modal-verify-btn">Verifikasi & Aktifkan</button>
                <button type="button" class="btn remove-photo-btn" id="2fa-modal-cancel-btn">Batal</button>
            </div>
        </div>
    </div>

    <script>
        // --- 1. Global Data from PHP ---
        const initialUserSettings = <?= $jsUserSettings ?>; // Data pengaturan pengguna dari PHP
        const initialLoginActivities = <?= $jsLoginActivities ?>; // Data aktivitas login dari PHP

        // --- 2. UI Utilities Module (ui-utils.js) ---
        // Handles common UI interactions like notifications, loading states, and confirmation dialogs.
        const uiUtils = (() => {
            const notificationMessage = document.getElementById('notification-message');
            const confirmationDialogOverlay = document.getElementById('confirmation-dialog-overlay');
            const confirmationDialogTitle = document.getElementById('confirmation-dialog-title');
            const confirmationDialogMessage = document.getElementById('confirmation-dialog-message');
            const confirmDialogYesBtn = document.getElementById('confirm-dialog-yes');
            const confirmDialogNoBtn = document.getElementById('confirm-dialog-no');

            let resolveConfirmationPromise; // Used to resolve the promise for confirmation dialog

            /**
             * Shows a transient notification message to the user.
             * @param {string} message - The message to display.
             * @param {'success'|'error'|'warning'} [type='success'] - The type of notification.
             */
            function showNotification(message, type = 'success') {
                notificationMessage.textContent = message;
                // Clear all existing type classes and add the new one
                notificationMessage.classList.remove('success', 'error', 'warning', 'show');
                notificationMessage.classList.add('show', type);
                
                // Set ARIA live region for screen readers based on notification type
                if (type === 'error' || type === 'warning') {
                    notificationMessage.setAttribute('aria-live', 'assertive'); // Interruptive
                } else {
                    notificationMessage.setAttribute('aria-live', 'polite');    // Non-interruptive
                }

                // Hide notification after 3 seconds
                setTimeout(() => {
                    notificationMessage.classList.remove('show');
                }, 3000);
            }

            /**
             * Toggles a loading state on a button, disabling it and adding a spinner.
             * @param {HTMLButtonElement} button - The button element.
             * @param {boolean} isLoading - True to show loading, false to hide.
             * @param {string} originalText - The original text of the button.
             */
            function toggleLoading(button, isLoading, originalText) {
                if (!button) return; // Ensure button exists

                if (isLoading) {
                    button.setAttribute('disabled', 'true'); // Disable button
                    // Only add spinner if not already present
                    if (!button.querySelector('.spinner')) {
                        // Dynamically change text based on action
                        let loadingText = originalText;
                        if (originalText.includes('Simpan')) loadingText = 'Menyimpan...';
                        else if (originalText.includes('Ubah')) loadingText = 'Mengubah...';
                        else if (originalText.includes('Ekspor')) loadingText = 'Mengekspor...';
                        else if (originalText.includes('Nonaktifkan')) loadingText = 'Menonaktifkan...';
                        else if (originalText.includes('Verifikasi')) loadingText = 'Memverifikasi...';
                        else if (originalText.includes('Keluar')) loadingText = 'Keluar...';
                        // Removed 'Hubungkan'/'Putuskan' as Integrations tab is gone
                        
                        button.innerHTML = `<span class="spinner"></span> ${loadingText}`;
                    }
                } else {
                    button.removeAttribute('disabled'); // Re-enable button
                    button.textContent = originalText; // Restore original text
                }
            }

            /**
             * Displays a custom confirmation dialog and returns a Promise that resolves to true (Yes) or false (No).
             * @param {string} title - The title of the dialog.
             * @param {string} message - The message body of the dialog.
             * @returns {Promise<boolean>} A promise that resolves to true if confirmed, false otherwise.
             */
            function showConfirmationDialog(title, message) {
                confirmationDialogTitle.textContent = title;
                confirmationDialogMessage.textContent = message;
                confirmationDialogOverlay.classList.add('show'); // Show the dialog

                // Return a new Promise that will be resolved when user clicks Yes/No
                return new Promise(resolve => {
                    resolveConfirmationPromise = resolve;
                });
            }

            // Event listeners for confirmation dialog buttons
            confirmDialogYesBtn.addEventListener('click', () => {
                confirmationDialogOverlay.classList.remove('show');
                if (resolveConfirmationPromise) {
                    resolveConfirmationPromise(true); // Resolve with true for 'Yes'
                }
            });

            confirmDialogNoBtn.addEventListener('click', () => {
                confirmationDialogOverlay.classList.remove('show');
                if (resolveConfirmationPromise) {
                    resolveConfirmationPromise(false); // Resolve with false for 'No'
                }
            });

            /**
             * Adds a 'shake' animation to a given element, typically a form, to indicate an error.
             * @param {HTMLElement} element - The element to shake.
             */
            function shakeElement(element) {
                element.classList.add('shake-effect');
                element.addEventListener('animationend', () => {
                    element.classList.remove('shake-effect');
                }, { once: true }); // Remove listener after one animation cycle
            }

            /**
             * Triggers a simple confetti effect originating from the center of the viewport.
             */
            function triggerConfetti() {
                const confettiContainer = document.createElement('div');
                confettiContainer.classList.add('confetti-container');
                document.body.appendChild(confettiContainer);

                const colors = ['#f00', '#0f0', '#00f', '#ff0', '#0ff', '#f0f']; // Confetti colors

                for (let i = 0; i < 50; i++) { // Generate 50 confetti pieces
                    const confetti = document.createElement('div');
                    confetti.classList.add('confetti');
                    confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                    
                    // Randomize starting position around the center
                    const startX = window.innerWidth / 2 + (Math.random() - 0.5) * 200;
                    const startY = window.innerHeight / 2 + (Math.random() - 0.5) * 200;
                    confetti.style.left = `${startX}px`;
                    confetti.style.top = `${startY}px`;

                    // Randomize end position and rotation for falling effect
                    const endX = (Math.random() - 0.5) * 800; // Spread horizontally
                    const endY = window.innerHeight + Math.random() * 200; // Fall past bottom
                    const rotation = Math.random() * 720; // Random rotation

                    confetti.style.setProperty('--confetti-end-x', `${endX}px`);
                    confetti.style.setProperty('--confetti-end-y', `${endY}px`);
                    confetti.style.setProperty('--confetti-rotation-end', `${rotation}deg`);

                    confettiContainer.appendChild(confetti);
                }

                // Remove confetti after animation (e.g., 3.5 seconds)
                setTimeout(() => {
                    confettiContainer.remove();
                }, 3500);
            }

            // Expose public methods
            return { showNotification, toggleLoading, showConfirmationDialog, shakeElement, triggerConfetti };
        })();


        // --- 3. Theme and Appearance Manager Module (appearance-manager.js) ---
        // Manages all visual settings including theme, accent color, and font size.
        const appearanceManager = (() => {
            const htmlElement = document.documentElement; // Root HTML element
            const themeRadios = document.querySelectorAll('input[name="theme"]');
            const accentColorPicker = document.getElementById('accent-color-picker');
            const colorPaletteButtons = document.querySelectorAll('.color-palette button');
            const fontSizeSelect = document.getElementById('font-size-select');

            /**
             * Applies the chosen theme to the HTML element.
             * Also saves the preference to localStorage.
             * @param {'light'|'dark'|'system'} theme - The theme to apply.
             */
            function applyTheme(theme) {
                if (theme === 'dark') {
                    htmlElement.classList.add('dark-mode');
                    localStorage.setItem('theme', 'dark');
                } else if (theme === 'light') {
                    htmlElement.classList.remove('dark-mode');
                    localStorage.setItem('theme', 'light');
                } else { // 'system'
                    localStorage.removeItem('theme'); // Clear stored preference to follow system
                    // Check current system preference and apply
                    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                        htmlElement.classList.add('dark-mode');
                    } else {
                        htmlElement.classList.remove('dark-mode');
                    }
                }
            }

            /**
             * Applies the chosen font size to the HTML element via CSS variable.
             * Also saves the preference to localStorage.
             * @param {'small'|'medium'|'large'} size - The font size to apply.
             */
            function applyFontSize(size) {
                htmlElement.classList.remove('font-small', 'font-medium', 'font-large'); // Remove existing
                htmlElement.classList.add(`font-${size}`); // Add new size class
                localStorage.setItem('font-size', size); // Save preference
            }

            /**
             * Applies the chosen accent color by setting a CSS variable.
             * @param {string} color - The hex color code (e.g., '#007bff').
             */
            function applyAccentColor(color) {
                document.documentElement.style.setProperty('--primary-color', color);
                accentColorPicker.value = color; // Keep color picker in sync
            }

            // --- Initialization on page load ---
            // Load saved theme from localStorage first, then PHP value if no localStorage
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                document.getElementById(`theme-${savedTheme}`).checked = true;
                applyTheme(savedTheme);
            } else if (initialUserSettings && initialUserSettings.theme_preference) {
                 // Use PHP value if no localStorage preference
                 document.getElementById(`theme-${initialUserSettings.theme_preference}`).checked = true;
                 applyTheme(initialUserSettings.theme_preference);
            } else {
                document.getElementById('theme-system').checked = true;
                applyTheme('system'); // Apply system preference if nothing saved or from PHP
            }

            // Load saved font size or set to default 'medium'
            const savedFontSize = localStorage.getItem('font-size');
            if (savedFontSize) {
                fontSizeSelect.value = savedFontSize;
                applyFontSize(savedFontSize);
            } else if (initialUserSettings && initialUserSettings.font_size_preference) {
                fontSizeSelect.value = initialUserSettings.font_size_preference;
                applyFontSize(initialUserSettings.font_size_preference);
            } else {
                fontSizeSelect.value = 'medium';
                applyFontSize('medium');
            }

            // Set initial selected accent color in palette based on current active color or PHP value
            const initialAccentColor = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim();
            // Fallback to PHP value if not set by CSS
            accentColorPicker.value = initialUserSettings.accent_color || initialAccentColor;
            applyAccentColor(initialUserSettings.accent_color || initialAccentColor); // Apply PHP value as initial color

            const initialSelectedButton = Array.from(colorPaletteButtons).find(btn => btn.dataset.color === (initialUserSettings.accent_color || initialAccentColor));
            if (initialSelectedButton) {
                initialSelectedButton.classList.add('selected');
            }


            // --- Event Listeners for Appearance settings (Live Preview) ---
            themeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    applyTheme(this.value);
                });
            });

            // Listen for system theme changes (only if user selected 'system')
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
                    if (!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') { // If theme is 'system' or not explicitly set
                        applyTheme('system');
                    }
                });
            }

            accentColorPicker.addEventListener('input', function() {
                applyAccentColor(this.value);
                // Deselect any palette button if a custom color is chosen
                colorPaletteButtons.forEach(btn => btn.classList.remove('selected'));
            });

            colorPaletteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const color = this.dataset.color;
                    applyAccentColor(color);
                    // Select the clicked palette button
                    colorPaletteButtons.forEach(btn => btn.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });

            fontSizeSelect.addEventListener('change', function() {
                applyFontSize(this.value);
            });

            // No public methods are returned here as `appearanceManager` directly manages UI
            // and `localStorage` for its state, and its methods are mostly for internal use.
        })();


        // --- 4. Main Application Logic (main.js) ---
        // Orchestrates interactions between different modules and handles form submissions.
        document.addEventListener('DOMContentLoaded', () => {
            // --- DOM Element References ---
            const tabs = document.querySelectorAll('.sidebar nav ul li a');
            const sections = document.querySelectorAll('.setting-section');

            // Mobile menu elements
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            const mobileSidebarOverlay = document.querySelector('.mobile-sidebar-overlay');
            const sidebar = document.querySelector('.sidebar');

            // Account Tab Elements
            const fullNameInput = document.getElementById('full-name');
            const usernameInput = document.getElementById('username');
            const emailInput = document.getElementById('email');
            const phoneNumberInput = document.getElementById('phone-number');
            const profilePhotoInput = document.getElementById('profile-photo-input');
            const profilePhotoPreview = document.getElementById('profile-photo-preview');
            const removeProfilePhotoBtn = document.getElementById('remove-profile-photo');
            const accountForm = document.getElementById('account-form');
            const exportDataBtn = document.getElementById('export-data-btn');
            const deactivateAccountBtn = document.getElementById('deactivate-account-btn');

            // Notifications Tab Elements
            const notificationsForm = document.getElementById('notifications-form');
            const emailNotifProductToggle = document.getElementById('email-notif-product');
            const emailNotifAnnouncementToggle = document.getElementById('email-notif-announcement');
            const pushNotifMessageToggle = document.getElementById('push-notif-message');
            const pushNotifEventToggle = document.getElementById('push-notif-event');
            const smsNotifToggle = document.getElementById('sms-notif');
            const marketingEmailToggle = document.getElementById('marketing-email');


            // Security Tab Elements
            const securityForm = document.getElementById('security-form');
            const newPasswordInput = document.getElementById('new-password');
            const confirmPasswordInput = document.getElementById('confirm-password');
            const confirmPasswordError = document.getElementById('confirm-password-error');
            const passwordStrengthBar = document.getElementById('password-strength-bar');
            const passwordStrengthText = document.getElementById('password-strength-text');
            const twoFactorToggle = document.getElementById('two-factor-auth');
            const configure2FABtn = document.getElementById('configure-2fa-btn');
            const logoutAllSessionsBtn = document.getElementById('logout-all-sessions');
            const loginActivityList = document.querySelector('.login-activity-list');

            // 2FA Modal Elements
            const twoFAModalOverlay = document.getElementById('2fa-modal-overlay');
            const twoFAModalVerifyBtn = document.getElementById('2fa-modal-verify-btn');
            const twoFAModalCancelBtn = document.getElementById('2fa-modal-cancel-btn');

            // Integrations Tab Elements (REMOVED: No longer needed)
            // const integrationsForm = document.getElementById('integrations-form');
            // const googleStatusSpan = document.getElementById('google-status');
            // const googleConnectedInput = document.getElementById('google-connected-input');
            // const toggleGoogleIntegrationBtn = document.getElementById('toggle-google-integration');

            // const slackStatusSpan = document.getElementById('slack-status');
            // const slackConnectedInput = document.getElementById('slack-connected-input');
            // const toggleSlackIntegrationBtn = document.getElementById('toggle-slack-integration');


            // --- Initial Data Loading (Populate UI from PHP data) ---
            /**
             * Populates the form fields with user settings data.
             * @param {Object} settings - The user settings object.
             */
            function populateUIWithUserSettings(settings) {
                // Account Tab
                fullNameInput.value = settings.full_name;
                usernameInput.value = settings.username;
                emailInput.value = settings.email;
                phoneNumberInput.value = settings.phone_number;
                profilePhotoPreview.src = settings.profile_picture;

                // Appearance Tab: Already handled by appearanceManager on its own init,
                // which reads from localStorage or initialUserSettings.

                // Notifications Tab
                emailNotifProductToggle.checked = settings.email_product_updates;
                emailNotifProductToggle.setAttribute('aria-checked', settings.email_product_updates);
                emailNotifAnnouncementToggle.checked = settings.email_announcements;
                emailNotifAnnouncementToggle.setAttribute('aria-checked', settings.email_announcements);
                pushNotifMessageToggle.checked = settings.push_new_messages;
                pushNotifMessageToggle.setAttribute('aria-checked', settings.push_new_messages);
                pushNotifEventToggle.checked = settings.push_event_reminders;
                pushNotifEventToggle.setAttribute('aria-checked', settings.push_event_reminders);
                smsNotifToggle.checked = settings.sms_notifications;
                smsNotifToggle.setAttribute('aria-checked', settings.sms_notifications);
                marketingEmailToggle.checked = settings.marketing_email;
                marketingEmailToggle.setAttribute('aria-checked', settings.marketing_email);

                // Security Tab
                twoFactorToggle.checked = settings.two_factor_enabled;
                twoFactorToggle.setAttribute('aria-checked', settings.two_factor_enabled);
                configure2FABtn.disabled = settings.two_factor_enabled; // Disable configure if already enabled

                // Integrations Tab (REMOVED: No longer updated here)
                // updateIntegrationsUI(settings);
            }

            /**
             * Renders the list of recent login activities.
             * @param {Array<Object>} activities - An array of login activity objects.
             */
            function renderLoginActivities(activities) {
                loginActivityList.innerHTML = ''; // Clear existing list
                if (activities.length === 0) {
                    loginActivityList.innerHTML = '<p style="color: var(--light-text-color);">Tidak ada aktivitas login terbaru.</p>';
                    return;
                }
                activities.forEach(activity => {
                    const div = document.createElement('div');
                    div.className = 'login-activity-item';
                    div.innerHTML = `
                        <strong>${activity.type}</strong>
                        <span>Perangkat: ${activity.device}</span>
                        <span>Lokasi: ${activity.location}</span>
                        <span>Waktu: ${activity.time}</span>
                    `;
                    loginActivityList.appendChild(div);
                });
            }
            
            // Populate UI with data from PHP
            if (initialUserSettings) {
                populateUIWithUserSettings(initialUserSettings);
            }
            if (initialLoginActivities) {
                renderLoginActivities(initialLoginActivities);
            }

            // --- Mobile Menu Logic ---
            const toggleMobileMenu = () => {
                const isOpen = sidebar.classList.toggle('open');
                mobileSidebarOverlay.classList.toggle('show');
                mobileMenuToggle.setAttribute('aria-expanded', isOpen);
                // Prevent body scrolling when menu is open
                document.body.style.overflow = isOpen ? 'hidden' : '';
            };

            mobileMenuToggle.addEventListener('click', toggleMobileMenu);
            mobileSidebarOverlay.addEventListener('click', toggleMobileMenu); // Close when overlay is clicked

            // Close mobile menu when a navigation item is clicked (only on mobile view)
            sidebar.querySelectorAll('nav ul li a').forEach(item => {
                item.addEventListener('click', () => {
                    // Check if current view width is less than or equal to 992px (our mobile breakpoint)
                    if (window.innerWidth <= 992) { 
                        toggleMobileMenu(); // Close the menu
                    }
                });
            });


            // --- Tab Switching Logic ---
            tabs.forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault(); // Prevent default link behavior

                    // Update ARIA attributes and active classes for all tabs and sections
                    tabs.forEach(t => {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    sections.forEach(s => {
                        s.classList.remove('active');
                        s.setAttribute('tabindex', '-1'); // Make inactive sections non-focusable
                    });

                    // Set active tab and section
                    e.target.classList.add('active');
                    e.target.setAttribute('aria-selected', 'true');

                    const targetId = e.target.id.replace('-tab', '-settings');
                    const activeSection = document.getElementById(targetId);
                    activeSection.classList.add('active');
                    activeSection.setAttribute('tabindex', '0'); // Make active section focusable
                    activeSection.focus(); // Set focus to the active tab panel for better accessibility
                });
            });

            // --- Form Submission Handlers (AJAX/Fetch to Backend) ---
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formId = this.id;
                    const submitButton = e.submitter;
                    const originalButtonText = submitButton.textContent;
                    
                    if (!this.checkValidity()) {
                        this.reportValidity();
                        uiUtils.showNotification('Mohon perbaiki kesalahan pada formulir.', 'error');
                        uiUtils.shakeElement(this); // Shake the form on validation error
                        return;
                    }

                    let endpoint = '';
                    let formData = new FormData(this); // Use FormData for easy handling of all input types, including files

                    // Specific validation and endpoint mapping
                    switch (formId) {
                        case 'account-form':
                            endpoint = 'api/update_account.php';
                            // Add profile picture file if selected
                            if (profilePhotoInput.files.length > 0) {
                                formData.append('profile_picture_file', profilePhotoInput.files[0]);
                            } else if (profilePhotoPreview.src.includes('placeholder.com') && profilePhotoInput.value === '') {
                                // If placeholder is shown and no new file selected, indicate default photo
                                formData.append('profile_picture_action', 'reset_to_default');
                            }
                            break;
                        case 'appearance-form':
                            endpoint = 'api/update_appearance.php';
                            // Add current accent color from CSS var
                            formData.append('accent_color', getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim());
                            break;
                        case 'notifications-form':
                            endpoint = 'api/update_notifications.php';
                            // Ensure checkboxes send values even if unchecked (FormData only includes checked ones)
                            // Append all checkboxes explicitly
                            formData.append('email_product_updates', emailNotifProductToggle.checked ? '1' : '0');
                            formData.append('email_announcements', emailNotifAnnouncementToggle.checked ? '1' : '0');
                            formData.append('push_new_messages', pushNotifMessageToggle.checked ? '1' : '0');
                            formData.append('push_event_reminders', pushNotifEventToggle.checked ? '1' : '0');
                            formData.append('sms_notifications', smsNotifToggle.checked ? '1' : '0');
                            formData.append('marketing_email', marketingEmailToggle.checked ? '1' : '0');
                            break;
                        case 'security-form':
                            endpoint = 'api/change_password.php';
                            if (newPasswordInput.value !== confirmPasswordInput.value) {
                                confirmPasswordError.style.display = 'block';
                                confirmPasswordInput.setCustomValidity("Passwords Don't Match");
                                uiUtils.showNotification('Password baru dan konfirmasi password tidak cocok.', 'error');
                                uiUtils.shakeElement(this); // Shake on password mismatch
                                confirmPasswordInput.reportValidity();
                                return;
                            } else {
                                confirmPasswordError.style.display = 'none';
                                confirmPasswordInput.setCustomValidity("");
                            }
                            // Only send password fields, not 2FA toggle from this form submit
                            formData = new FormData();
                            formData.append('current_password', document.getElementById('current-password').value);
                            formData.append('new_password', newPasswordInput.value);
                            formData.append('confirm_password', confirmPasswordInput.value);
                            break;
                        // Removed 'integrations-form' case
                        default:
                            uiUtils.showNotification('Form tidak dikenali.', 'error');
                            uiUtils.shakeElement(this);
                            return;
                    }

                    uiUtils.toggleLoading(submitButton, true, originalButtonText);

                    try {
                        const response = await fetch(endpoint, {
                            method: 'POST',
                            body: formData // FormData automatically sets Content-Type to multipart/form-data
                        });
                        const result = await response.json(); // Assuming PHP returns JSON
                        
                        if (response.ok && result.success) {
                            uiUtils.showNotification(result.message || 'Perubahan berhasil disimpan!', 'success');
                            uiUtils.triggerConfetti(); // Trigger confetti on success
                            if (formId === 'security-form') {
                                this.reset(); // Clear password fields after successful change
                                updatePasswordStrength(''); // Reset strength indicator
                            }
                            // Refresh UI for account details after update, if needed
                            if (formId === 'account-form') {
                                // Potentially update sidebar username/photo if changed
                                // For simplicity, we just rely on next page load or manual update
                            }
                        } else {
                            uiUtils.showNotification(result.message || 'Terjadi kesalahan saat menyimpan perubahan. Silakan coba lagi.', 'error');
                            uiUtils.shakeElement(this); // Shake form on server-side error
                        }
                    } catch (error) {
                        console.error('Error during form submission:', error);
                        uiUtils.showNotification('Terjadi kesalahan jaringan atau server.', 'error');
                        uiUtils.shakeElement(this); // Shake form on network error
                    } finally {
                        uiUtils.toggleLoading(submitButton, false, originalButtonText);
                    }
                });
            });


            // --- Account Tab Specific Logic ---
            profilePhotoInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Validate file size (max 2MB)
                    if (file.size > 2 * 1024 * 1024) {
                        uiUtils.showNotification('Ukuran file terlalu besar. Maksimal 2MB.', 'error');
                        this.value = ''; // Clear selected file
                        return;
                    }
                    // Validate file type
                    if (!['image/png', 'image/jpeg', 'image/gif'].includes(file.type)) {
                        uiUtils.showNotification('Format file tidak didukung. Gunakan PNG, JPG, atau GIF.', 'error');
                        this.value = ''; // Clear selected file
                        return;
                    }

                    // Read and display image preview
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        profilePhotoPreview.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });

            removeProfilePhotoBtn.addEventListener('click', function() {
                // Confirm with user
                uiUtils.showConfirmationDialog("Hapus Foto Profil", "Apakah Anda yakin ingin menghapus foto profil Anda? Ini akan direset ke default.").then(confirmed => {
                    if (confirmed) {
                        // In a real app, send API call to remove/reset photo
                        // Example: fetch('api/remove_profile_photo.php', { method: 'POST' }).then(...)
                        profilePhotoPreview.src = 'https://via.placeholder.com/90/007bff/FFFFFF?text=JD'; // Reset to default placeholder
                        profilePhotoInput.value = ''; // Clear file input value
                        uiUtils.showNotification('Foto profil berhasil dihapus.');
                    }
                });
            });

            exportDataBtn.addEventListener('click', async function() {
                const originalButtonText = this.textContent;
                uiUtils.toggleLoading(this, true, originalButtonText);

                // In a real application: Replace with actual fetch to your backend API for data export
                // This API should collect user's data (excluding sensitive like passwords/2FA secrets)
                // and return it as JSON or a file download.
                try {
                    const response = await fetch('api/export_user_data.php'); // Create this PHP file
                    const data = await response.json(); // Assuming it returns JSON

                    if (response.ok && data.success) {
                        const dataStr = JSON.stringify(data.user_data, null, 2); // Pretty print JSON
                        const blob = new Blob([dataStr], { type: 'application/json' }); // Create a Blob
                        const url = URL.createObjectURL(blob); // Create a temporary URL
                        
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `user_data_${Date.now()}.json`;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        uiUtils.showNotification('Data akun berhasil diekspor!', 'success');
                        uiUtils.triggerConfetti(); // Confetti on successful export
                    } else {
                        uiUtils.showNotification(data.message || 'Gagal mengekspor data akun.', 'error');
                    }
                } catch (error) {
                    console.error("Failed to export data:", error);
                    uiUtils.showNotification('Terjadi kesalahan jaringan saat mengekspor data akun.', 'error');
                } finally {
                    uiUtils.toggleLoading(this, false, originalButtonText);
                }
            });

            deactivateAccountBtn.addEventListener('click', async function() {
                const confirmed = await uiUtils.showConfirmationDialog(
                    "Konfirmasi Penonaktifan Akun",
                    "Anda yakin ingin menonaktifkan akun Anda? Anda dapat mengaktifkannya kembali dengan masuk."
                );

                if (confirmed) {
                    const originalButtonText = this.textContent;
                    uiUtils.toggleLoading(this, true, originalButtonText);
                    // In a real application: Send API call to deactivate account
                    try {
                        const response = await fetch('api/deactivate_account.php', { method: 'POST' }); // Create this PHP file
                        const result = await response.json();
                        if (response.ok && result.success) {
                            uiUtils.showNotification('Akun Anda berhasil dinonaktifkan.', 'success');
                            uiUtils.triggerConfetti(); // Confetti on successful deactivation
                            // Redirect to logout or a confirmation page after a short delay
                            setTimeout(() => { window.location.href = 'logout.php'; }, 1000);
                        } else {
                            uiUtils.showNotification(result.message || 'Gagal menonaktifkan akun.', 'error');
                        }
                    } catch (error) {
                        console.error('Error deactivating account:', error);
                        uiUtils.showNotification('Terjadi kesalahan jaringan saat menonaktifkan akun.', 'error');
                    } finally {
                         uiUtils.toggleLoading(this, false, originalButtonText);
                    }
                }
            });


            // --- Security Tab Specific Logic ---
            // Event listeners for password validation and strength indicator
            newPasswordInput.addEventListener('input', () => {
                validatePasswordMatch();
                updatePasswordStrength(newPasswordInput.value);
            });
            confirmPasswordInput.addEventListener('input', validatePasswordMatch);

            /**
             * Validates if the new password and confirm password fields match.
             */
            function validatePasswordMatch() {
                if (newPasswordInput.value !== confirmPasswordInput.value) {
                    confirmPasswordError.style.display = 'block';
                    confirmPasswordInput.setCustomValidity("Passwords Don't Match"); // HTML5 validation message
                } else {
                    confirmPasswordError.style.display = 'none';
                    confirmPasswordInput.setCustomValidity(""); // Clear custom validity message
                }
            }

            /**
             * Updates the visual password strength indicator based on the given password.
             * @param {string} password - The password string to evaluate.
             */
            function updatePasswordStrength(password) {
                let strength = 0;
                const classList = passwordStrengthText.classList;

                // Rule-based strength calculation (adjust as needed)
                if (password.length >= 8) strength++; // Min length
                if (/[A-Z]/.test(password)) strength++; // Uppercase
                if (/[a-z]/.test(password)) strength++; // Lowercase
                if (/\d/.test(password)) strength++; // Digits
                if (/[^A-Za-z0-9]/.test(password)) strength++; // Special characters

                // Update the strength bar width and color
                passwordStrengthBar.style.width = `${strength * 20}%`;
                passwordStrengthBar.className = 'strength-bar'; // Reset classes for strength bar
                classList.remove('strength-weak', 'strength-medium', 'strength-strong'); // Reset classes for text

                // Update text and class based on strength score
                if (password.length === 0) {
                    passwordStrengthText.textContent = '';
                    passwordStrengthBar.style.width = '0%';
                } else if (strength < 3) {
                    passwordStrengthText.textContent = 'Lemah';
                    classList.add('strength-weak');
                } else if (strength < 5) {
                    passwordStrengthText.textContent = 'Sedang';
                    classList.add('strength-medium');
                } else {
                    passwordStrengthText.textContent = 'Kuat';
                    classList.add('strength-strong');
                }
            }

            // --- Configure 2FA Modal Logic ---
            configure2FABtn.addEventListener('click', function() {
                twoFAModalOverlay.classList.add('show');
            });

            twoFAModalCancelBtn.addEventListener('click', function() {
                twoFAModalOverlay.classList.remove('show');
            });

            twoFAModalVerifyBtn.addEventListener('click', async function() {
                const verificationCode = document.getElementById('2fa-verification-code').value;
                if (verificationCode.length !== 6 || !/^\d+$/.test(verificationCode)) {
                    uiUtils.showNotification('Kode verifikasi harus 6 digit angka.', 'error');
                    uiUtils.shakeElement(document.querySelector('.modal-content')); // Shake modal
                    return;
                }

                uiUtils.toggleLoading(this, true, this.textContent);
                try {
                    // Send verification code to backend
                    const response = await fetch('api/verify_2fa.php', { // Create this PHP file
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: verificationCode })
                    });
                    const result = await response.json();

                    if (response.ok && result.success) {
                        uiUtils.showNotification('2FA berhasil diaktifkan!', 'success');
                        uiUtils.triggerConfetti(); // Confetti on successful 2FA activation
                        twoFactorToggle.checked = true; // Update UI toggle
                        twoFactorToggle.setAttribute('aria-checked', true);
                        configure2FABtn.disabled = true; // Disable config button
                        twoFAModalOverlay.classList.remove('show'); // Close modal
                    } else {
                        uiUtils.showNotification(result.message || 'Kode verifikasi tidak valid. Silakan coba lagi.', 'error');
                        uiUtils.shakeElement(document.querySelector('.modal-content')); // Shake modal on error
                    }
                } catch (error) {
                    console.error('Error verifying 2FA:', error);
                    uiUtils.showNotification('Terjadi kesalahan jaringan saat memverifikasi 2FA.', 'error');
                    uiUtils.shakeElement(document.querySelector('.modal-content')); // Shake modal on error
                } finally {
                    uiUtils.toggleLoading(this, false, this.textContent);
                }
            });

            // Handle 2FA toggle change (to enable/disable)
            twoFactorToggle.addEventListener('change', async function() {
                const isEnabled = this.checked;
                let confirmed = false;

                if (isEnabled) {
                    // If enabling, direct to configuration modal (or enable directly if secret already generated)
                    if (!initialUserSettings.two_factor_enabled) { // Only show config if not already enabled from DB
                        configure2FABtn.click(); // Programmatically click config button
                        this.checked = false; // Keep toggle off until verified in modal
                        this.setAttribute('aria-checked', false);
                        return;
                    }
                    confirmed = await uiUtils.showConfirmationDialog("Aktifkan 2FA", "Apakah Anda yakin ingin mengaktifkan kembali Autentikasi Dua Faktor?");
                } else {
                    confirmed = await uiUtils.showConfirmationDialog("Nonaktifkan 2FA", "Apakah Anda yakin ingin menonaktifkan Autentikasi Dua Faktor?");
                }

                if (confirmed) {
                    const originalText = 'Mengubah...'; // Generic loading text for toggle
                    uiUtils.toggleLoading(this.closest('.toggle-switch').querySelector('.slider'), true, originalText); // Apply loading to slider
                    
                    try {
                        const response = await fetch('api/toggle_2fa.php', { // Create this PHP file
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ enable: isEnabled })
                        });
                        const result = await response.json();

                        if (response.ok && result.success) {
                            uiUtils.showNotification(result.message || `2FA berhasil ${isEnabled ? 'diaktifkan' : 'dinonaktifkan'}.`, 'success');
                            uiUtils.triggerConfetti(); // Confetti on successful 2FA toggle
                            configure2FABtn.disabled = isEnabled; // Disable config if enabled
                            // Update initialUserSettings to reflect new state
                            initialUserSettings.two_factor_enabled = isEnabled;
                        } else {
                            uiUtils.showNotification(result.message || `Gagal ${isEnabled ? 'mengaktifkan' : 'menonaktifkan'} 2FA.`, 'error');
                            this.checked = !isEnabled; // Revert toggle on failure
                            this.setAttribute('aria-checked', !isEnabled);
                        }
                    } catch (error) {
                        console.error('Error toggling 2FA:', error);
                        uiUtils.showNotification('Terjadi kesalahan jaringan saat mengubah status 2FA.', 'error');
                        this.checked = !isEnabled; // Revert toggle on failure
                        this.setAttribute('aria-checked', !isEnabled);
                    } finally {
                        // Manually remove loading state, as toggleLoading isn't designed for this specific pattern
                        const sliderButton = this.closest('.toggle-switch').querySelector('.slider');
                        if (sliderButton) {
                             sliderButton.innerHTML = ''; // Remove spinner
                        }
                    }
                } else {
                    this.checked = !isEnabled; // Revert toggle if not confirmed
                    this.setAttribute('aria-checked', !isEnabled);
                }
            });


            // --- Toggle Switch Accessibility (making custom slider focusable) ---
            // Since the actual checkbox is hidden, we need to make the visible slider focusable
            // and allow keyboard interaction (Space/Enter) to toggle the checkbox.
            document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(checkbox => {
                const slider = checkbox.nextElementSibling; // Get the .slider span
                if (slider) {
                    slider.addEventListener('keydown', (e) => {
                        if (e.key === ' ' || e.key === 'Enter') {
                            e.preventDefault(); // Prevent default space/enter behavior (e.g., scrolling)
                            checkbox.checked = !checkbox.checked; // Toggle the actual checkbox
                            checkbox.dispatchEvent(new Event('change')); // Manually trigger change event
                        }
                    });
                }
                // Update aria-checked attribute when checkbox state changes
                checkbox.addEventListener('change', function() {
                    this.setAttribute('aria-checked', this.checked);
                });
            });


            // --- Logout All Sessions Confirmation Dialog ---
            logoutAllSessionsBtn.addEventListener('click', async function() {
                const confirmed = await uiUtils.showConfirmationDialog(
                    "Konfirmasi Keluar Sesi",
                    "Anda yakin ingin keluar dari semua sesi lain? Anda akan tetap masuk di sesi ini."
                );

                if (confirmed) {
                    const originalButtonText = this.textContent;
                    uiUtils.toggleLoading(this, true, originalButtonText);
                    try {
                        const response = await fetch('api/logout_other_sessions.php', { method: 'POST' }); // Create this PHP file
                        const result = await response.json();
                        if (response.ok && result.success) {
                            uiUtils.showNotification('Berhasil keluar dari semua sesi lain.', 'success');
                            uiUtils.triggerConfetti(); // Confetti on successful logout
                            // Optionally, remove other sessions from UI (except current)
                            const otherSessionsList = document.getElementById('other-sessions-list');
                            if (otherSessionsList) {
                                otherSessionsList.innerHTML = '<p style="color: var(--light-text-color);">Tidak ada sesi lain yang aktif.</p>';
                            }
                        } else {
                            uiUtils.showNotification(result.message || 'Gagal keluar dari sesi lain.', 'error');
                        }
                    } catch (error) {
                        console.error('Error logging out other sessions:', error);
                        uiUtils.showNotification('Terjadi kesalahan jaringan saat keluar dari sesi lain.', 'error');
                    } finally {
                        uiUtils.toggleLoading(this, false, originalButtonText);
                    }
                }
            });

            // --- Integrations Tab Specific Logic (REMOVED) ---
            // toggleGoogleIntegrationBtn.addEventListener('click', function() { /* ... */ });
            // toggleSlackIntegrationBtn.addEventListener('click', function() { /* ... */ });
        });
    </script>
</body>
</html>