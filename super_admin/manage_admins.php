
<?php
session_start();

require_once __DIR__ . "/../config/db.php";

// Only the Super Admin can access this page.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'super_admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

// Verify that the Super Admin account is still active.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users WHERE id = ? LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    $currentUser['role'] !== 'super_admin' ||
    !(int)$currentUser['is_active']
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Create CSRF token.
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "success";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function recordAction($pdo, $userId, $action, $details) {
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs
         (user_id, action, details, ip_address)
         VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
        $userId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

// Process administrator actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])
    ) {
        $message = "Invalid request. Refresh the page and try again.";
        $messageType = "error";

    } else {

        $action = $_POST['action'] ?? '';

        try {

            // CREATE ADMINISTRATOR
            if ($action === 'create') {

                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';

                if (
                    $fullName === '' ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new Exception("Enter a valid name and email address.");
                }

                if (strlen($password) < 12) {
                    throw new Exception(
                        "The password must contain at least 12 characters."
                    );
                }

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    "INSERT INTO users
                     (full_name, email, password_hash, role, is_active)
                     VALUES (?, ?, ?, 'admin', 1)"
                );

                $stmt->execute([
                    $fullName,
                    $email,
                    $passwordHash
                ]);

                recordAction(
                    $pdo,
                    $_SESSION['user_id'],
                    'ADMIN_CREATED',
                    "Created administrator account: " . $email
                );

                $message = "Administrator created successfully.";
            }

            // SUSPEND OR REACTIVATE ADMINISTRATOR
            elseif ($action === 'toggle_status') {

                $adminId = filter_var(
                    $_POST['admin_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$adminId || $adminId === (int)$_SESSION['user_id']) {
                    throw new Exception("Invalid administrator selected.");
                }

                $stmt = $pdo->prepare(
                    "SELECT id, email, role, is_active
                     FROM users WHERE id = ? LIMIT 1"
                );
                $stmt->execute([$adminId]);
                $target = $stmt->fetch();

                if (!$target || $target['role'] !== 'admin') {
                    throw new Exception(
                        "Only administrator accounts can be changed here."
                    );
                }

                $newStatus = (int)$target['is_active'] === 1 ? 0 : 1;

                $stmt = $pdo->prepare(
                    "UPDATE users SET is_active = ? WHERE id = ? AND role = 'admin'"
                );
                $stmt->execute([$newStatus, $adminId]);

                recordAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $newStatus ? 'ADMIN_REACTIVATED' : 'ADMIN_SUSPENDED',
                    "Administrator account: " . $target['email']
                );

                $message = $newStatus
                    ? "Administrator reactivated successfully."
                    : "Administrator suspended successfully.";
            }

            // REMOVE ADMINISTRATOR
            elseif ($action === 'delete') {

                $adminId = filter_var(
                    $_POST['admin_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$adminId) {
                    throw new Exception("Invalid administrator selected.");
                }

                $stmt = $pdo->prepare(
                    "SELECT id, email, role
                     FROM users WHERE id = ? LIMIT 1"
                );
                $stmt->execute([$adminId]);
                $target = $stmt->fetch();

                if (!$target || $target['role'] !== 'admin') {
                    throw new Exception(
                        "Only administrator accounts can be removed here."
                    );
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM users WHERE id = ? AND role = 'admin'"
                );
                $stmt->execute([$adminId]);

                recordAction(
                    $pdo,
                    $_SESSION['user_id'],
                    'ADMIN_REMOVED',
                    "Removed administrator account: " . $target['email']
                );

                $message = "Administrator removed successfully.";
            }

            else {
                throw new Exception("Unknown action.");
            }

            // Rotate token after successful action.
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = "error";

        } catch (PDOException $e) {
            error_log("Administrator management error: " . $e->getMessage());

            $message = "The operation could not be completed. The email may already exist, or the account may be linked to other records.";
            $messageType = "error";
        }
    }
}

// Fetch administrator accounts.
$stmt = $pdo->query(
    "SELECT id, full_name, email, is_active, date_created
     FROM users
     WHERE role = 'admin'
     ORDER BY date_created DESC"
);
$admins = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Administrators | eMobilis Library</title>

<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f5f9;
    color: #263238;
}

header {
    background: #172554;
    color: white;
    padding: 22px 30px;
}

nav {
    background: white;
    padding: 15px 30px;
}

