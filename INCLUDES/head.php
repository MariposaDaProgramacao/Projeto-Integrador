<?php
// ============================================================
// AUTENTIFICACAO_ACESSO/realizar_login.php (VERSÃO CORRIGIDA)
// ============================================================

// ============================================================
// 1. CARREGAR A CONEXÃO COM CAMINHO ABSOLUTO
// ============================================================

// Tenta carregar o conexao_banco.php
$caminhos = [
    __DIR__ . '/../conexao_banco.php',      // Caminho relativo
    __DIR__ . '/../../conexao_banco.php',   // Um nível acima
    $_SERVER['DOCUMENT_ROOT'] . '/Projeto Integrador/Sistema-de-Gerenciamento-de-Ambientes/conexao_banco.php', // Caminho absoluto
];

$conexao_carregada = false;
foreach ($caminhos as $caminho) {
    if (file_exists($caminho)) {
        require_once $caminho;
        $conexao_carregada = true;
        break;
    }
}

// Se não encontrou, define as funções manualmente
if (!$conexao_carregada) {
    // Iniciar sessão
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Definir funções manualmente
    if (!function_exists('isLoggedIn')) {
        function isLoggedIn() {
            return isset($_SESSION['id_usuario']) && isset($_SESSION['id_cliente']);
        }
    }
    
    if (!function_exists('redirect')) {
        function redirect($url) {
            header("Location: $url");
            exit;
        }
    }
    
    if (!function_exists('setMessage')) {
        function setMessage($tipo, $mensagem) {
            $_SESSION['message'] = [
                'tipo' => $tipo,
                'mensagem' => $mensagem
            ];
        }
    }
    
    if (!function_exists('getMessage')) {
        function getMessage() {
            if (isset($_SESSION['message'])) {
                $message = $_SESSION['message'];
                unset($_SESSION['message']);
                return $message;
            }
            return null;
        }
    }
    
    if (!function_exists('getClienteId')) {
        function getClienteId() {
            return isset($_SESSION['id_cliente']) ? $_SESSION['id_cliente'] : null;
        }
    }
    
    if (!function_exists('getUsuarioId')) {
        function getUsuarioId() {
            return isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : null;
        }
    }
}

