<?php
session_start();
require_once 'config.php'; // Memuat koneksi DB dan fungsi

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// --- Ambil Data Pengaturan Saat Ini (Nama Web & Logo) ---
// ASUMSI: Data pengaturan disimpan dalam tabel 'settings' dengan key-value pair
try {
    $stmt_name = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'website_name'");
    $stmt_name->execute();
    $website_name = $stmt_name->fetch(PDO::FETCH_COLUMN) ?: 'APLIKASI AYAM';

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
    $website_name = 'APLIKASI AYAM';
    $website_logo = 'assets/img/default_logo.png';
}


// --- LOGIKA FORM POST (UBAH PENGATURAN) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    switch ($action) {
        
        // 1. UBAH LOGO
        case 'ubah_logo':
            if (isset($_FILES['new_logo']) && $_FILES['new_logo']['error'] === 0) {
                $file_size = $_FILES['new_logo']['size'];
                // Batas ukuran file: 5MB
                if ($file_size > 5242880) {
                    $_SESSION['error_message'] = "Ukuran file logo tidak boleh melebihi 5MB.";
                } else {
                    $file_content = file_get_contents($_FILES['new_logo']['tmp_name']);
                    $file_type = $_FILES['new_logo']['type']; // e.g., 'image/png'
                    
                    if ($file_content === false) {
                        $_SESSION['error_message'] = "Gagal membaca file logo.";
                    } else {
                        try {
                            // Simpan file biner (base64 encoded) dan tipe MIME ke database
                            $encoded_file = base64_encode($file_content);
                            $stmt = $conn->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
                            $stmt->execute(['website_logo_file', $encoded_file]);
                            $stmt->execute(['website_logo_type', $file_type]);
                            $_SESSION['success_message'] = "Logo web berhasil diubah.";
                        } catch (PDOException $e) {
                            $_SESSION['error_message'] = "Gagal menyimpan logo ke DB: " . $e->getMessage();
                        }
                    }
                }
            } else {
                $_SESSION['error_message'] = "Pilih file gambar yang valid.";
            }
            break;

        // 2. UBAH NAMA WEB
        case 'ubah_nama':
            $new_name = trim($_POST['new_name'] ?? '');
            if (!empty($new_name)) {
                 try {
                    $stmt = $conn->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('website_name', ?)");
                    $stmt->execute([$new_name]);
                    $_SESSION['success_message'] = "Nama web berhasil diubah menjadi: " . $new_name;
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Gagal mengubah nama web: " . $e->getMessage();
                }
            } else {
                 $_SESSION['error_message'] = "Nama web tidak boleh kosong.";
            }
            break;
            
        // 3. UBAH KATA SANDI
        case 'ubah_password':
            $old_password = $_POST['old_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $repeat_password = $_POST['repeat_password'] ?? '';

            // Pengecekan 1: Kata Sandi Baru harus sama
            if ($new_password !== $repeat_password) {
                $_SESSION['error_message'] = "Ulangi Kata Sandi Baru tidak cocok.";
                break;
            }
            
            // Pengecekan 2: Kata Sandi Baru tidak boleh kosong
            if (empty($new_password) || strlen($new_password) < 6) {
                $_SESSION['error_message'] = "Kata Sandi Baru minimal 6 karakter.";
                break;
            }

            try {
                // Pengecekan 3: Verifikasi Kata Sandi Lama
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($old_password, $user['password_hash'])) {
                    // Update password hash baru
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt_update = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt_update->execute([$new_hash, $user_id]);
                    $_SESSION['success_message'] = "Kata sandi berhasil diubah!";
                } else {
                    $_SESSION['error_message'] = "Kata sandi lama salah."; // Kegagalan utama
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Gagal memproses perubahan kata sandi: " . $e->getMessage();
            }
            break;
    }

    // Pola PRG (Post-Redirect-Get)
    header('Location: settings.php');
    exit();
}

