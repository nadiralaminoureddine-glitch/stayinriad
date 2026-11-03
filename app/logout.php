<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') { logout(); session_start(); flash('Vous êtes déconnecté.'); }
redirect('login.php');
