<?php
// leaderboard.php (Diperbarui dengan Warna Hijau Gelap, Responsivitas, dan Peningkatan Desain)

// Memulai sesi jika belum ada. Penting untuk mengakses $_SESSION.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Membutuhkan file koneksi database dan fungsi helper.
// Pastikan kedua file ini ada dan path-nya benar.
require_once 'db_connection.php'; // Berisi $conn = new mysqli(...);
require_once 'helpers.php';       // Berisi fungsi seperti is_logged_in() dan redirect()

// Memeriksa apakah pengguna sudah login. Jika tidak, arahkan ke halaman login.
if (!is_logged_in()) {
    redirect('login.php');
}

// Mendapatkan ID pengguna saat ini dari sesi. Default ke 0 jika tidak ditemukan.
$currentUserId = $_SESSION['user_id'] ?? 0;

// Mengatur zona waktu ke Asia/Jakarta untuk konsistensi waktu.
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk menentukan gelar berdasarkan level
function getRankTitleByLevel($level) {
    if ($level >= 1 && $level <= 10) {
        return 'Detektif Sampah Junior';
    } elseif ($level >= 11 && $level <= 25) {
        return 'Pahlawan Daur Ulang';
    } elseif ($level >= 26 && $level <= 50) {
        return 'Master Pengelola Sampah';
    } elseif ($level >= 51 && $level <= 100) {
        return 'Pemimpin Revolusi Hijau';
    } elseif ($level >= 101) {
        return 'Master Daur Ulang Nusantara';
    } else {
        return 'Warga GoRako'; // Gelar default jika di luar rentang
    }
}

// Mengambil data pengguna untuk leaderboard.
// Urutkan berdasarkan total_points DESC (tertinggi ke terendah)
// Kemudian, jika total_points sama, urutkan berdasarkan weekly_points DESC
$leaderboardUsers = [];
// KOLOM 'level' akan dihitung secara dinamis, tidak lagi diambil dari DB.
$sql = "SELECT id, username, weekly_points, total_points, profile_picture FROM users ORDER BY total_points DESC, weekly_points DESC LIMIT 10";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $leaderboardUsers[] = $row;
    }
}

// Memisahkan 3 pengguna teratas untuk tampilan khusus.
$top3Users = array_slice($leaderboardUsers, 0, 3);
// Sisa pengguna untuk ditampilkan di tabel.
$otherUsers = array_slice($leaderboardUsers, 3);

// Mengambil data pengguna saat ini dari database.
$currentUserData = null;
if ($currentUserId > 0) {
    // Menggunakan prepared statement untuk keamanan (melindungi dari SQL injection).
    // KOLOM 'level' akan dihitung secara dinamis, tidak lagi diambil dari DB.
    $stmt = $conn->prepare("SELECT username, weekly_points, total_points FROM users WHERE id = ?");
    $stmt->bind_param("i", $currentUserId); // 'i' untuk integer
    $stmt->execute();
    $resultCurrentUser = $stmt->get_result();
    if ($resultCurrentUser->num_rows > 0) {
        $currentUserData = $resultCurrentUser->fetch_assoc();
    }
    $stmt->close(); // Menutup statement
}

// Mengambil jumlah TOTAL peserta dari tabel users
$totalParticipants = 0;
$sqlTotalUsers = "SELECT COUNT(id) FROM users";
$resultTotalUsers = $conn->query($sqlTotalUsers);
if ($resultTotalUsers && $resultTotalUsers->num_rows > 0) {
    $rowTotalUsers = $resultTotalUsers->fetch_row();
    $totalParticipants = $rowTotalUsers[0];
}

