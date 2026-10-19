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

$pesan = '';
$jenis_pesan = '';

$sql = "SELECT id_alat, kode_inventaris, nama_alat, stok_tersedia
        FROM alat
        WHERE stok_tersedia > 0
          AND kondisi = 'baik'
          AND status = 'tersedia'
        ORDER BY nama_alat ASC";

$result_alat = $koneksi->query($sql);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_alat = (int) ($_POST['id_alat'] ?? 0);
    $jumlah = (int) ($_POST['jumlah'] ?? 0);

    $tanggal_pinjam = trim($_POST['tanggal_pinjam'] ?? '');
    $jam_pinjam = trim($_POST['jam_pinjam'] ?? '');

    $tanggal_kembali = trim(
        $_POST['tanggal_rencana_kembali'] ?? ''
    );

    $jam_kembali = trim(
        $_POST['jam_rencana_kembali'] ?? ''
    );

    $keperluan = trim($_POST['keperluan'] ?? '');

    try {
        if (
            $id_alat <= 0 ||
            $jumlah <= 0 ||
            $tanggal_pinjam === '' ||
            $jam_pinjam === '' ||
            $tanggal_kembali === '' ||
            $jam_kembali === '' ||
            $keperluan === ''
        ) {
            throw new Exception('Semua kolom wajib diisi.');
        }

        if (strlen($keperluan) > 255) {
            throw new Exception(
                'Keperluan maksimal 255 karakter.'
            );
        }

        $waktu_pinjam = strtotime(
            $tanggal_pinjam . ' ' . $jam_pinjam
        );

        $waktu_kembali = strtotime(
            $tanggal_kembali . ' ' . $jam_kembali
        );

        if (
            $waktu_pinjam === false ||
            $waktu_kembali === false
        ) {
            throw new Exception(
                'Tanggal atau jam tidak valid.'
            );
        }

        if ($waktu_kembali <= $waktu_pinjam) {
            throw new Exception(
                'Waktu kembali harus setelah waktu pinjam.'
            );
        }

        $koneksi->begin_transaction();
        $stmt = $koneksi->prepare(
            "SELECT stok_tersedia, kondisi, status
             FROM alat
             WHERE id_alat = ?
             FOR UPDATE"
        );

        $stmt->bind_param('i', $id_alat);
        $stmt->execute();

        $data_alat = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data_alat) {
            throw new Exception('Alat tidak ditemukan.');
        }

        if (
            $data_alat['kondisi'] !== 'baik' ||
            $data_alat['status'] !== 'tersedia'
        ) {
            throw new Exception(
                'Alat sedang tidak tersedia untuk dipinjam.'
            );
        }

        if ((int) $data_alat['stok_tersedia'] < $jumlah) {
            throw new Exception(
                'Jumlah melebihi stok alat yang tersedia.'
            );
        }
        $kode_peminjaman = 'PJM-' .
            date('YmdHis') . '-' .
            strtoupper(bin2hex(random_bytes(3)));

        $status = 'menunggu_persetujuan';

        /* Simpan tabel peminjaman */
        $stmt = $koneksi->prepare(
            "INSERT INTO peminjaman (
                kode_peminjaman,
                id_user,
                tanggal_pinjam,
                jam_pinjam,
                tanggal_rencana_kembali,
                jam_rencana_kembali,
                keperluan,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'sissssss',
            $kode_peminjaman,
            $id_user,
            $tanggal_pinjam,
            $jam_pinjam,
            $tanggal_kembali,
            $jam_kembali,
            $keperluan,
            $status
        );

        $stmt->execute();
        $id_peminjaman = $koneksi->insert_id;
        $stmt->close();

        $stmt = $koneksi->prepare(
            "INSERT INTO detail_peminjaman (
                id_peminjaman,
                id_alat,
                jumlah
            ) VALUES (?, ?, ?)"
        );

        $stmt->bind_param(
            'iii',
            $id_peminjaman,
            $id_alat,
            $jumlah
        );

        $stmt->execute();
        $stmt->close();

        $koneksi->commit();
        try {
            $aktivitas = 'Mengajukan peminjaman';

            $detail = 'Kode peminjaman: ' . $kode_peminjaman .
                ' | ID alat: ' . $id_alat .
                ' | Jumlah: ' . $jumlah .
                ' | Tanggal pinjam: ' . $tanggal_pinjam .
                ' pukul ' . $jam_pinjam .
                ' | Rencana kembali: ' . $tanggal_kembali .
                ' pukul ' . $jam_kembali .
                ' | Keperluan: ' . $keperluan;

            $alamat_ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $nama_pelaku = $_SESSION['nama'] ?? 'Peminjam';
            $nama_tabel = 'peminjaman';

            $stmt_log = $koneksi->prepare(
                "INSERT INTO log_aktivitas (
                    id_user,
                    aktivitas,
                    detail,
                    alamat_ip,
                    nama_admin,
                    nama_tabel
                ) VALUES (?, ?, ?, ?, ?, ?)"
            );

            $stmt_log->bind_param(
                'isssss',
                $id_user,
                $aktivitas,
                $detail,
                $alamat_ip,
                $nama_pelaku,
                $nama_tabel
            );

            $stmt_log->execute();
            $stmt_log->close();

        } catch (Throwable $log_error) {
            error_log(
                'Gagal mencatat log aktivitas: ' .
                $log_error->getMessage()
            );
        }

        header('Location: riwayat.php?berhasil=1');
        exit;

    } catch (Throwable $e) {
        try {
            $koneksi->rollback();
        } catch (Throwable $rollback_error) {
            // Abaikan error rollback.
        }

        error_log(
            'Gagal menyimpan peminjaman: ' .
            $e->getMessage()
        );

        $pesan = 'Gagal menyimpan peminjaman: ' .
            $e->getMessage();

        $jenis_pesan = 'error';
    }
}

