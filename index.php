<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Empêcher la mise en cache pour la sécurité
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'config/database.php';

// ==================== STATISTIQUES GÉNÉRALES ====================

$stats = [];

$query = $pdo->query("SELECT COUNT(*) as count FROM categories WHERE deleted_at IS NULL");
$stats['categories'] = $query->fetch(PDO::FETCH_ASSOC)['count'];

$query = $pdo->query("SELECT COUNT(*) as count FROM clients WHERE deleted_at IS NULL");
$stats['clients'] = $query->fetch(PDO::FETCH_ASSOC)['count'];

$query = $pdo->query("SELECT COUNT(*) as count FROM fournisseurs WHERE deleted_at IS NULL");
$stats['fournisseurs'] = $query->fetch(PDO::FETCH_ASSOC)['count'];

$query = $pdo->query("SELECT COUNT(*) as count FROM produits WHERE deleted_at IS NULL");
$stats['produits'] = $query->fetch(PDO::FETCH_ASSOC)['count'];

$query = $pdo->query("SELECT COUNT(*) as count FROM commandes WHERE type = 'vente' AND deleted_at IS NULL");
$stats['commandes'] = $query->fetch(PDO::FETCH_ASSOC)['count'];

$query = $pdo->query("SELECT SUM(price * stock_quantity) as total FROM produits WHERE deleted_at IS NULL");
$stats['stock_value'] = $query->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ==================== STATISTIQUES TABLE STOCK ====================

$query = $pdo->query("SELECT COUNT(*) as count FROM stock WHERE deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL");
$stats['stock_entries'] = $query->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

$query = $pdo->query("SELECT SUM(total_amount) as total FROM stock WHERE deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL");
$stats['stock_total'] = $query->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ==================== DERNIÈRES COMMANDES ====================

