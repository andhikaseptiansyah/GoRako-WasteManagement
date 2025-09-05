<?php
// bank_sampah.php
// Include the database connection file
include 'db_connection.php';
// Include helpers for authentication
require_once 'helpers.php'; // Pastikan helpers.php ada dan berisi is_logged_in() dan redirect()

// If user is NOT logged in, redirect them to the login page
if (!is_logged_in()) {
    redirect('login.php'); // Redirect ke login.php jika belum login
}

// At this point, the user is logged in. Get user_id from session.
$current_user_id = $_SESSION['user_id'];

// --- Fetch Drop Points from Database ---
$dropPoints = [];

// Get the selected_dp_id from the URL parameter
$selected_dp_id = isset($_GET['selected_dp_id']) ? intval($_GET['selected_dp_id']) : 0;

// DEBUGGING: Log the received selected_dp_id
error_log("[bank_sampah.php] Received selected_dp_id: " . $selected_dp_id);

// Modify the SQL query to fetch only the selected drop point if ID is provided
// Periksa apakah koneksi database ada dan tidak ada error sebelum melakukan query
if ($conn && !$conn->connect_error) {
    if ($selected_dp_id > 0) {
        $sql_drop_points = "SELECT id, name, address, latitude, longitude, hours, types, prices, description, terms, rating, reviews FROM drop_points WHERE id = ?";
        $stmt_drop_points = $conn->prepare($sql_drop_points);
        if ($stmt_drop_points) {
            $stmt_drop_points->bind_param("i", $selected_dp_id);
            $stmt_drop_points->execute();
            $result_drop_points = $stmt_drop_points->get_result();

            if ($result_drop_points->num_rows > 0) {
                // DEBUGGING: Log if drop point is found
                error_log("[bank_sampah.php] Drop point found in DB for ID: " . $selected_dp_id);
                while($row = $result_drop_points->fetch_assoc()) {
                    // Decode JSON strings for 'types'
                    $row['types'] = json_decode($row['types'], true);
                    $row['lat'] = $row['latitude'];
                    unset($row['latitude']);
                    $row['lng'] = $row['longitude'];
                    unset($row['longitude']);

                    // Assign a random card color for visual variety
                    $card_colors = ['green-card', 'blue-card', 'purple-card'];
                    $random_color_key = array_rand($card_colors);
                    $row['cardColorClass'] = $card_colors[$random_color_key];

                    // Format prices for display, assuming 'prices' is a string like "Plastik: Rp 3.000/kg, Kertas: Rp 2.500/kg"
                    $prices_array = [];
                    if (!empty($row['prices'])) {
                        $price_pairs = explode(', ', $row['prices']);
                        foreach ($price_pairs as $pair) {
                            list($type_name, $price_val) = explode(': ', $pair);
                            $prices_array[trim($type_name)] = trim($price_val);
                        }
                    }

                    $final_waste_types_formatted = [];
                    if (is_array($row['types'])) {
                        foreach ($row['types'] as $type) {
                            $price_for_type = isset($prices_array[ucfirst($type)]) ? $prices_array[ucfirst($type)] : 'Harga Tidak Tersedia';
                            $final_waste_types_formatted[] = [
                                'type' => ucfirst($type),
                                'price' => $price_for_type
                            ];
                        }
                    }
                    $row['formatted_waste_types'] = $final_waste_types_formatted;

                    $dropPoints[] = $row; // Will contain only one selected drop point
                }
            }
            $stmt_drop_points->close();
        } else {
            // DEBUGGING: Log if statement preparation fails
            error_log("[bank_sampah.php] Failed to prepare drop points statement: " . $conn->error);
        }
    } else {
        // If no selected_dp_id, fetch all drop points to display in the list
        // DEBUGGING: Log that all drop points are being fetched
        error_log("[bank_sampah.php] No selected_dp_id found, fetching ALL drop points.");
        $sql_drop_points = "SELECT id, name, address, latitude, longitude, hours, types, prices, description, terms, rating, reviews FROM drop_points";
        $result_drop_points = $conn->query($sql_drop_points);

        if ($result_drop_points->num_rows > 0) {
            while($row = $result_drop_points->fetch_assoc()) {
                // Decode JSON strings for 'types'
                $row['types'] = json_decode($row['types'], true);
                $row['lat'] = $row['latitude'];
                unset($row['latitude']);
                $row['lng'] = $row['longitude'];
                unset($row['longitude']);

                // Assign a random card color for visual variety
                $card_colors = ['green-card', 'blue-card', 'purple-card'];
                $random_color_key = array_rand($card_colors);
                $row['cardColorClass'] = $card_colors[$random_color_key];

                // Format prices for display, assuming 'prices' is a string like "Plastik: Rp 3.000/kg, Kertas: Rp 2.500/kg"
                $prices_array = [];
                if (!empty($row['prices'])) {
                    $price_pairs = explode(', ', $row['prices']);
                    foreach ($price_pairs as $pair) {
                        list($type_name, $price_val) = explode(': ', $pair);
                        $prices_array[trim($type_name)] = trim($price_val);
                    }
                }

                $final_waste_types_formatted = [];
                if (is_array($row['types'])) {
                    foreach ($row['types'] as $type) {
                        $price_for_type = isset($prices_array[ucfirst($type)]) ? $prices_array[ucfirst($type)] : 'Harga Tidak Tersedia';
                        $final_waste_types_formatted[] = [
                            'type' => ucfirst($type),
                            'price' => $price_for_type
                        ];
                    }
                }
                $row['formatted_waste_types'] = $final_waste_types_formatted;
                $dropPoints[] = $row;
            }
        }
    }
} else {
    error_log("[bank_sampah.php] Koneksi database tidak tersedia atau error: " . ($conn ? $conn->connect_error : 'null $conn'));
}

// --- Fetch Bank Officers (retained for database query, but no longer used in JS for PIN validation dropdown) ---
$bankOfficers = [];
if ($conn && !$conn->connect_error) {
    $sql_bank_officers = "SELECT name FROM bank_officers";
    $result_bank_officers = $conn->query($sql_bank_officers);

    if ($result_bank_officers && $result_bank_officers->num_rows > 0) {
        while($row = $result_bank_officers->fetch_assoc()) {
            $bankOfficers[] = $row['name']; // Store just the name if needed elsewhere
        }
    }
}

// --- Fetch Current User Points ---
$userPoints = 0;
if ($conn && !$conn->connect_error) {
    $sql_user_points = "SELECT total_points FROM users WHERE id = ?";
    $stmt_user_points = $conn->prepare($sql_user_points);
    if ($stmt_user_points) {
        $stmt_user_points->bind_param("i", $current_user_id);
        $stmt_user_points->execute();
        $stmt_user_points->bind_result($points);
        $stmt_user_points->fetch();
        if ($points !== null) {
            $userPoints = $points;
        }
        $stmt_user_points->close();
    } else {
        error_log("[bank_sampah.php] Failed to prepare user points statement: " . $conn->error);
    }
}

