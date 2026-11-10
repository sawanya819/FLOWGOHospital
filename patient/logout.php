<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
unset($_SESSION['patient']);
session_regenerate_id(true);
redirect('patient/login.php');
