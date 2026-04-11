<?php
date_default_timezone_set('Asia/Manila');

$conn = new mysqli("localhost", "root", "", "rfid_db");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rfid_uid'])) {
    $uid = strtoupper(trim($_POST['rfid_uid']));
    $now = date('H:i'); 
    $today = date('Y-m-d');
    $currentTime = date('Y-m-d H:i:s');

    
    $stmt = $conn->prepare("SELECT student_name FROM parents WHERE UPPER(REPLACE(rfid_uid, ' ', '')) = UPPER(REPLACE(?, ' ', ''))");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $user_res = $stmt->get_result();

    if ($row = $user_res->fetch_assoc()) {
        $name = $row['student_name'];

       
        $check = $conn->prepare("SELECT * FROM attendance WHERE student_name = ? AND `time in` LIKE ?");
        $search_today = $today . "%";
        $check->bind_param("ss", $name, $search_today);
        $check->execute();
        $attendance_record = $check->get_result()->fetch_assoc();

      
        if ($now >= '06:00' && $now < '12:00') {
            if ($attendance_record) {
                echo "Error: May record ka na ngayong umaga.";
            } else {
                // Status Logic
                if ($now <= '07:30') {
                    $status = "Present"; $late = "None"; $absent = "None";
                } elseif ($now > '07:30' && $now < '08:30') {
                    $status = "Late"; $late = "Late"; $absent = "None";
                } else {
                    $status = "Absent"; $late = "None"; $absent = "Absent";
                }

                $ins = $conn->prepare("INSERT INTO attendance (student_name, `time in`, present, absent, late) VALUES (?, ?, ?, ?, ?)");
                $ins->bind_param("sssss", $name, $currentTime, $status, $absent, $late);
                echo $ins->execute() ? "Success: $status recorded for $name" : "Error sa Insert";
            }
        }

        // B. MORNING OUT (12:00 PM - 12:59 PM)
        elseif ($now >= '12:00' && $now < '13:00') {
            $upd = $conn->prepare("UPDATE attendance SET `time out` = ? WHERE student_name = ? AND `time in` LIKE ?");
            $upd->bind_param("sss", $currentTime, $name, $search_today);
            echo $upd->execute() ? "Success: Morning Out recorded" : "Error sa Update";
        }

        // C. AFTERNOON IN (1:00 PM - 4:29 PM)
        elseif ($now >= '13:00' && $now < '16:30') {
            // Status Logic para sa Hapon
            if ($now <= '13:30') {
                $status = "Present"; $late = "None"; $absent = "None";
            } elseif ($now > '13:30' && $now < '14:00') {
                $status = "Late"; $late = "Late"; $absent = "None";
            } else {
                $status = "Absent"; $late = "None"; $absent = "Absent";
            }

            if ($attendance_record) {
                $upd = $conn->prepare("UPDATE attendance SET `pm_in` = ?, `late` = ?, `present` = ?, `absent` = ? WHERE student_name = ? AND `time in` LIKE ?");
                $upd->bind_param("ssssss", $currentTime, $late, $status, $absent, $name, $search_today);
                echo $upd->execute() ? "Success: PM In recorded ($status)" : "Error sa Update PM";
            } else {
                $ins = $conn->prepare("INSERT INTO attendance (student_name, `pm_in`, present, absent, late, `time in`) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param("ssssss", $name, $currentTime, $status, $absent, $late, $currentTime);
                echo $ins->execute() ? "Success: PM In recorded ($status)" : "Error sa Insert PM";
            }
        }

        // D. AFTERNOON OUT (4:30 PM - 8:00 PM)
        elseif ($now >= '16:30' && $now < '20:00') {
            $upd = $conn->prepare("UPDATE attendance SET `pm_out` = ? WHERE student_name = ? AND `time in` LIKE ?");
            $upd->bind_param("sss", $currentTime, $name, $search_today);
            echo $upd->execute() ? "Success: PM Out recorded" : "Error sa Update PM Out";
        }

        else {
            echo "Error: Closed na ang system (Gabi na). Oras ngayon: $now";
        }

    } else {
        echo "Error: RFID UID not registered.";
    }
}
$conn->close();
?>
