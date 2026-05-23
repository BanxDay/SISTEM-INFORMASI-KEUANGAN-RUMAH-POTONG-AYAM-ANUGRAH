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

// Asumsi: Daftar Pemasok bisa diambil dari tabel terpisah (jika ada) atau di-hardcode.
// Untuk kemudahan, kita hardcode beberapa pemasok
$pemasok_options = [
    'PT Unggas Jaya', 
    'Peternakan Sumber Rezeki', 
    'Pemasok Lokal A', 
    'Pemasok Lokal B', 
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
        // 1. TAMBAH PEMBELIAN
        case 'tambah_pembelian':
            $tanggal = $_POST['tanggal'];
            $pemasok = $_POST['pemasok'];
            $jumlah_ekor = (int)$_POST['jumlah_ekor']; 
            $berat_total_kg = (float)$_POST['berat_total_kg'];
            $harga_per_kg = (int)$_POST['harga_per_kg'];
            
            // Hitung Total: Berat Total kg * Harga per kg
            $total = $berat_total_kg * $harga_per_kg;
            
            if (!empty($tanggal) && !empty($pemasok) && $jumlah_ekor > 0 && $berat_total_kg > 0 && $harga_per_kg > 0) {
                try {
                    $stmt = $conn->prepare("INSERT INTO pembelian_ayam (tanggal, pemasok, jumlah_ekor, berat_total_kg, harga_per_kg, total) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$tanggal, $pemasok, $jumlah_ekor, $berat_total_kg, $harga_per_kg, $total]);
                    $_SESSION['success_message'] = "Pembelian Ayam berhasil dicatat. Total: " . format_rupiah($total);
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal menyimpan data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid.";
            }
            break;

        // 2. UPDATE PEMBELIAN
        case 'update_pembelian':
            $id = $_POST['id'];
            $tanggal_baru = $_POST['tanggal'];
            $pemasok_baru = $_POST['pemasok'];
            $jumlah_ekor_baru = (int)$_POST['jumlah_ekor'];
            $berat_total_kg_baru = (float)$_POST['berat_total_kg'];
            $harga_per_kg_baru = (int)$_POST['harga_per_kg'];
            
            // Hitung Total Baru
            $total_baru = $berat_total_kg_baru * $harga_per_kg_baru;

            if (!empty($tanggal_baru) && !empty($pemasok_baru) && $jumlah_ekor_baru > 0 && $berat_total_kg_baru > 0 && $harga_per_kg_baru > 0) {
                try {
                    $stmt = $conn->prepare("UPDATE pembelian_ayam SET tanggal = ?, pemasok = ?, jumlah_ekor = ?, berat_total_kg = ?, harga_per_kg = ?, total = ? WHERE id = ?");
                    $stmt->execute([$tanggal_baru, $pemasok_baru, $jumlah_ekor_baru, $berat_total_kg_baru, $harga_per_kg_baru, $total_baru, $id]);
                    $_SESSION['success_message'] = "Pembelian Ayam berhasil diperbarui. Total Baru: " . format_rupiah($total_baru);
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal memperbarui data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid.";
            }
            break;

        // 3. DELETE PEMBELIAN
        case 'delete_pembelian':
            $id = $_POST['id'];
            try {
                $stmt_delete = $conn->prepare("DELETE FROM pembelian_ayam WHERE id = ?");
                $stmt_delete->execute([$id]);
                $_SESSION['success_message'] = "Data pembelian ayam berhasil dihapus.";
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Gagal menghapus data: " . $e->getMessage();
            }
            break;
    }
    // Pola PRG (Post-Redirect-Get)
    header('Location: pembelian_ayam.php');
    exit();
}
// --- AKHIR LOGIKA CRUD POST ---

// B. AMBIL DATA UNTUK EDIT (Via GET)
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id_edit = $_GET['id'];
    try {
        $stmt_edit = $conn->prepare("SELECT * FROM pembelian_ayam WHERE id = ?");
        $stmt_edit->execute([$id_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

        if ($edit_data) {
            $show_popup_edit = true; 
        } else {
            $error_message = "Data pembelian tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error_message = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// C. LOGIKA FILTER TABEL BERDASARKAN KEYWORD
$keyword = $_GET['keyword'] ?? '';

$sql = "SELECT * FROM pembelian_ayam";
$params = [];
$where_clauses = [];

if (!empty($keyword)) {
    // Mencari di kolom pemasok, jumlah_ekor, berat_total_kg, harga_per_kg, total, dan tanggal
    $where_clauses[] = "(pemasok LIKE ? OR jumlah_ekor LIKE ? OR berat_total_kg LIKE ? OR harga_per_kg LIKE ? OR total LIKE ? OR tanggal LIKE ?)";
    $search_param = "%" . $keyword . "%";
    
    $params[] = $search_param; // pemasok
    $params[] = $search_param; // jumlah_ekor
    $params[] = $search_param; // berat_total_kg
    $params[] = $search_param; // harga_per_kg
    $params[] = $search_param; // total
    $params[] = $search_param; // tanggal
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY tanggal DESC, id DESC"; // Urutkan berdasarkan tanggal terbaru

// --- D. Query Data untuk Tabel (Filter Aktif) ---
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data_pembelian = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_pembelian = [];
    $error_message = "Gagal mengambil data pembelian: " . $e->getMessage();
}
?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-semibold text-gray-800">Pembelian Ayam (Pengeluaran)</h1>
        
        <button onclick="document.getElementById('modal-tambah').classList.remove('hidden')"
                class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-lg shadow-md transition duration-150 flex items-center">
            <span class="mr-1 text-lg">+</span> Catat Pembelian
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
        <form method="GET" action="pembelian_ayam.php" class="flex flex-col md:flex-row md:space-x-4 space-y-2 md:space-y-0 items-stretch md:items-center w-full">
            <h3 class="font-medium text-gray-700 whitespace-nowrap pt-2 md:pt-0">Cari Data:</h3>
            
            <input type="text" name="keyword" 
                   value="<?php echo htmlspecialchars($keyword); ?>"
                   placeholder="Cari Pemasok, Jumlah, Berat, Harga, atau Tanggal..."
                   class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto flex-grow focus:ring-blue-500 focus:border-blue-500">
            
            <div class="flex space-x-2">
                <button type="submit" 
                        class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">
                    Terapkan
                </button>
                <a href="pembelian_ayam.php" class="text-center border border-gray-300 text-gray-700 hover:bg-gray-100 py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">Reset</a>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Tanggal</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Pemasok</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Ekor</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Berat Total (Kg)</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Harga/Kg</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Total Biaya</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($data_pembelian)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">Tidak ada data pembelian ayam yang ditemukan.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data_pembelian as $data): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($data['pemasok']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($data['jumlah_ekor'], 0, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($data['berat_total_kg'], 2, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo format_rupiah($data['harga_per_kg']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 text-right"><?php echo format_rupiah($data['total']); ?></td>
                            
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="pembelian_ayam.php?action=edit&id=<?php echo $data['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                
                                <form method="POST" action="pembelian_ayam.php" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data pembelian ini?')">
                                    <input type="hidden" name="action" value="delete_pembelian">
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
            <h3 class="text-xl font-semibold text-gray-800">Catat Pembelian Ayam Baru</h3>
            <button onclick="document.getElementById('modal-tambah').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="pembelian_ayam.php" method="POST">
            <input type="hidden" name="action" value="tambah_pembelian">

            <div class="mb-4">
                <label for="tanggal" class="block text-sm font-medium text-gray-700">Tanggal Pembelian</label>
                <input type="date" name="tanggal" value="<?php echo $today_date; ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div class="mb-4">
                <label for="pemasok" class="block text-sm font-medium text-gray-700">Pemasok</label>
                <input list="pemasok_list" name="pemasok" id="pemasok" required placeholder="Pilih atau ketik nama pemasok" value="" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                <datalist id="pemasok_list">
                    <?php foreach ($pemasok_options as $sup): ?>
                        <option value="<?php echo htmlspecialchars($sup); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="jumlah_ekor" class="block text-sm font-medium text-gray-700">Jumlah Ekor</label>
                    <input type="number" name="jumlah_ekor" id="jumlah_ekor" min="1" placeholder="Contoh: 100" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="berat_total_kg" class="block text-sm font-medium text-gray-700">Berat Total (Kg)</label>
                    <input type="number" step="0.01" name="berat_total_kg" id="berat_total_kg" min="0.01" placeholder="Contoh: 150.5" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div class="mb-6">
                <label for="harga_per_kg" class="block text-sm font-medium text-gray-700">Harga per Kg (Rp)</label>
                <input type="number" name="harga_per_kg" id="harga_per_kg" min="1" placeholder="Contoh: 25000" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                <p class="text-xs text-gray-500 mt-1">Total biaya = Berat Total (Kg) $\times$ Harga per Kg</p>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Simpan Pembelian
                </button>
            </div>
        </form>
    </div>
</div>
<div id="modal-edit" class="fixed inset-0 bg-gray-600 bg-opacity-75 <?php echo $show_popup_edit ? 'flex' : 'hidden'; ?> items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Edit Pembelian</h3>
            <a href="pembelian_ayam.php" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</a>
        </div>
        
        <?php if ($edit_data): ?>
            <form action="pembelian_ayam.php" method="POST">
                <input type="hidden" name="action" value="update_pembelian">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_data['id']); ?>">

                <div class="mb-4">
                    <label for="tanggal_edit" class="block text-sm font-medium text-gray-700">Tanggal Pembelian</label>
                    <input type="date" name="tanggal" id="tanggal_edit" value="<?php echo htmlspecialchars($edit_data['tanggal']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="pemasok_edit" class="block text-sm font-medium text-gray-700">Pemasok</label>
                    <input list="pemasok_list_edit" name="pemasok" id="pemasok_edit" required placeholder="Pilih atau ketik nama pemasok" value="<?php echo htmlspecialchars($edit_data['pemasok'] ?? ''); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                    <datalist id="pemasok_list_edit">
                        <?php foreach ($pemasok_options as $sup): ?>
                            <option value="<?php echo htmlspecialchars($sup); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="mb-4">
                        <label for="jumlah_ekor_edit" class="block text-sm font-medium text-gray-700">Jumlah Ekor</label>
                        <input type="number" name="jumlah_ekor" id="jumlah_ekor_edit" min="1" value="<?php echo htmlspecialchars($edit_data['jumlah_ekor']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div class="mb-4">
                        <label for="berat_total_kg_edit" class="block text-sm font-medium text-gray-700">Berat Total (Kg)</label>
                        <input type="number" step="0.01" name="berat_total_kg" id="berat_total_kg_edit" min="0.01" value="<?php echo htmlspecialchars($edit_data['berat_total_kg']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="mb-6">
                    <label for="harga_per_kg_edit" class="block text-sm font-medium text-gray-700">Harga per Kg (Rp)</label>
                    <input type="number" name="harga_per_kg" id="harga_per_kg_edit" min="1" value="<?php echo htmlspecialchars($edit_data['harga_per_kg']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Total biaya = Berat Total (Kg) $\times$ Harga per Kg</p>
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