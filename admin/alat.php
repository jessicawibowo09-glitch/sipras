
<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================
   CATAT LOG AKTIVITAS ADMIN
========================= */
function catatAktivitasAlat($koneksi, $aktivitas, $detail)
{
    $idUser = (int)($_SESSION['id_user'] ?? 0);
    $namaAdmin = $_SESSION['nama']
        ?? $_SESSION['username']
        ?? 'Administrator';

    $alamatIP = $_SERVER['REMOTE_ADDR'] ?? '-';
    $namaTabel = 'alat';

    $stmt = $koneksi->prepare(
        "INSERT INTO log_aktivitas
        (
            id_user,
            aktivitas,
            detail,
            alamat_ip,
            waktu,
            nama_admin,
            nama_tabel
        )
        VALUES (?, ?, ?, ?, NOW(), ?, ?)"
    );

    $stmt->bind_param(
        'isssss',
        $idUser,
        $aktivitas,
        $detail,
        $alamatIP,
        $namaAdmin,
        $namaTabel
    );

    $stmt->execute();
    $stmt->close();
}

/* =========================
   TOKEN KEAMANAN FORM
========================= */
if (empty($_SESSION['csrf_alat'])) {
    $_SESSION['csrf_alat'] = bin2hex(random_bytes(32));
}

$pesan = '';
$error = false;
$modeEdit = false;

$dataEdit = [
    'id_alat' => 0,
    'kode_inventaris' => '',
    'nama_alat' => '',
    'id_kategori' => '',
    'id_ruangan' => '',
    'deskripsi' => '',
    'stok_total' => 0,
    'stok_tersedia' => 0,
    'kondisi' => 'baik',
    'status' => 'tersedia'
];

$daftarKondisi = [
    'baik' => 'Baik',
    'perlu_perbaikan' => 'Perlu Perbaikan',
    'rusak' => 'Rusak'
];

$daftarStatus = [
    'tersedia' => 'Tersedia',
    'dipinjam' => 'Dipinjam',
    'perawatan' => 'Perawatan',
    'nonaktif' => 'Nonaktif'
];

/* =========================
   NOTIFIKASI
========================= */
if (isset($_SESSION['flash_alat'])) {
    $pesan = $_SESSION['flash_alat']['pesan'];
    $error = $_SESSION['flash_alat']['error'];
    unset($_SESSION['flash_alat']);
}

