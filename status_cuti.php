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

if (!$conn) {
    $message = "<div class='message error'>Koneksi database gagal.</div>";
    $sisa_cuti_karyawan = 0;
} else {
    $stmt_sisa_cuti = $conn->prepare("SELECT sisa_cuti FROM karyawan WHERE id_karyawan = ?");
    if ($stmt_sisa_cuti === false) {
        $message = "<div class='message error'>Error saat menyiapkan query sisa cuti: " . $conn->error . "</div>";
        $sisa_cuti_karyawan = 0;
    } else {
        $stmt_sisa_cuti->bind_param("i", $id_karyawan);
        $stmt_sisa_cuti->execute();
        $result_sisa_cuti = $stmt_sisa_cuti->get_result();
        $sisa_cuti_karyawan = 0;
        if ($result_sisa_cuti->num_rows > 0) {
            $row_sisa_cuti = $result_sisa_cuti->fetch_assoc();
            $sisa_cuti_karyawan = $row_sisa_cuti['sisa_cuti'];
        }
        $stmt_sisa_cuti->close();
    }

    $stmt_cuti = $conn->prepare("
        SELECT 
            id_cuti,
            tanggal_pengajuan,
            tanggal_mulai,
            tanggal_selesai,
            jenis_cuti,
            alasan,
            status_cuti,
            komentar_hrd,
            bukti_sakit_path, 
            jumlah_hari
        FROM 
            cuti 
        WHERE 
            id_karyawan = ? 
        ORDER BY 
            tanggal_pengajuan DESC
    ");
    if ($stmt_cuti === false) {
        $message .= "<div class='message error'>Error saat menyiapkan query data cuti: " . $conn->error . "</div>";
        $result_cuti = false;
    } else {
        $stmt_cuti->bind_param("i", $id_karyawan);
        $stmt_cuti->execute();
        $result_cuti = $stmt_cuti->get_result();
        $stmt_cuti->close();
    }
}


if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ($_SERVER["REQUEST_METHOD"] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'batalkan' && isset($_POST['id_cuti'])) {
        $id_cuti = $_POST['id_cuti'];
        
        if ($conn) {
            $stmt_cancel = $conn->prepare("UPDATE cuti SET status_cuti = 'Dibatalkan', komentar_hrd = 'Dibatalkan oleh Karyawan' WHERE id_cuti = ? AND id_karyawan = ? AND status_cuti = 'Menunggu'");
            if ($stmt_cancel === false) {
                $_SESSION['message'] = "<div class='message error'>Error saat menyiapkan pembatalan: " . $conn->error . "</div>";
            } else {
                $stmt_cancel->bind_param("ii", $id_cuti, $id_karyawan);
                if ($stmt_cancel->execute()) {
                    if ($stmt_cancel->affected_rows > 0) {
                        $_SESSION['message'] = "<div class='message success'>Pengajuan cuti berhasil dibatalkan.</div>";
                    } else {
                        $_SESSION['message'] = "<div class='message error'>Pengajuan cuti tidak dapat dibatalkan (mungkin sudah diproses atau tidak ditemukan).</div>";
                    }
                } else {
                    $_SESSION['message'] = "<div class='message error'>Error membatalkan cuti: " . $stmt_cancel->error . "</div>";
                }
                $stmt_cancel->close();
            }
        } else {
            $_SESSION['message'] = "<div class='message error'>Koneksi database belum terbentuk untuk membatalkan cuti.</div>";
        }
        header("Location: status_cuti.php");
        exit();
    }
}

