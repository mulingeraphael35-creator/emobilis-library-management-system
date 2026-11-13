
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Allow members only.
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'member') {
    header("Location: ../auth/login.php");
    exit;
}

$memberId = (int) $_SESSION['user_id'];

// Confirm that the account is still active.
$stmt = $pdo->prepare("
    SELECT id, full_name, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$memberId]);
$member = $stmt->fetch();

if (!$member || $member['role'] !== 'member' || (int)$member['is_active'] !== 1) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Create CSRF token.
if (empty($_SESSION['reservation_cancel_token'])) {
    $_SESSION['reservation_cancel_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

// Process cancellation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['reservation_cancel_token'], $token)
    ) {
        $message = "Invalid request. Please refresh the page and try again.";
        $messageType = "error";
    } elseif (!$reservationId || $reservationId < 1) {
        $message = "Invalid reservation selected.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the reservation and confirm that it belongs to this member.
            $stmt = $pdo->prepare("
                SELECT id, status
                FROM reservations
                WHERE id = ? AND member_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$reservationId, $memberId]);
            $reservation = $stmt->fetch();

            if (!$reservation) {
                throw new RuntimeException("Reservation not found.");
            }

            if ($reservation['status'] !== 'pending') {
                throw new RuntimeException(
                    "Only pending reservations can be cancelled."
                );
            }

            $stmt = $pdo->prepare("
                UPDATE reservations
                SET status = 'cancelled'
                WHERE id = ? AND member_id = ? AND status = 'pending'
            ");
            $stmt->execute([$reservationId, $memberId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "The reservation could not be cancelled."
                );
            }

            // Record the action in the audit log.
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action)
                VALUES (?, ?)
            ");
            $stmt->execute([
                $memberId,
                "RESERVATION_CANCELLED"
            ]);

            $pdo->commit();

            $_SESSION['reservation_cancel_token'] = bin2hex(random_bytes(32));
            $_SESSION['reservation_message'] = "Your reservation was cancelled successfully.";
            $_SESSION['reservation_message_type'] = "success";

            header("Location: my_reservations.php");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Reservation cancellation error: " . $e->getMessage());

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : "Something went wrong. Please try again.";

            $messageType = "error";
        }
    }
}

// Display messages after redirect.
if (isset($_SESSION['reservation_message'])) {
    $message = $_SESSION['reservation_message'];
    $messageType = $_SESSION['reservation_message_type'] ?? 'success';

    unset(
        $_SESSION['reservation_message'],
        $_SESSION['reservation_message_type']
    );
}

// Retrieve only this member's reservations.
$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.reservation_date,
        r.status,
        b.title,
        b.author
    FROM reservations r
    INNER JOIN books b ON r.book_id = b.id
    WHERE r.member_id = ?
    ORDER BY r.reservation_date DESC
");
$stmt->execute([$memberId]);
$reservations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations | eMobilis Library</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1100px;
            margin: auto;
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        h1 {
            color: #17365d;
        }

        .message {
            padding: 12px;
            margin: 15px 0;
            border-radius: 5px;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #17365d;
            color: white;
        }

        .status {
            font-weight: bold;
            text-transform: capitalize;
        }

        .pending {
            color: #b7791f;
        }

        .fulfilled {
            color: #218838;
        }

        .cancelled {
            color: #c82333;
        }

        button {
            background: #c82333;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #a71d2a;
        }

        .links {
            margin-top: 25px;
        }

        .links a {
            display: inline-block;
            margin-right: 15px;
            color: #17365d;
            text-decoration: none;
            font-weight: bold;
        }

        .empty {
            padding: 20px;
            background: #f4f6f9;
            border-radius: 5px;
        }

        @media (max-width: 700px) {
            .container {
                overflow-x: auto;
            }

            table {
                min-width: 650px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>My Reservations</h1>

    <p>eMobilis Library Management System</p>

    <h2>Welcome, <?= htmlspecialchars($member['full_name']) ?></h2>

    <p>View your reserved books and manage pending reservations.</p>

    <?php if ($message): ?>
        <div class="message <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <h2>Reservation History</h2>

    <?php if (empty($reservations)): ?>

        <div class="empty">
            You have not made any book reservations yet.
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Book</th>
                    <th>Author</th>
                    <th>Reservation Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($reservation['title']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($reservation['author']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($reservation['reservation_date']) ?>
                        </td>

                        <td>
                            <span class="status <?= htmlspecialchars($reservation['status']) ?>">
                                <?= htmlspecialchars(ucfirst($reservation['status'])) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($reservation['status'] === 'pending'): ?>
                                <form method="POST"
                                      action="my_reservations.php"
                                      onsubmit="return confirm('Are you sure you want to cancel this reservation?');">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= htmlspecialchars($_SESSION['reservation_cancel_token']) ?>">

                                    <input type="hidden"
                                           name="reservation_id"
                                           value="<?= (int)$reservation['id'] ?>">

                                    <button type="submit">Cancel Reservation</button>
                                </form>
                            <?php else: ?>
                                No action available
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <div class="links">
        <a href="dashboard.php">← Back to Member Dashboard</a>
        <a href="../books/index.php">Browse Books</a>
        <a href="../reservations/add.php">Reserve a Book</a>
    </div>

</div>

</body>
</html>