/* =========================
   PROSES TAMBAH, EDIT, HAPUS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_alat'], $token)
    ) {
        die('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        /* =========================
           TAMBAH ATAU EDIT SARANA
        ========================= */
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id = (int)($_POST['id_alat'] ?? 0);
            $kode = trim($_POST['kode_inventaris'] ?? '');
            $nama = trim($_POST['nama_alat'] ?? '');
            $idKategori = (int)($_POST['id_kategori'] ?? 0);

            $ruanganInput = trim($_POST['id_ruangan'] ?? '');
            $idRuangan = $ruanganInput === ''
                ? null
                : (int)$ruanganInput;

            $deskripsi = trim($_POST['deskripsi'] ?? '');

            $stokTotal = filter_var(
                $_POST['stok_total'] ?? null,
                FILTER_VALIDATE_INT
            );

            $kondisi = $_POST['kondisi'] ?? 'baik';
            $status = $_POST['status'] ?? 'tersedia';

            $modeEdit = ($aksi === 'edit');

            $dataEdit = [
                'id_alat' => $id,
                'kode_inventaris' => $kode,
                'nama_alat' => $nama,
                'id_kategori' => $idKategori,
                'id_ruangan' => $idRuangan,
                'deskripsi' => $deskripsi,
                'stok_total' => $stokTotal === false ? 0 : $stokTotal,
                'stok_tersedia' => 0,
                'kondisi' => $kondisi,
                'status' => $status
            ];

            if ($kode === '' || $nama === '') {
                throw new Exception(
                    'Kode inventaris dan nama sarana wajib diisi.'
                );
            }

            if (mb_strlen($kode) > 30 || mb_strlen($nama) > 100) {
                throw new Exception(
                    'Kode inventaris maksimal 30 karakter dan nama maksimal 100 karakter.'
                );
            }

            if ($idKategori < 1) {
                throw new Exception('Silakan pilih kategori sarana.');
            }

            if ($idRuangan !== null && $idRuangan < 1) {
                throw new Exception('Ruangan yang dipilih tidak valid.');
            }

            if ($stokTotal === false || $stokTotal < 0) {
                throw new Exception(
                    'Jumlah stok harus berupa angka nol atau lebih.'
                );
            }

            if (!array_key_exists($kondisi, $daftarKondisi)) {
                throw new Exception('Kondisi sarana tidak valid.');
            }

            if (!array_key_exists($status, $daftarStatus)) {
                throw new Exception('Status sarana tidak valid.');
            }

            /* VALIDASI KATEGORI */
            $stmt = $koneksi->prepare(
                'SELECT id_kategori
                 FROM kategori
                 WHERE id_kategori = ?'
            );

            $stmt->bind_param('i', $idKategori);
            $stmt->execute();

            $kategoriAda = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$kategoriAda) {
                throw new Exception(
                    'Kategori yang dipilih tidak ditemukan.'
                );
            }

            /* VALIDASI RUANGAN */
            if ($idRuangan !== null) {
                $stmt = $koneksi->prepare(
                    'SELECT id_ruangan
                     FROM ruangan
                     WHERE id_ruangan = ?'
                );

                $stmt->bind_param('i', $idRuangan);
                $stmt->execute();

                $ruanganAda = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$ruanganAda) {
                    throw new Exception(
                        'Ruangan yang dipilih tidak ditemukan.'
                    );
                }
            }

            /* =========================
               TAMBAH SARANA
            ========================= */
            if ($aksi === 'tambah') {
                $stokTersedia = $stokTotal;

                $stmt = $koneksi->prepare(
                    'INSERT INTO alat (
                        kode_inventaris,
                        nama_alat,
                        id_kategori,
                        id_ruangan,
                        deskripsi,
                        stok_total,
                        stok_tersedia,
                        kondisi,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->bind_param(
                    'ssiisiiss',
                    $kode,
                    $nama,
                    $idKategori,
                    $idRuangan,
                    $deskripsi,
                    $stokTotal,
                    $stokTersedia,
                    $kondisi,
                    $status
                );

                $stmt->execute();
                $stmt->close();

                /* CATAT LOG TAMBAH */
                catatAktivitasAlat(
                    $koneksi,
                    'tambah',
                    "Menambahkan sarana: {$nama} " .
                    "(Kode inventaris: {$kode}), " .
                    "stok: {$stokTotal}, " .
                    "kondisi: {$daftarKondisi[$kondisi]}, " .
                    "status: {$daftarStatus[$status]}."
                );

                $_SESSION['flash_alat'] = [
                    'pesan' => 'Sarana berhasil ditambahkan.',
                    'error' => false
                ];
            }

            /* =========================
               EDIT SARANA
            ========================= */
            else {
                if ($id < 1) {
                    throw new Exception('ID sarana tidak valid.');
                }

                $stmt = $koneksi->prepare(
                    'SELECT stok_total, stok_tersedia
                     FROM alat
                     WHERE id_alat = ?
                     FOR UPDATE'
                );

                $stmt->bind_param('i', $id);
                $stmt->execute();

                $stokLama = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$stokLama) {
                    throw new Exception('Data sarana tidak ditemukan.');
                }

                $sedangDipinjam =
                    (int)$stokLama['stok_total'] -
                    (int)$stokLama['stok_tersedia'];

                if ($stokTotal < $sedangDipinjam) {
                    throw new Exception(
                        'Stok total tidak boleh lebih kecil dari jumlah sarana yang sedang dipinjam.'
                    );
                }

                $stokTersedia = $stokTotal - $sedangDipinjam;

                $stmt = $koneksi->prepare(
                    'UPDATE alat SET
                        kode_inventaris = ?,
                        nama_alat = ?,
                        id_kategori = ?,
                        id_ruangan = ?,
                        deskripsi = ?,
                        stok_total = ?,
                        stok_tersedia = ?,
                        kondisi = ?,
                        status = ?
                     WHERE id_alat = ?'
                );

                $stmt->bind_param(
                    'ssiisiissi',
                    $kode,
                    $nama,
                    $idKategori,
                    $idRuangan,
                    $deskripsi,
                    $stokTotal,
                    $stokTersedia,
                    $kondisi,
                    $status,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                /* CATAT LOG EDIT */
                catatAktivitasAlat(
                    $koneksi,
                    'edit',
                    "Mengubah data sarana: {$nama} " .
                    "(Kode inventaris: {$kode}), " .
                    "stok total: {$stokTotal}, " .
                    "stok tersedia: {$stokTersedia}, " .
                    "kondisi: {$daftarKondisi[$kondisi]}, " .
                    "status: {$daftarStatus[$status]}."
                );

                $_SESSION['flash_alat'] = [
                    'pesan' => 'Data sarana berhasil diperbarui.',
                    'error' => false
                ];
            }

            header('Location: alat.php');
            exit;
        }

        /* =========================
           HAPUS SARANA
        ========================= */
        elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_alat'] ?? 0);

            if ($id < 1) {
                throw new Exception('ID sarana tidak valid.');
            }

            $stmt = $koneksi->prepare(
                'SELECT
                    kode_inventaris,
                    nama_alat,
                    stok_total,
                    stok_tersedia
                 FROM alat
                 WHERE id_alat = ?'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();

            $alat = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$alat) {
                throw new Exception('Data sarana tidak ditemukan.');
            }

            if (
                (int)$alat['stok_total'] !==
                (int)$alat['stok_tersedia']
            ) {
                throw new Exception(
                    'Sarana tidak dapat dihapus karena masih ada unit yang dipinjam.'
                );
            }

            $stmt = $koneksi->prepare(
                'DELETE FROM alat WHERE id_alat = ?'
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            /* CATAT LOG HAPUS */
            catatAktivitasAlat(
                $koneksi,
                'hapus',
                "Menghapus sarana: {$alat['nama_alat']} " .
                "(Kode inventaris: {$alat['kode_inventaris']}), " .
                "stok total sebelumnya: {$alat['stok_total']}."
            );

            $_SESSION['flash_alat'] = [
                'pesan' => 'Data sarana berhasil dihapus.',
                'error' => false
            ];

            header('Location: alat.php');
            exit;
        }

        else {
            throw new Exception('Aksi tidak dikenal.');
        }

    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1062) {
            $pesan = 'Kode inventaris sudah digunakan. Gunakan kode lain.';
        } else {
            error_log($ex->getMessage());

            $pesan = 'Data gagal diproses. Periksa relasi transaksi dan struktur database.';
        }

        $error = true;

    } catch (Exception $ex) {
        $pesan = $ex->getMessage();
        $error = true;
    }
}

