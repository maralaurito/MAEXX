<?php
require 'auth.php';

set_flash('You have been logged out successfully.', 'success');

session_unset();
session_destroy();

header('Location: login.php');
exit;
