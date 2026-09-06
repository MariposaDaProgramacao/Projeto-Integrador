<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/visualizar_acessos.php
// FUNÇÃO: Visualizar histórico de acessos do profissional
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
}

// ============================================================
// 3. VERIFICAR PERMISSÃO
// ============================================================

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem visualizar acessos.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA
// ============================================================

$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// ============================================================
// 5. RECEBER ID DO PROFISSIONAL
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do profissional inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. BUSCAR DADOS DO PROFISSIONAL
// ============================================================

try {
    $sql = "SELECT f.*, u.nome_unidade 
            FROM funcionarios f
            LEFT JOIN unidades u ON f.id_unidade = u.id_unidade
            WHERE f.id_funcionario = :id 
            AND f.id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $profissional = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profissional) {
        setMessage('error', 'Profissional não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    if ($tipo_usuario === 'gerente') {
        if ($profissional['id_unidade'] != $id_unidade_usuario) {
            setMessage('error', 'Você não tem permissão para visualizar este profissional.');
            redirect('listar_usuarios.php');
        }
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar profissional: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. BUSCAR HISTÓRICO DE ACESSOS COM PAGINAÇÃO
// ============================================================

$historico = [];
$total_registros = 0;
$total_paginas = 0;
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 10;
$offset = ($pagina - 1) * $limite;

try {
    $sqlCount = "SELECT COUNT(*) as total 
                 FROM historico_sistema h
                 WHERE h.id_funcionario = :id_funcionario";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->execute([':id_funcionario' => $id]);
    $total_registros = (int)$stmtCount->fetchColumn();
    $total_paginas = $total_registros > 0 ? ceil($total_registros / $limite) : 1;

    $sql = "SELECT h.*, 
            CASE 
                WHEN h.acao = 'login' THEN 'Login'
                WHEN h.acao = 'logout' THEN 'Logout'
                WHEN h.acao = 'UPDATE' AND h.tabela_afetada = 'funcionarios' THEN 'Atualização'
                WHEN h.acao = 'UPDATE' AND h.tabela_afetada = 'usuarios_sistema' THEN 'Atualização'
                WHEN h.acao = 'INSERT' AND h.tabela_afetada = 'funcionarios' THEN 'Cadastro'
                ELSE h.acao
            END as acao_descricao
            FROM historico_sistema h
            WHERE h.id_funcionario = :id_funcionario
            ORDER BY h.data_acao DESC
            LIMIT :limite OFFSET :offset";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_funcionario', $id, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro = 'Erro ao buscar histórico: ' . $e->getMessage();
    $historico = [];
    $total_registros = 0;
    $total_paginas = 0;
}

// ============================================================
// 8. FUNÇÕES AUXILIARES
// ============================================================

function getAcaoBadge($acao) {
    $map = [
        'login' => 'badge-success',
        'logout' => 'badge-secondary',
        'UPDATE' => 'badge-warning',
        'INSERT' => 'badge-primary',
        'DELETE' => 'badge-danger'
    ];
    return $map[$acao] ?? 'badge-secondary';
}

function getAcaoIcon($acao) {
    $map = [
        'login' => 'fa-sign-in-alt',
        'logout' => 'fa-sign-out-alt',
        'UPDATE' => 'fa-edit',
        'INSERT' => 'fa-plus',
        'DELETE' => 'fa-trash'
    ];
    return $map[$acao] ?? 'fa-circle';
}

function getAcaoDescricao($item) {
    if ($item['acao'] === 'login') {
        return 'Login realizado no sistema';
    } elseif ($item['acao'] === 'logout') {
        return 'Logout realizado do sistema';
    } elseif ($item['acao'] === 'UPDATE' && $item['tabela_afetada'] === 'funcionarios') {
        return 'Dados do profissional atualizados';
    } elseif ($item['acao'] === 'UPDATE' && $item['tabela_afetada'] === 'usuarios_sistema') {
        return 'Dados do usuário atualizados';
    } elseif ($item['acao'] === 'INSERT' && $item['tabela_afetada'] === 'funcionarios') {
        return 'Profissional cadastrado no sistema';
    } else {
        return $item['tabela_afetada'] . ' - ' . $item['acao'];
    }
}

function formatarDataHora($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i:s', strtotime($data));
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
    return '?' . http_build_query($params) . '#tabela-historico';
}

// ============================================================
// 10. CONTAGEM DE LOGINS E LOGOUTS
// ============================================================

try {
    $sqlStats = "SELECT 
                    SUM(CASE WHEN acao = 'login' THEN 1 ELSE 0 END) as total_logins,
                    SUM(CASE WHEN acao = 'logout' THEN 1 ELSE 0 END) as total_logouts
                 FROM historico_sistema
                 WHERE id_funcionario = :id_funcionario";
    $stmtStats = $conn->prepare($sqlStats);
    $stmtStats->execute([':id_funcionario' => $id]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $total_logins = (int)($stats['total_logins'] ?? 0);
    $total_logouts = (int)($stats['total_logouts'] ?? 0);
} catch (PDOException $e) {
    $total_logins = 0;
    $total_logouts = 0;
}

// ============================================================
// 11. TÍTULO DA PÁGINA
// ============================================================

$titulo = 'Visualizar Acessos - Gerenciamento de Ambientes';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<!-- CSS ESPECÍFICO PARA O MÓDULO DE ADMINISTRAÇÃO -->
<link rel="stylesheet" href="usuarios_adm.css">

<main class="main main-visualizar">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-history"></i> Histórico de Acessos</h1>
            <p class="page-subtitle">Visualize o histórico de acessos e atividades do profissional</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 13px; color: #7a8aa0;">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($_SESSION['nome_cliente'] ?? ''); ?>
            </span>
            <a href="listar_usuarios.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Voltar para listagem
            </a>
        </div>
    </header>

    <!-- ========================================== -->
    <!-- INFORMAÇÕES DO PROFISSIONAL -->
    <!-- ========================================== -->
    <div class="card-panel" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <i class="fas fa-user-circle" style="font-size: 48px; color: #0e1a2b; background: #f0f4fb; padding: 8px; border-radius: 50%;"></i>
            <div style="flex: 1;">
                <h2 style="margin: 0; font-size: 22px; color: #0e1a2b; font-weight: 700;">
                    <?php echo htmlspecialchars($profissional['nome_funcionario']); ?>
                </h2>
                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 4px;">
                    <span style="font-size: 14px; color: #5a6a7e;">
                        <i class="fas fa-envelope" style="margin-right: 4px;"></i>
                        <?php echo htmlspecialchars($profissional['email_funcionario']); ?>
                    </span>
                    <span style="font-size: 14px; color: #5a6a7e;">
                        <i class="fas fa-briefcase" style="margin-right: 4px;"></i>
                        <?php 
                        $cargos = [
                            'administrador' => 'Administrador',
                            'coordenador' => 'Coordenador',
                            'professor' => 'Professor',
                            'auxiliar' => 'Auxiliar',
                            'gerente' => 'Gerente',
                            'secretaria' => 'Secretaria',
                            'portaria' => 'Portaria'
                        ];
                        echo $cargos[$profissional['cargo_funcionario']] ?? ucfirst($profissional['cargo_funcionario']);
                        ?>
                    </span>
                    <span style="font-size: 14px; color: #5a6a7e;">
                        <i class="fas fa-building" style="margin-right: 4px;"></i>
                        <?php echo htmlspecialchars($profissional['nome_unidade'] ?? 'Não definida'); ?>
                    </span>
                    <span style="font-size: 14px;">
                        <span class="badge <?php echo $profissional['status_acesso'] === 'ativo' ? 'badge-success' : ($profissional['status_acesso'] === 'bloqueado' ? 'badge-danger' : 'badge-warning'); ?>">
                            <?php echo $profissional['status_acesso'] === 'ativo' ? 'Ativo' : ($profissional['status_acesso'] === 'bloqueado' ? 'Bloqueado' : 'Inativo'); ?>
                        </span>
                    </span>
                </div>
            </div>
            <div>
                <a href="editar_usuarios.php?id=<?php echo $id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- ESTATÍSTICAS RÁPIDAS -->
    <!-- ========================================== -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">
        <div style="background: #ffffff; padding: 16px 20px; border-radius: 12px; border: 1px solid #eef2f8; text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #1a73e8;"><?php echo $total_registros; ?></div>
            <div style="font-size: 13px; color: #7a8aa0;">Total de registros</div>
        </div>
        <div style="background: #ffffff; padding: 16px 20px; border-radius: 12px; border: 1px solid #eef2f8; text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #34a853;"><?php echo $total_logins; ?></div>
            <div style="font-size: 13px; color: #7a8aa0;">Logins</div>
        </div>
        <div style="background: #ffffff; padding: 16px 20px; border-radius: 12px; border: 1px solid #eef2f8; text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #f59e0b;"><?php echo $total_logouts; ?></div>
            <div style="font-size: 13px; color: #7a8aa0;">Logouts</div>
        </div>
        <?php if ($profissional['data_ultimo_acesso']): ?>
        <div style="background: #ffffff; padding: 16px 20px; border-radius: 12px; border: 1px solid #eef2f8; text-align: center;">
            <div style="font-size: 14px; font-weight: 600; color: #1a2639;">Último acesso</div>
            <div style="font-size: 13px; color: #7a8aa0; margin-top: 4px;">
                <?php echo formatarDataHora($profissional['data_ultimo_acesso']); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========================================== -->
    <!-- TABELA DE HISTÓRICO -->
    <!-- ========================================== -->
    <div class="table-wrapper" style="margin-bottom: 20px;" id="tabela-historico">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Histórico de Atividades</h3>
            <span style="font-size: 13px; color: #7a8aa0;">
                Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?> | 
                <strong><?php echo $total_registros; ?></strong> registros
            </span>
        </div>

        <div class="table-scroll">
            <table class="table-unidades">
                <thead>
                    <tr>
                        <th style="width: 20%;">Data/Hora</th>
                        <th style="width: 15%;">Ação</th>
                        <th style="width: 50%;">Descrição</th>
                        <th style="width: 15%;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historico)): ?>
                        <tr>
                            <td colspan="4" class="empty-state">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 12px;"></i>
                                Nenhum registro de acesso encontrado para este profissional.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historico as $item): ?>
                        <tr>
                            <td style="font-size: 13px; color: #4a5a72; white-space: nowrap;">
                                <?php echo formatarDataHora($item['data_acao']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo getAcaoBadge($item['acao']); ?>" style="white-space: nowrap;">
                                    <i class="fas <?php echo getAcaoIcon($item['acao']); ?>" style="margin-right: 4px;"></i>
                                    <?php echo htmlspecialchars($item['acao_descricao'] ?? $item['acao']); ?>
                                </span>
                            </td>
                            <td style="font-size: 13px; color: #4a5a72;">
                                <?php echo htmlspecialchars(getAcaoDescricao($item)); ?>
                            </td>
                            <td style="font-size: 13px; color: #7a8aa0; white-space: nowrap;">
                                <?php echo htmlspecialchars($item['ip_origem'] ?? '-'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ========================================== -->
        <!-- PAGINAÇÃO - IGUAL AO listar_usuarios.php (5 NÚMEROS) -->
        <!-- ========================================== -->
        <?php if ($total_paginas > 1): ?>
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

            <!-- Páginas ao redor da atual (5 NÚMEROS - IGUAL AO listar_usuarios.php) -->
            <?php 
            $start = max(1, $pagina - 2);
            $end = min($total_paginas, $pagina + 2);
            
            for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i == $pagina): ?>
                    <span class="btn btn-primary btn-sm" style="cursor: default;"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo manterFiltros($i); ?>" class="btn btn-outline btn-sm"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <!-- Última página -->
            <?php if ($pagina < $total_paginas - 2): ?>
                <?php if ($pagina < $total_paginas - 3): ?>
                    <span style="color: #999; padding: 0 4px;">…</span>
                <?php endif; ?>
                <a href="<?php echo manterFiltros($total_paginas); ?>" class="btn btn-outline btn-sm"><?php echo $total_paginas; ?></a>
            <?php endif; ?>

            <!-- Próximo -->
            <?php if ($pagina < $total_paginas): ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#tabela-historico') {
        setTimeout(function() {
            var elemento = document.getElementById('tabela-historico');
            if (elemento) {
                var offset = 20;
                var posicao = elemento.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: posicao, behavior: 'smooth' });
            }
        }, 100);
    }
});
</script>

</body>
</html>