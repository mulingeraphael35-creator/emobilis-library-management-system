
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Admins and Super Admins may manage reservations.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'super_admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// Confirm the logged-in account is still active.
$stmt = $pdo->prepare("
    SELECT id, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    !$currentUser['is_active'] ||
    $currentUser['role'] !== $_SESSION['role']
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$message = '';
$messageType = '';

// Generate CSRF token.
if (empty($_SESSION['reservation_manage_token'])) {
    $_SESSION['reservation_manage_token'] = bin2hex(random_bytes(32));
}

// Process reservation actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['reservation_manage_token'],
            $_POST['csrf_token']
        )
    ) {
        $message = "Invalid request. Please refresh and try again.";
        $messageType = "error";

    } else {

        $reservationId = filter_input(
            INPUT_POST,
            'reservation_id',
            FILTER_VALIDATE_INT
        );

        $action = $_POST['action'] ?? '';

        if (!$reservationId || !in_array($action, ['fulfil', 'cancel'], true)) {
            $message = "Invalid reservation request.";
            $messageType = "error";

        } else {

            try {
                $pdo->beginTransaction();

                // Lock the reservation.
                $stmt = $pdo->prepare("
                    SELECT id, book_id, member_id, status
                    FROM reservations
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$reservationId]);
                $reservation = $stmt->fetch();

                if (!$reservation || $reservation['status'] !== 'pending') {
                    throw new Exception(
                        "This reservation does not exist or is no longer pending."
                    );
                }

                if ($action === 'cancel') {

                    $stmt = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'cancelled'
                        WHERE id = ?
                    ");
                    $stmt->execute([$reservationId]);

                    $auditAction = 'RESERVATION_CANCELLED';
                    $message = "Reservation cancelled successfully.";

                } else {

                    // Lock the member account.
                    $stmt = $pdo->prepare("
                        SELECT id, is_active
                        FROM users
                        WHERE id = ? AND role = 'member'
                        FOR UPDATE
                    ");
                    $stmt->execute([$reservation['member_id']]);
                    $member = $stmt->fetch();

                    if (!$member || !$member['is_active']) {
                        throw new Exception(
                            "The member account is inactive or unavailable."
                        );
                    }

                    // Lock the book and check stock.
                    $stmt = $pdo->prepare("
                        SELECT id, available_quantity
                        FROM books
                        WHERE id = ?
                        FOR UPDATE
                    ");
                    $stmt->execute([$reservation['book_id']]);
                    $book = $stmt->fetch();

                    if (!$book || $book['available_quantity'] < 1) {
                        throw new Exception(
                            "This book is not currently available for issue."
                        );
                    }

                    // Create the borrowing record.
                    $stmt = $pdo->prepare("
                        INSERT INTO borrowings (
                            book_id,
                            member_id,
                            issued_by,
                            due_date,
                            status
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            DATE_ADD(CURDATE(), INTERVAL 14 DAY),
                            'borrowed'
                        )
                    ");

                    $stmt->execute([
                        $reservation['book_id'],
                        $reservation['member_id'],
                        $_SESSION['user_id']
                    ]);

                    // Reduce available stock.
                    $stmt = $pdo->prepare("
                        UPDATE books
                        SET available_quantity = available_quantity - 1
                        WHERE id = ?
                    ");
                    $stmt->execute([$reservation['book_id']]);

                    // Mark reservation as fulfilled.
                    $stmt = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'fulfilled'
                        WHERE id = ?
                    ");
                    $stmt->execute([$reservationId]);

                    $auditAction = 'RESERVATION_FULFILLED';
                    $message = "Reservation fulfilled. The book has been issued successfully.";
                }

                // Record the action in the audit log.
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action)
                    VALUES (?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $auditAction
                ]);

                $pdo->commit();
                $messageType = "success";

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Reservation management error: " . $e->getMessage());

                $message = $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// Retrieve pending reservations.
$stmt = $pdo->query("
    SELECT
        r.id,
        r.reservation_date,
        r.status,
        u.full_name,
        u.email,
        b.title,
        b.author,
        b.available_quantity
    FROM reservations r
    INNER JOIN users u ON r.member_id = u.id
    INNER JOIN books b ON r.book_id = b.id
    WHERE r.status = 'pending'
    ORDER BY r.reservation_date ASC
");

$reservations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reservations</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
        }

        .container {
            max-width: 1100px;
            margin: auto;
            background: white;
            padding: 25px;
            border-radius: 8px;
        }

        h2 {
            color: #243b53;
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
            background: #243b53;
            color: white;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
        }

        button {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            margin: 2px;
        }

        .fulfil {
            background: #198754;
        }

        .cancel {
            background: #dc3545;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #243b53;
            text-decoration: none;
        }

        .unavailable {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>

<body>

<div class="container">

    <h2>Reservation Management</h2>
    <p>Review pending reservations and issue available books to members.</p>

    <?php if ($message): ?>
        <div class="<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($reservations)): ?>

        <p>No pending reservations found.</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Book</th>
                    <th>Available Copies</th>
                    <th>Reservation Date</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($reservations as $reservation): ?>

                <tr>
                    <td><?= (int) $reservation['id'] ?></td>

                    <td>
                        <?= htmlspecialchars($reservation['full_name']) ?><br>
                        <small><?= htmlspecialchars($reservation['email']) ?></small>
                    </td>

                    <td>
                        <?= htmlspecialchars($reservation['title']) ?><br>
                        <small><?= htmlspecialchars($reservation['author']) ?></small>
                    </td>

                    <td>
                        <?php if ($reservation['available_quantity'] > 0): ?>
                            <?= (int) $reservation['available_quantity'] ?>
                        <?php else: ?>
                            <span class="unavailable">Unavailable</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($reservation['reservation_date']) ?>
                    </td>

                    <td>

                        <?php if ($reservation['available_quantity'] > 0): ?>

                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['reservation_manage_token']) ?>">

                                <input type="hidden" name="reservation_id"
                                    value="<?= (int) $reservation['id'] ?>">

                                <button class="fulfil" type="submit" name="action"
                                    value="fulfil"
                                    onclick="return confirm('Issue this book to the member and fulfil the reservation?');">
                                    Fulfil
                                </button>
                            </form>

                        <?php endif; ?>

                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['reservation_manage_token']) ?>">

                            <input type="hidden" name="reservation_id"
                                value="<?= (int) $reservation['id'] ?>">

                            <button class="cancel" type="submit" name="action"
                                value="cancel"
                                onclick="return confirm('Are you sure you want to cancel this reservation?');">
                                Cancel
                            </button>
                        </form>

                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

    <?php endif; ?>

    <a class="back" href="../admin/dashboard.php">← Back to Admin Dashboard</a>

</div>

</body>
</html>
