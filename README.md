# Transport App - Digital Logbook & Claim System

[![User Manual](https://img.shields.io/badge/📖_Read-User_Manual-8b5cf6?style=for-the-badge)](https://ops.framas.co.id/docs.php)
[![Live App](https://img.shields.io/badge/🚀_Live-App-118DFF?style=for-the-badge)](https://ops.framas.co.id)

Transport App is an integrated, mobile-friendly PHP/MySQL application designed to digitize corporate transport operations. It replaces manual paper-based logging for Drivers, and offers transparent approval workflows for Passengers, Supervisors, and Admins.

## 🚀 Features

### 1. Driver Features (Mobile-First PWA)
- **Attendance & GPS Clock In/Out**: Drivers clock in by clicking "Start Shift". The app captures real-time GPS locations to ensure compliance.
- **Trip Logbook**: Drivers log destinations, passengers, and Odometer readings (KM Start and KM End).
- **Expense Claims**: Built-in forms to submit expenses (Gasoline, Tolls, Parking, Meals). Requires mandatory photo upload for receipts.
- **Dynamic Routing & Search**: Auto-complete search for destinations and passengers to speed up data entry.

### 2. Passenger Features
- **Trip Validation**: Passengers can review pending trips and approve them to confirm that the driver indeed drove them to the stated destination.
- **Driver Rating**: Passengers can give 1-5 star ratings for the service they received.

### 3. Supervisor Features
- **Overtime Approval**: Supervisors can review the daily overtime conversion (Conv OT) hours of their assigned drivers, inspect their trip logs for the day, and approve the overtime.
- **Expense Monitoring**: Supervisors can flag suspicious expenses by adding a "Supervisor Note" (Yellow Alert) before the Admin does the final review.

### 4. Admin / HR / Finance Features (Desktop Dashboard)
- **Master Data Management**: Manage Users (Drivers/Passengers), Cars, and standard Destinations.
- **Financial Approvals**: Review driver receipts. Admins can edit amounts/liters if there are typos, read supervisor notes, and finalize expense approvals.
- **Comprehensive Reporting**: View and filter trip reports, expense claims, and attendance records on a monthly or annual basis.

## 🛠 Tech Stack
- **Backend**: Pure PHP 8.x (PDO MySQL)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript, SweetAlert2
- **Database**: MySQL / MariaDB
- **Export/Libraries**: `html2pdf.js` for manual generation, `sheetjs` (xlsx) for Excel exports.

## 📦 Installation & Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/hermawan-dony/ops.git
   cd ops
   ```

2. **Database Setup:**
   - Create a new MySQL database (e.g., `transport_db`).
   - Import the database schema from `schema.sql`:
     ```bash
     mysql -u username -p transport_db < schema.sql
     ```

3. **Configuration:**
   - Open `config.php` and configure your database credentials. 
   - The app detects the environment based on `$_SERVER['HTTP_HOST']` (e.g., local vs production). Adjust the if-else block in `config.php` to match your domain and credentials.

4. **Directory Permissions:**
   - Ensure the `uploads/` directory has write permissions so the system can save profile pictures and expense receipts.

5. **PWA Configuration (Optional but recommended):**
   - The app is PWA-ready. Edit `manifest.json` and adjust the app name and icons to match your company branding.

## 📖 User Manual
The application comes with a built-in, dynamically generated, bilingual User Manual (ID/EN). 
👉 **[Click here to view the Live User Manual](https://ops.framas.co.id/docs.php)**

Once the app is running locally, any user can also click the **Manual** button inside their dashboard to read the guide or download it as a PDF.

---
*Developed for internal transportation and logistics management.*
