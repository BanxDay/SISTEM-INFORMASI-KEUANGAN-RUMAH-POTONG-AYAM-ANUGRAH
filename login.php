<?php
// Sertakan file koneksi
require_once 'config.php';

// Cek apakah admin sudah login, jika ya, arahkan ke dashboard
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error_message = ''; // Variabel untuk menyimpan pesan error

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Ambil input
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error_message = "Email dan Kata Sandi wajib diisi.";
    } else {
        try {
            // 2. Query ke database untuk mencari user berdasarkan email
            $stmt = $conn->prepare("SELECT id, password_hash FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Verifikasi Password
            if ($user && password_verify($password, $user['password_hash'])) {
                // Login Berhasil
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $email;
                
                // Arahkan ke Dashboard
                header('Location: dashboard.php');
                exit();
            } else {
                // Login Gagal
                $error_message = "Email atau kata sandi salah.";
            }
        } catch (PDOException $e) {
            $error_message = "Terjadi kesalahan database: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Manajemen Ayam Potong</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Gaya tambahan agar tampilan lebih bersih dan modern */
        body {
            background-color: #f3f4f6; /* Abu-abu muda untuk latar belakang */
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md">
        <div class="bg-white shadow-xl rounded-lg p-8">
            <h2 class="text-3xl font-bold text-gray-800 text-center mb-6">Masuk ke Sistem</h2>

            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-semibold mb-2">Email</label>
                    <input type="email" id="email" name="email" required
                           class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-teal-500"
                           placeholder="Masukkan Email Anda">
                </div>

                <div class="mb-6">
                    <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Kata Sandi</label>
                    <input type="password" id="password" name="password" required
                           class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-teal-500"
                           placeholder="Masukkan Kata Sandi">
                </div>

                <div class="flex items-center justify-between mb-4">
                    <button type="submit"
                            class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 w-full">
                        Masuk
                    </button>
                </div>
                
                <div class="text-center">
                    <a class="inline-block align-baseline font-bold text-sm text-teal-600 hover:text-teal-800" href="#">
                        Lupa kata sandi? (Hubungi Administrator Sistem)
                    </a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>