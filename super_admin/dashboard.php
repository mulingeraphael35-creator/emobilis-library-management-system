
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Only authenticated Super Admins may access this page.
if (!isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

// Confirm that the account still exists and is active.
$stmt = $pdo->prepare(
    "SELECT full_name, email, role, is_active
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'super_admin' || !(int)$user['is_active']) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Get system statistics.
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
)->fetchColumn();
$totalMembers = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'member'"
)->fetchColumn();
$totalBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$totalBorrowings = (int)$pdo->query(
    "SELECT COUNT(*) FROM borrowings"
)->fetchColumn();
$totalReservations = (int)$pdo->query(
    "SELECT COUNT(*) FROM reservations"
)->fetchColumn();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | eMobilis Library</title>

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

        header h1 {
            margin: 0;
            font-size: 23px;
        }

        nav {
            background: white;
            padding: 15px 30px;
            border-bottom: 1px solid #ddd;
        }

        nav a {
            margin-right: 20px;
            color: #172554;
            text-decoration: none;
            font-weight: bold;
        }

        main {
            padding: 30px;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 18px;
        }

        .stat {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .stat h3 {
            margin: 0 0 12px;
            color: #64748b;
            font-size: 15px;
        }

        .stat p {
            margin: 0;
            font-size: 30px;
            font-weight: bold;
            color: #172554;
        }

        .notice {
            margin-top: 25px;
            padding: 18px;
            background: white;
            border-radius: 10px;
        }

        .logout {
            color: #b91c1c;
        }
    </style>
</head>

<body>

<header>
    <h1>eMobilis Library Management System</h1>
    <p>Super Admin Control Panel</p>
</header>

<nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="manage_admins.php">Manage Administrators</a>
    <a href="../books/index.php">Books</a>
    <a href="../borrowings/index.php">Borrowings</a>
    <a class="logout" href="../auth/logout.php">Logout</a>
</nav>

<main>

    <div class="welcome">
        <h2>Welcome, <?php echo h($user['full_name']); ?>!</h2>
        <p>You are logged in as the Super Administrator.</p>
    </div>

    <h2>System Overview</h2>

    <div class="stats">

        <div class="stat">
            <h3>Total Users</h3>
            <p><?php echo $totalUsers; ?></p>
        </div>

        <div class="stat">
            <h3>Administrators</h3>
            <p><?php echo $totalAdmins; ?></p>
        </div>

        <div class="stat">
            <h3>Members</h3>
            <p><?php echo $totalMembers; ?></p>
        </div>

        <div class="stat">
            <h3>Books</h3>
            <p><?php echo $totalBooks; ?></p>
        </div>

        <div class="stat">
            <h3>Borrowing Records</h3>
            <p><?php echo $totalBorrowings; ?></p>
        </div>

        <div class="stat">
            <h3>Reservations</h3>
            <p><?php echo $totalReservations; ?></p>
        </div>

    </div>

    <div class="notice">
        <h3>Administrator Control</h3>
        <p>
            Only the Super Admin can create, suspend, reactivate
            or remove administrator accounts.
        </p>
        <p>
            <a href="manage_admins.php">Manage Administrators</a>
        </p>
    </div>

</main>

</body>
</html>
