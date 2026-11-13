
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only active administrators can manage fine payments.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Verify the current account.
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

// Generate CSRF token.
if (empty($_SESSION['fine_payment_token'])) {
    $_SESSION['fine_payment_token'] = bin2hex(random_bytes(32));
}

$successMessage = $_SESSION['fine_success'] ?? '';
$errorMessage = $_SESSION['fine_error'] ?? '';

unset($_SESSION['fine_success'], $_SESSION['fine_error']);

// Process a fine payment.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['fine_payment_token'],
                $_POST['csrf_token']
            )
        ) {
            throw new Exception("Invalid security token. Please try again.");
        }

        $borrowingId = filter_var(
            $_POST['borrowing_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $amountInput = trim($_POST['amount'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? '';
        $reference = trim($_POST['reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$borrowingId || $borrowingId < 1) {
            throw new Exception("Invalid borrowing record.");
        }

        if (
            $amountInput === '' ||
            !is_numeric($amountInput) ||
            !is_finite((float)$amountInput) ||
            (float)$amountInput <= 0
        ) {
            throw new Exception("Enter a valid payment amount.");
        }

        $amount = round((float)$amountInput, 2);

        if (!in_array($paymentMethod, ['Cash', 'M-Pesa', 'Bank'], true)) {
            throw new Exception("Select a valid payment method.");
        }

        if (strlen($reference) > 100) {
            throw new Exception("Reference must not exceed 100 characters.");
        }

        $pdo->beginTransaction();

        // Lock the borrowing record to prevent conflicting payments.
        $stmt = $pdo->prepare(
            "SELECT id, fine_amount, fine_paid
             FROM borrowings
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$borrowingId]);
        $borrowing = $stmt->fetch();

        if (!$borrowing) {
            throw new Exception("Borrowing record not found.");
        }

        $fineAmount = (float)$borrowing['fine_amount'];
        $finePaid = (float)$borrowing['fine_paid'];
        $balance = round($fineAmount - $finePaid, 2);

        if ($balance <= 0) {
            throw new Exception("This fine has already been fully paid.");
        }

        if ($amount > $balance) {
            throw new Exception(
                "Payment exceeds the outstanding balance of KSh " .
                number_format($balance, 2)
            );
        }

        // Record the payment in the payment history.
        $stmt = $pdo->prepare(
            "INSERT INTO fine_payments
                (borrowing_id, received_by, amount,
                 payment_method, reference, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $borrowingId,
            $currentUser['id'],
            $amount,
            $paymentMethod,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null
        ]);

        // Update the total amount paid.
        $stmt = $pdo->prepare(
            "UPDATE borrowings
             SET fine_paid = fine_paid + ?
             WHERE id = ?"
        );

        $stmt->execute([$amount, $borrowingId]);

        $pdo->commit();

        $_SESSION['fine_success'] =
            "Payment of KSh " . number_format($amount, 2) .
            " recorded successfully.";

        // Rotate token after successful submission.
        $_SESSION['fine_payment_token'] = bin2hex(random_bytes(32));

        header("Location: fines.php");
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Fine payment error: " . $e->getMessage());

        $_SESSION['fine_error'] = $e instanceof PDOException
            ? "Unable to record the payment. Please try again."
            : $e->getMessage();

        header("Location: fines.php");
        exit;
    }
}

// Retrieve outstanding fines.
$stmt = $pdo->query(
    "SELECT
        br.id AS borrowing_id,
        br.fine_amount,
        br.fine_paid,
        br.due_date,
        u.full_name AS member_name,
        b.title AS book_title
     FROM borrowings br
     INNER JOIN users u ON br.member_id = u.id
     INNER JOIN books b ON br.book_id = b.id
     WHERE br.fine_amount > br.fine_paid
     ORDER BY br.due_date ASC"
);

$fines = $stmt->fetchAll();
// Retrieve fine payment history.
$stmt = $pdo->query(
    "SELECT
        fp.id,
        fp.borrowing_id,
        fp.amount,
        fp.payment_method,
        fp.reference,
        fp.paid_at,
        u.full_name AS received_by,
        m.full_name AS member_name,
        b.title AS book_title
     FROM fine_payments fp
     INNER JOIN users u ON fp.received_by = u.id
     INNER JOIN borrowings br ON fp.borrowing_id = br.id
     INNER JOIN users m ON br.member_id = m.id
     INNER JOIN books b ON br.book_id = b.id
     ORDER BY fp.paid_at DESC"
);

