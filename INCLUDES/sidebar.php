<?php
// ============================================================
// ARQUIVO: INCLUDES/sidebar.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Sidebar padrão do sistema com botão de logout
// ============================================================

// ============================================================
// CARREGAR CONEXÃO (SE NÃO FOI CARREGADO)
// ============================================================
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/../conexao_banco.php';
}

// ============================================================
// VERIFICAR SE O USUÁRIO ESTÁ LOGADO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    return;
}

// ============================================================
// VARIÁVEIS DO USUÁRIO (NOVO SISTEMA)
// ============================================================
$usuario_nome = $_SESSION['nome_usuario'] ?? 'Usuário';
$usuario_tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
$usuario_email = $_SESSION['email_usuario'] ?? '';
$nome_cliente = $_SESSION['nome_cliente'] ?? '';

// Mapeamento de tipos de usuário para exibição amigável
$tipos_exibicao = [
    'admin_cliente' => 'Administrador',
    'gerente' => 'Coordenador',
    'usuario' => 'Usuário',
    'visualizador' => 'Visualizador'
];
$usuario_cargo_exibicao = $tipos_exibicao[$usuario_tipo] ?? ucfirst($usuario_tipo);

// Iniciais do usuário
$iniciais = '';
$partes = explode(' ', $usuario_nome);
foreach ($partes as $parte) {
    $iniciais .= strtoupper($parte[0]);
    if (strlen($iniciais) >= 2) break;
}
$iniciais = $iniciais ?: 'U';

// ============================================================
// PERMISSÕES (NOVO SISTEMA)
// ============================================================
$is_admin = ($usuario_tipo === 'admin_cliente');
$is_gerente = ($usuario_tipo === 'gerente');
$is_usuario = ($usuario_tipo === 'usuario' || $usuario_tipo === 'visualizador');
$pode_gerenciar = ($is_admin || $is_gerente);

// ============================================================
// DETECTAR PÁGINA ATUAL PARA DESTAQUE DO MENU
// ============================================================
$pagina_atual = basename($_SERVER['PHP_SELF']);
$caminho_atual = $_SERVER['PHP_SELF'];

// Verificar se está na página de Mapa de Ocupação
$is_mapa_ocupacao = (strpos($caminho_atual, 'listar_aulas_dia.php') !== false || strpos($caminho_atual, 'mapa_salas_dia.php') !== false);

// Verificar se está na página de Cronograma (listar_aulas.php)
$is_cronograma = (strpos($caminho_atual, 'listar_aulas.php') !== false);

// Verificar se está na página de Registrar Recesso
$is_recesso = (strpos($caminho_atual, 'listar_recesso.php') !== false);

// Verificar se está na página de Dashboard
$is_dashboard = ($pagina_atual == 'dashboard.php');

// Verificar se está na página de Usuários
$is_usuarios = (strpos($caminho_atual, 'USUARIOS(ADM)') !== false);

// Verificar se está na página de Unidades
$is_unidades = (strpos($caminho_atual, 'UNIDADES') !== false);

// Verificar se está na página de Cursos
$is_cursos = (strpos($caminho_atual, 'CURSOS') !== false);

// Verificar se está na página de Salas
$is_salas = (strpos($caminho_atual, 'SALAS') !== false);

// ============================================================
// FUNÇÃO PARA VERIFICAR SE PÁGINA ESTÁ ATIVA
// ============================================================
function isActive($condicao) {
    return $condicao ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-icon">
            <i class="fas fa-door-open"></i>
        </div>
        <div class="logo-text">
            Senac<span>Salas</span>
            <small>Gerenciamento Acadêmico</small>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-label">Navegação</div>
        
        <!-- Dashboard -->
        <a href="../AUTENTIFICACAO_ACESSO/dashboard.php" class="menu-item <?php echo $is_dashboard ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>
        
        <!-- USUÁRIOS (apenas admin) -->
        <?php if ($is_admin): ?>
            <a href="../USUARIOS(ADM)/listar_usuarios.php" class="menu-item <?php echo $is_usuarios ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Usuários</span>
            </a>
        <?php endif; ?>
        
        <!-- Unidades (admin e gerente) -->
        <?php if ($pode_gerenciar): ?>
            <a href="../UNIDADES/listar_unidade.php" class="menu-item <?php echo $is_unidades ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span>Unidades</span>
            </a>
        <?php endif; ?>
        
        <!-- Cursos (admin e gerente) -->
        <?php if ($pode_gerenciar): ?>
            <a href="../CURSOS/listar_cursos.php" class="menu-item <?php echo $is_cursos ? 'active' : ''; ?>">
                <i class="fas fa-graduation-cap"></i>
                <span>Cursos</span>
            </a>
        <?php endif; ?>
        
        <!-- Salas (todos os tipos) -->
        <a href="../SALAS/listar_salas.php" class="menu-item <?php echo ($is_salas && strpos($caminho_atual, 'listar_salas.php') !== false) ? 'active' : ''; ?>">
            <i class="fas fa-door-open"></i>
            <span>Salas</span>
        </a>
        
        <!-- Cronograma - NÃO fica ativo quando estiver no Mapa de Ocupação -->
        <?php if (!$is_mapa_ocupacao): ?>
            <a href="../CRONOGRAMA_AULAS/listar_aulas.php" class="menu-item <?php echo $is_cronograma ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>Cronograma</span>
            </a>
        <?php else: ?>
            <a href="../CRONOGRAMA_AULAS/listar_aulas.php" class="menu-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Cronograma</span>
            </a>
        <?php endif; ?>
        
        <!-- Mapa de Ocupação (todos os tipos) -->
        <a href="../MAPA/mapa_salas_dia.php" class="menu-item <?php echo $is_mapa_ocupacao ? 'active' : ''; ?>">
            <i class="fas fa-map"></i>
            <span>Mapa de Ocupação</span>
            <span class="badge-menu">novo</span>
        </a>

        <!-- RECESSOS (admin e gerente) -->
        <?php if ($pode_gerenciar): ?>
            <a href="../RECESSOS/listar_recesso.php" class="menu-item <?php echo $is_recesso ? 'active' : ''; ?>">
                <i class="fas fa-calendar-minus"></i>
                <span>Registrar Recesso</span>
            </a>
        <?php endif; ?>
           
        <div class="menu-label">Configurações</div>
        
        <!-- Alterar Senha -->
        <a href="../AUTENTIFICACAO_ACESSO/resetar_senha.php" class="menu-item <?php echo strpos($caminho_atual, 'resetar_senha') !== false ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            <span>Alterar Senha</span>
        </a>
        
    </nav>

    <div class="sidebar-footer">
        <div class="user-row">
            <div class="avatar"><?php echo $iniciais; ?></div>
            <div class="user-info">
                <div class="name">
                    <span class="status-dot"></span> 
                    <?php echo htmlspecialchars($usuario_nome); ?>
                </div>
                <div class="role"><?php echo htmlspecialchars($usuario_cargo_exibicao); ?></div>
                <?php if (!empty($nome_cliente)): ?>
                    <div class="cliente">
                        <i class="fas fa-building" style="font-size: 10px;"></i>
                        <?php echo htmlspecialchars($nome_cliente); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- BOTÃO SAIR -->
        <a href="../AUTENTIFICACAO_ACESSO/realizar_logout.php" 
           class="logout-btn-sidebar"
           onclick="return confirm('Tem certeza que deseja sair do sistema?')">
            <i class="fas fa-sign-out-alt"></i> Sair
        </a>
    </div>
</aside>