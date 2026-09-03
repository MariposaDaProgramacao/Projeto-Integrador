<?php
// ============================================================
// ARQUIVO: dashboard.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Exibir informações do usuário logado e dashboard
// ============================================================

// ============================================================
// 1. CARREGAR A CONEXÃO
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. INICIAR SESSÃO
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 3. VERIFICAR SE O USUÁRIO ESTÁ LOGADO (NOVO SISTEMA)
// ============================================================

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar o dashboard.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 4. VERIFICAR PERMISSÃO DE ACESSO AO DASHBOARD
// ============================================================
// Tipos de usuário do novo sistema: admin_cliente, gerente, usuario, visualizador
// ============================================================

$tipos_permitidos = ['admin_cliente', 'gerente', 'usuario', 'visualizador'];

if (!isset($_SESSION['tipo_usuario']) || !in_array($_SESSION['tipo_usuario'], $tipos_permitidos)) {
    session_destroy();
    setMessage('error', 'Sessão inválida. Faça login novamente.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 5. VERIFICAR PERMISSÕES ESPECÍFICAS
// ============================================================
// APENAS admin_cliente pode gerar relatórios e gerenciar usuários
// ============================================================
$pode_gerar_relatorio = ($_SESSION['tipo_usuario'] === 'admin_cliente');
$pode_gerenciar_usuarios = ($_SESSION['tipo_usuario'] === 'admin_cliente');
$pode_gerenciar_tudo = ($_SESSION['tipo_usuario'] === 'admin_cliente');

// ============================================================
// 6. BUSCAR DADOS DO USUÁRIO NO BANCO (NOVA TABELA)
// ============================================================

$id_cliente = getClienteId();
$id_usuario = getUsuarioId();

try {
    // Buscar dados do usuário logado
    $sql = "SELECT 
                u.id_usuario,
                u.nome_usuario,
                u.email_usuario,
                u.tipo_usuario,
                u.status_usuario,
                u.data_ultimo_acesso,
                u.data_cadastro,
                u.telefone_usuario,
                c.id_cliente,
                c.nome_cliente,
                c.tipo_cliente,
                c.status_cliente,
                c.plano_cliente,
                c.limite_unidades,
                c.limite_usuarios,
                c.data_cadastro as data_cadastro_cliente
            FROM usuarios_sistema u
            JOIN clientes c ON u.id_cliente = c.id_cliente
            WHERE u.id_usuario = :id_usuario AND u.id_cliente = :id_cliente";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id_usuario' => $id_usuario,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        session_destroy();
        setMessage('error', 'Usuário não encontrado.');
        redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
    }
    
    // ============================================================
    // 7. BUSCAR ESTATÍSTICAS DO CLIENTE
    // ============================================================
    
    // Total de unidades
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM unidades WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $total_unidades = $stmt->fetch()['total'];
    
    // Total de salas
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM salas WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $total_salas = $stmt->fetch()['total'];
    
    // Total de cursos
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cursos WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $total_cursos = $stmt->fetch()['total'];
    
    // Total de funcionários
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $total_funcionarios = $stmt->fetch()['total'];
    
    // Total de usuários do sistema
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM usuarios_sistema WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $total_usuarios = $stmt->fetch()['total'];
    
    // Total de aulas agendadas
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cronograma WHERE id_cliente = ? AND status_aula = 'agendada'");
    $stmt->execute([$id_cliente]);
    $total_aulas_agendadas = $stmt->fetch()['total'];
    
    // Total de aulas realizadas
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cronograma WHERE id_cliente = ? AND status_aula = 'realizada'");
    $stmt->execute([$id_cliente]);
    $total_aulas_realizadas = $stmt->fetch()['total'];
    
    // Próximas aulas (próximos 7 dias)
    $stmt = $conn->prepare("
        SELECT * FROM cronograma 
        WHERE id_cliente = ? 
        AND data_aula >= CURDATE() 
        AND status_aula = 'agendada'
        ORDER BY data_aula ASC, horario_inicio ASC 
        LIMIT 5
    ");
    $stmt->execute([$id_cliente]);
    $proximas_aulas = $stmt->fetchAll();
    
    // Últimas atividades
    $stmt = $conn->prepare("
        SELECT * FROM historico_sistema 
        WHERE id_cliente = ? 
        ORDER BY data_acao DESC 
        LIMIT 5
    ");
    $stmt->execute([$id_cliente]);
    $ultimas_atividades = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $erro = 'Erro ao carregar dados do usuário.';
    error_log('Erro no dashboard: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Dashboard - Gerenciamento de Ambientes</title>

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
        .user-info .cliente {
            font-size: 11px;
            color: #1a73e8;
            font-weight: 500;
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

        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
        }
        .page-header h1 small {
            font-size: 14px;
            font-weight: 400;
            color: #7a8aa0;
            margin-left: 10px;
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

        .btn-key {
            background: #f5f7fa;
            color: #1a2639;
            border: 1px solid #dce3ef;
        }
        .btn-key:hover {
            background: #e8edf5;
            border-color: #bcc8db;
            transform: translateY(-1px);
        }
        .btn-key i {
            color: #5a6a7e;
            transition: color 0.2s;
        }
        .btn-key:hover i {
            color: #1a73e8;
        }

        .btn-users {
            background: #f5f7fa;
            color: #1a2639;
            border: 1px solid #dce3ef;
        }
        .btn-users:hover {
            background: #e8edf5;
            border-color: #bcc8db;
            transform: translateY(-1px);
        }
        .btn-users i {
            color: #5a6a7e;
            transition: color 0.2s;
        }
        .btn-users:hover i {
            color: #1a73e8;
        }

        .btn-report {
            background: #f5f7fa;
            color: #1a2639;
            border: 1px solid #dce3ef;
            padding: 12px 28px;
            font-size: 14px;
        }
        .btn-report:hover {
            background: #e8edf5;
            border-color: #bcc8db;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .btn-report i {
            color: #5a6a7e;
            transition: color 0.2s;
        }
        .btn-report:hover i {
            color: #1a73e8;
        }

        /* ======================================================
           CARDS DE ESTATÍSTICAS
        ====================================================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px 22px;
            border: 1px solid #ebf0f8;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #0e1a2b;
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #7a8aa0;
            margin-top: 4px;
        }
        .stat-card .stat-icon {
            float: right;
            font-size: 28px;
            color: #1a73e8;
            opacity: 0.2;
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

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .panel-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0e1a2b;
        }

        /* ======================================================
           INFORMAÇÕES DO USUÁRIO
        ====================================================== */
        .info-grid {
            display: grid;
            gap: 10px 20px;
        }

        .info-grid .item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 4px 0;
        }

        .info-grid .item .label {
            font-size: 11px;
            font-weight: 600;
            color: #7a8aa0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-grid .item .label i {
            margin-right: 4px;
            font-size: 12px;
            color: #1a73e8;
        }

        .info-grid .item .value {
            font-size: 14px;
            font-weight: 500;
            color: #0e1a2b;
        }

        .info-grid .item.full-width {
            grid-column: 1 / -1;
        }

        /* ======================================================
           TABELA DE PRÓXIMAS AULAS
        ====================================================== */
        .table-mini {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .table-mini th {
            text-align: left;
            padding: 8px 12px 8px 0;
            font-weight: 600;
            color: #5a6a7e;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #edf2f9;
        }
        .table-mini td {
            padding: 10px 12px 10px 0;
            border-bottom: 1px solid #f5f7fa;
            color: #1a2639;
        }
        .table-mini tr:last-child td {
            border-bottom: none;
        }

        .table-mini .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 60px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.agendada { background: #e3f2fd; color: #0d47a1; }
        .status-badge.realizada { background: #e6f7e9; color: #1e8546; }
        .status-badge.cancelada { background: #ffe9e9; color: #b33a3a; }

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

        /* ======================================================
           TWO-COL LAYOUT
        ====================================================== */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-bottom: 28px;
        }

        /* ======================================================
           BOTÃO RELATÓRIO
        ====================================================== */
        .report-section {
            display: flex;
            justify-content: center;
            margin-top: 10px;
            margin-bottom: 28px;
            gap: 16px;
            flex-wrap: wrap;
        }

        /* ======================================================
           EMPTY STATE
        ====================================================== */
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #9aabbf;
        }
        .empty-state i {
            font-size: 32px;
            color: #dce3ef;
            margin-bottom: 10px;
            display: block;
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
            .two-col {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr !important;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            .btn-report {
                width: 100%;
                justify-content: center;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card {
                padding: 14px 16px;
            }
            .stat-card .stat-number {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

    <!-- ========================================== -->
    <!-- SIDEBAR (INCLUDE)                         -->
    <!-- ========================================== -->
    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <!-- ========================================== -->
    <!-- CONTEÚDO PRINCIPAL                        -->
    <!-- ========================================== -->
    <main class="main">

        <!-- Cabeçalho -->
        <header class="page-header">
            <h1>
                Dashboard
                <small><?php echo htmlspecialchars($usuario['nome_cliente'] ?? 'Sua Organização'); ?></small>
            </h1>
            <div class="header-actions">
                <a href="resetar_senha.php" class="btn btn-key">
                    <i class="fas fa-key"></i> Alterar Senha
                </a>
                <?php if ($pode_gerenciar_usuarios): ?>
                <a href="../USUARIOS(ADM)/listar.php" class="btn btn-users">
                    <i class="fas fa-users"></i> Gerenciar Usuários
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ========================================== -->
        <!-- CARDS DE ESTATÍSTICAS                     -->
        <!-- ========================================== -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-building"></i></span>
                <div class="stat-number"><?php echo $total_unidades ?? 0; ?></div>
                <div class="stat-label">🏢 Unidades</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-door-open"></i></span>
                <div class="stat-number"><?php echo $total_salas ?? 0; ?></div>
                <div class="stat-label">🚪 Salas</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-book"></i></span>
                <div class="stat-number"><?php echo $total_cursos ?? 0; ?></div>
                <div class="stat-label">📚 Cursos</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-users"></i></span>
                <div class="stat-number"><?php echo $total_funcionarios ?? 0; ?></div>
                <div class="stat-label">👨‍🏫 Funcionários</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-user-friends"></i></span>
                <div class="stat-number"><?php echo $total_usuarios ?? 0; ?></div>
                <div class="stat-label">👤 Usuários</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-calendar-check"></i></span>
                <div class="stat-number"><?php echo $total_aulas_agendadas ?? 0; ?></div>
                <div class="stat-label">📅 Aulas Agendadas</div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- CARD DO USUÁRIO                           -->
        <!-- ========================================== -->
        <div class="card-panel">
            <div class="panel-header">
                <h3>
                    <i class="fas fa-user-circle" style="color: #1a73e8;"></i>
                    Minhas Informações
                </h3>
            </div>
            <div class="info-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px 20px; padding: 8px 0;">
                <div class="item">
                    <span class="label"><i class="fas fa-user"></i> Nome</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['nome_usuario'] ?? '-'); ?></span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-envelope"></i> E-mail</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['email_usuario'] ?? '-'); ?></span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-building"></i> Organização</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['nome_cliente'] ?? '-'); ?></span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-tag"></i> Tipo</span>
                    <span class="value" style="text-transform:capitalize;">
                        <?php 
                        $tipos = [
                            'admin_cliente' => 'Administrador',
                            'gerente' => 'Gerente',
                            'usuario' => 'Usuário',
                            'visualizador' => 'Visualizador'
                        ];
                        echo $tipos[$usuario['tipo_usuario']] ?? $usuario['tipo_usuario']; 
                        ?>
                    </span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-crown"></i> Plano</span>
                    <span class="value" style="text-transform:capitalize;">
                        <?php echo htmlspecialchars($usuario['plano_cliente'] ?? 'gratuito'); ?>
                        <small style="color: #7a8aa0; font-size: 11px;">
                            (<?php echo $usuario['limite_unidades']; ?> unidades / <?php echo $usuario['limite_usuarios']; ?> usuários)
                        </small>
                    </span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-circle"></i> Status</span>
                    <span class="badge badge-<?php echo $usuario['status_cliente'] === 'ativo' ? 'success' : 'danger'; ?>">
                        <?php echo ucfirst($usuario['status_cliente'] ?? 'desconhecido'); ?>
                    </span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-clock"></i> Último Acesso</span>
                    <span class="value"><?php echo $usuario['data_ultimo_acesso'] ? date('d/m/Y H:i', strtotime($usuario['data_ultimo_acesso'])) : 'Nunca acessou'; ?></span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-calendar-plus"></i> Cadastro</span>
                    <span class="value"><?php echo date('d/m/Y', strtotime($usuario['data_cadastro_cliente'])); ?></span>
                </div>
                <div class="item">
                    <span class="label"><i class="fas fa-phone"></i> Telefone</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['telefone_usuario'] ?? 'Não informado'); ?></span>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- PRÓXIMAS AULAS                           -->
        <!-- ========================================== -->
        <div class="card-panel">
            <div class="panel-header">
                <h3>
                    <i class="fas fa-calendar-alt" style="color: #1a73e8;"></i>
                    Próximas Aulas
                </h3>
                <a href="../CRONOGRAMA_AULAS/listar.php" class="btn" style="font-size:12px; padding:6px 16px;">
                    Ver todas <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <?php if ($proximas_aulas): ?>
            <table class="table-mini">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Curso</th>
                        <th>Sala</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proximas_aulas as $aula): 
                        // Buscar nome do curso
                        $stmt = $conn->prepare("SELECT nome_curso FROM cursos WHERE id_curso = ? AND id_cliente = ?");
                        $stmt->execute([$aula['id_curso'], $id_cliente]);
                        $curso = $stmt->fetch();
                        
                        // Buscar número da sala
                        $stmt = $conn->prepare("SELECT numero_sala FROM salas WHERE id_sala = ? AND id_cliente = ?");
                        $stmt->execute([$aula['id_sala'], $id_cliente]);
                        $sala = $stmt->fetch();
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($aula['data_aula'])); ?></td>
                        <td><?php echo substr($aula['horario_inicio'], 0, 5); ?> - <?php echo substr($aula['horario_fim'], 0, 5); ?></td>
                        <td><?php echo htmlspecialchars($curso['nome_curso'] ?? 'N/A'); ?></td>
                        <td><?php echo $sala['numero_sala'] ?? 'N/A'; ?></td>
                        <td>
                            <span class="status-badge <?php echo $aula['status_aula']; ?>">
                                <?php echo ucfirst($aula['status_aula']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-day"></i>
                <p>Nenhuma aula agendada para os próximos dias.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- BOTÕES DE AÇÃO RÁPIDA                     -->
        <!-- ========================================== -->
        <div class="report-section">
            <a href="../UNIDADES/cadastrar.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Nova Unidade
            </a>
            <a href="../CURSOS/cadastrar.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Novo Curso            </a>
            <a href="../SALAS/cadastrar.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Nova Sala
            </a>
            <a href="../CRONOGRAMA_AULAS/cadastrar.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Nova Aula
            </a>
            <?php if ($pode_gerar_relatorio): ?>
            <a href="relatorio.php" class="btn btn-report">
                <i class="fa-solid fa-file-pdf"></i> Gerar Relatório
            </a>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- RODAPÉ (INCLUDE)                           -->
        <!-- ========================================== -->
        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>

    </main>

</body>
</html>