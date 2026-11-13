
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only Super Admins and Admins can process returns.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// Recheck the logged-in account.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    !$currentUser['is_active'] ||
    !in_array($currentUser['role'], ['super_admin', 'admin'], true)
) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$error = '';
$success = '';

// Generate CSRF token.
if (empty($_SESSION['return_book_token'])) {
    $_SESSION['return_book_token'] = bin2hex(random_bytes(32));
}

// Process a book return.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $borrowingId = filter_input(INPUT_POST, 'borrowing_id', FILTER_VALIDATE_INT);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['return_book_token'], $token)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif (!$borrowingId || $borrowingId < 1) {
        $error = "Please select a valid borrowing record.";
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the borrowing record to prevent duplicate returns.
           $stmt = $pdo->prepare(
    "UPDATE borrowings
     SET status = 'returned',
         return_date = CURRENT_TIMESTAMP,
         overdue_hours = GREATEST(
             0,
             TIMESTAMPDIFF(
                 HOUR,
                 DATE_ADD(due_date, INTERVAL 1 DAY),
                 CURRENT_TIMESTAMP
             )
         ),
         fine_amount = GREATEST(
             0,
             TIMESTAMPDIFF(
                 HOUR,
                 DATE_ADD(due_date, INTERVAL 1 DAY),
                 CURRENT_TIMESTAMP
             )
         ) * 5.00
     WHERE id = ?"
);

$stmt->execute([$borrowingId]);
            $borrowing = $stmt->fetch();

            if (!$borrowing) {
                throw new RuntimeException("Borrowing record not found.");
            }

            if (!in_array($borrowing['status'], ['borrowed', 'overdue'], true)) {
                throw new RuntimeException("This book has already been returned or is not eligible for return.");
            }

            // Lock the book record.
            $stmt = $pdo->prepare(
                "SELECT id, quantity, available_quantity
                 FROM books
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$borrowing['book_id']]);
            $book = $stmt->fetch();

            if (!$book) {
                throw new RuntimeException("The book record could not be found.");
            }

            if ((int)$book['available_quantity'] >= (int)$book['quantity']) {
                throw new RuntimeException("The available book quantity is already at its maximum. Please check the inventory.");
            }

            // Mark the borrowing as returned.
            $stmt = $pdo->prepare(
                "UPDATE borrowings
                 SET status = 'returned',
                     return_date = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$borrowingId]);

            // Restore one available copy.
            $stmt = $pdo->prepare(
                "UPDATE books
                 SET available_quantity = available_quantity + 1
                 WHERE id = ?"
            );
            $stmt->execute([$borrowing['book_id']]);

            // Record the action in the audit log.
            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, details)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                'BOOK_RETURNED',
                'Returned borrowing record ID: ' . $borrowingId
            ]);

            $pdo->commit();

            $success = "Book returned successfully.";
            $_SESSION['return_book_token'] = bin2hex(random_bytes(32));

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log("Book return failed: " . $e->getMessage());
                $error = "Unable to process the return. Please try again.";
            }
        }
    }
}

// Retrieve books that can still be returned.
$stmt = $pdo->query(
    "SELECT
        br.id AS borrowing_id,
        u.full_name,
        b.title,
        br.issue_date,
        br.due_date,
        br.status
     FROM borrowings br
     INNER JOIN users u ON br.member_id = u.id
     INNER JOIN books b ON br.book_id = b.id
     WHERE br.status IN ('borrowed', 'overdue')
     ORDER BY br.due_date ASC"
);

$activeBorrowings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return a Book - Mobilis Library</title>
</head>
<body>

    <h1>Return a Book</h1>

    <p>
        Welcome,
        <?php echo htmlspecialchars($currentUser['full_name']); ?>.
    </p>

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

    <h2>Books Currently Borrowed</h2>

    <?php if (count($activeBorrowings) > 0): ?>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
                <tr>
                    <th>Borrowing ID</th>
                    <th>Member</th>
                    <th>Book</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($activeBorrowings as $record): ?>
                    <tr>
                        <td><?php echo (int)$record['borrowing_id']; ?></td>
                        <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($record['title']); ?></td>
                        <td><?php echo htmlspecialchars($record['issue_date']); ?></td>
                        <td><?php echo htmlspecialchars($record['due_date']); ?></td>
                        <td><?php echo htmlspecialchars($record['status']); ?></td>
                        <td>
                            <form method="POST" action="">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?php echo htmlspecialchars($_SESSION['return_book_token']); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="borrowing_id"
                                    value="<?php echo (int)$record['borrowing_id']; ?>"
                                >

                                <button type="submit">
                                    Confirm Return
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php else: ?>

        <p>There are currently no books awaiting return.</p>

    <?php endif; ?>

    <p>
        <a href="index.php">View Borrowing Records</a>
    </p>

    <p>
        <a href="../books/index.php">View Book Catalogue</a>
    </p>

    <p>
        <a href="../admin/dashboard.php">Admin Dashboard</a>
    </p>

</body>
</html>
