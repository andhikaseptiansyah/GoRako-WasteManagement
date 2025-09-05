<?php
// checkout.php

// Sertakan file koneksi database. Ini akan memulai sesi dan membuat koneksi $conn.
require_once 'db_connection.php';
// Memastikan session_start() sudah terpanggil di db_connection.php

// Sertakan file helper
require_once 'helpers.php';

// Pastikan variabel koneksi database global dapat diakses
global $conn;

// Pastikan pengguna sudah login untuk halaman checkout ini
if (!is_logged_in()) {
    set_flash_message('error', 'Anda harus login untuk melakukan checkout.');
    redirect('login.php'); // Arahkan ke halaman login jika belum login
    exit; // Pastikan tidak ada eksekusi kode lebih lanjut
}

// Inisialisasi variabel untuk pesan dan data formulir
$submission_success = false;
$error_messages = [];

// Inisialisasi nilai default untuk form fields agar tetap terisi saat terjadi error
// Gunakan clean_input untuk semua input yang mungkin dari $_POST
$nama = clean_input($_POST['nama'] ?? '');
$email = clean_input($_POST['email'] ?? '');
$nohp = clean_input($_POST['nohp'] ?? '');
$jumlah = (int)($_POST['jumlah'] ?? 1); // Pastikan ini tetap integer
$pesan = clean_input($_POST['pesan'] ?? '');

// Inisialisasi data reward untuk ringkasan.
$reward_summary_title = 'Reward Pilihan Anda Belum Ditemukan';
$reward_summary_description = 'Pastikan Anda menukar reward melalui halaman penukaran poin.';
$reward_summary_icon = '❓';
$reward_summary_image_url = '';
$reward_points_needed = 0; // Initialize points needed for the reward

$checkout_reward_id = null; // Inisialisasi ID reward yang sedang di-checkout

// Fetch user's current points (menggunakan total_points dari tabel users)
$user_current_points = 0;
$loggedInUserId = $_SESSION['user_id'];
$stmt_user_points = $conn->prepare("SELECT total_points FROM users WHERE id = ?"); // MENGGUNAKAN total_points
if ($stmt_user_points) {
    $stmt_user_points->bind_param("i", $loggedInUserId);
    $stmt_user_points->execute();
    $result_user_points = $stmt_user_points->get_result();
    if ($result_user_points->num_rows > 0) {
        $user_current_points = $result_user_points->fetch_assoc()['total_points']; // MENGGUNAKAN total_points
    }
    $stmt_user_points->close();
} else {
    error_log("Error preparing user points fetch statement in checkout.php: " . $conn->error);
    $error_messages[] = "Gagal memuat poin Anda. Silakan coba lagi.";
}


// Check if a reward_id is passed in the URL (from service_quiz.php after exchange)
if (isset($_GET['reward_id']) && is_numeric($_GET['reward_id'])) {
    $checkout_reward_id = (int)clean_input($_GET['reward_id']); // Gunakan clean_input untuk input GET

    // Fetch reward details including points_needed
    $stmt = $conn->prepare("SELECT name, description, image_url, category, points_needed, stock FROM rewards WHERE id = ?"); // Tambah 'stock'
    if ($stmt) {
        $stmt->bind_param("i", $checkout_reward_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $reward_data = $result->fetch_assoc();
            $reward_summary_title = htmlspecialchars($reward_data['name']);
            $reward_summary_description = htmlspecialchars($reward_data['description']);
            $reward_summary_image_url = htmlspecialchars($reward_data['image_url']);
            $reward_points_needed = (int)$reward_data['points_needed']; // Assign points needed
            $reward_current_stock = (int)$reward_data['stock']; // Ambil stok saat ini

            // Adjust icon based on category, similar to service_quiz.php
            switch (strtolower($reward_data['category'])) {
                case 'physical product':
                    $reward_summary_icon = '📦';
                    break;
                case 'digital product':
                    $reward_summary_icon = '💾';
                    break;
                case 'service voucher':
                    $reward_summary_icon = '🎫';
                    break;
                case 'donation':
                    $reward_summary_icon = '❤️';
                    break;
                default:
                    $reward_summary_icon = '🎁';
                    break;
            }
        } else {
            $error_messages[] = "Reward yang dipilih tidak ditemukan atau tidak valid.";
            $checkout_reward_id = null; // Set null agar formulir tidak bisa disubmit
        }
        $stmt->close();
    } else {
        error_log("Error preparing reward fetch statement in checkout.php: " . $conn->error);
        $error_messages[] = "Gagal memuat detail reward. Silakan coba lagi.";
    }
} else {
    $error_messages[] = "Tidak ada reward yang dipilih untuk checkout. Silakan pilih reward dari halaman penukaran poin.";
}


