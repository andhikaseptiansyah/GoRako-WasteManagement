<?php
// Konfigurasi Koneksi Database
$servername = "localhost";
$username = "root"; // Sesuai username MySQL Anda di XAMPP
$password = "";     // Sesuai password MySQL Anda di XAMPP (default: kosong)
$dbname = "GoRako";

// Buat koneksi
$conn = new mysqli($servername, $username, $password, $dbname);

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

$message = ''; // Variabel untuk menyimpan pesan sukses/error
$message_type = ''; // 'success' atau 'error'

// Ambil data hadiah jika ada parameter edit_id di URL (untuk mode edit)
$edit_reward_data = null;
if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM rewards WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_reward_data = $result->fetch_assoc();
    } else {
        $message = "Hadiah tidak ditemukan untuk diedit.";
        $message_type = "error";
    }
    $stmt->close();
}

// Tangani pengiriman formulir (baik tambah baru atau update)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $reward_id = $_POST['reward_id'] ?? null; // Akan ada jika mode edit
    $name = $_POST['reward_name'] ?? '';
    $description = $_POST['reward_description'] ?? '';
    $stock = (int)($_POST['stock_quantity'] ?? 0);
    $points_needed = (int)($_POST['points_required'] ?? 0);
    $category = $_POST['reward_category'] ?? '';
    $expiry_date = null; // Expiry date removed from form, so always null
    $feature_on_homepage = isset($_POST['feature_on_homepage']) ? 1 : 0;
    $limited_time_offer = isset($_POST['limited_time_offer']) ? 1 : 0;
    $notify_users = isset($_POST['notify_users']) ? 1 : 0;

    // No image handling here

    // Validasi Sisi Server (PHP)
    if (empty($name)) {
        $message = "Nama hadiah tidak boleh kosong.";
        $message_type = "error";
    } elseif ($stock < 0) {
        $message = "Jumlah stok tidak valid.";
        $message_type = "error";
    } elseif ($points_needed < 0) {
        $message = "Poin yang dibutuhkan tidak valid.";
        $message_type = "error";
    } else {
        $expiry_date_db = !empty($expiry_date) ? $expiry_date : null;

        if ($reward_id) { // Mode EDIT (UPDATE)
            $stmt = $conn->prepare("UPDATE rewards SET name = ?, description = ?, stock = ?, points_needed = ?, category = ?, expiry_date = ?, feature_on_homepage = ?, limited_time_offer = ?, notify_users = ? WHERE id = ?");
            $stmt->bind_param("ssiissiiii", $name, $description, $stock, $points_needed, $category, $expiry_date_db, $feature_on_homepage, $limited_time_offer, $notify_users, $reward_id);
            if ($stmt->execute()) {
                $message = "Hadiah berhasil diperbarui!";
                $message_type = "success";
            } else {
                $message = "Error memperbarui hadiah: " . $stmt->error;
                $message_type = "error";
            }
        } else { // Mode TAMBAH BARU (INSERT)
            $stmt = $conn->prepare("INSERT INTO rewards (name, description, stock, points_needed, category, expiry_date, feature_on_homepage, limited_time_offer, notify_users) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiissiii", $name, $description, $stock, $points_needed, $category, $expiry_date_db, $feature_on_homepage, $limited_time_offer, $notify_users);
            if ($stmt->execute()) {
                $message = "Hadiah berhasil ditambahkan!";
                $message_type = "success";
            } else {
                $message = "Error menambahkan hadiah: " . $stmt->error;
                $message_type = "error";
            }
        }
        $stmt->close();
        // Redirect back to dashboard_admin.php with a message
        header("Location: dashboard_admin.php?message=" . urlencode($message) . "&type=" . $message_type . "#kelola-hadiah");
        exit();
    }
}

