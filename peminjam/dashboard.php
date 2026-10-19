<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'peminjam'
) {
    header('Location: ../login.php');
    exit;
}

$id_user = (int) $_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? 'Peminjam';

$sql = "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(status = 'menunggu_persetujuan'), 0) AS menunggu,
            COALESCE(SUM(status = 'sedang_dipinjam'), 0) AS dipinjam,
            COALESCE(SUM(status = 'selesai'), 0) AS selesai
        FROM peminjaman
        WHERE id_user = ?";

$stmt = $koneksi->prepare($sql);
$stmt->bind_param('i', $id_user);
$stmt->execute();

$ringkasan = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sql = "SELECT
            kode_peminjaman,
            tanggal_pinjam,
            tanggal_rencana_kembali,
            status
        FROM peminjaman
        WHERE id_user = ?
        ORDER BY dibuat_pada DESC
        LIMIT 5";

$stmt = $koneksi->prepare($sql);
$stmt->bind_param('i', $id_user);
$stmt->execute();

$riwayat = $stmt->get_result();

$inisial = strtoupper(substr($nama, 0, 1));

/* Fungsi tampilan status */
function labelStatus($status)
{
    $label = [
        'menunggu_persetujuan' => 'Menunggu Persetujuan',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
        'sedang_dipinjam' => 'Sedang Dipinjam',
        'menunggu_pemeriksaan' => 'Menunggu Pemeriksaan',
        'selesai' => 'Selesai',
        'terlambat' => 'Terlambat',
        'dibatalkan' => 'Dibatalkan'
    ];

    return $label[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard Peminjam | SIPRAS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --navy: #12284b;
            --blue: #3268e9;
            --background: #f4f7fc;
            --text: #253653;
            --muted: #8290a7;
            --white: #ffffff;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            padding: 25px 18px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            background: linear-gradient(
                180deg,
                #102344 0%,
                #172f56 100%
            );
            color: white;
            box-shadow: 8px 0 30px rgba(15, 35, 70, 0.07);
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 7px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(135deg, #5b91ff, #3268e9);
            box-shadow: 0 5px 15px rgba(50,104,233,0.3);
            font-size: 24px;
        }

        .brand span {
            color: #76a0ff;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 23px 0 28px;
            padding: 14px 12px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 15px;
        }

        .profile-avatar {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: #dce8ff;
            color: #244eaa;
            font-size: 21px;
            font-weight: 800;
        }

        .profile-info {
            min-width: 0;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .profile-role {
            margin-top: 5px;
            color: #a9bbd8;
            font-size: 11px;
        }

        .menu-label {
            margin: 0 10px 12px;
            color: #8297b8;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 14px;
            border: 1px solid transparent;
            border-radius: 12px;
            color: #c5d2e7;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: 0.2s;
        }

        .menu-icon {
            width: 23px;
            flex-shrink: 0;
            text-align: center;
            font-size: 18px;
        }

        .menu a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
            transform: translateX(3px);
        }

        .menu a.active {
            background: linear-gradient(100deg, #3268e9, #477df4);
            color: white;
            font-weight: 700;
            box-shadow: 0 6px 18px rgba(50,104,233,0.25);
        }

        .menu a.logout {
            margin-top: 12px;
            color: #ffb6bd;
        }

        .menu a.logout:hover {
            background: rgba(255,90,105,0.12);
        }

        /* KONTEN UTAMA */
        main {
            margin-left: 260px;
            padding: 32px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 28px;
        }

        .welcome h1 {
            margin: 0 0 8px;
            font-size: 27px;
            color: #1b2e4d;
        }

        .welcome p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .top-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 14px;
        }

        .top-profile .profile-avatar {
            width: 38px;
            height: 38px;
            font-size: 17px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .card {
            position: relative;
            padding: 22px;
            overflow: hidden;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 17px;
            box-shadow: 0 5px 20px rgba(25, 50, 90, 0.025);
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 20px;
        }

        .card-title {
            color: #77859c;
            font-size: 12px;
            font-weight: 600;
        }

        .card-icon {
            width: 43px;
            height: 43px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            font-size: 21px;
        }

        .icon-blue {
            background: #e9f0ff;
            color: #3268e9;
        }

        .icon-orange {
            background: #fff2df;
            color: #e89a24;
        }

        .icon-purple {
            background: #f0eaff;
            color: #8056d9;
        }

        .icon-green {
            background: #e2f8ed;
            color: #1a9b60;
        }

        .card-number {
            font-size: 32px;
            font-weight: 800;
            color: #1d3151;
        }

        .card-caption {
            margin-top: 7px;
            color: #99a4b6;
            font-size: 11px;
        }

        /* TABEL RIWAYAT */
        .panel {
            padding: 24px;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 17px;
            box-shadow: 0 5px 20px rgba(25, 50, 90, 0.025);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 18px;
            color: #1d3151;
        }

        .panel-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .view-all {
            padding: 10px 14px;
            border-radius: 10px;
            background: #edf3ff;
            color: #3268e9;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .view-all:hover {
            background: #dfe9ff;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 570px;
            border-collapse: collapse;
        }

        th {
            padding: 14px 12px;
            background: #f7f9fd;
            color: #8290a7;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            white-space: nowrap;
        }

        td {
            padding: 16px 12px;
            border-bottom: 1px solid #edf1f7;
            color: #53627a;
            font-size: 12px;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #fbfcff;
        }

        .kode {
            color: #294f9e;
            font-weight: 700;
        }

        .badge {
            display: inline-block;
            padding: 7px 10px;
            border-radius: 20px;
            background: #eef2ff;
            color: #3158b8;
            font-size: 10px;
            font-weight: 700;
        }

        .status-ditolak,
        .status-dibatalkan {
            background: #ffebed;
            color: #cf3f50;
        }

        .status-selesai {
            background: #e2f8ed;
            color: #168653;
        }

        .status-terlambat {
            background: #fff0e1;
            color: #c36a13;
        }

        .empty {
            padding: 35px 15px;
            text-align: center;
            color: #8b98ad;
            line-height: 1.8;
        }

        .empty-icon {
            display: block;
            margin-bottom: 10px;
            font-size: 30px;
        }

        .footer {
            margin-top: 25px;
            color: #9aa5b7;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            main {
                padding: 24px;
            }
        }

        @media (max-width: 650px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                padding: 18px;
            }

            .brand {
                padding-bottom: 18px;
            }

            .profile-mini {
                margin: 16px 0;
            }

            .menu {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .menu a {
                gap: 7px;
                padding: 11px 8px;
                font-size: 11px;
            }

            .menu-icon {
                width: 20px;
                font-size: 16px;
            }

            main {
                margin-left: 0;
                padding: 18px;
            }

            .topbar {
                align-items: flex-start;
            }

            .top-profile {
                display: none;
            }

            .welcome h1 {
                font-size: 23px;
            }

            .cards {
                gap: 12px;
            }

            .card {
                padding: 16px;
            }

            .card-number {
                font-size: 27px;
            }

            .card-top {
                align-items: flex-start;
                flex-direction: column-reverse;
                margin-bottom: 14px;
            }

            .panel {
                padding: 16px;
            }

            .panel-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        <div class="brand-icon">▦</div>
        <div>SI<span>PRAS</span></div>
    </div>

    <div class="profile-mini">
        <div class="profile-avatar">
            <?= htmlspecialchars($inisial) ?>
        </div>

        <div class="profile-info">
            <div class="profile-name">
                <?= htmlspecialchars($nama) ?>
            </div>
            <div class="profile-role">Siswa / Peminjam</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a class="active" href="dashboard.php">
            <span class="menu-icon">⌂</span>
            Dashboard
        </a>

        <a href="alat.php">
            <span class="menu-icon">▤</span>
            Daftar Alat
        </a>

        <a href="peminjaman.php">
            <span class="menu-icon">＋</span>
            Ajukan Peminjaman
        </a>

        <a href="riwayat.php">
            <span class="menu-icon">◷</span>
            Riwayat Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>
            Pengembalian
        </a>

        <!-- MENU LOG AKTIVITAS -->
        <a href="log_aktivitas.php">
            <span class="menu-icon">◉</span>
            Log Aktivitas
        </a>

        <div class="menu-label" style="margin-top: 22px;">
            AKUN
        </div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>
            Profil Saya
        </a>

        <a href="../logout.php" class="logout">
            <span class="menu-icon">⇥</span>
            Keluar
        </a>
    </nav>

</aside>

<!-- KONTEN UTAMA -->
<main>

    <div class="topbar">
        <div class="welcome">
            <h1>Halo, <?= htmlspecialchars($nama) ?>! 👋</h1>
            <p>
                Selamat datang kembali di SIPRAS.
                Pantau peminjaman alat sekolahmu di sini.
            </p>
        </div>

        <div class="top-profile">
            <div class="profile-avatar">
                <?= htmlspecialchars($inisial) ?>
            </div>
            <div>
                <strong style="font-size:12px;">
                    <?= htmlspecialchars($nama) ?>
                </strong>
                <div style="margin-top:4px;color:#8290a7;font-size:11px;">
                    Akun Peminjam
                </div>
            </div>
        </div>
    </div>

    <!-- RINGKASAN -->
    <section class="cards">

        <div class="card">
            <div class="card-top">
                <div class="card-title">Total Pengajuan</div>
                <div class="card-icon icon-blue">▤</div>
            </div>
            <div class="card-number">
                <?= (int) $ringkasan['total'] ?>
            </div>
            <div class="card-caption">Seluruh transaksi kamu</div>
        </div>

        <div class="card">
            <div class="card-top">
                <div class="card-title">Menunggu Persetujuan</div>
                <div class="card-icon icon-orange">◷</div>
            </div>
            <div class="card-number">
                <?= (int) $ringkasan['menunggu'] ?>
            </div>
            <div class="card-caption">Menunggu pemeriksaan petugas</div>
        </div>

        <div class="card">
            <div class="card-top">
                <div class="card-title">Sedang Dipinjam</div>
                <div class="card-icon icon-purple">↗</div>
            </div>
            <div class="card-number">
                <?= (int) $ringkasan['dipinjam'] ?>
            </div>
            <div class="card-caption">Transaksi belum selesai</div>
        </div>

        <div class="card">
            <div class="card-top">
                <div class="card-title">Selesai</div>
                <div class="card-icon icon-green">✓</div>
            </div>
            <div class="card-number">
                <?= (int) $ringkasan['selesai'] ?>
            </div>
            <div class="card-caption">Transaksi telah selesai</div>
        </div>

    </section>

    <!-- RIWAYAT -->
    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Riwayat Peminjaman</h2>
                <p>Lima transaksi peminjaman terakhirmu.</p>
            </div>

            <a href="riwayat.php" class="view-all">
                Lihat Semua →
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>KODE PEMINJAMAN</th>
                        <th>TANGGAL PINJAM</th>
                        <th>RENCANA KEMBALI</th>
                        <th>STATUS</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($riwayat->num_rows > 0): ?>

                    <?php while ($row = $riwayat->fetch_assoc()): ?>
                        <?php
                        $kelasStatus = 'status-' . $row['status'];
                        ?>

                        <tr>
                            <td class="kode">
                                <?= htmlspecialchars($row['kode_peminjaman']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['tanggal_pinjam']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['tanggal_rencana_kembali']) ?>
                            </td>

                            <td>
                                <span class="badge <?= htmlspecialchars($kelasStatus) ?>">
                                    <?= htmlspecialchars(labelStatus($row['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" class="empty">
                            <span class="empty-icon">📋</span>
                            <strong>Belum ada peminjaman</strong><br>
                            Riwayat pengajuanmu akan muncul di sini.
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman Sarana dan Prasarana Sekolah
    </div>

</main>

</body>
</html>

<?php
$stmt->close();
?>
