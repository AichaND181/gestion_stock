<?php
session_start();
require_once '../config/database.php';

// Empêcher la mise en cache pour la sécurité
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// ======================== TRAITEMENTS AJAX ========================

// Ajouter une commande avec ses lignes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_commande'])) {
    $order_number = trim($_POST['order_number'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $supplier_id = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
    $client_id = !empty($_POST['client_id']) ? $_POST['client_id'] : null;
    $order_date = $_POST['order_date'] ?? date('Y-m-d');
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $status = trim($_POST['status'] ?? 'en attente');
    $notes = trim($_POST['notes'] ?? '');
    
    // Récupérer les produits commandés
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    
    $response = ['success' => false, 'error' => ''];
    
    if (empty($order_number)) {
        $response['error'] = "Le numéro de commande est requis.";
    } elseif ($type == 'achat' && !$supplier_id) {
        $response['error'] = "Veuillez sélectionner un fournisseur.";
    } elseif ($type == 'vente' && !$client_id) {
        $response['error'] = "Veuillez sélectionner un client.";
    } elseif (count($product_ids) == 0) {
        $response['error'] = "Veuillez ajouter au moins un produit.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insérer la commande
            $stmt = $pdo->prepare("INSERT INTO commandes (order_number, type, supplier_id, client_id, order_date, subtotal, tax_amount, shipping_cost, total_amount, status, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$order_number, $type, $supplier_id, $client_id, $order_date, $subtotal, $tax_amount, $shipping_cost, $total_amount, $status, $notes]);
            $commande_id = $pdo->lastInsertId();
            
            // Insérer les lignes de commande
            $stmt_ligne = $pdo->prepare("INSERT INTO commande_lignes (commande_id, produit_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < count($product_ids); $i++) {
                if (!empty($product_ids[$i]) && $quantities[$i] > 0) {
                    $stmt_ligne->execute([$commande_id, $product_ids[$i], $quantities[$i], $unit_prices[$i]]);
                    
                    // Mettre à jour le stock selon le type
                    if ($type == 'achat') {
                        // Achat : augmenter le stock
                        $pdo->prepare("UPDATE produits SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([$quantities[$i], $product_ids[$i]]);
                    } else {
                        // Vente : diminuer le stock
                        $pdo->prepare("UPDATE produits SET stock_quantity = stock_quantity - ? WHERE id = ?")->execute([$quantities[$i], $product_ids[$i]]);
                    }
                }
            }
            
            $pdo->commit();
            $response['success'] = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $response['error'] = "Erreur : " . $e->getMessage();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Modifier une commande
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_commande'])) {
    $id = $_POST['id'] ?? 0;
    $order_number = trim($_POST['order_number'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $supplier_id = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
    $client_id = !empty($_POST['client_id']) ? $_POST['client_id'] : null;
    $order_date = $_POST['order_date'] ?? date('Y-m-d');
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $status = trim($_POST['status'] ?? 'en attente');
    $notes = trim($_POST['notes'] ?? '');
    
    // Récupérer les produits commandés
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    
    $response = ['success' => false, 'error' => ''];
    
    if (empty($order_number)) {
        $response['error'] = "Le numéro de commande est requis.";
    } elseif ($total_amount <= 0) {
        $response['error'] = "Le montant total doit être supérieur à 0.";
    } elseif (count($product_ids) == 0) {
        $response['error'] = "Veuillez ajouter au moins un produit.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Mettre à jour la commande
            $stmt = $pdo->prepare("UPDATE commandes SET order_number = ?, type = ?, supplier_id = ?, client_id = ?, order_date = ?, subtotal = ?, tax_amount = ?, shipping_cost = ?, total_amount = ?, status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$order_number, $type, $supplier_id, $client_id, $order_date, $subtotal, $tax_amount, $shipping_cost, $total_amount, $status, $notes, $id]);
            
            // Supprimer les anciennes lignes
            $pdo->prepare("DELETE FROM commande_lignes WHERE commande_id = ?")->execute([$id]);
            
            // Insérer les nouvelles lignes
            $stmt_ligne = $pdo->prepare("INSERT INTO commande_lignes (commande_id, produit_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < count($product_ids); $i++) {
                if (!empty($product_ids[$i]) && $quantities[$i] > 0) {
                    $stmt_ligne->execute([$id, $product_ids[$i], $quantities[$i], $unit_prices[$i]]);
                }
            }
            
            $pdo->commit();
            $response['success'] = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $response['error'] = "Erreur : " . $e->getMessage();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Supprimer une commande (soft delete)
if (isset($_GET['delete_commande'])) {
    $id = $_GET['delete_commande'];
    $stmt = $pdo->prepare("UPDATE commandes SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php?deleted=1');
    exit;
}

// Récupérer une commande pour affichage (AJAX)
if (isset($_GET['action']) && $_GET['action'] == 'get_commande' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT c.*, 
           CASE WHEN c.type='achat' THEN f.name ELSE cl.name END AS partenaire,
           f.name as fournisseur_name,
           cl.name as client_name
    FROM commandes c
    LEFT JOIN fournisseurs f ON c.supplier_id = f.id
    LEFT JOIN clients cl ON c.client_id = cl.id
    WHERE c.id = ? AND c.deleted_at IS NULL");
    $stmt->execute([$id]);
    $commande = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($commande) {
        // Récupérer les lignes de commande
        $stmt_lignes = $pdo->prepare("SELECT cl.*, p.name as product_name FROM commande_lignes cl LEFT JOIN produits p ON cl.produit_id = p.id WHERE cl.commande_id = ?");
        $stmt_lignes->execute([$id]);
        $commande['lignes'] = $stmt_lignes->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($commande);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Commande non trouvée']);
    }
    exit;
}

// Récupérer les produits pour le formulaire AJAX
if (isset($_GET['action']) && $_GET['action'] == 'get_produits') {
    $stmt = $pdo->query("SELECT id, name, price, stock_quantity FROM produits WHERE deleted_at IS NULL ORDER BY name");
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($produits);
    exit;
}

// ======================== STATISTIQUES ========================
$stmt = $pdo->query("SELECT COUNT(*) FROM commandes WHERE deleted_at IS NULL");
$totalCommandes = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM commandes WHERE deleted_at IS NULL AND status = 'expédiée'");
$totalExpediee = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM commandes WHERE deleted_at IS NULL AND status = 'livrée'");
$totalLivree = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(total_amount) FROM commandes WHERE deleted_at IS NULL");
$totalMontant = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM commandes WHERE deleted_at IS NULL AND (status = 'en attente' OR status = 'En attente' OR status = 'attente')");
$enAttente = $stmt->fetchColumn();

// ======================== FILTRES & RECHERCHE ========================
$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

// ======================== PAGINATION ========================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Compter le total avec les filtres
$countSql = "SELECT COUNT(*) FROM commandes c WHERE c.deleted_at IS NULL";
$countParams = [];

if (!empty($search)) {
    $countSql .= " AND (c.order_number LIKE :search OR (CASE WHEN c.type='achat' THEN f.name ELSE cl.name END) LIKE :search)";
    $countParams[':search'] = "%$search%";
}
if ($typeFilter !== 'all') {
    $countSql .= " AND c.type = :type";
    $countParams[':type'] = $typeFilter;
}
if ($statusFilter !== 'all') {
    $countSql .= " AND c.status = :status";
    $countParams[':status'] = $statusFilter;
}

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($countParams);
$totalCommandesFiltered = $stmtCount->fetchColumn();
$totalPages = ceil($totalCommandesFiltered / $perPage);

// ======================== REQUÊTE PRINCIPALE ========================
$sql = "SELECT c.*, 
           CASE WHEN c.type='achat' THEN f.name ELSE cl.name END AS partenaire
    FROM commandes c
    LEFT JOIN fournisseurs f ON c.supplier_id = f.id
    LEFT JOIN clients cl ON c.client_id = cl.id
    WHERE c.deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (c.order_number LIKE :search OR (CASE WHEN c.type='achat' THEN f.name ELSE cl.name END) LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($typeFilter !== 'all') {
    $sql .= " AND c.type = :type";
    $params[':type'] = $typeFilter;
}
if ($statusFilter !== 'all') {
    $sql .= " AND c.status = :status";
    $params[':status'] = $statusFilter;
}

$sql .= " ORDER BY c.order_date DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$commandes = $stmt->fetchAll();

$stmtStatus = $pdo->query("SELECT DISTINCT status FROM commandes WHERE deleted_at IS NULL AND status IS NOT NULL AND status != ''");
$statusList = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les fournisseurs et clients pour les formulaires
$fournisseurs = $pdo->query("SELECT id, name FROM fournisseurs WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$clients = $pdo->query("SELECT id, name FROM clients WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$produits = $pdo->query("SELECT id, name, price, stock_quantity FROM produits WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commandes - Gestion de Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ========== STYLES IDENTIQUES AUX AUTRES PAGES ========== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            transition: background 0.3s ease, color 0.2s ease;
            min-height: 100vh;
        }
        body[data-bs-theme="light"] {
            background: linear-gradient(135deg, #f5f7fe 0%, #eef2f9 100%);
            color: #1e293b;
        }
        body[data-bs-theme="dark"] {
            background: #0f172a;
            color: #e2e8f0;
        }
        .navbar {
            background: linear-gradient(90deg, #0f172a 0%, #1e293b 100%);
            padding: 0.8rem 1rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }
        body[data-bs-theme="dark"] .navbar { background: #020617 !important; }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: -0.3px;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent !important;
        }
        .navbar-nav .nav-link {
            color: #cbd5e1;
            font-weight: 500;
            margin: 0 0.25rem;
            border-radius: 40px;
            transition: all 0.2s;
        }
        .navbar-nav .nav-link:hover { color: white; background-color: rgba(255,255,255,0.1); }
        .navbar-nav .nav-link.active { color: white; background-color: #4f46e5; }
        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            margin-left: 1rem;
        }
        .theme-switch {
            display: inline-block;
            height: 28px;
            position: relative;
            width: 52px;
        }
        .theme-switch input { display: none; }
        .slider {
            background-color: #ccc;
            bottom: 0;
            cursor: pointer;
            left: 0;
            position: absolute;
            right: 0;
            top: 0;
            transition: 0.4s;
            border-radius: 34px;
        }
        .slider:before {
            background-color: white;
            bottom: 4px;
            content: "";
            height: 20px;
            left: 4px;
            position: absolute;
            transition: 0.4s;
            width: 20px;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: #4f46e5; }
        input:checked + .slider:before { transform: translateX(24px); }
        .slider i {
            position: absolute;
            top: 6px;
            font-size: 12px;
            z-index: 1;
        }
        .fa-sun { left: 8px; color: #fbbf24; }
        .fa-moon { right: 8px; color: #f1f5f9; }

        /* ========== STATS CARDS ========== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            border-radius: 28px;
            padding: 1.2rem 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }
        body[data-bs-theme="light"] .stat-card {
            background: rgba(255, 255, 255, 0.85);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        body[data-bs-theme="dark"] .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon {
            position: absolute;
            right: 1rem;
            bottom: 0.8rem;
            font-size: 3rem;
            opacity: 0.15;
            pointer-events: none;
        }
        .stat-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        body[data-bs-theme="dark"] .stat-title { color: #94a3b8; }
        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            margin-top: 0.5rem;
            line-height: 1.2;
        }
        body[data-bs-theme="light"] .stat-number { color: #0f172a; }

        /* TABLE CARD */
        .card-table {
            border: none;
            border-radius: 28px;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        body[data-bs-theme="light"] .card-table {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.1);
        }
        body[data-bs-theme="dark"] .card-table {
            background: #1e293b;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.4);
        }
        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        body[data-bs-theme="dark"] .table-header { border-bottom-color: #334155; }
        .table-header h2 {
            font-weight: 700;
            font-size: 1.6rem;
            margin: 0;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .table-header-actions {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            flex-wrap: nowrap;
        }
        .table-header .table-header-info {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            color: #64748b;
            font-size: 0.95rem;
            white-space: nowrap;
        }
        .filters-bar {
            padding: 1rem 1.5rem;
            background: rgba(0,0,0,0.02);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        body[data-bs-theme="dark"] .filters-bar {
            background: rgba(255,255,255,0.02);
            border-bottom-color: #334155;
        }
        .filter-group {
            flex: 1;
            min-width: 160px;
        }
        .filter-group label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .form-select, .form-control {
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        body[data-bs-theme="dark"] .form-select,
        body[data-bs-theme="dark"] .form-control {
            background-color: #1e293b;
            border-color: #475569;
            color: #e2e8f0;
        }
        .btn-custom {
            border-radius: 40px;
            padding: 0.45rem 1.2rem;
            font-weight: 500;
        }
        .table-custom {
            width: 100%;
            margin-bottom: 0;
        }
        .table-custom thead th {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 600;
            padding: 1rem;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        body[data-bs-theme="dark"] .table-custom thead th {
            background: #0f172a;
            color: #cbd5e1;
            border-bottom-color: #334155;
        }
        .table-custom tbody tr:hover {
            background-color: rgba(79, 70, 229, 0.05);
        }
        body[data-bs-theme="dark"] .table-custom tbody tr:hover {
            background-color: rgba(79, 70, 229, 0.15);
        }
        .table-custom td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9eef3;
        }
        body[data-bs-theme="dark"] .table-custom td { border-bottom-color: #334155; }
        .badge-stock {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-stock.success { background: #10b981; color: white; }
        .badge-stock.warning { background: #f59e0b; color: white; }
        .badge-stock.danger { background: #ef4444; color: white; }
        .badge-status {
            background: #6b7280;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Boutons d'action - uniquement l'icône sans fond */
        .action-icons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .action-icons button {
            background: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            font-size: 1.2rem;
            cursor: pointer;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        .action-icons button:hover {
            transform: scale(1.1);
        }
        .action-icons .view-action { color: #0dcaf0; }
        .action-icons .edit-action { color: #f59e0b; }
        .action-icons .delete-action { color: #ef4444; }
        body[data-bs-theme="dark"] .action-icons .view-action { color: #22d3ee; }
        body[data-bs-theme="dark"] .action-icons .edit-action { color: #fbbf24; }
        body[data-bs-theme="dark"] .action-icons .delete-action { color: #f87171; }
        
        .badge-type {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-type.achat { background: #0ea5e9; color: white; }
        .badge-type.vente { background: #10b981; color: white; }
        body[data-bs-theme="dark"] .badge-type.achat { background: #0284c7; }
        body[data-bs-theme="dark"] .badge-type.vente { background: #059669; }
        
        .badge-status.en-attente { background: #f59e0b; }
        .badge-status.confirmee { background: #3b82f6; }
        .badge-status.expediee { background: #8b5cf6; }
        .badge-status.livree { background: #10b981; }
        .badge-status.annulee { background: #ef4444; }
        
        .btn-primary {
            background: linear-gradient(95deg, #4f46e5, #6366f1);
            border: none;
            border-radius: 60px;
            padding: 0.6rem 1.4rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: linear-gradient(95deg, #4338ca, #4f46e5);
            transform: translateY(-2px);
        }
        .btn-secondary {
            border-radius: 60px;
            padding: 0.5rem 1.2rem;
            font-weight: 500;
        }
        .footer-actions {
            padding: 1.2rem 1.5rem;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        body[data-bs-theme="dark"] .footer-actions { border-top-color: #334155; }

        /* ===== STYLES DES MODALES ===== */
        .modal-form .modal-content {
            border-radius: 24px;
            border: none;
            overflow: hidden;
        }
        body[data-bs-theme="dark"] .modal-form .modal-content {
            background: #1e293b;
        }
        .modal-form .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e9ecef;
            background: #f8fafc;
        }
        body[data-bs-theme="dark"] .modal-form .modal-header {
            background: #0f172a;
            border-bottom-color: #334155;
        }
        .modal-form .modal-header h5 {
            font-weight: 700;
            font-size: 1.3rem;
        }
        .modal-form .modal-body {
            padding: 2rem;
        }
        .modal-form .modal-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid #e9ecef;
            background: #f8fafc;
        }
        body[data-bs-theme="dark"] .modal-form .modal-footer {
            background: #0f172a;
            border-top-color: #334155;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: #475569;
            display: block;
        }
        body[data-bs-theme="dark"] .form-label {
            color: #cbd5e1;
        }
        .form-label i {
            width: 24px;
            color: #4f46e5;
        }
        .row-2cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #4f46e5;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-top: 1rem;
            font-size: 0.8rem;
        }
        body[data-bs-theme="dark"] .info-box {
            background: #1e1b4b;
            border-left-color: #818cf8;
        }
        .info-box i {
            margin-right: 8px;
            color: #4f46e5;
        }
        .btn-cancel-modal {
            padding: 0.5rem 1.5rem;
            border-radius: 40px;
            background: #e2e8f0;
            color: #475569;
            border: none;
        }
        .btn-cancel-modal:hover {
            background: #cbd5e6;
        }
        .btn-save-modal {
            padding: 0.5rem 1.8rem;
            border-radius: 40px;
            background: linear-gradient(95deg, #4f46e5, #6366f1);
            color: white;
            border: none;
        }
        .btn-save-modal:hover {
            background: linear-gradient(95deg, #4338ca, #4f46e5);
        }
        .detail-item {
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #eef2f6;
            padding-bottom: 0.75rem;
        }
        body[data-bs-theme="dark"] .detail-item {
            border-bottom-color: #334155;
        }
        .detail-label {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        body[data-bs-theme="dark"] .detail-label {
            color: #94a3b8;
        }
        .detail-value {
            font-size: 1rem;
            font-weight: 500;
        }
        .products-table th, .products-table td {
            padding: 0.5rem;
            vertical-align: middle;
        }
        .remove-product {
            cursor: pointer;
            font-size: 1.1rem;
        }
        .remove-product:hover {
            opacity: 0.7;
        }
        
        @media (max-width: 768px) {
            .navbar-nav { flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .nav-link { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
            .table-custom thead { display: none; }
            .table-custom tbody tr {
                display: block;
                margin-bottom: 1rem;
                border-radius: 16px;
            }
            .table-custom td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                text-align: right;
                padding: 0.75rem 1rem;
            }
            .table-custom td::before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                flex: 1;
            }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                width: 100%;
            }
            .action-icons {
                justify-content: flex-end;
            }
            .row-2cols {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }
        /* Modal de confirmation personnalisé */
        .modal-confirm {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }
        .modal-confirm.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-confirm-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 450px;
            overflow: hidden;
            animation: slideDown 0.3s ease;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        body[data-bs-theme="dark"] .modal-confirm-content {
            background: #1e293b;
        }
        .modal-confirm-header {
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        body[data-bs-theme="dark"] .modal-confirm-header {
            border-bottom-color: rgba(255, 255, 255, 0.1);
        }
        .modal-confirm-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        .modal-confirm-icon i {
            font-size: 2rem;
            color: white;
        }
        .modal-confirm-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }
        body[data-bs-theme="dark"] .modal-confirm-header h3 {
            color: #f1f5f9;
        }
        .modal-confirm-header p {
            color: #64748b;
            font-size: 0.9rem;
        }
        body[data-bs-theme="dark"] .modal-confirm-header p {
            color: #94a3b8;
        }
        .modal-confirm-body {
            padding: 1rem 1.5rem;
            text-align: center;
            color: #475569;
            font-size: 0.95rem;
        }
        body[data-bs-theme="dark"] .modal-confirm-body {
            color: #cbd5e1;
        }
        .modal-confirm-footer {
            padding: 1rem 1.5rem 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .btn-confirm {
            padding: 0.6rem 1.8rem;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-confirm-yes {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        .btn-confirm-yes:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);
        }
        .btn-confirm-no {
            background: #e2e8f0;
            color: #475569;
        }
        body[data-bs-theme="dark"] .btn-confirm-no {
            background: #334155;
            color: #e2e8f0;
        }
        .btn-confirm-no:hover {
            transform: translateY(-2px);
            background: #cbd5e1;
        }
        body[data-bs-theme="dark"] .btn-confirm-no:hover {
            background: #475569;
        }

        /* ========== PAGINATION ========== */
        .pagination-container {
            margin: 2rem 0;
            display: flex;
            justify-content: center;
        }
        .pagination {
            margin: 0;
        }
        .page-link {
            color: #4f46e5;
            background-color: #fff;
            border: 1px solid #dee2e6;
            padding: 0.5rem 0.75rem;
            margin: 0 0.125rem;
            border-radius: 0.375rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        body[data-bs-theme="dark"] .page-link {
            color: #a5b4fc;
            background-color: #1e293b;
            border-color: #334155;
        }
        .page-link:hover {
            color: #3730a3;
            background-color: #f3f4f6;
            border-color: #cbd5e1;
        }
        body[data-bs-theme="dark"] .page-link:hover {
            color: #c7d2fe;
            background-color: #334155;
            border-color: #475569;
        }
        .page-item.active .page-link {
            background-color: #4f46e5;
            border-color: #4f46e5;
            color: white;
        }
        body[data-bs-theme="dark"] .page-item.active .page-link {
            background-color: #6366f1;
            border-color: #6366f1;
        }
        .page-item.disabled .page-link {
            color: #6b7280;
            background-color: #f9fafb;
            border-color: #e5e7eb;
        }
        body[data-bs-theme="dark"] .page-item.disabled .page-link {
            color: #9ca3af;
            background-color: #0f172a;
            border-color: #1e293b;
        }
    </style>
</head>
<body data-bs-theme="light">

<!-- Barre de navigation -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php">
            <i class="fas fa-cubes me-2"></i> Gestion de Stock
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="../index.php"><i class="fas fa-chart-line me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../categories/index.php"><i class="fas fa-tags me-1"></i> Catégories</a></li>
                <li class="nav-item"><a class="nav-link" href="../fournisseurs/index.php"><i class="fas fa-truck me-1"></i> Fournisseurs</a></li>
                <li class="nav-item"><a class="nav-link" href="../produits/index.php"><i class="fas fa-boxes me-1"></i> Produits</a></li>
                <li class="nav-item"><a class="nav-link" href="../clients/index.php"><i class="fas fa-users me-1"></i> Clients</a></li>
                <li class="nav-item"><a class="nav-link active" href="index.php"><i class="fas fa-shopping-cart me-1"></i> Commandes</a></li>
                <li class="nav-item"><a class="nav-link" href="../stock/index.php"><i class="fas fa-database me-1"></i> Stock</a></li>
                <li class="nav-item"><a class="nav-link" href="#" id="logoutBtn"><i class="fas fa-sign-out-alt me-1"></i> Déconnexion</a></li>
            </ul>
            <div class="theme-switch-wrapper">
                <label class="theme-switch">
                    <input type="checkbox" id="darkmode-toggle">
                    <span class="slider"><i class="fas fa-sun"></i><i class="fas fa-moon"></i></span>
                </label>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Cartes statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
            <div class="stat-title">TOTAL COMMANDES</div>
            <div class="stat-number"><?= $totalCommandes ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-rocket"></i></div>
            <div class="stat-title">EXPÉDIÉE</div>
            <div class="stat-number"><?= $totalExpediee ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-title">LIVRÉE</div>
            <div class="stat-number"><?= $totalLivree ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-title">MONTANT TOTAL</div>
            <div class="stat-number"><?= number_format($totalMontant, 0, ',', ' ') ?> FCFA</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-title">EN ATTENTE</div>
            <div class="stat-number"><?= $enAttente ?></div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card-table">
        <div class="table-header">
            <h2><i class="fas fa-shopping-cart me-2"></i> Gestion des commandes</h2>
            <div class="table-header-actions">
                <div class="table-header-info"><i class="fas fa-database"></i> Total : <?= count($commandes) ?> commande(s)</div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCommandeModal">
                    <i class="fas fa-plus me-1"></i> Nouvelle commande
                </button>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters-bar">
            <form method="GET" class="d-flex flex-wrap gap-2 w-100 align-items-end">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Recherche</label>
                    <input type="text" name="search" class="form-control" placeholder="N° commande ou partenaire..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-exchange-alt"></i> Type</label>
                    <select name="type" class="form-select">
                        <option value="all" <?= $typeFilter == 'all' ? 'selected' : '' ?>>Tous</option>
                        <option value="achat" <?= $typeFilter == 'achat' ? 'selected' : '' ?>>Achats</option>
                        <option value="vente" <?= $typeFilter == 'vente' ? 'selected' : '' ?>>Ventes</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-chart-simple"></i> Statut</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $statusFilter == 'all' ? 'selected' : '' ?>>Tous</option>
                        <?php foreach ($statusList as $stat): ?>
                        <option value="<?= htmlspecialchars($stat) ?>" <?= $statusFilter == $stat ? 'selected' : '' ?>><?= htmlspecialchars($stat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex: 0 0 auto;">
                    <button type="submit" class="btn btn-primary btn-custom w-100"><i class="fas fa-filter"></i> Filtrer</button>
                </div>
                <div class="filter-group" style="flex: 0 0 auto;">
                    <a href="index.php" class="btn btn-secondary btn-custom w-100"><i class="fas fa-undo-alt"></i> Réinitialiser</a>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr><th>N° commande</th><th>Type</th><th>Partenaire</th><th>Date</th><th>Total HT</th><th>Statut</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($commandes as $cmd): ?>
                    <?php
                        $type = strtolower($cmd['type']);
                        $typeClass = ($type === 'achat') ? 'achat' : 'vente';
                        $typeIcon = ($type === 'achat') ? 'fa-truck' : 'fa-user';
                        $status = strtolower(trim($cmd['status'] ?? ''));
                        $statusClass = match(true) {
                            str_contains($status, 'attente') => 'en-attente',
                            str_contains($status, 'confirm') => 'confirmee',
                            str_contains($status, 'expéd') || str_contains($status, 'expedi') => 'expediee',
                            str_contains($status, 'livr') => 'livree',
                            str_contains($status, 'annul') => 'annulee',
                            default => 'en-attente'
                        };
                    ?>
                    <tr>
                        <td data-label="N° commande"><strong><?= htmlspecialchars($cmd['order_number']) ?></strong></td>
                        <td data-label="Type">
                            <span class="badge-type <?= $typeClass ?>">
                                <i class="fas <?= $typeIcon ?> me-1"></i> <?= ucfirst($type) ?>
                            </span>
                        </td>
                        <td data-label="Partenaire"><?= htmlspecialchars($cmd['partenaire'] ?? '—') ?></td>
                        <td data-label="Date"><?= date('d/m/Y', strtotime($cmd['order_date'])) ?></td>
                        <td data-label="Total"><strong><?= number_format($cmd['total_amount'], 0, ',', ' ') ?></strong> FCFA</td>
                        <td data-label="Statut"><span class="badge-status <?= $statusClass ?>"><?= htmlspecialchars($cmd['status'] ?? 'En attente') ?></span></td>
                        <td data-label="Actions">
                            <div class="action-icons">
                                <button class="view-action" data-id="<?= $cmd['id'] ?>" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="edit-action" data-id="<?= $cmd['id'] ?>" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-action" data-id="<?= $cmd['id'] ?>" data-number="<?= htmlspecialchars($cmd['order_number']) ?>" title="Supprimer">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($commandes) === 0): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-shopping-cart fa-2x mb-2 d-block"></i>Aucune commande trouvée. <a href="#" data-bs-toggle="modal" data-bs-target="#addCommandeModal">Créez-en une</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <nav aria-label="Pagination des commandes">
                <ul class="pagination justify-content-center">
                    <?php
                    // Construire la query string de base
                    $baseUrl = 'index.php?' . http_build_query(array_filter([
                        'search' => $search,
                        'type' => $typeFilter !== 'all' ? $typeFilter : null,
                        'status' => $statusFilter !== 'all' ? $statusFilter : null
                    ]));

                    // Bouton Précédent
                    if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $page - 1 ?>" aria-label="Précédent">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);

                    if ($start > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=1">1</a>
                        </li>
                        <?php if ($start > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
                        </li>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $page + 1 ?>" aria-label="Suivant">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <div class="footer-actions">
            <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Retour dashboard</a>
        </div>
    </div>
</div>

<!-- MODALE AJOUTER COMMANDE -->
<div class="modal fade modal-form" id="addCommandeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Nouvelle commande</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="addCommandeForm" method="POST">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-hashtag"></i> Numéro commande *</label>
                                <input type="text" name="order_number" class="form-control" placeholder="CMD-2024-0001" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-exchange-alt"></i> Type *</label>
                                <select name="type" id="addType" class="form-select" required>
                                    <option value="achat">Achat (Fournisseur)</option>
                                    <option value="vente">Vente (Client)</option>
                                </select>
                            </div>
                            <div class="form-group" id="addSupplierGroup">
                                <label class="form-label"><i class="fas fa-truck"></i> Fournisseur</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="">Sélectionner un fournisseur</option>
                                    <?php foreach ($fournisseurs as $fourn): ?>
                                    <option value="<?= $fourn['id'] ?>"><?= htmlspecialchars($fourn['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="addClientGroup" style="display: none;">
                                <label class="form-label"><i class="fas fa-user"></i> Client</label>
                                <select name="client_id" class="form-select">
                                    <option value="">Sélectionner un client</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-calendar"></i> Date commande</label>
                                <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-chart-simple"></i> Statut</label>
                                <select name="status" class="form-select">
                                    <option value="en attente">En attente</option>
                                    <option value="confirmée">Confirmée</option>
                                    <option value="expédiée">Expédiée</option>
                                    <option value="livrée">Livrée</option>
                                    <option value="annulée">Annulée</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-align-left"></i> Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Notes supplémentaires..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section Produits commandés -->
                    <hr class="my-3">
                    <h6 class="mb-3"><i class="fas fa-boxes me-2"></i> Produits commandés</h6>
                    <div class="table-responsive">
                        <table class="products-table table table-sm">
                            <thead>
                                <tr><th>Produit</th><th>Quantité</th><th>Prix unitaire HT</th><th>Total HT</th><th></th></tr>
                            </thead>
                            <tbody id="products-list">
                                <tr class="product-row">
                                    <td>
                                        <select name="product_id[]" class="form-select product-select" required>
                                            <option value="">Sélectionner un produit</option>
                                            <?php foreach ($produits as $prod): ?>
                                            <option value="<?= $prod['id'] ?>" data-price="<?= $prod['price'] ?>" data-stock="<?= $prod['stock_quantity'] ?>"><?= htmlspecialchars($prod['name']) ?> (Stock: <?= $prod['stock_quantity'] ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control product-quantity" step="1" min="1" value="1" style="width: 100px;"></td>
                                    <td><input type="number" name="unit_price[]" class="form-control product-price" step="1" min="0" value="0" style="width: 120px;"></td>
                                    <td><span class="product-total">0</span> FCFA</td>
                                    <td><i class="fas fa-trash-alt remove-product text-danger"></i></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr><td colspan="5"><button type="button" id="addProductBtn" class="btn btn-sm btn-secondary"><i class="fas fa-plus"></i> Ajouter un produit</button></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>Sous-total:</strong></td><td><input type="text" id="subtotal" name="subtotal" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>TVA (20%):</strong></td><td><input type="text" id="tax_amount" name="tax_amount" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>Frais de livraison:</strong></td><td><input type="number" name="shipping_cost" id="shipping_cost" class="form-control" step="1" value="0" style="width: 140px;"></td></tr>
                                <tr class="table-active"><td colspan="3" class="text-end"><strong>Total TTC:</strong></td><td><input type="text" id="total_amount" name="total_amount" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="info-box mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Les champs marqués d'un <span class="text-danger">*</span> sont obligatoires. Le stock sera automatiquement mis à jour.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" name="add_commande" class="btn-save-modal"><i class="fas fa-save me-2"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE VOIR COMMANDE -->
<div class="modal fade modal-form" id="viewCommandeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Détails de la commande</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="viewCommandeBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE MODIFIER COMMANDE -->
<div class="modal fade modal-form" id="editCommandeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen-alt me-2"></i> Modifier la commande</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="editCommandeForm" method="POST">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-hashtag"></i> Numéro commande *</label>
                                <input type="text" name="order_number" id="editOrderNumber" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-exchange-alt"></i> Type</label>
                                <select name="type" id="editType" class="form-select" disabled>
                                    <option value="achat">Achat</option>
                                    <option value="vente">Vente</option>
                                </select>
                            </div>
                            <div class="form-group" id="editSupplierGroup">
                                <label class="form-label"><i class="fas fa-truck"></i> Fournisseur</label>
                                <select name="supplier_id" id="editSupplierId" class="form-select">
                                    <option value="">Sélectionner un fournisseur</option>
                                    <?php foreach ($fournisseurs as $fourn): ?>
                                    <option value="<?= $fourn['id'] ?>"><?= htmlspecialchars($fourn['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="editClientGroup" style="display: none;">
                                <label class="form-label"><i class="fas fa-user"></i> Client</label>
                                <select name="client_id" id="editClientId" class="form-select">
                                    <option value="">Sélectionner un client</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-calendar"></i> Date commande</label>
                                <input type="date" name="order_date" id="editOrderDate" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-chart-simple"></i> Statut</label>
                                <select name="status" id="editStatus" class="form-select">
                                    <option value="en attente">En attente</option>
                                    <option value="confirmée">Confirmée</option>
                                    <option value="expédiée">Expédiée</option>
                                    <option value="livrée">Livrée</option>
                                    <option value="annulée">Annulée</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-align-left"></i> Notes</label>
                                <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="mb-3"><i class="fas fa-boxes me-2"></i> Produits commandés</h6>
                    <div class="table-responsive">
                        <table class="products-table table table-sm">
                            <thead>
                                <tr><th>Produit</th><th>Quantité</th><th>Prix unitaire HT</th><th>Total HT</th></tr>
                            </thead>
                            <tbody id="edit-products-list"></tbody>
                            <tfoot>
                                <tr><td colspan="4"><button type="button" id="editAddProductBtn" class="btn btn-sm btn-secondary"><i class="fas fa-plus"></i> Ajouter un produit</button></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>Sous-total:</strong></td><td><input type="text" id="editSubtotal" name="subtotal" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>TVA (18%):</strong></td><td><input type="text" id="editTaxAmount" name="tax_amount" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                                <tr class="table-light"><td colspan="3" class="text-end"><strong>Frais de livraison:</strong></td><td><input type="number" name="shipping_cost" id="editShippingCost" class="form-control" step="1" style="width: 140px;"></td></tr>
                                <tr class="table-active"><td colspan="3" class="text-end"><strong>Total TTC:</strong></td><td><input type="text" id="editTotalAmount" name="total_amount" class="form-control" readonly style="width: 140px; background:#f1f5f9;"></td></tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="info-box mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Les champs marqués d'un <span class="text-danger">*</span> sont obligatoires.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" name="edit_commande" class="btn-save-modal"><i class="fas fa-save me-2"></i> Mettre à jour</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE CONFIRMER SUPPRESSION -->
<div class="modal fade modal-form" id="deleteCommandeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i> Confirmer la suppression</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer la commande <strong id="deleteCommandeNumber"></strong> ?</p>
                <p class="text-muted small">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                <a href="#" id="confirmDeleteBtn" class="btn-save-modal" style="background: linear-gradient(95deg, #ef4444, #dc2626);"><i class="fas fa-trash-alt me-2"></i> Supprimer</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal de confirmation de déconnexion -->
<div id="logoutModal" class="modal-confirm">
    <div class="modal-confirm-content">
        <div class="modal-confirm-header">
            <div class="modal-confirm-icon"><i class="fas fa-sign-out-alt"></i></div>
            <h3>Déconnexion</h3>
            <p>Êtes-vous sûr de vouloir vous déconnecter ?</p>
        </div>
        <div class="modal-confirm-body">
            <i class="fas fa-info-circle me-1" style="color: #4f46e5;"></i>
            Vous serez redirigé vers la page de connexion.
        </div>
        <div class="modal-confirm-footer">
            <button class="btn-confirm btn-confirm-yes" id="confirmLogout"><i class="fas fa-check me-1"></i> Oui, déconnecter</button>
            <button class="btn-confirm btn-confirm-no" id="cancelLogout"><i class="fas fa-times me-1"></i> Annuler</button>
        </div>
    </div>
</div>

<script>
    // Dark mode toggle
    const toggle = document.getElementById('darkmode-toggle');
    const theme = localStorage.getItem('theme') || 'light';
    document.body.setAttribute('data-bs-theme', theme);
    if (theme === 'dark') toggle.checked = true;
    toggle.addEventListener('change', () => {
        if (toggle.checked) { document.body.setAttribute('data-bs-theme', 'dark'); localStorage.setItem('theme', 'dark'); }
        else { document.body.setAttribute('data-bs-theme', 'light'); localStorage.setItem('theme', 'light'); }
    });

    // Gestion de l'affichage des champs fournisseur/client selon le type (Ajout)
    const addTypeSelect = document.getElementById('addType');
    const addSupplierGroup = document.getElementById('addSupplierGroup');
    const addClientGroup = document.getElementById('addClientGroup');
    
    function toggleAddPartenaire() {
        if (addTypeSelect.value === 'achat') {
            addSupplierGroup.style.display = 'block';
            addClientGroup.style.display = 'none';
        } else {
            addSupplierGroup.style.display = 'none';
            addClientGroup.style.display = 'block';
        }
    }
    
    if (addTypeSelect) {
        addTypeSelect.addEventListener('change', toggleAddPartenaire);
        toggleAddPartenaire();
    }

    // Fonction pour calculer les totaux
    function calculateTotals() {
        let subtotal = 0;
        document.querySelectorAll('#products-list .product-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.product-quantity')?.value) || 0;
            const price = parseFloat(row.querySelector('.product-price')?.value) || 0;
            const total = qty * price;
            row.querySelector('.product-total').textContent = total.toFixed(0);
            subtotal += total;
        });
        
        const shipping = parseFloat(document.getElementById('shipping_cost')?.value) || 0;
        const tax = subtotal * 0.20;
        const total = subtotal + tax + shipping;
        
        document.getElementById('subtotal').value = subtotal.toFixed(0);
        document.getElementById('tax_amount').value = tax.toFixed(0);
        document.getElementById('total_amount').value = total.toFixed(0);
    }

    // Ajouter une ligne de produit
    document.getElementById('addProductBtn')?.addEventListener('click', function() {
        const tbody = document.getElementById('products-list');
        const newRow = document.createElement('tr');
        newRow.className = 'product-row';
        newRow.innerHTML = `
            <td>
                <select name="product_id[]" class="form-select product-select">
                    <option value="">Sélectionner un produit</option>
                    ${document.querySelector('#products-list .product-select')?.innerHTML || ''}
                </select>
            </td>
            <td><input type="number" name="quantity[]" class="form-control product-quantity" step="1" min="1" value="1" style="width: 100px;"></span></span></td>
            <td><input type="number" name="unit_price[]" class="form-control product-price" step="1" min="0" value="0" style="width: 120px;"></span></span></td>
            <td><span class="product-total">0</span> FCFA</span></span></td>
            <td><i class="fas fa-trash-alt remove-product text-danger"></i></span></span></td>
        `;
        tbody.appendChild(newRow);
        attachProductEvents(newRow);
        calculateTotals();
    });

    // Attacher les événements à une ligne
    function attachProductEvents(row) {
        const select = row.querySelector('.product-select');
        const quantity = row.querySelector('.product-quantity');
        const price = row.querySelector('.product-price');
        const remove = row.querySelector('.remove-product');
        
        if (select) {
            select.addEventListener('change', function() {
                const option = this.options[this.selectedIndex];
                const productPrice = option.dataset.price;
                if (productPrice) {
                    price.value = productPrice;
                    calculateTotals();
                }
            });
        }
        
        if (quantity) quantity.addEventListener('input', () => calculateTotals());
        if (price) price.addEventListener('input', () => calculateTotals());
        if (remove) remove.addEventListener('click', function() { row.remove(); calculateTotals(); });
    }

    // Initialiser les événements sur les lignes existantes
    document.querySelectorAll('#products-list .product-row').forEach(row => attachProductEvents(row));
    document.getElementById('shipping_cost')?.addEventListener('input', calculateTotals);
    calculateTotals();

    // ========== AJOUTER COMMANDE ==========
    document.getElementById('addCommandeForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('add_commande', '1');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('addCommandeModal'));
                modal.hide();
                location.reload();
            } else {
                alert('Erreur : ' + data.error);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue');
        });
    });

    // ========== VOIR COMMANDE ==========
    document.querySelectorAll('.view-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_commande&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    const type = data.type;
                    const typeClass = type === 'achat' ? 'achat' : 'vente';
                    const typeIcon = type === 'achat' ? 'fa-truck' : 'fa-user';
                    
                    let produitsHtml = '';
                    if (data.lignes && data.lignes.length > 0) {
                        produitsHtml = `
                            <table class="table table-sm">
                                <thead><tr><th>Produit</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr></thead>
                                <tbody>
                                    ${data.lignes.map(ligne => `
                                        <tr>
                                            <td>${escapeHtml(ligne.product_name)}</span></td>
                                            <td>${ligne.quantity}</span></td>
                                            <td>${parseFloat(ligne.unit_price).toLocaleString('fr-FR', {minimumFractionDigits: 0})} FCFA</span></td>
                                            <td>${(ligne.quantity * ligne.unit_price).toLocaleString('fr-FR', {minimumFractionDigits: 0})} FCFA</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        `;
                    } else {
                        produitsHtml = '<p class="text-muted">Aucun produit associé à cette commande.</p>';
                    }
                    
                    document.getElementById('viewCommandeBody').innerHTML = `
                        <div class="row-2cols">
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-hashtag"></i> Numéro commande</div>
                                    <div class="detail-value">${escapeHtml(data.order_number)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-exchange-alt"></i> Type</div>
                                    <div class="detail-value">
                                        <span class="badge-type ${typeClass}">
                                            <i class="fas ${typeIcon} me-1"></i> ${type === 'achat' ? 'Achat' : 'Vente'}
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas ${typeIcon}"></i> ${type === 'achat' ? 'Fournisseur' : 'Client'}</div>
                                    <div class="detail-value">${escapeHtml(data.partenaire) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-calendar"></i> Date commande</div>
                                    <div class="detail-value">${new Date(data.order_date).toLocaleDateString('fr-FR')}</div>
                                </div>
                            </div>
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-money-bill-wave"></i> Sous-total</div>
                                    <div class="detail-value">${parseFloat(data.subtotal || 0).toLocaleString('fr-FR', {minimumFractionDigits: 0})} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-percent"></i> TVA (20%)</div>
                                    <div class="detail-value">${parseFloat(data.tax_amount || 0).toLocaleString('fr-FR', {minimumFractionDigits: 0})} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-truck"></i> Frais de livraison</div>
                                    <div class="detail-value">${parseFloat(data.shipping_cost || 0).toLocaleString('fr-FR', {minimumFractionDigits: 0})} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-chart-simple"></i> Statut</div>
                                    <div class="detail-value">
                                        <span class="badge-status ${getStatusClass(data.status)}">${escapeHtml(data.status || 'En attente')}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <h6 class="mb-3"><i class="fas fa-boxes me-2"></i> Produits commandés</h6>
                        ${produitsHtml}
                        ${data.notes ? `<hr><div class="detail-item"><div class="detail-label"><i class="fas fa-align-left"></i> Notes</div><div class="detail-value">${escapeHtml(data.notes)}</div></div>` : ''}
                    `;
                    new bootstrap.Modal(document.getElementById('viewCommandeModal')).show();
                });
        });
    });

    // ========== MODIFIER COMMANDE ==========
    const editTypeSelect = document.getElementById('editType');
    const editSupplierGroupDiv = document.getElementById('editSupplierGroup');
    const editClientGroupDiv = document.getElementById('editClientGroup');
    
    function toggleEditPartenaire() {
        if (editTypeSelect && editTypeSelect.value === 'achat') {
            editSupplierGroupDiv.style.display = 'block';
            editClientGroupDiv.style.display = 'none';
        } else if (editTypeSelect) {
            editSupplierGroupDiv.style.display = 'none';
            editClientGroupDiv.style.display = 'block';
        }
    }
    
    document.querySelectorAll('.edit-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_commande&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('editId').value = data.id;
                    document.getElementById('editOrderNumber').value = data.order_number;
                    document.getElementById('editType').value = data.type;
                    toggleEditPartenaire();
                    
                    if (data.type === 'achat') {
                        document.getElementById('editSupplierId').value = data.supplier_id || '';
                    } else {
                        document.getElementById('editClientId').value = data.client_id || '';
                    }
                    
                    document.getElementById('editOrderDate').value = data.order_date;
                    document.getElementById('editStatus').value = data.status || 'en attente';
                    document.getElementById('editNotes').value = data.notes || '';
                    document.getElementById('editSubtotal').value = parseFloat(data.subtotal || 0).toFixed(0);
                    document.getElementById('editTaxAmount').value = parseFloat(data.tax_amount || 0).toFixed(0);
                    document.getElementById('editShippingCost').value = data.shipping_cost || 0;
                    document.getElementById('editTotalAmount').value = parseFloat(data.total_amount || 0).toFixed(0);
                    
                    // Afficher les produits (éditables)
                    const editProductsList = document.getElementById('edit-products-list');
                    if (data.lignes && data.lignes.length > 0) {
                        editProductsList.innerHTML = data.lignes.map(ligne => `
                            <tr class="product-row">
                                <td>
                                    <select name="product_id[]" class="form-select product-select" required>
                                        <option value="">Sélectionner un produit</option>
                                        ${document.querySelector('#products-list .product-select')?.innerHTML || ''}
                                    </select>
                                </td>
                                <td><input type="number" name="quantity[]" class="form-control product-quantity" step="1" min="1" value="${ligne.quantity}" style="width: 100px;"></span></span></td>
                                <td><input type="number" name="unit_price[]" class="form-control product-price" step="1" min="0" value="${ligne.unit_price}" style="width: 120px;"></span></span></td>
                                <td><span class="product-total">${(ligne.quantity * ligne.unit_price).toFixed(0)}</span> FCFA</span></span></td>
                                <td><i class="fas fa-trash-alt remove-product text-danger"></i></span></span></td>
                            </tr>
                        `).join('');
                        // Sélectionner le produit correct
                        data.lignes.forEach((ligne, index) => {
                            const row = editProductsList.children[index];
                            const select = row.querySelector('.product-select');
                            if (select) {
                                for (let option of select.options) {
                                    if (option.value == ligne.produit_id) {
                                        option.selected = true;
                                        break;
                                    }
                                }
                            }
                        });
                    } else {
                        editProductsList.innerHTML = '<tr class="product-row"><td><select name="product_id[]" class="form-select product-select" required><option value="">Sélectionner un produit</option>' + (document.querySelector('#products-list .product-select')?.innerHTML || '') + '</select></span></span></td><td><input type="number" name="quantity[]" class="form-control product-quantity" step="1" min="1" value="1" style="width: 100px;"></span></span></td><td><input type="number" name="unit_price[]" class="form-control product-price" step="1" min="0" value="0" style="width: 120px;"></span></span></td><td><span class="product-total">0</span> FCFA</span></span></td><td><i class="fas fa-trash-alt remove-product text-danger"></i></span></span></tr>';
                    }
                    
                    // Attacher les événements aux lignes d'édition
                    document.querySelectorAll('#edit-products-list .product-row').forEach(row => attachEditProductEvents(row));
                    calculateEditTotals();
                    
                    new bootstrap.Modal(document.getElementById('editCommandeModal')).show();
                });
        });
    });
    
    if (editTypeSelect) {
        editTypeSelect.addEventListener('change', toggleEditPartenaire);
    }

    // Attacher les événements pour les lignes d'édition
    function attachEditProductEvents(row) {
        const select = row.querySelector('.product-select');
        const quantity = row.querySelector('.product-quantity');
        const price = row.querySelector('.product-price');
        const remove = row.querySelector('.remove-product');
        
        if (select) {
            select.addEventListener('change', function() {
                const option = this.options[this.selectedIndex];
                const productPrice = option.dataset.price;
                if (productPrice) {
                    price.value = productPrice;
                    calculateEditTotals();
                }
            });
        }
        
        if (quantity) quantity.addEventListener('input', () => calculateEditTotals());
        if (price) price.addEventListener('input', () => calculateEditTotals());
        if (remove) remove.addEventListener('click', function() { row.remove(); calculateEditTotals(); });
    }

    // Calculs pour la modification
    function calculateEditTotals() {
        let subtotal = 0;
        document.querySelectorAll('#edit-products-list .product-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.product-quantity')?.value) || 0;
            const price = parseFloat(row.querySelector('.product-price')?.value) || 0;
            const total = qty * price;
            row.querySelector('.product-total').textContent = total.toFixed(0);
            subtotal += total;
        });
        
        const shipping = parseFloat(document.getElementById('editShippingCost')?.value) || 0;
        const tax = subtotal * 0.20;
        const total = subtotal + tax + shipping;
        
        document.getElementById('editSubtotal').value = subtotal.toFixed(0);
        document.getElementById('editTaxAmount').value = tax.toFixed(0);
        document.getElementById('editTotalAmount').value = total.toFixed(0);
    }
    
    // Ajouter une ligne de produit pour édition
    document.getElementById('editAddProductBtn')?.addEventListener('click', function() {
        const tbody = document.getElementById('edit-products-list');
        const newRow = document.createElement('tr');
        newRow.className = 'product-row';
        newRow.innerHTML = `
            <td>
                <select name="product_id[]" class="form-select product-select">
                    <option value="">Sélectionner un produit</option>
                    ${document.querySelector('#products-list .product-select')?.innerHTML || ''}
                </select>
            </td>
            <td><input type="number" name="quantity[]" class="form-control product-quantity" step="1" min="1" value="1" style="width: 100px;"></span></span></td>
            <td><input type="number" name="unit_price[]" class="form-control product-price" step="1" min="0" value="0" style="width: 120px;"></span></span></td>
            <td><span class="product-total">0</span> FCFA</span></span></td>
            <td><i class="fas fa-trash-alt remove-product text-danger"></i></span></span></td>
        `;
        tbody.appendChild(newRow);
        attachEditProductEvents(newRow);
        calculateEditTotals();
    });
    
    document.getElementById('editShippingCost')?.addEventListener('input', calculateEditTotals);

    // Soumission AJAX modification
    document.getElementById('editCommandeForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('edit_commande', '1');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editCommandeModal'));
                modal.hide();
                location.reload();
            } else {
                alert('Erreur : ' + data.error);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue');
        });
    });

    // ========== SUPPRIMER COMMANDE ==========
    let deleteId = null;
    document.querySelectorAll('.delete-action').forEach(btn => {
        btn.addEventListener('click', function() {
            deleteId = this.dataset.id;
            document.getElementById('deleteCommandeNumber').textContent = this.dataset.number;
            new bootstrap.Modal(document.getElementById('deleteCommandeModal')).show();
        });
    });

    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        if (deleteId) {
            window.location.href = `?delete_commande=${deleteId}`;
        }
    });

    function getStatusClass(status) {
        const s = (status || '').toLowerCase();
        if (s.includes('attente')) return 'en-attente';
        if (s.includes('confirm')) return 'confirmee';
        if (s.includes('expéd') || s.includes('expedi')) return 'expediee';
        if (s.includes('livr')) return 'livree';
        if (s.includes('annul')) return 'annulee';
        return 'en-attente';
    }

    // Fonction utilitaire pour échapper le HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const logoutBtn = document.getElementById('logoutBtn');
        const logoutModal = document.getElementById('logoutModal');
        const confirmBtn = document.getElementById('confirmLogout');
        const cancelBtn = document.getElementById('cancelLogout');
        const logoutPath = window.location.pathname.includes('/categories/') || window.location.pathname.includes('/Fournisseurs/') || window.location.pathname.includes('/produits/') || window.location.pathname.includes('/clients/') || window.location.pathname.includes('/commandes/') ? '../logout.php?confirm=yes' : 'logout.php?confirm=yes';

        if (logoutBtn && logoutModal) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                logoutModal.classList.add('show');
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                window.location.href = logoutPath;
            });
        }

        if (cancelBtn && logoutModal) {
            cancelBtn.addEventListener('click', function() {
                logoutModal.classList.remove('show');
            });
        }

        window.addEventListener('click', function(e) {
            if (logoutModal && e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && logoutModal && logoutModal.classList.contains('show')) {
                logoutModal.classList.remove('show');
            }
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>