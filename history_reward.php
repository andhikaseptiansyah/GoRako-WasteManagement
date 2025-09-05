<?php
// history_reward.php

// Pastikan db_connection.php di-include pertama kali untuk memulai sesi dan koneksi DB
require_once 'db_connection.php';
require_once 'helpers.php';

// Mendapatkan ID pengguna yang sedang login.
// Ini sangat penting! Dalam aplikasi nyata, user_id akan didapatkan dari sesi setelah login.
if (!is_logged_in()) {
    set_flash_message('error', 'Anda harus login untuk melihat riwayat penukaran.');
    redirect('login.php'); // Arahkan ke halaman login jika belum login
    exit; // Pastikan tidak ada eksekusi kode lebih lanjut
}
$loggedInUserId = $_SESSION['user_id']; // Gunakan ini di aplikasi nyata


// Ambil total poin pengguna dari database
$userPoints = 0;
$stmtUserPoints = $conn->prepare("SELECT total_points FROM users WHERE id = ?");
if ($stmtUserPoints) {
    $stmtUserPoints->bind_param("i", $loggedInUserId);
    $stmtUserPoints->execute();
    $stmtUserPoints->bind_result($points);
    $stmtUserPoints->fetch();
    $userPoints = $points;
    $stmtUserPoints->close();
} else {
    error_log("Gagal menyiapkan pernyataan untuk mengambil poin pengguna: " . $conn->error);
}

// Mengambil data riwayat penukaran (exchanges) dari database untuk user yang login
// Lakukan JOIN dengan tabel 'rewards' untuk mendapatkan detail reward seperti nama, icon, bg_gradient.
$exchanges = []; // Menggunakan nama variabel 'exchanges' agar lebih jelas
$sql = "SELECT
            e.id AS exchange_id,
            r.name AS reward_name,
            r.description, -- Deskripsi reward dari tabel rewards
            e.checkout_date AS exchange_date,
            r.points_needed AS points_spent, -- Poin yang dibutuhkan dari tabel rewards
            e.status,
            e.code_redeemed AS redeemed_code, -- MENGGUNAKAN KOLOM BARU INI
            r.icon,
            r.bg_gradient
        FROM
            exchanges e
        JOIN
            rewards r ON e.reward_id = r.id
        WHERE
            e.user_id = ?
        ORDER BY
            e.checkout_date DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $loggedInUserId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $exchanges[] = $row;
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan pernyataan untuk mengambil riwayat penukaran: " . $conn->error);
}

// Hitung statistik dari data yang sudah diambil (tetap ada di PHP meskipun tidak ditampilkan di HTML)
$successfulRedemptionsPoints = 0; // Total poin dari penukaran BERHASIL
$successfulRedemptionsCount = 0; // Jumlah transaksi penukaran yang berhasil
$totalPointsSpent = 0; // Total poin dari SEMUA penukaran (berhasil/gagal/diproses)

foreach ($exchanges as $exchange) {
    // Jika statusnya 'sent' atau 'delivered' (dianggap berhasil)
    if ($exchange['status'] === 'sent' || $exchange['status'] === 'delivered') {
        $successfulRedemptionsPoints += $exchange['points_spent']; // Akumulasi poin yang dihabiskan
        $successfulRedemptionsCount++; // Hitung jumlah transaksi berhasil
    }
    $totalPointsSpent += $exchange['points_spent']; // Akumulasi total poin yang dihabiskan secara keseluruhan
}

// Hitung rata-rata harga satuan jika ada penukaran berhasil
$averageUnitPrice = ($successfulRedemptionsCount > 0) ? ($successfulRedemptionsPoints / $successfulRedemptionsCount) : 0;


