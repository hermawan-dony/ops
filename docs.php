<?php
session_start();
// Default language
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'id';
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'] === 'en' ? 'en' : 'id';
}
$lang = $_SESSION['lang'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transport App - User Manual / Panduan Pengguna</title>
    
    <!-- PDF Export Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #118DFF;
            --primary-dark: #0070d6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --purple: #8b5cf6;
            --dark: #0f172a;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --light: #f8fafc;
            --border: #e2e8f0;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --hover-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        * { box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            margin: 0;
            color: #334155;
            line-height: 1.7;
        }

        /* Top Navigation */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 40px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid var(--border);
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-icon {
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(17, 141, 255, 0.3);
        }
        .header h1 {
            margin: 0;
            font-size: 1.4rem;
            color: var(--dark);
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }
        
        .controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary { 
            background: var(--primary); 
            color: white; 
            box-shadow: 0 4px 6px rgba(17, 141, 255, 0.2);
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: 0 6px 12px rgba(17, 141, 255, 0.3);
            transform: translateY(-1px);
        }
        .btn-outline { 
            background: #fff; 
            border: 1px solid var(--border); 
            color: var(--gray); 
        }
        .btn-outline:hover { 
            border-color: var(--primary); 
            color: var(--primary);
            background: rgba(17, 141, 255, 0.05);
        }

        /* Layout */
        .container {
            display: flex;
            max-width: 1400px;
            margin: 30px auto;
            gap: 30px;
            padding: 0 20px;
        }
        
        /* Sidebar Navigation */
        .sidebar {
            width: 280px;
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            height: fit-content;
            position: sticky;
            top: 100px;
            border: 1px solid var(--border);
        }
        .sidebar-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray);
            font-weight: 700;
            margin-bottom: 12px;
            padding-left: 12px;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--gray);
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .sidebar a:hover {
            background: var(--light);
            color: var(--dark);
        }
        .sidebar a.active {
            background: linear-gradient(to right, rgba(17, 141, 255, 0.1), transparent);
            color: var(--primary);
            border-left: 4px solid var(--primary);
        }
        .sidebar-icon {
            font-size: 1.2rem;
        }

        /* Main Content Area */
        .content {
            flex: 1;
            background: #fff;
            padding: 50px 60px;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border);
            max-width: 100%;
            overflow: hidden;
        }
        
        /* Typography inside content */
        .content h1 {
            font-size: 2.2rem;
            color: var(--dark);
            margin-bottom: 15px;
            letter-spacing: -1px;
        }
        .content h2 { 
            font-size: 1.6rem;
            color: var(--dark); 
            border-bottom: 2px solid var(--border); 
            padding-bottom: 15px; 
            margin-top: 50px; 
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .content h2:first-child { margin-top: 0; }
        .content h3 { 
            font-size: 1.2rem;
            color: var(--primary); 
            margin-top: 35px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .content p {
            font-size: 1rem;
            color: #475569;
            margin-bottom: 16px;
        }
        .content ul, .content ol {
            padding-left: 24px;
            margin-bottom: 24px;
        }
        .content li {
            margin-bottom: 10px;
            color: #475569;
        }
        .highlight {
            background: rgba(245, 158, 11, 0.15);
            color: #b45309;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Info Boxes */
        .info-box {
            background: rgba(17, 141, 255, 0.05);
            border-left: 4px solid var(--primary);
            padding: 16px 20px;
            border-radius: 0 8px 8px 0;
            margin: 24px 0;
            display: flex;
            gap: 16px;
        }
        .info-box.warning {
            background: rgba(245, 158, 11, 0.05);
            border-left-color: var(--warning);
        }
        .info-box.success {
            background: rgba(16, 185, 129, 0.05);
            border-left-color: var(--success);
        }
        .info-icon { font-size: 1.5rem; }
        .info-content h4 { margin: 0 0 5px 0; color: var(--dark); font-size: 1rem; }
        .info-content p { margin: 0; font-size: 0.9rem; color: var(--gray); }

        /* Step Cards */
        .step-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .step-card {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: var(--hover-shadow);
        }
        .step-card:hover {
            box-shadow: var(--hover-shadow);
        }
        .step-number {
            display: inline-flex;
            width: 32px;
            height: 32px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .step-card h4 {
            margin: 0 0 10px 0;
            color: var(--dark);
        }

        /* Beautiful Mockups (Pure CSS UI Representations) */
        .mockup-container {
            display: flex;
            gap: 30px;
            margin: 40px 0;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }
        .mockup-phone {
            width: 300px;
            height: 600px;
            background: #fff;
            border: 8px solid #cbd5e1;
            border-radius: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .mockup-phone::before { /* Notch */
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 120px; height: 24px;
            background: #cbd5e1;
            border-radius: 0 0 12px 12px;
            z-index: 10;
        }
        .mockup-header {
            background: var(--primary);
            color: white;
            padding: 35px 20px 15px;
            text-align: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .mockup-body {
            flex: 1;
            background: #f8fafc;
            padding: 15px;
            overflow-y: auto;
        }
        .mockup-bottom-nav {
            background: #fff;
            display: flex;
            justify-content: space-around;
            padding: 12px 0;
            border-top: 1px solid var(--border);
        }
        .mockup-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 0.65rem;
            color: var(--gray);
            gap: 4px;
        }
        .mockup-nav-item.active { color: var(--primary); font-weight: bold; }
        
        .m-card {
            background: #fff;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            border: 1px solid var(--border);
            margin-bottom: 15px;
        }
        .m-badge {
            font-size: 0.65rem; padding: 3px 8px; border-radius: 50px; font-weight: 700; text-transform: uppercase;
        }
        .m-btn {
            background: var(--primary); color: white; border-radius: 8px; padding: 10px; text-align: center; font-size: 0.85rem; font-weight: bold; margin-top: 10px;
        }
        
        /* Language handling classes */
        .lang-en, .lang-id { display: none; }
        <?php if($lang == 'en'): ?>
            .lang-en { display: block; }
            span.lang-en { display: inline; }
        <?php else: ?>
            .lang-id { display: block; }
            span.lang-id { display: inline; }
        <?php endif; ?>

        /* PDF print adjustments */
        @media print {
            .sidebar, .header, .controls { display: none !important; }
            .container { margin: 0; max-width: 100%; display: block; padding: 0; }
            .content { box-shadow: none; padding: 20px; border: none; }
            body { background: #fff; }
            .page-break { page-break-before: always; }
            .mockup-container { margin: 20px 0; }
            .mockup-phone { border: 2px solid #000; box-shadow: none; transform: scale(0.9); transform-origin: top center; height: 500px;}
        }
        
        /* Mobile adjustment */
        @media (max-width: 900px) {
            .container { flex-direction: column; padding: 10px; margin: 10px auto; }
            .sidebar { width: 100%; position: static; display: flex; overflow-x: auto; padding: 10px; gap: 10px; }
            .sidebar-title { display: none; }
            .sidebar a { white-space: nowrap; margin: 0; }
            .header { padding: 15px; flex-direction: column; gap: 15px; }
            .content { padding: 25px 20px; }
            .mockup-container { flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-brand">
            <div class="brand-icon">T</div>
            <div>
                <h1>Transport App</h1>
                <p>Digital Logbook & Claims System</p>
            </div>
        </div>
        <div class="controls">
            <div style="background: var(--light); padding: 4px; border-radius: 8px; display: flex; gap: 4px; border: 1px solid var(--border);">
                <a href="?lang=id" class="btn <?= $lang == 'id' ? 'btn-primary' : '' ?>" style="padding: 6px 12px;">🇮🇩 ID</a>
                <a href="?lang=en" class="btn <?= $lang == 'en' ? 'btn-primary' : '' ?>" style="padding: 6px 12px;">🇬🇧 EN</a>
            </div>
            <button onclick="downloadPDF()" class="btn btn-outline" style="border-color: var(--danger); color: var(--danger);">
                <span style="font-size: 1.1rem;">📄</span> 
                <span class="lang-id">Unduh PDF</span><span class="lang-en">Download PDF</span>
            </button>
            <a href="javascript:history.back()" class="btn btn-outline">
                <span class="lang-id">🔙 Kembali</span><span class="lang-en">🔙 Back</span>
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="sidebar-title">
                <span class="lang-id">Daftar Isi</span><span class="lang-en">Table of Contents</span>
            </div>
            <a href="#intro" class="active"><span class="sidebar-icon">🚀</span> <span class="lang-id">Pengenalan</span><span class="lang-en">Introduction</span></a>
            <a href="#driver"><span class="sidebar-icon">🚗</span> <span class="lang-id">Panduan Driver</span><span class="lang-en">Driver Guide</span></a>
            <a href="#passenger"><span class="sidebar-icon">👤</span> <span class="lang-id">Panduan Penumpang</span><span class="lang-en">Passenger Guide</span></a>
            <a href="#supervisor"><span class="sidebar-icon">👔</span> <span class="lang-id">Panduan Supervisor</span><span class="lang-en">Supervisor Guide</span></a>
            <a href="#admin"><span class="sidebar-icon">⚙️</span> <span class="lang-id">Panduan Admin</span><span class="lang-en">Admin Guide</span></a>
            <a href="#settings"><span class="sidebar-icon">🔧</span> <span class="lang-id">Pengaturan Akun</span><span class="lang-en">Account Settings</span></a>
        </div>

        <!-- Main Content -->
        <div class="content" id="pdf-content">
            
            <!-- Cover Page for PDF -->
            <div style="text-align: center; padding: 60px 0; border-bottom: 2px solid var(--border); margin-bottom: 50px;">
                <div style="font-size: 4rem; margin-bottom: 20px;">📘</div>
                <h1 style="font-size: 3rem; color: var(--dark); margin-bottom: 10px; font-weight: 800;">Transport App</h1>
                <h2 style="border: none; font-size: 1.5rem; margin-top: 0; color: var(--gray); font-weight: 400;">
                    <span class="lang-id">Buku Panduan Pengguna Lengkap & Dokumentasi Sistem</span>
                    <span class="lang-en">Comprehensive User Manual & System Documentation</span>
                </h2>
                <div style="margin-top: 30px; display: inline-block; padding: 10px 20px; background: var(--light); border-radius: 50px; font-size: 0.9rem; font-weight: 600; color: var(--primary);">
                    Versi 2.0 • 2026
                </div>
            </div>

            <!-- INTRODUCTION -->
            <section id="intro">
                <h2>🚀 <span class="lang-id">Pengenalan Aplikasi</span><span class="lang-en">App Introduction</span></h2>
                <div class="lang-id">
                    <p><b>Transport App</b> adalah sistem digital terpadu yang dirancang untuk menggantikan pencatatan manual berbasis kertas (logbook & bon) dalam operasional transportasi perusahaan. Aplikasi ini memfasilitasi komunikasi dan transparansi antara Driver, Penumpang, Supervisor, dan Admin (HR/Finance).</p>
                    
                    <div class="info-box success">
                        <div class="info-icon">📲</div>
                        <div class="info-content">
                            <h4>Instalasi sebagai Aplikasi Mobile (PWA)</h4>
                            <p>Anda tidak perlu mengunduh aplikasi ini dari Play Store! Cukup buka aplikasi di browser HP Anda (Chrome/Safari), lalu tunggu beberapa saat hingga muncul notifikasi <b>"Install this web app on your home screen"</b>. Tekan tombol <b>Install</b>, dan aplikasi ini akan muncul di layar utama HP Anda dengan ikon khusus dan berjalan layaknya aplikasi native tanpa frame browser.</p>
                        </div>
                    </div>
                </div>
                <div class="lang-en">
                    <p><b>Transport App</b> is an integrated digital system designed to replace manual paper-based logging (logbooks & receipts) in corporate transport operations. It facilitates communication and transparency between Drivers, Passengers, Supervisors, and Admins (HR/Finance).</p>
                    
                    <div class="info-box success">
                        <div class="info-icon">📲</div>
                        <div class="info-content">
                            <h4>Installation as a Mobile App (PWA)</h4>
                            <p>You don't need to download this app from the Play Store! Simply open the app in your mobile browser (Chrome/Safari), wait a moment for the <b>"Install this web app on your home screen"</b> prompt to appear. Press the <b>Install</b> button, and the app will appear on your home screen with a dedicated icon, running like a native app without a browser frame.</p>
                        </div>
                    </div>
                </div>
            </section>

            <div class="page-break"></div>

            <!-- DRIVER SECTION -->
            <section id="driver">
                <h2>🚗 <span class="lang-id">Panduan Driver</span><span class="lang-en">Driver Guide</span></h2>
                
                <div class="lang-id">
                    <p>Dashboard utama Driver dirancang khusus untuk kenyamanan penggunaan di perangkat seluler (Handphone). Berikut adalah tahapan lengkap dalam satu hari kerja (Shift):</p>
                    
                    <div class="step-grid">
                        <div class="step-card">
                            <div class="step-number">1</div>
                            <h4>Memulai Shift</h4>
                            <p>Saat tiba di tempat kerja, tekan tombol besar <b>Start Shift</b> di tab Home. Sistem akan meminta akses Lokasi/GPS untuk memvalidasi posisi Anda.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">2</div>
                            <h4>Input Perjalanan</h4>
                            <p>Pilih Tujuan dan Penumpang menggunakan kolom pencarian canggih. Masukkan <b>KM Awal</b> (Kilometer Speedometer saat ini), lalu klik <b>Start Trip</b>.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">3</div>
                            <h4>Selesaikan Perjalanan</h4>
                            <p>Setiba di tujuan, buka tab <b>History</b>. Klik tombol kuning <b>✏️ Edit Trip</b> pada perjalanan terakhir, dan masukkan <b>KM Akhir</b>. Data akan tersimpan.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">4</div>
                            <h4>Input Biaya (Expenses)</h4>
                            <p>Jika Anda mengeluarkan uang untuk Bensin (Gasoline) atau Tol, klik tombol panah <b>▼</b> pada perjalanan tersebut, isi nominalnya, dan **wajib melampirkan foto struk/bon**.</p>
                        </div>
                    </div>

                    <div class="info-box warning">
                        <div class="info-icon">⚠️</div>
                        <div class="info-content">
                            <h4>Penting Terkait Lembur (Overtime)</h4>
                            <p>Perhitungan lembur Anda didasarkan pada waktu Anda menekan <b>End Shift</b> (Clock Out) di penghujung hari. Jangan lupa menekan End Shift setelah seluruh tugas selesai agar sistem dapat menghitung nilai konversi lembur secara otomatis.</p>
                        </div>
                    </div>
                </div>

                <div class="lang-en">
                    <p>The main Driver dashboard is specifically designed for mobile device convenience. Here are the complete steps for a workday (Shift):</p>
                    
                    <div class="step-grid">
                        <div class="step-card">
                            <div class="step-number">1</div>
                            <h4>Starting a Shift</h4>
                            <p>Upon arriving at work, press the large <b>Start Shift</b> button on the Home tab. The system will request GPS access to validate your location.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">2</div>
                            <h4>Inputting a Trip</h4>
                            <p>Select Destination and Passenger using the search fields. Enter the <b>Start KM</b> (current speedometer), then click <b>Start Trip</b>.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">3</div>
                            <h4>Completing a Trip</h4>
                            <p>Upon arrival, open the <b>History</b> tab. Click the yellow <b>✏️ Edit Trip</b> button on the latest trip, and enter the <b>End KM</b>.</p>
                        </div>
                        <div class="step-card">
                            <div class="step-number">4</div>
                            <h4>Inputting Expenses</h4>
                            <p>If you spent money on Gas or Tolls, click the <b>▼</b> arrow on the trip card, enter the amount, and **you must upload a photo of the receipt**.</p>
                        </div>
                    </div>

                    <div class="info-box warning">
                        <div class="info-icon">⚠️</div>
                        <div class="info-content">
                            <h4>Important Note on Overtime</h4>
                            <p>Your overtime calculation is based on the exact time you press <b>End Shift</b> (Clock Out) at the end of the day. Do not forget to clock out so the system can calculate your overtime conversion automatically.</p>
                        </div>
                    </div>
                </div>

                <!-- Driver Mockup -->
                <div class="mockup-container">
                    <div class="mockup-phone">
                        <div class="mockup-header">Home (Clock In)</div>
                        <div class="mockup-body" style="display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 20px;">
                            <div style="font-size: 2.5rem; font-weight: 800; color: var(--primary);">08:00</div>
                            <div style="font-size: 0.85rem; color: var(--gray);">Thursday, 12 Oct</div>
                            <div style="width: 150px; height: 150px; border-radius: 50%; background: linear-gradient(135deg, var(--success), #34d399); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: bold; box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);">
                                Start Shift
                            </div>
                        </div>
                        <div class="mockup-bottom-nav">
                            <div class="mockup-nav-item active"><span style="font-size: 1.2rem;">🚗</span>Home</div>
                            <div class="mockup-nav-item"><span style="font-size: 1.2rem;">🕒</span>History</div>
                            <div class="mockup-nav-item"><span style="font-size: 1.2rem;">⚙️</span>Settings</div>
                        </div>
                    </div>
                    
                    <div class="mockup-phone">
                        <div class="mockup-header">Trip History</div>
                        <div class="mockup-body">
                            <div class="m-card">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                    <span style="font-size: 0.8rem; font-weight: bold; color: var(--primary);">12 Oct - 08:30</span>
                                    <span class="m-badge" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">Pending</span>
                                </div>
                                <div style="font-size: 0.85rem;"><b>Dest:</b> Head Office</div>
                                <div style="font-size: 0.85rem;"><b>Pass:</b> Mr. John Doe</div>
                                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 5px;">KM: 15000 ➔ <span style="color:var(--danger)">?</span></div>
                                
                                <div style="display: flex; gap: 8px; margin-top: 15px;">
                                    <div class="m-btn" style="flex: 1; background: #fff; color: var(--warning); border: 1px solid var(--warning);">✏️ Edit Trip (KM)</div>
                                    <div class="m-btn" style="width: 40px; background: #fff; color: var(--gray); border: 1px solid var(--border);">▼</div>
                                </div>
                            </div>
                        </div>
                        <div class="mockup-bottom-nav">
                            <div class="mockup-nav-item"><span style="font-size: 1.2rem;">🚗</span>Home</div>
                            <div class="mockup-nav-item active"><span style="font-size: 1.2rem;">🕒</span>History</div>
                            <div class="mockup-nav-item"><span style="font-size: 1.2rem;">⚙️</span>Settings</div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="page-break"></div>

            <!-- PASSENGER SECTION -->
            <section id="passenger">
                <h2>👤 <span class="lang-id">Panduan Penumpang</span><span class="lang-en">Passenger Guide</span></h2>
                
                <div class="lang-id">
                    <p>Sebagai Penumpang, peran Anda adalah memastikan perjalanan yang dicatat oleh Driver valid dan benar adanya.</p>
                    <ul>
                        <li><b>Pending Trips:</b> Daftar perjalanan yang baru saja Anda selesaikan. Klik <b>Approve Trip</b> untuk mengkonfirmasi bahwa Anda memang diantar oleh Driver tersebut ke lokasi yang tercantum.</li>
                        <li><b>Penilaian (Rating):</b> Anda bisa memberikan rating kepuasan layanan (Bintang 1 hingga 5).</li>
                        <li><b>Trip History:</b> Tab untuk melihat rekam jejak perjalanan Anda sebelumnya yang sudah disetujui.</li>
                    </ul>
                </div>
                <div class="lang-en">
                    <p>As a Passenger, your role is to validate the trips recorded by the Driver.</p>
                    <ul>
                        <li><b>Pending Trips:</b> A list of trips you recently completed. Click <b>Approve Trip</b> to confirm you were indeed driven by that Driver to the listed location.</li>
                        <li><b>Rating:</b> You can provide a service satisfaction rating (1 to 5 stars).</li>
                        <li><b>Trip History:</b> A tab to view the historical record of your previously approved trips.</li>
                    </ul>
                </div>
            </section>

            <!-- SUPERVISOR SECTION -->
            <section id="supervisor">
                <h2>👔 <span class="lang-id">Panduan Supervisor</span><span class="lang-en">Supervisor Guide</span></h2>
                
                <div class="lang-id">
                    <p>Akun Supervisor tergabung di dalam Dashboard Passenger. Jika Admin menunjuk Anda sebagai Supervisor untuk seorang/beberapa Driver, Anda akan otomatis melihat 2 tab baru:</p>
                    
                    <h3>Tab Driver OT (Overtime)</h3>
                    <p>Menampilkan rekaman jam lembur harian Driver. Anda harus me-<i>review</i> apakah lembur tersebut beralasan (misalnya mengantar tamu hingga malam).</p>
                    <ul>
                        <li>Gunakan tombol biru <b>Trips</b> untuk melihat rincian ke mana Driver pergi pada tanggal tersebut.</li>
                        <li>Jika sesuai, klik tombol hijau <b>Approve</b>.</li>
                        <li>Anda juga dapat menyisipkan pesan/alasan di form <b>Supervisor Note</b> yang muncul sebelum menyetujui.</li>
                    </ul>

                    <h3>Tab Driver Exp (Expenses)</h3>
                    <p>Menampilkan seluruh bon dan pengeluaran Driver Anda. Supervisor **tidak bertugas menyetujui biaya** (itu tugas Admin), tetapi Supervisor berhak memberikan <b>Note (Catatan Kuning)</b> pada pengeluaran yang janggal agar dibaca oleh Admin/Finance.</p>
                </div>

                <div class="lang-en">
                    <p>Supervisor access is integrated within the Passenger Dashboard. If an Admin assigns you as a Supervisor for one or more Drivers, you will automatically see 2 new tabs:</p>
                    
                    <h3>Driver OT (Overtime) Tab</h3>
                    <p>Displays the daily overtime records of the Driver. You must review if the overtime was justified (e.g., driving guests late at night).</p>
                    <ul>
                        <li>Use the blue <b>Trips</b> button to see the details of where the Driver went on that specific date.</li>
                        <li>If appropriate, click the green <b>Approve</b> button.</li>
                        <li>You can also insert a message/reason in the <b>Supervisor Note</b> form that appears before approving.</li>
                    </ul>

                    <h3>Driver Exp (Expenses) Tab</h3>
                    <p>Displays all receipts and expenses of your Driver. Supervisors **do not approve expenses** (that is the Admin's job), but Supervisors can add a <b>Note (Yellow Alert)</b> to irregular expenses so the Admin/Finance team can read them.</p>
                </div>
            </section>

            <div class="page-break"></div>

            <!-- ADMIN SECTION -->
            <section id="admin">
                <h2>⚙️ <span class="lang-id">Panduan Admin / HR / Finance</span><span class="lang-en">Admin / HR / Finance Guide</span></h2>
                
                <div class="lang-id">
                    <p>Admin memiliki akses penuh via Desktop/Laptop. Panel navigasi berada di sebelah kiri layar.</p>
                    
                    <h3>1. Master Data</h3>
                    <p>Tempat Anda mendaftarkan Entitas baru:</p>
                    <ul>
                        <li><span class="highlight">Destinations:</span> Masukkan nama-nama lokasi (kantor cabang, mall, hotel) agar mudah dipilih driver.</li>
                        <li><span class="highlight">Cars:</span> Daftarkan Plat Nomor dan Nama Mobil.</li>
                        <li><span class="highlight">Users:</span> Daftarkan akun Driver atau Passenger. Khusus untuk Driver, isi kolom <b>Supervisor</b> dengan nama lengkap penumpang yang menjadi atasannya (Ejaan harus persis sama).</li>
                    </ul>

                    <h3>2. Report & Approval Keuangan</h3>
                    <p>Halaman ini menampilkan tabel data pergerakan, absensi, dan klaim biaya per bulan.</p>
                    <ul>
                        <li>Tabel menampilkan total BBM (Liters) dan nominal uang. Angka berwarna <b>Biru</b> berarti belum disetujui (Pending), angka berwarna <b>Hijau</b> berarti sudah disetujui.</li>
                        <li>Klik pada angka biru tersebut untuk membuka <b>Jendela Verifikasi Bon (Popup)</b>.</li>
                        <li>Di dalam Popup, sistem akan memuat <b>Foto Struk/Bon</b> yang di-upload driver.</li>
                        <li>Jika foto struk menunjukkan Rp 100.000 tapi driver mengetik Rp 150.000, Anda bisa langsung mengganti angka tersebut di form yang tersedia dan menyimpannya (Edit).</li>
                        <li>Jika semua sudah sesuai, klik tombol <b>Approve Expense</b>. Statusnya akan berubah hijau permanen di database.</li>
                    </ul>

                    <h3>3. Attendance Report</h3>
                    <p>Berisi rangkuman jam kerja, absensi, dan total konversi lembur harian setiap driver dalam periode yang Anda pilih. Tabel ini sangat cocok untuk bahan perhitungan <i>Payroll</i> (Gaji) akhir bulan.</p>
                </div>

                <div class="lang-en">
                    <p>Admins have full access via Desktop/Laptop. The navigation panel is on the left side of the screen.</p>
                    
                    <h3>1. Master Data</h3>
                    <p>Where you register new entities:</p>
                    <ul>
                        <li><span class="highlight">Destinations:</span> Enter location names (branch offices, malls, hotels) for easy driver selection.</li>
                        <li><span class="highlight">Cars:</span> Register License Plates and Car Names.</li>
                        <li><span class="highlight">Users:</span> Register Driver or Passenger accounts. For Drivers specifically, fill the <b>Supervisor</b> column with the full name of the passenger who acts as their manager (Spelling must be exact).</li>
                    </ul>

                    <h3>2. Report & Financial Approval</h3>
                    <p>This page displays a tabular view of movements, attendance, and expense claims per month.</p>
                    <ul>
                        <li>The table shows total Fuel (Liters) and monetary amounts. <b>Blue</b> numbers mean pending approval, <b>Green</b> numbers mean approved.</li>
                        <li>Click on the blue numbers to open the <b>Receipt Verification Window (Popup)</b>.</li>
                        <li>Inside the Popup, the system will load the <b>Receipt Photo</b> uploaded by the driver.</li>
                        <li>If the receipt photo shows Rp 100,000 but the driver typed Rp 150,000, you can directly change the amount in the provided form and save it (Edit).</li>
                        <li>If everything is correct, click the <b>Approve Expense</b> button. The status will permanently change to green in the database.</li>
                    </ul>

                    <h3>3. Attendance Report</h3>
                    <p>Contains a summary of working hours, attendance, and total daily overtime conversions for each driver in your selected period. This table is highly suitable for end-of-month <i>Payroll</i> calculations.</p>
                </div>
            </section>

            <div class="page-break"></div>

            <!-- SETTINGS SECTION -->
            <section id="settings">
                <h2>🔧 <span class="lang-id">Pengaturan Akun & Profil</span><span class="lang-en">Account & Profile Settings</span></h2>
                
                <div class="lang-id">
                    <p>Setiap pengguna (Driver maupun Passenger) memiliki menu Settings (Pengaturan) untuk mempersonalisasi akunnya.</p>
                    <ul>
                        <li><b>Foto Profil:</b> Klik lingkaran inisial nama Anda di bagian paling atas Settings untuk mengunggah foto profil dari galeri HP Anda.</li>
                        <li><b>Bahasa (Language):</b> Ganti antarmuka sistem menjadi Bahasa Indonesia atau English kapan saja.</li>
                        <li><b>Tema (Theme):</b> Transport App mendukung <b>Dark Mode (Mode Gelap)</b> yang elegan dan menghemat baterai HP Anda! Klik menu Tema untuk mengubahnya.</li>
                        <li><b>WhatsApp:</b> Pastikan nomor WhatsApp Anda tersimpan dengan benar (gunakan format awalan 62 atau kode negara Anda).</li>
                        <li><b>Password:</b> Ubah kata sandi secara berkala untuk menjaga keamanan data perjalanan Anda.</li>
                    </ul>
                </div>

                <div class="lang-en">
                    <p>Every user (Driver and Passenger) has a Settings menu to personalize their account.</p>
                    <ul>
                        <li><b>Profile Picture:</b> Click the initial circle at the very top of Settings to upload a profile picture from your phone gallery.</li>
                        <li><b>Language:</b> Switch the system interface to Indonesian or English at any time.</li>
                        <li><b>Theme:</b> Transport App supports an elegant <b>Dark Mode</b> that saves your phone battery! Click the Theme menu to toggle it.</li>
                        <li><b>WhatsApp:</b> Make sure your WhatsApp number is saved correctly (use the 62 prefix or your country code).</li>
                        <li><b>Password:</b> Change your password periodically to maintain the security of your travel data.</li>
                    </ul>
                </div>
            </section>
            
            <div style="text-align: center; margin-top: 50px; padding-top: 30px; border-top: 1px solid var(--border); color: var(--gray); font-size: 0.85rem;">
                © 2026 Transport App Management System. All Rights Reserved.
            </div>
        </div>
    </div>

    <script>
        // Smooth scrolling & Sidebar highlight
        const sections = document.querySelectorAll("section");
        const navLinks = document.querySelectorAll(".sidebar a");

        window.addEventListener("scroll", () => {
            let current = "";
            sections.forEach((section) => {
                const sectionTop = section.offsetTop;
                if (pageYOffset >= sectionTop - 150) {
                    current = section.getAttribute("id");
                }
            });

            navLinks.forEach((link) => {
                link.classList.remove("active");
                if (link.getAttribute("href").substring(1) === current) {
                    link.classList.add("active");
                }
            });
        });

        // Click scrolling
        document.querySelectorAll('.sidebar a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                const headerOffset = 100;
                const elementPosition = targetElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                
                window.scrollTo({
                    top: offsetPosition,
                    behavior: "smooth"
                });
            });
        });

        // PDF Generation function
        function downloadPDF() {
            const element = document.getElementById('pdf-content');
            
            // Show loading state
            const btn = document.querySelector('button[onclick="downloadPDF()"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ <span class="lang-id">Memproses PDF...</span><span class="lang-en">Generating...</span>';
            btn.style.opacity = '0.7';

            const opt = {
                margin:       [20, 15, 20, 15],
                filename:     'Transport_App_User_Manual.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            // Use html2pdf
            html2pdf().set(opt).from(element).save().then(() => {
                // Restore button
                btn.innerHTML = originalText;
                btn.style.opacity = '1';
            }).catch(err => {
                console.error("PDF Generation Error: ", err);
                alert("Error generating PDF. Please try again.");
                btn.innerHTML = originalText;
                btn.style.opacity = '1';
            });
        }
    </script>
</body>
</html>
