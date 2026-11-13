
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only logged-in members may access this dashboard.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'member'
) {
    header("Location: ../auth/login.php");
    exit;
}

// Recheck the member's account in the database.
$stmt = $pdo->prepare("
    SELECT id, full_name, email, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$member = $stmt->fetch();

if (
    !$member ||
    !$member['is_active'] ||
    $member['role'] !== 'member'
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Count current borrowings.
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM borrowings
    WHERE member_id = ?
    AND status IN ('borrowed', 'overdue')
");
$stmt->execute([$member['id']]);
$activeBorrowings = (int) $stmt->fetchColumn();

// Count pending reservations.
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM reservations
    WHERE member_id = ?
    AND status = 'pending'
");
$stmt->execute([$member['id']]);
$pendingReservations = (int) $stmt->fetchColumn();

// Calculate outstanding fines.
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(GREATEST(fine_amount - fine_paid, 0)), 0)
    FROM borrowings
    WHERE member_id = ?
");
$stmt->execute([$member['id']]);
$outstandingFines = (float) $stmt->fetchColumn();

// Get the member's latest borrowing records.
$stmt = $pdo->prepare("
    SELECT
        b.title,
        br.issue_date,
        br.due_date,
        br.return_date,
        br.status
    FROM borrowings br
    INNER JOIN books b ON br.book_id = b.id
    WHERE br.member_id = ?
    ORDER BY br.issue_date DESC
    LIMIT 5
");
$stmt->execute([$member['id']]);
$recentBorrowings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Member Dashboard</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            color: #333;
        }

        header {
            background: #243b53;
            color: white;
            padding: 20px 30px;
        }

        header h2 {
            margin: 0;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .card h3 {
            margin-top: 0;
            color: #52677d;
            font-size: 15px;
        }

        .number {
            font-size: 28px;
            font-weight: bold;
            color: #243b53;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 30px;
        }

        .actions a {
            background: #198754;
            color: white;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 5px;
        }

        .actions a:hover {
            background: #146c43;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin-top: 15px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #243b53;
            color: white;
        }

        .logout {
            color: white;
            float: right;
            text-decoration: none;
        }

        .empty {
            background: white;
            padding: 20px;
            border-radius: 6px;
        }

        @media (max-width: 650px) {
            .table-wrapper {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>

<header>
    <a class="logout" href="../auth/logout.php">Logout</a>
    <h2>Library Member Portal</h2>
</header>

<div class="container">

    <div class="welcome">
        <h2>
            Welcome, <?= htmlspecialchars($member['full_name']) ?>!
        </h2>

        <p>
            Manage your library activities and keep track of your books.
        </p>
    </div>

    <div class="stats">

        <div class="card">
            <h3>Active Borrowings</h3>
            <div class="number"><?= $activeBorrowings ?></div>
        </div>

        <div class="card">
            <h3>Pending Reservations</h3>
            <div class="number"><?= $pendingReservations ?></div>
        </div>

        <div class="card">
            <h3>Outstanding Fines</h3>
            <div class="number">
                KSh <?= number_format($outstandingFines, 2) ?>
            </div>
        </div>

    </div>

    <div class="card">
        <h3>Library Services</h3>

       <div class="actions">
    <a href="../books/index.php">Browse Books</a>

    <a href="my_borrowings.php">My Borrowings</a>

    <a href="my_reservations.php">My Reservations</a>

    <a href="../reservations/add.php">Reserve a Book</a>
</div>
    </div>

    <div class="card" style="margin-top: 25px;">

        <h3>Recent Borrowing Activity</h3>

        <?php if (empty($recentBorrowings)): ?>

            <p class="empty">You have no borrowing records yet.</p>

        <?php else: ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($recentBorrowings as $borrowing): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($borrowing['title']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($borrowing['issue_date']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($borrowing['due_date']) ?>
                                </td>

                                <td>
                                    <?= $borrowing['return_date']
                                        ? htmlspecialchars($borrowing['return_date'])
                                        : 'Not returned' ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(ucfirst($borrowing['status'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
