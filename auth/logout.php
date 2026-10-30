<?php
require_once __DIR__ . '/../includes/bootstrap.php';
logout_user();
session_boot();
flash('info', 'You have been signed out.');
redirect('auth/login.php');
