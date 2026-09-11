<?php
session_start();

if (!isset($_SESSION["admin"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

$conn->query("CREATE TABLE IF NOT EXISTS store_settings (
    id INT PRIMARY KEY,
    brand_name VARCHAR(150) NOT NULL DEFAULT 'COFFEE MAKER',
    store_branch VARCHAR(150) NOT NULL DEFAULT '',
    contact_number VARCHAR(80) NOT NULL DEFAULT '',
    email_address VARCHAR(150) NOT NULL DEFAULT '',
    full_address VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    postal_code VARCHAR(30) NOT NULL DEFAULT '',
    receipt_header VARCHAR(255) NOT NULL DEFAULT 'Thank you for brewing with us!',
    receipt_footer VARCHAR(255) NOT NULL DEFAULT 'Thank you for your order!',
    social_handle VARCHAR(100) NOT NULL DEFAULT 'coffeemaker',
    show_order_details TINYINT(1) NOT NULL DEFAULT 1,
    cash_enabled TINYINT(1) NOT NULL DEFAULT 1,
    gcash_enabled TINYINT(1) NOT NULL DEFAULT 1,
    maya_enabled TINYINT(1) NOT NULL DEFAULT 0,
    card_enabled TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("INSERT IGNORE INTO store_settings (id) VALUES (1)");

$requested_tab = $_GET["tab"] ?? "store";
$active_tab = in_array($requested_tab, ["store", "receipts", "payments"], true) ? $requested_tab : "store";
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $active_tab = $_POST["tab"] ?? "store";
    $brand_name = trim($_POST["brand_name"] ?? "COFFEE MAKER");
    $store_branch = trim($_POST["store_branch"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");
    $email_address = trim($_POST["email_address"] ?? "");
    $full_address = trim($_POST["full_address"] ?? "");
    $city = trim($_POST["city"] ?? "");
    $postal_code = trim($_POST["postal_code"] ?? "");
    $receipt_header = trim($_POST["receipt_header"] ?? "");
    $receipt_footer = trim($_POST["receipt_footer"] ?? "");
    $social_handle = trim($_POST["social_handle"] ?? "");
    $show_order_details = isset($_POST["show_order_details"]) ? 1 : 0;
    $cash_enabled = isset($_POST["cash_enabled"]) ? 1 : 0;
    $gcash_enabled = isset($_POST["gcash_enabled"]) ? 1 : 0;
    $maya_enabled = isset($_POST["maya_enabled"]) ? 1 : 0;
    $card_enabled = isset($_POST["card_enabled"]) ? 1 : 0;

    $statement = $conn->prepare("UPDATE store_settings SET brand_name = ?, store_branch = ?, contact_number = ?, email_address = ?, full_address = ?, city = ?, postal_code = ?, receipt_header = ?, receipt_footer = ?, social_handle = ?, show_order_details = ?, cash_enabled = ?, gcash_enabled = ?, maya_enabled = ?, card_enabled = ? WHERE id = 1");
    $statement->bind_param("ssssssssssiiiii", $brand_name, $store_branch, $contact_number, $email_address, $full_address, $city, $postal_code, $receipt_header, $receipt_footer, $social_handle, $show_order_details, $cash_enabled, $gcash_enabled, $maya_enabled, $card_enabled);
    $message = $statement->execute() ? "Settings saved successfully." : "Unable to save settings.";
    $statement->close();
}

$settings = $conn->query("SELECT * FROM store_settings WHERE id = 1")->fetch_assoc();
$admin_name = $_SESSION["admin"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Coffee Maker</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #f7f7f7; color: #2b1610; font-family: Arial, Helvetica, sans-serif; }
.app-shell { min-height: 100vh; display: flex; }
.content { margin-left: 245px; width: calc(100% - 245px); padding: 34px 38px 60px; }
.settings-header { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 25px; }
h1 { font-size: 25px; } .intro { margin-top: 6px; color: #8f8986; font-size: 12px; }
.header-actions { display: flex; gap: 8px; } .button { border: 1px solid #cdbbb3; border-radius: 6px; padding: 10px 15px; background: #fff; color: #70483a; font-size: 10px; cursor: pointer; text-decoration: none; } .button.primary { border-color: #70402e; background: #70402e; color: #fff; font-weight: 700; }
.tabs { display: flex; gap: 20px; border-bottom: 1px solid #e4dcd8; margin-bottom: 25px; } .tab { padding: 0 0 12px; color: #766e6a; font-size: 10px; font-weight: 700; text-decoration: none; } .tab.active { color: #4d3026; border-bottom: 2px solid #70402e; }
.panel-grid { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 20px; align-items: start; } .panel { padding: 20px; border: 1px solid #eee5e1; border-radius: 8px; background: #fff; } .panel + .panel { margin-top: 18px; } .panel h2 { font-size: 15px; font-weight: 700; } .panel-description { margin-top: 5px; color: #8f8986; font-size: 10px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 15px; margin-top: 18px; } label { display: block; margin: 0 0 7px; color: #61443a; font-size: 9px; font-weight: 700; } .field { margin-bottom: 15px; } input, textarea { width: 100%; border: 1px solid #ded2cd; border-radius: 5px; padding: 10px; color: #403532; font: inherit; font-size: 11px; outline: none; } input { height: 36px; } textarea { min-height: 78px; resize: vertical; } input:focus, textarea:focus { border-color: #74473b; }
.full { grid-column: 1 / -1; } .switch-row { display: flex; align-items: center; justify-content: space-between; min-height: 42px; padding: 10px; border: 1px solid #eee5e1; border-radius: 6px; color: #5b4b45; font-size: 10px; } .switch-row input { width: auto; height: auto; accent-color: #70402e; }
.preview { background: #f3f0ed; } .receipt-preview { width: min(100%, 235px); min-height: 325px; margin: 15px auto 0; padding: 18px 15px; background: #fff; color: #5e514c; box-shadow: 0 2px 9px rgba(43,22,16,.06); font-size: 8px; } .receipt-preview h3 { color: #4d3026; font-size: 13px; text-align: center; } .receipt-preview hr { margin: 12px 0; border: 0; border-top: 1px dashed #ded2cd; } .receipt-preview p { margin: 6px 0; } .receipt-preview .preview-addons { color: #70402e; font-weight: 700; }
.payment-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 18px; } .payment-option { display: flex; align-items: center; gap: 9px; min-height: 58px; padding: 10px; border: 1px solid #eee5e1; border-radius: 6px; color: #4f403a; font-size: 10px; } .payment-option input { width: auto; height: auto; accent-color: #70402e; } .payment-option small { display: block; margin-top: 3px; color: #8f8986; font-size: 8px; }
.notice { margin-bottom: 17px; padding: 11px 13px; border-radius: 5px; background: #e7f3e9; color: #26733b; font-size: 11px; }
.actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 8px; padding-top: 17px; border-top: 1px solid #eee5e1; }
@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .panel-grid { grid-template-columns: 1fr; } }
@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; } .content { margin: 0; width: 100%; padding: 22px 16px; } .settings-header { align-items: flex-start; flex-direction: column; } .form-grid, .payment-grid { grid-template-columns: 1fr; } }
</style>
<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
<aside class="sidebar"><div><div class="brand">COFFEE MAKER</div><nav class="nav"><a class="nav-item" href="dashboard.php">DASHBOARD</a><a class="nav-item" href="pos.php">POS</a><a class="nav-item" href="orders.php">ORDERS</a><a class="nav-item" href="products.php">PRODUCTS</a><a class="nav-item" href="users.php">USERS</a></nav></div><div class="sidebar-footer"><div class="user"><div class="avatar">♙</div><span><?= htmlspecialchars($admin_name) ?></span></div><a class="settings" href="settings.php" aria-label="Settings" title="Settings">⚙</a></div></aside>
<section class="content">
<header class="settings-header"><div><h1>Settings</h1><p class="intro">Manage your store details, receipt preferences, and payment methods.</p></div><div class="header-actions"><a class="button" href="settings.php?tab=<?= htmlspecialchars($active_tab) ?>">Cancel</a><button class="button primary" form="settings-form" type="submit">Save Changes</button></div></header>
<nav class="tabs"><a class="tab <?= $active_tab === "store" ? "active" : "" ?>" href="settings.php?tab=store">Store Info</a><a class="tab <?= $active_tab === "receipts" ? "active" : "" ?>" href="settings.php?tab=receipts">Receipts</a><a class="tab <?= $active_tab === "payments" ? "active" : "" ?>" href="settings.php?tab=payments">Payments</a></nav>
<?php if ($message !== ""): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form id="settings-form" method="post">
<input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
<?php if ($active_tab === "store"): ?>
<div class="panel-grid"><div><section class="panel"><h2>Store Details</h2><p class="panel-description">Basic identity and contact information.</p><div class="form-grid"><div class="field full"><label for="brand-name">BRAND NAME</label><input id="brand-name" name="brand_name" value="<?= htmlspecialchars($settings["brand_name"]) ?>"></div><div class="field"><label for="store-branch">STORE BRANCH</label><input id="store-branch" name="store_branch" value="<?= htmlspecialchars($settings["store_branch"]) ?>"></div><div class="field"><label for="contact-number">CONTACT NUMBER</label><input id="contact-number" name="contact_number" value="<?= htmlspecialchars($settings["contact_number"]) ?>"></div><div class="field full"><label for="email-address">EMAIL ADDRESS</label><input id="email-address" name="email_address" value="<?= htmlspecialchars($settings["email_address"]) ?>"></div></div></section><section class="panel"><h2>Location</h2><p class="panel-description">Physical address of this branch.</p><div class="form-grid"><div class="field full"><label for="full-address">FULL ADDRESS</label><input id="full-address" name="full_address" value="<?= htmlspecialchars($settings["full_address"]) ?>"></div><div class="field"><label for="city">CITY</label><input id="city" name="city" value="<?= htmlspecialchars($settings["city"]) ?>"></div><div class="field"><label for="postal-code">POSTAL CODE</label><input id="postal-code" name="postal_code" value="<?= htmlspecialchars($settings["postal_code"]) ?>"></div></div></section></div><aside class="panel"><h2>Business Hours</h2><p class="panel-description">Operating hours can be configured here.</p><div class="receipt-preview"><h3><?= htmlspecialchars($settings["brand_name"]) ?></h3><hr><p><?= htmlspecialchars($settings["store_branch"] ?: "Store branch") ?></p><p><?= htmlspecialchars($settings["contact_number"] ?: "Contact number") ?></p><p><?= htmlspecialchars($settings["full_address"] ?: "Store address") ?></p></div></aside></div>
<?php elseif ($active_tab === "receipts"): ?>
<div class="panel-grid"><div><section class="panel"><h2>Receipt Settings</h2><p class="panel-description">Customize the appearance and details of customer receipts.</p><div class="form-grid"><div class="field full"><label for="receipt-header">HEADER MESSAGE</label><input id="receipt-header" name="receipt_header" value="<?= htmlspecialchars($settings["receipt_header"]) ?>"></div><div class="field full"><label for="receipt-footer">FOOTER MESSAGE</label><textarea id="receipt-footer" name="receipt_footer"><?= htmlspecialchars($settings["receipt_footer"]) ?></textarea></div><div class="field full"><label for="social-handle">SOCIAL HANDLE</label><input id="social-handle" name="social_handle" value="<?= htmlspecialchars($settings["social_handle"]) ?>"></div><div class="field full"><div class="switch-row"><span>Show order date and time on receipts</span><input type="checkbox" name="show_order_details" <?= $settings["show_order_details"] ? "checked" : "" ?>></div></div></div></section></div><aside class="panel preview"><h2>Live Preview</h2><div class="receipt-preview"><h3><?= htmlspecialchars($settings["brand_name"]) ?></h3><hr><p><?= htmlspecialchars($settings["receipt_header"]) ?></p><p><?= date("n/j/Y, g:i A") ?></p><p>Order #: ORD-000000</p><hr><p>1x TARO (Regular)</p><p class="preview-addons">ADD-ONS: PEARL</p><p style="margin-top: 24px;">TOTAL <span style="float: right;">₱108.00</span></p><hr><p><?= htmlspecialchars($settings["receipt_footer"]) ?></p></div></aside></div>
<?php else: ?>
<section class="panel"><h2>Accepted Methods</h2><p class="panel-description">Toggle the payment options available at checkout.</p><div class="payment-grid"><label class="payment-option"><input type="checkbox" name="cash_enabled" <?= $settings["cash_enabled"] ? "checked" : "" ?>><span>Cash<small>Physical currency</small></span></label><label class="payment-option"><input type="checkbox" name="gcash_enabled" <?= $settings["gcash_enabled"] ? "checked" : "" ?>><span>GCash<small>E-Wallet (PH)</small></span></label><label class="payment-option"><input type="checkbox" name="maya_enabled" <?= $settings["maya_enabled"] ? "checked" : "" ?>><span>Maya<small>E-Wallet (PH)</small></span></label><label class="payment-option"><input type="checkbox" name="card_enabled" <?= $settings["card_enabled"] ? "checked" : "" ?>><span>Credit / Debit Card<small>Visa, Mastercard</small></span></label></div></section>
<?php endif; ?>
</form>
</section>
</main>
</body>
</html>
