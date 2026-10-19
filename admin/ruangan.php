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

$pesan = '';
$error = false;
$modeEdit = false;

$dataEdit = [
    'id_ruangan' => 0,
    'kode_ruangan' => '',
    'nama_ruangan' => '',
    'jenis_ruangan' => 'lainnya',
    'lokasi_detail' => '',
    'penanggung_jawab' => '',
    'keterangan' => '',
    'aktif' => 1
];

$jenisRuangan = [
    'laboratorium' => 'Laboratorium',
    'kelas' => 'Kelas',
    'gudang' => 'Gudang',
    'kantor' => 'Kantor',
    'lainnya' => 'Lainnya'
];

// PROSES TAMBAH, EDIT, DAN HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah' || $aksi === 'edit') {
        $id = (int)($_POST['id_ruangan'] ?? 0);
        $kode = trim($_POST['kode_ruangan'] ?? '');
        $nama = trim($_POST['nama_ruangan'] ?? '');
        $jenis = $_POST['jenis_ruangan'] ?? 'lainnya';
        $lokasi = trim($_POST['lokasi_detail'] ?? '');
        $pj = trim($_POST['penanggung_jawab'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $aktif = isset($_POST['aktif']) ? 1 : 0;

        $dataEdit = [
            'id_ruangan' => $id,
            'kode_ruangan' => $kode,
            'nama_ruangan' => $nama,
            'jenis_ruangan' => $jenis,
            'lokasi_detail' => $lokasi,
            'penanggung_jawab' => $pj,
            'keterangan' => $keterangan,
            'aktif' => $aktif
        ];

        $modeEdit = ($aksi === 'edit');

        if ($kode === '' || $nama === '') {
            $pesan = 'Kode dan nama ruangan wajib diisi.';
            $error = true;
        } elseif (strlen($kode) > 20) {
            $pesan = 'Kode ruangan maksimal 20 karakter.';
            $error = true;
        } elseif (strlen($nama) > 100) {
            $pesan = 'Nama ruangan maksimal 100 karakter.';
            $error = true;
        } elseif (!array_key_exists($jenis, $jenisRuangan)) {
            $pesan = 'Jenis ruangan tidak valid.';
            $error = true;
        } elseif (strlen($lokasi) > 150 || strlen($pj) > 100) {
            $pesan = 'Lokasi maksimal 150 karakter dan penanggung jawab maksimal 100 karakter.';
            $error = true;
        } elseif ($aksi === 'edit' && $id <= 0) {
            $pesan = 'ID ruangan tidak valid.';
            $error = true;
        } else {
            try {
                if ($aksi === 'tambah') {
                    $stmt = $koneksi->prepare(
                        "INSERT INTO ruangan
                        (kode_ruangan, nama_ruangan, jenis_ruangan,
                         lokasi_detail, penanggung_jawab, keterangan, aktif)
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        'ssssssi',
                        $kode,
                        $nama,
                        $jenis,
                        $lokasi,
                        $pj,
                        $keterangan,
                        $aktif
                    );
                } else {
                    $stmt = $koneksi->prepare(
                        "UPDATE ruangan SET
                            kode_ruangan = ?,
                            nama_ruangan = ?,
                            jenis_ruangan = ?,
                            lokasi_detail = ?,
                            penanggung_jawab = ?,
                            keterangan = ?,
                            aktif = ?
                         WHERE id_ruangan = ?"
                    );

                    $stmt->bind_param(
                        'ssssssii',
                        $kode,
                        $nama,
                        $jenis,
                        $lokasi,
                        $pj,
                        $keterangan,
                        $aktif,
                        $id
                    );
                }

                $stmt->execute();
                $stmt->close();

                header('Location: ruangan.php?status=' . $aksi);
                exit;

            } catch (mysqli_sql_exception $ex) {
                if ((int)$ex->getCode() === 1062) {
                    $pesan = 'Kode ruangan sudah digunakan. Gunakan kode lain.';
                } else {
                    $pesan = 'Gagal menyimpan data ruangan. Periksa struktur tabel database.';
                }

                $error = true;
            }
        }
    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id_ruangan'] ?? 0);

        if ($id <= 0) {
            $pesan = 'ID ruangan tidak valid.';
            $error = true;
        } else {
            try {
                $stmt = $koneksi->prepare(
                    "DELETE FROM ruangan WHERE id_ruangan = ?"
                );

                $stmt->bind_param('i', $id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $stmt->close();
                    header('Location: ruangan.php?status=hapus');
                    exit;
                }

                $stmt->close();
                $pesan = 'Data ruangan tidak ditemukan.';
                $error = true;

            } catch (mysqli_sql_exception $ex) {
                $pesan = 'Ruangan tidak dapat dihapus karena mungkin masih digunakan oleh transaksi.';
                $error = true;
            }
        }
    }
}

