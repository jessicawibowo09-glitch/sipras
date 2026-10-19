<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'petugas'
) {
    header('Location: ../login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_user = (int) $_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? 'Petugas';

function h($nilai) {
    return htmlspecialchars(
        (string) ($nilai ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$total_pengajuan = 0;
$menunggu = 0;
$dipinjam = 0;
$pengembalian = 0;
$terlambat = 0;
$pengajuan_terbaru = [];
$pesan_error = '';

$label_status = [
    'menunggu_persetujuan' => 'Menunggu Persetujuan',
    'menunggu_pemeriksaan' => 'Menunggu Pemeriksaan',
    'disetujui' => 'Disetujui',
    'ditolak' => 'Ditolak',
    'sedang_dipinjam' => 'Sedang Dipinjam',
    'selesai' => 'Selesai',
    'terlambat' => 'Terlambat',
    'dibatalkan' => 'Dibatalkan'
];

function status_class($status) {
    switch ($status) {
        case 'disetujui':
        case 'selesai':
            return 'success';

        case 'ditolak':
        case 'dibatalkan':
            return 'danger';

        case 'sedang_dipinjam':
        case 'terlambat':
            return 'blue';

        case 'menunggu_pemeriksaan':
            return 'purple';

        default:
            return 'warning';
    }
}
if (empty($_SESSION['dashboard_petugas_dicatat'])) {
    try {
        $aktivitas = 'lainnya';
        $detail = 'Membuka dashboard petugas';
        $alamat_ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $nama_tabel = 'dashboard';

        $stmt_log = $koneksi->prepare("
            INSERT INTO log_aktivitas
                (id_user, aktivitas, detail, alamat_ip,
                 waktu, nama_admin, nama_tabel)
            VALUES (?, ?, ?, ?, NOW(), ?, ?)
        ");

        $stmt_log->bind_param(
            'isssss',
            $id_user,
            $aktivitas,
            $detail,
            $alamat_ip,
            $nama,
            $nama_tabel
        );

        $stmt_log->execute();
        $stmt_log->close();

        $_SESSION['dashboard_petugas_dicatat'] = true;
    } catch (Throwable $e) {
        error_log('Log dashboard petugas: ' . $e->getMessage());
    }
}

try {
    $sql_statistik = "
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(
                status = 'menunggu_persetujuan'
            ), 0) AS menunggu,
            COALESCE(SUM(
                status IN (
                    'disetujui',
                    'sedang_dipinjam',
                    'terlambat'
                )
            ), 0) AS dipinjam,
            COALESCE(SUM(
                status = 'menunggu_pemeriksaan'
            ), 0) AS pengembalian
        FROM peminjaman
    ";

    $hasil_statistik = $koneksi->query($sql_statistik);
    $stat = $hasil_statistik->fetch_assoc();

    $total_pengajuan = (int) $stat['total'];
    $menunggu = (int) $stat['menunggu'];
    $dipinjam = (int) $stat['dipinjam'];
    $pengembalian = (int) $stat['pengembalian'];
    $sql_terlambat = "
        SELECT COUNT(*) AS total
        FROM peminjaman
        WHERE status IN (
            'disetujui',
            'sedang_dipinjam',
            'terlambat'
        )
        AND TIMESTAMP(
            tanggal_rencana_kembali,
            jam_rencana_kembali
        ) < NOW()
    ";

    $hasil_terlambat = $koneksi->query($sql_terlambat);
    $terlambat = (int) $hasil_terlambat
        ->fetch_assoc()['total'];

    // Pengajuan terbaru
    $sql_terbaru = "
        SELECT
            p.id_peminjaman,
            p.kode_peminjaman,
            p.tanggal_pinjam,
            p.jam_pinjam,
            p.status,
            u.nama AS nama_peminjam,
            GROUP_CONCAT(
                DISTINCT a.nama_alat
                ORDER BY a.nama_alat
                SEPARATOR ', '
            ) AS daftar_alat,
            COALESCE(SUM(d.jumlah), 0) AS jumlah_alat
        FROM peminjaman p
        LEFT JOIN users u
            ON u.id_user = p.id_user
        LEFT JOIN detail_peminjaman d
            ON d.id_peminjaman = p.id_peminjaman
        LEFT JOIN alat a
            ON a.id_alat = d.id_alat
        GROUP BY
            p.id_peminjaman,
            p.kode_peminjaman,
            p.tanggal_pinjam,
            p.jam_pinjam,
            p.status,
            u.nama
        ORDER BY p.id_peminjaman DESC
        LIMIT 8
    ";

    $hasil_terbaru = $koneksi->query($sql_terbaru);

    while ($row = $hasil_terbaru->fetch_assoc()) {
        $pengajuan_terbaru[] = $row;
    }

} catch (Throwable $e) {
    error_log('Dashboard petugas: ' . $e->getMessage());
    $pesan_error = 'Data dashboard belum dapat dimuat. Periksa tabel dan kolom database.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Petugas | SIPRAS</title>

<style>
*{box-sizing:border-box}
:root{
    --navy:#12284b;
    --blue:#3268e9;
    --bg:#f4f7fc;
    --text:#253653;
    --muted:#8290a7
}
body{
    margin:0;
    font-family:"Segoe UI",Arial,sans-serif;
    background:var(--bg);
    color:var(--text)
}
.sidebar{
    position:fixed;
    inset:0 auto 0 0;
    width:260px;
    height:100vh;
    padding:25px 18px;
    overflow-y:auto;
    background:linear-gradient(180deg,#102344,#172f56);
    color:white;
    z-index:10
}
.brand{
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 7px 25px;
    border-bottom:1px solid #ffffff20;
    font-size:25px;
    font-weight:800;
    letter-spacing:1px
}
.brand span{color:#76a0ff}
.brand-icon{
    width:45px;
    height:45px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:14px;
    background:linear-gradient(135deg,#5b91ff,#3268e9);
    font-size:24px
}
.profile-mini{
    display:flex;
    align-items:center;
    gap:12px;
    margin:23px 0 28px;
    padding:14px 12px;
    background:#ffffff12;
    border:1px solid #ffffff14;
    border-radius:15px
}
.profile-avatar{
    width:44px;
    height:44px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:14px;
    background:#dce8ff;
    color:#244eaa;
    font-size:21px;
    font-weight:800
}
.profile-info{min-width:0}
.profile-name{
    font-size:13px;
    font-weight:700;
    overflow-wrap:anywhere
}
.profile-role{
    margin-top:5px;
    color:#a9bbd8;
    font-size:11px
}
.menu-label{
    margin:0 10px 12px;
    color:#8297b8;
    font-size:10px;
    font-weight:800;
    letter-spacing:1.5px
}
.menu{
    display:flex;
    flex-direction:column;
    gap:7px
}
.menu a{
    display:flex;
    align-items:center;
    gap:13px;
    padding:13px 14px;
    border-radius:12px;
    color:#c5d2e7;
    font-size:13px;
    text-decoration:none;
    transition:.2s
}
.menu a:hover{
    background:#ffffff17;
    color:white
}
.menu a.active{
    background:linear-gradient(100deg,#3268e9,#477df4);
    color:white;
    font-weight:700
}
.menu a.logout{
    margin-top:12px;
    color:#ffb6bd
}
.menu-icon{
    width:23px;
    flex-shrink:0;
    text-align:center;
    font-size:18px
}
main{
    margin-left:260px;
    padding:32px;
    min-width:0
}
.heading{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    flex-wrap:wrap;
    margin-bottom:25px
}
.heading h1{
    margin:0 0 8px;
    font-size:27px;
    color:#1b2e4d
}
.heading p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    line-height:1.6
}
.role-badge{
    padding:10px 15px;
    border-radius:10px;
    background:#e8efff;
    color:#2856bf;
    font-size:12px;
    font-weight:800
}
.quick-actions{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:15px;
    margin-bottom:22px
}
.action-link{
    display:flex;
    align-items:center;
    gap:15px;
    padding:19px;
    border:1px solid #edf0f7;
    border-radius:15px;
    background:white;
    color:var(--text);
    text-decoration:none;
    transition:.2s
}
.action-link:hover{
    border-color:#b8ccff;
    transform:translateY(-2px)
}
.action-icon{
    width:45px;
    height:45px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
    border-radius:13px;
    background:#eaf0ff;
    color:var(--blue);
    font-size:22px
}
.action-link strong{
    display:block;
    margin-bottom:5px;
    font-size:14px
}
.action-link small{
    color:var(--muted);
    font-size:12px;
    line-height:1.5
}
.stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:16px;
    margin-bottom:22px
}
.stat-card{
    display:flex;
    align-items:center;
    gap:13px;
    min-width:0;
    padding:19px 16px;
    border:1px solid #edf0f7;
    border-radius:16px;
    background:white
}
.stat-icon{
    width:45px;
    height:45px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:13px;
    font-size:21px
}
.stat-icon.blue{color:#3268e9;background:#eaf0ff}
.stat-icon.orange{color:#bd7910;background:#fff3d9}
.stat-icon.green{color:#16845b;background:#e0f7ed}
.stat-icon.red{color:#c23c4a;background:#ffeaed}
.stat-label{
    margin-bottom:6px;
    color:var(--muted);
    font-size:11px;
    line-height:1.5
}
.stat-number{
    color:#1b2e4d;
    font-size:25px;
    font-weight:800
}
.panel{
    padding:24px;
    border:1px solid #edf0f7;
    border-radius:17px;
    background:white
}
.panel-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    flex-wrap:wrap;
    margin-bottom:20px
}
.panel h2{
    margin:0 0 7px;
    font-size:19px;
    color:#1d3151
}
.description{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.6
}
.view-all{
    color:#3268e9;
    font-size:12px;
    font-weight:700;
    text-decoration:none
}
.table-wrap{
    width:100%;
    overflow-x:auto
}
table{
    width:100%;
    min-width:760px;
    border-collapse:collapse
}
thead th{
    padding:13px 14px;
    background:#f5f7fb;
    color:#74829a;
    font-size:10px;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:.7px;
    white-space:nowrap
}
tbody td{
    padding:15px 14px;
    border-bottom:1px solid #edf0f6;
    color:#44536b;
    font-size:12px;
    vertical-align:middle
}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fafcff}
.code{
    color:#2d5dcc;
    font-weight:800;
    white-space:nowrap
}
.item-name{
    margin-bottom:4px;
    color:#243653;
    font-size:13px;
    font-weight:700
}
.subtext{
    color:#98a3b5;
    font-size:11px
}
.badge{
    display:inline-block;
    max-width:180px;
    padding:7px 10px;
    border-radius:8px;
    font-size:10px;
    font-weight:800;
    line-height:1.4
}
.badge.warning{color:#946200;background:#fff2cc}
.badge.success{color:#14784e;background:#ddf7e9}
.badge.danger{color:#b42335;background:#ffeaed}
.badge.blue{color:#2856bf;background:#e8efff}
.badge.purple{color:#7542a8;background:#f2e8ff}
.empty-state{
    padding:35px 20px;
    color:var(--muted);
    text-align:center;
    font-size:13px
}
.alert{
    margin-bottom:20px;
    padding:14px 16px;
    border-radius:10px;
    background:#ffebed;
    color:#b42335;
    font-size:13px;
    line-height:1.6
}
.footer{
    margin-top:25px;
    color:#9aa5b7;
    font-size:11px;
    text-align:center
}
@media(max-width:1200px){
    .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .quick-actions{grid-template-columns:1fr}
}
@media(max-width:900px){
    main{padding:24px}
}
@media(max-width:650px){
    .sidebar{
        position:relative;
        width:100%;
        height:auto;
        padding:18px
    }
    .brand{padding-bottom:18px}
    .profile-mini{margin:16px 0}
    .menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
    .menu a{gap:7px;padding:11px 8px;font-size:11px}
    main{margin-left:0;padding:18px}
    .heading h1{font-size:23px}
    .stats{grid-template-columns:1fr}
    .panel{padding:17px}
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
            <div class="profile-role">Petugas Sarpras</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php" class="active">
            <span class="menu-icon">⌂</span>Dashboard
        </a>

        <a href="peminjaman.php">
            <span class="menu-icon">▤</span>Data Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>Pengembalian
        </a>

        <a href="data_alat.php">
            <span class="menu-icon">▣</span>Data Alat
        </a>

        <a href="riwayat.php">
            <span class="menu-icon">📋</span>Riwayat Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">
            AKUN
        </div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>Profil Saya
        </a>

        <a href="../logout.php"
           class="logout"
           onclick="return confirm('Yakin ingin keluar?')">
            <span class="menu-icon">⇥</span>Keluar
        </a>
    </nav>
</aside>

<main>
    <div class="heading">
        <div>
            <h1>Dashboard Petugas</h1>
            <p>
                Halo, <?= h($nama) ?>!
                Pantau pengajuan dan pengembalian sarana sekolah.
            </p>
        </div>
        <span class="role-badge">PETUGAS</span>
    </div>

    <?php if ($pesan_error !== ''): ?>
        <div class="alert"><?= h($pesan_error) ?></div>
    <?php endif; ?>

    <section class="quick-actions">
        <a href="peminjaman.php" class="action-link">
            <div class="action-icon">▤</div>
            <div>
                <strong>Periksa Peminjaman</strong>
                <small>Periksa dan proses pengajuan peminjaman.</small>
            </div>
        </a>

        <a href="pengembalian.php" class="action-link">
            <div class="action-icon">↩</div>
            <div>
                <strong>Periksa Pengembalian</strong>
                <small>Periksa kondisi alat yang dikembalikan.</small>
            </div>
        </a>

        <a href="riwayat.php" class="action-link">
            <div class="action-icon">📋</div>
            <div>
                <strong>Riwayat Aktivitas</strong>
                <small>Lihat catatan peminjaman dan pengembalian.</small>
            </div>
        </a>
    </section>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon blue">▤</div>
            <div>
                <div class="stat-label">Total Pengajuan</div>
                <div class="stat-number"><?= $total_pengajuan ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">◷</div>
            <div>
                <div class="stat-label">Menunggu Persetujuan</div>
                <div class="stat-number"><?= $menunggu ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">↗</div>
            <div>
                <div class="stat-label">Peminjaman Berjalan</div>
                <div class="stat-number"><?= $dipinjam ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red">!</div>
            <div>
                <div class="stat-label">Terlambat</div>
                <div class="stat-number"><?= $terlambat ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">↩</div>
            <div>
                <div class="stat-label">Menunggu Pemeriksaan</div>
                <div class="stat-number"><?= $pengembalian ?></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Pengajuan Terbaru</h2>
                <p class="description">
                    Daftar pengajuan peminjaman yang masuk ke sistem.
                </p>
            </div>

            <a href="peminjaman.php" class="view-all">
                Lihat Semua →
            </a>
        </div>

        <?php if (count($pengajuan_terbaru) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kode Pengajuan</th>
                            <th>Peminjam</th>
                            <th>Alat</th>
                            <th>Tanggal Pinjam</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($pengajuan_terbaru as $item): ?>
                        <tr>
                            <td>
                                <div class="code">
                                    <?= h($item['kode_peminjaman']) ?>
                                </div>
                                <div class="subtext" style="margin-top:5px">
                                    #<?= (int) $item['id_peminjaman'] ?>
                                </div>
                            </td>

                            <td>
                                <div class="item-name">
                                    <?= h($item['nama_peminjam'] ?? 'Pengguna') ?>
                                </div>
                            </td>

                            <td>
                                <div class="item-name">
                                    <?= h($item['daftar_alat'] ?: 'Belum ada detail alat') ?>
                                </div>
                                <div class="subtext">
                                    <?= (int) $item['jumlah_alat'] ?> unit
                                </div>
                            </td>

                            <td>
                                <?= h(
                                    !empty($item['tanggal_pinjam'])
                                    ? date('d/m/Y', strtotime($item['tanggal_pinjam']))
                                    : '-'
                                ) ?>

                                <div class="subtext" style="margin-top:5px">
                                    <?= h(substr(
                                        (string) ($item['jam_pinjam'] ?? ''),
                                        0,
                                        5
                                    )) ?>
                                </div>
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
                Belum ada pengajuan peminjaman yang tercatat.
            </div>
        <?php endif; ?>
    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman Sarana dan Prasarana Sekolah
    </div>
</main>
</body>
</html>