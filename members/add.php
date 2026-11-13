
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Super Admins and Admins can register members.
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['super_admin', 'admin'], true)) {
    header("Location: ../auth/login.php");
    exit;
}

// Confirm that the logged-in account is still active.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser || !$currentUser['is_active'] ||
    !in_array($currentUser['role'], ['super_admin', 'admin'], true)) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$error = '';
$success = '';

if (empty($_SESSION['member_csrf_token'])) {
    $_SESSION['member_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['member_csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $email === '' || $password === '') {
            $error = "Please fill in all required fields.";
        } elseif (strlen($fullName) > 120) {
            $error = "Full name is too long.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 12) {
            $error = "Password must contain at least 12 characters.";
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO users (full_name, email, password_hash, role, is_active)
                     VALUES (?, ?, ?, 'member', 1)"
                );

                $stmt->execute([$fullName, $email, $passwordHash]);

                $memberId = (int) $pdo->lastInsertId();

                $audit = $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, details)
                     VALUES (?, ?, ?)"
                );
                $audit->execute([
                    $_SESSION['user_id'],
                    'MEMBER_CREATED',
                    "Registered member ID: " . $memberId
                ]);

                $success = "Member registered successfully.";
                $_SESSION['member_csrf_token'] = bin2hex(random_bytes(32));

            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "That email address is already registered.";
                } else {
                    error_log("Member registration failed: " . $e->getMessage());
                    $error = "Unable to register member. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Member - Mobilis Library</title>
</head>
<body>

    <h1>Register a Library Member</h1>

    <p>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?>.</p>

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

    <form method="POST" action="">
        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($_SESSION['member_csrf_token']); ?>"
        >

        <label for="full_name">Full Name:</label><br>
        <input type="text" id="full_name" name="full_name" maxlength="120" required>
        <br><br>

        <label for="email">Email Address:</label><br>
        <input type="email" id="email" name="email" maxlength="150" required>
        <br><br>

        <label for="password">Temporary Password (minimum 12 characters):</label><br>
        <input type="password" id="password" name="password" minlength="12" required>
        <br><br>

        <button type="submit">Register Member</button>
    </form>

    <p><a href="index.php">View Members</a></p>
    <p><a href="../borrowings/issue.php">Issue a Book</a></p>
    <p><a href="../admin/dashboard.php">Admin Dashboard</a></p>

</body>
</html>
