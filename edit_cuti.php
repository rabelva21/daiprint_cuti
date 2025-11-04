<?php
session_start();
if (!isset($_SESSION['id_karyawan'])) {
    header("Location: index.php");
    exit();
}

include 'includes/db_connection.php';

$message = '';
$id_karyawan_login = $_SESSION['id_karyawan'];
$id_cuti_edit = isset($_GET['id']) ? intval($_GET['id']) : 0;

$cuti_data = null;
if ($id_cuti_edit > 0) {
    $stmt_get_cuti = $conn->prepare("SELECT tanggal_mulai, tanggal_selesai, jenis_cuti, alasan, bukti_sakit_path, jumlah_hari FROM cuti WHERE id_cuti = ? AND id_karyawan = ? AND status_cuti = 'Menunggu'");
    $stmt_get_cuti->bind_param("ii", $id_cuti_edit, $id_karyawan_login);
    $stmt_get_cuti->execute();
    $result_get_cuti = $stmt_get_cuti->get_result();
    $cuti_data = $result_get_cuti->fetch_assoc();
    $stmt_get_cuti->close();

    if (!$cuti_data) {
        $message = "<div class='message error'>Pengajuan cuti tidak ditemukan atau tidak dapat diedit (status bukan 'Menunggu').</div>";
        header("Location: status_cuti.php");
        exit();
    }
} else {
    $message = "<div class='message error'>ID Cuti tidak valid.</div>";
    header("Location: status_cuti.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tanggal_mulai = $_POST['tanggal_mulai'];
    $tanggal_selesai = $_POST['tanggal_selesai'];
    $jenis_cuti = $_POST['jenis_cuti'];
    $alasan = $_POST['alasan'];

    $datetime_mulai = new DateTime($tanggal_mulai);
    $datetime_selesai = new DateTime($tanggal_selesai);
    $interval = $datetime_mulai->diff($datetime_selesai);
    $jumlah_hari = $interval->days + 1;

    $bukti_sakit_path_lama = $cuti_data['bukti_sakit_path'];
    $bukti_sakit_path_baru = $bukti_sakit_path_lama;

    if ($tanggal_mulai > $tanggal_selesai) {
        $message = "<div class='message error'>Tanggal mulai tidak boleh lebih dari tanggal selesai.</div>";
    } else {
        if ($jenis_cuti === 'Cuti Sakit') {
            if (isset($_FILES['bukti_sakit']) && $_FILES['bukti_sakit']['error'] == UPLOAD_ERR_OK) {
                $target_dir = "uploads/bukti_sakit/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $file_extension = pathinfo($_FILES['bukti_sakit']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid('bukti_sakit_') . '.' . $file_extension;
                $target_file = $target_dir . $file_name;

                if (move_uploaded_file($_FILES['bukti_sakit']['tmp_name'], $target_file)) {
                    if ($bukti_sakit_path_lama && file_exists($bukti_sakit_path_lama)) {
                        unlink($bukti_sakit_path_lama);
                    }
                    $bukti_sakit_path_baru = $target_file;
                } else {
                    $message = "<div class='message error'>Gagal mengunggah bukti sakit.</div>";
                }
            }
            else if (empty($bukti_sakit_path_lama)) {
                $message = "<div class='message error'>Bukti Keterangan Dokter wajib diisi jika jenis cuti adalah Sakit.</div>";
            }
        }
        else {
            if ($bukti_sakit_path_lama && file_exists($bukti_sakit_path_lama)) {
                unlink($bukti_sakit_path_lama);
            }
            $bukti_sakit_path_baru = NULL;
        }

        if (empty($message)) {
            $stmt_update = $conn->prepare("UPDATE cuti SET tanggal_mulai = ?, tanggal_selesai = ?, jenis_cuti = ?, alasan = ?, jumlah_hari = ?, bukti_sakit_path = ? WHERE id_cuti = ? AND id_karyawan = ? AND status_cuti = 'Menunggu'");
            $stmt_update->bind_param("ssssisii", $tanggal_mulai, $tanggal_selesai, $jenis_cuti, $alasan, $jumlah_hari, $bukti_sakit_path_baru, $id_cuti_edit, $id_karyawan_login);

            if ($stmt_update->execute()) {
                if ($stmt_update->affected_rows > 0) {
                    $_SESSION['message'] = "<div class='message success'>Pengajuan cuti berhasil diperbarui!</div>";
                } else {
                    $_SESSION['message'] = "<div class='message info'>Tidak ada perubahan yang disimpan atau cuti sudah tidak berstatus 'Menunggu'.</div>";
                }
                header("Location: status_cuti.php");
                exit();
            } else {
                $message = "<div class='message error'>Error: " . $stmt_update->error . "</div>";
            }
            $stmt_update->close();
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pengajuan Cuti - Daiprint</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Edit Pengajuan Cuti</h2>
        <?php echo $message; ?>

        <?php if ($cuti_data): ?>
            <form action="edit_cuti.php?id=<?php echo htmlspecialchars($id_cuti_edit); ?>" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="tanggal_mulai">Tanggal Mulai Cuti:</label>
                    <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?php echo htmlspecialchars($cuti_data['tanggal_mulai']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="tanggal_selesai">Tanggal Selesai Cuti:</label>
                    <input type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?php echo htmlspecialchars($cuti_data['tanggal_selesai']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="jenis_cuti">Jenis Cuti:</label>
                    <select id="jenis_cuti" name="jenis_cuti" required>
                        <option value="Cuti Tahunan" <?php echo ($cuti_data['jenis_cuti'] == 'Cuti Tahunan') ? 'selected' : ''; ?>>Cuti Tahunan</option>
                        <option value="Cuti Sakit" <?php echo ($cuti_data['jenis_cuti'] == 'Cuti Sakit') ? 'selected' : ''; ?>>Cuti Sakit (tidak mengurangi cuti tahunan)</option>
                        <option value="Cuti Melahirkan" <?php echo ($cuti_data['jenis_cuti'] == 'Cuti Melahirkan') ? 'selected' : ''; ?>>Cuti Melahirkan</option>
                    </select>
                </div>
                
                <div class="form-group" id="bukti_sakit_group" style="display: none;">
                    <label for="bukti_sakit">Bukti Keterangan Dokter:</label>
                    <?php if (!empty($cuti_data['bukti_sakit_path'])): ?>
                        <p>Dokumen yang sudah ada: <a href="<?php echo htmlspecialchars($cuti_data['bukti_sakit_path']); ?>" target="_blank">Lihat Bukti</a></p>
                        <p class="info-message">Upload file baru untuk mengganti dokumen yang sudah ada.</p>
                    <?php else: ?>
                        <p class="warning-message">Bukti Keterangan Dokter wajib diisi jika jenis cuti adalah Sakit.</p>
                    <?php endif; ?>
                    <input type="file" id="bukti_sakit" name="bukti_sakit" accept="image/*,.pdf">
                </div>
                
                <div class="form-group">
                    <label for="alasan">Alasan Cuti:</label>
                    <textarea id="alasan" name="alasan" rows="5" required><?php echo htmlspecialchars($cuti_data['alasan']); ?></textarea>
                </div>
                <button type="submit">Simpan Perubahan</button>
            </form>
        <?php else: ?>
            <p>Tidak ada data cuti yang bisa diedit.</p>
        <?php endif; ?>

        <div class="nav-links">
            <a href="status_cuti.php">Kembali ke Status Cuti</a>
            <a href="logout.php" class="logout-button">Keluar</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const jenisCutiSelect = document.getElementById('jenis_cuti');
            const buktiSakitGroup = document.getElementById('bukti_sakit_group');
            const buktiSakitInput = document.getElementById('bukti_sakit');
            const existingBuktiSakitPath = "<?php echo htmlspecialchars($cuti_data['bukti_sakit_path'] ?? ''); ?>";

            function toggleBuktiSakit() {
                if (jenisCutiSelect.value === 'Cuti Sakit') {
                    buktiSakitGroup.style.display = 'block';
                    if (!existingBuktiSakitPath) {
                        buktiSakitInput.setAttribute('required', 'required');
                    } else {
                        buktiSakitInput.removeAttribute('required');
                    }
                } else {
                    buktiSakitGroup.style.display = 'none';
                    buktiSakitInput.removeAttribute('required');
                    buktiSakitInput.value = '';
                }
            }

            toggleBuktiSakit();
            
            jenisCutiSelect.addEventListener('change', toggleBuktiSakit);
        });
    </script>
</body>
</html>