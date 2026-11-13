
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only administrators can manage borrowing records.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Verify that the account is still active and has the correct role.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    (int)$currentUser['is_active'] !== 1 ||
    !in_array($currentUser['role'], ['super_admin', 'admin'], true)
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Retrieve borrowing records.
// Retrieve borrowing records, including fine details.
$stmt = $pdo->query(
    "SELECT
        br.id,
        br.issue_date,
        br.due_date,
        br.return_date,
        br.status,
        br.overdue_hours,
        br.fine_amount,
        br.fine_paid,
        u.full_name AS member_name,
        b.title AS book_title
     FROM borrowings br
     INNER JOIN users u ON br.member_id = u.id
     INNER JOIN books b ON br.book_id = b.id
     ORDER BY br.issue_date DESC"
);

$borrowings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Borrowing Management</title>
</head>
<body>

<h1>Mobilis Library Management System</h1>

<h2>Borrowing Records</h2>

<p>
    Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>.
</p>

<p>
    Your role: <?php echo htmlspecialchars($currentUser['role']); ?>
</p>

<p>
    <a href="../admin/dashboard.php">Admin Dashboard</a>
</p>

<h3>All Borrowing Records</h3>

<?php if (count($borrowings) > 0): ?>

<table border="1" cellpadding="10" cellspacing="0">

    <thead>
        <tr>
            <th>Borrowing ID</th>
            <th>Member</th>
            <th>Book</th>
            <th>Issue Date</th>
            <th>Due Date</th>
            <th>Return Date</th>
            <th>Status</th>
            <th>Overdue Hours</th>
            <th>Fine Amount (KSh)</th>
            <th>Fine Paid (KSh)</th>
            <th>Outstanding Balance (KSh)</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($borrowings as $record): ?>

        <tr>
            <td><?php echo (int)$record['id']; ?></td>

            <td>
                <?php echo htmlspecialchars($record['member_name']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($record['book_title']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($record['issue_date']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($record['due_date']); ?>
            </td>

            <td>
                <?php
                echo $record['return_date']
                    ? htmlspecialchars($record['return_date'])
                    : 'Not returned';
                ?>
            </td>

            <td>
    <?php echo htmlspecialchars($record['status']); ?>
</td>

<td>
    <?php echo (int)$record['overdue_hours']; ?>
</td>

<td>
    <?php echo number_format((float)$record['fine_amount'], 2); ?>
</td>

<td>
    <?php echo number_format((float)$record['fine_paid'], 2); ?>
</td>

<td>
    <?php
    $balance = max(
        0,
        (float)$record['fine_amount'] - (float)$record['fine_paid']
    );

    echo number_format($balance, 2);
    ?>
</td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<?php else: ?>

    <p>No borrowing records found.</p>

<?php endif; ?>

</body>
</html>
