<?php
// ============================================================
// ARQUIVO: SALAS/listar_salas.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Listar todas as salas com disponibilidade por turno (layout cards)
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
$pode_editar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);
$pode_cadastrar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

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
// SE FOR ADMINISTRADOR, VERIFICAR SE UNIDADE FOI SELECIONADA
// ============================================================
$unidadeSelecionada = null;
$mostrarSelecaoUnidade = false;

if ($tipo_usuario === 'admin_cliente') {
    if (isset($_GET['unidade']) && !empty($_GET['unidade'])) {
        $unidadeSelecionada = (int)$_GET['unidade'];
        $_SESSION['unidade_selecionada_admin_salas'] = $unidadeSelecionada;
    } elseif (isset($_SESSION['unidade_selecionada_admin_salas']) && !empty($_SESSION['unidade_selecionada_admin_salas'])) {
        $unidadeSelecionada = (int)$_SESSION['unidade_selecionada_admin_salas'];
    } else {
        $mostrarSelecaoUnidade = true;
    }
} else {
    $unidadeSelecionada = $id_unidade_usuario;
}

// ============================================================
// BUSCAR UNIDADES (FILTRADAS POR CLIENTE)
// ============================================================
$unidades = [];
try {
    $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? AND status_unidade = 'ativo' ORDER BY nome_unidade";
    $stmtUnidades = $conn->prepare($sqlUnidades);
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = 'Erro ao carregar unidades.';
}

