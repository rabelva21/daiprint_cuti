<?php
function formatTanggalIndonesia($tanggal_string) {
    if (!$tanggal_string || $tanggal_string === '0000-00-00' || $tanggal_string === '0000-00-00 00:00:00') {
        return '-'; 
    }

    $nama_bulan = array(
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    );
    
    $date_part = date('Y-m-d', strtotime($tanggal_string));
    $time_part = date('H:i:s', strtotime($tanggal_string));

    $timestamp = strtotime($date_part);
    $tanggal = date('d', $timestamp);
    $bulan = $nama_bulan[(int)date('m', $timestamp)];
    $tahun = date('Y', $timestamp);

    if (strpos($tanggal_string, ':') !== false) {
        return $tanggal . ' ' . $bulan . ' ' . $tahun . ' ' . $time_part;
    } else {
        return $tanggal . ' ' . $bulan . ' ' . $tahun;
    }
}
?>