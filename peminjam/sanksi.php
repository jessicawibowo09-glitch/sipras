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
$sqlRingkasan = "
    SELECT
        COUNT(DISTINCT s.id_sanksi) AS total_sanksi,
        COALESCE(SUM(s.nominal), 0) AS total_nominal,
        COALESCE(SUM(
            CASE
                WHEN LOWER(s.status) IN
                    ('belum dibayar', 'belum lunas')
                THEN s.nominal
                ELSE 0
            END
        ), 0) AS belum_lunas
    FROM sanksi s
    INNER JOIN peminjaman p
        ON p.id_peminjaman = s.id_peminjaman
    WHERE p.id_user = ?
";

$stmtRingkasan = $koneksi->prepare($sqlRingkasan);
$stmtRingkasan->bind_param('i', $id_user);
$stmtRingkasan->execute();

$ringkasan = $stmtRingkasan->get_result()->fetch_assoc();
$sql = "
    SELECT
        s.id_sanksi,
        s.id_peminjaman,
        s.jenis_sanksi,
        s.keterangan,
        s.nominal,
        s.status AS status_sanksi,
        s.id_petugas,
        s.dibuat_pada,

        p.kode_peminjaman,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.status AS status_peminjaman,

        pg.waktu_kembali,
        pg.terlambat_menit,
        pg.denda,
        pg.kondisi_akhir,
        pg.status AS status_pengembalian

    FROM sanksi s
    INNER JOIN peminjaman p
        ON p.id_peminjaman = s.id_peminjaman

    LEFT JOIN (
        SELECT pg1.*
        FROM pengembalian pg1
        INNER JOIN (
            SELECT
                id_peminjaman,
                MAX(id_pengembalian) AS id_terakhir
            FROM pengembalian
            GROUP BY id_peminjaman
        ) terbaru
            ON terbaru.id_terakhir = pg1.id_pengembalian
    ) pg
        ON pg.id_peminjaman = p.id_peminjaman

    WHERE p.id_user = ?
    ORDER BY s.dibuat_pada DESC
";

$stmt = $koneksi->prepare($sql);
$stmt->bind_param('i', $id_user);
$stmt->execute();

$hasil = $stmt->get_result();

function rupiah($angka)
{
    return 'Rp' . number_format(
        (float) $angka,
        0,
        ',',
        '.'
    );
}

function tanggalIndonesia($tanggal)
{
    if (!$tanggal) {
        return '-';
    }

    $waktu = strtotime($tanggal);

    if (!$waktu) {
        return htmlspecialchars(
            $tanggal,
            ENT_QUOTES,
            'UTF-8'
        );
    }

    return date('d-m-Y H:i', $waktu);
}

function durasiTerlambat($menit)
{
    $menit = max(0, (int) $menit);

    if ($menit === 0) {
        return '0 menit';
    }

    $hari = intdiv($menit, 1440);
    $sisa = $menit % 1440;
    $jam = intdiv($sisa, 60);
    $menitSisa = $sisa % 60;

    $hasil = [];

    if ($hari > 0) {
        $hasil[] = $hari . ' hari';
    }

    if ($jam > 0) {
        $hasil[] = $jam . ' jam';
    }

    if ($menitSisa > 0) {
        $hasil[] = $menitSisa . ' menit';
    }

    return implode(' ', $hasil);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan Sanksi | SIPRAS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f4f7fc;
    color: #253653;
}

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    padding: 25px 18px;
    background: linear-gradient(180deg, #102344, #172f56);
    color: white;
    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 7px 25px;
    border-bottom: 1px solid #ffffff20;
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
    background: #ffffff12;
    border-radius: 15px;
}

.avatar {
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: #dce8ff;
    color: #244eaa;
    font-weight: bold;
    font-size: 21px;
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
    border-radius: 12px;
    color: #c5d2e7;
    font-size: 13px;
    text-decoration: none;
}

.menu a:hover {
    background: #ffffff16;
    color: white;
}

