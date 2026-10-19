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

$nama = $_SESSION['nama'] ?? 'Peminjam';

$cari = trim($_GET['cari'] ?? '');
$kategori = trim($_GET['kategori'] ?? '');
$ruangan = trim($_GET['ruangan'] ?? '');

$daftarKategori = $koneksi->query(
    "SELECT id_kategori, nama_kategori
     FROM kategori
     ORDER BY nama_kategori"
);

$daftarRuangan = $koneksi->query(
    "SELECT id_ruangan, nama_ruangan
     FROM ruangan
     ORDER BY nama_ruangan"
);

$sql = "SELECT
            a.id_alat,
            a.kode_inventaris,
            a.nama_alat,
            a.deskripsi,
            a.stok_tersedia,
            a.kondisi,
            k.nama_kategori,
            r.nama_ruangan
        FROM alat a
        LEFT JOIN kategori k
            ON a.id_kategori = k.id_kategori
        LEFT JOIN ruangan r
            ON a.id_ruangan = r.id_ruangan
        WHERE a.stok_tersedia > 0
          AND a.status = 'tersedia'
          AND a.kondisi = 'baik'";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= " AND (
        a.nama_alat LIKE ?
        OR a.kode_inventaris LIKE ?
    )";

    $keyword = '%' . $cari . '%';
    $types .= 'ss';
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($kategori !== '' && ctype_digit($kategori)) {
    $sql .= " AND a.id_kategori = ?";
    $types .= 'i';
    $params[] = (int) $kategori;
}

if ($ruangan !== '' && ctype_digit($ruangan)) {
    $sql .= " AND a.id_ruangan = ?";
    $types .= 'i';
    $params[] = (int) $ruangan;
}

$sql .= " ORDER BY a.nama_alat ASC";

$stmt = $koneksi->prepare($sql);

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$hasil = $stmt->get_result();
$total = $hasil->num_rows;
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Daftar Alat | SIPRAS</title>

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

/* SIDEBAR */
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
    z-index: 10;
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
    transition: .2s;
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

/* KONTEN */
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
    line-height: 1.6;
}

/* PENCARIAN DAN FILTER */
.filter {
    margin-bottom: 22px;
    padding: 20px;
    background: white;
    border: 1px solid #edf0f7;
    border-radius: 15px;
}

.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}

.field label {
    display: block;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dfe6f0;
    border-radius: 9px;
    background: white;
    color: #253653;
    font: inherit;
    font-size: 13px;
}

input:focus,
select:focus {
    outline: 2px solid #dce7ff;
    border-color: #3268e9;
}

.actions {
    display: flex;
    gap: 8px;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 12px 16px;
    border: none;
    border-radius: 9px;
    background: #3268e9;
    color: white;
    font-size: 13px;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}

.btn:hover {
    background: #2857cb;
}

.btn-reset {
    background: #edf2fb;
    color: #405675;
}

.result-info {
    margin: 0 0 16px;
    color: #8290a7;
    font-size: 13px;
}

/* TABEL DAFTAR ALAT */
.table-wrap {
    overflow-x: auto;
    background: white;
    border: 1px solid #e9eef7;
    border-radius: 14px;
}

table {
    width: 100%;
    min-width: 850px;
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
}

td {
    padding: 16px;
    border-top: 1px solid #edf1f7;
    font-size: 13px;
    vertical-align: middle;
}

td strong {
    color: #253653;
}

.subtext {
    display: block;
    margin-top: 5px;
    color: #8996aa;
    font-size: 11px;
}

.stock {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 7px;
    background: #e4f8ed;
    color: #168653;
    font-size: 11px;
    font-weight: bold;
}