// Koneksi akan ditutup secara otomatis oleh register_shutdown_function di db_connection.php
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Penukaran Reward</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }

        .reward-card {
            transition: all 0.3s ease;
        }

        .reward-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .status-badge {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        /* Improved styling for redeemed code */
        .redeemed-code-display {
            background-color: #f3f4f6; /* Light gray background */
            border: 1px dashed #d1d5db; /* Dashed border */
            padding: 8px 12px;
            border-radius: 6px;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; /* Monospace font */
            color: #374151; /* Darker text color */
            font-size: 0.875rem; /* text-sm */
            display: inline-flex; /* Use inline-flex for better alignment with icon */
            align-items: center;
            gap: 6px; /* Space between icon and text */
            margin-top: 8px; /* mt-2 */
        }

        .redeemed-code-display .icon {
            color: #6b7280; /* Gray icon color */
        }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-50 to-blue-50 min-h-screen">
    <div class="bg-white shadow-lg">
        <div class="max-w-6xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div>
                    <a href="index.php" class="inline-flex items-center text-gray-600 hover:text-gray-800 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Kembali ke Beranda
                    </a>
                    <h1 class="text-3xl font-bold text-gray-800">Riwayat Penukaran Reward</h1>
                    <p class="text-gray-600 mt-1">Lihat semua reward yang telah Anda tukarkan</p>
                </div>
                <div class="bg-gradient-to-r from-purple-500 to-blue-500 text-white px-6 py-3 rounded-full">
                    <div class="text-center">
                        <div class="text-2xl font-bold"><?php echo number_format($userPoints); ?></div>
                        <div class="text-sm opacity-90">Total Poin</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-8">
        <div class="flex flex-wrap gap-3 mb-8">
            <button class="filter-btn active px-6 py-2 rounded-full bg-gray-200 text-gray-700 font-medium transition-all duration-300" onclick="filterRewards('semua')">
                Semua
            </button>
            <button class="filter-btn px-6 py-2 rounded-full bg-gray-200 text-gray-700 font-medium transition-all duration-300" onclick="filterRewards('berhasil')">
                Berhasil
            </button>
            <button class="filter-btn px-6 py-2 rounded-full bg-gray-200 text-gray-700 font-medium transition-all duration-300" onclick="filterRewards('diproses')">
                Diproses
            </button>
            <button class="filter-btn px-6 py-2 rounded-full bg-gray-200 text-gray-700 font-medium transition-all duration-300" onclick="filterRewards('gagal')">
                Gagal
            </button>
        </div>

        <div class="space-y-4" id="rewardsContainer">
            <?php if (!empty($exchanges)): // Loop melalui $exchanges ?>
                <?php foreach ($exchanges as $exchange): ?>
                    <?php
                    // Logika untuk badge status
                    $statusClass = '';
                    $statusText = '';
                    // Menyesuaikan status dari database (e.g., 'pending', 'approved', 'sent', 'delivered', 'rejected')
                    // ke tampilan 'berhasil', 'diproses', 'gagal'
                    $displayStatus = '';
                    switch ($exchange['status']) {
                        case 'sent':
                        case 'delivered':
                            $statusClass = 'bg-green-100 text-green-800';
                            $statusText = '✓ Berhasil';
                            $displayStatus = 'berhasil'; // Untuk filter JS
                            break;
                        case 'pending':
                        case 'approved': // Approved bisa dianggap masih dalam proses
                            $statusClass = 'bg-yellow-100 text-yellow-800';
                            $statusText = '⏳ Diproses';
                            $displayStatus = 'diproses'; // Untuk filter JS
                            break;
                        case 'rejected':
                            $statusClass = 'bg-red-100 text-red-800';
                            $statusText = '✗ Ditolak'; // Ubah teks untuk status 'rejected'
                            $displayStatus = 'gagal'; // Untuk filter JS
                            break;
                        default:
                            $statusClass = 'bg-gray-100 text-gray-700';
                            $statusText = 'Status Tidak Dikenal';
                            $displayStatus = 'lainnya'; // Untuk filter JS
                            break;
                    }
                    ?>
                    <div class="reward-card bg-white rounded-xl p-6 shadow-md border border-gray-100" data-status="<?php echo clean_input($displayStatus); ?>">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <div class="w-16 h-16 bg-gradient-to-br <?php echo clean_input($exchange['bg_gradient']); ?> rounded-xl flex items-center justify-center text-2xl">
                                    <?php echo clean_input($exchange['icon']); ?>
                                </div>
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-800"><?php echo clean_input($exchange['reward_name']); ?></h3>
                                    <p class="text-gray-600"><?php echo clean_input($exchange['description']); ?></p>
                                    <div class="flex items-center space-x-4 mt-2">
                                        <span class="text-sm text-gray-500"><?php echo date('d F Y', strtotime($exchange['exchange_date'])); ?></span>
                                        </div>
                                    <?php if (!empty($exchange['redeemed_code'])): ?>
                                        <div class="redeemed-code-display">
                                            <span class="icon">✨</span>
                                            <span>Kode Diterima: <?php echo clean_input($exchange['redeemed_code']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="status-badge inline-block px-3 py-1 <?php echo $statusClass; ?> text-sm font-medium rounded-full">
                                    <?php echo $statusText; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-center text-gray-600 text-lg mt-10">Tidak ada riwayat penukaran reward.</p>
            <?php endif; ?>
        </div>

        </div>

    <script>
        function filterRewards(status) {
            const cards = document.querySelectorAll('.reward-card');
            const buttons = document.querySelectorAll('.filter-btn');

            // Update active button
            buttons.forEach(btn => btn.classList.remove('active'));
            // Find the button that was clicked based on its status, or the 'Semua' button
            let clickedButton = Array.from(buttons).find(btn => {
                const buttonText = btn.textContent.trim().toLowerCase();
                const targetStatus = status.toLowerCase();

                if (targetStatus === 'berhasil') {
                    return buttonText === 'berhasil';
                } else if (targetStatus === 'diproses') {
                    return buttonText === 'diproses';
                } else if (targetStatus === 'gagal') {
                    return buttonText === 'gagal';
                } else if (targetStatus === 'semua') {
                    return buttonText === 'semua';
                }
                return false;
            });

            if (clickedButton) {
                clickedButton.classList.add('active');
            } else {
                // Fallback to "Semua" button if no matching filter button is found (e.g., if 'lainnya' status exists)
                Array.from(buttons).find(btn => btn.textContent.trim().toLowerCase() === 'semua').classList.add('active');
            }

            // Filter cards
            cards.forEach(card => {
                if (status === 'semua') {
                    card.style.display = 'block';
                } else {
                    if (card.dataset.status === status) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                }
            });
        }

        // Add click animation to cards
        document.querySelectorAll('.reward-card').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = 'translateY(-2px)';
                }, 100);
            });
        });

        // Call filterRewards on page load to ensure "Semua" is active and all cards are shown
        document.addEventListener('DOMContentLoaded', () => {
            filterRewards('semua');
        });
    </script>
</body>
</html>