$query_recent_orders = $pdo->prepare("
    SELECT 
        c.order_number,
        c.order_date,
        cl.quantity,
        p.name as product_name
    FROM commandes c
    LEFT JOIN commande_lignes cl ON c.id = cl.commande_id
    LEFT JOIN produits p ON cl.produit_id = p.id
    WHERE c.type = 'vente' AND c.deleted_at IS NULL
    ORDER BY c.order_date DESC, c.id DESC
    LIMIT 5
");
$query_recent_orders->execute();
$recent_orders = $query_recent_orders->fetchAll(PDO::FETCH_ASSOC);

// ==================== TOP 5 CLIENTS FIDÈLES ====================

$query_top_clients = $pdo->prepare("
    SELECT 
        id,
        name,
        loyalty_points
    FROM clients
    WHERE deleted_at IS NULL
    ORDER BY loyalty_points DESC
    LIMIT 5
");
$query_top_clients->execute();
$top_clients = $query_top_clients->fetchAll(PDO::FETCH_ASSOC);

$max_points = !empty($top_clients) ? max(array_column($top_clients, 'loyalty_points')) : 0;

// ==================== GRAPHIQUE COMMANDES PAR PRODUIT ====================

$query = $pdo->prepare("
    SELECT 
        p.name as produit_name,
        p.id as produit_id,
        SUM(cl.quantity) as total_quantity,
        DATE_FORMAT(c.order_date, '%Y-%m') as month,
        DATE_FORMAT(c.order_date, '%b %Y') as month_label
    FROM commandes c
    JOIN commande_lignes cl ON c.id = cl.commande_id
    JOIN produits p ON cl.produit_id = p.id
    WHERE c.type = 'vente' 
        AND c.deleted_at IS NULL
        AND c.order_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY p.id, DATE_FORMAT(c.order_date, '%Y-%m')
    ORDER BY month ASC, total_quantity DESC
");
$query->execute();
$monthly_data = $query->fetchAll(PDO::FETCH_ASSOC);

// ==================== GRAPHIQUE CATÉGORIES VENDUES ====================

$query = $pdo->prepare("
    SELECT 
        cat.name as categorie_name,
        SUM(cl.quantity) as total_quantity
    FROM commandes c
    JOIN commande_lignes cl ON c.id = cl.commande_id
    JOIN produits p ON cl.produit_id = p.id
    JOIN categories cat ON p.category_id = cat.id
    WHERE c.type = 'vente' 
        AND c.deleted_at IS NULL
        AND c.order_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY cat.id, cat.name
    ORDER BY total_quantity DESC
    LIMIT 8
");
$query->execute();
$categories_data = $query->fetchAll(PDO::FETCH_ASSOC);

// ==================== PRÉPARATION DONNÉES GRAPHIQUES ====================

$month_labels = [];
$chart_data = [];
$no_data = empty($monthly_data);
$has_pie_data = !empty($categories_data);

if (!$no_data) {
    $months = [];
    $products = [];
    $data_matrix = [];

    foreach ($monthly_data as $row) {
        $month = $row['month'];
        $product = $row['produit_name'];
        $quantity = (int)$row['total_quantity'];
        
        if (!in_array($month, $months)) {
            $months[] = $month;
        }
        if (!in_array($product, $products)) {
            $products[] = $product;
        }
        
        $data_matrix[$month][$product] = $quantity;
    }

    sort($months);

    $product_totals = [];
    foreach ($data_matrix as $month => $products_data) {
        foreach ($products_data as $product => $qty) {
            if (!isset($product_totals[$product])) {
                $product_totals[$product] = 0;
            }
            $product_totals[$product] += $qty;
        }
    }
    arsort($product_totals);
    $top_products = array_slice(array_keys($product_totals), 0, 8);

    $month_labels = [];
    foreach ($months as $month) {
        $date = DateTime::createFromFormat('Y-m', $month);
        $month_labels[] = $date->format('M Y');
    }

    $chart_data = [];
    foreach ($top_products as $product) {
        $product_data = [];
        foreach ($months as $month) {
            $product_data[] = isset($data_matrix[$month][$product]) ? $data_matrix[$month][$product] : 0;
        }
        $chart_data[] = [
            'label' => $product,
            'data' => $product_data
        ];
    }
}

$pie_labels = [];
$pie_data = [];
if ($has_pie_data) {
    foreach ($categories_data as $cat) {
        $pie_labels[] = $cat['categorie_name'];
        $pie_data[] = (int)$cat['total_quantity'];
    }
}
?>

<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de stock - Tableau de bord</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
        body[data-bs-theme="dark"] .navbar {
            background: #020617 !important;
        }
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
        .navbar-nav .nav-link:hover {
            color: white;
            background-color: rgba(255,255,255,0.1);
        }
        .navbar-nav .nav-link.active {
            color: white;
            background-color: #4f46e5;
        }

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
        .theme-switch input {
            display: none;
        }
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
        input:checked + .slider {
            background-color: #4f46e5;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        .slider i {
            position: absolute;
            top: 6px;
            font-size: 12px;
            z-index: 1;
        }
        .fa-sun { left: 8px; color: #fbbf24; }
        .fa-moon { right: 8px; color: #f1f5f9; }

        .stat-card {
            border: none;
            border-radius: 28px;
            transition: all 0.35s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            overflow: hidden;
            background: rgba(255,255,255,0.95);
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.1);
            height: 100%;
        }
        body[data-bs-theme="dark"] .stat-card {
            background: #1e293b;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.4);
        }
        .stat-card:hover {
            transform: translateY(-8px);
        }
        .stat-card-body {
            padding: 2rem 1.5rem;
            text-align: center;
        }
        .stat-card .stat-number {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 0.5rem;
            color: #4f46e5;
        }
        body[data-bs-theme="dark"] .stat-card .stat-number {
            color: #818cf8;
        }
        .stat-card .stat-label {
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 1.2rem;
            color: #475569;
        }
        body[data-bs-theme="dark"] .stat-card .stat-label {
            color: #cbd5e1;
        }
        .btn-outline-stat {
            border-radius: 60px;
            padding: 0.6rem 1.4rem;
            font-weight: 600;
            border: 1px solid currentColor;
            background: transparent;
            transition: all 0.2s;
        }
        .btn-outline-stat:hover {
            background-color: #4f46e5;
            border-color: #4f46e5;
            color: white !important;
            transform: translateY(-2px);
        }

        .kpi-value {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(6, 182, 212, 0.1));
            border-radius: 60px;
            padding: 0.6rem 1.5rem;
            text-align: center;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(79, 70, 229, 0.2);
        }
        body[data-bs-theme="dark"] .kpi-value {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.2), rgba(6, 182, 212, 0.2));
            border-color: rgba(79, 70, 229, 0.3);
        }

        .footer {
            text-align: center;
            margin-top: 3rem;
            padding: 1.2rem;
            font-size: 0.8rem;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
        body[data-bs-theme="dark"] .footer {
            border-top-color: #334155;
        }

        /* CARDS DES GRAPHIQUES - PLEINE HAUTEUR */
        .chart-card {
            background: rgba(255,255,255,0.95);
            border-radius: 28px;
            padding: 1.5rem;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
            height: 550px;
            display: flex;
            flex-direction: column;
        }
        body[data-bs-theme="dark"] .chart-card {
            background: #1e293b;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.4);
        }
        .chart-card:hover {
            transform: translateY(-5px);
        }
        .chart-header {
            border-bottom: 2px solid rgba(79, 70, 229, 0.2);
            padding-bottom: 1rem;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }
        
        /* CONTENEURS DES GRAPHIQUES */
        .chart-container {
            flex: 1;
            width: 100%;
            position: relative;
            min-height: 0;
        }
        
        .pie-chart-container {
            flex: 1;
            width: 100%;
            position: relative;
            min-height: 0;
        }
        
        /* FORCER LE CANVAS À PRENDRE TOUTE LA PLACE */
        .chart-container canvas,
        .pie-chart-container canvas {
            max-width: 100% !important;
            max-height: 100% !important;
            width: auto !important;
            height: auto !important;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card, .chart-card {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
        .chart-card { animation-delay: 0.5s; }

        .no-data-card {
            background: rgba(255,255,255,0.95);
            border-radius: 28px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        body[data-bs-theme="dark"] .no-data-card {
            background: #1e293b;
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
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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
        
        .stats-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            border-radius: 60px;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .border-top {
            border-top: 1px solid rgba(0,0,0,0.1);
        }
        
        body[data-bs-theme="dark"] .border-top {
            border-top-color: rgba(255,255,255,0.1);
        }
        
        .small {
            font-size: 0.75rem;
        }
        
        .text-primary {
            color: #4f46e5 !important;
        }
        
        .text-success {
            color: #10b981 !important;
        }
        
        .text-muted {
            color: #64748b !important;
        }
        
        body[data-bs-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        @media (max-width: 768px) {
            .chart-card {
                height: 450px;
            }
        }

        .dashboard-table-card {
            background: rgba(255,255,255,0.95);
            border-radius: 28px;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }
        body[data-bs-theme="dark"] .dashboard-table-card {
            background: #1e293b;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.4);
        }

        .dashboard-table-card:hover {
            transform: translateY(-5px);
        }

        .dashboard-table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.03), rgba(6, 182, 212, 0.03));
        }
        body[data-bs-theme="dark"] .dashboard-table-header {
            border-bottom-color: rgba(255,255,255,0.1);
        }

        .dashboard-table-body {
            padding: 0;
            max-height: 450px;
            overflow-y: auto;
        }

        .dashboard-table-body .table {
            margin-bottom: 0;
        }

        .dashboard-table-body .table thead th {
            background: rgba(0,0,0,0.02);
            border-bottom: 2px solid rgba(0,0,0,0.05);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        body[data-bs-theme="dark"] .dashboard-table-body .table thead th {
            background: rgba(255,255,255,0.05);
            border-bottom-color: rgba(255,255,255,0.1);
        }

        .dashboard-table-body .table tbody td {
            padding: 0.85rem 1rem;
            vertical-align: middle;
        }

        .ranking-badge {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .ranking-number {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.05);
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
        }
        body[data-bs-theme="dark"] .ranking-number {
            background: rgba(255,255,255,0.1);
            color: #cbd5e1;
        }

        .points-container {
            min-width: 100px;
        }

        .points-value {
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .text-bronze {
            color: #cd7f32;
        }

        .dashboard-table-body::-webkit-scrollbar {
            width: 6px;
        }

        .dashboard-table-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
            border-radius: 10px;
        }
        body[data-bs-theme="dark"] .dashboard-table-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
        }

        .dashboard-table-body::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
        }
        body[data-bs-theme="dark"] .dashboard-table-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
        }

        .dashboard-table-body::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.3);
        }
        body[data-bs-theme="dark"] .dashboard-table-body::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }

        @media (max-width: 768px) {
            .dashboard-table-header {
                padding: 1rem;
            }
            
            .dashboard-table-body .table thead th,
            .dashboard-table-body .table tbody td {
                padding: 0.6rem 0.75rem;
                font-size: 0.8rem;
            }
            
            .ranking-badge {
                width: 28px;
                height: 28px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body data-bs-theme="light">

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-cubes me-2"></i> Gestion de Stock
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link active" href="index.php"><i class="fas fa-chart-line me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="categories/index.php"><i class="fas fa-tags me-1"></i> Catégories</a></li>
                <li class="nav-item"><a class="nav-link" href="fournisseurs/index.php"><i class="fas fa-truck me-1"></i> Fournisseurs</a></li>
                <li class="nav-item"><a class="nav-link" href="produits/index.php"><i class="fas fa-boxes me-1"></i> Produits</a></li>
                <li class="nav-item"><a class="nav-link" href="clients/index.php"><i class="fas fa-users me-1"></i> Clients</a></li>
                <li class="nav-item"><a class="nav-link" href="commandes/index.php"><i class="fas fa-shopping-cart me-1"></i> Commandes</a></li>
                <li class="nav-item"><a class="nav-link" href="stock/index.php"><i class="fas fa-database me-1"></i> Stock</a></li>
                <li class="nav-item">
                    <a class="nav-link" href="#" id="logoutBtn">
                        <i class="fas fa-sign-out-alt me-1"></i> Déconnexion
                    </a>
                </li>
            </ul>
            <div class="theme-switch-wrapper">
                <label class="theme-switch">
                    <input type="checkbox" id="darkmode-toggle">
                    <span class="slider">
                        <i class="fas fa-sun"></i>
                        <i class="fas fa-moon"></i>
                    </span>
                </label>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="text-center mb-5">
        <h1 class="display-5 fw-bold" style="background: linear-gradient(135deg, #4f46e5, #06b6d4, #10b981); -webkit-background-clip: text; background-clip: text; color: transparent;">
            Tableau de bord
        </h1>
        <p class="lead text-muted">Vue d'ensemble de votre inventaire</p>
    </div>

    <!-- KPI Valeur du stock -->
    <div class="row justify-content-center mb-4">
        <div class="col-md-6">
            <div class="kpi-value d-flex justify-content-between align-items-center px-4 py-3">
                <span class="fw-semibold"><i class="fas fa-chart-line me-2"></i> Valeur totale du stock</span>
                <span class="h4 mb-0 fw-bold"><?= number_format($stats['stock_value'], 0, ',', ' ') ?> FCFA</span>
            </div>
        </div>
    </div>

    <!-- Cartes statistiques principales -->
    <div class="row g-4 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['categories'] ?></div>
                    <div class="stat-label"><i class="fas fa-tag me-1"></i> Catégorie<?= ($stats['categories'] > 1) ? 's' : '' ?></div>
                    <a href="categories/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['fournisseurs'] ?></div>
                    <div class="stat-label"><i class="fas fa-truck me-1"></i> Fournisseur<?= ($stats['fournisseurs'] > 1) ? 's' : '' ?></div>
                    <a href="fournisseurs/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['produits'] ?></div>
                    <div class="stat-label"><i class="fas fa-boxes me-1"></i> Produit<?= ($stats['produits'] > 1) ? 's' : '' ?></div>
                    <a href="produits/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['clients'] ?></div>
                    <div class="stat-label"><i class="fas fa-users me-1"></i> Client<?= ($stats['clients'] > 1) ? 's' : '' ?></div>
                    <a href="clients/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Cartes Commandes et Stock (même taille que les autres) -->
    <div class="row g-4 mb-5">
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['commandes'] ?></div>
                    <div class="stat-label"><i class="fas fa-shopping-cart me-1"></i> Commande<?= ($stats['commandes'] > 1) ? 's' : '' ?> de vente</div>
                    <a href="commandes/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-number"><?= $stats['stock_entries'] ?></div>
                    <div class="stat-label"><i class="fas fa-database me-1"></i> Entrée<?= ($stats['stock_entries'] > 1) ? 's' : '' ?> dans stock</div>
                    <div class="small text-muted mt-1">Total: <?= number_format($stats['stock_total'], 0, ',', ' ') ?> FCFA</div>
                    <a href="stock/index.php" class="btn btn-sm btn-outline-primary mt-2 rounded-pill">Voir <i class="fas fa-eye ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section Graphiques -->
<div class="container mt-5 mb-5">
    <div class="row g-4">
        <!-- Diagramme Circulaire -->
        <div class="col-lg-6">
            <?php if (!$has_pie_data): ?>
            <div class="no-data-card">
                <i class="fas fa-chart-pie fa-4x mb-3" style="color: #4f46e5;"></i>
                <h4 class="fw-bold mb-3">Aucune donnée disponible</h4>
                <p class="text-muted mb-4">Aucune vente par catégorie trouvée sur les 12 derniers mois.</p>
                <a href="commandes/create.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-plus me-2"></i>Ajouter une commande
                </a>
            </div>
            <?php else: ?>
            <div class="chart-card">
                <div class="chart-header">
                    <h4 class="fw-bold mb-1">
                        <i class="fas fa-chart-pie me-2" style="color: #4f46e5;"></i>
                        Ventes par catégorie
                    </h4>
                    <p class="text-muted small mb-0">Distribution des quantités vendues (12 mois)</p>
                </div>
                <div class="pie-chart-container">
                    <canvas id="categoriesPieChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Diagramme à Barres -->
        <div class="col-lg-6">
            <?php if ($no_data): ?>
            <div class="no-data-card">
                <i class="fas fa-chart-bar fa-4x mb-3" style="color: #4f46e5;"></i>
                <h4 class="fw-bold mb-3">Aucune donnée de commande</h4>
                <p class="text-muted mb-4">Aucune commande de type "vente" trouvée sur les 12 derniers mois.</p>
                <a href="commandes/create.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-plus me-2"></i>Ajouter une commande
                </a>
            </div>
            <?php else: ?>
            <div class="chart-card">
                <div class="chart-header">
                    <h4 class="fw-bold mb-1">
                        <i class="fas fa-chart-bar me-2" style="color: #4f46e5;"></i>
                        Commandes par produit
                    </h4>
                    <p class="text-muted small mb-0">Comparaison mensuelle des ventes (12 mois)</p>
                </div>
                <div class="chart-container">
                    <canvas id="productsChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Section Dernières commandes et Clients fidèles (côte à côte) -->
<div class="container mt-5 mb-5">
    <div class="row g-4">
        <!-- Dernières commandes -->
        <div class="col-lg-6">
            <div class="dashboard-table-card">
                <div class="dashboard-table-header">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="fas fa-clock me-2" style="color: #4f46e5;"></i>
                            Dernières commandes
                        </h4>
                        <p class="text-muted small mb-0">Les 5 dernières commandes de vente</p>
                    </div>
                </div>
                <div class="dashboard-table-body">
                    <?php if (empty($recent_orders)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Aucune commande trouvée</p>
                            <a href="commandes/create.php" class="btn btn-primary btn-sm rounded-pill">
                                <i class="fas fa-plus me-1"></i>Ajouter une commande
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>N° Commande</th>
                                        <th>Produit</th>
                                        <th>Quantité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold small"><?= htmlspecialchars($order['order_number']) ?></span>
                                                <br>
                                                <small class="text-muted"><?= date('d/m/Y', strtotime($order['order_date'])) ?></small>
                                             </td>
                                            <td><?= htmlspecialchars($order['product_name'] ?? '-') ?></td>
                                            <td><?= $order['quantity'] ?? '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 5 clients fidèles -->
        <div class="col-lg-6">
            <div class="dashboard-table-card">
                <div class="dashboard-table-header">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="fas fa-trophy me-2" style="color: #f59e0b;"></i>
                            Clients les plus fidèles
                        </h4>
                        <p class="text-muted small mb-0">Top 5 des meilleurs points de fidélité</p>
                    </div>
                </div>
                <div class="dashboard-table-body">
                    <?php if (empty($top_clients)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Aucun client trouvé</p>
                            <a href="clients/create.php" class="btn btn-primary btn-sm rounded-pill">
                                <i class="fas fa-plus me-1"></i>Ajouter un client
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Points de fidélité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_clients as $index => $client): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="ranking-badge me-2">
                                                        <?php if ($index == 0): ?>
                                                            <i class="fas fa-crown text-warning"></i>
                                                        <?php elseif ($index == 1): ?>
                                                            <i class="fas fa-medal text-secondary"></i>
                                                        <?php elseif ($index == 2): ?>
                                                            <i class="fas fa-medal text-bronze"></i>
                                                        <?php else: ?>
                                                            <span class="ranking-number"><?= $index + 1 ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($client['name']) ?></div>
                                                        <small class="text-muted">ID: #<?= $client['id'] ?></small>
                                                    </div>
                                                </div>
                                             </td>
                                            <td>
                                                <div class="points-container">
                                                    <div class="points-value mb-1">
                                                        <span class="fw-bold text-warning"><?= number_format($client['loyalty_points']) ?></span>
                                                        <span class="text-muted small"> pts</span>
                                                    </div>
                                                    <?php if ($max_points > 0): ?>
                                                        <div class="progress" style="height: 6px;">
                                                            <div class="progress-bar bg-warning" 
                                                                 style="width: <?= ($client['loyalty_points'] / $max_points) * 100 ?>%"
                                                                 role="progressbar"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                             </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="footer">
    <i class="fas fa-chart-pie me-1"></i> Gestion de stock — Suivi simplifié | © <?= date('Y') ?> Tous droits réservés
</div>

<!-- Modal de confirmation déconnexion -->
<div id="logoutModal" class="modal-confirm">
    <div class="modal-confirm-content">
        <div class="modal-confirm-header">
            <div class="modal-confirm-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h3>Déconnexion</h3>
            <p>Êtes-vous sûr de vouloir vous déconnecter ?</p>
        </div>
        <div class="modal-confirm-body">
            <i class="fas fa-info-circle me-1" style="color: #4f46e5;"></i>
            Vous serez redirigé vers la page de connexion.
        </div>
        <div class="modal-confirm-footer">
            <button class="btn-confirm btn-confirm-yes" id="confirmLogout">
                <i class="fas fa-check me-1"></i> Oui, déconnecter
            </button>
            <button class="btn-confirm btn-confirm-no" id="cancelLogout">
                <i class="fas fa-times me-1"></i> Annuler
            </button>
        </div>
    </div>
</div>

<script>
    // Dark mode
    const toggleSwitch = document.getElementById('darkmode-toggle');
    const currentTheme = localStorage.getItem('theme') || 'light';
    document.body.setAttribute('data-bs-theme', currentTheme);
    if (currentTheme === 'dark') toggleSwitch.checked = true;

    toggleSwitch.addEventListener('change', function() {
        if (this.checked) {
            document.body.setAttribute('data-bs-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        } else {
            document.body.setAttribute('data-bs-theme', 'light');
            localStorage.setItem('theme', 'light');
        }
        if (window.myChart) updateChartColors();
        if (window.pieChart) updatePieChartColors();
    });

    // Déconnexion
    document.addEventListener('DOMContentLoaded', function() {
        const logoutBtn = document.getElementById('logoutBtn');
        const logoutModal = document.getElementById('logoutModal');
        const confirmBtn = document.getElementById('confirmLogout');
        const cancelBtn = document.getElementById('cancelLogout');
        
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                logoutModal.classList.add('show');
            });
        }
        
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                window.location.href = 'logout.php?confirm=yes';
            });
        }
        
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                logoutModal.classList.remove('show');
            });
        }
        
        window.addEventListener('click', function(e) {
            if (e.target === logoutModal) logoutModal.classList.remove('show');
        });
    });
