<?php
// Pastikan sesi dimulai untuk dapat diakses dan dihancurkan
session_start();

// Hapus semua variabel sesi
$_SESSION = array();

// Jika menggunakan cookie sesi, hapus juga cookie-nya.
// Perintah ini akan menghancurkan cookie sesi.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hancurkan sesi
session_destroy();

// Arahkan (redirect) pengguna ke halaman login
header("Location: login.php"); // Ganti 'login.php' jika nama file login Anda berbeda
exit();
?>