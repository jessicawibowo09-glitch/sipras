
<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../login.php");
    exit;
}

function e($nilai) {
    return htmlspecialchars(
        (string)($nilai ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function jumlahData($koneksi, $tabel) {
    $tabelDiizinkan = [
        'users',
        'alat',
        'peminjaman',
        'sanksi'
    ];

    if (!in_array($tabel, $tabelDiizinkan, true)) {
        return 0;
    }

    try {
        $hasil = $koneksi->query(
            "SELECT COUNT(*) AS total FROM `$tabel`"
        );

        if ($hasil) {
            $data = $hasil->fetch_assoc();
            return (int)($data['total'] ?? 0);
        }
    } catch (Throwable $error) {
        return 0;
    }

    return 0;
}

$nama = $_SESSION['nama']
    ?? $_SESSION['username']
    ?? 'Administrator';

$totalUser = jumlahData($koneksi, 'users');
$totalAlat = jumlahData($koneksi, 'alat');
$totalPinjam = jumlahData($koneksi, 'peminjaman');
$totalSanksi = jumlahData($koneksi, 'sanksi');
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard Admin | SIPRAS</title>

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
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

/* SIDEBAR — DISESUAIKAN DENGAN RUANGAN.PHP */
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
    font-size: 19px;
    letter-spacing: 2px;
}

.brand p {
    margin-top: 4px;
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
    padding: 32px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
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

.subtitle {
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

/* SAMBUTAN */
.welcome {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 29px;
    overflow: hidden;
    border-radius: 18px;
    background: linear-gradient(110deg, #204c9c, #3777ef);
    color: white;
    box-shadow: 0 12px 25px rgba(44,99,205,.15);
}

.welcome::after {
    position: absolute;
    content: "";
    width: 210px;
    height: 210px;
    right: -50px;
    top: -100px;
    border: 35px solid rgba(255,255,255,.08);
    border-radius: 50%;
}

.welcome-content {
    position: relative;
    z-index: 1;
}

.welcome-label {
    margin-bottom: 10px;
    color: #d8e6ff;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1.5px;
}

.welcome h2 {
    font-size: 25px;
}

.welcome p {
    max-width: 490px;
    margin-top: 10px;
    color: #e0eaff;
    font-size: 13px;
    line-height: 1.8;
}

.welcome-mark {
    position: relative;
    z-index: 1;
    padding-right: 14px;
    color: rgba(255,255,255,.9);
    font-size: 53px;
}

/* BAGIAN */
.section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    margin: 31px 0 17px;
}

.section-heading h2 {
    color: var(--navy);
    font-size: 18px;
}

.section-heading p {
    margin-top: 6px;
    color: var(--muted);
    font-size: 12px;
}

/* STATISTIK */
.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.stat-card {
    padding: 20px;
    border: 1px solid #e9edf5;
    border-radius: 15px;
    background: white;
    box-shadow: 0 3px 12px rgba(30,55,90,.025);
    transition: .2s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(30,55,90,.06);
}

.stat-icon {
    display: grid;
    place-items: center;
    width: 43px;
    height: 43px;
    border-radius: 13px;
    font-size: 20px;
}

.blue {
    background: #e9f0ff;
    color: #3268e9;
}

.green {
    background: #e3f8ee;
    color: #149765;
}

.orange {
    background: #fff1df;
    color: #d98719;
}

.purple {
    background: #f0eaff;
    color: #8658df;
}

.stat-label {
    margin-top: 19px;
    color: var(--muted);
    font-size: 12px;
}

.stat-value {
    margin-top: 7px;
    color: var(--navy);
    font-size: 30px;
    font-weight: 800;
}

.stat-note {
    margin-top: 8px;
    color: #9aa5b6;
    font-size: 11px;
}

/* AKSES CEPAT */
.quick-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.quick-card {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    padding: 20px;
    border: 1px solid #e7ecf4;
    border-radius: 14px;
    background: white;
    color: inherit;
    text-decoration: none;
    transition: .2s;
}

.quick-card:hover {
    transform: translateY(-3px);
    border-color: #bfd1ff;
    box-shadow: 0 10px 24px rgba(30,55,90,.07);
}

.quick-icon {
    display: grid;
    place-items: center;
    width: 45px;
    height: 45px;
    flex-shrink: 0;
    border-radius: 13px;
    background: #edf3ff;
    color: #3268e9;
    font-size: 21px;
}

.quick-card h3 {
    font-size: 13px;
}

.quick-card p {
    margin-top: 6px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

/* FOOTER */
.footer {
    margin-top: 34px;
    padding-top: 19px;
    border-top: 1px solid #e4e9f2;
    color: #98a3b5;
    font-size: 11px;
    line-height: 1.8;
    text-align: center;
}

/* RESPONSIVE */
@media (max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .main {
        padding: 24px;
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

    .topbar {
        align-items: flex-start;
    }

    .date-badge {
        display: none;
    }

    .welcome {
        padding: 22px;
    }

    .welcome h2 {
        font-size: 21px;
    }

    .welcome-mark {
        display: none;
    }

    .stats {
        gap: 10px;
    }

    .stat-card {
        padding: 14px;
    }

    .stat-value {
        font-size: 25px;
    }

    .quick-grid {
        grid-template-columns: 1fr;
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
        <a class="active" href="dashboard.php">
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
        <a href="log_aktivitas.php">
            <span class="menu-icon">◷</span>
            <span>Log Aktivitas</span>
        </a>
    </nav>

    <div class="sidebar-bottom">

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($nama, 0, 1))) ?>
            </div>

            <div>
                <div class="profile-name"><?= e($nama) ?></div>
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

<!-- KONTEN DASHBOARD -->
<main class="main">

    <header class="topbar">
        <div>
            <div class="breadcrumb">SIPRAS / Menu Utama / Dashboard</div>
            <h1>Dashboard Admin</h1>
            <p class="subtitle">
                Ringkasan pengelolaan sarana dan prasarana sekolah.
            </p>
        </div>

        <div class="date-badge">
            ◷ &nbsp; <?= date('d-m-Y') ?>
        </div>
    </header>

    <section class="welcome">
        <div class="welcome-content">
            <div class="welcome-label">ADMINISTRATOR PANEL</div>

            <h2>Halo, <?= e($nama) ?>! 👋</h2>

            <p>
                Selamat datang di SIPRAS. Pantau data fasilitas,
                pengguna, peminjaman, dan sanksi keterlambatan
                dari satu tempat.
            </p>
        </div>

        <div class="welcome-mark">▦</div>
    </section>

    <!-- RINGKASAN DATA -->
    <section>
        <div class="section-heading">
            <div>
                <h2>Ringkasan Data</h2>
                <p>Jumlah data yang tercatat dalam sistem.</p>
            </div>
        </div>

        <div class="stats">

            <article class="stat-card">
                <div class="stat-icon blue">▣</div>
                <div class="stat-label">Total Sarana</div>
                <div class="stat-value"><?= $totalAlat ?></div>
                <div class="stat-note">Data alat dan fasilitas</div>
            </article>

            <article class="stat-card">
                <div class="stat-icon green">♙</div>
                <div class="stat-label">Total Pengguna</div>
                <div class="stat-value"><?= $totalUser ?></div>
                <div class="stat-note">Seluruh akun terdaftar</div>
            </article>

            <article class="stat-card">
                <div class="stat-icon orange">⇄</div>
                <div class="stat-label">Total Peminjaman</div>
                <div class="stat-value"><?= $totalPinjam ?></div>
                <div class="stat-note">Seluruh catatan transaksi</div>
            </article>

            <article class="stat-card">
                <div class="stat-icon purple">◷</div>
                <div class="stat-label">Data Sanksi</div>
                <div class="stat-value"><?= $totalSanksi ?></div>
                <div class="stat-note">Catatan sanksi keterlambatan</div>
            </article>

        </div>
    </section>

    <!-- AKSES CEPAT -->
    <section>
        <div class="section-heading">
            <div>
                <h2>Akses Cepat</h2>
                <p>Pilih menu untuk mulai mengelola sistem.</p>
            </div>
        </div>

        <div class="quick-grid">

            <a class="quick-card" href="kategori.php">
                <div class="quick-icon">▦</div>
                <div>
                    <h3>Kelola Kategori</h3>
                    <p>Atur kategori sarana sekolah.</p>
                </div>
            </a>

            <a class="quick-card" href="ruangan.php">
                <div class="quick-icon">⌘</div>
                <div>
                    <h3>Data Ruangan</h3>
                    <p>Kelola ruangan dan lokasi fasilitas.</p>
                </div>
            </a>

            <a class="quick-card" href="alat.php">
                <div class="quick-icon">▣</div>
                <div>
                    <h3>Kelola Sarana</h3>
                    <p>Data alat dan ketersediaan fasilitas.</p>
                </div>
            </a>

            <a class="quick-card" href="pengguna.php">
                <div class="quick-icon">♙</div>
                <div>
                    <h3>Kelola Pengguna</h3>
                    <p>Data akun yang terdaftar.</p>
                </div>
            </a>

            <a class="quick-card" href="peminjaman.php">
                <div class="quick-icon">⇄</div>
                <div>
                    <h3>Data Peminjaman</h3>
                    <p>Pantau transaksi peminjaman sarana.</p>
                </div>
            </a>

            <a class="quick-card" href="pengembalian.php">
                <div class="quick-icon">↩</div>
                <div>
                    <h3>Pengembalian</h3>
                    <p>Pantau proses pengembalian sarana.</p>
                </div>
            </a>

            <a class="quick-card" href="sanksi.php">
                <div class="quick-icon">◷</div>
                <div>
                    <h3>Sanksi Keterlambatan</h3>
                    <p>Lihat catatan sanksi peminjaman.</p>
                </div>
            </a>

            <a class="quick-card" href="laporan.php">
                <div class="quick-icon">▤</div>
                <div>
                    <h3>Laporan</h3>
                    <p>Akses laporan pengelolaan SIPRAS.</p>
                </div>
            </a>

            <a class="quick-card" href="log_aktivitas.php">
                <div class="quick-icon">◷</div>
                <div>
                    <h3>Log Aktivitas</h3>
                    <p>Lihat riwayat aktivitas administrator.</p>
                </div>
            </a>

        </div>
    </section>

    <footer class="footer">
        SIPRAS — Sistem Peminjaman Sarana dan Prasarana<br>
        Panel Administrator
    </footer>

</main>

</body>
</html>
