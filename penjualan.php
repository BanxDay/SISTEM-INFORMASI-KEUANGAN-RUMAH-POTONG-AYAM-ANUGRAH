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

// --- LOGIKA CRUD (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'tambah_penjualan':
            $tanggal = $_POST['tanggal'];
            $pelanggan = $_POST['pelanggan'];
            $jumlah_kg = $_POST['jumlah']; 
            $harga_per_item = $_POST['harga_per_item'];
            
            if (!empty($tanggal) && $jumlah_kg > 0 && $harga_per_item > 0) {
                try {
                    $conn->beginTransaction();
                    $stmt_penjualan = $conn->prepare("INSERT INTO penjualan (tanggal, pelanggan, jumlah, harga_per_item) VALUES (?, ?, ?, ?)");
                    $stmt_penjualan->execute([$tanggal, $pelanggan, $jumlah_kg, $harga_per_item]);
                    $stmt_stok = $conn->prepare("UPDATE stok SET stok_ayam_potong = stok_ayam_potong - ? WHERE id = 1");
                    $stmt_stok->execute([$jumlah_kg]);
                    $conn->commit();
                    $_SESSION['success_message'] = "Data Penjualan berhasil ditambahkan dan stok diperbarui.";
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $_SESSION['error_message'] = "Gagal menyimpan data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid.";
            }
            break;

        case 'update_penjualan':
            $id = $_POST['id'];
            $tanggal_baru = $_POST['tanggal'];
            $pelanggan_baru = $_POST['pelanggan'];
            $jumlah_baru = $_POST['jumlah'];
            $harga_baru = $_POST['harga_per_item'];
            $jumlah_lama = $_POST['jumlah_lama'];

            if (!empty($tanggal_baru) && $jumlah_baru > 0 && $harga_baru > 0) {
                try {
                    $conn->beginTransaction();
                    $stmt_update = $conn->prepare("UPDATE penjualan SET tanggal = ?, pelanggan = ?, jumlah = ?, harga_per_item = ? WHERE id = ?");
                    $stmt_update->execute([$tanggal_baru, $pelanggan_baru, $jumlah_baru, $harga_baru, $id]);

                    $perubahan_stok = $jumlah_lama - $jumlah_baru; 
                    $stmt_stok_koreksi = $conn->prepare("UPDATE stok SET stok_ayam_potong = stok_ayam_potong + ? WHERE id = 1");
                    $stmt_stok_koreksi->execute([$perubahan_stok]);
                    
                    $conn->commit();
                    $_SESSION['success_message'] = "Data Penjualan berhasil diperbarui dan stok dikoreksi.";

                } catch (PDOException $e) {
                    $conn->rollBack();
                    $_SESSION['error_message'] = "Gagal memperbarui data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom wajib diisi dengan nilai yang valid.";
            }
            break;

        case 'delete_penjualan':
            $id = $_POST['id'];
            $jumlah_lama = $_POST['jumlah_lama']; 

            try {
                $conn->beginTransaction();
                
                // Kembalikan stok ayam potong
                $stmt_stok_rollback = $conn->prepare("UPDATE stok SET stok_ayam_potong = stok_ayam_potong + ? WHERE id = 1");
                $stmt_stok_rollback->execute([$jumlah_lama]);

                // Hapus data penjualan
                $stmt_delete = $conn->prepare("DELETE FROM penjualan WHERE id = ?");
                $stmt_delete->execute([$id]);

                $conn->commit();
                $_SESSION['success_message'] = "Data Penjualan berhasil dihapus dan stok dikembalikan.";
            } catch (PDOException $e) {
                $conn->rollBack();
                $_SESSION['error_message'] = "Gagal menghapus data: " . $e->getMessage();
            }
            break;
    }
    // Pola PRG (Post-Redirect-Get)
    header('Location: penjualan.php');
    exit();
}
// --- AKHIR LOGIKA CRUD POST ---

