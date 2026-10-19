<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user']) ||
    ($_SESSION['role'] ?? '') !== 'petugas'
) {
    header('Location: ../login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_petugas = (int) $_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? 'Petugas';

function h($teks) {
    return htmlspecialchars(
        (string) ($teks ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function labelStatus($status) {
    $label = [
        'menunggu_persetujuan' => 'Menunggu Persetujuan',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
        'sedang_dipinjam' => 'Sedang Dipinjam',
        'menunggu_pemeriksaan' => 'Menunggu Pemeriksaan',
        'selesai' => 'Selesai',
        'terlambat' => 'Terlambat',
        'dibatalkan' => 'Dibatalkan'
    ];

    return $label[$status] ??
        ucfirst(str_replace('_', ' ', $status));
}
function catatAktivitas(
    $koneksi,
    $id_user,
    $nama,
    $detail
) {
    $aktivitas = 'lainnya';
    $alamat_ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $nama_tabel = 'peminjaman';

    $stmt = $koneksi->prepare("
        INSERT INTO log_aktivitas
            (
                id_user,
                aktivitas,
                detail,
                alamat_ip,
                waktu,
                nama_admin,
                nama_tabel
            )
        VALUES (?, ?, ?, ?, NOW(), ?, ?)
    ");

    $stmt->bind_param(
        'isssss',
        $id_user,
        $aktivitas,
        $detail,
        $alamat_ip,
        $nama,
        $nama_tabel
    );

    $stmt->execute();
    $stmt->close();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pesan = '';
$jenis_pesan = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $id_peminjaman = filter_input(
        INPUT_POST,
        'id_peminjaman',
        FILTER_VALIDATE_INT
    );
    $aksi = $_POST['aksi'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token) ||
        !$id_peminjaman ||
        !in_array($aksi, ['setujui', 'tolak'], true)
    ) {
        $pesan = 'Permintaan tidak valid. Muat ulang halaman.';
        $jenis_pesan = 'error';
    } else {
        try {
            $koneksi->begin_transaction();

            $stmt = $koneksi->prepare("
                SELECT status, kode_peminjaman
                FROM peminjaman
                WHERE id_peminjaman = ?
                FOR UPDATE
            ");

            $stmt->bind_param('i', $id_peminjaman);
            $stmt->execute();

            $peminjaman = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                !$peminjaman ||
                $peminjaman['status'] !== 'menunggu_persetujuan'
            ) {
                throw new Exception(
                    'Pengajuan tidak ditemukan atau sudah diproses.'
                );
            }

            $kode = $peminjaman['kode_peminjaman']
                ?: ('PJM-' . $id_peminjaman);

            if ($aksi === 'tolak') {
                $stmt = $koneksi->prepare("
                    UPDATE peminjaman
                    SET status = 'ditolak',
                        id_petugas_persetuju = ?,
                        waktu_persetujuan = NOW()
                    WHERE id_peminjaman = ?
                      AND status = 'menunggu_persetujuan'
                ");

                $stmt->bind_param(
                    'ii',
                    $id_petugas,
                    $id_peminjaman
                );

                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    $stmt->close();

                    throw new Exception(
                        'Pengajuan gagal ditolak.'
                    );
                }

                $stmt->close();
                catatAktivitas(
                    $koneksi,
                    $id_petugas,
                    $nama,
                    "Petugas menolak pengajuan peminjaman {$kode}."
                );

                $koneksi->commit();

                $pesan = 'Pengajuan berhasil ditolak.';
            } else {
                $stmt = $koneksi->prepare("
                    SELECT
                        d.id_alat,
                        d.jumlah,
                        a.nama_alat,
                        a.stok_tersedia,
                        a.kondisi,
                        a.status AS status_alat
                    FROM detail_peminjaman d
                    INNER JOIN alat a
                        ON a.id_alat = d.id_alat
                    WHERE d.id_peminjaman = ?
                    FOR UPDATE
                ");

                $stmt->bind_param('i', $id_peminjaman);
                $stmt->execute();

                $detail = $stmt->get_result()->fetch_all(
                    MYSQLI_ASSOC
                );

                $stmt->close();

                if (empty($detail)) {
                    throw new Exception(
                        'Detail alat peminjaman tidak ditemukan.'
                    );
                }
                foreach ($detail as $barang) {
                    if (
                        $barang['kondisi'] !== 'baik' ||
                        $barang['status_alat'] !== 'tersedia' ||
                        (int) $barang['stok_tersedia'] <
                            (int) $barang['jumlah']
                    ) {
                        throw new Exception(
                            'Stok atau kondisi alat tidak memenuhi syarat.'
                        );
                    }
                }
                foreach ($detail as $barang) {
                    $stok_baru =
                        (int) $barang['stok_tersedia'] -
                        (int) $barang['jumlah'];

                    $status_alat = $stok_baru > 0
                        ? 'tersedia'
                        : 'dipinjam';

                    $stmt = $koneksi->prepare("
                        UPDATE alat
                        SET stok_tersedia = ?,
                            status = ?
                        WHERE id_alat = ?
                    ");

                    $stmt->bind_param(
                        'isi',
                        $stok_baru,
                        $status_alat,
                        $barang['id_alat']
                    );

                    $stmt->execute();
                    $stmt->close();
                }
                $stmt = $koneksi->prepare("
                    UPDATE peminjaman
                    SET status = 'sedang_dipinjam',
                        id_petugas_persetuju = ?,
                        waktu_persetujuan = NOW()
                    WHERE id_peminjaman = ?
                      AND status = 'menunggu_persetujuan'
                ");

                $stmt->bind_param(
                    'ii',
                    $id_petugas,
                    $id_peminjaman
                );

                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    $stmt->close();

                    throw new Exception(
                        'Status peminjaman gagal diperbarui.'
                    );
                }

                $stmt->close();

                // Simpan log dalam transaksi yang sama.
                catatAktivitas(
                    $koneksi,
                    $id_petugas,
                    $nama,
                    "Petugas menyetujui pengajuan {$kode}; status menjadi sedang dipinjam."
                );

                $koneksi->commit();

                $pesan =
                    'Peminjaman berhasil disetujui dan sekarang berstatus Sedang Dipinjam.';
            }
        } catch (Throwable $e) {
            try {
                $koneksi->rollback();
            } catch (Throwable $rollback_error) {
                // Transaksi mungkin sudah tidak aktif.
            }

            error_log(
                'Proses peminjaman petugas: ' . $e->getMessage()
            );

            $pesan = 'Gagal memproses pengajuan. ' .
                $e->getMessage();

            $jenis_pesan = 'error';
        }
    }
}
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
            CONCAT(
                COALESCE(a.nama_alat, 'Alat tidak ditemukan'),
                ' (',
                d.jumlah,
                ')'
            )
            ORDER BY a.nama_alat SEPARATOR ', '
        ) AS daftar_alat
    FROM peminjaman p
    LEFT JOIN users u
        ON p.id_user = u.id_user
    LEFT JOIN detail_peminjaman d
        ON p.id_peminjaman = d.id_peminjaman
    LEFT JOIN alat a
        ON d.id_alat = a.id_alat
    GROUP BY
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.jam_pinjam,
        p.tanggal_rencana_kembali,
        p.jam_rencana_kembali,
        p.keperluan,
        p.status,
        p.dibuat_pada,
        u.nama
    ORDER BY
        CASE
            WHEN p.status = 'menunggu_persetujuan' THEN 0
            ELSE 1
        END,
        p.id_peminjaman DESC
";

try {
    $hasil = $koneksi->query($sql);
} catch (Throwable $e) {
    error_log('Daftar peminjaman: ' . $e->getMessage());
    $hasil = false;
    $pesan = 'Data peminjaman gagal dimuat. Periksa database.';
    $jenis_pesan = 'error';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Peminjaman - SIPRAS</title>

<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f4f7fc;color:#253653}
.sidebar{position:fixed;inset:0 auto 0 0;width:260px;height:100vh;padding:25px 18px;overflow-y:auto;background:linear-gradient(180deg,#102344,#172f56);color:#fff;z-index:10}
.brand{display:flex;align-items:center;gap:12px;padding:0 7px 25px;border-bottom:1px solid #ffffff20;font-size:25px;font-weight:800;letter-spacing:1px}
.brand span{color:#76a0ff}
.brand-icon{width:45px;height:45px;display:flex;align-items:center;justify-content:center;border-radius:14px;background:linear-gradient(135deg,#5b91ff,#3268e9);font-size:24px}
.profile-mini{display:flex;align-items:center;gap:12px;margin:23px 0 28px;padding:14px 12px;background:#ffffff12;border:1px solid #ffffff14;border-radius:15px}
.profile-avatar{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border-radius:14px;background:#dce8ff;color:#244eaa;font-size:21px;font-weight:800}
.profile-info{min-width:0}
.profile-name{font-size:13px;font-weight:700;overflow-wrap:anywhere}
.profile-role{margin-top:5px;color:#a9bbd8;font-size:11px}
.menu-label{margin:0 10px 12px;color:#8297b8;font-size:10px;font-weight:800;letter-spacing:1.5px}
.menu{display:flex;flex-direction:column;gap:7px}
.menu a{display:flex;align-items:center;gap:13px;padding:13px 14px;border-radius:12px;color:#c5d2e7;font-size:13px;text-decoration:none;transition:.2s}
.menu a:hover{background:#ffffff17;color:white}
.menu a.active{background:linear-gradient(100deg,#3268e9,#477df4);color:white;font-weight:700}
.menu a.logout{margin-top:12px;color:#ffb6bd}
.menu-icon{width:23px;flex-shrink:0;text-align:center;font-size:18px}
main{margin-left:260px;padding:32px;min-width:0}
.page-heading{margin-bottom:25px}
.page-heading h1{margin:0 0 8px;font-size:27px}
.page-heading p{margin:0;color:#8290a7;font-size:13px}
.panel{background:#fff;border:1px solid #e9eef7;border-radius:18px;padding:22px;box-shadow:0 8px 30px #182f550a}
.panel-heading{margin:0 0 18px;font-size:17px}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:18px;font-size:13px;line-height:1.6;overflow-wrap:anywhere}
.alert.success{color:#176b46;background:#e8f8ef}
.alert.error{color:#a22d3c;background:#fff0f1}
.table-wrap{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th{padding:13px 12px;background:#f5f7fc;color:#6c7b94;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
td{padding:15px 12px;border-bottom:1px solid #edf1f7;font-size:12px;vertical-align:top;line-height:1.6}
.kode{color:#3268e9;font-weight:800}
.status{display:inline-block;padding:6px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.status.menunggu_persetujuan{color:#98610b;background:#fff3d8}
.status.disetujui,.status.selesai{color:#18734d;background:#e5f8ed}
.status.ditolak,.status.dibatalkan{color:#a52c3b;background:#ffeaed}
.status.sedang_dipinjam,.status.menunggu_pemeriksaan{color:#2456b3;background:#e9f0ff}
.status.terlambat{color:#b33b19;background:#ffebe2}
.actions{display:flex;flex-direction:column;gap:7px;min-width:95px}
.btn{width:100%;border:none;border-radius:8px;padding:9px 11px;color:white;font-size:11px;font-weight:700;cursor:pointer}
.btn-approve{background:#168653}
.btn-reject{background:#dc4656}
.btn:hover{opacity:.85}
.muted{color:#8290a7}
.empty{padding:35px;text-align:center;color:#8290a7}
@media(max-width:650px){
.sidebar{position:relative;width:100%;height:auto;padding:18px}
.brand{padding-bottom:18px}
.profile-mini{margin:16px 0}
.menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
.menu a{gap:7px;padding:11px 8px;font-size:11px}
main{margin-left:0;padding:18px}
.panel{padding:14px}
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

        <a href="peminjaman.php" class="active">
            <span class="menu-icon">▤</span>Data Peminjaman
        </a>

        <a href="pengembalian.php">
            <span class="menu-icon">↩</span>Pengembalian
        </a>

        <a href="data_alat.php">
            <span class="menu-icon">▣</span>Data Alat
        </a>

        <a href="riwayat.php">
            <span class="menu-icon">📋</span>Riwayat Aktivitas
        </a>

        <div class="menu-label" style="margin-top:22px">AKUN</div>

        <a href="profil.php">
            <span class="menu-icon">♙</span>Profil Saya
        </a>

        <a href="../logout.php"
           class="logout"
           onclick="return confirm('Yakin ingin keluar?')">
            <span class="menu-icon">⇥</span>Keluar
        </a>
    </nav>
</aside>

<main>
    <div class="page-heading">
        <h1>Data Peminjaman</h1>
        <p>Kelola dan periksa pengajuan peminjaman sarana sekolah.</p>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= h($jenis_pesan) ?>">
            <?= h($pesan) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2 class="panel-heading">Daftar Pengajuan Peminjaman</h2>

        <?php if (!$hasil): ?>
            <div class="alert error">
                Data gagal dimuat. Periksa struktur tabel dan kolom database.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Peminjam</th>
                            <th>Alat dan Jumlah</th>
                            <th>Waktu Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Keperluan</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($hasil->num_rows > 0): ?>
                        <?php while ($row = $hasil->fetch_assoc()): ?>
                            <tr>
                                <td class="kode">
                                    <?= h(
                                        $row['kode_peminjaman']
                                        ?: ('PJM-' . $row['id_peminjaman'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= h($row['nama_peminjam'] ?: 'Pengguna') ?>
                                </td>

                                <td>
                                    <?= h($row['daftar_alat'] ?: 'Belum ada detail alat') ?>
                                </td>

                                <td>
                                    <?= h($row['tanggal_pinjam']) ?><br>
                                    <?= h($row['jam_pinjam']) ?>
                                </td>

                                <td>
                                    <?= h($row['tanggal_rencana_kembali']) ?><br>
                                    <?= h($row['jam_rencana_kembali']) ?>
                                </td>

                                <td>
                                    <?= h($row['keperluan'] ?: '-') ?>
                                </td>

                                <td>
                                    <span class="status <?= h($row['status']) ?>">
                                        <?= h(labelStatus($row['status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($row['status'] === 'menunggu_persetujuan'): ?>
                                        <div class="actions">
                                            <form method="POST"
                                                  onsubmit="return confirm('Setujui pengajuan ini?')">
                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= h($_SESSION['csrf_token']) ?>">

                                                <input type="hidden"
                                                       name="id_peminjaman"
                                                       value="<?= (int) $row['id_peminjaman'] ?>">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="setujui">

                                                <button class="btn btn-approve"
                                                        type="submit">
                                                    Setujui
                                                </button>
                                            </form>

                                            <form method="POST"
                                                  onsubmit="return confirm('Tolak pengajuan ini?')">
                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= h($_SESSION['csrf_token']) ?>">

                                                <input type="hidden"
                                                       name="id_peminjaman"
                                                       value="<?= (int) $row['id_peminjaman'] ?>">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="tolak">

                                                <button class="btn btn-reject"
                                                        type="submit">
                                                    Tolak
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">Sudah diproses</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty">
                                Belum ada data peminjaman.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
