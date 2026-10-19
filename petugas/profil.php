<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (!isset($_SESSION['id_user']) || $_SESSION['role'] !== 'petugas') {
    header("Location: ../login.php");
    exit;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$id_user = (int) $_SESSION['id_user'];
$pesan = '';
$error = '';

// Ambil data profil petugas
$stmt = mysqli_prepare(
    $koneksi,
    "SELECT * FROM users WHERE id_user = ? LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "i", $id_user);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    die("Data pengguna tidak ditemukan.");
}

// Simpan perubahan profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_baru = trim($_POST['nama'] ?? '');

    // Sesuaikan nama kolom ini dengan tabel users milikmu.
    $username_baru = trim($_POST['username'] ?? '');

    if ($nama_baru === '' || $username_baru === '') {
        $error = 'Nama dan username wajib diisi.';
    } else {
        $cek = mysqli_prepare(
            $koneksi,
            "SELECT id_user FROM users
             WHERE username = ? AND id_user != ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param(
            $cek,
            "si",
            $username_baru,
            $id_user
        );
        mysqli_stmt_execute($cek);
        $hasil_cek = mysqli_stmt_get_result($cek);
        $username_dipakai = mysqli_num_rows($hasil_cek) > 0;
        mysqli_stmt_close($cek);

        if ($username_dipakai) {
            $error = 'Username sudah digunakan akun lain.';
        } else {
            $update = mysqli_prepare(
                $koneksi,
                "UPDATE users SET nama = ?, username = ?
                 WHERE id_user = ?"
            );

            mysqli_stmt_bind_param(
                $update,
                "ssi",
                $nama_baru,
                $username_baru,
                $id_user
            );

            if (mysqli_stmt_execute($update)) {
                $_SESSION['nama'] = $nama_baru;
                $user['nama'] = $nama_baru;
                $user['username'] = $username_baru;
                $pesan = 'Profil berhasil diperbarui.';
            } else {
                $error = 'Profil gagal diperbarui.';
            }

            mysqli_stmt_close($update);
        }
    }
}

$nama = $_SESSION['nama'] ?? $user['nama'] ?? 'Petugas';
$username = $user['username'] ?? '';
$role = ucfirst($user['role'] ?? 'Petugas');
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil Petugas | SIPRAS</title>

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
    font-family: Arial, sans-serif;
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
    background: linear-gradient(180deg, #102344, #172f56);
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
    background: linear-gradient(135deg, #5b91ff, #3268e9);
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
    background: linear-gradient(100deg, #3268e9, #477df4);
    color: white;
    font-weight: 700;
    box-shadow: 0 6px 18px rgba(50,104,233,.25);
}

.menu a.logout {
    margin-top: 12px;
    color: #ffb6bd;
}

main {
    margin-left: 260px;
    padding: 35px;
}

.page-heading {
    margin-bottom: 25px;
}

.page-heading h1 {
    margin: 0 0 8px;
    font-size: 27px;
}

.page-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
}

.card {
    max-width: 850px;
    padding: 28px;
    background: white;
    border: 1px solid #e8edf5;
    border-radius: 20px;
    box-shadow: 0 10px 35px rgba(30,55,90,.05);
}

.card-title {
    margin: 0 0 8px;
    font-size: 19px;
}

.card-description {
    margin: 0 0 25px;
    color: var(--muted);
    font-size: 13px;
}

.identity {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 20px;
    margin-bottom: 25px;
    border-radius: 16px;
    background: #f4f7ff;
}

.big-avatar {
    width: 72px;
    height: 72px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 22px;
    background: #dce8ff;
    color: #244eaa;
    font-size: 30px;
    font-weight: 800;
}

.identity h2 {
    margin: 0 0 7px;
    font-size: 20px;
    overflow-wrap: anywhere;
}

.identity p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}

.role-badge {
    display: inline-block;
    margin-top: 9px;
    padding: 6px 11px;
    border-radius: 20px;
    background: #e2ecff;
    color: #2855b5;
    font-size: 11px;
    font-weight: 700;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 9px;
    font-size: 13px;
    font-weight: 700;
}

.form-group input {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #dce4f0;
    border-radius: 11px;
    outline: none;
    font-size: 14px;
}

.form-group input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(50,104,233,.1);
}

.button {
    padding: 13px 20px;
    border: none;
    border-radius: 11px;
    background: var(--blue);
    color: white;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.button:hover {
    background: #2455ca;
}

.alert {
    padding: 13px 15px;
    margin-bottom: 20px;
    border-radius: 10px;
    font-size: 13px;
}

.success {
    background: #e8f8ee;
    color: #167341;
}

.danger {
    background: #fff0f0;
    color: #b4232c;
}

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

    .card {
        padding: 20px;
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
            <span class="menu-icon">⌂</span>Dashboard
        </a>
        <a href="peminjaman.php">
            <span class="menu-icon">▤</span>Data Peminjaman
        </a>
        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>Pengembalian
        </a>
        <a href="data_alat.php">
            <span class="menu-icon">▣</span>Data Alat
        </a>

        <div class="menu-label" style="margin-top:22px">
            AKUN
        </div>

        <a href="profil.php" class="active">
            <span class="menu-icon">♙</span>Profil Saya
        </a>
        <a href="../logout.php" class="logout">
            <span class="menu-icon">⇥</span>Keluar
        </a>
    </nav>
</aside>

<main>
    <div class="page-heading">
        <h1>Profil Saya</h1>
        <p>Kelola informasi akun petugas sarana dan prasarana.</p>
    </div>

    <section class="card">
        <h2 class="card-title">Informasi Akun</h2>
        <p class="card-description">
            Periksa informasi akun dan perbarui nama atau username.
        </p>

        <?php if ($pesan !== ''): ?>
            <div class="alert success"><?= h($pesan) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="identity">
            <div class="big-avatar">
                <?= h(strtoupper(substr($nama, 0, 1))) ?>
            </div>
            <div>
                <h2><?= h($nama) ?></h2>
                <p><?= h($username) ?></p>
                <span class="role-badge"><?= h($role) ?></span>
            </div>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="nama">Nama Lengkap</label>
                <input
                    type="text"
                    id="nama"
                    name="nama"
                    maxlength="100"
                    value="<?= h($user['nama'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="50"
                    value="<?= h($username) ?>"
                    required
                >
            </div>

            <button type="submit" class="button">
                Simpan Perubahan
            </button>
        </form>
    </section>
</main>

</body>
</html>
