<?php
require '../includes/auth.php';
require '../includes/db.php';
require_role(['admin']);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $age      = filter_var($_POST['age'] ?? '', FILTER_VALIDATE_INT,
                           ['options' => ['min_range' => 0, 'max_range' => 17]]);
    $homeroom = filter_var($_POST['homeroom_id'] ?? '', FILTER_VALIDATE_INT);
    $parent1  = filter_var($_POST['parent1'] ?? '', FILTER_VALIDATE_INT);
    $parent2  = ($_POST['parent2'] ?? '') === ''
                ? null
                : filter_var($_POST['parent2'], FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Invalid request. Reload the page and try again.';
    } elseif ($name === '' || strlen($name) > 100) {
        $error = 'Enter a child name of up to 100 characters.';
    } elseif ($age === false) {
        $error = 'Age must be a whole number from 0 to 17.';
    } elseif ($homeroom === false || $parent1 === false) {
        $error = 'Choose a homeroom and at least one parent.';
    } elseif ($parent2 !== null && ($parent2 === false || $parent2 === $parent1)) {
        $error = 'The second parent must be a different parent.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO child (name, age) VALUES (?, ?)');
            $stmt->execute([$name, $age]);
            $childId = $pdo->lastInsertId();

            $link = $pdo->prepare('INSERT INTO parent_child (parent_id, child_id) VALUES (?, ?)');
            $link->execute([$parent1, $childId]);
            if ($parent2 !== null) {
                $link->execute([$parent2, $childId]);
            }

            $enroll = $pdo->prepare('INSERT INTO enrollment (child_id, homeroom_id) VALUES (?, ?)');
            $enroll->execute([$childId, $homeroom]);

            $pdo->commit();
            $message = 'Child enrolled successfully.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $error = ($e->getCode() === '45000')
                ? $e->errorInfo[2]
                : 'Could not enroll the child. Check the details and try again.';
        }
    }
}


$homerooms = $pdo->query(
    'SELECT h.homeroom_id, h.capacity, COUNT(e.enrollment_id) AS enrolled
     FROM homeroom h
     LEFT JOIN enrollment e ON e.homeroom_id = h.homeroom_id
     GROUP BY h.homeroom_id, h.capacity
     ORDER BY h.homeroom_id'
)->fetchAll();
$parents = $pdo->query('SELECT parent_id, name FROM parent ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enroll a Child</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<header><h1>Enroll a Child</h1></header>
<main>
    <p><a href="home.php">Back to home</a></p>

    <?php if ($message !== ''): ?>
        <p style="color:#1b6e2f;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p style="color:#b00020;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="enroll.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <p><label>Child name <input type="text" name="name" maxlength="100" required></label></p>
        <p><label>Age <input type="number" name="age" min="0" max="17" required></label></p>
        <p>
            <label>Homeroom
                <select name="homeroom_id" required>
                    <?php foreach ($homerooms as $h): ?>
                        <option value="<?= (int)$h['homeroom_id'] ?>">
                            Room <?= (int)$h['homeroom_id'] ?>
                            (<?= (int)$h['enrolled'] ?>/<?= (int)$h['capacity'] ?> enrolled)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Parent 1
                <select name="parent1" required>
                    <?php foreach ($parents as $p): ?>
                        <option value="<?= (int)$p['parent_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Parent 2 (optional)
                <select name="parent2">
                    <option value="">None</option>
                    <?php foreach ($parents as $p): ?>
                        <option value="<?= (int)$p['parent_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <button type="submit">Enroll</button>
    </form>

    <h2>Homeroom capacity</h2>
    <table>
        <tr><th>Room</th><th>Enrolled</th><th>Capacity</th></tr>
        <?php foreach ($homerooms as $h): ?>
            <tr>
                <td><?= (int)$h['homeroom_id'] ?></td>
                <td><?= (int)$h['enrolled'] ?></td>
                <td><?= (int)$h['capacity'] ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</main>
</body>
</html>