<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user']) ||
    ($_SESSION['role'] ?? '') !== 'petugas'
) {
    header('Location: ../login.php');
    exit;
}

function h($teks) {
    return htmlspecialchars(
        (string)($teks ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$nama = $_SESSION['nama'] ?? 'Petugas';
$pesan_error = '';
$cek_kategori = mysqli_query(
    $koneksi,
    "SHOW TABLES LIKE 'kategori'"
);
$ada_kategori = $cek_kategori &&
    mysqli_num_rows($cek_kategori) > 0;
$cek_ruangan = mysqli_query(
    $koneksi,
    "SHOW TABLES LIKE 'ruangan'"
);
$ada_ruangan = $cek_ruangan &&
    mysqli_num_rows($cek_ruangan) > 0;

if ($ada_kategori && $ada_ruangan) {
    $sql = "
        SELECT
            a.id_alat,
            a.kode_inventaris,
            a.nama_alat,
            a.stok_total,
            a.stok_tersedia,
            a.kondisi,
            a.status,
            a.deskripsi,
            k.nama_kategori,
            r.nama_ruangan
        FROM alat a
        LEFT JOIN kategori k
            ON a.id_kategori = k.id_kategori
        LEFT JOIN ruangan r
            ON a.id_ruangan = r.id_ruangan
        ORDER BY a.id_alat DESC
    ";
} elseif ($ada_kategori) {
    $sql = "
        SELECT
            a.id_alat,
            a.kode_inventaris,
            a.nama_alat,
            a.stok_total,
            a.stok_tersedia,
            a.kondisi,
            a.status,
            a.deskripsi,
            k.nama_kategori,
            a.id_ruangan
        FROM alat a
        LEFT JOIN kategori k
            ON a.id_kategori = k.id_kategori
        ORDER BY a.id_alat DESC
    ";
} else {
    $sql = "
        SELECT
            id_alat,
            kode_inventaris,
            nama_alat,
            id_kategori,
            id_ruangan,
            stok_total,
            stok_tersedia,
            kondisi,
            status,
            deskripsi
        FROM alat
        ORDER BY id_alat DESC
    ";
}

$hasil = mysqli_query($koneksi, $sql);

if (!$hasil) {
    $pesan_error = 'Gagal mengambil data alat: ' .
        mysqli_error($koneksi);
}
function labelKondisi($kondisi) {
    $label = [
        'baik' => 'Baik',
        'perlu_perbaikan' => 'Perlu Perbaikan',
        'rusak' => 'Rusak'
    ];

    return $label[$kondisi] ??
        ucfirst(str_replace('_', ' ', (string)$kondisi));
}
function labelStatusAlat($status) {
    $label = [
        'tersedia' => 'Tersedia',
        'dipinjam' => 'Dipinjam',
        'perawatan' => 'Perawatan',
        'nonaktif' => 'Nonaktif'
    ];

    return $label[$status] ??
        ucfirst(str_replace('_', ' ', (string)$status));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Data Alat - SIPRAS</title>

<style>
:root {
    --navy: #12284b;
    --blue: #3268e9;
    --background: #f4f7fc;
    --text: #253653;
    --muted: #8290a7;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: "Segoe UI", Arial, sans-serif;
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

/* KONTEN UTAMA */
main {
    margin-left: 260px;
    padding: 32px;
    min-width: 0;
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
    color: var(--muted);
    font-size: 13px;
}

.panel {
    padding: 22px;
    background: white;
    border: 1px solid #e9eef7;
    border-radius: 18px;
    box-shadow: 0 8px 30px rgba(24,47,85,.04);
}

.panel h2 {
    margin: 0 0 18px;
    font-size: 17px;
}

.alert {
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 18px;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.alert.error {
    color: #a22d3c;
    background: #fff0f1;
}

/* TABEL */
.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

th {
    padding: 13px 12px;
    background: #f5f7fc;
    color: #6c7b94;
    font-size: 11px;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}

td {
    padding: 15px 12px;
    border-bottom: 1px solid #edf1f7;
    font-size: 12px;
    vertical-align: top;
    line-height: 1.6;
}

tr:last-child td {
    border-bottom: none;
}

.kode {
    color: #3268e9;
    font-weight: 800;
}

/* BADGE */
.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.baik,
.tersedia {
    color: #18734d;
    background: #e5f8ed;
}

.rusak,
.nonaktif {
    color: #a52c3b;
    background: #ffeaed;
}

.perbaikan,
.perawatan {
    color: #98610b;
    background: #fff3d8;
}

.dipinjam,
.lainnya {
    color: #2456b3;
    background: #e9f0ff;
}

.empty {
    padding: 35px;
    text-align: center;
    color: var(--muted);
}

/* RESPONSIVE */
@media(max-width:650px) {
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

    .panel {
        padding: 14px;
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

        <a href="data_alat.php" class="active">
            <span class="menu-icon">▣</span>
            Data Alat
        </a>

        <a href="riwayat.php">
            <span class="menu-icon">📋</span>
            Riwayat Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">
            AKUN
        </div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>
            Profil Saya
        </a>

        <a
            href="../logout.php"
            class="logout"
            onclick="return confirm('Yakin ingin keluar?')"
        >
            <span class="menu-icon">⇥</span>
            Keluar
        </a>
    </nav>
</aside>

<!-- KONTEN -->
<main>
    <div class="heading">
        <h1>Data Alat</h1>
        <p>Daftar inventaris sarana dan prasarana sekolah.</p>
    </div>

    <?php if ($pesan_error !== ''): ?>
        <div class="alert error">
            <?= h($pesan_error) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Daftar Inventaris Sekolah</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Inventaris</th>
                        <th>Nama Alat</th>
                        <th>Kategori</th>
                        <th>Stok Total</th>
                        <th>Stok Tersedia</th>
                        <th>Ruangan</th>
                        <th>Kondisi</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($hasil && mysqli_num_rows($hasil) > 0): ?>
                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($hasil)): ?>
                        <?php
                        $kategori = $row['nama_kategori']
                            ?? ('Kategori #' . ($row['id_kategori'] ?? '-'));

                        $ruangan = $row['nama_ruangan']
                            ?? (
                                isset($row['id_ruangan']) &&
                                $row['id_ruangan'] !== null
                                ? 'Ruangan #' . $row['id_ruangan']
                                : '-'
                            );

                        $kondisi = $row['kondisi'] ?? '';
                        $status = $row['status'] ?? '';

                        $kelas_kondisi = $kondisi === 'baik'
                            ? 'baik'
                            : (
                                $kondisi === 'rusak'
                                ? 'rusak'
                                : (
                                    $kondisi === 'perlu_perbaikan'
                                    ? 'perbaikan'
                                    : 'lainnya'
                                )
                            );

                        $kelas_status = in_array(
                            $status,
                            ['tersedia', 'dipinjam', 'perawatan', 'nonaktif'],
                            true
                        ) ? $status : 'lainnya';
                        ?>

                        <tr>
                            <td><?= $no++ ?></td>

                            <td>
                                <span class="kode">
                                    <?= h($row['kode_inventaris']) ?>
                                </span>
                            </td>

                            <td><?= h($row['nama_alat']) ?></td>

                            <td><?= h($kategori) ?></td>

                            <td><?= (int)$row['stok_total'] ?></td>

                            <td><?= (int)$row['stok_tersedia'] ?></td>

                            <td><?= h($ruangan) ?></td>

                            <td>
                                <span class="badge <?= h($kelas_kondisi) ?>">
                                    <?= h(labelKondisi($kondisi)) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= h($kelas_status) ?>">
                                    <?= h(labelStatusAlat($status)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty">
                            <?php if ($hasil): ?>
                                Belum ada data alat di database.
                            <?php else: ?>
                                Data alat belum bisa ditampilkan.
                                Periksa pesan error di atas.
                            <?php endif; ?>
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