<?php
require '../includes/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<header><h1>Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?></h1></header>
<main>
    <p>Role: <?= htmlspecialchars($_SESSION['role']) ?></p>
    <ul>
        <?php if (in_array($_SESSION['role'], ['admin', 'staff'], true)): ?>
            <li><a href="attendance.php">Daily attendance</a></li>
            <li><a href="schedule.php">Staff schedule</a></li>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li><a href="enroll.php">Enroll a child</a></li>
            <li><a href="invoices.php">Invoices</a></li>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'parent'): ?>
            <li><a href="attendance.php">Sign in / out</a></li>
            <li><a href="invoices.php">My invoices</a></li>
        <?php endif; ?>
        <li><a href="../logout.php">Log out</a></li>
    </ul>
</main>
</body>
</html>