<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'dashboard.php' : (is_manager() ? 'equipment.php' : 'tasks.php'));
}

redirect('login.php');
