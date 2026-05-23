<?php
session_start();
require_once 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$error_message = '';
$filter_date = $_GET['filter_date'] ?? date('Y-m-d'); // Default hari ini
$period = $_GET['period'] ?? 'daily'; // daily | weekly | monthly

// Fungsi untuk format angka
function format_number($amount) {
    return number_format($amount, 0, ',', '.');
}

// ----------------------------------------------------
// A. LOGIKA PERHITUNGAN STOK (Berdasarkan SELURUH DATA)
// ----------------------------------------------------

try {
    // 1. Ayam Hidup (Ekor) = Total Beli Ekor - Total Potong Ekor
    $stmt_beli = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_beli FROM pembelian_ayam");
    $stmt_beli->execute();
    $total_ekor_beli = $stmt_beli->fetch(PDO::FETCH_ASSOC)['total_beli'];

    $stmt_potong_ekor = $conn->prepare("SELECT COALESCE(SUM(jumlah_ekor), 0) AS total_potong_ekor FROM proses_pemotongan");
    $stmt_potong_ekor->execute();
    $total_ekor_potong = $stmt_potong_ekor->fetch(PDO::FETCH_ASSOC)['total_potong_ekor'];
    
    // ASUMSI: Total Ekor Terjual dari Penjualan Ayam HIDUP (jika ada)
    // Untuk tujuan ini, kita asumsikan penjualan_ayam adalah ayam potong (kg) atau 0 ekor.
    $total_ekor_jual = 0; 
    
    $stok_ayam_hidup = $total_ekor_beli - $total_ekor_potong - $total_ekor_jual;

    // 2. Ayam Potong (Kg) = Total Berat Potong - Total Berat Jual
    $stmt_potong_berat = $conn->prepare("SELECT COALESCE(SUM(berat_potong_kg), 0) AS total_berat_potong FROM proses_pemotongan");
    $stmt_potong_berat->execute();
    $total_berat_potong = $stmt_potong_berat->fetch(PDO::FETCH_ASSOC)['total_berat_potong'];

    // NOTE: Tabel penjualan menggunakan kolom jumlah (dalam kg) untuk stok ayam potong
    $stmt_jual_berat = $conn->prepare("SELECT COALESCE(SUM(jumlah), 0) AS total_berat_jual FROM penjualan");
    $stmt_jual_berat->execute();
    $total_berat_jual = $stmt_jual_berat->fetch(PDO::FETCH_ASSOC)['total_berat_jual'];

    $stok_ayam_potong = $total_berat_potong - $total_berat_jual;

} catch (PDOException $e) {
    $stok_ayam_hidup = 0;
    $stok_ayam_potong = 0.00;
    $error_message .= "Gagal menghitung stok: " . $e->getMessage() . "<br>";
}

// ----------------------------------------------------
// B. LOGIKA PENGAMBILAN DATA UNTUK 3 TABEL
// ----------------------------------------------------

$params = [];
// Build WHERE clause depending on selected period
if ($period === 'weekly') {
    // Determine start (Monday) and end (Sunday) of the week that contains $filter_date
    $timestamp = strtotime($filter_date);
    $start_of_week = date('Y-m-d', strtotime('monday this week', $timestamp));
    $end_of_week = date('Y-m-d', strtotime('sunday this week', $timestamp));
    $where_clause = " WHERE tanggal BETWEEN ? AND ?";
    $params[] = $start_of_week;
    $params[] = $end_of_week;
} elseif ($period === 'monthly') {
    // Use year-month equality for the month containing $filter_date
    $year_month = date('Y-m', strtotime($filter_date));
    $where_clause = " WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?";
    $params[] = $year_month;
} else {
    // default: daily
    $where_clause = " WHERE tanggal = ?";
    $params[] = $filter_date;
}

// 1. Data Masuk (Pembelian Ayam)
try {
    $sql_masuk = "SELECT tanggal, jumlah_ekor FROM pembelian_ayam" . $where_clause . " ORDER BY tanggal DESC";
    $stmt_masuk = $conn->prepare($sql_masuk);
    $stmt_masuk->execute($params);
    $data_masuk = $stmt_masuk->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_masuk = [];
    $error_message .= "Gagal mengambil data Masuk: " . $e->getMessage() . "<br>";
}

// 2. Data Potong (Proses Pemotongan)
try {
    $sql_potong = "SELECT tanggal, jumlah_ekor FROM proses_pemotongan" . $where_clause . " ORDER BY tanggal DESC";
    $stmt_potong = $conn->prepare($sql_potong);
    $stmt_potong->execute($params);
    $data_potong = $stmt_potong->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_potong = [];
    $error_message .= "Gagal mengambil data Potong: " . $e->getMessage() . "<br>";
}

// 3. Data Keluar (Penjualan Ayam)
// Menggunakan tabel penjualan dengan kolom tanggal dan jumlah (kg)
try {
    $sql_keluar = "SELECT tanggal, jumlah AS jumlah_ayam FROM penjualan" . $where_clause . " ORDER BY tanggal DESC";
    $stmt_keluar = $conn->prepare($sql_keluar);
    $stmt_keluar->execute($params);
    $data_keluar = $stmt_keluar->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_keluar = [];
    $error_message .= "Gagal mengambil data Keluar (Penjualan): " . $e->getMessage() . "<br>";
}

