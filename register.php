<?php
session_start();
include 'db.php'; // เชื่อมต่อ budget_dtn

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // ตรวจสอบว่าชื่อผู้ใช้มีอยู่แล้วหรือไม่
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        $error = "❌ Username นี้มีผู้ใช้งานแล้ว";
    } else {
        // เข้ารหัสรหัสผ่าน
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // บันทึกลงฐานข้อมูล
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        if ($stmt->execute([$username, $hashedPassword])) {
            $_SESSION['success'] = "✅ สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ";
            header("Location: login.php");
            exit;
        } else {
            $error = "❌ เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f2f5;
        }
        .register-box {
            width: 400px;
            padding: 30px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .error { color: red; text-align: center; }
    </style>
</head>
<body>
   <div class="register-box">
        <h2 class="text-center mb-4">📝 Register</h2>
        <?php if (!empty($error)) echo "<p class='error'>$error</p>"; ?>
        
        <form method="post">
          <div class="form-outline mb-4">
            <input type="text" name="username" class="form-control" required/>
            <label class="form-label">Username</label>
          </div>

          <div class="form-outline mb-4">
            <input type="password" name="password" class="form-control" required/>
            <label class="form-label">Password</label>
          </div>

          <button type="submit" class="btn btn-success w-100 mb-4">Register</button>

          <div class="text-center">
            <p>Already have an account? <a href="login.php">Login</a></p>
          </div>
        </form>
    </div>
</body>
</html>
