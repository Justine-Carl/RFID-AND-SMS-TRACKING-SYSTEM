<?php
session_start();

$valid_user = "admin";
$valid_pass_hash = password_hash("admin123", PASSWORD_DEFAULT);

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = htmlspecialchars(trim($_POST['username']));
    $password = trim($_POST['password']);

    if ($username === $valid_user && password_verify($password, $valid_pass_hash)) {
        $_SESSION['user'] = $username;
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrated RFID and SMS Tracking System for Student Attendance</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">

    <div class="login-overlay"></div>

    <div class="login-container">
        <div class="login-box">
            <img src="bg3.jpg" alt="System Logo" class="login-logo">

            <h2>Welcome Admin</h2>
            <p class="login-subtitle">Please sign in to continue</p>

            <?php if (!empty($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" onsubmit="return validateForm()">
                <div class="input-group">
                    <input type="text" name="username" id="username" placeholder="Username" required>
                </div>

                <div class="input-group password-group">
                    <input type="password" name="password" id="password" placeholder="Password" required>
                </div>

                <label class="show-pass">
                    <input type="checkbox" onclick="togglePassword()"> Show Password
                </label>

                <button type="submit" class="login-button">Login</button>
            </form>
        </div>
    </div>

    <script>
        function togglePassword() {
            let pass = document.getElementById("password");
            pass.type = pass.type === "password" ? "text" : "password";
        }

        function validateForm() {
            let user = document.getElementById("username").value.trim();
            let pass = document.getElementById("password").value.trim();

            if (user.length < 3) {
                alert("Username must be at least 3 characters.");
                return false;
            }

            if (pass.length < 6) {
                alert("Password must be at least 6 characters.");
                return false;
            }

            return true;
        }
    </script>

</body>
</html>