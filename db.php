<?php
$host = "localhost";
$dbname = "budget_dtn";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>

<?php
// ตั้งค่าพื้นฐาน
date_default_timezone_set('Asia/Bangkok');

// ฟังก์ชันแปลงวันที่ไทย
function thai_date($date) {
    if(empty($date)) return '';
    $thai_months = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $date = new DateTime($date);
    return $date->format('j').' '.$thai_months[(int)$date->format('n')].' '.($date->format('Y')+543);
}
?>