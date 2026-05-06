<?php
session_start();
require_once '../config/database.php';

// Empêcher la mise en cache pour la sécurité
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

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

// Supprimer une entrée stock (soft delete)
if (isset($_GET['delete_stock'])) {
    $id = $_GET['delete_stock'];
    $stmt = $pdo->prepare("UPDATE stock SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php?deleted=1');
    exit;
}

// Récupérer une entrée stock pour affichage (AJAX)
if (isset($_GET['action']) && $_GET['action'] == 'get_stock' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as customer_name 
        FROM stock s
        LEFT JOIN clients c ON s.customer_id = c.id
        WHERE s.id = ? AND (s.deleted_at = '0000-00-00 00:00:00' OR s.deleted_at IS NULL)
    ");
    $stmt->execute([$id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stock) {
        header('Content-Type: application/json');
        echo json_encode($stock);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Entrée stock non trouvée']);
    }
    exit;
}

// ======================== STATISTIQUES ========================
$stmt = $pdo->query("SELECT COUNT(*) FROM stock WHERE deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL");
$totalEntries = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(total_amount) FROM stock WHERE deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL");
$totalAmount = (float) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(DISTINCT customer_id) FROM stock WHERE customer_id > 0 AND (deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL)");
$uniqueCustomers = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM stock 
    WHERE payment_status = 'livrée' AND (deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL)
");
$deliveredCount = $stmt->fetchColumn();

// ======================== FILTRES & RECHERCHE ========================
$search = $_GET['search'] ?? '';
$filterStatus = $_GET['filter_status'] ?? 'all';
$filterPayment = $_GET['filter_payment'] ?? 'all';

// ======================== PAGINATION ========================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Compter le total avec les filtres
$countSql = "SELECT COUNT(*) FROM stock WHERE (deleted_at = '0000-00-00 00:00:00' OR deleted_at IS NULL)";
$countParams = [];

if (!empty($search)) {
    $countSql .= " AND (sale_number LIKE :search OR notes LIKE :search)";
    $countParams[':search'] = "%$search%";
}

if ($filterStatus !== 'all') {
    $countSql .= " AND payment_status = :status";
    $countParams[':status'] = $filterStatus;
}

if ($filterPayment !== 'all') {
    $countSql .= " AND payment_method = :payment";
    $countParams[':payment'] = $filterPayment;
}

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($countParams);
$totalEntriesFiltered = $stmtCount->fetchColumn();
$totalPages = ceil($totalEntriesFiltered / $perPage);

// ======================== REQUÊTE PRINCIPALE ========================
$sql = "
    SELECT s.*, c.name as customer_name 
    FROM stock s
    LEFT JOIN clients c ON s.customer_id = c.id
    WHERE (s.deleted_at = '0000-00-00 00:00:00' OR s.deleted_at IS NULL)
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (s.sale_number LIKE :search OR s.notes LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($filterStatus !== 'all') {
    $sql .= " AND s.payment_status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterPayment !== 'all') {
    $sql .= " AND s.payment_method = :payment";
    $params[':payment'] = $filterPayment;
}

$sql .= " ORDER BY s.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stockEntries = $stmt->fetchAll();

// Récupérer les statuts et méthodes de paiement uniques pour les filtres
$stmtStatus = $pdo->query("SELECT DISTINCT payment_status FROM stock WHERE payment_status != '' ORDER BY payment_status");
$statusList = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);

$stmtPayment = $pdo->query("SELECT DISTINCT payment_method FROM stock WHERE payment_method != '' ORDER BY payment_method");
$paymentList = $stmtPayment->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrées Stock - Gestion de Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
            font-size: 2.2rem;
            font-weight: 800;
            margin-top: 0.5rem;
            line-height: 1.2;
        }
        body[data-bs-theme="light"] .stat-number { color: #0f172a; }
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
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 0 1.5rem 1rem 1.5rem;
            align-items: center;
        }
        .search-input {
            border-radius: 60px;
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            background: white;
        }
        body[data-bs-theme="dark"] .search-input {
            background: #0f172a;
            border-color: #334155;
            color: white;
        }
        .btn-custom {
            border-radius: 60px;
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
        body[data-bs-theme="dark"] .table-custom td {
            border-bottom-color: #334155;
        }
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-livree { background: #10b981; color: white; }
        .badge-expediee { background: #3b82f6; color: white; }
        .badge-attente { background: #f59e0b; color: white; }
        .badge-annulee { background: #ef4444; color: white; }
        
        /* Boutons d'action */
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
        .action-icons button:hover { transform: scale(1.1); }
        .action-icons .view-action { color: #0dcaf0; }
        .action-icons .delete-action { color: #ef4444; }
        body[data-bs-theme="dark"] .action-icons .view-action { color: #22d3ee; }
        body[data-bs-theme="dark"] .action-icons .delete-action { color: #f87171; }
        
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

        /* MODALES */
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

        /* Confirmation modal */
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
        .modal-confirm-body {
            padding: 1rem 1.5rem;
            text-align: center;
            color: #475569;
            font-size: 0.95rem;
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

        /* PAGINATION */
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
        .page-item.active .page-link {
            background-color: #4f46e5;
            border-color: #4f46e5;
            color: white;
        }

        @media (max-width: 768px) {
            .navbar-nav { flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .nav-link { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
            .table-custom thead { display: none; }
            .table-custom tbody tr { display: block; margin-bottom: 1rem; border-radius: 16px; }
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
                <li class="nav-item"><a class="nav-link" href="../commandes/index.php"><i class="fas fa-shopping-cart me-1"></i> Commandes</a></li>
                <li class="nav-item"><a class="nav-link active" href="index.php"><i class="fas fa-database me-1"></i> Stock</a></li>
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
            <div class="stat-icon"><i class="fas fa-database"></i></div>
            <div class="stat-title">TOTAL ENTREES</div>
            <div class="stat-number"><?= $totalEntries ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-title">MONTANT TOTAL</div>
            <div class="stat-number"><?= number_format($totalAmount, 0, ',', ' ') ?> FCFA</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-title">CLIENTS UNIQUES</div>
            <div class="stat-number"><?= $uniqueCustomers ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-title">COMMANDES LIVRÉES</div>
            <div class="stat-number"><?= $deliveredCount ?></div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card-table">
        <div class="table-header">
            <h2><i class="fas fa-database me-2"></i> Entrées dans stock</h2>
            <div class="table-header-actions">
                <div class="table-header-info"><i class="fas fa-database"></i> Total : <?= count($stockEntries) ?> entrée(s)</div>
            </div>
        </div>

        <div class="filters-bar">
            <form method="GET" class="d-flex flex-wrap gap-2 w-100">
                <div class="input-group" style="max-width: 280px;">
                    <span class="input-group-text bg-transparent border-end-0"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control search-input" placeholder="N° vente, notes..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="filter_status" class="form-select rounded-pill" style="width: auto;">
                    <option value="all" <?= $filterStatus == 'all' ? 'selected' : '' ?>>Tous les statuts</option>
                    <?php foreach ($statusList as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus == $status ? 'selected' : '' ?>><?= ucfirst(htmlspecialchars($status)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_payment" class="form-select rounded-pill" style="width: auto;">
                    <option value="all" <?= $filterPayment == 'all' ? 'selected' : '' ?>>Tous les modes de paiement</option>
                    <?php foreach ($paymentList as $payment): ?>
                        <option value="<?= htmlspecialchars($payment) ?>" <?= $filterPayment == $payment ? 'selected' : '' ?>><?= ucfirst(htmlspecialchars($payment)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="index.php" class="btn btn-secondary btn-custom"><i class="fas fa-undo-alt"></i> Réinitialiser</a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>N° Vente</th>
                        <th>Client</th>
                        <th>Montant total</th>
                        <th>Statut</th>
                        <th>Paiement</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($stockEntries as $entry): ?>
                    <tr>
                        <td data-label="ID"><?= $entry['id'] ?></td>
                        <td data-label="N° Vente"><strong><?= htmlspecialchars($entry['sale_number']) ?></strong></td>
                        <td data-label="Client"><?= htmlspecialchars($entry['customer_name'] ?? '—') ?></td>
                        <td data-label="Montant total"><strong><?= number_format($entry['total_amount'], 0, ',', ' ') ?> FCFA</strong></td>
                        <td data-label="Statut">
                            <?php
                            $statusClass = '';
                            switch($entry['payment_status']) {
                                case 'livrée': $statusClass = 'badge-livree'; break;
                                case 'expédiée': $statusClass = 'badge-expediee'; break;
                                case 'en attente': $statusClass = 'badge-attente'; break;
                                case 'annulée': $statusClass = 'badge-annulee'; break;
                                default: $statusClass = 'badge-attente';
                            }
                            ?>
                            <span class="badge-status <?= $statusClass ?>"><?= ucfirst(htmlspecialchars($entry['payment_status'])) ?></span>
                        </td>
                        <td data-label="Paiement"><?= ucfirst(htmlspecialchars($entry['payment_method'])) ?></td>
                        <td data-label="Notes"><?= htmlspecialchars(substr($entry['notes'] ?? '', 0, 30)) ?><?= strlen($entry['notes'] ?? '') > 30 ? '...' : '' ?></td>
                        <td data-label="Actions">
                            <div class="action-icons">
                                <button class="view-action" data-id="<?= $entry['id'] ?>" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="delete-action" data-id="<?= $entry['id'] ?>" data-number="<?= htmlspecialchars($entry['sale_number']) ?>" title="Supprimer">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(count($stockEntries) === 0): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-database fa-2x mb-2 d-block"></i>Aucune entrée stock trouvée.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <nav aria-label="Pagination des entrées stock">
                <ul class="pagination justify-content-center">
                    <?php
                    $baseUrl = 'index.php?' . http_build_query(array_filter([
                        'search' => $search,
                        'filter_status' => $filterStatus !== 'all' ? $filterStatus : null,
                        'filter_payment' => $filterPayment !== 'all' ? $filterPayment : null
                    ]));

                    if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $page - 1 ?>" aria-label="Précédent">«</a>
                        </li>
                    <?php endif;

                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);

                    if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&page=1">1</a></li>
                        <?php if ($start > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif;
                    endif;

                    for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;

                    if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                    <?php endif;

                    if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $page + 1 ?>" aria-label="Suivant">»</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <div class="footer-actions">
            <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Retour au dashboard</a>
        </div>
    </div>
</div>

<!-- MODALE VOIR STOCK -->
<div class="modal fade modal-form" id="viewStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Détails de l'entrée stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="viewStockBody">
                <!-- Contenu chargé via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE CONFIRMER SUPPRESSION -->
<div class="modal fade modal-form" id="deleteStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i> Confirmer la suppression</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer l'entrée stock <strong id="deleteStockNumber"></strong> ?</p>
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

    // ========== VOIR STOCK ==========
    document.querySelectorAll('.view-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_stock&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('viewStockBody').innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-hashtag"></i> ID</div>
                                    <div class="detail-value">${data.id}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-receipt"></i> N° Vente</div>
                                    <div class="detail-value"><strong>${escapeHtml(data.sale_number)}</strong></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-user"></i> Client</div>
                                    <div class="detail-value">${escapeHtml(data.customer_name) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-user-check"></i> ID Client</div>
                                    <div class="detail-value">${data.customer_id || '—'}</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-money-bill-wave"></i> Sous-total</div>
                                    <div class="detail-value">${Number(data.subtotal).toLocaleString()} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-percent"></i> Taxes</div>
                                    <div class="detail-value">${Number(data.tax_amount).toLocaleString()} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-tag"></i> Remise</div>
                                    <div class="detail-value">${Number(data.discount_amount).toLocaleString()} FCFA</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-chart-line"></i> Montant total</div>
                                    <div class="detail-value"><strong class="text-success">${Number(data.total_amount).toLocaleString()} FCFA</strong></div>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-truck"></i> Statut</div>
                                    <div class="detail-value">${escapeHtml(data.payment_status) || '—'}</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-credit-card"></i> Mode de paiement</div>
                                    <div class="detail-value">${escapeHtml(data.payment_method) || '—'}</div>
                                </div>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-sticky-note"></i> Notes</div>
                            <div class="detail-value">${escapeHtml(data.notes) || '—'}</div>
                        </div>
                    `;
                    new bootstrap.Modal(document.getElementById('viewStockModal')).show();
                });
        });
    });

    // ========== SUPPRIMER STOCK ==========
    let deleteId = null;
    document.querySelectorAll('.delete-action').forEach(btn => {
        btn.addEventListener('click', function() {
            deleteId = this.dataset.id;
            document.getElementById('deleteStockNumber').textContent = this.dataset.number;
            new bootstrap.Modal(document.getElementById('deleteStockModal')).show();
        });
    });

    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        if (deleteId) {
            window.location.href = `?delete_stock=${deleteId}`;
        }
    });

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

    // Déconnexion
    document.addEventListener('DOMContentLoaded', function() {
        const logoutBtn = document.getElementById('logoutBtn');
        const logoutModal = document.getElementById('logoutModal');
        const confirmBtn = document.getElementById('confirmLogout');
        const cancelBtn = document.getElementById('cancelLogout');
        const logoutPath = '../logout.php?confirm=yes';

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
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>