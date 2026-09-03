<?php
// ============================================================
// ARQUIVO: CURSOS/cadastrar_curso.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Exibir formulário para cadastrar um novo curso
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem cadastrar cursos.');
    redirect('listar_cursos.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
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
// BUSCAR DADOS PARA SELECTS (FILTRADOS POR CLIENTE)
// ============================================================
try {
    // ============================================================
    // UNIDADES - APENAS ADMINISTRADOR PODE VER TODAS
    // ============================================================
    if ($tipo_usuario === 'admin_cliente') {
        $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade ASC";
        $stmt_unidades = $conn->prepare($sql_unidades);
        $stmt_unidades->execute([$id_cliente]);
        $unidades = $stmt_unidades->fetchAll();
    } else {
        // Coordenador (gerente) vê apenas a sua unidade
        $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente ORDER BY nome_unidade ASC";
        $stmt_unidades = $conn->prepare($sql_unidades);
        $stmt_unidades->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $unidades = $stmt_unidades->fetchAll();
        
        // Se não encontrou a unidade, buscar todas do cliente (fallback)
        if (empty($unidades)) {
            $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade ASC";
            $stmt_unidades = $conn->prepare($sql_unidades);
            $stmt_unidades->execute([$id_cliente]);
            $unidades = $stmt_unidades->fetchAll();
        }
    }

    // ============================================================
    // PROFESSORES - COM SELECT2 (FILTRADOS POR CLIENTE)
    // ============================================================
    $sql_docentes = "SELECT id_funcionario, nome_funcionario 
                     FROM funcionarios 
                     WHERE cargo_funcionario = 'professor' 
                     AND id_cliente = :id_cliente
                     ORDER BY nome_funcionario ASC";
    $stmt_docentes = $conn->prepare($sql_docentes);
    $stmt_docentes->execute([':id_cliente' => $id_cliente]);
    $docentes = $stmt_docentes->fetchAll();

    // ==========================================
    // BUSCAR TIPOS DE SALA JÁ CADASTRADOS (FILTRADOS POR CLIENTE)
    // ==========================================
    $sql_tipos = "SELECT DISTINCT tipo_sala_preferencial FROM cursos 
                  WHERE id_cliente = :id_cliente
                  AND tipo_sala_preferencial IS NOT NULL 
                  AND tipo_sala_preferencial != '' 
                  ORDER BY tipo_sala_preferencial ASC";
    $stmt_tipos = $conn->prepare($sql_tipos);
    $stmt_tipos->execute([':id_cliente' => $id_cliente]);
    $tipos_cursos = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN);

    $sql_tipos_salas = "SELECT DISTINCT tipo_sala FROM salas 
                        WHERE id_cliente = :id_cliente
                        ORDER BY tipo_sala ASC";
    $stmt_tipos_salas = $conn->prepare($sql_tipos_salas);
    $stmt_tipos_salas->execute([':id_cliente' => $id_cliente]);
    $tipos_salas = $stmt_tipos_salas->fetchAll(PDO::FETCH_COLUMN);

    $tipos_sala = array_unique(array_merge($tipos_cursos, $tipos_salas));
    sort($tipos_sala);

} catch (PDOException $e) {
    setMessage('error', 'Erro ao carregar dados: ' . $e->getMessage());
    $tipos_sala = [];
    $unidades = [];
    $docentes = [];
}

// ============================================================
// MENSAGENS
// ============================================================
$message = getMessage();
$erro = '';
$sucesso = '';

if ($message) {
    if ($message['tipo'] === 'error') {
        $erro = $message['mensagem'];
    } elseif ($message['tipo'] === 'success') {
        $sucesso = $message['mensagem'];
    }
}

// Buscar nome da unidade do coordenador
$nomeUnidadeCoordenador = '';
if ($tipo_usuario === 'gerente' && $id_unidade_usuario) {
    try {
        $sql_nome = "SELECT nome_unidade FROM unidades WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
        $stmt_nome = $conn->prepare($sql_nome);
        $stmt_nome->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $nomeUnidadeCoordenador = $stmt_nome->fetchColumn();
    } catch (PDOException $e) {
        $nomeUnidadeCoordenador = 'Unidade não encontrada';
    }
}

