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

function h($nilai) {
    return htmlspecialchars((string)$nilai, ENT_QUOTES, 'UTF-8');
}

$pesan = '';
$error = '';

$stmt = $koneksi->prepare("
    SELECT nama, username, kelas, no_hp
    FROM users
    WHERE id_user = ?
    LIMIT 1
");
$stmt->bind_param('i', $id_user);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die('Data pengguna tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_baru = trim($_POST['nama'] ?? '');
    $username_baru = trim($_POST['username'] ?? '');
    $kelas_baru = trim($_POST['kelas'] ?? '');
    $no_hp_baru = trim($_POST['no_hp'] ?? '');

    if ($nama_baru === '' || $username_baru === '') {
        $error = 'Nama dan username wajib diisi.';
    } else {
        $cek = $koneksi->prepare("
            SELECT id_user
            FROM users
            WHERE username = ? AND id_user != ?
            LIMIT 1
        ");
        $cek->bind_param('si', $username_baru, $id_user);
        $cek->execute();
        $hasil_cek = $cek->get_result();

        if ($hasil_cek->num_rows > 0) {
            $error = 'Username sudah digunakan akun lain.';
        } else {
            $update = $koneksi->prepare("
                UPDATE users
                SET nama = ?, username = ?, kelas = ?, no_hp = ?
                WHERE id_user = ?
            ");

            $update->bind_param(
                'ssssi',
                $nama_baru,
                $username_baru,
                $kelas_baru,
                $no_hp_baru,
                $id_user
            );

            if ($update->execute()) {
                $pesan = 'Profil berhasil diperbarui!';

                /* CATAT LOG AKTIVITAS */
                try {
                    $aktivitas = 'Memperbarui profil';
                    $detail = 'Peminjam memperbarui informasi profil akun.';
                    $alamat_ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $nama_tabel = 'users';

                    $stmt_log = $koneksi->prepare(
                        "INSERT INTO log_aktivitas
                        (id_user, aktivitas, detail, alamat_ip, nama_admin, nama_tabel)
                        VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    $stmt_log->bind_param(
                        'isssss',
                        $id_user,
                        $aktivitas,
                        $detail,
                        $alamat_ip,
                        $nama_baru,
                        $nama_tabel
                    );

                    $stmt_log->execute();
                    $stmt_log->close();
                } catch (Throwable $e) {
                    error_log(
                        'Gagal mencatat log profil: ' .
                        $e->getMessage()
                    );
                }

                $user['nama'] = $nama_baru;
                $user['username'] = $username_baru;
                $user['kelas'] = $kelas_baru;
                $user['no_hp'] = $no_hp_baru;

                $_SESSION['nama'] = $nama_baru;
                $nama = $nama_baru;
            } else {
                $error = 'Profil gagal diperbarui.';
            }

            $update->close();
        }

        $cek->close();
    }
}

$inisial = strtoupper(substr($user['nama'], 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Profil Saya | SIPRAS</title>

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

/* SIDEBAR SAMA DENGAN RIWAYAT.PHP */
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
    background: linear-gradient(180deg,#102344 0%,#172f56 100%);
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

/* KONTEN PROFIL */
main {
    margin-left: 260px;
    padding: 32px;
    min-width: 0;
}

.heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
    flex-wrap: wrap;
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

.role-badge {
    display: inline-block;
    padding: 10px 15px;
    background: #e8efff;
    color: #2856bf;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
}

.profile-card {
    max-width: 950px;
    overflow: hidden;
    background: white;
    border: 1px solid #edf0f7;
    border-radius: 17px;
    box-shadow: 0 5px 20px rgba(25,50,90,.025);
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 28px;
    background: linear-gradient(120deg,#12284b,#3268e9);
    color: white;
}

.big-avatar {
    width: 76px;
    height: 76px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(255,255,255,.45);
    border-radius: 20px;
    background: rgba(255,255,255,.18);
    font-size: 32px;
    font-weight: 800;
}

.profile-header h2 {
    margin: 0 0 8px;
    font-size: 22px;
    overflow-wrap: anywhere;
}

.profile-header p {
    margin: 0;
    color: #dbeafe;
    font-size: 13px;
}

.profile-body {
    padding: 30px;
}

.section-title {
    margin: 0 0 7px;
    font-size: 19px;
    color: #1d3151;
}

.description {
    margin: 0 0 25px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 22px;
}

.field label {
    display: block;
    margin-bottom: 9px;
    color: #253653;
    font-size: 13px;
    font-weight: 700;
}

.field input {
    width: 100%;
    min-height: 45px;
    padding: 12px 14px;
    border: 1px solid #dce3ef;
    border-radius: 10px;
    outline: none;
    background: white;
    color: #253653;
    font: inherit;
    font-size: 13px;
}

.field input:focus {
    border-color: #3268e9;
    box-shadow: 0 0 0 3px rgba(50,104,233,.1);
}

.field input:disabled {
    background: #f4f7fc;
    color: #8290a7;
}

.field small {
    display: block;
    margin-top: 7px;
    color: #8290a7;
    font-size: 11px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid #edf0f6;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    background: #3268e9;
    color: white;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.btn:hover {
    background: #2456ca;
}

.alert {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.6;
}

.alert.success {
    background: #dcfce7;
    color: #166534;
}

.alert.error {
    background: #ffebed;
    color: #b42335;
}

.footer {
    margin-top: 25px;
    color: #9aa5b7;
    font-size: 11px;
    text-align: center;
}

@media (max-width: 900px) {
    .form-grid {
        gap: 18px;
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

    .profile-body {
        padding: 20px;
    }

    .profile-header {
        padding: 22px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .actions .btn {
        width: 100%;
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

        <a href="profil.php" class="active">
            <span class="menu-icon">♙</span>
            Profil Saya
        </a>

        <a href="../logout.php" class="logout"
           onclick="return confirm('Yakin ingin keluar?')">
            <span class="menu-icon">⇥</span>
            Keluar
        </a>
    </nav>
</aside>

<main>
    <div class="heading">
        <div>
            <h1>Profil Saya</h1>
            <p>Kelola informasi akun SIPRAS kamu.</p>
        </div>

        <span class="role-badge">PEMINJAM</span>
    </div>

    <section class="profile-card">
        <div class="profile-header">
            <div class="big-avatar">
                <?= h($inisial) ?>
            </div>

            <div>
                <h2><?= h($user['nama']) ?></h2>
                <p>@<?= h($user['username']) ?></p>
                <p style="margin-top:8px">Akun Peminjam SIPRAS</p>
            </div>
        </div>

        <div class="profile-body">
            <?php if ($pesan !== ''): ?>
                <div class="alert success">
                    ✓ <?= h($pesan) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert error">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <h3 class="section-title">Informasi Akun</h3>
            <p class="description">
                Pastikan data akun kamu benar sebelum menyimpan perubahan.
            </p>

            <form method="POST" action="profil.php">
                <div class="form-grid">
                    <div class="field">
                        <label for="nama">Nama Lengkap</label>
                        <input
                            type="text"
                            id="nama"
                            name="nama"
                            maxlength="100"
                            required
                            value="<?= h($user['nama']) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            maxlength="100"
                            required
                            value="<?= h($user['username']) ?>"
                        >
                        <small>Username harus unik.</small>
                    </div>

                    <div class="field">
                        <label for="kelas">Kelas</label>
                        <input
                            type="text"
                            id="kelas"
                            name="kelas"
                            maxlength="100"
                            value="<?= h($user['kelas'] ?? '') ?>"
                            placeholder="Contoh: XII RPL 1"
                        >
                    </div>

                    <div class="field">
                        <label for="no_hp">Nomor HP</label>
                        <input
                            type="tel"
                            id="no_hp"
                            name="no_hp"
                            maxlength="20"
                            value="<?= h($user['no_hp'] ?? '') ?>"
                            placeholder="Masukkan nomor HP"
                        >
                    </div>

                    <div class="field">
                        <label>Jenis Akun</label>
                        <input type="text" value="Peminjam" disabled>
                        <small>Jenis akun ditentukan oleh sistem.</small>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn">
                        ✓ &nbsp; Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman
        Sarana dan Prasarana Sekolah
    </div>
</main>

</body>
</html>
