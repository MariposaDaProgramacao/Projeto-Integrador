<?php
// ============================================================
// ARQUIVO: MANUTENCOES/registrar_manutencao.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Registrar uma nova manutenção para uma sala
// ============================================================

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
// PERMISSÕES - APENAS ADMINISTRADOR E GERENTE
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem registrar manutenções.');
    redirect('listar_manutencoes.php');
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
// BUSCAR SALAS PARA O SELECT (FILTRADAS POR CLIENTE E UNIDADE)
// ============================================================
try {
    $sql = "SELECT id_sala, numero_sala, tipo_sala, id_unidade 
            FROM salas 
            WHERE id_cliente = :id_cliente 
            AND status_sala != 'inativa'";
    
    $params = [':id_cliente' => $id_cliente];
    
    // Se for gerente, filtrar pela sua unidade
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND id_unidade = :id_unidade";
        $params[':id_unidade'] = $id_unidade_usuario;
    }
    
    $sql .= " ORDER BY numero_sala ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $salas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $salas = [];
    $mensagem_erro = "Erro ao carregar salas: " . $e->getMessage();
}

// ============================================================
// PROCESSAR FORMULÁRIO
// ============================================================
$mensagem = "";
$mensagem_tipo = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_sala = $_POST['id_sala'] ?? 0;
    $responsavel = trim($_POST['responsavel'] ?? '');
    $tipo = $_POST['tipo'] ?? '';
    $data_inicio = $_POST['data_inicio'] ?? '';
    $data_fim = $_POST['data_fim'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $motivo = trim($_POST['motivo'] ?? '');
    $status = $_POST['status'] ?? 'agendada';
    
    // Validações
    $erros = [];
    if (empty($id_sala)) $erros[] = 'Selecione uma sala.';
    if (empty($responsavel)) $erros[] = 'Informe o responsável pela manutenção.';
    if (empty($tipo)) $erros[] = 'Selecione o tipo de manutenção.';
    if (empty($data_inicio)) $erros[] = 'Informe a data de início.';
    if (empty($data_fim)) $erros[] = 'Informe a data de fim.';
    if (empty($turno)) $erros[] = 'Selecione o turno.';
    if (empty($motivo)) $erros[] = 'Descreva o motivo da manutenção.';
    
    if (strtotime($data_fim) < strtotime($data_inicio)) {
        $erros[] = 'A data de fim não pode ser anterior à data de início.';
    }
    
    if (empty($erros)) {
        try {
            // Verificar se a sala pertence ao cliente
            $stmtCheck = $conn->prepare("SELECT id_sala, id_unidade FROM salas WHERE id_sala = :id_sala AND id_cliente = :id_cliente");
            $stmtCheck->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $salaCheck = $stmtCheck->fetch();
            
            if (!$salaCheck) {
                throw new Exception('Sala não encontrada ou não pertence à sua organização.');
            }
            
            // Verificar se já existe manutenção para esta sala no período
            $stmtCheckManut = $conn->prepare("
                SELECT COUNT(*) FROM manutencoes 
                WHERE id_sala = :id_sala 
                AND id_cliente = :id_cliente
                AND status != 'concluida'
                AND ((data_inicio <= :data_fim AND data_fim >= :data_inicio))
            ");
            $stmtCheckManut->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim
            ]);
            $conflito = $stmtCheckManut->fetchColumn();
            
            if ($conflito > 0) {
                throw new Exception('Já existe uma manutenção agendada para esta sala neste período.');
            }
            
            // Inserir manutenção
            $sqlInsert = "INSERT INTO manutencoes (
                id_sala,
                id_cliente,
                data_inicio,
                data_fim,
                turno,
                motivo,
                status
            ) VALUES (
                :id_sala,
                :id_cliente,
                :data_inicio,
                :data_fim,
                :turno,
                :motivo,
                :status
            )";
            
            $stmtInsert = $conn->prepare($sqlInsert);
            $stmtInsert->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim,
                ':turno' => $turno,
                ':motivo' => $motivo,
                ':status' => $status
            ]);
            
            // Atualizar status da sala para 'manutencao' se a manutenção começar hoje ou antes
            if (strtotime($data_inicio) <= strtotime(date('Y-m-d'))) {
                $sqlUpdateSala = "UPDATE salas SET status_sala = 'manutencao' WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
                $stmtUpdateSala = $conn->prepare($sqlUpdateSala);
                $stmtUpdateSala->execute([
                    ':id_sala' => $id_sala,
                    ':id_cliente' => $id_cliente
                ]);
            }
            
            $mensagem = "Manutenção registrada com sucesso!";
            $mensagem_tipo = "success";
            
            // Registrar no histórico
            try {
                $sqlHistorico = "INSERT INTO historico_sistema (
                    id_funcionario,
                    tabela_afetada,
                    id_registro_afetado,
                    acao,
                    dados_novos,
                    ip_origem
                ) VALUES (
                    :id_funcionario,
                    'manutencoes',
                    :id_registro,
                    'INSERT',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => getUsuarioId(),
                    ':id_registro' => $conn->lastInsertId(),
                    ':dados' => json_encode([
                        'sala' => $id_sala,
                        'responsavel' => $responsavel,
                        'tipo' => $tipo,
                        'data_inicio' => $data_inicio,
                        'data_fim' => $data_fim,
                        'turno' => $turno,
                        'motivo' => $motivo
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                // Não interrompe o processo
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }
            
        } catch (Exception $e) {
            $mensagem = "Erro ao registrar manutenção: " . $e->getMessage();
            $mensagem_tipo = "danger";
        } catch (PDOException $e) {
            $mensagem = "Erro ao registrar manutenção: " . $e->getMessage();
            $mensagem_tipo = "danger";
        }
    } else {
        $mensagem = implode('<br>', $erros);
        $mensagem_tipo = "warning";
    }
}