/* =========================
   DATA KATEGORI DAN RUANGAN
========================= */
$kategoriList = $koneksi->query(
    'SELECT id_kategori, nama_kategori
     FROM kategori
     ORDER BY nama_kategori ASC'
)->fetch_all(MYSQLI_ASSOC);

$ruanganList = $koneksi->query(
    'SELECT id_ruangan, kode_ruangan, nama_ruangan
     FROM ruangan
     ORDER BY nama_ruangan ASC'
)->fetch_all(MYSQLI_ASSOC);

/* =========================
   DATA UNTUK FORM EDIT
========================= */
if (!$modeEdit && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $koneksi->prepare(
        'SELECT
            id_alat,
            kode_inventaris,
            nama_alat,
            id_kategori,
            id_ruangan,
            deskripsi,
            stok_total,
            stok_tersedia,
            kondisi,
            status
         FROM alat
         WHERE id_alat = ?'
    );

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $rowEdit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rowEdit) {
        $dataEdit = $rowEdit;
        $modeEdit = true;
    } else {
        $pesan = 'Data sarana yang dipilih tidak ditemukan.';
        $error = true;
    }
}

/* =========================
   PENCARIAN DATA SARANA
========================= */
$cari = trim($_GET['cari'] ?? '');

$sqlDasar = '
    SELECT
        a.id_alat,
        a.kode_inventaris,
        a.nama_alat,
        a.id_kategori,
        a.id_ruangan,
        a.deskripsi,
        a.stok_total,
        a.stok_tersedia,
        a.kondisi,
        a.status,
        k.nama_kategori,
        r.nama_ruangan
    FROM alat a
    LEFT JOIN kategori k ON a.id_kategori = k.id_kategori
    LEFT JOIN ruangan r ON a.id_ruangan = r.id_ruangan
