
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Super Admins and Admins can access overdue records.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// Recheck the logged-in account.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    !$currentUser['is_active'] ||
    !in_array($currentUser['role'], ['super_admin', 'admin'], true)
) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Automatically identify overdue books.
$stmt = $pdo->prepare(
    "UPDATE borrowings
     SET status = 'overdue'
     WHERE status = 'borrowed'
     AND due_date < CURDATE()"
);
$stmt->execute();

$updatedCount = $stmt->rowCount();

// Record the automatic overdue status update in the audit log.
if ($updatedCount > 0) {
    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, details)
         VALUES (?, ?, ?)"
    );

    $audit->execute([
        $_SESSION['user_id'],
        'OVERDUE_STATUS_UPDATED',
        $updatedCount . ' borrowing record(s) marked as overdue.'
    ]);
}

// Retrieve all overdue borrowing records.
$stmt = $pdo->query(
    "SELECT
        br.id AS borrowing_id,
        u.full_name,
        u.email,
        b.title,
        br.issue_date,
        br.due_date,
        DATEDIFF(CURDATE(), br.due_date) AS days_overdue
     FROM borrowings br
     INNER JOIN users u ON br.member_id = u.id
     INNER JOIN books b ON br.book_id = b.id
     WHERE br.status = 'overdue'
     ORDER BY br.due_date ASC"
);

$overdueRecords = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overdue Books - Mobilis Library</title>
</head>
<body>

    <h1>Overdue Books Management</h1>

    <p>
        Welcome,
        <?php echo htmlspecialchars($currentUser['full_name']); ?>.
    </p>

    <p>
        Your role:
        <?php echo htmlspecialchars($currentUser['role']); ?>
    </p>

    <p>
        <a href="../admin/dashboard.php">Admin Dashboard</a> |
        <a href="index.php">Borrowing Records</a> |
        <a href="return.php">Return a Book</a>
    </p>

    <h2>Overdue Borrowing Records</h2>

    <p>
        Total overdue books:
        <strong><?php echo count($overdueRecords); ?></strong>
    </p>

    <?php if (count($overdueRecords) > 0): ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>Borrowing ID</th>
                    <th>Member Name</th>
                    <th>Member Email</th>
                    <th>Book Title</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th>Days Overdue</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($overdueRecords as $record): ?>
                    <tr>
                        <td>
                            <?php echo (int)$record['borrowing_id']; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($record['full_name']); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($record['email']); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($record['title']); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($record['issue_date']); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($record['due_date']); ?>
                        </td>

                        <td>
                            <?php echo (int)$record['days_overdue']; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars('Overdue'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No overdue books found.</p>

    <?php endif; ?>

    <p>
        <a href="return.php">Process Book Returns</a>
    </p>

</body>
</html>
