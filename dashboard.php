<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

/* DATABASE CONNECTION */
$conn = new mysqli("localhost", "root", "", "rfid_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";

/* HANDLE ADD STUDENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $student_name = trim($_POST['student_name']);
    $parent_name = trim($_POST['parent_name']);
    $contact = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $rfid_uid = trim($_POST['rfid_uid']);

    $stmt = $conn->prepare("INSERT INTO parents (student_name, parent_name, contact_number, address, rfid_uid) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $student_name, $parent_name, $contact, $address, $rfid_uid);

    if ($stmt->execute()) {
        $_SESSION['action_message'] = "Student added successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    } else {
        $message = "Error: " . $conn->error;
    }
    $stmt->close();
}

/* HANDLE DELETE STUDENT */
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM parents WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['action_message'] = "Record deleted successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    }
    $stmt->close();
}

/* HANDLE EDIT STUDENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $id = intval($_POST['student_id']);
    $s_name = trim($_POST['student_name']);
    $p_name = trim($_POST['parent_name']);
    $contact = trim($_POST['contact_number']);
    $addr = trim($_POST['address']);
    $uid = trim($_POST['rfid_uid']);

    $stmt = $conn->prepare("UPDATE parents SET student_name=?, parent_name=?, contact_number=?, address=?, rfid_uid=? WHERE id=?");
    $stmt->bind_param("sssssi", $s_name, $p_name, $contact, $addr, $uid, $id);
    if ($stmt->execute()) {
        $_SESSION['action_message'] = "Record updated successfully!";
        header("Location: dashboard.php?section=parent");
        exit();
    } else {
        $message = "Update failed: " . $conn->error;
    }
    $stmt->close();
}

/* FIXED SCHOOL NAME */
$schoolName = "Jaen National High School";

/* CREATE SETTINGS TABLE IF NOT EXISTS */
$createSettingsTable = "
CREATE TABLE IF NOT EXISTS system_settings (
    id INT PRIMARY KEY,
    sms_notification VARCHAR(20) NOT NULL DEFAULT 'Enabled',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($createSettingsTable);

/* MAKE SURE ONLY ONE SETTINGS ROW EXISTS */
$defaultSms = "Enabled";
$checkSettings = $conn->query("SELECT * FROM system_settings WHERE id = 1 LIMIT 1");
if (!$checkSettings || $checkSettings->num_rows === 0) {
    $stmt = $conn->prepare("INSERT INTO system_settings (id, sms_notification) VALUES (1, ?)");
    $stmt->bind_param("s", $defaultSms);
    $stmt->execute();
    $stmt->close();
}

/* LOAD SAVED SETTINGS */
$smsNotification = $defaultSms;
$settingsResult = $conn->query("SELECT * FROM system_settings WHERE id = 1 LIMIT 1");
if ($settingsResult && $settingsResult->num_rows > 0) {
    $settingsData = $settingsResult->fetch_assoc();
    $smsNotification = $settingsData['sms_notification'];
}

/* HANDLE SAVE SETTINGS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $sms_notification = trim($_POST['sms_notification'] ?? 'Enabled');
    if (!in_array($sms_notification, ['Enabled', 'Disabled'])) {
        $sms_notification = 'Enabled';
    }
    $stmt = $conn->prepare("UPDATE system_settings SET sms_notification = ? WHERE id = 1");
    $stmt->bind_param("s", $sms_notification);
    if ($stmt->execute()) {
        $_SESSION['settings_saved'] = true;
        header("Location: dashboard.php?section=settings");
        exit();
    } else {
        $message = "Failed to save settings.";
    }
    $stmt->close();
}

/* ALERT FLAGS */
$showSavedAlert = false;
if (isset($_SESSION['settings_saved'])) {
    $showSavedAlert = true;
    unset($_SESSION['settings_saved']);
}
if (isset($_SESSION['action_message'])) {
    $message = $_SESSION['action_message'];
    unset($_SESSION['action_message']);
}

/* SAFE FETCH FUNCTION */
function getCount($conn, $sql) {
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['total'];
    }
    return 0;
}