// Data user saat ini
try {
    $stmt_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt_user->execute([$_SESSION['user_id']]);
    $current_username = $stmt_user->fetch(PDO::FETCH_COLUMN) ?: 'User';
} catch (PDOException $e) {
     $current_username = 'User';
}

?>

<?php include '_layout_header.php'; ?>

<div class="space-y-8">
    
    <h1 class="text-3xl font-semibold text-gray-800">Pengaturan Aplikasi dan Akun</h1>
    
    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <?php echo $success_message; ?>
        </div>
    <?php elseif ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-xl">
        <h2 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">Pengaturan Umum Web</h2>
        
        <div class="space-y-6">
            
            <div class="flex items-center justify-between border-b pb-4">
                <div>
                    <h3 class="font-semibold text-gray-700">Nama Website</h3>
                    <p class="text-sm text-gray-500">Nama yang saat ini ditampilkan: <span class="font-medium text-red-600"><?php echo htmlspecialchars($website_name); ?></span></p>
                </div>
                <button onclick="document.getElementById('modal-nama').classList.remove('hidden')"
                        class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-md">
                    Ubah Nama
                </button>
            </div>
            
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-700">Logo Website</h3>
                    <p class="text-sm text-gray-500">Logo yang saat ini digunakan:</p>
                    <img src="<?php echo htmlspecialchars($website_logo); ?>" alt="Logo Saat Ini" class="mt-2 h-16 max-w-sm object-contain border p-1 rounded">
                </div>
                <button onclick="document.getElementById('modal-logo').classList.remove('hidden')"
                        class="bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-md self-start">
                    Ubah Logo
                </button>
            </div>

        </div>
    </div>
    
    <div class="bg-white p-6 rounded-lg shadow-xl">
        <h2 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">Pengaturan Akun</h2>
        
        <div class="space-y-6">
            
            
            
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-700">Kata Sandi</h3>
                    <p class="text-sm text-gray-500">Ubah kata sandi Anda secara berkala untuk keamanan.</p>
                </div>
                <button onclick="document.getElementById('modal-password').classList.remove('hidden')"
                        class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md">
                    Ubah Kata Sandi
                </button>
            </div>

        </div>
    </div>

</div>

<div id="modal-logo" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Ubah Logo Website</h3>
            <button onclick="document.getElementById('modal-logo').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="settings.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="ubah_logo">

            <div class="mb-6">
                <label for="new_logo" class="block text-sm font-medium text-gray-700">Pilih File Logo (JPG/PNG)</label>
                <input type="file" name="new_logo" id="new_logo" accept="image/png, image/jpeg" required 
                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-md">
                    Simpan Logo
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-nama" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Ubah Nama Website</h3>
            <button onclick="document.getElementById('modal-nama').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="ubah_nama">

            <div class="mb-6">
                <label for="new_name" class="block text-sm font-medium text-gray-700">Nama Baru</label>
                <input type="text" name="new_name" id="new_name" value="<?php echo htmlspecialchars($website_name); ?>" required 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-md">
                    Simpan Nama
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-password" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm p-6">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Ubah Kata Sandi</h3>
            <button onclick="document.getElementById('modal-password').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        
        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="ubah_password">

            <div class="mb-4">
                <label for="old_password" class="block text-sm font-medium text-gray-700">Kata Sandi Lama</label>
                <input type="password" name="old_password" id="old_password" required 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>

            <div class="mb-4">
                <label for="new_password" class="block text-sm font-medium text-gray-700">Kata Sandi Baru</label>
                <input type="password" name="new_password" id="new_password" required minlength="6"
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
                <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter.</p>
            </div>
            
            <div class="mb-6">
                <label for="repeat_password" class="block text-sm font-medium text-gray-700">Ulangi Kata Sandi Baru</label>
                <input type="password" name="repeat_password" id="repeat_password" required 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-red-500 focus:border-red-500">
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md">
                    Ubah Kata Sandi
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '_layout_footer.php'; ?>