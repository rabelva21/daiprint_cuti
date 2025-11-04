<?php
session_start();


if (!isset($_SESSION['id_karyawan']) || $_SESSION['role'] !== 'HRD') {
    die("Akses ditolak. Anda tidak memiliki izin untuk melakukan tindakan ini.");
}

include 'includes/db_connection.php';

$jatah_cuti_baru = 12;

echo "<h2>Reset Jatah Cuti Karyawan</h2>";
echo "<p>Apakah Anda yakin ingin mereset seluruh jatah cuti karyawan menjadi " . $jatah_cuti_baru . " hari?</p>";
echo "<p>Tindakan ini tidak bisa dibatalkan.</p>";
echo "<form method='post'>";
echo "<input type='hidden' name='confirm_reset' value='true'>";
echo "<button type='submit' style='background-color: #dc3545; color: white; padding: 10px 20px; border: none; cursor: pointer;'>Ya, Reset Sekarang!</button>";
echo "</form>";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_reset'])) {

    $stmt = $conn->prepare("UPDATE karyawan SET sisa_cuti = ?");
    $stmt->bind_param("i", $jatah_cuti_baru);

    if ($stmt->execute()) {
        echo "<p style='color: green; font-weight: bold;'>Berhasil! Seluruh jatah cuti karyawan telah direset menjadi " . $jatah_cuti_baru . " hari.</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>Gagal mereset jatah cuti: " . $conn->error . "</p>";
    }
    $stmt->close();
    $conn->close();
}
?>