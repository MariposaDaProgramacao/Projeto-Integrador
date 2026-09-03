<?php
// ============================================================
// ARQUIVO: resetar_senha.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Permitir que o usuário logado altere sua própria senha
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
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 4. VARIÁVEIS PARA MENSAGENS
// ============================================================
$erro = '';
$sucesso = '';
$id_usuario = getUsuarioId();
$id_cliente = getClienteId();

// ============================================================
// 5. PROCESSAR O FORMULÁRIO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (empty($senha_atual) || empty($nova_senha) || empty($confirmar_senha)) {
        $erro = 'Preencha todos os campos.';
    } elseif (strlen($nova_senha) < 6) {
        $erro = 'A nova senha deve ter pelo menos 6 caracteres.';
    } elseif ($nova_senha !== $confirmar_senha) {
        $erro = 'A confirmação da senha não coincide.';
    } else {
        try {
            // ============================================================
            // BUSCAR SENHA ATUAL DO USUÁRIO (NOVA TABELA)
            // ============================================================
            $sql = "SELECT senha_usuario FROM usuarios_sistema WHERE id_usuario = :id AND id_cliente = :id_cliente";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $id_usuario,
                ':id_cliente' => $id_cliente
            ]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                $erro = 'Usuário não encontrado.';
            } elseif (!password_verify($senha_atual, $usuario['senha_usuario'])) {
                $erro = 'A senha atual está incorreta.';
            } else {
                // ============================================================
                // ATUALIZAR SENHA
                // ============================================================
                $novo_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                
                $sql_update = "UPDATE usuarios_sistema 
                               SET senha_usuario = :nova_senha 
                               WHERE id_usuario = :id AND id_cliente = :id_cliente";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':nova_senha' => $novo_hash,
                    ':id' => $id_usuario,
                    ':id_cliente' => $id_cliente
                ]);
                
                // ============================================================
                // REGISTRAR NO HISTÓRICO DO SISTEMA
                // ============================================================
                try {
                    $sql_historico = "INSERT INTO historico_sistema (
                        id_funcionario, tabela_afetada, id_registro_afetado, acao, motivo, ip_origem
                    ) VALUES (
                        :id_funcionario, 'usuarios_sistema', :id_registro, 'UPDATE', 'Alteração de senha', :ip
                    )";
                    $stmt_historico = $conn->prepare($sql_historico);
                    $stmt_historico->execute([
                        ':id_funcionario' => $id_usuario,
                        ':id_registro' => $id_usuario,
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                    ]);
                } catch (PDOException $e) {
                    error_log('Erro ao registrar alteração de senha: ' . $e->getMessage());
                }
                
                $sucesso = 'Senha alterada com sucesso!';
                
                // Opcional: limpar campos do formulário
                $_POST = [];
            }
            
        } catch (PDOException $e) {
            $erro = 'Erro ao processar a solicitação. Tente novamente.';
            error_log('Erro ao alterar senha: ' . $e->getMessage());
        }
    }
}

// ============================================================
// 6. BUSCAR DADOS DO USUÁRIO PARA EXIBIR (NOVA TABELA)
// ============================================================
try {
    $sql = "SELECT 
                u.id_usuario,
                u.nome_usuario,
                u.email_usuario,
                u.tipo_usuario,
                u.status_usuario,
                u.data_ultimo_acesso,
                u.telefone_usuario,
                c.nome_cliente,
                c.id_cliente
            FROM usuarios_sistema u
            JOIN clientes c ON u.id_cliente = c.id_cliente
            WHERE u.id_usuario = :id AND u.id_cliente = :id_cliente";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id_usuario,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        session_destroy();
        setMessage('error', 'Usuário não encontrado.');
        redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
    }
    
} catch (PDOException $e) {
    $erro = 'Erro ao carregar dados do usuário.';
    error_log('Erro ao carregar usuário: ' . $e->getMessage());
}