nav a {
    margin-right: 20px;
    text-decoration: none;
    color: #172554;
    font-weight: bold;
}

main {
    padding: 30px;
}

.panel {
    background: white;
    padding: 22px;
    border-radius: 10px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

input {
    display: block;
    width: 100%;
    max-width: 450px;
    padding: 11px;
    margin: 8px 0 16px;
    box-sizing: border-box;
}

button {
    padding: 9px 14px;
    border: 0;
    border-radius: 5px;
    cursor: pointer;
    margin: 3px;
}

.create-btn {
    background: #172554;
    color: white;
}

.suspend-btn {
    background: #f59e0b;
    color: white;
}

.delete-btn {
    background: #dc2626;
    color: white;
}

.success {
    color: #166534;
    background: #dcfce7;
    padding: 12px;
}

.error {
    color: #991b1b;
    background: #fee2e2;
    padding: 12px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.status-active {
    color: #15803d;
    font-weight: bold;
}

.status-suspended {
    color: #dc2626;
    font-weight: bold;
}

@media(max-width: 700px) {
    main { padding: 12px; }
    .panel { overflow-x: auto; }
    table { min-width: 650px; }
}
</style>
</head>

<body>

<header>
    <h1>eMobilis Library Management System</h1>
    <p>Super Admin – Administrator Management</p>
</header>

<nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="manage_admins.php">Manage Administrators</a>
    <a href="../auth/logout.php">Logout</a>
</nav>

<main>

<h2>Manage Administrators</h2>

<p>
    Welcome, <?php echo h($currentUser['full_name']); ?>.
    Only you can manage administrator accounts.
</p>

<?php if ($message !== ''): ?>
    <p class="<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
        <?php echo h($message); ?>
    </p>
<?php endif; ?>

<section class="panel">

    <h3>Create New Administrator</h3>

    <form method="POST">

        <input type="hidden" name="csrf_token"
            value="<?php echo h($_SESSION['admin_csrf']); ?>">

        <input type="hidden" name="action" value="create">

        <label>Full Name</label>
        <input type="text" name="full_name" maxlength="120" required>

        <label>Email Address</label>
        <input type="email" name="email" maxlength="150" required>

        <label>Temporary Password (minimum 12 characters)</label>
        <input type="password" name="password" minlength="12" required>

        <button class="create-btn" type="submit">
            Create Administrator
        </button>

    </form>

</section>

<section class="panel">

    <h3>Existing Administrators</h3>

    <?php if (count($admins) === 0): ?>

        <p>No administrators have been created yet.</p>

    <?php else: ?>

    <table>

        <thead>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Date Created</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($admins as $admin): ?>

            <tr>
                <td><?php echo h($admin['id']); ?></td>
                <td><?php echo h($admin['full_name']); ?></td>
                <td><?php echo h($admin['email']); ?></td>

                <td>
                    <?php if ((int)$admin['is_active'] === 1): ?>
                        <span class="status-active">Active</span>
                    <?php else: ?>
                        <span class="status-suspended">Suspended</span>
                    <?php endif; ?>
                </td>

                <td><?php echo h($admin['date_created']); ?></td>

                <td>

                    <form method="POST" style="display:inline;">

                        <input type="hidden" name="csrf_token"
                            value="<?php echo h($_SESSION['admin_csrf']); ?>">

                        <input type="hidden" name="admin_id"
                            value="<?php echo h($admin['id']); ?>">

                        <input type="hidden" name="action"
                            value="toggle_status">

                        <button class="suspend-btn" type="submit">
                            <?php echo (int)$admin['is_active'] === 1
                                ? 'Suspend'
                                : 'Reactivate'; ?>
                        </button>

                    </form>

                    <form method="POST" style="display:inline;"
                        onsubmit="return confirm('Are you sure you want to remove this administrator?');">

                        <input type="hidden" name="csrf_token"
                            value="<?php echo h($_SESSION['admin_csrf']); ?>">

                        <input type="hidden" name="admin_id"
                            value="<?php echo h($admin['id']); ?>">

                        <input type="hidden" name="action"
                            value="delete">

                        <button class="delete-btn" type="submit">
                            Remove
                        </button>

                    </form>

                </td>
            </tr>

        <?php endforeach; ?>

        </tbody>
    </table>

    <?php endif; ?>

</section>

</main>

</body>
</html>
