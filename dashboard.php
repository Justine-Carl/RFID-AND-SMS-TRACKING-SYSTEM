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

    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV headers
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
            <h2>Jaen National High School</h2>
        </div>
        <ul>
            <li class="active" onclick="showSection(event,'dashboard')">Dashboard</li>
            <li onclick="showSection(event,'students')">Students</li>
            <li onclick="showSection(event,'parent')">Parent Record</li>
            <li onclick="showSection(event,'reports')">Reports</li>
            <li onclick="showSection(event,'settings')">Settings</li>
        </ul>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="topbar">
            <h3>
                Integrated RFID and SMS Tracking System for Student Attendance
                <br>Welcome, <?php echo htmlspecialchars($_SESSION['user']); ?>
            </h3>
        </div>

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
                    <h4>Attendance Overview</h4>
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
                    // Assumption: sa parents table, ang student field ay student_name
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

        <!-- SETTINGS -->
        <div id="settings" class="section" style="display:none">
            <div class="chart-box">
                <h4>System Settings</h4>
                <label>School Name</label><br>
                <input type="text" value="Jaen National High School"><br><br>
                <label>SMS Notification</label><br>
                <select>
                    <option>Enabled</option>
                    <option>Disabled</option>
                </select><br><br>
                <button class="login-button">Save Settings</button>
            </div>
        </div>

    </div>
</div>

<script>
function showSection(event, sectionId) {
    document.querySelectorAll('.section').forEach(sec => sec.style.display = 'none');
    document.getElementById(sectionId).style.display = 'block';
    document.querySelectorAll('.sidebar ul li').forEach(li => li.classList.remove('active'));
    event.target.classList.add('active');
}

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