?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <h1 class="text-3xl font-semibold text-gray-800">Data Stok</h1>
    
    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm" role="alert">
            <h3 class="font-bold">⚠️ Error/Peringatan Database</h3>
            <p><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>

    <!-- Filter card was moved below (above Detail Pergerakan Stok) -->

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <div class="bg-white p-6 rounded-lg shadow-xl border-l-4 border-red-600 order-2 md:order-1">
            <h2 class="text-xl font-bold text-gray-700 mb-2">Ayam Potong (Karkas)</h2>
            <p class="text-sm text-gray-500 mb-4">Stok dalam berat bersih (kg).</p>

            <div class="flex justify-between items-center bg-red-50 p-4 rounded-lg">
                <span class="text-lg font-medium text-red-800">Stok Akhir (Kg)</span>
                <span class="text-4xl font-extrabold text-red-600">
                    <?php echo number_format($stok_ayam_potong, 2, ',', '.'); ?>
                    <span class="text-xl font-normal">kg</span>
                </span>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-xl border-l-4 border-blue-600 order-1 md:order-2">
            <h2 class="text-xl font-bold text-gray-700 mb-2">Ayam Hidup</h2>
            <p class="text-sm text-gray-500 mb-4">Stok dalam jumlah ekor.</p>

            <div class="flex justify-between items-center bg-blue-50 p-4 rounded-lg">
                <span class="text-lg font-medium text-blue-800">Stok Akhir (Ekor)</span>
                <span class="text-4xl font-extrabold text-blue-600">
                    <?php echo format_number($stok_ayam_hidup); ?>
                    <span class="text-xl font-normal">ekor</span>
                </span>
            </div>
        </div>
    </div>
    
   

    <h2 class="text-2xl font-semibold text-gray-800 pt-4">Detail Pergerakan Stok (Periode: 
        <?php
            if ($period === 'weekly') {
                echo htmlspecialchars($start_of_week) . ' s/d ' . htmlspecialchars($end_of_week);
            } elseif ($period === 'monthly') {
                echo htmlspecialchars($year_month);
            } else {
                echo htmlspecialchars($filter_date);
            }
        ?>
    )</h2>

 <div class="bg-white p-4 rounded-lg shadow-md">
        <form method="GET" action="stok.php" class="flex flex-col md:flex-row md:items-center space-y-3 md:space-y-0 md:space-x-4">
            <h3 class="font-medium text-gray-700 whitespace-nowrap">Filter Tanggal Operasi:</h3>
            <input type="date" name="filter_date" 
                   value="<?php echo htmlspecialchars($filter_date); ?>"
                   class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto focus:ring-blue-500 focus:border-blue-500">
            <select name="period" class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto" aria-label="Pilih periode">
                <option value="daily" <?php if ($period === 'daily') echo 'selected'; ?>>Harian</option>
                <option value="weekly" <?php if ($period === 'weekly') echo 'selected'; ?>>Mingguan</option>
                <option value="monthly" <?php if ($period === 'monthly') echo 'selected'; ?>>Bulanan</option>
            </select>
            <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 w-full md:w-auto">
                Tampilkan
            </button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <div class="bg-white p-4 rounded-lg shadow-lg">
            <h3 class="text-lg font-bold text-green-600 mb-3">1. MASUK (Pembelian Ekor)</h3>
            <div class="overflow-x-auto h-64">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class='px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase'>Tanggal</th>
                            <th class='px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase'>Jumlah (Ekor)</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($data_masuk)): ?>
                            <tr><td colspan="2" class="px-4 py-3 text-center text-gray-500">Tidak ada data masuk pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($data_masuk as $data): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right text-green-600 font-medium">+ <?php echo format_number($data['jumlah_ekor']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow-lg">
            <h3 class="text-lg font-bold text-yellow-600 mb-3">2. PROSES POTONG (Ayam Dipotong)</h3>
            <div class="overflow-x-auto h-64">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class='px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase'>Tanggal</th>
                            <th class='px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase'>Jumlah (Ekor)</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($data_potong)): ?>
                            <tr><td colspan="2" class="px-4 py-3 text-center text-gray-500">Tidak ada proses potong pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($data_potong as $data): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right text-yellow-600 font-medium">- <?php echo format_number($data['jumlah_ekor']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow-lg">
            <h3 class="text-lg font-bold text-red-600 mb-3">3. KELUAR (Penjualan Ekor/Qty)</h3>
            <div class="overflow-x-auto h-64">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class='px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase'>Tanggal</th>
                            <th class='px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase'>Jumlah (Ekor/Qty)</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($data_keluar) || (count($data_keluar) == 1 && $data_keluar[0]['jumlah_ayam'] == 'N/A')): ?>
                            <tr><td colspan="2" class="px-4 py-3 text-center text-gray-500">Tabel Penjualan belum terisi/tersedia.</td></tr>
                        <?php else: ?>
                            <?php foreach ($data_keluar as $data): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right text-red-600 font-medium">- <?php echo format_number($data['jumlah_ayam']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
</div>

<?php include '_layout_footer.php'; ?>