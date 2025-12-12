<?php
// keep_alive.php
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();
echo "OK";
?>
