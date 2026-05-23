<?php
// Panggil layout header untuk memulai sesi, cek login, dan menampilkan sidebar
session_start();
require_once 'config.php'; // Pastikan config.php di-include sebelum _layout_header.php jika config memuat koneksi/fungsi.

// Cek apakah user sudah login (Sebaiknya cek login di sini sebelum include header)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include '_layout_header.php'; // Termasuk di sini, tapi pastikan session_start() tidak ada di dalamnya.

// --- LOGIKA PHP UNTUK DASHBOARD ---

// 1. Inisialisasi Periode Filter
$start_date = $_GET['start_date'] ?? '2000-01-01'; // Default jauh di masa lalu
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default hari ini

// 2. Query Data Pemasukkan (dari Penjualan)
$stmt_incomes = $conn->prepare("SELECT COALESCE(SUM(jumlah * harga_per_item), 0) FROM penjualan WHERE tanggal BETWEEN ? AND ?");
$stmt_incomes->execute([$start_date, $end_date]);
$pemasukan = $stmt_incomes->fetchColumn();

// 3. Query Data Pengeluaran (dari Operasional dan Pembelian Ayam)
$stmt_op_exp = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM pengeluaran_operasional WHERE tanggal BETWEEN ? AND ?");
$stmt_op_exp->execute([$start_date, $end_date]);
$operasional_exp = $stmt_op_exp->fetchColumn();

$stmt_buy_exp = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM pembelian_ayam WHERE tanggal BETWEEN ? AND ?");
$stmt_buy_exp->execute([$start_date, $end_date]);
$pembelian_exp = $stmt_buy_exp->fetchColumn();

$pengeluaran = $operasional_exp + $pembelian_exp;
$laba = $pemasukan - $pengeluaran;

// 4. Query Data Bulanan untuk Grafik (Dari Database - Default View: Monthly)
// Agregasi data per bulan/tahun
$stmt_monthly = $conn->prepare("
    SELECT 
        DATE_FORMAT(tanggal, '%Y-%m') AS periode,
        DATE_FORMAT(tanggal, '%b %Y') AS label_periode,
        COALESCE(SUM(jumlah * harga_per_item), 0) AS total_income
    FROM penjualan
    GROUP BY DATE_FORMAT(tanggal, '%Y-%m')
    ORDER BY DATE_FORMAT(tanggal, '%Y-%m') ASC
");
$stmt_monthly->execute();
$monthly_income = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);

