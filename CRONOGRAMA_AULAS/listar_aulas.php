<?php
// ==========================================================
// listar_aulas.php - Listagem de Aulas do Dia (MODIFICADO PARA MULTI-TENANT)
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// PERMISSÕES - TODOS OS TIPOS DE USUÁRIO PODEM VISUALIZAR (NOVO SISTEMA)
// ============================================================
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

// ============================================================
// PERMISSÕES DE AÇÃO (NOVO SISTEMA)
// ============================================================
$pode_editar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);
$pode_cadastrar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

$caminhoBanco = __DIR__ . '/../conexao_banco.php';
if (!file_exists($caminhoBanco)) {
    die('Arquivo de conexão não encontrado.');
}
require_once $caminhoBanco;
if (!isset($conn)) {
    die('Erro: conexão com banco não estabelecida.');
}

// ============================================================
// DATA SELECIONADA (padrão: hoje)
// ============================================================
$data_selecionada = $_GET['data'] ?? date('Y-m-d');

// Validar formato da data
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_selecionada)) {
    $data_selecionada = date('Y-m-d');
}

// ============================================================
// CONSULTAR AULAS DO DIA SELECIONADO (FILTRADAS POR CLIENTE)
// ============================================================
try {
    $sql = "SELECT 
                c.*, 
                cu.nome_curso,
                cu.numero_curso,
                cu.status_curso,
                cu.dias_letivos AS dias_letivos_curso,
                cu.data_inicio_curso,
                cu.data_fim_curso_calculada,
                f.nome_funcionario AS nome_professor,
                s.numero_sala,
                u.nome_unidade,
                (SELECT COUNT(*) 
                 FROM cronograma c2 
                 WHERE c2.id_curso = c.id_curso 
                 AND c2.id_cliente = c.id_cliente
                 AND c2.status_aula != 'cancelada' 
                 AND (c2.data_aula < c.data_aula OR (c2.data_aula = c.data_aula AND c2.id_aula <= c.id_aula))) AS numero_aula_ordem
            FROM cronograma c
            LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
            LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
            LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
            LEFT JOIN unidades u ON cu.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
            WHERE c.data_aula = :data_aula
            AND c.id_cliente = :id_cliente";
    $params = [
        ':data_aula' => $data_selecionada,
        ':id_cliente' => $id_cliente
    ];

    // ============================================================
    // FILTRO POR UNIDADE (Gerente/Coordenador)
    // ============================================================
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND cu.id_unidade = :unidade";
        $params[':unidade'] = $id_unidade_usuario;
    }

    // Ordenação: por turno e horário
    $sql .= " ORDER BY FIELD(c.turno, 'manha', 'tarde', 'noite'), c.horario_inicio ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // AGRUPAR AULAS POR TURNO
    // ============================================================
    $aulasAgrupadas = [
        'manha' => [],
        'tarde' => [],
        'noite' => []
    ];
    
    // Contadores de status
    $contagemStatus = [
        'agendada' => 0,
        'realizada' => 0,
        'cancelada' => 0,
        'remarcada' => 0,
        'aguardando_remarcacao' => 0
    ];
    
    foreach ($aulas as $aula) {
        $turno = $aula['turno'] ?? 'manha';
        if (isset($aulasAgrupadas[$turno])) {
            $aulasAgrupadas[$turno][] = $aula;
        }
        
        $status = $aula['status_aula'] ?? 'agendada';
        if (isset($contagemStatus[$status])) {
            $contagemStatus[$status]++;
        }
    }
    
    $totalRegistros = count($aulas);
    $totalAgendadas = $contagemStatus['agendada'];
    $totalRealizadas = $contagemStatus['realizada'];
    $totalCanceladas = $contagemStatus['cancelada'];
    $totalRemarcadas = $contagemStatus['remarcada'];
    $totalAguardando = $contagemStatus['aguardando_remarcacao'];
    $totalAtivas = $totalAgendadas + $totalRemarcadas;

} catch (PDOException $e) {
    $erro = "Erro ao buscar aulas: " . $e->getMessage();
    $aulas = [];
    $aulasAgrupadas = ['manha' => [], 'tarde' => [], 'noite' => []];
    $totalRegistros = 0;
    $totalAgendadas = 0;
    $totalRealizadas = 0;
    $totalCanceladas = 0;
    $totalRemarcadas = 0;
    $totalAguardando = 0;
    $totalAtivas = 0;
}

// Mensagens da sessão
$message = getMessage();

