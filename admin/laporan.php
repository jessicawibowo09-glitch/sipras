
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

$nama = $_SESSION['username'] ?? $_SESSION['nama'] ?? 'Administrator';

function e($nilai) {
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($nilai) {
    return 'Rp ' . number_format((float)($nilai ?? 0), 0, ',', '.');
}

function ambilSatu($koneksi, $sql, $types = '', $params = []) {
    try {
        $stmt = $koneksi->prepare($sql);

        if ($types !== '' && $params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $hasil = $stmt->get_result();
        $data = $hasil ? $hasil->fetch_assoc() : [];
        $stmt->close();

        return $data ?: [];
    } catch (Throwable $err) {
        return [];
    }
}

function ambilBanyak($koneksi, $sql, $types = '', $params = []) {
    try {
        $stmt = $koneksi->prepare($sql);

        if ($types !== '' && $params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $hasil = $stmt->get_result();
        $data = $hasil ? $hasil->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $data;
    } catch (Throwable $err) {
        return [];
    }
}

$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai) ||
    $dari > $sampai
) {
    $dari = date('Y-m-01');
    $sampai = date('Y-m-d');
}

/* RINGKASAN PEMINJAMAN */
$ringkasanPinjam = ambilSatu(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'menunggu_persetujuan') AS menunggu,
        SUM(status = 'disetujui') AS disetujui,
        SUM(status = 'ditolak') AS ditolak
     FROM peminjaman
     WHERE tanggal_pinjam BETWEEN ? AND ?",
    'ss',
    [$dari, $sampai]
);

/* RINGKASAN PENGEMBALIAN */
$ringkasanKembali = ambilSatu(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(pg.denda), 0) AS total_denda
     FROM pengembalian pg
     INNER JOIN peminjaman p
        ON p.id_peminjaman = pg.id_peminjaman
     WHERE DATE(pg.waktu_kembali) BETWEEN ? AND ?",
    'ss',
    [$dari, $sampai]
);

/* RINGKASAN SANKSI */
$ringkasanSanksi = ambilSatu(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(
            CASE WHEN status <> 'dibatalkan'
                 THEN nominal ELSE 0 END
        ), 0) AS total_nominal
     FROM sanksi
     WHERE DATE(dibuat_pada) BETWEEN ? AND ?",
    'ss',
    [$dari, $sampai]
);

/* DAFTAR PEMINJAMAN */
$dataPinjam = ambilBanyak(
    $koneksi,
    "SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.status,
        u.nama,
        u.kelas
     FROM peminjaman p
     LEFT JOIN users u ON u.id_user = p.id_user
     WHERE p.tanggal_pinjam BETWEEN ? AND ?
     ORDER BY p.tanggal_pinjam DESC, p.jam_pinjam DESC",
    'ss',
    [$dari, $sampai]
);

/* DAFTAR PENGEMBALIAN */
$dataKembali = ambilBanyak(
    $koneksi,
    "SELECT
        pg.id_peminjaman,
        pg.waktu_kembali,
        pg.denda,
        pg.kondisi_akhir,
        p.kode_peminjaman,
        u.nama
     FROM pengembalian pg
     INNER JOIN peminjaman p
        ON p.id_peminjaman = pg.id_peminjaman
     LEFT JOIN users u ON u.id_user = p.id_user
     WHERE DATE(pg.waktu_kembali) BETWEEN ? AND ?
     ORDER BY pg.waktu_kembali DESC",
    'ss',
    [$dari, $sampai]
);

/* DAFTAR SANKSI */
$dataSanksi = ambilBanyak(
    $koneksi,
    "SELECT
        s.id_sanksi,
        s.id_peminjaman,
        s.jenis_sanksi,
        s.keterangan,
        s.nominal,
        s.status,
        s.dibuat_pada,
        p.kode_peminjaman,
        u.nama
     FROM sanksi s
     LEFT JOIN peminjaman p
        ON p.id_peminjaman = s.id_peminjaman
     LEFT JOIN users u
        ON u.id_user = p.id_user
     WHERE DATE(s.dibuat_pada) BETWEEN ? AND ?
     ORDER BY s.dibuat_pada DESC",
    'ss',
    [$dari, $sampai]
);

$totalPinjam = (int)($ringkasanPinjam['total'] ?? 0);
$totalMenunggu = (int)($ringkasanPinjam['menunggu'] ?? 0);
$totalDisetujui = (int)($ringkasanPinjam['disetujui'] ?? 0);
$totalDitolak = (int)($ringkasanPinjam['ditolak'] ?? 0);

