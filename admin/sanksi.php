
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

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$namaAdmin = $_SESSION['username'] ?? $_SESSION['nama'] ?? 'Administrator';
$pesan = '';
$error = false;
$modeEdit = false;

$jenisSanksi = [
    'peringatan_1' => 'Peringatan Pertama',
    'peringatan_2' => 'Peringatan Kedua',
    'penangguhan'  => 'Penangguhan Peminjaman',
    'denda'        => 'Denda Keterlambatan',
    'kerusakan'    => 'Kerusakan Barang',
    'kehilangan'   => 'Kehilangan Barang',
    'lainnya'      => 'Lainnya'
];

$statusSanksi = [
    'dicatat' => 'Dicatat',
    'dalam_proses' => 'Dalam Proses',
    'selesai' => 'Selesai',
    'dibatalkan' => 'Dibatalkan'
];

$dataEdit = [
    'id_sanksi' => 0,
    'id_peminjaman' => '',
    'jenis_sanksi' => 'peringatan_1',
    'keterangan' => '',
    'nominal' => '0',
    'status' => 'dicatat'
];

/* TAMBAH, EDIT, DAN HAPUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah' || $aksi === 'edit') {
        $id = (int)($_POST['id_sanksi'] ?? 0);
        $idPeminjaman = (int)($_POST['id_peminjaman'] ?? 0);
        $jenis = $_POST['jenis_sanksi'] ?? '';
        $keterangan = trim($_POST['keterangan'] ?? '');
        $nominalInput = trim($_POST['nominal'] ?? '0');
        $status = $_POST['status'] ?? 'dicatat';

        $dataEdit = [
            'id_sanksi' => $id,
            'id_peminjaman' => $idPeminjaman,
            'jenis_sanksi' => $jenis,
            'keterangan' => $keterangan,
            'nominal' => $nominalInput,
            'status' => $status
        ];

        $modeEdit = ($aksi === 'edit');

        if ($idPeminjaman <= 0) {
            $pesan = 'Pilih kode peminjaman terlebih dahulu.';
            $error = true;
        } elseif ($aksi === 'edit' && $id <= 0) {
            $pesan = 'ID sanksi tidak valid.';
            $error = true;
        } elseif (!array_key_exists($jenis, $jenisSanksi)) {
            $pesan = 'Jenis sanksi tidak valid.';
            $error = true;
        } elseif (!array_key_exists($status, $statusSanksi)) {
            $pesan = 'Status sanksi tidak valid.';
            $error = true;
        } elseif ($keterangan === '') {
            $pesan = 'Keterangan sanksi wajib diisi.';
            $error = true;
        } elseif (strlen($keterangan) > 5000) {
            $pesan = 'Keterangan terlalu panjang.';
            $error = true;
        } elseif (
            !is_numeric($nominalInput) ||
            (float)$nominalInput < 0
        ) {
            $pesan = 'Nominal harus berupa angka nol atau lebih.';
            $error = true;
        } elseif ((float)$nominalInput > 9999999999.99) {
            $pesan = 'Nominal terlalu besar.';
            $error = true;
        } else {
            try {
                $cek = $koneksi->prepare(
                    "SELECT id_peminjaman
                     FROM peminjaman
                     WHERE id_peminjaman = ?"
                );
                $cek->bind_param('i', $idPeminjaman);
                $cek->execute();
                $peminjamanAda = $cek->get_result()->fetch_assoc();
                $cek->close();

                if (!$peminjamanAda) {
                    throw new Exception(
                        'Data peminjaman tidak ditemukan.'
                    );
                }

                $nominal = (float)$nominalInput;
                $idPetugas = (int)$_SESSION['id_user'];

                if ($aksi === 'tambah') {
                    $stmt = $koneksi->prepare("
                        INSERT INTO sanksi
                        (
                            id_peminjaman,
                            jenis_sanksi,
                            keterangan,
                            nominal,
                            status,
                            id_petugas
                        )
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        'issdsi',
                        $idPeminjaman,
                        $jenis,
                        $keterangan,
                        $nominal,
                        $status,
                        $idPetugas
                    );
                } else {
                    $stmt = $koneksi->prepare("
                        UPDATE sanksi
                        SET
                            id_peminjaman = ?,
                            jenis_sanksi = ?,
                            keterangan = ?,
                            nominal = ?,
                            status = ?
                        WHERE id_sanksi = ?
                    ");

                    $stmt->bind_param(
                        'issdsi',
                        $idPeminjaman,
                        $jenis,
                        $keterangan,
                        $nominal,
                        $status,
                        $id
                    );
                }

                $stmt->execute();
                $stmt->close();

                header('Location: sanksi.php?status=' . $aksi);
                exit;

            } catch (Throwable $ex) {
                $pesan = $ex instanceof mysqli_sql_exception
                    ? 'Gagal menyimpan sanksi. Periksa struktur tabel database.'
                    : $ex->getMessage();

                $error = true;
            }
        }

    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id_sanksi'] ?? 0);

        try {
            $stmt = $koneksi->prepare(
                "DELETE FROM sanksi WHERE id_sanksi = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            header('Location: sanksi.php?status=hapus');
            exit;

        } catch (Throwable $ex) {
            $pesan = 'Sanksi gagal dihapus. Periksa kembali database.';
            $error = true;
        }
    }
}

/* NOTIFIKASI */
if (isset($_GET['status'])) {
    $notifikasi = [
        'tambah' => 'Sanksi berhasil ditambahkan.',
        'edit' => 'Data sanksi berhasil diperbarui.',
        'hapus' => 'Sanksi berhasil dihapus.'
    ];

    if (isset($notifikasi[$_GET['status']])) {
        $pesan = $notifikasi[$_GET['status']];
        $error = false;
    }
}

