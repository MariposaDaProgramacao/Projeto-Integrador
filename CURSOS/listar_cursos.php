<?php
// ============================================================
// ARQUIVO: CURSOS/listar_cursos.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Listar todos os cursos cadastrados com filtros e paginação
// ORDEM: Cursos Ativos primeiro, Concluídos por último
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO DE ACESSO (NOVO SISTEMA)
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
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// ============================================================
// PERMISSÕES DE AÇÃO (NOVO SISTEMA)
// ============================================================
$pode_editar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);
$pode_cadastrar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);
$pode_ver_progresso = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

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
// RECEBER FILTROS E PÁGINA
// ============================================================
$busca = $_GET['busca'] ?? '';
$turno = $_GET['turno'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$tipo_curso = $_GET['tipo_curso'] ?? '';
$status_curso = $_GET['status_curso'] ?? '';
$unidade_selecionada = '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 5;
$offset = ($pagina - 1) * $limite;

// ============================================================
// LÓGICA DE FILTRO POR UNIDADE (NOVO SISTEMA)
// ============================================================
$is_admin = ($tipo_usuario === 'admin_cliente');

if ($is_admin) {
    if (isset($_GET['unidade']) && $_GET['unidade'] != '') {
        $unidade_selecionada = (int)$_GET['unidade'];
    }
    try {
        $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade ASC";
        $stmt_unidades = $conn->prepare($sql_unidades);
        $stmt_unidades->execute([$id_cliente]);
        $unidades = $stmt_unidades->fetchAll();
    } catch (PDOException $e) {
        $unidades = [];
        setMessage('error', 'Erro ao carregar unidades: ' . $e->getMessage());
    }
} else {
    // Gerente ou usuário: apenas sua unidade
    $unidade_selecionada = $id_unidade_usuario;
    try {
        $sql_unidade = "SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente";
        $stmt_unidade = $conn->prepare($sql_unidade);
        $stmt_unidade->execute([
            ':id' => $unidade_selecionada,
            ':id_cliente' => $id_cliente
        ]);
        $unidade_nome = $stmt_unidade->fetchColumn();
    } catch (PDOException $e) {
        $unidade_nome = 'Unidade não definida';
    }
}

// ============================================================
// INICIALIZAR VARIÁVEIS
// ============================================================
$cursos = [];
$total_registros = 0;
$total_paginas = 0;

// ============================================================
// CONSULTAR CURSOS COM FILTROS (COM CONTAGEM E PAGINAÇÃO) - FILTRADOS POR CLIENTE
// ============================================================
try {
    $where = "WHERE c.id_cliente = :id_cliente";
    $params = [':id_cliente' => $id_cliente];
    
    if (!empty($unidade_selecionada)) {
        $where .= " AND c.id_unidade = :unidade";
        $params[':unidade'] = $unidade_selecionada;
    }
    
    if (!empty($busca)) {
        $where .= " AND (c.nome_curso LIKE :busca 
                 OR c.numero_curso LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }
    
    if (!empty($turno)) {
        $where .= " AND c.turno_curso = :turno";
        $params[':turno'] = $turno;
    }
    
    if (!empty($data_inicio)) {
        $where .= " AND c.data_inicio_curso >= :data_inicio";
        $params[':data_inicio'] = $data_inicio;
    }
    
    if (!empty($data_fim)) {
        $where .= " AND c.data_fim_curso_calculada <= :data_fim";
        $params[':data_fim'] = $data_fim;
    }
    
    if (!empty($tipo_curso)) {
        $where .= " AND c.tipo_curso = :tipo_curso";
        $params[':tipo_curso'] = $tipo_curso;
    }
    
    if (!empty($status_curso)) {
        $where .= " AND c.status_curso = :status_curso";
        $params[':status_curso'] = $status_curso;
    }
    
    // ==========================================================
    // CONSULTA COM SUBQUERIES PARA ÚLTIMA AULA E PROGRESSO
    // ==========================================================
    $sql_count = "SELECT COUNT(*) as total 
                  FROM cursos c
                  LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                  LEFT JOIN funcionarios f ON c.id_docente = f.id_funcionario AND f.id_cliente = c.id_cliente
                  $where";
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->execute($params);
    $total_registros = (int)$stmt_count->fetchColumn();
    $total_paginas = ceil($total_registros / $limite);
    
    // ==========================================================
    // CONSULTA PRINCIPAL COM ORDENAÇÃO POR STATUS
    // ==========================================================
    $sql = "SELECT 
                c.*, 
                u.nome_unidade, 
                f.nome_funcionario AS nome_professor,
                
                -- Total de dias letivos
                c.dias_letivos,
                
                -- ============================================================
                -- PROGRESSO EM TEMPO REAL
                -- ============================================================
                (SELECT COUNT(*) 
                 FROM cronograma cr 
                 WHERE cr.id_curso = c.id_curso 
                 AND cr.id_cliente = c.id_cliente) AS total_aulas_curso,
                
                (SELECT COUNT(*) 
                 FROM cronograma cr 
                 WHERE cr.id_curso = c.id_curso 
                 AND cr.id_cliente = c.id_cliente
                 AND cr.status_aula = 'realizada') AS aulas_realizadas_total,
                
                -- Última aula (número da aula)
                (SELECT cr.id_aula 
                 FROM cronograma cr 
                 WHERE cr.id_curso = c.id_curso 
                 AND cr.id_cliente = c.id_cliente
                 AND cr.status_aula = 'realizada' 
                 ORDER BY cr.data_aula DESC, cr.horario_inicio DESC 
                 LIMIT 1) AS ultima_aula_id,
                
                -- Data da última aula
                (SELECT cr.data_aula 
                 FROM cronograma cr 
                 WHERE cr.id_curso = c.id_curso 
                 AND cr.id_cliente = c.id_cliente
                 AND cr.status_aula = 'realizada' 
                 ORDER BY cr.data_aula DESC, cr.horario_inicio DESC 
                 LIMIT 1) AS ultima_aula_data
                
            FROM cursos c
            LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
            LEFT JOIN funcionarios f ON c.id_docente = f.id_funcionario AND f.id_cliente = c.id_cliente
            $where
            ORDER BY 
                -- ==========================================================
                -- ORDENAÇÃO POR STATUS: ATIVOS PRIMEIRO, CONCLUÍDOS POR ÚLTIMO
                -- ==========================================================
                CASE 
                    WHEN c.status_curso = 'ativo' THEN 1
                    WHEN c.status_curso = 'inativo' THEN 2
                    WHEN c.status_curso = 'concluido' THEN 3
                    ELSE 4
                END ASC,
                -- Depois: ordenar por nome
                c.nome_curso ASC
            LIMIT :limite OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $cursos = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $cursos = [];
    $total_registros = 0;
    $total_paginas = 0;
    setMessage('error', 'Erro ao carregar cursos: ' . $e->getMessage());
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

$titulo = 'Listar Cursos - Gerenciador de Salas';

// ============================================================
// FUNÇÃO PARA MANTER FILTROS NA PAGINAÇÃO
// ============================================================
function montar_url_com_filtros($pagina = null) {
    $params = $_GET;
    if ($pagina !== null) {
        $params['pagina'] = $pagina;
    }
    return '?' . http_build_query($params);
}
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
            flex-shrink: 0;
        }
        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #1a2639;
            line-height: 1.2;
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
            cursor: pointer;
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
            flex-shrink: 0;
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
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .btn-primary:hover {
            background: #1557b0;
            transform: translateY(-1px);
        }
        .btn-danger {
            background: #dc3545;
            color: #ffffff;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #d8e0ec;
        }
        .btn-outline:hover {
            background: #f0f4fb;
            border-color: #1a73e8;
        }
        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
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
            border: 1px solid transparent;
        }
        .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border-color: #ffd6d6;
        }
        .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border-color: #c8f0cf;
        }
        .alert i {
            font-size: 18px;
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
            display: flex;
            flex-direction: column;
            flex: 1;
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
            margin-right: 6px;
        }
        .table-header .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            width: 100%;
            align-items: flex-end;
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

        .table-cursos {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #ffffff;
            min-width: 1200px;
        }
        .table-cursos thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-cursos th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #e2e9f3;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #5a6a7e;
            font-weight: 600;
            background: #f9fbfe;
            white-space: nowrap;
        }
        .table-cursos td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f4fc;
            color: #1a2639;
            font-size: 13px;
        }
        .table-cursos tbody tr:hover {
            background: #fafcff;
        }
        .table-cursos tbody tr:last-child td {
            border-bottom: none;
        }

        /* ======================================================
           BADGES E STATUS
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
           AÇÕES
        ====================================================== */
        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .actions .btn-action {
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 60px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .actions .btn-detalhes {
            background: #e8f0fe;
            color: #1a73e8;
        }
        .actions .btn-detalhes:hover {
            background: #d0dcfa;
            color: #0d47a1;
        }
        .actions .btn-edit {
            background: #fff3e0;
            color: #e37400;
        }
        .actions .btn-edit:hover {
            background: #ffe0b2;
            color: #bf360c;
        }
        .actions .btn-progress {
            background: #e6f7e9;
            color: #1e8546;
        }
        .actions .btn-progress:hover {
            background: #c8f0cf;
            color: #0d6329;
        }
        .actions .btn-view {
            background: #f0f4fb;
            color: #5a6a7e;
        }
        .actions .btn-view:hover {
            background: #e2e9f3;
            color: #1a2639;
        }

        /* ======================================================
           ÚLTIMA AULA
        ====================================================== */
        .ultima-aula-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
        }
        .ultima-aula-info .aula-numero {
            font-weight: 600;
            color: #1a73e8;
        }
        .ultima-aula-info .aula-data {
            font-size: 11px;
            color: #7a8aa0;
        }
        .ultima-aula-info .sem-aulas {
            color: #f9ab00;
            font-weight: 600;
        }
        .ultima-aula-info .aguardando {
            color: #999;
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
           PAGINAÇÃO
        ====================================================== */
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 16px 22px;
            border-top: 1px solid #f0f4fb;
            flex-wrap: wrap;
            background: #ffffff;
            border-radius: 0 0 16px 16px;
            flex-shrink: 0;
        }
        .pagination-wrapper .btn {
            padding: 8px 16px;
            font-size: 13px;
            min-width: 40px;
            justify-content: center;
        }
        .pagination-wrapper .btn-primary {
            cursor: default;
            box-shadow: none;
        }
        .pagination-wrapper .btn-primary:hover {
            transform: none;
        }
        .pagination-wrapper .btn-outline {
            border: 1px solid #d8e0ec;
        }
        .pagination-wrapper .btn-outline:hover {
            background: #f0f4fb;
            border-color: #1a73e8;
        }
        .pagination-wrapper .btn-outline[style*="pointer-events: none"] {
            opacity: 0.5;
        }

        /* ======================================================
           FOOTER
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

        /* ======================================================
           RESPONSIVE
        ====================================================== */
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
            .table-cursos {
                font-size: 13px;
                min-width: 1000px;
            }
            .table-cursos th,
            .table-cursos td {
                padding: 8px 10px;
            }
            .actions {
                flex-direction: column;
                gap: 4px;
            }
            .table-header .filters {
                flex-direction: column;
                align-items: stretch;
            }
            .table-header .filters input,
            .table-header .filters select {
                width: 100%;
                border-radius: 6px;
            }
            .menu-toggle {
                display: block;
            }
            .pagination-wrapper .btn {
                padding: 6px 12px;
                font-size: 12px;
                min-width: 34px;
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
            .table-scroll {
                max-height: 300px;
            }
            .pagination-wrapper .btn {
                padding: 4px 10px;
                font-size: 11px;
                min-width: 30px;
            }
            .table-cursos {
                min-width: 850px;
                font-size: 12px;
            }
            .table-cursos th,
            .table-cursos td {
                padding: 6px 8px;
            }
            .ultima-aula-info .aula-data {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-graduation-cap"></i> Cursos</h1>
                <p class="page-subtitle">Visualize os cursos cadastrados no sistema</p>
            </div>
            <div class="header-actions">
                <?php if ($pode_cadastrar): ?>
                    <a href="cadastrar_curso.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Novo Curso
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

        <!-- ================================================== -->
        <!-- TABELA                                           -->
        <!-- ================================================== -->
        <div class="table-wrapper">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Lista de Cursos</h3>
                <div class="filters">
                    <form method="GET" action="" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; width: 100%;">
                        
                        <?php if ($is_admin): ?>
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Unidade</label>
                                <select name="unidade" style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                                    <option value="">Todas</option>
                                    <?php foreach ($unidades as $unidade): ?>
                                        <option value="<?php echo $unidade['id_unidade']; ?>" 
                                            <?php echo ($_GET['unidade'] ?? '') == $unidade['id_unidade'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Unidade</label>
                                <span style="padding: 6px 12px; background: #f0f4fb; border-radius: 6px; font-size: 13px; color: #1a2639;">
                                    <?php echo htmlspecialchars($unidade_nome ?? 'Não definida'); ?>
                                </span>
                                <input type="hidden" name="unidade" value="<?php echo $unidade_selecionada; ?>">
                            </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Buscar</label>
                            <input type="text" name="busca" placeholder="Nome ou número..." 
                                   value="<?php echo htmlspecialchars($_GET['busca'] ?? ''); ?>"
                                   style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px; min-width: 160px;">
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Turno</label>
                            <select name="turno" style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                                <option value="">Todos</option>
                                <option value="manha" <?php echo ($_GET['turno'] ?? '') == 'manha' ? 'selected' : ''; ?>>Manhã</option>
                                <option value="tarde" <?php echo ($_GET['turno'] ?? '') == 'tarde' ? 'selected' : ''; ?>>Tarde</option>
                                <option value="noite" <?php echo ($_GET['turno'] ?? '') == 'noite' ? 'selected' : ''; ?>>Noite</option>
                                <option value="integral" <?php echo ($_GET['turno'] ?? '') == 'integral' ? 'selected' : ''; ?>>Integral</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Tipo</label>
                            <select name="tipo_curso" style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                                <option value="">Todos</option>
                                <option value="curso_tecnico" <?php echo ($_GET['tipo_curso'] ?? '') == 'curso_tecnico' ? 'selected' : ''; ?>>Técnico</option>
                                <option value="curso_agil" <?php echo ($_GET['tipo_curso'] ?? '') == 'curso_agil' ? 'selected' : ''; ?>>Ágil</option>
                                <option value="pos_graduacao" <?php echo ($_GET['tipo_curso'] ?? '') == 'pos_graduacao' ? 'selected' : ''; ?>>Pós-graduação</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Status</label>
                            <select name="status_curso" style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                                <option value="">Todos</option>
                                <option value="ativo" <?php echo ($_GET['status_curso'] ?? '') == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inativo" <?php echo ($_GET['status_curso'] ?? '') == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                                <option value="concluido" <?php echo ($_GET['status_curso'] ?? '') == 'concluido' ? 'selected' : ''; ?>>Concluído</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Início (a partir)</label>
                            <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($_GET['data_inicio'] ?? ''); ?>"
                                   style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <label style="font-size: 11px; color: #5a6a7e; font-weight: 600;">Final (até)</label>
                            <input type="date" name="data_fim" value="<?php echo htmlspecialchars($_GET['data_fim'] ?? ''); ?>"
                                   style="padding: 6px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 13px;">
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-sm" style="height: 36px; align-self: flex-end;">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        
                        <?php if (!empty($_GET['busca']) || !empty($_GET['turno']) || !empty($_GET['data_inicio']) || !empty($_GET['data_fim']) || !empty($_GET['unidade']) || !empty($_GET['tipo_curso']) || !empty($_GET['status_curso'])): ?>
                            <a href="listar_cursos.php" class="btn btn-danger btn-sm" style="height: 36px; align-self: flex-end;">
                                <i class="fas fa-times"></i> Limpar
                            </a>
                        <?php endif; ?>
                        
                        <span style="font-size: 13px; color: #7a8aa0; margin-left: auto;">
                            Total: <strong><?php echo $total_registros; ?></strong>
                        </span>
                    </form>
                </div>
            </div>

            <!-- TABELA COM SCROLL -->
            <div class="table-scroll">
                <table class="table-cursos">
                    <thead>
                        <tr>
                            <th style="min-width: 50px; text-align: center;">Ações</th>
                            <th style="min-width: 80px;">Turma</th>
                            <th style="min-width: 180px;">Nome Curso</th>
                            <th style="min-width: 120px;">Professor</th>
                            <th style="min-width: 80px;">Carga Horária</th>
                            <th style="min-width: 80px;">Total Dias</th>
                            <th style="min-width: 120px;">Última Aula</th>
                            <th style="min-width: 100px;">Data Início</th>
                            <th style="min-width: 100px;">Data Fim</th>
                            <th style="min-width: 120px;">Progresso</th>
                            <th style="min-width: 80px;">Tipo</th>
                            <th style="min-width: 100px;">Status</th>
                            <?php if ($pode_editar): ?>
                                <th style="min-width: 80px;">Editar</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cursos)): ?>
                            <tr>
                                <td colspan="<?php echo ($pode_editar) ? 13 : 12; ?>" class="empty-state">
                                    <i class="fas fa-graduation-cap" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                    Nenhum curso encontrado com os filtros selecionados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cursos as $curso): 
                                // ============================================================
                                // CALCULAR PROGRESSO EM TEMPO REAL
                                // ============================================================
                                $totalAulasCurso = (int)($curso['total_aulas_curso'] ?? 0);
                                $aulasRealizadas = (int)($curso['aulas_realizadas_total'] ?? 0);
                                
                                if ($totalAulasCurso > 0) {
                                    $percentual = round(($aulasRealizadas / $totalAulasCurso) * 100, 2);
                                } else {
                                    $percentual = 0;
                                }
                                
                                $cor = $percentual >= 80 ? '#34a853' : ($percentual >= 40 ? '#f9ab00' : '#dc3545');
                            ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="../CRONOGRAMA_AULAS/detalhes_curso.php?id=<?php echo $curso['id_curso']; ?>" 
                                           class="btn-action btn-detalhes" 
                                           title="Ver detalhes do curso">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($curso['numero_curso']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($curso['nome_curso']); ?></td>
                                    <td>
                                        <?php 
                                            echo !empty($curso['nome_professor']) 
                                                ? htmlspecialchars($curso['nome_professor']) 
                                                : '<span style="color: #999;">Não definido</span>';
                                        ?>
                                    </td>
                                    <td><?php echo $curso['carga_horaria_curso']; ?>h</td>
                                    <td>
                                        <?php 
                                            $dias_letivos = $curso['dias_letivos'] ?? '-';
                                            if ($dias_letivos !== '-' && $dias_letivos > 0) {
                                                echo '<span style="font-weight: 600; color: #1a73e8;">' . $dias_letivos . ' dias</span>';
                                            } else {
                                                echo '<span style="color: #999;">Não calculado</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $ultima_aula_id = $curso['ultima_aula_id'] ?? null;
                                            $ultima_aula_data = $curso['ultima_aula_data'] ?? null;
                                            $data_inicio_curso = $curso['data_inicio_curso'] ?? null;
                                            
                                            $curso_comecou = !empty($data_inicio_curso) && strtotime($data_inicio_curso) <= time();
                                            
                                            if ($curso_comecou && $ultima_aula_id) {
                                                $data_formatada = date('d/m/Y', strtotime($ultima_aula_data));
                                                echo '<div class="ultima-aula-info">';
                                                echo '<span class="aula-numero">Aula #' . $ultima_aula_id . '</span>';
                                                echo '<span class="aula-data">' . $data_formatada . '</span>';
                                                echo '</div>';
                                            } elseif ($curso_comecou && !$ultima_aula_id) {
                                                echo '<div class="ultima-aula-info">';
                                                echo '<span class="sem-aulas">0</span>';
                                                echo '<span class="aula-data">Nenhuma aula realizada</span>';
                                                echo '</div>';
                                            } else {
                                                echo '<div class="ultima-aula-info">';
                                                echo '<span class="aguardando">0</span>';
                                                echo '<span class="aula-data">Aguardando início</span>';
                                                echo '</div>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $data_inicio_curso = $curso['data_inicio_curso'] ?? '';
                                            if (!empty($data_inicio_curso)) {
                                                echo date('d/m/Y', strtotime($data_inicio_curso));
                                            } else {
                                                echo '<span style="color: #999;">-</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $data_fim_curso = $curso['data_fim_curso_calculada'] ?? '';
                                            if (!empty($data_fim_curso)) {
                                                echo date('d/m/Y', strtotime($data_fim_curso));
                                            } else {
                                                echo '<span style="color: #999;">-</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 600; font-size: 14px; min-width: 45px;">
                                                <?php echo number_format($percentual, 1); ?>%
                                            </span>
                                            <div style="width: 60px; height: 6px; background: #e2e9f3; border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?php echo $percentual; ?>%; height: 100%; background: <?php echo $cor; ?>; border-radius: 4px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $tipo_label = '';
                                        $tipo_bg = '';
                                        $tipo_color = '';
                                        if ($curso['tipo_curso'] === 'curso_tecnico') {
                                            $tipo_label = 'Técnico';
                                            $tipo_bg = '#e3f2fd';
                                            $tipo_color = '#0d47a1';
                                        } elseif ($curso['tipo_curso'] === 'curso_agil') {
                                            $tipo_label = 'Ágil';
                                            $tipo_bg = '#fff3e0';
                                            $tipo_color = '#e37400';
                                        } elseif ($curso['tipo_curso'] === 'pos_graduacao') {
                                            $tipo_label = 'Pós-graduação';
                                            $tipo_bg = '#f3e5f5';
                                            $tipo_color = '#6a1b9a';
                                        } else {
                                            $tipo_label = 'Não definido';
                                            $tipo_bg = '#f0f4fb';
                                            $tipo_color = '#7a8aa0';
                                        }
                                        ?>
                                        <span style="
                                            display: inline-block;
                                            padding: 4px 12px;
                                            border-radius: 60px;
                                            font-size: 12px;
                                            font-weight: 600;
                                            background: <?php echo $tipo_bg; ?>;
                                            color: <?php echo $tipo_color; ?>;
                                            white-space: nowrap;
                                        ">
                                            <?php echo $tipo_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status = $curso['status_curso'] ?? 'ativo';
                                        
                                        $status_texto = 'Desconhecido';
                                        $status_bg = '#f0f4fb';
                                        $status_color = '#7a8aa0';
                                        $text_color = '#7a8aa0';
                                        
                                        if ($status === 'ativo') {
                                            $status_texto = 'Ativo';
                                            $status_bg = '#e6f7e9';
                                            $status_color = '#34a853';
                                            $text_color = '#1e8546';
                                        } elseif ($status === 'inativo') {
                                            $status_texto = 'Inativo';
                                            $status_bg = '#ffe9e9';
                                            $status_color = '#b33a3a';
                                            $text_color = '#b33a3a';
                                        } elseif ($status === 'concluido') {
                                            $status_texto = 'Concluído';
                                            $status_bg = '#e8f0fe';
                                            $status_color = '#1a73e8';
                                            $text_color = '#1a73e8';
                                        }
                                        ?>
                                        <span style="
                                            display: inline-block;
                                            padding: 4px 12px;
                                            border-radius: 60px;
                                            font-size: 12px;
                                            font-weight: 600;
                                            background: <?php echo $status_bg; ?>;
                                            color: <?php echo $text_color; ?>;
                                            white-space: nowrap;
                                        ">
                                            <i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: <?php echo $status_color; ?>;"></i>
                                            <?php echo $status_texto; ?>
                                        </span>
                                    </td>
                                    <?php if ($pode_editar): ?>
                                        <td>
                                            <div class="actions">
                                                <a href="editar_cursos.php?id=<?php echo $curso['id_curso']; ?>" class="btn-action btn-edit">
                                                    <i class="fas fa-edit"></i> Editar
                                                </a>
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
            <?php if ($total_paginas > 1): ?>
            <div class="pagination-wrapper">
                <!-- Anterior -->
                <?php if ($pagina > 1): ?>
                    <a href="<?php echo montar_url_com_filtros($pagina - 1); ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </a>
                <?php else: ?>
                    <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </span>
                <?php endif; ?>

                <!-- Primeira página -->
                <?php if ($pagina > 3): ?>
                    <a href="<?php echo montar_url_com_filtros(1); ?>" class="btn btn-outline btn-sm">1</a>
                    <?php if ($pagina > 4): ?>
                        <span style="color: #999; padding: 0 4px;">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Páginas ao redor da atual -->
                <?php 
                $start = max(1, $pagina - 2);
                $end = min($total_paginas, $pagina + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $pagina): ?>
                        <span class="btn btn-primary btn-sm" style="cursor: default;"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo montar_url_com_filtros($i); ?>" class="btn btn-outline btn-sm"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <!-- Última página -->
                <?php if ($pagina < $total_paginas - 2): ?>
                    <?php if ($pagina < $total_paginas - 3): ?>
                        <span style="color: #999; padding: 0 4px;">…</span>
                    <?php endif; ?>
                    <a href="<?php echo montar_url_com_filtros($total_paginas); ?>" class="btn btn-outline btn-sm"><?php echo $total_paginas; ?></a>
                <?php endif; ?>

                <!-- Próximo -->
                <?php if ($pagina < $total_paginas): ?>
                    <a href="<?php echo montar_url_com_filtros($pagina + 1); ?>" class="btn btn-outline btn-sm">
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