// NOTIFIKASI
if (isset($_GET['status'])) {
    $notifikasi = [
        'tambah' => 'Ruangan berhasil ditambahkan.',
        'edit' => 'Data ruangan berhasil diperbarui.',
        'hapus' => 'Ruangan berhasil dihapus.'
    ];

    if (isset($notifikasi[$_GET['status']])) {
        $pesan = $notifikasi[$_GET['status']];
        $error = false;
    }
}

// AMBIL DATA UNTUK EDIT
if (!$modeEdit && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    if ($id > 0) {
        $stmt = $koneksi->prepare(
            "SELECT id_ruangan, kode_ruangan, nama_ruangan,
                    jenis_ruangan, lokasi_detail, penanggung_jawab,
                    keterangan, aktif
             FROM ruangan
             WHERE id_ruangan = ?"
        );

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $resultEdit = $stmt->get_result();
        $rowEdit = $resultEdit->fetch_assoc();

        if ($rowEdit) {
            $dataEdit = $rowEdit;
            $modeEdit = true;
        }

        $stmt->close();
    }
}

// PENCARIAN
$cari = trim($_GET['cari'] ?? '');

if ($cari !== '') {
    $stmt = $koneksi->prepare(
        "SELECT id_ruangan, kode_ruangan, nama_ruangan,
                jenis_ruangan, lokasi_detail, penanggung_jawab,
                keterangan, aktif, dibuat_pada
         FROM ruangan
         WHERE kode_ruangan LIKE ?
            OR nama_ruangan LIKE ?
            OR jenis_ruangan LIKE ?
            OR lokasi_detail LIKE ?
            OR penanggung_jawab LIKE ?
         ORDER BY id_ruangan DESC"
    );

    $keyword = '%' . $cari . '%';

    $stmt->bind_param(
        'sssss',
        $keyword,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );

    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = $koneksi->query(
        "SELECT id_ruangan, kode_ruangan, nama_ruangan,
                jenis_ruangan, lokasi_detail, penanggung_jawab,
                keterangan, aktif, dibuat_pada
         FROM ruangan
         ORDER BY id_ruangan DESC"
    );
}

// STATISTIK
$totalRuangan = (int)$koneksi
    ->query("SELECT COUNT(*) AS total FROM ruangan")
    ->fetch_assoc()['total'];

$totalAktif = (int)$koneksi
    ->query("SELECT COUNT(*) AS total FROM ruangan WHERE aktif = 1")
    ->fetch_assoc()['total'];

