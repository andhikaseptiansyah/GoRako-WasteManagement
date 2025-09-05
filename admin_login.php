<?php
// Aktifkan pelaporan error untuk debugging (HAPUS INI DI PRODUKSI)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Pastikan sesi dimulai di awal setiap file PHP yang menggunakannya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php'; // Include the database connection and helper functions
require_once 'helpers.php'; // Include helper functions like is_admin_logged_in() and redirect()

// If admin is already logged in, redirect to admin dashboard
if (is_admin_logged_in()) { // Assuming you have a similar function for admin
    redirect('dashboard_admin.php'); // Redirect to your admin dashboard
    exit(); // Always exit after a redirect
}

$error_message = ''; // Initialize error message variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get 'email' and 'password' from POST request
    $email = clean_input($_POST['email']);
    $password = clean_input($_POST['password']);

    // Prepare SQL statement to find admin user by email AND role
    // Menggunakan nama tabel 'users' dan menambahkan kondisi role='admin'
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE email = ? AND role = 'admin'");

    // Check if prepare() failed (e.g., table not found, syntax error)
    if ($stmt === false) {
        $error_message = "Terjadi kesalahan pada persiapan query database.";
        // Log the actual error for debugging
        error_log("Prepare failed: " . $conn->error);
    } else {
        $stmt->bind_param("s", $email); // Bind email parameter to the prepared statement
        $stmt->execute(); // Execute the prepared statement
        $result = $stmt->get_result(); // Get the result from the executed statement

        if ($result->num_rows == 1) { // Check if exactly one admin user is found
            $admin = $result->fetch_assoc(); // Fetch admin data as an associative array
            // Verify the provided password with the hashed password in the database
            if (password_verify($password, $admin['password'])) {
                // Set session variables after successful login
                $_SESSION['admin_id'] = $admin['id']; // Set admin_id in session
                $_SESSION['admin_username'] = $admin['username']; // Pastikan username disimpan di sesi

                // Update last login timestamp for admin (optional)
                // Menggunakan nama tabel 'users'
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $admin['id']);

                // Set flash message for successful login
                set_flash_message('success', 'Login Admin Berhasil! Selamat datang, ' . htmlspecialchars($admin['username']) . '!');
                redirect('dashboard_admin.php'); // Redirect to admin dashboard after successful login
                exit();
            } else {
                $error_message = "Email atau password salah."; // Pesan yang lebih umum untuk keamanan
            }
        } else {
            $error_message = "Email atau password salah."; // Pesan yang lebih umum untuk keamanan
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoRako - Login Admin</title>
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
            padding-left: 1rem; /* Standard padding */
            padding-right: 2.5rem; /* Right padding for eye icon */
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
            color: #bdbdbd; /* Eye icon color */
            z-index: 10;
            transition: color 0.2s ease-in-out; /* Color animation on hover */
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

        /* Spinner animation */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .fa-spinner {
            animation: spin 1s linear infinite;
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
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 md:p-8">

    <div class="flex flex-col lg:flex-row w-full max-w-6xl bg-white shadow-xl rounded-xl overflow-hidden">

        <div class="lg:w-2/5 green-bg-primary text-white p-8 md:p-10 lg:p-12 flex flex-col justify-between relative rounded-l-xl lg:rounded-r-none">

            <div class="my-auto text-center lg:text-left">
                <h1 class="text-3xl md:text-4xl lg:text-4xl font-bold mb-6 leading-tight">
                    Selamat Datang di Dashboard Admin GoRako
                </h1>
                <p class="text-lg mb-8 opacity-90">
                    Masuk untuk mengelola platform edukasi pengelolaan sampah.
                </p>

                <ul class="space-y-5 text-lg">
                    <li class="flex items-center">
                        <i class="fas fa-chart-line text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Statistik & Laporan</span><br>
                            Lihat data dan laporan penggunaan platform
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-users-cog text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Manajemen Pengguna</span><br>
                            Kelola akun pengguna dan peran
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-book-open text-2xl mr-3"></i>
                        <span>
                            <span class="font-semibold">Konten & Kursus</span><br>
                            Perbarui materi edukasi dan kursus
                        </span>
                    </li>
                </ul>
            </div>

            <div class="mt-8 lg:mt-12 flex justify-between items-center text-sm opacity-80">
                <span>© 2023 GoRako. All rights reserved.</span>
                <div class="flex space-x-4">
                    <a href="#" class="hover:text-gray-200" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="hover:text-gray-200" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="hover:text-gray-200" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>

        <div class="lg:w-3/5 p-8 md:p-10 lg:p-12 flex flex-col justify-center">
            <div class="text-center mb-8">
                <div class="bg-gray-100 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-user-shield text-4xl green-text-primary welcome-icon-animate"></i>
                </div>
                <h2 class="text-3xl font-bold text-gray-800 mb-2">Login Admin GoRako</h2>
                <p class="text-gray-600">Masuk ke akun admin Anda</p>
            </div>

            <form id="loginForm" class="space-y-6" method="POST" action="">
                <div>
                    <label for="loginEmail" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Email</label>
                    <div class="relative">
                        <input type="email" id="loginEmail" name="email" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="nama@admin.com" required aria-label="Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <p class="text-red-500 text-sm mt-1 <?php echo ($error_message === 'Email atau password salah.' && !empty($_POST)) ? '' : 'hidden'; ?>" id="loginEmailError"><?php echo ($error_message === 'Email atau password salah.' && !empty($_POST)) ? $error_message : ''; ?></p>
                </div>
                <div>
                    <label for="loginPassword" class="block text-gray-700 text-sm font-medium mb-2 sr-only">Kata Sandi</label>
                    <div class="relative">
                        <input type="password" id="loginPassword" name="password" class="form-input w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Masukkan kata sandi" required aria-label="Kata Sandi">
                        <span class="toggle-password" data-target="loginPassword">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    <p class="text-red-500 text-sm mt-1 <?php echo ($error_message === 'Email atau password salah.' && !empty($_POST)) ? '' : 'hidden'; ?>" id="loginPasswordError"><?php echo ($error_message === 'Email atau password salah.' && !empty($_POST)) ? $error_message : ''; ?></p>
                </div>

                <div class="flex justify-between items-center text-sm">
                    <div class="flex items-center">
                        <input type="checkbox" id="rememberMe" class="checkbox-custom mr-2">
                        <label for="rememberMe" class="text-gray-700">Ingat saya</label>
                    </div>
                    <a href="#" class="green-text-primary font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-green-500 rounded">Lupa kata sandi?</a>
                </div>

                <button type="submit" id="loginSubmitBtn" class="w-full green-bg-primary text-white py-3 rounded-lg font-semibold text-lg green-button-hover flex items-center justify-center transition duration-300 focus:outline-none focus:ring-2 focus:ring-green-500">
                    Masuk <i class="fas fa-arrow-right ml-2"></i>
                    <span class="ml-2 hidden" id="loginSpinner"><i class="fas fa-spinner fa-spin"></i></span>
                </button>
            </form>

            <p class="mt-8 text-center">
                <a href="login.php" class="text-gray-500 text-sm hover:underline focus:outline-none focus:ring-2 focus:ring-gray-300 rounded"><i class="fas fa-arrow-left mr-2"></i> Login User</a>
            </p>
        </div>
    </div>

    <div id="custom-alert" class="fixed top-5 right-5 z-50 p-4 rounded-lg shadow-lg transform transition-all duration-500 ease-in-out hidden opacity-0 translate-y-full">
        <div class="flex items-center">
            <i id="custom-alert-icon" class="text-2xl mr-3"></i>
            <p id="custom-alert-message" class="font-semibold text-lg"></p>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const loginEmailInput = document.getElementById('loginEmail');
        const loginPasswordInput = document.getElementById('loginPassword');
        const loginEmailError = document.getElementById('loginEmailError');
        const loginPasswordError = document.getElementById('loginPasswordError');
        const loginSubmitBtn = document.getElementById('loginSubmitBtn');
        const loginSpinner = document.getElementById('loginSpinner');
        const loginWelcomeIcon = document.querySelector('.welcome-icon-animate');

        // PHP error data passed to JavaScript
        const phpErrorMessage = '<?php echo htmlspecialchars($error_message); ?>';
        const phpErrorType = 'error';

        // Function to show custom alerts
        function showCustomAlert(message, type = 'success') {
            const customAlert = document.getElementById('custom-alert');
            const customAlertMessage = document.getElementById('custom-alert-message');
            const customAlertIcon = document.getElementById('custom-alert-icon');

            // Reset classes
            customAlert.classList.remove('custom-alert-success', 'custom-alert-error');
            customAlertIcon.classList.remove('fa-check-circle', 'fa-times-circle');
            customAlertIcon.style.color = '';

            customAlertMessage.textContent = message;

            if (type === 'success') {
                customAlert.classList.add('custom-alert-success');
                customAlertIcon.classList.add('fas', 'fa-check-circle');
                customAlertIcon.style.color = '#28a745';
            } else if (type === 'error') {
                customAlert.classList.add('custom-alert-error');
                customAlertIcon.classList.add('fas', 'fa-times-circle');
                customAlertIcon.style.color = '#dc3545';
            }

            // Ensure it's not hidden before showing
            customAlert.classList.remove('hidden');
            // Trigger reflow to restart transition if already visible
            void customAlert.offsetWidth;
            customAlert.classList.add('custom-alert-show');

            // Automatically hide after 3 seconds
            setTimeout(() => {
                customAlert.classList.remove('custom-alert-show');
                customAlert.classList.add('opacity-0', 'translate-y-full');
                setTimeout(() => {
                    customAlert.classList.add('hidden');
                }, 500); // Wait for fade-out transition
            }, 3000);
        }

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
            const inputField = inputContainer ? inputContainer.querySelector('input') : null;

            if (inputField) {
                inputField.classList.add('border-red-500');
                inputField.classList.remove('border-gray-300', 'border-green-500');
            }
        }

        function hideError(element) {
            element.textContent = '';
            element.classList.add('hidden');
            const inputContainer = element.previousElementSibling;
            const inputField = inputContainer ? inputContainer.querySelector('input') : null;

            if (inputField) {
                inputField.classList.remove('border-red-500');
                inputField.classList.add('border-gray-300'); // Reset to default border
            }
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function validatePassword(password) {
            // Minimal 8 characters
            return password.length >= 8;
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
        }

        // --- Login Form Validation ---
        loginEmailInput.addEventListener('input', () => {
            // If there's an existing PHP error for email, clear it on input
            if (loginEmailError.textContent.trim() === 'Email atau password salah.') {
                loginEmailError.textContent = '';
                loginEmailError.classList.add('hidden');
                loginEmailInput.classList.remove('border-red-500');
            }

            if (!isValidEmail(loginEmailInput.value)) {
                showError(loginEmailError, 'Masukkan email yang valid.');
            } else {
                hideError(loginEmailError);
            }
        });

        loginPasswordInput.addEventListener('input', () => {
             // If there's an existing PHP error for password, clear it on input
            if (loginPasswordError.textContent.trim() === 'Email atau password salah.') { // Pesan error sudah diubah lebih umum
                loginPasswordError.textContent = '';
                loginPasswordError.classList.add('hidden');
                loginPasswordInput.classList.remove('border-red-500');
            }

            if (loginPasswordInput.value.length < 8) {
                showError(loginPasswordError, 'Kata sandi minimal 8 karakter.');
            } else {
                hideError(loginPasswordError);
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Animate in the welcome icon on page load
            loginWelcomeIcon.classList.add('welcome-icon-animate');

            // Check for PHP errors on page load and display them
            if (phpErrorMessage.trim() !== '') {
                showCustomAlert(phpErrorMessage, phpErrorType);

                // Highlight fields with errors
                // Karena pesan error sekarang lebih umum, kita bisa menandai kedua field atau tidak sama sekali
                loginEmailInput.classList.add('border-red-500'); // Asumsikan error terjadi pada kredensial
                loginPasswordInput.classList.add('border-red-500');
                loginEmailError.classList.remove('hidden'); // Memastikan pesan error HTML terlihat
                loginPasswordError.classList.remove('hidden'); // Memastikan pesan error HTML terlihat
            }
            loginEmailInput.focus(); // Set focus to email input on load
        });

        loginForm.addEventListener('submit', (e) => {
            let valid = true;

            // Re-validate all fields on submit
            if (!isValidEmail(loginEmailInput.value)) {
                showError(loginEmailError, 'Masukkan email yang valid.');
                valid = false;
            } else {
                hideError(loginEmailError);
            }

            if (!validatePassword(loginPasswordInput.value)) {
                showError(loginPasswordError, 'Kata sandi minimal 8 karakter.');
                valid = false;
            } else {
                hideError(loginPasswordError);
            }

            if (!valid) {
                e.preventDefault(); // Stop form submission if validation fails
                showCustomAlert('Mohon perbaiki kesalahan di formulir.', 'error');
            } else {
                loginSubmitBtn.disabled = true;
                loginSpinner.classList.remove('hidden');
                loginSubmitBtn.innerHTML = 'Masuk... <span class="ml-2"><i class="fas fa-spinner fa-spin"></i></span>';
                // Allow form to submit if validation passes
            }
        });
    </script>
</body>
</html>