// Ambil data rewards untuk ditampilkan
$rewards_from_db = [];
$sql = "SELECT id, name, description, stock, points_needed, category, expiry_date, feature_on_homepage, limited_time_offer, notify_users FROM rewards ORDER BY added_date DESC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $rewards_from_db[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reward Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        /* CSS Variables for consistent theming */
        :root {
            --primary-color: #1890ff; /* Blue for primary actions */
            --primary-hover-color: #096dd9;
            --secondary-color: #f0f2f5;
            --secondary-hover-color: #e0e2e5;
            --text-dark: #333;
            --text-medium: #555;
            --text-light: #777;
            --border-light: #eee;
            --border-medium: #ddd;
            --bg-light: #f9f9f9;
            --bg-body: #f0f2f5; /* Light grey body background */
            --danger-color: #ff4d4f;
            --danger-hover-color: #cf1322;
            --success-color: #52c41a; /* Green for success */
            --success-light: #f6ffed;
            --border-radius-base: 8px;
            --border-radius-large: 12px;
            --box-shadow-base: 0 4px 20px rgba(0, 0, 0, 0.08);
            --box-shadow-card: 0 2px 8px rgba(0, 0, 0, 0.05);
            --box-shadow-hover: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: var(--bg-body);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 20px;
            box-sizing: border-box;
            line-height: 1.6;
            color: var(--text-medium);
        }

        .container {
            background-color: #fff;
            border-radius: var(--border-radius-large);
            box-shadow: var(--box-shadow-base);
            width: 100%;
            max-width: 1400px; /* Even wider container for 3 columns */
            padding: 30px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        h1 {
            font-size: 1.8rem; /* Larger main title */
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 25px;
            text-align: center;
        }

        /* New: Main Layout Container for 3 Columns */
        .main-layout-container {
            display: flex;
            gap: 25px; /* Gap between major columns */
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            align-items: flex-start; /* Align columns to the top */
        }

        .form-and-preview-column {
            flex: 2; /* Takes 2 parts of space, e.g., ~50% */
            min-width: 550px; /* Minimum width for this column */
            display: flex;
            flex-direction: column;
            gap: 25px; /* Gap between form and preview sections */
        }

        .summary-and-recent-column {
            flex: 1; /* Takes 1 part of space, e.g., ~25% each for summary and recent */
            min-width: 350px; /* Minimum width for this column */
            display: flex;
            flex-direction: column;
            gap: 25px; /* Gap between summary and recent rewards */
        }

        /* General Section Styling */
        .form-section, .preview-section, .recent-rewards-section, .reward-review-summary {
            background-color: #fff;
            border-radius: var(--border-radius-large);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--box-shadow-card);
            /* Removed margin-bottom here, relying on flex gap */
        }

        .form-section h2, .preview-section h2, .recent-rewards-section h2, .reward-review-summary h2 {
            font-size: 1.4rem; /* Section titles */
            font-weight: 600;
            color: var(--text-dark);
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-light);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9375rem;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-medium);
            border-radius: var(--border-radius-base);
            font-size: 1rem;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.2); /* Blue shadow */
            outline: none;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }

        .form-row .half-width {
            flex: 1;
            min-width: 200px; /* Minimum width for columns */
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.875rem;
            margin-top: 5px;
            min-height: 1em;
        }

        input.is-invalid, textarea.is-invalid, select.is-invalid {
            border-color: var(--danger-color) !important;
        }

        /* Additional Options Checkboxes */
        .additional-options {
            margin-top: 10px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .checkbox-group label {
            margin-bottom: 0;
            margin-left: 8px; /* Space between checkbox and label */
            cursor: pointer;
            font-weight: 500;
        }
        .checkbox-group input[type="checkbox"] {
            transform: scale(1.1);
            cursor: pointer;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }

        .btn-draft {
            background-color: var(--secondary-color);
            color: var(--text-medium);
            border: 1px solid var(--border-medium);
        }
        .btn-draft:hover {
            background-color: var(--secondary-hover-color);
        }

        /* Preview Section */
        .preview-section {
            margin-bottom: 20px; /* Contoh: Menambahkan margin bawah */
    /* Atau properti lain yang Anda inginkan */
}
        .preview-content {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .preview-details {
            flex-grow: 1;
        }
        .preview-details h3 {
            font-size: 1.25rem; /* Larger preview title */
            font-weight: 700;
            color: var(--text-dark);
            margin-top: 0;
            margin-bottom: 10px;
        }
        .preview-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        .preview-meta div span {
            display: block;
            font-size: 0.875rem;
            color: var(--text-light);
        }
        .preview-meta div strong {
            font-size: 1rem;
            color: var(--text-dark);
        }
        .preview-description {
            font-size: 0.9375rem;
            color: var(--text-medium);
            white-space: pre-wrap; /* Preserve formatting */
        }
        .placeholder-preview {
            text-align: center;
            color: var(--text-light);
            font-style: italic;
            padding: 30px;
            background-color: var(--bg-light);
            border-radius: var(--border-radius-base);
            width: 100%; /* Take full width in preview section */
        }

        /* Reward Review Summary */
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed var(--border-light);
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-item span:first-child {
            font-weight: 500;
            color: var(--text-dark);
        }
        .summary-item span:last-child {
            font-weight: 600;
            color: var(--primary-color);
        }
        .summary-item.total-line {
            font-size: 1.1rem;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-medium);
        }
        .summary-item.total-line span:last-child {
            font-size: 1.2rem;
        }
        .no-summary-data {
            text-align: center;
            color: var(--text-light);
            font-style: italic;
        }

        /* Recent Rewards Section */
        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .reward-card {
            background-color: #fff;
            border: 1px solid var(--border-light);
            border-radius: var(--border-radius-base);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            transition: all 0.2s ease;
            position: relative;
        }
        .reward-card:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-2px);
        }
        .reward-card-content {
            padding: 15px;
        }
        .reward-card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-top: 0;
            margin-bottom: 8px;
            padding-right: 40px; /* Space for actions */
        }
        .reward-card p {
            margin: 0 0 5px 0;
            font-size: 0.9rem;
            color: var(--text-medium);
        }
        .reward-card .points-stock {
            font-weight: 600;
            color: var(--primary-color); /* Highlight points */
        }
        .reward-card .reward-card-actions {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .reward-card .reward-card-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.25rem;
            color: var(--text-light);
            padding: 5px;
            border-radius: 50%;
            transition: all 0.2s ease;
        }
        .reward-card .reward-card-actions button:hover {
            color: var(--text-dark);
            background-color: var(--secondary-color);
        }
        .reward-card .reward-card-actions .delete-btn:hover {
            color: var(--danger-color);
            background-color: rgba(255, 77, 79, 0.1);
        }
        .no-rewards-placeholder {
            text-align: center;
            color: var(--text-light);
            font-style: italic;
            padding: 30px;
            background-color: var(--bg-light);
            border-radius: var(--border-radius-base);
            grid-column: 1 / -1; /* Span all columns in grid */
        }

        /* Responsive adjustments */
        @media (max-width: 992px) { /* Adjust breakpoint for main content layout */
            .main-layout-container {
                flex-direction: column;
                gap: 15px;
            }
            .form-and-preview-column, .summary-and-recent-column {
                min-width: unset;
                flex: none;
                width: 100%;
            }
            /* Ensure sections within columns still have spacing on smaller screens */
            .form-and-preview-column, .summary-and-recent-column {
                gap: 15px; /* Adjust gap for vertical stacking */
            }
        }
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            .form-section, .preview-section, .recent-rewards-section, .reward-review-summary {
                padding: 15px;
            }
            h1 {
                font-size: 1.5rem;
            }
            .form-section h2, .preview-section h2, .recent-rewards-section h2, .reward-review-summary h2 {
                font-size: 1.2rem;
            }
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            .form-row .half-width {
                min-width: unset;
            }
            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }
            .preview-content {
                flex-direction: column;
                align-items: center;
            }
            .rewards-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reward Management</h1>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $message_type; ?>" style="padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; background-color: <?php echo $message_type === 'success' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $message_type === 'success' ? '#155724' : '#721c24'; ?>; border: 1px solid <?php echo $message_type === 'success' ? '#c3e6cb' : '#f5c6cb'; ?>;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="main-layout-container">
            <div class="form-and-preview-column">
                <div class="form-section">
                    <h2><?php echo $edit_reward_data ? 'Edit Reward' : 'Add New Reward'; ?></h2>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" id="reward-form">
                        <?php if ($edit_reward_data): ?>
                            <input type="hidden" name="reward_id" value="<?php echo htmlspecialchars($edit_reward_data['id']); ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="reward-name">Reward Name</label>
                            <input type="text" id="reward-name" name="reward_name" placeholder="e.g., Discount Voucher, Free E-book" value="<?php echo htmlspecialchars($edit_reward_data['name'] ?? ''); ?>" required>
                            <div class="error-message" id="error-reward-name"></div>
                        </div>
                        <div class="form-group">
                            <label for="reward-description">Description</label>
                            <textarea id="reward-description" name="reward_description" placeholder="Provide a brief description of the reward"><?php echo htmlspecialchars($edit_reward_data['description'] ?? ''); ?></textarea>
                            <div class="error-message" id="error-reward-description"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group half-width">
                                <label for="stock-quantity">Stock Quantity</label>
                                <input type="number" id="stock-quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($edit_reward_data['stock'] ?? '1'); ?>" required>
                                <div class="error-message" id="error-stock-quantity"></div>
                            </div>
                            <div class="form-group half-width">
                                <label for="points-required">Points Required</label>
                                <input type="number" id="points-required" name="points_required" min="0" value="<?php echo htmlspecialchars($edit_reward_data['points_needed'] ?? '0'); ?>" required>
                                <div class="error-message" id="error-points-required"></div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group half-width">
                                <label for="reward-category">Category</label>
                                <select id="reward-category" name="reward_category">
                                    <option value="Physical Product" <?php echo (($edit_reward_data['category'] ?? '') === 'Physical Product') ? 'selected' : ''; ?>>Physical Product</option>
                                    <option value="Digital Product" <?php echo (($edit_reward_data['category'] ?? '') === 'Digital Product') ? 'selected' : ''; ?>>Digital Product</option>
                                    <option value="Service Voucher" <?php echo (($edit_reward_data['category'] ?? '') === 'Service Voucher') ? 'selected' : ''; ?>>Service Voucher</option>
                                    <option value="Donation" <?php echo (($edit_reward_data['category'] ?? '') === 'Donation') ? 'selected' : ''; ?>>Donation</option>
                                    <option value="Other" <?php echo (($edit_reward_data['category'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="additional-options">
                            <div class="checkbox-group">
                                <input type="checkbox" id="feature-on-homepage" name="feature_on_homepage" <?php echo (($edit_reward_data['feature_on_homepage'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                <label for="feature-on-homepage">Feature this reward on homepage</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" id="limited-time-offer" name="limited_time_offer" <?php echo (($edit_reward_data['limited_time_offer'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                <label for="limited-time-offer">Limited time offer</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" id="notify-users" name="notify_users" <?php echo (($edit_reward_data['notify_users'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                <label for="notify-users">Notify users about this new reward</label>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary btn-draft" id="save-draft-btn">Save as Draft</button>
                            <button type="submit" class="btn btn-primary" id="add-reward-btn"><?php echo $edit_reward_data ? 'Update Reward' : 'Add Reward'; ?></button>
                        </div>
                    </form>
                </div>

                <div class="preview-section">
                    <h2>Reward Preview</h2>
                    <div id="reward-preview-container" class="preview-content">
                        <div class="placeholder-preview">
                            <p>Start filling the form to see a preview of your reward here.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="summary-and-recent-column">
                <div class="reward-review-summary">
                    <h2>Reward Review Summary</h2>
                    <div id="summary-content">
                        <div class="no-summary-data">No rewards added yet to summarize.</div>
                    </div>
                </div>

                <div class="recent-rewards-section">
                    <h2>Recent Rewards</h2>
                    <div class="rewards-grid" id="recent-rewards-grid">
                        <div class="no-rewards-placeholder" id="no-rewards-placeholder">
                            <p>No rewards added yet.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <template id="reward-card-template">
        <div class="reward-card" data-reward-id="">
            <div class="reward-card-actions">
                <button type="button" class="delete-btn" title="Delete Reward" aria-label="Delete Reward">
                    <span class="material-icons">delete</span>
                </button>
                </div>
            <div class="reward-card-content">
                <h3 class="reward-card-name"></h3>
                <p>Points: <span class="points-stock points-needed-display"></span></p>
                <p>Stock: <span class="points-stock stock-display"></span></p>
            </div>
        </div>
    </template>

    <template id="reward-preview-detail-template">
        <div class="preview-details">
            <h3 id="preview-name"></h3>
            <div class="preview-meta">
                <div><span>Category:</span> <strong id="preview-category"></strong></div>
                <div><span>Stock:</span> <strong id="preview-stock"></strong></div>
                <div><span>Points:</span> <strong id="preview-points"></strong></div>
            </div>
            <p id="preview-description" class="preview-description"></p>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- Elements ---
            const rewardForm = document.getElementById('reward-form'); // Added for form submission handling
            const rewardNameInput = document.getElementById('reward-name');
            const rewardDescriptionTextarea = document.getElementById('reward-description');
            const stockQuantityInput = document.getElementById('stock-quantity');
            const pointsRequiredInput = document.getElementById('points-required');
            const rewardCategorySelect = document.getElementById('reward-category');
            // Removed: const expiryDateInput = document.getElementById('expiry-date');
            // Removed: const rewardImageFileInput = document.getElementById('reward-image-file');
            // Removed: const imageUploadArea = document.getElementById('image-upload-area');
            // Removed: const uploadedImagePreview = document.getElementById('uploaded-image-preview');

            const featureOnHomepageCheckbox = document.getElementById('feature-on-homepage');
            const limitedTimeOfferCheckbox = document.getElementById('limited-time-offer');
            const notifyUsersCheckbox = document.getElementById('notify-users');

            const saveDraftBtn = document.getElementById('save-draft-btn');
            const addRewardBtn = document.getElementById('add-reward-btn');

            const rewardPreviewContainer = document.getElementById('reward-preview-container');
            const recentRewardsGrid = document.getElementById('recent-rewards-grid');
            const noRewardsPlaceholder = document.getElementById('no-rewards-placeholder');
            const rewardReviewSummaryContent = document.getElementById('summary-content');

            // Error message elements
            const errorRewardName = document.getElementById('error-reward-name');
            const errorRewardDescription = document.getElementById('error-reward-description');
            const errorStockQuantity = document.getElementById('error-stock-quantity');
            const errorPointsRequired = document.getElementById('error-points-required');
            // Removed: const errorExpiryDate = document.getElementById('error-expiry-date');
            // Removed: const errorRewardImage = document.getElementById('error-reward-image');

            // Templates
            const rewardCardTemplate = document.getElementById('reward-card-template');
            const rewardPreviewDetailTemplate = document.getElementById('reward-preview-detail-template');

            // --- Data Model (Ini akan di-override oleh data dari PHP) ---
            let rewards = <?php echo json_encode($rewards_from_db); ?>;
            let editRewardData = <?php echo json_encode($edit_reward_data); ?>; // NEW: Ambil data edit dari PHP

            function clearForm() {
                rewardNameInput.value = '';
                rewardDescriptionTextarea.value = '';
                stockQuantityInput.value = '1';
                pointsRequiredInput.value = '0';
                rewardCategorySelect.value = 'Physical Product';
                // Removed: expiryDateInput.value = '';
                // Removed: rewardImageFileInput.value = '';
                // Removed: uploadedImagePreview.style.display = 'none';
                // Removed: uploadedImagePreview.src = '#';
                // Removed: imageUploadArea.classList.remove('has-image');
                
                featureOnHomepageCheckbox.checked = false;
                limitedTimeOfferCheckbox.checked = false;
                notifyUsersCheckbox.checked = false;

                clearAllErrors();
                updatePreview();
            }

            function clearAllErrors() {
                document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
                document.querySelectorAll('input.is-invalid, textarea.is-invalid, select.is-invalid')
                    .forEach(el => el.classList.remove('is-invalid'));
            }

            function validateInput(inputElement, errorElement, checkValue = true, min = null, max = null) {
                let isValid = true;
                inputElement.classList.remove('is-invalid');
                errorElement.textContent = '';

                if (checkValue && !inputElement.value.trim()) {
                    inputElement.classList.add('is-invalid');
                    errorElement.textContent = 'This field cannot be empty.';
                    isValid = false;
                } else if (inputElement.type === 'number') {
                    const value = parseFloat(inputElement.value);
                    if (isNaN(value) || (min !== null && value < min) || (max !== null && value > max)) {
                        inputElement.classList.add('is-invalid');
                        errorElement.textContent = `Please enter a valid number. Min: ${min}, Max: ${max}.`;
                        isValid = false;
                    }
                }
                return isValid;
            }

            function validateFormJS() { // Renamed to avoid conflict if validateForm() is used by PHP submission
                clearAllErrors();
                let isValid = true;

                isValid = validateInput(rewardNameInput, errorRewardName, true) && isValid;
                isValid = validateInput(rewardDescriptionTextarea, errorRewardDescription, true) && isValid;
                isValid = validateInput(stockQuantityInput, errorStockQuantity, true, 0) && isValid;
                isValid = validateInput(pointsRequiredInput, errorPointsRequired, true, 0) && isValid;
                
                return isValid;
            }

            // --- Preview Functions ---
            function updatePreview() {
                const name = rewardNameInput.value.trim();
                const description = rewardDescriptionTextarea.value.trim();
                const stock = stockQuantityInput.value;
                const points = pointsRequiredInput.value;
                const category = rewardCategorySelect.value;
                
                if (!name && !description && !stock && !points) { // Simplified condition
                    rewardPreviewContainer.innerHTML = '';
                    const placeholder = document.createElement('div');
                    placeholder.classList.add('placeholder-preview');
                    placeholder.innerHTML = '<p>Start filling the form to see a preview of your reward here.</p>';
                    rewardPreviewContainer.appendChild(placeholder);
                    return;
                }
                
                rewardPreviewContainer.innerHTML = '';
                const previewClone = document.importNode(rewardPreviewDetailTemplate.content, true);

                const previewName = previewClone.querySelector('#preview-name');
                const previewCategory = previewClone.querySelector('#preview-category');
                const previewStock = previewClone.querySelector('#preview-stock');
                const previewPoints = previewClone.querySelector('#preview-points');
                const previewDescription = previewClone.querySelector('#preview-description');

                previewName.textContent = name || 'Reward Name';
                previewCategory.textContent = category || '-';
                previewStock.textContent = stock || '0';
                previewPoints.textContent = points || '0';
                previewDescription.textContent = description || 'No description provided.';

                rewardPreviewContainer.appendChild(previewClone);
            }

            // --- Reward Review Summary Functions ---
            function updateRewardSummary() {
                rewardReviewSummaryContent.innerHTML = '';

                if (rewards.length === 0) {
                    const placeholder = document.createElement('div');
                    placeholder.classList.add('no-summary-data');
                    placeholder.textContent = 'No rewards added yet to summarize.';
                    rewardReviewSummaryContent.appendChild(placeholder);
                    return;
                }

                let totalRewards = rewards.length;
                let totalStock = 0;
                let featuredRewards = 0;
                let limitedTimeOffers = 0;
                let uniqueCategories = new Set();
                let highestPoints = 0;
                let lowestPoints = Infinity;

                rewards.forEach(reward => {
                    totalStock += parseInt(reward.stock); // Ensure stock is a number
                    if (parseInt(reward.feature_on_homepage) === 1) featuredRewards++;
                    if (parseInt(reward.limited_time_offer) === 1) limitedTimeOffers++;
                    uniqueCategories.add(reward.category);
                    highestPoints = Math.max(highestPoints, parseInt(reward.points_needed));
                    lowestPoints = Math.min(lowestPoints, parseInt(reward.points_needed));
                });

                rewardReviewSummaryContent.innerHTML = `
                    <div class="summary-item">
                        <span>Total Rewards:</span>
                        <span>${totalRewards}</span>
                    </div>
                    <div class="summary-item">
                        <span>Total Stock Available:</span>
                        <span>${totalStock}</span>
                    </div>
                    <div class="summary-item">
                        <span>Featured Rewards:</span>
                        <span>${featuredRewards}</span>
                    </div>
                    <div class="summary-item">
                        <span>Limited Offers:</span>
                        <span>${limitedTimeOffers}</span>
                    </div>
                    <div class="summary-item">
                        <span>Unique Categories:</span>
                        <span>${uniqueCategories.size}</span>
                    </div>
                    <div class="summary-item">
                        <span>Highest Points Needed:</span>
                        <span>${highestPoints}</span>
                    </div>
                    <div class="summary-item">
                        <span>Lowest Points Needed:</span>
                        <span>${rewards.length > 0 ? lowestPoints : 'N/A'}</span>
                    </div>
                `;
            }

            // --- Recent Rewards Display Functions ---
            function renderRecentRewards() {
                recentRewardsGrid.innerHTML = '';
                if (rewards.length === 0) {
                    const placeholderDiv = document.createElement('div');
                    placeholderDiv.classList.add('no-rewards-placeholder');
                    placeholderDiv.innerHTML = '<p>No rewards added yet.</p>';
                    recentRewardsGrid.appendChild(placeholderDiv);
                } else {
                    rewards.forEach((reward) => { // Use reward object directly
                        const cardClone = document.importNode(rewardCardTemplate.content, true);
                        const rewardCard = cardClone.querySelector('.reward-card');
                        const cardName = cardClone.querySelector('.reward-card-name');
                        const cardPoints = cardClone.querySelector('.points-needed-display');
                        const cardStock = cardClone.querySelector('.stock-display');
                        
                        rewardCard.dataset.rewardId = reward.id; // Store actual reward ID from DB

                        cardName.textContent = reward.name;
                        cardPoints.textContent = reward.points_needed; // Use points_needed from DB
                        cardStock.textContent = reward.stock;
                        
                        // Attach delete event listener
                        const deleteBtn = cardClone.querySelector('.delete-btn');
                        deleteBtn.addEventListener('click', () => {
                            if (confirm('Are you sure you want to delete this reward? This action cannot be undone.')) {
                                fetch('dashboard_admin.php', { // Send delete request to dashboard_admin.php
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: `action=delete_reward&id=${reward.id}`
                                })
                                .then(response => response.text())
                                .then(data => {
                                    if (data.includes("success")) {
                                        alert('Reward deleted successfully!');
                                        location.reload(); // Reload to update UI
                                    } else {
                                        alert('Failed to delete reward: ' + data);
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('An error occurred while deleting the reward.');
                                });
                            }
                        });

                        recentRewardsGrid.appendChild(cardClone);
                    });
                }
            }

            // --- Event Handlers ---

            // Form input change handlers for live preview and validation (on blur)
            rewardNameInput.addEventListener('input', updatePreview);
            rewardDescriptionTextarea.addEventListener('input', updatePreview);
            stockQuantityInput.addEventListener('input', updatePreview);
            pointsRequiredInput.addEventListener('input', updatePreview);
            rewardCategorySelect.addEventListener('change', updatePreview);

            // Live validation on blur
            rewardNameInput.addEventListener('blur', () => validateInput(rewardNameInput, errorRewardName, true));
            rewardDescriptionTextarea.addEventListener('blur', () => validateInput(rewardDescriptionTextarea, errorRewardDescription, true));
            stockQuantityInput.addEventListener('blur', () => validateInput(stockQuantityInput, errorStockQuantity, true, 0));
            pointsRequiredInput.addEventListener('blur', () => validateInput(pointsRequiredInput, errorPointsRequired, true, 0));

            // Action Buttons
            saveDraftBtn.addEventListener('click', () => {
                // Konsep "Save as Draft" di sini mungkin berarti menyimpan ke database dengan status "draft"
                // atau hanya logging di frontend. Untuk contoh ini, kita hanya akan melakukan logging.
                // Jika ingin mengirim ke PHP untuk disimpan sebagai draft, Anda perlu AJAX atau form submission terpisah.
                const currentRewardDraft = {
                    name: rewardNameInput.value.trim(),
                    description: rewardDescriptionTextarea.value.trim(),
                    stock: parseInt(stockQuantityInput.value) || 0,
                    pointsNeeded: parseInt(pointsRequiredInput.value) || 0,
                    category: rewardCategorySelect.value,
                    expiryDate: null, // Expiry date removed
                    featureOnHomepage: featureOnHomepageCheckbox.checked,
                    limitedTimeOffer: limitedTimeOfferCheckbox.checked,
                    notifyUsers: notifyUsersCheckbox.checked
                };
                console.log("Reward saved as draft (frontend-only logging):", currentRewardDraft);
                alert('Reward saved as draft!');
            });

            // Add Reward button - now linked to PHP form submission
            addRewardBtn.addEventListener('click', (e) => {
                if (!validateFormJS()) { // Call JS validation before allowing submission
                    e.preventDefault(); // Prevent form submission if JS validation fails
                    alert('Please correct the errors in the form before adding/updating the reward.');
                }
                // If JS validation passes, form will naturally submit to PHP
            });

            // --- Initialization ---
            // NEW: Populate form if in edit mode
            if (editRewardData) {
                // The form action is already set in PHP for edit mode to include edit_id
                // No need to set it here again, as it's part of the HTML
                
                // Hidden inputs for reward_id are already rendered by PHP
                
                // Set form fields
                rewardNameInput.value = editRewardData.name;
                rewardDescriptionTextarea.value = editRewardData.description;
                stockQuantityInput.value = editRewardData.stock;
                pointsRequiredInput.value = editRewardData.points_needed;
                rewardCategorySelect.value = editRewardData.category;
                
                featureOnHomepageCheckbox.checked = editRewardData.feature_on_homepage == 1;
                limitedTimeOfferCheckbox.checked = editRewardData.limited_time_offer == 1;
                notifyUsersCheckbox.checked = editRewardData.notify_users == 1;

                // Update button text and section title dynamically on page load if in edit mode
                addRewardBtn.textContent = 'Update Reward';
                document.querySelector('.form-section h2').textContent = 'Edit Reward';
            }

            renderRecentRewards(); // Render rewards data from PHP initially
            updatePreview(); // Initial preview rendering
            updateRewardSummary(); // Initial summary rendering
        });
    </script>
</body>
</html>