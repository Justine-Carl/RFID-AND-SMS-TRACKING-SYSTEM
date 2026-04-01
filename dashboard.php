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

/* CREATE SETTINGS TABLE IF NOT EXISTS */
$createSettingsTable = "
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_name VARCHAR(255) NOT NULL,
    sms_notification VARCHAR(20) NOT NULL DEFAULT 'Enabled',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($createSettingsTable);

/* INSERT DEFAULT SETTINGS IF TABLE IS EMPTY */
$checkSettings = $conn->query("SELECT COUNT(*) AS total FROM system_settings");
if ($checkSettings) {
    $row = $checkSettings->fetch_assoc();
    if ((int)$row['total'] === 0) {
        $defaultSchool = "Jaen National High School";
        $defaultSms = "Enabled";

        $stmt = $conn->prepare("INSERT INTO system_settings (school_name, sms_notification) VALUES (?, ?)");
        $stmt->bind_param("ss", $defaultSchool, $defaultSms);
        $stmt->execute();
        $stmt->close();
    }
}

/* LOAD SAVED SETTINGS */
$schoolName = "Jaen National High School";
$smsNotification = "Enabled";
$settingsId = 1;

$settingsResult = $conn->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
if ($settingsResult && $settingsResult->num_rows > 0) {
    $settingsData = $settingsResult->fetch_assoc();
    $settingsId = (int)$settingsData['id'];
    $schoolName = $settingsData['school_name'];
    $smsNotification = $settingsData['sms_notification'];
}

/* HANDLE SAVE SETTINGS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $school_name = trim($_POST['school_name'] ?? '');
    $sms_notification = trim($_POST['sms_notification'] ?? 'Enabled');

    if ($school_name === "") {
        $school_name = "Jaen National High School";
    }

    if ($sms_notification !== "Enabled" && $sms_notification !== "Disabled") {
        $sms_notification = "Enabled";
    }

    $stmt = $conn->prepare("UPDATE system_settings SET school_name = ?, sms_notification = ? WHERE id = ?");
    $stmt->bind_param("ssi", $school_name, $sms_notification, $settingsId);

    if ($stmt->execute()) {
        $_SESSION['settings_saved'] = true;
        header("Location: dashboard.php?section=settings");
        exit();
    } else {
        $message = "Failed to save settings.";
    }

    $stmt->close();
}

/* RELOAD SETTINGS AFTER SAVE/REDIRECT */
$settingsResult = $conn->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
if ($settingsResult && $settingsResult->num_rows > 0) {
    $settingsData = $settingsResult->fetch_assoc();
    $settingsId = (int)$settingsData['id'];
    $schoolName = $settingsData['school_name'];
    $smsNotification = $settingsData['sms_notification'];
}

