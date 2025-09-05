<?php
// game_data.php

// Helper function to get upgrade cost
function getUpgradeCost($currentLevel, $upgradeCostTiers) {
    $nextLevel = $currentLevel + 1;
    foreach ($upgradeCostTiers as $tier) {
        if ($nextLevel >= $tier['level_min'] && $nextLevel <= $tier['level_max']) {
            return $tier['cost'];
        }
    }
    return PHP_INT_MAX; // Should not happen if tiers cover all levels up to cap
}

// Definisi konstanta GameConfig
const GameConfig = [
    'STARTING_MONEY' => 1000,
    'DAILY_WASTE_COUNT_MIN' => 7, // Diatur menjadi 7
    'DAILY_WASTE_COUNT_MAX' => 7, // Diatur menjadi 7
    'DISPOSAL_COST_PER_UNIT' => 5,
    'DAILY_MAINTENANCE_COST' => 50,
    'MESSAGE_DURATION' => 4000, // Client-side only
    'GAME_OVER_MONEY_THRESHOLD' => -100,
    'PROCESSING_ANIMATION_DURATION' => 700, // Client-side only
    'SHRED_ANIMATION_DURATION' => 300, // Client-side only
    'TRAVEL_ANIMATION_DURATION' => 400, // Client-side only
    'MESSAGE_LOG_MAX_ENTRIES' => 5,
    'MACHINE_COSTS' => [
        'Penghancur Plastik' => 0,
        'Mesin Cuci Plastik' => 200,
        'Mesin Pengering Plastik' => 300,
        'Pelebur & Pencetak Pelet' => 500,
        'Pencetak Botol Baru' => 700,
        'Pencetak Papan Komposit' => 800,
        'Mesin Cetak Produk Jadi' => 600
    ],
    'MONEY_PER_PROCESS' => 5,
    'UPGRADE_COST_TIERS' => [
        ['level_min' => 2, 'level_max' => 5, 'cost' => 20],
        ['level_min' => 6, 'level_max' => 25, 'cost' => 70],
        ['level_min' => 26, 'level_max' => 75, 'cost' => 100],
        ['level_min' => 76, 'level_max' => 100, 'cost' => 150]
    ],
    'UPGRADE_LEVEL_CAP' => 100,
    'SHOW_OTHER_GAMES_THRESHOLD' => 10000
];

// Definisi konstanta WasteTypes
const WasteTypes = [
    'PET' => ['name' => 'Botol PET (Air Mineral)', 'description' => 'Biasa ditemukan pada botol minuman jernih.', 'icon' => '💧'],
    'HDPE' => ['name' => 'Botol HDPE (Deterjen)', 'description' => 'Plastik tebal, buram, untuk botol susu atau deterjen.', 'icon' => '🧴'],
    'LDPE' => ['name' => 'Kantong Plastik LDPE', 'description' => 'Fleksibel, untuk kantong belanja atau pembungkus.', 'icon' => '🛍️'],
    'PP' => ['name' => 'Wadah Makanan PP', 'description' => 'Kuat dan tahan panas, untuk wadah makan atau tutup botol.', 'icon' => '🥡'],
    'PS' => ['name' => 'Styrofoam PS', 'description' => 'Ringan dan rapuh, sering untuk kemasan makanan.', 'icon' => '📦'],
];

