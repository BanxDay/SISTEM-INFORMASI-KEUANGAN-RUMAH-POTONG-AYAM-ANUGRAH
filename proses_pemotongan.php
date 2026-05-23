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

// Fungsi untuk menghitung Persentase Susut
function hitung_susut($berat_hidup, $berat_potong) {
    if ($berat_hidup > 0) {
        // Hitung Susut = ((Berat Hidup - Berat Potong) / Berat Hidup) * 100
        $susut = (($berat_hidup - $berat_potong) / $berat_hidup) * 100;
        return round($susut, 2); // Bulatkan 2 angka di belakang koma
    }
    return 0.00;
}

// Tanggal hari ini untuk form default
$today_date = date('Y-m-d');

// --- LOGIKA CRUD (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        // 1. TAMBAH PROSES PEMOTONGAN
        case 'tambah_potongan':
            $tanggal = $_POST['tanggal'];
            $jumlah_ekor = (int)$_POST['jumlah_ekor']; 
            $berat_hidup_kg = (float)$_POST['berat_hidup_kg'];
            $berat_potong_kg = (float)$_POST['berat_potong_kg'];
            
            // Hitung Persentase Susut
            $persentase_susut = hitung_susut($berat_hidup_kg, $berat_potong_kg);
            
            if (!empty($tanggal) && $jumlah_ekor > 0 && $berat_hidup_kg > 0 && $berat_potong_kg >= 0) {
                try {
                    $stmt = $conn->prepare("INSERT INTO proses_pemotongan (tanggal, jumlah_ekor, berat_hidup_kg, berat_potong_kg, persentase_susut) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tanggal, $jumlah_ekor, $berat_hidup_kg, $berat_potong_kg, $persentase_susut]);
                    $_SESSION['success_message'] = "Data Pemotongan berhasil dicatat. Susut: " . $persentase_susut . "%.";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal menyimpan data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom (kecuali Berat Potong) wajib diisi dengan nilai yang valid (> 0).";
            }
            break;

        // 2. UPDATE PROSES PEMOTONGAN
        case 'update_potongan':
            $id = $_POST['id'];
            $tanggal_baru = $_POST['tanggal'];
            $jumlah_ekor_baru = (int)$_POST['jumlah_ekor'];
            $berat_hidup_kg_baru = (float)$_POST['berat_hidup_kg'];
            $berat_potong_kg_baru = (float)$_POST['berat_potong_kg'];
            
            // Hitung Persentase Susut Baru
            $persentase_susut_baru = hitung_susut($berat_hidup_kg_baru, $berat_potong_kg_baru);

            if (!empty($tanggal_baru) && $jumlah_ekor_baru > 0 && $berat_hidup_kg_baru > 0 && $berat_potong_kg_baru >= 0) {
                try {
                    $stmt = $conn->prepare("UPDATE proses_pemotongan SET tanggal = ?, jumlah_ekor = ?, berat_hidup_kg = ?, berat_potong_kg = ?, persentase_susut = ? WHERE id = ?");
                    $stmt->execute([$tanggal_baru, $jumlah_ekor_baru, $berat_hidup_kg_baru, $berat_potong_kg_baru, $persentase_susut_baru, $id]);
                    $_SESSION['success_message'] = "Data Pemotongan berhasil diperbarui. Susut: " . $persentase_susut_baru . "%.";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal memperbarui data: " . $e->getMessage();
                }
            } else {
                $_SESSION['error_message'] = "Semua kolom (kecuali Berat Potong) wajib diisi dengan nilai yang valid (> 0).";
            }
            break;

        // 3. DELETE PROSES PEMOTONGAN
        case 'delete_potongan':
            $id = $_POST['id'];
            try {
                $stmt_delete = $conn->prepare("DELETE FROM proses_pemotongan WHERE id = ?");
                $stmt_delete->execute([$id]);
                $_SESSION['success_message'] = "Data proses pemotongan berhasil dihapus.";
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Gagal menghapus data: " . $e->getMessage();
            }
            break;
    }
    // Pola PRG (Post-Redirect-Get)
    header('Location: proses_pemotongan.php');
    exit();
}
// --- AKHIR LOGIKA CRUD POST ---

