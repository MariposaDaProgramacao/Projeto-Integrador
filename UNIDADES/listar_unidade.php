<?php
// ============================================================
// ARQUIVO: UNIDADES/listar_unidade.php (MODIFICADO PARA MULTI-TENANT)
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente', 'usuario', 'visualizador'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// VARIÁVEIS DE PERMISSÃO (NOVO SISTEMA)
// ============================================================
$id_cliente = getClienteId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$pode_editar = in_array($tipo_usuario, ['admin_cliente']);
$pode_cadastrar = in_array($tipo_usuario, ['admin_cliente']);

// ==========================================================
// RECEBER FILTROS E PÁGINA
// ==========================================================

$busca = $_GET['busca'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 8;
$offset = ($pagina - 1) * $limite;

// ==========================================================
// CONSULTAR UNIDADES COM FILTROS E PAGINAÇÃO (FILTRADAS POR CLIENTE)
// ==========================================================

try {
    $where = "WHERE id_cliente = :id_cliente";
    $params = [':id_cliente' => $id_cliente];
    
    if (!empty($busca)) {
        $where .= " AND (nome_unidade LIKE :busca 
                 OR cidade_unidade LIKE :busca 
                 OR estado_unidade LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }
    
    $sqlCount = "SELECT COUNT(*) as total FROM unidades $where";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalRegistros = $stmtCount->fetchColumn();
    $totalPaginas = ceil($totalRegistros / $limite);
    
    $sql = "SELECT * FROM unidades $where ORDER BY nome_unidade ASC LIMIT :limite OFFSET :offset";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $unidades = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $unidades = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
    setMessage('error', 'Erro ao carregar unidades: ' . $e->getMessage());
}

// ==========================================================
// FUNÇÃO PARA MANTER FILTROS NA PAGINAÇÃO
// ==========================================================

function manterFiltros($pagina = null) {
    $params = $_GET;
    if ($pagina !== null) {
        $params['pagina'] = $pagina;
    }
    return '?' . http_build_query($params);
}

// Mensagens da sessão
$message = getMessage();
$erro = '';
$sucesso = '';

if ($message) {
    if ($message['tipo'] === 'error') {
        $erro = $message['mensagem'];
    } elseif ($message['tipo'] === 'success') {
        $sucesso = $message['mensagem'];
    }
}

$titulo = 'Listar Unidades - Gerenciador de Salas';
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
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
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
        .user-info .cliente {
            font-size: 11px;
            color: #1a73e8;
            font-weight: 500;
            display: block;
            margin-top: 2px;
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

        .table-header .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .table-header .filters input,
        .table-header .filters select {
            padding: 6px 14px;
            border-radius: 60px;
            border: 1px solid #e2e9f3;
            background: #fafcff;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            color: #1a2639;
            outline: none;
        }

        .table-header .filters input:focus,
        .table-header .filters select:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

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
           ACTIONS
        ====================================================== */
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
            .table-unidades {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-building"></i> Unidades</h1>
                <p class="page-subtitle">Gerencie as unidades cadastradas no sistema</p>
            </div>
            <div class="header-actions">
                <?php if ($pode_cadastrar): ?>
                    <a href="cadastrar_unidade.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Nova Unidade
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sucesso); ?></div>
        <?php endif; ?>

        <div class="table-wrapper">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Lista de Unidades</h3>
                <div class="filters">
                    <form method="GET" action="" style="display: flex; align-items: center; gap: 8px;">
                        <input type="text" name="busca" placeholder="Buscar unidade..." 
                               value="<?php echo htmlspecialchars($_GET['busca'] ?? ''); ?>">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <?php if (!empty($_GET['busca'])): ?>
                            <a href="listar_unidade.php" class="btn btn-danger btn-sm">
                                <i class="fas fa-times"></i> Limpar
                            </a>
                        <?php endif; ?>
                    </form>
                    <span>Total: <strong><?php echo $totalRegistros; ?></strong></span>
                </div>
            </div>

            <!-- TABELA COM SCROLL -->
            <div class="table-scroll">
                <table class="table-unidades">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Cidade</th>
                            <th>UF</th>
                            <th>Telefone</th>
                            <th>Status</th>
                            <?php if ($pode_editar): ?>
                                <th>Ações</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($unidades)): ?>
                            <tr>
                                <td colspan="<?php echo $pode_editar ? 6 : 5; ?>" class="empty-state">
                                    <i class="fas fa-building" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                    Nenhuma unidade encontrada.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($unidades as $unidade): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($unidade['nome_unidade']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($unidade['cidade_unidade']); ?></td>
                                    <td><?php echo $unidade['estado_unidade']; ?></td>
                                    <td><?php echo $unidade['telefone_unidade'] ?? '-'; ?></td>
                                    <td>
                                        <?php
                                        $status = $unidade['status_unidade'] ?? 'ativo';
                                        $status_texto = $status === 'ativo' ? 'Ativo' : 'Inativo';
                                        $status_bg = $status === 'ativo' ? '#e6f7e9' : '#ffe9e9';
                                        $status_color = $status === 'ativo' ? '#34a853' : '#b33a3a';
                                        $status_text_color = $status === 'ativo' ? '#1e8546' : '#b33a3a';
                                        ?>
                                        <span style="
                                            display: inline-block;
                                            padding: 4px 12px;
                                            border-radius: 60px;
                                            font-size: 12px;
                                            font-weight: 600;
                                            background: <?php echo $status_bg; ?>;
                                            color: <?php echo $status_text_color; ?>;
                                        ">
                                            <i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: <?php echo $status_color; ?>;"></i>
                                            <?php echo $status_texto; ?>
                                        </span>
                                    </td>
                                    <?php if ($pode_editar): ?>
                                        <td>
                                            <div class="actions">
                                                <a href="editar_unidade.php?id=<?php echo $unidade['id_unidade']; ?>" class="btn-action btn-edit">
                                                    <i class="fas fa-edit"></i> Editar
                                                </a>
                                                <?php if ($status === 'ativo'): ?>
                                                    <a href="inativar_unidade.php?id=<?php echo $unidade['id_unidade']; ?>" 
                                                       class="btn-action btn-edit" style="background: #fff3cd; color: #856404;"
                                                       onclick="return confirm('Tem certeza que deseja inativar esta unidade?')">
                                                        <i class="fas fa-ban"></i> Inativar
                                                    </a>
                                                <?php else: ?>
                                                    <a href="ativar_unidade.php?id=<?php echo $unidade['id_unidade']; ?>" 
                                                       class="btn-action btn-edit" style="background: #e6f7e9; color: #1e8546;"
                                                       onclick="return confirm('Tem certeza que deseja ativar esta unidade?')">
                                                        <i class="fas fa-check-circle"></i> Ativar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ========================================== -->
            <!-- PAGINAÇÃO                                 -->
            <!-- ========================================== -->
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

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>