/* DOWNLOAD REPORT FUNCTION */
function downloadReport($conn, $type) {
    if ($type === 'daily') {
        $filename = 'daily_attendance_report_' . date('Y-m-d') . '.csv';
        $sql = "SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late` FROM attendance WHERE DATE(`time in`) = CURDATE() ORDER BY `time in` ASC";
    } elseif ($type === 'monthly') {
        $filename = 'monthly_attendance_report_' . date('Y-m') . '.csv';
        $sql = "SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late` FROM attendance WHERE MONTH(`time in`) = MONTH(CURDATE()) AND YEAR(`time in`) = YEAR(CURDATE()) ORDER BY `time in` ASC";
    } else { return; }

    $result = $conn->query($sql);
    if (!$result) die('Failed to generate report: ' . $conn->error);

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Student Name', 'Time In', 'Time Out', 'Present', 'Absent', 'Late']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['student_name'] ?? '', $row['time in'] ?? '', $row['time out'] ?? '', $row['present'] ?? '', $row['absent'] ?? '', $row['late'] ?? '']);
    }
    fclose($output);
    exit();
}

if (isset($_GET['download'])) downloadReport($conn, $_GET['download']);

/* FETCH DATA FOR DASHBOARD */
$studentsCount = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE DATE(`time in`) = CURDATE()");
$presentCount  = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `present` = 'Present' AND DATE(`time in`) = CURDATE()");
$absentCount   = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `absent` = 'Absent' AND DATE(`time in`) = CURDATE()");
$lateCount     = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `late` = 'Late' AND DATE(`time in`) = CURDATE()");
$rate = ($studentsCount > 0) ? round(($presentCount / $studentsCount) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .btn-edit { color: #f59e0b; cursor: pointer; border:none; background:none; font-size: 1.1rem; }
        .btn-delete { color: #ef4444; cursor: pointer; border:none; background:none; font-size: 1.1rem; }
        .action-cell { display: flex; gap: 15px; justify-content: center; }
    </style>
</head>
<body class="dashboard-page">

<?php if ($showSavedAlert): ?>
    <div id="settingsAlertOverlay" class="center-alert-overlay">
        <div class="center-alert-box">
            <h3>Success</h3>
            <p>Settings saved successfully!</p>
        </div>
    </div>
<?php endif; ?>

<div class="dashboard-container">
    <div class="sidebar">
        <div class="sidebar-logo">
            <img src="bg3.jpg" alt="Logo">
            <div>
                <h2>RFID Admin</h2>
                <small style="color:rgba(255,255,255,0.7);">Attendance System</small>
            </div>
        </div>
        <div class="menu-title">Main Menu</div>
        <ul>
            <li class="active" data-section="dashboard" onclick="showSection(event,'dashboard')"><i class="fa-solid fa-house"></i> Dashboard</li>
            <li data-section="students" onclick="showSection(event,'students')"><i class="fa-solid fa-user-graduate"></i> Students</li>
            <li data-section="parent" onclick="showSection(event,'parent')"><i class="fa-solid fa-users"></i> Parent Record</li>
            <li data-section="reports" onclick="showSection(event,'reports')"><i class="fa-solid fa-file-lines"></i> Reports</li>
            <li data-section="settings" onclick="showSection(event,'settings')"><i class="fa-solid fa-gear"></i> Settings</li>
        </ul>
        <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1><?php echo htmlspecialchars($schoolName); ?></h1>
                <p>Integrated RFID and SMS Tracking System for Student Attendance Monitoring</p>
            </div>
            <div class="topbar-right">
                <div class="admin-badge"><i class="fa-solid fa-user-shield"></i> <span>Administrator</span></div>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div style="background:#dcfce7;color:#166534;padding:12px 15px;margin-bottom:15px;border-radius:10px;font-weight:600; border: 1px solid #bbf7d0;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div id="dashboard" class="section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Total Students Today</div><div class="stat-mini-icon"><i class="fa-solid fa-users"></i></div></div>
                    <div class="stat-body"><div class="stat-icon-large"><i class="fa-solid fa-user-group"></i></div><div class="stat-value"><?php echo $studentsCount; ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Late Today</div><div class="stat-mini-icon"><i class="fa-solid fa-clock"></i></div></div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo ($studentsCount > 0 ? round(($lateCount / $studentsCount) * 100) : 0); ?>%"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-value"><?php echo $lateCount; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-head"><div class="stat-title">Attendance Rate</div><div class="stat-mini-icon"><i class="fa-solid fa-chart-pie"></i></div></div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo $rate; ?>%"><i class="fa-solid fa-chart-line"></i></div>
                        <div class="stat-value"><?php echo $rate; ?>%</div>
                    </div>
                </div>
            </div>

            <div class="grid-2">
                <div class="box">
                    <div class="box-title">Student Attendance Analytics</div>
                    <canvas id="attendanceChart" height="110"></canvas>
                </div>
                <div class="box progress-panel">
                    <div class="box-title">Attendance Rate</div>
                    <div class="big-progress"><span><?php echo $rate; ?>%</span></div>
                    <p>Overall present percentage for today.</p>
                </div>
            </div>
        </div>

        <div id="students" class="section" style="display:none">
            <div class="box">
                <div class="box-title">Student Attendance List</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Time In</th><th>Time Out</th><th>Present</th><th>Absent</th><th>Late</th></tr></thead>
                        <tbody>
                            <?php
                            $result = $conn->query("SELECT * FROM attendance ORDER BY `time in` DESC");
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo "<tr><td>".htmlspecialchars($row['student_name'])."</td><td>".htmlspecialchars($row['time in'])."</td><td>".htmlspecialchars($row['time out'])."</td><td>".htmlspecialchars($row['present'])."</td><td>".htmlspecialchars($row['absent'])."</td><td>".htmlspecialchars($row['late'])."</td></tr>";
                                }
                            } else { echo "<tr><td colspan='6'>No data available</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="parent" class="section" style="display:none">
            <div class="box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div class="box-title" style="margin:0;">Parent Records</div>
                    <button onclick="document.getElementById('addStudentModal').style.display='flex'" class="login-button" style="width:auto; padding: 10px 20px;">
                        <i class="fa-solid fa-user-plus"></i> Add Student
                    </button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Student Name</th><th>Parent Name</th><th>Contact Number</th><th>Address</th><th>RFID UID</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php
                            $parentResult = $conn->query("SELECT * FROM parents ORDER BY id DESC");
                            if ($parentResult && $parentResult->num_rows > 0) {
                                while ($row = $parentResult->fetch_assoc()) {
                                    echo "<tr>
                                        <td>".htmlspecialchars($row['student_name'])."</td>
                                        <td>".htmlspecialchars($row['parent_name'])."</td>
                                        <td>".htmlspecialchars($row['contact_number'])."</td>
                                        <td>".htmlspecialchars($row['address'])."</td>
                                        <td><small>".htmlspecialchars($row['rfid_uid'])."</small></td>
                                        <td class='action-cell'>
                                            <button class='btn-edit' onclick=\"openEditModal(".json_encode($row).")\"><i class='fa-solid fa-pen-to-square'></i></button>
                                            <a href='?delete_id=".$row['id']."' class='btn-delete' onclick=\"return confirm('Delete this student?')\"><i class='fa-solid fa-trash'></i></a>
                                        </td>
                                    </tr>";
                                }
                            } else { echo "<tr><td colspan='6'>No parent records found</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="reports" class="section" style="display:none">
            <div class="box">
                <div class="box-title">Export Attendance</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Student Name</th><th>Time In</th><th>Time Out</th><th>Present</th><th>Absent</th><th>Late</th></tr></thead>
                        <tbody>
                            <?php
                            $reportResult = $conn->query("SELECT * FROM attendance ORDER BY `time in` DESC LIMIT 15");
                            if ($reportResult && $reportResult->num_rows > 0) {
                                while ($row = $reportResult->fetch_assoc()) {
                                    echo "<tr><td>".htmlspecialchars($row['student_name'])."</td><td>".htmlspecialchars($row['time in'])."</td><td>".htmlspecialchars($row['time out'])."</td><td>".htmlspecialchars($row['present'])."</td><td>".htmlspecialchars($row['absent'])."</td><td>".htmlspecialchars($row['late'])."</td></tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:20px; display:flex; gap:10px;">
                    <a href="?download=daily" class="login-button" style="text-decoration:none; text-align:center;"><i class="fa-solid fa-download"></i> Daily Report</a>
                    <a href="?download=monthly" class="login-button" style="text-decoration:none; text-align:center;"><i class="fa-solid fa-download"></i> Monthly Report</a>
                </div>
            </div>
        </div>

        <div id="settings" class="section" style="display:none">
            <div class="box">
                <div class="box-title">System Settings</div>
                <form method="POST">
                    <label>School Name</label><br>
                    <input type="text" value="Jaen National High School" readonly style="background:#f3f4f6; cursor:not-allowed; width:100%; padding:10px; margin-bottom:15px; border-radius:8px; border:1px solid #ddd;"><br>
                    <label>SMS Notification</label><br>
                    <select name="sms_notification" style="width:100%; padding:10px; margin-bottom:15px; border-radius:8px; border:1px solid #ddd;">
                        <option value="Enabled" <?php echo ($smsNotification === 'Enabled') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="Disabled" <?php echo ($smsNotification === 'Disabled') ? 'selected' : ''; ?>>Disabled</option>
                    </select><br>
                    <button type="submit" name="save_settings" class="login-button"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
                </form>
            </div>
        </div>

    </div>
</div>

<div id="addStudentModal" class="center-alert-overlay" style="display:none; background: rgba(0,0,0,0.6); z-index:9999;">
    <div class="box" style="width: 450px; background: white; padding: 25px; border-radius: 15px;">
        <div class="box-title">Register New Student</div>
        <form method="POST">
            <input type="text" name="student_name" placeholder="Student Full Name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="parent_name" placeholder="Parent Name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="contact_number" placeholder="Contact Number" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="address" placeholder="Address" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="rfid_uid" placeholder="RFID Tag UID" required style="width:100%; padding:10px; margin-bottom:20px; border:1px solid #ddd; border-radius:8px;">
            <div style="display:flex; gap:10px;">
                <button type="submit" name="add_student" class="login-button">Save</button>
                <button type="button" onclick="document.getElementById('addStudentModal').style.display='none'" class="login-button" style="background:#6b7280;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="editStudentModal" class="center-alert-overlay" style="display:none; background: rgba(0,0,0,0.6); z-index:9999;">
    <div class="box" style="width: 450px; background: white; padding: 25px; border-radius: 15px;">
        <div class="box-title">Update Student Information</div>
        <form method="POST">
            <input type="hidden" name="student_id" id="edit_id">
            <input type="text" name="student_name" id="edit_name" placeholder="Student Name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="parent_name" id="edit_parent" placeholder="Parent Name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="contact_number" id="edit_contact" placeholder="Contact Number" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="address" id="edit_address" placeholder="Address" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:8px;">
            <input type="text" name="rfid_uid" id="edit_uid" placeholder="RFID UID" required style="width:100%; padding:10px; margin-bottom:20px; border:1px solid #ddd; border-radius:8px;">
            <div style="display:flex; gap:10px;">
                <button type="submit" name="edit_student" class="login-button">Update</button>
                <button type="button" onclick="document.getElementById('editStudentModal').style.display='none'" class="login-button" style="background:#6b7280;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSection(sectionId) {
    document.querySelectorAll('.section').forEach(sec => sec.style.display = 'none');
    const target = document.getElementById(sectionId);
    if (target) target.style.display = 'block';
    document.querySelectorAll('.sidebar ul li').forEach(li => {
        li.classList.remove('active');
        if (li.getAttribute('data-section') === sectionId) li.classList.add('active');
    });
}

function showSection(event, sectionId) { openSection(sectionId); }

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.student_name;
    document.getElementById('edit_parent').value = data.parent_name;
    document.getElementById('edit_contact').value = data.contact_number;
    document.getElementById('edit_address').value = data.address;
    document.getElementById('edit_uid').value = data.rfid_uid;
    document.getElementById('editStudentModal').style.display = 'flex';
}

window.onload = function () {
    const params = new URLSearchParams(window.location.search);
    openSection(params.get('section') || 'dashboard');
    
    setTimeout(() => {
        const alert = document.getElementById('settingsAlertOverlay');
        if(alert) alert.style.display = 'none';
    }, 3000);
};

const ctx = document.getElementById('attendanceChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Present', 'Absent', 'Late'],
            datasets: [{
                data: [<?php echo $presentCount; ?>, <?php echo $absentCount; ?>, <?php echo $lateCount; ?>],
                backgroundColor: ['#14b8a6', '#ef4444', '#f59e0b'],
                borderRadius: 5
            }]
        },
        options: { plugins: { legend: { display: false } } }
    });
}

setInterval(() => {
    fetch('dashboard.php')
        .then(response => response.text())
        .then(data => {
            const doc = new DOMParser().parseFromString(data, 'text/html');
            // Auto update tables for students and parent records sections
            document.querySelectorAll('.table-wrap').forEach((tw, i) => {
                tw.innerHTML = doc.querySelectorAll('.table-wrap')[i].innerHTML;
            });
            document.querySelector('.stats-grid').innerHTML = doc.querySelector('.stats-grid').innerHTML;
        });
}, 10000);
</script>
</body>
</html>
