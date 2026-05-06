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

// Ajouter un client
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_client'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $loyalty_points = intval($_POST['loyalty_points'] ?? 0);
    
    $response = ['success' => false, 'error' => ''];
    
    if (empty($name)) {
        $response['error'] = "Le nom du client est requis.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, address, city, country, loyalty_points) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $phone, $address, $city, $country, $loyalty_points])) {
            $response['success'] = true;
        } else {
            $response['error'] = "Erreur lors de l'enregistrement.";
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Modifier un client
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_client'])) {
    $id = $_POST['id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $loyalty_points = intval($_POST['loyalty_points'] ?? 0);
    
    $response = ['success' => false, 'error' => ''];
    
    if (empty($name)) {
        $response['error'] = "Le nom du client est requis.";
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, address = ?, city = ?, country = ?, loyalty_points = ? WHERE id = ?");
        if ($stmt->execute([$name, $email, $phone, $address, $city, $country, $loyalty_points, $id])) {
            $response['success'] = true;
        } else {
            $response['error'] = "Erreur lors de la modification.";
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Supprimer un client (soft delete)
if (isset($_GET['delete_client'])) {
    $id = $_GET['delete_client'];
    $stmt = $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php?deleted=1');
    exit;
}

// Récupérer un client pour affichage (AJAX)
if (isset($_GET['action']) && $_GET['action'] == 'get_client' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        header('Content-Type: application/json');
        echo json_encode($client);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Client non trouvé']);
    }
    exit;
}

// ======================== STATISTIQUES ========================
$stmt = $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL");
$totalClients = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL AND email IS NOT NULL AND email != ''");
$withEmail = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL AND phone IS NOT NULL AND phone != ''");
$withPhone = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(loyalty_points) FROM clients WHERE deleted_at IS NULL");
$totalPoints = (int) $stmt->fetchColumn();

// ======================== FILTRES & RECHERCHE ========================
$search = $_GET['search'] ?? '';
$filterCountry = $_GET['filter_country'] ?? 'all';

// ======================== PAGINATION ========================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Compter le total avec les filtres
$countSql = "SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL";
$countParams = [];

if (!empty($search)) {
    $countSql .= " AND (name LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $countParams[':search'] = "%$search%";
}

if ($filterCountry !== 'all') {
    $countSql .= " AND country = :country";
    $countParams[':country'] = $filterCountry;
}

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($countParams);
$totalClientsFiltered = $stmtCount->fetchColumn();
$totalPages = ceil($totalClientsFiltered / $perPage);

// ======================== REQUÊTE PRINCIPALE ========================
$sql = "SELECT * FROM clients WHERE deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (name LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($filterCountry !== 'all') {
    $sql .= " AND country = :country";
    $params[':country'] = $filterCountry;
}

$sql .= " ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

$stmtCountries = $pdo->query("SELECT DISTINCT country FROM clients WHERE deleted_at IS NULL AND country IS NOT NULL AND country != '' ORDER BY country");
$countries = $stmtCountries->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients - Gestion de Stock</title>
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
        .badge-points {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            font-weight: 700;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.85rem;
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

<!-- Barre de navigation unifiée -->
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
                <li class="nav-item"><a class="nav-link active" href="index.php"><i class="fas fa-users me-1"></i> Clients</a></li>
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
    <!-- Cartes statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-title">TOTAL CLIENTS</div>
            <div class="stat-number"><?= $totalClients ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-envelope"></i></div>
            <div class="stat-title">AVEC EMAIL</div>
            <div class="stat-number"><?= $withEmail ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-phone-alt"></i></div>
            <div class="stat-title">AVEC TÉLÉPHONE</div>
            <div class="stat-number"><?= $withPhone ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-star"></i></div>
            <div class="stat-title">POINTS FIDÉLITÉ</div>
            <div class="stat-number"><?= $totalPoints ?></div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card-table">
        <div class="table-header">
            <h2><i class="fas fa-users me-2"></i> Gestion des clients</h2>
            <div class="table-header-actions">
                <div class="table-header-info"><i class="fas fa-database"></i> Total : <?= count($clients) ?> client(s)</div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-plus me-1"></i> Nouveau client
                </button>
            </div>
        </div>

        <div class="filters-bar">
            <form method="GET" class="d-flex flex-wrap gap-2 w-100">
                <div class="input-group" style="max-width: 280px;">
                    <span class="input-group-text bg-transparent border-end-0"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control search-input" placeholder="Nom, email, téléphone..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="filter_country" class="form-select rounded-pill" style="width: auto;">
                    <option value="all" <?= $filterCountry == 'all' ? 'selected' : '' ?>>Tous les pays</option>
                    <?php foreach ($countries as $country): ?>
                        <option value="<?= htmlspecialchars($country) ?>" <?= $filterCountry == $country ? 'selected' : '' ?>><?= htmlspecialchars($country) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="index.php" class="btn btn-secondary btn-custom"><i class="fas fa-undo-alt"></i> Réinitialiser</a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr><th>ID</th><th>Nom</th><th>Email</th><th>Téléphone</th><th>Adresse</th><th>Ville</th><th>Pays</th><th>Points</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach($clients as $client): ?>
                    <tr>
                        <td data-label="ID"><?= $client['id'] ?></td>
                        <td data-label="Nom"><strong><?= htmlspecialchars($client['name']) ?></strong></td>
                        <td data-label="Email"><a href="mailto:<?= htmlspecialchars($client['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($client['email']) ?></a></td>
                        <td data-label="Téléphone"><?php if(!empty($client['phone'])): ?><a href="tel:<?= htmlspecialchars($client['phone']) ?>"><?= htmlspecialchars($client['phone']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td data-label="Adresse"><?= htmlspecialchars($client['address'] ?: '—') ?></td>
                        <td data-label="Ville"><?= htmlspecialchars($client['city'] ?: '—') ?></td>
                        <td data-label="Pays"><?= htmlspecialchars($client['country'] ?: '—') ?></td>
                        <td data-label="Points"><span class="badge-points"><i class="fas fa-star me-1"></i> <?= (int)($client['loyalty_points'] ?? 0) ?></span></td>
                        <td data-label="Actions">
                            <div class="action-icons">
                                <button class="view-action" data-id="<?= $client['id'] ?>" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="edit-action" data-id="<?= $client['id'] ?>" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-action" data-id="<?= $client['id'] ?>" data-name="<?= htmlspecialchars($client['name']) ?>" title="Supprimer">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(count($clients) === 0): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-user-friends fa-2x mb-2 d-block"></i>Aucun client trouvé. <a href="#" data-bs-toggle="modal" data-bs-target="#addClientModal">Ajoutez-en un</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <nav aria-label="Pagination des clients">
                <ul class="pagination justify-content-center">
                    <?php
                    // Construire la query string de base
                    $baseUrl = 'index.php?' . http_build_query(array_filter([
                        'search' => $search,
                        'filter_country' => $filterCountry !== 'all' ? $filterCountry : null
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
            <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Retour au dashboard</a>
        </div>
    </div>
</div>

<!-- MODALE AJOUTER CLIENT -->
<div class="modal fade modal-form" id="addClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Nouveau client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="addClientForm" method="POST">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-user"></i> Nom complet *</label>
                                <input type="text" name="name" class="form-control" placeholder="Nom du client" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-phone"></i> Téléphone</label>
                                <input type="text" name="phone" class="form-control" placeholder="+221 77 123 45 67">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-location-dot"></i> Adresse</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="Adresse complète"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-city"></i> Ville</label>
                                <input type="text" name="city" class="form-control" placeholder="Dakar, Thiès...">
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" name="email" class="form-control" placeholder="client@email.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-globe"></i> Pays</label>
                                <input type="text" name="country" class="form-control" placeholder="Sénégal">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-star"></i> Points fidélité</label>
                                <input type="number" name="loyalty_points" class="form-control" value="0" step="10">
                            </div>
                        </div>
                    </div>
                    <div class="info-box mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Les champs marqués d'un <span class="text-danger">*</span> sont obligatoires.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" name="add_client" class="btn-save-modal"><i class="fas fa-save me-2"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE VOIR CLIENT -->
<div class="modal fade modal-form" id="viewClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Détails du client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="viewClientBody">
                <!-- Contenu chargé via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE MODIFIER CLIENT -->
<div class="modal fade modal-form" id="editClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen-alt me-2"></i> Modifier le client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="editClientForm" method="POST">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-user"></i> Nom complet *</label>
                                <input type="text" name="name" id="editName" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-phone"></i> Téléphone</label>
                                <input type="text" name="phone" id="editPhone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-location-dot"></i> Adresse</label>
                                <textarea name="address" id="editAddress" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-city"></i> Ville</label>
                                <input type="text" name="city" id="editCity" class="form-control">
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" name="email" id="editEmail" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-globe"></i> Pays</label>
                                <input type="text" name="country" id="editCountry" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-star"></i> Points fidélité</label>
                                <input type="number" name="loyalty_points" id="editPoints" class="form-control" step="10">
                            </div>
                        </div>
                    </div>
                    <div class="info-box mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Les champs marqués d'un <span class="text-danger">*</span> sont obligatoires.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-modal" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i> Annuler</button>
                    <button type="submit" name="edit_client" class="btn-save-modal"><i class="fas fa-save me-2"></i> Mettre à jour</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE CONFIRMER SUPPRESSION -->
<div class="modal fade modal-form" id="deleteClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i> Confirmer la suppression</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer le client <strong id="deleteClientName"></strong> ?</p>
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

    // ========== AJOUTER CLIENT ==========
    document.getElementById('addClientForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('add_client', '1');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('addClientModal'));
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

    // ========== VOIR CLIENT ==========
    document.querySelectorAll('.view-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_client&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('viewClientBody').innerHTML = `
                        <div class="row-2cols">
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-user"></i> Nom</div>
                                    <div class="detail-value">${escapeHtml(data.name)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-envelope"></i> Email</div>
                                    <div class="detail-value">${escapeHtml(data.email) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-phone"></i> Téléphone</div>
                                    <div class="detail-value">${escapeHtml(data.phone) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-star"></i> Points fidélité</div>
                                    <div class="detail-value"><span class="badge-points"><i class="fas fa-star me-1"></i> ${data.loyalty_points || 0}</span></div>
                                </div>
                            </div>
                            <div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-location-dot"></i> Adresse</div>
                                    <div class="detail-value">${escapeHtml(data.address) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-city"></i> Ville</div>
                                    <div class="detail-value">${escapeHtml(data.city) || '—'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="fas fa-globe"></i> Pays</div>
                                    <div class="detail-value">${escapeHtml(data.country) || '—'}</div>
                                </div>
                            </div>
                        </div>
                    `;
                    new bootstrap.Modal(document.getElementById('viewClientModal')).show();
                });
        });
    });

    // ========== MODIFIER CLIENT ==========
    document.querySelectorAll('.edit-action').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch(`?action=get_client&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('editId').value = data.id;
                    document.getElementById('editName').value = data.name;
                    document.getElementById('editEmail').value = data.email || '';
                    document.getElementById('editPhone').value = data.phone || '';
                    document.getElementById('editAddress').value = data.address || '';
                    document.getElementById('editCity').value = data.city || '';
                    document.getElementById('editCountry').value = data.country || '';
                    document.getElementById('editPoints').value = data.loyalty_points || 0;
                    new bootstrap.Modal(document.getElementById('editClientModal')).show();
                });
        });
    });

    // Soumission AJAX modification
    document.getElementById('editClientForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('edit_client', '1');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editClientModal'));
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

    // ========== SUPPRIMER CLIENT ==========
    let deleteId = null;
    document.querySelectorAll('.delete-action').forEach(btn => {
        btn.addEventListener('click', function() {
            deleteId = this.dataset.id;
            document.getElementById('deleteClientName').textContent = this.dataset.name;
            new bootstrap.Modal(document.getElementById('deleteClientModal')).show();
        });
    });

    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        if (deleteId) {
            window.location.href = `?delete_client=${deleteId}`;
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