// Definisi konstanta AllMachines
const AllMachines = [
    'Penghancur Plastik' => [
        'description' => 'Mengubah sampah plastik menjadi serpihan kecil, langkah awal penting.',
        'processing_cost' => 0,
        'icon' => '🗜️',
        'recipes' => [
            'PET' => ['output' => 'Serpihan PET Bersih', 'value' => 0, 'success_rate' => 95],
            'HDPE' => ['output' => 'Serpihan HDPE Bersih', 'value' => 0, 'success_rate' => 95],
            'LDPE' => ['output' => 'Serpihan LDPE Bersih', 'value' => 0, 'success_rate' => 93],
            'PP' => ['output' => 'Serpihan PP Bersih', 'value' => 0, 'success_rate' => 94],
            'PS' => ['output' => 'Serpihan PS Bersih', 'value' => 0, 'success_rate' => 88],
            'PET Bersih' => ['output' => 'Serpihan PET Super Bersih', 'value' => 0, 'success_rate' => 99],
            'HDPE Bersih' => ['output' => 'Serpihan HDPE Super Bersih', 'value' => 0, 'success_rate' => 99],
            'LDPE Bersih' => ['output' => 'Serpihan LDPE Super Bersih', 'value' => 0, 'success_rate' => 97],
            'PP Bersih' => ['output' => 'Serpihan PP Super Bersih', 'value' => 0, 'success_rate' => 97],
            'PS Bersih' => ['output' => 'Serpihan PS Super Bersih', 'value' => 0, 'success_rate' => 92],
        ]
    ],
    'Mesin Cuci Plastik' => [
        'description' => 'Membersihkan sampah plastik sebelum dihancurkan, mengurangi risiko kegagalan pemrosesan.',
        'processing_cost' => 0,
        'icon' => '🚿',
        'recipes' => [
            'PET' => ['output' => 'PET Bersih', 'value' => 0, 'success_rate' => 98],
            'HDPE' => ['output' => 'HDPE Bersih', 'value' => 0, 'success_rate' => 97],
            'LDPE' => ['output' => 'LDPE Bersih', 'value' => 0, 'success_rate' => 95],
            'PP' => ['output' => 'PP Bersih', 'value' => 0, 'success_rate' => 96],
            'PS' => ['output' => 'PS Bersih', 'value' => 0, 'success_rate' => 90],
        ]
    ],
    'Mesin Pengering Plastik' => [
        'description' => 'Mengeringkan serpihan plastik untuk kualitas pelet yang lebih tinggi dan hasil akhir yang lebih baik.',
        'processing_cost' => 0,
        'icon' => '☀️',
        'recipes' => [
            'Serpihan PET Bersih' => ['output' => 'Serpihan PET Kering', 'value' => 0, 'success_rate' => 95],
            'Serpihan HDPE Bersih' => ['output' => 'Serpihan HDPE Kering', 'value' => 0, 'success_rate' => 95],
            'Serpihan LDPE Bersih' => ['output' => 'Serpihan LDPE Kering', 'value' => 0, 'success_rate' => 90],
            'Serpihan PP Bersih' => ['output' => 'Serpihan PP Kering', 'value' => 0, 'success_rate' => 90],
            'Serpihan PS Bersih' => ['output' => 'Serpihan PS Kering', 'value' => 0, 'success_rate' => 85],
            'Serpihan PET Super Bersih' => ['output' => 'Serpihan PET Sangat Kering', 'value' => 0, 'success_rate' => 99],
            'Serpihan HDPE Super Bersih' => ['output' => 'Serpihan HDPE Sangat Kering', 'value' => 0, 'success_rate' => 99],
            'Serpihan LDPE Super Bersih' => ['output' => 'Serpihan LDPE Sangat Kering', 'value' => 0, 'success_rate' => 97],
            'Serpihan PP Super Bersih' => ['output' => 'Serpihan PP Sangat Kering', 'value' => 0, 'success_rate' => 97],
            'Serpihan PS Super Bersih' => ['output' => 'Serpihan PS Sangat Kering', 'value' => 0, 'success_rate' => 93],
        ]
    ],
    'Pelebur & Pencetak Pelet' => [
        'description' => 'Melebur serpihan plastik dan membentuknya menjadi pelet, siap untuk produk baru.',
        'processing_cost' => 0,
        'icon' => '🔥',
        'recipes' => [
            'Serpihan PET Bersih' => ['output' => 'Pelet PET Kualitas Tinggi', 'value' => 0, 'success_rate' => 90],
            'Serpihan HDPE Bersih' => ['output' => 'Pelet HDPE Standar', 'value' => 0, 'success_rate' => 90],
            'Serpihan LDPE Bersih' => ['output' => 'Pelet LDPE Fleksibel', 'value' => 0, 'success_rate' => 85],
            'Serpihan PP Bersih' => ['output' => 'Pelet PP Kuat', 'value' => 0, 'success_rate' => 85],
            'Serpihan PS Bersih' => ['output' => 'Pelet PS Ringan', 'value' => 0, 'success_rate' => 80],
            'Serpihan PET Kering' => ['output' => 'Pelet PET Premium', 'value' => 0, 'success_rate' => 95],
            'Serpihan HDPE Kering' => ['output' => 'Pelet HDPE Kualitas Unggul', 'value' => 0, 'success_rate' => 95],
            'Serpihan LDPE Kering' => ['output' => 'Pelet LDPE Super Fleksibel', 'value' => 0, 'success_rate' => 90],
            'Serpihan PP Kering' => ['output' => 'Pelet PP Super Kuat', 'value' => 0, 'success_rate' => 90],
            'Serpihan PS Kering' => ['output' => 'Pelet PS Ultra Ringan', 'value' => 0, 'success_rate' => 85],
            'Serpihan PET Sangat Kering' => ['output' => 'Pelet PET Optimal', 'value' => 0, 'success_rate' => 98],
            'Serpihan HDPE Sangat Kering' => ['output' => 'Pelet HDPE Sempurna', 'value' => 0, 'success_rate' => 98],
            'Serpihan LDPE Sangat Kering' => ['output' => 'Pelet LDPE Hyper Fleksibel', 'value' => 0, 'success_rate' => 95],
            'Serpihan PP Sangat Kering' => ['output' => 'Pelet PP Hyper Kuat', 'value' => 0, 'success_rate' => 95],
            'Serpihan PS Sangat Kering' => ['output' => 'Pelet PS Absolut Ringan', 'value' => 0, 'success_rate' => 90],
        ]
    ],
    'Pencetak Botol Baru' => [
        'description' => 'Mencetak pelet PET menjadi botol plastik baru, siap untuk dijual kembali.',
        'processing_cost' => 0,
        'icon' => '🍾',
        'recipes' => [
            'Pelet PET Kualitas Tinggi' => ['output' => 'Botol Air Daur Ulang', 'value' => 160, 'success_rate' => 95],
            'Pelet PET Premium' => ['output' => 'Botol Minuman Daur Ulang Premium', 'value' => 180, 'success_rate' => 98],
            'Pelet PET Optimal' => ['output' => 'Botol Farmasi Daur Ulang', 'value' => 220, 'success_rate' => 99],
        ]
    ],
    'Pencetak Papan Komposit' => [
        'description' => 'Mengubah pelet LDPE dan PP menjadi papan komposit, cocok untuk konstruksi.',
        'processing_cost' => 0,
        'icon' => '🪵',
        'recipes' => [
            'Pelet LDPE Fleksibel' => ['output' => 'Papan Daur Ulang Ringan', 'value' => 140, 'success_rate' => 88],
            'Pelet PP Kuat' => ['output' => 'Papan Daur Ulang Kuat', 'value' => 150, 'success_rate' => 88],
            'Campuran Pelet' => ['output' => 'Papan Komposit Campuran', 'value' => 110, 'success_rate' => 75], // Contoh campuran
            'Pelet LDPE Super Fleksibel' => ['output' => 'Papan Daur Ulang Ultra Ringan', 'value' => 170, 'success_rate' => 92],
            'Pelet PP Super Kuat' => ['output' => 'Papan Daur Ulang Ultra Kuat', 'value' => 180, 'success_rate' => 92],
            'Pelet LDPE Hyper Fleksibel' => ['output' => 'Papan Insulasi Fleksibel', 'value' => 200, 'success_rate' => 95],
            'Pelet PP Hyper Kuat' => ['output' => 'Papan Balok Konstruksi', 'value' => 210, 'success_rate' => 95],
        ]
    ],
    'Mesin Cetak Produk Jadi' => [
        'description' => 'Mencetak berbagai jenis pelet menjadi produk akhir yang siap dijual, termasuk barang-barang konsumen.',
        'processing_cost' => 0,
        'icon' => '🔧',
        'recipes' => [
            'Pelet PET Kualitas Tinggi' => ['output' => 'Benang Poliester Daur Ulang', 'value' => 150, 'success_rate' => 95],
            'Pelet HDPE Standar' => ['output' => 'Pipa Saluran Air Daur Ulang', 'value' => 130, 'success_rate' => 95],
            'Pelet LDPE Fleksibel' => ['output' => 'Kantong Sampah Bio-Degradable', 'value' => 110, 'success_rate' => 90],
            'Pelet PP Kuat' => ['output' => 'Palet Plastik Daur Ulang', 'value' => 120, 'success_rate' => 90],
            'Pelet PS Ringan' => ['output' => 'Isian Dinding Ringan', 'value' => 90, 'success_rate' => 85],
            'Campuran Pelet' => ['output' => 'Produk Daur Ulang Campuran', 'value' => 70, 'success_rate' => 70],
            'Pelet PET Premium' => ['output' => 'Benang Poliester Kualitas Tinggi', 'value' => 180, 'success_rate' => 97],
            'Pelet HDPE Kualitas Unggul' => ['output' => 'Pipa Industri HDPE', 'value' => 160, 'success_rate' => 97],
            'Pelet LDPE Super Fleksibel' => ['output' => 'Kantong Sampah Industri', 'value' => 140, 'success_rate' => 93],
            'Pelet PP Super Kuat' => ['output' => 'Palet Tugas Berat', 'value' => 150, 'success_rate' => 93],
            'Pelet PS Ultra Ringan' => ['output' => 'Isian Paket Ringan', 'value' => 120, 'success_rate' => 88],
            'Pelet PET Optimal' => ['output' => 'Kain Poliester Tekstil', 'value' => 210, 'success_rate' => 99],
            'Pelet HDPE Sempurna' => ['output' => 'Komponen Otomotif HDPE', 'value' => 190, 'success_rate' => 99],
            'Pelet LDPE Hyper Fleksibel' => ['output' => 'Film Pertanian Tahan Lama', 'value' => 190, 'success_rate' => 95],
            'Pelet PP Hyper Kuat' => ['output' => 'Bumper Mobil Daur Ulang', 'value' => 200, 'success_rate' => 95],
            'Pelet PS Absolut Ringan' => ['output' => 'Material Isolasi Premium', 'value' => 160, 'success_rate' => 80],
        ]
    ]
];

