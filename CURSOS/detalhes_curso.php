<?php
// ============================================================
// detalhes_curso.php - Visualização Detalhada de um Curso (MODIFICADO PARA MULTI-TENANT)
// ============================================================

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
$pode_editar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

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
// RECEBER ID DO CURSO
// ============================================================
$id_curso = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_curso <= 0) {
    setMessage('error', 'ID do curso inválido.');
    redirect('listar_cursos.php');
}

// ============================================================
// FUNÇÃO PARA BUSCAR DIAS DE RECESSO DA UNIDADE (MODIFICADA)
// ============================================================
function buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso = null) {
    try {
        $sql = "SELECT data_inicio, data_fim, nome_recesso, tipo, id_cursos 
                FROM recessos 
                WHERE id_unidade = :id_unidade 
                AND id_cliente = :id_cliente
                AND ativo = 1";
        
        $params = [
            ':id_unidade' => $id_unidade,
            ':id_cliente' => $id_cliente
        ];
        
        if ($id_curso) {
            $sql .= " AND (id_cursos IS NULL OR FIND_IN_SET(:id_curso, id_cursos) > 0)";
            $params[':id_curso'] = $id_curso;
        }
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $recessos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $diasRecesso = [];
        foreach ($recessos as $recesso) {
            $dataInicio = new DateTime($recesso['data_inicio']);
            $dataFim = new DateTime($recesso['data_fim']);
            $dataFim->modify('+1 day');
            
            while ($dataInicio < $dataFim) {
                $dataStr = $dataInicio->format('Y-m-d');
                $diasRecesso[$dataStr] = [
                    'nome' => $recesso['nome_recesso'],
                    'tipo' => $recesso['tipo']
                ];
                $dataInicio->modify('+1 day');
            }
        }
        
        return $diasRecesso;
    } catch (PDOException $e) {
        return [];
    }
}

// ============================================================
// BUSCAR DADOS DO CURSO (FILTRADOS POR CLIENTE)
// ============================================================
try {
    $sql = "SELECT 
                c.*,
                u.nome_unidade,
                u.endereco_unidade,
                u.telefone_unidade,
                f.nome_funcionario AS nome_professor_responsavel,
                f.email_funcionario AS email_professor_responsavel,
                f.telefone_funcionario AS telefone_professor_responsavel,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente) AS total_aulas,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente AND status_aula = 'realizada') AS aulas_realizadas,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente AND status_aula = 'agendada') AS aulas_agendadas,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente AND status_aula = 'cancelada') AS aulas_canceladas,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente AND status_aula = 'remarcada') AS aulas_remarcadas,
                (SELECT COUNT(*) FROM cronograma WHERE id_curso = c.id_curso AND id_cliente = c.id_cliente AND status_aula = 'aguardando_remarcacao') AS aulas_aguardando
            FROM cursos c
            LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
            LEFT JOIN funcionarios f ON c.id_docente = f.id_funcionario AND f.id_cliente = c.id_cliente
            WHERE c.id_curso = :id_curso
            AND c.id_cliente = :id_cliente";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id_curso' => $id_curso,
        ':id_cliente' => $id_cliente
    ]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        setMessage('error', 'Curso não encontrado.');
        redirect('listar_cursos.php');
    }

    // Verificar permissão do coordenador (gerente)
    if ($tipo_usuario === 'gerente' && $curso['id_unidade'] != $id_unidade_usuario) {
        setMessage('error', 'Você não tem permissão para visualizar este curso.');
        redirect('listar_cursos.php');
    }

    // Buscar dias de recesso do curso
    $diasRecesso = buscarDiasRecesso($conn, $curso['id_unidade'], $id_cliente, $id_curso);

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar curso: ' . $e->getMessage());
    redirect('listar_cursos.php');
}

