<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_post_csrf();
$_SESSION = [];
session_destroy();
redirect('login.php');
