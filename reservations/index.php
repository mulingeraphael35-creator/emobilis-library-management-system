
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only administrators can view reservations.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Verify the current account.
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

// Retrieve all reservations.
$stmt = $pdo->query(
    "SELECT
        r.id,
        r.reservation_date,
        r.status,
        u.full_name AS member_name,
        b.title AS book_title,
        b.author AS book_author
     FROM reservations r
     INNER JOIN users u ON r.member_id = u.id
     INNER JOIN books b ON r.book_id = b.id
     ORDER BY
        CASE
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'fulfilled' THEN 2
            ELSE 3
        END,
        r.reservation_date DESC"
);

$reservations = $stmt->fetchAll();

// Count reservations by status.
$pendingCount = 0;
$fulfilledCount = 0;
$cancelledCount = 0;

foreach ($reservations as $reservation) {
    if ($reservation['status'] === 'pending') {
        $pendingCount++;
    } elseif ($reservation['status'] === 'fulfilled') {
        $fulfilledCount++;
    } elseif ($reservation['status'] === 'cancelled') {
        $cancelledCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reservation Management | Mobilis Library</title>
</head>
<body>

<h1>Mobilis Library Management System</h1>

<h2>Reservation Management</h2>

<p>
    Welcome,
    <?php echo htmlspecialchars($currentUser['full_name']); ?>.
</p>

<p>
    Your role:
    <?php echo htmlspecialchars($currentUser['role']); ?>
</p>

<p>
    <a href="../admin/dashboard.php">Admin Dashboard</a>
</p>

<h3>Reservation Summary</h3>

<p>Pending Reservations: <?php echo $pendingCount; ?></p>
<p>Fulfilled Reservations: <?php echo $fulfilledCount; ?></p>
<p>Cancelled Reservations: <?php echo $cancelledCount; ?></p>

<h3>All Reservations</h3>

<?php if (count($reservations) > 0): ?>

<table border="1" cellpadding="10" cellspacing="0">

    <thead>
        <tr>
            <th>Reservation ID</th>
            <th>Member</th>
            <th>Book</th>
            <th>Author</th>
            <th>Reservation Date</th>
            <th>Status</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($reservations as $reservation): ?>

        <tr>
            <td>
                <?php echo (int)$reservation['id']; ?>
            </td>

            <td>
                <?php echo htmlspecialchars($reservation['member_name']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($reservation['book_title']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($reservation['book_author']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($reservation['reservation_date']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($reservation['status']); ?>
            </td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<?php else: ?>

    <p>No reservations have been submitted yet.</p>

<?php endif; ?>

</body>
</html>