// B. AMBIL DATA UNTUK EDIT (Via GET)
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id_edit = $_GET['id'];
    try {
        $stmt_edit = $conn->prepare("SELECT * FROM proses_pemotongan WHERE id = ?");
        $stmt_edit->execute([$id_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);

        if ($edit_data) {
            $show_popup_edit = true; 
        } else {
            $error_message = "Data pemotongan tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error_message = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// C. LOGIKA FILTER TABEL BERDASARKAN KEYWORD
$keyword = $_GET['keyword'] ?? '';

$sql = "SELECT * FROM proses_pemotongan";
$params = [];
$where_clauses = [];

if (!empty($keyword)) {
    // Mencari di kolom tanggal, jumlah_ekor, berat_hidup_kg, berat_potong_kg
    $where_clauses[] = "(tanggal LIKE ? OR jumlah_ekor LIKE ? OR berat_hidup_kg LIKE ? OR berat_potong_kg LIKE ?)";
    $search_param = "%" . $keyword . "%";
    
    $params[] = $search_param; // tanggal
    $params[] = $search_param; // jumlah_ekor
    $params[] = $search_param; // berat_hidup_kg
    $params[] = $search_param; // berat_potong_kg
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY tanggal DESC, id DESC"; // Urutkan berdasarkan tanggal terbaru

// --- D. Query Data untuk Tabel (Filter Aktif) ---
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data_potongan = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_potongan = [];
    $error_message = "Gagal mengambil data pemotongan: " . $e->getMessage();
}
?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-semibold text-gray-800">Proses Pemotongan Ayam</h1>
        
        <button onclick="document.getElementById('modal-tambah').classList.remove('hidden')"
                class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg shadow-md transition duration-150 flex items-center">
            <span class="mr-1 text-lg">🔪</span> Catat Pemotongan
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
        <form method="GET" action="proses_pemotongan.php" class="flex flex-col md:flex-row md:space-x-4 space-y-2 md:space-y-0 items-stretch md:items-center w-full">
            <h3 class="font-medium text-gray-700 whitespace-nowrap pt-2 md:pt-0">Cari Data:</h3>
            
            <input type="text" name="keyword" 
                   value="<?php echo htmlspecialchars($keyword); ?>"
                   placeholder="Cari Tanggal, Ekor, atau Berat..."
                   class="p-2 border border-gray-300 rounded shadow-sm w-full md:w-auto flex-grow focus:ring-red-500 focus:border-red-500">
            
            <div class="flex space-x-2">
                <button type="submit" 
                    class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">
                    Terapkan
                </button>
                <a href="proses_pemotongan.php" class="text-center border border-gray-300 text-gray-700 hover:bg-gray-100 py-2 px-4 rounded transition duration-150 whitespace-nowrap w-1/2 md:w-auto">Reset</a>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-xl overflow-x-auto" style="max-height:750px; overflow-y:auto;">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Tanggal</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Jumlah Ekor</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Berat Hidup (Kg)</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Berat Potong (Kg)</th>
                    <th scope='col' class='px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider'>Susut (%)</th>
                    <th scope='col' class='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($data_potongan)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Tidak ada data proses pemotongan yang ditemukan.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data_potongan as $data): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($data['tanggal']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($data['jumlah_ekor'], 0, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($data['berat_hidup_kg'], 2, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($data['berat_potong_kg'], 2, ',', '.'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo ($data['persentase_susut'] > 5) ? 'text-red-600' : 'text-green-600'; ?> text-right"><?php echo number_format($data['persentase_susut'], 2, ',', '.') . '%'; ?></td>
                            
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="proses_pemotongan.php?action=edit&id=<?php echo $data['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                
                                <form method="POST" action="proses_pemotongan.php" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data pemotongan ini? Tindakan ini tidak dapat dibatalkan.')">
                                    <input type="hidden" name="action" value="delete_potongan">
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
            <h3 class="text-xl font-semibold text-gray-800">Catat Proses Pemotongan Baru</h3>
            <button onclick="document.getElementById('modal-tambah').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="proses_pemotongan.php" method="POST">
            <input type="hidden" name="action" value="tambah_potongan">

            <div class="mb-4">
                <label for="tanggal" class="block text-sm font-medium text-gray-700">Tanggal Pemotongan</label>
                <input type="date" name="tanggal" value="<?php echo $today_date; ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>
            
            <div class="mb-4">
                <label for="jumlah_ekor" class="block text-sm font-medium text-gray-700">Jumlah Ekor yang Dipotong</label>
                <input type="number" name="jumlah_ekor" id="jumlah_ekor" min="1" placeholder="Contoh: 100" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="mb-6">
                    <label for="berat_hidup_kg" class="block text-sm font-medium text-gray-700">Berat Hidup Total (Kg)</label>
                    <input type="number" step="0.01" name="berat_hidup_kg" id="berat_hidup_kg" min="0.01" placeholder="Contoh: 250.5" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>

                <div class="mb-6">
                    <label for="berat_potong_kg" class="block text-sm font-medium text-gray-700">Berat Bersih/Potong (Kg)</label>
                    <input type="number" step="0.01" name="berat_potong_kg" id="berat_potong_kg" min="0" placeholder="Contoh: 235.0" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                    <p class="text-xs text-gray-500 mt-1">Susut akan dihitung otomatis.</p>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Simpan Data Pemotongan
                </button>
            </div>
        </form>
    </div>
</div>
<div id="modal-edit" class="fixed inset-0 bg-gray-600 bg-opacity-75 <?php echo $show_popup_edit ? 'flex' : 'hidden'; ?> items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Edit Proses Pemotongan</h3>
            <a href="proses_pemotongan.php" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</a>
        </div>
        
        <?php if ($edit_data): ?>
            <form action="proses_pemotongan.php" method="POST">
                <input type="hidden" name="action" value="update_potongan">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_data['id']); ?>">

                <div class="mb-4">
                    <label for="tanggal_edit" class="block text-sm font-medium text-gray-700">Tanggal Pemotongan</label>
                    <input type="date" name="tanggal" id="tanggal_edit" value="<?php echo htmlspecialchars($edit_data['tanggal']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>

                <div class="mb-4">
                    <label for="jumlah_ekor_edit" class="block text-sm font-medium text-gray-700">Jumlah Ekor yang Dipotong</label>
                    <input type="number" name="jumlah_ekor" id="jumlah_ekor_edit" min="1" value="<?php echo htmlspecialchars($edit_data['jumlah_ekor']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="mb-6">
                        <label for="berat_hidup_kg_edit" class="block text-sm font-medium text-gray-700">Berat Hidup Total (Kg)</label>
                        <input type="number" step="0.01" name="berat_hidup_kg" id="berat_hidup_kg_edit" min="0.01" value="<?php echo htmlspecialchars($edit_data['berat_hidup_kg']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                    </div>

                    <div class="mb-6">
                        <label for="berat_potong_kg_edit" class="block text-sm font-medium text-gray-700">Berat Bersih/Potong (Kg)</label>
                        <input type="number" step="0.01" name="berat_potong_kg" id="berat_potong_kg_edit" min="0" value="<?php echo htmlspecialchars($edit_data['berat_potong_kg']); ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                        <p class="text-xs text-gray-500 mt-1">Susut saat ini: **<?php echo number_format($edit_data['persentase_susut'], 2, ',', '.') . '%'; ?>**</p>
                    </div>
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