<?php
require_once __DIR__ . '/../session_helper.php';
require_once __DIR__ . '/../system_config.php';
secsysStartRoleSession('Security Guard');

$_SESSION['username'] = 'guard1';
$_SESSION['role'] = 'Security Guard';
$_SESSION['guard_id'] = 'G001';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'save_visitor_info';
$_POST['plate_number'] = 'VIS1234';
$_POST['vehicle_type'] = 'Car';
$_POST['purpose'] = 'Visiting Resident - 12 Hours';

// Capture output
ob_start();
$t0 = microtime(true);
require __DIR__ . '/../guard_dashboard.php';
$output = ob_get_clean();
$elapsed = (microtime(true) - $t0) * 1000;

echo "Saved Visitor Response:\n" . $output . "\n";
echo "Total Execution Time: " . round($elapsed, 2) . " ms\n";

