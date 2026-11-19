<?php
require 'includes/auth.php';
require 'includes/db.php';

if (is_logged_in()) {
    header('Location: pages/home.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $type     = $_POST['type'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Invalid request. Reload the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === ''
              || !in_array($type, ['staff', 'parent'], true)) {
        $error = 'Enter a valid email and password.';
    } else {
        if ($type === 'staff') {
            $sql = 'SELECT staff_id AS id, name, password_hash, role FROM staff WHERE email = ?';
        } else {
            $sql = "SELECT parent_id AS id, name, password_hash, 'parent' AS role FROM parent WHERE email = ?";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['password_hash']
            && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role']      = $user['role'];
            header('Location: pages/home.php');
            exit;
        }
        $error = 'Incorrect email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Childcare Management System - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header><h1>Childcare Management System</h1></header>
<main>
    <h2>Log in</h2>
    <?php if ($error !== ''): ?>
        <p style="color:#b00020;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" action="index.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <p>
            <label>I am a
                <select name="type">
                    <option value="staff">Staff / Admin</option>
                    <option value="parent">Parent</option>
                </select>
            </label>
        </p>
        <p><label>Email <input type="email" name="email" required></label></p>
        <p><label>Password <input type="password" name="password" required></label></p>
        <button type="submit">Log in</button>
    </form>
</main>
</body>
</html>