$titulo = 'Aulas do Dia - Gerenciamento de Ambientes';

// Função para formatar data
function formatarData($data) {
    if (empty($data)) return '-';
    $timestamp = strtotime($data);
    $diasSemanaPT = [
        'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira', 'Wednesday' => 'Quarta-feira',
        'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
    ];
    $diaSemana = $diasSemanaPT[date('l', $timestamp)] ?? '';
    return $diaSemana . ', ' . date('d/m/Y', $timestamp);
}

// Função para formatar hora
function formatarHora($hora) {
    if (empty($hora)) return '-';
    return substr($hora, 0, 5);
}

// Verificar se a data é hoje
$hoje = date('Y-m-d');
$isHoje = ($data_selecionada === $hoje);
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

        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 24px 28px;
            margin-bottom: 20px;
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
            background: #28a745;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(40, 167, 69, 0.35);
        }
        .btn-success:hover {
            background: #218838;
            transform: scale(1.02);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #d8e0ec;
        }
        .btn-outline:hover {
            background: #f0f4fb;
        }
        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
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
            color: #212529;
            border: none;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-info {
            background: #17a2b8;
            color: #ffffff;
            border: none;
        }
        .btn-info:hover {
            background: #138496;
        }
        .btn-blocked {
            background: #e9ecef;
            color: #6c757d;
            border: 1px solid #d8e0ec;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .btn-blocked:hover {
            background: #e9ecef;
            transform: none;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .header-actions .btn {
            white-space: nowrap;
        }

        /* ======================================================
           NAVEGAÇÃO DE DATAS
        ====================================================== */
        .nav-datas {
            display: flex;
            align-items: center;
            gap: 16px;
            justify-content: center;
            padding: 12px 0;
            flex-wrap: wrap;
        }
        .nav-datas .btn {
            padding: 8px 16px;
            font-size: 14px;
            min-width: 100px;
            justify-content: center;
        }
        .nav-datas .data-atual {
            font-weight: 700;
            color: #0e1a2b;
            font-size: 18px;
            padding: 8px 24px;
            background: #f0f7ff;
            border-radius: 12px;
            border: 2px solid #1a73e8;
            min-width: 250px;
            text-align: center;
        }
        .nav-datas .data-atual .destaque-hoje {
            display: inline-block;
            background: #1a73e8;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 12px;
            border-radius: 12px;
            margin-left: 8px;
        }
        .nav-datas .data-atual .dia-semana {
            font-weight: 400;
            color: #5a6a7e;
            font-size: 14px;
            display: block;
        }

        /* ======================================================
           INDICADORES DE STATUS
        ====================================================== */
        .status-indicators {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: center;
            padding: 10px 0;
            margin-bottom: 8px;
        }
        .status-indicators .indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            background: #f8faff;
            border: 1px solid #e8edf5;
        }
        .status-indicators .indicator .count {
            font-weight: 700;
            font-size: 16px;
        }
        .status-indicators .indicator i {
            font-size: 14px;
        }
        .status-indicators .indicator.agendada { border-left: 4px solid #1a73e8; }
        .status-indicators .indicator.agendada i { color: #1a73e8; }
        .status-indicators .indicator.agendada .count { color: #1a73e8; }
        
        .status-indicators .indicator.realizada { border-left: 4px solid #28a745; }
        .status-indicators .indicator.realizada i { color: #28a745; }
        .status-indicators .indicator.realizada .count { color: #28a745; }
        
        .status-indicators .indicator.cancelada { border-left: 4px solid #dc3545; }
        .status-indicators .indicator.cancelada i { color: #dc3545; }
        .status-indicators .indicator.cancelada .count { color: #dc3545; }
        
        .status-indicators .indicator.remarcada { border-left: 4px solid #ffc107; }
        .status-indicators .indicator.remarcada i { color: #ffc107; }
        .status-indicators .indicator.remarcada .count { color: #d39e00; }
        
        .status-indicators .indicator.aguardando { border-left: 4px solid #6c757d; }
        .status-indicators .indicator.aguardando i { color: #6c757d; }
        .status-indicators .indicator.aguardando .count { color: #6c757d; }

        /* ======================================================
           TABELA COM ROLAGEM
        ====================================================== */
        .table-wrapper {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 400px;
        }

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
        .table-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0e1a2b;
        }
        .table-header h3 i {
            color: #1a73e8;
            margin-right: 8px;
        }

        .table-scroll {
            overflow: auto;
            flex: 1;
            min-height: 250px;
            max-height: 500px;
            padding: 0 4px 4px 4px;
            overflow-x: auto;
            overflow-y: scroll;
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
        .table-scroll {
            scrollbar-width: thin;
            scrollbar-color: #c1c9d6 #f0f4fb;
        }

        .table-aulas {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #ffffff;
            min-width: 1100px;
        }

        .table-aulas thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table-aulas th {
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

        .table-aulas td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f4fc;
            color: #1a2639;
            font-size: 13px;
            white-space: nowrap;
        }

        .table-aulas tbody tr:hover {
            background: #f8faff;
        }
        .table-aulas tbody tr:last-child td {
            border-bottom: none;
        }

        .turno-separator {
            background: #f8faff !important;
            border-bottom: 2px solid #e2e9f3 !important;
        }
        .turno-separator td {
            padding: 8px 14px !important;
            font-weight: 600;
            color: #1a73e8;
            font-size: 14px;
            background: #f0f7ff !important;
        }
        .turno-separator td i {
            margin-right: 8px;
        }
        .turno-separator td .badge-count {
            background: #e3f2fd;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            color: #0d47a1;
            margin-left: 8px;
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
        .badge-success { background: #e6f7e9; color: #1e8546; }
        .badge-warning { background: #fff2e0; color: #b86a1f; }
        .badge-danger { background: #ffe9e9; color: #b33a3a; }
        .badge-info { background: #e3f2fd; color: #0d47a1; }
        .badge-secondary { background: #e9ecef; color: #6c757d; }

        .badge-turno {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-turno.manha { background: #fff3cd; color: #856404; }
        .badge-turno.tarde { background: #fce4ec; color: #c62828; }
        .badge-turno.noite { background: #e3f2fd; color: #0d47a1; }

        .badge-curso-status {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-curso-status.ativo { background: #e6f7e9; color: #1e8546; }
        .badge-curso-status.inativo { background: #ffe9e9; color: #b33a3a; }
        .badge-curso-status.concluido { background: #e3f2fd; color: #0d47a1; }

        /* ======================================================
           ALERTAS, EMPTY STATE
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
        .alert i { font-size: 18px; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7a8aa0;
            font-size: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .empty-state i {
            font-size: 56px;
            color: #dce3ef;
            display: block;
            margin-bottom: 16px;
        }
        .empty-state p {
            font-size: 15px;
            color: #7a8aa0;
        }
        .empty-state .sub-text {
            font-size: 13px;
            color: #9aabbf;
            margin-top: 4px;
        }

        /* ======================================================
           AÇÕES
        ====================================================== */
        .btn-action {
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            padding: 3px 10px;
            border-radius: 60px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-edit {
            background: #e7edfe;
            color: #1a73e8;
        }
        .btn-edit:hover {
            background: #d0defb;
        }
        .btn-view {
            background: #f0f4fb;
            color: #5a6a7e;
        }
        .btn-view:hover {
            background: #e2e9f3;
        }
        .btn-blocked-action {
            background: #f0f0f0;
            color: #999;
            cursor: not-allowed;
            opacity: 0.6;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 60px;
        }

        .professor-nao-definido {
            color: #999;
            font-style: italic;
            font-size: 12px;
        }
        .professor-nao-definido i {
            color: #ccc;
            margin-right: 4px;
        }

        .data-aula-destaque {
            background: #e3f2fd;
            padding: 2px 10px;
            border-radius: 6px;
            font-weight: 600;
            color: #0d47a1;
            display: inline-block;
            font-size: 12px;
        }
        .data-aula-destaque i {
            margin-right: 4px;
            color: #1a73e8;
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
            .sidebar.open { left: 0; }
            .main { padding: 16px 18px; }
            .table-aulas {
                font-size: 12px;
                min-width: 1000px;
            }
            .table-aulas th,
            .table-aulas td {
                padding: 8px 10px;
            }
            .menu-toggle {
                display: block;
            }
            .nav-datas {
                gap: 10px;
            }
            .nav-datas .data-atual {
                font-size: 15px;
                min-width: 180px;
                padding: 6px 16px;
            }
            .nav-datas .btn {
                padding: 6px 12px;
                font-size: 12px;
                min-width: 70px;
            }
            .status-indicators {
                gap: 10px;
            }
            .status-indicators .indicator {
                font-size: 12px;
                padding: 4px 12px;
            }
            .header-actions .btn {
                font-size: 12px;
                padding: 6px 14px;
            }
            .table-scroll {
                min-height: 200px;
                max-height: 350px;
            }
            .table-wrapper {
                min-height: 300px;
            }
        }

        @media (max-width: 540px) {
            .main { padding: 12px 14px; }
            .card-panel { padding: 16px; }
            .table-aulas { min-width: 800px; font-size: 11px; }
            .table-aulas th,
            .table-aulas td { padding: 6px 8px; }
            .table-scroll {
                min-height: 150px;
                max-height: 250px;
            }
            .table-wrapper {
                min-height: 220px;
            }
            .nav-datas {
                flex-direction: column;
                gap: 8px;
            }
            .nav-datas .data-atual {
                font-size: 14px;
                min-width: unset;
                width: 100%;
            }
            .nav-datas .btn {
                width: 100%;
                justify-content: center;
            }
            .status-indicators {
                flex-direction: column;
                align-items: center;
            }
            .status-indicators .indicator {
                width: 100%;
                justify-content: center;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .header-actions {
                width: 100%;
                flex-direction: column;
            }
            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
            .empty-state {
                padding: 30px 16px;
            }
            .empty-state i {
                font-size: 40px;
            }
        }

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

        .total-info {
            font-size: 14px;
            color: #5a6a7e;
        }
        .total-info strong {
            color: #0e1a2b;
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <!-- CONTEÚDO PRINCIPAL -->
    <main class="main">
        <!-- CABEÇALHO -->
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-calendar-day"></i> Aulas do Dia</h1>
                <p class="page-subtitle">Visualize todas as aulas da data selecionada</p>
            </div>
            <div class="header-actions">
                <!-- ============================================================
                BOTÃO "AGENDAR AULAS" - APENAS PARA ADMIN E GERENTE
                ============================================================ -->
                <?php if ($pode_cadastrar): ?>
                    <a href="agendar_aula.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Agendar Aulas
                    </a>
                <?php endif; ?>
                
                <!-- ============================================================
                BOTÃO "VER TODAS AS AULAS DE UM CURSO"
                ============================================================ -->
                <a href="ver_aulas_por_curso.php" class="btn btn-success">
                    <i class="fas fa-book"></i> Ver Aulas por Curso
                </a>
            </div>
        </header>

        <?php if ($message && $message['tipo'] === 'error'): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($message['mensagem']); ?></div>
        <?php endif; ?>
        <?php if ($message && $message['tipo'] === 'success'): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message['mensagem']); ?></div>
        <?php endif; ?>

        <!-- NAVEGAÇÃO DE DATAS -->
        <div class="card-panel">
            <div class="nav-datas">
                <a href="?data=<?php echo date('Y-m-d', strtotime($data_selecionada . ' -1 day')); ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-chevron-left"></i> Anterior
                </a>
                
                <div class="data-atual">
                    <span class="dia-semana">
                        <?php 
                            $timestamp = strtotime($data_selecionada);
                            $diasSemanaPT = [
                                'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira', 
                                'Wednesday' => 'Quarta-feira', 'Thursday' => 'Quinta-feira',
                                'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
                            ];
                            echo $diasSemanaPT[date('l', $timestamp)] ?? '';
                        ?>
                    </span>
                    <?php echo date('d/m/Y', strtotime($data_selecionada)); ?>
                    <?php if ($isHoje): ?>
                        <span class="destaque-hoje"><i class="fas fa-star"></i> Hoje</span>
                    <?php endif; ?>
                </div>

                <a href="?data=<?php echo date('Y-m-d', strtotime($data_selecionada . ' +1 day')); ?>" class="btn btn-outline btn-sm">
                    Próximo <i class="fas fa-chevron-right"></i>
                </a>
            </div>

            <!-- INDICADORES DE STATUS -->
            <div class="status-indicators">
                <div class="indicator agendada">
                    <i class="fas fa-clock"></i>
                    <span class="count"><?php echo $totalAgendadas; ?></span>
                    Agendadas
                </div>
                <div class="indicator realizada">
                    <i class="fas fa-check-circle"></i>
                    <span class="count"><?php echo $totalRealizadas; ?></span>
                    Realizadas
                </div>
                <div class="indicator cancelada">
                    <i class="fas fa-times-circle"></i>
                    <span class="count"><?php echo $totalCanceladas; ?></span>
                    Canceladas
                </div>
                <div class="indicator remarcada">
                    <i class="fas fa-clock"></i>
                    <span class="count"><?php echo $totalRemarcadas; ?></span>
                    Remarcadas
                </div>
                <div class="indicator aguardando">
                    <i class="fas fa-hourglass-half"></i>
                    <span class="count"><?php echo $totalAguardando; ?></span>
                    Aguardando
                </div>
            </div>

            <div style="text-align: center; font-size: 13px; color: #7a8aa0; padding-top: 4px;">
                <i class="fas fa-info-circle"></i> 
                <strong><?php echo $totalRegistros; ?></strong> aula(s) no total • 
                <span style="color: #1a73e8; font-weight: 500;"><?php echo $totalAtivas; ?></span> ativas
                <?php if ($tipo_usuario === 'gerente' && !empty($id_unidade_usuario)): ?>
                    • <i class="fas fa-building"></i> Sua unidade
                <?php endif; ?>
            </div>
        </div>

        <!-- TABELA COM SCROLL -->
        <div class="table-wrapper">
            <div class="table-header">
                <h3>
                    <i class="fas fa-list"></i> 
                    Aulas do dia <?php echo date('d/m/Y', strtotime($data_selecionada)); ?>
                </h3>
                <span class="total-info">
                    Total: <strong><?php echo $totalRegistros; ?></strong> aula<?php echo $totalRegistros != 1 ? 's' : ''; ?>
                </span>
            </div>

            <!-- CAIXA DE ROLAGEM -->
            <div class="table-scroll">
                <?php if ($totalRegistros == 0): ?>
                    <table class="table-aulas">
                        <thead>
                            <tr>
                                <th style="min-width: 60px;">Nº</th>
                                <th style="min-width: 180px;">Curso</th>
                                <th style="min-width: 80px;">Status Curso</th>
                                <th style="min-width: 150px;">Professor</th>
                                <th style="min-width: 80px;">Sala</th>
                                <th style="min-width: 100px;">Horário</th>
                                <th style="min-width: 100px;">Status</th>
                                <th style="min-width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-check"></i>
                                        <p><strong>Nenhuma aula encontrada para esta data.</strong></p>
                                        <p class="sub-text"><?php echo formatarData($data_selecionada); ?> - Nenhuma aula agendada.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <table class="table-aulas">
                        <thead>
                            <tr>
                                <th style="min-width: 60px;">Nº</th>
                                <th style="min-width: 180px;">Curso</th>
                                <th style="min-width: 80px;">Status Curso</th>
                                <th style="min-width: 150px;">Professor</th>
                                <th style="min-width: 80px;">Sala</th>
                                <th style="min-width: 100px;">Horário</th>
                                <th style="min-width: 100px;">Status</th>
                                <th style="min-width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $turnos = [
                                'manha' => ['label' => '☀️ Manhã', 'icon' => 'fa-sun', 'color' => '#F9A825'],
                                'tarde' => ['label' => '☀️ Tarde', 'icon' => 'fa-cloud-sun', 'color' => '#E65100'],
                                'noite' => ['label' => '🌙 Noite', 'icon' => 'fa-moon', 'color' => '#0D47A1']
                            ];
                            
                            foreach ($turnos as $turnoKey => $turnoInfo):
                                $aulasTurno = $aulasAgrupadas[$turnoKey] ?? [];
                                if (empty($aulasTurno)) continue;
                            ?>
                            <!-- Separador de turno -->
                            <tr class="turno-separator">
                                <td colspan="8">
                                    <i class="fas <?php echo $turnoInfo['icon']; ?>" style="color: <?php echo $turnoInfo['color']; ?>;"></i>
                                    <?php echo $turnoInfo['label']; ?>
                                    <span class="badge-count"><?php echo count($aulasTurno); ?> aula<?php echo count($aulasTurno) != 1 ? 's' : ''; ?></span>
                                </td>
                            </tr>
                            
                            <?php foreach ($aulasTurno as $a): 
                                $dataAulaFormatada = date('d/m/Y', strtotime($a['data_aula']));
                                
                                $numeroAula = $a['numero_aula_ordem'] ?? '-';
                                $dataInicioCursoComparacao = $a['data_inicio_curso'] ?? null;
                                $cursoComecou = !empty($dataInicioCursoComparacao) && strtotime($dataInicioCursoComparacao) <= time();
                                
                                if ($numeroAula !== '-' && $cursoComecou) {
                                    $numeroAulaExibicao = $numeroAula;
                                } elseif ($numeroAula !== '-' && !$cursoComecou) {
                                    $numeroAulaExibicao = 0;
                                } else {
                                    $numeroAulaExibicao = '-';
                                }
                                
                                $horaInicio = formatarHora($a['horario_inicio']);
                                $horaFim = formatarHora($a['horario_fim']);
                                $horario = $horaInicio . ' - ' . $horaFim;
                                $turno = ucfirst($a['turno']);
                                
                                $nomeProfessor = $a['nome_professor'] ?? null;
                                $temProfessor = !empty($nomeProfessor);
                                
                                // Status da aula
                                $status = $a['status_aula'];
                                $statusBadge = '';
                                if ($status === 'agendada') {
                                    $statusBadge = '<span class="badge badge-info"><i class="fas fa-clock"></i> Agendada</span>';
                                } elseif ($status === 'realizada') {
                                    $statusBadge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Realizada</span>';
                                } elseif ($status === 'cancelada') {
                                    $statusBadge = '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
                                } elseif ($status === 'remarcada') {
                                    $statusBadge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Remarcada</span>';
                                } elseif ($status === 'aguardando_remarcacao') {
                                    $statusBadge = '<span class="badge badge-secondary"><i class="fas fa-hourglass-half"></i> Aguardando</span>';
                                } else {
                                    $statusBadge = '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
                                }
                                
                                // Status do curso
                                $statusCurso = $a['status_curso'] ?? 'ativo';
                                $statusCursoBadge = '';
                                if ($statusCurso === 'ativo') {
                                    $statusCursoBadge = '<span class="badge-curso-status ativo">✅ Ativo</span>';
                                } elseif ($statusCurso === 'inativo') {
                                    $statusCursoBadge = '<span class="badge-curso-status inativo">❌ Inativo</span>';
                                } elseif ($statusCurso === 'concluido') {
                                    $statusCursoBadge = '<span class="badge-curso-status concluido">📌 Concluído</span>';
                                }
                                
                                $podeEditarAula = $pode_editar && $statusCurso === 'ativo';
                            ?>
                            <tr>
                                <td>
                                    <?php if ($numeroAulaExibicao !== '-'): ?>
                                        <strong style="color: #1a73e8; font-size: 15px;">
                                            <?php echo $numeroAulaExibicao; ?>ª
                                        </strong>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($a['nome_curso'] ?? 'Não definido'); ?></strong>
                                    <br>
                                    <span style="font-size: 11px; color: #7a8aa0;">
                                        Turma: <?php echo htmlspecialchars($a['numero_curso'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td><?php echo $statusCursoBadge; ?></td>
                                <td>
                                    <?php if ($temProfessor): ?>
                                        <span style="color: #1a2639; font-size: 12px;">
                                            <i class="fas fa-user-tie" style="color: #1a73e8; margin-right: 4px;"></i>
                                            <?php echo htmlspecialchars($nomeProfessor); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="professor-nao-definido">
                                            <i class="fas fa-user-slash"></i>
                                            Não definido
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 500; color: #0e1a2b; font-size: 13px;">
                                        <i class="fas fa-door-open" style="color: #1a73e8;"></i>
                                        <?php echo htmlspecialchars($a['numero_sala'] ?? 'Sem sala'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 500; font-size: 13px;">
                                        <i class="fas fa-clock" style="color: #1a73e8;"></i>
                                        <?php echo $horario; ?>
                                    </span>
                                    <br>
                                    <span class="badge-turno <?php echo $a['turno']; ?>">
                                        <?php echo $turno; ?>
                                    </span>
                                </td>
                                <td><?php echo $statusBadge; ?></td>
                                <td>
                                    <?php if ($podeEditarAula): ?>
                                        <a href="editar_aula.php?id=<?php echo $a['id_aula']; ?>" class="btn-action btn-edit">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                    <?php elseif ($pode_editar && ($statusCurso === 'inativo' || $statusCurso === 'concluido')): ?>
                                        <span class="btn-blocked-action" title="Curso <?php echo $statusCurso; ?> - Não é possível editar">
                                            <i class="fas fa-lock"></i> Bloqueado
                                        </span>
                                    <?php else: ?>
                                        <a href="visualizar_aula.php?id=<?php echo $a['id_aula']; ?>" class="btn-action btn-view">
                                            <i class="fas fa-eye"></i> Ver
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- RODAPÉ -->
        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <script>
        // Menu toggle para mobile
        document.querySelector('.menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('open');
        });

        // Fechar sidebar ao clicar fora
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const toggle = document.querySelector('.menu-toggle');
            if (window.innerWidth <= 820) {
                if (sidebar && !sidebar.contains(event.target) && !toggle?.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    </script>
</body>
</html>