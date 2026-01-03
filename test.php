<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';

$restaurantId = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : 1;

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get menu items for a restaurant
    $query = "SELECT id, name, description, price, discounted_price, in_stock, is_active, 
                     restaurant_id, image_url 
              FROM menu_items 
              WHERE restaurant_id = :restaurant_id 
                AND is_active = 1 
              ORDER BY id DESC 
              LIMIT 10";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'restaurant_id' => $restaurantId,
        'menu_items' => $menuItems,
        'count' => count($menuItems)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'menu_items' => []
    ]);
}
?>