<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Welcome</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container text-center mt-5">
        <h1>👋 สวัสดีครับ <?= htmlspecialchars($_SESSION['user']) ?></h1>
        <p>ยินดีต้อนรับเข้าสู่ระบบ</p>
        <a href="logout.php" class="btn btn-danger">Logout</a>
    </div>
</body>
</html>
