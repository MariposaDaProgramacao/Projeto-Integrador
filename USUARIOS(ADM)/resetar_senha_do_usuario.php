<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/redefinir_senha_usuario.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Redefinir senha do usuário
// ============================================================

// ============================================================
// 1. INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 3. VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================

$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Apenas administradores podem redefinir senhas.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================

$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';

// ============================================================
// 5. VALIDAR ID DO USUÁRIO
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. BUSCAR DADOS DO USUÁRIO (COM VALIDAÇÃO DE CLIENTE)
// ============================================================

$usuario = null;
$erro = '';

try {
    $stmt = $conn->prepare("
        SELECT u.id_usuario, u.nome_usuario, u.email_usuario, u.tipo_usuario
        FROM usuarios_sistema u
        WHERE u.id_usuario = :id
        AND u.id_cliente = :id_cliente
        AND u.tipo_usuario != 'admin_cliente'
    ");
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        setMessage('error', 'Usuário não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }
    
    // Verificar se o usuário é admin_cliente (não pode redefinir senha de outro admin)
    if ($usuario['tipo_usuario'] === 'admin_cliente') {
        setMessage('error', 'Não é possível redefinir a senha de outro administrador.');
        redirect('listar_usuarios.php');
    }
    
} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar usuário: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. FUNÇÃO PARA GERAR SENHA PROVISÓRIA
// ============================================================

function gerarSenhaProvisoria($tamanho = 8) {
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $senha = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $senha .= $caracteres[random_int(0, strlen($caracteres) - 1)];
    }
    return $senha;
}

// ============================================================
// 8. PROCESSAR REDEFINIÇÃO DE SENHA (POST)
// ============================================================

$novaSenha = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    $novaSenha = gerarSenhaProvisoria();
    $senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);

    try {
        $conn->beginTransaction();

        // Atualizar senha
        $sqlUpdate = "UPDATE usuarios_sistema SET senha_usuario = :senha WHERE id_usuario = :id AND id_cliente = :id_cliente";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':senha' => $senhaHash,
            ':id' => $id,
            ':id_cliente' => $id_cliente
        ]);

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
                :id_admin,
                'usuarios_sistema',
                :id_user,
                'UPDATE',
                :dados,
                :ip
            )";
            $stmtHist = $conn->prepare($sqlHistorico);
            $stmtHist->execute([
                ':id_admin' => $id_usuario_logado,
                ':id_user' => $id,
                ':dados' => json_encode([
                    'acao' => 'Redefinição de senha',
                    'usuario' => $usuario['nome_usuario'],
                    'email' => $usuario['email_usuario']
                ]),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
        } catch (PDOException $e) {
            error_log('Erro ao registrar histórico: ' . $e->getMessage());
        }

        $conn->commit();
        $sucesso = 'Senha redefinida com sucesso!';

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $erro = 'Erro ao redefinir senha: ' . $e->getMessage();
    }
}

// ============================================================
// 9. MENSAGENS DA SESSÃO
// ============================================================

$mensagem_erro = '';
$mensagem_sucesso = '';

$message = getMessage();
if ($message) {
    if ($message['tipo'] === 'error') {
        $mensagem_erro = $message['mensagem'];
    } elseif ($message['tipo'] === 'success') {
        $mensagem_sucesso = $message['mensagem'];
    }
}

// Se tiver erro do POST, sobrescreve
if (!empty($erro)) {
    $mensagem_erro = $erro;
}
if (!empty($sucesso)) {
    $mensagem_sucesso = $sucesso;
}

// ============================================================
// 10. TÍTULO DA PÁGINA
// ============================================================

