<?php
session_start();
include 'includes/db_connection.php';
include 'includes/date_formatter.php';

if (!isset($_SESSION['id_karyawan']) || $_SESSION['role'] !== 'Karyawan') {
    header("Location: index.php");
    exit();
}

$id_karyawan = $_SESSION['id_karyawan'];
$nama_lengkap = $_SESSION['nama_lengkap'];
$jabatan = $_SESSION['jabatan'];

$message = '';

$stmt_sisa_cuti = $conn->prepare("SELECT sisa_cuti, id_departemen FROM karyawan WHERE id_karyawan = ?");
$stmt_sisa_cuti->bind_param("i", $id_karyawan);
$stmt_sisa_cuti->execute();
$result_sisa_cuti = $stmt_sisa_cuti->get_result();
$sisa_cuti_karyawan = 0;
$id_departemen_karyawan = null;
if ($result_sisa_cuti->num_rows > 0) {
    $row_sisa_cuti = $result_sisa_cuti->fetch_assoc();
    $sisa_cuti_karyawan = $row_sisa_cuti['sisa_cuti'];
    $id_departemen_karyawan = $row_sisa_cuti['id_departemen'];
}
$stmt_sisa_cuti->close();

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tanggal_mulai_str = $_POST['tanggal_mulai'];
    $tanggal_selesai_str = $_POST['tanggal_selesai'];
    $jenis_cuti = $_POST['jenis_cuti'];
    $alasan = $_POST['alasan'];
    $bukti_sakit_path = null;

    $datetime_mulai = new DateTime($tanggal_mulai_str);
    $datetime_selesai = new DateTime($tanggal_selesai_str);
    $interval = $datetime_mulai->diff($datetime_selesai);
    $jumlah_hari = $interval->days + 1;

    if (empty($tanggal_mulai_str) || empty($tanggal_selesai_str) || empty($jenis_cuti) || empty($alasan)) {
        $message = "<div class='message error'>Semua kolom wajib diisi.</div>";
    } elseif ($datetime_mulai > $datetime_selesai) {
        $message = "<div class='message error'>Tanggal mulai tidak boleh lebih lambat dari tanggal selesai.</div>";
    } elseif ($jenis_cuti === 'Cuti Tahunan' && $jumlah_hari > $sisa_cuti_karyawan) {
        $message = "<div class='message error'>Jumlah hari cuti tahunan melebihi sisa cuti Anda. Sisa cuti Anda: " . $sisa_cuti_karyawan . " hari.</div>";
    } else {
        $is_quota_violated = false;
        if ($id_departemen_karyawan) {
            $stmt_get_quota = $conn->prepare("SELECT maks_karyawan_cuti_bersamaan FROM kuota_departemen_cuti WHERE id_departemen = ?");
            if ($stmt_get_quota && $stmt_get_quota->bind_param("i", $id_departemen_karyawan) && $stmt_get_quota->execute()) {
                $result_quota = $stmt_get_quota->get_result();
                $max_allowed_in_department = 999;
                if ($result_quota->num_rows > 0) {
                    $row_quota = $result_quota->fetch_assoc();
                    $max_allowed_in_department = $row_quota['maks_karyawan_cuti_bersamaan'];
                }
                $stmt_get_quota->close();

                $current_date = clone $datetime_mulai;
                while ($current_date <= $datetime_selesai) {
                    $date_to_check = $current_date->format('Y-m-d');
                    
                    $stmt_count_cuti = $conn->prepare("
                        SELECT COUNT(c.id_cuti) AS count_on_day
                        FROM cuti c
                        JOIN karyawan k ON c.id_karyawan = k.id_karyawan
                        WHERE k.id_departemen = ?
                        AND c.id_karyawan != ?
                        AND ? BETWEEN c.tanggal_mulai AND c.tanggal_selesai
                        AND c.status_cuti IN ('Menunggu', 'Disetujui')
                    ");

                    if ($stmt_count_cuti && $stmt_count_cuti->bind_param("iis", $id_departemen_karyawan, $id_karyawan, $date_to_check) && $stmt_count_cuti->execute()) {
                        $result_count = $stmt_count_cuti->get_result();
                        $row_count = $result_count->fetch_assoc();
                        $cuti_count_on_day = $row_count['count_on_day'];
                        $stmt_count_cuti->close();

                        if ($cuti_count_on_day >= $max_allowed_in_department) {
                            $is_quota_violated = true;
                            $message = "<div class='message error'>Pengajuan cuti Anda pada tanggal <b>" . formatTanggalIndonesia($date_to_check) . "</b> tidak dapat diproses. Sudah ada " . ($cuti_count_on_day) . " rekan departemen Anda yang cuti/izin. Maksimal " . $max_allowed_in_department . " orang dalam satu departemen. Mohon pilih tanggal lain atau hubungi Head Departemen Anda.</div>";
                            break;
                        }
                    } else {
                        $message = "<div class='message error'>Error saat mengecek kuota: " . ($stmt_count_cuti ? $stmt_count_cuti->error : $conn->error) . "</div>";
                        $is_quota_violated = true;
                        break;
                    }
                    $current_date->modify('+1 day');
                }
            } else {
                $message = "<div class='message error'>Error saat mengambil kuota departemen: " . ($stmt_get_quota ? $stmt_get_quota->error : $conn->error) . "</div>";
                $is_quota_violated = true;
            }
        }
        
        if (!$is_quota_violated) {
            if (($jenis_cuti === 'Cuti Sakit' || $jenis_cuti === 'Cuti Melahirkan') && isset($_FILES['bukti_sakit']) && $_FILES['bukti_sakit']['error'] == UPLOAD_ERR_OK) {
                $target_dir = "uploads/bukti_sakit/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $file_extension = pathinfo($_FILES['bukti_sakit']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid('bukti_sakit_') . '.' . $file_extension;
                $target_file = $target_dir . $file_name;

                if (move_uploaded_file($_FILES['bukti_sakit']['tmp_name'], $target_file)) {
                    $bukti_sakit_path = $target_file;
                } else {
                    $message = "<div class='message error'>Gagal mengunggah bukti keterangan.</div>";
                }
            } else if (($jenis_cuti === 'Cuti Sakit' || $jenis_cuti === 'Cuti Melahirkan') && (!isset($_FILES['bukti_sakit']) || $_FILES['bukti_sakit']['error'] != UPLOAD_ERR_OK)) {
                $message = "<div class='message error'>Bukti Keterangan Dokter wajib diisi jika jenis cuti adalah Sakit atau Melahirkan.</div>";
            }

            if (empty($message)) {
                $stmt_insert = $conn->prepare("INSERT INTO cuti (id_karyawan, tanggal_pengajuan, tanggal_mulai, tanggal_selesai, jenis_cuti, alasan, jumlah_hari, status_cuti, bukti_sakit_path) VALUES (?, NOW(), ?, ?, ?, ?, ?, 'Menunggu', ?)");
                
                if ($stmt_insert === false) {
                    $message = "<div class='message error'>Error saat menyiapkan pengajuan cuti: " . $conn->error . "</div>";
                } else {
                    $stmt_insert->bind_param("issssis", $id_karyawan, $tanggal_mulai_str, $tanggal_selesai_str, $jenis_cuti, $alasan, $jumlah_hari, $bukti_sakit_path);
                    if ($stmt_insert->execute()) {
                        $_SESSION['message'] = "<div class='message success'>Pengajuan cuti berhasil diajukan! Menunggu persetujuan.</div>";
                        header("Location: status_cuti.php");
                        exit();
                    } else {
                        $message = "<div class='message error'>Gagal mengajukan cuti: " . $stmt_insert->error . "</div>";
                    }
                    $stmt_insert->close();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajukan Cuti - Daiprint</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .sisa-cuti-tersedia { color: green; font-weight: bold; }
        .sisa-cuti-habis { color: red; font-weight: bold; }
        .required-star {
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Ajukan Cuti</h2>
        <p>Halo, <b><?php echo htmlspecialchars($nama_lengkap); ?></b> (<?php echo htmlspecialchars($jabatan); ?>)!</p>
        <p>Sisa cuti tahunan Anda: <b class="<?php echo ($sisa_cuti_karyawan > 0) ? 'sisa-cuti-tersedia' : 'sisa-cuti-habis'; ?>">
            <?php echo $sisa_cuti_karyawan; ?>
        </b> hari</p>

        <?php echo $message; ?>

        <form action="ajukan_cuti.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="tanggal_mulai"><span class="required-star">* </span>Tanggal Mulai Cuti:</label>
                <input type="date" id="tanggal_mulai" name="tanggal_mulai" required>
            </div>
            <div class="form-group">
                <label for="tanggal_selesai"><span class="required-star">* </span>Tanggal Selesai Cuti:</label>
                <input type="date" id="tanggal_selesai" name="tanggal_selesai" required>
            </div>
            <div class="form-group">
                <label for="jenis_cuti"><span class="required-star">* </span>Jenis Cuti:</label>
                <select id="jenis_cuti" name="jenis_cuti" required>
                    <option value="">Pilih Jenis Cuti</option>
                    <option value="Cuti Tahunan">Cuti Tahunan</option>
                    <option value="Cuti Sakit">Cuti Sakit (tidak mengurangi cuti tahunan)</option>
                    <option value="Cuti Melahirkan">Cuti Melahirkan (tidak mengurangi cuti tahunan)</option>
                </select>
            </div>
            <div class="form-group" id="bukti_sakit_group" style="display: none;">
                <label for="bukti_sakit">Bukti Keterangan Dokter (PDF/JPG/PNG, max 2MB):</label>
                <input type="file" name="bukti_sakit" id="bukti_sakit" class="file-input">
            </div>
            <div class="form-group">
                <label for="alasan"><span class="required-star">* </span>Alasan Cuti:</label>
                <textarea id="alasan" name="alasan" rows="4" required></textarea>
            </div>
            <button type="submit">Ajukan Cuti</button>
        </form>

        <div class="nav-links">
            <a href="status_cuti.php">Lihat Status Cuti Saya</a>
            <a href="logout.php">Keluar</a>
        </div>
    </div>

    <script>
        function toggleBuktiSakit() {
            var jenisCuti = document.getElementById('jenis_cuti').value;
            var buktiSakitGroup = document.getElementById('bukti_sakit_group');
            var buktiSakitInput = document.getElementById('bukti_sakit');

            if (jenisCuti === 'Cuti Sakit' || jenisCuti === 'Cuti Melahirkan') {
                buktiSakitGroup.style.display = 'block';
                if (buktiSakitInput) {
                    buktiSakitInput.setAttribute('required', 'required');
                }
            } else {
                buktiSakitGroup.style.display = 'none';
                if (buktiSakitInput) {
                    buktiSakitInput.removeAttribute('required');
                    buktiSakitInput.value = '';
                }
            }
        }

        document.getElementById('jenis_cuti').addEventListener('change', toggleBuktiSakit);
        document.addEventListener('DOMContentLoaded', toggleBuktiSakit);
    </script>
</body>
</html>