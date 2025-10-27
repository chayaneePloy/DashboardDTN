<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$id_detail = intval($_GET['id_detail'] ?? 0);
$redirect  = $_GET['redirect'] ?? 'admin_home.php';

if($id_detail>0){
  $stmt = $pdo->prepare("DELETE FROM budget_detail WHERE id_detail=?");
  $stmt->execute([$id_detail]);
}
header("Location: $redirect"); exit;
