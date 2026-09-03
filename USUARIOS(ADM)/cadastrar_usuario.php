<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/cadastrar_usuario.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Cadastrar novo usuário
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
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem cadastrar usuários.');
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
// 5. BUSCAR UNIDADES PARA O SELECT (APENAS ADMIN - FILTRADAS POR CLIENTE)
// ============================================================

$unidades = [];
if ($tipo_usuario === 'admin_cliente') {
    try {
        $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade");
        $stmtUnidades->execute([$id_cliente]);
        $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $unidades = [];
    }
}

// ============================================================
// 6. PROCESSAR CADASTRO (POST)
// ============================================================

$erro = '';
$sucesso = '';
$senha_provisoria = '';
$nome_cadastrado = '';
$email_cadastrado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $telefone = preg_replace('/\D/', '', $telefone);
    $tipo_usuario_novo = $_POST['tipo_usuario'] ?? '';
    
    if ($tipo_usuario === 'gerente') {
        $unidade = $id_unidade_usuario;
    } else {
        $unidade = (int)($_POST['unidade'] ?? 0);
    }

    // Validações
    if (empty($nome) || empty($email) || empty($tipo_usuario_novo) || empty($unidade) || $unidade <= 0) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif ($tipo_usuario === 'gerente' && $tipo_usuario_novo === 'admin_cliente') {
        $erro = 'Coordenadores não podem cadastrar administradores.';
    } elseif (!empty($telefone) && strlen($telefone) < 10) {
        $erro = 'Telefone inválido. Use o formato (99) 99999-9999';
    } else {
        try {
            // Verificar se o e-mail já existe para este cliente
            $check = $conn->prepare("SELECT COUNT(*) FROM usuarios_sistema WHERE email_usuario = :email AND id_cliente = :id_cliente");
            $check->execute([
                ':email' => $email,
                ':id_cliente' => $id_cliente
            ]);
            if ($check->fetchColumn() > 0) {
                $erro = 'Este e-mail já está cadastrado nesta organização.';
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
                    // Gerar senha provisória
                    $senha_provisoria = gerarSenhaProvisoria();
                    $senha_hash = password_hash($senha_provisoria, PASSWORD_DEFAULT);

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

                    $conn->beginTransaction();

                    $sqlInsert = "INSERT INTO usuarios_sistema (
                        id_cliente,
                        nome_usuario,
                        tipo_usuario,
                        id_unidade,
                        email_usuario,
                        telefone_usuario,
                        senha_usuario,
                        status_usuario,
                        data_cadastro
                    ) VALUES (
                        :id_cliente,
                        :nome,
                        :tipo,
                        :unidade,
                        :email,
                        :telefone,
                        :senha,
                        'inativo',
                        NOW()
                    )";
                    $stmtInsert = $conn->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':id_cliente' => $id_cliente,
                        ':nome' => $nome,
                        ':tipo' => $tipo_usuario_db,
                        ':unidade' => $unidade,
                        ':email' => $email,
                        ':telefone' => $telefone,
                        ':senha' => $senha_hash
                    ]);

                    $idNovoUsuario = $conn->lastInsertId();
                    $nome_cadastrado = $nome;
                    $email_cadastrado = $email;

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
                            'INSERT',
                            :dados,
                            :ip
                        )";
                        $stmtHistorico = $conn->prepare($sqlHistorico);
                        $stmtHistorico->execute([
                            ':id_funcionario' => $id_usuario_logado,
                            ':id_registro' => $idNovoUsuario,
                            ':dados' => json_encode([
                                'usuario' => $nome,
                                'email' => $email,
                                'tipo' => $tipo_usuario_novo,
                                'unidade' => $unidade
                            ]),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                        ]);
                    } catch (PDOException $e) {
                        error_log('Erro ao registrar histórico: ' . $e->getMessage());
                    }

                    $conn->commit();
                    $sucesso = 'Usuário cadastrado com sucesso!';
                }
            }
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $erro = 'Erro ao cadastrar usuário: ' . $e->getMessage();
        }
    }
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
// 8. FUNÇÃO PARA BUSCAR NOME DA UNIDADE
// ============================================================

function buscarNomeUnidade($conn, $id_unidade, $id_cliente) {
    try {
        $stmt = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
        $stmt->execute([
            ':id' => $id_unidade,
            ':id_cliente' => $id_cliente
        ]);
        $nome = $stmt->fetchColumn();
        return $nome ?: 'Unidade não definida';
    } catch (PDOException $e) {
        return 'Unidade não definida';
    }
}

// ============================================================
// 9. FUNÇÃO PARA FORMATAR TELEFONE
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

// ============================================================
// 10. TÍTULO DA PÁGINA
// ============================================================

