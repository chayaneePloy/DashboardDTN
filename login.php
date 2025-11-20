<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
        $_SESSION['user'] = $user['username'];
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "❌ Username หรือ Password ไม่ถูกต้อง";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Login</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { display:flex; justify-content:center; align-items:center; height:100vh; background:#f0f2f5;}
.login-box { width:400px; padding:30px; background:#fff; border-radius:12px; box-shadow:0 5px 15px rgba(0,0,0,0.1);}
.error { color:red; text-align:center; }
</style>
</head>
<body>
<div class="login-box">
 <h2 class="text-center">Dashboard งบประมาณโครงการ IT </h2>   
<h2 class="text-center mb-4">🔐 Login </h2>
<?php if (!empty($error)) echo "<p class='error'>$error</p>"; ?>
<form method="post">
<input type="text" name="username" class="form-control mb-3" placeholder="Username" required>
<input type="password" name="password" class="form-control mb-3" placeholder="Password" required>
<button type="submit" class="btn btn-primary w-100">Sign in</button>
<p class="mt-3 text-center"><a href="register.php">Register</a></p>
</form>
</div>
</body>
</html>
