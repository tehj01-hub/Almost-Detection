<?php
try {
    $c = new mysqli('127.0.0.1', 'root', '', 'security_system', 3307);
    echo "127.0.0.1:3307 SUCCESS\n";
} catch (Exception $e) {
    echo "127.0.0.1:3307 FAIL: " . $e->getMessage() . "\n";
}

try {
    $c = new mysqli('localhost:3307', 'root', '', 'security_system');
    echo "localhost:3307 SUCCESS\n";
} catch (Exception $e) {
    echo "localhost:3307 FAIL: " . $e->getMessage() . "\n";
}

