<head>
    <link rel="stylesheet" href="usuarios_adm.css">
</head>

<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/listar_usuarios.php
// FUNÇÃO: Listagem de profissionais (funcionarios) com filtros
// ============================================================

// ============================================================
// 1. INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR LOGIN
// ============================================================

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
    exit;
}

// ============================================================
// 3. VERIFICAR PERMISSÃO
// ============================================================

$tipos_permitidos = ['admin_cliente', 'gerente'];
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
if (!in_array($tipo_usuario, $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e gerentes podem acessar.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
    exit;
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA
// ============================================================

$id_cliente = $_SESSION['id_cliente'] ?? 0;
$id_usuario_logado = $_SESSION['id_usuario'] ?? 0;
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// Se não tiver unidade definida, buscar do funcionário ou primeira unidade
if (empty($id_unidade_usuario)) {
    try {
        // Buscar unidade do funcionário logado
        $stmt = $conn->prepare("SELECT id_unidade FROM funcionarios WHERE id_funcionario = ? AND id_cliente = ?");
        $stmt->execute([$id_usuario_logado, $id_cliente]);
        $result = $stmt->fetch();
        
        if ($result && $result['id_unidade']) {
            $id_unidade_usuario = $result['id_unidade'];
        } else {
            // Buscar primeira unidade do cliente
            $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
            $stmt->execute([$id_cliente]);
            $unidade = $stmt->fetch();
            if ($unidade) {
                $id_unidade_usuario = $unidade['id_unidade'];
            } else {
                $id_unidade_usuario = 0;
            }
        }
        $_SESSION['usuario_unidade'] = $id_unidade_usuario;
    } catch (PDOException $e) {
        $id_unidade_usuario = 0;
    }
}

// ============================================================
// 5. FILTROS E PAGINAÇÃO
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
// 6. BUSCAR DADOS PARA FILTROS
// ============================================================

$unidades = [];
$estados = [];
$cargos = [];

try {
    // Buscar unidades do cliente
    $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade, estado_unidade 
                                   FROM unidades 
                                   WHERE id_cliente = ? 
                                   ORDER BY nome_unidade");
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar estados distintos
    $stmtEstados = $conn->prepare("SELECT DISTINCT estado_unidade 
                                  FROM unidades 
                                  WHERE id_cliente = ? 
                                  ORDER BY estado_unidade");
    $stmtEstados->execute([$id_cliente]);
    $estados = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);
    
    // Buscar cargos distintos
    $stmtCargos = $conn->prepare("SELECT DISTINCT cargo_funcionario 
                                  FROM funcionarios 
                                  WHERE id_cliente = ? 
                                  ORDER BY cargo_funcionario");
    $stmtCargos->execute([$id_cliente]);
    $cargos = $stmtCargos->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $unidades = [];
    $estados = [];
    $cargos = [];
}

// ============================================================
// 7. CONSULTAR FUNCIONÁRIOS COM FILTROS E PAGINAÇÃO
// ============================================================

$funcionarios = [];
$totalRegistros = 0;
$totalPaginas = 0;

try {
    // ✅ USANDO TABELA funcionarios
    $sql = "SELECT f.*, u.nome_unidade, u.estado_unidade 
            FROM funcionarios f
            LEFT JOIN unidades u ON f.id_unidade = u.id_unidade
            WHERE f.id_cliente = :id_cliente";
    $params = [':id_cliente' => $id_cliente];

    // Restrição por unidade para gerentes
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND f.id_unidade = :unidade";
        $params[':unidade'] = $id_unidade_usuario;
    } elseif ($tipo_usuario === 'admin_cliente' && !empty($unidadeFiltro)) {
        $sql .= " AND f.id_unidade = :unidade";
        $params[':unidade'] = $unidadeFiltro;
    }

    // Filtro por status
    if (!empty($statusFiltro)) {
        $sql .= " AND f.status_acesso = :status";
        $params[':status'] = $statusFiltro;
    }

    // Filtro por cargo
    if (!empty($cargoFiltro)) {
        $sql .= " AND f.cargo_funcionario = :cargo";
        $params[':cargo'] = $cargoFiltro;
    }

    // Filtro por estado da unidade (apenas admin)
    if (!empty($estadoFiltro) && $tipo_usuario === 'admin_cliente') {
        $sql .= " AND u.estado_unidade = :estado";
        $params[':estado'] = $estadoFiltro;
    }

    // Filtro por busca (nome ou email)
    if (!empty($busca)) {
        $sql .= " AND (f.nome_funcionario LIKE :busca OR f.email_funcionario LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }

    // Ordenação
    $sql .= " ORDER BY f.nome_funcionario";

    // Query de contagem
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
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro = "Erro ao buscar funcionários: " . $e->getMessage();
    $funcionarios = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
}

// ============================================================
// 8. FUNÇÕES AUXILIARES
// ============================================================

function getCargoLabel($cargo) {
    $map = [
        'administrador' => 'Administrador',
        'coordenador' => 'Coordenador',
        'professor' => 'Professor',
        'auxiliar' => 'Auxiliar',
        'gerente' => 'Gerente',
        'secretaria' => 'Secretaria',
        'portaria' => 'Portaria'
    ];
    return $map[$cargo] ?? ucfirst($cargo);
}

function getCargoBadgeClass($cargo) {
    $map = [
        'administrador' => 'badge-danger',
        'coordenador' => 'badge-warning',
        'professor' => 'badge-primary',
        'auxiliar' => 'badge-info',
        'gerente' => 'badge-success',
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

// ============================================================
// 9. FUNÇÃO PARA MANTER FILTROS NA PAGINAÇÃO
// ============================================================

function manterFiltros($pagina = null) {
    $params = $_GET;
    if ($pagina !== null) {
        $params['pagina'] = $pagina;
    }
    unset($params['excluir']);
    return '?' . http_build_query($params);
}

// ============================================================
// 10. MENSAGENS DA SESSÃO
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

// ============================================================
// 11. TÍTULO DA PÁGINA
// ============================================================

$titulo = 'Listar Profissionais - Gerenciamento de Ambientes';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<main class="main">
    <!-- CABEÇALHO -->
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-users"></i> Listar Profissionais</h1>
            <p class="page-subtitle">Gerencie os profissionais cadastrados no sistema</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
            </span>
            <a href="cadastrar_usuario.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Cadastrar Profissional
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

    <!-- TABELA DE PROFISSIONAIS -->
    <div class="table-wrapper">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Profissionais cadastrados</h3>
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
                        <th>Unidade</th>
                        <th>Data Cadastro</th>
                        <th>Último Acesso</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($funcionarios)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                Nenhum profissional encontrado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($funcionarios as $f): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($f['nome_funcionario']); ?></strong></td>
                            <td>
                                <span class="badge <?php echo getCargoBadgeClass($f['cargo_funcionario']); ?>">
                                    <?php echo getCargoLabel($f['cargo_funcionario']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo getStatusBadgeClass($f['status_acesso']); ?>">
                                    <?php echo getStatusIcon($f['status_acesso']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($f['nome_unidade'] ?? 'Não definida'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($f['data_cadastro_funcionario'])); ?></td>
                            <td>
                                <?php if ($f['data_ultimo_acesso']): ?>
                                    <?php echo date('d/m/Y H:i', strtotime($f['data_ultimo_acesso'])); ?>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 12px;">Nunca acessou</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <a href="editar_usuarios.php?id=<?php echo $f['id_funcionario']; ?>" class="btn-action btn-edit" title="Editar">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <a href="visualizar_acessos.php?id=<?php echo $f['id_funcionario']; ?>" class="btn-action btn-view" title="Ver acessos">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <?php if ($f['status_acesso'] === 'bloqueado'): ?>
                                        <a href="desbloquear_usuario.php?id=<?php echo $f['id_funcionario']; ?>" class="btn-action btn-success" title="Desbloquear">
                                            <i class="fas fa-unlock"></i>
                                        </a>
                                    <?php elseif ($f['status_acesso'] === 'ativo'): ?>
                                        <a href="bloquear_usuario.php?id=<?php echo $f['id_funcionario']; ?>" class="btn-action btn-danger" title="Bloquear" onclick="return confirm('Tem certeza que deseja bloquear este profissional?');">
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
            <!-- Anterior -->
            <?php if ($pagina > 1): ?>
                <a href="<?php echo manterFiltros($pagina - 1); ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-chevron-left"></i> Anterior
                </a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">
                    <i class="fas fa-chevron-left"></i> Anterior
                </span>
            <?php endif; ?>

            <!-- Primeira página -->
            <?php if ($pagina > 3): ?>
                <a href="<?php echo manterFiltros(1); ?>" class="btn btn-outline btn-sm">1</a>
                <?php if ($pagina > 4): ?>
                    <span style="color: #999; padding: 0 4px;">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Páginas ao redor da atual -->
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

            <!-- Última página -->
            <?php if ($pagina < $totalPaginas - 2): ?>
                <?php if ($pagina < $totalPaginas - 3): ?>
                    <span style="color: #999; padding: 0 4px;">…</span>
                <?php endif; ?>
                <a href="<?php echo manterFiltros($totalPaginas); ?>" class="btn btn-outline btn-sm"><?php echo $totalPaginas; ?></a>
            <?php endif; ?>

            <!-- Próximo -->
            <?php if ($pagina < $totalPaginas): ?>
                <a href="<?php echo manterFiltros($pagina + 1); ?>" class="btn btn-outline btn-sm">
                    Próximo <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="btn btn-outline btn-sm" style="color: #b0bec5; pointer-events: none;">
                    Próximo <i class="fas fa-chevron-right"></i>
                </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>

</body>
</html>