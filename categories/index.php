<?php
session_start(); // pour la déconnexion
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

// Traitement AJAX pour ajouter une catégorie
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '');

    $response = ['success' => false, 'error' => ''];
    
    if (empty($name)) {
        $response['error'] = "Le nom de la catégorie est requis.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO categories (name, description, color) VALUES (?, ?, ?)");
        if ($stmt->execute([$name, $description, $color])) {
            $response['success'] = true;
        } else {
            $response['error'] = "Erreur lors de l'enregistrement.";
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Traitement AJAX pour modifier une catégorie
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category'])) {
    $id = $_POST['id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '');

    $response = ['success' => false, 'error' => ''];
    
    if (empty($name)) {
        $response['error'] = "Le nom de la catégorie est requis.";
    } else {
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ?");
        if ($stmt->execute([$name, $description, $color, $id])) {
            $response['success'] = true;
        } else {
            $response['error'] = "Erreur lors de la modification.";
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Traitement pour supprimer (soft delete)
if (isset($_GET['delete_category'])) {
    $id = $_GET['delete_category'];
    $stmt = $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php?deleted=1');
    exit;
}

// Récupérer une catégorie pour affichage (AJAX)
if (isset($_GET['action']) && $_GET['action'] == 'get_category' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($category) {
        header('Content-Type: application/json');
        echo json_encode($category);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Catégorie non trouvée']);
    }
    exit;
}

// Statistiques
$stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL");
$totalCategories = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL AND description IS NOT NULL AND description != ''");
$withDesc = $stmt->fetchColumn();

// ======================== FILTRES & RECHERCHE ========================
$search = $_GET['search'] ?? '';

// ======================== PAGINATION ========================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Compter le total avec les filtres
$countSql = "SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL";
$countParams = [];
if (!empty($search)) {
    $countSql .= " AND name LIKE :search";
    $countParams[':search'] = "%$search%";
}

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($countParams);
$totalCategoriesFiltered = $stmtCount->fetchColumn();
$totalPages = ceil($totalCategoriesFiltered / $perPage);

// ======================== REQUÊTE PRINCIPALE ========================
$sql = "SELECT * FROM categories WHERE deleted_at IS NULL";
$params = [];
if (!empty($search)) {
    $sql .= " AND name LIKE :search";
    $params[':search'] = "%$search%";
}
$sql .= " ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catégories - Gestion de Stock</title>
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin: 2rem 1rem 2rem 1rem;
        }
        .stat-card {
            border-radius: 28px;
            padding: 1.3rem 1.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(2px);
        }
        body[data-bs-theme="light"] .stat-card {
            background: linear-gradient(145deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.95) 100%);
            box-shadow: 0 12px 25px -10px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(79, 70, 229, 0.2);
        }
        body[data-bs-theme="dark"] .stat-card {
            background: linear-gradient(145deg, #1e293b, #0f172a);
            border: 1px solid rgba(79, 70, 229, 0.3);
            box-shadow: 0 12px 25px -10px rgba(0, 0, 0, 0.5);
        }
        .stat-card:hover { transform: translateY(-6px); }
        .stat-icon {
            position: absolute;
            right: 1rem;
            bottom: 0.8rem;
            font-size: 3rem;
            opacity: 0.15;
            pointer-events: none;
        }
        .stat-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #4f46e5;
            margin-bottom: 0.5rem;
        }
        body[data-bs-theme="dark"] .stat-title { color: #818cf8; }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.25rem;
        }
        .stat-sub {
            font-size: 0.7rem;
            color: #64748b;
        }
        .card-table {
            border: none;
            border-radius: 28px;
            overflow: hidden;
            margin: 0 1rem 2rem 1rem;
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
            margin-bottom: 1rem;
            padding: 0 1.5rem;
        }
        .search-input {
            border-radius: 60px;
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
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
        .table-custom td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9eef3;
        }
        body[data-bs-theme="dark"] .table-custom td { border-bottom-color: #334155; }
        .color-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            vertical-align: middle;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        /* Boutons d'action - uniquement l'icône sans fond */
        .action-icons {
            display: flex;
            gap: 12px;
        }
        .action-icons a, .action-icons button {
            background: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            font-size: 1.2rem;
            cursor: pointer;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        .action-icons a:hover, .action-icons button:hover {
            transform: scale(1.1);
        }
        .action-icons .view-action { color: #0dcaf0; }
        .action-icons .edit-action { color: #f59e0b; }
        .action-icons .delete-action { color: #ef4444; }
        body[data-bs-theme="dark"] .action-icons .view-action { color: #22d3ee; }
        body[data-bs-theme="dark"] .action-icons .edit-action { color: #fbbf24; }
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

        /* Modales */
        .modal-form .modal-content {
            border-radius: 24px;
            border: none;
            overflow: hidden;
        }
        body[data-bs-theme="dark"] .modal-form .modal-content { background: #1e293b; }
        .modal-form .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e9ecef;
            background: #f8fafc;
        }
        body[data-bs-theme="dark"] .modal-form .modal-header {
            background: #0f172a;
            border-bottom-color: #334155;
        }
        .modal-form .modal-header h5 { font-weight: 700; font-size: 1.3rem; }
        .modal-form .modal-body { padding: 2rem; }
        .modal-form .modal-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid #e9ecef;
            background: #f8fafc;
        }
        body[data-bs-theme="dark"] .modal-form .modal-footer {
            background: #0f172a;
            border-top-color: #334155;
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: #475569;
            display: block;
        }
        body[data-bs-theme="dark"] .form-label { color: #cbd5e1; }
        .form-label i { width: 24px; color: #4f46e5; }
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
        .info-box i { margin-right: 8px; color: #4f46e5; }
        .color-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .color-group input[type="color"] {
            width: 70px;
            height: 48px;
            padding: 0.25rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .color-group input[type="text"] { flex: 1; }
        textarea.form-control { border-radius: 16px; }
        .btn-cancel-modal {
            padding: 0.5rem 1.5rem;
            border-radius: 40px;
            background: #e2e8f0;
            color: #475569;
            border: none;
        }
        .btn-cancel-modal:hover { background: #cbd5e6; }
        .btn-save-modal {
            padding: 0.5rem 1.8rem;
            border-radius: 40px;
            background: linear-gradient(95deg, #4f46e5, #6366f1);
            color: white;
            border: none;
        }
        .btn-save-modal:hover { background: linear-gradient(95deg, #4338ca, #4f46e5); }
        .detail-item {
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #eef2f6;
            padding-bottom: 0.75rem;
        }
        body[data-bs-theme="dark"] .detail-item { border-bottom-color: #334155; }
        .detail-label {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .detail-value { font-size: 1.1rem; font-weight: 500; }
        
        @media (max-width: 640px) {
            .row-2cols { grid-template-columns: 1fr; gap: 1rem; }
        }
        
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
                <li class="nav-item"><a class="nav-link active" href="index.php"><i class="fas fa-tags me-1"></i> Catégories</a></li>
                <li class="nav-item"><a class="nav-link" href="../fournisseurs/index.php"><i class="fas fa-truck me-1"></i> Fournisseurs</a></li>
                <li class="nav-item"><a class="nav-link" href="../produits/index.php"><i class="fas fa-boxes me-1"></i> Produits</a></li>
                <li class="nav-item"><a class="nav-link" href="../clients/index.php"><i class="fas fa-users me-1"></i> Clients</a></li>
                <li class="nav-item"><a class="nav-link" href="../commandes/index.php"><i class="fas fa-shopping-cart me-1"></i> Commandes</a></li>
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
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-title">TOTAL CATÉGORIES</div>
            <div class="stat-number"><?= $totalCategories ?></div>
            <div class="stat-sub">actives</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-align-left"></i></div>
            <div class="stat-title">AVEC DESCRIPTION</div>
            <div class="stat-number"><?= $withDesc ?></div>
            <div class="stat-sub">détaillées</div>
        </div>
    </div>

    <div class="card-table">
        <div class="table-header">
            <h2><i class="fas fa-tags me-2"></i> Gestion des catégories</h2>
            <div class="table-header-actions">
                <div class="table-header-info"><i class="fas fa-database"></i> Total : <?= count($categories) ?> catégorie(s)</div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus me-1"></i> Nouvelle catégorie
                </button>
            </div>
        </div>

        <div class="filters-bar">
            <form method="GET" class="d-flex flex-wrap gap-2 w-100">
                <div class="input-group" style="max-width: 260px;">
                    <span class="input-group-text bg-transparent border-end-0"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control search-input" placeholder="Nom..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="index.php" class="btn btn-secondary btn-custom"><i class="fas fa-undo-alt"></i> Réinitialiser</a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Description</th>
                        <th>Couleur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($categories as $cat): ?>
                    <tr>
                        <td data-label="ID"><?= $cat['id'] ?></td>
                        <td data-label="Nom">
                            <strong><?= htmlspecialchars($cat['name']) ?></strong>
                        </td>
                        <td data-label="Description"><?= htmlspecialchars($cat['description'] ?: '—') ?></td>
                        <td data-label="Couleur">
                            <?php if(!empty($cat['color'])): ?>
                                <span class="color-badge" style="background-color:<?= htmlspecialchars($cat['color']) ?>"></span>
                                <small><?= htmlspecialchars($cat['color']) ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-icons">
                                <button class="view-action" data-id="<?= $cat['id'] ?>" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="edit-action" data-id="<?= $cat['id'] ?>" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-action" data-id="<?= $cat['id'] ?>" data-name="<?= htmlspecialchars($cat['name']) ?>" title="Supprimer">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(count($categories) === 0): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-folder-open fa-2x mb-2 d-block"></i>Aucune catégorie. <a href="#" data-bs-toggle="modal" data-bs-target="#addCategoryModal">Ajoutez-en une</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <nav aria-label="Pagination des catégories">
                <ul class="pagination justify-content-center">
                    <?php
                    $baseUrl = 'index.php?' . http_build_query(array_filter([
                        'search' => $search
                    ]));

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
            <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Retour au dashboard</a>
        </div>
    </div>
</div>

<!-- MODALE AJOUT -->
<div class="modal fade modal-form" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Nouvelle catégorie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="addCategoryForm">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-tag"></i> Nom *</label>
                                <input type="text" name="name" class="form-control" placeholder="Ex: Électronique, Vêtements..." required>
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Description optionnelle..."></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-palette"></i> Couleur (hexadécimal)</label>
                                <div class="color-group">
                                    <input type="color" name="color" id="addColorPicker" value="">
                                    <input type="text" id="addColorHex" class="form-control" value="" placeholder="#6c757d">
                                </div>
                            </div>
                            <div class="info-box">
                                <i class="fas fa-check-circle"></i> La catégorie sera immédiatement disponible.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" class="btn-save-modal"><i class="fas fa-save me-2"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE VISUALISATION -->
<div class="modal fade modal-form" id="viewCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Détails de la catégorie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="viewCategoryBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE MODIFICATION -->
<div class="modal fade modal-form" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen-alt me-2"></i> Modifier la catégorie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="editCategoryForm">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-tag"></i> Nom *</label>
                                <input type="text" name="name" id="editName" class="form-control" required>
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                                <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-palette"></i> Couleur (hexadécimal)</label>
                                <div class="color-group">
                                    <input type="color" name="color" id="editColorPicker" value="">
                                    <input type="text" id="editColorHex" class="form-control" value="">
                                </div>
                            </div>
                            <div class="info-box">
                                <i class="fas fa-check-circle"></i> Modifications immédiates.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" class="btn-save-modal"><i class="fas fa-save me-2"></i> Mettre à jour</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE SUPPRESSION -->
<div class="modal fade modal-form" id="deleteCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i> Confirmer la suppression</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer la catégorie <strong id="deleteCategoryName"></strong> ?</p>
                <p class="text-muted small">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                <button type="button" id="confirmDeleteBtn" class="btn-save-modal" style="background: linear-gradient(95deg, #ef4444, #dc2626);"><i class="fas fa-trash-alt me-2"></i> Supprimer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DECONNEXION -->
<div id="logoutModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i> Déconnexion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir vous déconnecter ?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal">Annuler</button>
                <a href="?logout=1" id="confirmLogout" class="btn-save-modal">Déconnecter</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Gestion du thème
    const toggle = document.getElementById('darkmode-toggle');
    const theme = localStorage.getItem('theme') || 'light';
    document.body.setAttribute('data-bs-theme', theme);
    if (theme === 'dark') toggle.checked = true;
    toggle.addEventListener('change', () => {
        if (toggle.checked) { 
            document.body.setAttribute('data-bs-theme', 'dark'); 
            localStorage.setItem('theme', 'dark'); 
        } else { 
            document.body.setAttribute('data-bs-theme', 'light'); 
            localStorage.setItem('theme', 'light'); 
        }
    });

    // Synchronisation des champs de couleur pour l'ajout
    const addColorPicker = document.getElementById('addColorPicker');
    const addColorHex = document.getElementById('addColorHex');
    if (addColorPicker && addColorHex) {
        addColorPicker.addEventListener('input', () => { addColorHex.value = addColorPicker.value; });
        addColorHex.addEventListener('input', () => { addColorPicker.value = addColorHex.value; });
    }

    // Synchronisation des champs de couleur pour la modification
    const editColorPicker = document.getElementById('editColorPicker');
    const editColorHex = document.getElementById('editColorHex');
    if (editColorPicker && editColorHex) {
        editColorPicker.addEventListener('input', () => { editColorHex.value = editColorPicker.value; });
        editColorHex.addEventListener('input', () => { editColorPicker.value = editColorHex.value; });
    }

    // Fonction pour échapper le HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    // === GESTION DE L'AJOUT ===
    document.getElementById('addCategoryForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('add_category', '1');
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addCategoryModal'));
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
    
    // === GESTION DE LA VISUALISATION ===
    document.querySelectorAll('.view-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_category&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    let colorHtml = '';
                    if (data.color) {
                        colorHtml = `<div class="detail-item">
                            <div class="detail-label"><i class="fas fa-palette me-1"></i> Couleur</div>
                            <div class="detail-value"><span class="color-badge" style="background-color: ${escapeHtml(data.color)};"></span> ${escapeHtml(data.color)}</div>
                        </div>`;
                    }
                    
                    document.getElementById('viewCategoryBody').innerHTML = `
                        <div class="row-2cols">
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-tag me-1"></i> Nom</div>
                                    <div class="detail-value">${escapeHtml(data.name)}</div>
                                </div>
                            </div>
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-align-left me-1"></i> Description</div>
                                    <div class="detail-value">${escapeHtml(data.description) || 'Aucune description'}</div>
                                </div>
                                ${colorHtml}
                            </div>
                        </div>
                    `;
                    
                    const viewModal = new bootstrap.Modal(document.getElementById('viewCategoryModal'));
                    viewModal.show();
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des détails');
                });
        });
    });
    
    // === GESTION DE LA MODIFICATION ===
    document.querySelectorAll('.edit-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_category&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    document.getElementById('editId').value = data.id;
                    document.getElementById('editName').value = data.name;
                    document.getElementById('editDescription').value = data.description || '';
                    
                    const colorVal = data.color || '';
                    if (editColorPicker) editColorPicker.value = colorVal;
                    if (editColorHex) editColorHex.value = colorVal;
                    
                    const editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
                    editModal.show();
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des données');
                });
        });
    });
    
    // Formulaire de modification
    document.getElementById('editCategoryForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('edit_category', '1');
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editCategoryModal'));
                    modal.hide();
                    location.reload();
                } else {
                    alert('Erreur : ' + data.error);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de la modification');
            });
    });
    
    // === GESTION DE LA SUPPRESSION ===
    let deleteId = null;
    
    document.querySelectorAll('.delete-action').forEach(btn => {
        btn.addEventListener('click', function() {
            deleteId = this.dataset.id;
            document.getElementById('deleteCategoryName').textContent = this.dataset.name;
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
            deleteModal.show();
        });
    });
    
    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
        if (deleteId) {
            window.location.href = `?delete_category=${deleteId}`;
        }
    });
    
    // === GESTION DE LA DECONNEXION ===
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'), {});
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            logoutModal.show();
        });
    }
</script>
</body>
</html>