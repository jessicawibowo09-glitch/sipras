<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user']) ||
    ($_SESSION['role'] ?? '') !== 'peminjam'
) {
    header('Location: ../login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$nama = $_SESSION['nama'] ?? 'Peminjam';
$id_user = (int) $_SESSION['id_user'];
$pesan = '';
$jenis = 'success';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $id_peminjaman = filter_input(
        INPUT_POST,
        'id_peminjaman',
        FILTER_VALIDATE_INT
    );

    if (
        !hash_equals($_SESSION['csrf_token'], $token) ||
        !$id_peminjaman
    ) {
        $pesan = 'Permintaan tidak valid. Silakan coba lagi.';
        $jenis = 'error';
    } else {
        try {
            $koneksi->begin_transaction();

            $stmt = $koneksi->prepare(
                "SELECT id_peminjaman, kode_peminjaman, status
                 FROM peminjaman
                 WHERE id_peminjaman = ?
                   AND id_user = ?
                 FOR UPDATE"
            );
            $stmt->bind_param(
                'ii',
                $id_peminjaman,
                $id_user
            );
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$data) {
                throw new Exception('Data peminjaman tidak ditemukan.');
            }

            if (!in_array(
                $data['status'],
                ['disetujui', 'sedang_dipinjam', 'terlambat'],
                true
            )) {
                throw new Exception(
                    'Peminjaman belum bisa dikembalikan. Status saat ini: ' .
                    $data['status']
                );
            }

            $stmt = $koneksi->prepare(
                "UPDATE peminjaman
                 SET status = 'menunggu_pemeriksaan'
                 WHERE id_peminjaman = ?
                   AND id_user = ?"
            );
            $stmt->bind_param(
                'ii',
                $id_peminjaman,
                $id_user
            );
            $stmt->execute();
            $stmt->close();

            $koneksi->commit();
            try {
                $aktivitas = 'Mengajukan pengembalian';
                $detail = 'Peminjam mengajukan pengembalian untuk kode peminjaman: ' .
                          $data['kode_peminjaman'];
                $alamat_ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $nama_tabel = 'peminjaman';

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
                    $nama,
                    $nama_tabel
                );

                $stmt_log->execute();
                $stmt_log->close();
            } catch (Throwable $e) {
                error_log(
                    'Gagal mencatat log pengembalian: ' .
                    $e->getMessage()
                );
            }

            $pesan = 'Berhasil! Pengajuan pengembalian sudah dikirim ke petugas.';
        } catch (Throwable $e) {
            try {
                $koneksi->rollback();
            } catch (Throwable $rollbackError) {
                error_log($rollbackError->getMessage());
            }

            $pesan = $e->getMessage();
            $jenis = 'error';
        }
    }
}

