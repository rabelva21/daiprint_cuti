<?php
session_start();
include 'includes/db_connection.php';

if (!isset($_SESSION['id_karyawan'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['tanggal_mulai']) && isset($_POST['tanggal_selesai']) && isset($_POST['jenis_cuti']) && isset($_POST['alasan'])) {
        $id_karyawan = $_SESSION['id_karyawan'];
        $tanggal_mulai = $_POST['tanggal_mulai'];
        $tanggal_selesai = $_POST['tanggal_selesai'];
        $jenis_cuti = $_POST['jenis_cuti'];
        $alasan = $_POST['alasan'];
        $tanggal_pengajuan = date("Y-m-d H:i:s");

        if (strtotime($tanggal_mulai) > strtotime($tanggal_selesai)) {
            $_SESSION['message'] = "<div class='message error'>Tanggal mulai cuti tidak boleh setelah tanggal selesai cuti.</div>";
            header("Location: ajukan_cuti.php");
            exit();
        }

        $start = new DateTime($tanggal_mulai);
        $end = new DateTime($tanggal_selesai);
        $interval = $start->diff($end);
        $jumlah_hari = $interval->days + 1;

        $bukti_sakit_path = NULL;
        $upload_dir = 'uploads/bukti_sakit/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($jenis_cuti === 'Sakit') {
            if (isset($_FILES['bukti_sakit']) && $_FILES['bukti_sakit']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['bukti_sakit']['tmp_name'];
                $file_name = $_FILES['bukti_sakit']['name'];
                $file_size = $_FILES['bukti_sakit']['size'];
                $file_type = $_FILES['bukti_sakit']['type'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_ext = array('pdf', 'jpg', 'jpeg', 'png');

                if (!in_array($file_ext, $allowed_ext)) {
                    $_SESSION['message'] = "<div class='message error'>Format file bukti sakit tidak diizinkan. Hanya PDF, JPG, JPEG, PNG.</div>";
                    header("Location: ajukan_cuti.php");
                    exit();
                }

                if ($file_size > 2 * 1024 * 1024) {
                    $_SESSION['message'] = "<div class='message error'>Ukuran file bukti sakit terlalu besar (maks 2MB).</div>";
                    header("Location: ajukan_cuti.php");
                    exit();
                }

                $new_file_name = uniqid('bukti_sakit_', true) . '.' . $file_ext;
                $destination = $upload_dir . $new_file_name;

                if (move_uploaded_file($file_tmp, $destination)) {
                    $bukti_sakit_path = $destination;
                } else {
                    $_SESSION['message'] = "<div class='message error'>Gagal mengunggah file bukti sakit.</div>";
                    header("Location: ajukan_cuti.php");
                    exit();
                }
            } else {
                $_SESSION['message'] = "<div class='message error'>Bukti keterangan dokter wajib diunggah untuk jenis cuti Sakit.</div>";
                header("Location: ajukan_cuti.php");
                exit();
            }
        }

        $conn->begin_transaction();

        try {
            $status_cuti_insert = 'Menunggu';
            $komentar_hrd_insert = NULL;
            $bukti_sakit_path_insert = $bukti_sakit_path;

            $stmt_insert_cuti = $conn->prepare("INSERT INTO cuti (id_karyawan, tanggal_pengajuan, tanggal_mulai, tanggal_selesai, jenis_cuti, alasan, status_cuti, jumlah_hari, komentar_HRD, bukti_sakit_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt_insert_cuti->bind_param(
                "issssssiss",
                $id_karyawan,
                $tanggal_pengajuan,
                $tanggal_mulai,
                $tanggal_selesai,
                $jenis_cuti,
                $alasan,
                $status_cuti_insert,
                $jumlah_hari,
                $komentar_hrd_insert,
                $bukti_sakit_path_insert
            );
            $stmt_insert_cuti->execute();

            if ($jenis_cuti !== 'Sakit') {
                $stmt_get_sisa = $conn->prepare("SELECT sisa_cuti FROM karyawan WHERE id_karyawan = ?");
                $stmt_get_sisa->bind_param("i", $id_karyawan);
                $stmt_get_sisa->execute();
                $result_sisa = $stmt_get_sisa->get_result();
                $current_sisa_cuti = 0;
                if ($result_sisa->num_rows > 0) {
                    $row_sisa = $result_sisa->fetch_assoc();
                    $current_sisa_cuti = $row_sisa['sisa_cuti'];
                }
                $stmt_get_sisa->close();

                if ($current_sisa_cuti < $jumlah_hari) {
                    $conn->rollback();
                    $_SESSION['message'] = "<div class='message error'>Sisa cuti Anda tidak cukup untuk pengajuan ini. Sisa cuti: " . $current_sisa_cuti . " hari.</div>";
                    header("Location: ajukan_cuti.php");
                    exit();
                }

                $stmt_update_sisa = $conn->prepare("UPDATE karyawan SET sisa_cuti = sisa_cuti - ? WHERE id_karyawan = ?");
                $stmt_update_sisa->bind_param("ii", $jumlah_hari, $id_karyawan);
                $stmt_update_sisa->execute();
                $stmt_update_sisa->close();
            }

            $conn->commit();
            $_SESSION['message'] = "<div class='message success'>Pengajuan cuti berhasil diajukan!</div>";
            header("Location: status_cuti.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>Terjadi kesalahan: " . $e->getMessage() . "</div>";
            header("Location: ajukan_cuti.php");
            exit();
        } finally {
            if (isset($stmt_insert_cuti) && $stmt_insert_cuti) {
                $stmt_insert_cuti->close();
            }
        }
    }
    else if (isset($_POST['batalkan_cuti'])) {
        $id_cuti_to_cancel = $_POST['id_cuti'];
        $id_karyawan_login = $_SESSION['id_karyawan'];

        $conn->begin_transaction();

        try {
            $stmt_check = $conn->prepare("SELECT status_cuti, jenis_cuti, jumlah_hari FROM cuti WHERE id_cuti = ? AND id_karyawan = ?");
            $stmt_check->bind_param("ii", $id_cuti_to_cancel, $id_karyawan_login);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $row_check = $result_check->fetch_assoc();
            $stmt_check->close();

            if ($row_check && $row_check['status_cuti'] == 'Menunggu') {
                $stmt_cancel = $conn->prepare("UPDATE cuti SET status_cuti = 'Dibatalkan', komentar_hrd = 'Dibatalkan oleh karyawan.' WHERE id_cuti = ? AND id_karyawan = ?");
                $stmt_cancel->bind_param("ii", $id_cuti_to_cancel, $id_karyawan_login);
                $stmt_cancel->execute();
                $stmt_cancel->close();

                if ($row_check['jenis_cuti'] !== 'Sakit') {
                    $jumlah_hari_dibatalkan = $row_check['jumlah_hari'];
                    $stmt_refund_sisa = $conn->prepare("UPDATE karyawan SET sisa_cuti = sisa_cuti + ? WHERE id_karyawan = ?");
                    $stmt_refund_sisa->bind_param("ii", $jumlah_hari_dibatalkan, $id_karyawan_login);
                    $stmt_refund_sisa->execute();
                    $stmt_refund_sisa->close();
                }

                $conn->commit();
                $_SESSION['message'] = "<div class='message success'>Pengajuan cuti berhasil dibatalkan.</div>";
            } else {
                $conn->rollback();
                $_SESSION['message'] = "<div class='message error'>Pengajuan cuti tidak ditemukan atau tidak dapat dibatalkan (status bukan 'Menunggu').</div>";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>Terjadi kesalahan saat membatalkan cuti: " . $e->getMessage() . "</div>";
        }
        header("Location: status_cuti.php");
        exit();
    }
    else {
        $_SESSION['message'] = "<div class='message error'>Metode request tidak valid atau parameter tidak lengkap.</div>";
        header("Location: ajukan_cuti.php");
        exit();
    }
} else {
    header("Location: ajukan_cuti.php");
    exit();
}
?>