// Nome do usuário para saudação
$usuario_nome = $usuario['nome_usuario'] ?? 'Usuário';
$nome_cliente = $usuario['nome_cliente'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Alterar Senha - Gerenciamento de Ambientes</title>

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

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-bottom: 28px;
        }

        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 24px 28px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .panel-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0e1a2b;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1a2639;
            margin-bottom: 5px;
        }

        .form-group label i {
            margin-right: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #e2e9f3;
            background: #fafcff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #1a2639;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            box-sizing: border-box;
        }

        .form-group input:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        }

        .form-group input::placeholder {
            color: #9aabbf;
        }

        .password-requirements {
            margin-top: 4px;
            padding: 6px 12px;
            background: #f8faff;
            border-radius: 8px;
            font-size: 12px;
            color: #7a8aa0;
        }

        .password-requirements .req-item {
            display: inline-block;
            margin-right: 12px;
        }

        .password-requirements .req-item i {
            margin-right: 4px;
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
            transform: scale(1.02);
        }

        .btn-outline {
            background: transparent;
            color: #1a2639;
            border: 1px solid #dce3ef;
        }
        .btn-outline:hover {
            background: #f0f4fb;
            border-color: #bcc8db;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }

        .form-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* Alertas */
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
        .alert i {
            font-size: 18px;
        }

        .info-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #f8faff;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #eef3fa;
        }
        .info-row .info-item {
            font-size: 13px;
            color: #5a6a7e;
        }
        .info-row .info-item strong {
            color: #1a2639;
        }
        .info-row .info-item i {
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
            .two-col {
                grid-template-columns: 1fr;
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
            .card-panel {
                padding: 18px 16px;
            }
            .form-actions {
                flex-direction: column;
            }
            .form-actions .btn {
                flex: none;
            }
            .info-row {
                flex-direction: column;
                gap: 6px;
            }
        }
    </style>
</head>
<body>

    <!-- ========================================== -->
    <!-- SIDEBAR (INCLUDE)                          -->
    <!-- ========================================== -->
    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <!-- ========================================== -->
    <!-- CONTEÚDO PRINCIPAL                        -->
    <!-- ========================================== -->
    <main class="main">
        
        <!-- Cabeçalho -->
        <header class="page-header">
            <h1>
                <i class="fas fa-key" style="color: #1a73e8;"></i>
                Alterar Senha
                <small>Mantenha sua senha segura</small>
            </h1>
            <div style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($nome_cliente); ?>
            </div>
        </header>

        <!-- ========================================== -->
        <!-- INFO DO USUÁRIO                           -->
        <!-- ========================================== -->
        <div class="info-row">
            <span class="info-item">
                <i class="fas fa-user"></i> <strong><?php echo htmlspecialchars($usuario_nome); ?></strong>
            </span>
            <span class="info-item">
                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($usuario['email_usuario'] ?? ''); ?>
            </span>
            <span class="info-item">
                <i class="fas fa-user-tag"></i> 
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
            <span class="info-item">
                <i class="fas fa-clock"></i> Último acesso: 
                <?php echo $usuario['data_ultimo_acesso'] ? date('d/m/Y H:i', strtotime($usuario['data_ultimo_acesso'])) : 'Nunca'; ?>
            </span>
        </div>

        <!-- ========================================== -->
        <!-- CARD DE ALTERAÇÃO DE SENHA                -->
        <!-- ========================================== -->
        <div class="two-col">
            <div class="card-panel" style="grid-column: span 2; max-width: 600px; margin: 0 auto;">
                <div class="panel-header">
                    <h3>
                        <i class="fas fa-key" style="color: #1a73e8;"></i>
                        Preencha os campos abaixo
                    </h3>
                </div>

                <!-- Mensagens de erro/sucesso -->
                <?php if ($erro): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($erro); ?>
                    </div>
                <?php endif; ?>

                <?php if ($sucesso): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($sucesso); ?>
                    </div>
                <?php endif; ?>

                <!-- Formulário -->
                <form method="POST" action="">
                    
                    <div class="form-group">
                        <label for="senha_atual">
                            <i class="fas fa-lock" style="color: #1a73e8;"></i> Senha Atual
                        </label>
                        <input type="password" name="senha_atual" id="senha_atual" 
                               placeholder="Digite sua senha atual" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="nova_senha">
                            <i class="fas fa-key" style="color: #1a73e8;"></i> Nova Senha
                        </label>
                        <input type="password" name="nova_senha" id="nova_senha" 
                               placeholder="Mínimo 6 caracteres" required>
                        <div class="password-requirements">
                            <i class="fas fa-info-circle" style="color: #1a73e8;"></i>
                            <span class="req-item">
                                <i class="fas fa-check-circle" style="color: #34a853;"></i> Mínimo 6 caracteres
                            </span>
                            <span class="req-item">
                                <i class="fas fa-check-circle" style="color: #34a853;"></i> Letras e números
                            </span>
                            <span class="req-item">
                                <i class="fas fa-check-circle" style="color: #34a853;"></i> Caractere especial
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmar_senha">
                            <i class="fas fa-check-double" style="color: #1a73e8;"></i> Confirmar Nova Senha
                        </label>
                        <input type="password" name="confirmar_senha" id="confirmar_senha" 
                               placeholder="Digite novamente a nova senha" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Alterar Senha
                        </button>
                        <a href="dashboard.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Cancelar
                        </a>
                    </div>

                </form>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- RODAPÉ (INCLUDE)                           -->
        <!-- ========================================== -->
        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>

    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Focar no campo de senha atual
            document.getElementById('senha_atual').focus();
            
            // Validação em tempo real da confirmação de senha
            const novaSenha = document.getElementById('nova_senha');
            const confirmarSenha = document.getElementById('confirmar_senha');
            
            function validarConfirmacao() {
                if (confirmarSenha.value.length === 0) {
                    confirmarSenha.style.borderColor = '#e2e9f3';
                    confirmarSenha.style.boxShadow = 'none';
                    return;
                }
                
                if (novaSenha.value === confirmarSenha.value) {
                    confirmarSenha.style.borderColor = '#34a853';
                    confirmarSenha.style.boxShadow = '0 0 0 4px rgba(52, 168, 83, 0.1)';
                } else {
                    confirmarSenha.style.borderColor = '#dc3545';
                    confirmarSenha.style.boxShadow = '0 0 0 4px rgba(220, 53, 69, 0.1)';
                }
            }
            
            novaSenha.addEventListener('input', validarConfirmacao);
            confirmarSenha.addEventListener('input', validarConfirmacao);
        });
    </script>

</body>
</html>