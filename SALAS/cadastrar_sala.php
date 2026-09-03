<?php
// ============================================================
// ARQUIVO: SALAS/cadastrar_sala.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Cadastrar novas salas no sistema
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem cadastrar salas.');
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
// BUSCAR LISTA DE UNIDADES (PARA O SELECT - FILTRADAS POR CLIENTE)
// ============================================================
try {
    $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? AND status_unidade = 'ativo' ORDER BY nome_unidade";
    $stmtUnidades = $conn->prepare($sqlUnidades);
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $unidades = [];
}

// ============================================================
// BUSCAR TIPOS DE SALA EXISTENTES (FILTRADOS POR CLIENTE)
// ============================================================
try {
    $sqlTipos = "SELECT DISTINCT tipo_sala FROM salas WHERE id_cliente = ? AND tipo_sala IS NOT NULL AND tipo_sala != '' ORDER BY tipo_sala ASC";
    $stmtTipos = $conn->prepare($sqlTipos);
    $stmtTipos->execute([$id_cliente]);
    $tiposExistentes = $stmtTipos->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $tiposExistentes = [];
}

// ============================================================
// PROCESSAR CADASTRO
// ============================================================
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Receber dados do formulário
    if ($tipo_usuario === 'gerente') {
        $id_unidade = $id_unidade_usuario;
    } else {
        $id_unidade = (int)($_POST['id_unidade'] ?? 0);
    }
    
    $numero       = trim($_POST['numero'] ?? '');
    $andar        = trim($_POST['andar'] ?? '');
    $capacidade   = trim($_POST['capacidade'] ?? '');
    $tipo         = trim($_POST['tipo'] ?? '');
    $status       = $_POST['status'] ?? 'disponivel';
    $descricao    = trim($_POST['descricao'] ?? '');
    $recursos     = trim($_POST['recursos'] ?? '');

    // Validações
    if (empty($id_unidade) || empty($numero) || empty($andar) || empty($capacidade) || empty($tipo)) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (!is_numeric($capacidade) || $capacidade <= 0) {
        $erro = 'Capacidade deve ser um número positivo.';
    }

    if (empty($erro)) {
        try {
            // Verificar se o número da sala já existe para este cliente
            $check = $conn->prepare("SELECT COUNT(*) FROM salas WHERE numero_sala = :numero AND id_cliente = :id_cliente");
            $check->execute([
                ':numero' => $numero,
                ':id_cliente' => $id_cliente
            ]);
            if ($check->fetchColumn() > 0) {
                $erro = 'Já existe uma sala cadastrada com este número nesta organização.';
            } else {
                $sql = "INSERT INTO salas (
                            id_cliente,
                            id_unidade,
                            numero_sala,
                            andar_sala,
                            capacidade_sala,
                            tipo_sala,
                            status_sala,
                            descricao_sala,
                            recursos_sala
                        ) VALUES (
                            :id_cliente,
                            :id_unidade,
                            :numero,
                            :andar,
                            :capacidade,
                            :tipo,
                            :status,
                            :descricao,
                            :recursos
                        )";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':id_cliente' => $id_cliente,
                    ':id_unidade' => $id_unidade,
                    ':numero'     => $numero,
                    ':andar'      => $andar,
                    ':capacidade' => $capacidade,
                    ':tipo'       => $tipo,
                    ':status'     => $status,
                    ':descricao'  => $descricao,
                    ':recursos'   => $recursos ?: null
                ]);

                $id_sala = $conn->lastInsertId();
                
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
                        'salas',
                        :id_registro,
                        'INSERT',
                        :dados,
                        :ip
                    )";
                    $stmtHistorico = $conn->prepare($sqlHistorico);
                    $stmtHistorico->execute([
                        ':id_funcionario' => getUsuarioId(),
                        ':id_registro' => $id_sala,
                        ':dados' => json_encode([
                            'numero' => $numero,
                            'andar' => $andar,
                            'capacidade' => $capacidade,
                            'tipo' => $tipo,
                            'status' => $status,
                            'id_unidade' => $id_unidade
                        ]),
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                    ]);
                } catch (PDOException $e) {
                    // Não interrompe o processo
                    error_log('Erro ao registrar histórico: ' . $e->getMessage());
                }

                $sucesso = 'Sala cadastrada com sucesso!';
            }
        } catch (PDOException $e) {
            $erro = 'Erro ao cadastrar sala: ' . $e->getMessage();
        }
    }
}

// Mensagens da sessão
$message = getMessage();
if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
} elseif ($message && $message['tipo'] === 'success') {
    $sucesso = $message['mensagem'];
}

