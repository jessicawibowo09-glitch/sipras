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
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

$aktivitas = [];
$pesan_error = '';

try {
    $stmt = $koneksi->prepare("
        SELECT id_log, aktivitas, detail, waktu, nama_tabel
        FROM log_aktivitas
        WHERE id_user = ?
        ORDER BY waktu DESC, id_log DESC
    ");

    $stmt->bind_param('i', $id_user);
    $stmt->execute();
    $hasil = $stmt->get_result();

    while ($row = $hasil->fetch_assoc()) {
        $aktivitas[] = $row;
    }

    $stmt->close();
} catch (Throwable $e) {
    error_log('Gagal memuat log peminjam: ' . $e->getMessage());
    $pesan_error = 'Log aktivitas gagal dimuat. Periksa struktur tabel database.';
}

$total = count($aktivitas);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log Aktivitas | SIPRAS</title>

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

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    padding: 25px 18px;
    overflow-y: auto;
    background: linear-gradient(180deg,#102344,#172f56);
    color: white;
    box-shadow: 8px 0 30px rgba(15,35,70,.07);
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
}

.menu a.active {
    background: linear-gradient(100deg,#3268e9,#477df4);
    color: white;
    font-weight: 700;
}

.menu a.logout {
    margin-top: 12px;
    color: #ffb6bd;
}

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
    color: #1b2e4d;
    font-size: 27px;
}

.heading p {
    margin: 0;
    color: #8290a7;
    font-size: 14px;
    line-height: 1.7;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 22px;
    padding: 20px;
    background: white;
    border: 1px solid #edf0f7;
    border-radius: 16px;
    box-shadow: 0 5px 20px rgba(25,50,90,.025);
}

.stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: #eaf0ff;
    color: #3268e9;
    font-size: 23px;
}

.stat-label {
    margin-bottom: 5px;
    color: #8290a7;
    font-size: 12px;
}

.stat-number {
    color: #1b2e4d;
    font-size: 25px;
    font-weight: 800;
}

.panel {
    padding: 24px;
    background: white;
    border: 1px solid #edf0f7;
    border-radius: 17px;
    box-shadow: 0 5px 20px rgba(25,50,90,.025);
}

.panel h2 {
    margin: 0 0 7px;
    color: #1d3151;
    font-size: 19px;
}

.description {
    margin: 0 0 22px;
    color: #8290a7;
    font-size: 13px;
    line-height: 1.7;
}

.activity-list {
    display: flex;
    flex-direction: column;
}

.activity-item {
    display: flex;
    gap: 15px;
    padding: 19px 0;
    border-bottom: 1px solid #edf0f6;
}

.activity-item:first-child {
    padding-top: 5px;
}

.activity-item:last-child {
    border-bottom: 0;
    padding-bottom: 5px;
}

.activity-icon {
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: #eaf0ff;
    color: #3268e9;
    font-size: 19px;
}

.activity-content {
    min-width: 0;
    flex: 1;
}

.activity-title {
    margin-bottom: 6px;
    color: #243653;
    font-size: 14px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.activity-detail {
    margin-bottom: 8px;
    color: #697991;
    font-size: 13px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.activity-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    color: #98a3b5;
    font-size: 11px;
}

.table-tag {
    padding: 4px 7px;
    border-radius: 6px;
    background: #f0f3f9;
    color: #64748b;
}

.empty-state {
    padding: 45px 20px;
    text-align: center;
}

.empty-icon {
    width: 62px;
    height: 62px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    border-radius: 20px;
    background: #edf3ff;
    color: #3268e9;
    font-size: 28px;
}

.empty-state h3 {
    margin: 0 0 8px;
    color: #243653;
    font-size: 16px;
}

.empty-state p {
    max-width: 400px;
    margin: 0 auto;
    color: #8290a7;
    font-size: 13px;
    line-height: 1.8;
}

.alert {
    margin-bottom: 18px;
    padding: 14px 16px;
    border-radius: 10px;
    background: #ffebed;
    color: #b42335;
    font-size: 13px;
    line-height: 1.6;
}

.footer {
    margin-top: 25px;
    color: #9aa5b7;
    font-size: 11px;
    text-align: center;
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

    .panel {
        padding: 17px;
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
            <span class="menu-icon">⌂</span>Dashboard
        </a>
        <a href="alat.php">
            <span class="menu-icon">▤</span>Daftar Alat
        </a>
        <a href="peminjaman.php">
            <span class="menu-icon">＋</span>Ajukan Peminjaman
        </a>
        <a href="riwayat.php">
            <span class="menu-icon">◷</span>Riwayat Peminjaman
        </a>
        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>Pengembalian
        </a>
        <a href="log_aktivitas.php" class="active">
            <span class="menu-icon">◉</span>Log Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">
            AKUN
        </div>
        <a href="profil.php">
            <span class="menu-icon">♙</span>Profil Saya
        </a>
        <a href="../logout.php" class="logout">
            <span class="menu-icon">⇥</span>Keluar
        </a>
    </nav>
</aside>

<main>
    <div class="heading">
        <h1>Log Aktivitas</h1>
        <p>
            Halo, <?= h($nama) ?>!<br>
            Pantau catatan aktivitas akun peminjamanmu di SIPRAS.
        </p>
    </div>

    <?php if ($pesan_error !== ''): ?>
        <div class="alert"><?= h($pesan_error) ?></div>
    <?php endif; ?>

    <section class="stat-card">
        <div class="stat-icon">◉</div>
        <div>
            <div class="stat-label">Total Aktivitas Tercatat</div>
            <div class="stat-number"><?= $total ?></div>
        </div>
    </section>

    <section class="panel">
        <h2>Aktivitas Saya</h2>
        <p class="description">
            Daftar aktivitas diurutkan dari yang terbaru.
        </p>

        <?php if ($total > 0): ?>
            <div class="activity-list">
                <?php foreach ($aktivitas as $item): ?>
                    <article class="activity-item">
                        <div class="activity-icon">✓</div>

                        <div class="activity-content">
                            <div class="activity-title">
                                <?= h($item['aktivitas']) ?>
                            </div>

                            <?php if (!empty($item['detail'])): ?>
                                <div class="activity-detail">
                                    <?= nl2br(h($item['detail'])) ?>
                                </div>
                            <?php endif; ?>

                            <div class="activity-meta">
                                <span>
                                    ◷
                                    <?= h(date(
                                        'd/m/Y H:i',
                                        strtotime($item['waktu'])
                                    )) ?>
                                </span>

                                <?php if (!empty($item['nama_tabel'])): ?>
                                    <span class="table-tag">
                                        <?= h($item['nama_tabel']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">◷</div>
                <h3>Belum Ada Aktivitas</h3>
                <p>
                    Aktivitasmu akan tampil di sini setelah sistem
                    pencatatan log dihubungkan dengan proses peminjaman,
                    pengembalian, dan aktivitas lainnya.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <div class="footer">
        © <?= date('Y') ?> SIPRAS · Sistem Informasi Peminjaman
        Sarana dan Prasarana Sekolah
    </div>
</main>

</body>
</html>