if (isset($conn)) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status & Riwayat Pengajuan Cuti Anda</title>
    <link rel="stylesheet" href="css/style.css"> 
    <style>
        .cuti-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .cuti-table th, .cuti-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        .cuti-table th {
            background-color: #f2f2f2;
        }
        .status-pending { color: #ffc107; font-weight: bold; }
        .status-approved { color: #28a745; font-weight: bold; }
        .status-rejected { color: #dc3545; font-weight: bold; }
        .status-dibatalkan { color: #6c757d; font-weight: bold; }

        .action-buttons button, .action-buttons a { 
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-bottom: 5px; 
            text-decoration: none; 
            display: inline-block; 
            text-align: center; 
            margin-right: 5px;
        }
        .action-buttons .edit-button { 
            background-color: #ffc107;
            color: black; 
        }
        .action-buttons .edit-button:hover {
            background-color: #e0a800; 
        }
        .action-buttons .cancel-button {
            background-color: #dc3545;
            color: white;
        }
        .action-buttons .cancel-button:hover {
            background-color: #c82333;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            text-align: center;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .logout-button {
            background-color: #f44336; 
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            margin-left: 10px;
        }
        .logout-button:hover {
            background-color: #d32f2f;
        }
        .ajukan-cuti-button {
            background-color: #4CAF50; 
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        .ajukan-cuti-button:hover {
            background-color: #45a049;
        }
        .nav-links {
            margin-top: 20px;
            text-align: center; 
        }
    </style>
</head>
<body>
    <div class="container container-table-view">
        <h2>Status & History Pengajuan Cuti Anda</h2>
        <p>Halo, <b><?php echo htmlspecialchars($nama_lengkap); ?></b> (Jabatan: <?php echo htmlspecialchars($jabatan); ?>)!</p>
        <p>Sisa cuti tahunan Anda: <b class="<?php echo ($sisa_cuti_karyawan > 0) ? 'sisa-cuti-tersedia' : 'sisa-cuti-habis'; ?>">
            <?php echo $sisa_cuti_karyawan; ?>
        </b> hari</p>

        <?php 
        if (isset($_SESSION['message'])) {
            echo $_SESSION['message'];
            unset($_SESSION['message']); 
        }
        echo $message; 
        ?>

        <?php if ($result_cuti && $result_cuti->num_rows > 0): ?>
            <div style="overflow-x:auto;">
                <table class="cuti-table">
                    <thead>
                        <tr>
                            <th>ID Cuti</th>
                            <th>Tgl Pengajuan</th>
                            <th>Mulai Cuti</th>
                            <th>Selesai Cuti</th>
                            <th>Jenis Cuti</th>
                            <th>Alasan</th>
                            <th>Status</th>
                            <th>Komentar HRD</th>
                            <th>Bukti Sakit</th> 
                            <th>Jumlah Hari</th>
                            <th>Aksi</th> </tr>
                    </thead>
                    <tbody>
                        <?php while ($row_cuti = $result_cuti->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row_cuti['id_cuti']); ?></td>
                            <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_pengajuan']); ?></td>
                            <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_mulai']); ?></td>
                            <td><?php echo formatTanggalIndonesia($row_cuti['tanggal_selesai']); ?></td>
                            <td><?php echo htmlspecialchars($row_cuti['jenis_cuti']); ?></td>
                            <td><?php echo htmlspecialchars($row_cuti['alasan']); ?></td>
                            <td>
                                <?php
                                $status_class = '';
                                switch ($row_cuti['status_cuti']) {
                                    case 'Menunggu': $status_class = 'status-pending'; break;
                                    case 'Disetujui': $status_class = 'status-approved'; break;
                                    case 'Ditolak': $status_class = 'status-rejected'; break;
                                    case 'Dibatalkan': $status_class = 'status-dibatalkan'; break;
                                }
                                echo "<span class='{$status_class}'>" . htmlspecialchars($row_cuti['status_cuti']) . "</span>";
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($row_cuti['komentar_hrd'] ?: '-'); ?></td>
                            <td>
                                <?php if (!empty($row_cuti['bukti_sakit_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($row_cuti['bukti_sakit_path']); ?>" target="_blank">Lihat Bukti</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row_cuti['jumlah_hari']); ?></td>
                            <td class="action-buttons">
                                <?php if ($row_cuti['status_cuti'] == 'Menunggu'): ?>
                                    <a href="edit_cuti.php?id=<?php echo htmlspecialchars($row_cuti['id_cuti']); ?>" class="edit-button">Edit</a>
                                    
                                    <form action="status_cuti.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan pengajuan cuti ini?');" style="display: inline-block;">
                                        <input type="hidden" name="id_cuti" value="<?php echo htmlspecialchars($row_cuti['id_cuti']); ?>">
                                        <input type="hidden" name="action" value="batalkan">
                                        <button type="submit" class="cancel-button">Batalkan</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Belum ada pengajuan cuti atau terjadi kesalahan saat mengambil data cuti Anda.</p>
        <?php endif; ?>

        <div class="nav-links">
            <a href="ajukan_cuti.php" class="ajukan-cuti-button">Ajukan Cuti Baru</a>
            <a href="logout.php" class="logout-button">Keluar</a>
        </div>
    </div>

    <script>
    </script>
</body>
</html>