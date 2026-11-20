<?php
require '../includes/auth.php';
require '../includes/db.php';
require_role(['admin', 'parent']);

$role   = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Returns the amount as a string like "25.50", or null if it is not valid
function parse_amount(string $value): ?string {
    $value = trim($value);
    if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $value) || (float)$value <= 0) {
        return null;
    }
    return $value;
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Invalid request. Reload the page and try again.';

    } elseif ($role === 'admin' && $action === 'create') {
        $parentId = filter_var($_POST['parent_id'] ?? '', FILTER_VALIDATE_INT);
        $due      = trim($_POST['due_date'] ?? '');
        $amount   = parse_amount($_POST['amount'] ?? '');
        $dueDate  = DateTime::createFromFormat('Y-m-d', $due);

        if ($parentId === false) {
            $error = 'Choose a parent.';
        } elseif (!$dueDate || $dueDate->format('Y-m-d') !== $due) {
            $error = 'Enter a valid due date.';
        } elseif ($amount === null) {
            $error = 'Enter an amount greater than 0, with up to 2 decimals.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO invoices (parent_id, due_date, amount_requested) VALUES (?, ?, ?)');
                $stmt->execute([$parentId, $due, $amount]);
                $message = 'Invoice created.';
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'Could not create the invoice. Check that the parent exists.';
            }
        }

    } elseif ($role === 'parent' && $action === 'pay') {
        $invoiceId = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        $amount    = parse_amount($_POST['amount'] ?? '');

        if ($invoiceId === false) {
            $error = 'Invalid invoice.';
        } elseif ($amount === null) {
            $error = 'Enter a payment greater than 0, with up to 2 decimals.';
        } else {
            try {
                $pdo->beginTransaction();

                // Lock this parent's invoice row so two payments cannot overpay it
                $inv = $pdo->prepare('SELECT amount_requested FROM invoices WHERE invoice_id = ? AND parent_id = ? FOR UPDATE');
                $inv->execute([$invoiceId, $userId]);
                $requested = $inv->fetchColumn();

                if ($requested === false) {
                    $pdo->rollBack();
                    $error = 'Invoice not found.';
                } else {
                    $sum = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE invoice_id = ?');
                    $sum->execute([$invoiceId]);
                    $paid = $sum->fetchColumn();

                    // Compare in cents to avoid decimal rounding problems
                    $balanceCents = (int)round((float)$requested * 100) - (int)round((float)$paid * 100);
                    $payCents     = (int)round((float)$amount * 100);

                    if ($payCents > $balanceCents) {
                        $pdo->rollBack();
                        $error = 'That payment is more than the balance of $'
                               . number_format($balanceCents / 100, 2) . '.';
                    } else {
                        $ins = $pdo->prepare('INSERT INTO payments (invoice_id, parent_id, amount_paid) VALUES (?, ?, ?)');
                        $ins->execute([$invoiceId, $userId, $amount]);
                        $pdo->commit();
                        $message = 'Payment of $' . number_format($payCents / 100, 2) . ' recorded.';
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Could not record the payment. Please try again.';
            }
        }
    } else {
        $error = 'Action not allowed.';
    }
}

// Load the invoice list (after the POST so it is up to date)
$sql = 'SELECT i.invoice_id, p.name AS parent_name, i.due_date, i.amount_requested,
               COALESCE(SUM(pay.amount_paid), 0) AS paid
        FROM invoices i
        JOIN parent p ON p.parent_id = i.parent_id
        LEFT JOIN payments pay ON pay.invoice_id = i.invoice_id';
if ($role === 'parent') {
    $list = $pdo->prepare($sql . ' WHERE i.parent_id = ?
        GROUP BY i.invoice_id, p.name, i.due_date, i.amount_requested
        ORDER BY i.due_date DESC');
    $list->execute([$userId]);
} else {
    $list = $pdo->query($sql . '
        GROUP BY i.invoice_id, p.name, i.due_date, i.amount_requested
        ORDER BY i.due_date DESC');
}
$invoices = $list->fetchAll();

$parents = [];
if ($role === 'admin') {
    $parents = $pdo->query('SELECT parent_id, name FROM parent ORDER BY name')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoices</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<header><h1>Invoices and Payments</h1></header>
<main>
    <p><a href="home.php">Back to home</a></p>

    <?php if ($message !== ''): ?>
        <p style="color:#1b6e2f;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p style="color:#b00020;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
        <h2>Create an invoice</h2>
        <form method="post" action="invoices.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="create">
            <p>
                <label>Parent
                    <select name="parent_id" required>
                        <?php foreach ($parents as $p): ?>
                            <option value="<?= (int)$p['parent_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p><label>Due date <input type="date" name="due_date" required></label></p>
            <p><label>Amount ($) <input type="number" name="amount" min="0.01" step="0.01" required></label></p>
            <button type="submit">Create invoice</button>
        </form>
    <?php endif; ?>

    <h2><?= $role === 'admin' ? 'All invoices' : 'My invoices' ?></h2>
    <?php if (!$invoices): ?>
        <p>No invoices yet.</p>
    <?php else: ?>
    <table>
        <tr>
            <th>#</th>
            <?php if ($role === 'admin'): ?><th>Parent</th><?php endif; ?>
            <th>Due date</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th>
            <?php if ($role === 'parent'): ?><th>Pay</th><?php endif; ?>
        </tr>
        <?php foreach ($invoices as $i):
            $requestedC = (int)round((float)$i['amount_requested'] * 100);
            $paidC      = (int)round((float)$i['paid'] * 100);
            $balanceC   = $requestedC - $paidC;
            $status     = $balanceC <= 0 ? 'Paid' : ($paidC > 0 ? 'Partial' : 'Unpaid');
        ?>
        <tr>
            <td><?= (int)$i['invoice_id'] ?></td>
            <?php if ($role === 'admin'): ?><td><?= htmlspecialchars($i['parent_name']) ?></td><?php endif; ?>
            <td><?= htmlspecialchars($i['due_date']) ?></td>
            <td>$<?= number_format($requestedC / 100, 2) ?></td>
            <td>$<?= number_format($paidC / 100, 2) ?></td>
            <td>$<?= number_format(max($balanceC, 0) / 100, 2) ?></td>
            <td><?= $status ?></td>
            <?php if ($role === 'parent'): ?>
            <td>
                <?php if ($balanceC > 0): ?>
                <form method="post" action="invoices.php">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="invoice_id" value="<?= (int)$i['invoice_id'] ?>">
                    <input type="number" name="amount" min="0.01" step="0.01"
                           max="<?= number_format($balanceC / 100, 2, '.', '') ?>" required>
                    <button type="submit">Pay</button>
                </form>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</main>
</body>
</html>
