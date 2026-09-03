<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/listar_usuarios.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Listagem com filtros e paginação
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
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem acessar.');
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
// 5. FILTROS E PAGINAÇÃO
// ============================================================

$busca = $_GET['busca'] ?? '';
$statusFiltro = $_GET['status'] ?? '';
$unidadeFiltro = $_GET['unidade'] ?? '';
$estadoFiltro = $_GET['estado'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 5;
$offset = ($pagina - 1) * $limite;

// ============================================================
// 6. BUSCAR UNIDADES E ESTADOS PARA FILTROS (APENAS ADMIN)
// ============================================================

$unidades = [];
$estados = [];

if ($tipo_usuario === 'admin_cliente') {
    try {
        $stmtUnidades = $conn->prepare("SELECT id_unidade, nome_unidade, estado_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade");
        $stmtUnidades->execute([$id_cliente]);
        $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $unidades = [];
    }
    
    try {
        $stmtEstados = $conn->prepare("SELECT DISTINCT estado_unidade FROM unidades WHERE id_cliente = ? ORDER BY estado_unidade");
        $stmtEstados->execute([$id_cliente]);
        $estados = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $estados = [];
    }
}

// ============================================================
// 7. CONSULTAR USUÁRIOS COM FILTROS E PAGINAÇÃO
// ============================================================

$usuarios = [];
$totalRegistros = 0;
$totalPaginas = 0;

try {
    // Construir query base - usando a nova tabela usuarios_sistema
    $sql = "SELECT u.*, un.nome_unidade, un.estado_unidade 
            FROM usuarios_sistema u
            LEFT JOIN unidades un ON u.id_unidade = un.id_unidade
            WHERE u.id_cliente = :id_cliente
            AND u.tipo_usuario != 'admin_cliente'";
    $params = [':id_cliente' => $id_cliente];

    // Restrição por unidade para coordenadores
    if ($tipo_usuario === 'gerente') {
        $sql .= " AND u.id_unidade = :unidade";
        $params[':unidade'] = $id_unidade_usuario;
    } else {
        if (!empty($unidadeFiltro)) {
            $sql .= " AND u.id_unidade = :unidade";
            $params[':unidade'] = $unidadeFiltro;
        }
    }

    // Filtro por status
    if (!empty($statusFiltro)) {
        $sql .= " AND u.status_usuario = :status";
        $params[':status'] = $statusFiltro;
    }

    // Filtro por estado da unidade
    if (!empty($estadoFiltro) && $tipo_usuario === 'admin_cliente') {
        $sql .= " AND un.estado_unidade = :estado";
        $params[':estado'] = $estadoFiltro;
    }

    // Filtro por busca (nome ou email)
    if (!empty($busca)) {
        $sql .= " AND (u.nome_usuario LIKE :busca OR u.email_usuario LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }

    // Ordenação
    $sql .= " ORDER BY u.nome_usuario";

    // Query de contagem
    $sqlCount = "SELECT COUNT(*) FROM (" . $sql . ") AS total";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalRegistros = $stmtCount->fetchColumn();
    $totalPaginas = ceil($totalRegistros / $limite);

    // Query com paginação
    $sql .= " LIMIT :limite OFFSET :offset";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro = "Erro ao buscar usuários: " . $e->getMessage();
    $usuarios = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
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
// 10. FUNÇÃO PARA MAPEAR TIPO DE USUÁRIO PARA BADGE
// ============================================================

function getBadgeClass($tipo) {
    $map = [
        'admin_cliente' => 'badge-danger',
        'gerente' => 'badge-orange',
        'usuario' => 'badge-purple',
        'secretaria' => 'badge-info',
        'portaria' => 'badge-info'
    ];
    return $map[$tipo] ?? 'badge-info';
}

function getTipoLabel($tipo) {
    $map = [
        'admin_cliente' => 'Administrador',
        'gerente' => 'Coordenador',
        'usuario' => 'Professor',
        'secretaria' => 'Secretaria',
        'portaria' => 'Portaria'
    ];
    return $map[$tipo] ?? ucfirst($tipo);
}

// ============================================================
// 11. TÍTULO DA PÁGINA
// ============================================================

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
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                Nenhum profissional encontrado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($u['nome_usuario']); ?></strong></td>
                            <td>
                                <span class="badge <?php echo getBadgeClass($u['tipo_usuario']); ?>">
                                    <?php echo getTipoLabel($u['tipo_usuario']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['status_usuario'] === 'ativo'): ?>
                                    <span class="badge badge-success"><i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i> Ativo</span>
                                <?php elseif ($u['status_usuario'] === 'inativo'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-clock" style="margin-right:4px;"></i> Inativo</span>
                                <?php elseif ($u['status_usuario'] === 'bloqueado'): ?>
                                    <span class="badge badge-danger"><i class="fas fa-lock" style="margin-right:4px;"></i> Bloqueado</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['nome_unidade'] ?? 'Não definida'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($u['data_cadastro'])); ?></td>
                            <td>
                                <div class="actions">
                                    <a href="editar_usuario.php?id=<?php echo $u['id_usuario']; ?>" class="btn-action btn-edit" title="Editar">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
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