</script>

<?php if (!$no_data): ?>
<script>
const chartMonths = <?php echo json_encode($month_labels); ?>;
const chartDatasets = <?php echo json_encode($chart_data); ?>;

const colorPalette = [
    { bg: 'rgba(79, 70, 229, 0.8)', border: 'rgba(79, 70, 229, 1)' },
    { bg: 'rgba(6, 182, 212, 0.8)', border: 'rgba(6, 182, 212, 1)' },
    { bg: 'rgba(16, 185, 129, 0.8)', border: 'rgba(16, 185, 129, 1)' },
    { bg: 'rgba(245, 158, 11, 0.8)', border: 'rgba(245, 158, 11, 1)' },
    { bg: 'rgba(239, 68, 68, 0.8)', border: 'rgba(239, 68, 68, 1)' },
    { bg: 'rgba(139, 92, 246, 0.8)', border: 'rgba(139, 92, 246, 1)' },
    { bg: 'rgba(236, 72, 153, 0.8)', border: 'rgba(236, 72, 153, 1)' },
    { bg: 'rgba(34, 197, 94, 0.8)', border: 'rgba(34, 197, 94, 1)' }
];

const datasets = chartDatasets.map((item, index) => ({
    label: item.label,
    data: item.data,
    backgroundColor: colorPalette[index % colorPalette.length].bg,
    borderColor: colorPalette[index % colorPalette.length].border,
    borderWidth: 2,
    borderRadius: 6,
    barPercentage: 0.8,
    categoryPercentage: 0.85
}));

