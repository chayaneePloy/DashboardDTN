<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if(isset($_GET['id'])){
    $id = intval($_GET['id']);

    // ลบ budget_item และ budget_detail (เนื่องจาก FK ON DELETE CASCADE)
    $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: dashboard.php");
    exit;
}
?>
