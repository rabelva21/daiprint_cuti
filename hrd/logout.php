<?php
session_start();

unset($_SESSION['hrd_id_karyawan']);
unset($_SESSION['hrd_nama_lengkap']);
unset($_SESSION['hrd_jabatan']);
session_destroy();
header("Location: index.php");
exit();
?>