/* DATA UNTUK EDIT */
if (!$modeEdit && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $koneksi->prepare("
        SELECT
            id_sanksi,
            id_peminjaman,
            jenis_sanksi,
            keterangan,
            nominal,
            status
        FROM sanksi
        WHERE id_sanksi = ?
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rowEdit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rowEdit) {
        $dataEdit = $rowEdit;
        $modeEdit = true;
    }
}

/* DAFTAR PEMINJAMAN UNTUK FORM */
$daftarPeminjaman = $koneksi->query("
    SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        u.nama
    FROM peminjaman p
    LEFT JOIN users u ON u.id_user = p.id_user
    ORDER BY p.id_peminjaman DESC
");

/* PENCARIAN DAN FILTER */
$cari = trim($_GET['cari'] ?? '');
$filter = $_GET['filter'] ?? 'semua';

$sql = "
    SELECT
        s.id_sanksi,
        s.id_peminjaman,
        s.jenis_sanksi,
        s.keterangan,
        s.nominal,
        s.status,
        s.dibuat_pada,
        p.kode_peminjaman,
        u.nama AS nama_peminjam
    FROM sanksi s
    LEFT JOIN peminjaman p
        ON p.id_peminjaman = s.id_peminjaman
    LEFT JOIN users u
        ON u.id_user = p.id_user
    WHERE 1=1
";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= "
        AND (
            p.kode_peminjaman LIKE ?
            OR u.nama LIKE ?
            OR s.keterangan LIKE ?
        )
    ";

    $keyword = '%' . $cari . '%';
    $types .= 'sss';
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if (array_key_exists($filter, $statusSanksi)) {
    $sql .= " AND s.status = ?";
    $types .= 's';
    $params[] = $filter;
}

$sql .= " ORDER BY s.id_sanksi DESC";

if (!empty($params)) {
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = $koneksi->query($sql);
}

/* STATISTIK */
$statistik = $koneksi->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'dicatat'), 0) AS dicatat,
        COALESCE(SUM(status = 'dalam_proses'), 0) AS proses,
        COALESCE(SUM(status = 'selesai'), 0) AS selesai,
        COALESCE(
            SUM(
                CASE
                    WHEN status != 'dibatalkan' THEN nominal
                    ELSE 0
                END
            ),
            0
        ) AS total_nominal
    FROM sanksi
")->fetch_assoc();