// ============================================================
// BUSCAR AULAS DO CURSO (FILTRADAS POR CLIENTE)
// ============================================================
try {
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
                    f.email_funcionario AS email_professor,
                    (SELECT COUNT(*) 
                     FROM cronograma c2 
                     WHERE c2.id_curso = cron.id_curso 
                     AND c2.id_cliente = cron.id_cliente
                     AND c2.status_aula != 'cancelada' 
                     AND (c2.data_aula < cron.data_aula OR (c2.data_aula = cron.data_aula AND c2.id_aula <= cron.id_aula))) AS numero_aula_ordem
                FROM cronograma cron
                LEFT JOIN salas s ON cron.id_sala = s.id_sala AND s.id_cliente = cron.id_cliente
                LEFT JOIN funcionarios f ON cron.id_professor = f.id_funcionario AND f.id_cliente = cron.id_cliente
                WHERE cron.id_curso = :id_curso
                AND cron.id_cliente = :id_cliente
                ORDER BY cron.data_aula ASC, cron.horario_inicio ASC";
    
    $stmtAulas = $conn->prepare($sqlAulas);
    $stmtAulas->execute([
        ':id_curso' => $id_curso,
        ':id_cliente' => $id_cliente
    ]);
    $aulas = $stmtAulas->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $aulas = [];
}

// ============================================================
// CALCULAR PROGRESSO
// ============================================================
$totalAulas = $curso['total_aulas'] ?? 0;
$aulasRealizadas = $curso['aulas_realizadas'] ?? 0;
$percentualConclusao = $totalAulas > 0 ? round(($aulasRealizadas / $totalAulas) * 100, 2) : 0;

// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarHora($hora) {
    if (empty($hora)) return '-';
    return substr($hora, 0, 5);
}

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

function getStatusBadge($status) {
    $classes = [
        'agendada' => 'badge-agendada',
        'realizada' => 'badge-realizada',
        'cancelada' => 'badge-cancelada',
        'remarcada' => 'badge-remarcada',
        'aguardando_remarcacao' => 'badge-aguardando'
    ];
    $icons = [
        'agendada' => 'fa-calendar-check',
        'realizada' => 'fa-check-circle',
        'cancelada' => 'fa-times-circle',
        'remarcada' => 'fa-clock',
        'aguardando_remarcacao' => 'fa-hourglass-half'
    ];
    $labels = [
        'agendada' => '📅 Agendada',
        'realizada' => '✅ Realizada',
        'cancelada' => '❌ Cancelada',
        'remarcada' => '🔄 Remarcada',
        'aguardando_remarcacao' => '⏳ Aguardando'
    ];
    
    $classe = $classes[$status] ?? 'badge-agendada';
    $icone = $icons[$status] ?? 'fa-calendar-check';
    $label = $labels[$status] ?? $status;
    
    return '<span class="' . $classe . '"><i class="fas ' . $icone . '"></i> ' . $label . '</span>';
}

function getTurnoLabel($turno) {
    $turnos = [
        'manha' => '☀️ Manhã',
        'tarde' => '🌤️ Tarde',
        'noite' => '🌙 Noite'
    ];
    return $turnos[$turno] ?? $turno;
}

// ============================================================
// FUNÇÃO PARA VERIFICAR SE É FIM DE SEMANA
// ============================================================
function isFimDeSemana($data) {
    $diaSemana = date('N', strtotime($data));
    return $diaSemana >= 6;
}

// ============================================================
// FUNÇÃO PARA VERIFICAR SE É RECESSO
// ============================================================
function isRecesso($data, $diasRecesso) {
    return isset($diasRecesso[$data]);
}

// ============================================================
// FUNÇÃO PARA GERAR O INDICADOR DE STATUS DO DIA
// ============================================================
function getDiaStatus($data, $diasRecesso) {
    if (isset($diasRecesso[$data])) {
        $recesso = $diasRecesso[$data];
        $tipoLabels = [
            'feriado' => 'Feriado',
            'recesso' => 'Recesso',
            'ponto_facultativo' => 'Ponto Facultativo',
            'paralisacao' => 'Paralisação'
        ];
        $tipo = $tipoLabels[$recesso['tipo']] ?? 'Recesso';
        return [
            'status' => 'recesso',
            'label' => $recesso['nome'],
            'tipo' => $tipo,
            'icon' => 'fa-calendar-times',
            'color' => '#dc3545',
            'bg' => '#ffe9e9'
        ];
    }
    
    if (isFimDeSemana($data)) {
        $diaSemana = date('l', strtotime($data));
        $dias = [
            'Saturday' => 'Sábado',
            'Sunday' => 'Domingo'
        ];
        return [
            'status' => 'fim_de_semana',
            'label' => $dias[$diaSemana] ?? 'Fim de semana',
            'tipo' => 'Fim de semana',
            'icon' => 'fa-calendar-week',
            'color' => '#6c757d',
            'bg' => '#f5f5f5'
        ];
    }
    
    return null;
}

