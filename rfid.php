<?php
// Itakda ang timezone para tama ang oras ng pag-tap
date_default_timezone_set('Asia/Manila');

$conn = new mysqli("localhost", "root", "", "rfid_db");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rfid_uid'])) {
    // Linisin ang input mula sa ESP32
    $uid = strtoupper(trim($_POST['rfid_uid']));
    
    // 1. Hanapin ang estudyante sa 'parents' table gamit ang rfid_uid
    // Gagamit tayo ng REPLACE para masiguro na kahit may space o wala ang UID ay mag-ma-match sila
    $stmt = $conn->prepare("SELECT student_name FROM parents WHERE UPPER(REPLACE(rfid_uid, ' ', '')) = UPPER(REPLACE(?, ' ', ''))");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $name = $row['student_name'];
        $currentTime = date('Y-m-d H:i:s');
        
        // 2. Logic para sa LATE (Halimbawa: Pag lagpas 8:00 AM, Late na)
        $status_late = (date('H:i') > '08:00') ? 'Late' : 'None';
        $status_present = 'Present';
        $status_absent = 'None';

        // 3. I-record sa 'attendance' table
        // Pansinin ang backticks (`) sa `time in` dahil may space ang column name mo
        $ins = $conn->prepare("INSERT INTO attendance (student_name, `time in`, present, absent, late) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("sssss", $name, $currentTime, $status_present, $status_absent, $status_late);
        
        if ($ins->execute()) {
            echo "Success: Attendance recorded for " . $name;
        } else {
            echo "Error: Database insertion failed.";
        }
    } else {
        echo "Error: RFID UID [" . $uid . "] is not registered.";
    }
    
    $stmt->close();
}
$conn->close();
?>