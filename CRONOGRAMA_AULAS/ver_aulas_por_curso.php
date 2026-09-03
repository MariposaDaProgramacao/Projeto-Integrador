<?php
// ==========================================================
// ver_aulas_por_curso.php - Visualização de Aulas por Curso (MODIFICADO PARA MULTI-TENANT)
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
// PERMISSÕES (NOVO SISTEMA)
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente', 'usuario', 'visualizador'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

$caminhoBanco = __DIR__ . '/../conexao_banco.php';
if (!file_exists($caminhoBanco)) {
    die('Arquivo de conexão não encontrado.');
}
require_once $caminhoBanco;
if (!isset($conn)) {
    die('Erro: conexão com banco não estabelecida.');
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

// ============================================================
// PERMISSÃO PARA EDITAR (NOVO SISTEMA)
// ============================================================
$pode_editar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

// ============================================================
// BUSCAR CURSOS PARA O SELECT (FILTRADOS POR CLIENTE)
// ============================================================
try {
    $sqlCursos = "SELECT 
                    c.id_curso,
                    c.numero_curso,
                    c.nome_curso,
                    c.id_unidade,
                    c.dias_letivos,
                    c.data_inicio_curso,
                    c.data_fim_curso_calculada,
                    c.dias_semana,
                    c.turno_curso,
                    c.status_curso,
                    c.id_cliente,
                    u.nome_unidade,
                    COUNT(cron.id_aula) AS total_aulas,
                    SUM(CASE WHEN cron.status_aula = 'concluida' THEN 1 ELSE 0 END) AS aulas_concluidas,
                    SUM(CASE WHEN cron.status_aula = 'agendada' THEN 1 ELSE 0 END) AS aulas_agendadas,
                    SUM(CASE WHEN cron.status_aula = 'cancelada' THEN 1 ELSE 0 END) AS aulas_canceladas
                FROM cursos c
                LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                LEFT JOIN cronograma cron ON c.id_curso = cron.id_curso AND cron.id_cliente = c.id_cliente
                WHERE c.status_curso = 'ativo'
                AND c.id_cliente = :id_cliente";
    
    if ($tipo_usuario === 'gerente') {
        $sqlCursos .= " AND c.id_unidade = :id_unidade";
    }
    
    $sqlCursos .= " GROUP BY c.id_curso";
    $sqlCursos .= " ORDER BY c.numero_curso, c.nome_curso";
    
    $stmtCursos = $conn->prepare($sqlCursos);
    $params = [':id_cliente' => $id_cliente];
    if ($tipo_usuario === 'gerente') {
        $params[':id_unidade'] = $id_unidade_usuario;
    }
    $stmtCursos->execute($params);
    
    $cursos = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cursos = [];
    $mensagem_erro = '❌ Erro ao buscar cursos: ' . $e->getMessage();
}

// ============================================================
// BUSCAR DADOS DO CURSO SELECIONADO (FILTRADOS POR CLIENTE)
// ============================================================
$cursoSelecionado = null;
$aulas = [];
$totalAulas = 0;
$concluidas = 0;
$agendadas = 0;
$canceladas = 0;

if (isset($_GET['id_curso']) && !empty($_GET['id_curso'])) {
    $idCurso = (int)$_GET['id_curso'];
    
    try {
        // Buscar dados do curso
        $sqlCurso = "SELECT 
                        c.*,
                        u.nome_unidade,
                        u.endereco_unidade,
                        u.telefone_unidade,
                        COUNT(cron.id_aula) AS total_aulas,
                        SUM(CASE WHEN cron.status_aula = 'concluida' THEN 1 ELSE 0 END) AS aulas_concluidas,
                        SUM(CASE WHEN cron.status_aula = 'agendada' THEN 1 ELSE 0 END) AS aulas_agendadas,
                        SUM(CASE WHEN cron.status_aula = 'cancelada' THEN 1 ELSE 0 END) AS aulas_canceladas
                    FROM cursos c
                    LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                    LEFT JOIN cronograma cron ON c.id_curso = cron.id_curso AND cron.id_cliente = c.id_cliente
                    WHERE c.id_curso = :id_curso
                    AND c.id_cliente = :id_cliente
                    GROUP BY c.id_curso";
        
        $stmtCurso = $conn->prepare($sqlCurso);
        $stmtCurso->execute([
            ':id_curso' => $idCurso,
            ':id_cliente' => $id_cliente
        ]);
        $cursoSelecionado = $stmtCurso->fetch(PDO::FETCH_ASSOC);
        
        if ($cursoSelecionado) {
            // Buscar todas as aulas do curso
            $sqlAulas = "SELECT 
                            cron.id_aula,
                            cron.data_aula,
                            cron.horario_inicio,
                            cron.horario_fim,
                            cron.turno,
                            cron.status_aula,
                            cron.observacao,
                            cron.id_sala,
                            cron.id_professor,
                            s.numero_sala,
                            s.tipo_sala,
                            s.capacidade_sala,
                            s.descricao_sala,
                            f.nome_funcionario AS nome_professor,
                            f.email_funcionario AS email_professor
                        FROM cronograma cron
                        LEFT JOIN salas s ON cron.id_sala = s.id_sala AND s.id_cliente = cron.id_cliente
                        LEFT JOIN funcionarios f ON cron.id_professor = f.id_funcionario AND f.id_cliente = cron.id_cliente
                        WHERE cron.id_curso = :id_curso
                        AND cron.id_cliente = :id_cliente
                        ORDER BY cron.data_aula ASC, cron.horario_inicio ASC";
            
            $stmtAulas = $conn->prepare($sqlAulas);
            $stmtAulas->execute([
                ':id_curso' => $idCurso,
                ':id_cliente' => $id_cliente
            ]);
            $aulas = $stmtAulas->fetchAll(PDO::FETCH_ASSOC);
            
            $totalAulas = count($aulas);
            $concluidas = $cursoSelecionado['aulas_concluidas'] ?? 0;
            $agendadas = $cursoSelecionado['aulas_agendadas'] ?? 0;
            $canceladas = $cursoSelecionado['aulas_canceladas'] ?? 0;
        }
    } catch (PDOException $e) {
        $mensagem_erro = '❌ Erro ao buscar dados do curso: ' . $e->getMessage();
    }
}

// ============================================================
// FUNÇÃO PARA FORMATAR DATA
// ============================================================
function formatarData($data) {
    if (empty($data)) return '-';
    $timestamp = strtotime($data);
    return date('d/m/Y', $timestamp);
}

// ============================================================
// FUNÇÃO PARA FORMATAR DIA DA SEMANA
// ============================================================
function getDiaSemana($data) {
    $dias = [
        'Monday' => 'Segunda-feira',
        'Tuesday' => 'Terça-feira',
        'Wednesday' => 'Quarta-feira',
        'Thursday' => 'Quinta-feira',
        'Friday' => 'Sexta-feira',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    ];
    $nome = date('l', strtotime($data));
    return $dias[$nome] ?? $nome;
}

// ============================================================
// FUNÇÃO PARA COR DO STATUS
// ============================================================
function getStatusBadge($status) {
    $classes = [
        'agendada' => 'badge-agendada',
        'concluida' => 'badge-concluida',
        'cancelada' => 'badge-cancelada',
        'andamento' => 'badge-andamento',
        'aguardando_remarcacao' => 'badge-aguardando'
    ];
    $icons = [
        'agendada' => 'fa-calendar-check',
        'concluida' => 'fa-check-circle',
        'cancelada' => 'fa-times-circle',
        'andamento' => 'fa-spinner',
        'aguardando_remarcacao' => 'fa-hourglass-half'
    ];
    $labels = [
        'agendada' => '📅 Agendada',
        'concluida' => '✅ Concluída',
        'cancelada' => '❌ Cancelada',
        'andamento' => '🔄 Em Andamento',
        'aguardando_remarcacao' => '⏳ Aguardando'
    ];
    
    $classe = $classes[$status] ?? 'badge-agendada';
    $icone = $icons[$status] ?? 'fa-calendar-check';
    $label = $labels[$status] ?? $status;
    
    return '<span class="' . $classe . '"><i class="fas ' . $icone . '"></i> ' . $label . '</span>';
}

$titulo = 'Ver Aulas por Curso - Gerenciamento de Ambientes';

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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        /* ============================================================
           RESET E BASE
           ============================================================ */
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

        /* ============================================================
           SIDEBAR
           ============================================================ */
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

        @media (max-width: 820px) {
            .sidebar {
                display: none;
            }
        }

        /* ============================================================
           CONTEÚDO PRINCIPAL
           ============================================================ */
        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* ============================================================
           HEADER
           ============================================================ */
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
        }
        .page-title i {
            color: #1a73e8;
            margin-right: 10px;
        }
        .page-subtitle {
            font-size: 14px;
            color: #7a8aa0;
            margin-top: 4px;
        }

        /* ============================================================
           BOTÕES
           ============================================================ */
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

        .btn-secondary {
            background: #e2e9f3;
            color: #1a2639;
            border: 1px solid #d8e0ec;
        }
        .btn-secondary:hover {
            background: #d0dbe8;
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

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        /* ============================================================
           BOTÃO EDITAR AULA
           ============================================================ */
        .btn-edit-aula {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            background: #1a73e8;
            color: #ffffff;
            border: none;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-edit-aula:hover {
            background: #1557b0;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
        }
        .btn-edit-aula i {
            font-size: 12px;
        }
        .btn-edit-aula.blocked {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .btn-edit-aula.blocked:hover {
            transform: none;
            box-shadow: none;
        }
        .btn-edit-aula.blocked i {
            color: #6c757d;
        }

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
        .btn-view {
            background: #f0f4fb;
            color: #5a6a7e;
        }
        .btn-view:hover {
            background: #e2e9f3;
        }

        /* ============================================================
           SELECT2
           ============================================================ */
        .select2-container--default .select2-selection--single {
            border: 1.5px solid #e2e9f3;
            border-radius: 10px;
            height: 44px;
            padding: 4px 10px;
            background: #fafcff;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 34px;
            color: #1a2639;
            font-size: 14px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        }
        .select2-dropdown {
            border: 1.5px solid #e2e9f3;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .select2-search__field {
            border-radius: 8px !important;
            border: 1.5px solid #e2e9f3 !important;
            padding: 8px 12px !important;
            font-size: 14px !important;
        }
        .select2-results__option {
            padding: 10px 14px !important;
            font-size: 14px;
        }
        .select2-results__option--highlighted {
            background: #e3f2fd !important;
            color: #1a2639 !important;
        }
        .select2-results__option[aria-selected="true"] {
            background: #1a73e8 !important;
            color: #ffffff !important;
        }

        /* ============================================================
           FILTRO
           ============================================================ */
        .filtro-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .filtro-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filtro-row .form-group {
            flex: 1;
            min-width: 200px;
            margin-bottom: 0;
        }

        .filtro-row .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2d3a4f;
            margin-bottom: 5px;
        }
        .filtro-row .form-group label i {
            color: #1a73e8;
            margin-right: 6px;
        }

        .filtro-row .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e9f3;
            border-radius: 10px;
            font-size: 14px;
            background: #fafcff;
            font-family: 'Inter', sans-serif;
            color: #1a2639;
            transition: all 0.2s;
            height: 44px;
        }
        .filtro-row .form-group select:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
            outline: none;
        }

        /* ============================================================
           CARD DO CURSO
           ============================================================ */
        .curso-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .curso-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f4fb;
        }

        .curso-titulo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .curso-titulo .numero {
            font-size: 28px;
            font-weight: 700;
            color: #1a73e8;
        }
        .curso-titulo .nome {
            font-size: 22px;
            font-weight: 600;
            color: #0e1a2b;
        }
        .curso-titulo .status-curso {
            font-size: 13px;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
            background: #e6f7e9;
            color: #1e8546;
        }
        .curso-titulo .status-curso.inativo {
            background: #ffe9e9;
            color: #b33a3a;
        }

        .curso-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px 24px;
        }

        .curso-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #eef4fa;
        }
        .curso-info-item i {
            font-size: 18px;
            color: #1a73e8;
            width: 24px;
            text-align: center;
        }
        .curso-info-item .label {
            font-size: 12px;
            color: #7a8aa0;
            font-weight: 500;
        }
        .curso-info-item .value {
            font-size: 14px;
            color: #0e1a2b;
            font-weight: 600;
        }

        /* ============================================================
           ESTATÍSTICAS
           ============================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .stat-item {
            text-align: center;
            padding: 12px 16px;
            border-radius: 10px;
            background: #f8faff;
            border: 1px solid #eef4fa;
        }
        .stat-item .number {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
        }
        .stat-item .label {
            font-size: 12px;
            color: #7a8aa0;
            font-weight: 500;
        }
        .stat-item.total .number {
            color: #1a73e8;
        }
        .stat-item.concluidas .number {
            color: #28a745;
        }
        .stat-item.agendadas .number {
            color: #ffc107;
        }
        .stat-item.canceladas .number {
            color: #dc3545;
        }

        /* ============================================================
           TABELA DE AULAS COM CAIXA DE ROLAGEM
           ============================================================ */
        .aulas-table-wrapper {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 400px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            background: #f8faff;
            border-bottom: 1px solid #ebf0f8;
            flex-wrap: wrap;
            gap: 12px;
            flex-shrink: 0;
        }

        .table-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #0e1a2b;
        }
        .table-header h3 i {
            color: #1a73e8;
            margin-right: 8px;
        }

        .table-header .total-info {
            font-size: 13px;
            color: #5a6a7e;
        }
        .table-header .total-info strong {
            color: #0e1a2b;
        }

        /* ============================================================
           CAIXA DE ROLAGEM - SEMPRE VISÍVEL
           ============================================================ */
        .table-scroll-wrapper {
            overflow: auto;
            flex: 1;
            min-height: 250px;
            max-height: 500px;
            padding: 0 4px 4px 4px;
        }

        .table-scroll-wrapper {
            overflow-x: scroll;
            overflow-y: scroll;
        }

        .table-scroll-wrapper::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-track {
            background: #f0f4fb;
            border-radius: 8px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #c1cbd8;
            border-radius: 8px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a0aebf;
        }

        .table-scroll-wrapper::-webkit-scrollbar-corner {
            background: #f0f4fb;
        }

        .table-scroll-wrapper {
            scrollbar-width: thin;
            scrollbar-color: #c1cbd8 #f0f4fb;
        }

        /* ============================================================
           TABELA
           ============================================================ */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 900px;
        }

        table thead {
            background: #f8faff;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table thead th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #2d3a4f;
            border-bottom: 2px solid #e2e9f3;
            white-space: nowrap;
            background: #f8faff;
        }
        table thead th i {
            color: #1a73e8;
            margin-right: 4px;
        }

        table tbody tr {
            border-bottom: 1px solid #f0f4fb;
            transition: background 0.15s;
        }
        table tbody tr:hover {
            background: #f8faff;
        }
        table tbody tr:last-child {
            border-bottom: none;
        }

        table tbody td {
            padding: 14px 16px;
            color: #1a2639;
            vertical-align: middle;
        }

        table tbody td .data-completa {
            display: flex;
            flex-direction: column;
        }
        table tbody td .data-completa .data {
            font-weight: 600;
            font-size: 14px;
            color: #0e1a2b;
        }
        table tbody td .data-completa .dia-semana {
            font-size: 12px;
            color: #7a8aa0;
        }

        table tbody td .sala-info {
            display: flex;
            flex-direction: column;
        }
        table tbody td .sala-info .numero {
            font-weight: 600;
            color: #0e1a2b;
        }
        table tbody td .sala-info .detalhe {
            font-size: 12px;
            color: #7a8aa0;
        }

        table tbody td .professor-info {
            display: flex;
            flex-direction: column;
        }
        table tbody td .professor-info .nome {
            font-weight: 500;
            color: #0e1a2b;
        }
        table tbody td .professor-info .email {
            font-size: 12px;
            color: #7a8aa0;
        }

        .horario-cell {
            font-weight: 600;
            color: #0e1a2b;
            white-space: nowrap;
        }
        .horario-cell i {
            color: #7a8aa0;
            margin: 0 4px;
        }

        /* ============================================================
           BADGES DE STATUS
           ============================================================ */
        .badge-agendada {
            background: #fff8e1;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-concluida {
            background: #e6f7e9;
            color: #1e8546;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-cancelada {
            background: #ffe9e9;
            color: #b33a3a;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-andamento {
            background: #e3f2fd;
            color: #1a73e8;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-aguardando {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-turno {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        .badge-turno.manha {
            background: #fff3cd;
            color: #856404;
        }
        .badge-turno.tarde {
            background: #cce5ff;
            color: #004085;
        }
        .badge-turno.noite {
            background: #d6d8db;
            color: #383d41;
        }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #7a8aa0;
        }
        .empty-state i {
            font-size: 56px;
            color: #dce3ef;
            display: block;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            color: #0e1a2b;
            margin-bottom: 8px;
        }
        .empty-state p {
            font-size: 14px;
            color: #7a8aa0;
        }

        /* ============================================================
           ALERTAS
           ============================================================ */
        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert i {
            font-size: 18px;
            margin-top: 1px;
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
        .alert-warning {
            background: #fff8e1;
            color: #856404;
            border: 1px solid #ffecb5;
        }
        .alert-info {
            background: #e3f2fd;
            color: #004085;
            border: 1px solid #b8d4f0;
        }

        /* ============================================================
           RESPONSIVIDADE
           ============================================================ */
        @media (max-width: 768px) {
            .main {
                padding: 16px;
            }
            .curso-card {
                padding: 20px 16px;
            }
            .curso-titulo .numero {
                font-size: 22px;
            }
            .curso-titulo .nome {
                font-size: 18px;
            }
            .filtro-row {
                flex-direction: column;
            }
            .filtro-row .form-group {
                width: 100%;
                min-width: unset;
            }
            .curso-info-grid {
                grid-template-columns: 1fr 1fr;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .table-scroll-wrapper {
                min-height: 200px;
                max-height: 350px;
            }
            .aulas-table-wrapper {
                min-height: 300px;
            }
            table {
                min-width: 700px;
                font-size: 13px;
            }
            table thead th,
            table tbody td {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .curso-info-grid {
                grid-template-columns: 1fr;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
            .curso-titulo {
                flex-wrap: wrap;
            }
            .curso-titulo .numero {
                font-size: 20px;
            }
            .curso-titulo .nome {
                font-size: 16px;
            }
            .table-scroll-wrapper {
                min-height: 150px;
                max-height: 280px;
            }
            .aulas-table-wrapper {
                min-height: 220px;
            }
            table {
                min-width: 600px;
                font-size: 12px;
            }
            table thead th,
            table tbody td {
                padding: 8px 10px;
            }
            .empty-state {
                padding: 30px 16px;
            }
            .empty-state i {
                font-size: 40px;
            }
        }
    </style>
</head>
<body>

    <!-- ============================================================
    SIDEBAR
    ============================================================ -->
    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <!-- ============================================================
    CONTEÚDO PRINCIPAL
    ============================================================ -->
    <main class="main">

        <!-- HEADER -->
        <header class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-chalkboard-teacher"></i> Ver Aulas por Curso
                </h1>
                <p class="page-subtitle">Visualize todos os dados do curso e a lista completa de aulas</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="listar_aulas.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
                <?php if ($cursoSelecionado): ?>
                    <a href="editar_curso.php?id=<?php echo $cursoSelecionado['id_curso']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar Curso
                    </a>
                    <a href="agendar_aula.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Nova Aula
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- MENSAGENS -->
        <?php if (isset($mensagem_erro) && !empty($mensagem_erro)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $mensagem_erro; ?>
            </div>
        <?php endif; ?>

        <?php if ($message && $message['tipo'] === 'error'): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($message['mensagem']); ?>
            </div>
        <?php endif; ?>

        <!-- FILTRO -->
        <div class="filtro-card">
            <form method="GET" action="" id="formFiltro">
                <div class="filtro-row">
                    <div class="form-group">
                        <label for="id_curso"><i class="fas fa-book"></i> Selecione um Curso</label>
                        <select name="id_curso" id="id_curso" style="width: 100%;" required>
                            <option value="">Selecione um curso...</option>
                            <?php foreach ($cursos as $curso): 
                                $selected = (isset($_GET['id_curso']) && $_GET['id_curso'] == $curso['id_curso']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $curso['id_curso']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($curso['numero_curso'] . ' - ' . $curso['nome_curso']); ?>
                                    <span style="color: #7a8aa0; font-size: 12px;">
                                        (<?php echo $curso['total_aulas'] ?? 0; ?> aulas)
                                    </span>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Visualizar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($cursoSelecionado): ?>
            
            <!-- ============================================================
            CARD DO CURSO
            ============================================================ -->
            <div class="curso-card">
                <div class="curso-header">
                    <div class="curso-titulo">
                        <span class="numero"><?php echo htmlspecialchars($cursoSelecionado['numero_curso']); ?></span>
                        <span class="nome"><?php echo htmlspecialchars($cursoSelecionado['nome_curso']); ?></span>
                        <span class="status-curso <?php echo $cursoSelecionado['status_curso'] === 'ativo' ? '' : 'inativo'; ?>">
                            <?php echo $cursoSelecionado['status_curso'] === 'ativo' ? '✅ Ativo' : '❌ Inativo'; ?>
                        </span>
                    </div>
                    <div>
                        <span style="font-size: 13px; color: #7a8aa0;">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($cursoSelecionado['nome_unidade'] ?? 'Unidade não definida'); ?>
                        </span>
                    </div>
                </div>

                <div class="curso-info-grid">
                    <div class="curso-info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <div class="label">Período</div>
                            <div class="value">
                                <?php echo formatarData($cursoSelecionado['data_inicio_curso']); ?> 
                                <i class="fas fa-arrow-right" style="font-size: 12px; color: #7a8aa0;"></i> 
                                <?php echo formatarData($cursoSelecionado['data_fim_curso_calculada']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="curso-info-item">
                        <i class="fas fa-calendar-week"></i>
                        <div>
                            <div class="label">Dias Letivos</div>
                            <div class="value"><?php echo $cursoSelecionado['dias_letivos'] ?? 'Não definido'; ?></div>
                        </div>
                    </div>
                    <div class="curso-info-item">
                        <i class="fas fa-calendar-day"></i>
                        <div>
                            <div class="label">Dias da Semana</div>
                            <div class="value"><?php echo $cursoSelecionado['dias_semana'] ?? 'Não definido'; ?></div>
                        </div>
                    </div>
                    <div class="curso-info-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <div class="label">Turno</div>
                            <div class="value">
                                <?php 
                                    $turno = $cursoSelecionado['turno_curso'] ?? '';
                                    $turnoLabels = ['manha' => '☀️ Manhã', 'tarde' => '☀️ Tarde', 'noite' => '🌙 Noite'];
                                    echo $turnoLabels[$turno] ?? $turno;
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ESTATÍSTICAS -->
                <div class="stats-row">
                    <div class="stat-item total">
                        <div class="number"><?php echo $totalAulas; ?></div>
                        <div class="label">📚 Total de Aulas</div>
                    </div>
                    <div class="stat-item concluidas">
                        <div class="number"><?php echo $concluidas; ?></div>
                        <div class="label">✅ Concluídas</div>
                    </div>
                    <div class="stat-item agendadas">
                        <div class="number"><?php echo $agendadas; ?></div>
                        <div class="label">📅 Agendadas</div>
                    </div>
                    <div class="stat-item canceladas">
                        <div class="number"><?php echo $canceladas; ?></div>
                        <div class="label">❌ Canceladas</div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            TABELA DE AULAS COM CAIXA DE ROLAGEM E BOTÃO EDITAR
            ============================================================ -->
            <div class="aulas-table-wrapper">
                <div class="table-header">
                    <h3>
                        <i class="fas fa-list"></i> 
                        Lista de Aulas
                    </h3>
                    <span class="total-info">
                        <strong><?php echo $totalAulas; ?></strong> aulas encontradas
                        <?php if ($totalAulas > 0): ?>
                            <span style="color: #7a8aa0; font-size: 12px;">
                                (<?php echo $concluidas; ?> concluídas, 
                                <?php echo $agendadas; ?> agendadas, 
                                <?php echo $canceladas; ?> canceladas)
                            </span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- ============================================================
                CAIXA DE ROLAGEM - SEMPRE VISÍVEL
                ============================================================ -->
                <div class="table-scroll-wrapper">
                    <?php if ($totalAulas > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar-day"></i> Data</th>
                                    <th><i class="fas fa-clock"></i> Horário</th>
                                    <th><i class="fas fa-door-open"></i> Sala</th>
                                    <th><i class="fas fa-user-tie"></i> Professor</th>
                                    <th><i class="fas fa-sun"></i> Turno</th>
                                    <th><i class="fas fa-circle"></i> Status</th>
                                    <th><i class="fas fa-cog"></i> Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aulas as $aula): 
                                    $statusCurso = $cursoSelecionado['status_curso'] ?? 'ativo';
                                    $podeEditarAula = $pode_editar && $statusCurso === 'ativo';
                                ?>
                                    <tr>
                                        <td>
                                            <div class="data-completa">
                                                <span class="data"><?php echo formatarData($aula['data_aula']); ?></span>
                                                <span class="dia-semana">
                                                    <i class="far fa-calendar-alt"></i> 
                                                    <?php echo getDiaSemana($aula['data_aula']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="horario-cell">
                                            <?php 
                                                $inicio = substr($aula['horario_inicio'] ?? '', 0, 5);
                                                $fim = substr($aula['horario_fim'] ?? '', 0, 5);
                                                echo $inicio . ' <i class="fas fa-arrow-right"></i> ' . $fim;
                                            ?>
                                        </td>
                                        <td>
                                            <div class="sala-info">
                                                <span class="numero">
                                                    <i class="fas fa-door-open" style="color: #1a73e8; font-size: 12px;"></i>
                                                    Sala <?php echo htmlspecialchars($aula['numero_sala'] ?? 'N/A'); ?>
                                                </span>
                                                <?php if (!empty($aula['tipo_sala'])): ?>
                                                    <span class="detalhe">
                                                        <?php echo htmlspecialchars($aula['tipo_sala']); ?>
                                                        <?php if ($aula['capacidade_sala']): ?>
                                                            | <?php echo $aula['capacidade_sala']; ?> pessoas
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($aula['nome_professor'])): ?>
                                                <div class="professor-info">
                                                    <span class="nome">
                                                        <i class="fas fa-user" style="color: #1a73e8; font-size: 12px;"></i>
                                                        <?php echo htmlspecialchars($aula['nome_professor']); ?>
                                                    </span>
                                                    <?php if (!empty($aula['email_professor'])): ?>
                                                        <span class="email">
                                                            <i class="fas fa-envelope" style="font-size: 10px;"></i>
                                                            <?php echo htmlspecialchars($aula['email_professor']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #7a8aa0; font-size: 13px;">
                                                    <i class="fas fa-user-slash"></i> Não definido
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $turno = $aula['turno'] ?? '';
                                                $turnoLabels = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
                                                $turnoClass = $turno;
                                            ?>
                                            <span class="badge-turno <?php echo $turnoClass; ?>">
                                                <?php echo $turnoLabels[$turno] ?? $turno; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo getStatusBadge($aula['status_aula'] ?? 'agendada'); ?>
                                            <?php if (!empty($aula['observacao'])): ?>
                                                <br>
                                                <span style="font-size: 11px; color: #7a8aa0;">
                                                    <i class="fas fa-comment"></i> 
                                                    <?php echo htmlspecialchars(substr($aula['observacao'], 0, 30)); ?>
                                                    <?php echo strlen($aula['observacao']) > 30 ? '...' : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <!-- ============================================================
                                            BOTÃO EDITAR - REDIRECIONA PARA editar_aula.php
                                            ============================================================ -->
                                            <?php if ($podeEditarAula): ?>
                                                <a href="editar_aula.php?id=<?php echo $aula['id_aula']; ?>" class="btn-edit-aula" title="Editar esta aula">
                                                    <i class="fas fa-edit"></i> Editar
                                                </a>
                                            <?php else: ?>
                                                <span class="btn-edit-aula blocked" title="Curso <?php echo $statusCurso; ?> - Não é possível editar">
                                                    <i class="fas fa-lock"></i> Bloqueado
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>Nenhuma aula encontrada</h3>
                            <p>Este curso ainda não possui aulas cadastradas.</p>
                            <br>
                            <a href="agendar_aula.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Cadastrar Aulas
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif (isset($_GET['id_curso']) && !empty($_GET['id_curso'])): ?>
            <!-- Curso não encontrado -->
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                Curso não encontrado ou não está disponível para visualização.
            </div>
        <?php else: ?>
            <!-- Nenhum curso selecionado -->
            <div class="empty-state" style="background: #ffffff; border-radius: 16px; border: 1px solid #ebf0f8; padding: 60px 24px;">
                <i class="fas fa-book-open"></i>
                <h3>Selecione um curso para visualizar</h3>
                <p>Escolha um curso no campo acima para ver todos os detalhes e a lista completa de aulas.</p>
            </div>
        <?php endif; ?>

        <!-- RODAPÉ -->
        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <!-- ============================================================
    JAVASCRIPT
    ============================================================ -->
    <script>
        $(document).ready(function() {
            // ============================================================
            // SELECT2 PARA CURSO
            // ============================================================
            $('#id_curso').select2({
                placeholder: 'Selecione um curso...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return 'Nenhum curso encontrado';
                    },
                    searching: function() {
                        return 'Buscando...';
                    }
                }
            });

            // ============================================================
            // SUBMIT AUTOMÁTICO AO SELECIONAR
            // ============================================================
            $('#id_curso').on('change', function() {
                if ($(this).val()) {
                    $('#formFiltro').submit();
                }
            });
        });
    </script>
</body>
</html>