$titulo = 'Redefinir Senha - Gerenciamento de Ambientes';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<main class="main">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-key"></i> Redefinir Senha</h1>
            <p class="page-subtitle">Gerar nova senha provisória para <?php echo htmlspecialchars($usuario['nome_usuario']); ?></p>
        </div>
        <div style="font-size: 13px; color: #7a8aa0;">
            <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
        </div>
    </header>

    <?php if ($mensagem_erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensagem_erro); ?></div>
    <?php endif; ?>

    <?php if ($mensagem_sucesso && $novaSenha): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensagem_sucesso); ?>
            
            <div style="margin-top: 16px; padding: 16px 20px; background: #fff8e1; border-left: 6px solid #ff9800; border-radius: 8px; box-shadow: 0 2px 8px rgba(255, 152, 0, 0.15);">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <i class="fas fa-key" style="font-size: 28px; color: #ff9800;"></i>
                    <div>
                        <strong style="font-size: 15px; color: #e65100;">Nova senha provisória:</strong>
                        <span style="display: inline-block; margin-left: 12px; background: #ffffff; padding: 6px 18px; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 22px; font-weight: 700; letter-spacing: 2px; color: #1a237e; border: 2px dashed #ff9800;">
                            <?php echo $novaSenha; ?>
                        </span>
                    </div>
                </div>
                <p style="margin-top: 10px; margin-bottom: 0; color: #5a6a7e; font-size: 14px;">
                    <i class="fas fa-info-circle" style="color: #ff9800;"></i> 
                    Entregue esta senha ao usuário. Ele deve alterá-la no primeiro acesso.
                </p>
                
                <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 14px;">
                    <button onclick="copiarSenha()" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-copy"></i> Copiar Senha
                    </button>
                </div>
            </div>

            <script>
                function copiarSenha() {
                    const texto = 
                        'Usuário: <?php echo htmlspecialchars($usuario['nome_usuario']); ?>\n' +
                        'E-mail: <?php echo htmlspecialchars($usuario['email_usuario']); ?>\n' +
                        'Nova senha provisória: <?php echo $novaSenha; ?>\n\n' +
                        'Instruções:\n' +
                        '1. Acesse o sistema com seu e-mail e esta senha.\n' +
                        '2. No primeiro acesso, altere sua senha.';
                    
                    navigator.clipboard.writeText(texto).then(function() {
                        const btn = document.querySelector('button[onclick="copiarSenha()"]');
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                        }, 2000);
                    }).catch(function() {
                        alert('Copie manualmente as informações abaixo:\n\n' + texto);
                    });
                }
            </script>
        </div>
        
        <p style="margin-top: 20px;">
            <a href="editar_usuario.php?id=<?php echo $id; ?>" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Voltar para edição
            </a>
        </p>
        
    <?php else: ?>
        <div class="card-panel">
            <div style="background: #fff3cd; border-left: 6px solid #ffc107; padding: 16px 20px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 10px;"></i>
                <strong style="color: #856404;">Atenção:</strong>
                <span style="color: #856404;">Ao redefinir a senha, o usuário perderá o acesso à senha atual e precisará usar a nova senha provisória que será gerada.</span>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="confirmar" value="1">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="font-weight: 600; font-size: 14px; color: #5a6a7e;">Usuário</label>
                        <p style="padding: 8px 0; font-size: 15px;"><strong><?php echo htmlspecialchars($usuario['nome_usuario']); ?></strong></p>
                    </div>
                    <div>
                        <label style="font-weight: 600; font-size: 14px; color: #5a6a7e;">E-mail</label>
                        <p style="padding: 8px 0; font-size: 15px;"><?php echo htmlspecialchars($usuario['email_usuario']); ?></p>
                    </div>
                </div>
                
                <div style="background: #e8f0fe; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
                    <i class="fas fa-info-circle" style="color: #1a73e8;"></i>
                    <span style="color: #1a2639; font-size: 14px;">
                        A nova senha terá <strong>8 caracteres</strong> e será composta por letras maiúsculas e números.
                    </span>
                </div>
                
                <div class="form-actions" style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Tem certeza que deseja redefinir a senha de <?php echo htmlspecialchars($usuario['nome_usuario']); ?>?')">
                        <i class="fas fa-sync-alt"></i> Gerar Nova Senha
                    </button>
                    <a href="editar_usuario.php?id=<?php echo $id; ?>" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>
</body>
</html>