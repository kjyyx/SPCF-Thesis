<?php
require_once '../includes/session.php';
logoutUser();
header('Location: user-login.php');
exit();
?>