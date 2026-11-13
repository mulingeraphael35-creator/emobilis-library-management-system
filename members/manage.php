
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Super Admins and Admins can manage members.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// Revalidate the current account.
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

$error = '';
$success = '';

// Generate CSRF token.
if (empty($_SESSION['manage_member_token'])) {
    $_SESSION['manage_member_token'] = bin2hex(random_bytes(32));
}

// Process suspend/reactivate request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['manage_member_token'], $token)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif (!$memberId || $memberId < 1) {
        $error = "Invalid member selected.";
    } elseif (!in_array($action, ['suspend', 'reactivate'], true)) {
        $error = "Invalid action selected.";
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the selected member record.
            $stmt = $pdo->prepare(
                "SELECT id, full_name, is_active
                 FROM users
                 WHERE id = ? AND role = 'member'
                 FOR UPDATE"
            );
            $stmt->execute([$memberId]);
            $member = $stmt->fetch();

            if (!$member) {
                throw new RuntimeException("Member account not found.");
            }

            $newStatus = ($action === 'reactivate') ? 1 : 0;

            if ((int)$member['is_active'] === $newStatus) {
                throw new RuntimeException(
                    "The member account already has the requested status."
                );
            }

            // Update the member account.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET is_active = ?
                 WHERE id = ? AND role = 'member'"
            );
            $stmt->execute([$newStatus, $memberId]);

            // Record the action in the audit log.
            $auditAction = ($action === 'suspend')
                ? 'MEMBER_SUSPENDED'
                : 'MEMBER_REACTIVATED';

            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, details)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                $auditAction,
                $action === 'suspend'
                    ? "Suspended member ID: " . $memberId
                    : "Reactivated member ID: " . $memberId
            ]);

            $pdo->commit();

            $success = ($action === 'suspend')
                ? "Member account suspended successfully."
                : "Member account reactivated successfully.";

            $_SESSION['manage_member_token'] = bin2hex(random_bytes(32));

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log("Member management failed: " . $e->getMessage());
                $error = "Unable to update the member account. Please try again.";
            }
        }
    }
}

// Retrieve all registered members.
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
    <title>Manage Members - Mobilis Library</title>
</head>
<body>

    <h1>Member Management</h1>

    <p>
        Welcome,
        <?php echo htmlspecialchars($currentUser['full_name']); ?>.
    </p>

    <p>
        Your role:
        <?php echo htmlspecialchars($currentUser['role']); ?>
    </p>

    <p>
        <a href="index.php">View Members</a> |
        <a href="add.php">Register Member</a> |
        <a href="../admin/dashboard.php">Admin Dashboard</a>
    </p>

    <?php if ($error !== ''): ?>
        <p style="color: red;">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p style="color: green;">
            <?php echo htmlspecialchars($success); ?>
        </p>
    <?php endif; ?>

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
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?php echo (int)$member['id']; ?></td>

                        <td>
                            <?php echo htmlspecialchars($member['full_name']); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($member['email']); ?>
                        </td>

                        <td>
                            <?php echo $member['is_active'] ? 'Active' : 'Suspended'; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($member['date_created']); ?>
                        </td>

                        <td>
                            <form method="POST" action="">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?php echo htmlspecialchars($_SESSION['manage_member_token']); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="member_id"
                                    value="<?php echo (int)$member['id']; ?>"
                                >

                                <?php if ($member['is_active']): ?>
                                    <button
                                        type="submit"
                                        name="action"
                                        value="suspend"
                                    >
                                        Suspend
                                    </button>
                                <?php else: ?>
                                    <button
                                        type="submit"
                                        name="action"
                                        value="reactivate"
                                    >
                                        Reactivate
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>No registered members found.</p>

    <?php endif; ?>

</body>
</html>
