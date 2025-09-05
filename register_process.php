<?php
// Ensure session is started at the very beginning for potential session usage
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php'; // Your database connection file
require_once 'helpers.php';       // Assumed to contain clean_input() and other helpers

header('Content-Type: application/json'); // Crucial: Tell the client to expect JSON

$response = ['success' => false, 'message' => 'Terjadi kesalahan tidak dikenal.', 'field_errors' => []];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Check if JSON decoding was successful
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Invalid JSON data received.';
        echo json_encode($response);
        exit();
    }

    $firstName = clean_input($data['firstName'] ?? '');
    $lastName = clean_input($data['lastName'] ?? '');
    $email = clean_input($data['email'] ?? '');
    $password = $data['password'] ?? ''; // Password will be hashed, no sanitization here

    // Basic server-side validation
    $field_errors = [];
    if (empty($firstName)) {
        $field_errors['firstName'] = 'Nama depan tidak boleh kosong.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Format email tidak valid.';
    }
    // Password validation: min 8 chars, at least one letter, at least one number
    if (strlen($password) < 8 || !preg_match("/[A-Za-z]/", $password) || !preg_match("/\d/", $password)) {
        $field_errors['password'] = 'Kata sandi harus minimal 8 karakter dengan huruf dan angka.';
    }

    if (!empty($field_errors)) {
        $response['message'] = 'Validasi data pendaftaran gagal.';
        $response['field_errors'] = $field_errors;
        echo json_encode($response);
        exit();
    }

    $full_username = $firstName . (empty($lastName) ? '' : ' ' . $lastName); // Combine first and last name

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    if (!$stmt) {
        $response['message'] = 'Database preparation error (email check): ' . $conn->error;
        error_log($response['message']); // Log the error
        echo json_encode($response);
        exit();
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result(); // Needed to check num_rows
    if ($stmt->num_rows > 0) {
        $response['message'] = 'Pendaftaran gagal: Email sudah terdaftar.';
        $response['field_errors']['email'] = 'Email ini sudah digunakan.';
        echo json_encode($response);
        $stmt->close();
        exit();
    }
    $stmt->close();

    // NEW: Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if (!$stmt) {
        $response['message'] = 'Database preparation error (username check): ' . $conn->error;
        error_log($response['message']); // Log the error
        echo json_encode($response);
        exit();
    }
    $stmt->bind_param("s", $full_username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $response['message'] = 'Pendaftaran gagal: Nama pengguna sudah terdaftar.';
        $response['field_errors']['username'] = 'Nama pengguna ini sudah digunakan.';
        echo json_encode($response);
        $stmt->close();
        exit();
    }
    $stmt->close();


    // Hash the password for secure storage
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Default profile picture path (ensure this path exists and is accessible)
    $default_profile_picture = 'images/default_profile.png';

    // Insert new user into database
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, profile_picture) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        $response['message'] = 'Database preparation error (user insert): ' . $conn->error;
        error_log($response['message']); // Log the error
        echo json_encode($response);
        exit();
    }

    $stmt->bind_param("ssss", $full_username, $email, $hashed_password, $default_profile_picture);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Pendaftaran berhasil! Silakan masuk.';
        // Optionally, you could set session variables here for automatic login
        // $_SESSION['user_id'] = $stmt->insert_id;
        // $_SESSION['username'] = $full_username;
    } else {
        $response['message'] = 'Terjadi kesalahan saat mendaftar: ' . $stmt->error;
        error_log($response['message']); // Log the error
    }
    $stmt->close();
} else {
    $response['message'] = 'Permintaan HTTP tidak valid.';
}

$conn->close(); // Close the database connection
echo json_encode($response);
?>