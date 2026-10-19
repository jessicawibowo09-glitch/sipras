
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

function redirectPengguna($pesan)
{
    header('Location: pengguna.php?pesan=' . urlencode($pesan));
    exit;
}

function catatAktivitasPengguna($koneksi, $aktivitas, $detail)
{
    try {
        $idAdmin = (int)($_SESSION['id_user'] ?? 0);
        $namaAdmin = $_SESSION['nama'] ?? 'Administrator';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';

        $stmt = $koneksi->prepare(
            "INSERT INTO log_aktivitas
            (id_user, aktivitas, detail, alamat_ip, waktu, nama_admin, nama_tabel)
            VALUES (?, ?, ?, ?, NOW(), ?, 'users')"
        );

        $stmt->bind_param(
            'issss',
            $idAdmin,
            $aktivitas,
            $detail,
            $ip,
            $namaAdmin
        );

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Log aktivitas pengguna gagal: ' . $error->getMessage());
    }
}

function buatToken()
{
    if (empty($_SESSION['csrf_pengguna'])) {
        $_SESSION['csrf_pengguna'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_pengguna'];
}

function periksaToken()
{
    $token = $_POST['csrf_token'] ?? '';
    $tokenSesi = $_SESSION['csrf_pengguna'] ?? '';

    if (
        !is_string($token) ||
        !is_string($tokenSesi) ||
        $tokenSesi === '' ||
        !hash_equals($tokenSesi, $token)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }
}

$namaAdmin = $_SESSION['nama'] ?? 'Administrator';
$pesan = $_GET['pesan'] ?? '';

$modeEdit = false;
$dataEdit = [
    'id_user' => '',
    'nama' => '',
    'username' => '',
    'role' => 'peminjam',
    'kelas' => '',
    'no_hp' => '',
    'aktif' => 1
];

$csrfToken = buatToken();
$roleValid = ['admin', 'petugas', 'peminjam'];

/* =========================
   TAMBAH PENGGUNA
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') === 'tambah'
) {
    periksaToken();

    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'peminjam';
    $kelas = trim($_POST['kelas'] ?? '');
    $noHp = trim($_POST['no_hp'] ?? '');
    $aktif = isset($_POST['aktif']) ? 1 : 0;

    if ($nama === '' || $username === '' || $password === '') {
        redirectPengguna('Nama, username, dan password wajib diisi.');
    }

    if (!in_array($role, $roleValid, true)) {
        redirectPengguna('Role tidak valid.');
    }

    if (strlen($password) < 6) {
        redirectPengguna('Password minimal 6 karakter.');
    }

    $stmt = $koneksi->prepare(
        "SELECT id_user FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $sudahAda = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($sudahAda) {
        redirectPengguna('Username sudah digunakan.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $koneksi->prepare(
        "INSERT INTO users
        (nama, username, password, role, kelas, no_hp, aktif)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        'ssssssi',
        $nama,
        $username,
        $passwordHash,
        $role,
        $kelas,
        $noHp,
        $aktif
    );

    $stmt->execute();
    $stmt->close();

    catatAktivitasPengguna(
        $koneksi,
        'tambah',
        'Admin menambahkan pengguna: ' . $nama .
        ' | Username: ' . $username .
        ' | Role: ' . $role .
        ' | Status: ' . ($aktif ? 'Aktif' : 'Nonaktif')
    );

    redirectPengguna('Pengguna berhasil ditambahkan.');
}

/* =========================
   EDIT PENGGUNA
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') === 'edit'
) {
    periksaToken();

    $idUser = (int)($_POST['id_user'] ?? 0);
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'peminjam';
    $kelas = trim($_POST['kelas'] ?? '');
    $noHp = trim($_POST['no_hp'] ?? '');
    $aktif = isset($_POST['aktif']) ? 1 : 0;

    if ($idUser < 1 || $nama === '' || $username === '') {
        redirectPengguna('Data pengguna tidak lengkap.');
    }

    if (!in_array($role, $roleValid, true)) {
        redirectPengguna('Role tidak valid.');
    }

    if ($password !== '' && strlen($password) < 6) {
        redirectPengguna('Password baru minimal 6 karakter.');
    }

    $stmt = $koneksi->prepare(
        "SELECT id_user, nama, username, role
         FROM users WHERE id_user = ? LIMIT 1"
    );
    $stmt->bind_param('i', $idUser);
    $stmt->execute();
    $penggunaLama = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$penggunaLama) {
        redirectPengguna('Pengguna tidak ditemukan.');
    }

    if ($idUser === (int)$_SESSION['id_user'] && $aktif !== 1) {
        redirectPengguna('Akun admin yang sedang digunakan tidak boleh dinonaktifkan.');
    }

    if ($idUser === (int)$_SESSION['id_user'] && $role !== 'admin') {
        redirectPengguna('Role akun admin yang sedang digunakan tidak boleh diubah.');
    }

    $stmt = $koneksi->prepare(
        "SELECT id_user FROM users
         WHERE username = ? AND id_user <> ? LIMIT 1"
    );
    $stmt->bind_param('si', $username, $idUser);
    $stmt->execute();
    $usernameDipakai = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($usernameDipakai) {
        redirectPengguna('Username sudah digunakan oleh pengguna lain.');
    }

    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $koneksi->prepare(
            "UPDATE users
             SET nama = ?, username = ?, password = ?, role = ?,
                 kelas = ?, no_hp = ?, aktif = ?
             WHERE id_user = ?"
        );

        $stmt->bind_param(
            'ssssssii',
            $nama,
            $username,
            $passwordHash,
            $role,
            $kelas,
            $noHp,
            $aktif,
            $idUser
        );
    } else {
        $stmt = $koneksi->prepare(
            "UPDATE users
             SET nama = ?, username = ?, role = ?,
                 kelas = ?, no_hp = ?, aktif = ?
             WHERE id_user = ?"
        );

        $stmt->bind_param(
            'sssssii',
            $nama,
            $username,
            $role,
            $kelas,
            $noHp,
            $aktif,
            $idUser
        );
    }

    $stmt->execute();
    $stmt->close();

    catatAktivitasPengguna(
        $koneksi,
        'edit',
        'Admin mengubah pengguna: ' . $penggunaLama['nama'] .
        ' menjadi ' . $nama .
        ' | Username: ' . $username .
        ' | Role: ' . $role .
        ' | Kelas: ' . ($kelas !== '' ? $kelas : '-') .
        ' | Status: ' . ($aktif ? 'Aktif' : 'Nonaktif') .
        ($password !== '' ? ' | Password diperbarui' : '')
    );

    redirectPengguna('Data pengguna berhasil diperbarui.');
}

/* =========================
   HAPUS PENGGUNA
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') === 'hapus'
) {
    periksaToken();

    $idUser = (int)($_POST['id_user'] ?? 0);

    if ($idUser < 1) {
        redirectPengguna('ID pengguna tidak valid.');
    }

    if ($idUser === (int)$_SESSION['id_user']) {
        redirectPengguna('Akun admin yang sedang digunakan tidak boleh dihapus.');
    }

    $stmt = $koneksi->prepare(
        "SELECT id_user, nama, username, role
         FROM users WHERE id_user = ? LIMIT 1"
    );
    $stmt->bind_param('i', $idUser);
    $stmt->execute();
    $penggunaHapus = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$penggunaHapus) {
        redirectPengguna('Pengguna tidak ditemukan.');
    }

    try {
        $stmt = $koneksi->prepare(
            "DELETE FROM users WHERE id_user = ?"
        );
        $stmt->bind_param('i', $idUser);
        $stmt->execute();
        $stmt->close();

        catatAktivitasPengguna(
            $koneksi,
            'hapus',
            'Admin menghapus pengguna: ' . $penggunaHapus['nama'] .
            ' | Username: ' . $penggunaHapus['username'] .
            ' | ID pengguna: ' . $idUser .
            ' | Role: ' . $penggunaHapus['role']
        );

        redirectPengguna('Pengguna berhasil dihapus.');
    } catch (mysqli_sql_exception $error) {
        redirectPengguna(
            'Pengguna tidak dapat dihapus karena masih memiliki data terkait.'
        );
    }
}

/* =========================
   DATA EDIT
========================= */
if (isset($_GET['edit'])) {
    $idEdit = (int)$_GET['edit'];

    $stmt = $koneksi->prepare(
        "SELECT id_user, nama, username, role, kelas, no_hp, aktif
         FROM users WHERE id_user = ? LIMIT 1"
    );
    $stmt->bind_param('i', $idEdit);
    $stmt->execute();
    $hasilEdit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($hasilEdit) {
        $modeEdit = true;
        $dataEdit = $hasilEdit;
    } else {
        $pesan = 'Data pengguna tidak ditemukan.';
    }
}

/* =========================
   PENCARIAN DAN FILTER
========================= */
$cari = trim($_GET['cari'] ?? '');
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = "SELECT id_user, nama, username, role, kelas, no_hp, aktif
        FROM users WHERE 1=1";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= " AND (nama LIKE ? OR username LIKE ? OR kelas LIKE ?)";
    $kataCari = '%' . $cari . '%';
    $types .= 'sss';
    array_push($params, $kataCari, $kataCari, $kataCari);
}

if (in_array($filterRole, $roleValid, true)) {
    $sql .= " AND role = ?";
    $types .= 's';
    $params[] = $filterRole;
}

if ($filterStatus === 'aktif' || $filterStatus === 'nonaktif') {
    $sql .= " AND aktif = ?";
    $types .= 'i';
    $params[] = $filterStatus === 'aktif' ? 1 : 0;
}

$sql .= " ORDER BY id_user DESC";

$stmt = $koneksi->prepare($sql);

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$daftarPengguna = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   STATISTIK
========================= */
$statistik = [
    'total' => 0,
    'admin' => 0,
    'petugas' => 0,
    'peminjam' => 0,
    'aktif' => 0
];

$hasilStatistik = $koneksi->query(
    "SELECT COUNT(*) AS total,
            SUM(role = 'admin') AS admin,
            SUM(role = 'petugas') AS petugas,
            SUM(role = 'peminjam') AS peminjam,
            SUM(aktif = 1) AS aktif
     FROM users"
);

if ($hasilStatistik) {
    $statistik = array_merge(
        $statistik,
        $hasilStatistik->fetch_assoc()
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Pengguna | SIPRAS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7fb;
    color: #263449;
}

/* SIDEBAR SAMA DENGAN DASHBOARD */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    display: flex;
    flex-direction: column;
    width: 250px;
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

/* KONTEN */
.main {
    margin-left: 250px;
    padding: 30px;
    min-width: 0;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.topbar h1 {
    font-size: 27px;
    margin: 0 0 7px;
}

.muted {
    color: #7b8798;
    font-size: 13px;
}

.admin-label {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}

.stat,
.panel {
    background: white;
    border: 1px solid #e6ebf2;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(20,42,75,.035);
}

.stat {
    padding: 18px;
}

.stat-label {
    font-size: 12px;
    color: #778397;
    margin-bottom: 10px;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
    color: #213b65;
}

.panel {
    padding: 22px;
    margin-bottom: 22px;
}

.panel h2 {
    font-size: 18px;
    margin: 0 0 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 7px;
}

input,
select {
    width: 100%;
    border: 1px solid #d7dfeb;
    border-radius: 8px;
    padding: 11px 12px;
    font-size: 13px;
    background: white;
    color: #263449;
}

input:focus,
select:focus {
    outline: 2px solid #c9dcff;
    border-color: #507fc3;
}

.check-field {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 13px;
    margin-top: 22px;
}

.check-field input {
    width: auto;
}

.actions {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.btn {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 10px 15px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.btn-primary {
    background: #315c9b;
    color: white;
}

.btn-secondary {
    background: #edf1f7;
    color: #34445d;
}

.btn-danger {
    background: #fdecec;
    color: #bd3030;
}

.btn-small {
    padding: 7px 10px;
    font-size: 12px;
}

.notice {
    background: #e8f6ed;
    color: #236b40;
    border: 1px solid #c9e9d3;
    border-radius: 9px;
    padding: 13px 15px;
    margin-bottom: 18px;
    font-size: 13px;
}

.filters {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 10px;
    margin-bottom: 18px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    padding: 13px 12px;
    border-bottom: 1px solid #edf0f5;
    text-align: left;
    font-size: 13px;
}

th {
    color: #68768b;
    background: #f8fafd;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

tbody tr:hover {
    background: #fafcff;
}

.badge {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 11px;
    font-weight: 700;
    background: #edf1f7;
    color: #44536b;
}

.badge-admin {
    background: #e7edff;
    color: #3653a0;
}

.badge-petugas {
    background: #fff1d9;
    color: #926014;
}

.badge-peminjam {
    background: #e2f7eb;
    color: #267348;
}

.badge-off {
    background: #fde9e9;
    color: #b32e2e;
}

.inline-form {
    display: inline;
}

.empty {
    padding: 30px;
    text-align: center;
    color: #7b8798;
}

@media (max-width: 1100px) {
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
        max-height: none;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .form-grid,
    .filters,
    .stats {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

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
        <a href="alat.php">
            <span class="menu-icon">▣</span>
            <span>Data Sarana</span>
        </a>
        <a href="pengguna.php" class="active">
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

        <a class="logout"
           href="../logout.php"
           onclick="return confirm('Yakin ingin keluar?')">
            ↪ &nbsp; Keluar dari akun
        </a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h1>Data Pengguna</h1>
            <div class="muted">
                Kelola akun admin, petugas, dan peminjam SIPRAS.
            </div>
        </div>

        <div class="admin-label">
            Login sebagai <strong><?= e($namaAdmin) ?></strong>
        </div>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="notice"><?= e($pesan) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <div class="stat-label">Total Pengguna</div>
            <div class="stat-value"><?= (int)$statistik['total'] ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Admin</div>
            <div class="stat-value"><?= (int)$statistik['admin'] ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Petugas</div>
            <div class="stat-value"><?= (int)$statistik['petugas'] ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Peminjam</div>
            <div class="stat-value"><?= (int)$statistik['peminjam'] ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Akun Aktif</div>
            <div class="stat-value"><?= (int)$statistik['aktif'] ?></div>
        </div>
    </section>

    <section class="panel">
        <h2><?= $modeEdit ? 'Edit Pengguna' : 'Tambah Pengguna' ?></h2>

        <form method="POST" action="pengguna.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="aksi" value="<?= $modeEdit ? 'edit' : 'tambah' ?>">

            <?php if ($modeEdit): ?>
                <input type="hidden" name="id_user" value="<?= (int)$dataEdit['id_user'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label for="nama">Nama lengkap *</label>
                    <input id="nama" name="nama" type="text" maxlength="100"
                           required value="<?= e($dataEdit['nama']) ?>">
                </div>

                <div class="field">
                    <label for="username">Username *</label>
                    <input id="username" name="username" type="text" maxlength="100"
                           required value="<?= e($dataEdit['username']) ?>">
                </div>

                <div class="field">
                    <label for="password">
                        Password <?= $modeEdit ? '(kosongkan jika tetap)' : '*' ?>
                    </label>
                    <input id="password" name="password" type="password"
                           <?= $modeEdit ? '' : 'required' ?>
                           minlength="6" autocomplete="new-password">
                </div>

                <div class="field">
                    <label for="role">Role *</label>
                    <select id="role" name="role" required>
                        <?php foreach ($roleValid as $rolePilihan): ?>
                            <option value="<?= e($rolePilihan) ?>"
                                <?= $dataEdit['role'] === $rolePilihan ? 'selected' : '' ?>>
                                <?= ucfirst($rolePilihan) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="kelas">Kelas</label>
                    <input id="kelas" name="kelas" type="text" maxlength="50"
                           value="<?= e($dataEdit['kelas']) ?>"
                           placeholder="Contoh: XII RPL 1">
                </div>

                <div class="field">
                    <label for="no_hp">Nomor HP</label>
                    <input id="no_hp" name="no_hp" type="text" maxlength="20"
                           value="<?= e($dataEdit['no_hp']) ?>"
                           placeholder="Nomor HP pengguna">
                </div>
            </div>

            <label class="check-field">
                <input type="checkbox" name="aktif" value="1"
                    <?= (int)$dataEdit['aktif'] === 1 ? 'checked' : '' ?>>
                Akun aktif
            </label>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    <?= $modeEdit ? 'Simpan Perubahan' : 'Tambah Pengguna' ?>
                </button>

                <?php if ($modeEdit): ?>
                    <a href="pengguna.php" class="btn btn-secondary">Batal Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Daftar Pengguna</h2>

        <form method="GET" action="pengguna.php" class="filters">
            <input type="text" name="cari"
                   placeholder="Cari nama, username, atau kelas..."
                   value="<?= e($cari) ?>">

            <select name="role">
                <option value="">Semua Role</option>
                <?php foreach ($roleValid as $rolePilihan): ?>
                    <option value="<?= e($rolePilihan) ?>"
                        <?= $filterRole === $rolePilihan ? 'selected' : '' ?>>
                        <?= ucfirst($rolePilihan) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">Semua Status</option>
                <option value="aktif" <?= $filterStatus === 'aktif' ? 'selected' : '' ?>>
                    Aktif
                </option>
                <option value="nonaktif" <?= $filterStatus === 'nonaktif' ? 'selected' : '' ?>>
                    Nonaktif
                </option>
            </select>

            <button type="submit" class="btn btn-primary">Cari</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Kelas</th>
                        <th>No. HP</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($daftarPengguna)): ?>
                    <tr>
                        <td colspan="8" class="empty">Data pengguna tidak ditemukan.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($daftarPengguna as $index => $pengguna): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong><?= e($pengguna['nama']) ?></strong></td>
                            <td><?= e($pengguna['username']) ?></td>
                            <td>
                                <span class="badge badge-<?= e($pengguna['role']) ?>">
                                    <?= ucfirst(e($pengguna['role'])) ?>
                                </span>
                            </td>
                            <td><?= e($pengguna['kelas'] ?: '-') ?></td>
                            <td><?= e($pengguna['no_hp'] ?: '-') ?></td>
                            <td>
                                <?php if ((int)$pengguna['aktif'] === 1): ?>
                                    <span class="badge badge-peminjam">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-off">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-secondary btn-small"
                                   href="pengguna.php?edit=<?= (int)$pengguna['id_user'] ?>">
                                    Edit
                                </a>

                                <?php if ((int)$pengguna['id_user'] !== (int)$_SESSION['id_user']): ?>
                                    <form method="POST" action="pengguna.php"
                                          class="inline-form"
                                          onsubmit="return confirm('Yakin ingin menghapus pengguna ini?')">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_user"
                                               value="<?= (int)$pengguna['id_user'] ?>">

                                        <button type="submit" class="btn btn-danger btn-small">
                                            Hapus
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

</body>
</html>