';

if ($cari !== '') {
    $keyword = '%' . $cari . '%';

    $stmt = $koneksi->prepare(
        $sqlDasar . '
        WHERE a.kode_inventaris LIKE ?
           OR a.nama_alat LIKE ?
           OR k.nama_kategori LIKE ?
           OR r.nama_ruangan LIKE ?
           OR a.kondisi LIKE ?
           OR a.status LIKE ?
        ORDER BY a.id_alat DESC'
    );

    $stmt->bind_param(
        'ssssss',
        $keyword,
        $keyword,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );

    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = $koneksi->query(
        $sqlDasar . ' ORDER BY a.id_alat DESC'
    );
}

/* =========================
   STATISTIK SARANA
========================= */
$stat = $koneksi->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stok_total), 0) AS stok,
        COALESCE(SUM(stok_tersedia), 0) AS tersedia,
        COALESCE(SUM(kondisi = 'baik'), 0) AS baik
     FROM alat"
)->fetch_assoc();

$totalAlat = (int)$stat['total'];
$totalStok = (int)$stat['stok'];
$totalTersedia = (int)$stat['tersedia'];
$totalBaik = (int)$stat['baik'];

$namaAdmin = $_SESSION['nama']
    ?? $_SESSION['username']
    ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Data Sarana | SIPRAS</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f5f7fc;
    color: #263653;
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    display: flex;
    flex-direction: column;
    width: 250px;
    height: 100vh;
    padding: 26px 18px;
    background: #12284b;
    color: white;
    overflow-y: auto;
    z-index: 100;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 8px 28px;
    border-bottom: 1px solid #314360;
}

.logo {
    display: grid;
    place-items: center;
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    border-radius: 13px;
    background: linear-gradient(135deg, #4385ff, #2452c8);
    font-size: 22px;
    font-weight: bold;
}

.brand h2 {
    margin: 0;
    font-size: 19px;
    letter-spacing: 2px;
}

.brand p {
    margin: 4px 0 0;
    color: #b9c9e6;
    font-size: 10px;
}

.menu-title {
    margin: 28px 10px 12px;
    color: #91a6c9;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 1.5px;
}

.menu {
    display: grid;
    gap: 6px;
}

.menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 13px;
    border-radius: 10px;
    color: #d5def0;
    text-decoration: none;
    font-size: 13px;
    transition: .2s;
}

.menu a:hover,
.menu a.active {
    background: #294b83;
    color: white;
}

.menu a.active {
    box-shadow: inset 3px 0 #76a7ff;
}

.menu-icon {
    width: 21px;
    flex-shrink: 0;
    text-align: center;
    font-size: 17px;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 24px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 9px;
    border-top: 1px solid #314360;
}

.avatar {
    display: grid;
    place-items: center;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 12px;
    background: #e3ecff;
    color: #2454bc;
    font-weight: bold;
}

