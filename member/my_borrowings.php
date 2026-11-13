
<?php

session_start();

require_once __DIR__ . '/../config/db.php';

// Only authenticated members may access this page.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'member'
) {
    header("Location: ../auth/login.php");
    exit;
}

// Revalidate the member account.
$stmt = $pdo->prepare("
    SELECT id, full_name, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$member = $stmt->fetch();

if (
    !$member ||
    !(int)$member['is_active'] ||
    $member['role'] !== 'member'
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Retrieve only this member's borrowing records.
$stmt = $pdo->prepare("
    SELECT
        br.id,
        br.issue_date,
        br.due_date,
        br.return_date,
        br.status,
        br.overdue_hours,
        br.fine_amount,
        br.fine_paid,
        b.title,
        b.author
    FROM borrowings br
    INNER JOIN books b ON br.book_id = b.id
    WHERE br.member_id = ?
    ORDER BY br.issue_date DESC
");

$stmt->execute([$member['id']]);
$borrowings = $stmt->fetchAll();

// Calculate summary figures.
$activeCount = 0;
$overdueCount = 0;
$returnedCount = 0;
$totalOutstanding = 0;

foreach ($borrowings as $borrowing) {

    if ($borrowing['status'] === 'borrowed') {
        $activeCount++;
    }

    if ($borrowing['status'] === 'overdue') {
        $overdueCount++;
    }

    if ($borrowing['status'] === 'returned') {
        $returnedCount++;
    }

    $totalOutstanding += max(
        0,
        (float)$borrowing['fine_amount'] - (float)$borrowing['fine_paid']
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Borrowings | eMobilis Library</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            color: #333;
        }

        header {
            background: #243b53;
            color: white;
            padding: 22px 30px;
        }

        header h1 {
            margin: 0;
        }

        .container {
            max-width: 1150px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .welcome,
        .summary-card,
        .records {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 22px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .summary-card h3 {
            color: #52677d;
            font-size: 15px;
            margin-top: 0;
        }

        .number {
            font-size: 26px;
            font-weight: bold;
            color: #243b53;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            margin-top: 18px;
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

        .status {
            font-weight: bold;
        }

        .borrowed {
            color: #0d6efd;
        }

        .overdue {
            color: #dc3545;
        }

        .returned {
            color: #198754;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            margin-right: 15px;
            margin-bottom: 20px;
            color: #243b53;
            text-decoration: none;
        }

        .empty {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 5px;
        }

    </style>

</head>

<body>

<header>
    <h1>My Borrowings</h1>
    <p>eMobilis Library Management System</p>
</header>

<div class="container">

    <div class="welcome">

        <h2>
            Welcome, <?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p>
            View your borrowing history, track due dates and monitor outstanding fines.
        </p>

    </div>

    <div class="summary">

        <div class="summary-card">
            <h3>Active Borrowings</h3>
            <div class="number"><?= $activeCount ?></div>
        </div>

        <div class="summary-card">
            <h3>Overdue Books</h3>
            <div class="number"><?= $overdueCount ?></div>
        </div>

        <div class="summary-card">
            <h3>Returned Books</h3>
            <div class="number"><?= $returnedCount ?></div>
        </div>

        <div class="summary-card">
            <h3>Outstanding Fines</h3>
            <div class="number">
                KSh <?= number_format($totalOutstanding, 2) ?>
            </div>
        </div>

    </div>

    <div class="records">

        <h2>Borrowing History</h2>

        <?php if (empty($borrowings)): ?>

            <p class="empty">
                You do not have any borrowing records yet.
            </p>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Author</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                            <th>Fine (KSh)</th>
                            <th>Outstanding (KSh)</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($borrowings as $borrowing): ?>

                            <?php
                                $fine = (float)$borrowing['fine_amount'];
                                $paid = (float)$borrowing['fine_paid'];
                                $balance = max(0, $fine - $paid);

                                $statusClass = $borrowing['status'];
                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($borrowing['title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($borrowing['author'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($borrowing['issue_date'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($borrowing['due_date'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= $borrowing['return_date']
                                        ? htmlspecialchars($borrowing['return_date'], ENT_QUOTES, 'UTF-8')
                                        : 'Not returned' ?>
                                </td>

                                <td>
                                    <span class="status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($borrowing['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>

                                <td>
                                    <?= number_format($fine, 2) ?>
                                </td>

                                <td>
                                    <?= number_format($balance, 2) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <a class="back" href="dashboard.php">
        ← Back to Member Dashboard
    </a>

    <a class="back" href="../books/index.php">
        Browse Books
    </a>

</div>

</body>
</html>
