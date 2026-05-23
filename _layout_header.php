<?php

require_once 'config.php'; // Pastikan file ini ada

// Cek apakah user sudah login. Jika tidak, arahkan kembali ke login.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Ambil data nama web dan logo dari tabel pengaturan (menggunakan setting_key/setting_value)
try {
    $stmt_name = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'website_name'");
    $stmt_name->execute();
    $result_name = $stmt_name->fetch(PDO::FETCH_ASSOC);
    $website_name = $result_name['setting_value'] ?? 'Sistem Manajemen';
    
    // Coba ambil logo dari database (base64 encoded)
    $stmt_logo_file = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'website_logo_file'");
    $stmt_logo_file->execute();
    $logo_file_encoded = $stmt_logo_file->fetch(PDO::FETCH_COLUMN);
    
    $stmt_logo_type = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'website_logo_type'");
    $stmt_logo_type->execute();
    $logo_type = $stmt_logo_type->fetch(PDO::FETCH_COLUMN) ?: 'image/png';
    
    // Jika ada file logo di database, buat data URI; jika tidak, gunakan default
    if ($logo_file_encoded) {
        $website_logo = 'data:' . $logo_type . ';base64,' . $logo_file_encoded;
    } else {
        $website_logo = 'assets/img/default_logo.png';
    }
} catch (PDOException $e) {
    // Handle error jika tabel belum ada
    $website_name = 'Sistem Manajemen';
    $website_logo = 'assets/img/default_logo.png';
}

// Tentukan halaman aktif untuk penandaan di sidebar
$current_page = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $website_name; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS Tambahan untuk Sidebar */
        .sidebar {
            width: 256px; /* 64 * 4px = 256px, sesuai w-64 di Tailwind */
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-100 flex">

    <aside class="sidebar bg-teal-800 text-white flex-shrink-0 min-h-screen">
        <div class="p-4 flex flex-col items-center border-b border-teal-700">
            <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo" 
                 class="h-16 w-16 rounded-full object-cover border-2 border-white mb-2">
            <h1 class="text-xl font-semibold"><?php echo htmlspecialchars($website_name); ?></h1>
        </div>
        
        <nav class="p-4 space-y-2">
            <?php
            // Daftar Menu dan Halaman
            $menu_items = [
                'dashboard.php' => ['icon' => '🏠', 'name' => 'Dashboard'],
                'penjualan.php' => ['icon' => '💰', 'name' => 'Penjualan'],
                'pengeluaran_operasional.php' => ['icon' => '💸', 'name' => 'Pengeluaran Operasional'],
                'pembelian_ayam.php' => ['icon' => '🐔', 'name' => 'Pembelian Ayam'],
                'proses_pemotongan.php' => ['icon' => '🔪', 'name' => 'Proses Pemotongan'],
                'stok.php' => ['icon' => '📦', 'name' => 'Stok'],
                'laporan.php' => ['icon' => '📄', 'name' => 'Laporan'],
                'settings.php' => ['icon' => '⚙️', 'name' => 'Pengaturan'],
                'logout.php' => ['icon' => '🚪', 'name' => 'Logout']
            ];

            foreach ($menu_items as $file => $item) {
                $is_active = ($current_page == $file) ? 'bg-teal-700 font-bold' : 'hover:bg-teal-700';
                echo "<a href=\"{$file}\" class=\"flex items-center p-3 rounded-lg {$is_active} transition duration-150\">";
                echo "<span>{$item['icon']}</span>";
                echo "<span class=\"ml-3\">{$item['name']}</span>";
                echo "</a>";
            }
            ?>
        </nav>
    </aside>

    <main class="flex-1 p-8 overflow-y-auto"> 
        

