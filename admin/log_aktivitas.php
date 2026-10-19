
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

$namaAdmin = $_SESSION['nama'] ?? 'Administrator';

$cari = trim($_GET['cari'] ?? '');
$filterAktivitas = trim($_GET['aktivitas'] ?? '');
$filterTabel = trim($_GET['tabel'] ?? '');

$jenisAktivitas = [
    'tambah' => 'Tambah',
    'edit' => 'Edit',
    'hapus' => 'Hapus',
    'login' => 'Login',
    'logout' => 'Logout',
    'lainnya' => 'Lainnya'
];

if (!array_key_exists($filterAktivitas, $jenisAktivitas)) {
    $filterAktivitas = '';
}

/* =========================
   AMBIL DATA LOG AKTIVITAS
========================= */

$sql = "SELECT
            id_log,
            id_user,
            aktivitas,
            detail,
            alamat_ip,
            waktu,
            nama_admin,
            nama_tabel
        FROM log_aktivitas
        WHERE 1=1";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= " AND (
        nama_admin LIKE ?
        OR detail LIKE ?
        OR aktivitas LIKE ?
        OR nama_tabel LIKE ?
        OR alamat_ip LIKE ?
    )";

    $like = '%' . $cari . '%';
    $types .= 'sssss';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
    }
}

if ($filterAktivitas !== '') {
    $sql .= " AND aktivitas = ?";
    $types .= 's';
    $params[] = $filterAktivitas;
}

if ($filterTabel !== '') {
    $sql .= " AND nama_tabel = ?";
    $types .= 's';
    $params[] = $filterTabel;
}

$sql .= " ORDER BY waktu DESC, id_log DESC";

$stmt = $koneksi->prepare($sql);

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   STATISTIK AKTIVITAS
========================= */

$statistik = [
    'total' => 0,
    'tambah' => 0,
    'edit' => 0,
    'hapus' => 0,
    'login' => 0,
    'logout' => 0
];

$resultStat = $koneksi->query(
    "SELECT LOWER(aktivitas) AS aktivitas, COUNT(*) AS jumlah
     FROM log_aktivitas
     GROUP BY LOWER(aktivitas)"
);

while ($row = $resultStat->fetch_assoc()) {
    $jenis = strtolower($row['aktivitas']);
    $jumlah = (int)$row['jumlah'];

    $statistik['total'] += $jumlah;

    if (isset($statistik[$jenis])) {
        $statistik[$jenis] += $jumlah;
    }
}

/* =========================
   DAFTAR MODUL
========================= */

$daftarModul = [];

$resultModul = $koneksi->query(
    "SELECT DISTINCT nama_tabel
     FROM log_aktivitas
     WHERE nama_tabel IS NOT NULL
       AND nama_tabel <> ''
     ORDER BY nama_tabel"
);

while ($row = $resultModul->fetch_assoc()) {
    $daftarModul[] = $row['nama_tabel'];
}

/* =========================
   LABEL AKTIVITAS
========================= */

function kelasAktivitas($aktivitas)
{
    $aktivitas = strtolower((string)$aktivitas);

    return in_array(
        $aktivitas,
        ['tambah', 'edit', 'hapus', 'login', 'logout'],
        true
    ) ? $aktivitas : 'lainnya';
}

function namaAktivitas($aktivitas)
{
    $aktivitas = strtolower((string)$aktivitas);

    $label = [
        'tambah' => 'Tambah',
        'edit' => 'Edit',
        'hapus' => 'Hapus',
        'login' => 'Login',
        'logout' => 'Logout',
        'lainnya' => 'Lainnya'
    ];

    return $label[$aktivitas] ?? ucfirst($aktivitas);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Log Aktivitas | SIPRAS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    color: #243047;
    background: #f4f6fb;
}

/* =========================
   SIDEBAR DASHBOARD SIPRAS
========================= */

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

/* PROFIL ADMIN DI BAGIAN BAWAH */

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

/* =========================
   KONTEN UTAMA
========================= */

