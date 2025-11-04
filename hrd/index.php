<?php
session_start();
include '../includes/db_connection.php'; 

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama_lengkap_input = $_POST['nama_lengkap'];
    $email_input = $_POST['email'];

    $nama_lengkap_input_lower = strtolower($nama_lengkap_input);
    $email_input_lower = strtolower($email_input);

    try {
        
        $stmt = $conn->prepare("SELECT id_karyawan, nama_lengkap, jabatan, role FROM karyawan WHERE LOWER(nama_lengkap) = ? AND LOWER(email) = ? AND role = 'HRD'");
        $stmt->bind_param("ss", $nama_lengkap_input_lower, $email_input_lower);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc(); 

            $_SESSION['id_karyawan'] = $row['id_karyawan']; 
            $_SESSION['nama_lengkap'] = $row['nama_lengkap'];
            $_SESSION['jabatan'] = $row['jabatan'];
            $_SESSION['role'] = $row['role']; 


            error_log("DEBUG (HRD Login): Login HRD Berhasil. ID: " . $_SESSION['id_karyawan']);
            error_log("DEBUG (HRD Login): Nama: " . $_SESSION['nama_lengkap']);
            error_log("DEBUG (HRD Login): Jabatan: " . $_SESSION['jabatan']);
            error_log("DEBUG (HRD Login): Role di Sesi: " . $_SESSION['role']);


            header("Location: ../dashboard_hrd.php"); 
            exit();

        } else {
            $message = "<div class='message error'>Nama Lengkap atau Email tidak terdaftar sebagai HRD. Pastikan data benar.</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='message error'>Terjadi kesalahan teknis: " . $e->getMessage() . "</div>";
        error_log("ERROR (HRD Login): " . $e->getMessage());
    } finally {
        if (isset($stmt)) {
            $stmt->close();
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
    <title>Login HRD - Daiprint</title>
    <link rel="stylesheet" href="../css/style.css"> 
</head>
<body>
    <div class="container"> 
        <h2>DASHBOARD ADMIN & HRD</h2>
        <?php echo $message; ?>
        <form action="index.php" method="POST">
            <div class="form-group">
                <label for="nama_lengkap">Nama Lengkap:</label>
                <input type="text" id="nama_lengkap" name="nama_lengkap" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required autocomplete="off"> </div>
            <button type="submit">Masuk</button>
        </form>
        <div class="switch-roles">  
            <a href="../index.php" class="role-link">Masuk sebagai karyawan</a>
        </div>
    </div>
</body>
</html>