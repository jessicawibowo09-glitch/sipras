
<?php
session_start();
require_once "koneksi.php";

$error = "";
$success = "";
$mode = $_POST["mode"] ?? "login";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($mode === "daftar") {
        $nama = trim($_POST["nama"] ?? "");
        $kelas = trim($_POST["kelas"] ?? "");
        $no_hp = trim($_POST["no_hp"] ?? "");

        if ($nama === "" || $username === "" ||
            $password === "" || $kelas === "") {
            $error = "Mohon lengkapi semua kolom wajib.";
        } elseif (strlen($password) < 8) {
            $error = "Password minimal 8 karakter.";
        } else {
            $cek = $koneksi->prepare(
                "SELECT id_user FROM users WHERE username = ? LIMIT 1"
            );
            $cek->bind_param("s", $username);
            $cek->execute();
            $ada = $cek->get_result()->num_rows > 0;
            $cek->close();

            if ($ada) {
                $error = "Username sudah digunakan.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $koneksi->prepare(
                    "INSERT INTO users
                    (nama, username, password, role, kelas, no_hp, aktif)
                    VALUES (?, ?, ?, 'peminjam', ?, ?, 1)"
                );
                $stmt->bind_param(
                    "sssss", $nama, $username, $hash, $kelas, $no_hp
                );

                if ($stmt->execute()) {
                    $success = "Akun berhasil dibuat! Silakan login.";
                    $mode = "login";
                } else {
                    $error = "Pendaftaran gagal. Silakan coba lagi.";
                }
                $stmt->close();
            }
        }
    } else {
        if ($username === "" || $password === "") {
            $error = "Masukkan username dan password.";
        } else {
            $stmt = $koneksi->prepare(
                "SELECT id_user, nama, username, password, role
                 FROM users WHERE username = ? AND aktif = 1 LIMIT 1"
            );
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $valid = false;

            if ($user) {
                $stored = (string)$user["password"];
                $info = password_get_info($stored);
                $isHash = !empty($info["algo"]);

                $valid = $isHash
                    ? password_verify($password, $stored)
                    : hash_equals($stored, $password);
            }

            if ($valid) {
                session_regenerate_id(true);
                $_SESSION["id_user"] = $user["id_user"];
                $_SESSION["nama"] = $user["nama"];
                $_SESSION["role"] = $user["role"];

                $halaman = [
                    "admin" => "admin/dashboard.php",
                    "petugas" => "petugas/dashboard.php",
                    "peminjam" => "peminjam/dashboard.php"
                ];

                if (isset($halaman[$user["role"]])) {
                    header("Location: " . $halaman[$user["role"]]);
                    exit;
                }

                $error = "Role akun tidak dikenali.";
            } else {
                $error = "Username atau password salah.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SIPRAS | Sistem Peminjaman Sarana</title>
<style>
:root {
    --navy: #101b36;
    --blue: #4361ee;
    --blue-dark: #304bd1;
    --muted: #8993a7;
    --border: #e6eaf2;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f3f5fa;
    color: var(--navy);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.page {
    width: 100%;
    max-width: 1000px;
    min-height: 620px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: white;
    border-radius: 25px;
    overflow: hidden;
    box-shadow: 0 25px 80px #18264b18;
    animation: muncul .55s ease;
}
@keyframes muncul {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.kiri {
    background: linear-gradient(145deg, #101b36, #1b3262);
    color: white;
    padding: 48px 42px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}
.kiri:before, .kiri:after {
    content: "";
    position: absolute;
    border: 1px solid #ffffff17;
    border-radius: 50%;
    width: 290px;
    height: 290px;
    right: -140px;
    top: 100px;
}
.kiri:after {
    width: 400px;
    height: 400px;
    right: -210px;
    top: 50px;
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}
.logo {
    width: 47px;
    height: 47px;
    display: grid;
    place-items: center;
    background: #ffffff17;
    border: 1px solid #ffffff30;
    border-radius: 14px;
    font-weight: 800;
    font-size: 18px;
}
.brand-name { font-size: 21px; font-weight: 800; letter-spacing: 1px; }
.brand-sub { font-size: 11px; color: #bac7e7; margin-top: 3px; }
.intro { position: relative; z-index: 1; margin: 35px 0; }
.eyebrow {
    color: #a9bcff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}
.intro h1 {
    font-size: clamp(30px, 4vw, 43px);
    line-height: 1.17;
    margin: 18px 0;
    letter-spacing: -1.5px;
}
.intro p {
    color: #c2cce0;
    line-height: 1.8;
    font-size: 14px;
    max-width: 330px;
}
.features { display: grid; gap: 17px; position: relative; z-index: 1; }
.feature { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #e0e7f8; }
.check {
    width: 27px; height: 27px; display: grid; place-items: center;
    background: #ffffff15; border-radius: 9px; color: #a9bcff;
}
.footer { color: #94a5ca; font-size: 11px; position: relative; z-index: 1; margin-top: 40px; }
.kanan { padding: 48px 45px; display: flex; flex-direction: column; justify-content: center; }
.form-head { margin-bottom: 27px; }
.form-head h2 { font-size: 28px; letter-spacing: -.8px; margin: 0 0 9px; }
.form-head p { color: var(--muted); font-size: 13px; margin: 0; line-height: 1.6; }
.tabs {
    display: flex; padding: 5px; background: #f1f3f8;
    border-radius: 12px; gap: 5px; margin-bottom: 25px;
}
.tabs button {
    flex: 1; border: 0; padding: 12px; border-radius: 9px;
    color: #7d879b; background: transparent; cursor: pointer;
    font-weight: 700; font-size: 13px; transition: .2s;
}
.tabs button.active {
    color: var(--blue); background: white;
    box-shadow: 0 2px 8px #1720330b;
}
.message {
    padding: 12px 14px; border-radius: 10px;
    font-size: 12px; line-height: 1.6; margin-bottom: 17px;
}
.error { background: #fff0f0; color: #bd3030; border: 1px solid #ffdada; }
.success { background: #eafaf1; color: #187747; border: 1px solid #c9f0d9; }
.field { margin-bottom: 17px; }
.field label {
    display: block; margin-bottom: 8px; font-size: 12px;
    font-weight: 700; color: #35415b;
}
.field input {
    width: 100%; height: 46px; padding: 0 14px;
    border: 1px solid var(--border); border-radius: 10px;
    outline: none; font-size: 13px; color: var(--navy);
    background: #fff; transition: .2s;
}
.field input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px #4361ee15;
}
.field input::placeholder { color: #b0b7c6; }
.grid-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.submit {
    width: 100%; height: 48px; border: 0; border-radius: 10px;
    color: white; background: var(--blue); font-size: 13px;
    font-weight: 800; cursor: pointer; margin-top: 3px;
    box-shadow: 0 7px 18px #4361ee35; transition: .2s;
}
.submit:hover { background: var(--blue-dark); transform: translateY(-1px); }
.note { text-align: center; font-size: 11px; color: #a0a8b8; margin-top: 22px; line-height: 1.7; }
.hidden { display: none !important; }
@media (max-width: 720px) {
    body { padding: 12px; }
    .page { grid-template-columns: 1fr; max-width: 450px; }
    .kiri { padding: 25px 28px; min-height: auto; }
    .intro { margin: 30px 0 18px; }
    .intro h1 { font-size: 29px; }
    .intro p { margin-bottom: 0; }
    .features, .footer { display: none; }
    .kanan { padding: 30px 25px; }
    .grid-fields { grid-template-columns: 1fr; gap: 0; }
}
</style>
</head>
<body>
<main class="page">
    <section class="kiri">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <div class="brand-name">SIPRAS</div>
                <div class="brand-sub">SISTEM PEMINJAMAN SARANA</div>
            </div>
        </div>

        <div class="intro">
            <div class="eyebrow">School Facility Management</div>
            <h1>Kelola sarana sekolah dengan lebih mudah.</h1>
            <p>
                Satu platform untuk mengelola peminjaman,
                pengembalian, dan informasi fasilitas sekolah
                secara praktis dan teratur.
            </p>
        </div>

        <div class="features">
            <div class="feature"><span class="check">✓</span> Peminjaman lebih terorganisir</div>
            <div class="feature"><span class="check">✓</span> Data tersimpan dalam satu sistem</div>
            <div class="feature"><span class="check">✓</span> Akses sesuai peran pengguna</div>
        </div>

        <div class="footer">© <?= date("Y") ?> SIPRAS · Sistem Informasi Sarana Sekolah</div>
    </section>

    <section class="kanan">
        <div class="form-head">
            <h2 id="judul"><?= $mode === "daftar" ? "Buat akun baru" : "Selamat datang!" ?></h2>
            <p id="deskripsi"><?= $mode === "daftar" ? "Daftarkan dirimu sebagai peminjam." : "Masuk untuk melanjutkan ke akun SIPRAS kamu." ?></p>
        </div>

        <div class="tabs">
            <button type="button" id="tabLogin"
                class="<?= $mode === 'login' ? 'active' : '' ?>"
                onclick="ubahMode('login')">Masuk</button>
            <button type="button" id="tabDaftar"
                class="<?= $mode === 'daftar' ? 'active' : '' ?>"
                onclick="ubahMode('daftar')">Buat Akun</button>
        </div>

        <?php if ($error !== ""): ?>
            <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="mode" id="mode"
                value="<?= htmlspecialchars($mode, ENT_QUOTES, "UTF-8") ?>">

            <div class="field <?= $mode === 'daftar' ? '' : 'hidden' ?>" id="boxNama">
                <label for="nama">Nama lengkap *</label>
                <input id="nama" name="nama" placeholder="Masukkan nama lengkap"
                    maxlength="100"
                    value="<?= htmlspecialchars($_POST['nama'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    <?= $mode === 'daftar' ? 'required' : '' ?>>
            </div>

            <div class="field">
                <label for="username">Username *</label>
                <input id="username" name="username" placeholder="Masukkan username"
                    maxlength="50" autocomplete="username" required
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="field">
                <label for="password">Password *</label>
                <input id="password" type="password" name="password"
                    placeholder="<?= $mode === 'daftar' ? 'Minimal 8 karakter' : 'Masukkan password' ?>"
                    autocomplete="<?= $mode === 'daftar' ? 'new-password' : 'current-password' ?>"
                    minlength="<?= $mode === 'daftar' ? '8' : '1' ?>" required>
            </div>

            <div id="boxDetail" class="<?= $mode === 'daftar' ? '' : 'hidden' ?>">
                <div class="grid-fields">
                    <div class="field">
                        <label for="kelas">Kelas *</label>
                        <input id="kelas" name="kelas" placeholder="Contoh: XI RPL 1"
                            maxlength="30"
                            value="<?= htmlspecialchars($_POST['kelas'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            <?= $mode === 'daftar' ? 'required' : '' ?>>
                    </div>
                    <div class="field">
                        <label for="no_hp">Nomor HP</label>
                        <input id="no_hp" name="no_hp" placeholder="Opsional"
                            maxlength="25"
                            value="<?= htmlspecialchars($_POST['no_hp'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit" id="btnSubmit">
                <?= $mode === 'daftar' ? 'Buat Akun Peminjam →' : 'Masuk ke SIPRAS →' ?>
            </button>
        </form>

        <div class="note">
            Dengan melanjutkan, kamu menggunakan sistem peminjaman
            sarana sekolah sesuai akun yang terdaftar.
        </div>
    </section>
</main>

<script>
function ubahMode(mode) {
    const daftar = mode === "daftar";

    document.getElementById("mode").value = mode;
    document.getElementById("tabLogin").classList.toggle("active", !daftar);
    document.getElementById("tabDaftar").classList.toggle("active", daftar);

    document.getElementById("boxNama").classList.toggle("hidden", !daftar);
    document.getElementById("boxDetail").classList.toggle("hidden", !daftar);

    document.getElementById("nama").required = daftar;
    document.getElementById("kelas").required = daftar;
    document.getElementById("password").minLength = daftar ? 8 : 1;
    document.getElementById("password").placeholder =
        daftar ? "Minimal 8 karakter" : "Masukkan password";

    document.getElementById("judul").textContent =
        daftar ? "Buat akun baru" : "Selamat datang!";
    document.getElementById("deskripsi").textContent =
        daftar ? "Daftarkan dirimu sebagai peminjam." :
        "Masuk untuk melanjutkan ke akun SIPRAS kamu.";
    document.getElementById("btnSubmit").textContent =
        daftar ? "Buat Akun Peminjam →" : "Masuk ke SIPRAS →";
}
</script>
</body>
</html>