.empty {
    padding: 45px 20px;
    text-align: center;
    color: #8290a7;
    line-height: 1.8;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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

    .menu-icon {
        font-size: 16px;
    }

    main {
        margin-left: 0;
        padding: 18px;
    }

    .heading h1 {
        font-size: 23px;
    }

    .filter-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar">

    <div class="brand">
        <div class="brand-icon">▦</div>
        <div>SI<span>PRAS</span></div>
    </div>

    <div class="profile-mini">
        <div class="avatar">
            <?= htmlspecialchars(strtoupper(substr($nama, 0, 1))) ?>
        </div>

        <div>
            <div class="profile-name">
                <?= htmlspecialchars($nama) ?>
            </div>
            <div class="profile-role">Siswa / Peminjam</div>
        </div>
    </div>

    <div class="menu-label">MENU UTAMA</div>

    <nav class="menu">
        <a href="dashboard.php">
            <span class="menu-icon">⌂</span>
            Dashboard
        </a>

        <a href="alat.php" class="active">
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

<!-- KONTEN UTAMA -->
<main>

    <header class="heading">
        <h1>Daftar Alat Sekolah</h1>
        <p>
            Lihat alat dan sarana sekolah yang tersedia
            untuk dipinjam.
        </p>
    </header>

    <!-- FORM PENCARIAN -->
    <form class="filter" method="GET" action="alat.php">

        <div class="filter-grid">

            <div class="field">
                <label for="cari">Cari Alat</label>
                <input
                    type="search"
                    id="cari"
                    name="cari"
                    placeholder="Nama atau kode inventaris..."
                    value="<?= htmlspecialchars($cari) ?>"
                >
            </div>

            <div class="field">
                <label for="kategori">Kategori</label>

                <select name="kategori" id="kategori">
                    <option value="">Semua kategori</option>

                    <?php while ($k = $daftarKategori->fetch_assoc()): ?>
                        <option
                            value="<?= (int) $k['id_kategori'] ?>"
                            <?= $kategori === (string) $k['id_kategori'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($k['nama_kategori']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="field">
                <label for="ruangan">Ruangan</label>

                <select name="ruangan" id="ruangan">
                    <option value="">Semua ruangan</option>

                    <?php while ($r = $daftarRuangan->fetch_assoc()): ?>
                        <option
                            value="<?= (int) $r['id_ruangan'] ?>"
                            <?= $ruangan === (string) $r['id_ruangan'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($r['nama_ruangan']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Cari</button>
                <a class="btn btn-reset" href="alat.php">Reset</a>
            </div>

        </div>
    </form>

    <p class="result-info">
        Jumlah alat tersedia:
        <strong><?= $total ?></strong>
    </p>

    <!-- TABEL ALAT -->
    <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama Alat</th>
                    <th>Kode Inventaris</th>
                    <th>Kategori</th>
                    <th>Ruangan</th>
                    <th>Kondisi</th>
                    <th>Stok</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($total > 0): ?>
                <?php $no = 1; ?>

                <?php while ($item = $hasil->fetch_assoc()): ?>
                    <tr>

                        <td><?= $no++ ?></td>

                        <td>
                            <strong>
                                <?= htmlspecialchars($item['nama_alat']) ?>
                            </strong>

                            <?php if (!empty($item['deskripsi'])): ?>
                                <span class="subtext">
                                    <?= htmlspecialchars($item['deskripsi']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item['kode_inventaris']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item['nama_kategori'] ?? '-') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($item['nama_ruangan'] ?? '-') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                ucfirst(str_replace('_', ' ', $item['kondisi']))
                            ) ?>
                        </td>

                        <td>
                            <span class="stock">
                                <?= (int) $item['stok_tersedia'] ?> unit
                            </span>
                        </td>

                        <td>
                            <a
                                class="btn"
                                href="peminjaman.php?alat=<?= (int) $item['id_alat'] ?>"
                            >
                                Pinjam
                            </a>
                        </td>

                    </tr>
                <?php endwhile; ?>

            <?php else: ?>
                <tr>
                    <td colspan="8" class="empty">
                        <strong>Belum ada alat yang tersedia.</strong>
                        <br>
                        Coba ubah kata pencarian atau filter.
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
?>
