
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Super Admins and Admins can view the member list.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// Recheck the user's current account status and role.
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

// Retrieve all member accounts.
$stmt = $pdo->query(
    "SELECT id, full_name, email, is_active, date_created
     FROM users
     WHERE role = 'member'
     ORDER BY date_created DESC"
);
$members = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Members</title>
</head>
<body>

    <h1>Library Members</h1>

    <p>
        Welcome,
        <?php echo htmlspecialchars($currentUser['full_name']); ?>.
    </p>

    <p>
        <a href="add.php">Register New Member</a> |
        <a href="../admin/dashboard.php">Admin Dashboard</a>
    </p>

    <h2>Registered Members</h2>

    <?php if (count($members) > 0): ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>Member ID</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Account Status</th>
                    <th>Date Registered</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?php echo (int) $member['id']; ?></td>
                        <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($member['email']); ?></td>
                        <td>
                            <?php echo $member['is_active'] ? 'Active' : 'Suspended'; ?>
                        </td>
                        <td><?php echo htmlspecialchars($member['date_created']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No members have been registered yet.</p>

    <?php endif; ?>

</body>
</html>
