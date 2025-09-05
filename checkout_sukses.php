<?php
// checkout_sukses.php (sekarang menangani POST dan GET)
require_once 'db_connection.php';
require_once 'helpers.php';

// Pastikan pengguna sudah login
if (!is_logged_in()) {
    set_flash_message('error', 'Anda harus login untuk mengakses halaman ini.');
    redirect('login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$exchange_data = null; // Inisialisasi data penukaran

// --- Bagian Pemrosesan Checkout (hanya jika request adalah POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validasi dan ambil data dari POST
    $reward_id = isset($_POST['reward_id']) ? intval($_POST['reward_id']) : 0;
    $nama_penerima = isset($_POST['nama_penerima']) ? clean_input($_POST['nama_penerima']) : '';
    $email_penerima = isset($_POST['email_penerima']) ? clean_input($_POST['email_penerima']) : '';
    $nohp_penerima = isset($_POST['nohp_penerima']) ? clean_input($_POST['nohp_penerima']) : '';
    $jumlah_item = isset($_POST['jumlah_item']) ? intval($_POST['jumlah_item']) : 1;
    $pesan_tambahan = isset($_POST['pesan_tambahan']) ? clean_input($_POST['pesan_tambahan']) : '';

    // 2. Dapatkan detail reward untuk mengetahui berapa poin yang dibutuhkan
    $points_needed = 0;
    $stmt_reward = $conn->prepare("SELECT points_needed FROM rewards WHERE id = ?");
    if ($stmt_reward) {
        $stmt_reward->bind_param("i", $reward_id);
        $stmt_reward->execute();
        $stmt_reward->bind_result($needed);
        $stmt_reward->fetch();
        $points_needed = $needed;
        $stmt_reward->close();
    }

    if ($points_needed <= 0) {
        set_flash_message('error', 'Reward tidak valid atau poin yang dibutuhkan tidak ditemukan.');
        redirect('/rewards.php'); // Redirect ke halaman daftar reward Anda
        exit;
    }

    // 3. Periksa apakah pengguna memiliki cukup poin
    $user_current_points_before_deduct = 0; // Simpan poin sebelum dikurangi untuk tampilan
    $stmt_user_points = $conn->prepare("SELECT total_points FROM users WHERE id = ?");
    if ($stmt_user_points) {
        $stmt_user_points->bind_param("i", $user_id);
        $stmt_user_points->execute();
        $stmt_user_points->bind_result($current_points);
        $stmt_user_points->fetch();
        $user_current_points_before_deduct = $current_points;
        $stmt_user_points->close();
    }

    if ($user_current_points_before_deduct < $points_needed) {
        set_flash_message('error', 'Poin Anda tidak cukup untuk menukarkan reward ini.');
        redirect('/rewards.php'); // Redirect ke halaman daftar reward Anda
        exit;
    }

    // 4. Mulai transaksi database
    $conn->begin_transaction();
    $new_exchange_id = null; // Untuk menyimpan ID penukaran baru

    try {
        // 5. Kurangi poin pengguna
        $sql_update_user_points = "UPDATE users SET total_points = total_points - ? WHERE id = ?";
        $stmt_update_user = $conn->prepare($sql_update_user_points);
        if (!$stmt_update_user) {
            throw new Exception("Gagal menyiapkan statement update poin: " . $conn->error);
        }
        $stmt_update_user->bind_param("ii", $points_needed, $user_id);
        if (!$stmt_update_user->execute()) {
            throw new Exception("Gagal mengurangi poin pengguna: " . $stmt_update_user->error);
        }
        $stmt_update_user->close();

        // 6. Masukkan data penukaran ke tabel exchanges
        $sql_insert_exchange = "INSERT INTO exchanges (user_id, reward_id, nama_penerima, email_penerima, nohp_penerima, jumlah_item, pesan_tambahan, checkout_date, points_used, status, estimated_delivery, code_redeemed)
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'pending', ?, ?)";
        $stmt_insert_exchange = $conn->prepare($sql_insert_exchange);

        if (!$stmt_insert_exchange) {
            throw new Exception("Gagal menyiapkan statement insert penukaran: " . $conn->error);
        }

        $estimated_delivery = '1-2 hari kerja';
        $redeemed_code = 'N/A'; // Atau generate kode unik jika ada

        $stmt_insert_exchange->bind_param("iisssiisis", $user_id, $reward_id, $nama_penerima, $email_penerima, $nohp_penerima, $jumlah_item, $pesan_tambahan, $points_needed, $estimated_delivery, $redeemed_code);

        if (!$stmt_insert_exchange->execute()) {
            throw new Exception("Gagal menyimpan data penukaran: " . $stmt_insert_exchange->error);
        }
        $new_exchange_id = $conn->insert_id; // Ambil ID penukaran yang baru dibuat
        $stmt_insert_exchange->close();

        $conn->commit(); // Commit transaksi jika semua berhasil
        set_flash_message('success', 'Penukaran poin berhasil! Detail penukaran Anda sedang diproses.');

        // Set exchange_id untuk tampilan di bawah
        $_GET['exchange_id'] = $new_exchange_id; // Simulasikan GET parameter agar logika tampilan di bawah tetap jalan

    } catch (Exception $e) {
        $conn->rollback(); // Rollback transaksi jika terjadi kesalahan
        error_log("Kesalahan proses checkout: " . $e->getMessage());
        set_flash_message('error', 'Terjadi kesalahan saat memproses penukaran: ' . $e->getMessage() . '. Mohon coba lagi.');
        redirect('/rewards.php'); // Redirect kembali ke halaman reward atau keranjang
        exit;
    }
}

