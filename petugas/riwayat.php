<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (!isset($_SESSION['id_user']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: ../login.php');
    exit;
}

function h($nilai)
{
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

$nama = $_SESSION['nama'] ?? 'Petugas';
$jenis = $_GET['jenis'] ?? 'semua';

$pilihanJenis = ['semua', 'peminjaman', 'pengembalian', 'sanksi'];

if (!in_array($jenis, $pilihanJenis, true)) {
    $jenis = 'semua';
}

$dataRiwayat = [];
$pesanError = '';

$sqlPeminjaman = "
    SELECT
        p.id_peminjaman AS id,
        'Peminjaman' AS jenis_aktivitas,
        CONCAT(
            COALESCE(u.nama, 'Pengguna'),
            ' melakukan peminjaman'
        ) AS aktivitas,
        CONCAT(
            COALESCE(p.tanggal_pinjam, ''),
            ' ',
            COALESCE(p.jam_pinjam, '')
        ) AS waktu,
        COALESCE(p.status, '-') AS status,
        '-' AS keterangan
    FROM peminjaman p
    LEFT JOIN users u ON u.id_user = p.id_user
";

$sqlPengembalian = "
    SELECT
        pg.id_pengembalian AS id,
        'Pengembalian' AS jenis_aktivitas,
        CONCAT(
            COALESCE(u.nama, 'Pengguna'),
            ' melakukan pengembalian'
        ) AS aktivitas,
        COALESCE(
            CAST(pg.waktu_kembali AS CHAR),
            '-'
        ) AS waktu,
        COALESCE(p.status, '-') AS status,
        CONCAT(
            'Kondisi: ',
            COALESCE(pg.kondisi_akhir, '-'),
            ' | Denda: Rp ',
            COALESCE(pg.denda, 0)
        ) AS keterangan
    FROM pengembalian pg
    LEFT JOIN peminjaman p
        ON p.id_peminjaman = pg.id_peminjaman
    LEFT JOIN users u
        ON u.id_user = p.id_user
";

$sqlSanksi = "
    SELECT
        s.id_sanksi AS id,
        'Sanksi' AS jenis_aktivitas,
        CONCAT(
            'Sanksi untuk ',
            COALESCE(u.nama, 'Pengguna')
        ) AS aktivitas,
        COALESCE(
            CAST(s.dibuat_pada AS CHAR),
            '-'
        ) AS waktu,
        COALESCE(s.status, '-') AS status,
        CONCAT(
            COALESCE(s.jenis_sanksi, '-'),
            ' - ',
            COALESCE(s.keterangan, '-'),
            ' | Nominal: Rp ',
            COALESCE(s.nominal, 0)
        ) AS keterangan
    FROM sanksi s
    LEFT JOIN peminjaman p
        ON p.id_peminjaman = s.id_peminjaman
    LEFT JOIN users u
        ON u.id_user = p.id_user
";

if ($jenis === 'peminjaman') {
    $sql = $sqlPeminjaman . " ORDER BY waktu DESC LIMIT 500";
} elseif ($jenis === 'pengembalian') {
    $sql = $sqlPengembalian . " ORDER BY waktu DESC LIMIT 500";
} elseif ($jenis === 'sanksi') {
    $sql = $sqlSanksi . " ORDER BY waktu DESC LIMIT 500";
} else {
    $sql = "
        SELECT * FROM (
            $sqlPeminjaman
            UNION ALL
            $sqlPengembalian
            UNION ALL
            $sqlSanksi
        ) AS riwayat
        ORDER BY waktu DESC
        LIMIT 500
    ";
}

try {
    $hasil = mysqli_query($koneksi, $sql);

    if ($hasil) {
        while ($baris = mysqli_fetch_assoc($hasil)) {
            $dataRiwayat[] = $baris;
        }
    } else {
        $pesanError = mysqli_error($koneksi);
    }
} catch (Throwable $e) {
    $pesanError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Aktivitas | SIPRAS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            background: #f4f7fc;
            color: #1e293b;
        }

        /* SIDEBAR - SAMA DENGAN DASHBOARD PETUGAS */
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
            background: linear-gradient(180deg, #102344 0%, #172f56 100%);
            color: white;
            box-shadow: 8px 0 30px rgba(15, 35, 70, .07);
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 7px 25px;
            border-bottom: 1px solid rgba(255,255,255,.12);
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand span {
            color: #76a0ff;
        }

        .brand-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(135deg, #5b91ff, #3268e9);
            font-size: 24px;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 23px 0 28px;
            padding: 14px 12px;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.08);
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
            text-decoration: none;
            transition: .2s;
        }

        .menu-icon {
            width: 23px;
            flex-shrink: 0;
            text-align: center;
            font-size: 18px;
        }

        .menu a:hover {
            background: rgba(255,255,255,.09);
            color: white;
            transform: translateX(3px);
        }

        .menu a.active {
            background: linear-gradient(100deg, #3268e9, #477df4);
            color: white;
            font-weight: 700;
            box-shadow: 0 6px 18px rgba(50,104,233,.25);
        }

        .menu a.logout {
            margin-top: 12px;
            color: #ffb6bd;
        }

        .menu a.logout:hover {
            background: rgba(255,90,105,.12);
        }

        /* KONTEN RIWAYAT */
        main {
            margin-left: 260px;
            padding: 32px;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 26px;
        }

        .page-title {
            font-size: 27px;
            font-weight: 800;
            color: #172f56;
        }

        .page-subtitle {
            margin-top: 8px;
            color: #7b879b;
            font-size: 13px;
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 17px;
            border: none;
            border-radius: 11px;
            background: #3268e9;
            color: white;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-print:hover {
            background: #2454c7;
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 22px;
            margin-bottom: 24px;
            border: 1px solid #e7edf7;
            border-radius: 17px;
            background: white;
            box-shadow: 0 5px 20px rgba(20, 45, 90, .04);
        }

        .summary-icon {
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 15px;
            background: #e7efff;
            color: #3268e9;
            font-size: 25px;
        }

        .summary-label {
            color: #7b879b;
            font-size: 12px;
        }

        .summary-number {
            margin-top: 5px;
            color: #172f56;
            font-size: 25px;
            font-weight: 800;
        }

        .panel {
            padding: 24px;
            border: 1px solid #e7edf7;
            border-radius: 17px;
            background: white;
            box-shadow: 0 5px 20px rgba(20, 45, 90, .04);
        }

        .panel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .panel-heading h2 {
            color: #172f56;
            font-size: 18px;
        }

        .filter {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter a {
            padding: 9px 13px;
            border: 1px solid #e1e8f3;
            border-radius: 9px;
            color: #64748b;
            background: white;
            font-size: 12px;
            text-decoration: none;
        }

        .filter a:hover,
        .filter a.active {
            border-color: #3268e9;
            background: #3268e9;
            color: white;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        thead {
            background: #f5f8fe;
        }

        th {
            padding: 14px 13px;
            color: #65758d;
            font-size: 11px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        td {
            padding: 16px 13px;
            border-bottom: 1px solid #edf1f7;
            color: #46546a;
            font-size: 12px;
            vertical-align: top;
        }

        tbody tr:hover {
            background: #fafcff;
        }

        .badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 7px;
            background: #e8efff;
            color: #3268e9;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .empty {
            padding: 45px 20px;
            text-align: center;
            color: #8b97a9;
        }

        .empty-icon {
            margin-bottom: 12px;
            font-size: 35px;
        }

        .error {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 10px;
            background: #fff0f0;
            color: #a12626;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        @media print {
            .sidebar,
            .btn-print,
            .filter {
                display: none !important;
            }

            main {
                margin-left: 0;
                padding: 0;
            }

            body {
                background: white;
            }

            .panel,
            .summary-card {
                box-shadow: none;
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

            main {
                margin-left: 0;
                padding: 18px;
            }

            .page-title {
                font-size: 23px;
            }

            .panel {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<!-- SIDEBAR PETUGAS -->
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">▦</div>
        <div>SI<span>PRAS</span></div>
    </div>

    <div class="profile-mini">
        <div class="profile-avatar">
            <?= h(strtoupper(substr($nama, 0, 1))) ?>
        </div>

        <div class="profile-info">
            <div class="profile-name"><?= h($nama) ?></div>
            <div class="profile-role">Petugas Sarpras</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php">
            <span class="menu-icon">⌂</span>
            Dashboard
        </a>

        <a href="peminjaman.php">
            <span class="menu-icon">▤</span>
            Data Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>
            Pengembalian
        </a>

        <a href="data_alat.php">
            <span class="menu-icon">▣</span>
            Data Alat
        </a>

        <a href="riwayat.php" class="active">
            <span class="menu-icon">📋</span>
            Riwayat Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">AKUN</div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>
            Profil Saya
        </a>

        <a href="../logout.php" class="logout"
           onclick="return confirm('Yakin ingin keluar?')">
            <span class="menu-icon">⇥</span>
            Keluar
        </a>
    </nav>
</aside>

<!-- KONTEN UTAMA -->
<main>
    <div class="page-header">
        <div>
            <h1 class="page-title">Riwayat Aktivitas</h1>
            <p class="page-subtitle">
                Catatan peminjaman, pengembalian, dan sanksi di SIPRAS.
            </p>
        </div>

        <button class="btn-print" onclick="window.print()">
            🖨 Cetak Riwayat
        </button>
    </div>

    <?php if ($pesanError !== ''): ?>
        <div class="error">
            <strong>Terjadi kesalahan saat mengambil data.</strong>
            <p><?= h($pesanError) ?></p>
            <p>Periksa kembali nama tabel dan kolom di database.</p>
        </div>
    <?php endif; ?>

    <div class="summary-card">
        <div class="summary-icon">📋</div>
        <div>
            <div class="summary-label">Jumlah aktivitas ditampilkan</div>
            <div class="summary-number">
                <?= number_format(count($dataRiwayat), 0, ',', '.') ?>
            </div>
        </div>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <h2>Daftar Riwayat</h2>

            <div class="filter">
                <a href="riwayat.php"
                   class="<?= $jenis === 'semua' ? 'active' : '' ?>">
                    Semua
                </a>

                <a href="riwayat.php?jenis=peminjaman"
                   class="<?= $jenis === 'peminjaman' ? 'active' : '' ?>">
                    Peminjaman
                </a>

                <a href="riwayat.php?jenis=pengembalian"
                   class="<?= $jenis === 'pengembalian' ? 'active' : '' ?>">
                    Pengembalian
                </a>

                <a href="riwayat.php?jenis=sanksi"
                   class="<?= $jenis === 'sanksi' ? 'active' : '' ?>">
                    Sanksi
                </a>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Jenis Aktivitas</th>
                        <th>Aktivitas</th>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($dataRiwayat) > 0): ?>
                        <?php foreach ($dataRiwayat as $i => $item): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>

                                <td>
                                    <span class="badge">
                                        <?= h($item['jenis_aktivitas']) ?>
                                    </span>
                                </td>

                                <td><?= h($item['aktivitas']) ?></td>

                                <td><?= h($item['waktu']) ?></td>

                                <td><?= h($item['status']) ?></td>

                                <td><?= h($item['keterangan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <div class="empty-icon">📭</div>
                                    <strong>Belum ada riwayat aktivitas</strong>
                                    <p style="margin-top:8px">
                                        Data akan muncul setelah aktivitas
                                        tercatat di sistem.
                                    </p>
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