// Menutup koneksi database.
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard - GoRako</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        body { 
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(to bottom right, #0A332B, #1B4F4A); /* Gradasi hijau gelap ke hijau kebiruan gelap */
            min-height: 100vh;
            color: #fff;
            padding: 1rem;
        }

        /* --- Warna Baru untuk Tema Hijau Gelap --- */
        .bg-green-dark-start { background-color: #0A332B; }
        .bg-green-dark-end { background-color: #1B4F4A; }
        .bg-teal-accent { background-color: #00BF8F; } /* Aksen teal cerah */
        .text-green-accent { color: #00BF8F; }
        .border-green-accent { border-color: #00BF8F; }

        /* General element styles for responsiveness */
        h1, h2 { font-family: 'Nunito', sans-serif; } /* Nunito untuk judul agar lebih bulat */

        /* Hall of Fame specific styles */
        .hof-card {
            background: rgba(255, 255, 255, 0.08); /* Sedikit lebih transparan */
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 1.25rem; /* Slightly reduced padding for mobile */
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2), 0 0 15px rgba(255, 255, 255, 0.05); /* Shadow lebih gelap */
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
            margin-bottom: 1rem; /* Spacing between cards on mobile */
            padding-top: 70px; /* **Diperbarui:** Tambahkan padding atas yang lebih banyak untuk ikon juara/medali */
        }

        .hof-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3), 0 0 20px rgba(255, 255, 255, 0.1);
        }

        .hof-card.champion {
            background: linear-gradient(to bottom right, #FFD700, #FFA500); /* Tetap emas */
            color: #333;
            transform: scale(1.1);
            z-index: 2;
            /* Efek glow tambahan untuk champion */
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 25px rgba(255,215,0,0.5), inset 0 0 15px rgba(255,255,255,0.3);
            margin-top: 2rem;
            padding-top: 90px; /* **Diperbarui:** Padding lebih banyak untuk champion card */
        }
        
        .hof-card.champion:hover {
            transform: scale(1.12) translateY(-8px);
        }

        .hof-card.second-place {
            background: linear-gradient(to bottom right, #C0C0C0, #A9A9A9); /* Tetap perak */
        }

        .hof-card.third-place {
            background: linear-gradient(to bottom right, #CD7F32, #B87333); /* Tetap perunggu */
        }

        /* Ikon Medali SVG - Ini tidak akan digunakan lagi secara langsung untuk posisi 1-3 karena diganti Font Awesome */
        .medal-icon {
            width: 48px; /* Atur ukuran ikon */
            height: 48px;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            margin-bottom: 0.5rem; /* Jarak dari nama */
        }
        .medal-gold { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFD700"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15H9v-2h2v2zm0-4H9v-2h2v2zm0-4H9V7h2v2z"/></svg>'); }
        .medal-silver { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23C0C0C0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15H9v-2h2v2zm0-4H9v-2h2v2zm0-4H9V7h2v2z"/></svg>'); }
        .medal-bronze { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23CD7F32"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15H9v-2h2v2zm0-4H9v-2h2v2zm0-4H9V7h2v2z"/></svg>'); }

        .profile-pic-hof {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.7);
            object-fit: cover;
            margin-bottom: 0.75rem;
        }
        @media (min-width: 768px) {
            .profile-pic-hof {
                width: 80px;
                height: 80px;
            }
        }

        .hof-card.champion .profile-pic-hof {
            width: 90px;
            height: 90px;
            border-width: 4px;
        }
        @media (min-width: 768px) {
            .hof-card.champion .profile-pic-hof {
                width: 100px;
                height: 100px;
            }
        }

        .points-text-gradient {
            background: linear-gradient(to right, #ffffff, #e0e0e0); /* Gradasi putih ke abu-abu terang */
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
            font-family: 'Nunito', sans-serif; /* Menggunakan Nunito untuk angka poin */
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        @media (min-width: 768px) {
            .badge {
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
        }

        /* Penyesuaian warna badge untuk tema gelap */
        .badge-level { background-color: #3C8D7C; color: white; }
        .badge-active { background-color: #00BF8F; color: #0A332B; }
        .badge-eco { background-color: #276B66; color: white; }
        .badge-orange { background-color: #E27B00; color: white; }

        /* Leaderboard table specific styles */
        .leaderboard-table-container {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border-radius: 1.5rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .leaderboard-table {
            min-width: 600px;
        }

        .leaderboard-table th {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #e0e0e0;
        }
        @media (min-width: 768px) {
            .leaderboard-table th {
                padding: 1rem 1.5rem;
                font-size: 0.9rem;
            }
        }

        .leaderboard-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: #f0f0f0;
            font-weight: 500;
            font-size: 0.9rem;
        }
         @media (min-width: 768px) {
            .leaderboard-table td {
                padding: 1rem 1.5rem;
                font-size: 1rem;
            }
        }

        .leaderboard-table tbody tr:last-child td {
            border-bottom: none;
        }

        .leaderboard-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.12);
        }
        /* Striped rows */
        .leaderboard-table tbody tr:nth-child(even) {
            background-color: rgba(0, 0, 0, 0.05); /* Sedikit lebih gelap untuk baris genap */
        }


        .ranking-circle {
            width: 35px;
            height: 35px;
            font-size: 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            font-family: 'Nunito', sans-serif; /* Menggunakan Nunito untuk angka peringkat */
        }
        @media (min-width: 768px) {
            .ranking-circle {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }
        }

        /* Warna peringkat tetap terang untuk kontras */
        .rank-color-1 { background-color: #FFD700; color: #333;}
        .rank-color-2 { background-color: #C0C0C0; color: #333;}
        .rank-color-3 { background-color: #CD7F32; color: #333;}
        .rank-color-4 { background-color: #ef4444; }
        .rank-color-5 { background-color: #f97316; }
        .rank-color-6 { background-color: #eab308; }
        .rank-color-7 { background-color: #22c55e; }
        .rank-color-8 { background-color: #0ea5e9; }
        .rank-color-9 { background-color: #a855f7; }
        .rank-color-10 { background-color: #ec4899; }

        /* Personal stats section */
        .personal-stats-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        @media (min-width: 768px) {
            .personal-stats-card {
                padding: 2rem;
            }
        }

        /* Gradasi kartu personal stats disesuaikan ke warna hijau/biru */
        .personal-stats-card.orange {
            background: linear-gradient(to bottom right, #00BF8F, #0A5F56); /* Teal cerah ke hijau kebiruan gelap */
            color: #0A332B; /* Teks lebih gelap untuk kontras */
        }
        .personal-stats-card.orange .text-white\/90,
        .personal-stats-card.orange .badge {
            color: #0A332B; /* Memastikan teks dan badge di dalamnya juga gelap */
        }
        .personal-stats-card.orange .badge-level,
        .personal-stats-card.orange .badge-eco {
            background-color: rgba(10, 51, 43, 0.2); /* Background badge yang lebih transparan dari warna gelap utama */
            color: #0A332B; /* Teks badge juga gelap */
        }

        .personal-stats-card.purple {
            background: linear-gradient(to bottom right, #1F7A8C, #0C4D5E); /* Biru kehijauan ke biru gelap */
        }
        .personal-stats-card.purple .badge-active,
        .personal-stats-card.purple .badge-level {
            background-color: rgba(255, 255, 255, 0.2); /* Badge background lebih terang untuk kontras */
        }

        .personal-stats-card .points-value {
            font-family: 'Nunito', sans-serif; /* Menggunakan Nunito untuk angka poin */
            background: linear-gradient(to right, #fff, #e0e0e0); /* Gradasi putih ke abu-abu terang */
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
        }


        .icon-large {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        @media (min-width: 768px) {
            .icon-large {
                font-size: 3rem;
            }
        }
        /* Ikon personal stats SVG (contoh untuk bintang dan grup) */
        .icon-large.star {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%2300BF8F"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            width: 48px; height: 48px; /* Ukuran default SVG icon */
            display: inline-block; /* Agar bisa pakai width/height */
        }
        .icon-large.group {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFFFFF"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.54.89 2.5 1.95 2.5 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            width: 48px; height: 48px;
            display: inline-block;
        }


        /* Latar Belakang Animasi */
        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            overflow: hidden;
            z-index: -1;
        }

        .animated-bg li {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background-size: contain;
            background-repeat: no-repeat;
            animation: float-up 25s linear infinite;
            bottom: -150px;
            opacity: 0.3;
        }

        /* Warna ikon SVG diubah ke putih untuk tema gelap */
        .icon-leaf {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFFFFF"><path d="M17 6H10V1L5 6l5 5V7h7c1.1 0 2 .9 2 2v2H17v6h-7v5l5-5V17h7c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg>');
        }
        .icon-drop {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFFFFF"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15H9v-2h2v2zm0-4H9v-2h2v2zm0-4H9V7h2v2z"/></svg>');
        }
        .icon-sun {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFFFFF"><path d="M6.76 4.84l-1.41 1.41-1.41-1.41 1.41-1.41 1.41 1.41zm0 14.32l-1.41-1.41-1.41 1.41 1.41 1.41 1.41-1.41zM20 13h3v-2h-3v2zm-14 0H1v-2h3v2zM12 4c-4.42 0-8 3.58-8 8s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm5.66 2.34l1.41-1.41 1.41 1.41-1.41 1.41-1.41-1.41zm-1.41 14.32l1.41 1.41 1.41-1.41-1.41-1.41-1.41 1.41zM12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6z"/></svg>');
        }
        .icon-recycle {
             background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23FFFFFF"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>');
        }


        .animated-bg li:nth-child(1){ left: 25%; width: 80px; height: 80px; animation-delay: 0s; transform: translateY(0) rotate(0deg); animation-duration: 25s; }
        .animated-bg li:nth-child(2){ left: 10%; width: 20px; height: 20px; animation-delay: 2s; animation-duration: 18s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(3){ left: 70%; width: 20px; height: 20px; animation-delay: 4s; animation-duration: 22s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(4){ left: 40%; width: 60px; height: 60px; animation-delay: 0s; animation-duration: 16s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(5){ left: 65%; width: 20px; height: 20px; animation-delay: 0s; animation-duration: 20s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(6){ left: 75%; width: 110px; height: 110px; animation-delay: 3s; animation-duration: 30s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(7){ left: 35%; width: 150px; height: 150px; animation-delay: 7s; animation-duration: 28s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(8){ left: 50%; width: 25px; height: 25px; animation-delay: 15s; animation-duration: 45s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(9){ left: 20%; width: 15px; height: 15px; animation-delay: 2s; animation-duration: 35s; transform: translateY(0) rotate(0deg); }
        .animated-bg li:nth-child(10){ left: 85%; width: 150px; height: 150px; animation-delay: 0s; animation-duration: 11s; transform: translateY(0) rotate(0deg); }

        /* Keyframe untuk animasi float-up */
        @keyframes float-up {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.3;
            }
            100% {
                transform: translateY(-120vh) rotate(720deg); /* Bergerak ke atas sambil berputar */
                opacity: 0;
            }
        }

        /* Gaya baru untuk ikon Font Awesome di Hall of Fame */
        .rank-icon-overlay {
            position: absolute;
            top: -40px; /* **Diperbarui:** Diatur lebih tinggi di atas kartu */
            left: 50%;
            transform: translateX(-50%); /* Hanya geser horizontal */
            z-index: 10; /* Pastikan di atas elemen lain */
            font-size: 3rem; /* Ukuran ikon default */
            color: #ccc; /* Warna default, akan di-override untuk posisi spesifik */
            text-shadow: 0 2px 5px rgba(0,0,0,0.3); /* Sedikit bayangan pada ikon */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1; /* Mengurangi spasi baris untuk Font Awesome */
        }

        .hof-card.champion .rank-icon-overlay {
            font-size: 4.5rem; /* Ukuran lebih besar untuk champion */
            color: #FFD700; /* Warna emas untuk piala */
            top: -55px; /* **Diperbarui:** Sesuaikan posisi agar lebih tinggi dari kartu */
        }
        .hof-card.champion .rank-icon-overlay .champion-text {
            font-size: 0.8rem; /* Ukuran teks CHAMPION */
            font-weight: 700;
            color: #333; /* Warna teks gelap agar terbaca di emas */
            margin-top: -10px; /* **Diperbarui:** Sesuaikan posisi teks di bawah piala */
            text-shadow: none; /* Hapus bayangan teks jika ada */
            background-color: rgba(255, 255, 255, 0.7); /* Background untuk teks CHAMPION */
            padding: 2px 8px;
            border-radius: 5px;
            white-space: nowrap; /* Pastikan teks tidak patah */
        }
        .hof-card.champion .rank-icon-overlay .crown-emoji {
            font-size: 1.2rem; /* Ukuran emoji mahkota */
            margin-bottom: -5px; /* **Diperbarui:** Sesuaikan posisi mahkota di atas piala */
        }

        .hof-card.second-place .rank-icon-overlay {
            font-size: 3.5rem; /* Sedikit lebih besar dari default */
            color: #C0C0C0; /* Warna perak */
            top: -45px; /* **Diperbarui:** Sesuaikan posisi */
        }
        .hof-card.second-place .rank-icon-overlay .rank-number-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #333;
            margin-top: -8px; /* **Diperbarui:** Sesuaikan posisi teks */
            background-color: rgba(255, 255, 255, 0.7);
            padding: 2px 6px;
            border-radius: 5px;
        }

        .hof-card.third-place .rank-icon-overlay {
            font-size: 3.5rem; /* Sedikit lebih besar dari default */
            color: #CD7F32; /* Warna perunggu */
            top: -45px; /* **Diperbarui:** Sesuaikan posisi */
        }
         .hof-card.third-place .rank-icon-overlay .rank-number-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #333;
            margin-top: -8px; /* **Diperbarui:** Sesuaikan posisi teks */
            background-color: rgba(255, 255, 255, 0.7);
            padding: 2px 6px;
            border-radius: 5px;
        }
        
        /* Mengatur z-index untuk header agar teks "Hall of Fame" tidak tertimpa */
        #top-users-section h2 {
            position: relative;
            z-index: 5; /* Lebih rendah dari ikon rank, tapi di atas hof-card */
        }

        /* Responsive adjustments for smaller screens */
        @media (max-width: 768px) {
            .hof-card {
                padding-top: 60px; /* Sedikit lebih kecil untuk mobile */
            }
            .hof-card.champion {
                padding-top: 80px; /* Sedikit lebih kecil untuk mobile */
            }

            .rank-icon-overlay {
                font-size: 2.5rem; /* Ukuran ikon lebih kecil di mobile */
                top: -30px;
            }
            .hof-card.champion .rank-icon-overlay {
                font-size: 3.5rem;
                top: -40px;
            }
            .hof-card.champion .rank-icon-overlay .champion-text {
                font-size: 0.7rem;
                margin-top: -5px;
            }
            .hof-card.champion .rank-icon-overlay .crown-emoji {
                font-size: 1rem;
            }
            .hof-card.second-place .rank-icon-overlay,
            .hof-card.third-place .rank-icon-overlay {
                font-size: 3rem;
                top: -35px;
            }
            .hof-card.second-place .rank-icon-overlay .rank-number-text,
            .hof-card.third-place .rank-icon-overlay .rank-number-text {
                font-size: 0.8rem;
                margin-top: -5px;
            }
        }
    </style>
</head>
<body class="min-h-screen p-4 antialiased font-['Poppins']">

    <ul class="animated-bg">
        <li class="icon-leaf"></li>
        <li class="icon-drop"></li>
        <li class="icon-sun"></li>
        <li class="icon-recycle"></li>
        <li class="icon-leaf"></li>
        <li class="icon-drop"></li>
        <li class="icon-sun"></li>
        <li class="icon-recycle"></li>
        <li class="icon-leaf"></li>
        <li class="icon-drop"></li>
    </ul>

    <header class="text-center py-8 relative z-10">
        <h1 class="text-4xl md:text-6xl font-extrabold text-white mb-2 leading-tight drop-shadow-lg">
            Leaderboard GoRako 🏆
        </h1>
        <p class="text-md md:text-xl text-gray-200 drop-shadow">
            Pahlawan Lingkungan dengan Poin Tertinggi
        </p>
    </header>

    <section id="top-users-section" class="container mx-auto px-4 mb-8 md:mb-12 relative z-10">
        <h2 class="text-2xl md:text-3xl font-bold text-white text-center mb-6 md:mb-8 drop-shadow-md">Hall of Fame ✨</h2>
        <div id="top-users-container" class="flex flex-col items-center md:flex-row md:justify-center md:items-end gap-4 md:gap-6">
            <?php
            // Reorder top 3 for display: 2nd, 1st, 3rd
            $displayTopUsers = [];
            if (isset($top3Users[1])) {
                $displayTopUsers[] = $top3Users[1]; // 2nd place
            }
            if (isset($top3Users[0])) {
                $displayTopUsers[] = $top3Users[0]; // 1st place
            }
            if (isset($top3Users[2])) {
                $displayTopUsers[] = $top3Users[2]; // 3rd place
            }

            $hofClasses = ['second-place', 'champion', 'third-place'];
            
            if (!empty($displayTopUsers)) {
                foreach ($displayTopUsers as $index => $user) {
                    // Manajemen Gambar Profil: Menggunakan path default lokal
                    $profilePicturePath = empty($user['profile_picture']) ? '/assets/images/default_profile.png' : htmlspecialchars($user['profile_picture']);
                    
                    // Menghitung level secara dinamis berdasarkan total_points
                    $userLevel = floor($user['total_points'] / 50) + 1;
                    $userRankTitle = getRankTitleByLevel($userLevel); // Dapatkan gelar berdasarkan level

                    $cardClass = $hofClasses[$index] ?? '';
                    $rankIconHtml = '';

                    // Menambahkan ikon Font Awesome atau nomor overlay berdasarkan peringkat
                    if ($index === 1) { // Champion (Posisi ke-1 di array displayTopUsers)
                        $rankIconHtml = '
                            <div class="rank-icon-overlay">
                                <span class="crown-emoji">👑</span> <i class="fas fa-trophy"></i> <span class="champion-text">CHAMPION</span>
                            </div>
                        ';
                    } elseif ($index === 0) { // Posisi ke-2 (Posisi ke-0 di array displayTopUsers)
                        $rankIconHtml = '
                            <div class="rank-icon-overlay">
                                <i class="fas fa-medal"></i> <span class="rank-number-text">#2</span>
                            </div>
                        ';
                    } elseif ($index === 2) { // Posisi ke-3 (Posisi ke-2 di array displayTopUsers)
                        $rankIconHtml = '
                            <div class="rank-icon-overlay">
                                <i class="fas fa-medal"></i> <span class="rank-number-text">#3</span>
                            </div>
                        ';
                    }

                    echo '
                    <div class="hof-card ' . $cardClass . ' w-full sm:w-2/3 md:w-1/3 lg:w-1/4 xl:w-1/5">
                        ' . $rankIconHtml . ' <img src="' . $profilePicturePath . '" alt="Profil ' . htmlspecialchars($user['username']) . '" class="profile-pic-hof">
                        <h3 class="text-xl md:text-2xl font-bold mb-1">' . htmlspecialchars($user['username']) . '</h3>
                        <p class="text-base md:text-lg font-semibold mb-2">Total Poin: <span class="text-xl md:text-2xl font-extrabold points-text-gradient">' . number_format($user['total_points']) . '</span></p>
                        <div class="flex gap-2 text-white">
                            <span class="badge badge-level">Level ' . $userLevel . '</span>
                            <span class="badge badge-eco">' . htmlspecialchars($userRankTitle) . '</span> 
                        </div>
                    </div>';
                }
            } else {
                echo '<p class="text-center text-gray-300 col-span-full">Hall of Fame masih kosong. Jadilah yang pertama!</p>';
            }
            ?>
        </div>
    </section>

    <section id="weekly-ranking-table-section" class="container mx-auto px-4 pb-8 relative z-10">
        <h2 class="text-2xl md:text-3xl font-bold text-white text-center mb-6 md:mb-8 drop-shadow-md">Leaderboard Lengkap 👥</h2>
        <div class="leaderboard-table-container">
            <table class="min-w-full table-auto border-collapse leaderboard-table">
                <thead>
                    <tr>
                        <th>Peringkat</th>
                        <th>Pengguna</th>
                        <th>Level</th>
                        <th>Gelar</th> <th>Total Poin</th>
                    </tr>
                </thead>
                <tbody id="weekly-ranking-table-body">
                    <?php
                    if (!empty($leaderboardUsers)) {
                        $rankColors = ['rank-color-1', 'rank-color-2', 'rank-color-3', 'rank-color-4', 'rank-color-5', 'rank-color-6', 'rank-color-7', 'rank-color-8', 'rank-color-9', 'rank-color-10'];
                        foreach ($leaderboardUsers as $index => $user) {
                            $rank = $index + 1;
                            // Manajemen Gambar Profil: Menggunakan path default lokal
                            $profilePicturePath = empty($user['profile_picture']) ? '/assets/images/default_profile.png' : htmlspecialchars($user['profile_picture']);
                            
                            // Menghitung level secara dinamis berdasarkan total_points
                            $userLevel = floor($user['total_points'] / 50) + 1;
                            $userRankTitle = getRankTitleByLevel($userLevel); // Dapatkan gelar berdasarkan level
                            
                            $rankColorClass = $rankColors[$index] ?? '';

                            echo '<tr class="border-b border-white/10">
                                <td class="py-2 px-3 md:py-3 md:px-6 text-left whitespace-nowrap">
                                    <div class="ranking-circle ' . $rankColorClass . '">' . $rank . '</div>
                                </td>
                                <td class="py-2 px-3 md:py-3 md:px-6 text-left flex items-center gap-2 md:gap-3">
                                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full overflow-hidden border-2 border-white/50 flex-shrink-0">
                                        <img src="' . $profilePicturePath . '" alt="Profil" class="w-full h-full object-cover">
                                    </div>
                                    <span class="text-sm md:text-lg font-semibold">' . htmlspecialchars($user['username']) . '</span>
                                </td>
                                <td class="py-2 px-3 md:py-3 md:px-6 text-left font-medium">
                                    <span class="badge badge-level">Level ' . $userLevel . '</span>
                                </td>
                                <td class="py-2 px-3 md:py-3 md:px-6 text-left font-medium">
                                    <span class="badge badge-eco">' . htmlspecialchars($userRankTitle) . '</span> </td>
                                <td class="py-2 px-3 md:py-3 md:px-6 text-left font-extrabold text-lg md:text-xl">' . number_format($user['total_points']) . '</td>
                            </tr>';
                        }
                    } else {
                        echo '<tr><td colspan="5" class="text-center py-4 text-gray-300">Tidak ada data peringkat.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="current-user-points-section" class="container mx-auto px-4 py-6 md:py-8 mt-4 md:mt-8 relative z-10">
        <h2 class="text-2xl md:text-3xl font-bold text-white text-center mb-6 md:mb-8 drop-shadow-md">Statistik Personal 🎖️</h2>
         <?php
        if ($currentUserData) {
            // Menghitung level secara dinamis berdasarkan total_points
            $currentUserLevel = floor($currentUserData['total_points'] / 50) + 1;
            $currentUserRankTitle = getRankTitleByLevel($currentUserLevel); // Dapatkan gelar untuk pengguna saat ini

            echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">
                <div class="personal-stats-card orange">
                    <div class="icon-large star"></div> <p class="text-base md:text-lg text-white/90">Total Poin Saya</p>
                    <p class="text-4xl md:text-5xl font-extrabold mt-2 mb-3 points-value">' . number_format($currentUserData['total_points']) . '</p>
                    <div class="flex justify-center gap-2">
                        <span class="badge badge-level">Level ' . $currentUserLevel . '</span>
                        <span class="badge badge-eco">' . htmlspecialchars($currentUserRankTitle) . '</span> </div>
                </div>
                <div class="personal-stats-card purple">
                    <div class="icon-large group"></div> <p class="text-base md:text-lg text-white/90">Total Peserta</p>
                    <p class="text-4xl md:text-5xl font-extrabold mt-2 mb-3 points-value">' . number_format($totalParticipants) . '</p>
                    <div class="flex justify-center gap-2">
                        <span class="badge badge-active">Bergabung</span>
                        <span class="badge badge-level">Komunitas</span>
                    </div>
                </div>
            </div>';
        } else {
            echo '<div class="bg-white/15 backdrop-blur-sm p-5 md:p-6 rounded-lg shadow-xl text-center text-white/90">
                    <p class="text-base md:text-xl">Anda perlu login untuk melihat statistik Anda.</p>
                </div>';
        }
        ?>
    </section>
    
    <div class="text-center my-8 md:my-12 relative z-10">
        <a href="index.php" class="bg-gradient-to-r from-orange-500 to-pink-600 hover:from-orange-600 hover:to-pink-700 text-white font-bold py-2 px-6 md:py-3 md:px-8 rounded-full transition-all transform hover:scale-105 shadow-xl text-base md:text-lg tracking-wide">
            Kembali ke Dashboard
        </a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // JavaScript lainnya jika ada
        });
    </script>
</body>
</html>