.menu a.active {
    background: linear-gradient(100deg, #3268e9, #477df4);
    color: white;
    font-weight: 700;
}

.menu-icon {
    width: 23px;
    text-align: center;
    font-size: 18px;
}

.menu a.logout {
    margin-top: 12px;
    color: #ffb6bd;
}

main {
    margin-left: 260px;
    padding: 32px;
}

.heading {
    margin-bottom: 25px;
}

.heading h1 {
    margin: 0 0 8px;
    font-size: 27px;
}

.heading p {
    margin: 0;
    color: #8290a7;
    font-size: 14px;
    line-height: 1.7;
}

.cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.card {
    padding: 22px;
    background: white;
    border: 1px solid #edf0f7;
    border-radius: 15px;
}

.card-label {
    color: #8290a7;
    font-size: 12px;
    font-weight: 600;
}

.card-value {
    margin-top: 12px;
    font-size: 25px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.card-note {
    margin-top: 7px;
    color: #8996aa;
    font-size: 11px;
}

.notice {
    margin-bottom: 22px;
    padding: 15px 18px;
    border: 1px solid #dce7ff;
    border-radius: 12px;
    background: #edf3ff;
    color: #35558e;
    font-size: 12px;
    line-height: 1.8;
}

.section-title {
    margin-bottom: 18px;
}

.section-title h2 {
    margin: 0 0 7px;
    font-size: 18px;
}

.section-title p {
    margin: 0;
    color: #8290a7;
    font-size: 13px;
}

.table-wrap {
    overflow-x: auto;
    background: white;
    border: 1px solid #e9eef7;
    border-radius: 14px;
}

table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}

th {
    padding: 16px;
    background: #f8faff;
    color: #667791;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}

td {
    padding: 16px;
    border-top: 1px solid #edf1f7;
    font-size: 12px;
    vertical-align: top;
    line-height: 1.7;
}

.muted {
    display: block;
    margin-top: 4px;
    color: #8996aa;
    font-size: 11px;
}

.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 7px;
    font-size: 10px;
    font-weight: 700;
}

.badge-danger {
    background: #fff0f0;
    color: #c03945;
}

.badge-warning {
    background: #fff4dc;
    color: #946200;
}

.badge-success {
    background: #e4f8ed;
    color: #168653;
}

.badge-neutral {
    background: #edf2fb;
    color: #526580;
}

.empty {
    padding: 45px 20px;
    text-align: center;
    color: #8290a7;
    line-height: 1.9;
}

@media (max-width: 1100px) {
    .cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
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

    .heading h1 {
        font-size: 23px;
    }
}

@media print {
    .sidebar {
        display: none;
    }

    main {
        margin: 0;
        padding: 0;
    }

    body {
        background: white;
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
        <div class="avatar">
            <?= htmlspecialchars(
                strtoupper(substr($nama, 0, 1)),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

        <div>
            <div class="profile-name">
                <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="profile-role">Siswa / Peminjam</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php">
            <span class="menu-icon">⌂</span>Dashboard
        </a>

        <a href="alat.php">
            <span class="menu-icon">▤</span>Daftar Alat
        </a>

        <a href="peminjaman.php">
            <span class="menu-icon">＋</span>Ajukan Peminjaman
        </a>

        <a href="riwayat.php">
            <span class="menu-icon">◷</span>Riwayat Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>Pengembalian
        </a>

        <a href="log_aktivitas.php">
            <span class="menu-icon">◉</span>Log Aktivitas
        </a>

        <a href="sanksi.php" class="active">
            <span class="menu-icon">!</span>Laporan Sanksi
        </a>

        <div class="menu-label" style="margin-top:22px">
            AKUN
        </div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>Profil Saya
        </a>

        <a href="../logout.php" class="logout">
            <span class="menu-icon">⇥</span>Keluar
        </a>
    </nav>

</aside>

<main>

    <header class="heading">
        <h1>Laporan Sanksi</h1>
        <p>
            Pantau sanksi, keterlambatan, dan denda
            dari peminjaman fasilitas sekolah.
        </p>
    </header>

    <section class="cards">
        <div class="card">
            <div class="card-label">Total Sanksi</div>
            <div class="card-value">
                <?= (int) $ringkasan['total_sanksi'] ?>
            </div>
            <div class="card-note">Jumlah catatan sanksi akunmu</div>
        </div>

        <div class="card">
            <div class="card-label">Total Nominal Sanksi</div>
            <div class="card-value">
                <?= rupiah($ringkasan['total_nominal']) ?>
            </div>
            <div class="card-note">Akumulasi nominal sanksi</div>
        </div>

        <div class="card">
            <div class="card-label">Denda Belum Lunas</div>
            <div class="card-value">
                <?= rupiah($ringkasan['belum_lunas']) ?>
            </div>
            <div class="card-note">
                Hanya menghitung status belum dibayar/belum lunas
            </div>
        </div>
    </section>

    <div class="notice">
        <strong>Informasi:</strong>
        Data sanksi ditampilkan berdasarkan akun peminjam yang login.
        Status sanksi mengikuti data yang dicatat petugas.
        Denda pengembalian ditampilkan dari tabel pengembalian.
    </div>

    <section class="section-title">
        <h2>Riwayat Sanksi Saya</h2>
        <p>Detail sanksi yang tercatat di dalam sistem.</p>
    </section>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Kode Peminjaman</th>
                    <th>Rencana Kembali</th>
                    <th>Waktu Kembali</th>
                    <th>Keterlambatan</th>
                    <th>Jenis Sanksi</th>
                    <th>Keterangan</th>
                    <th>Denda Pengembalian</th>
                    <th>Nominal Sanksi</th>
                    <th>Status Sanksi</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($hasil->num_rows > 0): ?>
                <?php $no = 1; ?>

                <?php while ($row = $hasil->fetch_assoc()): ?>
                    <?php
                    $terlambat = (int) ($row['terlambat_menit'] ?? 0);
                    $waktuKembali = $row['waktu_kembali'] ?? null;
                    $statusSanksi = trim(
                        (string) ($row['status_sanksi'] ?? '')
                    );

                    $statusNormal = strtolower($statusSanksi);

                    if (in_array(
                        $statusNormal,
                        ['lunas', 'dibayar', 'selesai', 'paid'],
                        true
                    )) {
                        $kelasStatus = 'badge-success';
                    } elseif (in_array(
                        $statusNormal,
                        ['belum dibayar', 'belum lunas'],
                        true
                    )) {
                        $kelasStatus = 'badge-danger';
                    } elseif ($statusNormal === 'dicatat') {
                        $kelasStatus = 'badge-warning';
                    } else {
                        $kelasStatus = 'badge-neutral';
                    }

                    $rencana = $row['tanggal_rencana_kembali']
                        . ' ' . $row['jam_rencana_kembali'];
                    ?>

                    <tr>
                        <td><?= $no++ ?></td>

                        <td>
                            <strong>
                                <?= htmlspecialchars(
                                    $row['kode_peminjaman'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <?= tanggalIndonesia($rencana) ?>
                        </td>

                        <td>
                            <?= $waktuKembali
                                ? tanggalIndonesia($waktuKembali)
                                : 'Belum tercatat' ?>
                        </td>

                        <td>
                            <?php if ($terlambat > 0): ?>
                                <span class="badge badge-danger">
                                    <?= htmlspecialchars(
                                        durasiTerlambat($terlambat),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            <?php elseif ($waktuKembali): ?>
                                <span class="badge badge-success">
                                    <?= durasiTerlambat($terlambat) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-neutral">
                                    Belum tercatat
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                ucwords(str_replace(
                                    '_',
                                    ' ',
                                    $row['jenis_sanksi']
                                )),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $row['keterangan'] ?? '-',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= rupiah($row['denda'] ?? 0) ?>
                        </td>

                        <td>
                            <strong>
                                <?= rupiah($row['nominal']) ?>
                            </strong>
                        </td>

                        <td>
                            <span class="badge <?= $kelasStatus ?>">
                                <?= htmlspecialchars(
                                    $statusSanksi ?: 'Tidak ditentukan',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </td>
                    </tr>

                <?php endwhile; ?>

            <?php else: ?>
                <tr>
                    <td colspan="10" class="empty">
                        <strong>Belum ada sanksi tercatat.</strong>
                        <br>
                        Jika kamu terlambat mengembalikan alat,
                        pastikan pengembalian telah diproses dan
                        petugas telah mencatat sanksinya.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

</body>
</html>

<?php
$stmt->close();
$stmtRingkasan->close();
?>
