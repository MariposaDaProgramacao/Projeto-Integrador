<?php
// ============================================================
// ARQUIVO: RECESSOS/listar_recesso.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Listar todos os recessos registrados com detalhes
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CARREGAR CONEXÃO E FUNÇÕES
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// PERMISSÕES - TODOS OS TIPOS DE USUÁRIO PODEM VISUALIZAR
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente', 'usuario', 'visualizador'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// Se não tiver unidade definida, buscar a primeira unidade do cliente
if ($id_unidade_usuario == 0 || $id_unidade_usuario === null) {
    try {
        $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
        $stmt->execute([$id_cliente]);
        $unidade = $stmt->fetch();
        if ($unidade) {
            $id_unidade_usuario = $unidade['id_unidade'];
            $_SESSION['usuario_unidade'] = $id_unidade_usuario;
        }
    } catch (PDOException $e) {
        $id_unidade_usuario = 0;
    }
}

try {
    $sql = "SELECT r.*, u.nome_unidade 
            FROM recessos r
            LEFT JOIN unidades u ON r.id_unidade = u.id_unidade AND u.id_cliente = r.id_cliente
            WHERE r.id_cliente = :id_cliente";
    
    $params = [':id_cliente' => $id_cliente];
    
    // Se for gerente, filtrar pela sua unidade
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND (r.id_unidade = :id_unidade OR r.id_unidade IS NULL)";
        $params[':id_unidade'] = $id_unidade_usuario;
    }
    
    $sql .= " ORDER BY r.data_inicio DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $recessos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================================
    // BUSCAR CURSOS AFETADOS PARA CADA RECESSO (FILTRADOS POR CLIENTE)
    // ============================================================
    foreach ($recessos as &$recesso) {
        if (!empty($recesso['id_cursos'])) {
            $ids = explode(',', $recesso['id_cursos']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sqlCursos = "SELECT id_curso, numero_curso, nome_curso, turno_curso, tipo_curso 
                          FROM cursos 
                          WHERE id_curso IN ($placeholders) 
                          AND id_cliente = ?
                          ORDER BY numero_curso";
            $stmtCursos = $conn->prepare($sqlCursos);
            $paramsCursos = array_merge($ids, [$id_cliente]);
            $stmtCursos->execute($paramsCursos);
            $recesso['cursos_afetados'] = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $recesso['cursos_afetados'] = [];
        }
    }
    
} catch (PDOException $e) {
    $recessos = [];
    $erro = 'Erro ao carregar recessos: ' . $e->getMessage();
}

// ============================================================
// FUNÇÃO PARA FORMATAR TURNO
// ============================================================
function formatarTurno($turno) {
    $turnos = [
        'manha' => '☀️ Manhã',
        'tarde' => '🌤️ Tarde',
        'noite' => '🌙 Noite',
        'integral' => '🔄 Integral'
    ];
    return $turnos[$turno] ?? $turno;
}

// ============================================================
// FUNÇÃO PARA FORMATAR TIPO DE CURSO
// ============================================================
function formatarTipoCurso($tipo) {
    $tipos = [
        'curso_tecnico' => '📘 Técnico',
        'curso_agil' => '📗 Ágil',
        'pos_graduacao' => '📕 Pós-graduação'
    ];
    return $tipos[$tipo] ?? $tipo;
}

$titulo = 'Listar Recessos - Gerenciamento de Ambientes';