.profile-name {
    max-width: 140px;
    overflow-wrap: anywhere;
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.profile-role {
    margin-top: 4px;
    color: #b6c7e4;
    font-size: 11px;
}

.logout {
    display: block;
    margin-top: 8px;
    padding: 11px;
    border: 1px solid #405271;
    border-radius: 9px;
    color: #ffe0e0;
    text-align: center;
    text-decoration: none;
    font-size: 12px;
    transition: .2s;
}

.logout:hover {
    background: rgba(255,255,255,.08);
}

/* KONTEN UTAMA */
.main {
    width: calc(100% - 250px);
    min-height: 100vh;
    margin-left: 250px;
    padding: 30px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 25px;
}

.breadcrumb {
    margin-bottom: 8px;
    color: #8490a5;
    font-size: 12px;
}

h1 {
    margin: 0;
    color: #12284b;
    font-size: 28px;
}

.subtitle {
    margin-top: 8px;
    color: #8490a5;
    font-size: 13px;
    line-height: 1.6;
}

.date-badge {
    padding: 12px 16px;
    border: 1px solid #e5eaf3;
    border-radius: 12px;
    background: white;
    color: #536581;
    font-size: 12px;
    white-space: nowrap;
}

/* STATISTIK */
.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 19px;
    border: 1px solid #e9edf5;
    border-radius: 15px;
    background: white;
    box-shadow: 0 3px 12px #1e375a08;
    min-width: 0;
}

.stat-icon {
    display: grid;
    place-items: center;
    width: 45px;
    height: 45px;
    flex-shrink: 0;
    border-radius: 13px;
    background: #e9f0ff;
    color: #3268e9;
    font-size: 22px;
}

.stat-label {
    color: #8490a5;
    font-size: 12px;
}

.stat-value {
    margin-top: 5px;
    color: #12284b;
    font-size: 24px;
    font-weight: 800;
}

/* KARTU FORM */
.card {
    padding: 24px;
    margin-bottom: 22px;
    border: 1px solid #e9edf5;
    border-radius: 15px;
    background: white;
    box-shadow: 0 3px 12px #1e375a08;
}

.card-heading {
    margin-bottom: 20px;
}

.card-heading h2,
.toolbar h2 {
    color: #12284b;
    font-size: 17px;
}

.card-heading p,
.toolbar p {
    margin-top: 6px;
    color: #8490a5;
    font-size: 12px;
    line-height: 1.6;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: bold;
}

input,
textarea,
select {
    width: 100%;
    min-width: 0;
    padding: 12px 13px;
    border: 1px solid #dce3ee;
    border-radius: 9px;
    outline: none;
    background: white;
    color: #263653;
    font: inherit;
    font-size: 13px;
}

input:focus,
textarea:focus,
select:focus {
    border-color: #3268e9;
    box-shadow: 0 0 0 3px #3268e91a;
}

textarea {
    min-height: 90px;
    resize: vertical;
}

.note {
    margin-top: 6px;
    color: #8490a5;
    font-size: 11px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 20px;
}

.btn {
    display: inline-block;
    padding: 11px 15px;
    border: 0;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    font-size: 12px;
    font-weight: bold;
}

.primary {
    background: #3268e9;
    color: white;
}

.primary:hover {
    background: #2454c4;
}

.secondary {
    background: #edf1f7;
    color: #42516a;
}

.danger {
    background: #ffebeb;
    color: #b52c2c;
}

.alert {
    padding: 14px 16px;
    margin-bottom: 20px;
    border-radius: 9px;
    background: #e4f7eb;
    color: #207443;
    font-size: 13px;
    line-height: 1.6;
}

.alert.error {
    background: #fee9e9;
    color: #a82c2c;
}

/* TABEL SARANA */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}

.search-form {
    display: flex;
    gap: 8px;
    width: min(100%, 400px);
}

.search-form input {
    min-width: 0;
    flex: 1;
}

.table-wrap {
    overflow-x: auto;
    width: 100%;
}

table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

th,
td {
    padding: 14px 12px;
    border-bottom: 1px solid #edf0f5;
    text-align: left;
    vertical-align: top;
}