function getChartColors() {
    const isDark = document.body.getAttribute('data-bs-theme') === 'dark';
    return {
        gridColor: isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)',
        textColor: isDark ? '#e2e8f0' : '#475569',
        titleColor: isDark ? '#f1f5f9' : '#1e293b',
        tooltipBg: isDark ? '#1e293b' : 'white',
        tooltipTitle: isDark ? '#f1f5f9' : '#1e293b',
        tooltipBody: isDark ? '#cbd5e1' : '#475569'
    };
}

let myChart;

function initChart() {
    const ctx = document.getElementById('productsChart').getContext('2d');
    const colors = getChartColors();
    if (myChart) myChart.destroy();
    myChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: chartMonths, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, color: colors.textColor } },
                tooltip: { backgroundColor: colors.tooltipBg, titleColor: colors.tooltipTitle, bodyColor: colors.tooltipBody }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: colors.gridColor }, title: { display: true, text: 'Quantité' }, ticks: { color: colors.textColor } },
                x: { grid: { display: false }, ticks: { color: colors.textColor, maxRotation: 45, minRotation: 45 } }
            }
        }
    });
}

function updateChartColors() {
    if (myChart) {
        const colors = getChartColors();
        myChart.options.scales.y.grid.color = colors.gridColor;
        myChart.options.scales.y.ticks.color = colors.textColor;
        myChart.options.scales.x.ticks.color = colors.textColor;
        myChart.options.plugins.legend.labels.color = colors.textColor;
        myChart.update();
    }
}