// ============================================================
// SE ADMIN E NÃO SELECIONOU UNIDADE, MOSTRA TELA DE SELEÇÃO
// ============================================================
if ($mostrarSelecaoUnidade && $tipo_usuario === 'admin_cliente') {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selecionar Unidade - Salas</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .select-container {
            background: #ffffff;
            border-radius: 24px;
            padding: 48px 56px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            border: 1px solid #e8edf5;
            text-align: center;
        }
        .select-container .icon { font-size: 56px; color: #1a73e8; margin-bottom: 16px; }
        .select-container h1 { font-size: 24px; font-weight: 700; color: #0e1a2b; margin-bottom: 8px; }
        .select-container p { color: #7a8aa0; font-size: 14px; margin-bottom: 28px; }
        .select-container .form-group { text-align: left; margin-bottom: 20px; }
        .select-container .form-group label { display: block; font-weight: 600; font-size: 14px; color: #1a2639; margin-bottom: 6px; }
        .select-container .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e9f3;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            background: #fafcff;
            color: #1a2639;
            transition: border-color 0.2s;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237a8aa0' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
            cursor: pointer;
        }
        .select-container .form-group select:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }
        .btn {
            padding: 12px 32px;
            border-radius: 60px;
            border: none;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
            width: 100%;
            justify-content: center;
        }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        @media (max-width: 540px) {
            .select-container { padding: 32px 24px; margin: 20px; }
        }
    </style>
</head>
<body>
    <div class="select-container">
        <div class="icon"><i class="fas fa-building"></i></div>
        <h1>Selecionar Unidade</h1>
        <p>Escolha uma unidade para visualizar as salas</p>
        <form method="GET" action="">
            <div class="form-group">
                <label for="unidade">Unidade</label>
                <select name="unidade" id="unidade" required>
                    <option value="">Selecione uma unidade...</option>
                    <?php foreach ($unidades as $unidade): ?>
                        <option value="<?php echo $unidade['id_unidade']; ?>">
                            <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($filtro_numero)): ?>
                <input type="hidden" name="numero" value="<?php echo htmlspecialchars($filtro_numero); ?>">
            <?php endif; ?>
            <?php if (!empty($filtro_capacidade)): ?>
                <input type="hidden" name="capacidade" value="<?php echo htmlspecialchars($filtro_capacidade); ?>">
            <?php endif; ?>
            <?php if (!empty($filtro_tipo)): ?>
                <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($filtro_tipo); ?>">
            <?php endif; ?>
            <?php if (!empty($filtro_status)): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>">
            <?php endif; ?>
            <?php if (!empty($filtro_turno)): ?>
                <input type="hidden" name="turno" value="<?php echo htmlspecialchars($filtro_turno); ?>">
            <?php endif; ?>
            <?php if (!empty($filtro_data)): ?>
                <input type="hidden" name="data" value="<?php echo htmlspecialchars($filtro_data); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> Visualizar Salas
            </button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// ==========================================================
// RECEBER FILTROS
// ==========================================================
$filtro_numero = $_GET['numero'] ?? '';
$filtro_capacidade = $_GET['capacidade'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$filtro_turno = $_GET['turno'] ?? '';
$filtro_data = $_GET['data'] ?? date('Y-m-d');

// ==========================================================
// VALIDAR DATA
// ==========================================================
if (!empty($filtro_data)) {
    $dataObj = DateTime::createFromFormat('Y-m-d', $filtro_data);
    if (!$dataObj || $dataObj->format('Y-m-d') !== $filtro_data) {
        $filtro_data = date('Y-m-d');
    }
}

// ==========================================================
// MONTAR WHERE COM FILTROS (FILTRADOS POR CLIENTE)
// ==========================================================
$where = "WHERE id_cliente = :id_cliente";
$params = [':id_cliente' => $id_cliente];

// ============================================================
// FILTRO POR UNIDADE
// ============================================================
if ($tipo_usuario === 'admin_cliente' && !empty($unidadeSelecionada)) {
    $where .= " AND id_unidade = :unidade";
    $params[':unidade'] = $unidadeSelecionada;
} elseif ($tipo_usuario === 'gerente' && !empty($id_unidade_usuario)) {
    $where .= " AND id_unidade = :unidade";
    $params[':unidade'] = $id_unidade_usuario;
} elseif (!in_array($tipo_usuario, ['admin_cliente']) && !empty($id_unidade_usuario)) {
    $where .= " AND id_unidade = :unidade";
    $params[':unidade'] = $id_unidade_usuario;
}

if (!empty($filtro_numero)) {
    $where .= " AND numero_sala LIKE :numero";
    $params[':numero'] = '%' . $filtro_numero . '%';
}

if (!empty($filtro_capacidade)) {
    $where .= " AND capacidade_sala >= :capacidade";
    $params[':capacidade'] = (int)$filtro_capacidade;
}

if (!empty($filtro_tipo)) {
    $where .= " AND tipo_sala = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

if (!empty($filtro_status)) {
    $where .= " AND status_sala = :status";
    $params[':status'] = $filtro_status;
}

// ==========================================================
// CONSULTAR SALAS (FILTRADAS POR CLIENTE)
// ==========================================================
try {
    $sql = "SELECT * FROM salas $where ORDER BY numero_sala ASC";
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $salas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_registros = count($salas);
} catch (PDOException $e) {
    $salas = [];
    $total_registros = 0;
    setMessage('error', 'Erro ao carregar salas: ' . $e->getMessage());
}

// ==========================================================
// BUSCAR MANUTENÇÕES (FILTRADAS POR CLIENTE)
// ==========================================================
$manutencoesPorSala = [];
try {
    $sqlManut = "SELECT id_sala, data_inicio, data_fim, status, motivo 
                 FROM manutencoes 
                 WHERE id_cliente = :id_cliente
                 AND :data_hoje BETWEEN data_inicio AND data_fim
                 AND status != 'concluida'";
    
    $stmtManut = $conn->prepare($sqlManut);
    $stmtManut->execute([
        ':id_cliente' => $id_cliente,
        ':data_hoje' => $filtro_data
    ]);
    $manutencoesAtivas = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($manutencoesAtivas as $manut) {
        $manutencoesPorSala[$manut['id_sala']] = $manut;
    }
} catch (PDOException $e) {
    $manutencoesPorSala = [];
}

// ==========================================================
// BUSCAR AULAS DA DATA SELECIONADA (FILTRADAS POR CLIENTE)
// ==========================================================
$dataSelecionada = $filtro_data;
$ocupacao = [];

try {
    $sqlAulas = "SELECT c.id_sala, c.turno, c.id_aula, cu.nome_curso
                 FROM cronograma c
                 LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                 WHERE c.data_aula = :data_selecionada
                   AND c.id_cliente = :id_cliente
                   AND c.status_aula IN ('agendada', 'remarcada')";
    $stmtAulas = $conn->prepare($sqlAulas);
    $stmtAulas->execute([
        ':data_selecionada' => $dataSelecionada,
        ':id_cliente' => $id_cliente
    ]);
    $aulas = $stmtAulas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($aulas as $aula) {
        $idSala = $aula['id_sala'];
        $turno = $aula['turno'];
        $ocupacao[$idSala][$turno] = [
            'id_aula' => $aula['id_aula'],
            'curso' => $aula['nome_curso']
        ];
    }
} catch (PDOException $e) {
    $ocupacao = [];
}

// ==========================================================
// BUSCAR VALORES ÚNICOS PARA FILTROS (FILTRADOS POR CLIENTE)
// ==========================================================
try {
    $stmtTipos = $conn->prepare("SELECT DISTINCT tipo_sala FROM salas WHERE id_cliente = :id_cliente AND tipo_sala IS NOT NULL AND tipo_sala != '' ORDER BY tipo_sala ASC");
    $stmtTipos->execute([':id_cliente' => $id_cliente]);
    $tipos = $stmtTipos->fetchAll(PDO::FETCH_COLUMN);

    $stmtStatus = $conn->prepare("SELECT DISTINCT status_sala FROM salas WHERE id_cliente = :id_cliente AND status_sala IS NOT NULL AND status_sala != '' ORDER BY status_sala ASC");
    $stmtStatus->execute([':id_cliente' => $id_cliente]);
    $statusList = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $tipos = $statusList = [];
}

// Buscar nome da unidade selecionada para exibir
$nomeUnidade = '';
if ($tipo_usuario === 'admin_cliente' && !empty($unidadeSelecionada)) {
    try {
        $stmtUnidade = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
        $stmtUnidade->execute([
            ':id' => $unidadeSelecionada,
            ':id_cliente' => $id_cliente
        ]);
        $nomeUnidade = $stmtUnidade->fetchColumn();
    } catch (PDOException $e) {}
} elseif ($tipo_usuario === 'gerente' && !empty($id_unidade_usuario)) {
    try {
        $stmtUnidade = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
        $stmtUnidade->execute([
            ':id' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $nomeUnidade = $stmtUnidade->fetchColumn();
    } catch (PDOException $e) {}
}

$turnos = [
    'manha' => ['label' => 'Manhã', 'icon' => '☁️', 'horario' => '07:00 - 12:00'],
    'tarde' => ['label' => 'Tarde', 'icon' => '☀️', 'horario' => '13:00 - 18:00'],
    'noite' => ['label' => 'Noite', 'icon' => '🌙', 'horario' => '19:00 - 22:00']
];

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

// ============================================================
// FUNÇÃO PARA FORMATAR DATA
// ============================================================
function formatarDataParaExibicao($data) {
    if (empty($data)) return 'Não definida';
    $timestamp = strtotime($data);
    $diasSemanaPT = [
        'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira', 'Wednesday' => 'Quarta-feira',
        'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
    ];
    $diaSemana = $diasSemanaPT[date('l', $timestamp)] ?? '';
    return $diaSemana . ', ' . date('d/m/Y', $timestamp);
}

// ============================================================
// FUNÇÃO SIMPLIFICADA - ÍCONE UNIVERSAL
// ============================================================
function getIconeSala($tipo) {
    return 'fa-door-open';
}

// ============================================================
// FUNÇÃO SIMPLIFICADA - LABEL GENÉRICO
// ============================================================
function getLabelSala($tipo) {
    if (empty($tipo)) {
        return 'Sala';
    }
    return ucfirst(str_replace('_', ' ', $tipo));
}

$titulo = 'Listar Salas - Gerenciador de Salas';
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
        /* ============================================================
           TODOS OS ESTILOS PERMANECEM IGUAIS
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
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        .filter-bar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            border: 1px solid #e8edf5;
        }

        .filter-bar .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-bar .form-group label {
            font-size: 12px;
            font-weight: 500;
            color: #5a6a7e;
        }

        .filter-bar .form-group input,
        .filter-bar .form-group select {
            padding: 6px 12px;
            border: 1px solid #e2e9f3;
            border-radius: 6px;
            font-size: 13px;
            background: #fafcff;
            min-width: 140px;
        }

        .filter-bar .btn {
            height: 36px;
            align-self: flex-end;
        }

        .data-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: #e3f2fd;
            border-radius: 20px;
            color: #0d47a1;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid #b3d4f5;
        }
        .data-indicator i {
            color: #1a73e8;
        }
        .data-indicator .btn-data-nav {
            background: transparent;
            border: none;
            color: #0d47a1;
            cursor: pointer;
            padding: 2px 6px;
            font-size: 14px;
            transition: color 0.2s;
        }
        .data-indicator .btn-data-nav:hover {
            color: #1a73e8;
        }

        .cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .sala-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            overflow: hidden;
            transition: all 0.25s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .sala-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .sala-card-header {
            padding: 16px 20px;
            background: #f9fbfe;
            border-bottom: 1px solid #eef3fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sala-card-header .sala-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sala-card-header .sala-numero {
            font-size: 18px;
            font-weight: 700;
            color: #0e1a2b;
        }

        .sala-card-header .sala-tipo {
            font-size: 13px;
            color: #7a8aa0;
        }

        .sala-card-header .sala-tipo i {
            font-size: 18px;
            color: #5a7a9a;
            margin-right: 4px;
        }

        .sala-card-header .sala-status {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 60px;
        }

        .status-disponivel {
            background: #e6f7e9;
            color: #1e8546;
        }
        .status-ocupada {
            background: #fff2e0;
            color: #b86a1f;
        }
        .status-manutencao {
            background: #fff8e1;
            color: #e37400;
            border: 2px solid #ffc107;
        }
        .status-inativa {
            background: #f0f4fb;
            color: #7a8aa0;
        }

        .sala-card-body {
            padding: 12px 16px 16px;
        }

        .turno-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 6px;
            transition: background 0.2s;
        }

        .turno-item:last-child {
            margin-bottom: 0;
        }

        .turno-item:hover {
            background: #f8faff;
        }

        .turno-item .turno-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .turno-item .turno-icon {
            font-size: 20px;
        }

        .turno-item .turno-nome {
            font-weight: 600;
            font-size: 14px;
            color: #1a2639;
        }

        .turno-item .turno-horario {
            font-size: 12px;
            color: #7a8aa0;
        }

        .turno-item .turno-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .turno-item .turno-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 60px;
        }

        .badge-ocupado {
            background: #ffebee;
            color: #b71c1c;
        }
        .badge-disponivel {
            background: #e8f5e9;
            color: #1b5e20;
        }
        .badge-manutencao {
            background: #fff8e1;
            color: #e37400;
            border: 2px solid #ffc107;
        }
        .badge-bloqueado {
            background: #f5f5f5;
            color: #616161;
        }

        .turno-item .turno-curso {
            font-size: 13px;
            color: #1a73e8;
            font-weight: 500;
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sala-card-footer {
            padding: 12px 20px;
            border-top: 1px solid #f0f4fb;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #fafcff;
        }

        .sala-card-footer .btn-action {
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            padding: 4px 14px;
            border-radius: 60px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit {
            background: #e8f0fe;
            color: #1a73e8;
        }
        .btn-edit:hover {
            background: #d0dcfa;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7a8aa0;
            font-size: 15px;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 48px;
            color: #dce3ef;
            display: block;
            margin-bottom: 12px;
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
            .cards-container {
                grid-template-columns: 1fr 1fr;
            }
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-bar .form-group {
                width: 100%;
            }
            .filter-bar .form-group input,
            .filter-bar .form-group select {
                width: 100%;
            }
            .filter-bar .btn {
                width: 100%;
                justify-content: center;
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
            }
            .cards-container {
                grid-template-columns: 1fr;
            }
            .sala-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .turno-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .turno-item .turno-status {
                width: 100%;
                justify-content: flex-start;
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

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-door-open"></i> Salas</h1>
                <p class="page-subtitle">
                    Visualize a disponibilidade das salas por turno
                    <?php if (!empty($nomeUnidade)): ?>
                        • <strong><?php echo htmlspecialchars($nomeUnidade); ?></strong>
                    <?php endif; ?>
                    <span class="data-indicator" style="margin-left: 12px;">
                        <i class="fas fa-calendar-day"></i>
                        <?php echo formatarDataParaExibicao($filtro_data); ?>
                    </span>
                </p>
            </div>
            <div class="header-actions">
                <?php if ($pode_cadastrar): ?>
                    <a href="cadastrar_sala.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Nova Sala
                    </a>
                <?php endif; ?>
                <?php if ($tipo_usuario === 'admin_cliente'): ?>
                <a href="../trocar_unidade.php?redirect=listar_salas.php" class="btn btn-outline">
                    <i class="fas fa-exchange-alt"></i> Trocar Unidade
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

        <div class="filter-bar">
            <form method="GET" action="" style="display: contents;">
                <div class="form-group">
                    <label for="numero"><i class="fas fa-search"></i> Número</label>
                    <input type="text" name="numero" id="numero" placeholder="Número da sala..." value="<?php echo htmlspecialchars($filtro_numero); ?>">
                </div>

                <div class="form-group">
                    <label for="capacidade">Capacidade ≥</label>
                    <input type="number" name="capacidade" id="capacidade" placeholder="Mínimo" value="<?php echo htmlspecialchars($filtro_capacidade); ?>">
                </div>

                <div class="form-group">
                    <label for="tipo">Tipo</label>
                    <select name="tipo" id="tipo">
                        <option value="">Todos</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo ($filtro_tipo == $tipo) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $tipo)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">Todos</option>
                        <?php foreach ($statusList as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($filtro_status == $status) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="turno">Turno</label>
                    <select name="turno" id="turno">
                        <option value="">Todos</option>
                        <option value="manha" <?php echo ($filtro_turno == 'manha') ? 'selected' : ''; ?>>☁️ Manhã</option>
                        <option value="tarde" <?php echo ($filtro_turno == 'tarde') ? 'selected' : ''; ?>>☀️ Tarde</option>
                        <option value="noite" <?php echo ($filtro_turno == 'noite') ? 'selected' : ''; ?>>🌙 Noite</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="data"><i class="fas fa-calendar-alt"></i> Data</label>
                    <input type="date" name="data" id="data" value="<?php echo htmlspecialchars($filtro_data); ?>">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <?php if (!empty($_GET['numero']) || !empty($_GET['capacidade']) || !empty($_GET['tipo']) || !empty($_GET['status']) || !empty($_GET['turno']) || !empty($_GET['data']) && $_GET['data'] != date('Y-m-d')): ?>
                    <a href="listar_salas.php" class="btn btn-danger"><i class="fas fa-times"></i> Limpar</a>
                <?php endif; ?>
                
                <span style="font-size: 13px; color: #7a8aa0; margin-left: auto;">
                    Total: <strong><?php echo $total_registros; ?></strong> salas
                </span>
            </form>
        </div>

        <!-- ============================================================
        NAVEGAÇÃO RÁPIDA ENTRE DATAS
        ============================================================ -->
        <div style="display: flex; justify-content: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['data' => date('Y-m-d', strtotime($filtro_data . ' -1 day'))])); ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-chevron-left"></i> Dia Anterior
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['data' => date('Y-m-d')])); ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-calendar-day"></i> Hoje
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['data' => date('Y-m-d', strtotime($filtro_data . ' +1 day'))])); ?>" class="btn btn-outline btn-sm">
                Próximo Dia <i class="fas fa-chevron-right"></i>
            </a>
        </div>

        <div class="cards-container">
            <?php if (empty($salas)): ?>
                <div class="empty-state">
                    <i class="fas fa-door-open"></i>
                    Nenhuma sala encontrada com os filtros aplicados.
                </div>
            <?php else: ?>
                <?php foreach ($salas as $sala): 
                    $statusSala = $sala['status_sala'];
                    $idSala = $sala['id_sala'];
                    
                    $emManutencaoNaData = isset($manutencoesPorSala[$idSala]);
                    $motivoManut = '';
                    if ($emManutencaoNaData) {
                        $motivoManut = $manutencoesPorSala[$idSala]['motivo'] ?? '';
                    }
                    
                    if ($emManutencaoNaData) {
                        $statusClass = 'status-manutencao';
                        $statusLabel = '🔧 MANUTENÇÃO';
                    } elseif ($statusSala === 'manutencao' || $statusSala === 'manutenção') {
                        $statusClass = 'status-manutencao';
                        $statusLabel = '🔧 MANUTENÇÃO';
                    } elseif ($statusSala === 'disponivel' || $statusSala === 'disponível') {
                        $statusClass = 'status-disponivel';
                        $statusLabel = 'Disponível';
                    } elseif ($statusSala === 'ocupada') {
                        $statusClass = 'status-ocupada';
                        $statusLabel = 'Ocupada';
                    } elseif ($statusSala === 'inativa') {
                        $statusClass = 'status-inativa';
                        $statusLabel = 'Inativa';
                    } else {
                        $statusClass = 'status-inativa';
                        $statusLabel = ucfirst($statusSala);
                    }
                    
                    $icone = 'fa-door-open';
                    
                    if (empty($sala['tipo_sala'])) {
                        $tipoLabel = 'Sala';
                    } else {
                        $tipoLabel = ucfirst(str_replace('_', ' ', $sala['tipo_sala']));
                    }
                ?>
                <div class="sala-card">
                    <div class="sala-card-header">
                        <div class="sala-info">
                            <span class="sala-numero">Sala <?php echo htmlspecialchars($sala['numero_sala']); ?></span>
                            <span class="sala-tipo">
                                <i class="fas <?php echo $icone; ?>"></i>
                                <?php echo $tipoLabel; ?>
                            </span>
                            <span style="font-size: 12px; color: #7a8aa0;">
                                • <?php echo $sala['capacidade_sala']; ?> lugares
                            </span>
                            <?php if ($emManutencaoNaData && !empty($motivoManut)): ?>
                                <span style="font-size: 11px; color: #e65100; background: #fff3cd; padding: 2px 8px; border-radius: 4px; margin-left: 4px;">
                                    <i class="fas fa-tools"></i> <?php echo htmlspecialchars($motivoManut); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <span class="sala-status <?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                    </div>

                    <div class="sala-card-body">
                        <?php foreach ($turnos as $turnoKey => $turno): 
                            $ocupado = isset($ocupacao[$sala['id_sala']][$turnoKey]);
                            $curso = $ocupado ? $ocupacao[$sala['id_sala']][$turnoKey]['curso'] : '';
                            
                            $emManutencao = ($emManutencaoNaData || $statusSala === 'manutencao' || $statusSala === 'manutenção');
                            
                            if (!empty($filtro_turno) && $filtro_turno !== $turnoKey) {
                                continue;
                            }
                            
                            if ($emManutencao) {
                                $badgeClass = 'badge-manutencao';
                                $badgeLabel = '🔧 Manutenção';
                            } elseif ($ocupado) {
                                $badgeClass = 'badge-ocupado';
                                $badgeLabel = '🟢 Ocupada';
                            } else {
                                $badgeClass = 'badge-disponivel';
                                $badgeLabel = '✅ Disponível';
                            }
                        ?>
                        <div class="turno-item">
                            <div class="turno-info">
                                <span class="turno-icon"><?php echo $turno['icon']; ?></span>
                                <div>
                                    <div class="turno-nome"><?php echo $turno['label']; ?></div>
                                    <div class="turno-horario"><?php echo $turno['horario']; ?></div>
                                </div>
                            </div>
                            <div class="turno-status">
                                <?php if ($ocupado && !$emManutencao): ?>
                                    <span class="turno-curso"><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($curso); ?></span>
                                <?php endif; ?>
                                <?php if ($emManutencao): ?>
                                    <span class="turno-curso" style="color: #e65100;"><i class="fas fa-tools"></i> Em manutenção</span>
                                <?php endif; ?>
                                <span class="turno-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($pode_editar): ?>
                    <div class="sala-card-footer">
                        <a href="editar_sala.php?id=<?php echo $sala['id_sala']; ?>" class="btn-action btn-edit">
                            <i class="fas fa-edit"></i> Editar Sala
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>