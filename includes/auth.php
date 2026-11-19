<?php
session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /childcare-app/index.php');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}