// ============================================================
// FUNÇÃO PARA FORMATAR TIPO DE MANUTENÇÃO
// ============================================================
function getTipoLabel($tipo) {
    $tipos = [
        'preventiva' => 'Preventiva',
        'corretiva' => 'Corretiva',
        'limpeza' => 'Limpeza',
        'eletrica' => 'Elétrica',
        'informatica' => 'Informática',
        'hidraulica' => 'Hidráulica',
        'outro' => 'Outro'
    ];
    return $tipos[$tipo] ?? $tipo;
}

$titulo = 'Registrar Manutenção - Gerenciamento de Ambientes';
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
        
        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 28px 32px;
            margin-bottom: 20px;
            max-width: 900px;
            width: 100%;
            align-self: center;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
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
            font-family: 'Inter', sans-serif;
        }
        
        .btn-secondary {
            background: #e2e9f3;
            color: #1a2639;
            border: 1px solid #d8e0ec;
        }
        .btn-secondary:hover {
            background: #d0dbe8;
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
        
        .btn-warning {
            background: #ffc107;
            color: #1a2639;
            border: none;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2d3a4f;
            margin-bottom: 4px;
        }
        .form-group label i {
            color: #1a73e8;
            margin-right: 6px;
        }
        .form-group label .required {
            color: #dc3545;
            margin-left: 2px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            background: #fafcff;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            color: #1a2639;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
            outline: none;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-group small {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #7a8aa0;
        }
        .form-group small i {
            margin-right: 4px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 900px;
            width: 100%;
            align-self: center;
            border: 1px solid transparent;
        }
        .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border-color: #c8f0cf;
        }
        .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border-color: #ffd6d6;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffc107;
        }
        .alert i {
            font-size: 18px;
        }
        
        .info-box {
            background: #f0f7ff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid #1a73e8;
        }
        .info-box p {
            font-size: 13px;
            color: #5a6a7e;
            margin: 4px 0;
        }
        .info-box strong {
            color: #0e1a2b;
        }
        
        .info-box.warning {
            border-left-color: #ffc107;
            background: #fff8e1;
        }
        
        /* SIDEBAR */
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
        .user-info .cliente { font-size: 11px; color: #1a73e8; font-weight: 500; display: block; margin-top: 2px; }
        .logout-btn-sidebar { display: flex; align-items: center; justify-content: center; gap: 8px; background: #dc3545; color: #ffffff; border: none; border-radius: 60px; padding: 10px 16px; font-weight: 600; font-size: 13px; text-decoration: none; transition: all 0.2s ease; width: 100%; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25); cursor: pointer; }
        .logout-btn-sidebar:hover { background: #c82333; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35); }
        .footer-system { text-align: center; font-size: 12px; color: #8a9bb5; padding: 16px 0 8px; border-top: 1px solid #e2e9f3; margin-top: auto; background: transparent; flex-shrink: 0; }
        
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
        .menu-toggle:hover { background: #1557b0; }
        .menu-toggle i { font-size: 24px; }
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
        .sidebar-overlay.active { display: block; opacity: 1; }
        body.menu-open { overflow: hidden; }
        
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
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .card-panel { padding: 20px; }
            .menu-toggle { display: block; }
        }
        @media (max-width: 540px) {
            .main { padding: 12px 14px; }
            .card-panel { padding: 16px; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-tools"></i> Registrar Manutenção</h1>
                <p class="page-subtitle">Registre uma nova manutenção para uma sala</p>
            </div>
            <a href="listar_manutencoes.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </header>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $mensagem_tipo; ?>">
                <i class="fas fa-<?php echo $mensagem_tipo === 'success' ? 'check-circle' : ($mensagem_tipo === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <div class="card-panel">
            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> <strong>Informações importantes:</strong></p>
                <p>• Ao registrar uma manutenção, a sala será marcada como <strong>"em manutenção"</strong> automaticamente.</p>
                <p>• Se houver conflito com outra manutenção no mesmo período, o sistema alertará.</p>
                <p>• A manutenção pode ser agendada para uma data futura.</p>
            </div>

            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="id_sala"><i class="fas fa-door-open"></i> Sala <span class="required">*</span></label>
                        <select name="id_sala" id="id_sala" required>
                            <option value="">Selecione uma sala</option>
                            <?php foreach ($salas as $sala): ?>
                                <option value="<?php echo $sala['id_sala']; ?>">
                                    Sala <?php echo htmlspecialchars($sala['numero_sala']); ?>
                                    <?php if (!empty($sala['tipo_sala'])): ?>
                                        - <?php echo htmlspecialchars($sala['tipo_sala']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($salas)): ?>
                            <small style="color: #dc3545;">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Nenhuma sala disponível para manutenção. <a href="../SALAS/cadastrar_sala.php">Cadastre uma sala</a>.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="responsavel"><i class="fas fa-user"></i> Responsável <span class="required">*</span></label>
                        <input type="text" name="responsavel" id="responsavel" 
                               placeholder="Nome do responsável pela manutenção" 
                               value="<?php echo htmlspecialchars($_POST['responsavel'] ?? ''); ?>" 
                               required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="tipo"><i class="fas fa-tag"></i> Tipo de Manutenção <span class="required">*</span></label>
                        <select name="tipo" id="tipo" required>
                            <option value="">Selecione o tipo</option>
                            <option value="preventiva" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'preventiva') ? 'selected' : ''; ?>>Preventiva</option>
                            <option value="corretiva" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'corretiva') ? 'selected' : ''; ?>>Corretiva</option>
                            <option value="limpeza" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'limpeza') ? 'selected' : ''; ?>>Limpeza</option>
                            <option value="eletrica" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'eletrica') ? 'selected' : ''; ?>>Elétrica</option>
                            <option value="informatica" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'informatica') ? 'selected' : ''; ?>>Informática</option>
                            <option value="hidraulica" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'hidraulica') ? 'selected' : ''; ?>>Hidráulica</option>
                            <option value="outro" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'outro') ? 'selected' : ''; ?>>Outro</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="turno"><i class="fas fa-clock"></i> Turno <span class="required">*</span></label>
                        <select name="turno" id="turno" required>
                            <option value="">Selecione o turno</option>
                            <option value="manha" <?php echo (isset($_POST['turno']) && $_POST['turno'] === 'manha') ? 'selected' : ''; ?>>☀️ Manhã</option>
                            <option value="tarde" <?php echo (isset($_POST['turno']) && $_POST['turno'] === 'tarde') ? 'selected' : ''; ?>>☀️ Tarde</option>
                            <option value="noite" <?php echo (isset($_POST['turno']) && $_POST['turno'] === 'noite') ? 'selected' : ''; ?>>🌙 Noite</option>
                            <option value="integral" <?php echo (isset($_POST['turno']) && $_POST['turno'] === 'integral') ? 'selected' : ''; ?>>🔄 Integral</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="data_inicio"><i class="fas fa-calendar-plus"></i> Data de Início <span class="required">*</span></label>
                        <input type="date" name="data_inicio" id="data_inicio" 
                               value="<?php echo htmlspecialchars($_POST['data_inicio'] ?? date('Y-m-d')); ?>" 
                               required>
                    </div>

                    <div class="form-group">
                        <label for="data_fim"><i class="fas fa-calendar-check"></i> Data de Fim <span class="required">*</span></label>
                        <input type="date" name="data_fim" id="data_fim" 
                               value="<?php echo htmlspecialchars($_POST['data_fim'] ?? date('Y-m-d', strtotime('+1 day'))); ?>" 
                               required>
                        <small><i class="fas fa-info-circle"></i> A data de fim deve ser igual ou posterior à data de início.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="motivo"><i class="fas fa-comment"></i> Motivo da Manutenção <span class="required">*</span></label>
                    <textarea name="motivo" id="motivo" rows="4" 
                              placeholder="Descreva detalhadamente o motivo da manutenção..." 
                              required><?php echo htmlspecialchars($_POST['motivo'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="status"><i class="fas fa-circle"></i> Status Inicial</label>
                    <select name="status" id="status">
                        <option value="agendada" <?php echo (isset($_POST['status']) && $_POST['status'] === 'agendada') ? 'selected' : ''; ?>>📅 Agendada</option>
                        <option value="em_andamento" <?php echo (isset($_POST['status']) && $_POST['status'] === 'em_andamento') ? 'selected' : ''; ?>>🔧 Em Andamento</option>
                    </select>
                    <small><i class="fas fa-info-circle"></i> Se a manutenção já começou, selecione "Em Andamento".</small>
                </div>

                <div class="form-actions">
                    <a href="listar_manutencoes.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="reset" class="btn btn-warning">
                        <i class="fas fa-undo"></i> Limpar
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Registrar Manutenção
                    </button>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <script>
        // Validação: data de fim não pode ser anterior à data de início
        document.getElementById('data_fim').addEventListener('change', function() {
            const inicio = document.getElementById('data_inicio').value;
            const fim = this.value;
            if (inicio && fim && fim < inicio) {
                alert('⚠️ A data de fim não pode ser anterior à data de início.');
                this.value = inicio;
            }
        });

        document.getElementById('data_inicio').addEventListener('change', function() {
            const fim = document.getElementById('data_fim').value;
            const inicio = this.value;
            if (inicio && fim && fim < inicio) {
                alert('⚠️ A data de fim não pode ser anterior à data de início.');
                document.getElementById('data_fim').value = inicio;
            }
        });

        // Pré-visualização: mostrar informações da sala selecionada
        document.getElementById('id_sala').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (this.value) {
                console.log('Sala selecionada: ' + selectedOption.text);
            }
        });
    </script>

</body>
</html>