/* DAFTAR PEMINJAMAN MILIK SISWA */
$stmt = $koneksi->prepare(
    "SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.status,
        GROUP_CONCAT(
            CONCAT(a.nama_alat, ' (', d.jumlah, ')')
            ORDER BY a.nama_alat SEPARATOR ', '
        ) AS daftar_alat
     FROM peminjaman p
     LEFT JOIN detail_peminjaman d
        ON d.id_peminjaman = p.id_peminjaman
     LEFT JOIN alat a
        ON a.id_alat = d.id_alat
     WHERE p.id_user = ?
     GROUP BY
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.status,
        p.dibuat_pada
     ORDER BY p.id_peminjaman DESC"
);
$stmt->bind_param('i', $id_user);
$stmt->execute();
$hasil = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pengembalian Saya - SIPRAS</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f4f7fc;color:#263753}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:250px;background:#142849;color:white;padding:25px 18px;overflow-y:auto}
.brand{font-size:25px;font-weight:800;padding:0 8px 25px;border-bottom:1px solid #ffffff25}
.brand span{color:#80a8ff}
.profile{margin:22px 0;padding:15px;background:#ffffff12;border-radius:12px}
.profile small{display:block;margin-top:5px;color:#bdcbe0}
.menu-label{font-size:10px;color:#91a5c4;letter-spacing:1.3px;margin:24px 10px 12px}
nav a{display:block;color:#d0daeb;text-decoration:none;padding:13px 12px;border-radius:10px;margin:5px 0;font-size:13px}
nav a:hover,nav a.active{background:#356be9;color:white}
nav a.logout{color:#ffb8bd}
main{margin-left:250px;padding:32px;min-width:0}
h1{margin:0 0 8px;font-size:27px}
.sub{margin-bottom:25px;color:#8290a7;font-size:13px}
.panel{background:white;padding:22px;border-radius:16px;border:1px solid #e9eef7;box-shadow:0 8px 30px #182f5508}
h2{font-size:17px;margin:0 0 20px}
.alert{padding:13px 15px;margin-bottom:18px;border-radius:10px;font-size:13px;line-height:1.6}
.success{background:#e7f8ee;color:#176b46}
.error{background:#fff0f1;color:#a22d3c}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:750px}
th{background:#f5f7fc;text-align:left;color:#6c7b94;font-size:11px;padding:13px}
td{padding:14px 12px;border-bottom:1px solid #edf1f7;font-size:12px;line-height:1.6;vertical-align:top}
.kode{font-weight:800;color:#3268e9}
.status{display:inline-block;padding:6px 9px;border-radius:20px;font-size:10px;font-weight:700;background:#e9f0ff;color:#2456b3}
.status.menunggu_persetujuan{background:#fff3d8;color:#98610b}
.status.ditolak{background:#ffeaed;color:#a52c3b}
.status.selesai{background:#e5f8ed;color:#18734d}
.status.menunggu_pemeriksaan{background:#fff3d8;color:#98610b}
button{border:0;border-radius:8px;background:#3268e9;color:white;padding:10px 12px;font-size:11px;font-weight:700;cursor:pointer}
button:hover{background:#2454c5}
button:disabled{background:#a5afc0;cursor:not-allowed}
.empty{text-align:center;padding:30px;color:#8290a7}
@media(max-width:700px){.sidebar{position:relative;width:100%;height:auto}.profile{margin:15px 0}main{margin:0;padding:18px}.panel{padding:14px}}
</style>
</head>
<body>
<aside class="sidebar">
    <div class="brand">SI<span>PRAS</span></div>
    <div class="profile">
        <strong><?= h($nama) ?></strong>
        <small>Peminjam</small>
    </div>
    <nav>
        <div class="menu-label">MENU UTAMA</div>
        <a href="dashboard.php">⌂ &nbsp; Dashboard</a>
        <a href="daftar_alat.php">▣ &nbsp; Daftar Alat</a>
        <a href="peminjaman.php">▤ &nbsp; Peminjaman</a>
        <a href="riwayat.php">◷ &nbsp; Riwayat Peminjaman</a>
        <a href="pengembalian.php" class="active">↩ &nbsp; Pengembalian</a>
        <a href="log_aktivitas.php">◉ &nbsp; Log Aktivitas</a>
        <a href="sanksi.php">⚑ &nbsp; Sanksi Saya</a>
        <div class="menu-label">AKUN</div>
        <a href="profil.php">♙ &nbsp; Profil Saya</a>
        <a href="../logout.php" class="logout">⇥ &nbsp; Keluar</a>
    </nav>
</aside>

<main>
    <h1>Pengembalian Saya</h1>
    <div class="sub">Ajukan pengembalian alat yang sedang kamu pinjam.</div>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= h($jenis) ?>"><?= h($pesan) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Daftar Peminjaman</h2>
        <?php if ($hasil->num_rows === 0): ?>
            <div class="empty">Kamu belum mempunyai data peminjaman.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Kode Peminjaman</th>
                        <th>Alat</th>
                        <th>Rencana Kembali</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $hasil->fetch_assoc()): ?>
                    <?php
                    $status = $row['status'];
                    $bisa_kembali = in_array(
                        $status,
                        ['disetujui', 'sedang_dipinjam', 'terlambat'],
                        true
                    );
                    ?>
                    <tr>
                        <td class="kode"><?= h($row['kode_peminjaman']) ?></td>
                        <td><?= h($row['daftar_alat'] ?: '-') ?></td>
                        <td>
                            <?= h($row['tanggal_rencana_kembali']) ?><br>
                            <?= h($row['jam_rencana_kembali']) ?>
                        </td>
                        <td>
                            <span class="status <?= h($status) ?>">
                                <?= h(ucfirst(str_replace('_', ' ', $status))) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($bisa_kembali): ?>
                                <form method="POST" onsubmit="return confirm('Ajukan pengembalian kepada petugas?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="id_peminjaman" value="<?= (int)$row['id_peminjaman'] ?>">
                                    <button type="submit">Ajukan Pengembalian</button>
                                </form>
                            <?php elseif ($status === 'menunggu_pemeriksaan'): ?>
                                <span>Menunggu petugas memeriksa</span>
                            <?php elseif ($status === 'menunggu_persetujuan'): ?>
                                <span>Menunggu persetujuan petugas</span>
                            <?php elseif ($status === 'selesai'): ?>
                                <span>Pengembalian selesai</span>
                            <?php else: ?>
                                <span><?= h(ucfirst(str_replace('_', ' ', $status))) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