function h($nilai)
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Ajukan Peminjaman | SIPRAS</title>

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

        .menu a.logout:hover {
            background: rgba(255,90,105,.12);
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
            color: #1b2e4d;
            font-size: 27px;
        }

        .heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .panel {
            max-width: 900px;
            padding: 28px;
            background: white;
            border: 1px solid #edf0f7;
            border-radius: 17px;
            box-shadow: 0 5px 20px rgba(25,50,90,.025);
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 19px;
            color: #1d3151;
        }

        .description {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2,minmax(0,1fr));
            gap: 19px;
        }

        .field {
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #dce3ef;
            border-radius: 10px;
            background: white;
            color: #253653;
            font-family: inherit;
            font-size: 13px;
            outline: none;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(50,104,233,.1);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .alert {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .alert.error {
            background: #ffebed;
            color: #b42335;
        }

        .alert.info {
            background: #edf3ff;
            color: #294f9e;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .button {
            display: inline-block;
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .primary {
            background: var(--blue);
            color: white;
        }

        .primary:hover {
            background: #2456ca;
        }

        .secondary {
            background: #edf1f7;
            color: #53627a;
        }

        .secondary:hover {
            background: #e1e7f0;
        }

        .footer {
            margin-top: 25px;
            color: #9aa5b7;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 900px) {
            main {
                padding: 24px;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .panel {
                padding: 20px;
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

        <a href="peminjaman.php" class="active">
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

        <!-- TAMBAHAN: MENU LOG AKTIVITAS -->
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
        <h1>Ajukan Peminjaman</h1>
        <p>
            Halo, <?= h($nama) ?>! Isi formulir untuk meminjam
            alat atau fasilitas sekolah.
        </p>
    </div>

    <section class="panel">
        <h2>Formulir Peminjaman</h2>

        <p class="description">
            Lengkapi alat, jumlah, waktu peminjaman, dan rencana
            pengembalian sebelum mengirim pengajuan.
        </p>

        <?php if ($pesan !== ''): ?>
            <div class="alert <?= h($jenis_pesan) ?>">
                <?= h($pesan) ?>
            </div>
        <?php endif; ?>

        <?php if ($result_alat->num_rows === 0): ?>

            <div class="alert info">
                Belum ada alat yang tersedia. Silakan hubungi petugas sekolah.
            </div>

        <?php else: ?>

            <form method="POST" action="peminjaman.php">

                <div class="form-grid">

                    <div class="field full">
                        <label for="id_alat">Pilih Alat *</label>

                        <select name="id_alat" id="id_alat" required>
                            <option value="">-- Pilih alat --</option>

                            <?php while ($row_alat = $result_alat->fetch_assoc()): ?>
                                <option value="<?= (int) $row_alat['id_alat'] ?>"
                                    <?= (int)($_POST['id_alat'] ?? 0) === (int)$row_alat['id_alat'] ? 'selected' : '' ?>>

                                    <?= h($row_alat['kode_inventaris']) ?>
                                    -
                                    <?= h($row_alat['nama_alat']) ?>
                                    (stok: <?= (int) $row_alat['stok_tersedia'] ?>)

                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="jumlah">Jumlah *</label>

                        <input
                            type="number"
                            name="jumlah"
                            id="jumlah"
                            min="1"
                            value="<?= h($_POST['jumlah'] ?? '1') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="tanggal_pinjam">Tanggal Pinjam *</label>

                        <input
                            type="date"
                            name="tanggal_pinjam"
                            id="tanggal_pinjam"
                            value="<?= h($_POST['tanggal_pinjam'] ?? date('Y-m-d')) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="jam_pinjam">Jam Pinjam *</label>

                        <input
                            type="time"
                            name="jam_pinjam"
                            id="jam_pinjam"
                            value="<?= h($_POST['jam_pinjam'] ?? date('H:i')) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="tanggal_rencana_kembali">
                            Tanggal Rencana Kembali *
                        </label>

                        <input
                            type="date"
                            name="tanggal_rencana_kembali"
                            id="tanggal_rencana_kembali"
                            value="<?= h($_POST['tanggal_rencana_kembali'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="jam_rencana_kembali">
                            Jam Rencana Kembali *
                        </label>

                        <input
                            type="time"
                            name="jam_rencana_kembali"
                            id="jam_rencana_kembali"
                            value="<?= h($_POST['jam_rencana_kembali'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="field full">
                        <label for="keperluan">Keperluan Peminjaman *</label>

                        <textarea
                            name="keperluan"
                            id="keperluan"
                            maxlength="255"
                            placeholder="Contoh: Untuk kegiatan olahraga sekolah"
                            required
                        ><?= h($_POST['keperluan'] ?? '') ?></textarea>
                    </div>

                </div>

                <div class="actions">
                    <button type="submit" class="button primary">
                        Kirim Pengajuan
                    </button>

                    <a href="alat.php" class="button secondary">
                        Kembali ke Daftar Alat
                    </a>
                </div>

            </form>

        <?php endif; ?>

    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman Sarana
        dan Prasarana Sekolah
    </div>

</main>

</body>
</html>