// --- Bagian Tampilan Halaman Sukses (akan dieksekusi setelah POST atau jika diakses via GET) ---

// Ambil exchange_id dari parameter URL (setelah POST, ini sudah diset di atas)
$exchange_id = isset($_GET['exchange_id']) ? clean_input($_GET['exchange_id']) : null;

// Jika exchange_id tidak ada atau tidak valid, dan bukan dari POST yang berhasil, redirect
if (!$exchange_id || !is_numeric($exchange_id)) {
    set_flash_message('error', 'ID penukaran tidak valid atau transaksi belum selesai.');
    redirect('/dashboard.php'); // Atau halaman riwayat penukaran
    exit;
}
$exchange_id = intval($exchange_id); // Pastikan menjadi integer setelah dibersihkan

// Query untuk mengambil data penukaran dan pengguna
$sql = "SELECT
            u.name AS user_name,
            u.email AS user_email,
            u.total_points AS user_current_points,
            e.reward_id,
            e.nama_penerima,
            e.email_penerima,
            e.nohp_penerima,
            e.jumlah_item,
            e.pesan_tambahan,
            e.checkout_date,
            e.points_used,
            e.status AS exchange_status,
            e.estimated_delivery,
            r.name AS reward_name,
            r.description AS reward_description
        FROM
            exchanges e
        JOIN
            users u ON e.user_id = u.id
        JOIN
            rewards r ON e.reward_id = r.id
        WHERE
            e.id = ? AND e.user_id = ?"; // Memastikan exchange milik user yang login

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Gagal menyiapkan statement di checkout_sukses.php: " . $conn->error);
    set_flash_message('error', 'Terjadi kesalahan sistem saat mengambil data penukaran. Mohon coba lagi nanti.');
    redirect('/dashboard.php');
    exit;
}