// B. AMBIL DATA UNTUK EDIT (Via GET)
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id_edit = $_GET['id'];
    try {
        $stmt_edit = $conn->prepare("SELECT * FROM penjualan WHERE id = ?");
        $stmt_edit->execute([$id_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

        if ($edit_data) {
            $show_popup_edit = true; 
        } else {
            $error_message = "Data tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error_message = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// C. LOGIKA FILTER TABEL BERDASARKAN KEYWORD
$keyword = $_GET['keyword'] ?? '';

$sql = "SELECT *, (jumlah * harga_per_item) AS total_hitung FROM penjualan";
$params = [];
$where_clauses = [];

if (!empty($keyword)) {
    // Menambahkan klausa WHERE untuk mencari di kolom pelanggan, jumlah, harga_per_item, dan tanggal
    $where_clauses[] = "(pelanggan LIKE ? OR jumlah LIKE ? OR harga_per_item LIKE ? OR tanggal LIKE ?)";
    $search_param = "%" . $keyword . "%";
    
    // Parameter diulang untuk setiap kolom yang dicari
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY tanggal DESC";

// --- D. Query Data untuk Tabel (Filter Aktif) ---
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data_penjualan = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_penjualan = [];
    $error_message = "Gagal mengambil data penjualan: " . $e->getMessage();
}

// Fungsi format Rupiah
function format_rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Tanggal hari ini untuk form default
$today_date = date('Y-m-d');
?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-semibold text-gray-800">Data Penjualan</h1>
        
        <button onclick="document.getElementById('modal-tambah').classList.remove('hidden')"
                class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-lg shadow-md transition duration-150 flex items-center">
            <span class="mr-1 text-lg">+</span> Catat Penjualan
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
        <form method="GET" action="penjualan.php" class="flex flex-col md:flex-row md:space-x-4 space-y-2 md:space-y-0 items-stretch md:items-center w-full">
            
            <h3 class="font-medium text-gray-700 whitespace-nowrap pt-2 md:pt-0">Cari Data:</h3>
            
            <input type="text" name="keyword" 
                   value="<?php echo htmlspecialchars($keyword); ?>"
                   placeholder="Cari Pelanggan, Jumlah (kg), Harga, atau Tanggal..."
                   class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto flex-grow focus:ring-teal-500 focus:border-teal-500">
            
            <div class="flex space-x-2">
                <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">
                    Terapkan
                </button>
                <a href="penjualan.php" class="text-center border border-gray-300 text-gray-700 hover:bg-gray-100 py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">Reset</a>
            </div>
        </form>
    </div>


    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <?php
                    $headers = ['Tanggal', 'Pelanggan', 'Jumlah (Kg)', 'Harga per Item', 'Total', 'Aksi'];
                    foreach ($headers as $header) {
                        echo "<th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>" . $header . "</th>";
                    }
                    ?>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($data_penjualan)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Tidak ada data penjualan yang ditemukan.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data_penjualan as $data): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($data['pelanggan']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($data['jumlah']); ?> kg</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo format_rupiah($data['harga_per_item']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600"><?php echo format_rupiah($data['total_hitung']); ?></td>
                            
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="penjualan.php?action=edit&id=<?php echo $data['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                
                                <form method="POST" action="penjualan.php" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data penjualan ini? Stok ayam potong akan dikembalikan sebesar <?php echo htmlspecialchars($data['jumlah']); ?> kg.')">
                                    <input type="hidden" name="action" value="delete_penjualan">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($data['id']); ?>">
                                    <input type="hidden" name="jumlah_lama" value="<?php echo htmlspecialchars($data['jumlah']); ?>">
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
            <h3 class="text-xl font-semibold text-gray-800">Tambah Data Penjualan</h3>
            <button onclick="document.getElementById('modal-tambah').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="penjualan.php" method="POST">
            <input type="hidden" name="action" value="tambah_penjualan">
            <div class="mb-4">
                <label for="tanggal" class="block text-sm font-medium text-gray-700">Tanggal</label>
                <input type="date" name="tanggal" value="<?php echo $today_date; ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div class="mb-4">
                <label for="pelanggan" class="block text-sm font-medium text-gray-700">Pelanggan</label>
                <input type="text" name="pelanggan" placeholder="Nama Pelanggan" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div class="mb-4">
                <label for="jumlah" class="block text-sm font-medium text-gray-700">Jumlah (Berat Ayam Potong dalam Kg)</label>
                <input type="number" step="0.01" name="jumlah" min="0" placeholder="Contoh: 50.5" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
                <p class="text-xs text-red-500 mt-1">Nilai ini akan mengurangi 'Stok Ayam Potong' (kg).</p>
            </div>
            <div class="mb-6">
                <label for="harga_per_item" class="block text-sm font-medium text-gray-700">Harga per Kg</label>
                <input type="number" name="harga_per_item" min="0" placeholder="Contoh: 28000" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div class="flex justify-end">
                <button type="submit"
                        class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Simpan Data
                </button>
            </div>
        </form>
    </div>
</div>
<div id="modal-edit" class="fixed inset-0 bg-gray-600 bg-opacity-75 <?php echo $show_popup_edit ? 'flex' : 'hidden'; ?> items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Edit Data Penjualan</h3>
            <a href="penjualan.php" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</a>
        </div>
        <?php if ($edit_data): ?>
            <form action="penjualan.php" method="POST">
                <input type="hidden" name="action" value="update_penjualan">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_data['id']); ?>">
                <input type="hidden" name="jumlah_lama" value="<?php echo htmlspecialchars($edit_data['jumlah']); ?>"> 
                <div class="mb-4">
                    <label for="tanggal_edit" class="block text-sm font-medium text-gray-700">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal_edit" value="<?php echo htmlspecialchars($edit_data['tanggal']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
                </div>
                <div class="mb-4">
                    <label for="pelanggan_edit" class="block text-sm font-medium text-gray-700">Pelanggan</label>
                    <input type="text" name="pelanggan" id="pelanggan_edit" value="<?php echo htmlspecialchars($edit_data['pelanggan']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
                </div>
                <div class="mb-4">
                    <label for="jumlah_edit" class="block text-sm font-medium text-gray-700">Jumlah (Berat Ayam Potong dalam Kg)</label>
                    <input type="number" step="0.01" name="jumlah" id="jumlah_edit" min="0" value="<?php echo htmlspecialchars($edit_data['jumlah']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
                    <p class="text-xs text-red-500 mt-1">Stok akan dikoreksi berdasarkan perubahan nilai ini.</p>
                </div>
                <div class="mb-6">
                    <label for="harga_edit" class="block text-sm font-medium text-gray-700">Harga per Kg</label>
                    <input type="number" name="harga_per_item" id="harga_edit" min="0" value="<?php echo htmlspecialchars($edit_data['harga_per_item']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-teal-500 focus:border-teal-500">
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