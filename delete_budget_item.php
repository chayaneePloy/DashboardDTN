<?php
include 'db.php';
$id = $_GET['id'] ?? null;
if (!$id) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
$stmt->execute([$id]);

header("Location: index.php");
exit;
?>