// Cek apakah formulir telah dikirimkan menggunakan metode POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validasi sisi server
    if (empty($nama)) {
        $error_messages[] = "Nama lengkap wajib diisi.";
    }
    if (empty($email)) {
        $error_messages[] = "Email aktif wajib diisi.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_messages[] = "Format email tidak valid.";
    }
    if (empty($nohp)) {
        $error_messages[] = "Nomor HP aktif wajib diisi.";
    } elseif (!preg_match("/^(08|\+628)[1-9][0-9]{6,10}$/", $nohp)) {
        $error_messages[] = "Format nomor HP tidak valid (contoh: 081234567890 atau +6281234567890).";
    }
    if ($jumlah < 1 || $jumlah > 10) {
        $error_messages[] = "Jumlah harus antara 1 dan 10.";
    }
    // Pastikan reward_id masih valid saat POST
    if (is_null($checkout_reward_id)) {
        $error_messages[] = "Reward yang ingin di-checkout tidak valid. Kembali ke halaman penukaran.";
    }

    // Re-check points balance during form submission for security
    $total_points_needed = $reward_points_needed * $jumlah;
    if ($user_current_points < $total_points_needed) {
        $error_messages[] = "Poin Anda tidak mencukupi untuk melakukan checkout ini. Poin Anda: " . $user_current_points . "🪙, Dibutuhkan: " . $total_points_needed . "🪙.";
    }

    // Re-check reward stock during form submission for security
    // Fetch reward stock again from DB to prevent race conditions
    $stmt_recheck_stock = $conn->prepare("SELECT stock FROM rewards WHERE id = ?");
    if ($stmt_recheck_stock) {
        $stmt_recheck_stock->bind_param("i", $checkout_reward_id);
        $stmt_recheck_stock->execute();
        $result_recheck_stock = $stmt_recheck_stock->get_result();
        if ($result_recheck_stock->num_rows > 0) {
            $current_actual_stock = $result_recheck_stock->fetch_assoc()['stock'];
            if ($current_actual_stock < $jumlah) {
                $error_messages[] = "Maaf, stok reward " . $reward_summary_title . " tidak mencukupi. Tersisa: " . $current_actual_stock . " item.";
            }
        } else {
            $error_messages[] = "Reward tidak ditemukan saat re-validasi stok.";
        }
        $stmt_recheck_stock->close();
    } else {
        error_log("Error preparing re-check stock statement in checkout.php: " . $conn->error);
        $error_messages[] = "Gagal memuat detail stok reward. Silakan coba lagi.";
    }


    // Jika tidak ada error validasi, proses penyimpanan ke database
    if (empty($error_messages)) {
        // Mulai transaksi untuk memastikan atomisitas (semua atau tidak sama sekali)
        $conn->begin_transaction();

        try {
            // 1. Simpan data checkout ke tabel `exchanges`
            // Sesuaikan nama kolom jika tabel `exchanges` Anda berbeda
            $stmt_insert = $conn->prepare("INSERT INTO exchanges (user_id, reward_id, nama_penerima, email_penerima, nohp_penerima, jumlah_item, pesan_tambahan, checkout_date, points_used, status, estimated_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'pending', ?)");

            if ($stmt_insert === false) {
                throw new Exception("Error preparing statement for exchanges: " . $conn->error);
            }
            
            $default_estimated_delivery = "1-3 Hari Kerja"; // Contoh default
            $stmt_insert->bind_param("iissiisid",
                $loggedInUserId,
                $checkout_reward_id,
                $nama,
                $email,
                $nohp,
                $jumlah,
                $pesan,
                $total_points_needed, // Kolom points_used di exchanges
                $default_estimated_delivery
            );

            if (!$stmt_insert->execute()) {
                throw new Exception("Gagal menyimpan data checkout ke database: " . $stmt_insert->error);
            }
            $new_exchange_id = $stmt_insert->insert_id; // Dapatkan ID penukaran yang baru saja dibuat
            $stmt_insert->close();

            // 2. Kurangi poin pengguna dari tabel `users`
            $stmt_update_points = $conn->prepare("UPDATE users SET total_points = total_points - ? WHERE id = ?"); // MENGURANGI total_points
            if ($stmt_update_points === false) {
                throw new Exception("Error preparing statement for user points update: " . $conn->error);
            }
            $stmt_update_points->bind_param("ii", $total_points_needed, $loggedInUserId);
            if (!$stmt_update_points->execute()) {
                throw new Exception("Gagal mengurangi poin pengguna: " . $stmt_update_points->error);
            }
            $stmt_update_points->close();

            // 3. Catat transaksi di points_history
            // Asumsikan ada tabel points_history dengan kolom: user_id, points_amount, description, transaction_date
            $description_points_history = "Penukaran reward: " . $reward_summary_title . " (x" . $jumlah . ")";
            $stmt_points_history = $conn->prepare("INSERT INTO points_history (user_id, points_amount, description, transaction_date) VALUES (?, ?, ?, NOW())");
            if ($stmt_points_history === false) {
                throw new Exception("Error preparing statement for points history: " . $conn->error);
            }
            // Gunakan nilai negatif karena ini adalah pengurangan poin
            $negative_total_points_needed = -$total_points_needed;
            $stmt_points_history->bind_param("iis", $loggedInUserId, $negative_total_points_needed, $description_points_history);
            if (!$stmt_points_history->execute()) {
                throw new Exception("Gagal menyimpan riwayat poin: " . $stmt_points_history->error);
            }
            $stmt_points_history->close();

            // 4. Update stok reward (jika reward adalah produk fisik/memiliki stok)
            $stmt_update_stock = $conn->prepare("UPDATE rewards SET stock = stock - ? WHERE id = ? AND stock >= ?");
            if ($stmt_update_stock === false) {
                throw new Exception("Error preparing statement for reward stock update: " . $conn->error);
            }
            $stmt_update_stock->bind_param("iii", $jumlah, $checkout_reward_id, $jumlah);
            if (!$stmt_update_stock->execute()) {
                throw new Exception("Gagal mengurangi stok reward: " . $stmt_update_stock->error);
            }
            if ($stmt_update_stock->affected_rows === 0) {
                 // Jika stok tidak berkurang (misal: stok tidak cukup padahal di awal sudah dicek),
                 // ini bisa jadi kondisi race atau data tidak valid, perlu ditangani lebih lanjut.
                 throw new Exception("Gagal mengurangi stok reward. Stok mungkin tidak mencukupi.");
            }
            $stmt_update_stock->close();


            // Jika semua berhasil, commit transaksi
            $conn->commit();

            // Set flash message
            set_flash_message('success', 'Checkout berhasil! Pesanan Anda sedang diproses.');

            // Redirect ke halaman sukses checkout dengan ID penukaran dan user ID
            redirect('checkout_sukses.php?user_id=' . $loggedInUserId . '&exchange_id=' . $new_exchange_id);
            exit; // Penting untuk menghentikan eksekusi setelah redirect

        } catch (Exception $e) {
            // Jika ada kesalahan, rollback transaksi
            $conn->rollback();
            error_log("Transaksi checkout gagal: " . $e->getMessage());
            $error_messages[] = "Terjadi kesalahan saat memproses pesanan Anda. Poin Anda belum terpotong. Silakan coba lagi. (Detail: " . $e->getMessage() . ")";
        }
    }
}

