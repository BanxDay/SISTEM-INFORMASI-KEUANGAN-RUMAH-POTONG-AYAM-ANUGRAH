<?php
// Konfigurasi Database
$db_host = 'localhost'; // Umumnya 'localhost' di lingkungan lokal
$db_user = 'root';      // Username default XAMPP/Laragon
$db_pass = '';          // Password default XAMPP/Laragon (kosong)
$db_name = 'rpa_anugrah'; // GANTI dengan nama database yang Anda buat

// Mencoba koneksi
try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    // Mengatur mode error PDO ke exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Koneksi database berhasil!"; // Hapus baris ini setelah pengujian
} catch(PDOException $e) {
    // Jika koneksi gagal, tampilkan pesan error
    die("Koneksi database gagal: " . $e->getMessage());
}
?>