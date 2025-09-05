<?php
// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php'; // Include the database connection and helper functions
require_once 'helpers.php';       // Include helper functions like is_logged_in() and redirect()

// Jika user sudah login, redirect ke halaman index
if (is_logged_in()) {
    redirect('index.php');
}

$error = ''; // Inisialisasi variabel pesan error PHP, akan dikirim ke JS melalui data attribute atau langsung
$error_type = 'error'; // Default error type for custom alert

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Mengambil 'email' dan 'password' dari POST request
    $email = clean_input($_POST['email']);
    $password = clean_input($_POST['password']);

    // Siapkan statement SQL untuk mencari user berdasarkan email
    $stmt = $conn->prepare("SELECT id, username, password, two_factor_enabled FROM users WHERE email = ?"); // Ambil juga two_factor_enabled
    $stmt->bind_param("s", $email); // Bind parameter email ke prepared statement
    $stmt->execute(); // Jalankan prepared statement
    $result = $stmt->get_result(); // Dapatkan hasil dari statement yang dijalankan

    if ($result->num_rows == 1) { // Cek jika tepat satu user ditemukan
        $user = $result->fetch_assoc(); // Ambil data user sebagai associative array
        // Verifikasi password yang diberikan dengan hashed password di database
        if (password_verify($password, $user['password'])) {
            // ************ LOGIKA 2FA (Start) ************
            if ($user['two_factor_enabled']) {
                // Jika 2FA aktif, simpan email dan user_id sementara di sesi
                // dan redirect ke halaman verifikasi 2FA (misal: 2fa_verify.php)
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_username'] = $user['username'];
                $_SESSION['temp_user_email'] = $user['email']; // Bisa dipakai untuk tampilan di 2FA
                
                // Set flash message sebelum redirect ke halaman 2FA
                set_flash_message('info', 'Verifikasi Autentikasi Dua Faktor diperlukan.');
                redirect('2fa_verify.php'); // Anda perlu membuat file ini
                exit(); // Pastikan tidak ada kode lain yang dieksekusi setelah redirect
            } else {
                // Jika 2FA tidak aktif, lanjutkan login normal
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                // Update timestamp login terakhir untuk user
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);

                // Set flash message untuk sukses login
                set_flash_message('success', 'Login Berhasil! Selamat datang, ' . htmlspecialchars($user['username']) . '!');
                redirect('index.php'); // Redirect ke index.php setelah login berhasil
                exit();
            }
            // ************ LOGIKA 2FA (End) ************
        } else {
            $error = "Password salah"; // Set pesan error untuk password yang salah
            $error_type = 'error';
        }
    } else {
        $error = "Email tidak ditemukan"; // Set pesan error untuk email tidak ditemukan
        $error_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Login & Daftar</title>
    <link rel="icon" href="images/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f2f5; /* Light gray background */
        }
        .green-bg-primary {
            background-color: #4CAF50; /* Primary green from image */
        }
        .green-text-primary {
            color: #4CAF50;
        }
        .green-button-hover:hover {
            background-color: #45a049; /* Darker green on hover */
            transform: translateY(-2px); /* Slight lift on hover */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); /* Add shadow */
        }
        .form-input {
            border-color: #e0e0e0; /* Lighter border for inputs */
            padding-left: 1rem; /* Padding standar */
            padding-right: 2.5rem; /* Padding kanan untuk ikon mata */
        }

        /* Custom focus ring animation */
        .form-input:focus {
            animation: focus-ring-pulse 1.2s infinite;
        }

        @keyframes focus-ring-pulse {
            0% { box-shadow: 0 0 0 0px rgba(76, 175, 80, 0.7); } /* green-500 */
            50% { box-shadow: 0 0 0 5px rgba(76, 175, 80, 0); }
            100% { box-shadow: 0 0 0 0px rgba(76, 175, 80, 0.7); }
        }

        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #bdbdbd; /* Warna ikon mata */
            z-index: 10;
            transition: color 0.2s ease-in-out; /* Animasi warna saat hover */
        }
        .toggle-password:hover {
            color: #4CAF50; /* Green on hover */
        }
        .checkbox-custom {
            accent-color: #4CAF50; /* Green accent for checkbox */
            transform: scale(1);
            transition: transform 0.2s ease-in-out;
        }
        .checkbox-custom:checked {
            transform: scale(1.1);
        }

        /* Form section transition (slide and fade) */
        .form-section {
            transition: opacity 0.6s cubic-bezier(0.25, 0.8, 0.25, 1), transform 0.6s cubic-bezier(0.25, 0.8, 0.25, 1);
            transform: translateX(0);
            opacity: 1;
            width: 100%;
        }
        .form-section.hidden-left { /* Hidden state for forms moving left */
            opacity: 0;
            transform: translateX(-40px);
            pointer-events: none;
            position: absolute; /* Allows other content to take its place */
        }
        .form-section.hidden-right { /* Hidden state for forms moving right (not used in this setup but good for completeness) */
            opacity: 0;
            transform: translateX(40px);
            pointer-events: none;
            position: absolute;
        }
        .form-section-container {
            position: relative;
            min-height: 500px; /* To prevent height collapse during transition */
            overflow: hidden;
        }

        /* Welcome icon animation */
        .welcome-icon-animate {
            animation: bounce-in 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes bounce-in {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.1); opacity: 1; }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); }
        }

        /* Spinner animation (already provided by Font Awesome, but for custom spin) */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Custom Alert/Toast Styles */
        .custom-alert {
            position: fixed;
            top: 1.25rem; /* 20px */
            right: 1.25rem; /* 20px */
            z-index: 50;
            padding: 1rem; /* 16px */
            border-radius: 0.5rem; /* 8px */
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1); /* Subtle shadow */
            transform: translateY(-100%); /* Start off-screen above */
            opacity: 0;
            transition: transform 0.5s ease-out, opacity 0.5s ease-out;
        }

        .custom-alert-show {
            transform: translateY(0);
            opacity: 1;
        }

        .custom-alert-success {
            background-color: #d4edda; /* Light green */
            color: #155724; /* Dark green text */
            border: 1px solid #c3e6cb;
        }
        .custom-alert-error {
            background-color: #f8d7da; /* Light red */
            color: #721c24; /* Dark red text */
            border: 1px solid #f5c6cb;
        }
        .custom-alert-info { /* New info type for 2FA redirection */
            background-color: #d1ecf1; /* Light blue */
            color: #0c5460; /* Dark blue text */
            border: 1px solid #bee5eb;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 md:p-8">

    <div class="flex flex-col lg:flex-row w-full max-w-6xl bg-white shadow-xl rounded-xl overflow-hidden">

        <div class="lg:w-2/5 green-bg-primary text-white p-8 md:p-10 lg:p-12 flex flex-col justify-between relative rounded-l-xl lg:rounded-r-none">

            <div class="my-auto text-center lg:text-left">
                <h1 class="text-3xl md:text-4xl lg:text-4xl font-bold mb-6 leading-tight">
                    Selamat Datang di Platform Edukasi Pengelolaan Sampah
                </h1>
                <p class="text-lg mb-8 opacity-90">
                    Bergabunglah dengan ribuan orang yang peduli terhadap lingkungan dan masa depan bumi kita.
                </p>

                <ul class="space-y-5 text-lg">
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Edukasi Berkelanjutan</span><br>
                            Akses materi dan kursus tentang pengelolaan sampah
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Komunitas Aktif</span><br>
                            Bergabung dengan komunitas peduli lingkungan
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Dampak Nyata</span><br>
                            Ikut berkontribusi dalam mengurangi sampah
                        </span>
                    </li>
                </ul>
            </div>

            <div class="mt-8 lg:mt-12 flex justify-between items-center text-sm opacity-80">
                <span>© 2025 GoRako. All rights reserved.</span>
                <div class="flex space-x-4">
                    <a href="#" class="hover:text-gray-200" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>

        <div class="lg:w-3/5 p-8 md:p-10 lg:p-12 flex flex-col justify-center">
            <div class="flex border-b border-gray-200 mb-8">
                <button id="showLogin" class="py-2 px-4 text-lg font-semibold text-gray-700 border-b-2 border-green-600 transition duration-300 focus:outline-none">Masuk</button>
                <button id="showRegister" class="py-2 px-4 text-lg font-semibold text-gray-400 hover:text-gray-700 transition duration-300 focus:outline-none">Daftar</button>
            </div>

            <div class="form-section-container">
                <div id="loginContent" class="form-section">
                    <div class="text-center mb-8">
                        <div class="bg-gray-100 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-hand-holding text-4xl green-text-primary welcome-icon-animate"></i> </div>
                        <h2 class="text-3xl font-bold text-gray-800 mb-2">Selamat Datang Kembali</h2>
                        <p class="text-gray-600">Masuk ke akun GoRako Anda</p>
                    </div>

                    <form id="loginForm" class="space-y-6" method="POST" action="">
                        <div>
                            <label for="loginEmail" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Email</label>
                            <div class="relative">
                                <input type="email" id="loginEmail" name="email" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="nama@email.com" required aria-label="Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <p class="text-red-500 text-sm mt-1 <?php echo ($error === 'Email tidak ditemukan') ? '' : 'hidden'; ?>" id="loginEmailError"><?php echo ($error === 'Email tidak ditemukan') ? $error : ''; ?></p>
                        </div>
                        <div>
                            <label for="loginPassword" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Kata Sandi</label>
                            <div class="relative">
                                <input type="password" id="loginPassword" name="password" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Masukkan kata sandi" required aria-label="Kata Sandi">
                                <span class="toggle-password" data-target="loginPassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <p class="text-red-500 text-sm mt-1 <?php echo ($error === 'Password salah') ? '' : 'hidden'; ?>" id="loginPasswordError"><?php echo ($error === 'Password salah') ? $error : ''; ?></p>
                        </div>

                        <div class="flex justify-between items-center text-sm">
                            <div class="flex items-center">
                                <input type="checkbox" id="rememberMe" class="checkbox-custom mr-2">
                                <label for="rememberMe" class="text-gray-700">Ingat saya</label>
                            </div>
                        </div>

                        <button type="submit" id="loginSubmitBtn" class="w-full green-bg-primary text-white py-3 rounded-lg font-semibold text-lg green-button-hover flex items-center justify-center transition duration-300 focus:outline-none focus:ring-2 focus:ring-green-500">
                            Masuk <i class="fas fa-arrow-right ml-2"></i>
                            <span class="ml-2 hidden" id="loginSpinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </button>
                    </form>
                    
                    <p class="mt-8 text-center">
                        <a href="index.php" class="text-gray-500 text-sm hover:underline focus:outline-none focus:ring-2 focus:ring-gray-300 rounded"><i class="fas fa-arrow-left mr-2"></i> Kembali ke Beranda</a>
                    </p>
                </div>

                <div id="registerContent" class="form-section hidden-left">
                    <div class="text-center mb-8">
                        <div class="bg-gray-100 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-user-plus text-4xl green-text-primary welcome-icon-animate"></i> </div>
                        <h2 class="text-3xl font-bold text-gray-800 mb-2">Buat Akun Baru</h2>
                        <p class="text-gray-600">Bergabung dengan komunitas peduli lingkungan</p>
                    </div>

                    <form id="registerForm" class="space-y-6">
                        <div class="flex flex-col sm:flex-row sm:space-x-4 space-y-6 sm:space-y-0">
                            <div class="relative flex-1">
                                <label for="firstName" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Nama Depan</label>
                                <input type="text" id="firstName" name="firstName" class="w-full px-4 py-3 rounded-lg border form-input focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Nama depan" required aria-label="Nama Depan">
                                <p class="text-red-500 text-sm mt-1 hidden" id="firstNameError"></p>
                            </div>
                            <div class="relative flex-1">
                                <label for="lastName" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Nama Belakang</label>
                                <input type="text" id="lastName" name="lastName" class="w-full px-4 py-3 rounded-lg border form-input focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Nama belakang" aria-label="Nama Belakang">
                                <p class="text-red-500 text-sm mt-1 hidden" id="lastNameError"></p>
                            </div>
                        </div>
                        <div>
                            <label for="registerEmail" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Email</label>
                            <div class="relative">
                                <input type="email" id="registerEmail" name="email" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="nama@email.com" required aria-label="Email">
                            </div>
                            <p class="text-red-500 text-sm mt-1 hidden" id="registerEmailError"></p>
                        </div>
                        <div>
                            <label for="registerPassword" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Kata Sandi</label>
                            <div class="relative">
                                <input type="password" id="registerPassword" name="password" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Buat kata sandi" required aria-label="Kata Sandi">
                                <span class="toggle-password" data-target="registerPassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-2 ml-1" id="passwordHint">Minimal 8 karakter dengan huruf dan angka</p>
                            <p class="text-red-500 text-sm mt-1 hidden" id="registerPasswordError"></p>
                            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                <div id="passwordStrength" class="bg-red-500 h-2 rounded-full transition-all duration-300" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div>
                            <label for="confirmPassword" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Konfirmasi Kata Sandi</label>
                            <div class="relative">
                                <input type="password" id="confirmPassword" name="confirmPassword" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Konfirmasi kata sandi" required aria-label="Konfirmasi Kata Sandi">
                                <span class="toggle-password" data-target="confirmPassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <p class="text-red-500 text-sm mt-1 hidden" id="confirmPasswordError"></p>
                        </div>

                        <div class="flex items-center text-sm">
                            <input type="checkbox" id="agreeTerms" class="checkbox-custom mr-2" required>
                            <label for="agreeTerms" class="text-gray-700">Saya setuju dengan <a href="#" class="green-text-primary font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-green-500 rounded">Syarat & Ketentuan</a></label>
                            <p class="text-red-500 text-sm mt-1 hidden" id="agreeTermsError"></p>
                        </div>

                        <button type="submit" id="registerSubmitBtn" class="w-full green-bg-primary text-white py-3 rounded-lg font-semibold text-lg green-button-hover flex items-center justify-center transition duration-300 focus:outline-none focus:ring-2 focus:ring-green-500">
                            Daftar Sekarang <i class="fas fa-arrow-right ml-2"></i>
                            <span class="ml-2 hidden" id="registerSpinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </button>
                    </form>

                    <p class="mt-8 text-center text-gray-600">
                        Sudah punya akun? <a href="#" id="backToLogin" class="green-text-primary font-semibold hover:underline focus:outline-none focus:ring-2 focus:ring-green-500 rounded">Masuk</a>
                    </p>
                    <p class="mt-4 text-center">
                        <a href="index.php" class="text-gray-500 text-sm hover:underline focus:outline-none focus:ring-2 focus:ring-gray-300 rounded"><i class="fas fa-arrow-left mr-2"></i> Kembali ke Beranda</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div id="custom-alert" class="custom-alert">
        <div class="flex items-center">
            <i id="custom-alert-icon" class="text-2xl mr-3"></i>
            <p id="custom-alert-message" class="font-semibold text-lg"></p>
        </div>
    </div>

    <script>
        const showLoginBtn = document.getElementById('showLogin');
        const showRegisterBtn = document.getElementById('showRegister');
        const loginContent = document.getElementById('loginContent');
        const registerContent = document.getElementById('registerContent');
        const backToLoginLink = document.getElementById('backToLogin');

        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');

        const loginEmailInput = document.getElementById('loginEmail');
        const loginPasswordInput = document.getElementById('loginPassword');
        const loginEmailError = document.getElementById('loginEmailError');
        const loginPasswordError = document.getElementById('loginPasswordError');
        const loginSubmitBtn = document.getElementById('loginSubmitBtn');
        const loginSpinner = document.getElementById('loginSpinner');

        const firstNameInput = document.getElementById('firstName');
        const lastNameInput = document.getElementById('lastName');
        const registerEmailInput = document.getElementById('registerEmail');
        const registerPasswordInput = document.getElementById('registerPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const agreeTermsCheckbox = document.getElementById('agreeTerms');
        const firstNameError = document.getElementById('firstNameError');
        const lastNameError = document.getElementById('lastNameError');
        const registerEmailError = document.getElementById('registerEmailError');
        const registerPasswordError = document.getElementById('registerPasswordError');
        const confirmPasswordError = document.getElementById('confirmPasswordError');
        const agreeTermsError = document.getElementById('agreeTermsError');
        const registerSubmitBtn = document.getElementById('registerSubmitBtn');
        const registerSpinner = document.getElementById('registerSpinner');
        const passwordStrengthBar = document.getElementById('passwordStrength');
        const passwordHint = document.getElementById('passwordHint');

        const loginWelcomeIcon = loginContent.querySelector('.welcome-icon-animate');
        const registerWelcomeIcon = registerContent.querySelector('.welcome-icon-animate');

        // PHP error data, safely passed to JavaScript
        const phpError = '<?php echo htmlspecialchars($error); ?>';
        const phpErrorType = '<?php echo htmlspecialchars($error_type); ?>';

        // New function to show custom alerts
        function showCustomAlert(message, type = 'success') {
            const customAlert = document.getElementById('custom-alert');
            const customAlertMessage = document.getElementById('custom-alert-message');
            const customAlertIcon = document.getElementById('custom-alert-icon');

            // Reset classes
            customAlert.classList.remove('custom-alert-success', 'custom-alert-error', 'custom-alert-info');
            customAlertIcon.classList.remove('fa-check-circle', 'fa-times-circle', 'fa-info-circle');
            customAlertIcon.style.color = ''; // Reset inline style for color

            customAlertMessage.textContent = message;

            if (type === 'success') {
                customAlert.classList.add('custom-alert-success');
                customAlertIcon.classList.add('fas', 'fa-check-circle');
                customAlertIcon.style.color = '#28a745'; // Specific green for success icon
            } else if (type === 'error') {
                customAlert.classList.add('custom-alert-error');
                customAlertIcon.classList.add('fas', 'fa-times-circle');
                customAlertIcon.style.color = '#dc3545'; // Specific red for error icon
            } else if (type === 'info') { // New info type
                customAlert.classList.add('custom-alert-info');
                customAlertIcon.classList.add('fas', 'fa-info-circle');
                customAlertIcon.style.color = '#17a2b8'; // Specific blue for info icon
            }

            // Ensure it's not hidden before showing
            customAlert.classList.remove('hidden');
            // Trigger reflow to restart transition if already visible
            void customAlert.offsetWidth; 
            customAlert.classList.add('custom-alert-show');

            // Automatically hide after 3 seconds
            setTimeout(() => {
                customAlert.classList.remove('custom-alert-show');
                setTimeout(() => {
                    customAlert.classList.add('hidden');
                }, 500); // Wait for fade-out transition
            }, 3000);
        }

        function switchForm(formToShow) {
            loginWelcomeIcon.classList.remove('welcome-icon-animate');
            registerWelcomeIcon.classList.remove('welcome-icon-animate');

            if (formToShow === 'login') {
                registerContent.style.opacity = '0';
                registerContent.style.transform = 'translateX(40px)';
                registerContent.classList.add('pointer-events-none');

                setTimeout(() => {
                    registerContent.classList.add('hidden-left');
                    loginContent.classList.remove('hidden-left');

                    loginContent.style.opacity = '1';
                    loginContent.style.transform = 'translateX(0)';
                    loginContent.classList.remove('pointer-events-none');

                    loginWelcomeIcon.classList.add('welcome-icon-animate');

                    showLoginBtn.classList.remove('text-gray-400', 'hover:text-gray-700');
                    showLoginBtn.classList.add('border-green-600', 'text-gray-700');
                    showRegisterBtn.classList.add('text-gray-400', 'hover:text-gray-700');
                    showRegisterBtn.classList.remove('border-green-600', 'text-gray-700');

                    loginEmailInput.focus();

                }, 600);
            } else { // formToShow === 'register'
                loginContent.style.opacity = '0';
                loginContent.style.transform = 'translateX(-40px)';
                loginContent.classList.add('pointer-events-none');

                setTimeout(() => {
                    loginContent.classList.add('hidden-left');
                    registerContent.classList.remove('hidden-left');

                    registerContent.style.opacity = '1';
                    registerContent.style.transform = 'translateX(0)';
                    registerContent.classList.remove('pointer-events-none');

                    registerWelcomeIcon.classList.add('welcome-icon-animate');

                    showRegisterBtn.classList.remove('text-gray-400', 'hover:text-gray-700');
                    showRegisterBtn.classList.add('border-green-600', 'text-gray-700');
                    showLoginBtn.classList.add('text-gray-400', 'hover:text-gray-700');
                    showLoginBtn.classList.remove('border-green-600', 'text-gray-700');

                    firstNameInput.focus();

                }, 600);
            }
            resetFormValidation(loginForm);
            resetFormValidation(registerForm);
        }

        showLoginBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Only switch if not already showing to avoid redundant animation
            if (loginContent.classList.contains('hidden-left')) { 
                switchForm('login');
            }
        });

        showRegisterBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Only switch if not already showing to avoid redundant animation
            if (registerContent.classList.contains('hidden-left')) { 
                switchForm('register');
            }
        });

        backToLoginLink.addEventListener('click', (e) => {
            e.preventDefault();
            switchForm('login');
        });

        document.addEventListener('DOMContentLoaded', () => {
            if (phpError.trim() !== '') {
                // If there's a PHP error, display it and ensure login form is active
                showCustomAlert(phpError, phpErrorType);

                // Ensure login form is visible and register form is hidden
                loginContent.classList.remove('hidden-left');
                loginContent.style.opacity = '1';
                loginContent.style.transform = 'translateX(0)';
                loginContent.classList.remove('pointer-events-none');
                loginWelcomeIcon.classList.add('welcome-icon-animate'); // Re-add animation

                registerContent.classList.add('hidden-left');
                registerContent.style.opacity = '0';
                registerContent.style.transform = 'translateX(40px)';
                registerContent.classList.add('pointer-events-none');

                // Update tab styles
                showLoginBtn.classList.remove('text-gray-400', 'hover:text-gray-700');
                showLoginBtn.classList.add('border-green-600', 'text-gray-700');
                showRegisterBtn.classList.add('text-gray-400', 'hover:text-gray-700');
                showRegisterBtn.classList.remove('border-green-600', 'text-gray-700');

                // Highlight fields with errors
                if (phpError === 'Email tidak ditemukan') {
                    loginEmailInput.classList.add('border-red-500');
                    loginEmailError.classList.remove('hidden');
                } else if (phpError === 'Password salah') {
                    loginPasswordInput.classList.add('border-red-500');
                    loginPasswordError.classList.remove('hidden');
                }
            } else {
                // If no PHP error, set initial state to login form with animation
                registerContent.classList.add('hidden-left'); // Ensure register is hidden initially
                loginContent.style.opacity = '1'; // Ensure login is visible
                loginContent.style.transform = 'translateX(0)';
                loginContent.classList.remove('pointer-events-none');
                loginWelcomeIcon.classList.add('welcome-icon-animate'); // Initial animation for login form

                // Set active tab styles
                showLoginBtn.classList.remove('text-gray-400', 'hover:text-gray-700');
                showLoginBtn.classList.add('border-green-600', 'text-gray-700');
                showRegisterBtn.classList.add('text-gray-400', 'hover:text-gray-700');
                showRegisterBtn.classList.remove('border-green-600', 'text-gray-700');
            }
        });


        // --- Password Toggle Functionality ---
        document.querySelectorAll('.toggle-password').forEach(toggle => {
            toggle.addEventListener('click', () => {
                const targetId = toggle.dataset.target;
                const passwordInput = document.getElementById(targetId);
                const icon = toggle.querySelector('i');

                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // --- Real-time Validation & Error Display Helper ---
        function showError(element, message) {
            element.textContent = message;
            element.classList.remove('hidden');
            const inputContainer = element.previousElementSibling;
            const inputField = inputContainer ? inputContainer.querySelector('input, select, textarea') : element.closest('div').querySelector('input[type="checkbox"]');

            if (inputField) {
                inputField.classList.add('border-red-500');
                inputField.classList.remove('border-gray-300', 'border-green-500');
            }
        }

        function hideError(element) {
            element.textContent = '';
            element.classList.add('hidden');
            const inputContainer = element.previousElementSibling;
            const inputField = inputContainer ? inputContainer.querySelector('input, select, textarea') : element.closest('div').querySelector('input[type="checkbox"]');

            if (inputField) {
                inputField.classList.remove('border-red-500');
                inputField.classList.add('border-gray-300'); // Reset to default border
            }
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function validatePassword(password) {
            // Min 8 chars, at least one letter, at least one number
            const regex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;
            return regex.test(password);
        }

        function getPasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength += 25;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 25; // Both lower and upper case
            if (/\d/.test(password)) strength += 25; // Numbers
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 25; // Special characters
            return strength;
        }

        function updatePasswordStrengthIndicator(password) {
            const strength = getPasswordStrength(password);
            passwordStrengthBar.style.width = `${strength}%`;

            if (strength <= 25) {
                passwordStrengthBar.className = 'bg-red-500 h-2 rounded-full transition-all duration-300';
            } else if (strength <= 50) {
                passwordStrengthBar.className = 'bg-orange-500 h-2 rounded-full transition-all duration-300';
            } else if (strength <= 75) {
                passwordStrengthBar.className = 'bg-yellow-500 h-2 rounded-full transition-all duration-300';
            } else {
                passwordStrengthBar.className = 'bg-green-500 h-2 rounded-full transition-all duration-300';
            }
            // Hide hint if password meets basic criteria or is strong enough
            if (password.length > 0 && strength >= 50) {
                passwordHint.classList.add('hidden');
            } else {
                passwordHint.classList.remove('hidden');
            }
        }

        function resetFormValidation(formElement) {
            formElement.querySelectorAll('.text-red-500').forEach(errorEl => {
                errorEl.textContent = '';
                errorEl.classList.add('hidden');
            });
            formElement.querySelectorAll('input').forEach(input => {
                input.classList.remove('border-red-500', 'border-green-500');
                input.classList.add('border-gray-300');
                input.style.boxShadow = ''; // Remove custom box-shadow from focus animation
            });
            // Reset password strength if present
            if (formElement.id === 'registerForm') {
                passwordStrengthBar.style.width = '0%';
                passwordStrengthBar.className = 'bg-red-500 h-2 rounded-full transition-all duration-300';
                passwordHint.classList.remove('hidden');
            }
        }


        // --- Login Form Validation ---
        loginEmailInput.addEventListener('input', () => {
            if (!isValidEmail(loginEmailInput.value)) {
                // Only show a generic email format error if PHP didn't provide a specific 'not found' error
                if (loginEmailError.textContent === '') { 
                    showError(loginEmailError, 'Masukkan email yang valid.');
                }
            } else {
                // Only hide if the error is not the 'not found' error from PHP
                if (loginEmailError.textContent !== 'Email tidak ditemukan') {
                    hideError(loginEmailError);
                }
            }
        });

        loginPasswordInput.addEventListener('input', () => {
            if (loginPasswordInput.value.length < 1) { // Changed to check for empty
                showError(loginPasswordError, 'Kata sandi tidak boleh kosong.'); // More appropriate message
            } else {
                // Only hide if the error is not the 'password salah' error from PHP
                if (loginPasswordError.textContent !== 'Password salah') {
                    hideError(loginPasswordError);
                }
            }
        });

        loginForm.addEventListener('submit', (e) => {
            // Re-validate just before submission (frontend check)
            let valid = true;
            if (!isValidEmail(loginEmailInput.value)) {
                showError(loginEmailError, 'Masukkan email yang valid.');
                valid = false;
            } else {
                hideError(loginEmailError);
            }
            if (loginPasswordInput.value.length < 1) {
                showError(loginPasswordError, 'Kata sandi tidak boleh kosong.');
                valid = false;
            } else {
                hideError(loginPasswordError);
            }

            if (!valid) {
                e.preventDefault(); // Stop submission if frontend validation fails
            }

            // The PHP handles the actual submission and redirection if valid
            // No need for AJAX here for the main login form submission
        });


        // --- Register Form Validation ---
        firstNameInput.addEventListener('input', () => {
            if (firstNameInput.value.trim() === '') {
                showError(firstNameError, 'Nama depan tidak boleh kosong.');
            } else {
                hideError(firstNameError);
            }
        });

        registerEmailInput.addEventListener('input', () => {
            if (!isValidEmail(registerEmailInput.value)) {
                showError(registerEmailError, 'Masukkan email yang valid.');
            } else {
                hideError(registerEmailError);
            }
        });

        registerPasswordInput.addEventListener('input', () => {
            updatePasswordStrengthIndicator(registerPasswordInput.value);
            if (!validatePassword(registerPasswordInput.value)) {
                showError(registerPasswordError, 'Minimal 8 karakter, harus ada huruf dan angka.');
            } else {
                hideError(registerPasswordError);
            }
            // Also re-check confirm password if password changes
            if (confirmPasswordInput.value !== '' && registerPasswordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordError, 'Kata sandi tidak cocok.');
            } else {
                hideError(confirmPasswordError);
            }
        });

        confirmPasswordInput.addEventListener('input', () => {
            if (registerPasswordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordError, 'Kata sandi tidak cocok.');
            } else {
                hideError(confirmPasswordError);
            }
        });

        agreeTermsCheckbox.addEventListener('change', () => {
            if (!agreeTermsCheckbox.checked) {
                showError(agreeTermsError, 'Anda harus menyetujui Syarat & Ketentuan.');
            } else {
                hideError(agreeTermsError);
            }
        });

        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault(); // Prevent default form submission

            let valid = true;

            // Perform all validations
            if (firstNameInput.value.trim() === '') {
                showError(firstNameError, 'Nama depan tidak boleh kosong.');
                valid = false;
            } else {
                hideError(firstNameError);
            }

            if (!isValidEmail(registerEmailInput.value)) {
                showError(registerEmailError, 'Masukkan email yang valid.');
                valid = false;
            } else {
                hideError(registerEmailError);
            }

            if (!validatePassword(registerPasswordInput.value)) {
                showError(registerPasswordError, 'Minimal 8 karakter, harus ada huruf dan angka.');
                valid = false;
            } else {
                hideError(registerPasswordError);
            }

            if (registerPasswordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordError, 'Kata sandi tidak cocok.');
                valid = false;
            } else {
                hideError(confirmPasswordError);
            }

            if (!agreeTermsCheckbox.checked) {
                showError(agreeTermsError, 'Anda harus menyetujui Syarat & Ketentuan.');
                valid = false;
            } else {
                hideError(agreeTermsError);
            }


            if (valid) {
                registerSubmitBtn.disabled = true;
                registerSpinner.classList.remove('hidden');
                registerSubmitBtn.innerHTML = 'Mendaftar... <span class="ml-2"><i class="fas fa-spinner fa-spin"></i></span>';

                try {
                    const response = await fetch('register_process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            firstName: firstNameInput.value,
                            lastName: lastNameInput.value,
                            email: registerEmailInput.value,
                            password: registerPasswordInput.value
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        showCustomAlert('Pendaftaran Berhasil! Silakan cek email Anda.', 'success');
                        switchForm('login');
                        registerForm.reset(); // Clear form fields
                        resetFormValidation(registerForm); // Clear validation messages
                    } else {
                        showCustomAlert('Pendaftaran Gagal: ' + (result.message || 'Terjadi kesalahan.'), 'error');
                        if (result.field_errors) {
                            if (result.field_errors.firstName) showError(firstNameError, result.field_errors.firstName);
                            if (result.field_errors.lastName) showError(lastNameError, result.field_errors.lastName);
                            if (result.field_errors.email) showError(registerEmailError, result.field_errors.email);
                            if (result.field_errors.password) showError(registerPasswordError, result.field_errors.password);
                            // Assuming confirmPassword error might also be returned, or handled by backend password validation
                            if (result.field_errors.confirmPassword) showError(confirmPasswordError, result.field_errors.confirmPassword);
                            if (result.field_errors.agreeTerms) showError(agreeTermsError, result.field_errors.agreeTerms);
                        }
                    }
                } catch (error) {
                    console.error('Error during registration:', error);
                    showCustomAlert('Terjadi kesalahan jaringan saat pendaftaran. Silakan coba lagi.', 'error');
                } finally { // Use finally to ensure button state is reset
                    registerSubmitBtn.disabled = false;
                    registerSpinner.classList.add('hidden');
                    registerSubmitBtn.innerHTML = 'Daftar Sekarang <i class="fas fa-arrow-right ml-2"></i>';
                }
            }
        });
    </script>
</body>
</html>