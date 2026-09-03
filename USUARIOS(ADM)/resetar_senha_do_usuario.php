<?php
// ==========================================================
// redefinir_senha_usuario.php - Redefinir senha do usuário
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../AUTENTIFICACAO_ACESSO/realizar_login.php');
    exit;
}

if ($_SESSION['usuario_cargo'] !== 'administrador') {
    $_SESSION['erro'] = 'Apenas administradores podem redefinir senhas.';
    header('Location: listar_usuarios.php');
    exit;
}

$caminhoBanco = __DIR__ . '/../conexao_banco.php';
if (!file_exists($caminhoBanco)) {
    die('Arquivo de conexão não encontrado.');
}
require_once $caminhoBanco;
if (!isset($pdo)) {
    die('Erro: conexão com banco não estabelecida.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['erro'] = 'ID inválido.';
    header('Location: listar_usuarios.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id_funcionario, nome_funcionario, email_funcionario FROM funcionarios WHERE id_funcionario = :id");
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        $_SESSION['erro'] = 'Usuário não encontrado.';
        header('Location: listar_usuarios.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['erro'] = 'Erro ao buscar usuário: ' . $e->getMessage();
    header('Location: listar_usuarios.php');
    exit;
}

function gerarSenhaProvisoria($tamanho = 8) {
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $senha = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $senha .= $caracteres[random_int(0, strlen($caracteres) - 1)];
    }
    return $senha;
}

$novaSenha = '';
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    $novaSenha = gerarSenhaProvisoria();
    $senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);

    try {
        $sqlUpdate = "UPDATE funcionarios SET senha_funcionario = :senha WHERE id_funcionario = :id";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([':senha' => $senhaHash, ':id' => $id]);

        try {
            $sqlHistorico = "INSERT INTO historico_sistema (id_funcionario, tabela_afetada, id_registro_afetado, acao, motivo, ip_origem)
                             VALUES (:id_admin, 'funcionarios', :id_user, 'UPDATE', 'Redefinição de senha do usuário ' . :nome, :ip)";
            $stmtHist = $pdo->prepare($sqlHistorico);
            $stmtHist->execute([
                ':id_admin' => $_SESSION['usuario_id'],
                ':id_user' => $id,
                ':nome' => $usuario['nome_funcionario'],
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
        } catch (PDOException $e) {
            error_log('Erro ao registrar histórico: ' . $e->getMessage());
        }

        $sucesso = 'Senha redefinida com sucesso!';

    } catch (PDOException $e) {
        $erro = 'Erro ao redefinir senha: ' . $e->getMessage();
    }
}

$titulo = 'Redefinir Senha - Gerenciamento de Ambientes';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<main class="main">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-key"></i> Redefinir Senha</h1>
            <p class="page-subtitle">Gerar nova senha provisória para <?php echo htmlspecialchars($usuario['nome_funcionario']); ?></p>
        </div>
        
    </header>

    <?php if ($erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $erro; ?></div>
    <?php endif; ?>

    <?php if ($sucesso && $novaSenha): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $sucesso; ?>
            
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
                        'Usuário: <?php echo htmlspecialchars($usuario['nome_funcionario']); ?>\n' +
                        'E-mail: <?php echo htmlspecialchars($usuario['email_funcionario']); ?>\n' +
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
            <a href="editar_usuarios.php?id=<?php echo $id; ?>" class="btn btn-primary">
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
                <p><strong>Usuário:</strong> <?php echo htmlspecialchars($usuario['nome_funcionario']); ?></p>
                <p><strong>E-mail:</strong> <?php echo htmlspecialchars($usuario['email_funcionario']); ?></p>
                
                <div class="form-actions" style="margin-top: 16px;">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Tem certeza que deseja redefinir a senha de <?php echo htmlspecialchars($usuario['nome_funcionario']); ?>?')">
                        <i class="fas fa-sync-alt"></i> Gerar Nova Senha
                    </button>
                    <a href="editar_usuarios.php?id=<?php echo $id; ?>" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>
</body>
</html>