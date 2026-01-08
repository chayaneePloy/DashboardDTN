<?php
include 'db.php';

$id   = intval($_GET['id'] ?? 0);
$year = $_GET['year'] ?? '';

if ($id > 0) {
    $stmt = $pdo->prepare("
        UPDATE budget_items
        SET status = 'cancelled'
        WHERE id = ?
    ");
    $stmt->execute([$id]);
}

header("Location: dashboard.php?year=" . urlencode($year));
exit;