$stmt_monthly_exp = $conn->prepare("
    SELECT 
        DATE_FORMAT(tanggal, '%Y-%m') AS periode,
        COALESCE(SUM(total), 0) AS total_expense
    FROM (
        SELECT tanggal, total FROM pengeluaran_operasional
        UNION ALL
        SELECT tanggal, total FROM pembelian_ayam
    ) AS combined
    GROUP BY DATE_FORMAT(tanggal, '%Y-%m')
    ORDER BY DATE_FORMAT(tanggal, '%Y-%m') ASC
");
$stmt_monthly_exp->execute();
$monthly_expense = $stmt_monthly_exp->fetchAll(PDO::FETCH_ASSOC);

// Merge monthly data
$monthly_data = [];
foreach ($monthly_income as $income) {
    $period = $income['periode'];
    $monthly_data[$period] = [
        'label' => $income['label_periode'],
        'income' => $income['total_income'],
        'expense' => 0
    ];
}
foreach ($monthly_expense as $expense) {
    $period = $expense['periode'];
    if (!isset($monthly_data[$period])) {
        $monthly_data[$period] = ['label' => $period, 'income' => 0, 'expense' => 0];
    }
    $monthly_data[$period]['expense'] = $expense['total_expense'];
}
ksort($monthly_data);

// 5. Query Data Mingguan untuk Zoom Level 1
$stmt_weekly = $conn->prepare("
    SELECT 
        CONCAT(YEAR(tanggal), '-W', WEEK(tanggal)) AS periode,
        CONCAT('Week ', WEEK(tanggal), ' ', YEAR(tanggal)) AS label_periode,
        COALESCE(SUM(jumlah * harga_per_item), 0) AS total_income
    FROM penjualan
    GROUP BY YEAR(tanggal), WEEK(tanggal)
    ORDER BY YEAR(tanggal), WEEK(tanggal) ASC
");
$stmt_weekly->execute();
$weekly_income = $stmt_weekly->fetchAll(PDO::FETCH_ASSOC);

$stmt_weekly_exp = $conn->prepare("
    SELECT 
        CONCAT(YEAR(tanggal), '-W', WEEK(tanggal)) AS periode,
        COALESCE(SUM(total), 0) AS total_expense
    FROM (
        SELECT tanggal, total FROM pengeluaran_operasional
        UNION ALL
        SELECT tanggal, total FROM pembelian_ayam
    ) AS combined
    GROUP BY YEAR(tanggal), WEEK(tanggal)
    ORDER BY YEAR(tanggal), WEEK(tanggal) ASC
");
$stmt_weekly_exp->execute();
$weekly_expense = $stmt_weekly_exp->fetchAll(PDO::FETCH_ASSOC);

$weekly_data = [];
foreach ($weekly_income as $income) {
    $period = $income['periode'];
    $weekly_data[$period] = [
        'label' => $income['label_periode'],
        'income' => $income['total_income'],
        'expense' => 0
    ];
}
foreach ($weekly_expense as $expense) {
    $period = $expense['periode'];
    if (!isset($weekly_data[$period])) {
        $weekly_data[$period] = ['label' => $period, 'income' => 0, 'expense' => 0];
    }
    $weekly_data[$period]['expense'] = $expense['total_expense'];
}
ksort($weekly_data);

// 6. Query Data Harian untuk Zoom Level 2
$stmt_daily = $conn->prepare("
    SELECT 
        tanggal,
        DATE_FORMAT(tanggal, '%d %b %Y') AS label_tanggal,
        COALESCE(SUM(jumlah * harga_per_item), 0) AS total_income
    FROM penjualan
    GROUP BY tanggal
    ORDER BY tanggal ASC
");
$stmt_daily->execute();
$daily_income = $stmt_daily->fetchAll(PDO::FETCH_ASSOC);

$stmt_daily_exp = $conn->prepare("
    SELECT 
        tanggal,
        COALESCE(SUM(total), 0) AS total_expense
    FROM (
        SELECT tanggal, total FROM pengeluaran_operasional
        UNION ALL
        SELECT tanggal, total FROM pembelian_ayam
    ) AS combined
    GROUP BY tanggal
    ORDER BY tanggal ASC
");
$stmt_daily_exp->execute();
$daily_expense = $stmt_daily_exp->fetchAll(PDO::FETCH_ASSOC);

$daily_data = [];
foreach ($daily_income as $income) {
    $date = $income['tanggal'];
    $daily_data[$date] = [
        'label' => $income['label_tanggal'],
        'income' => $income['total_income'],
        'expense' => 0
    ];
}
foreach ($daily_expense as $expense) {
    $date = $expense['tanggal'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['label' => $date, 'income' => 0, 'expense' => 0];
    }
    $daily_data[$date]['expense'] = $expense['total_expense'];
}
ksort($daily_data);

// Fungsi format rupiah
function format_rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

?>

<h1 class="text-3xl font-semibold text-gray-800 mb-6">Dashboard</h1>

<div class="bg-white p-4 rounded-lg shadow-md mb-6">
    <form method="GET" action="dashboard.php" class="flex flex-col md:flex-row md:space-x-4 space-y-3 md:space-y-0 items-stretch md:items-center w-full">
        
        <div class="flex flex-col flex-1">
            <label class="font-medium text-gray-700 mb-1">Periode Awal:</label>
            <input type="date" name="start_date" 
                   value="<?php echo htmlspecialchars($start_date == '2000-01-01' ? '' : $start_date); ?>"
                   class="p-2 border border-gray-300 rounded shadow-sm focus:ring-teal-500 focus:border-teal-500">
        </div>
        
        <div class="flex flex-col flex-1">
            <label class="font-medium text-gray-700 mb-1">Periode Akhir:</label>
            <input type="date" name="end_date" 
                   value="<?php echo htmlspecialchars($end_date); ?>"
                   class="p-2 border border-gray-300 rounded shadow-sm focus:ring-teal-500 focus:border-teal-500">
        </div>
        
        <div class="flex space-x-2 md:mt-6 w-full md:w-auto">
            <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 flex-1 md:flex-none whitespace-nowrap">
                Terapkan
            </button>
            <a href="dashboard.php" class="text-center border border-gray-300 text-gray-700 hover:bg-gray-100 py-2 px-4 rounded transition duration-150 flex-1 md:flex-none whitespace-nowrap">Reset</a>
        </div>
    </form>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    
    <div class="bg-white p-6 rounded-lg shadow-xl border-l-4 border-green-500">
        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Pemasukkan</p>
        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo format_rupiah($pemasukan); ?></p>
        <p class="text-xs text-gray-400 mt-2">Jumlah penjualan berdasarkan periode</p>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-xl border-l-4 border-red-500">
        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Pengeluaran</p>
        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo format_rupiah($pengeluaran); ?></p>
        <p class="text-xs text-gray-400 mt-2">Pembelian ayam & pengeluaran operasional</p>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-xl border-l-4 border-blue-500">
        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Laba Bersih</p>
        <p class="text-3xl font-bold <?php echo ($laba >= 0) ? 'text-green-600' : 'text-red-600'; ?> mt-1">
            <?php echo format_rupiah($laba); ?>
        </p>
        <p class="text-xs text-gray-400 mt-2">Pemasukkan dikurangi pengeluaran</p>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow-xl">
    <h3 class="text-xl font-semibold mb-4 text-gray-800">Pemasukan vs Pengeluaran</h3>
    <div class="mb-4">
        <p class="text-sm text-gray-600">
            <span id="zoom-level">Zoom: Bulanan</span> | 
            <button id="zoom-out-btn" onclick="zoomOut()" class="text-blue-600 hover:underline text-sm" style="display:none;">← Kembali</button>
        </p>
    </div>
    <div class="h-96">
        <canvas id="lineChart"></canvas>
    </div>
</div>

<script>
    // Data dari PHP
    const monthlyDataRaw = <?php echo json_encode($monthly_data); ?>;
    const weeklyDataRaw = <?php echo json_encode($weekly_data); ?>;
    const dailyDataRaw = <?php echo json_encode($daily_data); ?>;

    // State untuk zoom tracking
    let currentZoomLevel = 'monthly'; // monthly, weekly, daily
    let chartInstance = null;

    // Fungsi untuk format rupiah di chart
    function formatRupiahShort(value) {
        if (value >= 1000000000) {
            return 'Rp ' + (value / 1000000000).toFixed(1) + ' M';
        }
        if (value >= 1000000) {
            return 'Rp ' + (value / 1000000).toFixed(0) + ' Jt';
        }
        if (value >= 1000) {
            return 'Rp ' + (value / 1000).toFixed(0) + ' Rb';
        }
        return 'Rp ' + value;
    }

    // Fungsi untuk update chart dengan data baru
    function updateChart(dataObj, zoomLevel) {
        const labels = Object.values(dataObj).map(d => d.label);
        const incomeData = Object.values(dataObj).map(d => d.income);
        const expenseData = Object.values(dataObj).map(d => d.expense);

        if (chartInstance) {
            chartInstance.destroy();
        }

        const ctx = document.getElementById('lineChart').getContext('2d');
        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Pemasukkan',
                        data: incomeData,
                        borderColor: 'rgb(16, 185, 129)', 
                        backgroundColor: 'rgba(16, 185, 129, 0.2)',
                        fill: false,
                        tension: 0.1,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'Pengeluaran',
                        data: expenseData,
                        borderColor: 'rgb(239, 68, 68)', 
                        backgroundColor: 'rgba(239, 68, 68, 0.2)',
                        fill: false,
                        tension: 0.1,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: handleChartClick,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return formatRupiahShort(value);
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += formatRupiahShort(context.parsed.y);
                                return label;
                            }
                        }
                    }
                }
            }
        });

        currentZoomLevel = zoomLevel;
        document.getElementById('zoom-level').textContent = zoomLevel === 'monthly' ? 'Zoom: Bulanan' : zoomLevel === 'weekly' ? 'Zoom: Mingguan' : 'Zoom: Harian';
        document.getElementById('zoom-out-btn').style.display = currentZoomLevel !== 'monthly' ? 'inline' : 'none';
    }

    // Fungsi untuk handle click pada chart (zoom in)
    function handleChartClick(event, activeElements) {
        if (activeElements.length === 0) return;

        const index = activeElements[0].index;

        if (currentZoomLevel === 'monthly') {
            // Zoom ke weekly
            updateChart(weeklyDataRaw, 'weekly');
        } else if (currentZoomLevel === 'weekly') {
            // Zoom ke daily
            updateChart(dailyDataRaw, 'daily');
        }
        // Di level daily, tidak bisa zoom lebih
    }

    // Fungsi untuk zoom out (kembali)
    function zoomOut() {
        if (currentZoomLevel === 'weekly') {
            updateChart(monthlyDataRaw, 'monthly');
        } else if (currentZoomLevel === 'daily') {
            updateChart(weeklyDataRaw, 'weekly');
        }
    }

    // Inisialisasi chart dengan data bulanan (default)
    updateChart(monthlyDataRaw, 'monthly');
</script>

<?php
// Tutup tag main dan body
include '_layout_footer.php'; 
?>