$payments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fine Payment Management</title>
</head>
<body>

<h1>Mobilis Library Management System</h1>

<h2>Fine Payment Management</h2>

<p>
    Welcome,
    <?php echo htmlspecialchars($currentUser['full_name']); ?>.
</p>

<p>
    <a href="../admin/dashboard.php">Admin Dashboard</a>
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

<h3>Outstanding Fines</h3>

<?php if (count($fines) > 0): ?>

<table border="1" cellpadding="10" cellspacing="0">

    <thead>
        <tr>
            <th>Borrowing ID</th>
            <th>Member</th>
            <th>Book</th>
            <th>Fine Amount (KSh)</th>
            <th>Fine Paid (KSh)</th>
            <th>Balance (KSh)</th>
            <th>Record Payment</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($fines as $fine): ?>

        <?php
        $balance = max(
            0,
            (float)$fine['fine_amount'] - (float)$fine['fine_paid']
        );
        ?>

        <tr>
            <td><?php echo (int)$fine['borrowing_id']; ?></td>

            <td>
                <?php echo htmlspecialchars($fine['member_name']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($fine['book_title']); ?>
            </td>

            <td>
                <?php echo number_format((float)$fine['fine_amount'], 2); ?>
            </td>

            <td>
                <?php echo number_format((float)$fine['fine_paid'], 2); ?>
            </td>

            <td>
                <?php echo number_format($balance, 2); ?>
            </td>

            <td>
                <form method="POST" action="fines.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['fine_payment_token']); ?>"
                    >

                    <input
                        type="hidden"
                        name="borrowing_id"
                        value="<?php echo (int)$fine['borrowing_id']; ?>"
                    >

                    <label>Amount (KSh)</label><br>

                    <input
                        type="number"
                        name="amount"
                        min="0.01"
                        max="<?php echo htmlspecialchars(number_format($balance, 2, '.', '')); ?>"
                        step="0.01"
                        required
                    >

                    <br><br>

                    <label>Payment Method</label><br>

                    <select name="payment_method" required>
                        <option value="">Select method</option>
                        <option value="Cash">Cash</option>
                        <option value="M-Pesa">M-Pesa</option>
                        <option value="Bank">Bank</option>
                    </select>

                    <br><br>

                    <label>Payment Reference (Optional)</label><br>

                    <input
                        type="text"
                        name="reference"
                        maxlength="100"
                    >

                    <br><br>

                    <label>Notes (Optional)</label><br>

                    <textarea name="notes" rows="2"></textarea>

                    <br><br>

                    <button type="submit">Record Payment</button>

                </form>
            </td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<?php else: ?>

    <p>No outstanding fines found.</p>

<?php endif; ?>

<h3>Fine Payment History</h3>

<?php if (count($payments) > 0): ?>

<table border="1" cellpadding="10" cellspacing="0">
    <thead>
        <tr>
            <th>Payment ID</th>
            <th>Borrowing ID</th>
            <th>Member</th>
            <th>Book</th>
            <th>Amount (KSh)</th>
            <th>Payment Method</th>
            <th>Reference</th>
            <th>Received By</th>
            <th>Payment Date</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($payments as $payment): ?>
        <tr>
            <td><?php echo (int)$payment['id']; ?></td>
            <td><?php echo (int)$payment['borrowing_id']; ?></td>
            <td><?php echo htmlspecialchars($payment['member_name']); ?></td>
            <td><?php echo htmlspecialchars($payment['book_title']); ?></td>
            <td><?php echo number_format((float)$payment['amount'], 2); ?></td>
            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
            <td><?php echo htmlspecialchars($payment['reference'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($payment['received_by']); ?></td>
            <td><?php echo htmlspecialchars($payment['paid_at']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php else: ?>

<p>No fine payments have been recorded yet.</p>

<?php endif; ?>
</body>
</html>
