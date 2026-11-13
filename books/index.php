
<?php

session_start();

require_once __DIR__ . '/../config/db.php';

// Require an authenticated user.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Confirm that the account still exists and is active.
$stmt = $pdo->prepare("
    SELECT id, full_name, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !(int)$user['is_active']) {
    session_unset();
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$role = $user['role'];

$canManageBooks = in_array(
    $role,
    ['super_admin', 'admin'],
    true
);

// Read search and category filters.
$search = trim($_GET['q'] ?? '');
$selectedCategory = trim($_GET['category'] ?? '');

// Retrieve all categories and their book counts.
$stmt = $pdo->query("
    SELECT
        category,
        COUNT(*) AS book_count
    FROM books
    GROUP BY category
    ORDER BY category ASC
");

$categories = $stmt->fetchAll();

// Build the book search query.
$sql = "
    SELECT
        id,
        title,
        author,
        isbn,
        category,
        quantity,
        available_quantity
    FROM books
";

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(
        title LIKE ?
        OR author LIKE ?
        OR isbn LIKE ?
        OR category LIKE ?
    )";

    $term = "%{$search}%";

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($selectedCategory !== '') {
    $conditions[] = "category = ?";
    $params[] = $selectedCategory;
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY category ASC, title ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Group the retrieved books by category.
$groupedBooks = [];

foreach ($books as $book) {
    $categoryName = $book['category'];

    if (!isset($groupedBooks[$categoryName])) {
        $groupedBooks[$categoryName] = [];
    }

    $groupedBooks[$categoryName][] = $book;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Book Catalogue | eMobilis Library</title>

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
            font-size: 25px;
        }

        header p {
            margin-bottom: 0;
            color: #dce6f0;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .welcome,
        .search-box,
        .catalogue,
        .category-section {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 22px;
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        input[type="search"] {
            flex: 1;
            min-width: 220px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button, .button {
            display: inline-block;
            padding: 11px 16px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        button {
            background: #198754;
            color: white;
        }

        .clear {
            background: #6c757d;
            color: white;
        }

        .add {
            background: #198754;
            color: white;
        }

        .reserve {
            background: #0d6efd;
            color: white;
        }

        .edit {
            color: #0d6efd;
        }

        .delete {
            color: #dc3545;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 15px;
            margin-top: 18px;
        }

        .category-card {
            display: block;
            background: #f4f7fb;
            border: 1px solid #dce4ed;
            border-radius: 8px;
            padding: 20px;
            text-decoration: none;
            color: #243b53;
            transition: 0.2s;
        }

        .category-card:hover {
            background: #e8f0f8;
            border-color: #243b53;
        }

        .category-card h3 {
            margin: 0 0 10px;
        }

        .category-count {
            color: #52677d;
            font-size: 14px;
        }

        .category-heading {
            color: #243b53;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
            min-width: 750px;
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

        tr:hover {
            background: #f7f9fc;
        }

        .available {
            color: #198754;
            font-weight: bold;
        }

        .unavailable {
            color: #dc3545;
            font-weight: bold;
        }

        .actions {
            white-space: nowrap;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            margin-bottom: 25px;
            color: #243b53;
            text-decoration: none;
        }

        .empty {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        .filter-info {
            background: #e8f0f8;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

    </style>

</head>

<body>

<header>

    <h1>eMobilis Library Management System</h1>

    <p>Explore our library collection</p>

</header>

<div class="container">

    <div class="welcome">

        <h2>
            Welcome, <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>
            Browse our collection by category, search for books
            and check their availability.
        </p>

    </div>

    <!-- Book search -->
    <div class="search-box">

        <h3>Find a Book</h3>

        <form method="GET" action="index.php" class="search-form">

            <input
                type="search"
                name="q"
                placeholder="Search by title, author, ISBN or category"
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            >

            <?php if ($selectedCategory !== ''): ?>
                <input
                    type="hidden"
                    name="category"
                    value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>"
                >
            <?php endif; ?>

            <button type="submit">Search</button>

            <a href="index.php" class="button clear">Clear Filters</a>

        </form>

    </div>

    <!-- Category overview -->
    <div class="catalogue">

        <h2>Browse by Category</h2>

        <p>
            Select a category to view the books available in that subject.
        </p>

        <div class="category-grid">

            <a href="index.php" class="category-card">
                <h3>All Categories</h3>
                <div class="category-count">
                    <?= array_sum(array_column($categories, 'book_count')) ?>
                    book(s)
                </div>
            </a>

            <?php foreach ($categories as $category): ?>

                <a
                    href="index.php?category=<?= urlencode($category['category']) ?>"
                    class="category-card"
                >

                    <h3>
                        <?= htmlspecialchars($category['category'], ENT_QUOTES, 'UTF-8') ?>
                    </h3>

                    <div class="category-count">
                        <?= (int)$category['book_count'] ?>
                        book(s)
                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    </div>

    <!-- Catalogue results -->
    <div class="catalogue">

        <h2>Book Catalogue</h2>

        <p>
            <?= count($books) ?> book(s) found.
        </p>

        <?php if ($selectedCategory !== ''): ?>

            <div class="filter-info">
                Showing books in:
                <strong>
                    <?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>
                </strong>
                &nbsp;|&nbsp;
                <a href="index.php">View all categories</a>
            </div>

        <?php endif; ?>

        <?php if ($canManageBooks): ?>

            <p>
                <a href="add.php" class="button add">
                    + Add New Book
                </a>
            </p>

        <?php elseif ($role === 'member'): ?>

            <p>
                <a href="../reservations/add.php" class="button reserve">
                    Reserve an Unavailable Book
                </a>
            </p>

        <?php endif; ?>

        <?php if (count($books) > 0): ?>

            <?php foreach ($groupedBooks as $categoryName => $categoryBooks): ?>

                <div class="category-section">

                    <h3 class="category-heading">
                        <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>
                        (<?= count($categoryBooks) ?> book(s))
                    </h3>

                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>
                                    <th>Book ID</th>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>ISBN</th>
                                    <th>Total Copies</th>
                                    <th>Availability</th>

                                    <?php if ($canManageBooks): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($categoryBooks as $book): ?>

                                    <tr>

                                        <td><?= (int)$book['id'] ?></td>

                                        <td>
                                            <?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($book['author'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($book['isbn'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= (int)$book['quantity'] ?>
                                        </td>

                                        <td>

                                            <?php if ((int)$book['available_quantity'] > 0): ?>

                                                <span class="available">
                                                    <?= (int)$book['available_quantity'] ?> Available
                                                </span>

                                            <?php else: ?>

                                                <span class="unavailable">
                                                    Currently Unavailable
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <?php if ($canManageBooks): ?>

                                            <td class="actions">

                                                <a
                                                    class="edit"
                                                    href="edit.php?id=<?= (int)$book['id'] ?>"
                                                >
                                                    Edit
                                                </a>

                                                |

                                                <a
                                                    class="delete"
                                                    href="delete.php?id=<?= (int)$book['id'] ?>"
                                                >
                                                    Delete
                                                </a>

                                            </td>

                                        <?php endif; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No books found. Try another search or category.
            </p>

        <?php endif; ?>

    </div>

    <?php if ($role === 'member'): ?>

        <a class="back" href="../member/dashboard.php">
            ← Back to Member Dashboard
        </a>

    <?php elseif ($role === 'super_admin'): ?>

        <a class="back" href="../super_admin/dashboard.php">
            ← Back to Super Admin Dashboard
        </a>

    <?php else: ?>

        <a class="back" href="../admin/dashboard.php">
            ← Back to Admin Dashboard
        </a>

    <?php endif; ?>

</div>

</body>
</html>
