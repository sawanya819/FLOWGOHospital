<?php
require __DIR__ . '/inc/bootstrap.php';
unset($_SESSION['user']);
session_regenerate_id(true);
redirect('login.php');
