
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Redirect users who are already logged in.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'super_admin':
            header("Location: ../super_admin/dashboard.php");
            exit;

        case 'admin':
            header("Location: ../admin/dashboard.php");
            exit;

        case 'member':
            header("Location: ../member/dashboard.php");
            exit;
    }
}

// Create a CSRF token.
if (empty($_SESSION['login_token'])) {
    $_SESSION['login_token'] = bin2hex(random_bytes(32));
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['login_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid request. Please refresh the page.";

    } else {
        $email = trim($_POST['email'] ?? "");
        $password = $_POST['password'] ?? "";

        if ($email === "" || $password === "") {
            $error = "Please enter your email and password.";

        } else {
            $stmt = $pdo->prepare(
                "SELECT id, full_name, email, password_hash, role, is_active
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (
                $user &&
                password_verify($password, $user['password_hash'])
            ) {
                if (!(int) $user['is_active']) {
                    $error = "Your account is inactive. Contact the Super Admin.";

                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    // Regenerate the login form token.
                    unset($_SESSION['login_token']);

                    // Record successful login.
                    $log = $pdo->prepare(
                        "INSERT INTO audit_logs (user_id, action, details, ip_address)
                         VALUES (?, ?, ?, ?)"
                    );

                    $log->execute([
                        $user['id'],
                        'LOGIN',
                        'User logged into the system',
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);

                    // Redirect according to the user's role.
                    switch ($user['role']) {
                        case 'super_admin':
                            header("Location: ../super_admin/dashboard.php");
                            exit;

                        case 'admin':
                            header("Location: ../admin/dashboard.php");
                            exit;

                        case 'member':
                            header("Location: ../member/dashboard.php");
                            exit;

                        default:
                            session_unset();
                            session_destroy();
                            $error = "Invalid account role.";
                    }
                }

            } else {
                $error = "Invalid email or password.";
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
    <title>Login | eMobilis Library</title>
</head>
<body>

    <h1>eMobilis Library Management System</h1>
    <h2>Account Login</h2>

    <?php if ($error !== ""): ?>
        <p style="color: red;">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($_SESSION['login_token'], ENT_QUOTES, 'UTF-8'); ?>"
        >

        <label>Email Address</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Login</button>

    </form>

</body>
</html>
