<?php
$dirs = [__DIR__, __DIR__ . '/quickstart'];

foreach ($dirs as $dir) {
    $tmp = $dir . '/tmp';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0755, true);
    }
    $file = $tmp . '/restart.txt';
    @touch($file);
    echo "Touched: $file <br>";
}
echo "Python App Restarted successfully! Refresh your app now.";
?>
