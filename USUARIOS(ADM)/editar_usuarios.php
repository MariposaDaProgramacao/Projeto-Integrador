<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/editar_usuarios.php (MULTI-UNIDADES)
// FUNÇÃO: Editar usuário do sistema
// ============================================================

// ============================================================
// 1. INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR LOGIN E PERMISSÃO
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem editar usuários.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// 3. VARIÁVEIS DO SISTEMA
// ============================================================
$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// Se não tiver unidade definida, buscar a primeira
if ($id_unidade_usuario == 0 || $id_unidade_usuario === null) {
    try {
        $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
        $stmt->execute([$id_cliente]);
        $unidade = $stmt->fetch();
        if ($unidade) {
            $id_unidade_usuario = $unidade['id_unidade'];
            $_SESSION['usuario_unidade'] = $id_unidade_usuario;
        }
    } catch (PDOException $e) {}
}

// ============================================================
// 4. RECEBER ID DO USUÁRIO E BUSCAR DADOS
// ============================================================
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do usuário inválido.');
    redirect('listar_usuarios.php');
}

try {
    // Busca na tabela NOVA
    $sql = "SELECT * FROM usuarios_sistema WHERE id_usuario = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':id_cliente' => $id_cliente]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        setMessage('error', 'Usuário não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    // Buscar as unidades atuais desse usuário para pré-selecionar no formulário
    $stmtMinhasUnidades = $conn->prepare("SELECT id_unidade FROM usuarios_unidades WHERE id_usuario = :id_usuario");
    $stmtMinhasUnidades->execute([':id_usuario' => $id]);
    $unidades_do_usuario = $stmtMinhasUnidades->fetchAll(PDO::FETCH_COLUMN);

    // Verificar permissão: gerente só pode editar se o usuário for da mesma unidade
    if ($tipo_usuario === 'gerente') {
        if (!in_array($id_unidade_usuario, $unidades_do_usuario)) {
            setMessage('error', 'Você não tem permissão para editar este usuário.');
            redirect('listar_usuarios.php');
        }
    }

    // Não permitir editar administradores (apenas admin pode editar admin)
    if ($usuario['tipo_usuario'] === 'admin_cliente' && $tipo_usuario !== 'admin_cliente') {
        setMessage('error', 'Apenas administradores podem editar outros administradores.');
        redirect('listar_usuarios.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar usuário: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 5. BUSCAR TODAS AS UNIDADES PARA O SELECT
// ============================================================
try {
    $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade");
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $unidades = [];
}

// ============================================================
// 6. PROCESSAR AÇÕES (POST)
// ============================================================
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ------------------------------------------------------------
    // 6.1 AÇÃO: SALVAR EDIÇÃO
    // ------------------------------------------------------------
    if ($acao === 'salvar') {
        $nome = trim($_POST['nome'] ?? '');
        $cargo = $_POST['cargo'] ?? '';
        $status_acesso = $_POST['status_acesso'] ?? 'inativo';
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $telefone = preg_replace('/\D/', '', $telefone);

        // Receber múltiplas unidades
        if ($tipo_usuario === 'gerente') {
            $unidades_selecionadas = [$id_unidade_usuario]; // Gerente só salva a própria
        } else {
            $unidades_selecionadas = $_POST['unidades'] ?? [];
        }

        // Validações
        if (empty($nome) || empty($cargo) || empty($email) || empty($unidades_selecionadas)) {
            $erro = 'Preencha todos os campos obrigatórios (incluindo ao menos uma unidade).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'E-mail inválido.';
        } elseif (!empty($telefone) && strlen($telefone) < 10) {
            $erro = 'Telefone inválido. Use o formato (XX) XXXXX-XXXX';
        } else {
            try {
                // Mapear o cargo do HTML para o banco
                $tipo_mapping = [
                    'admin_cliente' => 'admin_cliente',
                    'coordenador' => 'gerente',
                    'professor' => 'usuario',
                    'auxiliar' => 'usuario',
                    'gerente' => 'gerente',
                    'secretaria' => 'secretaria',
                    'portaria' => 'portaria'
                ];
                $tipo_usuario_db = $tipo_mapping[$cargo] ?? 'usuario';

                // Verificar se o e-mail já existe em OUTRO usuário
                $check = $conn->prepare("SELECT COUNT(*) FROM usuarios_sistema WHERE email_usuario = :email AND id_cliente = :id_cliente AND id_usuario != :id");
                $check->execute([':email' => $email, ':id_cliente' => $id_cliente, ':id' => $id]);
                
                if ($check->fetchColumn() > 0) {
                    $erro = 'Este e-mail já está em uso por outro usuário nesta organização.';
                } else {
                    $conn->beginTransaction();

                    // Atualiza a tabela usuarios_sistema (sem a coluna id_unidade)
                    $sqlUpdate = "UPDATE usuarios_sistema SET 
                        nome_usuario = :nome,
                        tipo_usuario = :tipo,
                        status_usuario = :status,
                        email_usuario = :email,
                        telefone_usuario = :telefone
                    WHERE id_usuario = :id AND id_cliente = :id_cliente";
                    
                    $stmtUpdate = $conn->prepare($sqlUpdate);
                    $stmtUpdate->execute([
                        ':nome' => $nome,
                        ':tipo' => $tipo_usuario_db,
                        ':status' => $status_acesso,
                        ':email' => $email,
                        ':telefone' => $telefone,
                        ':id' => $id,
                        ':id_cliente' => $id_cliente
                    ]);

                    // Atualizar as unidades na tabela de ligação
                    // 1. Apaga os vínculos antigos
                    $conn->prepare("DELETE FROM usuarios_unidades WHERE id_usuario = ?")->execute([$id]);
                    
                    // 2. Insere os novos vínculos
                    $stmtVinculo = $conn->prepare("INSERT INTO usuarios_unidades (id_usuario, id_unidade) VALUES (:id_usuario, :id_unidade)");
                    foreach ($unidades_selecionadas as $id_unidade_sel) {
                        $stmtVinculo->execute([':id_usuario' => $id, ':id_unidade' => $id_unidade_sel]);
                    }

                    // Registrar no histórico
                    registrarHistorico($conn, $id_usuario_logado, 'usuarios_sistema', $id, 'UPDATE', null, [
                        'usuario' => $nome,
                        'email' => $email,
                        'tipo' => $tipo_usuario_db,
                        'unidades' => $unidades_selecionadas
                    ]);

                    $conn->commit();
                    $sucesso = 'Dados do usuário atualizados com sucesso!';

                    // Recarregar os dados atualizados
                    $stmtReload = $conn->prepare("SELECT * FROM usuarios_sistema WHERE id_usuario = :id");
                    $stmtReload->execute([':id' => $id]);
                    $usuario = $stmtReload->fetch(PDO::FETCH_ASSOC);

                    $stmtMinhasUnidades->execute([':id_usuario' => $id]);
                    $unidades_do_usuario = $stmtMinhasUnidades->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (PDOException $e) {
                if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
                $erro = 'Erro ao atualizar: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------
    // 6.2 AÇÕES RÁPIDAS (APROVAR, BLOQUEAR, DESBLOQUEAR)
    // ------------------------------------------------------------
    if (in_array($acao, ['aprovar', 'bloquear', 'desbloquear'])) {
        $novo_status = '';
        if ($acao === 'aprovar' && $usuario['status_usuario'] === 'inativo') {
            $novo_status = 'ativo';
        } elseif ($acao === 'bloquear' && $usuario['status_usuario'] === 'ativo') {
            $novo_status = 'bloqueado';
        } elseif ($acao === 'desbloquear' && $usuario['status_usuario'] === 'bloqueado') {
            $novo_status = 'ativo';
        }

        if ($novo_status) {
            $stmtUpdate = $conn->prepare("UPDATE usuarios_sistema SET status_usuario = :status WHERE id_usuario = :id AND id_cliente = :id_cliente");
            $stmtUpdate->execute([':status' => $novo_status, ':id' => $id, ':id_cliente' => $id_cliente]);
            
            $usuario['status_usuario'] = $novo_status;
            $sucesso = "Status alterado para {$novo_status} com sucesso!";

            registrarHistorico($conn, $id_usuario_logado, 'usuarios_sistema', $id, 'UPDATE', null, ['status_novo' => $novo_status]);
        } else {
            $erro = 'Ação inválida para o status atual do usuário.';
        }
    }
}

// ============================================================
// 7. TÍTULO DA PÁGINA
// ============================================================
$titulo = 'Editar Usuário - Gerenciamento de Ambientes';

$message = getMessage();
if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
} elseif ($message && $message['tipo'] === 'success') {
    $sucesso = $message['mensagem'];
}
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<link rel="stylesheet" href="usuarios_adm.css">

<main class="main">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-user-edit"></i> Editar Usuário</h1>
            <p class="page-subtitle">Edite as informações do usuário selecionado</p>
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

    <!-- INFORMAÇÕES DO USUÁRIO -->
    <div class="card-panel" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px; border-bottom: 2px solid #edf2f9; padding-bottom: 16px;">
            <i class="fas fa-user-circle" style="font-size: 48px; color: #0e1a2b; background: #f0f4fb; padding: 8px; border-radius: 50%;"></i>
            <div style="flex: 1;">
                <h2 style="margin: 0; font-size: 24px; color: #0e1a2b; font-weight: 700;">
                    <?php echo htmlspecialchars($usuario['nome_usuario']); ?>
                </h2>
                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 4px;">
                    <span style="font-size: 14px; color: #5a6a7e;">
                        <i class="fas fa-envelope" style="margin-right: 4px;"></i>
                        <?php echo htmlspecialchars($usuario['email_usuario']); ?>
                    </span>
                </div>
            </div>
            <div style="text-align: right;">
                <?php 
                $status = $usuario['status_usuario'];
                $status_labels = [
                    'ativo' => ['class' => 'badge-success', 'icon' => 'fa-circle', 'text' => 'Ativo'],
                    'inativo' => ['class' => 'badge-warning', 'icon' => 'fa-clock', 'text' => 'Inativo'],
                    'bloqueado' => ['class' => 'badge-danger', 'icon' => 'fa-lock', 'text' => 'Bloqueado']
                ];
                $label = $status_labels[$status] ?? ['class' => 'badge-secondary', 'icon' => 'fa-circle', 'text' => $status];
                ?>
                <span class="badge <?php echo $label['class']; ?>" style="font-size: 14px; padding: 6px 16px;">
                    <i class="fas <?php echo $label['icon']; ?>" style="font-size: 10px;"></i> <?php echo $label['text']; ?>
                </span>
            </div>
        </div>

        <!-- BOTÕES DE AÇÃO RÁPIDA -->
        <div style="display: flex; flex-wrap: wrap; gap: 8px; border-top: 1px solid #edf2f9; padding-top: 16px; margin-top: 16px;">
            <?php if ($usuario['status_usuario'] === 'inativo'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente aprovar este usuário?')">
                    <input type="hidden" name="acao" value="aprovar">
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Aprovar Usuário</button>
                </form>
            <?php endif; ?>

            <?php if ($usuario['status_usuario'] === 'ativo'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente bloquear este usuário?')">
                    <input type="hidden" name="acao" value="bloquear">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Bloquear Usuário</button>
                </form>
            <?php endif; ?>

            <?php if ($usuario['status_usuario'] === 'bloqueado'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente desbloquear este usuário?')">
                    <input type="hidden" name="acao" value="desbloquear">
                    <button type="submit" class="btn btn-success"><i class="fas fa-unlock"></i> Desbloquear Usuário</button>
                </form>
            <?php endif; ?>

            <?php if ($tipo_usuario === 'admin_cliente' && $usuario['tipo_usuario'] !== 'admin_cliente' && $usuario['id_usuario'] != $id_usuario_logado): ?>
                <a href="excluir_usuario.php?id=<?php echo $usuario['id_usuario']; ?>" 
                   class="btn btn-danger"
                   onclick="return confirm('ATENÇÃO: Esta ação é irreversível!\nDeseja realmente excluir o usuário <?php echo htmlspecialchars($usuario['nome_usuario']); ?>?')">
                    <i class="fas fa-trash"></i> Excluir Usuário
                </a>
            <?php endif; ?>

            <?php if ($tipo_usuario === 'admin_cliente' && $usuario['id_usuario'] != $id_usuario_logado): ?>
                <a href="resetar_senha_do_usuario.php?id=<?php echo $usuario['id_usuario']; ?>" 
                   class="btn" 
                   style="background: #e67e22; color: #fff; border-color: #d35400;"
                   onclick="return confirm('Isso irá gerar uma nova senha provisória.\nDeseja continuar?')">
                    <i class="fas fa-key"></i> Redefinir Senha
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- FORMULÁRIO DE EDIÇÃO -->
    <div class="card-panel">
        <h3 style="margin-bottom: 16px; font-size: 16px; color: #0e1a2b;">
            <i class="fas fa-edit"></i> Editar Dados
        </h3>
        <form method="POST" action="">
            <input type="hidden" name="acao" value="salvar">

            <div class="form-row">
                <div class="form-group">
                    <label for="nome">Nome Completo *</label>
                    <input type="text" name="nome" id="nome" value="<?php echo htmlspecialchars($usuario['nome_usuario']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">E-mail *</label>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($usuario['email_usuario']); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="telefone">Telefone</label>
                    <input type="text" name="telefone" id="telefone" 
                           value="<?php echo htmlspecialchars(formatarTelefone($usuario['telefone_usuario'] ?? '')); ?>" 
                           placeholder="(XX) XXXXX-XXXX"
                           maxlength="17">
                </div>
                <div class="form-group">
                    <label for="cargo">Cargo *</label>
                    <select name="cargo" id="cargo" required>
                        <option value="">Selecione o cargo</option>
                        <?php if ($tipo_usuario === 'admin_cliente'): ?>
                            <option value="admin_cliente" <?php echo $usuario['tipo_usuario'] === 'admin_cliente' ? 'selected' : ''; ?>>Administrador</option>
                        <?php endif; ?>
                        <option value="coordenador" <?php echo $usuario['tipo_usuario'] === 'gerente' ? 'selected' : ''; ?>>Coordenador</option>
                        <option value="professor" <?php echo $usuario['tipo_usuario'] === 'usuario' ? 'selected' : ''; ?>>Professor</option>
                        <option value="secretaria" <?php echo $usuario['tipo_usuario'] === 'secretaria' ? 'selected' : ''; ?>>Secretaria</option>
                        <option value="portaria" <?php echo $usuario['tipo_usuario'] === 'portaria' ? 'selected' : ''; ?>>Portaria</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="width: 100%;">
                    <label for="unidade">Unidade(s) *</label>
                    <?php if ($tipo_usuario === 'admin_cliente'): ?>
                        <!-- Select multiple para Admin -->
                        <select name="unidades[]" id="unidade" multiple required style="height: 120px; padding: 8px;">
                            <option disabled style="color: #7a8aa0;">Segure a tecla Ctrl (Windows) ou Command (Mac) para selecionar várias</option>
                            <?php foreach ($unidades as $u): ?>
                                <?php $selected = in_array($u['id_unidade'], $unidades_do_usuario) ? 'selected' : ''; ?>
                                <option value="<?php echo $u['id_unidade']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($u['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <!-- Gerente só vê a própria unidade -->
                        <?php 
                            $nome_unidade_gerente = 'Unidade não definida';
                            foreach ($unidades as $u) {
                                if ($u['id_unidade'] == $id_unidade_usuario) {
                                    $nome_unidade_gerente = $u['nome_unidade'];
                                    break;
                                }
                            }
                        ?>
                        <input type="hidden" name="unidades[]" value="<?php echo $id_unidade_usuario; ?>">
                        <input type="text" value="<?php echo htmlspecialchars($nome_unidade_gerente); ?>" disabled
                               style="background: #f0f4fb; color: #5a6a7e; padding: 8px 12px; border: 1px solid #e2e9f3; border-radius: 6px; width: 100%;">
                        <small style="color: #7a8aa0;"><i class="fas fa-info-circle"></i> Unidade definida automaticamente.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="width: 100%;">
                    <label for="status_acesso">Status de Acesso</label>
                    <select name="status_acesso" id="status_acesso">
                        <option value="ativo" <?php echo $usuario['status_usuario'] === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $usuario['status_usuario'] === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        <option value="bloqueado" <?php echo $usuario['status_usuario'] === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                    </select>
                </div>
            </div>

            <div class="form-actions" style="margin-top: 16px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Alterações</button>
                <a href="listar_usuarios.php" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var telefoneInput = document.getElementById('telefone');
    if (telefoneInput) {
        telefoneInput.addEventListener('input', function(e) {
            var value = this.value.replace(/\D/g, '');
            var formatted = '';
            if (value.length > 0) {
                if (value.length <= 2) formatted = '(' + value;
                else if (value.length <= 6) formatted = '(' + value.substring(0, 2) + ') ' + value.substring(2);
                else formatted = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7, 11);
                if (value.length >= 11) formatted = formatted.substring(0, 17);
            }
            this.value = formatted;
        });
    }
});
</script>
</body>
</html>