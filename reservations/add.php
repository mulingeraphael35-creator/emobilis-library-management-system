
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only logged-in members can place reservations.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Verify that the account is active and is a member.
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
    $currentUser['role'] !== 'member'
) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

// Generate CSRF token.
if (empty($_SESSION['reservation_token'])) {
    $_SESSION['reservation_token'] = bin2hex(random_bytes(32));
}

$successMessage = $_SESSION['reservation_success'] ?? '';
$errorMessage = $_SESSION['reservation_error'] ?? '';

unset(
    $_SESSION['reservation_success'],
    $_SESSION['reservation_error']
);

// Process reservation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['reservation_token'],
                $_POST['csrf_token']
            )
        ) {
            throw new Exception("Invalid security token. Please try again.");
        }

        $bookId = filter_var(
            $_POST['book_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$bookId || $bookId < 1) {
            throw new Exception("Please select a valid book.");
        }

        $pdo->beginTransaction();

        // Lock the selected book while checking availability.
        $stmt = $pdo->prepare(
            "SELECT id, title, available_quantity
             FROM books
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if (!$book) {
            throw new Exception("The selected book was not found.");
        }

        if ((int)$book['available_quantity'] > 0) {
            throw new Exception(
                "This book is currently available and does not need a reservation."
            );
        }

        // Prevent duplicate pending reservations.
        $stmt = $pdo->prepare(
            "SELECT id
             FROM reservations
             WHERE book_id = ?
               AND member_id = ?
               AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([$bookId, $currentUser['id']]);

        if ($stmt->fetch()) {
            throw new Exception(
                "You already have a pending reservation for this book."
            );
        }

        // Create the reservation.
        $stmt = $pdo->prepare(
            "INSERT INTO reservations
                (book_id, member_id, status)
             VALUES (?, ?, 'pending')"
        );

        $stmt->execute([
            $bookId,
            $currentUser['id']
        ]);

        $pdo->commit();

        $_SESSION['reservation_success'] =
            "Your reservation for " . $book['title'] .
            " has been submitted successfully.";

        $_SESSION['reservation_token'] = bin2hex(random_bytes(32));

        header("Location: add.php");
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Reservation error: " . $e->getMessage());

        $_SESSION['reservation_error'] = $e instanceof PDOException
            ? "Unable to process your reservation. Please try again."
            : $e->getMessage();

        header("Location: add.php");
        exit;
    }
}

// Retrieve books that are currently unavailable.
$stmt = $pdo->query(
    "SELECT id, title, author, category
     FROM books
     WHERE available_quantity = 0
     ORDER BY title ASC"
);

$unavailableBooks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve a Book | Mobilis Library</title>
</head>
<body>

<h1>Mobilis Library Management System</h1>

<h2>Reserve a Book</h2>

<p>
    Welcome,
    <?php echo htmlspecialchars($currentUser['full_name']); ?>.
</p>

<p>
    <a href="../members/index.php">Back to Library</a>
</p>

<?php if ($successMessage): ?>
    <p style="color: green;">
        <?php echo htmlspecialchars($successMessage); ?>
    </p>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <p style="color: red;">
        <?php echo htmlspecialchars($errorMessage); ?>
    </p>
<?php endif; ?>

<h3>Currently Unavailable Books</h3>

<?php if (count($unavailableBooks) > 0): ?>

    <form method="POST" action="add.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($_SESSION['reservation_token']); ?>"
        >

        <label for="book_id">Select a book:</label>

        <select name="book_id" id="book_id" required>
            <option value="">Choose a book</option>

            <?php foreach ($unavailableBooks as $book): ?>
                <option value="<?php echo (int)$book['id']; ?>">
                    <?php
                    echo htmlspecialchars(
                        $book['title'] . ' - ' .
                        $book['author'] . ' (' .
                        $book['category'] . ')'
                    );
                    ?>
                </option>
            <?php endforeach; ?>

        </select>

        <button type="submit">Submit Reservation</button>

    </form>

<?php else: ?>

    <p>
        There are currently no unavailable books to reserve.
    </p>

<?php endif; ?>

</body>
</html>
