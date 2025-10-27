<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$cid = intval($_GET['contract_id'] ?? 0);
if($cid>0){
  // ถ้าไม่ได้ตั้ง FK CASCADE ให้ลบ phases ก่อน
  $pdo->prepare("DELETE FROM phases WHERE contract_detail_id=?")->execute([$cid]);
  $pdo->prepare("DELETE FROM contracts WHERE contract_id=?")->execute([$cid]);
}
header("Location: admin_home.php"); exit;
