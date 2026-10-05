<?php
// ============================================================
// AUTENTIFICACAO_ACESSO/cadastro.php (MODIFICADO PARA MULTI-TENANT)
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// Se já estiver logado, redireciona para o dashboard
if (isLoggedIn()) {
    redirect('../index.html');
}

$erro = '';
$sucesso = '';
$dados_form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Capturar dados do formulário
    $dados_form = [
        'nome_cliente' => trim($_POST['nome_cliente'] ?? ''),
        'tipo_cliente' => $_POST['tipo_cliente'] ?? 'outro',
        'cnpj' => trim($_POST['cnpj'] ?? ''),
        'email_cliente' => trim($_POST['email_cliente'] ?? ''),
        'telefone_cliente' => trim($_POST['telefone_cliente'] ?? ''),
        'endereco' => trim($_POST['endereco'] ?? ''),
        'cidade' => trim($_POST['cidade'] ?? ''),
        'estado' => strtoupper(trim($_POST['estado'] ?? '')),
        'nome_usuario' => trim($_POST['nome_usuario'] ?? ''),
        'email_usuario' => trim($_POST['email_usuario'] ?? ''),
        'senha' => $_POST['senha'] ?? '',
        'confirmar_senha' => $_POST['confirmar_senha'] ?? ''
    ];
    
    // Validações
    if (empty($dados_form['nome_cliente'])) {
        $erro = 'Por favor, informe o nome da sua organização.';
    } elseif (empty($dados_form['email_cliente'])) {
        $erro = 'Por favor, informe o e-mail da organização.';
    } elseif (!filter_var($dados_form['email_cliente'], FILTER_VALIDATE_EMAIL)) {
        $erro = 'Por favor, informe um e-mail válido para a organização.';
    } elseif (empty($dados_form['nome_usuario'])) {
        $erro = 'Por favor, informe seu nome completo.';
    } elseif (empty($dados_form['email_usuario'])) {
        $erro = 'Por favor, informe seu e-mail.';
    } elseif (!filter_var($dados_form['email_usuario'], FILTER_VALIDATE_EMAIL)) {
        $erro = 'Por favor, informe um e-mail válido para o administrador.';
    } elseif (empty($dados_form['senha'])) {
        $erro = 'Por favor, crie uma senha.';
    } elseif (strlen($dados_form['senha']) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif (!preg_match('/[A-Z]/', $dados_form['senha'])) {
        $erro = 'A senha deve conter pelo menos uma letra maiúscula.';
    } elseif (!preg_match('/[a-z]/', $dados_form['senha'])) {
        $erro = 'A senha deve conter pelo menos uma letra minúscula.';
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $dados_form['senha'])) {
        $erro = 'A senha deve conter pelo menos um caractere especial (!@#$%^&*(),.?":{}|<>).';
    } elseif ($dados_form['senha'] !== $dados_form['confirmar_senha']) {
        $erro = 'As senhas não coincidem.';
    } else {
        try {
            // Verificar se já existe cliente com este e-mail
            $sql = "SELECT id_cliente FROM clientes WHERE email_cliente = :email";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':email' => $dados_form['email_cliente']]);
            
            if ($stmt->fetch()) {
                $erro = 'Já existe uma organização cadastrada com este e-mail. Por favor, use outro.';
            } else {
                // Verificar se já existe usuário com este e-mail
                $sql = "SELECT id_usuario FROM usuarios_sistema WHERE email_usuario = :email";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':email' => $dados_form['email_usuario']]);
                
                if ($stmt->fetch()) {
                    $erro = 'Este e-mail já está cadastrado como usuário. Por favor, use outro.';
                } else {
                    // Iniciar transação
                    $conn->beginTransaction();
                    
                    // Hash da senha
                    $senha_hash = password_hash($dados_form['senha'], PASSWORD_DEFAULT);
                    
                    // 1. Inserir o cliente (organização)
                    $sql = "INSERT INTO clientes 
                            (nome_cliente, tipo_cliente, cnpj_cliente, email_cliente, 
                             telefone_cliente, endereco_cliente, cidade_cliente, 
                             estado_cliente, status_cliente, plano_cliente, 
                             limite_unidades, limite_usuarios) 
                            VALUES 
                            (:nome, :tipo, :cnpj, :email, 
                             :telefone, :endereco, :cidade, 
                             :estado, 'ativo', 'gratuito', 
                             3, 10)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':nome' => $dados_form['nome_cliente'],
                        ':tipo' => $dados_form['tipo_cliente'],
                        ':cnpj' => $dados_form['cnpj'],
                        ':email' => $dados_form['email_cliente'],
                        ':telefone' => $dados_form['telefone_cliente'],
                        ':endereco' => $dados_form['endereco'],
                        ':cidade' => $dados_form['cidade'],
                        ':estado' => $dados_form['estado']
                    ]);
                    
                    $id_cliente = $conn->lastInsertId();
                    
                    // 2. Inserir o usuário administrador
                    $sql = "INSERT INTO usuarios_sistema 
                            (id_cliente, nome_usuario, email_usuario, senha_usuario, 
                             tipo_usuario, status_usuario, telefone_usuario) 
                            VALUES 
                            (:id_cliente, :nome, :email, :senha, 
                             'admin_cliente', 'ativo', :telefone)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id_cliente' => $id_cliente,
                        ':nome' => $dados_form['nome_usuario'],
                        ':email' => $dados_form['email_usuario'],
                        ':senha' => $senha_hash,
                        ':telefone' => $dados_form['telefone_cliente']
                    ]);
                    
                    $id_usuario = $conn->lastInsertId();
                    
                    // 3. Registrar no histórico
                    $sql_historico = "INSERT INTO historico_sistema 
                                    (id_funcionario, tabela_afetada, id_registro_afetado, acao, dados_novos) 
                                    VALUES 
                                    (:id_funcionario, 'clientes', :id_registro, 'cadastro_cliente', :dados)";
                    $stmt_historico = $conn->prepare($sql_historico);
                    $stmt_historico->execute([
                        ':id_funcionario' => 1, // Admin sistema
                        ':id_registro' => $id_cliente,
                        ':dados' => json_encode([
                            'cliente' => $dados_form['nome_cliente'],
                            'email' => $dados_form['email_cliente'],
                            'usuario' => $dados_form['nome_usuario']
                        ])
                    ]);
                    
                    // 4. Commit da transação
                    $conn->commit();
                    
                    // 5. Mensagem de sucesso
                    $_SESSION['sucesso'] = 'Cadastro realizado com sucesso! Agora você já pode acessar o sistema com seu e-mail e senha.';
                    header('Location: realizar_login.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $erro = 'Erro ao realizar cadastro. Tente novamente mais tarde.';
            error_log('Erro no cadastro: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Gerenciador de Salas</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        /* Reset básico */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f4fb;
            padding: 20px;
        }

        /* Imagem de fundo */
        .bg-image {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 0;
            overflow: hidden;
        }
        .bg-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Overlay */
        .cadastro-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 600px;
        }

        /* Card de cadastro */
        .cadastro-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 35px 32px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.6);
            transition: transform 0.2s ease;
        }
        .cadastro-card:hover {
            transform: translateY(-2px);
        }

        .cadastro-card .logo-icon {
            text-align: center;
            font-size: 42px;
            color: #1a73e8;
            margin-bottom: 6px;
        }
        .cadastro-card h2 {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
            text-align: center;
            margin-bottom: 2px;
        }
        .cadastro-card .subtitle {
            text-align: center;
            color: #7a8aa0;
            font-size: 14px;
            margin-bottom: 22px;
        }

        /* Título de seção */
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1a73e8;
            margin: 20px 0 12px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e9f3;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i {
            font-size: 16px;
        }
        .section-title:first-of-type {
            margin-top: 5px;
        }

        /* Campos do formulário */
        .cadastro-card .form-group {
            margin-bottom: 14px;
        }
        .cadastro-card .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1a2639;
            margin-bottom: 4px;
        }
        .cadastro-card .form-group label i {
            color: #1a73e8;
            margin-right: 6px;
            width: 18px;
        }
        .cadastro-card .form-group label .required {
            color: #b33a3a;
            margin-left: 2px;
        }

        /* Estilo para inputs */
        .cadastro-card .form-group input:not(.input-wrapper input),
        .cadastro-card .form-group select {
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
        }
        .cadastro-card .form-group input:not(.input-wrapper input):focus,
        .cadastro-card .form-group select:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        }
        .cadastro-card .form-group input:not(.input-wrapper input)::placeholder {
            color: #9aabbf;
        }
        .cadastro-card .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239aabbf' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
            cursor: pointer;
        }

        /* Container com ícone de olho */
        .input-wrapper {
            position: relative;
            width: 100%;
        }
        .input-wrapper input {
            width: 100%;
            padding: 10px 44px 10px 14px;
            border-radius: 12px;
            border: 1px solid #e2e9f3;
            background: #fafcff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #1a2639;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .input-wrapper input:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        }
        .input-wrapper input::placeholder {
            color: #9aabbf;
        }

        /* Botão de mostrar/ocultar senha */
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9aabbf;
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.2s;
        }
        .toggle-password:hover {
            color: #1a73e8;
        }

        /* Grid para campos lado a lado */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
        }

        /* Botão */
        .cadastro-card .btn {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            border-radius: 60px;
            margin-top: 10px;
            border: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .cadastro-card .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .cadastro-card .btn-primary:hover {
            background: #1557b0;
            transform: scale(1.02);
        }
        .cadastro-card .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Alertas */
        .cadastro-card .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cadastro-card .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border: 1px solid #ffd6d6;
        }
        .cadastro-card .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border: 1px solid #c8f0cf;
        }
        .cadastro-card .alert i {
            font-size: 18px;
        }

        /* Link para login */
        .cadastro-card .login-link {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
            color: #7a8aa0;
        }
        .cadastro-card .login-link a {
            color: #1a73e8;
            text-decoration: none;
            font-weight: 600;
        }
        .cadastro-card .login-link a:hover {
            text-decoration: underline;
        }

        /* Requisitos de senha */
        .senha-requisitos {
            margin-top: 8px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 16px;
        }
        .senha-requisitos .requisito {
            font-size: 12px;
            color: #7a8aa0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            padding: 2px 0;
        }
        .senha-requisitos .requisito i {
            font-size: 12px;
            width: 16px;
            text-align: center;
        }
        .senha-requisitos .requisito.valido {
            color: #1e8546;
        }
        .senha-requisitos .requisito.invalido {
            color: #b33a3a;
        }

        .confirmacao-requisitos {
            margin-top: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .confirmacao-requisitos .requisito {
            font-size: 12px;
            color: #7a8aa0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            padding: 2px 0;
        }
        .confirmacao-requisitos .requisito i {
            font-size: 12px;
            width: 16px;
            text-align: center;
        }
        .confirmacao-requisitos .requisito.valido {
            color: #1e8546;
        }
        .confirmacao-requisitos .requisito.invalido {
            color: #b33a3a;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .form-row-3 {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
        @media (max-width: 480px) {
            .cadastro-card {
                padding: 24px 18px;
            }
            .cadastro-card h2 {
                font-size: 20px;
            }
            .cadastro-card .btn {
                font-size: 14px;
                padding: 10px;
            }
            .senha-requisitos {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }

        /* Estilo para o separador */
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 18px 0 12px 0;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e2e9f3;
        }
        .divider::before {
            margin-right: 15px;
        }
        .divider::after {
            margin-left: 15px;
        }
        .divider span {
            color: #9aabbf;
            font-size: 13px;
            font-weight: 500;
        }

        /* Tooltip para CNPJ */
        .helper-text {
            font-size: 11px;
            color: #9aabbf;
            margin-top: 3px;
            display: block;
        }
        .helper-text i {
            margin-right: 4px;
        }
    </style>
</head>
<body>

    <!-- Imagem de fundo -->
    <div class="bg-image">
        <img src="../IMAGENS/Predio.png" alt="Fundo do sistema">
    </div>

    <!-- Wrapper centralizador -->
    <div class="cadastro-wrapper">
        <div class="cadastro-card">
            <!-- Ícone -->
            <div class="logo-icon">
                <i class="fas fa-user-plus"></i>
            </div>
            <h2>Criar Conta</h2>
            <p class="subtitle">Gerenciador de Ambientes - Cadastro de Organização</p>

            <!-- Mensagens de erro -->
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
            <form method="POST" action="" id="formCadastro" novalidate>
                <!-- ========================================== -->
                <!-- DADOS DA ORGANIZAÇÃO                       -->
                <!-- ========================================== -->
                <div class="section-title">
                    <i class="fas fa-building"></i> Dados da Organização
                </div>

                <div class="form-group">
                    <label for="nome_cliente">
                        <i class="fas fa-store"></i> Nome da Organização <span class="required">*</span>
                    </label>
                    <input type="text" name="nome_cliente" id="nome_cliente" 
                           placeholder="Ex: Escola São Paulo, Salão Beleza Total, etc." 
                           value="<?php echo htmlspecialchars($dados_form['nome_cliente'] ?? ''); ?>" 
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="tipo_cliente">
                            <i class="fas fa-tag"></i> Tipo <span class="required">*</span>
                        </label>
                        <select name="tipo_cliente" id="tipo_cliente" required>
                            <option value="escola" <?php echo (isset($dados_form['tipo_cliente']) && $dados_form['tipo_cliente'] === 'escola') ? 'selected' : ''; ?>>Escola</option>
                            <option value="salão" <?php echo (isset($dados_form['tipo_cliente']) && $dados_form['tipo_cliente'] === 'salão') ? 'selected' : ''; ?>>Salão</option>
                            <option value="empresa" <?php echo (isset($dados_form['tipo_cliente']) && $dados_form['tipo_cliente'] === 'empresa') ? 'selected' : ''; ?>>Empresa</option>
                            <option value="outro" <?php echo (!isset($dados_form['tipo_cliente']) || $dados_form['tipo_cliente'] === 'outro') ? 'selected' : ''; ?>>Outro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cnpj">
                            <i class="fas fa-id-card"></i> CNPJ
                        </label>
                        <input type="text" name="cnpj" id="cnpj" 
                               placeholder="00.000.000/0000-00" 
                               value="<?php echo htmlspecialchars($dados_form['cnpj'] ?? ''); ?>">
                        <span class="helper-text"><i class="fas fa-info-circle"></i> Opcional, mas recomendado</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email_cliente">
                            <i class="fas fa-envelope"></i> E-mail da Organização <span class="required">*</span>
                        </label>
                        <input type="email" name="email_cliente" id="email_cliente" 
                               placeholder="contato@organizacao.com" 
                               value="<?php echo htmlspecialchars($dados_form['email_cliente'] ?? ''); ?>" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="telefone_cliente">
                            <i class="fas fa-phone"></i> Telefone
                        </label>
                        <input type="tel" name="telefone_cliente" id="telefone_cliente" 
                               placeholder="(00) 00000-0000" 
                               value="<?php echo htmlspecialchars($dados_form['telefone_cliente'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="endereco">
                        <i class="fas fa-map-marker-alt"></i> Endereço
                    </label>
                    <input type="text" name="endereco" id="endereco" 
                           placeholder="Rua, número, bairro" 
                           value="<?php echo htmlspecialchars($dados_form['endereco'] ?? ''); ?>">
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label for="cidade">
                            <i class="fas fa-city"></i> Cidade
                        </label>
                        <input type="text" name="cidade" id="cidade" 
                               placeholder="Sua cidade" 
                               value="<?php echo htmlspecialchars($dados_form['cidade'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="estado">
                            <i class="fas fa-map"></i> Estado
                        </label>
                        <input type="text" name="estado" id="estado" 
                               placeholder="UF" maxlength="2"
                               value="<?php echo htmlspecialchars($dados_form['estado'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <div style="font-size: 11px; color: #9aabbf; padding-bottom: 8px;">
                            <i class="fas fa-info-circle"></i> Ex: MG, SP, RJ
                        </div>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- DADOS DO ADMINISTRADOR                     -->
                <!-- ========================================== -->
                <div class="divider">
                    <span>Dados do Administrador</span>
                </div>

                <div class="form-group">
                    <label for="nome_usuario">
                        <i class="fas fa-user"></i> Nome Completo <span class="required">*</span>
                    </label>
                    <input type="text" name="nome_usuario" id="nome_usuario" 
                           placeholder="Seu nome completo" 
                           value="<?php echo htmlspecialchars($dados_form['nome_usuario'] ?? ''); ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label for="email_usuario">
                        <i class="fas fa-envelope"></i> E-mail do Administrador <span class="required">*</span>
                    </label>
                    <input type="email" name="email_usuario" id="email_usuario" 
                           placeholder="seu.email@dominio.com" 
                           value="<?php echo htmlspecialchars($dados_form['email_usuario'] ?? ''); ?>" 
                           required>
                    <span class="helper-text"><i class="fas fa-info-circle"></i> Este será seu e-mail de acesso ao sistema</span>
                </div>

                <!-- SENHA -->
                <div class="form-group">
                    <label for="senha">
                        <i class="fas fa-lock"></i> Senha <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="password" name="senha" id="senha" 
                               placeholder="Crie uma senha forte" 
                               required>
                        <button type="button" class="toggle-password" id="toggleSenha" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Requisitos de senha -->
                <div class="senha-requisitos" id="requisitosSenha">
                    <span class="requisito invalido" id="req-tamanho">
                        <i class="fas fa-circle"></i> 6+ caracteres
                    </span>
                    <span class="requisito invalido" id="req-maiuscula">
                        <i class="fas fa-circle"></i> Letra maiúscula
                    </span>
                    <span class="requisito invalido" id="req-minuscula">
                        <i class="fas fa-circle"></i> Letra minúscula
                    </span>
                    <span class="requisito invalido" id="req-especial">
                        <i class="fas fa-circle"></i> Caractere especial
                    </span>
                </div>

                <!-- CONFIRMAR SENHA -->
                <div class="form-group" style="margin-top: 12px;">
                    <label for="confirmar_senha">
                        <i class="fas fa-check-circle"></i> Confirmar Senha <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="password" name="confirmar_senha" id="confirmar_senha" 
                               placeholder="Confirme sua senha" 
                               required>
                        <button type="button" class="toggle-password" id="toggleConfirmar" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Requisitos de confirmação -->
                <div class="confirmacao-requisitos" id="requisitosConfirmacao">
                    <span class="requisito invalido" id="req-confirmacao">
                        <i class="fas fa-circle"></i> As senhas devem coincidir
                    </span>
                </div>

                <!-- Informações adicionais -->
                <div style="margin: 12px 0 8px 0; font-size: 12px; color: #7a8aa0; background: #f5f8fc; padding: 10px 14px; border-radius: 10px; border-left: 3px solid #1a73e8;">
                    <i class="fas fa-info-circle" style="color: #1a73e8;"></i>
                    Ao se cadastrar, você terá acesso gratuito ao plano básico com 
                    <strong>3 unidades</strong> e <strong>10 usuários</strong>. 
                    Entre em contato para planos maiores.
                </div>

                <button type="submit" class="btn btn-primary" id="btnCadastrar">
                    <i class="fas fa-user-plus"></i> Cadastrar Organização
                </button>
            </form>

            <!-- Link para login -->
            <div class="login-link">
                Já tem uma conta? <a href="realizar_login.php">Faça login</a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('formCadastro');
            const senha = document.getElementById('senha');
            const confirmarSenha = document.getElementById('confirmar_senha');
            const btnCadastrar = document.getElementById('btnCadastrar');

            // Requisitos de senha
            const reqTamanho = document.getElementById('req-tamanho');
            const reqMaiuscula = document.getElementById('req-maiuscula');
            const reqMinuscula = document.getElementById('req-minuscula');
            const reqEspecial = document.getElementById('req-especial');
            const reqConfirmacao = document.getElementById('req-confirmacao');

            // Toggle senha
            document.getElementById('toggleSenha').addEventListener('click', function() {
                const input = document.getElementById('senha');
                const icon = this.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'fas fa-eye';
                }
            });

            document.getElementById('toggleConfirmar').addEventListener('click', function() {
                const input = document.getElementById('confirmar_senha');
                const icon = this.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'fas fa-eye';
                }
            });

            function atualizarRequisito(elemento, valido) {
                const icone = elemento.querySelector('i');
                if (valido) {
                    elemento.className = 'requisito valido';
                    icone.className = 'fas fa-check-circle';
                } else {
                    elemento.className = 'requisito invalido';
                    icone.className = 'fas fa-circle';
                }
            }

            function validarSenha(valor) {
                const temTamanho = valor.length >= 6;
                const temMaiuscula = /[A-Z]/.test(valor);
                const temMinuscula = /[a-z]/.test(valor);
                const temEspecial = /[!@#$%^&*(),.?":{}|<>]/.test(valor);

                atualizarRequisito(reqTamanho, temTamanho);
                atualizarRequisito(reqMaiuscula, temMaiuscula);
                atualizarRequisito(reqMinuscula, temMinuscula);
                atualizarRequisito(reqEspecial, temEspecial);

                return temTamanho && temMaiuscula && temMinuscula && temEspecial;
            }

            function validarConfirmacao() {
                const senhaValor = senha.value;
                const confirmarValor = confirmarSenha.value;
                
                if (confirmarValor.length === 0) {
                    confirmarSenha.style.borderColor = '#e2e9f3';
                    confirmarSenha.style.boxShadow = 'none';
                    atualizarRequisito(reqConfirmacao, false);
                    return;
                }
                
                if (senhaValor === confirmarValor) {
                    confirmarSenha.style.borderColor = '#1e8546';
                    confirmarSenha.style.boxShadow = '0 0 0 4px rgba(30, 133, 70, 0.1)';
                    atualizarRequisito(reqConfirmacao, true);
                } else {
                    confirmarSenha.style.borderColor = '#b33a3a';
                    confirmarSenha.style.boxShadow = '0 0 0 4px rgba(179, 58, 58, 0.1)';
                    atualizarRequisito(reqConfirmacao, false);
                }
            }

            senha.addEventListener('input', function() {
                validarSenha(this.value);
                validarConfirmacao();
            });

            confirmarSenha.addEventListener('input', validarConfirmacao);

            // Máscara para CNPJ
            document.getElementById('cnpj').addEventListener('input', function() {
                let valor = this.value.replace(/\D/g, '');
                if (valor.length > 14) valor = valor.slice(0, 14);
                
                if (valor.length > 0) {
                    if (valor.length <= 2) {
                        valor = valor;
                    } else if (valor.length <= 5) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2);
                    } else if (valor.length <= 8) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5);
                    } else if (valor.length <= 12) {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5, 8) + '/' + valor.slice(8);
                    } else {
                        valor = valor.slice(0, 2) + '.' + valor.slice(2, 5) + '.' + valor.slice(5, 8) + '/' + valor.slice(8, 12) + '-' + valor.slice(12);
                    }
                }
                this.value = valor;
            });

            // Máscara para telefone
            document.getElementById('telefone_cliente').addEventListener('input', function() {
                let valor = this.value.replace(/\D/g, '');
                if (valor.length > 11) valor = valor.slice(0, 11);
                
                if (valor.length > 0) {
                    if (valor.length <= 2) {
                        valor = '(' + valor;
                    } else if (valor.length <= 6) {
                        valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2);
                    } else if (valor.length <= 10) {
                        valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2, 6) + '-' + valor.slice(6);
                    } else {
                        valor = '(' + valor.slice(0, 2) + ') ' + valor.slice(2, 7) + '-' + valor.slice(7);
                    }
                }
                this.value = valor;
            });

            // Validação do formulário
            form.addEventListener('submit', function(e) {
                const senhaValor = senha.value;
                const confirmarValor = confirmarSenha.value;
                
                if (!validarSenha(senhaValor)) {
                    e.preventDefault();
                    alert('A senha não atende aos requisitos mínimos de segurança.');
                    senha.focus();
                    return false;
                }
                
                if (senhaValor !== confirmarValor) {
                    e.preventDefault();
                    alert('As senhas não coincidem.');
                    confirmarSenha.focus();
                    return false;
                }
                
                btnCadastrar.disabled = true;
                btnCadastrar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
            });

            // Converter estado para maiúsculo
            document.getElementById('estado').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
    </script>

</body>
</html>