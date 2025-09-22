<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$iid = intval($_GET['issue_id'] ?? 0);
if($iid>0){
  $pdo->prepare("DELETE FROM issues WHERE issue_id=?")->execute([$iid]);
}
header("Location: admin_home.php"); exit;
