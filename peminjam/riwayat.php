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

function h($nilai) {
    return htmlspecialchars((string)$nilai, ENT_QUOTES, 'UTF-8');
}

$pesan_error = '';
$daftar = [];

try {
    $stmt = $koneksi->prepare("
        SELECT
            p.id_peminjaman,
            p.kode_peminjaman,
            p.tanggal_pinjam,
            p.jam_pinjam,
            p.tanggal_rencana_kembali,
            p.jam_rencana_kembali,
            p.keperluan,
            p.status,
            d.jumlah,
            a.nama_alat,
            a.kode_inventaris
        FROM peminjaman p
        LEFT JOIN detail_peminjaman d
            ON d.id_peminjaman = p.id_peminjaman
        LEFT JOIN alat a
            ON a.id_alat = d.id_alat
        WHERE p.id_user = ?
        ORDER BY p.id_peminjaman DESC
    ");

    $stmt->bind_param('i', $id_user);
    $stmt->execute();
    $hasil = $stmt->get_result();

    while ($row = $hasil->fetch_assoc()) {
        $daftar[] = $row;
    }

    $stmt->close();
} catch (Throwable $e) {
    error_log('Gagal memuat riwayat: ' . $e->getMessage());
    $pesan_error = 'Riwayat belum dapat dimuat. Periksa struktur tabel database.';
}

$total = count($daftar);
$menunggu = 0;
$berjalan = 0;
$selesai = 0;

foreach ($daftar as $item) {
    if (in_array($item['status'], [
        'menunggu_persetujuan', 'menunggu_pemeriksaan'
    ], true)) {
        $menunggu++;
    } elseif (in_array($item['status'], [
        'disetujui', 'sedang_dipinjam', 'terlambat'
    ], true)) {
        $berjalan++;
    } elseif (in_array($item['status'], [
        'selesai', 'ditolak', 'dibatalkan'
    ], true)) {
        $selesai++;
    }
}

$filter = $_GET['status'] ?? 'semua';
$cari = trim($_GET['cari'] ?? '');

$label_status = [
    'menunggu_persetujuan' => 'Menunggu Persetujuan',
    'disetujui' => 'Disetujui',
    'ditolak' => 'Ditolak',
    'sedang_dipinjam' => 'Sedang Dipinjam',
    'menunggu_pemeriksaan' => 'Menunggu Pemeriksaan',
    'selesai' => 'Selesai',
    'terlambat' => 'Terlambat',
    'dibatalkan' => 'Dibatalkan'
];

$daftar_tampil = array_values(array_filter(
    $daftar,
    function ($item) use ($filter, $cari) {
        $cocok_status =
            $filter === 'semua' || $item['status'] === $filter;

        $teks = implode(' ', [
            $item['kode_peminjaman'] ?? '',
            $item['nama_alat'] ?? '',
            $item['kode_inventaris'] ?? '',
            $item['keperluan'] ?? ''
        ]);

        $cocok_cari =
            $cari === '' || stripos($teks, $cari) !== false;

        return $cocok_status && $cocok_cari;
    }
));

function status_class($status) {
    switch ($status) {
        case 'disetujui':
        case 'selesai':
            return 'success';
        case 'ditolak':
        case 'terlambat':
        case 'dibatalkan':
            return 'danger';
        case 'sedang_dipinjam':
            return 'blue';
        case 'menunggu_pemeriksaan':
            return 'purple';
        default:
            return 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Peminjaman | SIPRAS</title>

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
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        /* SIDEBAR SAMA DENGAN PEMINJAMAN.PHP */
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
                180deg, #102344 0%, #172f56 100%
            );
            color: white;
            box-shadow: 8px 0 30px rgba(15,35,70,.07);
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
            background: linear-gradient(135deg,#5b91ff,#3268e9);
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
            background: linear-gradient(100deg,#3268e9,#477df4);
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

        /* KONTEN */
        main {
            margin-left: 260px;
            padding: 32px;
            min-width: 0;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .heading h1 {
            margin: 0 0 8px;
            color: #1b2e4d;
            font-size: 27px;
        }

        .heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .top-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 10px;
            background: var(--blue);
            color: white;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .top-button:hover {
            background: #2456ca;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3,minmax(0,1fr));
            gap: 17px;
            margin-bottom: 22px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
            padding: 20px;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(25,50,90,.025);
        }

        .stat-icon {
            width: 47px;
            height: 47px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            font-size: 22px;
        }

        .stat-icon.blue {
            color: #3268e9;
            background: #eaf0ff;
        }

        .stat-icon.orange {
            color: #bd7910;
            background: #fff3d9;
        }

        .stat-icon.green {
            color: #16845b;
            background: #e0f7ed;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
        }

        .stat-number {
            color: #1b2e4d;
            font-size: 25px;
            font-weight: 800;
        }

        .panel {
            padding: 24px;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 17px;
            box-shadow: 0 5px 20px rgba(25,50,90,.025);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .panel h2 {
            margin: 0 0 7px;
            font-size: 19px;
            color: #1d3151;
        }

        .description {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filters input,
        .filters select {
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #dce3ef;
            border-radius: 10px;
            background: white;
            color: #253653;
            font: inherit;
            font-size: 13px;
            outline: none;
        }

        .filters input {
            flex: 1 1 220px;
            min-width: 0;
        }

        .filters select {
            flex: 0 1 220px;
        }

        .filters input:focus,
        .filters select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(50,104,233,.1);
        }

        .filter-button,
        .reset-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 15px;
            border: 0;
            border-radius: 10px;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .filter-button {
            background: var(--blue);
            color: white;
        }

        .reset-button {
            background: #edf1f7;
            color: #53627a;
        }

        .table-wrap {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        thead th {
            padding: 13px 14px;
            background: #f5f7fb;
            color: #74829a;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .7px;
            white-space: nowrap;
        }

        tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #edf0f6;
            color: #44536b;
            font-size: 12px;
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: #fafcff;
        }

        .code {
            color: #2d5dcc;
            font-weight: 800;
            white-space: nowrap;
        }

        .item-name {
            color: #243653;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .subtext {
            color: #98a3b5;
            font-size: 11px;
        }

        .date {
            white-space: nowrap;
            line-height: 1.7;
        }

        .badge {
            display: inline-block;
            max-width: 180px;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1.35;
        }

        .badge.warning {
            color: #946200;
            background: #fff2cc;
        }

        .badge.success {
            color: #14784e;
            background: #ddf7e9;
        }

        .badge.danger {
            color: #b42335;
            background: #ffeaed;
        }

        .badge.blue {
            color: #2856bf;
            background: #e8efff;
        }

        .badge.purple {
            color: #7542a8;
            background: #f2e8ff;
        }

        .empty-state {
            text-align: center;
            padding: 48px 20px;
        }

        .empty-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            background: #edf3ff;
            color: #3268e9;
            font-size: 28px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            color: #243653;
            font-size: 16px;
        }

        .empty-state p {
            margin: 0 auto 18px;
            max-width: 390px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .alert {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.6;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .alert.error {
            background: #ffebed;
            color: #b42335;
        }

        .footer {
            margin-top: 25px;
            color: #9aa5b7;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 1100px) {
            main {
                padding: 25px;
            }
            .stats {
                gap: 12px;
            }
            .stat-card {
                padding: 16px;
            }
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: 1fr;
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
                grid-template-columns: repeat(2,minmax(0,1fr));
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

            .heading h1 {
                font-size: 23px;
            }

            .panel {
                padding: 17px;
            }

            .filters {
                align-items: stretch;
            }

            .filters input,
            .filters select {
                flex-basis: 100%;
                width: 100%;
            }

            .filter-button,
            .reset-button {
                flex: 1;
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
            <?= h(strtoupper(substr($nama, 0, 1))) ?>
        </div>

        <div class="profile-info">
            <div class="profile-name"><?= h($nama) ?></div>
            <div class="profile-role">Siswa / Peminjam</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php">
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

        <a href="riwayat.php" class="active">
            <span class="menu-icon">◷</span>
            Riwayat Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>
            Pengembalian
        </a>

        <!-- TAMBAHAN MENU LOG AKTIVITAS -->
        <a href="log_aktivitas.php">
            <span class="menu-icon">◉</span>
            Log Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">
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
<main>
    <div class="heading">
        <div>
            <h1>Riwayat Peminjaman</h1>
            <p>
                Halo, <?= h($nama) ?>!
                Pantau pengajuan dan status peminjaman alat sekolahmu.
            </p>
        </div>

        <a href="peminjaman.php" class="top-button">
            <span>＋</span> Ajukan Peminjaman
        </a>
    </div>

    <?php if (isset($_GET['berhasil'])): ?>
        <div class="alert success">
            ✓ Pengajuan berhasil dikirim dan sedang menunggu persetujuan petugas.
        </div>
    <?php endif; ?>

    <?php if ($pesan_error !== ''): ?>
        <div class="alert error">
            <?= h($pesan_error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon blue">▤</div>
            <div>
                <div class="stat-label">Total Pengajuan</div>
                <div class="stat-number"><?= $total ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">◷</div>
            <div>
                <div class="stat-label">Menunggu Proses</div>
                <div class="stat-number"><?= $menunggu ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">✓</div>
            <div>
                <div class="stat-label">Selesai / Ditutup</div>
                <div class="stat-number"><?= $selesai ?></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Daftar Pengajuan</h2>
                <p class="description">
                    Lihat detail alat, jadwal, dan status setiap pengajuan.
                </p>
            </div>
        </div>

        <form class="filters" method="GET" action="riwayat.php">
            <input
                type="search"
                name="cari"
                placeholder="Cari kode, nama alat, atau keperluan..."
                value="<?= h($cari) ?>"
            >

            <select name="status" aria-label="Filter status">
                <option value="semua"
                    <?= $filter === 'semua' ? 'selected' : '' ?>>
                    Semua Status
                </option>

                <?php foreach ($label_status as $key => $label): ?>
                    <option
                        value="<?= h($key) ?>"
                        <?= $filter === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="filter-button">
                Cari
            </button>

            <a href="riwayat.php" class="reset-button">
                Reset
            </a>
        </form>

        <?php if (count($daftar_tampil) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kode Pengajuan</th>
                            <th>Alat Dipinjam</th>
                            <th>Jumlah</th>
                            <th>Waktu Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($daftar_tampil as $item): ?>
                        <tr>
                            <td>
                                <div class="code">
                                    <?= h($item['kode_peminjaman']) ?>
                                </div>
                                <div class="subtext" style="margin-top:5px">
                                    Pengajuan #<?= (int)$item['id_peminjaman'] ?>
                                </div>
                            </td>

                            <td>
                                <div class="item-name">
                                    <?= h($item['nama_alat'] ?? 'Alat tidak ditemukan') ?>
                                </div>

                                <div class="subtext">
                                    <?= h($item['kode_inventaris'] ?? '-') ?>
                                </div>

                                <?php if (!empty($item['keperluan'])): ?>
                                    <div class="subtext" style="margin-top:5px">
                                        Keperluan: <?= h($item['keperluan']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= (int)($item['jumlah'] ?? 0) ?> unit
                            </td>

                            <td class="date">
                                <?= h(date('d/m/Y', strtotime($item['tanggal_pinjam']))) ?>
                                <br>
                                <span class="subtext">
                                    <?= h(substr((string)$item['jam_pinjam'], 0, 5)) ?> WIB
                                </span>
                            </td>

                            <td class="date">
                                <?= h(date('d/m/Y', strtotime($item['tanggal_rencana_kembali']))) ?>
                                <br>
                                <span class="subtext">
                                    <?= h(substr((string)$item['jam_rencana_kembali'], 0, 5)) ?> WIB
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= h(status_class($item['status'])) ?>">
                                    <?= h(
                                        $label_status[$item['status']]
                                        ?? ucwords(str_replace('_', ' ', $item['status']))
                                    ) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">◷</div>

                <h3>
                    <?= $total === 0
                        ? 'Belum Ada Riwayat Peminjaman'
                        : 'Data Tidak Ditemukan' ?>
                </h3>

                <p>
                    <?= $total === 0
                        ? 'Pengajuan yang kamu kirim akan muncul di halaman ini. Mulai dengan memilih alat yang tersedia.'
                        : 'Tidak ada pengajuan yang cocok dengan kata kunci atau filter status tersebut.' ?>
                </p>

                <?php if ($total === 0): ?>
                    <a href="alat.php" class="top-button">
                        Lihat Daftar Alat
                    </a>
                <?php else: ?>
                    <a href="riwayat.php" class="reset-button">
                        Hapus Filter
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman
        Sarana dan Prasarana Sekolah
    </div>
</main>

</body>
</html>