/* ONE-TIME ALERT FLAG */
$showSavedAlert = false;
if (isset($_SESSION['settings_saved']) && $_SESSION['settings_saved'] === true) {
    $showSavedAlert = true;
    unset($_SESSION['settings_saved']);
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
        $sql = "SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late`
                FROM attendance
                WHERE DATE(`time in`) = CURDATE()
                ORDER BY `time in` ASC";
    } elseif ($type === 'monthly') {
        $filename = 'monthly_attendance_report_' . date('Y-m') . '.csv';
        $sql = "SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late`
                FROM attendance
                WHERE MONTH(`time in`) = MONTH(CURDATE())
                  AND YEAR(`time in`) = YEAR(CURDATE())
                ORDER BY `time in` ASC";
    } else {
        return;
    }

    $result = $conn->query($sql);
    if (!$result) {
        die('Failed to generate report: ' . $conn->error);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Student Name', 'Time In', 'Time Out', 'Present', 'Absent', 'Late']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['student_name'] ?? '',
            $row['time in'] ?? '',
            $row['time out'] ?? '',
            $row['present'] ?? '',
            $row['absent'] ?? '',
            $row['late'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}

/* HANDLE DOWNLOAD */
if (isset($_GET['download'])) {
    downloadReport($conn, $_GET['download']);
}

/* FETCH DATA FOR DASHBOARD */
$students = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE DATE(`time in`) = CURDATE()");
$present  = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `present` = 'Present' AND DATE(`time in`) = CURDATE()");
$absent   = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `absent` = 'Absent' AND DATE(`time in`) = CURDATE()");
$late     = getCount($conn, "SELECT COUNT(*) as total FROM attendance WHERE `late` = 'Late' AND DATE(`time in`) = CURDATE()");
$rate     = ($students > 0) ? round(($present / $students) * 100) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>RFID Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-page">
<div class="dashboard-container">

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <img src="bg3.jpg" alt="School Logo">
            <h2>Admin Panel</h2>
        </div>
        <ul>
            <li class="active" data-section="dashboard" onclick="showSection(event,'dashboard')">Dashboard</li>
            <li data-section="students" onclick="showSection(event,'students')">Students</li>
            <li data-section="parent" onclick="showSection(event,'parent')">Parent Record</li>
            <li data-section="reports" onclick="showSection(event,'reports')">Reports</li>
            <li data-section="missionvision" onclick="showSection(event,'missionvision')">Mission & Vision</li>
            <li data-section="settings" onclick="showSection(event,'settings')">Settings</li>
        </ul>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1><?php echo htmlspecialchars($schoolName); ?></h1>
                <p>Integrated RFID and SMS Tracking System for Student Attendance Monitoring</p>
            </div>

            <div class="topbar-right">
                <span class="welcome-label">Welcome</span>
                <div class="welcome-user"><?php echo htmlspecialchars($_SESSION['user']); ?></div>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div style="background:#fee2e2;color:#991b1b;padding:12px 15px;margin-bottom:15px;border-radius:10px;font-weight:600;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD -->
        <div id="dashboard" class="section">
            <div class="stats-row">
                <div class="stat-card blue">
                    <h4>Late Students</h4>
                    <p><?php echo $late; ?></p>
                </div>
                <div class="stat-card red">
                    <h4>Total Absent</h4>
                    <p><?php echo $absent; ?></p>
                </div>
                <div class="stat-card orange">
                    <h4>Total Present</h4>
                    <p><?php echo $present; ?></p>
                </div>
                <div class="stat-card green">
                    <h4>Total Students</h4>
                    <p><?php echo $students; ?></p>
                </div>
            </div>

            <div class="content-row">
                <div class="chart-box">
                    <h4>Student Attendance Analytics</h4>
                    <canvas id="attendanceChart"></canvas>
                </div>
                <div class="progress-box">
                    <h4>Attendance Rate</h4>
                    <div class="circle" style="background: conic-gradient(#4e73df <?php echo $rate; ?>%, #e5e7eb 0%)">
                        <span><?php echo $rate; ?>%</span>
                    </div>
                    <p class="progress-label">Monthly Attendance Target</p>
                </div>
            </div>
        </div>

        <!-- STUDENTS SECTION -->
        <div id="students" class="section" style="display:none">
            <div class="chart-box">
                <h4>Student List</h4>
                <table width="100%">
                    <tr>
                        <th>Name</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                    </tr>
                    <?php
                    $result = $conn->query("SELECT * FROM attendance ORDER BY `time in` DESC");
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr>
                                <td>" . htmlspecialchars($row['student_name'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['time in'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['time out'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['present'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['absent'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['late'] ?? '') . "</td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6'>No data available</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- PARENT RECORD -->
        <div id="parent" class="section" style="display:none">
            <div class="chart-box">
                <h4>Parent Records</h4>
                <table width="100%">
                    <tr>
                        <th>Student Name</th>
                        <th>Parent Name</th>
                        <th>Contact Number</th>
                        <th>Address</th>
                    </tr>
                    <?php
                    $parentResult = $conn->query("SELECT `student_name`, parent_name, contact_number, address FROM parents ORDER BY id DESC");
                    if ($parentResult && $parentResult->num_rows > 0) {
                        while ($row = $parentResult->fetch_assoc()) {
                            echo "<tr>
                                <td>" . htmlspecialchars($row['student_name'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['parent_name'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['contact_number'] ?? '') . "</td>
                                <td>" . htmlspecialchars($row['address'] ?? '') . "</td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4'>No parent records found</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- REPORTS -->
        <div id="reports" class="section" style="display:none">
            <div class="chart-box">
                <h4>Attendance Reports</h4>
                <form method="GET" action="">
                    <input type="hidden" name="download" value="daily">
                    <button type="submit" class="login-button">Download Daily Report</button>
                </form>
                <br>
                <form method="GET" action="">
                    <input type="hidden" name="download" value="monthly">
                    <button type="submit" class="login-button">Download Monthly Report</button>
                </form>
            </div>
        </div>

        <!-- MISSION AND VISION -->
        <div id="missionvision" class="section" style="display:none">
            <div class="mv-section-box">
                <div class="mv-content">
                    <div class="mv-grid">
                        <div class="mv-card">
                            <img src="bg1.jpg" alt="Vision">
                            <h4>Vision</h4>
                            <p>
                                We dream of Filipinos<br>
                                who passionately love their country<br>
                                and whose values and competencies<br>
                                enable them to realize their full potential<br>
                                and contribute meaningfully to building the nation.
                                <br><br>
                                As a learner-centered public institution,<br>
                                the Department of Education<br>
                                continuously improves itself<br>
                                to better serve its stakeholders.
                            </p>
                        </div>

                        <div class="mv-card">
                            <img src="bg4.jpg" alt="Mission">
                            <h4>Mission</h4>
                            <p>
                                To protect and promote the right of every Filipino
                                to quality, equitable, culture-based, and complete
                                basic education where:
                                <br><br>
                                Students learn in a child-friendly, gender-sensitive,
                                safe, and motivating environment.
                                <br><br>
                                Teachers facilitate learning and constantly nurture every learner.
                                <br><br>
                                Administrators and staff, as stewards of the institution,
                                ensure an enabling and supportive environment for effective learning to happen.
                                <br><br>
                                Family, community, and other stakeholders are actively engaged
                                and share responsibility for developing life-long learners.
                            </p>
                        </div>

                        <div class="mv-card">
                            <img src="bg5.jpg" alt="Core Values">
                            <h4>Core Values</h4>
                            <p class="core-values-list">
                                Maka-Diyos<br>
                                Maka-tao<br>
                                Makakalikasan<br>
                                Makabansa
                            </p>
                        </div>
                    </div>

                    <div class="mv-footer">
                        © 2022 by Katrina DL. Pascual (Jaen National High School)
                    </div>
                </div>
            </div>
        </div>

        <!-- SETTINGS -->
        <div id="settings" class="section" style="display:none">
            <div class="chart-box">
                <h4>System Settings</h4>
                <form method="POST">
                    <label>School Name</label><br>
                    <input type="text" name="school_name" value="<?php echo htmlspecialchars($schoolName); ?>" required><br><br>

                    <label>SMS Notification</label><br>
                    <select name="sms_notification">
                        <option value="Enabled" <?php echo ($smsNotification === 'Enabled') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="Disabled" <?php echo ($smsNotification === 'Disabled') ? 'selected' : ''; ?>>Disabled</option>
                    </select><br><br>

                    <button type="submit" name="save_settings" class="login-button">Save Settings</button>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
function openSection(sectionId) {
    document.querySelectorAll('.section').forEach(function(sec) {
        sec.style.display = 'none';
    });

    const target = document.getElementById(sectionId);
    if (target) {
        target.style.display = 'block';
    }

    document.querySelectorAll('.sidebar ul li').forEach(function(li) {
        li.classList.remove('active');
    });

    document.querySelectorAll('.sidebar ul li').forEach(function(li) {
        if (li.getAttribute('data-section') === sectionId) {
            li.classList.add('active');
        }
    });
}

function showSection(event, sectionId) {
    openSection(sectionId);
}

window.onload = function () {
    const params = new URLSearchParams(window.location.search);
    const section = params.get('section') || 'dashboard';

    openSection(section);

    if (<?php echo $showSavedAlert ? 'true' : 'false'; ?>) {
        alert('Settings saved successfully!');
    }
};
const ctx = document.getElementById('attendanceChart');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['Present', 'Absent', 'Late'],
        datasets: [{
            data: [<?php echo $present; ?>, <?php echo $absent; ?>, <?php echo $late; ?>],
            backgroundColor: ['#4e73df', '#e74a3b', '#f6c23e']
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        }
    }
});
</script>

</body>
</html>
