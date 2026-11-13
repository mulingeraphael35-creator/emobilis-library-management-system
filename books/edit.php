
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Only Super Admins and Admins can edit books.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

// Verify that the account is still active and authorised.
$stmt = $pdo->prepare(
    "SELECT id, role, is_active
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

// Validate the book ID.
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bookId || $bookId < 1) {
    http_response_code(400);
    exit("Invalid book ID.");
}

// Fetch the book.
$stmt = $pdo->prepare(
    "SELECT * FROM books WHERE id = ? LIMIT 1"
);
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    http_response_code(404);
    exit("Book not found.");
}

// Create a CSRF token.
if (empty($_SESSION['edit_book_token'])) {
    $_SESSION['edit_book_token'] = bin2hex(random_bytes(32));
}

$error = "";
$success = false;

$title = $book['title'];
$author = $book['author'];
$isbn = $book['isbn'] ?? "";
$category = $book['category'];
$description = $book['description'] ?? "";
$quantity = (string)$book['quantity'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['edit_book_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid request. Please refresh the page.";

    } else {
        $title = trim($_POST['title'] ?? "");
        $author = trim($_POST['author'] ?? "");
        $isbn = trim($_POST['isbn'] ?? "");
        $category = trim($_POST['category'] ?? "");
        $description = trim($_POST['description'] ?? "");
        $quantityInput = trim($_POST['quantity'] ?? "");

        $quantityValue = filter_var(
            $quantityInput,
            FILTER_VALIDATE_INT
        );

        if ($title === "" || $author === "" || $category === "") {
            $error = "Please complete all required fields.";

        } elseif (
            strlen($title) > 200 ||
            strlen($author) > 150 ||
            strlen($isbn) > 30 ||
            strlen($category) > 100
        ) {
            $error = "One or more fields exceed the allowed length.";

        } elseif ($quantityValue === false || $quantityValue < 1) {
            $error = "Quantity must be a whole number greater than zero.";

        } else {
            try {
                // Lock the book record to safely calculate available copies.
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT quantity, available_quantity
                     FROM books
                     WHERE id = ?
                     FOR UPDATE"
                );
                $stmt->execute([$bookId]);
                $currentBook = $stmt->fetch();

                if (!$currentBook) {
                    throw new RuntimeException("Book not found.");
                }

                $currentlyBorrowed =
                    (int)$currentBook['quantity'] -
                    (int)$currentBook['available_quantity'];

                if ($quantityValue < $currentlyBorrowed) {
                    throw new RuntimeException(
                        "Total copies cannot be less than the number of copies currently borrowed (" .
                        $currentlyBorrowed . ")."
                    );
                }

                // Recalculate available copies without changing borrowings.
                $newAvailable =
                    $quantityValue - $currentlyBorrowed;

                $stmt = $pdo->prepare(
                    "UPDATE books
                     SET title = ?,
                         author = ?,
                         isbn = ?,
                         category = ?,
                         description = ?,
                         quantity = ?,
                         available_quantity = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $title,
                    $author,
                    $isbn === "" ? null : $isbn,
                    $category,
                    $description === "" ? null : $description,
                    $quantityValue,
                    $newAvailable,
                    $bookId
                ]);

                $pdo->commit();

                unset($_SESSION['edit_book_token']);

                header("Location: index.php?updated=1");
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    $error = "That ISBN is already assigned to another book.";
                } elseif ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    error_log("Book update failed: " . $e->getMessage());
                    $error = "Unable to update the book. Please try again.";
                }
            }
        }
    }
}

function escapeEdit($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Book | eMobilis Library</title>
</head>
<body>

    <h1>eMobilis Library Management System</h1>
    <h2>Edit Book</h2>

    <p>
        <a href="index.php">Back to Book Catalogue</a>
    </p>

    <?php if ($error !== ""): ?>
        <p style="color: red;">
            <?php echo escapeEdit($error); ?>
        </p>
    <?php endif; ?>

    <p>
        Currently borrowed copies:
        <?php
        echo (int)$book['quantity'] - (int)$book['available_quantity'];
        ?>
    </p>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo escapeEdit($_SESSION['edit_book_token']); ?>"
        >

        <label for="title">Book Title *</label><br>
        <input
            type="text"
            id="title"
            name="title"
            maxlength="200"
            value="<?php echo escapeEdit($title); ?>"
            required
        >
        <br><br>

        <label for="author">Author *</label><br>
        <input
            type="text"
            id="author"
            name="author"
            maxlength="150"
            value="<?php echo escapeEdit($author); ?>"
            required
        >
        <br><br>

        <label for="isbn">ISBN (Optional)</label><br>
        <input
            type="text"
            id="isbn"
            name="isbn"
            maxlength="30"
            value="<?php echo escapeEdit($isbn); ?>"
        >
        <br><br>

        <label for="category">Category *</label><br>
        <input
            type="text"
            id="category"
            name="category"
            maxlength="100"
            value="<?php echo escapeEdit($category); ?>"
            required
        >
        <br><br>

        <label for="description">Description</label><br>
        <textarea
            id="description"
            name="description"
            rows="5"
            cols="40"
        ><?php echo escapeEdit($description); ?></textarea>
        <br><br>

        <label for="quantity">Total Copies *</label><br>
        <input
            type="number"
            id="quantity"
            name="quantity"
            min="1"
            value="<?php echo escapeEdit($quantity); ?>"
            required
        >
        <br><br>

        <button type="submit">Update Book</button>

    </form>

</body>
</html>
