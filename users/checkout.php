<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') { 
    header("Location: Studlogin.php"); 
    exit(); 
}

// Include database connection - updated path to match your structure
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

$user_id = $_SESSION['user_id'];
$success = $error = '';

// Get user data
$user_sql = "SELECT name, email, role, profile_image FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
    $userName = $user['name'];
    $firstLetter = strtoupper(substr($userName, 0, 1));
} else {
    // If user not found in database, log them out
    session_destroy();
    header("Location: Studlogin.php");
    exit();
}
$stmt->close();

// Function to format store name for URLs
function formatStoreName($storeName) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $storeName);
}

// Function to get proper image URL
function getProductImageUrl($productImage, $storeName) {
    if (empty($productImage)) {
        return null;
    }
    
    // If it's already a full path, return as is
    if (strpos($productImage, 'uploads/') === 0) {
        return '../' . $productImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return '../uploads/menus/' . $formattedStoreName . '/' . $productImage;
}

// Function to get proper store image URL
function getStoreImageUrl($storeImage, $storeName) {
    if (empty($storeImage)) {
        return null;
    }
    
    // If it's already a full path, return as is
    if (strpos($storeImage, 'uploads/') === 0) {
        return '../' . $storeImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return '../uploads/stores/' . $formattedStoreName . '.jpg';
}

// Fetch cart items for the current user with restaurant information
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$cart_item_count = 0;

$cart_query = "SELECT c.*, r.name as restaurant_name 
               FROM cart c 
               JOIN restaurants r ON c.restaurant_id = r.id 
               WHERE c.user_id = ?";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_result = $stmt->get_result();

if ($cart_result) {
    while ($row = $cart_result->fetch_assoc()) {
        // Fix image URLs for cart items
        if (!empty($row['product_image_url'])) {
            $row['product_image_url'] = getProductImageUrl($row['product_image_url'], $row['restaurant_name']);
        }
        $cart_items[] = $row;
        $cart_total += $row['price'] * $row['quantity'];
        $cart_count += $row['quantity'];
        $cart_item_count++;
    }
}
$stmt->close();

// Handle AJAX requests for cart operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }
    
    switch ($_POST['action']) {
        case 'add_to_cart':
            if (isset($_POST['product_id'], $_POST['quantity'])) {
                $product_id = intval($_POST['product_id']);
                $quantity = intval($_POST['quantity']);
                
                // Get product details with restaurant information
                $product_query = "SELECT p.*, r.id as restaurant_id, r.name as restaurant_name 
                                 FROM products p 
                                 JOIN restaurants r ON p.restaurant_id = r.id 
                                 WHERE p.id = ?";
                $stmt = $conn->prepare($product_query);
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $product_result = $stmt->get_result();
                
                if ($product_result->num_rows > 0) {
                    $product = $product_result->fetch_assoc();
                    
                    // Store original image URL without modification for database
                    $product_image = $product['product_image_url'];
                    
                    // Check if item already exists in cart
                    $check_query = "SELECT * FROM cart WHERE user_id = ? AND product_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("ii", $user_id, $product_id);
                    $check_stmt->execute();
                    $existing_item = $check_stmt->get_result();
                    
                    if ($existing_item->num_rows > 0) {
                        // Update quantity
                        $update_query = "UPDATE cart SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND product_id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("iii", $quantity, $user_id, $product_id);
                        $update_stmt->execute();
                        $response['success'] = $update_stmt->affected_rows > 0;
                        $response['message'] = $response['success'] ? 'Quantity updated in cart' : 'Failed to update cart';
                        $update_stmt->close();
                    } else {
                        // Insert new item with all required fields
                        $insert_query = "INSERT INTO cart (user_id, product_id, product_name, product_image_url, price, quantity, restaurant_id, restaurant_name) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_query);
                        
                        if (!$insert_stmt) {
                            $response['message'] = 'Database error: ' . $conn->error;
                            $response['success'] = false;
                        } else {
                            $insert_stmt->bind_param("iissdiss", 
                                $user_id, 
                                $product_id, 
                                $product['name'], 
                                $product_image,
                                $product['price'], 
                                $quantity, 
                                $product['restaurant_id'],
                                $product['restaurant_name']
                            );
                            
                            if ($insert_stmt->execute()) {
                                $response['success'] = true;
                                $response['message'] = 'Item added to cart';
                            } else {
                                $response['success'] = false;
                                $response['message'] = 'Failed to add item: ' . $insert_stmt->error;
                            }
                            $insert_stmt->close();
                        }
                    }
                    
                    $check_stmt->close();
                    
                    // Return updated cart data
                    if ($response['success']) {
                        $response['cart_data'] = getCartData($conn, $user_id);
                    }
                } else {
                    $response['message'] = 'Product not found';
                    $response['success'] = false;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Missing required parameters';
                $response['success'] = false;
            }
            break;
            
        case 'update_cart_quantity':
            if (isset($_POST['cart_id'], $_POST['quantity'])) {
                $cart_id = intval($_POST['cart_id']);
                $quantity = intval($_POST['quantity']);
                
                if ($quantity <= 0) {
                    // Remove item if quantity is 0 or less
                    $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $cart_id, $user_id);
                    $delete_stmt->execute();
                    $response['success'] = $delete_stmt->affected_rows > 0;
                    $delete_stmt->close();
                    $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                } else {
                    // Update quantity
                    $update_query = "UPDATE cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("iii", $quantity, $cart_id, $user_id);
                    $update_stmt->execute();
                    $response['success'] = $update_stmt->affected_rows > 0;
                    $update_stmt->close();
                    $response['message'] = $response['success'] ? 'Cart updated' : 'Failed to update cart';
                }
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'remove_from_cart':
            if (isset($_POST['cart_id'])) {
                $cart_id = intval($_POST['cart_id']);
                $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("ii", $cart_id, $user_id);
                $delete_stmt->execute();
                $response['success'] = $delete_stmt->affected_rows > 0;
                $delete_stmt->close();
                $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'get_cart_count':
            // Count distinct items (not quantities)
            $count_query = "SELECT COUNT(*) as item_count FROM cart WHERE user_id = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_data = $count_result->fetch_assoc();
            $response['success'] = true;
            $response['count'] = $count_data['item_count'] ?? 0;
            $count_stmt->close();
            break;
            
        case 'get_cart_data':
            $response['success'] = true;
            $response['cart_data'] = getCartData($conn, $user_id);
            break;
    }
    
    echo json_encode($response);
    exit();
}

// Function to get cart data
function getCartData($conn, $user_id) {
    $cart_data = [
        'items' => [],
        'total' => 0,
        'count' => 0,
        'item_count' => 0
    ];
    
    $cart_query = "SELECT c.*, r.name as restaurant_name 
                   FROM cart c 
                   JOIN restaurants r ON c.restaurant_id = r.id 
                   WHERE c.user_id = ?";
    $stmt = $conn->prepare($cart_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_result = $stmt->get_result();
    
    if ($cart_result) {
        while ($row = $cart_result->fetch_assoc()) {
            // Fix image URLs for cart items
            if (!empty($row['product_image_url'])) {
                $row['product_image_url'] = getProductImageUrl($row['product_image_url'], $row['restaurant_name']);
            }
            
            $cart_data['items'][] = $row;
            $cart_data['total'] += $row['price'] * $row['quantity'];
            $cart_data['count'] += $row['quantity'];
            $cart_data['item_count']++;
        }
    }
    $stmt->close();
    
    return $cart_data;
}

// Process order if form submitted - MODIFIED TO CREATE SEPARATE ORDERS PER RESTAURANT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // Get cart items from database
    $cart_query = "SELECT c.*, r.name as restaurant_name, r.id as restaurant_id 
                   FROM cart c 
                   JOIN restaurants r ON c.restaurant_id = r.id 
                   WHERE c.user_id = ?";
    $stmt = $conn->prepare($cart_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_result = $stmt->get_result();
    $cart_items = [];
    
    if ($cart_result) {
        while ($row = $cart_result->fetch_assoc()) {
            $cart_items[] = $row;
        }
    }
    $stmt->close();
    
    if (!empty($cart_items)) {
        // Group cart items by restaurant_id
        $items_by_restaurant = [];
        foreach ($cart_items as $item) {
            $restaurant_id = $item['restaurant_id'];
            if (!isset($items_by_restaurant[$restaurant_id])) {
                $items_by_restaurant[$restaurant_id] = [];
            }
            $items_by_restaurant[$restaurant_id][] = $item;
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $payment_method = $_POST['payment_method'];
            $service_fee = 10.00;
            $all_order_numbers = [];
            $grand_total = 0;
            
            // Create separate orders for each restaurant
            foreach ($items_by_restaurant as $restaurant_id => $restaurant_items) {
                // Generate unique order number for this restaurant
                $order_number = 'ORD' . date('YmdHis') . rand(1000, 9999);
                $all_order_numbers[] = $order_number;
                
                // Calculate total for this restaurant's items
                $restaurant_total = 0;
                foreach ($restaurant_items as $item) {
                    $restaurant_total += floatval($item['price']) * intval($item['quantity']);
                }
                
                // Add service fee - Option: Only add to first restaurant
                // If you want service fee per restaurant, keep this line as is
                // If you want ONE service fee total, use the commented code below
                $restaurant_total += $service_fee;
                
                // OPTIONAL: Apply service fee only once (to first restaurant)
                // $is_first_restaurant = ($restaurant_id === array_key_first($items_by_restaurant));
                // $restaurant_total += $is_first_restaurant ? $service_fee : 0;
                
                $grand_total += $restaurant_total;
                
                // Get the first product_id for this restaurant (for the order record)
                $first_product_id = $restaurant_items[0]['product_id'];
                
                // Insert into orders table
                $order_sql = "INSERT INTO orders (user_id, restaurant_id, product_id, order_number, total_amount, status, order_type, payment_id) 
                             VALUES (?, ?, ?, ?, ?, 'preparing', 'pickup', NULL)";
                $stmt = $conn->prepare($order_sql);
                $stmt->bind_param("iiisd", $user_id, $restaurant_id, $first_product_id, $order_number, $restaurant_total);
                
                if ($stmt->execute()) {
                    $order_id = $stmt->insert_id;
                    $stmt->close();
                    
                    // Insert order items for this restaurant
                    $order_items_sql = "INSERT INTO order_items (order_id, product_id, item_name, product_image_url, quantity, unit_price, total_price) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_items = $conn->prepare($order_items_sql);
                    
                    foreach ($restaurant_items as $item) {
                        $item_total = floatval($item['price']) * intval($item['quantity']);
                        $stmt_items->bind_param("iissidd", $order_id, $item['product_id'], $item['product_name'], $item['product_image_url'], $item['quantity'], $item['price'], $item_total);
                        $stmt_items->execute();
                    }
                    $stmt_items->close();
                    
                    // Insert payment record for this order
                    $payment_status = ($payment_method === 'cash') ? 'Pending' : 'Completed';
                    $payment_method_enum = ($payment_method === 'cash') ? 'Cash' : (($payment_method === 'gcash') ? 'Online' : 'Cash');
                    
                    $payment_sql = "INSERT INTO payments (order_id, amount, payment_method, payment_status) 
                                   VALUES (?, ?, ?, ?)";
                    $stmt_payment = $conn->prepare($payment_sql);
                    $stmt_payment->bind_param("idss", $order_id, $restaurant_total, $payment_method_enum, $payment_status);
                    $stmt_payment->execute();
                    $payment_id = $stmt_payment->insert_id;
                    $stmt_payment->close();
                    
                    // Update order with payment_id
                    $update_order_sql = "UPDATE orders SET payment_id = ? WHERE id = ?";
                    $stmt_update = $conn->prepare($update_order_sql);
                    $stmt_update->bind_param("ii", $payment_id, $order_id);
                    $stmt_update->execute();
                    $stmt_update->close();
                    
                } else {
                    throw new Exception("Failed to create order for restaurant ID: " . $restaurant_id);
                }
            }
            
            // Clear cart after all orders are successful
            $clear_cart_sql = "DELETE FROM cart WHERE user_id = ?";
            $clear_stmt = $conn->prepare($clear_cart_sql);
            $clear_stmt->bind_param("i", $user_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            // Set session variables for success message
            $_SESSION['order_success'] = true;
            $_SESSION['order_ids'] = implode(', ', $all_order_numbers); // Multiple order numbers
            $_SESSION['order_total'] = $grand_total;
            $_SESSION['payment_method'] = $payment_method;
            $_SESSION['order_count'] = count($items_by_restaurant); // Number of separate orders
            
            // Redirect to my_orders with success message
            header("Location: my_orders.php");
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Failed to place order: " . $e->getMessage();
        }
    } else {
        $error_message = "Your cart is empty. Please add items before placing an order.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - UMAK Foodhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body {
            box-sizing: border-box;
        }
        
        .sidebar-transition {
            transition: transform 0.3s ease-in-out;
        }
        
        .cart-transition {
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        
        .overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }
        
        .umak-blue {
            background-color: #1e3a8a;
        }
        
        .umak-yellow {
            background-color: #fbbf24;
        }
        
        .umak-blue-text {
            color: #1e3a8a;
        }
        
        .umak-yellow-text {
            color: #fbbf24;
        }
        
        .umak-blue-border {
            border-color: #1e3a8a;
        }
        
        .umak-yellow-border {
            border-color: #fbbf24;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        /* Cart Badge */
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #fbbf24;
            color: #1e3a8a;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            font-weight: bold;
            display: none;
            align-items: center;
            justify-content: center;
        }

        /* Checkout Styles */
        .checkout-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
        }

        .order-summary {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }

        .payment-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .section-header {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .order-items {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 30px;
        }

        .order-item {
            display: flex;
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .order-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .item-image {
            width: 80px;
            height: 80px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-details {
            flex: 1;
        }

        .item-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .item-description {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .item-price-quantity {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .item-quantity {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
        }

        .quantity-btn {
            background: none;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #4299e1;
            transition: all 0.3s ease;
        }

        .quantity-btn:hover {
            background: #4299e1;
            color: white;
        }

        .quantity-btn:disabled {
            color: #cbd5e0;
            cursor: not-allowed;
        }

        .quantity-btn:disabled:hover {
            background: none;
            color: #cbd5e0;
        }

        .quantity-value {
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .item-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: #4299e1;
        }

        .item-total::before {
            content: '₱';
        }

        .empty-order {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-order i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #cbd5e0;
        }

        .empty-order h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: #4a5568;
        }

        /* Payment Section Styles */
        .payment-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .payment-option.selected {
            border-color: #4299e1;
            background-color: #f0f9ff;
        }

        .payment-option:hover {
            border-color: #cbd5e0;
        }

        .payment-radio input[type="radio"] {
            margin: 0;
        }

        .payment-details {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .payment-logo {
            width: 40px;
            height: 40px;
            background: #4299e1;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .gcash-logo {
            background: #0066a4;
        }

        .payment-name {
            font-weight: 600;
            color: #2d3748;
        }

        .payment-summary {
            border-top: 2px solid #e2e8f0;
            padding-top: 20px;
            margin-top: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 1rem;
        }

        .summary-label {
            color: #718096;
        }

        .summary-value {
            font-weight: 500;
            color: #2d3748;
        }

        .summary-total {
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            margin-top: 12px;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .summary-total .summary-label {
            color: #2d3748;
        }

        .summary-total .summary-value {
            color: #4299e1;
        }

        .checkout-btn {
            width: 100%;
            background: #48bb78;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .checkout-btn:hover {
            background: #38a169;
            transform: translateY(-2px);
        }

        .checkout-btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }

        .remove-item-btn {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 10px;
            transition: all 0.3s ease;
        }

        .remove-item-btn:hover {
            background: #c53030;
        }

        /* Authentication Popup Styles */
        .auth-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .auth-popup-overlay.active {
            display: flex;
        }

        .auth-popup-container {
            background: white;
            border-radius: 15px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .auth-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
        }

        .auth-popup-header h3 {
            margin: 0;
            color: #2d3748;
            font-size: 1.3rem;
        }

        .auth-popup-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #718096;
            padding: 5px;
        }

        .auth-popup-close:hover {
            color: #2d3748;
        }

        .auth-popup-content {
            padding: 25px;
            text-align: center;
        }

        .auth-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .auth-icon.success {
            color: #48bb78;
        }

        .auth-popup-content h4 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 1.4rem;
        }

        .auth-popup-content p {
            color: #718096;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .order-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-label {
            color: #718096;
            font-weight: 500;
        }

        .detail-value {
            color: #2d3748;
            font-weight: 600;
        }

        .auth-buttons {
            margin-bottom: 20px;
        }

        .auth-confirm-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .auth-confirm-btn:hover {
            background: #3182ce;
        }

        .auth-note {
            background: #f0f9ff;
            border: 1px solid #bee3f8;
            border-radius: 8px;
            padding: 12px;
            color: #2b6cb0;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .auth-note i {
            font-size: 1rem;
        }

        /* Mobile Search */
        .search-dropdown {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .search-input {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-left: none;
        }

        .cart-item-image {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 8px;
        }

        .cart-item-image-fallback {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        @media (max-width: 768px) {
            .checkout-container {
                grid-template-columns: 1fr;
            }
            
            .order-item {
                flex-direction: column;
                text-align: center;
            }
            
            .item-price-quantity {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-full">
<!-- Overlay -->
<div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden overlay"></div>

<!-- Top Navigation -->
<nav class="umak-blue shadow-lg fixed top-0 left-0 right-0 z-30">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Left: Logo and Hamburger -->
            <div class="flex items-center space-x-4">
                <button id="hamburger" class="p-2 rounded-md text-white hover:text-yellow-300 hover:bg-blue-800 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 rounded-lg overflow-hidden">
                        <img src="FoodHub_Title.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                            <span class="umak-blue-text font-bold text-sm">F</span>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <span class="text-xl font-bold text-white">UMAK</span>
                        <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                    </div>
                </div>
            </div>

            <!-- Center: Search Bar -->
            <div class="hidden md:flex items-center flex-1 max-w-2xl mx-8">
                <div class="flex items-center w-full bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                    <select class="px-4 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer">
                        <option value="all">All</option>
                        <option value="foods">Foods</option>
                        <option value="shops">Stores</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300"></div>
                    <input type="text" placeholder="What are you craving today?" class="flex-1 px-4 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                    <button class="px-6 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Right: Cart and Profile -->
            <div class="flex items-center space-x-4">
                <button id="cart-btn" class="relative p-2 text-white hover:text-yellow-300 hover:bg-blue-800 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <span class="cart-badge" style="<?php echo $cart_item_count > 0 ? 'display: flex;' : ''; ?>"><?php echo $cart_item_count; ?></span>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 umak-yellow rounded-full flex items-center justify-center">
                        <span class="umak-blue-text font-bold text-sm"><?php echo $firstLetter; ?></span>
                    </div>
                    <span id="user-name" class="hidden sm:block text-sm font-medium text-white"><?php echo htmlspecialchars($userName); ?></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-white shadow-lg z-50 transform -translate-x-full sidebar-transition">
    <div class="p-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-lg overflow-hidden">
                    <img src="FoodHub_Title.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                        <span class="umak-blue-text font-bold text-sm">F</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <span class="text-xl font-bold umak-blue-text">UMAK</span>
                    <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                </div>
            </div>
            <button id="close-sidebar" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    <nav class="p-4">
        <ul class="space-y-2">
            <li><a href="UserInt.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="currentColor" viewbox="0 0 20 20">
                    <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                </svg>
                <span>Home</span>
            </a></li>
            <li><a href="browse_menu.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"></path>
                </svg>
                <span>Browse Menu</span>
            </a></li>
            <li><a href="favorites.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                </svg>
                <span>Favorites</span>
            </a></li>
            <li><a href="my_orders.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                </svg>
                <span>My Orders</span>
            </a></li>
            <li><a href="profile.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <span>Profile</span>
            </a></li>
            <li><a href="../users/logout.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>Logout</span>
            </a></li>
        </ul>
    </nav>
</aside>

<!-- Cart Sidebar -->
<div id="cart-sidebar" class="fixed right-0 top-0 h-full w-80 bg-white shadow-lg z-50 transform translate-x-full cart-transition flex flex-col">
    <div class="p-4 border-b border-gray-200 flex-shrink-0">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Your Cart</h2>
            <button id="close-cart" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Scrollable cart items area -->
    <div class="flex-1 overflow-y-auto">
        <div id="cart-empty" class="text-center py-12 <?php echo !empty($cart_items) ? 'hidden' : ''; ?>">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
            </svg>
            <p class="text-gray-500 mb-2">Your cart is empty</p>
            <p class="text-sm text-gray-400">Add some delicious items to get started!</p>
        </div>
        <div id="cart-items-container" class="p-4 <?php echo empty($cart_items) ? 'hidden' : ''; ?>">
            <?php foreach ($cart_items as $item): ?>
            <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="<?php echo $item['id']; ?>">
                <div class="flex items-center space-x-3">
                    <div class="flex-shrink-0">
                        <?php if (!empty($item['product_image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($item['product_image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                 class="cart-item-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php endif; ?>
                        <div class="cart-item-image-fallback <?php echo !empty($item['product_image_url']) ? 'hidden' : ''; ?>">
                            <span>🍽️</span>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></h4>
                        <p class="text-sm text-gray-600">₱<?php echo number_format($item['price'], 2); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($item['restaurant_name']); ?></p>
                        <div class="flex items-center justify-between mt-2">
                            <div class="flex items-center space-x-2">
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, -1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">-</button>
                                <span class="font-medium cart-quantity"><?php echo $item['quantity']; ?></span>
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">+</button>
                            </div>
                            <button onclick="removeFromCart(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700 text-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Fixed footer that stays at bottom -->
    <div class="border-t border-gray-200 p-4 bg-white flex-shrink-0">
        <div class="flex justify-between items-center mb-4">
            <span class="font-semibold text-gray-900">Total:</span>
            <span id="cart-total" class="font-bold text-lg umak-blue-text">₱<?php echo number_format($cart_total, 2); ?></span>
        </div>
        <a href="checkout.php" id="proceed-to-checkout" class="w-full umak-yellow umak-blue-text py-3 rounded-lg font-semibold hover:bg-yellow-300 transition-all duration-300 text-center block <?php echo empty($cart_items) ? 'opacity-50 pointer-events-none' : ''; ?>">
            Proceed to Checkout
        </a>
    </div>
</div>

<!-- Main Content -->
<main class="pt-16">
    <div class="p-6">
        <!-- Mobile Search -->
        <div class="md:hidden mb-6">
            <div class="flex items-center bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                <select class="px-3 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer flex-shrink-0">
                    <option value="all">All</option>
                    <option value="foods">Foods</option>
                    <option value="shops">Stores</option>
                </select>
                <div class="w-px h-6 bg-gray-300 flex-shrink-0"></div>
                <input type="text" placeholder="Search food or stores..." class="flex-1 px-3 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                <button class="px-4 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Personalized Greeting Section -->
        <section class="mb-8">
            <div class="umak-blue rounded-2xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold mb-2">Checkout, <span id="greeting-name"><?php echo htmlspecialchars($userName); ?></span>! 🛒</h1>
                        <p class="text-lg opacity-90">Review your order before placing it</p>
                    </div>
                    <div class="hidden md:block">
                        <div class="w-16 h-16 umak-yellow rounded-full flex items-center justify-center">
                            <span class="text-3xl">📦</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Checkout Content -->
        <div class="checkout-container">
            <!-- Order Summary -->
            <div class="order-summary">
                <div class="section-header">
                    Order Summary (<span id="itemCount"><?php echo count($cart_items); ?></span> items)
                </div>
                <div class="order-items" id="orderItems">
                    <?php if (empty($cart_items)): ?>
                    <div class="empty-order" id="emptyOrder">
                        <i class="fas fa-shopping-cart"></i>
                        <h3>Your order is empty</h3>
                        <p>Add items from your favorites or menu to get started!</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($cart_items as $item): ?>
                        <div class="order-item">
                            <div class="item-image">
                                <?php if (!empty($item['product_image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['product_image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                         class="w-full h-full object-cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>
                                <div class="w-full h-full bg-gray-200 flex items-center justify-center <?php echo !empty($item['product_image_url']) ? 'hidden' : ''; ?>">
                                    <span class="text-gray-400 text-2xl">🍽️</span>
                                </div>
                            </div>
                            <div class="item-details">
                                <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                <div class="item-description">From <?php echo htmlspecialchars($item['restaurant_name']); ?></div>
                                <div class="item-price-quantity">
                                    <div class="item-quantity">
                                        <span>Quantity:</span>
                                        <div class="quantity-controls">
                                            <button class="quantity-btn" onclick="updateCartQuantity(<?php echo $item['id']; ?>, -1)" <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <span class="quantity-value"><?php echo $item['quantity']; ?></span>
                                            <button class="quantity-btn" onclick="updateCartQuantity(<?php echo $item['id']; ?>, 1)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="item-total"><?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                                </div>
                                <button class="remove-item-btn" onclick="removeFromCart(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="payment-section">
                <div class="section-header">
                    Payment Method
                </div>
                
                <div class="section-content">
                    <div class="payment-options">
                        <div class="payment-option selected" data-payment="cash">
                            <div class="payment-radio">
                                <input type="radio" name="payment" id="cash" value="cash" checked>
                            </div>
                            <div class="payment-details">
                                <div class="payment-logo">
                                    ₱
                                </div>
                                <div class="payment-name">Cash on Pickup</div>
                            </div>
                        </div>
                        
                        <div class="payment-option" data-payment="gcash">
                            <div class="payment-radio">
                                <input type="radio" name="payment" id="gcash" value="gcash">
                            </div>
                            <div class="payment-details">
                                <div class="payment-logo gcash-logo">
                                    G
                                </div>
                                <div class="payment-name">GCash</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="payment-summary">
                        <div class="summary-row">
                            <div class="summary-label">Subtotal (<span id="summaryItemCount"><?php echo count($cart_items); ?></span> items)</div>
                            <div class="summary-value" id="summarySubtotal">₱<?php echo number_format($cart_total, 2); ?></div>
                        </div>
                        <div class="summary-row">
                            <div class="summary-label">Service Fee</div>
                            <div class="summary-value" id="serviceFee">₱10.00</div>
                        </div>
                        <div class="summary-row summary-total">
                            <div class="summary-label">Total</div>
                            <div class="summary-value" id="summaryTotal">₱<?php echo number_format($cart_total + 10.00, 2); ?></div>
                        </div>
                    </div>
                    
                    <form id="checkoutForm" method="POST">
                        <input type="hidden" name="payment_method" id="paymentMethod" value="cash">
                        <button type="submit" name="place_order" id="placeOrderBtn" class="checkout-btn" <?php echo empty($cart_items) ? 'disabled' : ''; ?>>
                            Place Order
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Authentication Popup -->
<div id="auth-popup" class="auth-popup-overlay">
    <div class="auth-popup-container">
        <div class="auth-popup-header">
            <h3>Order Confirmation</h3>
            <button id="close-auth-popup" class="auth-popup-close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="auth-popup-content">
            <div class="auth-icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <h4>Order Placed Successfully!</h4>
            <p>Your order has been received and is being processed.</p>
            
            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value" id="order-id-display">ORD20231201123456</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value" id="order-total-display">₱0.00</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Method:</span>
                    <span class="detail-value" id="payment-method-display">Cash on Pickup</span>
                </div>
            </div>
            
            <div class="auth-buttons">
                <button id="view-orders-btn" class="auth-confirm-btn">
                    View My Orders
                </button>
            </div>
            
            <div class="auth-note">
                <i class="fas fa-info-circle"></i>
                <span>You will receive a notification when your order is ready for pickup.</span>
            </div>
        </div>
    </div>
</div>

<script>
    // Sidebar functionality
    const hamburger = document.getElementById('hamburger');
    const sidebar = document.getElementById('sidebar');
    const closeSidebar = document.getElementById('close-sidebar');
    const overlay = document.getElementById('overlay');

    hamburger.addEventListener('click', () => {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    });

    closeSidebar.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        cartSidebar.classList.add('translate-x-full');
    });

    // Cart functionality
    const cartBtn = document.getElementById('cart-btn');
    const cartSidebar = document.getElementById('cart-sidebar');
    const closeCart = document.getElementById('close-cart');

    cartBtn.addEventListener('click', () => {
        cartSidebar.classList.remove('translate-x-full');
        overlay.classList.remove('hidden');
    });

    closeCart.addEventListener('click', () => {
        cartSidebar.classList.add('translate-x-full');
        overlay.classList.add('hidden');
    });

    // Payment method selection
    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentMethodInput = document.getElementById('paymentMethod');
    
    paymentOptions.forEach(option => {
        option.addEventListener('click', () => {
            // Remove selected class from all options
            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            // Add selected class to clicked option
            option.classList.add('selected');
            // Update the radio button
            const radio = option.querySelector('input[type="radio"]');
            radio.checked = true;
            // Update hidden input
            paymentMethodInput.value = radio.value;
        });
    });

    // Update cart quantity via AJAX
    function updateCartQuantity(cartId, change) {
        const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
        const quantityElement = cartItem.querySelector('.cart-quantity');
        let currentQuantity = parseInt(quantityElement.textContent);
        let newQuantity = currentQuantity + change;

        if (newQuantity <= 0) {
            removeFromCart(cartId);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_cart_quantity');
        formData.append('cart_id', cartId);
        formData.append('quantity', newQuantity);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                quantityElement.textContent = newQuantity;
                updateCartCount();
                updateCartTotal();
                location.reload(); // Reload to update checkout page
            } else {
                showNotification(data.message || 'Failed to update cart', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred', 'error');
        });
    }

    // Remove from cart via AJAX
    function removeFromCart(cartId) {
        if (!confirm('Remove this item from your cart?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'remove_from_cart');
        formData.append('cart_id', cartId);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Item removed from cart');
                updateCartCount();
                location.reload(); // Reload to update checkout page
            } else {
                showNotification(data.message || 'Failed to remove item', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred', 'error');
        });
    }

    // Update cart count badge via AJAX - now counts distinct items only
    function updateCartCount() {
        const formData = new FormData();
        formData.append('action', 'get_cart_count');

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cartBadge = document.querySelector('.cart-badge');
                const count = data.count || 0;
                
                if (count > 0) {
                    cartBadge.textContent = count > 99 ? '99+' : count;
                    cartBadge.style.display = 'flex';
                } else {
                    cartBadge.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    // Update cart total
    function updateCartTotal() {
        let total = 0;
        const cartItems = document.querySelectorAll('.cart-item');
        
        cartItems.forEach(item => {
            const priceText = item.querySelector('.text-sm.text-gray-600').textContent;
            const price = parseFloat(priceText.replace('₱', '').replace(',', ''));
            const quantity = parseInt(item.querySelector('.cart-quantity').textContent);
            total += price * quantity;
        });
        
        document.getElementById('cart-total').textContent = '₱' + total.toFixed(2);
        
        // Update checkout button state
        const checkoutBtn = document.getElementById('proceed-to-checkout');
        if (cartItems.length === 0) {
            checkoutBtn.classList.add('opacity-50', 'pointer-events-none');
        } else {
            checkoutBtn.classList.remove('opacity-50', 'pointer-events-none');
        }
    }

    // Show notification
    function showNotification(message, type = 'success') {
        // Remove any existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(n => n.remove());
        
        const notification = document.createElement('div');
        notification.className = 'notification';
        notification.style.cssText = `
            position: fixed;
            top: 100px;
            right: 20px;
            background: ${type === 'error' ? '#ef4444' : '#10b981'};
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
            max-width: 300px;
            font-weight: 500;
        `;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // Authentication popup functionality
    const authPopup = document.getElementById('auth-popup');
    const closeAuthPopup = document.getElementById('close-auth-popup');
    const viewOrdersBtn = document.getElementById('view-orders-btn');
    
    closeAuthPopup.addEventListener('click', () => {
        authPopup.classList.remove('active');
        overlay.classList.add('hidden');
    });
    
    viewOrdersBtn.addEventListener('click', () => {
        window.location.href = 'my_orders.php';
    });

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
        updateCartCount();
        
        // Update cart badge visibility on page load
        const cartBadge = document.querySelector('.cart-badge');
        const cartCount = parseInt(cartBadge.textContent);
        if (cartCount > 0) {
            cartBadge.style.display = 'flex';
        }
    });
</script>
</body>
</html>