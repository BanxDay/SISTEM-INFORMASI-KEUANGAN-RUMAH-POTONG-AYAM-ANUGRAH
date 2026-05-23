<?php
// Pastikan session_start() ada di file utama
session_start();
require_once 'config.php'; 

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$show_popup_edit = false;
$edit_data = null; 
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';

// Hapus pesan dari sesi agar tidak muncul lagi saat refresh berikutnya
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Daftar kategori pengeluaran yang diizinkan (Disesuaikan untuk operasional)
$kategori_options = [
    'Gaji Karyawan', 
    'Listrik & Air', 
    'Bahan habis pakai', 
    'Biaya transportasi',
    'maintenance alat',
    'bahan pembersih',
    'Lain-lain'
];

// Fungsi format Rupiah
function format_rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Tanggal hari ini untuk form default
$today_date = date('Y-m-d');

// --- LOGIKA CRUD (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        // 1. TAMBAH PENGELUARAN
        case 'tambah_pengeluaran':
            $tanggal = $_POST['tanggal'];
            $keterangan = $_POST['keterangan']; // Diambil dari select
            $jumlah = (float)$_POST['jumlah']; 
            $harga_per_item = (int)$_POST['harga_per_item'];
            // Hitung Total: Jumlah * Harga per Item
            $total = $jumlah * $harga_per_item;
            
            // Cek Keterangan (Agar tidak menyimpan data dengan keterangan kosong)
            if (!empty($tanggal) && !empty($keterangan) && $jumlah > 0 && $harga_per_item > 0) {
                try {
                    $stmt = $conn->prepare("INSERT INTO pengeluaran_operasional (tanggal, keterangan, jumlah, harga_per_item, total) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tanggal, $keterangan, $jumlah, $harga_per_item, $total]);
                    $_SESSION['success_message'] = "Pengeluaran Operasional berhasil ditambahkan. Total: " . format_rupiah($total);
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal menyimpan data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid. (Keterangan: " . (empty($keterangan) ? 'KOSONG' : 'OK') . ")";
            }
            break;

        // 2. UPDATE PENGELUARAN
        case 'update_pengeluaran':
            $id = $_POST['id'];
            $tanggal_baru = $_POST['tanggal'];
            $keterangan_baru = $_POST['keterangan']; // Diambil dari select
            $jumlah_baru = (float)$_POST['jumlah'];
            $harga_baru = (int)$_POST['harga_per_item'];
            // Hitung Total Baru
            $total_baru = $jumlah_baru * $harga_baru;

            // Cek Keterangan
            if (!empty($tanggal_baru) && !empty($keterangan_baru) && $jumlah_baru > 0 && $harga_baru > 0) {
                try {
                    $stmt = $conn->prepare("UPDATE pengeluaran_operasional SET tanggal = ?, keterangan = ?, jumlah = ?, harga_per_item = ?, total = ? WHERE id = ?");
                    $stmt->execute([$tanggal_baru, $keterangan_baru, $jumlah_baru, $harga_baru, $total_baru, $id]);
                    $_SESSION['success_message'] = "Pengeluaran Operasional berhasil diperbarui. Total Baru: " . format_rupiah($total_baru);
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal memperbarui data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid. (Keterangan: " . (empty($keterangan_baru) ? 'KOSONG' : 'OK') . ")";
            }
            break;

        // 3. DELETE PENGELUARAN
        case 'delete_pengeluaran':
            $id = $_POST['id'];
            try {
                $stmt_delete = $conn->prepare("DELETE FROM pengeluaran_operasional WHERE id = ?");
                $stmt_delete->execute([$id]);
                $_SESSION['success_message'] = "Pengeluaran Operasional berhasil dihapus.";
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Gagal menghapus data: " . $e->getMessage();
            }
            break;
    }
    // Pola PRG (Post-Redirect-Get)
    header('Location: pengeluaran_operasional.php');
    exit();
}
// --- AKHIR LOGIKA CRUD POST ---

// B. AMBIL DATA UNTUK EDIT (Via GET)
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id_edit = $_GET['id'];
    try {
        $stmt_edit = $conn->prepare("SELECT * FROM pengeluaran_operasional WHERE id = ?");
        $stmt_edit->execute([$id_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

        if ($edit_data) {
            $show_popup_edit = true; 
        } else {
            $error_message = "Data pengeluaran tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error_message = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// C. LOGIKA FILTER TABEL BERDASARKAN KEYWORD
$keyword = $_GET['keyword'] ?? '';

$sql = "SELECT * FROM pengeluaran_operasional";
$params = [];
$where_clauses = [];

if (!empty($keyword)) {
    $where_clauses[] = "(keterangan LIKE ? OR jumlah LIKE ? OR harga_per_item LIKE ? OR total LIKE ? OR tanggal LIKE ?)";
    $search_param = "%" . $keyword . "%";
    
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY tanggal DESC, id DESC"; 

// --- D. Query Data untuk Tabel (Filter Aktif) ---
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data_pengeluaran = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_pengeluaran = [];
    $error_message = "Gagal mengambil data pengeluaran: " . $e->getMessage();
}

?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-semibold text-gray-800">Pengeluaran Operasional</h1>
        
        <button onclick="document.getElementById('modal-tambah').classList.remove('hidden')"
                class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg shadow-md transition duration-150 flex items-center">
            <span class="mr-1 text-lg">-</span> Catat Pengeluaran
        </button>
    </div>

    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <?php echo $success_message; ?>
        </div>
    <?php elseif ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-4 rounded-lg shadow-md">
        <form method="GET" action="pengeluaran_operasional.php" class="flex flex-col md:flex-row md:space-x-4 space-y-2 md:space-y-0 items-stretch md:items-center w-full">
            <h3 class="font-medium text-gray-700 whitespace-nowrap pt-2 md:pt-0">Cari Data:</h3>
            
            <input type="text" name="keyword" 
                   value="<?php echo htmlspecialchars($keyword); ?>"
                   placeholder="Cari Keterangan, Jumlah, Harga, atau Tanggal..."
                   class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto flex-grow focus:ring-red-500 focus:border-red-500">
            
            <div class="flex space-x-2">
                <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">
                    Terapkan
                </button>
                <a href="pengeluaran_operasional.php" class="text-center border border-gray-300 text-gray-700 hover:bg-gray-100 py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">Reset</a>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Tanggal</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Keterangan</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Jumlah</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Harga per Item</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Total Pengeluaran</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($data_pengeluaran)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Tidak ada data pengeluaran operasional yang ditemukan.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data_pengeluaran as $data): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($data['keterangan']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($data['jumlah']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo format_rupiah($data['harga_per_item']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600"><?php echo format_rupiah($data['total']); ?></td>
                            
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="pengeluaran_operasional.php?action=edit&id=<?php echo $data['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                
                                <form method="POST" action="pengeluaran_operasional.php" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data pengeluaran ini? Tindakan ini tidak dapat dibatalkan.')">
                                    <input type="hidden" name="action" value="delete_pengeluaran">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($data['id']); ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<div id="modal-tambah" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Catat Pengeluaran Operasional Baru</h3>
            <button onclick="document.getElementById('modal-tambah').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="pengeluaran_operasional.php" method="POST">
            <input type="hidden" name="action" value="tambah_pengeluaran">

            <div class="mb-4">
                <label for="tanggal" class="block text-sm font-medium text-gray-700">Tanggal</label>
                <input type="date" name="tanggal" value="<?php echo $today_date; ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>
            
            <div class="mb-4">
                <label for="keterangan" class="block text-sm font-medium text-gray-700">Keterangan Pengeluaran</label>
                <input list="keterangan_list" name="keterangan" id="keterangan" required placeholder="Pilih atau ketik kategori" value="" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                <datalist id="keterangan_list">
                    <?php foreach ($kategori_options as $kat): ?>
                        <option value="<?php echo htmlspecialchars($kat); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="mb-4">
                <label for="jumlah" class="block text-sm font-medium text-gray-700">Jumlah Item / Satuan</label>
                <input type="number" step="0.01" name="jumlah" id="jumlah" min="0.01" value="1" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>

            <div class="mb-6">
                <label for="harga_per_item" class="block text-sm font-medium text-gray-700">Harga per Item (Rp)</label>
                <input type="number" name="harga_per_item" id="harga_per_item" min="1" placeholder="Contoh: 500000" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                <p class="text-xs text-gray-500 mt-1">Total akan dihitung: Jumlah * Harga per Item</p>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Simpan Pengeluaran
                </button>
            </div>
        </form>
    </div>
</div>
<div id="modal-edit" class="fixed inset-0 bg-gray-600 bg-opacity-75 <?php echo $show_popup_edit ? 'flex' : 'hidden'; ?> items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Edit Pengeluaran</h3>
            <a href="pengeluaran_operasional.php" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</a>
        </div>
        
        <?php if ($edit_data): ?>
            <form action="pengeluaran_operasional.php" method="POST">
                <input type="hidden" name="action" value="update_pengeluaran">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_data['id']); ?>">

                <div class="mb-4">
                    <label for="tanggal_edit" class="block text-sm font-medium text-gray-700">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal_edit" value="<?php echo htmlspecialchars($edit_data['tanggal']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>

                <div class="mb-4">
                    <label for="keterangan_edit" class="block text-sm font-medium text-gray-700">Keterangan Pengeluaran</label>
                    <input list="keterangan_list" name="keterangan" id="keterangan_edit" required placeholder="Pilih atau ketik kategori" value="<?php echo htmlspecialchars($edit_data['keterangan'] ?? ''); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>
                
                <div class="mb-4">
                    <label for="jumlah_edit" class="block text-sm font-medium text-gray-700">Jumlah Item / Satuan</label>
                    <input type="number" step="0.01" name="jumlah" id="jumlah_edit" min="0.01" value="<?php echo htmlspecialchars($edit_data['jumlah'] ?? 1); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>

                <div class="mb-6">
                    <label for="harga_edit" class="block text-sm font-medium text-gray-700">Harga per Item (Rp)</label>
                    <input type="number" name="harga_per_item" id="harga_edit" min="1" value="<?php echo htmlspecialchars($edit_data['harga_per_item'] ?? 0); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                    <p class="text-xs text-gray-500 mt-1">Total akan dihitung: Jumlah * Harga per Item</p>
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Ubah Data
                    </button>
                </div>
            </form>
        <?php else: ?>
            <p class="text-red-500">Data edit tidak tersedia.</p>
        <?php endif; ?>
    </div>
</div>
<?php include '_layout_footer.php'; ?>