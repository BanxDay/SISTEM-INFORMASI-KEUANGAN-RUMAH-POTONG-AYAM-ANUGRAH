<?php
session_start();
require_once 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Fungsi format Rupiah
function format_rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

$error_message = '';

// --- 1. INISIALISASI FILTER TANGGAL ---
// Default: Laporan bulan ini
$default_start = date('Y-m-01');
$default_end = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $default_start;
$end_date = $_GET['end_date'] ?? $default_end;

// Pastikan tanggal akhir tidak lebih awal dari tanggal awal
if ($start_date > $end_date) {
    $error_message = "Tanggal Akhir tidak boleh lebih awal dari Tanggal Awal.";
    $start_date = $default_start;
    $end_date = $default_end;
}

// --- 2. FUNGSI UTAMA PENGAMBILAN DATA DAN PERHITUNGAN ---
function generate_laporan_data($conn, $start, $end, &$error_message) {
    $data = [
        'pemasukan' => [],
        'pengeluaran_operasional' => [],
        'pembelian_ayam' => [],
        'total_pemasukan' => 0,
        'total_pengeluaran' => 0,
        'laba_rugi' => 0,
        'arus_kas' => 0
    ];

    $params = [$start, $end];

    // A. PEMASUKAN (Dari Penjualan Ayam)
    try {
        // Tabel penjualan memiliki kolom: tanggal, pelanggan, jumlah (kg), harga_per_item, total
        // Hitung total dari jumlah * harga_per_item
        $sql_pemasukan = "SELECT tanggal, pelanggan AS keterangan, jumlah AS berat_total_kg, harga_per_item, (jumlah * harga_per_item) AS total FROM penjualan WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal ASC";
        $stmt = $conn->prepare($sql_pemasukan);
        $stmt->execute($params);
        $data['pemasukan'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sql_sum_pemasukan = "SELECT COALESCE(SUM(jumlah * harga_per_item), 0) AS total FROM penjualan WHERE tanggal BETWEEN ? AND ?";
        $stmt_sum = $conn->prepare($sql_sum_pemasukan);
        $stmt_sum->execute($params);
        $data['total_pemasukan'] = $stmt_sum->fetch(PDO::FETCH_ASSOC)['total'];

    } catch (PDOException $e) {
        $error_message .= "Gagal mengambil data Pemasukan: " . $e->getMessage() . "<br>";
    }

    // B. PENGELUARAN OPERASIONAL
    try {
        // Pastikan juga mengambil kolom jumlah dan harga_per_item jika tersedia
        $sql_op = "SELECT tanggal, keterangan, jumlah, harga_per_item, total FROM pengeluaran_operasional WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal ASC";
        $stmt = $conn->prepare($sql_op);
        $stmt->execute($params);
        $data['pengeluaran_operasional'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sql_sum_op = "SELECT COALESCE(SUM(total), 0) AS total FROM pengeluaran_operasional WHERE tanggal BETWEEN ? AND ?";
        $stmt_sum = $conn->prepare($sql_sum_op);
        $stmt_sum->execute($params);
        $total_op = $stmt_sum->fetch(PDO::FETCH_ASSOC)['total'];

    } catch (PDOException $e) {
        $error_message .= "Gagal mengambil data Pengeluaran Operasional: " . $e->getMessage() . "<br>";
        $total_op = 0;
    }

    // C. PENGELUARAN PEMBELIAN AYAM
    try {
        // Ambil berat_total_kg dan harga_per_kg untuk ditampilkan di kolom Jumlah
        $sql_beli = "SELECT tanggal, pemasok AS keterangan, berat_total_kg, harga_per_kg, total FROM pembelian_ayam WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal ASC";
        $stmt = $conn->prepare($sql_beli);
        $stmt->execute($params);
        $data['pembelian_ayam'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sql_sum_beli = "SELECT COALESCE(SUM(total), 0) AS total FROM pembelian_ayam WHERE tanggal BETWEEN ? AND ?";
        $stmt_sum = $conn->prepare($sql_sum_beli);
        $stmt_sum->execute($params);
        $total_beli = $stmt_sum->fetch(PDO::FETCH_ASSOC)['total'];

    } catch (PDOException $e) {
        $error_message .= "Gagal mengambil data Pembelian Ayam: " . $e->getMessage() . "<br>";
        $total_beli = 0;
    }
    
    // D. TOTAL PENGELUARAN (Gabungan)
    $data['total_pengeluaran'] = $total_op + $total_beli;

    // E. LABA RUGI OTOMATIS
    $data['laba_rugi'] = $data['total_pemasukan'] - $data['total_pengeluaran'];

    // F. ARUS KAS (Sederhana: Pemasukan Kas Bersih)
    // Dalam konteks ini, Arus Kas Sederhana = Laba/Rugi (Asumsi semua transaksi tunai)
    $data['arus_kas'] = $data['laba_rugi']; 

    return $data;
}

$laporan = generate_laporan_data($conn, $start_date, $end_date, $error_message);

?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <div class="flex items-center justify-between">
        <h1 class="text-3xl font-semibold text-gray-800">Data Laporan Keuangan</h1>
        <a href="export_pdf.php?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>"
   target="_blank"
   class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded transition duration-150 shadow-md w-full md:w-auto text-center">
    Cetak PDF
</a>
    </div>
    
    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm" role="alert">
            <h3 class="font-bold">⚠️ Error/Peringatan</h3>
            <p><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white p-4 rounded-lg shadow-md">
        <form method="GET" action="laporan.php" class="flex flex-col md:flex-row md:items-end space-y-3 md:space-y-0 md:space-x-4">
            <div class="flex-1 md:flex-initial">
                <h3 class="font-medium text-gray-700 whitespace-nowrap mb-2">Filter Periode:</h3>
            </div>
            
            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 flex-1">
                <div class="flex-1">
                    <label class="text-xs text-gray-600 block mb-1">Dari Tanggal</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required
                           class="p-2 border border-gray-300 rounded shadow-sm w-full focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="flex-1">
                    <label class="text-xs text-gray-600 block mb-1">Sampai Tanggal</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required
                           class="p-2 border border-gray-300 rounded shadow-sm w-full focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 w-full sm:w-auto">
                Tampilkan
            </button>
        </form>

        <!-- PDF button moved to page header -->
    </div>
    
    <div class="text-xl font-medium text-gray-600">
        Laporan periode **<?php echo date('d M Y', strtotime($start_date)); ?>** s/d **<?php echo date('d M Y', strtotime($end_date)); ?>**
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <div class="bg-green-50 p-6 rounded-lg shadow-md border-l-4 border-green-600">
            <h3 class="text-lg font-semibold text-green-700">Total Pemasukan</h3>
            <p class="text-3xl font-extrabold text-green-600 mt-2"><?php echo format_rupiah($laporan['total_pemasukan']); ?></p>
        </div>

        <div class="bg-red-50 p-6 rounded-lg shadow-md border-l-4 border-red-600">
            <h3 class="text-lg font-semibold text-red-700">Total Pengeluaran</h3>
            <p class="text-3xl font-extrabold text-red-600 mt-2"><?php echo format_rupiah($laporan['total_pengeluaran']); ?></p>
        </div>

        <?php 
            $is_laba = $laporan['laba_rugi'] >= 0;
            $color = $is_laba ? 'blue' : 'red';
        ?>
        <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-<?php echo $color; ?>-600">
            <h3 class="text-lg font-semibold text-gray-700">Laba / Rugi Bersih</h3>
            <p class="text-3xl font-extrabold text-<?php echo $color; ?>-600 mt-2">
                <?php echo format_rupiah(abs($laporan['laba_rugi'])); ?>
                <span class="text-lg font-normal ml-2">(<?php echo $is_laba ? 'Laba' : 'Rugi'; ?>)</span>
            </p>
        </div>
    </div>
    
    <!-- Arus Kas card removed as requested -->
    <hr>
    
    <h2 class="text-2xl font-semibold text-gray-800">1. Laporan Pemasukan (Penjualan)</h2>
    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Tanggal</th>
                       <th class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Pelanggan</th>
                       <th class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Jumlah (Kg)</th>
                       <th class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Harga per Item</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Total Pemasukan</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                   <?php if (empty($laporan['pemasukan'])): ?>
                       <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">Tidak ada data pemasukan pada periode ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($laporan['pemasukan'] as $item): ?>
                        <tr class="hover:bg-green-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($item['keterangan'] ?? 'Penjualan Ayam Potong'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($item['berat_total_kg'] ?? 0, 2, ',', '.'); ?></td>
                                                       <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo format_rupiah($item['harga_per_item'] ?? 0); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600 text-right"><?php echo format_rupiah($item['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr>
                       <td colspan="4" class="px-6 py-4 text-right font-extrabold text-md text-gray-800 border-t-2">TOTAL PEMASUKAN</td>
                    <td class="px-6 py-4 text-right font-extrabold text-md text-green-600 border-t-2"><?php echo format_rupiah($laporan['total_pemasukan']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <hr>

    <h2 class="text-2xl font-semibold text-gray-800">2. Laporan Pengeluaran</h2>
    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Tanggal</th>
                    <th class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Keterangan</th>
                    <th class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Jenis</th>
                                       <th class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Jumlah</th>
                    <th class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Total Pengeluaran</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php $has_data = !empty($laporan['pembelian_ayam']) || !empty($laporan['pengeluaran_operasional']); ?>
                   <?php if (!$has_data): ?>
                       <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">Tidak ada data pengeluaran pada periode ini.</td></tr>
                <?php else: ?>
                    
                    <?php foreach ($laporan['pembelian_ayam'] as $item): ?>
                        <tr class="hover:bg-red-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($item['keterangan']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-700">Pembelian Ayam</td>
                                                       <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($item['berat_total_kg'] ?? 0, 2, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 text-right"><?php echo format_rupiah($item['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php foreach ($laporan['pengeluaran_operasional'] as $item): ?>
                        <tr class="hover:bg-red-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($item['keterangan']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-700">Operasional</td>
                           <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo isset($item['jumlah']) ? number_format($item['jumlah'], 2, ',', '.') : '-'; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 text-right"><?php echo format_rupiah($item['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr>
                       <td colspan="4" class="px-6 py-4 text-right font-extrabold text-md text-gray-800 border-t-2">TOTAL PENGELUARAN</td>
                    <td class="px-6 py-4 text-right font-extrabold text-md text-red-600 border-t-2"><?php echo format_rupiah($laporan['total_pengeluaran']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

</div>

<?php include '_layout_footer.php'; ?>