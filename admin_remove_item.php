<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$id = intval($_GET['id'] ?? 0);
if($id>0){
  // เนื่องจาก FK ON DELETE CASCADE แล้ว ลบ parent ก็ลบ child
  $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id=?");
  $stmt->execute([$id]);
}
header("Location: admin_home.php"); exit;