$totalSanksi = (int)$statistik['total'];
$totalDicatat = (int)$statistik['dicatat'];
$totalProses = (int)$statistik['proses'];
$totalSelesai = (int)$statistik['selesai'];
$totalNominal = (float)$statistik['total_nominal'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sanksi Keterlambatan | SIPRAS</title>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7fc;color:#263653}
.sidebar{position:fixed;inset:0 auto 0 0;display:flex;flex-direction:column;width:250px;padding:26px 18px;background:#12284b;color:white;overflow-y:auto}
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
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:22px}
.stat-card{display:flex;align-items:center;gap:13px;padding:18px;border:1px solid #e9edf5;border-radius:15px;background:white}
.stat-icon{display:grid;place-items:center;width:44px;height:44px;flex-shrink:0;border-radius:13px;background:#e9f0ff;color:#3268e9;font-size:21px}
.stat-label{color:#8490a5;font-size:11px}
.stat-value{margin-top:6px;color:#12284b;font-size:23px;font-weight:800}
.card{padding:24px;margin-bottom:22px;border:1px solid #e9edf5;border-radius:15px;background:white;box-shadow:0 3px 12px #1e375a08}
.card-heading{margin-bottom:20px}
.card-heading h2,.toolbar h2{color:#12284b;font-size:17px}
.card-heading p,.toolbar p{margin-top:6px;color:#8490a5;font-size:12px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
label{display:block;margin-bottom:8px;font-size:12px;font-weight:bold}
input,textarea,select{width:100%;padding:12px 13px;border:1px solid #dce3ee;border-radius:9px;outline:none;background:white;color:#263653;font:inherit;font-size:12px}
input:focus,textarea:focus,select:focus{border-color:#3268e9;box-shadow:0 0 0 3px #3268e91a}
textarea{min-height:100px;resize:vertical}
.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:20px}
.btn{display:inline-block;padding:11px 14px;border:0;border-radius:8px;cursor:pointer;text-decoration:none;text-align:center;font-size:12px;font-weight:bold;white-space:nowrap}
.primary{background:#3268e9;color:white}
.secondary{background:#edf1f7;color:#42516a}
.danger{background:#ffebeb;color:#b52c2c}
.alert{padding:14px 16px;margin-bottom:20px;border-radius:9px;background:#e4f7eb;color:#207443;font-size:13px}
.alert.error{background:#fee9e9;color:#a82c2c}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}
.search-form{display:flex;gap:8px;width:min(100%,430px)}
.search-form input{min-width:100px}
.filter{max-width:150px}
.table-wrap{overflow-x:auto}
table{width:100%;min-width:1050px;border-collapse:collapse}
th,td{padding:14px 12px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:middle}
th{background:#f7f9fc;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
td{font-size:12px;line-height:1.7}
.code{color:#2459bc;font-weight:800}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#e7f7ed;color:#247344;font-size:10px;font-weight:bold;white-space:nowrap}
.badge.warning{background:#fff2d9;color:#9a6700}
.badge.danger-badge{background:#ffebeb;color:#b52c2c}
.badge.neutral{background:#edf0f5;color:#687386}
.row-actions{display:flex;gap:7px}
.row-actions form{margin:0}
.row-actions .btn{padding:8px 10px}
.number{display:inline-block;padding:5px 9px;border-radius:6px;background:#eaf1ff;color:#2858a5;font-weight:bold}
.empty{padding:35px;color:#8490a5;text-align:center}
.help{margin-top:7px;color:#8490a5;font-size:11px;line-height:1.6}
.footer{padding:20px 0;color:#98a3b5;font-size:11px;line-height:1.8;text-align:center}
@media(max-width:1250px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:950px){.main{padding:22px}.toolbar{align-items:stretch;flex-direction:column}.search-form{width:100%}}
@media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.sidebar-bottom{margin-top:20px}.main{width:100%;margin-left:0;padding:18px}.date-badge{display:none}.form-grid{grid-template-columns:1fr}}
@media(max-width:430px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>

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
        <a href="dashboard.php"><span class="menu-icon">⌂</span> Dashboard</a>
        <a href="kategori.php"><span class="menu-icon">▦</span> Kategori</a>
        <a href="ruangan.php"><span class="menu-icon">⌘</span> Data Ruangan</a>
        <a href="alat.php"><span class="menu-icon">▣</span> Data Sarana</a>
        <a href="pengguna.php"><span class="menu-icon">♙</span> Data Pengguna</a>
    </nav>

    <div class="menu-title">TRANSAKSI</div>
    <nav class="menu">
        <a href="peminjaman.php"><span class="menu-icon">⇄</span> Peminjaman</a>
        <a href="pengembalian.php"><span class="menu-icon">↩</span> Pengembalian</a>
        <a href="sanksi.php" class="active"><span class="menu-icon">◷</span> Sanksi Keterlambatan</a>
        <a href="laporan.php"><span class="menu-icon">▤</span> Laporan</a>
    </nav>

    <!-- MENU LOG AKTIVITAS DITAMBAHKAN -->
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
                <div class="profile-name"><?= e($namaAdmin) ?></div>
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
            <div class="breadcrumb">SIPRAS / Transaksi / Sanksi</div>
            <h1>Sanksi Keterlambatan</h1>
            <p class="subtitle">
                Kelola sanksi dan tindak lanjut pelanggaran peminjaman.
            </p>
        </div>
        <div class="date-badge">◷ &nbsp; <?= date('d-m-Y') ?></div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $error ? 'error' : '' ?>">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">▤</div>
            <div>
                <div class="stat-label">Total Sanksi</div>
                <div class="stat-value"><?= $totalSanksi ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">◷</div>
            <div>
                <div class="stat-label">Dicatat</div>
                <div class="stat-value"><?= $totalDicatat ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">⇄</div>
            <div>
                <div class="stat-label">Dalam Proses</div>
                <div class="stat-value"><?= $totalProses ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">Rp</div>
            <div>
                <div class="stat-label">Total Nominal Aktif</div>
                <div class="stat-value" style="font-size:19px">
                    Rp<?= number_format($totalNominal, 0, ',', '.') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-heading">
            <h2><?= $modeEdit ? 'Edit Data Sanksi' : 'Tambah Sanksi Baru' ?></h2>
            <p>Pilih transaksi peminjaman dan masukkan informasi sanksi.</p>
        </div>

        <form method="POST" action="sanksi.php">
            <input
                type="hidden"
                name="aksi"
                value="<?= $modeEdit ? 'edit' : 'tambah' ?>"
            >

            <?php if ($modeEdit): ?>
                <input
                    type="hidden"
                    name="id_sanksi"
                    value="<?= (int)$dataEdit['id_sanksi'] ?>"
                >
            <?php endif; ?>

            <div class="form-grid">
                <div>
                    <label for="id_peminjaman">Kode Peminjaman *</label>
                    <select name="id_peminjaman" id="id_peminjaman" required>
                        <option value="">-- Pilih peminjaman --</option>
                        <?php while ($p = $daftarPeminjaman->fetch_assoc()): ?>
                            <option
                                value="<?= (int)$p['id_peminjaman'] ?>"
                                <?= (int)$dataEdit['id_peminjaman'] === (int)$p['id_peminjaman'] ? 'selected' : '' ?>
                            >
                                <?= e($p['kode_peminjaman'] ?: 'Peminjaman #' . $p['id_peminjaman']) ?>
                                — <?= e($p['nama'] ?: 'Pengguna tidak ditemukan') ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label for="jenis_sanksi">Jenis Sanksi *</label>
                    <select name="jenis_sanksi" id="jenis_sanksi" required>
                        <?php foreach ($jenisSanksi as $nilai => $labelJenis): ?>
                            <option
                                value="<?= e($nilai) ?>"
                                <?= $dataEdit['jenis_sanksi'] === $nilai ? 'selected' : '' ?>
                            >
                                <?= e($labelJenis) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="nominal">Nominal Sanksi (Rp) *</label>
                    <input
                        type="number"
                        id="nominal"
                        name="nominal"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        required
                        value="<?= e($dataEdit['nominal']) ?>"
                        placeholder="Contoh: 10000"
                    >
                    <p class="help">
                        Untuk sanksi nonfinansial, isi 0.
                    </p>
                </div>

                <div>
                    <label for="status">Status Sanksi *</label>
                    <select name="status" id="status" required>
                        <?php foreach ($statusSanksi as $nilai => $labelStatus): ?>
                            <option
                                value="<?= e($nilai) ?>"
                                <?= $dataEdit['status'] === $nilai ? 'selected' : '' ?>
                            >
                                <?= e($labelStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="grid-column:1/-1">
                    <label for="keterangan">Keterangan Sanksi *</label>
                    <textarea
                        id="keterangan"
                        name="keterangan"
                        maxlength="5000"
                        required
                        placeholder="Tuliskan alasan dan rincian sanksi..."
                    ><?= e($dataEdit['keterangan']) ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary">
                    <?= $modeEdit ? 'Simpan Perubahan' : '+ Tambah Sanksi' ?>
                </button>

                <?php if ($modeEdit): ?>
                    <a href="sanksi.php" class="btn secondary">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="toolbar">
            <div>
                <h2>Daftar Sanksi</h2>
                <p>Riwayat sanksi yang tersimpan di database.</p>
            </div>

            <form method="GET" action="sanksi.php" class="search-form">
                <input
                    type="search"
                    name="cari"
                    placeholder="Cari kode, nama, keterangan..."
                    value="<?= e($cari) ?>"
                >

                <select name="filter" class="filter">
                    <option value="semua">Semua status</option>
                    <?php foreach ($statusSanksi as $nilai => $labelStatus): ?>
                        <option
                            value="<?= e($nilai) ?>"
                            <?= $filter === $nilai ? 'selected' : '' ?>
                        >
                            <?= e($labelStatus) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn primary">Cari</button>

                <?php if ($cari !== '' || $filter !== 'semua'): ?>
                    <a href="sanksi.php" class="btn secondary">Reset</a>
                <?php endif; ?>
            </form>
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
                        <th>Tanggal</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($hasil && $hasil->num_rows > 0): ?>
                    <?php $no = 1; ?>

                    <?php while ($row = $hasil->fetch_assoc()): ?>
                        <?php
                        $badgeClass = 'neutral';

                        if ($row['status'] === 'selesai') {
                            $badgeClass = '';
                        } elseif ($row['status'] === 'dicatat') {
                            $badgeClass = 'warning';
                        } elseif ($row['status'] === 'dibatalkan') {
                            $badgeClass = 'danger-badge';
                        }
                        ?>

                        <tr>
                            <td>
                                <span class="number"><?= $no++ ?></span>
                            </td>

                            <td>
                                <span class="code">
                                    <?= e(
                                        $row['kode_peminjaman']
                                        ?: 'Peminjaman #' . $row['id_peminjaman']
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <?= e(
                                        $row['nama_peminjam']
                                        ?: 'Pengguna tidak ditemukan'
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e(
                                    $jenisSanksi[$row['jenis_sanksi']]
                                    ?? $row['jenis_sanksi']
                                ) ?>
                            </td>

                            <td><?= e($row['keterangan']) ?></td>

                            <td>
                                <strong>
                                    Rp<?= number_format(
                                        (float)$row['nominal'],
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <span class="badge <?= e($badgeClass) ?>">
                                    <?= e(
                                        $statusSanksi[$row['status']]
                                        ?? $row['status']
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                echo !empty($row['dibuat_pada'])
                                    ? e(date(
                                        'd/m/Y',
                                        strtotime($row['dibuat_pada'])
                                    ))
                                    : '-';
                                ?>
                            </td>

                            <td>
                                <div class="row-actions">
                                    <a
                                        class="btn secondary"
                                        href="sanksi.php?edit=<?= (int)$row['id_sanksi'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="sanksi.php"
                                        onsubmit="return confirm('Yakin ingin menghapus sanksi ini?')"
                                    >
                                        <input
                                            type="hidden"
                                            name="aksi"
                                            value="hapus"
                                        >

                                        <input
                                            type="hidden"
                                            name="id_sanksi"
                                            value="<?= (int)$row['id_sanksi'] ?>"
                                        >

                                        <button type="submit" class="btn danger">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty">
                            <?php
                            echo $cari !== ''
                                ? 'Data sanksi tidak ditemukan.'
                                : 'Belum ada data sanksi.';
                            ?>
                        </td>
                    </tr>
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

</body>
</html>
