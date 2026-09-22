<?php
require_once 'config.php';

// Cek apakah admin sudah login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Akses ditolak. Silakan login sebagai Admin terlebih dahulu di aplikasi.');
}

$email_status = '';
$repair_status = '';

// 1. Tambahkan kolom email ke master_passengers
try {
    $pdo->exec("ALTER TABLE master_passengers ADD COLUMN email VARCHAR(255) NULL AFTER wa_no");
    $email_status = "<p style='color:#166534;'>✅ Kolom <strong>email</strong> berhasil ditambahkan ke tabel <strong>master_passengers</strong>.</p>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $email_status = "<p style='color:#4b5563;'>ℹ️ Kolom <strong>email</strong> sudah ada di tabel <strong>master_passengers</strong> (tidak diubah).</p>";
    } else {
        $email_status = "<p style='color:#b91c1c;'>❌ Gagal menambahkan kolom email: <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
    }
}

// 2. Perbaiki jam end_time shift historis yang tidak konsisten
try {
    $updateSql = "UPDATE shifts s
                  JOIN (
                      SELECT shift_id, TIME(MAX(end_time)) as last_trip_time
                      FROM trips
                      WHERE status = 'completed'
                      GROUP BY shift_id
                  ) t ON s.id = t.shift_id
                  SET s.end_time = t.last_trip_time
                  WHERE s.end_time < t.last_trip_time OR s.end_time = '00:00:00'";
                  
    $rows = $pdo->exec($updateSql);
    $repair_status = "<p style='color:#166534;'>✅ Sukses memperbaiki <strong>$rows</strong> data shift historis yang tidak konsisten (disamakan dengan waktu trip terakhir).</p>";
} catch (Exception $e) {
    $repair_status = "<p style='color:#b91c1c;'>❌ Gagal memperbaiki data shift: <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
}

// Tampilkan hasil migrasi ke browser
echo "<div style='font-family: sans-serif; padding: 25px; border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 12px; max-width: 650px; margin: 40px auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>";
echo "<h2 style='color:#0f172a; margin-top:0; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;'>⚙️ Hasil Migrasi & Perbaikan Database</h2>";
echo $email_status;
echo $repair_status;
echo "<div style='margin-top: 20px; padding: 15px; border-left: 4px solid #f59e0b; background: #fffbeb;'>";
echo "<p style='color:#b45309; font-weight:bold; margin:0;'>⚠️ PERINGATAN KEAMANAN:</p>";
echo "<p style='color:#b45309; margin: 5px 0 0 0;'>Silakan segera <strong>HAPUS</strong> file <code>migrate.php</code> ini dari file manager hosting Anda agar tidak bisa diakses oleh orang lain.</p>";
echo "</div>";
echo "</div>";
