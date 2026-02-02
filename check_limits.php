<?php
header('Content-Type: text/plain');
echo "max_input_vars: " . ini_get('max_input_vars') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "Current PHP version: " . phpversion() . "\n";
?>
