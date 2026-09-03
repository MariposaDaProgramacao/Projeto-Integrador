<?php
// ==========================================================
// listar_usuarios.php - Listagem com filtros e paginação
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../AUTENTIFICACAO_ACESSO/realizar_login.php');
    exit;
}

if (!in_array($_SESSION['usuario_cargo'], ['administrador', 'coordenador'])) {
    $_SESSION['erro'] = 'Acesso negado. Apenas administradores e coordenadores podem acessar.';
    header('Location: ../AUTENTIFICACAO_ACESSO/dashboard.php');
    exit;
}

$caminhoBanco = __DIR__ . '/../conexao_banco.php';
if (!file_exists($caminhoBanco)) {
    die('Arquivo de conexão não encontrado.');
}
require_once $caminhoBanco;
if (!isset($pdo)) {
    die('Erro: conexão com banco não estabelecida.');
}

$cargoUsuario = $_SESSION['usuario_cargo'];
$idUnidadeUsuario = $_SESSION['usuario_unidade'] ?? null;

$busca = $_GET['busca'] ?? '';
$statusFiltro = $_GET['status'] ?? '';
$unidadeFiltro = $_GET['unidade'] ?? '';
$estadoFiltro = $_GET['estado'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 5;
$offset = ($pagina - 1) * $limite;

try {
    $sql = "SELECT f.*, u.nome_unidade, u.estado_unidade 
            FROM funcionarios f
            LEFT JOIN unidades u ON f.id_unidade = u.id_unidade
            WHERE f.cargo_funcionario != 'administrador'";
    $params = [];

    if ($cargoUsuario === 'coordenador') {
        $sql .= " AND f.id_unidade = :unidade";
        $params[':unidade'] = $idUnidadeUsuario;
    } else {
        if (!empty($unidadeFiltro)) {
            $sql .= " AND f.id_unidade = :unidade";
            $params[':unidade'] = $unidadeFiltro;
        }
    }

    if (!empty($statusFiltro)) {
        $sql .= " AND f.status_acesso = :status";
        $params[':status'] = $statusFiltro;
    }

    if (!empty($estadoFiltro)) {
        $sql .= " AND u.estado_unidade = :estado";
        $params[':estado'] = $estadoFiltro;
    }

    if (!empty($busca)) {
        $sql .= " AND f.nome_funcionario LIKE :busca";
        $params[':busca'] = '%' . $busca . '%';
    }

    $sql .= " ORDER BY f.nome_funcionario";

    $sqlCount = "SELECT COUNT(*) FROM (" . $sql . ") AS total";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalRegistros = $stmtCount->fetchColumn();
    $totalPaginas = ceil($totalRegistros / $limite);

    $sql .= " LIMIT :limite OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro = "Erro ao buscar usuários: " . $e->getMessage();
    $usuarios = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
}

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? '';
$mensagem_erro = $_SESSION['mensagem_erro'] ?? '';
unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro']);

$unidades = [];
$estados = [];
if ($cargoUsuario === 'administrador') {
    try {
        $stmtUnidades = $pdo->query("SELECT id_unidade, nome_unidade, estado_unidade FROM unidades ORDER BY nome_unidade");
        $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    try {
        $stmtEstados = $pdo->query("SELECT DISTINCT estado_unidade FROM unidades ORDER BY estado_unidade");
        $estados = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

function manterFiltros($pagina = null) {
    $params = $_GET;
    if ($pagina !== null) {
        $params['pagina'] = $pagina;
    }
    unset($params['excluir']);
    return '?' . http_build_query($params);
}

$titulo = 'Listar Usuários - Gerenciamento de Ambientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        /* ======================================================
           RESET & BASE
        ====================================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ======================================================
           SIDEBAR
        ====================================================== */
        .sidebar {
            width: 270px;
            background: #ffffff;
            border-right: 1px solid #e8edf5;
            padding: 28px 20px;
            display: flex;
            flex-direction: column;
            gap: 28px;
            flex-shrink: 0;
            overflow-y: auto;
            height: 100vh;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f4fb;
        }

        .logo-icon {
            background: linear-gradient(145deg, #1a73e8, #0d47a1);
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
            box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3);
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #1a2639;
        }
        .logo-text span {
            color: #1a73e8;
        }
        .logo-text small {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #7a8aa0;
            margin-top: 2px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .menu-label {
            font-size: 11px;
            font-weight: 600;
            color: #9aabbf;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px 6px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 16px;
            border-radius: 10px;
            color: #5a6a7e;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .menu-item i {
            width: 20px;
            font-size: 16px;
            color: #8a9bb5;
            transition: color 0.15s;
        }

        .menu-item:hover {
            background: #f0f6ff;
            color: #1a2639;
        }
        .menu-item:hover i {
            color: #1a73e8;
        }

        .menu-item.active {
            background: #1a73e8;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
        }
        .menu-item.active i {
            color: #ffffff;
        }

        .menu-item .badge-menu {
            margin-left: auto;
            background: #ff6b6b;
            color: #fff;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 60px;
            font-weight: 600;
        }

        .sidebar-footer {
            border-top: 1px solid #edf2f9;
            padding-top: 16px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            margin-top: auto;
        }

        .sidebar-footer .user-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(145deg, #eef2f9, #dce3ef);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            color: #2d3a4f;
        }

        .user-info {
            line-height: 1.3;
        }
        .user-info .name {
            font-weight: 600;
            font-size: 13px;
            color: #1a2639;
        }
        .user-info .role {
            font-size: 12px;
            color: #8a9bb5;
        }

        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #34a853;
            margin-right: 6px;
        }

        .logout-btn-sidebar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #dc3545;
            color: #ffffff;
            border: none;
            border-radius: 60px;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s ease;
            width: 100%;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
            cursor: pointer;
        }

        .logout-btn-sidebar:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
        }

        /* ======================================================
           MAIN CONTENT
        ====================================================== */
        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            background: #f0f4fb;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* ======================================================
           PAGE HEADER
        ====================================================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
            margin-bottom: 6px;
        }

        .page-title i {
            color: #1a73e8;
            margin-right: 10px;
        }

        .page-subtitle {
            font-size: 14px;
            color: #7a8aa0;
            margin-bottom: 0;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ======================================================
           BOTÕES
        ====================================================== */
        .btn {
            padding: 9px 20px;
            border-radius: 60px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: #ffffff;
            color: #1a2639;
            border: 1px solid #e2e9f3;
            text-decoration: none;
        }

        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .btn-primary:hover {
            background: #1557b0;
            transform: scale(1.02);
        }

        .btn-success {
            background: #34a853;
            color: #ffffff;
            border: none;
        }
        .btn-success:hover {
            background: #2d9248;
        }

        .btn-danger {
            background: #dc3545;
            color: #ffffff;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: #ffc107;
            color: #1a2639;
            border: none;
        }
        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #d8e0ec;
        }
        .btn-outline:hover {
            background: #f0f4fb;
        }

        .btn-redefinir-senha {
            background: #e67e22;
            color: #fff;
            border-color: #d35400;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-redefinir-senha:hover {
            background: #d35400;
            border-color: #a04000;
            color: #fff;
        }

        /* ======================================================
           CARD PANEL
        ====================================================== */
        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        /* ======================================================
           FILTER FORM
        ====================================================== */
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .filter-form .form-group {
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-form .form-group label {
            font-size: 12px;
            font-weight: 500;
            color: #5a6a7e;
            margin-bottom: 0;
        }

        .filter-form .form-group input,
        .filter-form .form-group select {
            padding: 6px 12px;
            border: 1px solid #e2e9f3;
            border-radius: 6px;
            font-size: 13px;
            background: #fafcff;
            min-width: 160px;
        }

        .filter-form .form-group input:focus,
        .filter-form .form-group select:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .filter-form .btn {
            height: 36px;
            align-self: flex-end;
        }

        /* ======================================================
           TABLE
        ====================================================== */
        .table-wrapper {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 22px;
            border-bottom: 1px solid #f0f4fb;
            flex-wrap: wrap;
            gap: 10px;
        }

        .table-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0e1a2b;
        }

        .table-unidades {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: #ffffff;
        }

        .table-unidades thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
        }

        .table-unidades th {
            text-align: left;
            padding: 14px 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #5a6a7e;
        }

        .table-unidades td {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f4fc;
            color: #1a2639;
        }

        .table-unidades tbody tr:hover {
            background: #f8faff;
        }

        .table-unidades tbody tr:last-child td {
            border-bottom: none;
        }

        /* ======================================================
           TABLE - COM SCROLL (igual ao listar_cursos)
        ====================================================== */
        .table-scroll {
            overflow: auto;
            max-height: 450px;
            width: 100%;
        }
        .table-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        .table-scroll::-webkit-scrollbar-track {
            background: #f0f4fb;
            border-radius: 8px;
        }
        .table-scroll::-webkit-scrollbar-thumb {
            background: #c1c9d6;
            border-radius: 8px;
        }
        .table-scroll::-webkit-scrollbar-thumb:hover {
            background: #a8b2c4;
        }
        .table-scroll::-webkit-scrollbar-corner {
            background: #f0f4fb;
        }

        .table-unidades {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: #ffffff;
            min-width: 900px;
        }

        .table-unidades thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Ações na tabela */
        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .actions .btn-action {
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 60px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .actions .btn-edit {
            background: #e7edfe;
            color: #1a73e8;
        }

        .actions .btn-edit:hover {
            background: #d0dcfa;
        }

        /* ======================================================
           BADGES
        ====================================================== */
        .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #e6f7e9;
            color: #1e8546;
        }
        .badge-warning {
            background: #fff2e0;
            color: #b86a1f;
        }
        .badge-danger {
            background: #ffe9e9;
            color: #b33a3a;
        }
        .badge-info {
            background: #e3f2fd;
            color: #0d47a1;
        }
        .badge-purple {
            background: #f3e5f5;
            color: #6a1b9a;
        }
        .badge-orange {
            background: #fff3e0;
            color: #e37400;
        }

        /* ======================================================
           ALERTAS
        ====================================================== */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border: 1px solid #ffd6d6;
        }

        .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border: 1px solid #c8f0cf;
        }

        .alert i {
            font-size: 18px;
        }

        /* ======================================================
           EMPTY STATE
        ====================================================== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7a8aa0;
            font-size: 15px;
        }

        .empty-state i {
            font-size: 48px;
            color: #dce3ef;
            display: block;
            margin-bottom: 12px;
        }

        /* ======================================================
           RESPONSIVIDADE
        ====================================================== */
        @media (max-width: 820px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -300px;
                width: 280px;
                height: 100vh;
                z-index: 999;
                transition: left 0.3s ease;
                padding-top: 70px;
            }
            .sidebar.open {
                left: 0;
            }
            .main {
                padding: 16px 18px;
            }
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-form .form-group {
                width: 100%;
            }
            .filter-form .form-group input,
            .filter-form .form-group select {
                min-width: unset;
                width: 100%;
            }
            .filter-form .btn {
                width: 100%;
                justify-content: center;
            }
            .table-unidades {
                font-size: 13px;
            }
            .table-unidades th,
            .table-unidades td {
                padding: 10px 14px;
            }
            .actions {
                flex-direction: column;
                gap: 4px;
            }
            .actions .btn-action {
                font-size: 12px;
                padding: 2px 10px;
            }
        }

        @media (max-width: 540px) {
            .main {
                padding: 12px 14px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .header-actions {
                width: 100%;
                flex-wrap: wrap;
            }
            .header-actions .btn {
                flex: 1;
                justify-content: center;
                font-size: 12px;
                padding: 8px 12px;
            }
            .card-panel {
                padding: 18px 16px;
            }
            .table-unidades {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        /* ======================================================
           RODAPÉ
        ====================================================== */
        .footer-system {
            text-align: center;
            font-size: 12px;
            color: #8a9bb5;
            padding: 16px 0 8px;
            border-top: 1px solid #e2e9f3;
            margin-top: auto;
            background: transparent;
            flex-shrink: 0;
        }

        .footer-system .footer-content i {
            color: #1a73e8;
            margin-right: 4px;
        }

        .footer-system .footer-divider {
            margin: 0 8px;
            color: #dce3ef;
        }

        .footer-system .footer-version {
            color: #aab8cc;
            font-weight: 400;
        }

        /* Menu toggle (hamburger) */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1000;
            background: #1a73e8;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
            transition: background 0.2s;
        }

        .menu-toggle:hover {
            background: #1557b0;
        }

        .menu-toggle i {
            font-size: 24px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        body.menu-open {
            overflow: hidden;
        }

        @media (max-width: 820px) {
            .menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <!-- CONTEÚDO PRINCIPAL -->
    <main class="main">
        <!-- CABEÇALHO COM BOTÃO CADASTRAR USUÁRIO -->
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-users"></i> Listar Usuários</h1>
                <p class="page-subtitle">Gerencie os usuários cadastrados no sistema</p>
            </div>
            <div class="header-actions">
                <a href="cadastrar_usuario.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Cadastrar Usuário
                </a>
            </div>
        </header>

        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensagem_sucesso); ?></div>
        <?php endif; ?>
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensagem_erro); ?></div>
        <?php endif; ?>

        <!-- FILTROS -->
        <div class="card-panel" style="margin-bottom: 20px;">
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label for="busca"><i class="fas fa-search"></i> Buscar</label>
                    <input type="text" name="busca" id="busca" placeholder="Nome do funcionário..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">Todos</option>
                        <option value="ativo" <?php echo $statusFiltro === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $statusFiltro === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        <option value="bloqueado" <?php echo $statusFiltro === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                    </select>
                </div>
                <?php if ($cargoUsuario === 'administrador'): ?>
                <div class="form-group">
                    <label for="unidade">Unidade</label>
                    <select name="unidade" id="unidade">
                        <option value="">Todas</option>
                        <?php foreach ($unidades as $unidade): ?>
                            <option value="<?php echo $unidade['id_unidade']; ?>" <?php echo $unidadeFiltro == $unidade['id_unidade'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="estado">UF</label>
                    <select name="estado" id="estado">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $uf): ?>
                            <option value="<?php echo $uf; ?>" <?php echo $estadoFiltro === $uf ? 'selected' : ''; ?>><?php echo $uf; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="listar_usuarios.php" class="btn btn-danger"><i class="fas fa-times"></i> Limpar</a>
            </form>
        </div>

                <!-- TABELA DE USUÁRIOS -->
                <div class="table-wrapper">
                    <div class="table-header">
                        <h3><i class="fas fa-list"></i> Profissionais cadastrados</h3>
                        <span style="font-size: 13px; color: #7a8aa0;">Total: <strong><?php echo $totalRegistros; ?></strong></span>
                    </div>

                    <!-- TABELA COM SCROLL -->
                    <div class="table-scroll">
                        <table class="table-unidades">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Cargo</th>
                                    <th>Status</th>
                                    <th>Unidade</th>
                                    <th>Data Cadastro</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($usuarios)): ?>
                                    <tr>
                                        <td colspan="6" class="empty-state">
                                            <i class="fas fa-inbox" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                            Nenhum profissional encontrado.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($usuarios as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($u['nome_funcionario']); ?></strong></td>
                                        <td>
                                            <?php
                                                $cargo = $u['cargo_funcionario'];
                                                if ($cargo === 'professor') {
                                                    $badgeClass = 'badge-purple';
                                                } elseif ($cargo === 'coordenador') {
                                                    $badgeClass = 'badge-orange';
                                                } else {
                                                    $badgeClass = 'badge-info';
                                                }
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo ucfirst($cargo); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($u['status_acesso'] === 'ativo'): ?>
                                                <span class="badge badge-success"><i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i> Ativo</span>
                                            <?php elseif ($u['status_acesso'] === 'inativo'): ?>
                                                <span class="badge badge-warning"><i class="fas fa-clock" style="margin-right:4px;"></i> Inativo</span>
                                            <?php elseif ($u['status_acesso'] === 'bloqueado'): ?>
                                                <span class="badge badge-danger"><i class="fas fa-lock" style="margin-right:4px;"></i> Bloqueado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['nome_unidade'] ?? 'Não definida'); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($u['data_cadastro_funcionario'])); ?></td>
                                        <td>
                                            <div class="actions">
                                                <?php if (file_exists('editar_usuarios.php')): ?>
                                                    <a href="editar_usuarios.php?id=<?php echo $u['id_funcionario']; ?>" class="btn-action btn-edit" title="Editar">
                                                        <i class="fas fa-edit"></i> Editar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINAÇÃO -->
                    <?php if ($totalPaginas > 1): ?>
                    <div style="display: flex; justify-content: center; gap: 6px; padding: 16px 22px; border-top: 1px solid #f0f4fb; flex-wrap: wrap; background: #ffffff; border-radius: 0 0 16px 16px;">
                        <!-- Anterior -->
                        <?php if ($pagina > 1): ?>
                            <a href="<?php echo manterFiltros($pagina - 1); ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        <?php else: ?>
                            <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </span>
                        <?php endif; ?>

                        <!-- Primeira página -->
                        <?php if ($pagina > 3): ?>
                            <a href="<?php echo manterFiltros(1); ?>" class="btn btn-outline btn-sm">1</a>
                            <?php if ($pagina > 4): ?>
                                <span style="color: #999; padding: 0 4px;">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Páginas ao redor da atual -->
                        <?php 
                        $start = max(1, $pagina - 2);
                        $end = min($totalPaginas, $pagina + 2);
                        
                        for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i == $pagina): ?>
                                <span class="btn btn-primary btn-sm" style="cursor: default;"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo manterFiltros($i); ?>" class="btn btn-outline btn-sm"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <!-- Última página -->
                        <?php if ($pagina < $totalPaginas - 2): ?>
                            <?php if ($pagina < $totalPaginas - 3): ?>
                                <span style="color: #999; padding: 0 4px;">…</span>
                            <?php endif; ?>
                            <a href="<?php echo manterFiltros($totalPaginas); ?>" class="btn btn-outline btn-sm"><?php echo $totalPaginas; ?></a>
                        <?php endif; ?>

                        <!-- Próximo -->
                        <?php if ($pagina < $totalPaginas): ?>
                            <a href="<?php echo manterFiltros($pagina + 1); ?>" class="btn btn-outline btn-sm">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>

        <!-- RODAPÉ -->
        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>