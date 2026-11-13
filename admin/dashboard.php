<?php

session_start();

require_once "../config/db.php";

// Only administrators can access this page.
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

// Confirm the administrator account is still active.
$stmt = $pdo->prepare("
    SELECT id, full_name, email, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$_SESSION["user_id"]]);
$user = $stmt->fetch();

if (!$user || !$user["is_active"] || $user["role"] !== "admin") {
    session_unset();
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Dashboard statistics.
$bookCount = (int) $pdo
    ->query("SELECT COUNT(*) FROM books")
    ->fetchColumn();

$memberCount = (int) $pdo
    ->query("SELECT COUNT(*) FROM users WHERE role = 'member'")
    ->fetchColumn();

$activeBorrowings = (int) $pdo
    ->query("SELECT COUNT(*) FROM borrowings WHERE status = 'borrowed'")
    ->fetchColumn();

$pendingReservations = (int) $pdo
    ->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")
    ->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | eMobilis Library</title>

    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #222;
        }

        header {
            background: #1f2937;
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        header p {
            margin: 5px 0 0;
            color: #d1d5db;
        }

        .logout {
            color: white;
            text-decoration: none;
            background: #dc2626;
            padding: 10px 15px;
            border-radius: 6px;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .welcome {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .welcome h2 {
            margin-top: 0;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .card h3 {
            margin-top: 0;
            color: #4b5563;
        }

        .number {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0 0;
        }

        .actions {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .actions h2 {
            margin-top: 0;
        }

        .actions a {
            display: inline-block;
            margin: 8px 8px 8px 0;
            padding: 12px 18px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .actions a:hover {
            background: #1d4ed8;
        }

        @media (max-width: 800px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 500px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header>
    <div>
        <h1>eMobilis Library Management System</h1>
        <p>Administrator Dashboard</p>
    </div>

    <a class="logout" href="../auth/logout.php">Logout</a>
</header>

<div class="container">

    <section class="welcome">
        <h2>Welcome, <?= htmlspecialchars($user["full_name"]) ?></h2>

        <p>
            You are logged in as a Library Administrator.
        </p>

        <p>
            <strong>Email:</strong>
            <?= htmlspecialchars($user["email"]) ?>
        </p>
    </section>

    <section class="stats">

        <div class="card">
            <h3>Total Books</h3>
            <p class="number"><?= $bookCount ?></p>
        </div>

        <div class="card">
            <h3>Members</h3>
            <p class="number"><?= $memberCount ?></p>
        </div>

        <div class="card">
            <h3>Active Borrowings</h3>
            <p class="number"><?= $activeBorrowings ?></p>
        </div>

        <div class="card">
            <h3>Pending Reservations</h3>
            <p class="number"><?= $pendingReservations ?></p>
        </div>

    </section>

    <section class="actions">

        <h2>Library Management</h2>

        <a href="../books/">Manage Books</a>

       <a href="../borrowings/">Manage Borrowings</a>

<a href="../borrowings/fines.php">
    Fine Payments
</a>

<a href="../member/">Manage Members</a>

<a href="../reservations/index.php">View Reservations</a>
<a href="../reservations/manage.php">Manage Reservations</a>


    </section>

</div>

</body>
</html>
