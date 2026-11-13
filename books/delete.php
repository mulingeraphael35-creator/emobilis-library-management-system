
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Only Super Admins and Admins can delete books.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit("Access denied. You are not authorised to delete books.");
}

// Verify that the account is still active and authorised.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (
    !$user ||
    !(int)$user['is_active'] ||
    !in_array($user['role'], ['super_admin', 'admin'], true)
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Create a CSRF token.
if (empty($_SESSION['delete_book_token'])) {
    $_SESSION['delete_book_token'] = bin2hex(random_bytes(32));
}

$error = "";
$book = null;

// Get and validate the book ID.
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bookId || $bookId < 1) {
    http_response_code(400);
    exit("Invalid book ID.");
}

// Fetch book details.
$stmt = $pdo->prepare(
    "SELECT id, title, author, isbn, quantity, available_quantity
     FROM books
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    http_response_code(404);
    exit("Book not found.");
}

// Process deletion only through POST.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['delete_book_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid request. Please refresh the page.";

    } else {
        try {
            $pdo->beginTransaction();

            // Lock the book record while checking its borrowing status.
            $stmt = $pdo->prepare(
                "SELECT id, title, quantity, available_quantity
                 FROM books
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$bookId]);
            $lockedBook = $stmt->fetch();

            if (!$lockedBook) {
                throw new RuntimeException("Book not found.");
            }

            // Check for currently borrowed copies.
            $borrowedCopies =
                (int)$lockedBook['quantity'] -
                (int)$lockedBook['available_quantity'];

            if ($borrowedCopies > 0) {
                throw new RuntimeException(
                    "This book cannot be deleted because " .
                    $borrowedCopies . " copy/copies are currently borrowed."
                );
            }

            // Check for active borrowing records.

              $stmt = $pdo->prepare(
             "SELECT COUNT(*)
              FROM borrowings
              WHERE book_id = ?
              AND status IN ('borrowed', 'overdue')"
             );
            $stmt->execute([$bookId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    "This book has an active borrowing record and cannot be deleted."
                );
            }

            // Check for pending reservations.
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM reservations
                 WHERE book_id = ?
                 AND status = 'pending'"
            );
            $stmt->execute([$bookId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    "This book has pending reservations and cannot be deleted."
                );
            }

            // Delete the book.
            $stmt = $pdo->prepare(
                "DELETE FROM books WHERE id = ?"
            );
            $stmt->execute([$bookId]);

            // Record the deletion.
            $log = $pdo->prepare(
                "INSERT INTO audit_logs
                 (user_id, action, details, ip_address)
                 VALUES (?, ?, ?, ?)"
            );

            $log->execute([
                $user['id'],
                'DELETE_BOOK',
                'Deleted book: ' . $lockedBook['title'] .
                ' (Book ID: ' . $bookId . ')',
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $pdo->commit();

            unset($_SESSION['delete_book_token']);

            header("Location: index.php?deleted=1");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log("Book deletion failed: " . $e->getMessage());
                $error = "Unable to delete the book. Please try again.";
            }
        }
    }
}

function escapeDelete($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Book | eMobilis Library</title>
</head>
<body>

    <h1>eMobilis Library Management System</h1>
    <h2>Delete Book</h2>

    <p>
        <a href="index.php">Back to Book Catalogue</a>
    </p>

    <?php if ($error !== ""): ?>
        <p style="color: red;">
            <?php echo escapeDelete($error); ?>
        </p>
    <?php endif; ?>

    <h3>Confirm Book Deletion</h3>

    <p>Are you sure you want to delete this book?</p>

    <ul>
        <li>
            <strong>Title:</strong>
            <?php echo escapeDelete($book['title']); ?>
        </li>
        <li>
            <strong>Author:</strong>
            <?php echo escapeDelete($book['author']); ?>
        </li>
        <li>
            <strong>ISBN:</strong>
            <?php echo escapeDelete($book['isbn'] ?? 'N/A'); ?>
        </li>
    </ul>

    <p style="color: red;">
        Warning: This action cannot be undone.
    </p>

    <form method="POST" action="">
        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo escapeDelete($_SESSION['delete_book_token']); ?>"
        >

        <button
            type="submit"
            onclick="return confirm('Are you absolutely sure you want to delete this book?');"
        >
            Confirm Delete
        </button>

        <a href="index.php">Cancel</a>
    </form>

</body>
</html>
