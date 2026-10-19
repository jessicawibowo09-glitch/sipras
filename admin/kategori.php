
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

function catatLogKategori($koneksi, $aktivitas, $detail) {
    try {
        $idUser = (int)($_SESSION['id_user'] ?? 0);
        $namaAdmin = $_SESSION['nama']
            ?? $_SESSION['username']
            ?? 'Administrator';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $namaTabel = 'kategori';

        $stmtLog = $koneksi->prepare(
            "INSERT INTO log_aktivitas
            (id_user, aktivitas, detail, alamat_ip, nama_admin, nama_tabel)
            VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmtLog->bind_param(
            'isssss',
            $idUser,
            $aktivitas,
            $detail,
            $ip,
            $namaAdmin,
            $namaTabel
        );

        $stmtLog->execute();
        $stmtLog->close();
    } catch (Throwable $err) {
        // Kegagalan pencatatan log tidak membatalkan penyimpanan kategori.
    }
}

$pesan = '';
$jenisPesan = 'success';
$modeEdit = false;

$dataEdit = [
    'id_kategori' => 0,
    'nama_kategori' => '',
    'deskripsi' => ''
];

// PROSES TAMBAH, EDIT, DAN HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah' || $aksi === 'edit') {
        $nama = trim($_POST['nama_kategori'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $id = (int)($_POST['id_kategori'] ?? 0);

        $dataEdit = [
            'id_kategori' => $id,
            'nama_kategori' => $nama,
            'deskripsi' => $deskripsi
        ];

        $modeEdit = ($aksi === 'edit');

        if ($nama === '') {
            $pesan = 'Nama kategori wajib diisi.';
            $jenisPesan = 'error';
        } elseif (mb_strlen($nama, 'UTF-8') > 80) {
            $pesan = 'Nama kategori maksimal 80 karakter.';
            $jenisPesan = 'error';
        } elseif ($aksi === 'edit' && $id <= 0) {
            $pesan = 'ID kategori tidak valid.';
            $jenisPesan = 'error';
        } else {
            try {
                if ($aksi === 'tambah') {
                    $stmt = $koneksi->prepare(
                        "INSERT INTO kategori (nama_kategori, deskripsi)
                         VALUES (?, ?)"
                    );

                    $stmt->bind_param('ss', $nama, $deskripsi);
                    $stmt->execute();

                    $idBaru = (int)$koneksi->insert_id;
                    $stmt->close();

                    catatLogKategori(
                        $koneksi,
                        'tambah',
                        "Admin menambahkan kategori: {$nama} (ID: {$idBaru})"
                    );
                } else {
                    $stmtCek = $koneksi->prepare(
                        "SELECT nama_kategori
                         FROM kategori
                         WHERE id_kategori = ?"
                    );

                    $stmtCek->bind_param('i', $id);
                    $stmtCek->execute();
                    $kategoriLama = $stmtCek->get_result()->fetch_assoc();
                    $stmtCek->close();

                    if (!$kategoriLama) {
                        throw new RuntimeException(
                            'Kategori yang akan diedit tidak ditemukan.'
                        );
                    }

                    $namaLama = $kategoriLama['nama_kategori'];

                    $stmt = $koneksi->prepare(
                        "UPDATE kategori
                         SET nama_kategori = ?, deskripsi = ?
                         WHERE id_kategori = ?"
                    );

                    $stmt->bind_param('ssi', $nama, $deskripsi, $id);
                    $stmt->execute();
                    $stmt->close();

                    catatLogKategori(
                        $koneksi,
                        'edit',
                        "Admin mengubah kategori '{$namaLama}' menjadi '{$nama}' (ID: {$id})"
                    );
                }

                header('Location: kategori.php?status=' . $aksi);
                exit;

            } catch (mysqli_sql_exception $err) {
                if ((int)$err->getCode() === 1062) {
                    $pesan = 'Nama kategori sudah digunakan.';
                } else {
                    $pesan = 'Gagal menyimpan kategori. Periksa database.';
                }

                $jenisPesan = 'error';

            } catch (RuntimeException $err) {
                $pesan = $err->getMessage();
                $jenisPesan = 'error';
            }
        }

    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id_kategori'] ?? 0);

        if ($id <= 0) {
            $pesan = 'ID kategori tidak valid.';
            $jenisPesan = 'error';
        } else {
            try {
                $stmtCek = $koneksi->prepare(
                    "SELECT nama_kategori
                     FROM kategori
                     WHERE id_kategori = ?"
                );

                $stmtCek->bind_param('i', $id);
                $stmtCek->execute();
                $kategori = $stmtCek->get_result()->fetch_assoc();
                $stmtCek->close();

                if (!$kategori) {
                    $pesan = 'Kategori tidak ditemukan.';
                    $jenisPesan = 'error';
                } else {
                    $namaKategori = $kategori['nama_kategori'];

                    $stmt = $koneksi->prepare(
                        "DELETE FROM kategori WHERE id_kategori = ?"
                    );

                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $berhasilHapus = $stmt->affected_rows > 0;
                    $stmt->close();

                    if ($berhasilHapus) {
                        catatLogKategori(
                            $koneksi,
                            'hapus',
                            "Admin menghapus kategori: {$namaKategori} (ID: {$id})"
                        );

                        header('Location: kategori.php?status=hapus');
                        exit;
                    }

                    $pesan = 'Kategori tidak berhasil dihapus.';
                    $jenisPesan = 'error';
                }
            } catch (mysqli_sql_exception $err) {
                $pesan = 'Kategori tidak dapat dihapus karena mungkin masih digunakan oleh data sarana.';
                $jenisPesan = 'error';
            }
        }
    }
}

// NOTIFIKASI
if (isset($_GET['status'])) {
    $daftarPesan = [
        'tambah' => 'Kategori berhasil ditambahkan.',
        'edit' => 'Kategori berhasil diperbarui.',
        'hapus' => 'Kategori berhasil dihapus.'
    ];

    $status = $_GET['status'];

    if (isset($daftarPesan[$status])) {
        $pesan = $daftarPesan[$status];
        $jenisPesan = 'success';
    }
}

// DATA UNTUK EDIT
if (!$modeEdit && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    if ($id > 0) {
        $stmt = $koneksi->prepare(
            "SELECT id_kategori, nama_kategori, deskripsi
             FROM kategori
             WHERE id_kategori = ?"
        );

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($data) {
            $dataEdit = $data;
            $modeEdit = true;
        } else {
            $pesan = 'Kategori tidak ditemukan.';
            $jenisPesan = 'error';
        }
    }
}

// PENCARIAN
$cari = trim($_GET['cari'] ?? '');

if ($cari !== '') {
    $stmt = $koneksi->prepare(
        "SELECT id_kategori, nama_kategori, deskripsi, dibuat_pada
         FROM kategori
         WHERE nama_kategori LIKE ? OR deskripsi LIKE ?
         ORDER BY id_kategori DESC"
    );

    $kataCari = '%' . $cari . '%';
    $stmt->bind_param('ss', $kataCari, $kataCari);
    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = $koneksi->query(
        "SELECT id_kategori, nama_kategori, deskripsi, dibuat_pada
         FROM kategori
         ORDER BY id_kategori DESC"
    );
}

// STATISTIK
$totalKategori = (int)$koneksi
    ->query("SELECT COUNT(*) AS total FROM kategori")
    ->fetch_assoc()['total'];

$namaAdmin = $_SESSION['nama']
    ?? $_SESSION['username']
    ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Kategori | SIPRAS</title>

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
.menu a{display:flex;align-items:center;gap:12px;padding:12px 13px;border-radius:10px;color:#d5def0;text-decoration:none;font-size:13px;transition:.2s}
.menu a:hover,.menu a.active{background:#294b83;color:white}
.menu a.active{box-shadow:inset 3px 0 #76a7ff}
.menu-icon{width:21px;text-align:center;font-size:17px}
.sidebar-bottom{margin-top:auto;padding-top:24px}
.profile{display:flex;align-items:center;gap:10px;padding:13px 9px;border-top:1px solid #314360}
.avatar{display:grid;place-items:center;width:38px;height:38px;flex-shrink:0;border-radius:12px;background:#e3ecff;color:#2454bc;font-weight:bold}
.profile-name{max-width:140px;overflow-wrap:anywhere;font-size:12px;font-weight:bold}
.profile-role{margin-top:4px;color:#b6c7e4;font-size:11px}
.logout{display:block;margin-top:8px;padding:11px;border:1px solid #405271;border-radius:9px;color:#ffe0e0;text-align:center;text-decoration:none;font-size:12px}
.main{width:calc(100% - 250px);margin-left:250px;padding:32px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:28px}
.breadcrumb{margin-bottom:8px;color:#8490a5;font-size:12px}
h1{color:#12284b;font-size:29px}
.subtitle{margin-top:8px;color:#8490a5;font-size:13px}
.date-badge{padding:12px 16px;border:1px solid #e5eaf3;border-radius:12px;background:white;color:#536581;font-size:12px;white-space:nowrap}
.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:22px}
.stat-card{display:flex;align-items:center;gap:16px;padding:20px;border:1px solid #e9edf5;border-radius:15px;background:white}
.stat-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:13px;background:#e9f0ff;color:#3268e9;font-size:23px}
.stat-label{color:#8490a5;font-size:12px}
.stat-value{margin-top:5px;color:#12284b;font-size:25px;font-weight:800}
.card{padding:24px;margin-bottom:22px;border:1px solid #e9edf5;border-radius:15px;background:white;box-shadow:0 3px 12px #1e375a08}
.card-heading{margin-bottom:20px}
.card-heading h2,.toolbar h2{color:#12284b;font-size:17px}
.card-heading p,.toolbar p{margin-top:6px;color:#8490a5;font-size:12px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
label{display:block;margin-bottom:8px;font-size:13px;font-weight:bold}
input,textarea{width:100%;padding:12px 13px;border:1px solid #dce3ee;border-radius:9px;outline:none;background:white;color:#263653;font:inherit;font-size:13px}
input:focus,textarea:focus{border-color:#3268e9;box-shadow:0 0 0 3px #3268e91a}
textarea{min-height:88px;resize:vertical}
.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.btn{display:inline-block;padding:11px 15px;border:0;border-radius:8px;cursor:pointer;text-decoration:none;text-align:center;font-size:12px;font-weight:bold}
.primary{background:#3268e9;color:white}
.primary:hover{background:#2456cb}
.secondary{background:#edf1f7;color:#42516a}
.danger{background:#ffebeb;color:#b52c2c}
.alert{padding:14px 16px;margin-bottom:20px;border-radius:9px;background:#e4f7eb;color:#207443;font-size:13px}
.alert.error{background:#fee9e9;color:#a82c2c}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}
.search-form{display:flex;gap:8px;width:min(100%,370px)}
.search-form input{min-width:0}
.table-wrap{overflow-x:auto}
table{width:100%;min-width:680px;border-collapse:collapse}
th,td{padding:14px 12px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:top}
th{background:#f7f9fc;color:#64748b;font-size:11px;text-transform:uppercase}
td{font-size:12px;line-height:1.7}
.number{display:inline-block;padding:5px 9px;border-radius:6px;background:#eaf1ff;color:#2858a5;font-weight:bold}
.row-actions{display:flex;align-items:center;gap:7px}
.row-actions form{margin:0}
.row-actions .btn{padding:8px 10px}
.empty{padding:30px;color:#8490a5;text-align:center}
.footer{padding:20px 0;color:#98a3b5;font-size:11px;line-height:1.8;text-align:center}
@media(max-width:1050px){.main{padding:22px}}
@media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.sidebar-bottom{margin-top:20px}.main{width:100%;margin-left:0;padding:18px}.form-grid{grid-template-columns:1fr}.toolbar{align-items:stretch;flex-direction:column}.search-form{width:100%}.date-badge{display:none}.stats{grid-template-columns:1fr}}
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
        <a class="active" href="kategori.php"><span class="menu-icon">▦</span> Kategori</a>
        <a href="ruangan.php"><span class="menu-icon">⌘</span> Data Ruangan</a>
        <a href="alat.php"><span class="menu-icon">▣</span> Data Sarana</a>
        <a href="pengguna.php"><span class="menu-icon">♙</span> Data Pengguna</a>
    </nav>

    <div class="menu-title">TRANSAKSI</div>
    <nav class="menu">
        <a href="peminjaman.php"><span class="menu-icon">⇄</span> Peminjaman</a>
        <a href="pengembalian.php"><span class="menu-icon">↩</span> Pengembalian</a>
        <a href="sanksi.php"><span class="menu-icon">◷</span> Sanksi Keterlambatan</a>
        <a href="laporan.php"><span class="menu-icon">▤</span> Laporan</a>
    </nav>

    <div class="menu-title">SISTEM</div>
    <nav class="menu">
        <a href="log_aktivitas.php"><span class="menu-icon">◉</span> Log Aktivitas</a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="avatar"><?= e(strtoupper(substr($namaAdmin, 0, 1))) ?></div>
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
            <div class="breadcrumb">SIPRAS / Menu Utama / Kategori</div>
            <h1>Data Kategori</h1>
            <p class="subtitle">Kelola kategori sarana dan prasarana sekolah.</p>
        </div>
        <div class="date-badge">◷ &nbsp; <?= date('d-m-Y') ?></div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $jenisPesan === 'error' ? 'error' : '' ?>">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">▦</div>
            <div>
                <div class="stat-label">Total Kategori</div>
                <div class="stat-value"><?= $totalKategori ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">Kategori Terdaftar</div>
                <div class="stat-value"><?= $totalKategori ?></div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-heading">
            <h2><?= $modeEdit ? 'Edit Kategori' : 'Tambah Kategori Baru' ?></h2>
            <p>Isi nama dan deskripsi kategori fasilitas sekolah.</p>
        </div>

        <form method="POST" action="kategori.php">
            <input type="hidden" name="aksi" value="<?= $modeEdit ? 'edit' : 'tambah' ?>">

            <?php if ($modeEdit): ?>
                <input type="hidden" name="id_kategori" value="<?= (int)$dataEdit['id_kategori'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div>
                    <label for="nama_kategori">Nama Kategori *</label>
                    <input
                        type="text"
                        id="nama_kategori"
                        name="nama_kategori"
                        required
                        maxlength="80"
                        placeholder="Contoh: Elektronik"
                        value="<?= e($dataEdit['nama_kategori']) ?>"
                    >
                </div>

                <div>
                    <label for="deskripsi">Deskripsi</label>
                    <textarea id="deskripsi" name="deskripsi" placeholder="Informasi kategori"><?= e($dataEdit['deskripsi']) ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary">
                    <?= $modeEdit ? 'Simpan Perubahan' : '+ Tambah Kategori' ?>
                </button>

                <?php if ($modeEdit): ?>
                    <a href="kategori.php" class="btn secondary">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="toolbar">
            <div>
                <h2>Daftar Kategori</h2>
                <p>Data kategori yang tersimpan di database.</p>
            </div>

            <form method="GET" action="kategori.php" class="search-form">
                <input type="search" name="cari" placeholder="Cari kategori..." value="<?= e($cari) ?>">
                <button type="submit" class="btn primary">Cari</button>
                <?php if ($cari !== ''): ?>
                    <a href="kategori.php" class="btn secondary">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kategori</th>
                        <th>Deskripsi</th>
                        <th>Dibuat Pada</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($hasil->num_rows > 0): ?>
                    <?php $no = 1; ?>
                    <?php while ($row = $hasil->fetch_assoc()): ?>
                        <tr>
                            <td><span class="number"><?= $no++ ?></span></td>
                            <td><strong><?= e($row['nama_kategori']) ?></strong></td>
                            <td><?= e($row['deskripsi']) ?: '-' ?></td>
                            <td><?= e($row['dibuat_pada']) ?: '-' ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn secondary" href="kategori.php?edit=<?= (int)$row['id_kategori'] ?>">Edit</a>

                                    <form method="POST" action="kategori.php" onsubmit="return confirm('Yakin ingin menghapus kategori ini?')">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_kategori" value="<?= (int)$row['id_kategori'] ?>">
                                        <button type="submit" class="btn danger">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty">
                            <?= $cari !== '' ? 'Kategori yang dicari tidak ditemukan.' : 'Belum ada data kategori.' ?>
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
