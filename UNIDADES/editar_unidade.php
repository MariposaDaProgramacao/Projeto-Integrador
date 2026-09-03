<?php
// ============================================================
// ARQUIVO: UNIDADES/editar_unidade.php (MODIFICADO PARA MULTI-TENANT)
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Apenas administradores podem editar unidades
$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem editar unidades.');
    redirect('listar_unidade.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();

// ============================================================
// LISTA DE FUSOS HORÁRIOS DO BRASIL
// ============================================================
$fusos = [
    'America/Noronha' => 'Fernando de Noronha (UTC-2)',
    'America/Belem' => 'Belém (UTC-3)',
    'America/Fortaleza' => 'Fortaleza (UTC-3)',
    'America/Recife' => 'Recife (UTC-3)',
    'America/Araguaina' => 'Araguaína (UTC-3)',
    'America/Maceio' => 'Maceió (UTC-3)',
    'America/Bahia' => 'Salvador (UTC-3)',
    'America/Sao_Paulo' => 'São Paulo (UTC-3)',
    'America/Campo_Grande' => 'Campo Grande (UTC-4)',
    'America/Cuiaba' => 'Cuiabá (UTC-4)',
    'America/Santarem' => 'Santarém (UTC-3)',
    'America/Porto_Velho' => 'Porto Velho (UTC-4)',
    'America/Boa_Vista' => 'Boa Vista (UTC-4)',
    'America/Manaus' => 'Manaus (UTC-4)',
    'America/Eirunepe' => 'Eirunepé (UTC-5)',
    'America/Rio_Branco' => 'Rio Branco (UTC-5)'
];

// ============================================================
// RECEBER O ID DA UNIDADE
// ============================================================
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setMessage('error', 'ID da unidade inválido.');
    redirect('listar_unidade.php');
}

// ============================================================
// BUSCAR DADOS DA UNIDADE (FILTRADA POR CLIENTE)
// ============================================================
try {
    $sql = "SELECT * FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $unidade = $stmt->fetch();

    if (!$unidade) {
        setMessage('error', 'Unidade não encontrada ou não pertence à sua organização.');
        redirect('listar_unidade.php');
    }
} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar unidade: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// Mensagens da sessão
$message = getMessage();
$erro = '';

if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
}

$titulo = 'Editar Unidade - Gerenciador de Salas';
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
        .form-group select {
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

        .select-dropdown {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237a8aa0' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px 8px;
            padding-right: 40px;
            cursor: pointer;
        }

        .select-dropdown:focus {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 7l5-5 5 5' stroke='%231a73e8' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-group input:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-group small {
            display: block;
            font-size: 12px;
            color: #7a8aa0;
            margin-top: 4px;
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

        .alert i {
            font-size: 18px;
        }

        .info-box {
            background: #f0f7ff;
            border: 1px solid #d0dcfa;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 8px;
        }

        .info-box i {
            color: #1a73e8;
            margin-right: 8px;
        }

        .info-box small {
            color: #1a2639;
            font-size: 13px;
        }

        /* Sidebar */
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
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-edit"></i> Editar Unidade</h1>
                <p class="page-subtitle">Atualize os dados da unidade</p>
            </div>
            <div style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
            </div>
        </header>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <div class="card-panel">
            <form action="processar_unidades.php" method="POST">
                <input type="hidden" name="acao" value="editar">
                <input type="hidden" name="id" value="<?php echo $unidade['id_unidade']; ?>">
                <input type="hidden" name="id_cliente" value="<?php echo $id_cliente; ?>">

                <div class="form-group">
                    <label for="nome">Nome da Unidade <span class="required">*</span></label>
                    <input type="text" name="nome" id="nome" value="<?php echo htmlspecialchars($unidade['nome_unidade']); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="estado">UF <span class="required">*</span></label>
                        <input type="text" name="estado" id="estado" maxlength="2" value="<?php echo htmlspecialchars($unidade['estado_unidade']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="cidade">Cidade <span class="required">*</span></label>
                        <input type="text" name="cidade" id="cidade" value="<?php echo htmlspecialchars($unidade['cidade_unidade']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="endereco">Endereço <span class="required">*</span></label>
                    <input type="text" name="endereco" id="endereco" value="<?php echo htmlspecialchars($unidade['endereco_unidade']); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telefone">Telefone</label>
                        <input type="text" name="telefone" id="telefone" value="<?php echo htmlspecialchars($unidade['telefone_unidade'] ?? ''); ?>" placeholder="(31) 3333-3333">
                    </div>
                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($unidade['email_unidade'] ?? ''); ?>" placeholder="unidade@senac.br">
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- CAMPO: STATUS DA UNIDADE                  -->
                <!-- ========================================== -->
                <div class="form-group">
                    <label for="status_unidade">Status da Unidade</label>
                    <select name="status_unidade" id="status_unidade">
                        <option value="ativo" <?php echo ($unidade['status_unidade'] ?? 'ativo') === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo ($unidade['status_unidade'] ?? 'ativo') === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                    <small>Unidades inativas não aparecem na lista principal.</small>
                </div>

                <!-- ========================================== -->
                <!-- CAMPO: FUSO HORÁRIO                       -->
                <!-- ========================================== -->
                <div class="form-group">
                    <label for="fuso">Fuso Horário <span class="required">*</span></label>
                    <select name="fuso" id="fuso" class="select-dropdown" required>
                        <option value="">Selecione o fuso horário...</option>
                        <?php foreach ($fusos as $valor => $label): ?>
                            <option value="<?php echo $valor; ?>" <?php echo ($unidade['fuso'] ?? 'America/Sao_Paulo') === $valor ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Selecione o fuso horário correspondente à localização da unidade.</small>
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <small>
                            <strong>Horário atual:</strong> 
                            <?php 
                                $timezone = $unidade['fuso'] ?? 'America/Sao_Paulo';
                                $date = new DateTime('now', new DateTimeZone($timezone));
                                echo $date->format('H:i:s') . ' - ' . $timezone;
                            ?>
                        </small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    <a href="listar_unidade.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>