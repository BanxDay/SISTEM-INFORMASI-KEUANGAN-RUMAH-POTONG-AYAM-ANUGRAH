<?php
require __DIR__ . '/vendor/autoload.php'; // Use absolute path to autoload
require_once 'config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// --- Copy the generate_laporan_data function from laporan.php (without including the page) ---
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
        // Ambil jumlah dan harga_per_item jika ada, agar kolom Jumlah dapat ditampilkan
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

    // F. ARUS KAS (Sederhana)
    $data['arus_kas'] = $data['laba_rugi']; 

    return $data;
}

// Fungsi format Rupiah
function format_rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// 1. Ambil Filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// 1b. Ambil nama website dari database
$website_name = 'APLIKASI AYAM';
try {
    $stmt_name = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'website_name'");
    $stmt_name->execute();
    $result = $stmt_name->fetch(PDO::FETCH_COLUMN);
    if ($result) {
        $website_name = $result;
    }
} catch (PDOException $e) {
    // Use default if query fails
}

// 2. Ambil Data (Gunakan logika data yang sama dengan laporan.php)
$error_message = '';
$laporan = generate_laporan_data($conn, $start_date, $end_date, $error_message);

// 3. Siapkan HTML untuk PDF
// Buat tabel pemasukan
$pemasukan_html = '';
    if (!empty($laporan['pemasukan'])) {
    $pemasukan_html = '<table>
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Tanggal</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Pelanggan</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Jumlah (Kg)</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Harga per Item</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($laporan['pemasukan'] as $item) {
        $pemasukan_html .= '<tr>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['tanggal']) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['keterangan'] ?? 'Penjualan Ayam Potong') . '</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($item['berat_total_kg'] ?? 0, 2, ',', '.') . '</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($item['harga_per_item'] ?? 0) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($item['total']) . '</td>
        </tr>';
    }
    $pemasukan_html .= '<tr style="background-color: #f2f2f2; font-weight: bold;">
        <td colspan="4" style="border: 1px solid #ddd; padding: 8px; text-align: right;">TOTAL PEMASUKAN</td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($laporan['total_pemasukan']) . '</td>
    </tr></tbody>
    </table>';
} else {
    $pemasukan_html = '<p>Tidak ada data pemasukan pada periode ini.</p>';
}

// Buat tabel pengeluaran
$pengeluaran_html = '';
if (!empty($laporan['pembelian_ayam']) || !empty($laporan['pengeluaran_operasional'])) {
    $pengeluaran_html = '<table>
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Tanggal</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Keterangan</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Jenis</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Jumlah</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($laporan['pembelian_ayam'] as $item) {
        $pengeluaran_html .= '<tr>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['tanggal']) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['keterangan']) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px;">Pembelian Ayam</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . number_format($item['berat_total_kg'] ?? 0, 2, ',', '.') . '</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($item['total']) . '</td>
        </tr>';
    }
    
    foreach ($laporan['pengeluaran_operasional'] as $item) {
        $pengeluaran_html .= '<tr>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['tanggal']) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['keterangan']) . '</td>
            <td style="border: 1px solid #ddd; padding: 8px;">Operasional</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . (isset($item['jumlah']) ? number_format($item['jumlah'], 2, ',', '.') : '-') . '</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($item['total']) . '</td>
        </tr>';
    }
    
    $pengeluaran_html .= '<tr style="background-color: #f2f2f2; font-weight: bold;">
        <td colspan="4" style="border: 1px solid #ddd; padding: 8px; text-align: right;">TOTAL PENGELUARAN</td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . format_rupiah($laporan['total_pengeluaran']) . '</td>
    </tr></tbody>
    </table>';
} else {
    $pengeluaran_html = '<p>Tidak ada data pengeluaran pada periode ini.</p>';
}

$html = '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Laporan Periode ' . $start_date . ' s/d ' . $end_date . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header .website-name { font-size: 16px; font-weight: bold; margin: 0; }
            .header p { margin: 5px 0; font-size: 12px; }
            h1 { text-align: center; color: #333; margin: 8px 0; font-size: 18px; }
            h2 { border-bottom: 2px solid #333; padding-bottom: 10px; color: #333; font-size: 14px; margin-top: 20px; }
            .summary, table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
            .summary td, th { padding: 8px; border: 1px solid #ddd; }
            table thead { background-color: #f2f2f2; }
            table td, table th { padding: 8px; border: 1px solid #ddd; }
            .laba { background-color: #e6ffe6; font-weight: bold; }
            .rugi { background-color: #ffe6e6; font-weight: bold; }
            .total { background-color: #f2f2f2; font-weight: bold; }
            .summary { font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>LAPORAN KEUANGAN</h1>
            <p class="website-name">' . htmlspecialchars($website_name) . '</p>
            <p>Periode: ' . date('d M Y', strtotime($start_date)) . ' s/d ' . date('d M Y', strtotime($end_date)) . '</p>
        </div>

        <h2>Ringkasan Laba Rugi</h2>
        <table class="summary">
            <tr><td>Total Pemasukan</td><td class="total">' . format_rupiah($laporan['total_pemasukan']) . '</td></tr>
            <tr><td>Total Pengeluaran</td><td class="total">' . format_rupiah($laporan['total_pengeluaran']) . '</td></tr>
            <tr class="' . (($laporan['laba_rugi'] >= 0) ? 'laba' : 'rugi') . '">
                <td>Laba / Rugi Bersih</td>
                <td>' . format_rupiah($laporan['laba_rugi']) . '</td>
            </tr>
        </table>

        <h2>1. Laporan Pemasukan (Penjualan)</h2>
        ' . $pemasukan_html . '

        <h2>2. Laporan Pengeluaran</h2>
        ' . $pengeluaran_html . '
        
    </body>
    </html>
';

// 4. Render PDF
$options = new Options();
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// 5. Output PDF (Display in browser for preview, allow print/download)
$filename = "Laporan_Ayam_" . $start_date . "_" . $end_date . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]); // false = display inline in browser, true = download

?>