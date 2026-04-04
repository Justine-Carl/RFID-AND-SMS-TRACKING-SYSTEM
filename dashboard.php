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

/* OPTIONAL EXTRA COUNTS */
$parentCount = getCount($conn, "SELECT COUNT(*) as total FROM parents");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Dashboard</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <img src="bg3.jpg" alt="School Logo">
            <div>
                <h2>RFID Admin</h2>
                <small style="color:rgba(255,255,255,0.7);">Attendance System</small>
            </div>
        </div>

        <div class="menu-title">Main Menu</div>
        <ul>
            <li class="active" data-section="dashboard" onclick="showSection(event,'dashboard')">
                <i class="fa-solid fa-house"></i> Dashboard
            </li>
            <li data-section="students" onclick="showSection(event,'students')">
                <i class="fa-solid fa-user-graduate"></i> Students
            </li>
            <li data-section="parent" onclick="showSection(event,'parent')">
                <i class="fa-solid fa-users"></i> Parent Record
            </li>
            <li data-section="reports" onclick="showSection(event,'reports')">
                <i class="fa-solid fa-file-lines"></i> Reports
            </li>
            <li data-section="missionvision" onclick="showSection(event,'missionvision')">
                <i class="fa-solid fa-bullseye"></i> Mission & Vision
            </li>
            <li data-section="settings" onclick="showSection(event,'settings')">
                <i class="fa-solid fa-gear"></i> Settings
            </li>
        </ul>

        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1><?php echo htmlspecialchars($schoolName); ?></h1>
                <p>Integrated RFID and SMS Tracking System for Student Attendance Monitoring</p>
            </div>

            <div class="topbar-right">
                <div class="admin-badge">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Administrator</span>
                </div>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div style="background:#fee2e2;color:#991b1b;padding:12px 15px;margin-bottom:15px;border-radius:10px;font-weight:600;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div id="dashboard" class="section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-title">Total Students Today</div>
                        <div class="stat-mini-icon"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="stat-body">
                        <div class="stat-icon-large"><i class="fa-solid fa-user-group"></i></div>
                        <div class="stat-value"><?php echo $students; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-title">Present Today</div>
                        <div class="stat-mini-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo ($students > 0 ? round(($present / $students) * 100) : 0); ?>%">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $present; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-title">Late Today</div>
                        <div class="stat-mini-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo ($students > 0 ? round(($late / max($students, 1)) * 100) : 0); ?>%">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $late; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-title">Attendance Percentage</div>
                        <div class="stat-mini-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    </div>
                    <div class="stat-body">
                        <div class="ring" style="--value: <?php echo $rate; ?>%">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo $rate; ?>%</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-title">Parent Records</div>
                        <div class="stat-mini-icon"><i class="fa-solid fa-address-book"></i></div>
                    </div>
                    <div class="stat-body">
                        <div class="stat-icon-large"><i class="fa-solid fa-id-card"></i></div>
                        <div class="stat-value"><?php echo $parentCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="grid-2">
                <div class="box">
                    <div class="box-title">Student Attendance Analytics</div>
                    <div class="sub-text">Today’s attendance summary based on RFID logs.</div>
                    <canvas id="attendanceChart" height="110"></canvas>
                </div>

                <div class="box progress-panel">
                    <div class="box-title">Attendance Rate</div>
                    <div class="big-progress">
                        <span><?php echo $rate; ?>%</span>
                    </div>
                    <p>Overall present percentage for today.</p>
                </div>
            </div>

            <div class="box">
                <div class="section-title-row">
                    <div>
                        <div class="box-title" style="margin-bottom:4px;">Attendance Records Preview</div>
                        <div class="sub-text" style="margin:0;">Latest student attendance logs.</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                                <th>Absent</th>
                                <th>Late</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $previewResult = $conn->query("SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late`
                                                           FROM attendance
                                                           ORDER BY `time in` DESC
                                                           LIMIT 10");

                            if ($previewResult && $previewResult->num_rows > 0) {
                                while ($row = $previewResult->fetch_assoc()) {
                                    $presentText = trim((string)($row['present'] ?? ''));
                                    $absentText = trim((string)($row['absent'] ?? ''));
                                    $lateText = trim((string)($row['late'] ?? ''));

                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['student_name'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['time in'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['time out'] ?? '') . "</td>";

                                    if (strcasecmp($presentText, 'Present') === 0) {
                                        echo "<td><span class='badge badge-green'>Present</span></td>";
                                    } else {
                                        echo "<td><span class='badge badge-red'>Not Marked</span></td>";
                                    }

                                    if (strcasecmp($absentText, 'Absent') === 0) {
                                        echo "<td><span class='badge badge-red'>Absent</span></td>";
                                    } else {
                                        echo "<td>-</td>";
                                    }

                                    if (strcasecmp($lateText, 'Late') === 0) {
                                        echo "<td><span class='badge badge-yellow'>Late</span></td>";
                                    } else {
                                        echo "<td>-</td>";
                                    }

                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6'>No attendance records found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- STUDENTS SECTION -->
        <div id="students" class="section" style="display:none">
            <div class="box">
                <div class="box-title">Student List</div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                            </tr>
                        </thead>
                        <tbody>
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PARENT RECORD -->
        <div id="parent" class="section" style="display:none">
            <div class="box">
                <div class="box-title">Parent Records</div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Parent Name</th>
                                <th>Contact Number</th>
                                <th>Address</th>
                            </tr>
                        </thead>
                        <tbody>
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- REPORTS -->
        <div id="reports" class="section" style="display:none">
            <div class="box">
                <div class="section-title-row">
                    <div>
                        <div class="box-title" style="margin-bottom:4px;">Attendance Records Preview</div>
                        <div class="sub-text" style="margin:0;">Export daily or monthly attendance reports.</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $reportResult = $conn->query("SELECT `student_name`, `time in`, `time out`, `present`, `absent`, `late`
                                                          FROM attendance
                                                          ORDER BY `time in` DESC");

                            if ($reportResult && $reportResult->num_rows > 0) {
                                while ($row = $reportResult->fetch_assoc()) {
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
                                echo "<tr><td colspan='6' style='text-align:center;'>No attendance records found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                    <form method="GET" action="">
                        <input type="hidden" name="download" value="daily">
                        <button type="submit" class="login-button">
                            <i class="fa-solid fa-download"></i> Download Daily Report
                        </button>
                    </form>

                    <form method="GET" action="">
                        <input type="hidden" name="download" value="monthly">
                        <button type="submit" class="login-button">
                            <i class="fa-solid fa-download"></i> Download Monthly Report
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- MISSION AND VISION -->
        <div id="missionvision" class="section" style="display:none">
            <div class="mv-grid">
                <div class="mv-card">
                    <img src="bg1.jpg" alt="Vision">
                    <h4>Vision</h4>
                    <p>
                        We dream of Filipinos who passionately love their country and whose values and competencies
                        enable them to realize their full potential and contribute meaningfully to building the nation.
                        <br><br>
                        As a learner-centered public institution, the Department of Education continuously improves itself
                        to better serve its stakeholders.
                    </p>
                </div>

                <div class="mv-card">
                    <img src="bg4.jpg" alt="Mission">
                    <h4>Mission</h4>
                    <p>
                        To protect and promote the right of every Filipino to quality, equitable, culture-based,
                        and complete basic education where students learn in a child-friendly, gender-sensitive,
                        safe, and motivating environment.
                        <br><br>
                        Teachers facilitate learning and constantly nurture every learner.
                    </p>
                </div>

                <div class="mv-card">
                    <img src="bg5.jpg" alt="Core Values">
                    <h4>Core Values</h4>
                    <p>
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

        <!-- SETTINGS -->
        <div id="settings" class="section" style="display:none">
            <div class="box">
                <div class="box-title">System Settings</div>
                <form method="POST">
                    <label>School Name</label><br>
                    <input type="text" name="school_name" value="<?php echo htmlspecialchars($schoolName); ?>" required><br><br>

                    <label>SMS Notification</label><br>
                    <select name="sms_notification">
                        <option value="Enabled" <?php echo ($smsNotification === 'Enabled') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="Disabled" <?php echo ($smsNotification === 'Disabled') ? 'selected' : ''; ?>>Disabled</option>
                    </select><br><br>

                    <button type="submit" name="save_settings" class="login-button">
                        <i class="fa-solid fa-floppy-disk"></i> Save Settings
                    </button>
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

    const alertOverlay = document.getElementById('settingsAlertOverlay');
    if (alertOverlay) {
        setTimeout(() => {
            alertOverlay.style.transition = "opacity 0.4s ease";
            alertOverlay.style.opacity = "0";
            setTimeout(() => {
                alertOverlay.style.display = "none";
            }, 400);
        }, 2000);
    }

    if (section === 'settings') {
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl + '?section=settings');
    }
};

const ctx = document.getElementById('attendanceChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Present', 'Absent', 'Late'],
            datasets: [{
                label: 'Students',
                data: [<?php echo $present; ?>, <?php echo $absent; ?>, <?php echo $late; ?>],
                backgroundColor: ['#14b8a6', '#ef4444', '#f59e0b'],
                borderRadius: 10,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#e5e7eb'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
