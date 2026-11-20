<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../includes/auth.php';
require '../includes/db.php';
require_role(['admin', 'staff', 'parent']);

date_default_timezone_set('America/New_York');
$today  = date('Y-m-d');
$now    = date('H:i:s');
$role   = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

echo 'DEBUG role=' . htmlspecialchars($role) . ' id=' . $userId;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error   = '';

// Staff are limited to their own homeroom; admin sees all
$staffRoom = null;
if ($role === 'staff') {
    $stmt = $pdo->prepare('SELECT homeroom_id FROM staff WHERE staff_id = ?');
    $stmt->execute([$userId]);
    $staffRoom = $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $childId = filter_var($_POST['child_id'] ?? '', FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Invalid request. Reload the page and try again.';
    } elseif ($childId === false) {
        $error = 'Invalid child.';
    } else {
        try {
            if ($role === 'parent' && in_array($action, ['sign_in', 'sign_out'], true)) {

                // Is this child linked to this parent?
                $own = $pdo->prepare('SELECT 1 FROM parent_child WHERE parent_id = ? AND child_id = ?');
                $own->execute([$userId, $childId]);

                if (!$own->fetchColumn()) {
                    $error = 'You can only sign in or out your own children.';
                } elseif ($action === 'sign_in') {
                    $row = $pdo->prepare('SELECT attendance_id, time_in FROM attendance WHERE child_id = ? AND `date` = ?');
                    $row->execute([$childId, $today]);
                    $existing = $row->fetch();

                    if ($existing && $existing['time_in'] !== null) {
                        $error = 'This child is already signed in today.';
                    } elseif ($existing) {
                        $upd = $pdo->prepare("UPDATE attendance SET record = 'Present', time_in = ? WHERE attendance_id = ?");
                        $upd->execute([$now, $existing['attendance_id']]);
                        $message = 'Signed in at ' . $now . '.';
                    } else {
                        $ins = $pdo->prepare("INSERT INTO attendance (`date`, child_id, record, time_in) VALUES (?, ?, 'Present', ?)");
                        $ins->execute([$today, $childId, $now]);
                        $message = 'Signed in at ' . $now . '.';
                    }
                } else { // sign_out
                    $upd = $pdo->prepare('UPDATE attendance SET time_out = ?
                                          WHERE child_id = ? AND `date` = ?
                                          AND time_in IS NOT NULL AND time_out IS NULL');
                    $upd->execute([$now, $childId, $today]);
                    if ($upd->rowCount() === 0) {
                        $error = 'This child is not signed in, or is already signed out.';
                    } else {
                        $message = 'Signed out at ' . $now . '.';
                    }
                }

            } elseif (in_array($role, ['admin', 'staff'], true) && $action === 'mark') {
                $record = $_POST['record'] ?? '';
                if (!in_array($record, ['Present', 'Absent'], true)) {
                    $error = 'Invalid attendance value.';
                } else {
                    // Staff may only mark children enrolled in their own homeroom
                    $allowed = true;
                    if ($role === 'staff') {
                        $chk = $pdo->prepare('SELECT 1 FROM enrollment WHERE child_id = ? AND homeroom_id = ?');
                        $chk->execute([$childId, $staffRoom]);
                        $allowed = (bool)$chk->fetchColumn();
                    }

                    if (!$allowed) {
                        $error = 'That child is not in your homeroom.';
                    } else {
                        $up = $pdo->prepare('INSERT INTO attendance (`date`, child_id, record) VALUES (?, ?, ?)
                                             ON DUPLICATE KEY UPDATE record = ?');
                        $up->execute([$today, $childId, $record, $record]);

                        if ($record === 'Absent') {
                            $clr = $pdo->prepare('UPDATE attendance SET time_in = NULL, time_out = NULL
                                                  WHERE child_id = ? AND `date` = ?');
                            $clr->execute([$childId, $today]);
                        }
                        $message = 'Attendance saved.';
                    }
                }
            } else {
                $error = 'Action not allowed.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Could not save attendance. Please try again.';
        }
    }
}

// Load today's list (after the POST so it is up to date)
if ($role === 'parent') {
    $list = $pdo->prepare(
        'SELECT c.child_id, c.name, a.record, a.time_in, a.time_out
         FROM parent_child pc
         JOIN child c ON c.child_id = pc.child_id
         LEFT JOIN attendance a ON a.child_id = c.child_id AND a.`date` = ?
         WHERE pc.parent_id = ?
         ORDER BY c.name'
    );
    $list->execute([$today, $userId]);
} elseif ($role === 'staff') {
    $list = $pdo->prepare(
        'SELECT c.child_id, c.name, e.homeroom_id, a.record, a.time_in, a.time_out
         FROM enrollment e
         JOIN child c ON c.child_id = e.child_id
         LEFT JOIN attendance a ON a.child_id = c.child_id AND a.`date` = ?
         WHERE e.homeroom_id = ?
         ORDER BY c.name'
    );
    $list->execute([$today, $staffRoom]);
} else { // admin
    $list = $pdo->prepare(
        'SELECT c.child_id, c.name, e.homeroom_id, a.record, a.time_in, a.time_out
         FROM enrollment e
         JOIN child c ON c.child_id = e.child_id
         LEFT JOIN attendance a ON a.child_id = c.child_id AND a.`date` = ?
         ORDER BY e.homeroom_id, c.name'
    );
    $list->execute([$today]);
}
$rows = $list->fetchAll();
echo '<pre>DEBUG role=' . htmlspecialchars($role) . ' id=' . $userId
   . ' rows=' . count($rows) . "\n";
print_r($rows);
echo '</pre>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<header><h1>Attendance for <?= htmlspecialchars($today) ?></h1></header>
<main>
    <p><a href="home.php">Back to home</a></p>

    <?php if ($message !== ''): ?>
        <p style="color:#1b6e2f;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p style="color:#b00020;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <p>No children to show.</p>
    <?php else: ?>
    <table>
        <tr>
            <?php if ($role !== 'parent'): ?><th>Room</th><?php endif; ?>
            <th>Child</th><th>Status</th><th>Signed in</th><th>Signed out</th><th>Action</th>
        </tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($role !== 'parent'): ?>
                <td><?= (int)$r['homeroom_id'] ?></td>
            <?php endif; ?>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['record'] ?? 'Not recorded') ?></td>
            <td><?= htmlspecialchars($r['time_in'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['time_out'] ?? '-') ?></td>
            <td>
                <?php if ($role === 'parent'): ?>
                    <?php if ($r['time_in'] === null): ?>
                        <form method="post" action="attendance.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="child_id" value="<?= (int)$r['child_id'] ?>">
                            <input type="hidden" name="action" value="sign_in">
                            <button type="submit">Sign in</button>
                        </form>
                    <?php elseif ($r['time_out'] === null): ?>
                        <form method="post" action="attendance.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="child_id" value="<?= (int)$r['child_id'] ?>">
                            <input type="hidden" name="action" value="sign_out">
                            <button type="submit">Sign out</button>
                        </form>
                    <?php else: ?>
                        Done for today
                    <?php endif; ?>
                <?php else: ?>
                    <form method="post" action="attendance.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="child_id" value="<?= (int)$r['child_id'] ?>">
                        <input type="hidden" name="action" value="mark">
                        <button type="submit" name="record" value="Present">Present</button>
                        <button type="submit" name="record" value="Absent">Absent</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</main>
</body>
</html>
