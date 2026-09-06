<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/bloquear_usuario.php
// FUNÇÃO: Bloquear profissional (funcionarios) - ativo → bloqueado
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
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem bloquear profissionais.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA
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
// 5. RECEBER ID DO PROFISSIONAL
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do profissional inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. BUSCAR DADOS DO PROFISSIONAL (FILTRADO POR CLIENTE)
// ============================================================

try {
    // ✅ USANDO TABELA funcionarios
    $sql = "SELECT f.*, u.nome_unidade 
            FROM funcionarios f
            LEFT JOIN unidades u ON f.id_unidade = u.id_unidade AND u.id_cliente = f.id_cliente
            WHERE f.id_funcionario = :id 
            AND f.id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        setMessage('error', 'Profissional não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    // Verificar permissão: gerente só pode bloquear profissionais da sua unidade
    if ($tipo_usuario === 'gerente') {
        if ($usuario['id_unidade'] != $id_unidade_usuario) {
            setMessage('error', 'Você não tem permissão para bloquear este profissional.');
            redirect('listar_usuarios.php');
        }
    }

    // Verificar se já está bloqueado
    if ($usuario['status_acesso'] === 'bloqueado') {
        setMessage('warning', 'Este profissional já está bloqueado.');
        redirect('listar_usuarios.php');
    }

    if ($usuario['status_acesso'] === 'inativo') {
        setMessage('error', 'Profissionais inativos não podem ser bloqueados. Aprove primeiro.');
        redirect('listar_usuarios.php');
    }

    // Não permitir bloquear administradores
    if ($usuario['cargo_funcionario'] === 'administrador') {
        setMessage('error', 'Não é possível bloquear um administrador.');
        redirect('listar_usuarios.php');
    }

    // Não permitir bloquear a si mesmo
    if ($usuario['id_funcionario'] == $id_usuario_logado) {
        setMessage('error', 'Você não pode bloquear a si mesmo.');
        redirect('listar_usuarios.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar profissional: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. BLOQUEAR PROFISSIONAL
// ============================================================

try {
    $conn->beginTransaction();

    // ✅ Atualizar na tabela funcionarios
    $sqlUpdate = "UPDATE funcionarios 
                  SET status_acesso = 'bloqueado' 
                  WHERE id_funcionario = :id 
                  AND id_cliente = :id_cliente";
    $stmtUpdate = $conn->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);

    // ============================================================
    // 8. REGISTRAR NO HISTÓRICO DO SISTEMA
    // ============================================================
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
            'funcionarios',
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
                'profissional' => $usuario['nome_funcionario'],
                'email' => $usuario['email_funcionario'],
                'status_anterior' => $usuario['status_acesso'],
                'status_novo' => 'bloqueado',
                'acao' => 'Bloqueio de profissional'
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar bloqueio: ' . $e->getMessage());
    }

    $conn->commit();

    setMessage('success', "Profissional \"" . htmlspecialchars($usuario['nome_funcionario']) . "\" bloqueado com sucesso!");

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Erro ao bloquear profissional: ' . $e->getMessage());
}

// ============================================================
// 9. REDIRECIONAR PARA A LISTAGEM
// ============================================================

redirect('listar_usuarios.php');
exit;