$totalKembali = (int)($ringkasanKembali['total'] ?? 0);
$totalDendaKembali = (float)($ringkasanKembali['total_denda'] ?? 0);

$totalSanksi = (int)($ringkasanSanksi['total'] ?? 0);
$totalNominalSanksi = (float)($ringkasanSanksi['total_nominal'] ?? 0);

function labelStatus($status) {
    return ucwords(str_replace('_', ' ', (string)$status));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Admin | SIPRAS</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --navy: #12284b;
    --blue: #3268e9;
    --bg: #f5f7fc;
    --text: #263653;
    --muted: #8490a5;
}

body {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: "Segoe UI", Arial, sans-serif;
}

.layout {
    display: flex;
    min-height: 100vh;
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    display: flex;
    flex-direction: column;
    width: 250px;
    padding: 26px 18px;
    background: var(--navy);
    color: white;
    overflow-y: auto;
    z-index: 100;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 8px 28px;
    border-bottom: 1px solid rgba(255,255,255,.12);
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
    font-weight: 800;
}

.brand h2 {
    font-size: 19px;
    letter-spacing: 2px;
}

.brand p {
    margin-top: 4px;
    color: #b9c9e6;
    font-size: 10px;
}

.menu-title {
    margin: 25px 10px 12px;
    color: #91a6c9;
    font-size: 10px;
    font-weight: 700;
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
    border-top: 1px solid rgba(255,255,255,.12);
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
    font-weight: 800;
}

.profile-name {
    max-width: 135px;
    overflow-wrap: anywhere;
    font-size: 12px;
    font-weight: 700;
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
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 9px;
    color: #ffe0e0;
    text-align: center;
    text-decoration: none;
    font-size: 12px;
}

.logout:hover {
    background: rgba(255,255,255,.08);
}

/* KONTEN */
.main {
    width: calc(100% - 250px);
    min-height: 100vh;
    margin-left: 250px;
    padding: 32px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 28px;
}

.breadcrumb {
    margin-bottom: 8px;
    color: var(--muted);
    font-size: 12px;
}

.topbar h1 {
    color: var(--navy);
    font-size: clamp(23px, 3vw, 30px);
}