.main {
    margin-left: 250px;
    padding: 28px;
    min-width: 0;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 25px;
}

h1 {
    margin: 0 0 8px;
    font-size: 26px;
}

.subtitle {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
}

.admin-info {
    background: white;
    padding: 12px 16px;
    border: 1px solid #e8edf5;
    border-radius: 11px;
    font-size: 13px;
    white-space: nowrap;
}

/* =========================
   KARTU STATISTIK
========================= */

.stats {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border: 1px solid #e8edf5;
    border-radius: 13px;
    padding: 17px;
    box-shadow: 0 3px 12px #0f172a06;
    min-width: 0;
}

.stat-label {
    color: #64748b;
    font-size: 12px;
    margin-bottom: 12px;
}

.stat-number {
    font-size: 25px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

/* =========================
   KARTU RIWAYAT
========================= */

.card {
    background: white;
    padding: 23px;
    border-radius: 14px;
    border: 1px solid #e8edf5;
    box-shadow: 0 3px 14px #0f172a06;
}

.card-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 7px;
}

.card-subtitle {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 20px;
}

/* =========================
   FILTER DAN PENCARIAN
========================= */

.filters {
    display: grid;
    grid-template-columns:
        minmax(180px, 2fr)
        minmax(130px, 1fr)
        minmax(130px, 1fr)
        auto auto;
    gap: 10px;
    margin-bottom: 18px;
}

input,
select {
    width: 100%;
    min-width: 0;
    padding: 11px 12px;
    border: 1px solid #dbe2ed;
    border-radius: 8px;
    background: white;
    color: #243047;
    font-size: 13px;
    outline: none;
}

input:focus,
select:focus {
    border-color: #6366f1;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 11px 15px;
    border: 0;
    border-radius: 8px;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    font-size: 13px;
    font-weight: bold;
}

.btn-primary {
    background: #4f46e5;
    color: white;
}

.btn-primary:hover {
    background: #4338ca;
}

.btn-secondary {
    background: #e9eef5;
    color: #334155;
}

.btn-secondary:hover {
    background: #dbe3ef;
}

.result-count {
    color: #64748b;
    font-size: 12px;
    margin-bottom: 13px;
}

/* =========================
   TABEL AKTIVITAS
========================= */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 950px;
    border-collapse: collapse;
}

thead {
    background: #f8fafc;
}

th {
    padding: 14px 12px;
    text-align: left;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}

td {
    padding: 15px 12px;
    border-bottom: 1px solid #edf0f5;
    vertical-align: top;
    font-size: 13px;
}

tbody tr:hover {
    background: #fafbff;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.badge.tambah {
    background: #d1fae5;
    color: #047857;
}

