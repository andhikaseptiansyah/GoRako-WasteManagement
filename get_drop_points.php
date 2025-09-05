<?php
// get_drop_points.php
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya (jika diperlukan untuk otentikasi API)
// if (session_status() == PHP_SESSION_NONE) {
//     session_start();
// }

// Sertakan file koneksi database Anda
require_once 'db_connection.php';

// Atur header agar browser memahami respons sebagai JSON
header('Content-Type: application/json');

$dropPoints = [];

// Query untuk mengambil semua data drop points
$sql_drop_points = "SELECT id, name, address, latitude, longitude, hours, types, prices, description, terms, rating, reviews FROM drop_points";
$result_drop_points = $conn->query($sql_drop_points);

if ($result_drop_points->num_rows > 0) {
    while($row = $result_drop_points->fetch_assoc()) {
        // Mendekode string JSON untuk 'types'. Jika gagal, pastikan defaultnya array kosong.
        $row['types'] = json_decode($row['types'], true) ?? [];
        $row['lat'] = (float) $row['latitude']; // Konversi ke float
        unset($row['latitude']); // Hapus kolom asli setelah diubah namanya
        $row['lng'] = (float) $row['longitude']; // Konversi ke float
        unset($row['longitude']); // Hapus kolom asli setelah diubah namanya

        // Mengatur warna kartu acak untuk variasi visual
        $card_colors = ['green-card', 'blue-card', 'purple-card'];
        $random_color_key = array_rand($card_colors);
        $row['cardColorClass'] = $card_colors[$random_color_key];

        // Memformat harga untuk tampilan
        $prices_array = [];
        if (!empty($row['prices'])) {
            $price_pairs = explode(', ', $row['prices']);
            foreach ($price_pairs as $pair) {
                // Pastikan format 'Jenis: Harga'
                if (strpos($pair, ': ') !== false) {
                    list($type_name, $price_val) = explode(': ', $pair, 2);
                    $prices_array[trim($type_name)] = trim($price_val);
                }
            }
        }

        $final_waste_types_formatted = [];
        if (is_array($row['types'])) {
            foreach ($row['types'] as $type) {
                $type_clean = trim((string)$type); // Pastikan string dan bersihkan whitespace
                $price_for_type = isset($prices_array[ucfirst($type_clean)]) ? $prices_array[ucfirst($type_clean)] : 'Harga Tidak Tersedia';
                $final_waste_types_formatted[] = [
                    'type' => ucfirst($type_clean),
                    'price' => $price_for_type
                ];
            }
        }
        $row['formatted_waste_types'] = $final_waste_types_formatted;

        $dropPoints[] = $row;
    }
}

// Mengirimkan array drop points sebagai respons JSON
echo json_encode($dropPoints);

// Tutup koneksi database
$conn->close();
?>