window.addEventListener('load', function() { setTimeout(initChart, 100); });
</script>
<?php endif; ?>

<?php if ($has_pie_data): ?>
<script>
const pieLabels = <?php echo json_encode($pie_labels); ?>;
const pieData = <?php echo json_encode($pie_data); ?>;
const pieColorPalette = ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#22c55e'];
const pieBackgroundColors = pieLabels.map((_, i) => pieColorPalette[i % pieColorPalette.length]);

function getPieChartColors() {
    const isDark = document.body.getAttribute('data-bs-theme') === 'dark';
    return { textColor: isDark ? '#e2e8f0' : '#475569', tooltipBg: isDark ? '#1e293b' : 'white' };
}

let pieChart;

function initPieChart() {
    const ctx = document.getElementById('categoriesPieChart').getContext('2d');
    const colors = getPieChartColors();
    if (pieChart) pieChart.destroy();
    pieChart = new Chart(ctx, {
        type: 'pie',
        data: { labels: pieLabels, datasets: [{ data: pieData, backgroundColor: pieBackgroundColors, borderWidth: 2 }] },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, color: colors.textColor } },
                tooltip: { backgroundColor: colors.tooltipBg, callbacks: { label: function(ctx) {
                    const total = ctx.dataset.data.reduce((a,b) => a + b, 0);
                    const pct = ((ctx.raw / total) * 100).toFixed(1);
                    return `${ctx.label}: ${ctx.raw} u (${pct}%)`;
                } } }
            }
        }
    });
}

function updatePieChartColors() {
    if (pieChart) {
        const colors = getPieChartColors();
        pieChart.options.plugins.legend.labels.color = colors.textColor;
        pieChart.update();
    }
}

window.addEventListener('load', function() { setTimeout(initPieChart, 100); });
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>