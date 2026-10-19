
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

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$namaAdmin = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Administrator';
$pesan = '';
$error = false;

/* Tarif denda per jam keterlambatan */
$tarifPerJam = 1000;

/* =====================================================
   PROSES PENGEMBALIAN
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'proses') {
        $idPeminjaman = (int)($_POST['id_peminjaman'] ?? 0);
        $kondisi = $_POST['kondisi_akhir'] ?? 'baik';
        $catatan = trim($_POST['catatan'] ?? '');

        $kondisiValid = [
            'baik',
            'perlu_perbaikan',
            'rusak',
            'hilang'
        ];

        if (
            $idPeminjaman <= 0 ||
            !in_array($kondisi, $kondisiValid, true)
        ) {
            $pesan = 'Data pengembalian tidak valid.';
            $error = true;
        } else {
            try {
                $koneksi->begin_transaction();

                /* Ambil data peminjaman dan kunci transaksi */
                $stmt = $koneksi->prepare("
                    SELECT
                        p.id_peminjaman,
                        p.kode_peminjaman,
                        p.status,
                        p.tanggal_rencana_kembali,
                        p.jam_rencana_kembali
                    FROM peminjaman p
                    WHERE p.id_peminjaman = ?
                    FOR UPDATE
                ");

                $stmt->bind_param('i', $idPeminjaman);
                $stmt->execute();
                $pinjam = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$pinjam) {
                    throw new Exception('Data peminjaman tidak ditemukan.');
                }

                if (
                    !in_array(
                        $pinjam['status'],
                        ['sedang_dipinjam', 'terlambat'],
                        true
                    )
                ) {
                    throw new Exception(
                        'Peminjaman tidak aktif atau sudah selesai.'
                    );
                }

                /* Pastikan belum pernah dikembalikan */
                $stmt = $koneksi->prepare("
                    SELECT id_pengembalian
                    FROM pengembalian
                    WHERE id_peminjaman = ?
                    LIMIT 1
                ");

                $stmt->bind_param('i', $idPeminjaman);
                $stmt->execute();
                $sudahKembali = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($sudahKembali) {
                    throw new Exception(
                        'Pengembalian untuk peminjaman ini sudah tercatat.'
                    );
                }

                /* Validasi tanggal dan jam batas kembali */
                $tanggalBatas = $pinjam['tanggal_rencana_kembali'] ?? '';
                $jamBatas = $pinjam['jam_rencana_kembali'] ?? '';

                if (
                    $tanggalBatas === '' ||
                    $jamBatas === '' ||
                    strtotime($tanggalBatas . ' ' . $jamBatas) === false
                ) {
                    throw new Exception(
                        'Tanggal atau jam rencana pengembalian belum valid.'
                    );
                }

                /*
                 * Ambil waktu server untuk mencatat pengembalian.
                 * Pastikan zona waktu PHP dan MySQL sesuai.
                 */
                $waktuKembali = date('Y-m-d H:i:s');
                $timestampKembali = strtotime($waktuKembali);
                $timestampBatas = strtotime(
                    $tanggalBatas . ' ' . $jamBatas
                );

                /* Hitung keterlambatan dalam menit */
                $selisihDetik = $timestampKembali - $timestampBatas;

                $terlambatMenit = $selisihDetik > 0
                    ? (int)ceil($selisihDetik / 60)
                    : 0;

                /*
                 * Denda dibulatkan ke atas per jam.
                 * Contoh: terlambat 10 menit = denda 1 jam.
                 */
                $jamTerlambat = $terlambatMenit > 0
                    ? (int)ceil($terlambatMenit / 60)
                    : 0;

                $denda = $jamTerlambat * $tarifPerJam;
                $idPetugas = (int)$_SESSION['id_user'];

                /* Simpan pengembalian */
                $stmt = $koneksi->prepare("
                    INSERT INTO pengembalian (
                        id_peminjaman,
                        waktu_kembali,
                        kondisi_akhir,
                        terlambat_menit,
                        denda,
                        catatan,
                        id_petugas_penerima,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'selesai')
                ");

                $stmt->bind_param(
                    'issidsi',
                    $idPeminjaman,
                    $waktuKembali,
                    $kondisi,
                    $terlambatMenit,
                    $denda,
                    $catatan,
                    $idPetugas
                );

                $stmt->execute();
                $stmt->close();

                /* Perbarui stok hanya untuk kondisi baik */
                if ($kondisi === 'baik') {
                    $stmt = $koneksi->prepare("
                        SELECT id_alat, jumlah
                        FROM detail_peminjaman
                        WHERE id_peminjaman = ?
                    ");

                    $stmt->bind_param('i', $idPeminjaman);
                    $stmt->execute();
                    $detail = $stmt->get_result();

                    $stmtStok = $koneksi->prepare("
                        UPDATE alat
                        SET
                            stok_tersedia = LEAST(
                                stok_total,
                                stok_tersedia + ?
                            ),
                            status = CASE
                                WHEN LEAST(
                                    stok_total,
                                    stok_tersedia + ?
                                ) >= stok_total
                                THEN 'tersedia'
                                ELSE 'dipinjam'
                            END
                        WHERE id_alat = ?
                    ");

                    while ($item = $detail->fetch_assoc()) {
                        $jumlah = (int)$item['jumlah'];
                        $idAlat = (int)$item['id_alat'];

                        $stmtStok->bind_param(
                            'iii',
                            $jumlah,
                            $jumlah,
                            $idAlat
                        );

                        $stmtStok->execute();
                    }

                    $stmtStok->close();
                    $stmt->close();
                }

                /* Perbarui status peminjaman */
                $stmt = $koneksi->prepare("
                    UPDATE peminjaman
                    SET status = 'selesai'
                    WHERE id_peminjaman = ?
                ");

                $stmt->bind_param('i', $idPeminjaman);
                $stmt->execute();
                $stmt->close();

                /*
                 * Catat sanksi denda jika terlambat.
                 * Nominal dan status mengikuti tabel sanksi.
                 */
                if ($denda > 0) {
                    $jenisSanksi = 'denda';

                    $keterangan = 'Terlambat mengembalikan barang selama '
                        . $terlambatMenit
                        . ' menit. Tarif Rp'
                        . number_format($tarifPerJam, 0, ',', '.')
                        . ' per jam. Total denda Rp'
                        . number_format($denda, 0, ',', '.')
                        . '.';

                    $stmt = $koneksi->prepare("
                        INSERT INTO sanksi (
                            id_peminjaman,
                            jenis_sanksi,
                            keterangan,
                            nominal,
                            status,
                            id_petugas
                        )
                        VALUES (?, ?, ?, ?, 'dicatat', ?)
                    ");

                    $stmt->bind_param(
                        'issdi',
                        $idPeminjaman,
                        $jenisSanksi,
                        $keterangan,
                        $denda,
                        $idPetugas
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                /* Catat kondisi barang bermasalah */
                if (
                    in_array(
                        $kondisi,
                        ['perlu_perbaikan', 'rusak', 'hilang'],
                        true
                    )
                ) {
                    $jenisSanksi = $kondisi === 'hilang'
                        ? 'kehilangan'
                        : 'kerusakan';

                    $keterangan = 'Kondisi pengembalian: '
                        . str_replace('_', ' ', $kondisi);

                    if ($catatan !== '') {
                        $keterangan .= '. Catatan: ' . $catatan;
                    }

                    $nominal = 0;

                    $stmt = $koneksi->prepare("
                        INSERT INTO sanksi (
                            id_peminjaman,
                            jenis_sanksi,
                            keterangan,
                            nominal,
                            status,
                            id_petugas
                        )
                        VALUES (?, ?, ?, ?, 'dicatat', ?)
                    ");

                    $stmt->bind_param(
                        'issdi',
                        $idPeminjaman,
                        $jenisSanksi,
                        $keterangan,
                        $nominal,
                        $idPetugas
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                $koneksi->commit();

                header('Location: pengembalian.php?status=berhasil');
                exit;

            } catch (Throwable $ex) {
                try {
                    $koneksi->rollback();
                } catch (Throwable $rollbackError) {
                    error_log($rollbackError->getMessage());
                }

                error_log('Pengembalian SIPRAS: ' . $ex->getMessage());

                $pesan = $ex instanceof mysqli_sql_exception
                    ? 'Gagal menyimpan data. Periksa struktur tabel database dan log PHP.'
                    : $ex->getMessage();

                $error = true;
            }
        }
    }
}

/* =====================================================
   NOTIFIKASI
===================================================== */

if (
    isset($_GET['status']) &&
    $_GET['status'] === 'berhasil'
) {
    $pesan = 'Pengembalian berhasil dicatat.';
}

/* =====================================================
   PENCARIAN DAN FILTER
===================================================== */

$cari = trim($_GET['cari'] ?? '');
$filter = $_GET['filter'] ?? 'semua';

$filterValid = ['semua', 'aktif', 'selesai', 'terlambat'];

if (!in_array($filter, $filterValid, true)) {
    $filter = 'semua';
}

$sql = "
    SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.status AS status_pinjam,
        u.nama AS nama_peminjam,
        pg.waktu_kembali,
        pg.kondisi_akhir,
        pg.terlambat_menit,
        pg.denda,
        pg.status AS status_kembali
    FROM peminjaman p
    JOIN users u
        ON u.id_user = p.id_user
    LEFT JOIN pengembalian pg
        ON pg.id_peminjaman = p.id_peminjaman
    WHERE 1=1
";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= "
        AND (
            p.kode_peminjaman LIKE ?
            OR u.nama LIKE ?
        )
    ";

    $keyword = '%' . $cari . '%';

    $types .= 'ss';
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($filter === 'aktif') {
    $sql .= "
        AND p.status IN ('sedang_dipinjam', 'terlambat')
        AND pg.id_pengembalian IS NULL
    ";
} elseif ($filter === 'selesai') {
    $sql .= " AND pg.id_pengembalian IS NOT NULL";
} elseif ($filter === 'terlambat') {
    $sql .= "
        AND p.status IN ('sedang_dipinjam', 'terlambat')
        AND TIMESTAMP(
            p.tanggal_rencana_kembali,
            p.jam_rencana_kembali
        ) < NOW()
        AND pg.id_pengembalian IS NULL
    ";
}

$sql .= " ORDER BY p.id_peminjaman DESC";

if ($params) {
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = $koneksi->query($sql);
}

/* =====================================================
   STATISTIK
===================================================== */

$totalAktif = (int)$koneksi->query("
    SELECT COUNT(*) AS total
    FROM peminjaman p
    WHERE p.status IN ('sedang_dipinjam', 'terlambat')
      AND NOT EXISTS (
          SELECT 1
          FROM pengembalian pg
          WHERE pg.id_peminjaman = p.id_peminjaman
      )
")->fetch_assoc()['total'];

$totalSelesai = (int)$koneksi->query("
    SELECT COUNT(*) AS total
    FROM pengembalian
    WHERE status = 'selesai'
")->fetch_assoc()['total'];

$totalTerlambat = (int)$koneksi->query("
    SELECT COUNT(*) AS total
    FROM peminjaman p
    WHERE p.status IN ('sedang_dipinjam', 'terlambat')
      AND TIMESTAMP(
          p.tanggal_rencana_kembali,
          p.jam_rencana_kembali
      ) < NOW()
      AND NOT EXISTS (
          SELECT 1
          FROM pengembalian pg
          WHERE pg.id_peminjaman = p.id_peminjaman
      )
")->fetch_assoc()['total'];

$totalDenda = (float)$koneksi->query("
    SELECT COALESCE(SUM(denda), 0) AS total
    FROM pengembalian
    WHERE status = 'selesai'
")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pengembalian | SIPRAS</title>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7fc;color:#263653}
.sidebar{position:fixed;inset:0 auto 0 0;display:flex;flex-direction:column;width:250px;padding:26px 18px;background:#12284b;color:white;overflow-y:auto;z-index:10}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 28px;border-bottom:1px solid #314360}
.logo{display:grid;place-items:center;width:43px;height:43px;border-radius:13px;background:linear-gradient(135deg,#4385ff,#2452c8);font-size:22px;font-weight:bold}
.brand h2{font-size:19px;letter-spacing:2px}
.brand p{margin-top:4px;color:#b9c9e6;font-size:10px}
.menu-title{margin:28px 10px 12px;color:#91a6c9;font-size:10px;font-weight:bold;letter-spacing:1.5px}
.menu{display:grid;gap:6px}
.menu a{display:flex;align-items:center;gap:12px;padding:12px 13px;border-radius:10px;color:#d5def0;text-decoration:none;font-size:13px}
.menu a:hover,.menu a.active{background:#294b83;color:white}
.menu a.active{box-shadow:inset 3px 0 #76a7ff}
.menu-icon{width:21px;text-align:center;font-size:17px}
.sidebar-bottom{margin-top:auto;padding-top:24px}
.profile{display:flex;align-items:center;gap:10px;padding:13px 9px;border-top:1px solid #314360}
.avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#e3ecff;color:#2454bc;font-weight:bold}
.profile-name{max-width:140px;overflow-wrap:anywhere;font-size:12px;font-weight:bold}
.profile-role{margin-top:4px;color:#b6c7e4;font-size:11px}
.logout{display:block;margin-top:8px;padding:11px;border:1px solid #405271;border-radius:9px;color:#ffe0e0;text-align:center;text-decoration:none;font-size:12px}
.main{width:calc(100% - 250px);margin-left:250px;padding:32px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:28px}
.breadcrumb{margin-bottom:8px;color:#8490a5;font-size:12px}
h1{color:#12284b;font-size:29px}
.subtitle{margin-top:8px;color:#8490a5;font-size:13px}
.date-badge{padding:12px 16px;border:1px solid #e5eaf3;border-radius:12px;background:white;color:#536581;font-size:12px;white-space:nowrap}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:22px}
.stat-card{display:flex;align-items:center;gap:13px;padding:18px;border:1px solid #e9edf5;border-radius:15px;background:white}
.stat-icon{display:grid;place-items:center;width:44px;height:44px;flex-shrink:0;border-radius:13px;background:#e9f0ff;color:#3268e9;font-size:21px}
.stat-label{color:#8490a5;font-size:11px}
.stat-value{margin-top:6px;color:#12284b;font-size:23px;font-weight:800}
.card{padding:24px;margin-bottom:22px;border:1px solid #e9edf5;border-radius:15px;background:white;box-shadow:0 3px 12px #1e375a08}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}
.toolbar h2{color:#12284b;font-size:17px}
.toolbar p{margin-top:6px;color:#8490a5;font-size:12px}
.search-form{display:flex;gap:8px;width:min(100%,440px)}
input,textarea,select{width:100%;padding:11px 12px;border:1px solid #dce3ee;border-radius:9px;outline:none;background:white;color:#263653;font:inherit;font-size:12px}
input:focus,textarea:focus,select:focus{border-color:#3268e9;box-shadow:0 0 0 3px #3268e91a}
.search-form input{min-width:100px}
.btn{display:inline-block;padding:11px 14px;border:0;border-radius:8px;cursor:pointer;text-decoration:none;text-align:center;font-size:12px;font-weight:bold;white-space:nowrap}
.primary{background:#3268e9;color:white}
.secondary{background:#edf1f7;color:#42516a}
.alert{padding:14px 16px;margin-bottom:20px;border-radius:9px;background:#e4f7eb;color:#207443;font-size:13px}
.alert.error{background:#fee9e9;color:#a82c2c}
.table-wrap{overflow-x:auto}
table{width:100%;min-width:1000px;border-collapse:collapse}
th,td{padding:14px 12px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:middle}
th{background:#f7f9fc;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
td{font-size:12px;line-height:1.7}
.code{color:#2459bc;font-weight:800}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#e7f7ed;color:#247344;font-size:10px;font-weight:bold;white-space:nowrap}
.badge.warning{background:#fff2d9;color:#9a6700}
.badge.danger-badge{background:#ffebeb;color:#b52c2c}
.badge.neutral{background:#edf0f5;color:#687386}
.empty{padding:35px;color:#8490a5;text-align:center}
.filter{max-width:155px}
.footer{padding:20px 0;color:#98a3b5;font-size:11px;line-height:1.8;text-align:center}
.modal-form{display:grid;grid-template-columns:1fr 1fr;gap:17px}
.modal-form .full{grid-column:1/-1}
.modal-form label{display:block;margin-bottom:8px;font-size:12px;font-weight:700}
.help{margin-top:7px;color:#8490a5;font-size:11px;line-height:1.6}
@media(max-width:1200px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){.main{padding:22px}.toolbar{align-items:stretch;flex-direction:column}.search-form{width:100%}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.sidebar-bottom{margin-top:20px}.main{width:100%;margin-left:0;padding:18px}.date-badge{display:none}.stats{grid-template-columns:1fr 1fr}.modal-form{grid-template-columns:1fr}.modal-form .full{grid-column:auto}}
@media(max-width:420px){.stats{grid-template-columns:1fr}}
</style>
</head>

<body>
<aside class="sidebar">
    <div class="brand">
        <div class="logo">S</div>
        <div><h2>SIPRAS</h2><p>SMART FACILITY SYSTEM</p></div>
    </div>

    <div class="menu-title">MENU UTAMA</div>
    <nav class="menu">
        <a href="dashboard.php"><span class="menu-icon">⌂</span> Dashboard</a>
        <a href="kategori.php"><span class="menu-icon">▦</span> Kategori</a>
        <a href="ruangan.php"><span class="menu-icon">⌘</span> Data Ruangan</a>
        <a href="alat.php"><span class="menu-icon">▣</span> Data Sarana</a>
        <a href="pengguna.php"><span class="menu-icon">♙</span> Data Pengguna</a>
    </nav>

    <div class="menu-title">TRANSAKSI</div>
    <nav class="menu">
        <a href="peminjaman.php"><span class="menu-icon">⇄</span> Peminjaman</a>
        <a href="pengembalian.php" class="active"><span class="menu-icon">↩</span> Pengembalian</a>
        <a href="sanksi.php"><span class="menu-icon">◷</span> Sanksi Keterlambatan</a>
        <a href="laporan.php"><span class="menu-icon">▤</span> Laporan</a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="avatar">A</div>
            <div>
                <div class="profile-name"><?= e($namaAdmin) ?></div>
                <div class="profile-role">Administrator</div>
            </div>
        </div>
        <a class="logout" href="../logout.php" onclick="return confirm('Yakin ingin keluar?')">↪ &nbsp; Keluar dari akun</a>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div>
            <div class="breadcrumb">SIPRAS / Transaksi / Pengembalian</div>
            <h1>Pengembalian</h1>
            <p class="subtitle">Kelola pengembalian sarana sekolah dan keterlambatan.</p>
        </div>
        <div class="date-badge">◷ &nbsp; <?= date('d-m-Y') ?></div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $error ? 'error' : '' ?>"><?= e($pesan) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">⇄</div>
            <div>
                <div class="stat-label">Sedang Dipinjam</div>
                <div class="stat-value"><?= $totalAktif ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">Pengembalian Selesai</div>
                <div class="stat-value"><?= $totalSelesai ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">◷</div>
            <div>
                <div class="stat-label">Terlambat Belum Kembali</div>
                <div class="stat-value"><?= $totalTerlambat ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">Rp</div>
            <div>
                <div class="stat-label">Total Denda Pengembalian</div>
                <div class="stat-value" style="font-size:19px">
                    Rp<?= number_format($totalDenda, 0, ',', '.') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="toolbar">
            <div>
                <h2>Daftar Peminjaman</h2>
                <p>Pilih peminjaman aktif untuk mencatat pengembalian.</p>
            </div>
            <form method="GET" action="pengembalian.php" class="search-form">
                <input type="search" name="cari" placeholder="Cari kode atau nama peminjam..." value="<?= e($cari) ?>">
                <select class="filter" name="filter">
                    <option value="semua" <?= $filter === 'semua' ? 'selected' : '' ?>>Semua status</option>
                    <option value="aktif" <?= $filter === 'aktif' ? 'selected' : '' ?>>Belum kembali</option>
                    <option value="terlambat" <?= $filter === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="selesai" <?= $filter === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                </select>
                <button type="submit" class="btn primary">Cari</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Peminjam</th>
                        <th>Batas Kembali</th>
                        <th>Waktu Kembali</th>
                        <th>Denda</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($hasil->num_rows > 0): ?>
                    <?php $no = 1; ?>
                    <?php while ($row = $hasil->fetch_assoc()): ?>
                        <?php
                        $batas = strtotime(
                            $row['tanggal_rencana_kembali'] . ' ' .
                            $row['jam_rencana_kembali']
                        );

                        $lewatBatas = !$row['waktu_kembali']
                            && $batas !== false
                            && time() > $batas
                            && in_array(
                                $row['status_pinjam'],
                                ['sedang_dipinjam', 'terlambat'],
                                true
                            );
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><span class="code"><?= e($row['kode_peminjaman']) ?></span></td>
                            <td><strong><?= e($row['nama_peminjam']) ?></strong></td>
                            <td>
                                <?php if ($batas !== false): ?>
                                    <?= e(date('d/m/Y', $batas)) ?><br>
                                    <span style="color:#8490a5"><?= e(date('H:i', $batas)) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['waktu_kembali']): ?>
                                    <?= e(date('d/m/Y', strtotime($row['waktu_kembali']))) ?><br>
                                    <span style="color:#8490a5"><?= e(date('H:i', strtotime($row['waktu_kembali']))) ?></span>
                                <?php else: ?>
                                    <span style="color:#98a3b5">Belum kembali</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['waktu_kembali']): ?>
                                    Rp<?= number_format((float)$row['denda'], 0, ',', '.') ?>
                                <?php elseif ($lewatBatas): ?>
                                    <span style="color:#b52c2c">Menunggu pengembalian</span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['waktu_kembali']): ?>
                                    <span class="badge">Selesai</span>
                                <?php elseif ($lewatBatas): ?>
                                    <span class="badge danger-badge">Terlambat</span>
                                <?php else: ?>
                                    <span class="badge warning">Dipinjam</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (
                                    in_array(
                                        $row['status_pinjam'],
                                        ['sedang_dipinjam', 'terlambat'],
                                        true
                                    ) && !$row['waktu_kembali']
                                ): ?>
                                    <button
                                        class="btn primary"
                                        type="button"
                                        onclick="bukaForm(
                                            <?= (int)$row['id_peminjaman'] ?>,
                                            <?= json_encode($row['kode_peminjaman']) ?>,
                                            <?= json_encode($row['nama_peminjam']) ?>
                                        )"
                                    >Proses</button>
                                <?php else: ?>
                                    <span style="color:#8490a5">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">Tidak ada data peminjaman yang cocok.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" id="formPengembalian" style="display:none">
        <div class="toolbar">
            <div>
                <h2>Proses Pengembalian</h2>
                <p>Periksa kondisi barang sebelum menyimpan data.</p>
            </div>
            <button type="button" class="btn secondary" onclick="tutupForm()">Tutup</button>
        </div>

        <div id="infoPeminjaman" style="margin-bottom:18px;color:#536581;font-size:13px"></div>

        <form method="POST" action="pengembalian.php"
              onsubmit="return confirm('Yakin data pengembalian sudah benar?')">
            <input type="hidden" name="aksi" value="proses">
            <input type="hidden" name="id_peminjaman" id="id_peminjaman">

            <div class="modal-form">
                <div>
                    <label for="kondisi_akhir">Kondisi Barang *</label>
                    <select id="kondisi_akhir" name="kondisi_akhir" required>
                        <option value="baik">Baik</option>
                        <option value="perlu_perbaikan">Perlu Perbaikan</option>
                        <option value="rusak">Rusak</option>
                        <option value="hilang">Hilang</option>
                    </select>
                    <p class="help">Stok otomatis ditambahkan hanya jika kondisi barang baik.</p>
                </div>

                <div>
                    <label for="catatan">Catatan Pemeriksaan</label>
                    <textarea id="catatan" name="catatan" maxlength="500"
                              placeholder="Catatan kondisi barang (opsional)"></textarea>
                </div>

                <div class="full">
                    <div class="alert" style="margin:0">
                        Denda dihitung otomatis berdasarkan waktu kembali dan batas pengembalian.
                        Tarif Rp<?= number_format($tarifPerJam, 0, ',', '.') ?> per jam,
                        dibulatkan ke atas.
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px">
                <button type="submit" class="btn primary">Simpan Pengembalian</button>
                <button type="button" class="btn secondary" onclick="tutupForm()">Batal</button>
            </div>
        </form>
    </section>

    <footer class="footer">
        SIPRAS — Sistem Peminjaman Sarana dan Prasarana<br>
        Panel Administrator
    </footer>
</main>

<script>
function bukaForm(id, kode, nama) {
    document.getElementById('id_peminjaman').value = id;
    document.getElementById('infoPeminjaman').textContent =
        'Kode: ' + kode + ' | Peminjam: ' + nama;

    document.getElementById('formPengembalian').style.display = 'block';

    document.getElementById('formPengembalian').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}

function tutupForm() {
    document.getElementById('formPengembalian').style.display = 'none';
}
</script>

</body>
</html>