// Close the database connection after fetching initial data
if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tukar Sampah Jadi Reward - EcoPoint</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />

    <style>
        /* Variabel CSS */
        :root {
            --primary-color: #00c853; /* Hijau */
            --background-light: #ffffff; /* Putih */
            --white: #ffffff;
            --text-dark: #333333;
            --text-gray: #666666;
            --shadow-light: rgba(0, 0, 0, 0.1);
            --border-radius-soft: 12px;
            --padding-section: 60px 20px;
        }

        /* General Styling */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--background-light);
            color: var(--text-dark);
            line-height: 1.6;
            box-sizing: border-box;
            transition: background-color 1s ease-in-out;
        }

        *, *::before, *::after {
            box-sizing: inherit;
        }

        /* PERBAIKAN PENTING: Hapus opacity dan animation dari `main` */
        /* Ini adalah penyebab umum peta berkedip karena inisialisasi dengan dimensi tidak akurat */
        main {
            /* opacity: 0; */ /* Hapus baris ini */
            /* animation: fadeInContent 1s ease-out forwards; */ /* Hapus baris ini */
            /* animation-delay: 0.5s; */ /* Hapus baris ini */
        }

        /* Jika Anda ingin efek fade-in pada konten lain, terapkan pada elemen spesifik di dalam main */
        /* @keyframes fadeInContent { */
        /* to { opacity: 1; } */
        /* } */

        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Typography */
        h1, h2, h3 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 40px;
        }

        h1 {
            font-size: 2.8em;
            font-weight: 700;
        }

        h2 {
            font-size: 2.2em;
            font-weight: 600;
        }

        h3 {
            font-size: 1.5em;
            font-weight: 500;
        }

        p {
            font-size: 1em;
            line-height: 1.4;
            margin-bottom: 5px;
        }
        p:last-of-type {
            margin-bottom: 0;
        }


        /* Buttons */
        .btn {
            display: inline-block;
            padding: 12px 25px;
            border: none;
            border-radius: var(--border-radius-soft);
            background-color: var(--primary-color);
            color: var(--white);
            font-size: 1em;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease;
            text-decoration: none;
        }

        .btn:hover {
            background-color: #00a041;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn.outline {
            background-color: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn.outline:hover {
            background-color: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn.secondary {
            background-color: #2196F3;
            color: var(--white);
        }
        .btn.secondary:hover {
            background-color: #1a87da;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn.danger {
            background-color: #f44336;
            color: var(--white);
        }
        .btn.danger:hover {
            background-color: #d32f2f;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn.full-width {
            width: 100%;
            text-align: center;
            margin-top: 20px;
        }


        /* Card/Box Styling */
        .card {
            background-color: var(--white);
            border-radius: var(--border-radius-soft);
            box-shadow: 0 4px 15px var(--shadow-light);
            padding: 30px;
            margin-bottom: 30px;
            transition: all 0.3s ease-in-out;
        }

        /* Header & Navigation - Dihapus sepenuhnya */
        /* Hero Section disembunyikan */
        .hero-section {
            display: none;
        }

        /* How It Works Section */
        .how-it-works {
            display: none; /* Removed as per request */
        }

        /* Map Section */
        .map-section {
            padding: var(--padding-section) 0;
            margin-top: 50px;
        }

        .map-controls {
            display: flex; /* Default: visible with flex layout */
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
        }

        .map-controls input[type="text"] {
            flex-grow: 1;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius-soft);
            font-size: 1em;
            max-width: 400px;
            transition: all 0.3s ease-in-out;
        }
        .map-controls input[type="text"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 200, 83, 0.2);
            outline: none;
        }

        .map-controls .btn {
            white-space: nowrap;
        }

        .map-controls select {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius-soft);
            font-size: 1em;
            background-color: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .map-controls select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 200, 83, 0.2);
            outline: none;
        }


        #myLocationStatus {
            margin-left: 10px;
            font-size: 0.9em;
            color: var(--text-gray);
            transition: opacity 0.3s ease;
        }

        #map-container {
            position: relative;
            margin-bottom: 30px;
        }

        /* INI PENTING! Pastikan #map memiliki tinggi dan lebar yang terlihat. */
        #map {
            height: 500px;
            width: 100%;
            border-radius: var(--border-radius-soft);
            box-shadow: 0 4px 15px var(--shadow-light);
        }

        #route-message {
            position: absolute;
            top: auto;
            bottom: 20px;
            left: 50%;
            transform: translate(-50%, 0);
            background-color: var(--white);
            border: 1px solid #ddd;
            padding: 10px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            font-size: 0.9em;
            font-weight: 500;
            color: var(--text-dark);
            z-index: 500;
            display: none;
            text-align: center;
            max-width: 80%;
            white-space: normal;
        }

        /* List Drop Point */
        .drop-point-list {
            padding-top: 20px;
        }

        .drop-point-list h3 {
            text-align: left;
            margin-bottom: 20px;
            color: var(--text-dark);
            font-size: 1.8em;
        }

        #dropPointResults-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: flex-start;
            align-items: stretch;
        }

        .drop-point-item {
            background-color: var(--white);
            border-radius: var(--border-radius-soft);
            box-shadow: 0 2px 10px var(--shadow-light);
            padding: 25px;
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
            gap: 0px;
            transition: transform 0.2s ease, width 0.3s ease, box-shadow 0.3s ease;
            width: 100%; /* Make each item take full width */
            box-sizing: border-box;
            align-items: flex-start;
        }

        .drop-point-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
        }

        .drop-point-item h4 {
            width: 100%;
            margin-bottom: 10px;
        }

        .drop-point-item .button-group {
            margin-top: 15px;
            width: 100%;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .drop-point-item .button-group .btn {
            flex: unset;
            margin-top: 0;
            padding: 8px 15px;
            font-size: 0.85em;
            text-align: center;
        }

        .drop-point-item .route-action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            margin-top: 15px;
        }
        .drop-point-item .route-status {
            margin-top: 10px;
            padding: 8px 15px;
            background-color: #e3f2fd;
            border-left: 5px solid #2196F3;
            color: #1976d2;
            font-weight: 500;
            border-radius: var(--border-radius-soft);
            font-size: 0.9em;
            text-align: center;
        }

        /* Ensure full width for all drop point items, no specific selected-full-width needed for this layout */
        .drop-point-item.selected-full-width {
            width: 100%;
            max-width: 100%;
            margin-bottom: 20px;
        }


        .waste-type-badge {
            background-color: #e0f2f1;
            color: #00796b;
            padding: 3px 8px;
            border-radius: 5px;
            margin-right: 5px;
            font-size: 0.8em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .waste-type-badge i {
            font-size: 0.9em;
        }

        .rating-stars {
            color: #FFD700;
            font-size: 1.1em;
            margin-right: 5px;
        }
        .rating-stars .far {
            color: #ccc;
        }
        .rating-section {
            display: flex;
            align-items: center;
            margin-top: 10px;
            margin-bottom: 10px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rewards-section, .profile-section {
            display: none;
        }

        .modal {
            display: none;
        }

        footer {
            display: none;
        }

        /* Styles for the new recycling form */
        #recycling-form-section {
            display: none;
            padding: 30px;
            margin-top: 30px;
        }

        #recycling-form-section h3 {
            text-align: center;
            margin-bottom: 25px;
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="password"], /* Added for PIN */
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius-soft);
            font-size: 1em;
            box-sizing: border-box;
            transition: all 0.3s ease-in-out;
        }
        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group input[type="password"]:focus, /* Added for PIN */
        .form-group select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 200, 83, 0.2);
            outline: none;
        }

        .form-group input[type="file"] {
            padding: 8px;
        }

        .form-group .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .form-group .checkbox-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 400;
        }

        .form-group .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 5px;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 30px;
        }

        .form-actions .btn {
            flex-grow: 1;
        }

        /* Modal for QR Scanner */
        .qr-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.8);
            justify-content: center;
            align-items: column;
            flex-direction: column;
        }

        .qr-modal-content {
            background-color: var(--white);
            margin: auto;
            padding: 20px;
            border-radius: var(--border-radius-soft);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            text-align: center;
        }

        .qr-modal-content h4 {
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        #qr-video-container {
            width: 100%;
            height: 300px;
            background-color: #eee;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--text-gray);
            margin-bottom: 20px;
        }
        #qr-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .close-button {
            color: var(--text-gray);
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover,
        .close-button:focus {
            color: var(--text-dark);
            text-decoration: none;
            cursor: pointer;
        }

        /* Reward Notification */
        #reward-notification {
            display: none;
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: #4CAF50;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            font-size: 1.1em;
            text-align: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
        }
        #reward-notification.show {
            opacity: 1;
        }

        /* Notification for distance validation */
        .validation-notification {
            background-color: #ffeb3b;
            color: #333;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 15px;
            text-align: center;
            font-weight: 500;
            display: none;
        }
        .validation-notification.show {
            display: block;
        }

        /* Pandu Character Styles */
        #pandu-character {
            position: fixed;
            bottom: 150px;
            left: 20px;
            z-index: 900;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
            transform: translateX(0);
            opacity: 1;
            transition: transform 0.5s ease-out, opacity 0.5s ease-out;
        }

        #pandu-character img {
            width: 80px;
            height: auto;
            order: 2;
        }

        #pandu-greeting {
            background-color: var(--white);
            padding: 10px 15px;
            border-radius: var(--border-radius-soft);
            box-shadow: 0 2px 10px var(--shadow-light);
            color: var(--text-dark);
            position: relative;
            order: 1;
            margin-bottom: 0;
            width: auto;
            max-width: 200px;
            text-align: left;
            transition: opacity 0.3s ease, max-height 0.3s ease, padding 0.3s ease;
            font-size: 1em;
        }

        /* Class for hiding Pandu's greeting text */
        #pandu-greeting.hidden {
            opacity: 0;
            pointer-events: none;
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
            overflow: hidden;
            transition: opacity 0.3s ease, max-height 0.3s ease, padding 0.3s ease;
        }
        #pandu-greeting:not(.hidden) {
            max-height: 200px;
            padding: 10px 15px;
            transition: opacity 0.3s ease, max-height 0.3s ease, padding 0.3s ease;
        }

        /* Close button for Pandu's greeting */
        .close-pandu-greeting {
            position: absolute;
            top: 5px;
            right: 10px;
            font-size: 1.2em;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-gray);
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
        }

        /* Show close button on hover or when greeting is "active" (clicked to show) */
        #pandu-greeting:hover .close-pandu-greeting,
        #pandu-greeting.active .close-pandu-greeting {
            opacity: 1;
            pointer-events: auto;
        }

        /* Arrow for Pandu's greeting bubble */
        #pandu-greeting::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 20px;
            width: 0;
            height: 0;
            border-left: 10px solid transparent;
            border-right: 10px solid transparent;
            border-top: 10px solid var(--white);
        }

        /* Controls for Pandu's greeting text visibility - Removed separate buttons */
        .pandu-controls {
            display: none;
        }

        /* Button to toggle Kid Mode - Removed */
        #toggle-kid-mode {
            display: none;
        }

        /* Styles to simplify UI for kid mode - Removed */
        /* All kid-mode specific styles are removed */


        /* Ambient Mood System Styles */
        #weather-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            pointer-events: none;
            opacity: 0;
            transition: opacity 1s ease-in-out, background-color 1s ease-in-out;
            background-color: transparent;
        }

        /* Rainy Mood Styles */
        body.rainy-mood {
            background-color: #a7d0e0;
        }

        .rainy-mood #weather-overlay {
            opacity: 1;
            background-color: rgba(0, 0, 0, 0.2);
            animation: fadeInOverlay 1s forwards;
            position: fixed;
            overflow: hidden;
        }

        /* Animation for raindrops */
        .rainy-mood #weather-overlay::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><circle cx="5" cy="5" r="1" fill="rgba(255,255,255,0.7)"/></svg>');
            background-size: 10px 10px;
            animation: rain 1s linear infinite;
        }

        @keyframes rain {
            0% {
                transform: translateY(-100%);
            }
            100% {
                transform: translateY(100%);
            }
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Clear Mood (default light background) */
        body.clear-mood {
            background-color: var(--background-light);
            transition: background-color 1.5s ease-in-out;
        }
        .clear-mood #weather-overlay {
            opacity: 0;
        }

        /* Cloudy Mood */
        body.cloudy-mood {
            background-color: #bbccdd; /* Softer grey-blue */
            transition: background-color 1.5s ease-in-out;
        }
        .cloudy-mood #weather-overlay {
            opacity: 0.5; /* Slightly visible overlay for clouds */
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="150"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.7)"/><circle cx="150" cy="80" r="50" fill="rgba(255,255,255,0.7)"/><circle cx="100" cy="120" r="30" fill="rgba(255,255,255,0.7)"/></svg>');
            background-size: 300px 200px;
            animation: moveClouds 20s linear infinite;
        }
        @keyframes moveClouds {
            0% { background-position: 0 0; }
            100% { background-position: -600px 0; }
        }


        /* Traffic Status styles */
        .traffic-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: 600;
            margin-left: 10px;
        }

        .traffic-status.ramai {
            background-color: #ffcdd2;
            color: #d32f2f;
        }

        .traffic-status.sedang {
            background-color: #fff9c4;
            color: #fbc02d;
        }

        .traffic-status.sepi {
            background-color: #c8e6c9;
            color: #388e3c;
        }

        .traffic-recommendation {
            margin-top: 10px;
            padding: 8px 15px;
            background-color: #e3f2fd;
            border-left: 5px solid #2196F3;
            color: #1976d2;
            font-weight: 500;
            border-radius: var(--border-radius-soft);
            font-size: 0.9em;
        }

        /* Recycling Rebirth Ritual Modal */
        #rebirth-modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: rgba(0,0,0,0.9);
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: white;
            font-size: 1.5em;
            text-align: center;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
        }
        #rebirth-modal.show {
            opacity: 1;
            display: flex;
        }

        .rebirth-content {
            position: relative;
            z-index: 1;
            padding: 20px;
        }

        /* Rebirth Animations */
        .rebirth-animation {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .flower, .butterfly, .tree {
            position: absolute;
            opacity: 0;
        }

        .flower {
            width: 50px;
            height: 50px;
            background-color: pink;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            animation: bloom var(--animation-duration, 2s) ease-out forwards, fadeOut var(--animation-fade-out-duration, 1s) forwards var(--animation-fade-out-delay, 4s);
        }
        @keyframes bloom {
            0% { transform: translate(-50%, -50%) scale(0); opacity: 0; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 1; }
            100% { transform: translate(-50%, -50%) scale(1); opacity: 0.8; }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translate(-50%, -50%) scale(1.5); }
        }

        .butterfly {
            width: 30px;
            height: 30px;
            background-color: #FFEB3B;
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            animation: fly var(--animation-duration, 6s) ease-in-out infinite alternate;
            opacity: 0;
        }
        @keyframes fly {
            0% { transform: translate(10vw, 80vh) rotate(0deg) scale(0.5); opacity: 0; }
            20% { opacity: 1; }
            50% { transform: translate(50vw, 10vh) rotate(180deg) scale(1); }
            80% { opacity: 1; }
            100% { transform: translate(90vw, 80vh) rotate(360deg) scale(0.5); opacity: 0; }
        }

        .tree {
            width: 80px;
            height: 150px;
            background-color: brown;
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%) scaleY(0);
            transform-origin: bottom;
            animation: grow var(--animation-duration, 2s) ease-out forwards;
            opacity: 0;
        }
        .tree::before {
            content: '';
            position: absolute;
            width: 120px;
            height: 120px;
            background-color: forestgreen;
            border-radius: 50%;
            top: -60px;
            left: -20px;
        }
        @keyframes grow {
            0% { transform: translateX(-50%) scaleY(0); opacity: 0; }
            100% { transform: translateX(-50%) scaleY(1); opacity: 1; }
        }

        /* Notif Edukatif Mini */
        #eco-fact-notification {
            display: none;
            position: fixed;
            top: 20px; /* Positioned at the top */
            left: 50%;
            transform: translateX(-50%);
            background-color: #f0f8ff; /* Light blue background */
            color: var(--text-dark);
            padding: 15px 25px;
            border-radius: var(--border-radius-soft);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 1em;
            text-align: center;
            z-index: 950; /* Above map, below modals */
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            max-width: 90%;
            border-left: 5px solid var(--primary-color);
        }
        #eco-fact-notification.show {
            opacity: 1;
            display: block;
        }
        #eco-fact-notification .question {
            font-weight: 600;
            margin-bottom: 5px;
        }
        #eco-fact-notification .answer {
            font-style: italic;
            color: var(--text-gray);
        }


        /* MEDIA QUERIES FOR RESPONSIVENESS */
        @media (max-width: 768px) {
            .container {
                padding: 0 15px;
            }

            h1 {
                font-size: 2em;
            }

            h2 {
                font-size: 1.8em;
            }

            h3 {
                font-size: 1.3em;
            }

            .map-controls {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .map-controls input[type="text"],
            .map-controls select,
            .map-controls .btn {
                width: 100%;
                max-width: unset;
            }

            #map {
                height: 350px; /* Adjusted height for smaller screens */
            }

            #dropPointResults-wrapper {
                flex-direction: column;
                gap: 15px;
            }

            .drop-point-item {
                padding: 20px;
                flex-basis: 100%;
                max-width: 100%;
            }

            .drop-point-item .button-group {
                flex-direction: column;
                gap: 8px;
            }

            .form-actions {
                flex-direction: column;
                gap: 10px;
            }

            /* Pandu character adjustments for smaller screens */
            #pandu-character {
                bottom: 80px;
                left: 10px;
            }
            #pandu-character img {
                width: 60px;
            }
            #pandu-greeting {
                font-size: 0.85em;
                margin-bottom: 10px;
            }

            #eco-fact-notification {
                font-size: 0.9em;
                padding: 10px 15px;
            }
        }

        @media (min-width: 769px) {
            .drop-point-item {
                flex-basis: 100%;
                max-width: 100%;
            }
        }


        @media (max-width: 480px) {
            h1 {
                font-size: 1.8em;
            }
            h2 {
                font-size: 1.5em;
            }
            h3 {
                font-size: 1.2em;
            }
            .card {
                padding: 20px;
            }
            .step-item .icon {
                font-size: 3em;
            }
        }

        /* Styling for the new photo upload component */
        #photo-upload-container {
            border: 2px dashed #ccc;
            border-radius: var(--border-radius-soft);
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: var(--white);
            position: relative;
        }

        #photo-upload-container:hover {
            border-color: var(--primary-color);
            background-color: #f0fdf5;
        }

        /* Feedback visual for drag-and-drop */
        #photo-upload-container.dragover {
            border-color: var(--primary-color);
            background-color: #e6ffe6;
            box-shadow: 0 0 0 4px rgba(0, 200, 83, 0.2);
        }

        #photo-upload-container.has-file {
            border-color: var(--primary-color);
            background-color: #e6ffe6;
        }

        #drop-zone .upload-icon {
            font-size: 3em;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        #drop-zone-text {
            color: var(--text-gray);
            font-size: 0.95em;
            margin-bottom: 0;
        }

        .browse-files {
            color: var(--primary-color);
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
        }

        #file-preview-container {
            margin-top: 15px;
            position: relative;
            max-width: 150px;
            max-height: 150px;
            overflow: hidden;
            border-radius: var(--border-radius-soft);
            margin-left: auto;
            margin-right: auto;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        #file-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: rgba(255, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 0.8em;
            cursor: pointer;
            transition: background-color 0.2s ease;
            z-index: 10;
        }

        .remove-btn:hover {
            background-color: rgba(255, 0, 0, 0.9);
        }

        #file-name-display {
            display: block;
            margin-top: 10px;
            font-size: 0.85em;
            color: var(--text-dark);
            font-weight: 500;
        }

        /* User Points Display */
        #user-points-display {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background-color: #e8f5e9;
            border-radius: var(--border-radius-soft);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.2em;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        #user-points-display i {
            font-size: 1.5em;
        }

        /* Offline Sync Indicator */
        #offline-sync-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #ffeb3b;
            color: #333;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            font-size: 0.9em;
            font-weight: 500;
            z-index: 990;
            display: none;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        #offline-sync-indicator.show {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #offline-sync-indicator:hover {
            background-color: #fdd835;
        }
        #offline-sync-indicator .count {
            font-weight: bold;
            font-size: 1.1em;
            margin-left: 5px;
        }

        /* Post-submission Thank You / Suggestions Modal */
        #post-submit-modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.8);
            justify-content: center;
            align-items: center;
            flex-direction: column;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        #post-submit-modal.show {
            opacity: 1;
            display: flex;
        }

        .post-submit-content {
            background-color: var(--white);
            margin: auto;
            padding: 30px;
            border-radius: var(--border-radius-soft);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            text-align: center;
            color: var(--text-dark);
            position: relative;
        }
        .post-submit-content h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.8em;
            font-weight: 600;
        }
        .post-submit-content p {
            margin-bottom: 15px;
            font-size: 1em;
            color: var(--text-dark);
        }
        .post-submit-content .reward-info {
            font-size: 1.2em;
            font-weight: 700;
            color: #4CAF50;
            margin-bottom: 20px;
        }
        .post-submit-content .suggestion-list {
            list-style: none;
            padding: 0;
            margin-bottom: 20px;
        }
        .post-submit-content .suggestion-list li {
            background-color: #f0f8ff;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 0.9em;
            border-left: 4px solid #2196F3;
        }
        .post-submit-content .btn-close-modal {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <main>
        <section id="drop-point-section" class="map-section container card">
            <div id="user-points-display">
                <i class="fas fa-coins"></i> Total Poin Anda: <span id="current-user-points"><?php echo $userPoints; ?></span>
            </div>
            <h2>Temukan Drop Point Terdekat</h2>
            <div class="map-controls">
                <input type="text" id="searchLocation" placeholder="Cari lokasi drop point (misal: Jakarta Pusat)...">

                <button id="myLocationBtn" class="btn primary"><i class="fas fa-location-arrow"></i> Lokasi Saya</button>
                <span id="myLocationStatus" style="display: none;"></span>
                <button id="searchBtn" class="btn outline"><i class="fas fa-search"></i> Cari</button>
            </div>
            <div id="map-and-message-wrapper">
                <div id="map"></div>
                <div id="route-message" style="display: none;"></div>
            </div>

            <div class="drop-point-list card">
                <h3 id="drop-point-list-heading">Daftar Drop Point</h3>
                <div id="dropPointResults-wrapper">
                    </div>
            </div>
        </section>

        <section id="recycling-form-section" class="container card" style="display: none;">
            <h3>Detail Penukaran Sampah</h3>
            <form id="recycling-submission-form" action="submit_recycling.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="userId" name="userId" value="<?php echo $current_user_id; ?>">
                <input type="hidden" id="dropPointId" name="dropPointId">

                <div class="form-group">
                    <label for="pin">PIN Lokasi Bank Sampah:</label>
                    <input type="password" id="pin" name="pin" required maxlength="6" placeholder="Masukkan PIN lokasi" autocomplete="off">
                    <p style="font-size: 0.85em; color: var(--text-gray); margin-top: 5px;">Minta PIN 6 digit ke petugas bank sampah di lokasi ini.</p>
                </div>
                <div class="form-group">
                    <label>Jenis Sampah:</label>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="wasteType[]" value="plastik"> Plastik</label>
                        <label><input type="checkbox" name="wasteType[]" value="kertas"> Kertas</label>
                        <label><input type="checkbox" name="wasteType[]" value="logam"> Logam</label>
                        <label><input type="checkbox" name="wasteType[]" value="kaca"> Kaca</label>
                        <label><input type="checkbox" name="wasteType[]" value="elektronik"> Elektronik</label>
                        <label><input type="checkbox" name="wasteType[]" value="minyak jelantah"> Minyak Jelantah</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="wasteWeight">Berat Total (kg):</label>
                    <input type="number" id="wasteWeight" name="wasteWeight" step="0.1" min="0.1" required placeholder="Masukkan berat sampah">
                </div>
                <div class="form-group">
                    <label for="wastePhoto">Foto Sampah:</label>
                    <div id="photo-upload-container">
                        <input type="file" id="wastePhoto" name="wastePhoto" accept="image/*" style="display: none;">
                        <div id="drop-zone">
                            <i class="fas fa-camera-retro upload-icon"></i>
                            <p id="drop-zone-text">Seret & Jatuhkan Foto atau <span class="browse-files">Telusuri File</span></p>
                            <div id="file-preview-container" style="display: none;">
                                <img id="file-preview" src="" alt="Pratinjau Foto Sampah">
                                <button type="button" id="remove-photo-btn" class="remove-btn"><i class="fas fa-times-circle"></i></button>
                            </div>
                            <span id="file-name-display" style="display: none;"></span>
                        </div>
                    </div>
                    <canvas id="imageCanvas" style="display:none;"></canvas>
                </div>
                <div class="form-actions">
                    <button type="submit" id="submitRecyclingForm" class="btn primary" disabled>Selesai Perjalanan & Submit</button>
                    <button type="button" id="cancelRecyclingForm" class="btn danger">Batalkan Penukaran</button>
                </div>
                <div id="distanceValidationMessage" class="validation-notification">
                    </div>
            </form>
        </section>

        <div id="reward-notification">
            Selamat! Anda mendapatkan <span id="reward-points"></span> poin!
        </div>

        <div id="rebirth-modal">
            <div class="rebirth-animation">
                </div>
            <div class="rebirth-content">
                "Selamat! Anda telah memilih untuk **lahir kembali sebagai manusia yang peduli!**"
            </div>
        </div>

        <div id="eco-fact-notification">
            <div class="question"></div>
            <div class="answer"></div>
        </div>

        <div id="offline-sync-indicator">
            <i class="fas fa-cloud-upload-alt"></i> Data offline: <span class="count">0</span>
        </div>

        <div id="post-submit-modal">
            <div class="post-submit-content">
                <span class="close-button" id="close-post-submit-modal">×</span>
                <h4>Selamat! Penukaran Sampah Berhasil!</h4>
                <p>Terima kasih telah berpartisipasi dalam menjaga lingkungan!</p>
                <p class="reward-info">Anda mendapatkan <span id="final-reward-points">0</span> poin!</p>
                <p>Total poin Anda saat ini: <span id="final-total-points">0</span></p>

                <p>Apa lagi yang bisa Anda lakukan?</p>
                <ul class="suggestion-list">
                    <li><i class="fas fa-gift"></i> Yuk, tukarkan poin Anda dengan hadiah menarik!</li>
                    <li><i class="fas fa-share-alt"></i> Ajak teman dan keluarga untuk ikut mendaur ulang!</li>
                    <li><i class="fas fa-tree"></i> Terus beraksi untuk bumi yang lebih hijau!</li>
                </ul>
                <button class="btn primary btn-close-modal">Oke, mengerti!</button>
            </div>
        </div>


        <audio id="arrival-sound" src="https://assets.mixkit.co/sfx/preview/mixkit-small-bell-ring-1122.mp3" preload="auto"></audio>

        <div id="pandu-character">
            <div id="pandu-greeting">
                <span class="close-pandu-greeting">X</span>
                Halo, aku Pandu Si Pengumpul! Ayo kita kumpulkan sampah bersama!
            </div>
            <img src="karakter pandu.png" alt="Karakter Pandu">
        </div>
        <div class="pandu-controls" style="display: none;">
            <button id="decrease-font">-A</button>
            <button id="increase-font">+A</button>
        </div>

        <div id="weather-overlay"></div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.min.js"></script>
        <script>
            // Elemen UI untuk pesan rute
            const routeMessage = document.getElementById('route-message');
            const myLocationStatus = document.getElementById('myLocationStatus');
            // Elemen untuk wrapper daftar drop point
            const dropPointResultsWrapper = document.getElementById('dropPointResults-wrapper');
            const dropPointListHeading = document.getElementById('drop-point-list-heading');
            const searchLocationInput = document.getElementById('searchLocation');
            const searchBtn = document.getElementById('searchBtn');
            const myLocationBtn = document.getElementById('myLocationBtn');

            // New form elements
            const recyclingFormSection = document.getElementById('recycling-form-section');
            const recyclingSubmissionForm = document.getElementById('recycling-submission-form');
            const pinInput = document.getElementById('pin');
            const wasteTypeCheckboxes = document.querySelectorAll('input[name="wasteType[]"]');
            const wasteWeightInput = document.getElementById('wasteWeight');
            const wastePhotoInput = document.getElementById('wastePhoto');
            const imageCanvas = document.getElementById('imageCanvas');
            const submitRecyclingFormBtn = document.getElementById('submitRecyclingForm');
            const cancelRecyclingFormBtn = document.getElementById('cancelRecyclingForm');
            const dropPointIdInput = document.getElementById('dropPointId');
            const userIdInput = document.getElementById('userId');


            // Reward Notification elements
            const rewardNotification = document.getElementById('reward-notification');
            const rewardPointsSpan = document.getElementById('reward-points');
            const distanceValidationMessage = document.getElementById('distanceValidationMessage');
            const userPointsDisplay = document.getElementById('user-points-display');
            const currentUserPointsSpan = document.getElementById('current-user-points');

            // Audio element
            const arrivalSound = document.getElementById('arrival-sound');

            // Pandu elements
            const panduCharacter = document.getElementById('pandu-character');
            const panduGreeting = document.getElementById('pandu-greeting');
            const closePanduGreetingBtn = document.querySelector('.close-pandu-greeting');


            // Ambient Mood elements
            const weatherOverlay = document.getElementById('weather-overlay');

            // Rebirth Ritual elements
            const rebirthModal = document.getElementById('rebirth-modal');
            const rebirthAnimationContainer = document.querySelector('.rebirth-animation');

            // Eco Fact Notification elements
            const ecoFactNotification = document.getElementById('eco-fact-notification');
            const ecoFactQuestion = ecoFactNotification.querySelector('.question');
            const ecoFactAnswer = ecoFactNotification.querySelector('.answer');

            // --- New elements for photo upload ---
            const photoUploadContainer = document.getElementById('photo-upload-container');
            const dropZone = document.getElementById('drop-zone');
            const browseFilesSpan = dropZone.querySelector('.browse-files');
            const filePreviewContainer = document.getElementById('file-preview-container');
            const filePreview = document.getElementById('file-preview');
            const removePhotoBtn = document.getElementById('remove-photo-btn');
            const fileNameDisplay = document.getElementById('file-name-display');

            // Offline Sync Indicator
            const offlineSyncIndicator = document.getElementById('offline-sync-indicator');

            // Post-submission modal elements
            const postSubmitModal = document.getElementById('post-submit-modal');
            const closePostSubmitModalBtn = document.getElementById('close-post-submit-modal');
            const finalRewardPointsSpan = document.getElementById('final-reward-points');
            const finalTotalPointsSpan = document.getElementById('final-total-points');
            const btnClosePostSubmitModal = postSubmitModal.querySelector('.btn-close-modal');


            // PHP injected data
            const phpDropPoints = <?php echo json_encode($dropPoints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const currentUserId = <?php echo $current_user_id; ?>;


            // Data untuk aplikasi
            let dropPoints = phpDropPoints;
            let rewards = [
                { id: 1, name: "Voucher Kopi", points: 500, image: "https://dummyimage.com/200x150/00c853/ffffff&text=Voucher+Kopi" },
                { id: 2, name: "Tas Daur Ulang", points: 1500, image: "https://dummyimage.com/200x150/00c853/ffffff&text=Tas+Daur+Ulang" },
            ];
            let currentUser = null;

            // === Leaflet Map Initialization and Functions ===
            let map;
            let userMarker = null;
            let userLocationWatchId = null;
            const dropPointMarkers = L.featureGroup();
            let routingControl = null;
            let currentDestination = null;
            let currentTransportMode = 'driving';
            let initialZoomSet = false; // Flag to prevent repeated zooming

            // New variables for route tracking
            let isTrackingRoute = false;
            let currentRouteTargetId = null;
            let routeStatusInterval = null;
            let hasArrivedSoundPlayed = false;
            let currentDistanceToDropPoint = Infinity;

            // Offline Sync
            const OFFLINE_SUBMISSIONS_KEY = 'offlineSubmissions';

            // Helper function to escape HTML for safe display in JavaScript template literals
           function escapeHtml(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")  // Mengganti & menjadi &amp; (penting pertama)
         .replace(/</g, "&lt;")   // Mengganti < menjadi &lt;
         .replace(/>/g, "&gt;")   // Mengganti > menjadi &gt;
         .replace(/"/g, "&quot;") // Mengganti " menjadi &quot;
         .replace(/'/g, "&#039;"); // Mengganti ' menjadi &#039; (atau &apos; jika HTML5)
}
            // PERBAIKAN: Fungsi debounce yang lebih umum
            function createDebouncedFunction(func, delay) {
                let timeout;
                return function(...args) {
                    const context = this;
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(context, args), delay);
                };
            }

            // PERBAIKAN: Debounce untuk showRouteMessage, getWeatherData, dan drawOrUpdateRoute
            const debouncedShowRouteMessage = createDebouncedFunction(showRouteMessage, 1500); // Pesan muncul maks setiap 1.5 detik
            const debouncedGetWeatherData = createDebouncedFunction(getWeatherData, 300000); // Update cuaca maks setiap 5 menit
            const debouncedDrawOrUpdateRoute = createDebouncedFunction(drawOrUpdateRoute, 2000); // Gambar ulang rute maks setiap 2 detik


            function initMap() {
                // DEBUGGING: Log isi data yang diterima JavaScript
                console.log("phpDropPoints:", phpDropPoints);

                const defaultLocation = [-6.2088, 106.8456]; // Jakarta default

                const mapElement = document.getElementById('map');
                if (!mapElement) {
                    console.error("Error: Elemen HTML dengan ID 'map' tidak ditemukan.");
                    return;
                }
                if (mapElement.offsetWidth === 0 || mapElement.offsetHeight === 0) {
                    console.warn("Peringatan: Elemen 'map' memiliki dimensi nol atau disembunyikan. Peta mungkin tidak muncul dengan benar. Memanggil invalidateSize setelah DOM stabil.");
                }

                map = L.map('map').setView(defaultLocation, 13);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                if (dropPoints.length > 0) {
                    addDropPointMarkersToMap(dropPoints);
                }

                const urlParams = new URLSearchParams(window.location.search);
                const selectedDpId = urlParams.get('selected_dp_id');
                console.log("JavaScript: selected_dp_id from URL:", selectedDpId);
                if (selectedDpId) {
                    const selectedDropPoint = dropPoints.find(dp => dp.id == selectedDpId);
                    if (selectedDropPoint) {
                        console.log("JavaScript: Found selected drop point in data:", selectedDropPoint);
                        map.setView([selectedDropPoint.lat, selectedDropPoint.lng], 15);
                        initialZoomSet = true; // Set flag
                        setTimeout(() => {
                            dropPointMarkers.eachLayer(function(layer) {
                                if (layer.options.pointId == selectedDropPoint.id) {
                                    layer.openPopup();
                                }
                            });
                        }, 500);
                        currentDestination = { lat: selectedDropPoint.lat, lng: selectedDropPoint.lng, id: selectedDropPoint.id };
                        currentRouteTargetId = selectedDropPoint.id;
                        dropPointIdInput.value = selectedDropPoint.id;
                        recyclingFormSection.style.display = 'block';
                        startRouteTracking(selectedDropPoint.lat, selectedDropPoint.lng, selectedDropPoint.id);
                        dropPointListHeading.textContent = "Detail Drop Point Pilihan";
                    } else {
                        console.warn("JavaScript: Selected drop point not found in phpDropPoints array.");
                    }
                } else {
                    console.log("JavaScript: No selected_dp_id in URL, showing all drop points if available.");
                }
            }

            // Custom icon for drop points (green marker)
            const dropPointIcon = new L.Icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41],
                popupAnchor: [1, -34],
                shadowSize: [41, 41]
            });

            // Custom icon for user location (blue marker)
            const userLocationIcon = new L.Icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41],
                popupAnchor: [1, -34],
                shadowSize: [41, 41]
            });

            function addDropPointMarkersToMap(points) {
                dropPointMarkers.clearLayers();

                points.forEach(point => {
                    const marker = L.marker([point.lat, point.lng], { icon: dropPointIcon, pointId: point.id }).addTo(map);
                    // PERBAIKAN: Format URL Google Maps yang universal
                    marker.bindPopup(`
                        <div style="font-family: Poppins, sans-serif;">
                            <b>${escapeHtml(point.name)}</b><br>
                            ${escapeHtml(point.address)}<br>
                            Jarak: ${point.distance || 'Tidak diketahui'}<br>
                            Jam buka: ${escapeHtml(point.hours)}<br>
                            Jenis: ${escapeHtml(point.types.join(', '))}
                            <br><a href="https://www.google.com/maps/search/?api=1&query=${point.lat},${point.lng}" target="_blank">Lihat Arah (Google Maps)</a>
                        </div>
                    `);
                    dropPointMarkers.addLayer(marker);
                    point.marker = marker;
                });
                dropPointMarkers.addTo(map);
            }

            function showRouteMessage(message, isError = false) {
                routeMessage.textContent = message;
                routeMessage.style.display = 'block';

                const computedStyle = getComputedStyle(document.documentElement);
                const textDarkColor = computedStyle.getPropertyValue('--text-dark').trim();

                routeMessage.style.color = isError ? '#E74C3C' : textDarkColor;
                routeMessage.style.borderColor = isError ? '#E74C3C' : '#ddd';

                setTimeout(() => {
                    routeMessage.style.display = 'none';
                }, 5000);
            }

            function isOpenNow(hours) {
                const now = new Date();
                const currentDay = now.getDay();
                const currentTime = now.getHours() * 60 + now.getMinutes();

                const parts = hours.split('–');
                if (parts.length !== 2) return false;

                const [openTimeStr, closeTimeStr] = parts;

                const parseTime = (timeStr) => {
                    const [h, m] = timeStr.split('.').map(Number);
                    return h * 60 + m;
                };

                const openTime = parseTime(openTimeStr);
                const closeTime = parseTime(closeTimeStr);

                if (closeTime < openTime) {
                    return (currentTime >= openTime || currentTime <= closeTime);
                } else {
                    return (currentTime >= openTime && currentTime <= closeTime);
                }
            }

            function getWasteTypeIcon(type) {
                switch (type.toLowerCase()) {
                    case 'plastik': return '<i class="fas fa-bottle-water"></i>';
                    case 'kertas': return '<i class="fas fa-file-alt"></i>';
                    case 'logam': return '<i class="fas fa-box-open"></i>';
                    case 'kaca': return '<i class="fas fa-wine-bottle"></i>';
                    case 'elektronik': return '<i class="fas fa-laptop"></i>';
                    case 'minyak jelantah': return '<i class="fas fa-oil-can"></i>';
                    default: return '<i class="fas fa-recycle"></i>';
                }
            }

            function renderStars(rating) {
                let starsHtml = '';
                const fullStars = Math.floor(rating);
                const halfStar = rating - fullStars >= 0.5;
                const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);

                for (let i = 0; i < fullStars; i++) {
                    starsHtml += '<i class="fas fa-star"></i>';
                }
                if (halfStar) {
                    starsHtml += '<i class="fas fa-star-half-alt"></i>';
                }
                for (let i = 0; i < emptyStars; i++) {
                    starsHtml += '<i class="far fa-star"></i>';
                }
                return `<span class="rating-stars">${starsHtml}</span>`;
            }

            function getDropPointTraffic(dropPointName) {
                const hour = new Date().getHours();
                if (dropPointName.includes("Bersih")) {
                    if (hour % 2 === 0) return { status: "ramai", message: "Ramai" };
                    if (hour % 3 === 0) return { status: "sedang", message: "Sedang" };
                    return { status: "sepi", message: "Sepi" };
                }
                return { status: "sepi", message: "Sepi" };
            }

            function createDropPointElement(point) {
                const item = document.createElement('div');
                item.className = 'drop-point-item card full-width-item';
                item.setAttribute('data-point-id', point.id);

                const traffic = getDropPointTraffic(point.name);
                let trafficHtml = `<span class="traffic-status ${traffic.status}">${traffic.message}</span>`;
                let recommendationHtml = '';

                if (traffic.status === 'ramai') {
                    const alternative = dropPoints.find(dp =>
                        dp.id !== point.id &&
                        getDropPointTraffic(dp.name).status !== 'ramai' &&
                        userMarker && L.latLng(userMarker.getLatLng()).distanceTo(L.latLng(dp.lat, dp.lng)) < 5000
                    );
                    if (alternative) {
                        recommendationHtml = `<div class="traffic-recommendation">Bank Sampah ini sedang **ramai**. Coba ke **${escapeHtml(alternative.name)}**!</div>`;
                    } else {
                        recommendationHtml = `<div class="traffic-recommendation">Bank Sampah ini sedang **ramai**. Mungkin perlu menunggu.</div>`;
                    }
                }

                const formattedWasteTypesHtml = point.formatted_waste_types.map(waste => `
                    <li><i class="fas fa-recycle"></i> ${escapeHtml(waste.type)}: ${escapeHtml(waste.price)}</li>
                `).join('');

                const displayRating = point.rating !== null ? parseFloat(point.rating).toFixed(1) : 'N/A';
                const displayReviews = point.reviews !== null ? parseInt(point.reviews) : 0;


                item.innerHTML = `
                    <h4>${escapeHtml(point.name)} ${trafficHtml}</h4>
                    <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(point.address)}</p>
                    <p><i class="fas fa-route"></i> Jarak: ${point.distance || 'Tidak diketahui'}</p>
                    <p><i class="fas fa-clock"></i> Jam buka: ${escapeHtml(point.hours)}<br>
                    <ul class="waste-types-list">
                        ${formattedWasteTypesHtml}
                    </ul>
                    <p><strong>Deskripsi:</strong> ${escapeHtml(point.description || 'Tidak ada deskripsi.')}</p>
                    <p><strong>Syarat & Ketentuan:</strong> ${escapeHtml(point.terms || 'Tidak ada syarat dan ketentuan.')}</p>

                    <div class="rating-section">
                        ${point.rating !== null ? `${renderStars(point.rating)} ${displayRating} (${displayReviews} ulasan)` : 'Belum ada ulasan.'}
                    </div>
                    ${recommendationHtml}
                    <div class="button-group">
                        <button class="btn secondary small get-directions-btn" data-lat="${point.lat}" data-lng="${point.lng}" data-point-id="${point.id}">Dapatkan Arah</button>
                        <button class="btn secondary small share-location-btn" data-lat="${point.lat}" data-lng="${point.lng}" data-point-name="${escapeHtml(point.name)}">Bagikan Lokasi</button>
                    </div>
                    <div class="route-action-buttons" style="display: none;">
                        </div>
                    <span class="route-status" style="display: none;">Status: Menunggu...</span>
                `;
                return item;
            }

            function applyFiltersAndRenderList() {
                dropPointResultsWrapper.innerHTML = '';

                let pointsToDisplay = [];
                const urlParams = new URLSearchParams(window.location.search);
                const selectedDpId = urlParams.get('selected_dp_id');

                if (selectedDpId && parseInt(selectedDpId) > 0) {
                    const selectedDropPoint = dropPoints.find(dp => dp.id == selectedDpId);
                    if (selectedDropPoint) {
                        pointsToDisplay = [selectedDropPoint];
                        dropPointListHeading.textContent = "Detail Drop Point Pilihan";
                    } else {
                        pointsToDisplay = dropPoints;
                        dropPointListHeading.textContent = "Daftar Drop Point";
                    }
                } else {
                    pointsToDisplay = dropPoints;
                    dropPointListHeading.textContent = "Daftar Drop Point";
                }


                if (pointsToDisplay.length === 0) {
                    dropPointResultsWrapper.innerHTML = '<p style="text-align: center; color: var(--text-gray); width: 100%;">Tidak ada drop point ditemukan.</p>';
                    return;
                }

                pointsToDisplay.forEach(pointToDisplay => {
                    if (userMarker) {
                        const userLatLng = userMarker.getLatLng();
                        const originPoint = L.latLng(userLatLng.lat, userLatLng.lng);
                        const dropPointGeo = L.latLng(pointToDisplay.lat, pointToDisplay.lng);
                        const distanceMeters = originPoint.distanceTo(dropPointGeo);
                        pointToDisplay.distance = `${(distanceMeters / 1000).toFixed(2)} km`;
                        pointToDisplay.distanceMeters = distanceMeters;
                    } else {
                        pointToDisplay.distance = 'Tidak diketahui';
                        pointToDisplay.distanceMeters = Infinity;
                    }

                    const item = createDropPointElement(pointToDisplay);
                    dropPointResultsWrapper.appendChild(item);
                });

                attachDropPointButtonListeners();

                document.querySelectorAll('.drop-point-item').forEach(item => {
                    const buttonGroup = item.querySelector('.button-group');
                    const routeActionButtons = item.querySelector('.route-action-buttons');
                    const routeStatus = item.querySelector('.route-status');

                    if (isTrackingRoute && parseInt(item.dataset.pointId) === currentRouteTargetId) {
                        if (buttonGroup) buttonGroup.style.display = 'none';
                        routeActionButtons.innerHTML = `<button class="btn danger small cancel-route-btn" data-point-id="${item.dataset.pointId}">Batalkan Perjalanan</button>`;
                        const cancelButton = routeActionButtons.querySelector('.cancel-route-btn');
                        if (cancelButton) {
                            cancelButton.removeEventListener('click', handleCancelRouteClick);
                            cancelButton.addEventListener('click', handleCancelRouteClick);
                        }
                        if (routeActionButtons) routeActionButtons.style.display = 'flex';
                        if (routeStatus) routeStatus.style.display = 'block';
                    } else {
                        if (buttonGroup) buttonGroup.style.display = 'flex';
                        if (routeActionButtons) routeActionButtons.style.display = 'none';
                        if (routeStatus) routeStatus.style.display = 'none';
                        routeStatus.textContent = "Status: Menunggu...";
                    }
                });
            }


            function drawOrUpdateRoute(startLatLng, endLatLng, profile) {
                // Jangan panggil debouncedShowRouteMessage di sini karena sudah di-debounce di atas
                debouncedShowRouteMessage(`Mencari rute untuk mode ${profile}...`, false);

                if (routingControl && map.hasLayer(routingControl)) {
                    console.log('Menghapus kontrol rute sebelumnya.');
                    map.removeControl(routingControl);
                    routingControl = null;
                }

                const osrmServiceUrl = `https://router.project-osrm.org/route/v1`;

                console.log('Membuat atau memperbarui rute dengan profil:', profile, 'dari:', startLatLng, 'ke:', endLatLng);

                routingControl = L.Routing.control({
                    waypoints: [
                        L.latLng(startLatLng[0], startLatLng[1]),
                        L.latLng(endLatLng[0], endLatLng[1])
                    ],
                    router: L.routing.osrmv1({
                        serviceUrl: osrmServiceUrl,
                        profile: profile
                    }),
                    routeWhileDragging: false,
                    showAlternatives: false,
                    lineOptions: {
                        styles: [{ color: '#00c853', weight: 6, opacity: 0.7 }]
                    },
                    show: false,
                    collapsed: true
                });

                routingControl.addTo(map);

                routingControl.on('routingerror', function(e) {
                    console.error('Routing Error:', e.error.message, e);
                    let errorMessage = `Gagal mendapatkan rute untuk mode ${profile}. `;
                    if (e.error && e.error.message) {
                        errorMessage += `Detail: ${e.error.message}.`;
                    } else {
                        errorMessage += `Terjadi masalah koneksi atau server sibuk.`;
                    }
                    debouncedShowRouteMessage(errorMessage + ' Pastikan koneksi internet stabil atau coba mode transportasi lain.', true);

                    if (routingControl && map.hasLayer(routingControl)) {
                         map.removeControl(routingControl);
                    }
                    routingControl = null;
                    currentDestination = null;
                    currentRouteTargetId = null;
                    isTrackingRoute = false;
                    applyFiltersAndRenderList();
                });

                routingControl.on('routesfound', function(e) {
                    console.log('Rute ditemukan:', e.routes);
                    if (e.routes && e.routes.length > 0) {
                        console.log('Garis rute berhasil ditampilkan untuk profil:', profile);
                        debouncedShowRouteMessage(`Rute berhasil dimuat untuk mode ${profile}.`, false);
                        const bounds = L.latLngBounds(e.routes[0].coordinates);
                        map.fitBounds(bounds, {padding: [50, 50]});
                    } else {
                        console.warn('Tidak ada rute yang ditemukan oleh layanan rute untuk profil:', profile);
                        debouncedShowRouteMessage(`Tidak ada rute yang ditemukan untuk mode ${profile}. Coba lokasi lain atau mode berbeda.`, true);
                    }
                });

                routingControl.on('routerror', function(e) {
                    console.error('Router Error (generic):', e);
                    debouncedShowRouteMessage(`Terjadi kesalahan router untuk mode ${profile}. Coba lagi nanti.`, true);
                    if (routingControl && map.hasLayer(routingControl)) {
                         map.removeControl(routingControl);
                    }
                    routingControl = null;
                    currentDestination = null;
                    currentRouteTargetId = null;
                    isTrackingRoute = false;
                    applyFiltersAndRenderList();
                });
            }

            function startRouteTracking(targetLat, targetLng, targetId) {
                if (!userMarker) {
                    debouncedShowRouteMessage("Mohon aktifkan lokasi Anda terlebih dahulu dengan menekan tombol 'Lokasi Saya' untuk memulai perjalanan.", true);
                    return;
                }

                if (isTrackingRoute) {
                    debouncedShowRouteMessage("Anda sudah dalam perjalanan menuju drop point lain. Selesaikan atau batalkan dulu.", true);
                    return;
                }

                isTrackingRoute = true;
                currentRouteTargetId = targetId;
                currentDestination = { lat: targetLat, lng: targetLng, id: targetId };
                dropPointIdInput.value = targetId;

                debouncedShowRouteMessage("Mulai melacak perjalanan Anda...", false);

                recyclingFormSection.style.display = 'block';

                applyFiltersAndRenderList();

                if (userLocationWatchId !== null) {
                    navigator.geolocation.clearWatch(userLocationWatchId);
                }

                // Track if initial view/zoom has been set for this route
                let routeInitialViewSet = false;

                userLocationWatchId = navigator.geolocation.watchPosition(
                    (position) => {
                        const userLatLng = [position.coords.latitude, position.coords.longitude];

                        if (userMarker) {
                            userMarker.setLatLng(userLatLng);
                        } else {
                            userMarker = L.marker(userLatLng, { icon: userLocationIcon }).addTo(map)
                                .bindPopup("Lokasi Anda").openPopup();
                        }

                        // Hanya pan dan zoom sekali saat memulai rute atau jika pengguna bergerak jauh
                        if (!routeInitialViewSet || !map.getBounds().contains(userMarker.getLatLng())) {
                            map.panTo(userLatLng);
                            if (!initialZoomSet) { // Only set zoom once if it hasn't been set globally
                                map.setZoom(15);
                                initialZoomSet = true;
                            }
                            routeInitialViewSet = true;
                        }


                        if (currentDestination) {
                            // Gunakan debounced version untuk menggambar ulang rute
                            debouncedDrawOrUpdateRoute(
                                [userLatLng[0], userLatLng[1]],
                                [currentDestination.lat, currentDestination.lng],
                                currentTransportMode
                            );
                        }

                        // Update currentDistanceToDropPoint for display purposes
                        if (currentDestination) {
                             const dropPointGeo = L.latLng(currentDestination.lat, currentDestination.lng);
                             currentDistanceToDropPoint = L.latLng(userLatLng[0], userLatLng[1]).distanceTo(dropPointGeo);
                        }

                        applyFiltersAndRenderList(); // Ini akan memperbarui jarak di daftar

                        debouncedGetWeatherData(userLatLng[0], userLatLng[1]); // Gunakan debounced
                        recordSmartwatchData(userLatLng[0], userLatLng[1], 100);
                    },
                    (error) => {
                        console.error('Error getting location (watchPosition):', error);
                        myLocationStatus.textContent = `Gagal mendapatkan lokasi: ${error.message}.`;
                        myLocationStatus.style.color = '#f44336';
                        setTimeout(() => { myLocationStatus.style.display = 'none'; }, 5000);
                        userMarker = null;
                        cancelRouteTracking("Pelacakan lokasi dinonaktifkan karena masalah.");
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            }

            function cancelRouteTracking(message = "Perjalanan dibatalkan.", arrived = false) {
                if (userLocationWatchId !== null) {
                    navigator.geolocation.clearWatch(userLocationWatchId);
                    userLocationWatchId = null;
                }
                if (routeStatusInterval !== null) {
                    clearInterval(routeStatusInterval);
                    routeStatusInterval = null;
                }
                if (routingControl && map.hasLayer(routingControl)) {
                    map.removeControl(routingControl);
                    routingControl = null;
                }

                isTrackingRoute = false;
                currentRouteTargetId = null;
                currentDestination = null;
                dropPointIdInput.value = '';
                hasArrivedSoundPlayed = false;
                currentDistanceToDropPoint = Infinity;
                initialZoomSet = false; // Reset initial zoom flag

                document.querySelectorAll('.drop-point-item .route-status').forEach(el => {
                    el.textContent = "Status: Menunggu...";
                    el.style.display = 'none';
                });

                debouncedShowRouteMessage(message, false); // Gunakan debounced

                // Sembunyikan notifikasi rute jika sedang ditampilkan
                routeMessage.style.display = 'none';
                rewardNotification.classList.remove('show'); // Pastikan notif reward juga hilang
                ecoFactNotification.classList.remove('show'); // Pastikan notif eco fact juga hilang

                recyclingFormSection.style.display = 'none';
                distanceValidationMessage.classList.remove('show');
                resetRecyclingForm();

                const urlParams = new URLSearchParams(window.location.search);
                const selectedDpId = urlParams.get('selected_dp_id');
                if (selectedDpId && parseInt(selectedDpId) > 0) {
                    urlParams.delete('selected_dp_id');
                    const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                    history.replaceState(null, '', newUrl);
                    // Tidak perlu reload penuh jika hanya ingin menghapus param,
                    // tapi jika ada state lain yang bergantung pada URL, mungkin tetap perlu.
                    // Jika tidak, cukup: applyFiltersAndRenderList();
                    // window.location.reload(); // Pertimbangkan untuk menghapus ini
                }
                applyFiltersAndRenderList(); // Render ulang daftar drop point
            }

            function attachDropPointButtonListeners() {
                document.querySelectorAll('.get-directions-btn').forEach(button => {
                    button.removeEventListener('click', handleGetDirectionsClick);
                    button.addEventListener('click', handleGetDirectionsClick);
                });

                document.querySelectorAll('.share-location-btn').forEach(button => {
                    button.removeEventListener('click', handleShareLocationClick);
                    button.addEventListener('click', handleShareLocationClick);
                });
            }

            function handleGetDirectionsClick(e) {
                if (isTrackingRoute) {
                    debouncedShowRouteMessage("Anda sedang dalam perjalanan. Selesaikan atau batalkan perjalanan saat ini terlebih dahulu.", true); // Gunakan debounced
                    return;
                }

                const destLat = parseFloat(e.target.dataset.lat);
                const destLng = parseFloat(e.target.dataset.lng);
                const pointId = parseInt(e.target.dataset.pointId);

                currentDestination = { lat: destLat, lng: destLng, id: pointId };
                currentRouteTargetId = pointId;
                dropPointIdInput.value = pointId;

                console.log('Permintaan arah ke:', currentDestination, 'dengan mode:', currentTransportMode);

                if (userMarker) {
                    const userLatLng = userMarker.getLatLng();
                    debouncedDrawOrUpdateRoute([userLatLng.lat, userLatLng.lng], [destLat, destLng], currentTransportMode); // Gunakan debounced
                    startRouteTracking(destLat, destLng, pointId);
                } else {
                    debouncedShowRouteMessage("Mohon aktifkan lokasi Anda terlebih dahulu dengan menekan tombol 'Lokasi Saya'.", true); // Gunakan debounced
                }
            }

            function handleCancelRouteClick() {
                if (confirm("Apakah Anda yakin ingin membatalkan perjalanan ini?")) {
                    cancelRouteTracking("Perjalanan dibatalkan.");
                }
            }

            async function handleShareLocationClick(e) {
                const lat = e.target.dataset.lat;
                const lng = e.target.dataset.lng;
                const name = e.target.dataset.pointName;
                // PERBAIKAN: Format URL Google Maps yang universal dan direkomendasikan
                const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

                try {
                    if (navigator.share) {
                        await navigator.share({
                            title: `Lokasi Drop Point: ${name}`,
                            text: `Cek drop point ini di EcoPoint: ${name}`,
                            url: googleMapsUrl,
                        });
                        debouncedShowRouteMessage("Lokasi berhasil dibagikan!", false); // Gunakan debounced
                    } else {
                        await navigator.clipboard.writeText(googleMapsUrl);
                        debouncedShowRouteMessage("Link lokasi disalin ke clipboard!", false); // Gunakan debounced
                    }
                } catch (error) {
                    console.error('Error sharing or copying location:', error);
                    debouncedShowRouteMessage("Gagal membagikan atau menyalin lokasi.", true); // Gunakan debounced
                }
            }

            myLocationBtn.addEventListener('click', () => {
                if (navigator.geolocation) {
                    if (userLocationWatchId !== null) {
                        navigator.geolocation.clearWatch(userLocationWatchId);
                    }
                    myLocationStatus.textContent = "Mencari lokasi...";
                    myLocationStatus.style.display = 'inline';
                    myLocationStatus.style.color = getComputedStyle(document.documentElement).getPropertyValue('--text-gray').trim();

                    // Reset initialZoomSet saat mencari lokasi baru
                    initialZoomSet = false;

                    userLocationWatchId = navigator.geolocation.watchPosition(
                        (position) => {
                            const userLatLng = [position.coords.latitude, position.coords.longitude];

                            if (userMarker) {
                                userMarker.setLatLng(userLatLng);
                            } else {
                                userMarker = L.marker(userLatLng, { icon: userLocationIcon }).addTo(map)
                                    .bindPopup("Lokasi Anda").openPopup();
                            }

                            // Hanya pan dan zoom jika belum diatur atau pengguna berada di luar pandangan
                            if (!map.getBounds().contains(userMarker.getLatLng()) || !initialZoomSet) {
                                map.panTo(userLatLng);
                                if (!initialZoomSet) {
                                    map.setZoom(15);
                                    initialZoomSet = true;
                                }
                            }

                            if (currentDestination) {
                                debouncedDrawOrUpdateRoute( // Gunakan debounced
                                    [userLatLng[0], userLatLng[1]],
                                    [currentDestination.lat, currentDestination.lng],
                                    currentTransportMode
                                );
                            }
                            // Update currentDistanceToDropPoint for display purposes
                            if (currentDestination) {
                                 const dropPointGeo = L.latLng(currentDestination.lat, currentDestination.lng);
                                 currentDistanceToDropPoint = L.latLng(userLatLng[0], userLatLng[1]).distanceTo(dropPointGeo);
                            }

                            applyFiltersAndRenderList();

                            debouncedGetWeatherData(userLatLng[0], userLatLng[1]); // Gunakan debounced
                            recordSmartwatchData(userLatLng[0], userLatLng[1], 100);
                        },
                        (error) => {
                            console.error('Error getting location (watchPosition):', error);
                            myLocationStatus.textContent = `Gagal mendapatkan lokasi: ${error.message}.`;
                            myLocationStatus.style.color = '#f44336';
                            setTimeout(() => { myLocationStatus.style.display = 'none'; }, 5000);
                            userMarker = null;
                            cancelRouteTracking("Pelacakan lokasi dinonaktifkan karena masalah.");
                        },
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                    );
                } else {
                    myLocationStatus.textContent = 'Geolocation tidak didukung oleh browser ini.';
                    myLocationStatus.style.color = '#f44336';
                    myLocationStatus.style.display = 'inline';
                }
            });

            // Debounce for performSearch already exists and is good


            async function performSearch() {
                const searchTerm = searchLocationInput.value;
                if (!searchTerm) {
                    debouncedShowRouteMessage("Masukkan lokasi untuk dicari.", true); // Gunakan debounced
                    return;
                }

                debouncedShowRouteMessage("Mencari lokasi...", false); // Gunakan debounced

                try {
                    const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchTerm)}&limit=1`);
                    const data = await response.json();

                    if (data && data.length > 0) {
                        const place = data[0];
                        const searchLatLng = [parseFloat(place.lat), parseFloat(place.lon)];
                        map.setView(searchLatLng, 13);

                        if (userMarker) {
                            map.removeLayer(userMarker);
                            userMarker = null;
                        }
                        if (userLocationWatchId !== null) {
                            navigator.geolocation.clearWatch(userLocationWatchId);
                            userLocationWatchId = null;
                            myLocationStatus.textContent = 'Pelacakan lokasi dihentikan karena pencarian baru.';
                            myLocationStatus.style.color = getComputedStyle(document.documentElement).getPropertyValue('--text-gray').trim();
                            myLocationStatus.style.display = 'inline';
                            setTimeout(() => { myLocationStatus.style.display = 'none'; }, 3000);
                        }
                        if (routingControl && map.hasLayer(routingControl)) {
                            map.removeControl(routingControl);
                            routingControl = null;
                            currentDestination = null;
                        }

                        console.log('Pencarian lokasi berhasil:', searchTerm, searchLatLng);
                        debouncedShowRouteMessage(`Lokasi '${searchTerm}' ditemukan.`, false); // Gunakan debounced

                        applyFiltersAndRenderList();

                        debouncedGetWeatherData(searchLatLng[0], searchLatLng[1]); // Gunakan debounced

                    } else {
                        debouncedShowRouteMessage('Lokasi tidak ditemukan. Coba pencarian lain.', true); // Gunakan debounced
                    }
                } catch (error) {
                    console.error('Error during geocoding:', error);
                    debouncedShowRouteMessage('Terjadi kesalahan saat mencari lokasi.', true); // Gunakan debounced
                }
            }

            const debouncedPerformSearch = createDebouncedFunction(performSearch, 500); // Pastikan ini pakai createDebouncedFunction

            searchBtn.addEventListener('click', debouncedPerformSearch);
            searchLocationInput.addEventListener('input', debouncedPerformSearch);
            searchLocationInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    debouncedPerformSearch();
                }
            });

            // --- Fungsi baru untuk menghitung poin berdasarkan berat ---
            function calculatePoints(weight) {
                if (weight < 1) {
                    return 50;
                } else if (weight >= 1 && weight <= 10) {
                    return 100;
                } else if (weight >= 11 && weight <= 20) {
                    return 130;
                } else if (weight >= 21 && weight <= 40) {
                    return 150;
                } else if (weight >= 41 && weight <= 60) {
                    return 170;
                } else if (weight > 60) {
                    return 200;
                }
                return 0; // Default or error case
            }

            async function checkFormValidity() {
                const isAnyWasteTypeSelected = Array.from(wasteTypeCheckboxes).some(checkbox => checkbox.checked);
                const isPhotoSelected = wastePhotoInput.files.length > 0;

                let isPinValid = false;
                const selectedDropPointId = dropPointIdInput.value;
                const enteredPin = pinInput.value.trim();

                if (selectedDropPointId && enteredPin.length === 6) {
                    try {
                        const response = await fetch('verify_pin.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                dropPointId: selectedDropPointId,
                                pin: enteredPin
                            })
                        });
                        const result = await response.json();
                        isPinValid = result.isValid;
                        if (!isPinValid) {
                            distanceValidationMessage.textContent = `PIN yang dimasukkan tidak cocok untuk lokasi ini.`;
                            distanceValidationMessage.classList.add('show');
                        } else {
                            if (distanceValidationMessage.textContent.includes('PIN')) {
                                distanceValidationMessage.classList.remove('show');
                            }
                        }
                    } catch (error) {
                        console.error('Error verifying PIN:', error);
                        isPinValid = false;
                        distanceValidationMessage.textContent = `Terjadi kesalahan saat verifikasi PIN. Pastikan lokasi bank sampah sudah dipilih dan koneksi internet stabil.`;
                        distanceValidationMessage.classList.add('show');
                    }
                }
                else if (enteredPin.length > 0 && enteredPin.length < 6) {
                    isPinValid = false;
                    if (distanceValidationMessage.textContent.includes('PIN tidak cocok')) {
                        // Biarkan pesan PIN tidak cocok jika sudah ada
                    } else {
                        distanceValidationMessage.textContent = `PIN harus 6 digit.`;
                        distanceValidationMessage.classList.add('show');
                    }
                } else {
                    isPinValid = false;
                    if (distanceValidationMessage.classList.contains('show')) {
                        distanceValidationMessage.classList.remove('show');
                    }
                }


                const isBasicFormValid = isPinValid &&
                                         isPhotoSelected &&
                                         isAnyWasteTypeSelected &&
                                         parseFloat(wasteWeightInput.value) > 0;


                if (isBasicFormValid) {
                    submitRecyclingFormBtn.disabled = false;
                    distanceValidationMessage.classList.remove('show');
                } else {
                    submitRecyclingFormBtn.disabled = true;
                    if (!isPinValid) {
                        // PIN message already handled above
                    } else if (!isAnyWasteTypeSelected || parseFloat(wasteWeightInput.value) <= 0 || !isPhotoSelected) {
                        distanceValidationMessage.textContent = "Harap lengkapi semua bidang yang diperlukan.";
                        distanceValidationMessage.classList.add('show');
                    }
                }
            }

            function resetRecyclingForm() {
                recyclingSubmissionForm.reset();
                pinInput.value = '';
                wasteTypeCheckboxes.forEach(checkbox => checkbox.checked = false);
                submitRecyclingFormBtn.disabled = true;
                currentDistanceToDropPoint = Infinity;
                distanceValidationMessage.classList.remove('show');
                resetPhotoUpload();
                // Sembunyikan notifikasi rute yang mungkin masih ada
                routeMessage.style.display = 'none';
            }

            pinInput.addEventListener('input', checkFormValidity);
            wasteTypeCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', checkFormValidity);
            });
            wasteWeightInput.addEventListener('input', checkFormValidity);

            recyclingSubmissionForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                await checkFormValidity();
                if (submitRecyclingFormBtn.disabled) {
                    console.warn("Form is not valid, preventing submission.");
                    return;
                }

                let compressedImageData = null;
                if (wastePhotoInput.files.length > 0) {
                    const file = wastePhotoInput.files[0];
                    try {
                        compressedImageData = await compressImage(file, 800, 0.7);
                        console.log("Image compressed. Size:", compressedImageData.length / 1024, "KB");
                    } catch (error) {
                        console.error("Error compressing image:", error);
                        debouncedShowRouteMessage("Gagal mengompres gambar. Coba lagi atau gunakan gambar lain.", true);
                        return;
                    }
                }

                const formData = new FormData();
                formData.append('userId', userIdInput.value);
                formData.append('dropPointId', dropPointIdInput.value);
                formData.append('pin', pinInput.value);
                Array.from(wasteTypeCheckboxes)
                    .filter(checkbox => checkbox.checked)
                    .map(checkbox => checkbox.value)
                    .forEach(type => formData.append('wasteType[]', type));
                formData.append('wasteWeight', wasteWeightInput.value);
                if (compressedImageData) {
                    formData.append('wastePhotoBase64', compressedImageData);
                }
                formData.append('qrCodeValue', 'N/A_No_QR_Scan_Required');
                formData.append('offlineSync', 'true');

                debouncedShowRouteMessage("Data penukaran sampah sedang diproses...", false);

                const weight = parseFloat(wasteWeightInput.value);
                const earnedPoints = calculatePoints(weight);


                if (!navigator.onLine) {
                    console.log("Offline: Saving submission locally.");
                    debouncedShowRouteMessage("Anda sedang offline. Data akan disimpan secara lokal dan disinkronkan nanti.", false);
                    const submissionDataOffline = {
                        userId: userIdInput.value,
                        dropPointId: dropPointIdInput.value,
                        pin: pinInput.value,
                        wasteTypes: Array.from(wasteTypeCheckboxes).filter(checkbox => checkbox.checked).map(checkbox => checkbox.value),
                        wasteWeight: weight,
                        wastePhotoBase64: compressedImageData,
                        qrCodeValue: 'N/A_No_QR_Scan_Required',
                        timestamp: new Date().toISOString(),
                        earnedPoints: earnedPoints
                    };
                    saveOfflineSubmission(submissionDataOffline);

                    let currentPoints = parseFloat(currentUserPointsSpan.textContent);
                    currentPoints += earnedPoints;

                    rewardPointsSpan.textContent = earnedPoints;
                    rewardNotification.classList.add('show');
                    setTimeout(() => {
                        rewardNotification.classList.remove('show');
                    }, 5000);

                    await performRebirthRitual();
                    showEcoFactNotification();
                    updateOfflineSyncIndicator();

                    cancelRouteTracking("Perjalanan selesai! Data Anda disimpan secara offline.");
                    showPostSubmitModal(earnedPoints, currentPoints);
                    updateUserPointsDisplay(currentPoints);
                    return;
                }

                try {
                    const response = await fetch('submit_recycling.php', {
                        method: 'POST',
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const result = await response.json();

                    if (result.success) {
                        console.log("Data submitted online:", result);

                        debouncedShowRouteMessage("Penukaran sampah berhasil dicatat! Terima kasih telah berpartisipasi.", false);

                        const finalEarnedPoints = result.earnedPoints !== undefined ? result.earnedPoints : earnedPoints;
                        let currentPoints = result.newTotalPoints;

                        rewardPointsSpan.textContent = finalEarnedPoints;
                        rewardNotification.classList.add('show');
                        setTimeout(() => {
                            rewardNotification.classList.remove('show');
                        }, 5000);

                        await performRebirthRitual();
                        showEcoFactNotification();

                        cancelRouteTracking("Perjalanan telah diselesaikan dan sampah berhasil ditukarkan.");
                        showPostSubmitModal(finalEarnedPoints, currentPoints);
                        updateUserPointsDisplay(currentPoints);
                    } else {
                        throw new Error(result.message || "Pengiriman gagal.");
                    }
                } catch (error) {
                    console.error("Error submitting data online:", error);
                    debouncedShowRouteMessage(`Gagal mengirim data penukaran: ${error.message}. Data disimpan secara lokal untuk sinkronisasi nanti.`, true);
                    const submissionDataOffline = {
                        userId: userIdInput.value,
                        dropPointId: dropPointIdInput.value,
                        pin: pinInput.value,
                        wasteTypes: Array.from(wasteTypeCheckboxes).filter(checkbox => checkbox.checked).map(checkbox => checkbox.value),
                        wasteWeight: weight,
                        wastePhotoBase64: compressedImageData,
                        qrCodeValue: 'N/A_No_QR_Scan_Required',
                        timestamp: new Date().toISOString(),
                        earnedPoints: earnedPoints
                    };
                    saveOfflineSubmission(submissionDataOffline);
                    updateOfflineSyncIndicator();

                    let currentPoints = parseFloat(currentUserPointsSpan.textContent);
                    currentPoints += earnedPoints;

                    showPostSubmitModal(earnedPoints, currentPoints);
                    updateUserPointsDisplay(currentPoints);
                }
            });

            cancelRecyclingFormBtn.addEventListener('click', () => {
                if (confirm("Apakah Anda yakin ingin membatalkan penukaran sampah ini?")) {
                    cancelRouteTracking("Perjalanan dibatalkan.");
                }
            });

            function saveOfflineSubmission(data) {
                let offlineSubmissions = JSON.parse(localStorage.getItem(OFFLINE_SUBMISSIONS_KEY) || '[]');
                offlineSubmissions.push(data);
                localStorage.setItem(OFFLINE_SUBMISSIONS_KEY, JSON.stringify(offlineSubmissions));
            }

            async function syncOfflineSubmissions() {
                let offlineSubmissions = JSON.parse(localStorage.getItem(OFFLINE_SUBMISSIONS_KEY) || '[]');
                if (offlineSubmissions.length === 0) {
                    console.log("Tidak ada data offline untuk disinkronkan.");
                    updateOfflineSyncIndicator();
                    return;
                }

                console.log(`Mencoba sinkronisasi ${offlineSubmissions.length} data offline...`);
                debouncedShowRouteMessage(`Mendeteksi koneksi internet. Mencoba sinkronisasi ${offlineSubmissions.length} data tertunda...`, false);

                let successfulSyncs = [];
                let failedSyncs = [];

                for (const submission of offlineSubmissions) {
                    try {
                        const formData = new FormData();
                        formData.append('userId', submission.userId);
                        formData.append('dropPointId', submission.dropPointId);
                        formData.append('pin', submission.pin);
                        submission.wasteTypes.forEach(type => formData.append('wasteType[]', type));
                        formData.append('wasteWeight', submission.wasteWeight);
                        if (submission.wastePhotoBase64) {
                            formData.append('wastePhotoBase64', submission.wastePhotoBase64);
                        }
                        formData.append('qrCodeValue', 'N/A_No_QR_Scan_Required');
                        formData.append('offlineSync', 'true');

                        const response = await fetch('submit_recycling.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        const result = await response.json();
                        if (result.success) {
                            successfulSyncs.push(submission);
                            console.log("Offline data successfully synced:", result);
                            updateUserPointsDisplay(result.newTotalPoints);
                        } else {
                            throw new Error(result.message || "Sync failed on server side.");
                        }

                    } catch (error) {
                        console.error("Gagal sinkronisasi data:", submission, error);
                        failedSyncs.push(submission);
                    }
                }

                localStorage.setItem(OFFLINE_SUBMISSIONS_KEY, JSON.stringify(failedSyncs));

                if (successfulSyncs.length > 0) {
                    debouncedShowRouteMessage(`Berhasil sinkronisasi ${successfulSyncs.length} data.`, false);
                }
                if (failedSyncs.length > 0) {
                    debouncedShowRouteMessage(`${failedSyncs.length} data gagal disinkronkan. Akan dicoba lagi nanti.`, true);
                }
                updateOfflineSyncIndicator();
            }

            function updateOfflineSyncIndicator() {
                const offlineCount = JSON.parse(localStorage.getItem(OFFLINE_SUBMISSIONS_KEY) || '[]').length;
                if (offlineCount > 0) {
                    offlineSyncIndicator.querySelector('.count').textContent = offlineCount;
                    offlineSyncIndicator.classList.add('show');
                } else {
                    offlineSyncIndicator.classList.remove('show');
                }
            }

            offlineSyncIndicator.addEventListener('click', () => {
                if (navigator.onLine) {
                    syncOfflineSubmissions();
                } else {
                    debouncedShowRouteMessage("Anda sedang offline. Tidak dapat sinkronisasi sekarang.", true);
                }
            });


            window.addEventListener('online', () => {
                console.log("Perangkat online.");
                syncOfflineSubmissions();
            });

            window.addEventListener('offline', () => {
                console.log("Perangkat offline.");
                debouncedShowRouteMessage("Anda sedang offline. Data akan disimpan secara lokal.", true);
                updateOfflineSyncIndicator();
            });


            let lastKnownWeatherLat = -6.2088;
            let lastKnownWeatherLng = 106.8456;

            async function getWeatherData(lat, lng) {
                lastKnownWeatherLat = lat;
                lastKnownWeatherLng = lng;

                console.log(`Fetching weather for: ${lat}, ${lng}`);

                const now = new Date();
                const hour = now.getHours();
                const day = now.getDay();

                let randomCondition;

                if (hour >= 20 || hour < 6) {
                    randomCondition = 'clear';
                } else if (day === 5 || day === 6) {
                    const rand = Math.random();
                    if (rand < 0.4) randomCondition = 'rain';
                    else if (rand < 0.8) randomCondition = 'clouds';
                    else randomCondition = 'clear';
                } else {
                    const rand = Math.random();
                    if (rand < 0.2) randomCondition = 'rain';
                    else if (rand < 0.7) randomCondition = 'clouds';
                    else randomCondition = 'clear';
                }

                console.log(`Simulating weather: ${randomCondition}`);
                applyAmbientMood(randomCondition);
            }

            function applyAmbientMood(weatherCondition) {
                document.body.classList.remove('rainy-mood', 'clear-mood', 'cloudy-mood');
                weatherOverlay.classList.remove('rainy-mood', 'cloudy-mood');
                weatherOverlay.style.opacity = 0;

                switch (weatherCondition) {
                    case 'rain':
                        document.body.classList.add('rainy-mood');
                        weatherOverlay.classList.add('rainy-mood');
                        debouncedShowRouteMessage("Wah, hujan! Tetap semangat mendaur ulang!", false); // Gunakan debounced
                        break;
                    case 'clear':
                        document.body.classList.add('clear-mood');
                        debouncedShowRouteMessage("Cuaca cerah! Hari yang indah untuk beraksi!", false); // Gunakan debounced
                        break;
                    case 'clouds':
                        document.body.classList.add('cloudy-mood');
                        weatherOverlay.classList.add('cloudy-mood');
                        weatherOverlay.style.opacity = 0.5;
                        debouncedShowRouteMessage("Langit berawan. Mari beraksi!", false); // Gunakan debounced
                        break;
                    default:
                        document.body.classList.add('clear-mood');
                        break;
                }
            }


            function initSmartwatchIntegration() {
                console.log("Smartwatch integration initialized (simulated).");
            }

            function sendSmartwatchNotification(message) {
                console.log("Smartwatch Notification (simulated):", message);
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('EcoPoint', {
                        body: message,
                        icon: 'https://via.placeholder.com/48x48?text=EP'
                    });
                } else if ('Notification' in window && Notification.permission !== 'denied') {
                    Notification.requestPermission().then(permission => {
                        if (permission === 'granted') {
                            new Notification('EcoPoint', {
                                body: message,
                                icon: 'https://via.placeholder.com/48x48?text=EP'
                            });
                        }
                    });
                }
            }

            function recordSmartwatchData(lat, lng, steps) {
                console.log(`Smartwatch Data Recorded (simulated): Lat: ${lat}, Lng: ${lng}, Steps: ${steps}`);
            }

            function createRebirthElement(type, delay, duration) {
                const element = document.createElement('div');
                element.className = type;
                element.style.setProperty('--animation-delay', `${delay}s`);
                element.style.setProperty('--animation-duration', `${duration}s`);
                if (type === 'flower') {
                    element.style.setProperty('--animation-fade-out-delay', `${delay + duration - 0.5}s`);
                    element.style.setProperty('--animation-fade-out-duration', '1s');
                }
                rebirthAnimationContainer.appendChild(element);

                element.addEventListener('animationend', () => {
                    element.remove();
                });
            }

            async function performRebirthRitual() {
                rebirthAnimationContainer.innerHTML = '';
                rebirthModal.classList.add('show');

                for (let i = 0; i < 7; i++) {
                    const delay = Math.random() * 1.5;
                    const duration = 2 + Math.random() * 0.5;
                    createRebirthElement('flower', delay, duration);
                }
                for (let i = 0; i < 3; i++) {
                    const delay = 0.5 + Math.random() * 2;
                    const duration = 5 + Math.random() * 2;
                    createRebirthElement('butterfly', delay, duration);
                }
                createRebirthElement('tree', 3, 2);

                await new Promise(resolve => setTimeout(resolve, 7000));
                rebirthModal.classList.remove('show');
            }

            const ecoFacts = [
                { question: "Tahukah kamu, 1 botol plastik bisa jadi seragam sekolah?", answer: "Betul! Banyak produk baru yang bisa dibuat dari daur ulang sampahmu." },
                { question: "Kalau kamu buang 1 kantong kresek per hari, dalam 1 tahun beratnya...?", answer: "Bisa mencapai puluhan kilogram! Yuk, mulai kurangi sampah plastik." },
                { question: "Apa kamu tahu? Daur ulang bisa menghemat 95% energi dibanding membuat plastik baru.", answer: "Itu berarti kita turut menjaga lingkungan dan sumber daya alam!" }
            ];

            function showEcoFactNotification() {
                const randomIndex = Math.floor(Math.random() * ecoFacts.length);
                const fact = ecoFacts[randomIndex];
                ecoFactQuestion.textContent = `❓ ${fact.question}`;
                ecoFactAnswer.textContent = `🧠 ${fact.answer}`;
                ecoFactNotification.classList.add('show');

                setTimeout(() => {
                    ecoFactNotification.classList.remove('show');
                }, 10000);
            }

            function togglePanduGreetingText() {
                if (panduGreeting.classList.contains('hidden')) {
                    panduGreeting.classList.remove('hidden');
                    panduGreeting.classList.add('active');
                } else {
                    panduGreeting.classList.add('hidden');
                    panduGreeting.classList.remove('active');
                }
            }

            closePanduGreetingBtn.addEventListener('click', (event) => {
                event.stopPropagation();
                panduGreeting.classList.add('hidden');
                panduGreeting.classList.remove('active');
            });

            panduGreeting.addEventListener('click', () => {
                if (panduGreeting.classList.contains('hidden')) {
                    panduGreeting.classList.remove('hidden');
                    panduGreeting.classList.add('active');
                }
            });

            function compressImage(file, maxWidth, quality) {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.readAsDataURL(file);
                    reader.onload = (event) => {
                        const img = new Image();
                        img.src = event.target.result;
                        img.onload = () => {
                            const canvas = imageCanvas;
                            const ctx = canvas.getContext('2d');

                            let width = img.width;
                            let height = img.height;

                            if (width > maxWidth) {
                                height = Math.round((height * maxWidth) / width);
                                width = maxWidth;
                            }

                            canvas.width = width;
                            canvas.height = height;

                            ctx.clearRect(0, 0, width, height);
                            ctx.drawImage(img, 0, 0, width, height);

                            canvas.toBlob((blob) => {
                                if (blob) {
                                    const readerBlob = new FileReader();
                                    readerBlob.readAsDataURL(blob);
                                    readerBlob.onloadend = () => {
                                        resolve(readerBlob.result);
                                    };
                                } else {
                                    reject(new Error('Canvas to Blob conversion failed.'));
                                }
                            }, file.type, quality);
                        };
                        img.onerror = (error) => {
                            reject(error);
                        };
                    };
                    reader.onerror = (error) => {
                        reject(error);
                    };
                });
            }

            browseFilesSpan.addEventListener('click', () => {
                wastePhotoInput.click();
            });

            dropZone.addEventListener('click', (event) => {
                if (!removePhotoBtn.contains(event.target) && event.target !== wastePhotoInput) {
                    wastePhotoInput.click();
                }
            });

            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('dragover');
            });

            dropZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('dragover');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    wastePhotoInput.files = files;
                    handleFileSelect(files[0]);
                }
            });

            wastePhotoInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    handleFileSelect(file);
                }
            });

            function handleFileSelect(file) {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        filePreview.src = e.target.result;
                        filePreviewContainer.style.display = 'block';
                        dropZone.querySelector('.upload-icon').style.display = 'none';
                        dropZone.querySelector('#drop-zone-text').style.display = 'none';
                        fileNameDisplay.textContent = file.name;
                        fileNameDisplay.style.display = 'block';
                        photoUploadContainer.classList.add('has-file');
                        checkFormValidity();
                    };
                    reader.readAsDataURL(file);
                } else {
                    alert('Mohon pilih file gambar (JPG, PNG, GIF, dll.).');
                    wastePhotoInput.value = '';
                    resetPhotoUpload();
                }
            }

            removePhotoBtn.addEventListener('click', () => {
                wastePhotoInput.value = '';
                resetPhotoUpload();
                checkFormValidity();
            });

            function resetPhotoUpload() {
                filePreview.src = '';
                filePreviewContainer.style.display = 'none';
                dropZone.querySelector('.upload-icon').style.display = 'block';
                dropZone.querySelector('#drop-zone-text').style.display = 'block';
                fileNameDisplay.textContent = '';
                fileNameDisplay.style.display = 'none';
                photoUploadContainer.classList.remove('has-file');
            }

            function updateUserPointsDisplay(newPoints = null) {
                currentUserPointsSpan.textContent = newPoints !== null ? newPoints.toFixed(0) : <?php echo $userPoints; ?>;
                const currentPoints = parseFloat(currentUserPointsSpan.textContent);

                if (currentPoints > 0) {
                    userPointsDisplay.style.display = 'flex';
                } else {
                    userPointsDisplay.style.display = 'none';
                }
            }

            function showPostSubmitModal(earnedPoints, totalPoints) {
                finalRewardPointsSpan.textContent = earnedPoints;
                finalTotalPointsSpan.textContent = totalPoints;
                postSubmitModal.classList.add('show');
            }

            closePostSubmitModalBtn.addEventListener('click', () => {
                postSubmitModal.classList.remove('show');
            });
            btnClosePostSubmitModal.addEventListener('click', () => {
                postSubmitModal.classList.remove('show');
            });


            // === Logika Aplikasi Utama ===
            document.addEventListener('DOMContentLoaded', () => {
                initMap();

                // PERBAIKAN: Panggil invalidateSize() setelah DOM stabil
                // Memberikan waktu lebih banyak (misalnya 1000ms) untuk memastikan semua CSS dimuat dan di-apply
                setTimeout(() => {
                    if (map) {
                        map.invalidateSize();
                        console.log("Map invalidated size after initial content load.");
                    }
                }, 1000); // Penyesuaian waktu

                // PERBAIKAN: Tambahkan event listener untuk resize window
                window.addEventListener('resize', () => {
                    if (map) {
                        map.invalidateSize();
                        console.log("Map invalidated size on window resize.");
                    }
                });

                panduGreeting.classList.remove('hidden');
                panduGreeting.classList.add('active');

                updateUserPointsDisplay();

                if (navigator.onLine) {
                    syncOfflineSubmissions();
                } else {
                    updateOfflineSyncIndicator();
                }

                // PERBAIKAN: Panggil debouncedGetWeatherData sekali di awal
                debouncedGetWeatherData(lastKnownWeatherLat, lastKnownWeatherLng);

                initSmartwatchIntegration();

                showEcoFactNotification(); // Notifikasi ini akan tetap muncul sekali di awal

                const handleHashChange = () => {
                    const sections = document.querySelectorAll('main section');
                    sections.forEach(section => {
                        if (section.id === 'drop-point-section') {
                            section.style.display = 'block';
                            // PERBAIKAN: Invalidate map size jika section peta baru saja dibuat visible
                            if (map) {
                                map.invalidateSize();
                                console.log("Map invalidated size after hash change and section visibility.");
                            }
                        }
                    });
                };

                window.addEventListener('hashchange', handleHashChange);
                handleHashChange();

                // Pastikan drop points dirender setelah peta diinisialisasi
                applyFiltersAndRenderList();
            });
        </script>
</body>
</html>