.badge.edit {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge.hapus {
    background: #fee2e2;
    color: #b91c1c;
}

.badge.login {
    background: #ede9fe;
    color: #6d28d9;
}

.badge.logout {
    background: #e2e8f0;
    color: #475569;
}

.badge.lainnya {
    background: #fef3c7;
    color: #92400e;
}

.module {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    background: #eef2ff;
    color: #4338ca;
    font-size: 11px;
}

.detail {
    max-width: 350px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.muted {
    color: #64748b;
    margin-top: 5px;
    font-size: 11px;
}

.empty {
    text-align: center;
    padding: 40px 15px;
    color: #64748b;
}

/* =========================
   RESPONSIVE
========================= */

@media (max-width: 1400px) {
    .stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        min-height: auto;
    }

    .sidebar-bottom {
        margin-top: 25px;
    }

    .main {
        margin-left: 0;
        padding: 16px;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 16px;
    }

    .stat-number {
        font-size: 22px;
    }
}
</style>
</head>

<body>

<!-- =========================
     SIDEBAR ADMIN
========================= -->

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
        <a href="log_aktivitas.php" class="active">
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
                <div class="profile-name">
                    <?= e($namaAdmin) ?>
                </div>
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

<!-- =========================
     KONTEN UTAMA
========================= -->

<main class="main">

    <header class="topbar">
        <div>
            <h1>Log Aktivitas</h1>
            <div class="subtitle">
                Pantau aktivitas yang tercatat dalam sistem SIPRAS.
            </div>
        </div>

        <div class="admin-info">
            Admin: <strong><?= e($namaAdmin) ?></strong>
        </div>
    </header>

    <!-- STATISTIK -->

    <section class="stats">

        <div class="stat-card">
            <div class="stat-label">Total Aktivitas</div>
            <div class="stat-number">
                <?= number_format($statistik['total']) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Tambah</div>
            <div class="stat-number">
                <?= number_format($statistik['tambah']) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Edit</div>
            <div class="stat-number">
                <?= number_format($statistik['edit']) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Hapus</div>
            <div class="stat-number">
                <?= number_format($statistik['hapus']) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Login</div>
            <div class="stat-number">
                <?= number_format($statistik['login']) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Logout</div>
            <div class="stat-number">
                <?= number_format($statistik['logout']) ?>
            </div>
        </div>

    </section>

    <!-- RIWAYAT AKTIVITAS -->

    <section class="card">

        <div class="card-title">Riwayat Tindakan Admin</div>

        <div class="card-subtitle">
            Catatan tambah, edit, hapus, login, dan logout yang sudah tersimpan.
        </div>

        <!-- FILTER -->

        <form method="GET" class="filters">

            <input
                type="text"
                name="cari"
                placeholder="Cari nama admin atau detail..."
                value="<?= e($cari) ?>"
            >

            <select name="aktivitas">
                <option value="">Semua Aktivitas</option>

                <?php foreach ($jenisAktivitas as $key => $label): ?>
                    <option
                        value="<?= e($key) ?>"
                        <?= $filterAktivitas === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="tabel">
                <option value="">Semua Modul</option>

                <?php foreach ($daftarModul as $modul): ?>
                    <option
                        value="<?= e($modul) ?>"
                        <?= $filterTabel === $modul ? 'selected' : '' ?>
                    >
                        <?= e(ucwords(str_replace('_', ' ', $modul))) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-primary" type="submit">
                Cari
            </button>

            <a href="log_aktivitas.php" class="btn btn-secondary">
                Reset
            </a>

        </form>

        <div class="result-count">
            Ditemukan <?= number_format(count($logs)) ?> catatan aktivitas.
        </div>

        <!-- TABEL -->

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Admin</th>
                        <th>Aktivitas</th>
                        <th>Modul</th>
                        <th>Detail Tindakan</th>
                        <th>Alamat IP</th>
                        <th>Waktu</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!empty($logs)): ?>

                    <?php foreach ($logs as $index => $log): ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= e($log['nama_admin'] ?: 'Admin') ?>
                                </strong>

                                <div class="muted">
                                    ID: <?= e($log['id_user'] ?? '-') ?>
                                </div>
                            </td>

                            <td>
                                <span class="badge <?= e(kelasAktivitas($log['aktivitas'])) ?>">
                                    <?= e(namaAktivitas($log['aktivitas'])) ?>
                                </span>
                            </td>

                            <td>
                                <span class="module">
                                    <?= e(
                                        $log['nama_tabel']
                                            ? ucwords(str_replace('_', ' ', $log['nama_tabel']))
                                            : '-'
                                    ) ?>
                                </span>
                            </td>

                            <td class="detail">
                                <?= e($log['detail'] ?: '-') ?>
                            </td>

                            <td>
                                <?= e($log['alamat_ip'] ?: '-') ?>
                            </td>

                            <td>
                                <?php if (!empty($log['waktu'])): ?>
                                    <?= e(date(
                                        'd-m-Y H:i:s',
                                        strtotime($log['waktu'])
                                    )) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            <strong>
                                Belum ada aktivitas yang ditemukan.
                            </strong>

                            <div style="margin-top:8px">
                                Coba ubah filter atau lakukan aktivitas baru.
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

</main>

</body>
</html>