.topbar p {
    margin-top: 8px;
    color: var(--muted);
    font-size: 13px;
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

.filter-card {
    margin-bottom: 24px;
    padding: 20px;
    border: 1px solid #e9edf5;
    border-radius: 15px;
    background: white;
}

.filter-card h2 {
    margin-bottom: 15px;
    color: var(--navy);
    font-size: 16px;
}

.filter-form {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 14px;
}

.form-group {
    display: grid;
    gap: 7px;
}

.form-group label {
    color: #536581;
    font-size: 12px;
    font-weight: 600;
}

.form-group input {
    min-height: 41px;
    padding: 9px 11px;
    border: 1px solid #dfe6f1;
    border-radius: 9px;
    color: var(--text);
    font: inherit;
    font-size: 13px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 41px;
    padding: 10px 15px;
    border: 0;
    border-radius: 9px;
    cursor: pointer;
    text-decoration: none;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-secondary {
    border: 1px solid #dfe6f1;
    background: white;
    color: #536581;
}

.btn:hover {
    filter: brightness(.96);
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.stat-card {
    padding: 20px;
    border: 1px solid #e9edf5;
    border-radius: 15px;
    background: white;
    box-shadow: 0 3px 12px rgba(30,55,90,.025);
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-value {
    margin-top: 10px;
    color: var(--navy);
    font-size: 27px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.stat-note {
    margin-top: 7px;
    color: #9aa5b6;
    font-size: 11px;
}

.blue { border-top: 3px solid #3268e9; }
.green { border-top: 3px solid #149765; }
.orange { border-top: 3px solid #d98719; }
.purple { border-top: 3px solid #8658df; }

.section-card {
    margin-bottom: 24px;
    overflow: hidden;
    border: 1px solid #e7ecf4;
    border-radius: 15px;
    background: white;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid #edf0f6;
}

.section-header h2 {
    color: var(--navy);
    font-size: 16px;
}

.section-header p {
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th {
    padding: 13px 15px;
    background: #f7f9fd;
    color: #65738b;
    text-align: left;
    font-size: 11px;
    letter-spacing: .2px;
}

td {
    padding: 14px 15px;
    border-top: 1px solid #edf0f6;
    color: #43516a;
    font-size: 12px;
}

tbody tr:hover {
    background: #fafbff;
}

.empty {
    padding: 28px;
    color: var(--muted);
    text-align: center;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf1f7;
    color: #59677d;
    font-size: 10px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-disetujui,
.status-selesai {
    background: #e3f8ee;
    color: #138253;
}

.status-menunggu_persetujuan,
.status-dalam_proses {
    background: #fff1df;
    color: #b76b10;
}

.status-ditolak,
.status-dibatalkan {
    background: #ffe8e8;
    color: #bf3838;
}

.footer {
    margin-top: 30px;
    padding-top: 19px;
    border-top: 1px solid #e4e9f2;
    color: #98a3b5;
    font-size: 11px;
    line-height: 1.8;
    text-align: center;
}

@media (max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .main {
        padding: 24px;
    }
}

@media (max-width: 700px) {
    .sidebar {
        width: 68px;
        padding: 18px 8px;
    }

    .brand {
        justify-content: center;
        padding: 0 0 20px;
    }

    .brand > div:last-child,
    .menu-title,
    .menu a span:not(.menu-icon),
    .profile > div:last-child,
    .logout {
        display: none;
    }

    .menu a {
        justify-content: center;
        padding: 13px 5px;
    }

    .menu-icon {
        font-size: 19px;
    }

    .profile {
        justify-content: center;
        padding: 13px 0;
    }

    .main {
        width: calc(100% - 68px);
        margin-left: 68px;
        padding: 19px 13px;
    }

    .topbar {
        align-items: flex-start;
    }

    .date-badge {
        display: none;
    }

    .stats {
        gap: 10px;
    }

    .stat-card {
        padding: 14px;
    }

    .stat-value {
        font-size: 22px;
    }

    .section-header {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media print {
    .sidebar,
    .filter-card,
    .no-print {
        display: none !important;
    }

    .main {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    body {
        background: white;
    }

    .section-card,
    .stat-card {
        break-inside: avoid;
        box-shadow: none;
    }
}
</style>
</head>

<body>
<div class="layout">

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
        <a class="active" href="laporan.php">
            <span class="menu-icon">▤</span>
            <span>Laporan</span>
        </a>
    </nav>

    <!-- MENU LOG AKTIVITAS -->
    <div class="menu-title">SISTEM</div>
    <nav class="menu">
        <a href="log_aktivitas.php">
            <span class="menu-icon">◷</span>
            <span>Log Aktivitas</span>
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="avatar">A</div>
            <div>
                <div class="profile-name"><?= e($nama) ?></div>
                <div class="profile-role">Administrator</div>
            </div>
        </div>

        <a class="logout" href="../logout.php"
           onclick="return confirm('Yakin ingin keluar?')">
            ↪ &nbsp; Keluar dari akun
        </a>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div>
            <div class="breadcrumb">SIPRAS / Transaksi / Laporan</div>
            <h1>Laporan SIPRAS</h1>
            <p>Laporan peminjaman, pengembalian, dan sanksi sarana sekolah.</p>
        </div>
        <div class="date-badge">
            ◷ &nbsp; <?= date('d-m-Y') ?>
        </div>
    </header>

    <section class="filter-card">
        <h2>Filter Periode Laporan</h2>

        <form method="GET" class="filter-form">
            <div class="form-group">
                <label for="dari">Dari tanggal</label>
                <input type="date" id="dari" name="dari"
                       value="<?= e($dari) ?>" required>
            </div>

            <div class="form-group">
                <label for="sampai">Sampai tanggal</label>
                <input type="date" id="sampai" name="sampai"
                       value="<?= e($sampai) ?>" required>
            </div>

            <button type="submit" class="btn btn-primary">Tampilkan</button>
            <a href="laporan.php" class="btn btn-secondary">Reset</a>
            <button type="button" class="btn btn-secondary no-print"
                    onclick="window.print()">Cetak Laporan</button>
        </form>
    </section>

    <section class="stats">
        <article class="stat-card blue">
            <div class="stat-label">Total Peminjaman</div>
            <div class="stat-value"><?= $totalPinjam ?></div>
            <div class="stat-note">
                Disetujui: <?= $totalDisetujui ?> |
                Menunggu: <?= $totalMenunggu ?> |
                Ditolak: <?= $totalDitolak ?>
            </div>
        </article>

        <article class="stat-card green">
            <div class="stat-label">Total Pengembalian</div>
            <div class="stat-value"><?= $totalKembali ?></div>
            <div class="stat-note">Transaksi pengembalian tercatat</div>
        </article>

        <article class="stat-card orange">
            <div class="stat-label">Total Sanksi</div>
            <div class="stat-value"><?= $totalSanksi ?></div>
            <div class="stat-note">Catatan sanksi pada periode terpilih</div>
        </article>

        <article class="stat-card purple">
            <div class="stat-label">Total Nominal Denda dan Sanksi</div>
            <div class="stat-value" style="font-size:21px">
                <?= rupiah($totalDendaKembali + $totalNominalSanksi) ?>
            </div>
            <div class="stat-note">Akumulasi dari dua sumber data</div>
        </article>
    </section>

    <section class="section-card">
        <div class="section-header">
            <div>
                <h2>Laporan Peminjaman</h2>
                <p>Ringkasan transaksi peminjaman sarana sekolah.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Nama Peminjam</th>
                        <th>Kelas</th>
                        <th>Tanggal Pinjam</th>
                        <th>Jam Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Jam Kembali</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dataPinjam): ?>
                    <tr>
                        <td colspan="9" class="empty">
                            Belum ada data peminjaman pada periode ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dataPinjam as $i => $row): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($row['kode_peminjaman'] ?? '-') ?></td>
                        <td><?= e($row['nama'] ?? '-') ?></td>
                        <td><?= e($row['kelas'] ?? '-') ?></td>
                        <td>
                            <?= !empty($row['tanggal_pinjam'])
                                ? date('d-m-Y', strtotime($row['tanggal_pinjam']))
                                : '-' ?>
                        </td>
                        <td><?= e($row['jam_pinjam'] ?? '-') ?></td>
                        <td>
                            <?= !empty($row['tanggal_rencana_kembali'])
                                ? date('d-m-Y', strtotime($row['tanggal_rencana_kembali']))
                                : '-' ?>
                        </td>
                        <td><?= e($row['jam_rencana_kembali'] ?? '-') ?></td>
                        <td>
                            <span class="status status-<?= e($row['status'] ?? '') ?>">
                                <?= e(labelStatus($row['status'] ?? '-')) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="section-card">
        <div class="section-header">
            <div>
                <h2>Laporan Pengembalian</h2>
                <p>Data pengembalian dan denda yang tercatat.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Nama Peminjam</th>
                        <th>Waktu Kembali</th>
                        <th>Kondisi Akhir</th>
                        <th>Denda</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dataKembali): ?>
                    <tr>
                        <td colspan="6" class="empty">
                            Belum ada data pengembalian pada periode ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dataKembali as $i => $row): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($row['kode_peminjaman'] ?? '-') ?></td>
                        <td><?= e($row['nama'] ?? '-') ?></td>
                        <td>
                            <?= !empty($row['waktu_kembali'])
                                ? date('d-m-Y H:i', strtotime($row['waktu_kembali']))
                                : '-' ?>
                        </td>
                        <td><?= e($row['kondisi_akhir'] ?? '-') ?></td>
                        <td><?= rupiah($row['denda'] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="section-card">
        <div class="section-header">
            <div>
                <h2>Laporan Sanksi Keterlambatan</h2>
                <p>Daftar sanksi dan nominal yang tercatat.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Nama Peminjam</th>
                        <th>Jenis Sanksi</th>
                        <th>Keterangan</th>
                        <th>Nominal</th>
                        <th>Status</th>
                        <th>Tanggal Dicatat</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dataSanksi): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada data sanksi pada periode ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dataSanksi as $i => $row): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($row['kode_peminjaman'] ?? '-') ?></td>
                        <td><?= e($row['nama'] ?? '-') ?></td>
                        <td><?= e(labelStatus($row['jenis_sanksi'] ?? '-')) ?></td>
                        <td><?= e($row['keterangan'] ?? '-') ?></td>
                        <td><?= rupiah($row['nominal'] ?? 0) ?></td>
                        <td>
                            <span class="status status-<?= e($row['status'] ?? '') ?>">
                                <?= e(labelStatus($row['status'] ?? '-')) ?>
                            </span>
                        </td>
                        <td>
                            <?= !empty($row['dibuat_pada'])
                                ? date('d-m-Y H:i', strtotime($row['dibuat_pada']))
                                : '-' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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
</div>
</body>
</html>
