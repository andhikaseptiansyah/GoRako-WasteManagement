<?php
require_once 'db_connection.php'; // Your database connection

// In a real application, this should be part of an admin panel with proper authentication.
// For demonstration, we'll keep it simple, but be aware of security implications.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $officer_name = $_POST['name'] ?? '';
    $pin = $_POST['pin'] ?? ''; // Plain text PIN from form
    $drop_point_id = $_POST['drop_point_id'] ?? null; // Optional: Link to a specific drop point

    if (empty($officer_name) || empty($pin) || strlen($pin) !== 6) {
        die("Nama petugas dan PIN (6 digit) harus diisi.");
    }

    // Hash the PIN using a strong hashing algorithm
    // PASSWORD_BCRYPT is recommended for passwords/PINs
    $pin_hash = password_hash($pin, PASSWORD_BCRYPT);

    try {
        $stmt = $conn->prepare("INSERT INTO bank_officers (name, pin_hash, drop_point_id) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan pernyataan: " . $conn->error);
        }

        if ($drop_point_id === null || $drop_point_id === '') {
            $stmt->bind_param("ssi", $officer_name, $pin_hash, $drop_point_id); // Use i for null if DB column allows NULL
        } else {
            $stmt->bind_param("ssi", $officer_name, $pin_hash, $drop_point_id);
        }

        if ($stmt->execute()) {
            echo "Petugas Bank Sampah '{$officer_name}' berhasil ditambahkan dengan PIN terenkripsi.";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } catch (Exception $e) {
        echo "Kesalahan: " . $e->getMessage();
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tambah Petugas Bank Sampah</title>
</head>
<body>
    <h2>Tambah Petugas Bank Sampah Baru</h2>
    <form method="POST">
        <label for="name">Nama Petugas:</label><br>
        <input type="text" id="name" name="name" required><br><br>

        <label for="pin">PIN (6 digit):</label><br>
        <input type="password" id="pin" name="pin" maxlength="6" required><br><br>

        <label for="drop_point_id">ID Drop Point (Opsional, jika terkait):</label><br>
        <input type="number" id="drop_point_id" name="drop_point_id"><br><br>

        <button type="submit">Tambah Petugas</button>
    </form>
    <p>Ingat untuk tidak membagikan script ini di produksi dan gunakan via admin panel.</p>
</body>
</html>