th {
    background: #f7f9fc;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}

td {
    font-size: 12px;
    line-height: 1.7;
}

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.status-tersedia,
.kondisi-baik {
    background: #e2f7e9;
    color: #237c49;
}

.status-dipinjam,
.kondisi-perlu_perbaikan {
    background: #fff2d6;
    color: #966400;
}

.status-perawatan {
    background: #e1efff;
    color: #2465b1;
}

.status-nonaktif,
.kondisi-rusak {
    background: #ffe7e7;
    color: #b33434;
}

.row-actions {
    display: flex;
    align-items: center;
    gap: 7px;
}

.row-actions form {
    margin: 0;
}

.row-actions .btn {
    padding: 8px 10px;
}

.empty {
    padding: 30px;
    color: #8490a5;
    text-align: center;
}

.footer {
    padding: 20px 0;
    color: #98a3b5;
    font-size: 11px;
    line-height: 1.8;
    text-align: center;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .sidebar-bottom {
        margin-top: 20px;
    }

    .main {
        width: 100%;
        margin-left: 0;
        padding: 18px;
    }

    .topbar,
    .toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .date-badge {
        display: none;
    }

    .stats,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .search-form {
        width: 100%;
    }

    .card {
        padding: 17px;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar">

    <div class="brand">
        <div class="logo">S</div>
        <div>
            <h2>SIPRAS</h2>
            <p>SMART FACILITY SYSTEM</p>
        </div>
    </div>

    <div class="menu-title">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php">
            <span class="menu-icon">⌂</span>
            <span>Dashboard</span>
        </a>

        <a href="kategori.php">
            <span class="menu-icon">▦</span>
            <span>Kategori</span>
        </a>

        <a href="ruangan.php">
            <span class="menu-icon">⌘</span>
            <span>Data Ruangan</span>
        </a>

        <a href="alat.php" class="active">
            <span class="menu-icon">▣</span>
            <span>Data Sarana</span>
        </a>

        <a href="pengguna.php">
            <span class="menu-icon">♙</span>
            <span>Data Pengguna</span>
        </a>
    </nav>

    <div class="menu-title">TRANSAKSI</div>

    <nav class="menu">
        <a href="peminjaman.php">
            <span class="menu-icon">⇄</span>
            <span>Peminjaman</span>
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>
            <span>Pengembalian</span>
        </a>

        <a href="sanksi.php">
            <span class="menu-icon">◷</span>
            <span>Sanksi Keterlambatan</span>
        </a>

        <a href="laporan.php">
            <span class="menu-icon">▤</span>
            <span>Laporan</span>
        </a>
    </nav>

    <div class="menu-title">SISTEM</div>

    <nav class="menu">
        <a href="log_aktivitas.php">
            <span class="menu-icon">◷</span>
            <span>Log Aktivitas</span>
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($namaAdmin, 0, 1))) ?>
            </div>

            <div>
                <div class="profile-name"><?= e($namaAdmin) ?></div>
                <div class="profile-role">Administrator</div>
            </div>
        </div>

        <a
            class="logout"
            href="../logout.php"
            onclick="return confirm('Yakin ingin keluar?')"
        >
            ↪ &nbsp; Keluar dari akun
        </a>
    </div>
</aside>

<!-- KONTEN UTAMA -->
<main class="main">

    <header class="topbar">
        <div>
            <div class="breadcrumb">
                SIPRAS / Menu Utama / Data Sarana
            </div>

            <h1>Data Sarana</h1>

            <p class="subtitle">
                Kelola inventaris dan ketersediaan sarana sekolah.
            </p>
        </div>

        <div class="date-badge">
            ◷ &nbsp; <?= date('d-m-Y') ?>
        </div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $error ? 'error' : '' ?>">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <!-- STATISTIK -->
    <section class="stats">

        <div class="stat-card">
            <div class="stat-icon">▣</div>
            <div>
                <div class="stat-label">Jenis Sarana</div>
                <div class="stat-value"><?= $totalAlat ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">▤</div>
            <div>
                <div class="stat-label">Total Stok</div>
                <div class="stat-value"><?= $totalStok ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">Stok Tersedia</div>
                <div class="stat-value"><?= $totalTersedia ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">✦</div>
            <div>
                <div class="stat-label">Jenis Kondisi Baik</div>
                <div class="stat-value"><?= $totalBaik ?></div>
            </div>
        </div>

    </section>

    <!-- FORM TAMBAH / EDIT -->
    <section class="card">

        <div class="card-heading">
            <h2>
                <?= $modeEdit ? 'Edit Data Sarana' : 'Tambah Sarana Baru' ?>
            </h2>

            <p>
                Masukkan data inventaris, kategori, lokasi, stok, dan kondisi.
            </p>
        </div>

        <form method="POST" action="alat.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_alat']) ?>"
            >

            <input
                type="hidden"
                name="aksi"
                value="<?= $modeEdit ? 'edit' : 'tambah' ?>"
            >

            <?php if ($modeEdit): ?>
                <input
                    type="hidden"
                    name="id_alat"
                    value="<?= (int)$dataEdit['id_alat'] ?>"
                >
            <?php endif; ?>

            <div class="form-grid">

                <div>
                    <label for="kode_inventaris">Kode Inventaris *</label>

                    <input
                        id="kode_inventaris"
                        name="kode_inventaris"
                        required
                        maxlength="30"
                        value="<?= e($dataEdit['kode_inventaris']) ?>"
                        placeholder="Contoh: INV-001"
                    >
                </div>

                <div>
                    <label for="nama_alat">Nama Sarana *</label>

                    <input
                        id="nama_alat"
                        name="nama_alat"
                        required
                        maxlength="100"
                        value="<?= e($dataEdit['nama_alat']) ?>"
                        placeholder="Contoh: Proyektor"
                    >
                </div>

                <div>
                    <label for="id_kategori">Kategori *</label>

                    <select id="id_kategori" name="id_kategori" required>
                        <option value="">-- Pilih Kategori --</option>

                        <?php foreach ($kategoriList as $kategori): ?>
                            <option
                                value="<?= (int)$kategori['id_kategori'] ?>"
                                <?= (string)$dataEdit['id_kategori'] ===
                                    (string)$kategori['id_kategori']
                                    ? 'selected' : ''
                                ?>
                            >
                                <?= e($kategori['nama_kategori']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="id_ruangan">Lokasi Ruangan</label>

                    <select id="id_ruangan" name="id_ruangan">
                        <option value="">-- Belum Ditentukan --</option>

                        <?php foreach ($ruanganList as $ruangan): ?>
                            <option
                                value="<?= (int)$ruangan['id_ruangan'] ?>"
                                <?= (string)$dataEdit['id_ruangan'] ===
                                    (string)$ruangan['id_ruangan']
                                    ? 'selected' : ''
                                ?>
                            >
                                <?= e(
                                    $ruangan['kode_ruangan'] . ' - ' .
                                    $ruangan['nama_ruangan']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="stok_total">Jumlah Stok *</label>

                    <input
                        id="stok_total"
                        name="stok_total"
                        type="number"
                        min="0"
                        required
                        value="<?= e($dataEdit['stok_total']) ?>"
                    >

                    <p class="note">
                        Stok tersedia disesuaikan dengan jumlah unit yang dipinjam.
                    </p>
                </div>

                <div>
                    <label for="kondisi">Kondisi Sarana *</label>

                    <select id="kondisi" name="kondisi" required>
                        <?php foreach ($daftarKondisi as $nilai => $labelKondisi): ?>
                            <option
                                value="<?= e($nilai) ?>"
                                <?= $dataEdit['kondisi'] === $nilai
                                    ? 'selected' : ''
                                ?>
                            >
                                <?= e($labelKondisi) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="status">Status Sarana *</label>

                    <select id="status" name="status" required>
                        <?php foreach ($daftarStatus as $nilai => $labelStatus): ?>
                            <option
                                value="<?= e($nilai) ?>"
                                <?= $dataEdit['status'] === $nilai
                                    ? 'selected' : ''
                                ?>
                            >
                                <?= e($labelStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="grid-column:1/-1">
                    <label for="deskripsi">Deskripsi</label>

                    <textarea
                        id="deskripsi"
                        name="deskripsi"
                        placeholder="Keterangan atau spesifikasi sarana"
                    ><?= e($dataEdit['deskripsi']) ?></textarea>
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="btn primary">
                    <?= $modeEdit ? 'Simpan Perubahan' : '+ Tambah Sarana' ?>
                </button>

                <?php if ($modeEdit): ?>
                    <a href="alat.php" class="btn secondary">Batal</a>
                <?php endif; ?>
            </div>

        </form>
    </section>

    <!-- DAFTAR SARANA -->
    <section class="card">

        <div class="toolbar">
            <div>
                <h2>Daftar Sarana</h2>
                <p>
                    Data inventaris sarana sekolah yang tersimpan di database.
                </p>
            </div>

            <form method="GET" action="alat.php" class="search-form">

                <input
                    type="search"
                    name="cari"
                    value="<?= e($cari) ?>"
                    placeholder="Cari kode, nama, kategori..."
                >

                <button type="submit" class="btn primary">Cari</button>

                <?php if ($cari !== ''): ?>
                    <a href="alat.php" class="btn secondary">Reset</a>
                <?php endif; ?>

            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Inventaris</th>
                        <th>Nama Sarana</th>
                        <th>Kategori</th>
                        <th>Lokasi</th>
                        <th>Total Stok</th>
                        <th>Tersedia</th>
                        <th>Kondisi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($hasil->num_rows > 0): ?>
                    <?php $no = 1; ?>

                    <?php while ($row = $hasil->fetch_assoc()): ?>
                        <tr>
                            <td><?= $no++ ?></td>

                            <td>
                                <strong><?= e($row['kode_inventaris']) ?></strong>
                            </td>

                            <td>
                                <strong><?= e($row['nama_alat']) ?></strong>

                                <?php if (!empty($row['deskripsi'])): ?>
                                    <div style="color:#8490a5;font-size:11px;margin-top:4px">
                                        <?= e($row['deskripsi']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td><?= e($row['nama_kategori'] ?? '-') ?></td>

                            <td>
                                <?= e($row['nama_ruangan'] ?? 'Belum ditentukan') ?>
                            </td>

                            <td><?= (int)$row['stok_total'] ?></td>
                            <td><?= (int)$row['stok_tersedia'] ?></td>

                            <td>
                                <span class="badge kondisi-<?= e($row['kondisi']) ?>">
                                    <?= e(
                                        $daftarKondisi[$row['kondisi']]
                                        ?? $row['kondisi']
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge status-<?= e($row['status']) ?>">
                                    <?= e(
                                        $daftarStatus[$row['status']]
                                        ?? $row['status']
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <div class="row-actions">

                                    <a
                                        class="btn secondary"
                                        href="alat.php?edit=<?= (int)$row['id_alat'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="alat.php"
                                        onsubmit="return confirm('Yakin ingin menghapus sarana ini?')"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_alat']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="aksi"
                                            value="hapus"
                                        >

                                        <input
                                            type="hidden"
                                            name="id_alat"
                                            value="<?= (int)$row['id_alat'] ?>"
                                        >

                                        <button type="submit" class="btn danger">
                                            Hapus
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="10" class="empty">
                            <?= $cari !== ''
                                ? 'Data sarana tidak ditemukan.'
                                : 'Belum ada data sarana.'
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

    <footer class="footer">
        SIPRAS — Sistem Peminjaman Sarana dan Prasarana<br>
        Panel Administrator
    </footer>

</main>

</body>
</html>