$titulo = 'Cadastrar Usuário - Gerenciamento de Ambientes';

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
            <h1 class="page-title"><i class="fas fa-user-plus"></i> Cadastrar Usuário</h1>
            <p class="page-subtitle">Preencha os dados para cadastrar um novo usuário</p>
        </div>
        <div style="font-size: 13px; color: #7a8aa0;">
            <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
        </div>
    </header>

    <?php if ($erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <?php if ($sucesso): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sucesso); ?>
            
            <?php if ($senha_provisoria): ?>
                <div style="margin-top: 16px; padding: 16px 20px; background: #fff8e1; border-left: 6px solid #ff9800; border-radius: 8px; box-shadow: 0 2px 8px rgba(255, 152, 0, 0.15);">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <i class="fas fa-key" style="font-size: 28px; color: #ff9800;"></i>
                        <div>
                            <strong style="font-size: 15px; color: #e65100;">Senha provisória:</strong>
                            <span style="display: inline-block; margin-left: 12px; background: #ffffff; padding: 6px 18px; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 22px; font-weight: 700; letter-spacing: 2px; color: #1a237e; border: 2px dashed #ff9800;">
                                <?php echo $senha_provisoria; ?>
                            </span>
                        </div>
                    </div>
                    <p style="margin-top: 10px; margin-bottom: 0; color: #5a6a7e; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: #ff9800;"></i> 
                        Entregue esta senha ao usuário. Ele deve alterá-la no primeiro acesso.
                    </p>
                    
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 14px;">
                        <button onclick="copiarCredenciais()" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fas fa-copy"></i> Copiar Credenciais
                        </button>
                    </div>
                </div>

                <script>
                    function copiarCredenciais() {
                        const texto = 
                            'Usuário: <?php echo htmlspecialchars($nome_cadastrado); ?>\n' +
                            'E-mail: <?php echo htmlspecialchars($email_cadastrado); ?>\n' +
                            'Senha provisória: <?php echo $senha_provisoria; ?>\n\n' +
                            'Instruções:\n' +
                            '1. Acesse o sistema com seu e-mail e esta senha.\n' +
                            '2. No primeiro acesso, altere sua senha.';
                        
                        navigator.clipboard.writeText(texto).then(function() {
                            const btn = document.querySelector('button[onclick="copiarCredenciais()"]');
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
                
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- FORMULÁRIO DE CADASTRO                     -->
    <!-- ========================================== -->
    <div class="card-panel">
        <form method="POST" action="">
            <input type="hidden" name="id_cliente" value="<?php echo $id_cliente; ?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nome">Nome Completo *</label>
                    <input type="text" name="nome" id="nome" value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">E-mail *</label>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="telefone">Telefone</label>
                    <input type="text" name="telefone" id="telefone" 
                           value="<?php echo htmlspecialchars(formatarTelefone($_POST['telefone'] ?? '')); ?>" 
                           placeholder="(99) 99999-9999"
                           maxlength="17">
                </div>
                <div class="form-group">
                    <label for="tipo_usuario">Cargo *</label>
                    <select name="tipo_usuario" id="tipo_usuario" required>
                        <option value="">Selecione o cargo</option>
                        
                        <?php if ($tipo_usuario === 'admin_cliente'): ?>
                            <option value="admin_cliente" <?php echo ($_POST['tipo_usuario'] ?? '') === 'admin_cliente' ? 'selected' : ''; ?>>Administrador</option>
                        <?php endif; ?>
                        
                        <option value="coordenador" <?php echo ($_POST['tipo_usuario'] ?? '') === 'coordenador' ? 'selected' : ''; ?>>Coordenador</option>
                        <option value="professor" <?php echo ($_POST['tipo_usuario'] ?? '') === 'professor' ? 'selected' : ''; ?>>Professor</option>
                        <option value="auxiliar" <?php echo ($_POST['tipo_usuario'] ?? '') === 'auxiliar' ? 'selected' : ''; ?>>Auxiliar</option>
                        <option value="gerente" <?php echo ($_POST['tipo_usuario'] ?? '') === 'gerente' ? 'selected' : ''; ?>>Gerente</option>
                        <option value="secretaria" <?php echo ($_POST['tipo_usuario'] ?? '') === 'secretaria' ? 'selected' : ''; ?>>Secretaria</option>
                        <option value="portaria" <?php echo ($_POST['tipo_usuario'] ?? '') === 'portaria' ? 'selected' : ''; ?>>Portaria</option>
                    </select>
                    
                    <?php if ($tipo_usuario === 'gerente'): ?>
                        <small style="color: #7a8aa0;"><i class="fas fa-info-circle"></i> Coordenadores não podem cadastrar administradores.</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="unidade">Unidade *</label>
                    
                    <?php if ($tipo_usuario === 'admin_cliente'): ?>
                        <select name="unidade" id="unidade" required>
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($unidades as $unidade): ?>
                                <option value="<?php echo $unidade['id_unidade']; ?>" <?php echo ($_POST['unidade'] ?? '') == $unidade['id_unidade'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php $nomeUnidadeCoordenador = buscarNomeUnidade($conn, $id_unidade_usuario, $id_cliente); ?>
                        <input type="hidden" name="unidade" value="<?php echo $id_unidade_usuario; ?>">
                        <input type="text" 
                               value="<?php echo htmlspecialchars($nomeUnidadeCoordenador); ?>" 
                               disabled
                               style="background: #f0f4fb; color: #5a6a7e; padding: 8px 12px; border: 1px solid #e2e9f3; border-radius: 6px; width: 100%;">
                        <small style="color: #7a8aa0;"><i class="fas fa-info-circle"></i> Unidade definida automaticamente.</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions" style="margin-top: 16px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Cadastrar Usuário</button>
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