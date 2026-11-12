
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Record the logout before destroying the session.
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, details, ip_address)
             VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $_SESSION['user_id'],
            'LOGOUT',
            'User logged out of the system',
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

    } catch (PDOException $e) {
        error_log("Logout audit error: " . $e->getMessage());
    }
}

// Clear session data.
$_SESSION = [];

// Remove the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session.
session_destroy();

// Return to login.
header("Location: login.php");
exit;

?>