$titulo = 'Cadastrar Sala - Gerenciador de Salas';
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

        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 30px;
            max-width: 700px;
            width: 100%;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #1a2639;
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: #dc3545;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: #fafcff;
            transition: border-color 0.2s;
            color: #1a2639;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-group input:disabled,
        .form-group input[readonly] {
            background: #f0f4fb;
            color: #6c7a8e;
            cursor: not-allowed;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group small {
            display: block;
            font-size: 12px;
            color: #7a8aa0;
            margin-top: 4px;
        }

        .form-group small i {
            color: #1a73e8;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f0f4fb;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 60px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
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

        .btn-outline {
            background: transparent;
            color: #1a2639;
            border: 1px solid #d8e0ec;
        }
        .btn-outline:hover {
            background: #f0f4fb;
        }

        .btn-danger {
            background: #dc3545;
            color: #ffffff;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
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

        @media (max-width: 540px) {
            .main {
                padding: 16px 18px;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .card-panel {
                padding: 20px;
            }
            .form-actions {
                flex-direction: column;
            }
            .form-actions .btn {
                justify-content: center;
            }
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

        .unidade-disabled {
            padding: 10px 14px;
            background: #f0f4fb;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            color: #6c7a8e;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unidade-disabled i {
            color: #1a73e8;
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-plus-circle"></i> Cadastrar Sala</h1>
                <p class="page-subtitle">Preencha os dados para cadastrar uma nova sala</p>
            </div>
            <div style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
            </div>
        </header>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sucesso); ?></div>
        <?php endif; ?>

        <div class="card-panel">
            <form method="POST" action="">
                <!-- Unidade -->
                <div class="form-group">
                    <label for="id_unidade">Unidade <span class="required">*</span></label>
                    <?php if ($tipo_usuario === 'admin_cliente'): ?>
                        <select name="id_unidade" id="id_unidade" required>
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($unidades as $uni): ?>
                                <option value="<?php echo $uni['id_unidade']; ?>">
                                    <?php echo htmlspecialchars($uni['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <!-- Coordenador: mostra a unidade travada -->
                        <div class="unidade-disabled">
                            <i class="fas fa-building"></i>
                            <?php 
                                $nomeUnidade = '';
                                foreach ($unidades as $uni) {
                                    if ($uni['id_unidade'] == $id_unidade_usuario) {
                                        $nomeUnidade = $uni['nome_unidade'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($nomeUnidade);
                            ?>
                            <input type="hidden" name="id_unidade" value="<?php echo $id_unidade_usuario; ?>">
                        </div>
                        <small><i class="fas fa-lock"></i> Unidade travada de acordo com seu vínculo como coordenador.</small>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="numero">Número da Sala <span class="required">*</span></label>
                        <input type="text" name="numero" id="numero" placeholder="Ex: 201" required>
                    </div>
                    <div class="form-group">
                        <label for="andar">Andar <span class="required">*</span></label>
                        <input type="text" name="andar" id="andar" placeholder="Ex: 2" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="capacidade">Capacidade (pessoas) <span class="required">*</span></label>
                        <input type="number" name="capacidade" id="capacidade" placeholder="Ex: 30" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="tipo">Tipo da Sala <span class="required">*</span></label>
                        <input type="text" name="tipo" id="tipo" 
                               list="tipos_list" 
                               placeholder="Digite ou selecione um tipo" 
                               autocomplete="off" required>
                        <datalist id="tipos_list">
                            <?php foreach ($tiposExistentes as $tipo): ?>
                                <option value="<?php echo htmlspecialchars($tipo); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small><i class="fas fa-info-circle"></i> Você pode digitar um novo tipo ou selecionar um já existente.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Status <span class="required">*</span></label>
                    <select name="status" id="status" required>
                        <option value="disponivel">Disponível</option>
                        <option value="ocupada">Ocupada</option>
                        <option value="inativa">Inativa</option>
                    </select>
                    <small><i class="fas fa-info-circle"></i> Status "Em Manutenção" deve ser registrado através do botão específico.</small>
                </div>

                <!-- Campo Recursos (texto livre) -->
                <div class="form-group">
                    <label for="recursos">Recursos da Sala</label>
                    <textarea name="recursos" id="recursos" rows="3" 
                              placeholder="Descreva os recursos disponíveis (ex: projetor, quadro branco, ar-condicionado, 30 cadeiras...)"></textarea>
                    <small><i class="fas fa-info-circle"></i> Informe os recursos da sala em texto livre, separados por vírgula ou em forma de lista.</small>
                </div>

                <!-- Descrição -->
                <div class="form-group">
                    <label for="descricao">Descrição</label>
                    <textarea name="descricao" id="descricao" rows="4" 
                              placeholder="Digite alguma observação sobre a sala..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Cadastrar Sala</button>
                    <button type="reset" class="btn btn-outline"><i class="fas fa-undo"></i> Limpar</button>
                    <a href="listar_salas.php" class="btn btn-danger"><i class="fas fa-times"></i> Cancelar</a>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>