$stmt->bind_param("ii", $exchange_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $exchange_data = $result->fetch_assoc();
} else {
    set_flash_message('warning', 'Data penukaran tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('/dashboard.php');
    exit;
}

$stmt->close();

$formatted_exchange_date = date('d F Y', strtotime($exchange_data['checkout_date']));

// HTML untuk tampilan halaman sukses (sisa kode HTML dari file checkout_sukses.php Anda)
// ... (Masukkan semua kode HTML dari checkout_sukses.php di sini)
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penukaran Berhasil - EcoLearn</title>
    <meta name="description" content="Penukaran poin EcoLearn berhasil! Sertifikat Eco Warrior Anda sedang diproses. Pelajari detail penukaran dan langkah selanjutnya.">
    <meta property="og:title" content="Penukaran Berhasil - EcoLearn">
    <meta property="og:description" content="Selamat! Poin EcoLearn Anda berhasil ditukar dengan Sertifikat Eco Warrior.">
    <meta property="og:image" content="[URL_GAMBAR_UNTUK_SHARE_MISALNYYA_LOGO_ANDA]">
    <meta property="og:url" content="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"); ?>">
    <meta property="og:type" content="website">

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Poppins', sans-serif;
        }

        .success-animation {
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        .leaf-pattern {
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2322c55e' fill-opacity='0.1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        .recycle-icon {
            animation: rotate 3s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Custom CSS Variables for Consistency */
        :root {
            --color-ecolearn-green: #22c55e; /* green-500 */
            --color-ecolearn-dark-green: #16a34a; /* green-600 (used in gradient) */
            --color-ecolearn-primary-text: #1f2937; /* gray-800/900 equivalent */
            --color-ecolearn-secondary-text: #6b7280; /* gray-600/700 equivalent */
            --color-ecolearn-blue: #3b82f6; /* blue-500 */
            --color-ecolearn-orange: #f97316; /* orange-600 */
            --color-ecolearn-yellow: #eab308; /* yellow-500 */
            --color-ecolearn-red: #ef4444; /* red-500 */
        }
        .bg-green-500 { background-color: var(--color-ecolearn-green); }
        .text-green-500 { color: var(--color-ecolearn-green); }
        .border-green-500 { border-color: var(--color-ecolearn-green); }
        .bg-green-600 { background-color: var(--color-ecolearn-dark-green); }
        .hover\:bg-green-600:hover { background-color: var(--color-ecolearn-dark-green); }
        .text-gray-800 { color: var(--color-ecolearn-primary-text); }
        .text-gray-600 { color: var(--color-ecolearn-secondary-text); }
        .text-orange-600 { color: var(--color-ecolearn-orange); }
        .text-yellow-800 { color: var(--color-ecolearn-yellow); }

        /* Toast Notification Styling */
        .toast-notification {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: #333;
            color: white;
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
            font-family: 'Poppins', sans-serif; /* Consistent font for toast */
        }
        .toast-notification.show {
            opacity: 1;
            visibility: visible;
        }

        /* Active state for buttons */
        .btn-active:active {
            transform: translateY(1px);
            box-shadow: none;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-green-50 to-blue-50 min-h-screen leaf-pattern">

    <main class="max-w-4xl mx-auto px-4 py-8">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden border-t-4 border-green-500">
            <div class="bg-gradient-to-r from-green-500 to-green-600 px-8 py-6 text-center">
                <div class="success-animation inline-block" aria-hidden="true">
                    <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-12 h-12 text-green-500" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                        </svg>
                    </div>
                </div>
                <h2 class="text-3xl font-bold text-white mb-2">Penukaran Berhasil! 🎉</h2>
                <p class="text-green-100 text-lg">Selamat! Poin Anda telah berhasil ditukar</p>
                <p class="text-white mt-2 font-medium" id="user-greeting">Halo, <?php echo htmlspecialchars($exchange_data['user_name']); ?>!</p>
            </div>

            <div class="px-8 py-6">
                <div class="bg-gradient-to-r from-blue-50 to-green-50 rounded-xl p-6 mb-6 border-l-4 border-blue-500">
                    <div class="flex items-start space-x-4">
                        <div class="w-16 h-16 bg-blue-500 rounded-xl flex items-center justify-center flex-shrink-0" aria-hidden="true">
                            <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($exchange_data['reward_name']); ?></h3>
                            <p class="text-gray-600 mb-3">Sertifikat penghargaan untuk kontribusi Anda dalam edukasi pengelolaan sampah dan pelestarian lingkungan.</p>
                            <div class="flex items-center space-x-4 text-sm">
                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full font-medium">
                                    📧 Dikirim via Email
                                </span>
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full font-medium">
                                    🏆 Level: Advanced
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-gray-50 rounded-xl p-4">
                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-orange-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12,2A3,3 0 0,1 15,5V11A3,3 0 0,1 12,14A3,3 0 0,1 9,11V5A3,3 0 0,1 12,2M19,11C19,14.53 16.39,17.44 13,17.93V21H11V17.93C7.61,17.44 5,14.53 5,11H7A5,5 0 0,0 12,16A5,5 0 0,0 17,11H19Z"/>
                            </svg>
                            Detail Penukaran
                        </h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Poin Digunakan:</span>
                                <span class="font-semibold text-orange-600" id="points-used"><?php echo htmlspecialchars($exchange_data['points_used']); ?> Poin</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Sisa Poin:</span>
                                <span class="font-semibold text-green-600" id="remaining-points"><?php echo htmlspecialchars($exchange_data['user_current_points']); ?> Poin <span class="text-gray-500 text-xs cursor-help" title="Poin dapat digunakan untuk menukar reward lainnya.">ⓘ</span></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Tanggal:</span>
                                <span class="font-semibold" id="exchange-date"><?php echo $formatted_exchange_date; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4">
                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20,8L12,13L4,8V6L12,11L20,6M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4Z"/>
                            </svg>
                            Informasi Pengiriman
                        </h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Email:</span>
                                <span class="font-semibold" id="user-email"><?php echo htmlspecialchars($exchange_data['email_penerima']); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Status:</span>
                                <?php
                                $status_text = '';
                                $status_class = '';
                                $status_icon = '';

                                switch ($exchange_data['exchange_status']) {
                                    case 'pending':
                                        $status_text = 'Menunggu Persetujuan Admin';
                                        $status_class = 'text-orange-600';
                                        $status_icon = '<svg class="animate-spin h-4 w-4 mr-2 text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                                        break;
                                    case 'approved':
                                        $status_text = 'Disetujui, Menunggu Pengiriman';
                                        $status_class = 'text-blue-600';
                                        $status_icon = '<svg class="h-4 w-4 mr-2 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
                                        break;
                                    case 'sent':
                                        $status_text = 'Terkirim via Email';
                                        $status_class = 'text-green-600';
                                        $status_icon = '<svg class="h-4 w-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>';
                                        break;
                                    case 'rejected':
                                        $status_text = 'Ditolak';
                                        $status_class = 'text-red-600';
                                        $status_icon = '<svg class="h-4 w-4 mr-2 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg>';
                                        break;
                                    default:
                                        $status_text = 'Tidak Diketahui';
                                        $status_class = 'text-gray-600';
                                        $status_icon = '';
                                        break;
                                }
                                ?>
                                <span class="font-semibold <?php echo $status_class; ?> flex items-center" id="delivery-status" aria-live="polite">
                                    <?php echo $status_icon; ?>
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Estimasi:</span>
                                <span class="font-semibold" id="estimated-delivery"><?php echo htmlspecialchars($exchange_data['estimated_delivery']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6">
                    <h4 class="font-semibold text-yellow-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M13,14H11V10H13M13,18H11V16H13M1,21H23L12,2L1,21Z"/>
                        </svg>
                        Langkah Selanjutnya
                    </h4>
                    <ul class="space-y-2 text-sm text-yellow-700">
                        <li class="flex items-start">
                            <span class="w-2 h-2 bg-yellow-500 rounded-full mt-2 mr-3 flex-shrink-0" aria-hidden="true"></span>
                            Sertifikat akan dikirim ke email Anda setelah disetujui admin, dalam 1-2 hari kerja.
                        </li>
                        <li class="flex items-start">
                            <span class="w-2 h-2 bg-yellow-500 rounded-full mt-2 mr-3 flex-shrink-0" aria-hidden="true"></span>
                            Periksa folder spam atau folder promosi jika tidak menerima email setelah estimasi waktu.
                        </li>
                        <li class="flex items-start">
                            <span class="w-2 h-2 bg-yellow-500 rounded-full mt-2 mr-3 flex-shrink-0" aria-hidden="true"></span>
                            Status pengiriman akan diperbarui di halaman ini atau dashboard Anda.
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col sm:flex-row gap-4">
                    <button id="back-to-dashboard-btn" class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-xl transition-colors duration-200 flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50 btn-active">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z"/>
                        </svg>
                        Kembali ke Beranda
                    </button>
                    <button id="download-proof-btn" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-6 rounded-xl transition-colors duration-200 flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 btn-active">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z M12,19L8,15H10.5V12H13.5V15H16L12,19Z"/>
                        </svg>
                        Unduh Bukti Penukaran
                    </button>
                </div>
            </div>
        </div>

        <div class="mt-8 text-center">
            <div class="bg-white rounded-xl p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">🌱 Siap berkontribusi lebih jauh? Dapatkan lebih banyak poin sekarang!</h3>
                <p class="text-gray-600 mb-4">Jelajahi berbagai aktivitas seperti mengikuti kuis, menonton video edukasi, dan berbagi tips pengelolaan sampah untuk mendapatkan lebih banyak poin.</p>
                <div class="flex justify-center space-x-6 text-sm">
                    <div class="text-center">
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-2" aria-hidden="true">
                            <span class="text-2xl">♻️</span>
                        </div>
                        <span class="text-gray-600">Daur Ulang</span>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-2" aria-hidden="true">
                            <span class="text-2xl">🌍</span>
                        </div>
                        <span class="text-gray-600">Lingkungan</span>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-2" aria-hidden="true">
                            <span class="text-2xl">🏆</span>
                        </div>
                        <span class="text-gray-600">Prestasi</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php $flash = get_flash_message(); ?>
    <?php if ($flash): ?>
        <div id="toast-notification" class="toast-notification show" role="status" aria-live="polite"
             style="background-color: <?php
                if ($flash['type'] === 'success') echo 'var(--color-ecolearn-dark-green)';
                else if ($flash['type'] === 'error') echo 'var(--color-ecolearn-red)';
                else if ($flash['type'] === 'info') echo 'var(--color-ecolearn-blue)';
                else if ($flash['type'] === 'warning') echo 'var(--color-ecolearn-yellow)';
                else echo '#333'; // default
            ?>;">
            <span id="toast-icon">
                <?php
                    if ($flash['type'] === 'success') echo '✅';
                    else if ($flash['type'] === 'error') echo '❌';
                    else if ($flash['type'] === 'info') echo 'ℹ️';
                    else if ($flash['type'] === 'warning') echo '⚠️';
                ?>
            </span>
            <span id="toast-message"><?php echo htmlspecialchars($flash['message']); ?></span>
        </div>
    <?php endif; ?>

    <script>
        // Function to show custom toast notification (now only for JS-triggered messages like download proof)
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast-notification');
            const toastMessage = document.getElementById('toast-message');
            const toastIcon = document.getElementById('toast-icon');

            // Set message and icon
            toastMessage.textContent = message;
            // Clear existing type classes to allow dynamic styling
            toast.className = 'toast-notification';

            if (type === 'success') {
                toast.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-dark-green');
                toastIcon.innerHTML = '✅';
            } else if (type === 'error') {
                toast.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-red');
                toastIcon.innerHTML = '❌';
            } else if (type === 'info') {
                toast.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-blue');
                toastIcon.innerHTML = 'ℹ️';
            } else if (type === 'warning') {
                toast.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-yellow');
                toastIcon.innerHTML = '⚠️';
            }

            // Show the toast
            toast.classList.add('show');

            // Hide after 4 seconds
            setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // Function to generate and download the proof image
        async function downloadProof() {
            const downloadBtn = document.getElementById('download-proof-btn');
            const originalBtnText = downloadBtn.innerHTML;

            // Add loading state to button
            downloadBtn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Mengunduh...';
            downloadBtn.disabled = true; // Disable button during download

            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                // Set canvas size
                canvas.width = 800;
                canvas.height = 600;

                // Background gradient (using CSS variables for consistency)
                const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
                gradient.addColorStop(0, getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-dark-green'));
                gradient.addColorStop(1, getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-blue'));
                ctx.fillStyle = gradient;
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Add decorative pattern
                ctx.fillStyle = 'rgba(255, 255, 255, 0.1)';
                for (let i = 0; i < 20; i++) {
                    ctx.beginPath();
                    ctx.arc(Math.random() * canvas.width, Math.random() * canvas.height, Math.random() * 30 + 10, 0, 2 * Math.PI);
                    ctx.fill();
                }

                // White background card
                ctx.fillStyle = 'white';
                ctx.roundRect = function(x, y, w, h, r) {
                    this.beginPath();
                    this.moveTo(x + r, y);
                    this.lineTo(x + w - r, y);
                    this.quadraticCurveTo(x + w, y, x + w, y + r);
                    this.lineTo(x + w, y + h - r);
                    this.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
                    this.lineTo(x + r, y + h);
                    this.quadraticCurveTo(x, y + h, x, y + h - r);
                    this.lineTo(x, y + r);
                    this.quadraticCurveTo(x, y, x + r, y);
                    this.closePath();
                    this.fill();
                };
                ctx.roundRect(50, 50, canvas.width - 100, canvas.height - 100, 20);

                // Success icon (checkmark circle)
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-dark-green');
                ctx.beginPath();
                ctx.arc(canvas.width / 2, 150, 40, 0, 2 * Math.PI);
                ctx.fill();

                // Checkmark
                ctx.strokeStyle = 'white';
                ctx.lineWidth = 6;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.beginPath();
                ctx.moveTo(canvas.width / 2 - 20, 150);
                ctx.lineTo(canvas.width / 2 - 5, 165);
                ctx.lineTo(canvas.width / 2 + 20, 135);
                ctx.stroke();

                // Title
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-primary-text');
                ctx.font = 'bold 36px "Poppins", sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('BUKTI PENUKARAN BERHASIL', canvas.width / 2, 240);

                // Subtitle
                ctx.font = '24px "Poppins", sans-serif';
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-secondary-text');
                ctx.fillText('🎉 Selamat! Poin Anda telah berhasil ditukar', canvas.width / 2, 280);

                // Reward info
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-primary-text');
                ctx.font = 'bold 28px "Poppins", sans-serif';
                ctx.fillText(document.querySelector('.bg-gradient-to-r.from-blue-50 h3').textContent, canvas.width / 2, 340);

                ctx.font = '18px "Poppins", sans-serif';
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-secondary-text');
                ctx.fillText('Sertifikat penghargaan untuk kontribusi dalam edukasi sampah', canvas.width / 2, 370);

                // Transaction details (Dynamically retrieved from DOM)
                ctx.textAlign = 'left';
                ctx.font = 'bold 20px "Poppins", sans-serif';
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-primary-text');
                ctx.fillText('Detail Penukaran:', 100, 420);

                ctx.font = '16px "Poppins", sans-serif';
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-secondary-text');
                ctx.fillText(`• Poin Digunakan: ${document.getElementById('points-used').textContent}`, 120, 450);
                ctx.fillText(`• Sisa Poin: ${document.getElementById('remaining-points').textContent}`, 120, 475);
                ctx.fillText(`• Tanggal: ${document.getElementById('exchange-date').textContent}`, 120, 500);
                ctx.fillText(`• Status: ${document.getElementById('delivery-status').textContent.trim()}`, 120, 525);

                // EcoLearn branding
                ctx.textAlign = 'center';
                ctx.font = 'bold 16px "Poppins", sans-serif';
                ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-ecolearn-dark-green');
                ctx.fillText('EcoLearn - Platform Edukasi Sampah', canvas.width / 2, 570);

                // Convert canvas to blob and download
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'bukti-penukaran-ecolearn.jpg';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                showToast('Bukti penukaran berhasil diunduh! Silakan cek folder unduhan Anda.', 'success');
            } catch (error) {
                console.error("Failed to download proof:", error);
                showToast('Gagal mengunduh bukti penukaran. Silakan coba lagi.', 'error');
            } finally {
                downloadBtn.innerHTML = originalBtnText; // Restore original button text
                downloadBtn.disabled = false; // Re-enable button
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Flash message handling for PHP-set messages
            const phpFlashToast = document.getElementById('toast-notification');
            if (phpFlashToast && phpFlashToast.classList.contains('show')) {
                // If a PHP flash message is displayed, it will hide itself after 4 seconds due to the CSS transition.
                // No need for a JS setTimeout here as it's already handled by the initial 'show' class.
            }

            // Event Listener for "Kembali ke Beranda" button (previously "Kembali ke Dashboard")
            document.getElementById('back-to-dashboard-btn').addEventListener('click', function() {
                window.location.href = 'index.php'; // Ganti dengan path index.php
            });

            // Add hover and active effects to cards
            const cards = document.querySelectorAll('.bg-white, .bg-gray-50');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.transition = 'transform 0.2s ease, box-shadow 0.2s ease';
                    this.style.boxShadow = '0 6px 15px rgba(0,0,0,0.1)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)';
                });
            });

            // Attach event listener to download button
            document.getElementById('download-proof-btn').addEventListener('click', downloadProof);
        });
    </script>
</body>
</html>