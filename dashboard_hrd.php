<?php
session_start();

error_log("DEBUG (dashboard_hrd.php): Halaman diakses.");
error_log("DEBUG (dashboard_hrd.php): Sesi ID: " . ($_SESSION['id_karyawan'] ?? 'N/A'));
error_log("DEBUG (dashboard_hrd.php): Sesi Jabatan: " . ($_SESSION['jabatan'] ?? 'N/A'));
error_log("DEBUG (dashboard_hrd.php): Sesi Role: " . ($_SESSION['role'] ?? 'N/A'));

include 'includes/db_connection.php';
include 'includes/date_formatter.php';

if (!isset($_SESSION['id_karyawan']) || $_SESSION['role'] !== 'HRD') {
    error_log("DEBUG (dashboard_hrd.php): Akses ditolak. Role: " . ($_SESSION['role'] ?? 'Tidak terdaftar'));
    header("Location: hrd/index.php");
    exit();
}

$id_hrd = $_SESSION['id_karyawan'];
$nama_hrd = $_SESSION['nama_lengkap'];
$jabatan_hrd = $_SESSION['jabatan'];
$role_hrd = $_SESSION['role'];

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_cuti'])) {
    $id_cuti = intval($_POST['id_cuti']);
    $status_baru = $_POST['status_cuti'];
    $komentar_hrd = trim($_POST['komentar_hrd']);

    $conn->begin_transaction();
    try {
        $stmt_get_old_cuti = $conn->prepare("SELECT id_karyawan, jumlah_hari, jenis_cuti, status_cuti FROM cuti WHERE id_cuti = ?");
        $stmt_get_old_cuti->bind_param("i", $id_cuti);
        $stmt_get_old_cuti->execute();
        $result_old_cuti = $stmt_get_old_cuti->get_result();
        $old_cuti_data = $result_old_cuti->fetch_assoc();
        $stmt_get_old_cuti->close();

        if ($old_cuti_data) {
            $id_karyawan_cuti = $old_cuti_data['id_karyawan'];
            $jumlah_hari_cuti = $old_cuti_data['jumlah_hari'];
            $jenis_cuti_old = $old_cuti_data['jenis_cuti'];
            $status_cuti_old = $old_cuti_data['status_cuti'];

            error_log("DEBUG (Update Cuti): ID Cuti: $id_cuti");
            error_log("DEBUG (Update Cuti): Status Lama: $status_cuti_old, Status Baru: $status_baru");
            error_log("DEBUG (Update Cuti): Jenis Cuti: $jenis_cuti_old");

            $stmt_update_cuti = $conn->prepare("UPDATE cuti SET status_cuti = ?, komentar_hrd = ?, tanggal_persetujuan = NOW() WHERE id_cuti = ?");
            $stmt_update_cuti->bind_param("ssi", $status_baru, $komentar_hrd, $id_cuti);

            if ($stmt_update_cuti->execute()) {
                if ($status_baru == 'Disetujui' && $status_cuti_old != 'Disetujui') {
                    error_log("DEBUG (Update Cuti): Kondisi disetujui terpenuhi. Cek jenis cuti...");

                    if ($jenis_cuti_old == 'Cuti Tahunan') {
                        error_log("DEBUG (Update Cuti): Jenis cuti 'Cuti Tahunan'. Mengurangi jatah cuti karyawan ID: $id_karyawan_cuti sebanyak $jumlah_hari_cuti hari.");

                        $stmt_update_sisa = $conn->prepare("UPDATE karyawan SET sisa_cuti = sisa_cuti - ? WHERE id_karyawan = ?");
                        $stmt_update_sisa->bind_param("ii", $jumlah_hari_cuti, $id_karyawan_cuti);
                        $stmt_update_sisa->execute();
                        $stmt_update_sisa->close();
                        
                    } else {
                        error_log("DEBUG (Update Cuti): Jenis cuti BUKAN 'Cuti Tahunan'. Jatah cuti tidak dikurangi.");
                    }
                } elseif ($status_baru == 'Ditolak' && $status_cuti_old == 'Disetujui') {
                    if ($jenis_cuti_old == 'Cuti Tahunan') {
                        error_log("DEBUG (Update Cuti): Cuti ditolak setelah disetujui. Mengembalikan jatah cuti karyawan ID: $id_karyawan_cuti sebanyak $jumlah_hari_cuti hari.");
                        $stmt_update_sisa = $conn->prepare("UPDATE karyawan SET sisa_cuti = sisa_cuti + ? WHERE id_karyawan = ?");
                        $stmt_update_sisa->bind_param("ii", $jumlah_hari_cuti, $id_karyawan_cuti);
                        $stmt_update_sisa->execute();
                        $stmt_update_sisa->close();
                    }
                } elseif ($status_baru == 'Dibatalkan' && $status_cuti_old == 'Disetujui') {
                    if ($jenis_cuti_old == 'Cuti Tahunan') {
                        error_log("DEBUG (Update Cuti): Cuti dibatalkan setelah disetujui. Mengembalikan jatah cuti karyawan ID: $id_karyawan_cuti sebanyak $jumlah_hari_cuti hari.");
                        $stmt_update_sisa = $conn->prepare("UPDATE karyawan SET sisa_cuti = sisa_cuti + ? WHERE id_karyawan = ?");
                        $stmt_update_sisa->bind_param("ii", $jumlah_hari_cuti, $id_karyawan_cuti);
                        $stmt_update_sisa->execute();
                        $stmt_update_sisa->close();
                    }
                }
                
                $conn->commit();
                $_SESSION['message'] = "<div class='message success'>Status cuti berhasil diperbarui.</div>";
            } else {
                $conn->rollback();
                $_SESSION['message'] = "<div class='message error'>Gagal memperbarui status cuti: " . $stmt_update_cuti->error . "</div>";
                error_log("ERROR (Update Cuti): Gagal update status cuti: " . $stmt_update_cuti->error);
            }
            $stmt_update_cuti->close();
        } else {
            $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>Data cuti tidak ditemukan.</div>";
            error_log("ERROR (Update Cuti): Data cuti tidak ditemukan.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "<div class='message error'>Terjadi kesalahan: " . $e->getMessage() . "</div>";
        error_log("ERROR (Update Cuti): Terjadi kesalahan: " . $e->getMessage());
    }
    header("Location: dashboard_hrd.php");
    exit();
}

$stmt_cuti = $conn->prepare("
    SELECT
        c.*,
        k.nama_lengkap,
        k.jabatan,
        k.sisa_cuti AS sisa_cuti_karyawan
    FROM cuti c
    JOIN karyawan k ON c.id_karyawan = k.id_karyawan
    ORDER BY c.tanggal_pengajuan DESC
");
if ($stmt_cuti === false) {
    die("Error preparing statement: " . $conn->error);
}
$stmt_cuti->execute();
$result_cuti = $stmt_cuti->get_result();
$cuti_exists = ($result_cuti->num_rows > 0);


$stmt_kuota = $conn->prepare("
    SELECT
        kdc.id_departemen,
        d.nama_departemen,
        kdc.maks_karyawan_cuti_bersamaan
    FROM kuota_departemen_cuti kdc
    JOIN departemen d ON kdc.id_departemen = d.id_departemen
    ORDER BY d.nama_departemen ASC
");
if ($stmt_kuota === false) {
    die("Error preparing kuota statement: " . $conn->error);
}
$stmt_kuota->execute();
$result_kuota = $stmt_kuota->get_result();
$kuota_exists = ($result_kuota->num_rows > 0);


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_kuota'])) {
    $id_departemen_kuota = intval($_POST['id_departemen_kuota']);
    $maks_karyawan = intval($_POST['maks_karyawan_cuti_bersamaan']);

    if ($maks_karyawan < 0) {
        $message = "<div class='message error'>Maksimal karyawan cuti tidak boleh negatif.</div>";
    } else {
        $stmt_check_kuota = $conn->prepare("SELECT COUNT(*) FROM kuota_departemen_cuti WHERE id_departemen = ?");
        $stmt_check_kuota->bind_param("i", $id_departemen_kuota);
        $stmt_check_kuota->execute();
        $result_check_kuota = $stmt_check_kuota->get_result();
        $row_check_kuota = $result_check_kuota->fetch_row();
        $count_kuota = $row_check_kuota[0];
        $stmt_check_kuota->close();

        if ($count_kuota > 0) {
            $stmt_update_kuota = $conn->prepare("UPDATE kuota_departemen_cuti SET maks_karyawan_cuti_bersamaan = ? WHERE id_departemen = ?");
            $stmt_update_kuota->bind_param("ii", $maks_karyawan, $id_departemen_kuota);
            if ($stmt_update_kuota->execute()) {
                $message = "<div class='message success'>Kuota departemen berhasil diperbarui.</div>";
            } else {
                $message = "<div class='message error'>Gagal memperbarui kuota departemen: " . $stmt_update_kuota->error . "</div>";
            }
            $stmt_update_kuota->close();
        } else {
            $stmt_insert_kuota = $conn->prepare("INSERT INTO kuota_departemen_cuti (id_departemen, maks_karyawan_cuti_bersamaan) VALUES (?, ?)");
            $stmt_insert_kuota->bind_param("ii", $id_departemen_kuota, $maks_karyawan);
            if ($stmt_insert_kuota->execute()) {
                $message = "<div class='message success'>Kuota departemen berhasil ditambahkan.</div>";
            } else {
                $message = "<div class='message error'>Gagal menambahkan kuota departemen: " . $stmt_insert_kuota->error . "</div>";
            }
            $stmt_insert_kuota->close();
        }
    }
    header("Location: dashboard_hrd.php");
    exit();
}

$stmt_get_departments = $conn->prepare("SELECT id_departemen, nama_departemen FROM departemen ORDER BY nama_departemen ASC");
$stmt_get_departments->execute();
$result_departments = $stmt_get_departments->get_result();
$departments = [];
while ($row_dept = $result_departments->fetch_assoc()) {
    $departments[] = $row_dept;
}
$stmt_get_departments->close();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard HRD - Daiprint</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container container-table-view">
        <h2>Dashboard HRD</h2>
        <p>Selamat datang, <b><?php echo htmlspecialchars($nama_hrd); ?></b> (<?php echo htmlspecialchars($jabatan_hrd); ?>)!</p>

        <?php echo $message; ?>

        <div class="dashboard-container-wrapper">
            <div class="left-panel">
                <h3>Kelola Kuota Cuti Per Departemen</h3>
                <form action="dashboard_hrd.php" method="POST" class="kuota-form">
                    <div class="form-group">
                        <label for="id_departemen_kuota">Departemen:</label>
                        <select id="id_departemen_kuota" name="id_departemen_kuota" required>
                            <option value="">Pilih Departemen</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['id_departemen']); ?>">
                                    <?php echo htmlspecialchars($dept['nama_departemen']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="maks_karyawan_cuti_bersamaan">Maks. Karyawan Cuti Bersamaan:</label>
                        <input type="number" id="maks_karyawan_cuti_bersamaan" name="maks_karyawan_cuti_bersamaan" min="0" required>
                    </div>
                    <button type="submit" name="update_kuota">Simpan Kuota</button>
                </form>

                <h4>Kuota Cuti Departemen Saat Ini</h4>
                <?php if ($kuota_exists): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Departemen</th>
                                    <th>Maks. Karyawan Cuti Bersamaan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $result_kuota->data_seek(0);
                                while ($row_kuota = $result_kuota->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row_kuota['nama_departemen']); ?></td>
                                    <td><?php echo htmlspecialchars($row_kuota['maks_karyawan_cuti_bersamaan']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Belum ada kuota cuti departemen yang diatur.</p>
                <?php endif; ?>
            </div>

            <div class="right-panel">
                <h3>Daftar Pengajuan Cuti Karyawan</h3>
                <?php if ($cuti_exists): ?>
                    <div class="table-scroll-vertical">
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID Cuti</th>
                                        <th>Karyawan</th>
                                        <th>Jabatan</th>
                                        <th>Tgl Pengajuan</th>
                                        <th>Tgl Mulai</th>
                                        <th>Tgl Selesai</th>
                                        <th>Jenis Cuti</th>
                                        <th>Jumlah Hari</th>
                                        <th>Alasan</th>
                                        <th>Bukti Sakit</th>
                                        <th>Status</th>
                                        <th>Tgl Persetujuan</th>
                                        <th>Komentar HRD</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row_cuti = $result_cuti->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row_cuti['id_cuti']); ?></td>
                                        <td><?php echo htmlspecialchars($row_cuti['nama_lengkap']); ?></td>
                                        <td><?php echo htmlspecialchars($row_cuti['jabatan']); ?></td>
                                        <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_pengajuan']); ?></td>
                                        <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_mulai']); ?></td>
                                        <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_selesai']); ?></td>
                                        <td><?php echo htmlspecialchars($row_cuti['jenis_cuti']); ?></td>
                                        <td><?php echo htmlspecialchars($row_cuti['jumlah_hari']); ?></td>
                                        <td><?php echo htmlspecialchars($row_cuti['alasan']); ?></td>
                                        <td>
                                            <?php if (!empty($row_cuti['bukti_sakit_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($row_cuti['bukti_sakit_path']); ?>" target="_blank">Lihat Bukti</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="
                                                <?php
                                                    if ($row_cuti['status_cuti'] == 'Menunggu') echo 'status-pending';
                                                    else if ($row_cuti['status_cuti'] == 'Disetujui') echo 'status-approved';
                                                    else if ($row_cuti['status_cuti'] == 'Ditolak') echo 'status-rejected';
                                                    else if ($row_cuti['status_cuti'] == 'Dibatalkan') echo 'status-dibatalkan';
                                                ?>
                                            ">
                                                <?php echo htmlspecialchars($row_cuti['status_cuti']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo !empty($row_cuti['tanggal_persetujuan']) ? formatTanggalIndonesia($row_cuti['tanggal_persetujuan']) : '-'; ?></td>
                                        <td><?php echo !empty($row_cuti['komentar_hrd']) ? htmlspecialchars($row_cuti['komentar_hrd']) : '-'; ?></td>
                                        <td>
                                            <form action="dashboard_hrd.php" method="POST">
                                                <input type="hidden" name="id_cuti" value="<?php echo htmlspecialchars($row_cuti['id_cuti']); ?>">
                                                <select name="status_cuti" required>
                                                    <option value="Menunggu" <?php echo ($row_cuti['status_cuti'] == 'Menunggu') ? 'selected' : ''; ?>>Menunggu</option>
                                                    <option value="Disetujui" <?php echo ($row_cuti['status_cuti'] == 'Disetujui') ? 'selected' : ''; ?>>Disetujui</option>
                                                    <option value="Ditolak" <?php echo ($row_cuti['status_cuti'] == 'Ditolak') ? 'selected' : ''; ?>>Ditolak</option>
                                                    <option value="Dibatalkan" <?php echo ($row_cuti['status_cuti'] == 'Dibatalkan') ? 'selected' : ''; ?>>Dibatalkan</option>
                                                </select>
                                                <textarea name="komentar_hrd" placeholder="Komentar HRD (opsional)"><?php echo htmlspecialchars($row_cuti['komentar_hrd'] ?? ''); ?></textarea>
                                                <button type="submit" name="update_cuti" class="approve-button">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <p>Belum ada pengajuan cuti atau terjadi kesalahan saat mengambil data.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-links">
            <a href="logout.php" class="logout-button">Keluar</a>
        </div>
    </div>
</body>
</html>