// ============================================================
// 2. VERIFICAR SE JÁ ESTÁ LOGADO
// ============================================================
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    if (empty($email) || empty($senha)) {
        $erro = 'Preencha todos os campos.';
    } else {
        try {
            // Verificar se a conexão existe
            if (!isset($conn)) {
                throw new Exception('Conexão com banco não estabelecida.');
            }
            
            $sql = "SELECT 
                        u.id_usuario,
                        u.nome_usuario,
                        u.email_usuario,
                        u.senha_usuario,
                        u.tipo_usuario,
                        u.status_usuario,
                        u.telefone_usuario,
                        u.data_ultimo_acesso,
                        u.tentativas_login,
                        c.id_cliente,
                        c.nome_cliente,
                        c.status_cliente,
                        c.plano_cliente,
                        c.limite_unidades,
                        c.limite_usuarios
                    FROM usuarios_sistema u
                    JOIN clientes c ON u.id_cliente = c.id_cliente
                    WHERE u.email_usuario = :email";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                $erro = 'E-mail ou senha inválidos.';
            } elseif (!password_verify($senha, $usuario['senha_usuario'])) {
                $sql = "UPDATE usuarios_sistema SET tentativas_login = tentativas_login + 1 WHERE id_usuario = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $usuario['id_usuario']]);
                $erro = 'E-mail ou senha inválidos.';
            } elseif ($usuario['status_usuario'] === 'inativo') {
                $erro = 'Seu cadastro está pendente de aprovação.';
            } elseif ($usuario['status_usuario'] === 'bloqueado') {
                $erro = 'Seu acesso foi bloqueado. Entre em contato com o administrador.';
            } elseif ($usuario['status_cliente'] === 'inativo' || $usuario['status_cliente'] === 'bloqueado') {
                $erro = 'Sua organização está inativa. Entre em contato com o suporte.';
            } else {
                // Resetar tentativas de login
                $sql = "UPDATE usuarios_sistema SET tentativas_login = 0 WHERE id_usuario = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $usuario['id_usuario']]);
                
                // Atualizar último acesso
                $sql = "UPDATE usuarios_sistema SET data_ultimo_acesso = NOW() WHERE id_usuario = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $usuario['id_usuario']]);
                
                // Criar sessão
                $_SESSION['id_usuario'] = $usuario['id_usuario'];
                $_SESSION['id_cliente'] = $usuario['id_cliente'];
                $_SESSION['nome_usuario'] = $usuario['nome_usuario'];
                $_SESSION['email_usuario'] = $usuario['email_usuario'];
                $_SESSION['tipo_usuario'] = $usuario['tipo_usuario'];
                $_SESSION['status_usuario'] = $usuario['status_usuario'];
                $_SESSION['nome_cliente'] = $usuario['nome_cliente'];
                $_SESSION['status_cliente'] = $usuario['status_cliente'];
                $_SESSION['plano_cliente'] = $usuario['plano_cliente'];
                $_SESSION['limite_unidades'] = $usuario['limite_unidades'];
                $_SESSION['limite_usuarios'] = $usuario['limite_usuarios'];
                
                setMessage('success', 'Bem-vindo(a) ' . $usuario['nome_usuario'] . '!');
                header('Location: dashboard.php');
                exit;
            }
        } catch (Exception $e) {
            $erro = 'Erro ao realizar login: ' . $e->getMessage();
            error_log('Erro no login: ' . $e->getMessage());
        } catch (PDOException $e) {
            $erro = 'Erro ao realizar login. Tente novamente.';
            error_log('Erro no login: ' . $e->getMessage());
        }
    }
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gerenciador de Salas</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        /* Reset básico */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f4fb;
            padding: 20px;
            overflow: hidden;
        }

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

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 40px 36px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.6);
            transition: transform 0.2s ease;
        }
        .login-card:hover { transform: translateY(-2px); }

        .login-card .login-icon {
            font-size: 48px;
            color: #1a73e8;
            text-align: center;
            margin-bottom: 8px;
        }
        .login-card h2 {
            font-size: 26px;
            font-weight: 700;
            color: #0e1a2b;
            text-align: center;
            margin: 6px 0 2px;
        }
        .login-card .subtitle {
            text-align: center;
            color: #7a8aa0;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .login-card .form-group {
            margin-bottom: 18px;
        }
        .login-card .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1a2639;
            margin-bottom: 5px;
        }
        .login-card .form-group label i {
            color: #1a73e8;
            margin-right: 6px;
        }
        .login-card .form-group input {
            width: 100%;
            padding: 10px 16px;
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
        .login-card .form-group input:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        }
        .login-card .form-group input::placeholder { color: #9aabbf; }

        .login-card .btn {
            width: 100%;
            justify-content: center;
            padding: 12px;
            font-size: 15px;
            border-radius: 60px;
            margin-top: 6px;
            border: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .login-card .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .login-card .btn-primary:hover {
            background: #1557b0;
            transform: scale(1.02);
        }

        .login-card .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-card .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border: 1px solid #ffd6d6;
        }
        .login-card .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border: 1px solid #c8f0cf;
        }
        .login-card .alert i { font-size: 18px; }

        .login-card .footer-text {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #8a9bb5;
        }
        .login-card .footer-text i { margin-right: 4px; }

        .login-card .register-link {
            text-align: center;
            margin-top: 14px;
            font-size: 14px;
            color: #7a8aa0;
        }
        .login-card .register-link a {
            color: #1a73e8;
            text-decoration: none;
            font-weight: 600;
        }
        .login-card .register-link a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .login-card { padding: 28px 20px; }
            .login-card h2 { font-size: 22px; }
            .login-card .btn { font-size: 14px; padding: 10px; }
        }
    </style>
</head>
<body>

    <div class="bg-image">
        <img src="../IMAGENS/Predio.png" alt="Fundo do sistema">
    </div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-icon">
                <i class="fas fa-door-open"></i>
            </div>
            <h2>Gerenciador de Salas</h2>
            <p class="subtitle">Gerenciamento de Ambientes</p>

            <?php if ($erro): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($erro); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message && $message['tipo'] === 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message['mensagem']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> E-mail</label>
                    <input type="email" name="email" id="email" 
                           placeholder="seu.email@usuario.br" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="senha"><i class="fas fa-lock"></i> Senha</label>
                    <input type="password" name="senha" id="senha" 
                           placeholder="Digite sua senha" required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Entrar
                </button>
            </form>

            <div class="register-link">
                Não tem uma conta? <a href="cadastro.php">Cadastre-se gratuitamente</a>
            </div>

            <div class="footer-text">
                <i class="fas fa-shield-alt"></i> 
                Sistema protegido. Apenas usuários autorizados.
            </div>
        </div>
    </div>

</body>
</html>