$namaAdmin = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Ruangan | SIPRAS</title>

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
.menu a{display:flex;align-items:center;gap:12px;padding:12px 13px;border-radius:10px;color:#d5def0;text-decoration:none;font-size:13px;transition:.2s}
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
input,textarea,select{width:100%;padding:12px 13px;border:1px solid #dce3ee;border-radius:9px;outline:none;background:white;color:#263653;font:inherit;font-size:13px}
input:focus,textarea:focus,select:focus{border-color:#3268e9;box-shadow:0 0 0 3px #3268e91a}
textarea{min-height:88px;resize:vertical}
.checkbox{display:flex;align-items:center;gap:9px;margin-top:27px}
.checkbox input{width:auto}
.checkbox label{margin:0}
.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.btn{display:inline-block;padding:11px 15px;border:0;border-radius:8px;cursor:pointer;text-decoration:none;text-align:center;font-size:12px;font-weight:bold}
.primary{background:#3268e9;color:white}
.secondary{background:#edf1f7;color:#42516a}
.danger{background:#ffebeb;color:#b52c2c}
.alert{padding:14px 16px;margin-bottom:20px;border-radius:9px;background:#e4f7eb;color:#207443;font-size:13px}
.alert.error{background:#fee9e9;color:#a82c2c}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}
.search-form{display:flex;gap:8px;width:min(100%,370px)}
.search-form input{min-width:0}
.table-wrap{overflow-x:auto}
table{width:100%;min-width:900px;border-collapse:collapse}
th,td{padding:14px 12px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:top}
th{background:#f7f9fc;color:#64748b;font-size:11px;text-transform:uppercase}
td{font-size:12px;line-height:1.7}
.number{display:inline-block;padding:5px 9px;border-radius:6px;background:#eaf1ff;color:#2858a5;font-weight:bold}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#e7f7ed;color:#247344;font-size:11px;font-weight:bold}
.badge.off{background:#f0f1f4;color:#687386}
.row-actions{display:flex;align-items:center;gap:7px}
.row-actions form{margin:0}
.row-actions .btn{padding:8px 10px}
.empty{padding:30px;color:#8490a5;text-align:center}
.footer{padding:20px 0;color:#98a3b5;font-size:11px;line-height:1.8;text-align:center}
@media(max-width:1050px){.main{padding:22px}.form-grid{grid-template-columns:1fr}}
@media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.sidebar-bottom{margin-top:20px}.main{width:100%;margin-left:0;padding:18px}.toolbar{align-items:stretch;flex-direction:column}.search-form{width:100%}.date-badge{display:none}.stats{grid-template-columns:1fr}}
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
        <a class="active" href="ruangan.php"><span class="menu-icon">⌘</span> Data Ruangan</a>
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
        <a class="logout" href="../logout.php" onclick="return confirm('Yakin ingin keluar?')">↪ &nbsp; Keluar dari akun</a>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div>
            <div class="breadcrumb">SIPRAS / Menu Utama / Data Ruangan</div>
            <h1>Data Ruangan</h1>
            <p class="subtitle">Kelola ruangan dan lokasi fasilitas sekolah.</p>
        </div>
        <div class="date-badge">◷ &nbsp; <?= date('d-m-Y') ?></div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $error ? 'error' : '' ?>"><?= e($pesan) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">⌘</div>
            <div>
                <div class="stat-label">Total Ruangan</div>
                <div class="stat-value"><?= $totalRuangan ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">Ruangan Aktif</div>
                <div class="stat-value"><?= $totalAktif ?></div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-heading">
            <h2><?= $modeEdit ? 'Edit Data Ruangan' : 'Tambah Ruangan Baru' ?></h2>
            <p>Lengkapi kode, nama, jenis, dan informasi ruangan.</p>
        </div>

        <form method="POST" action="ruangan.php">
            <input type="hidden" name="aksi" value="<?= $modeEdit ? 'edit' : 'tambah' ?>">

            <?php if ($modeEdit): ?>
                <input type="hidden" name="id_ruangan" value="<?= (int)$dataEdit['id_ruangan'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div>
                    <label for="kode_ruangan">Kode Ruangan *</label>
                    <input id="kode_ruangan" name="kode_ruangan" required maxlength="20" placeholder="Contoh: LAB-001" value="<?= e($dataEdit['kode_ruangan']) ?>">
                </div>

                <div>
                    <label for="nama_ruangan">Nama Ruangan *</label>
                    <input id="nama_ruangan" name="nama_ruangan" required maxlength="100" placeholder="Contoh: Laboratorium Komputer" value="<?= e($dataEdit['nama_ruangan']) ?>">
                </div>

                <div>
                    <label for="jenis_ruangan">Jenis Ruangan *</label>
                    <select id="jenis_ruangan" name="jenis_ruangan" required>
                        <?php foreach ($jenisRuangan as $nilai => $labelJenis): ?>
                            <option value="<?= e($nilai) ?>" <?= $dataEdit['jenis_ruangan'] === $nilai ? 'selected' : '' ?>>
                                <?= e($labelJenis) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="lokasi_detail">Lokasi Detail</label>
                    <input id="lokasi_detail" name="lokasi_detail" maxlength="150" placeholder="Contoh: Lantai 2, Gedung A" value="<?= e($dataEdit['lokasi_detail']) ?>">
                </div>

                <div>
                    <label for="penanggung_jawab">Penanggung Jawab</label>
                    <input id="penanggung_jawab" name="penanggung_jawab" maxlength="100" placeholder="Nama penanggung jawab" value="<?= e($dataEdit['penanggung_jawab']) ?>">
                </div>

                <div>
                    <label for="keterangan">Keterangan</label>
                    <textarea id="keterangan" name="keterangan" placeholder="Informasi tambahan"><?= e($dataEdit['keterangan']) ?></textarea>
                </div>

                <div class="checkbox">
                    <input type="checkbox" id="aktif" name="aktif" value="1" <?= (int)$dataEdit['aktif'] === 1 ? 'checked' : '' ?>>
                    <label for="aktif">Ruangan aktif dan dapat digunakan</label>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary">
                    <?= $modeEdit ? 'Simpan Perubahan' : '+ Tambah Ruangan' ?>
                </button>

                <?php if ($modeEdit): ?>
                    <a href="ruangan.php" class="btn secondary">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="toolbar">
            <div>
                <h2>Daftar Ruangan</h2>
                <p>Data ruangan yang tersimpan di database.</p>
            </div>

            <form method="GET" action="ruangan.php" class="search-form">
                <input type="search" name="cari" placeholder="Cari kode, nama, lokasi..." value="<?= e($cari) ?>">
                <button type="submit" class="btn primary">Cari</button>

                <?php if ($cari !== ''): ?>
                    <a href="ruangan.php" class="btn secondary">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode</th>
                        <th>Nama Ruangan</th>
                        <th>Jenis</th>
                        <th>Lokasi Detail</th>
                        <th>Penanggung Jawab</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($hasil->num_rows > 0): ?>
                    <?php $no = 1; ?>
                    <?php while ($row = $hasil->fetch_assoc()): ?>
                        <tr>
                            <td><span class="number"><?= $no++ ?></span></td>
                            <td><strong><?= e($row['kode_ruangan']) ?></strong></td>
                            <td>
                                <strong><?= e($row['nama_ruangan']) ?></strong>
                                <?php if (!empty($row['keterangan'])): ?>
                                    <div style="color:#8490a5;margin-top:4px"><?= e($row['keterangan']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($jenisRuangan[$row['jenis_ruangan']] ?? $row['jenis_ruangan']) ?></td>
                            <td><?= e($row['lokasi_detail']) ?: '-' ?></td>
                            <td><?= e($row['penanggung_jawab']) ?: '-' ?></td>
                            <td>
                                <?php if ((int)$row['aktif'] === 1): ?>
                                    <span class="badge">Aktif</span>
                                <?php else: ?>
                                    <span class="badge off">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn secondary" href="ruangan.php?edit=<?= (int)$row['id_ruangan'] ?>">Edit</a>

                                    <form method="POST" action="ruangan.php" onsubmit="return confirm('Yakin ingin menghapus ruangan ini?')">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_ruangan" value="<?= (int)$row['id_ruangan'] ?>">
                                        <button type="submit" class="btn danger">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">
                            <?= $cari !== '' ? 'Data ruangan tidak ditemukan.' : 'Belum ada data ruangan.' ?>
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
