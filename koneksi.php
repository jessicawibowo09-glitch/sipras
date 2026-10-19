<?php
$host = "localhost";
$username = "root";
$password = "";
$database = "db_sipras";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $koneksi = new mysqli(
        $host,
        $username,
        $password,
        $database
    );

    $koneksi->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Koneksi database gagal. Periksa pengaturan database.");
}
?>
