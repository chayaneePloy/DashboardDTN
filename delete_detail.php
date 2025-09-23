<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if(!isset($_GET['id_detail'])){ header("Location: dashboard.php"); exit; }

$id_detail = intval($_GET['id_detail']);
$redirect = $_GET['redirect'] ?? 'dashboard.php';

// ลบ detail
$stmt = $pdo->prepare("DELETE FROM budget_detail WHERE id_detail = ?");
$stmt->execute([$id_detail]);

header("Location: " . $redirect);
exit;
