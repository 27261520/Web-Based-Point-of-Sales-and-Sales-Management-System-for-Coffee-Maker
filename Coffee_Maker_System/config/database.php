<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "coffee_maker";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(40) NOT NULL UNIQUE,
    customer_name VARCHAR(150) NOT NULL DEFAULT 'Walk-in',
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'Cash',
    status VARCHAR(30) NOT NULL DEFAULT 'Preparing',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (created_at),
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    size VARCHAR(30) NOT NULL DEFAULT 'Regular',
    addons TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS size VARCHAR(30) NOT NULL DEFAULT 'Regular' AFTER subtotal");
$conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS addons TEXT NULL AFTER size");
$conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER addons");

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER role");

?>