// Tutup koneksi database jika belum ditutup
if ($conn && $conn->ping()) {
    $conn->close();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout Reward - Gorako</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .gradient-header {
            background: linear-gradient(90deg, #6b46c1, #805ad5); /* Purple gradient */
        }
        .btn-primary {
            background: linear-gradient(45deg, #805ad5, #a36cf3); /* Lighter purple gradient for buttons */
            transition: all 0.2s ease-in-out;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(128, 90, 213, 0.4);
        }
        .focus-ring {
            box-shadow: 0 0 0 3px rgba(128, 90, 213, 0.3);
        }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] {
            -moz-appearance: textfield;
        }
        /* Style for toast notification from flash messages */
        .flash-message-toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Poppins', sans-serif;
        }
        .flash-message-toast.show {
            opacity: 1;
            visibility: visible;
        }
        .flash-success { background-color: #10B981; color: white; } /* green-600 */
        .flash-error { background-color: #EF4444; color: white; } /* red-500 */
        .flash-info { background-color: #3B82F6; color: white; } /* blue-500 */
        .flash-warning { background-color: #F59E0B; color: white; } /* yellow-500 */
    </style>
</head>
<body class="bg-gray-100 min-h-screen antialiased">
    <header class="gradient-header text-white py-5 shadow-lg">
        <div class="container mx-auto px-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <span class="text-3xl">🛍️</span>
                <h1 class="text-3xl font-bold">Gorako</h1>
            </div>
            <p class="text-lg opacity-90">Checkout Reward Anda</p>
        </div>
    </header>

    <main class="px-4 py-10">
        <div class="max-w-7xl mx-auto">
            <div class="grid lg:grid-cols-2 gap-10">
                <section class="bg-white rounded-xl shadow-lg p-8">
                    <h2 class="text-2xl font-semibold mb-8 text-gray-800 flex items-center">
                        <svg class="w-6 h-6 mr-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                        Informasi Pengiriman
                    </h2>

                    <?php
                    // Tampilkan flash message jika ada
                    $flash = get_flash_message();
                    if ($flash):
                    ?>
                        <div id="php-flash-toast" class="flash-message-toast show flash-<?php echo htmlspecialchars($flash['type']); ?>" role="alert">
                            <span class="toast-icon">
                                <?php
                                    if ($flash['type'] === 'success') echo '✅';
                                    else if ($flash['type'] === 'error') echo '❌';
                                    else if ($flash['type'] === 'info') echo 'ℹ️';
                                    else if ($flash['type'] === 'warning') echo '⚠️';
                                ?>
                            </span>
                            <span class="toast-message"><?php echo htmlspecialchars($flash['message']); ?></span>
                        </div>
                    <?php
                    endif;
                    ?>

                    <?php if (!empty($error_messages)): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-5 py-4 rounded-lg relative mb-6 text-sm" role="alert">
                            <strong class="font-bold mr-2">Oops!</strong>
                            <span class="block sm:inline"><?php echo implode("<br>", $error_messages); ?></span>
                        </div>
                    <?php endif; ?>

                    <form id="checkoutForm" class="space-y-6" method="POST">
                        <?php if (!is_null($checkout_reward_id)): ?>
                            <input type="hidden" name="reward_id" value="<?php echo htmlspecialchars($checkout_reward_id); ?>">
                        <?php endif; ?>

                        <div>
                            <label for="nama" class="block text-sm font-medium text-gray-700 mb-2">
                                Nama Lengkap <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="nama"
                                name="nama"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus-ring transition-all placeholder-gray-400"
                                placeholder="Masukkan nama lengkap Anda"
                                value="<?php echo htmlspecialchars($nama); ?>"
                            >
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Aktif <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus-ring transition-all placeholder-gray-400"
                                placeholder="contoh@email.com"
                                value="<?php echo htmlspecialchars($email); ?>"
                            >
                            <p class="text-xs text-gray-500 mt-1">Email untuk pengiriman reward dan notifikasi</p>
                        </div>

                        <div>
                            <label for="nohp" class="block text-sm font-medium text-gray-700 mb-2">
                                Nomor HP Aktif <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="tel"
                                id="nohp"
                                name="nohp"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus-ring transition-all placeholder-gray-400"
                                placeholder="08xxxxxxxxxx atau +628xxxxxxxxxx"
                                value="<?php echo htmlspecialchars($nohp); ?>"
                            >
                            <p class="text-xs text-gray-500 mt-1">Nomor yang dapat dihubungi untuk konfirmasi</p>
                        </div>

                        <div>
                            <label for="jumlah" class="block text-sm font-medium text-gray-700 mb-2">
                                Jumlah Item <span class="text-red-500">*</span>
                            </label>
                            <div class="flex items-center space-x-3">
                                <button
                                    type="button"
                                    onclick="changeQuantity(-1)"
                                    class="w-10 h-10 bg-gray-200 hover:bg-gray-300 rounded-full flex items-center justify-center font-bold text-gray-700 text-lg transition-colors focus:outline-none focus-ring"
                                >
                                    -
                                </button>
                                <input
                                    type="number"
                                    id="jumlah"
                                    name="jumlah"
                                    value="<?php echo htmlspecialchars($jumlah); ?>"
                                    min="1"
                                    max="10"
                                    required
                                    class="w-24 px-3 py-2 border border-gray-300 rounded-lg text-center text-gray-800 font-medium focus:outline-none focus-ring"
                                >
                                <button
                                    type="button"
                                    onclick="changeQuantity(1)"
                                    class="w-10 h-10 bg-gray-200 hover:bg-gray-300 rounded-full flex items-center justify-center font-bold text-gray-700 text-lg transition-colors focus:outline-none focus-ring"
                                >
                                    +
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Maksimal 10 item per transaksi</p>
                        </div>

                        <div>
                            <label for="pesan" class="block text-sm font-medium text-gray-700 mb-2">
                                Pesan (Opsional)
                            </label>
                            <textarea
                                id="pesan"
                                name="pesan"
                                rows="4"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus-ring transition-all resize-none placeholder-gray-400"
                                placeholder="Tambahkan catatan atau permintaan khusus untuk pesanan Anda..."
                            ><?php echo htmlspecialchars($pesan); ?></textarea>
                        </div>

                        <button
                            type="submit"
                            id="submitButton"
                            class="w-full btn-primary text-white py-4 rounded-lg font-semibold text-lg shadow-md"
                        >
                            🎁 Proses Checkout Sekarang
                        </button>
                    </form>
                </section>

                <section class="bg-white rounded-xl shadow-lg p-8 h-fit lg:sticky lg:top-10">
                    <h2 class="text-2xl font-semibold mb-8 text-gray-800 flex items-center">
                        <svg class="w-6 h-6 mr-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Ringkasan Pesanan
                    </h2>

                    <div class="border-b border-gray-200 pb-6 mb-6">
                        <div class="flex items-center space-x-5 mb-4">
                            <?php if (!empty($reward_summary_image_url)): ?>
                                <img src="<?php echo $reward_summary_image_url; ?>" alt="<?php echo $reward_summary_title; ?>" class="w-28 h-28 object-cover rounded-xl shadow-md border border-gray-200">
                            <?php else: ?>
                                <div class="w-28 h-28 bg-gradient-to-br from-purple-400 to-pink-400 rounded-xl flex items-center justify-center text-5xl shadow-md text-white">
                                    <?php echo $reward_summary_icon; ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <h3 class="font-bold text-gray-800 text-xl mb-1"><?php echo $reward_summary_title; ?></h3>
                                <p class="text-sm text-gray-600 mb-2"><?php echo $reward_summary_description; ?></p>
                                <div class="flex items-center">
                                    <span class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full font-medium">✅ Reward Siap</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 mb-8">
                        <div class="flex justify-between items-center text-base">
                            <span class="text-gray-600">Jumlah Item:</span>
                            <span id="summaryQuantity" class="font-semibold text-gray-800"><?php echo htmlspecialchars($jumlah); ?> item</span>
                        </div>
                        <div class="flex justify-between items-center text-base">
                            <span class="text-gray-600">Poin Anda Saat Ini:</span>
                            <span class="font-semibold text-green-600"><?php echo htmlspecialchars($user_current_points); ?> 🪙 Poin</span>
                        </div>
                        <div class="flex justify-between items-center text-base">
                            <span class="text-gray-600">Poin Dibutuhkan per Item:</span>
                            <span class="font-semibold text-red-600"><?php echo htmlspecialchars($reward_points_needed); ?> 🪙 Poin</span>
                        </div>
                        <div class="flex justify-between items-center border-t border-gray-200 pt-4 mt-4">
                            <span class="text-gray-800 font-bold text-lg">Total Poin Dibayar:</span>
                            <span class="font-bold text-2xl text-purple-700" id="totalPointsPaid">
                                <?php echo htmlspecialchars($reward_points_needed * $jumlah); ?> 🪙 Poin
                            </span>
                        </div>
                        <div class="flex justify-between items-center text-lg">
                            <span class="text-gray-800 font-bold">Sisa Poin Anda:</span>
                            <span class="font-bold text-xl text-blue-600" id="remainingPoints">
                                <?php echo htmlspecialchars($user_current_points - ($reward_points_needed * $jumlah)); ?> 🪙 Poin
                            </span>
                        </div>
                        <div class="flex justify-between items-center text-base">
                            <span class="text-gray-600">Status Pesanan:</span>
                            <span class="text-blue-600 font-medium flex items-center">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Menunggu Konfirmasi
                            </span>
                        </div>
                        <div class="flex justify-between items-center text-base">
                            <span class="text-gray-600">Estimasi Pengiriman:</span>
                            <span class="font-medium text-gray-800 flex items-center">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                1-7 hari kerja
                            </span>
                        </div>
                    </div>

                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-5 mb-8">
                        <h4 class="font-semibold text-purple-800 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 0 00-.363 1.118l1.519 4.674c.3.921-.755 1.688-1.538 1.118l-3.976-2.888a1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.519-4.674a1 0 00-.363-1.118L2.928 8.127c-.783-.57-.381-1.81.588-1.81h4.915a1 0 00.95-.69l1.519-4.674z"></path></svg>
                            Keuntungan Reward Anda:
                        </h4>
                        <ul class="space-y-2 text-sm text-purple-700 list-disc pl-5">
                            <li>Prioritas customer service 24/7</li>
                            <li>Diskon khusus untuk pembelian produk/layanan di masa depan</li>
                        </ul>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 mb-8">
                        <div class="flex items-start space-x-3">
                            <svg class="w-6 h-6 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <div class="text-sm text-blue-700">
                                <p class="font-semibold mb-2">Penting: Informasi Pengiriman Reward</p>
                                <ul class="space-y-1 text-xs">
                                    <li>Reward digital akan dikirimkan ke email aktif yang Anda daftarkan.</li>
                                    <li>Mohon pastikan semua data yang Anda isi sudah benar dan valid.</li>
                                    <li>Jika ada kendala atau pertanyaan, jangan ragu hubungi Customer Service kami.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="inline-flex items-center space-x-2 text-sm text-gray-600 bg-gray-50 px-5 py-2 rounded-full border border-gray-200 shadow-sm">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.007 12.007 0 002.928 12c0 3.072 1.885 5.712 4.524 7.087.6.324 1.274.54 1.98.636.945.117 1.901.175 2.868.179l.001.001zm-1.042 1.487a2 2 0 10-1.004 3.464 2 2 0 001.004-3.464z"></path></svg>
                            <span>Transaksi Aman & Terpercaya</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <script>
        // Data poin dari PHP
        const REWARD_POINTS_NEEDED_PER_ITEM = <?php echo json_encode($reward_points_needed); ?>;
        const USER_CURRENT_POINTS = <?php echo json_encode($user_current_points); ?>;
        // Tambahkan variabel untuk stok saat ini
        const REWARD_CURRENT_STOCK = <?php echo json_encode($reward_current_stock ?? 0); ?>;


        // Update quantity
        function changeQuantity(change) {
            const quantityInput = document.getElementById('jumlah');
            let currentValue = parseInt(quantityInput.value);
            let newValue = currentValue + change;

            if (newValue >= 1 && newValue <= 10) {
                // Tambahkan validasi stok di sisi klien
                if (newValue > REWARD_CURRENT_STOCK) {
                    showToastMessage('Jumlah melebihi stok yang tersedia (' + REWARD_CURRENT_STOCK + ' item).', 'error');
                    quantityInput.value = REWARD_CURRENT_STOCK; // Set ke stok maksimal jika melebihi
                } else {
                    quantityInput.value = newValue;
                }
                updateSummary();
                checkPointsAndEnableSubmit();
            }
        }

        // Update summary display, including points
        function updateSummary() {
            const quantity = parseInt(document.getElementById('jumlah').value);
            const summaryQuantity = document.getElementById('summaryQuantity');
            const totalPointsPaidElement = document.getElementById('totalPointsPaid');
            const remainingPointsElement = document.getElementById('remainingPoints');

            const totalPointsPaid = REWARD_POINTS_NEEDED_PER_ITEM * quantity;
            const remainingPoints = USER_CURRENT_POINTS - totalPointsPaid;

            summaryQuantity.textContent = quantity + ' item';
            totalPointsPaidElement.textContent = totalPointsPaid + ' 🪙 Poin';
            remainingPointsElement.textContent = remainingPoints + ' 🪙 Poin';

            // Change color if remaining points are negative
            if (remainingPoints < 0) {
                remainingPointsElement.classList.add('text-red-600');
                remainingPointsElement.classList.remove('text-blue-600');
            } else {
                remainingPointsElement.classList.remove('text-red-600');
                remainingPointsElement.classList.add('text-blue-600');
            }
        }

        // Function to check points and enable/disable submit button
        function checkPointsAndEnableSubmit() {
            const submitButton = document.getElementById('submitButton');
            const quantity = parseInt(document.getElementById('jumlah').value);
            const totalPointsNeeded = REWARD_POINTS_NEEDED_PER_ITEM * quantity;

            // Pastikan tombol submit dinonaktifkan jika:
            // 1. Poin tidak mencukupi
            // 2. Jumlah melebihi stok yang tersedia
            if (USER_CURRENT_POINTS < totalPointsNeeded) {
                submitButton.disabled = true;
                submitButton.textContent = 'Poin Anda Tidak Cukup';
                submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                submitButton.classList.remove('hover:opacity-90', 'hover:scale-[1.02]');
            } else if (quantity > REWARD_CURRENT_STOCK) {
                submitButton.disabled = true;
                submitButton.textContent = 'Stok Reward Tidak Cukup';
                submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                submitButton.classList.remove('hover:opacity-90', 'hover:scale-[1.02]');
            }
            else {
                submitButton.disabled = false;
                submitButton.textContent = '🎁 Proses Checkout Sekarang';
                submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                submitButton.classList.add('hover:opacity-90', 'hover:scale-[1.02]');
            }
        }

        // Utility function for toast messages (copy from service_quiz.php)
        function showToastMessage(message, type = 'success', duration = 3000) {
            let toastElement = document.getElementById('checkoutToast');
            if (!toastElement) {
                toastElement = document.createElement('div');
                toastElement.id = 'checkoutToast';
                toastElement.className = 'flash-message-toast';
                document.body.appendChild(toastElement);
            }

            toastElement.innerHTML = `
                <span class="toast-icon">
                    ${type === 'success' ? '✅' : (type === 'error' ? '❌' : (type === 'info' ? 'ℹ️' : '⚠️'))}
                </span>
                <span class="toast-message-text">${message}</span>
            `;
            toastElement.classList.remove('flash-success', 'flash-error', 'flash-info', 'flash-warning');
            toastElement.classList.add(`flash-${type}`);
            toastElement.classList.add('show');

            clearTimeout(toastElement.timer);
            toastElement.timer = setTimeout(() => {
                toastElement.classList.remove('show');
            }, duration);
        }

        // Initialize on DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            updateSummary(); // Initial update of summary on page load
            checkPointsAndEnableSubmit(); // Initial check for button state

            // Update summary when quantity input changes (manual input)
            document.getElementById('jumlah').addEventListener('input', function() {
                // Ensure input value doesn't exceed REWARD_CURRENT_STOCK
                let currentVal = parseInt(this.value);
                if (currentVal > REWARD_CURRENT_STOCK) {
                    this.value = REWARD_CURRENT_STOCK;
                    showToastMessage('Jumlah tidak bisa melebihi stok yang tersedia (' + REWARD_CURRENT_STOCK + ' item).', 'error');
                } else if (currentVal < 1) {
                    this.value = 1; // Minimum 1
                }
                updateSummary();
                checkPointsAndEnableSubmit();
            });

            // Handle PHP flash message display
            const phpFlashToast = document.getElementById('php-flash-toast');
            if (phpFlashToast) {
                setTimeout(() => {
                    phpFlashToast.classList.remove('show');
                }, 4000); // Hide after 4 seconds
            }

            // Menambahkan listener untuk menonaktifkan tombol submit setelah form dikirim
            const checkoutForm = document.getElementById('checkoutForm');
            const submitButton = document.getElementById('submitButton');

            if (checkoutForm && submitButton) {
                checkoutForm.addEventListener('submit', function() {
                    // Only disable if currently enabled (not disabled by point/stock check)
                    if (!submitButton.disabled) {
                        submitButton.disabled = true;
                        submitButton.textContent = 'Memproses Pesanan...';
                        submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                        submitButton.classList.remove('hover:opacity-90', 'hover:scale-[1.02]');
                    }
                });
            }
        });
    </script>
</body>
</html>