// Definisi konstanta ProductIcons
const ProductIcons = [
    'PET' => '🥤', 'HDPE' => '🥛', 'LDPE' => '🛍️', 'PP' => '🍱', 'PS' => '📦',
    'PET Bersih' => '🧼', 'HDPE Bersih' => '🫧', 'LDPE Bersih' => '💦', 'PP Bersih' => '💧', 'PS Bersih' => '🚿',
    'Serpihan PET Bersih' => '✨', 'Serpihan HDPE Bersih' => '🌟', 'Serpihan LDPE Bersih' => '💫', 'Serpihan PP Bersih' => '🔥', 'Serpihan PS Bersih' => '💡',
    'Serpihan PET Super Bersih' => '💎✨', 'Serpihan HDPE Super Bersih' => '👑🌟', 'Serpihan LDPE Super Bersih' => '🌈💫', 'Serpihan PP Super Bersih' => '✨🔥', 'Serpihan PS Super Bersih' => '✨💡',
    'Serpihan PET Kering' => '🏜️✨', 'Serpihan HDPE Kering' => '🏜️🌟', 'Serpihan LDPE Kering' => '🏜️💫', 'Serpihan PP Kering' => '🏜️🔥', 'Serpihan PS Kering' => '🏜️💡',
    'Serpihan PET Sangat Kering' => '🌞✨', 'Serpihan HDPE Sangat Kering' => '🌞🌟', 'Serpihan LDPE Sangat Kering' => '🌞💫', 'Serpihan PP Sangat Kering' => '🌞🔥', 'Serpihan PS Sangat Kering' => '🌞💡',
    'Pelet PET Kualitas Tinggi' => '⚪💎', 'Pelet HDPE Standar' => '⚪🌿', 'Pelet LDPE Fleksibel' => '⚪🍃', 'Pelet PP Kuat' => '⚪💪', 'Pelet PS Ringan' => '⚪☁️',
    'Pelet PET Premium' => '🟠💎', 'Pelet HDPE Kualitas Unggul' => '🟠🌿', 'Pelet LDPE Super Fleksibel' => '🟠🍃', 'Pelet PP Super Kuat' => '🟠💪', 'Pelet PS Ultra Ringan' => '🟠☁️',
    'Pelet PET Optimal' => '🟣💎', 'Pelet HDPE Sempurna' => '🟣🌿', 'Pelet LDPE Hyper Fleksibel' => '🟣🍃', 'Pelet PP Hyper Kuat' => '🟣💪', 'Pelet PS Absolut Ringan' => '🟣☁️',
    'Benang Poliester Daur Ulang' => '🧶', 'Pipa Saluran Air Daur Ulang' => '🚿', 'Kantong Sampah Bio-Degradable' => '♻️', 'Palet Plastik Daur Ulang' => '🏗️', 'Isian Dinding Ringan' => '🧱',
    'Produk Daur Ulang Campuran' => '🗑️', // Untuk kasus kegagalan atau produk campuran
    'Botol Air Daur Ulang' => '🍶', 'Botol Minuman Daur Ulang Premium' => '🍾', 'Botol Farmasi Daur Ulang' => '🧪',
    'Papan Daur Ulang Ringan' => '🪵', 'Papan Daur Ulang Kuat' => '🌳', 'Papan Komposit Campuran' => '🧱🪵',
    'Papan Daur Ulang Ultra Ringan' => '🌿🪵', 'Papan Daur Ulang Ultra Kuat' => '🔨🪵',
    'Papan Insulasi Fleksibel' => '☁️🧱', 'Papan Balok Konstruksi' => '🏗️🧱',
    'Benang Poliester Kualitas Tinggi' => '👑🧶',
    'Pipa Industri HDPE' => '🏭🚿',
    'Kantong Sampah Industri' => '🏭♻️',
    'Palet Tugas Berat' => '🚜🏗️',
    'Isian Paket Ringan' => '🎈🧱',
    'Kain Poliester Tekstil' => '👕',
    'Komponen Otomotif HDPE' => '🚗⚙️',
    'Film Pertanian Tahan Lama' => '🌾',
    'Bumper Mobil Daur Ulang' => '🚘🛡️',
    'Material Isolasi Premium' => '🏠❄️',
];
?>