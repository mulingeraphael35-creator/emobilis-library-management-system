
<?php

session_start();

require_once __DIR__ . "/../config/db.php";

// Only Super Admins and Admins may add books.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit("Access denied. You are not authorised to add books.");
}

// Confirm that the account is still active and authorised.
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

// Create a CSRF token.
if (empty($_SESSION['book_token'])) {
    $_SESSION['book_token'] = bin2hex(random_bytes(32));
}

$error = "";
$success = isset($_GET['success']);

$title = "";
$author = "";
$isbn = "";
$category = "";
$description = "";
$quantity = "1";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['book_token'], $_POST['csrf_token'])
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
            $error = "Please fill in all required fields.";

        } elseif (
            strlen($title) > 200 ||
            strlen($author) > 150 ||
            strlen($isbn) > 30 ||
            strlen($category) > 100
        ) {
            $error = "One or more fields exceed the allowed length.";

        } elseif (
            $quantityValue === false ||
            $quantityValue < 1
        ) {
            $error = "Quantity must be a whole number greater than zero.";

        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO books
                    (title, author, isbn, category, description,
                     quantity, available_quantity)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $title,
                    $author,
                    $isbn === "" ? null : $isbn,
                    $category,
                    $description === "" ? null : $description,
                    $quantityValue,
                    $quantityValue
                ]);

                unset($_SESSION['book_token']);

                header("Location: add.php?success=1");
                exit;

            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "This ISBN is already registered. Please check the ISBN.";
                } else {
                    error_log("Book creation failed: " . $e->getMessage());
                    $error = "Unable to save the book. Please try again.";
                }
            }
        }
    }
}

function escapeOutput($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Book | eMobilis Library</title>
</head>
<body>

    <h1>eMobilis Library Management System</h1>
    <h2>Add a New Book</h2>

    <p>
        <a href="index.php">Back to Book Catalogue</a>
    </p>

    <?php if ($success): ?>
        <p style="color: green;">
            Book successfully added to the catalogue.
        </p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p style="color: red;">
            <?php echo escapeOutput($error); ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo escapeOutput($_SESSION['book_token']); ?>"
        >

        <label for="title">Book Title *</label><br>
        <input
            type="text"
            id="title"
            name="title"
            maxlength="200"
            value="<?php echo escapeOutput($title); ?>"
            required
        >
        <br><br>

        <label for="author">Author *</label><br>
        <input
            type="text"
            id="author"
            name="author"
            maxlength="150"
            value="<?php echo escapeOutput($author); ?>"
            required
        >
        <br><br>

        <label for="isbn">ISBN (Optional)</label><br>
        <input
            type="text"
            id="isbn"
            name="isbn"
            maxlength="30"
            value="<?php echo escapeOutput($isbn); ?>"
        >
        <br><br>

        <label for="category">Category *</label><br>
        <input
            type="text"
            id="category"
            name="category"
            maxlength="100"
            value="<?php echo escapeOutput($category); ?>"
            placeholder="e.g. Technology, Business, Fiction"
            required
        >
        <br><br>

        <label for="description">Description</label><br>
        <textarea
            id="description"
            name="description"
            rows="5"
            cols="40"
        ><?php echo escapeOutput($description); ?></textarea>
        <br><br>

        <label for="quantity">Number of Copies *</label><br>
        <input
            type="number"
            id="quantity"
            name="quantity"
            min="1"
            value="<?php echo escapeOutput($quantity); ?>"
            required
        >
        <br><br>

        <button type="submit">Save Book</button>

    </form>

</body>
</html>
