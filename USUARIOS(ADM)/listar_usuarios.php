<head>
    <link rel="stylesheet" href="usuarios_adm.css">
    <link rel="stylesheet" href="usuarios_adm_responsivo.css">
</head>

<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/listar_usuarios.php
// FUNÇÃO: Listagem de usuários do sistema com múltiplas unidades
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
    exit;
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
if (!in_array($tipo_usuario, $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e gerentes podem acessar.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
    exit;
}

// ============================================================
// 3. VARIÁVEIS DO SISTEMA
// ============================================================

$id_cliente = $_SESSION['id_cliente'] ?? 0;
$id_usuario_logado = $_SESSION['id_usuario'] ?? 0;
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// Se não tiver unidade definida, buscar a primeira
if (empty($id_unidade_usuario)) {
    try {
        $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
        $stmt->execute([$id_cliente]);
        $unidade = $stmt->fetch();
        $id_unidade_usuario = $unidade ? $unidade['id_unidade'] : 0;
        $_SESSION['usuario_unidade'] = $id_unidade_usuario;
    } catch (PDOException $e) {
        $id_unidade_usuario = 0;
    }
}

// ============================================================
// 4. FILTROS E PAGINAÇÃO
// ============================================================

$busca = $_GET['busca'] ?? '';
$statusFiltro = $_GET['status'] ?? '';
$cargoFiltro = $_GET['cargo'] ?? '';
$unidadeFiltro = $_GET['unidade'] ?? '';
$estadoFiltro = $_GET['estado'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 5;
$offset = ($pagina - 1) * $limite;

// ============================================================
// 5. BUSCAR DADOS PARA FILTROS
// ============================================================

$unidades = [];
$estados = [];
$cargos = [];

try {
    $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade, estado_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade");
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtEstados = $conn->prepare("SELECT DISTINCT estado_unidade FROM unidades WHERE id_cliente = ? ORDER BY estado_unidade");
    $stmtEstados->execute([$id_cliente]);
    $estados = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);
    
    $stmtCargos = $conn->prepare("SELECT DISTINCT tipo_usuario FROM usuarios_sistema WHERE id_cliente = ? ORDER BY tipo_usuario");
    $stmtCargos->execute([$id_cliente]);
    $cargos = $stmtCargos->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Falha silenciosa para não quebrar a tela
}

// ============================================================
// 6. CONSULTAR USUÁRIOS COM FILTROS E PAGINAÇÃO
// ============================================================

$usuarios = [];
$totalRegistros = 0;
$totalPaginas = 0;

try {
    // Agrupa as múltiplas unidades em uma única string separada por vírgula
    $sql = "SELECT 
                u.id_usuario, 
                u.nome_usuario, 
                u.email_usuario, 
                u.telefone_usuario, 
                u.tipo_usuario, 
                u.status_usuario, 
                u.data_cadastro, 
                GROUP_CONCAT(uni.nome_unidade SEPARATOR ', ') as nome_unidade,
                GROUP_CONCAT(uni.estado_unidade SEPARATOR ', ') as estado_unidade
            FROM usuarios_sistema u
            LEFT JOIN usuarios_unidades uu ON u.id_usuario = uu.id_usuario
            LEFT JOIN unidades uni ON uu.id_unidade = uni.id_unidade
            WHERE u.id_cliente = :id_cliente";
            
    $params = [':id_cliente' => $id_cliente];

    // Restrição por unidade para gerentes (só vê usuários que compartilham a mesma unidade)
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND u.id_usuario IN (SELECT id_usuario FROM usuarios_unidades WHERE id_unidade = :unidade_gerente)";
        $params[':unidade_gerente'] = $id_unidade_usuario;
    } elseif ($tipo_usuario === 'admin_cliente' && !empty($unidadeFiltro)) {
        // Admin filtrando por unidade específica
        $sql .= " AND u.id_usuario IN (SELECT id_usuario FROM usuarios_unidades WHERE id_unidade = :unidade_filtro)";
        $params[':unidade_filtro'] = $unidadeFiltro;
    }

    if (!empty($statusFiltro)) {
        $sql .= " AND u.status_usuario = :status";
        $params[':status'] = $statusFiltro;
    }

    if (!empty($cargoFiltro)) {
        $sql .= " AND u.tipo_usuario = :cargo";
        $params[':cargo'] = $cargoFiltro;
    }

    if (!empty($estadoFiltro) && $tipo_usuario === 'admin_cliente') {
        $sql .= " AND uni.estado_unidade = :estado";
        $params[':estado'] = $estadoFiltro;
    }

    if (!empty($busca)) {
        $sql .= " AND (u.nome_usuario LIKE :busca OR u.email_usuario LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }

    // Como usamos GROUP_CONCAT, precisamos agrupar pelo ID do usuário
    $sql .= " GROUP BY u.id_usuario ORDER BY u.nome_usuario";

    // Query de contagem (usando subquery para respeitar o GROUP BY)
    $sqlCount = "SELECT COUNT(*) FROM (" . $sql . ") AS total";
    $stmtCount = $conn->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = (int)$stmtCount->fetchColumn();
    $totalPaginas = $totalRegistros > 0 ? ceil($totalRegistros / $limite) : 1;

    // Query com paginação
    $sql .= " LIMIT :limite OFFSET :offset";
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro = "Erro ao buscar usuários: " . $e->getMessage();
    $usuarios = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
}

// ============================================================
// 7. FUNÇÕES AUXILIARES (Mapeamento para a nova tabela)
// ============================================================

function getCargoLabel($cargo) {
    $map = [
        'admin_cliente' => 'Administrador',
        'gerente' => 'Coordenador', // No banco está gerente, mas exibimos Coordenador
        'usuario' => 'Professor',   // No banco está usuario, mas exibimos Professor
        'secretaria' => 'Secretaria',
        'portaria' => 'Portaria'
    ];
    return $map[$cargo] ?? ucfirst($cargo);
}

function getCargoBadgeClass($cargo) {
    $map = [
        'admin_cliente' => 'badge-danger',
        'gerente' => 'badge-warning',
        'usuario' => 'badge-primary',
        'secretaria' => 'badge-secondary',
        'portaria' => 'badge-secondary'
    ];
    return $map[$cargo] ?? 'badge-secondary';
}

function getStatusBadgeClass($status) {
    $map = [
        'ativo' => 'badge-success',
        'inativo' => 'badge-warning',
        'bloqueado' => 'badge-danger'
    ];
    return $map[$status] ?? 'badge-secondary';
}

function getStatusIcon($status) {
    $map = [
        'ativo' => '<i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i> Ativo',
        'inativo' => '<i class="fas fa-clock" style="margin-right:4px;"></i> Inativo',
        'bloqueado' => '<i class="fas fa-lock" style="margin-right:4px;"></i> Bloqueado'
    ];
    return $map[$status] ?? $status;
}

function manterFiltros($pagina = null) {
    $params = $_GET;
    if ($pagina !== null) {
        $params['pagina'] = $pagina;
    }
    unset($params['excluir']);
    return '?' . http_build_query($params);
}

// ============================================================
// 8. MENSAGENS DA SESSÃO
// ============================================================

$mensagem_sucesso = '';
$mensagem_erro = '';

$message = getMessage();
if ($message) {
    if ($message['tipo'] === 'success') {
        $mensagem_sucesso = $message['mensagem'];
    } elseif ($message['tipo'] === 'error') {
        $mensagem_erro = $message['mensagem'];
    }
}

$titulo = 'Listar Usuários - Gerenciamento de Ambientes';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<main class="main">
    <!-- CABEÇALHO -->
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-users"></i> Listar Usuários</h1>
            <p class="page-subtitle">Gerencie os usuários cadastrados no sistema</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
            </span>
            <a href="cadastrar_usuario.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Cadastrar Usuário
            </a>
        </div>
    </header>

    <!-- ALERTAS -->
    <?php if ($mensagem_sucesso): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensagem_sucesso); ?></div>
    <?php endif; ?>
    <?php if ($mensagem_erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensagem_erro); ?></div>
    <?php endif; ?>

    <!-- FILTROS -->
    <div class="card-panel" style="margin-bottom: 20px;">
        <form method="GET" action="" class="filter-form">
            <div class="form-group">
                <label for="busca"><i class="fas fa-search"></i> Buscar</label>
                <input type="text" name="busca" id="busca" placeholder="Nome ou e-mail..." value="<?php echo htmlspecialchars($busca); ?>">
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <option value="">Todos</option>
                    <option value="ativo" <?php echo $statusFiltro === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                    <option value="inativo" <?php echo $statusFiltro === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    <option value="bloqueado" <?php echo $statusFiltro === 'bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                </select>
            </div>
            <div class="form-group">
                <label for="cargo">Cargo</label>
                <select name="cargo" id="cargo">
                    <option value="">Todos</option>
                    <?php foreach ($cargos as $cargo): ?>
                        <option value="<?php echo $cargo; ?>" <?php echo $cargoFiltro === $cargo ? 'selected' : ''; ?>>
                            <?php echo getCargoLabel($cargo); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($tipo_usuario === 'admin_cliente'): ?>
            <div class="form-group">
                <label for="unidade">Unidade</label>
                <select name="unidade" id="unidade">
                    <option value="">Todas</option>
                    <?php foreach ($unidades as $unidade): ?>
                        <option value="<?php echo $unidade['id_unidade']; ?>" <?php echo $unidadeFiltro == $unidade['id_unidade'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="estado">UF</label>
                <select name="estado" id="estado">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $uf): ?>
                        <option value="<?php echo $uf; ?>" <?php echo $estadoFiltro === $uf ? 'selected' : ''; ?>><?php echo $uf; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="listar_usuarios.php" class="btn btn-danger"><i class="fas fa-times"></i> Limpar</a>
        </form>
    </div>

    <!-- TABELA DE USUÁRIOS -->
    <div class="table-wrapper">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Usuários cadastrados</h3>
            <span style="font-size: 13px; color: #7a8aa0;">Total: <strong><?php echo $totalRegistros; ?></strong></span>
        </div>

        <!-- TABELA COM SCROLL -->
        <div class="table-scroll">
            <table class="table-unidades">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Cargo</th>
                        <th>Status</th>
                        <th>Unidade(s)</th>
                        <th>Data Cadastro</th>
                        <th>Contato</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                Nenhum usuário encontrado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($u['nome_usuario']); ?></strong>
                                <br>
                                <small style="color: #7a8aa0;"><?php echo htmlspecialchars($u['email_usuario']); ?></small>
                            </td>
                            <td>
                                <span class="badge <?php echo getCargoBadgeClass($u['tipo_usuario']); ?>">
                                    <?php echo getCargoLabel($u['tipo_usuario']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo getStatusBadgeClass($u['status_usuario']); ?>">
                                    <?php echo getStatusIcon($u['status_usuario']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['nome_unidade']): ?>
                                    <span style="font-size: 12px; display: inline-block; background: #f0f4fb; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e9f3;">
                                        <?php echo htmlspecialchars($u['nome_unidade']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 12px;">Nenhuma</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($u['data_cadastro'])); ?></td>
                            <td><?php echo $u['telefone_usuario'] ? htmlspecialchars($u['telefone_usuario']) : '<span style="color:#999;">-</span>'; ?></td>
                            <td>
                                <div class="actions" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <a href="editar_usuarios.php?id=<?php echo $u['id_usuario']; ?>" class="btn-action btn-edit" title="Editar">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    
                                    <?php if ($u['status_usuario'] === 'inativo'): ?>
                                        <a href="aprovar_usuario.php?id=<?php echo $u['id_usuario']; ?>" class="btn-action btn-success" title="Aprovar" onclick="return confirm('Aprovar este usuário?');">
                                            <i class="fas fa-check"></i> Aprovar
                                        </a>
                                    <?php elseif ($u['status_usuario'] === 'bloqueado'): ?>
                                        <a href="desbloquear_usuario.php?id=<?php echo $u['id_usuario']; ?>" class="btn-action btn-success" title="Desbloquear">
                                            <i class="fas fa-unlock"></i>
                                        </a>
                                    <?php elseif ($u['status_usuario'] === 'ativo'): ?>
                                        <a href="bloquear_usuario.php?id=<?php echo $u['id_usuario']; ?>" class="btn-action btn-danger" title="Bloquear" onclick="return confirm('Tem certeza que deseja bloquear este usuário?');">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINAÇÃO -->
        <?php if ($totalPaginas > 1): ?>
        <div style="display: flex; justify-content: center; gap: 6px; padding: 16px 22px; border-top: 1px solid #f0f4fb; flex-wrap: wrap; background: #ffffff; border-radius: 0 0 16px 16px;">
            <?php if ($pagina > 1): ?>
                <a href="<?php echo manterFiltros($pagina - 1); ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i> Anterior</a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;"><i class="fas fa-chevron-left"></i> Anterior</span>
            <?php endif; ?>

            <?php 
            $start = max(1, $pagina - 2);
            $end = min($totalPaginas, $pagina + 2);
            for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i == $pagina): ?>
                    <span class="btn btn-primary btn-sm" style="cursor: default;"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo manterFiltros($i); ?>" class="btn btn-outline btn-sm"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($pagina < $totalPaginas): ?>
                <a href="<?php echo manterFiltros($pagina + 1); ?>" class="btn btn-outline btn-sm">Próximo <i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">Próximo <i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>

</body>
</html>