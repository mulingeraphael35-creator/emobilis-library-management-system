
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Require an authenticated administrator.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Recheck the administrator's account and role.
$stmt = $pdo->prepare(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE id = ?"
);
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (
    !$currentUser ||
    (int)$currentUser['is_active'] !== 1 ||
    !in_array($currentUser['role'], ['super_admin', 'admin'], true)
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Create a CSRF token.
if (empty($_SESSION['issue_book_token'])) {
    $_SESSION['issue_book_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Process the issue-book form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['issue_book_token'], $token)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
        $dueDate = $_POST['due_date'] ?? '';

        $dateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);
        $validDate = $dateObject &&
            $dateObject->format('Y-m-d') === $dueDate &&
            $dueDate > date('Y-m-d');

        if (!$memberId || !$bookId || !$validDate) {
            $error = "Please select a valid member, book and future due date.";
        } else {

            try {
                $pdo->beginTransaction();

                // Confirm that the selected member is active.
                $stmt = $pdo->prepare(
                    "SELECT id
                     FROM users
                     WHERE id = ?
                     AND role = 'member'
                     AND is_active = 1
                     FOR UPDATE"
                );
                $stmt->execute([$memberId]);
                $member = $stmt->fetch();

                if (!$member) {
                    throw new RuntimeException(
                        "The selected member does not exist or is inactive."
                    );
                }

                // Lock the book record to prevent issuing unavailable copies.
                $stmt = $pdo->prepare(
                    "SELECT id, title, available_quantity
                     FROM books
                     WHERE id = ?
                     FOR UPDATE"
                );
                $stmt->execute([$bookId]);
                $book = $stmt->fetch();

                if (!$book) {
                    throw new RuntimeException("The selected book was not found.");
                }

                if ((int)$book['available_quantity'] < 1) {
                    throw new RuntimeException(
                        "This book has no available copies."
                    );
                }

                // Create the borrowing record.
                $stmt = $pdo->prepare(
                    "INSERT INTO borrowings
                     (book_id, member_id, issued_by, due_date, status)
                     VALUES (?, ?, ?, ?, 'borrowed')"
                );

                $stmt->execute([
                    $bookId,
                    $memberId,
                    $currentUser['id'],
                    $dueDate
                ]);

                // Reduce available quantity by one.
                $stmt = $pdo->prepare(
                    "UPDATE books
                     SET available_quantity = available_quantity - 1
                     WHERE id = ?
                     AND available_quantity > 0"
                );
                $stmt->execute([$bookId]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Unable to update the available book quantity."
                    );
                }

                // Record the action in the audit log.
                $stmt = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (user_id, action, details, ip_address)
                     VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $currentUser['id'],
                    'BOOK_ISSUED',
                    'Book ID ' . $bookId . ' issued to member ID ' . $memberId,
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                $pdo->commit();

                unset($_SESSION['issue_book_token']);

                header("Location: index.php?issued=1");
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Book issue error: " . $e->getMessage());

                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : "Unable to issue the book. Please try again.";
            }
        }
    }
}

// Retrieve active members.
$stmt = $pdo->query(
    "SELECT id, full_name, email
     FROM users
     WHERE role = 'member'
     AND is_active = 1
     ORDER BY full_name"
);
$members = $stmt->fetchAll();

// Retrieve books with available copies.
$stmt = $pdo->query(
    "SELECT id, title, author, available_quantity
     FROM books
     WHERE available_quantity > 0
     ORDER BY title"
);
$books = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issue a Book</title>
</head>
<body>

<h1>Mobilis Library Management System</h1>

<h2>Issue a Book</h2>

<p>
    Welcome,
    <?php echo htmlspecialchars($currentUser['full_name']); ?>.
</p>

<?php if ($error): ?>
    <p style="color: red;">
        <?php echo htmlspecialchars($error); ?>
    </p>
<?php endif; ?>

<form method="POST" action="">

    <input
        type="hidden"
        name="csrf_token"
        value="<?php echo htmlspecialchars($_SESSION['issue_book_token']); ?>"
    >

    <label for="member_id">Select Member:</label>
    <br>

    <select name="member_id" id="member_id" required>
        <option value="">-- Select Member --</option>

        <?php foreach ($members as $member): ?>
            <option value="<?php echo (int)$member['id']; ?>">
                <?php echo htmlspecialchars(
                    $member['full_name'] . ' (' . $member['email'] . ')'
                ); ?>
            </option>
        <?php endforeach; ?>

    </select>

    <br><br>

    <label for="book_id">Select Book:</label>
    <br>

    <select name="book_id" id="book_id" required>
        <option value="">-- Select Book --</option>

        <?php foreach ($books as $book): ?>
            <option value="<?php echo (int)$book['id']; ?>">
                <?php echo htmlspecialchars(
                    $book['title'] . ' by ' . $book['author'] .
                    ' - Available: ' . $book['available_quantity']
                ); ?>
            </option>
        <?php endforeach; ?>

    </select>

    <br><br>

    <label for="due_date">Due Date:</label>
    <br>

    <input
        type="date"
        name="due_date"
        id="due_date"
        min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
        required
    >

    <br><br>

    <button type="submit">Issue Book</button>

</form>

<p>
    <a href="index.php">View Borrowing Records</a>
</p>

<p>
    <a href="../admin/dashboard.php">Admin Dashboard</a>
</p>

</body>
</html>