// Mensagens da sessão
$message = getMessage();

$titulo = 'Detalhes do Curso - Gerenciamento de Ambientes';
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
        
        .main::-webkit-scrollbar {
            width: 10px;
        }
        .main::-webkit-scrollbar-track {
            background: #f0f4fb;
            border-radius: 8px;
        }
        .main::-webkit-scrollbar-thumb {
            background: #c1c9d6;
            border-radius: 8px;
        }
        .main::-webkit-scrollbar-thumb:hover {
            background: #a8b2c4;
        }
        .main {
            scrollbar-width: thin;
            scrollbar-color: #c1c9d6 #f0f4fb;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
            flex-shrink: 0;
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
        
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
            flex-shrink: 0;
        }
        .info-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 20px;
            border: 1px solid #ebf0f8;
            text-align: center;
            transition: all 0.2s;
        }
        .info-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }
        .info-card .icon {
            font-size: 28px;
            margin-bottom: 6px;
        }
        .info-card .number {
            font-size: 26px;
            font-weight: 700;
            color: #0e1a2b;
        }
        .info-card .label {
            font-size: 13px;
            color: #7a8aa0;
            font-weight: 500;
        }
        .info-card .number.blue { color: #1a73e8; }
        .info-card .number.green { color: #28a745; }
        .info-card .number.orange { color: #f9ab00; }
        .info-card .number.red { color: #dc3545; }
        .info-card .number.purple { color: #6f42c1; }
        
        .curso-detalhes {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 28px 32px;
            margin-bottom: 24px;
            flex-shrink: 0;
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
            flex-wrap: wrap;
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
        .curso-titulo .status {
            font-size: 13px;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
        }
        .status-ativo { background: #e6f7e9; color: #1e8546; }
        .status-inativo { background: #ffe9e9; color: #b33a3a; }
        .status-concluido { background: #e3f2fd; color: #0d47a1; }
        
        .curso-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px 24px;
        }
        .curso-item {
            display: flex;
            flex-direction: column;
            padding: 10px 14px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #eef4fa;
        }
        .curso-item .label {
            font-size: 11px;
            color: #7a8aa0;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .curso-item .value {
            font-size: 15px;
            font-weight: 600;
            color: #0e1a2b;
            margin-top: 2px;
        }
        .curso-item .value i {
            color: #1a73e8;
            margin-right: 6px;
        }
        .curso-item .value .badge-tipo {
            font-size: 12px;
            font-weight: 500;
            padding: 2px 10px;
            border-radius: 12px;
        }
        .badge-tipo-tecnico { background: #e3f2fd; color: #0d47a1; }
        .badge-tipo-agil { background: #e8f5e9; color: #1b5e20; }
        .badge-tipo-pos { background: #f3e5f5; color: #6a1b9a; }
        
        .progresso-container {
            margin-top: 16px;
            padding: 16px 20px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #eef4fa;
        }
        .progresso-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .progresso-header .label {
            font-size: 14px;
            font-weight: 600;
            color: #0e1a2b;
        }
        .progresso-header .percentual {
            font-size: 20px;
            font-weight: 700;
        }
        .progresso-header .percentual.verde { color: #28a745; }
        .progresso-header .percentual.amarelo { color: #f9ab00; }
        .progresso-header .percentual.vermelho { color: #dc3545; }
        
        .progresso-bar {
            width: 100%;
            height: 10px;
            background: #e2e9f3;
            border-radius: 8px;
            overflow: hidden;
        }
        .progresso-bar .fill {
            height: 100%;
            border-radius: 8px;
            transition: width 0.6s ease;
            background: linear-gradient(90deg, #1a73e8, #4dabf7);
        }
        .progresso-bar .fill.verde { background: linear-gradient(90deg, #28a745, #5cb85c); }
        .progresso-bar .fill.amarelo { background: linear-gradient(90deg, #f9ab00, #ffc107); }
        .progresso-bar .fill.vermelho { background: linear-gradient(90deg, #dc3545, #ff6b6b); }
        
        .progresso-detalhes {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
            font-size: 13px;
            color: #5a6a7e;
        }
        .progresso-detalhes span i {
            color: #1a73e8;
            margin-right: 4px;
        }
        
        .table-wrapper {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            flex: 1;
            display: flex;
            flex-direction: column;
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
            min-height: 300px;
            max-height: 550px;
            padding: 0 4px 4px 4px;
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
        .table-scroll {
            scrollbar-width: thin;
            scrollbar-color: #c1c9d6 #f0f4fb;
        }
        
        .table-aulas {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
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
            vertical-align: middle;
        }
        .table-aulas tbody tr:hover {
            background: #f8faff;
        }
        .table-aulas tbody tr:last-child td {
            border-bottom: none;
        }
        
        .badge-agendada { background: #fff8e1; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-realizada { background: #e6f7e9; color: #1e8546; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-cancelada { background: #ffe9e9; color: #b33a3a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-remarcada { background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-aguardando { background: #e3f2fd; color: #0d47a1; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        
        .badge-recesso {
            background: #ffe9e9;
            color: #b33a3a;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #ffd6d6;
        }
        .badge-recesso i {
            color: #dc3545;
        }
        .badge-fim-semana {
            background: #f5f5f5;
            color: #6c757d;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #e2e9f3;
        }
        .badge-fim-semana i {
            color: #6c757d;
        }
        
        .dia-sem-aula {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #f8faff;
            border-radius: 8px;
            border-left: 4px solid #6c757d;
            font-size: 13px;
            color: #5a6a7e;
            margin: 2px 0;
        }
        .dia-sem-aula.recesso {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .dia-sem-aula.fim-de-semana {
            border-left-color: #6c757d;
            background: #f8f9fa;
        }
        
        .badge-turno {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-turno.manha { background: #fff3cd; color: #856404; }
        .badge-turno.tarde { background: #cce5ff; color: #004085; }
        .badge-turno.noite { background: #d6d8db; color: #383d41; }
        
        .sala-tag {
            display: inline-block;
            background: #f0f4fb;
            color: #1a2639;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            border: 1px solid #e2e9f3;
        }
        .sala-tag i {
            color: #1a73e8;
            margin-right: 4px;
        }
        
        .professor-tag {
            display: flex;
            flex-direction: column;
        }
        .professor-tag .nome {
            font-weight: 500;
            color: #0e1a2b;
        }
        .professor-tag .email {
            font-size: 11px;
            color: #7a8aa0;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7a8aa0;
        }
        .empty-state i {
            font-size: 48px;
            color: #dce3ef;
            display: block;
            margin-bottom: 12px;
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
        .footer-system { text-align: center; font-size: 12px; color: #8a9bb5; padding: 16px 0 8px; border-top: 1px solid #e2e9f3; margin-top: auto; background: transparent; flex-shrink: 0; }
        
        .dia-sem-aula-row {
            background: #fafcff !important;
        }
        .dia-sem-aula-row td {
            padding: 6px 14px !important;
            color: #5a6a7e !important;
        }
        
        @media (max-width: 820px) {
            .sidebar { display: none; }
            .main { padding: 16px 18px; }
            .curso-grid { grid-template-columns: 1fr 1fr; }
            .info-cards { grid-template-columns: repeat(2, 1fr); }
            .table-aulas { min-width: 900px; }
        }
        @media (max-width: 540px) {
            .main { padding: 12px 14px; }
            .curso-header { flex-direction: column; }
            .curso-grid { grid-template-columns: 1fr; }
            .info-cards { grid-template-columns: 1fr; }
            .table-aulas { min-width: 700px; font-size: 12px; }
            .table-aulas th, .table-aulas td { padding: 6px 8px; }
            .curso-titulo .numero { font-size: 22px; }
            .curso-titulo .nome { font-size: 18px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .table-scroll { min-height: 200px; max-height: 350px; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-graduation-cap"></i> Detalhes do Curso</h1>
                <p class="page-subtitle">Visualize todas as informações do curso e sua grade de aulas</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="listar_cursos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
                <?php if ($pode_editar): ?>
                    <a href="editar_cursos.php?id=<?php echo $id_curso; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar Curso
                    </a>
                    <a href="agendar_aula.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Adicionar Aula
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ============================================================
        CARDS DE ESTATÍSTICAS
        ============================================================ -->
        <div class="info-cards">
            <div class="info-card">
                <div class="icon">📚</div>
                <div class="number blue"><?php echo $totalAulas; ?></div>
                <div class="label">Total de Aulas</div>
            </div>
            <div class="info-card">
                <div class="icon">✅</div>
                <div class="number green"><?php echo $aulasRealizadas; ?></div>
                <div class="label">Aulas Realizadas</div>
            </div>
            <div class="info-card">
                <div class="icon">📅</div>
                <div class="number orange"><?php echo $curso['aulas_agendadas'] ?? 0; ?></div>
                <div class="label">Aulas Agendadas</div>
            </div>
            <div class="info-card">
                <div class="icon">⏳</div>
                <div class="number purple"><?php echo $curso['aulas_aguardando'] ?? 0; ?></div>
                <div class="label">Aguardando</div>
            </div>
            <div class="info-card">
                <div class="icon">❌</div>
                <div class="number red"><?php echo $curso['aulas_canceladas'] ?? 0; ?></div>
                <div class="label">Canceladas</div>
            </div>
        </div>

        <!-- ============================================================
        INFORMAÇÕES DO CURSO
        ============================================================ -->
        <div class="curso-detalhes">
            <div class="curso-header">
                <div class="curso-titulo">
                    <span class="numero"><?php echo htmlspecialchars($curso['numero_curso']); ?></span>
                    <span class="nome"><?php echo htmlspecialchars($curso['nome_curso']); ?></span>
                    <?php
                        $statusClass = '';
                        $statusLabel = $curso['status_curso'];
                        if ($curso['status_curso'] === 'ativo') {
                            $statusClass = 'status-ativo';
                            $statusLabel = '✅ Ativo';
                        } elseif ($curso['status_curso'] === 'inativo') {
                            $statusClass = 'status-inativo';
                            $statusLabel = '❌ Inativo';
                        } elseif ($curso['status_curso'] === 'concluido') {
                            $statusClass = 'status-concluido';
                            $statusLabel = '📌 Concluído';
                        }
                    ?>
                    <span class="status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                </div>
                <div>
                    <span style="font-size: 13px; color: #7a8aa0;">
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($curso['nome_unidade'] ?? 'Unidade não definida'); ?>
                    </span>
                </div>
            </div>

            <div class="curso-grid">
                <div class="curso-item">
                    <span class="label"><i class="fas fa-calendar-alt"></i> Período</span>
                    <span class="value">
                        <i class="fas fa-calendar-plus"></i> <?php echo formatarData($curso['data_inicio_curso']); ?>
                        <i class="fas fa-arrow-right" style="color: #7a8aa0; font-size: 12px;"></i>
                        <i class="fas fa-calendar-check"></i> <?php echo formatarData($curso['data_fim_curso_calculada']); ?>
                    </span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-clock"></i> Turno</span>
                    <span class="value"><?php echo getTurnoLabel($curso['turno_curso']); ?></span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-tag"></i> Tipo</span>
                    <span class="value">
                        <?php
                            $tipoLabels = [
                                'curso_tecnico' => 'Técnico',
                                'curso_agil' => 'Ágil',
                                'pos_graduacao' => 'Pós-graduação'
                            ];
                            $tipoClass = [
                                'curso_tecnico' => 'badge-tipo-tecnico',
                                'curso_agil' => 'badge-tipo-agil',
                                'pos_graduacao' => 'badge-tipo-pos'
                            ];
                            $tipo = $curso['tipo_curso'] ?? '';
                        ?>
                        <span class="badge-tipo <?php echo $tipoClass[$tipo] ?? ''; ?>">
                            <?php echo $tipoLabels[$tipo] ?? $tipo; ?>
                        </span>
                    </span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-user-tie"></i> Professor Responsável</span>
                    <span class="value">
                        <?php if (!empty($curso['nome_professor_responsavel'])): ?>
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($curso['nome_professor_responsavel']); ?>
                            <?php if (!empty($curso['email_professor_responsavel'])): ?>
                                <br><span style="font-size: 12px; color: #7a8aa0; font-weight: 400;">
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($curso['email_professor_responsavel']); ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #7a8aa0; font-weight: 400;">Não definido</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-calendar-week"></i> Dias da Semana</span>
                    <span class="value"><?php echo ucfirst(str_replace(',', ', ', $curso['dias_semana'])); ?></span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-hourglass-half"></i> Dias Letivos</span>
                    <span class="value"><?php echo $curso['dias_letivos'] ?? 'Não definido'; ?> dias</span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-clock"></i> Carga Horária</span>
                    <span class="value"><?php echo $curso['carga_horaria_curso']; ?> horas</span>
                </div>
                <div class="curso-item">
                    <span class="label"><i class="fas fa-door-open"></i> Tipo de Sala</span>
                    <span class="value"><?php echo !empty($curso['tipo_sala_preferencial']) ? htmlspecialchars($curso['tipo_sala_preferencial']) : 'Não definido'; ?></span>
                </div>
            </div>

            <!-- ============================================================
            PROGRESSO
            ============================================================ -->
            <div class="progresso-container">
                <div class="progresso-header">
                    <span class="label"><i class="fas fa-chart-line"></i> Progresso do Curso</span>
                    <?php
                        $corClasse = 'verde';
                        $corPercentual = '';
                        if ($percentualConclusao >= 80) {
                            $corClasse = 'verde';
                            $corPercentual = 'verde';
                        } elseif ($percentualConclusao >= 40) {
                            $corClasse = 'amarelo';
                            $corPercentual = 'amarelo';
                        } else {
                            $corClasse = 'vermelho';
                            $corPercentual = 'vermelho';
                        }
                    ?>
                    <span class="percentual <?php echo $corPercentual; ?>"><?php echo number_format($percentualConclusao, 1); ?>%</span>
                </div>
                <div class="progresso-bar">
                    <div class="fill <?php echo $corClasse; ?>" style="width: <?php echo $percentualConclusao; ?>%;"></div>
                </div>
                <div class="progresso-detalhes">
                    <span><i class="fas fa-check-circle" style="color: #28a745;"></i> <?php echo $aulasRealizadas; ?> concluídas</span>
                    <span><i class="fas fa-calendar-check" style="color: #1a73e8;"></i> <?php echo $curso['aulas_agendadas'] ?? 0; ?> agendadas</span>
                    <span><i class="fas fa-clock" style="color: #6f42c1;"></i> <?php echo $curso['aulas_aguardando'] ?? 0; ?> aguardando</span>
                    <span><i class="fas fa-times-circle" style="color: #dc3545;"></i> <?php echo $curso['aulas_canceladas'] ?? 0; ?> canceladas</span>
                    <span><i class="fas fa-sync-alt" style="color: #f9ab00;"></i> <?php echo $curso['aulas_remarcadas'] ?? 0; ?> remarcadas</span>
                    <span><i class="fas fa-book"></i> <?php echo $totalAulas; ?> total</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        TABELA DE AULAS
        ============================================================ -->
        <div class="table-wrapper">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Grade de Aulas</h3>
                <span style="font-size: 13px; color: #7a8aa0;">
                    <strong><?php echo $totalAulas; ?></strong> aulas encontradas
                    <?php if (!empty($diasRecesso)): ?>
                        <span style="color: #dc3545; margin-left: 10px;">
                            <i class="fas fa-calendar-times"></i> <?php echo count($diasRecesso); ?> recesso(s)
                        </span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="table-scroll">
                <?php if (empty($aulas)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>Nenhuma aula cadastrada para este curso.</p>
                        <?php if ($pode_editar): ?>
                            <br>
                            <a href="agendar_aula.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Cadastrar Aulas
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table class="table-aulas">
                        <thead>
                            <tr>
                                <th style="min-width: 50px;">#</th>
                                <th style="min-width: 130px;">Data</th>
                                <th style="min-width: 100px;">Horário</th>
                                <th style="min-width: 80px;">Sala</th>
                                <th style="min-width: 150px;">Professor</th>
                                <th style="min-width: 80px;">Turno</th>
                                <th style="min-width: 130px;">Status</th>
                                <th style="min-width: 120px;">Indicador</th>
                                <th style="min-width: 100px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $ultimaData = null;
                            foreach ($aulas as $aula): 
                                $dataAtual = $aula['data_aula'];
                                
                                if ($ultimaData) {
                                    $dataUltima = new DateTime($ultimaData);
                                    $dataAtualObj = new DateTime($dataAtual);
                                    $intervalo = $dataUltima->diff($dataAtualObj)->days;
                                    
                                    if ($intervalo > 1) {
                                        $dataVerificacao = clone $dataUltima;
                                        $dataVerificacao->modify('+1 day');
                                        
                                        while ($dataVerificacao < $dataAtualObj) {
                                            $dataVerificar = $dataVerificacao->format('Y-m-d');
                                            $diaStatus = getDiaStatus($dataVerificar, $diasRecesso);
                                            
                                            if ($diaStatus) {
                                                echo '<tr class="dia-sem-aula-row">';
                                                echo '<td colspan="9">';
                                                echo '<div class="dia-sem-aula ' . $diaStatus['status'] . '">';
                                                echo '<i class="fas ' . $diaStatus['icon'] . '" style="color: ' . $diaStatus['color'] . ';"></i>';
                                                echo '<strong>' . formatarData($dataVerificar) . '</strong> - ';
                                                echo '<span style="font-weight: 500;">' . $diaStatus['label'] . '</span>';
                                                echo ' <span style="font-size: 12px; color: #7a8aa0;">(' . $diaStatus['tipo'] . ')</span>';
                                                echo '</div>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                            $dataVerificacao->modify('+1 day');
                                        }
                                    }
                                }
                                
                                $numeroAula = $aula['numero_aula_ordem'] ?? '-';
                                $dataFormatada = formatarData($aula['data_aula']);
                                $diaSemana = getDiaSemana($aula['data_aula']);
                                $horario = formatarHora($aula['horario_inicio']) . ' - ' . formatarHora($aula['horario_fim']);
                                $turno = $aula['turno'] ?? '';
                                $turnoLabel = getTurnoLabel($turno);
                                
                                $diaStatus = getDiaStatus($dataAtual, $diasRecesso);
                                
                                $podeEditarAula = $pode_editar && $curso['status_curso'] === 'ativo' && !$diaStatus;
                                
                                $ultimaData = $dataAtual;
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: #1a73e8; font-size: 14px;">
                                        <?php echo $numeroAula !== '-' ? $numeroAula . 'ª' : '-'; ?>
                                    </strong>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?php echo $dataFormatada; ?></div>
                                    <span style="font-size: 11px; color: #7a8aa0;"><?php echo $diaSemana; ?></span>
                                </td>
                                <td>
                                    <span style="font-weight: 500; font-size: 13px;">
                                        <i class="fas fa-clock" style="color: #1a73e8;"></i>
                                        <?php echo $horario; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($aula['numero_sala'])): ?>
                                        <span class="sala-tag">
                                            <i class="fas fa-door-open"></i>
                                            <?php echo htmlspecialchars($aula['numero_sala']); ?>
                                            <?php if (!empty($aula['tipo_sala'])): ?>
                                                <span style="font-size: 10px; color: #7a8aa0; display: block;">
                                                    <?php echo htmlspecialchars($aula['tipo_sala']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">Não definida</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($aula['nome_professor'])): ?>
                                        <div class="professor-tag">
                                            <span class="nome">
                                                <i class="fas fa-user" style="color: #1a73e8; font-size: 12px;"></i>
                                                <?php echo htmlspecialchars($aula['nome_professor']); ?>
                                            </span>
                                            <?php if (!empty($aula['email_professor'])): ?>
                                                <span class="email">
                                                    <i class="fas fa-envelope"></i>
                                                    <?php echo htmlspecialchars($aula['email_professor']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">
                                            <i class="fas fa-user-slash"></i> Não definido
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-turno <?php echo $turno; ?>">
                                        <?php echo $turnoLabel; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo getStatusBadge($aula['status_aula'] ?? 'agendada'); ?>
                                    <?php if (!empty($aula['observacao'])): ?>
                                        <div style="font-size: 11px; color: #7a8aa0; margin-top: 2px;">
                                            <i class="fas fa-comment"></i> 
                                            <?php echo htmlspecialchars(substr($aula['observacao'], 0, 30)); ?>
                                            <?php echo strlen($aula['observacao']) > 30 ? '...' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($diaStatus): ?>
                                        <span class="badge-recesso">
                                            <i class="fas <?php echo $diaStatus['icon']; ?>"></i>
                                            <?php echo $diaStatus['label']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #28a745; font-size: 12px;">
                                            <i class="fas fa-check-circle"></i> Dia normal
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($podeEditarAula): ?>
                                        <a href="editar_aula.php?id=<?php echo $aula['id_aula']; ?>" 
                                           class="btn btn-primary" 
                                           style="padding: 4px 12px; font-size: 12px; border-radius: 20px;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php elseif ($pode_editar && $diaStatus): ?>
                                        <span style="color: #999; font-size: 12px;" title="Não é possível editar aula em dia de recesso">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;" title="Edição bloqueada">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
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