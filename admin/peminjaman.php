
<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$namaAdmin = $_SESSION['nama'] ?? 'Administrator';
$pesan = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];

function buatKodePeminjaman(): string
{
    return 'PJM-' . date('YmdHis') . '-' .
        strtoupper(bin2hex(random_bytes(2)));
}

function tanggalValid(string $tanggal): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date !== false &&
        $date->format('Y-m-d') === $tanggal;
}

function jamValid(string $jam): bool
{
    $time = DateTime::createFromFormat('!H:i', $jam);

    return $time !== false &&
        $time->format('H:i') === $jam;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($csrf, (string) $_POST['csrf_token'])
        ) {
            throw new Exception(
                'Permintaan tidak valid. Muat ulang halaman.'
            );
        }

        $aksi = $_POST['aksi'] ?? '';

        if ($aksi === 'tambah') {
            $idUser = filter_var(
                $_POST['id_user'] ?? null,
                FILTER_VALIDATE_INT
            );

            $idAlat = filter_var(
                $_POST['id_alat'] ?? null,
                FILTER_VALIDATE_INT
            );

            $jumlah = filter_var(
                $_POST['jumlah'] ?? null,
                FILTER_VALIDATE_INT
            );

            $tanggalPinjam = trim($_POST['tanggal_pinjam'] ?? '');
            $jamPinjam = trim($_POST['jam_pinjam'] ?? '');
            $tanggalKembali = trim(
                $_POST['tanggal_rencana_kembali'] ?? ''
            );
            $jamKembali = trim(
                $_POST['jam_rencana_kembali'] ?? ''
            );
            $keperluan = trim($_POST['keperluan'] ?? '');

            if (
                !$idUser || !$idAlat ||
                !$jumlah || $jumlah < 1
            ) {
                throw new Exception(
                    'Pilih peminjam, alat, dan jumlah yang benar.'
                );
            }

            if (
                !tanggalValid($tanggalPinjam) ||
                !tanggalValid($tanggalKembali) ||
                !jamValid($jamPinjam) ||
                !jamValid($jamKembali)
            ) {
                throw new Exception(
                    'Tanggal atau jam peminjaman tidak valid.'
                );
            }

            $mulai = strtotime("$tanggalPinjam $jamPinjam");
            $kembali = strtotime("$tanggalKembali $jamKembali");

            if ($kembali <= $mulai) {
                throw new Exception(
                    'Waktu pengembalian harus setelah waktu peminjaman.'
                );
            }

            if (
                $keperluan === '' ||
                mb_strlen($keperluan) > 255
            ) {
                throw new Exception(
                    'Keperluan wajib diisi, maksimal 255 karakter.'
                );
            }

            $stmt = $koneksi->prepare(
                "SELECT id_user
                 FROM users
                 WHERE id_user = ?
                   AND role = 'peminjam'
                   AND aktif = 1"
            );
            $stmt->bind_param('i', $idUser);
            $stmt->execute();

            if (!$stmt->get_result()->fetch_assoc()) {
                throw new Exception(
                    'Akun peminjam tidak ditemukan atau tidak aktif.'
                );
            }

            $stmt = $koneksi->prepare(
                "SELECT id_alat, stok_tersedia, kondisi, status
                 FROM alat
                 WHERE id_alat = ?"
            );
            $stmt->bind_param('i', $idAlat);
            $stmt->execute();
            $alat = $stmt->get_result()->fetch_assoc();

            if (!$alat) {
                throw new Exception('Data alat tidak ditemukan.');
            }

            if (
                $alat['kondisi'] !== 'baik' ||
                $alat['status'] !== 'tersedia' ||
                (int) $alat['stok_tersedia'] < $jumlah
            ) {
                throw new Exception(
                    'Stok alat tidak mencukupi atau alat tidak tersedia.'
                );
            }

            $koneksi->begin_transaction();

            try {
                $kode = buatKodePeminjaman();
                $status = 'menunggu_persetujuan';

                $stmt = $koneksi->prepare(
                    "INSERT INTO peminjaman
                    (
                        kode_peminjaman,
                        id_user,
                        tanggal_pinjam,
                        jam_pinjam,
                        tanggal_rencana_kembali,
                        jam_rencana_kembali,
                        keperluan,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    'sissssss',
                    $kode,
                    $idUser,
                    $tanggalPinjam,
                    $jamPinjam,
                    $tanggalKembali,
                    $jamKembali,
                    $keperluan,
                    $status
                );
                $stmt->execute();

                $idPeminjaman = $koneksi->insert_id;

                $stmt = $koneksi->prepare(
                    "INSERT INTO detail_peminjaman
                    (id_peminjaman, id_alat, jumlah)
                    VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    'iii',
                    $idPeminjaman,
                    $idAlat,
                    $jumlah
                );
                $stmt->execute();

                $koneksi->commit();

                $pesan = 'Pengajuan peminjaman berhasil ditambahkan.';
            } catch (Throwable $ex) {
                $koneksi->rollback();
                throw $ex;
            }

        } elseif ($aksi === 'setujui') {
            $idPeminjaman = filter_var(
                $_POST['id_peminjaman'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$idPeminjaman) {
                throw new Exception('ID peminjaman tidak valid.');
            }

            $koneksi->begin_transaction();

            try {
                $stmt = $koneksi->prepare(
                    "SELECT status
                     FROM peminjaman
                     WHERE id_peminjaman = ?
                     FOR UPDATE"
                );
                $stmt->bind_param('i', $idPeminjaman);
                $stmt->execute();

                $peminjaman = $stmt->get_result()->fetch_assoc();

                if (
                    !$peminjaman ||
                    $peminjaman['status'] !== 'menunggu_persetujuan'
                ) {
                    throw new Exception(
                        'Peminjaman ini sudah diproses atau tidak ditemukan.'
                    );
                }

                $stmt = $koneksi->prepare(
                    "SELECT id_alat, jumlah
                     FROM detail_peminjaman
                     WHERE id_peminjaman = ?"
                );
                $stmt->bind_param('i', $idPeminjaman);
                $stmt->execute();

                $details = $stmt->get_result()->fetch_all(
                    MYSQLI_ASSOC
                );

                if (!$details) {
                    throw new Exception(
                        'Detail alat pada peminjaman tidak ditemukan.'
                    );
                }

                foreach ($details as $detail) {
                    $idAlat = (int) $detail['id_alat'];
                    $jumlah = (int) $detail['jumlah'];

                    $stmt = $koneksi->prepare(
                        "SELECT stok_tersedia, kondisi, status
                         FROM alat
                         WHERE id_alat = ?
                         FOR UPDATE"
                    );
                    $stmt->bind_param('i', $idAlat);
                    $stmt->execute();

                    $alat = $stmt->get_result()->fetch_assoc();

                    if (
                        !$alat ||
                        $alat['kondisi'] !== 'baik' ||
                        $alat['status'] !== 'tersedia' ||
                        (int) $alat['stok_tersedia'] < $jumlah
                    ) {
                        throw new Exception(
                            'Stok alat tidak mencukupi. Peminjaman tidak dapat disetujui.'
                        );
                    }

                    $stokBaru =
                        (int) $alat['stok_tersedia'] - $jumlah;

                    $statusAlat = $stokBaru > 0
                        ? 'tersedia'
                        : 'dipinjam';

                    $stmt = $koneksi->prepare(
                        "UPDATE alat
                         SET stok_tersedia = ?, status = ?
                         WHERE id_alat = ?"
                    );
                    $stmt->bind_param(
                        'isi',
                        $stokBaru,
                        $statusAlat,
                        $idAlat
                    );
                    $stmt->execute();
                }

                $statusBaru = 'sedang_dipinjam';
                $catatan = 'Disetujui oleh administrator.';
                $idAdmin = (int) $_SESSION['id_user'];

                $stmt = $koneksi->prepare(
                    "UPDATE peminjaman
                     SET status = ?,
                         id_petugas_persetuju = ?,
                         waktu_persetujuan = NOW(),
                         catatan_petugas = ?
                     WHERE id_peminjaman = ?"
                );

                $stmt->bind_param(
                    'sisi',
                    $statusBaru,
                    $idAdmin,
                    $catatan,
                    $idPeminjaman
                );
                $stmt->execute();

                $koneksi->commit();

                $pesan = 'Peminjaman berhasil disetujui.';
            } catch (Throwable $ex) {
                $koneksi->rollback();
                throw $ex;
            }

        } elseif ($aksi === 'tolak') {
            $idPeminjaman = filter_var(
                $_POST['id_peminjaman'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$idPeminjaman) {
                throw new Exception('ID peminjaman tidak valid.');
            }

            $statusBaru = 'ditolak';
            $catatan = 'Pengajuan ditolak oleh administrator.';
            $idAdmin = (int) $_SESSION['id_user'];

            $stmt = $koneksi->prepare(
                "UPDATE peminjaman
                 SET status = ?,
                     id_petugas_persetuju = ?,
                     waktu_persetujuan = NOW(),
                     catatan_petugas = ?
                 WHERE id_peminjaman = ?
                   AND status = 'menunggu_persetujuan'"
            );

            $stmt->bind_param(
                'sisi',
                $statusBaru,
                $idAdmin,
                $catatan,
                $idPeminjaman
            );
            $stmt->execute();

            if ($stmt->affected_rows < 1) {
                throw new Exception(
                    'Pengajuan sudah diproses atau tidak ditemukan.'
                );
            }

            $pesan = 'Pengajuan peminjaman berhasil ditolak.';

        } else {
            throw new Exception('Aksi tidak dikenal.');
        }

    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

/* Data peminjam aktif */
$peminjam = $koneksi->query(
    "SELECT id_user, nama
     FROM users
     WHERE role = 'peminjam'
       AND aktif = 1
     ORDER BY nama"
)->fetch_all(MYSQLI_ASSOC);

/* Data alat yang tersedia */
$daftarAlat = $koneksi->query(
    "SELECT id_alat, kode_inventaris, nama_alat, stok_tersedia
     FROM alat
     WHERE status = 'tersedia'
       AND kondisi = 'baik'
       AND stok_tersedia > 0
     ORDER BY nama_alat"
)->fetch_all(MYSQLI_ASSOC);

/* Daftar transaksi */
$sql = "
    SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.keperluan,
        p.status,
        u.nama AS nama_peminjam,
        GROUP_CONCAT(
            CONCAT(a.nama_alat, ' (', dp.jumlah, ')')
            ORDER BY a.nama_alat
            SEPARATOR ', '
        ) AS daftar_barang
    FROM peminjaman p
    JOIN users u
        ON u.id_user = p.id_user
    LEFT JOIN detail_peminjaman dp
        ON dp.id_peminjaman = p.id_peminjaman
    LEFT JOIN alat a
        ON a.id_alat = dp.id_alat
    GROUP BY
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.keperluan,
        p.status,
        u.nama
    ORDER BY p.id_peminjaman DESC
";

$transaksi = $koneksi->query($sql)->fetch_all(MYSQLI_ASSOC);

$total = count($transaksi);
$menunggu = 0;
$berjalan = 0;
$selesai = 0;

foreach ($transaksi as $item) {
    if ($item['status'] === 'menunggu_persetujuan') {
        $menunggu++;
    }

    if (
        in_array(
            $item['status'],
            ['sedang_dipinjam', 'terlambat'],
            true
        )
    ) {
        $berjalan++;
    }

    if ($item['status'] === 'selesai') {
        $selesai++;
    }
}

function labelStatus(string $status): string
{
    $label = [
        'menunggu_persetujuan' => 'Menunggu Persetujuan',
        'sedang_dipinjam' => 'Sedang Dipinjam',
        'terlambat' => 'Terlambat',
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
    ];

    return $label[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman | SIPRAS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fc;
            color: #1e293b;
        }

        /* SIDEBAR DISAMAKAN DENGAN admin/alat.php */
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            display: flex;
            flex-direction: column;
            width: 250px;
            height: 100vh;
            padding: 26px 18px;
            background: #12284b;
            color: white;
            overflow-y: auto;
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 8px 28px;
            border-bottom: 1px solid #314360;
        }

        .logo {
            display: grid;
            place-items: center;
            flex-shrink: 0;
            width: 43px;
            height: 43px;
            border-radius: 13px;
            background: linear-gradient(135deg, #4385ff, #2452c8);
            font-size: 22px;
            font-weight: bold;
        }

        .brand h2 {
            font-size: 19px;
            letter-spacing: 2px;
        }

        .brand p {
            margin-top: 4px;
            color: #b9c9e6;
            font-size: 10px;
            letter-spacing: .4px;
        }

        .menu-title {
            margin: 28px 10px 12px;
            color: #91a6c9;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.5px;
        }

        .menu {
            display: grid;
            gap: 6px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 43px;
            padding: 12px 13px;
            border-radius: 10px;
            color: #d5def0;
            text-decoration: none;
            font-size: 13px;
            transition: background .2s ease, color .2s ease;
        }

        .menu a:hover {
            background: #203c69;
            color: white;
        }

        .menu a.active {
            background: #294b83;
            color: white;
            box-shadow: inset 3px 0 #76a7ff;
        }

        .menu-icon {
            display: inline-grid;
            place-items: center;
            flex-shrink: 0;
            width: 21px;
            font-size: 17px;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 24px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 9px;
            border-top: 1px solid #314360;
        }

        .avatar {
            display: grid;
            place-items: center;
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: #e3ecff;
            color: #2454bc;
            font-weight: bold;
        }

        .profile-name {
            max-width: 140px;
            overflow-wrap: anywhere;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .profile-role {
            margin-top: 4px;
            color: #b6c7e4;
            font-size: 11px;
        }

        .logout {
            display: block;
            margin-top: 8px;
            padding: 11px;
            border: 1px solid #405271;
            border-radius: 9px;
            color: #ffe0e0;
            text-align: center;
            text-decoration: none;
            font-size: 12px;
            transition: background .2s ease;
        }

        .logout:hover {
            background: #713b4b;
            color: white;
        }

        .main {
            width: calc(100% - 250px);
            min-height: 100vh;
            margin-left: 250px;
            padding: 32px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 28px;
        }

        .topbar h1 {
            font-size: 27px;
            color: #172b4d;
        }

        .topbar p {
            color: #718096;
            font-size: 13px;
            margin-top: 8px;
        }

        .welcome {
            background: white;
            border: 1px solid #e4eaf4;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 12px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat {
            background: white;
            padding: 22px;
            border: 1px solid #e6ebf3;
            border-radius: 15px;
            box-shadow: 0 5px 18px #172b4d08;
        }

        .stat span {
            color: #718096;
            font-size: 12px;
        }

        .stat h2 {
            margin-top: 13px;
            font-size: 27px;
        }

        .panel {
            background: white;
            padding: 24px;
            border: 1px solid #e5eaf2;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 5px 18px #172b4d08;
        }

        .panel h2 {
            color: #172b4d;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .alert {
            padding: 14px 17px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .success {
            background: #e5f8ed;
            color: #187443;
        }

        .danger {
            background: #fff0f0;
            color: #b42323;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: bold;
            color: #43516a;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d9e1ed;
            border-radius: 9px;
            font: inherit;
            font-size: 13px;
            background: white;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: 2px solid #c7dcff;
            border-color: #5b8de0;
        }

        .full {
            grid-column: 1 / -1;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 10px 13px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #3269cb;
            color: white;
        }

        .btn-primary:hover {
            background: #2455ad;
        }

        .btn-success {
            background: #dcf8e8;
            color: #167344;
        }

        .btn-danger {
            background: #ffebeb;
            color: #b42323;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            padding: 14px 12px;
            text-align: left;
            background: #f6f8fc;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        td {
            padding: 15px 12px;
            border-bottom: 1px solid #edf0f5;
            font-size: 12px;
            vertical-align: top;
            line-height: 1.6;
        }

        .kode {
            font-weight: bold;
            color: #3269cb;
        }

        .muted {
            color: #718096;
        }

        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 6px 9px;
            font-size: 10px;
            font-weight: bold;
            background: #edf2f7;
            color: #475569;
        }

        .status.menunggu_persetujuan {
            background: #fff3d6;
            color: #996515;
        }

        .status.sedang_dipinjam {
            background: #e4efff;
            color: #2455ad;
        }

        .status.terlambat {
            background: #ffe7e7;
            color: #b42323;
        }

        .status.selesai {
            background: #dcf8e8;
            color: #167344;
        }

        .status.ditolak {
            background: #f1e8ff;
            color: #7540a6;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .empty {
            text-align: center;
            padding: 35px;
            color: #718096;
        }

        @media (max-width: 1100px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .main {
                width: 100%;
                margin-left: 0;
                padding: 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .topbar h1 {
                font-size: 23px;
            }
        }

        @media (max-width: 420px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }
        }
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
        <a href="dashboard.php">
            <span class="menu-icon">⌂</span> Dashboard
        </a>
        <a href="kategori.php">
            <span class="menu-icon">▦</span> Kategori
        </a>
        <a href="ruangan.php">
            <span class="menu-icon">⌘</span> Data Ruangan
        </a>
        <a href="alat.php">
            <span class="menu-icon">▣</span> Data Sarana
        </a>
        <a href="pengguna.php">
            <span class="menu-icon">♙</span> Data Pengguna
        </a>
    </nav>

    <div class="menu-title">TRANSAKSI</div>
    <nav class="menu">
        <a href="peminjaman.php" class="active">
            <span class="menu-icon">⇄</span> Peminjaman
        </a>
        <a href="pengembalian.php">
            <span class="menu-icon">↩</span> Pengembalian
        </a>
        <a href="sanksi.php">
            <span class="menu-icon">◷</span> Sanksi Keterlambatan
        </a>
        <a href="laporan.php">
            <span class="menu-icon">▤</span> Laporan
        </a>
    </nav>

    <div class="menu-title">SISTEM</div>
    <nav class="menu">
        <a href="log_aktivitas.php">
            <span class="menu-icon">◷</span> Log Aktivitas
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($namaAdmin, 0, 1))) ?>
            </div>
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
            <h1>Data Peminjaman</h1>
            <p>Kelola pengajuan dan persetujuan peminjaman sarana sekolah.</p>
        </div>

        <div class="welcome">
            Selamat datang, <strong><?= e($namaAdmin) ?></strong>
        </div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert success"><?= e($pesan) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <span>Total Peminjaman</span>
            <h2><?= $total ?></h2>
        </div>

        <div class="stat">
            <span>Menunggu Persetujuan</span>
            <h2><?= $menunggu ?></h2>
        </div>

        <div class="stat">
            <span>Sedang Dipinjam</span>
            <h2><?= $berjalan ?></h2>
        </div>

        <div class="stat">
            <span>Selesai</span>
            <h2><?= $selesai ?></h2>
        </div>
    </section>

    <section class="panel">
        <h2>+ Tambah Peminjaman</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="aksi" value="tambah">

            <div class="form-grid">
                <div class="field">
                    <label for="id_user">Nama Peminjam</label>
                    <select id="id_user" name="id_user" required>
                        <option value="">-- Pilih Peminjam --</option>
                        <?php foreach ($peminjam as $p): ?>
                            <option value="<?= (int) $p['id_user'] ?>">
                                <?= e($p['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="id_alat">Sarana / Alat</label>
                    <select id="id_alat" name="id_alat" required>
                        <option value="">-- Pilih Alat --</option>
                        <?php foreach ($daftarAlat as $a): ?>
                            <option value="<?= (int) $a['id_alat'] ?>">
                                <?= e($a['kode_inventaris']) ?>
                                - <?= e($a['nama_alat']) ?>
                                (Stok: <?= (int) $a['stok_tersedia'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="jumlah">Jumlah</label>
                    <input
                        type="number"
                        id="jumlah"
                        name="jumlah"
                        min="1"
                        value="1"
                        required
                    >
                </div>

                <div class="field">
                    <label for="tanggal_pinjam">Tanggal Pinjam</label>
                    <input
                        type="date"
                        id="tanggal_pinjam"
                        name="tanggal_pinjam"
                        required
                    >
                </div>

                <div class="field">
                    <label for="jam_pinjam">Jam Pinjam</label>
                    <input
                        type="time"
                        id="jam_pinjam"
                        name="jam_pinjam"
                        required
                    >
                </div>

                <div class="field">
                    <label for="tanggal_rencana_kembali">
                        Tanggal Rencana Kembali
                    </label>
                    <input
                        type="date"
                        id="tanggal_rencana_kembali"
                        name="tanggal_rencana_kembali"
                        required
                    >
                </div>

                <div class="field">
                    <label for="jam_rencana_kembali">
                        Jam Rencana Kembali
                    </label>
                    <input
                        type="time"
                        id="jam_rencana_kembali"
                        name="jam_rencana_kembali"
                        required
                    >
                </div>

                <div class="field full">
                    <label for="keperluan">Keperluan</label>
                    <textarea
                        id="keperluan"
                        name="keperluan"
                        rows="3"
                        maxlength="255"
                        placeholder="Contoh: Meminjam proyektor untuk presentasi kelas"
                        required
                    ></textarea>
                </div>

                <div class="full">
                    <button class="btn btn-primary" type="submit">
                        Simpan Pengajuan
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Daftar Transaksi Peminjaman</h2>

        <div class="field" style="margin-bottom:18px">
            <label for="cari">Cari Peminjaman</label>
            <input
                type="search"
                id="cari"
                placeholder="Cari kode, nama, alat, atau status..."
            >
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode / Peminjam</th>
                        <th>Barang</th>
                        <th>Waktu Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Keperluan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody id="isiTabel">
                <?php if (!$transaksi): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada data peminjaman.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transaksi as $i => $t): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td>
                                <div class="kode">
                                    <?= e($t['kode_peminjaman']) ?>
                                </div>
                                <div><?= e($t['nama_peminjam']) ?></div>
                            </td>

                            <td><?= e($t['daftar_barang'] ?? '-') ?></td>

                            <td>
                                <?= e($t['tanggal_pinjam']) ?><br>
                                <span class="muted">
                                    <?= e(substr($t['jam_pinjam'], 0, 5)) ?>
                                </span>
                            </td>

                            <td>
                                <?= e($t['tanggal_rencana_kembali']) ?><br>
                                <span class="muted">
                                    <?= e(substr($t['jam_rencana_kembali'], 0, 5)) ?>
                                </span>
                            </td>

                            <td><?= e($t['keperluan']) ?></td>

                            <td>
                                <span class="status <?= e($t['status']) ?>">
                                    <?= e(labelStatus($t['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($t['status'] === 'menunggu_persetujuan'): ?>
                                    <div class="actions">
                                        <form method="POST"
                                              onsubmit="return confirm('Setujui peminjaman ini?')">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrf) ?>">
                                            <input type="hidden" name="aksi"
                                                   value="setujui">
                                            <input type="hidden" name="id_peminjaman"
                                                   value="<?= (int) $t['id_peminjaman'] ?>">
                                            <button class="btn btn-success" type="submit">
                                                Setujui
                                            </button>
                                        </form>

                                        <form method="POST"
                                              onsubmit="return confirm('Tolak peminjaman ini?')">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrf) ?>">
                                            <input type="hidden" name="aksi"
                                                   value="tolak">
                                            <input type="hidden" name="id_peminjaman"
                                                   value="<?= (int) $t['id_peminjaman'] ?>">
                                            <button class="btn btn-danger" type="submit">
                                                Tolak
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Tidak ada aksi</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
    const kolomCari = document.getElementById('cari');
    const barisTabel = document.querySelectorAll('#isiTabel tr');

    kolomCari.addEventListener('input', function () {
        const kata = this.value.toLowerCase();

        barisTabel.forEach(function (baris) {
            baris.style.display =
                baris.textContent.toLowerCase().includes(kata)
                    ? ''
                    : 'none';
        });
    });
</script>

</body>
</html>
