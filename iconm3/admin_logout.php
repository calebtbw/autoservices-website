<?php
session_start();
session_unset();
session_destroy();
setcookie('admin_logged_in', '', time() - 3600, '/', '', true, true);
header('Location: admin_login.php');
exit;
?>