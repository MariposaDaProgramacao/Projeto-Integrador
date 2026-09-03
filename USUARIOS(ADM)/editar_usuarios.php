<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/editar_usuarios.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Editar usuário com ações de status
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

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem editar usuários.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================

$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();
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
// 5. RECEBER ID DO USUÁRIO
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do usuário inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. BUSCAR DADOS DO USUÁRIO (FILTRADO POR CLIENTE)
// ============================================================

try {
    $sql = "SELECT u.*, un.nome_unidade, un.estado_unidade 
            FROM usuarios_sistema u
            LEFT JOIN unidades un ON u.id_unidade = un.id_unidade AND un.id_cliente = u.id_cliente
            WHERE u.id_usuario = :id 
            AND u.id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        setMessage('error', 'Usuário não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    // Verificar permissão: gerente só pode editar usuários da sua unidade
    if ($tipo_usuario === 'gerente') {
        if ($usuario['id_unidade'] != $id_unidade_usuario) {
            setMessage('error', 'Você não tem permissão para editar este usuário.');
            redirect('listar_usuarios.php');
        }
    }

    // Não permitir editar administradores (apenas admin pode editar admin)
    if ($usuario['tipo_usuario'] === 'admin_cliente' && $tipo_usuario !== 'admin_cliente') {
        setMessage('error', 'Apenas administradores podem editar outros administradores.');
        redirect('listar_usuarios.php');
    }

    // Não permitir editar a si mesmo (apenas para evitar confusão)
    if ($usuario['id_usuario'] == $id_usuario_logado && isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
        // Permite editar, mas com restrições
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar usuário: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. BUSCAR UNIDADES PARA O SELECT (FILTRADAS POR CLIENTE)
// ============================================================

try {
    $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade");
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $unidades = [];
}

// ============================================================
// 8. PROCESSAR AÇÕES (POST)
// ============================================================

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $acao = $_POST['acao'] ?? '';

    // ============================================================
    // 8.1 AÇÃO: SALVAR EDIÇÃO
    // ============================================================
    if ($acao === 'salvar') {
        
        $nome = trim($_POST['nome'] ?? '');
        $tipo_usuario_novo = $_POST['tipo_usuario'] ?? '';
        $unidade = (int)($_POST['unidade'] ?? 0);
        $status_usuario = $_POST['status_usuario'] ?? 'inativo';
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $telefone = preg_replace('/\D/', '', $telefone);

        // Mapear cargo para tipo de usuário
        $tipo_mapping = [
            'admin_cliente' => 'admin_cliente',
            'coordenador' => 'gerente',
            'professor' => 'usuario',
            'auxiliar' => 'usuario',
            'gerente' => 'gerente',
            'secretaria' => 'usuario',
            'portaria' => 'usuario'
        ];
        $tipo_usuario_db = $tipo_mapping[$tipo_usuario_novo] ?? 'usuario';

        // Validar campos obrigatórios
        if (empty($nome) || empty($tipo_usuario_novo) || $unidade <= 0 || empty($email)) {
            $erro = 'Preencha todos os campos obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'E-mail inválido.';
        } elseif (!empty($telefone) && strlen($telefone) < 10) {
            $erro = 'Telefone inválido. Use o formato (XX) XXXXX-XXXX';
        } else {
            try {
                // Verificar permissão para alterar cargo
                if ($usuario['tipo_usuario'] === 'admin_cliente' && $tipo_usuario !== 'admin_cliente') {
                    $erro = 'Apenas administradores podem editar administradores.';
                } else {
                    // Verificar se a unidade pertence ao cliente
                    $checkUnidade = $conn->prepare("SELECT COUNT(*) FROM unidades WHERE id_unidade = :unidade AND id_cliente = :id_cliente");
                    $checkUnidade->execute([
                        ':unidade' => $unidade,
                        ':id_cliente' => $id_cliente
                    ]);
                    if ($checkUnidade->fetchColumn() == 0) {
                        $erro = 'Unidade inválida ou não pertence à sua organização.';
                    } else {
                        // Verificar se o e-mail já existe (exceto para o próprio usuário)
                        $check = $conn->prepare("SELECT COUNT(*) FROM usuarios_sistema 
                                                 WHERE email_usuario = :email 
                                                 AND id_cliente = :id_cliente
                                                 AND id_usuario != :id");
                        $check->execute([
                            ':email' => $email,
                            ':id_cliente' => $id_cliente,
                            ':id' => $id
                        ]);
                        if ($check->fetchColumn() > 0) {
                            $erro = 'Este e-mail já está em uso por outro usuário nesta organização.';
                        } else {
                            $conn->beginTransaction();

                            // Atualizar usuário
                            $sqlUpdate = "UPDATE usuarios_sistema SET 
                                nome_usuario = :nome,
                                tipo_usuario = :tipo,
                                id_unidade = :unidade,
                                status_usuario = :status,
                                email_usuario = :email,
                                telefone_usuario = :telefone
                            WHERE id_usuario = :id 
                            AND id_cliente = :id_cliente";
                            $stmtUpdate = $conn->prepare($sqlUpdate);
                            $stmtUpdate->execute([
                                ':nome' => $nome,
                                ':tipo' => $tipo_usuario_db,
                                ':unidade' => $unidade,
                                ':status' => $status_usuario,
                                ':email' => $email,
                                ':telefone' => $telefone,
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
                                    :id_funcionario,
                                    'usuarios_sistema',
                                    :id_registro,
                                    'UPDATE',
                                    :dados,
                                    :ip
                                )";
                                $stmtHistorico = $conn->prepare($sqlHistorico);
                                $stmtHistorico->execute([
                                    ':id_funcionario' => $id_usuario_logado,
                                    ':id_registro' => $id,
                                    ':dados' => json_encode([
                                        'usuario' => $nome,
                                        'email' => $email,
                                        'tipo_anterior' => $usuario['tipo_usuario'],
                                        'tipo_novo' => $tipo_usuario_novo,
                                        'status_anterior' => $usuario['status_usuario'],
                                        'status_novo' => $status_usuario
                                    ]),
                                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                                ]);
                            } catch (PDOException $e) {
                                error_log('Erro ao registrar histórico: ' . $e->getMessage());
                            }

                            $conn->commit();

                            $sucesso = 'Dados do usuário atualizados com sucesso!';

                            // Recarregar dados do usuário
                            $sqlReload = "SELECT u.*, un.nome_unidade, un.estado_unidade 
                                          FROM usuarios_sistema u
                                          LEFT JOIN unidades un ON u.id_unidade = un.id_unidade AND un.id_cliente = u.id_cliente
                                          WHERE u.id_usuario = :id 
                                          AND u.id_cliente = :id_cliente";
                            $stmtReload = $conn->prepare($sqlReload);
                            $stmtReload->execute([
                                ':id' => $id,
                                ':id_cliente' => $id_cliente
                            ]);
                            $usuario = $stmtReload->fetch(PDO::FETCH_ASSOC);
                        }
                    }
                }
            } catch (PDOException $e) {
                if (isset($conn) && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $erro = 'Erro ao atualizar: ' . $e->getMessage();
            }
        }
    }

    // ============================================================
    // 8.2 AÇÃO: APROVAR
    // ============================================================
    if ($acao === 'aprovar') {
        if ($usuario['status_usuario'] === 'inativo') {
            $sqlUpdate = "UPDATE usuarios_sistema 
                          SET status_usuario = 'ativo' 
                          WHERE id_usuario = :id 
                          AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $sucesso = 'Usuário aprovado com sucesso!';
            $usuario['status_usuario'] = 'ativo';
            
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
                    'usuarios_sistema',
                    :id_registro,
                    'UPDATE',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario_logado,
                    ':id_registro' => $id,
                    ':dados' => json_encode([
                        'usuario' => $usuario['nome_usuario'],
                        'status_anterior' => 'inativo',
                        'status_novo' => 'ativo',
                        'acao' => 'Aprovação'
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }
        } else {
            $erro = 'Usuário já está ativo.';
        }
    }

    // ============================================================
    // 8.3 AÇÃO: BLOQUEAR
    // ============================================================
    if ($acao === 'bloquear') {
        if ($usuario['status_usuario'] === 'ativo') {
            $sqlUpdate = "UPDATE usuarios_sistema 
                          SET status_usuario = 'bloqueado' 
                          WHERE id_usuario = :id 
                          AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $sucesso = 'Usuário bloqueado com sucesso!';
            $usuario['status_usuario'] = 'bloqueado';
            
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
                    'usuarios_sistema',
                    :id_registro,
                    'UPDATE',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario_logado,
                    ':id_registro' => $id,
                    ':dados' => json_encode([
                        'usuario' => $usuario['nome_usuario'],
                        'status_anterior' => 'ativo',
                        'status_novo' => 'bloqueado',
                        'acao' => 'Bloqueio'
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }
        } else {
            $erro = 'Apenas usuários ativos podem ser bloqueados.';
        }
    }

    // ============================================================
    // 8.4 AÇÃO: DESBLOQUEAR
    // ============================================================
    if ($acao === 'desbloquear') {
        if ($usuario['status_usuario'] === 'bloqueado') {
            $sqlUpdate = "UPDATE usuarios_sistema 
                          SET status_usuario = 'ativo' 
                          WHERE id_usuario = :id 
                          AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $sucesso = 'Usuário desbloqueado com sucesso!';
            $usuario['status_usuario'] = 'ativo';
            
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
                    'usuarios_sistema',
                    :id_registro,
                    'UPDATE',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario_logado,
                    ':id_registro' => $id,
                    ':dados' => json_encode([
                        'usuario' => $usuario['nome_usuario'],
                        'status_anterior' => 'bloqueado',
                        'status_novo' => 'ativo',
                        'acao' => 'Desbloqueio'
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }
        } else {
            $erro = 'Apenas usuários bloqueados podem ser desbloqueados.';
        }
    }

    // Recarregar dados após ações
    if (!empty($sucesso) || !empty($erro)) {
        try {
            $sqlReload = "SELECT u.*, un.nome_unidade, un.estado_unidade 
                          FROM usuarios_sistema u
                          LEFT JOIN unidades un ON u.id_unidade = un.id_unidade AND un.id_cliente = u.id_cliente
                          WHERE u.id_usuario = :id 
                          AND u.id_cliente = :id_cliente";
            $stmtReload = $conn->prepare($sqlReload);
            $stmtReload->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $usuario = $stmtReload->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Ignora
        }
    }
}

// ============================================================
// 9. DADOS DO USUÁRIO LOGADO
// ============================================================

$nomeUsuario = htmlspecialchars($_SESSION['nome_usuario'] ?? 'Usuário');

// ============================================================
// 10. TÍTULO DA PÁGINA
// ============================================================

$titulo = 'Editar Usuário - Gerenciamento de Ambientes';

// ============================================================
// 11. FUNÇÃO PARA FORMATAR TELEFONE
// ============================================================
function formatarTelefone($telefone) {
    if (empty($telefone)) return '';
    $telefone = preg_replace('/\D/', '', $telefone);
    if (strlen($telefone) === 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 5) . '-' . substr($telefone, 7);
    } elseif (strlen($telefone) === 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 4) . '-' . substr($telefone, 6);
    }
    return $telefone;
}

// Mensagens da sessão
$message = getMessage();
if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
} elseif ($message && $message['tipo'] === 'success') {
    $sucesso = $message['mensagem'];
}
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

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

    <!-- ========================================== -->
    <!-- INFORMAÇÕES DO USUÁRIO (CARD) - NOME DESTAQUE -->
    <!-- ========================================== -->
    <div class="card-panel" style="margin-bottom: 20px;">
        <!-- Cabeçalho com nome e status -->
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
                    <?php if (!empty($usuario['telefone_usuario'])): ?>
                        <span style="font-size: 14px; color: #5a6a7e;">
                            <i class="fas fa-phone" style="margin-right: 4px;"></i>
                            <?php echo htmlspecialchars(formatarTelefone($usuario['telefone_usuario'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align: right;">
                <?php 
                $status = $usuario['status_usuario'];
                $status_labels = [
                    'ativo' => ['class' => 'badge-success', 'icon' => 'fa-circle', 'text' => 'Ativo'],
                    'inativo' => ['class' => 'badge-warning', 'icon' => 'fa-clock', 'text' => 'Inativo'],
                    'bloqueado' => ['class' => 'badge-danger', 'icon' => 'fa-lock', 'text' => 'Bloqueado'],
                    'pendente' => ['class' => 'badge-warning', 'icon' => 'fa-hourglass-half', 'text' => 'Pendente']
                ];
                $label = $status_labels[$status] ?? ['class' => 'badge-secondary', 'icon' => 'fa-circle', 'text' => $status];
                ?>
                <span class="badge <?php echo $label['class']; ?>" style="font-size: 14px; padding: 6px 16px;">
                    <i class="fas <?php echo $label['icon']; ?>" style="font-size: 10px;"></i> <?php echo $label['text']; ?>
                </span>
            </div>
        </div>

        <!-- Detalhes adicionais (cargo, unidade) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
                <span style="font-size: 12px; color: #7a8aa0; text-transform: uppercase; letter-spacing: 0.5px;">Cargo</span>
                <div style="font-weight: 600; font-size: 16px;">
                    <?php 
                    $cargos = [
                        'admin_cliente' => 'Administrador',
                        'gerente' => 'Coordenador',
                        'usuario' => 'Usuário',
                        'visualizador' => 'Visualizador'
                    ];
                    echo $cargos[$usuario['tipo_usuario']] ?? ucfirst($usuario['tipo_usuario']);
                    ?>
                </div>
            </div>
            <div>
                <span style="font-size: 12px; color: #7a8aa0; text-transform: uppercase; letter-spacing: 0.5px;">Unidade</span>
                <div style="font-weight: 600; font-size: 16px;"><?php echo htmlspecialchars($usuario['nome_unidade'] ?? 'Não definida'); ?></div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- BOTÕES DE AÇÃO (APROVAR/BLOQUEAR/DESBLOQUEAR) -->
        <!-- ========================================== -->
        <div style="display: flex; flex-wrap: wrap; gap: 8px; border-top: 1px solid #edf2f9; padding-top: 16px; margin-top: 16px;">
            <?php if ($usuario['status_usuario'] === 'inativo' || $usuario['status_usuario'] === 'pendente'): ?>
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

            <!-- ========================================== -->
            <!-- BOTÃO EXCLUIR (APENAS ADMIN)              -->
            <!-- ========================================== -->
            <?php if ($tipo_usuario === 'admin_cliente' && $usuario['tipo_usuario'] !== 'admin_cliente' && $usuario['id_usuario'] != $id_usuario_logado): ?>
                <a href="excluir_usuario.php?id=<?php echo $usuario['id_usuario']; ?>" 
                   class="btn btn-danger"
                   onclick="return confirm('ATENÇÃO: Esta ação é irreversível!\nDeseja realmente excluir o usuário <?php echo htmlspecialchars($usuario['nome_usuario']); ?>?')">
                    <i class="fas fa-trash"></i> Excluir Usuário
                </a>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- BOTÃO REDEFINIR SENHA (APENAS ADMIN)      -->
            <!-- ========================================== -->
            <?php if ($tipo_usuario === 'admin_cliente' && $usuario['id_usuario'] != $id_usuario_logado): ?>
                <a href="resetar_senha_do_usuario.php?id=<?php echo $usuario['id_usuario']; ?>" 
                   class="btn" 
                   style="background: #e67e22; color: #fff; border-color: #d35400;"
                   onclick="return confirm('ATENÇÃO: Isso irá gerar uma nova senha provisória para o usuário.\nA senha atual será substituída.\nDeseja continuar?')">
                    <i class="fas fa-key"></i> Redefinir Senha
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- FORMULÁRIO DE EDIÇÃO                      -->
    <!-- ========================================== -->
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
                    <label for="tipo_usuario">Cargo *</label>
                    <select name="tipo_usuario" id="tipo_usuario" required>
                        <option value="">Selecione o cargo</option>
                        
                        <?php if ($tipo_usuario === 'admin_cliente'): ?>
                            <option value="admin_cliente" <?php echo $usuario['tipo_usuario'] === 'admin_cliente' ? 'selected' : ''; ?>>Administrador</option>
                        <?php endif; ?>
                        
                        <option value="coordenador" <?php echo $usuario['tipo_usuario'] === 'gerente' ? 'selected' : ''; ?>>Coordenador</option>
                        <option value="professor" <?php echo $usuario['tipo_usuario'] === 'usuario' && strpos($usuario['tipo_usuario_original'] ?? '', 'professor') !== false ? 'selected' : ''; ?>>Professor</option>
                        <option value="auxiliar" <?php echo $usuario['tipo_usuario'] === 'usuario' && strpos($usuario['tipo_usuario_original'] ?? '', 'auxiliar') !== false ? 'selected' : ''; ?>>Auxiliar</option>
                        <option value="gerente" <?php echo $usuario['tipo_usuario'] === 'gerente' ? 'selected' : ''; ?>>Gerente</option>
                        <option value="secretaria" <?php echo $usuario['tipo_usuario'] === 'usuario' && strpos($usuario['tipo_usuario_original'] ?? '', 'secretaria') !== false ? 'selected' : ''; ?>>Secretaria</option>
                        <option value="portaria" <?php echo $usuario['tipo_usuario'] === 'usuario' && strpos($usuario['tipo_usuario_original'] ?? '', 'portaria') !== false ? 'selected' : ''; ?>>Portaria</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="unidade">Unidade *</label>
                    <?php if ($tipo_usuario === 'admin_cliente'): ?>
                        <select name="unidade" id="unidade" required>
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($unidades as $unidade): ?>
                                <option value="<?php echo $unidade['id_unidade']; ?>" <?php echo $unidade['id_unidade'] == $usuario['id_unidade'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="unidade" value="<?php echo $id_unidade_usuario; ?>">
                        <input type="text" 
                               value="<?php echo htmlspecialchars($usuario['nome_unidade'] ?? 'Unidade não definida'); ?>" 
                               disabled
                               style="background: #f0f4fb; color: #5a6a7e; padding: 8px 12px; border: 1px solid #e2e9f3; border-radius: 6px; width: 100%;">
                        <small style="color: #7a8aa0;"><i class="fas fa-info-circle"></i> Unidade definida automaticamente.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="status_usuario">Status de Acesso</label>
                    <select name="status_usuario" id="status_usuario">
                        <option value="ativo" <?php echo $usuario['status_usuario'] === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $usuario['status_usuario'] === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        <option value="bloqueado" <?php echo $usuario['status_usuario'] === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                        <option value="pendente" <?php echo $usuario['status_usuario'] === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                    </select>
                    <small>Alterar manualmente o status (use os botões acima para ações rápidas).</small>
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

<!-- ========================================== -->
<!-- SCRIPT DA MÁSCARA DE TELEFONE (JavaScript Puro) -->
<!-- ========================================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var telefoneInput = document.getElementById('telefone');
    
    telefoneInput.addEventListener('input', function(e) {
        var value = this.value.replace(/\D/g, '');
        var formatted = '';
        
        if (value.length > 0) {
            if (value.length <= 2) {
                formatted = '(' + value;
            } else if (value.length <= 6) {
                formatted = '(' + value.substring(0, 2) + ') ' + value.substring(2);
            } else {
                formatted = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7, 11);
            }
            
            if (value.length >= 11) {
                formatted = formatted.substring(0, 17);
            }
        }
        
        this.value = formatted;
    });
    
    telefoneInput.addEventListener('blur', function() {
        var numeros = this.value.replace(/\D/g, '');
        if (numeros.length > 0 && numeros.length < 10) {
            this.style.borderColor = '#ff6b6b';
            this.style.borderWidth = '2px';
            this.style.borderStyle = 'solid';
        } else {
            this.style.borderColor = '';
            this.style.borderWidth = '';
            this.style.borderStyle = '';
        }
    });
    
    telefoneInput.addEventListener('focus', function() {
        this.style.borderColor = '';
        this.style.borderWidth = '';
        this.style.borderStyle = '';
    });
});
</script>

</body>
</html>