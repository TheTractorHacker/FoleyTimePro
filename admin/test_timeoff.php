<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }
http_response_code(404);
exit;
