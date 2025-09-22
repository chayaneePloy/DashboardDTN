<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if(isset($_GET['id_detail'])){
    $id_detail = intval($_GET['id_detail']);
    
    // ลบ detail ทีละรายการ
    $stmt = $pdo->prepare("DELETE FROM budget_detail WHERE id_detail = ?");
    $stmt->execute([$id_detail]);

    // กลับไปยังหน้าที่มาจาก GET['redirect'] หรือ dashboard
    $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';
    header("Location: $redirect");
    exit;
}
?>