// Mensagens da sessão
$message = getMessage();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-title { font-size: 24px; font-weight: 700; color: #0e1a2b; }
        .page-title i { color: #1a73e8; margin-right: 10px; }
        .page-subtitle { font-size: 14px; color: #7a8aa0; margin-top: 4px; }
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
            text-decoration: none;
        }
        .btn-secondary { background: #e2e9f3; color: #1a2639; border: 1px solid #d8e0ec; }
        .btn-secondary:hover { background: #d0dbe8; }
        .btn-primary { background: #1a73e8; color: #ffffff; border: none; box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35); }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }
        
        .table-wrapper {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .table-scroll {
            overflow: auto;
            max-height: 500px;
            width: 100%;
        }
        .table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
        .table-scroll::-webkit-scrollbar-track { background: #f0f4fb; border-radius: 8px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #c1c9d6; border-radius: 8px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #a8b2c4; }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 22px;
            border-bottom: 1px solid #f0f4fb;
            flex-wrap: wrap;
            gap: 10px;
            flex-shrink: 0;
            background: #ffffff;
        }
        .table-header h3 { font-size: 15px; font-weight: 600; color: #0e1a2b; }
        .table-header h3 i { color: #1a73e8; margin-right: 6px; }
        
        .table-recessos {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #ffffff;
            min-width: 1100px;
        }
        .table-recessos thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-recessos th {
            text-align: left;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #5a6a7e;
            background: #f9fbfe;
            white-space: nowrap;
            border-bottom: 2px solid #e2e9f3;
        }
        .table-recessos td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f4fc;
            color: #1a2639;
            font-size: 13px;
            vertical-align: top;
        }
        .table-recessos tbody tr:hover { background: #f8faff; }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-feriado { background: #ffebee; color: #b71c1c; }
        .badge-recesso { background: #fff3e0; color: #e37400; }
        .badge-ponto_facultativo { background: #e3f2fd; color: #0d47a1; }
        .badge-paralisacao { background: #f3e5f5; color: #6a1b9a; }
        
        .badge-active { background: #e6f7e9; color: #1e8546; }
        .badge-inactive { background: #f5f5f5; color: #616161; }
        
        /* Badges para turno e tipo */
        .badge-turno {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-turno-manha { background: #fff3cd; color: #856404; }
        .badge-turno-tarde { background: #cce5ff; color: #004085; }
        .badge-turno-noite { background: #d6d8db; color: #383d41; }
        .badge-turno-integral { background: #e8f5e9; color: #1b5e20; }
        
        .badge-tipo-curso {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-tipo-curso-tecnico { background: #e3f2fd; color: #0d47a1; }
        .badge-tipo-curso-agil { background: #e8f5e9; color: #1b5e20; }
        .badge-tipo-curso-pos { background: #f3e5f5; color: #6a1b9a; }
        
        /* Badge para "Apenas um curso" */
        .badge-apenas-um-curso {
            display: inline-block;
            background: #e8f5e9;
            color: #1b5e20;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #a5d6a7;
        }
        .badge-apenas-um-curso i {
            color: #43a047;
            margin-right: 4px;
        }
        
        .curso-tag {
            display: inline-block;
            background: #f0f4fb;
            color: #1a2639;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            border: 1px solid #e2e9f3;
        }
        .curso-tag i {
            color: #1a73e8;
            margin-right: 4px;
        }
        .curso-tag .turno-tag {
            font-size: 10px;
            color: #7a8aa0;
        }
        
        .info-detalhe {
            font-size: 12px;
            color: #5a6a7e;
            margin-top: 2px;
        }
        .info-detalhe i {
            color: #1a73e8;
            margin-right: 4px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7a8aa0;
            font-size: 15px;
        }
        .empty-state i { font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px; }
        
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
        .logo-area { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f4fb; }
        .logo-icon { background: linear-gradient(145deg, #1a73e8, #0d47a1); width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px; box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3); }
        .logo-text { font-size: 20px; font-weight: 700; color: #1a2639; }
        .logo-text span { color: #1a73e8; }
        .logo-text small { display: block; font-size: 11px; font-weight: 400; color: #7a8aa0; margin-top: 2px; }
        .sidebar-menu { display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .menu-label { font-size: 11px; font-weight: 600; color: #9aabbf; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 16px 6px; }
        .menu-item { display: flex; align-items: center; gap: 14px; padding: 10px 16px; border-radius: 10px; color: #5a6a7e; font-weight: 500; font-size: 14px; transition: all 0.15s ease; text-decoration: none; }
        .menu-item i { width: 20px; font-size: 16px; color: #8a9bb5; transition: color 0.15s; }
        .menu-item:hover { background: #f0f6ff; color: #1a2639; }
        .menu-item:hover i { color: #1a73e8; }
        .menu-item.active { background: #1a73e8; color: #ffffff; box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3); }
        .menu-item.active i { color: #ffffff; }
        .sidebar-footer { border-top: 1px solid #edf2f9; padding-top: 16px; display: flex; flex-direction: column; align-items: stretch; gap: 12px; margin-top: auto; }
        .sidebar-footer .user-row { display: flex; align-items: center; gap: 14px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(145deg, #eef2f9, #dce3ef); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; color: #2d3a4f; }
        .user-info { line-height: 1.3; }
        .user-info .name { font-weight: 600; font-size: 13px; color: #1a2639; }
        .user-info .role { font-size: 12px; color: #8a9bb5; }
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #34a853; margin-right: 6px; }
        .logout-btn-sidebar { display: flex; align-items: center; justify-content: center; gap: 8px; background: #dc3545; color: #ffffff; border: none; border-radius: 60px; padding: 10px 16px; font-weight: 600; font-size: 13px; text-decoration: none; transition: all 0.2s ease; width: 100%; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25); cursor: pointer; }
        .logout-btn-sidebar:hover { background: #c82333; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35); }
        
        .cursos-container {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            max-width: 300px;
        }
        .curso-tag {
            display: inline-block;
            background: #f8faff;
            border: 1px solid #e2e9f3;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 11px;
            color: #1a2639;
            margin: 1px 2px;
            white-space: nowrap;
        }
        .curso-tag i {
            color: #1a73e8;
            margin-right: 4px;
        }
        
        .apenas-um-curso-box {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 8px;
            padding: 6px 14px;
            display: inline-block;
        }
        .apenas-um-curso-box .numero-curso {
            font-weight: 700;
            color: #1b5e20;
        }
        .apenas-um-curso-box .nome-curso {
            color: #2e7d32;
        }
        .apenas-um-curso-box .turno-curso {
            font-size: 11px;
            color: #558b2f;
        }
        
        @media (max-width: 640px) {
            .main { padding: 16px; }
            .table-recessos { min-width: 900px; font-size: 12px; }
            .table-recessos th, .table-recessos td { padding: 6px 8px; }
        }
        @media (max-width: 820px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-calendar-times"></i> Recessos</h1>
                <p class="page-subtitle">Visualize todos os recessos registrados com seus detalhes</p>
            </div>
            <?php if (in_array($tipo_usuario, ['admin_cliente', 'gerente'])): ?>
                <a href="cadastrar_recesso.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Novo Recesso
                </a>
            <?php endif; ?>
        </header>

        <?php if ($message && $message['tipo'] === 'error'): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($message['mensagem']); ?>
            </div>
        <?php endif; ?>

        <?php if ($message && $message['tipo'] === 'success'): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message['mensagem']); ?>
            </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Histórico de Recessos</h3>
                <span style="font-size: 13px; color: #7a8aa0;">
                    Total: <strong><?php echo count($recessos); ?></strong>
                </span>
            </div>

            <div class="table-scroll">
                <?php if (empty($recessos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        Nenhum recesso registrado.
                        <br>
                        <?php if (in_array($tipo_usuario, ['admin_cliente', 'gerente'])): ?>
                            <a href="cadastrar_recesso.php" style="color: #1a73e8; text-decoration: none; font-weight: 600;">
                                Clique aqui para registrar um recesso
                            </a>
                        <?php else: ?>
                            <span style="color: #7a8aa0; font-size: 13px;">
                                Aguarde o registro de recessos pela administração.
                            </span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table class="table-recessos">
                        <thead>
                            <tr>
                                <th style="min-width: 140px;">Nome</th>
                                <th style="min-width: 100px;">Tipo</th>
                                <th style="min-width: 150px;">Período</th>
                                <th style="min-width: 80px;">Unidade</th>
                                <th style="min-width: 100px;">Turno</th>
                                <th style="min-width: 100px;">Tipo Curso</th>
                                <th style="min-width: 250px;">Cursos Afetados</th>
                                <th style="min-width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recessos as $r): 
                                $statusClass = $r['ativo'] ? 'badge-active' : 'badge-inactive';
                                $statusLabel = $r['ativo'] ? '✅ Ativo' : '❌ Inativo';
                                
                                $tipoClass = 'badge-' . $r['tipo'];
                                $tipoLabel = [
                                    'feriado' => 'Feriado',
                                    'recesso' => 'Recesso',
                                    'ponto_facultativo' => 'Ponto Facultativo',
                                    'paralisacao' => 'Paralisação'
                                ][$r['tipo']] ?? $r['tipo'];
                                
                                $cursosAfetados = $r['cursos_afetados'] ?? [];
                                $totalCursos = count($cursosAfetados);
                                
                                $temTurnoEspecifico = !empty($r['turno_curso']);
                                $temTipoCursoEspecifico = !empty($r['tipo_curso']);
                                $apenasUmCurso = $totalCursos === 1;
                                
                                if ($apenasUmCurso) {
                                    $turnoLabel = formatarTurno($cursosAfetados[0]['turno_curso'] ?? '');
                                    $turnoClass = 'badge-turno-' . ($cursosAfetados[0]['turno_curso'] ?? 'manha');
                                } elseif ($temTurnoEspecifico) {
                                    $turnoLabel = formatarTurno($r['turno_curso']);
                                    $turnoClass = 'badge-turno-' . ($r['turno_curso'] ?? 'todos');
                                } else {
                                    $turnoLabel = 'Todos';
                                    $turnoClass = '';
                                }
                                
                                if ($apenasUmCurso) {
                                    $tipoCursoLabel = formatarTipoCurso($cursosAfetados[0]['tipo_curso'] ?? '');
                                    $tipoCursoClass = 'badge-tipo-curso-' . ($cursosAfetados[0]['tipo_curso'] ?? '');
                                } elseif ($temTipoCursoEspecifico) {
                                    $tipoCursoLabel = formatarTipoCurso($r['tipo_curso']);
                                    $tipoCursoClass = 'badge-tipo-curso-' . ($r['tipo_curso'] ?? '');
                                } else {
                                    $tipoCursoLabel = 'Todos';
                                    $tipoCursoClass = '';
                                }
                                
                                $cursosNomes = [];
                                if (!empty($cursosAfetados)) {
                                    foreach ($cursosAfetados as $curso) {
                                        $cursosNomes[] = $curso['numero_curso'] . ' - ' . $curso['nome_curso'];
                                    }
                                }
                                
                                $diasSemana = !empty($r['dias_semana']) ? ucfirst(str_replace(',', ', ', $r['dias_semana'])) : 'Todos';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['nome_recesso']); ?></strong>
                                    <?php if (!empty($r['descricao'])): ?>
                                        <div class="info-detalhe">
                                            <i class="fas fa-comment"></i> <?php echo htmlspecialchars(substr($r['descricao'], 0, 40)); ?>
                                            <?php echo strlen($r['descricao']) > 40 ? '...' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $tipoClass; ?>"><?php echo $tipoLabel; ?></span>
                                </td>
                                <td>
                                    <div>
                                        <i class="fas fa-calendar-alt" style="color: #1a73e8;"></i>
                                        <?php echo date('d/m/Y', strtotime($r['data_inicio'])); ?>
                                        <?php if ($r['data_inicio'] != $r['data_fim']): ?>
                                            <i class="fas fa-arrow-right" style="color: #7a8aa0; margin: 0 4px;"></i>
                                            <?php echo date('d/m/Y', strtotime($r['data_fim'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($r['dias_semana'])): ?>
                                        <div class="info-detalhe">
                                            <i class="fas fa-calendar-week"></i> 
                                            <strong>Dias:</strong> <?php echo ucfirst(str_replace(',', ', ', $r['dias_semana'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($r['nome_unidade'] ?? 'Todas'); ?>
                                </td>
                                <td>
                                    <?php if ($apenasUmCurso): ?>
                                        <span class="badge-turno <?php echo $turnoClass; ?>">
                                            <?php echo $turnoLabel; ?>
                                        </span>
                                    <?php elseif ($temTurnoEspecifico): ?>
                                        <span class="badge-turno <?php echo $turnoClass; ?>">
                                            <?php echo $turnoLabel; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #7a8aa0; font-size: 12px;">Todos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($apenasUmCurso): ?>
                                        <span class="badge-tipo-curso <?php echo $tipoCursoClass; ?>">
                                            <?php echo $tipoCursoLabel; ?>
                                        </span>
                                    <?php elseif ($temTipoCursoEspecifico): ?>
                                        <span class="badge-tipo-curso <?php echo $tipoCursoClass; ?>">
                                            <?php echo $tipoCursoLabel; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #7a8aa0; font-size: 12px;">Todos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($apenasUmCurso): ?>
                                        <div class="apenas-um-curso-box">
                                            <i class="fas fa-graduation-cap" style="color: #43a047;"></i>
                                            <span class="numero-curso"><?php echo htmlspecialchars($cursosAfetados[0]['numero_curso']); ?></span>
                                            <span class="nome-curso">- <?php echo htmlspecialchars($cursosAfetados[0]['nome_curso']); ?></span>
                                            <span class="turno-curso">
                                                (<?php echo formatarTurno($cursosAfetados[0]['turno_curso'] ?? ''); ?>)
                                            </span>
                                        </div>
                                    <?php elseif (!empty($cursosNomes)): ?>
                                        <div class="cursos-container">
                                            <?php foreach ($cursosAfetados as $curso): ?>
                                                <span class="curso-tag">
                                                    <i class="fas fa-graduation-cap"></i>
                                                    <?php echo htmlspecialchars($curso['numero_curso']); ?>
                                                    <span class="turno-tag" style="font-size: 10px; color: #7a8aa0;">
                                                        (<?php echo formatarTurno($curso['turno_curso'] ?? ''); ?>)
                                                    </span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #1a73e8; font-weight: 500;">✅ Todos os cursos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>