$titulo = 'Cadastrar Curso - Gerenciador de Salas';
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<!-- Select2 CSS e JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Ajustes para Select2 */
    .select2-container--default .select2-selection--single {
        border: 1px solid #e2e9f3;
        border-radius: 8px;
        height: 42px;
        padding: 4px 8px;
        background: #fafcff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 32px;
        color: #1a2639;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px;
    }
    .select2-container--default .select2-selection--single:focus {
        border-color: #1a73e8;
        box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
    }
    .select2-dropdown {
        border: 1px solid #e2e9f3;
        border-radius: 8px;
    }
    .select2-search__field {
        border-radius: 6px !important;
        border: 1px solid #e2e9f3 !important;
        padding: 6px 10px !important;
    }
    .select2-results__option {
        padding: 8px 12px !important;
    }
    .select2-results__option--highlighted {
        background: #e3f2fd !important;
        color: #1a2639 !important;
    }
    
    /* Estilo para unidade bloqueada */
    .unidade-bloqueada {
        padding: 10px 14px;
        background: #f0f4fb;
        border: 1px solid #e2e9f3;
        border-radius: 8px;
        color: #1a2639;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .unidade-bloqueada i {
        color: #1a73e8;
    }
    .unidade-bloqueada .badge-coordenador {
        background: #e3f2fd;
        color: #0d47a1;
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }

    /* ============================================================
       ESTILOS DO CARD E FORMULÁRIO
    ============================================================ */
    .main {
        flex: 1;
        padding: 28px 36px 20px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 28px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-title {
        font-size: 24px;
        font-weight: 700;
        color: #0e1a2b;
    }
    .page-title i {
        color: #1a73e8;
        margin-right: 10px;
    }
    .page-subtitle {
        font-size: 14px;
        color: #7a8aa0;
        margin-top: 4px;
    }

    .card-panel {
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #ebf0f8;
        padding: 28px 32px;
        margin-bottom: 20px;
        max-width: 900px;
        width: 100%;
        align-self: center;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    }

    .form-group {
        margin-bottom: 18px;
    }
    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #2d3a4f;
        margin-bottom: 4px;
    }
    .form-group label i {
        color: #1a73e8;
        margin-right: 6px;
    }
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 10px 14px;
        border: 1.5px solid #e2e9f3;
        border-radius: 8px;
        font-size: 14px;
        background: #fafcff;
        transition: all 0.2s;
        font-family: 'Inter', sans-serif;
        color: #1a2639;
    }
    .form-group input:focus,
    .form-group select:focus {
        border-color: #1a73e8;
        box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        outline: none;
    }
    .form-group input[readonly] {
        background: #f0f4fb;
        color: #6c7a8e;
        cursor: not-allowed;
    }
    .form-group small {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        color: #7a8aa0;
    }
    .form-group small i {
        margin-right: 4px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }

    .form-group label input[type="checkbox"] {
        width: auto;
        margin-right: 4px;
    }

    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }

    .btn {
        padding: 9px 20px;
        border-radius: 60px;
        border: none;
        font-weight: 600;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        font-family: 'Inter', sans-serif;
    }

    .btn-primary {
        background: #1a73e8;
        color: #ffffff;
        border: none;
        box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
    }
    .btn-primary:hover {
        background: #1557b0;
        transform: scale(1.02);
    }

    .btn-outline {
        background: transparent;
        color: #1a2639;
        border: 1px solid #d8e0ec;
    }
    .btn-outline:hover {
        background: #f0f4fb;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 8px;
        max-width: 900px;
        width: 100%;
        align-self: center;
    }
    .alert-danger {
        background: #ffe9e9;
        color: #b33a3a;
        border: 1px solid #ffd6d6;
    }
    .alert-success {
        background: #e6f7e9;
        color: #1e8546;
        border: 1px solid #c8f0cf;
    }
    .alert i {
        font-size: 18px;
    }

    /* Responsividade */
    @media (max-width: 640px) {
        .main { padding: 16px; }
        .card-panel { padding: 20px; }
        .form-row { grid-template-columns: 1fr; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; justify-content: center; }
    }

    /* Sidebar e outros estilos já existentes */
    .sidebar {
        width: 270px;
        background: #ffffff;
        border-right: 1px solid #e8edf5;
        padding: 28px 20px;
        display: flex;
        flex-direction: column;
        gap: 28px;
        flex-shrink: 0;
        overflow-y: auto;
        height: 100vh;
    }
    .logo-area { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f4fb; }
    .logo-icon { background: linear-gradient(145deg, #1a73e8, #0d47a1); width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px; box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3); }
    .logo-text { font-size: 20px; font-weight: 700; color: #1a2639; }
    .logo-text span { color: #1a73e8; }
    .logo-text small { display: block; font-size: 11px; font-weight: 400; color: #7a8aa0; margin-top: 2px; }
    .sidebar-menu { display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .menu-label { font-size: 11px; font-weight: 600; color: #9aabbf; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 16px 6px; }
    .menu-item { display: flex; align-items: center; gap: 14px; padding: 10px 16px; border-radius: 10px; color: #5a6a7e; font-weight: 500; font-size: 14px; transition: all 0.15s ease; text-decoration: none; }
    .menu-item i { width: 20px; font-size: 16px; color: #8a9bb5; transition: color 0.15s; }
    .menu-item:hover { background: #f0f6ff; color: #1a2639; }
    .menu-item:hover i { color: #1a73e8; }
    .menu-item.active { background: #1a73e8; color: #ffffff; box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3); }
    .menu-item.active i { color: #ffffff; }
    .sidebar-footer { border-top: 1px solid #edf2f9; padding-top: 16px; display: flex; flex-direction: column; align-items: stretch; gap: 12px; margin-top: auto; }
    .sidebar-footer .user-row { display: flex; align-items: center; gap: 14px; }
    .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(145deg, #eef2f9, #dce3ef); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; color: #2d3a4f; }
    .user-info { line-height: 1.3; }
    .user-info .name { font-weight: 600; font-size: 13px; color: #1a2639; }
    .user-info .role { font-size: 12px; color: #8a9bb5; }
    .logout-btn-sidebar { display: flex; align-items: center; justify-content: center; gap: 8px; background: #dc3545; color: #ffffff; border: none; border-radius: 60px; padding: 10px 16px; font-weight: 600; font-size: 13px; text-decoration: none; transition: all 0.2s ease; width: 100%; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25); cursor: pointer; }
    .logout-btn-sidebar:hover { background: #c82333; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35); }
    .footer-system { text-align: center; font-size: 12px; color: #8a9bb5; padding: 16px 0 8px; border-top: 1px solid #e2e9f3; margin-top: auto; background: transparent; flex-shrink: 0; }

    @media (max-width: 820px) {
        .sidebar { display: none; }
        .main { padding: 16px; }
    }
</style>

<main class="main">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-graduation-cap"></i> Cadastrar Curso</h1>
            <p class="page-subtitle">Preencha os dados para cadastrar um novo curso</p>
        </div>
        <div style="font-size: 13px; color: #7a8aa0;">
            <i class="fas fa-building"></i> 
            <?php 
                $nomeCliente = $_SESSION['nome_cliente'] ?? '';
                echo htmlspecialchars($nomeCliente);
            ?>
        </div>
    </header>

    <?php if ($erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sucesso); ?></div>
    <?php endif; ?>

    <div class="card-panel">
        <form action="processar_cursos.php" method="POST">
            <input type="hidden" name="acao" value="cadastrar">
            <input type="hidden" name="id_cliente" value="<?php echo $id_cliente; ?>">

            <!-- Número do Curso -->
            <div class="form-group">
                <label for="numero_curso"><i class="fas fa-hashtag"></i> Número do Curso *</label>
                <input type="text" name="numero_curso" id="numero_curso" placeholder="Ex: TURMA-001-2026" required>
            </div>

            <!-- Nome do Curso -->
            <div class="form-group">
                <label for="nome_curso"><i class="fas fa-book"></i> Nome do Curso *</label>
                <input type="text" name="nome_curso" id="nome_curso" placeholder="Ex: Excel Avançado" required>
            </div>

            <!-- ============================================================
            UNIDADE - BLOQUEADA PARA COORDENADOR (GERENTE)
            ============================================================ -->
            <div class="form-group">
                <label for="id_unidade"><i class="fas fa-building"></i> Unidade *</label>
                
                <?php if ($tipo_usuario === 'admin_cliente'): ?>
                    <!-- Administrador: pode escolher a unidade -->
                    <select name="id_unidade" id="id_unidade" required>
                        <option value="">Selecione a unidade</option>
                        <?php foreach ($unidades as $unidade): ?>
                            <option value="<?php echo $unidade['id_unidade']; ?>">
                                <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <!-- Coordenador (gerente): unidade bloqueada -->
                    <div class="unidade-bloqueada">
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($nomeUnidadeCoordenador ?: 'Unidade não definida'); ?>
                        <span class="badge-coordenador">Coordenador</span>
                    </div>
                    <input type="hidden" name="id_unidade" value="<?php echo $id_unidade_usuario; ?>">
                    <small style="color: #7a8aa0; font-size: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        A unidade está bloqueada pois você é coordenador. Apenas administradores podem alterar a unidade.
                    </small>
                <?php endif; ?>
            </div>

            <!-- ============================================================
            PROFESSOR RESPONSÁVEL - COM AUTOCOMPLETE (SELECT2)
            ============================================================ -->
            <div class="form-group">
                <label for="id_docente"><i class="fas fa-user-tie"></i> Professor Responsável</label>
                <select name="id_docente" id="id_docente" style="width: 100%;">
                    <option value="">Selecione ou digite o nome do professor</option>
                    <?php foreach ($docentes as $docente): ?>
                        <option value="<?php echo $docente['id_funcionario']; ?>">
                            <?php echo htmlspecialchars($docente['nome_funcionario']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Digite para buscar um professor ou deixe em branco.</small>
            </div>

            <!-- Carga Horária -->
            <div class="form-row">
                <div class="form-group">
                    <label for="carga_horaria_curso"><i class="fas fa-clock"></i> Carga Horária Total (horas) *</label>
                    <input type="number" name="carga_horaria_curso" id="carga_horaria_curso" placeholder="Ex: 60" min="1" required>
                </div>
                <div class="form-group">
                    <label for="horas_por_dia"><i class="fas fa-hourglass-half"></i> Horas por Dia *</label>
                    <input type="number" name="horas_por_dia" id="horas_por_dia" value="4" min="1" required>
                </div>
            </div>

            <!-- Percentual de Conclusão (inicial) -->
            <div class="form-group">
                <label for="percentual_conclusao"><i class="fas fa-chart-line"></i> Percentual de Conclusão (%)</label>
                <input type="number" name="percentual_conclusao" id="percentual_conclusao" value="0" step="0.01" readonly>
                <small><i class="fas fa-info-circle"></i> O percentual será atualizado automaticamente conforme o progresso.</small>
            </div>

            <!-- Tipo de Sala Preferencial -->
            <div class="form-group">
                <label for="tipo_sala_preferencial"><i class="fas fa-door-open"></i> Tipo de Sala Preferencial</label>
                <input type="text" 
                       name="tipo_sala_preferencial" 
                       id="tipo_sala_preferencial" 
                       list="lista_tipos_sala"
                       placeholder="Digite ou selecione um tipo de sala..."
                       style="width: 100%; padding: 10px 14px; border: 1.5px solid #e2e9f3; border-radius: 8px; font-size: 14px; background: #fafcff;"
                       value="<?php echo htmlspecialchars($_POST['tipo_sala_preferencial'] ?? ''); ?>">
                <datalist id="lista_tipos_sala">
                    <?php foreach ($tipos_sala as $tipo): ?>
                        <option value="<?php echo htmlspecialchars($tipo); ?>">
                    <?php endforeach; ?>
                </datalist>
                <small>
                    <i class="fas fa-info-circle"></i> 
                    Digite para criar um novo tipo ou selecione um existente na lista.
                </small>
            </div>

            <!-- Data de Início -->
            <div class="form-row">
                <div class="form-group">
                    <label for="data_inicio_curso"><i class="fas fa-calendar-plus"></i> Data de Início *</label>
                    <input type="date" name="data_inicio_curso" id="data_inicio_curso" required>
                </div>
                <div class="form-group">
                    <label for="turno_curso"><i class="fas fa-sun"></i> Turno *</label>
                    <select name="turno_curso" id="turno_curso" required>
                        <option value="">Selecione o turno</option>
                        <option value="manha">☀️ Manhã</option>
                        <option value="tarde">☀️ Tarde</option>
                        <option value="noite">🌙 Noite</option>
                        <option value="integral">🔄 Integral</option>
                    </select>
                </div>
            </div>

            <!-- Dias da Semana -->
            <div class="form-group">
                <label><i class="fas fa-calendar-week"></i> Dias da Semana *</label>
                <div style="display: flex; gap: 12px; flex-wrap: wrap; padding-top: 4px;">
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="segunda"> Segunda
                    </label>
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="terca"> Terça
                    </label>
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="quarta"> Quarta
                    </label>
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="quinta"> Quinta
                    </label>
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="sexta"> Sexta
                    </label>
                    <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" name="dias_semana[]" value="sabado"> Sábado
                    </label>
                </div>
                <small><i class="fas fa-info-circle"></i> Selecione um ou mais dias da semana em que o curso acontece.</small>
            </div>

            <!-- Tipo de Curso -->
            <div class="form-group">
                <label for="tipo_curso"><i class="fas fa-tag"></i> Tipo de Curso *</label>
                <select name="tipo_curso" id="tipo_curso" required>
                    <option value="">Selecione o tipo</option>
                    <option value="curso_tecnico">📘 Curso Técnico</option>
                    <option value="curso_agil">⚡ Curso Ágil</option>
                    <option value="pos_graduacao">🎓 Pós-graduação</option>
                </select>
            </div>

            <!-- Status do Curso -->
            <div class="form-group">
                <label for="status_curso"><i class="fas fa-circle"></i> Status do Curso</label>
                <select name="status_curso" id="status_curso">
                    <option value="ativo" selected>✅ Ativo</option>
                    <option value="inativo">❌ Inativo</option>
                    <option value="concluido">📌 Concluído</option>
                </select>
                <small><i class="fas fa-info-circle"></i> Defina o status atual do curso.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Cadastrar Curso
                </button>
                <a href="listar_cursos.php" class="btn btn-outline">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
    </div>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>

<script>
    $(document).ready(function() {
        // ============================================================
        // SELECT2 PARA PROFESSOR (AUTOCOMPLETE)
        // ============================================================
        $('#id_docente').select2({
            placeholder: 'Digite o nome do professor...',
            allowClear: true,
            width: '100%',
            language: {
                noResults: function() {
                    return 'Nenhum professor encontrado';
                },
                searching: function() {
                    return 'Buscando...';
                }
            }
        });

        // ============================================================
        // VALIDAR DATA DE INÍCIO (não pode ser no passado)
        // ============================================================
        const dataInput = document.getElementById('data_inicio_curso');
        dataInput.addEventListener('change', function() {
            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);
            const dataSelecionada = new Date(this.value);
            
            if (dataSelecionada < hoje) {
                alert('⚠️ A data de início não pode ser no passado.');
                this.value = '';
            }
        });
    });
</script>
</body>
</html>