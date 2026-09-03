<?php
// ============================================================
// ARQUIVO: SALAS/excluir_sala.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Excluir uma sala do sistema
// ============================================================

// ============================================================
// INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================
session_start();
require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// PERMISSÕES - APENAS ADMINISTRADOR E GERENTE (NOVO SISTEMA)
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem excluir salas.');
    redirect('listar_salas.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// ============================================================
// PROCESSAR EXCLUSÃO
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" || $_SERVER["REQUEST_METHOD"] == "GET") {
    
    // Receber o ID da sala (via POST ou GET)
    $id_sala = isset($_POST['id_sala']) ? (int)$_POST['id_sala'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    
    if ($id_sala <= 0) {
        setMessage('error', 'ID da sala inválido.');
        redirect('listar_salas.php');
    }
    
    try {
        // ============================================================
        // VERIFICAR SE A SALA PERTENCE AO CLIENTE
        // ============================================================
        $sqlCheck = "SELECT id_sala, id_unidade, numero_sala, tipo_sala 
                     FROM salas 
                     WHERE id_sala = :id_sala 
                     AND id_cliente = :id_cliente";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente
        ]);
        $sala = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$sala) {
            setMessage('error', 'Sala não encontrada ou não pertence à sua organização.');
            redirect('listar_salas.php');
        }
        
        // ============================================================
        // VERIFICAR SE O GERENTE TEM PERMISSÃO PARA EXCLUIR ESTA SALA
        // ============================================================
        if ($tipo_usuario === 'gerente' && $sala['id_unidade'] != $id_unidade_usuario) {
            setMessage('error', 'Você não tem permissão para excluir esta sala.');
            redirect('listar_salas.php');
        }
        
        // ============================================================
        // VERIFICAR SE A SALA POSSUI AULAS AGENDADAS
        // ============================================================
        $sqlAulas = "SELECT COUNT(*) as total 
                     FROM cronograma 
                     WHERE id_sala = :id_sala 
                     AND id_cliente = :id_cliente
                     AND status_aula IN ('agendada', 'remarcada', 'aguardando_remarcacao')";
        $stmtAulas = $conn->prepare($sqlAulas);
        $stmtAulas->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente
        ]);
        $aulasPendentes = $stmtAulas->fetchColumn();
        
        if ($aulasPendentes > 0) {
            setMessage('error', "Não é possível excluir esta sala. Ela possui <strong>{$aulasPendentes} aula(s)</strong> pendentes. Remova as aulas primeiro ou altere o status da sala para 'Inativa'.");
            redirect('listar_salas.php');
        }
        
        // ============================================================
        // VERIFICAR SE A SALA POSSUI MANUTENÇÕES ATIVAS
        // ============================================================
        $sqlManut = "SELECT COUNT(*) as total 
                     FROM manutencoes 
                     WHERE id_sala = :id_sala 
                     AND id_cliente = :id_cliente
                     AND status != 'concluida'";
        $stmtManut = $conn->prepare($sqlManut);
        $stmtManut->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente
        ]);
        $manutencoesAtivas = $stmtManut->fetchColumn();
        
        if ($manutencoesAtivas > 0) {
            setMessage('error', "Não é possível excluir esta sala. Ela possui <strong>{$manutencoesAtivas} manutenção(ões)</strong> ativa(s). Conclua ou remova as manutenções primeiro.");
            redirect('listar_salas.php');
        }
        
        // ============================================================
        // EXCLUIR A SALA
        // ============================================================
        $sql = "DELETE FROM salas WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente
        ]);
        
        // ============================================================
        // REGISTRAR NO HISTÓRICO
        // ============================================================
        try {
            $sqlHistorico = "INSERT INTO historico_sistema (
                id_funcionario,
                tabela_afetada,
                id_registro_afetado,
                acao,
                dados_anteriores,
                ip_origem
            ) VALUES (
                :id_funcionario,
                'salas',
                :id_registro,
                'DELETE',
                :dados,
                :ip
            )";
            $stmtHistorico = $conn->prepare($sqlHistorico);
            $stmtHistorico->execute([
                ':id_funcionario' => $id_usuario,
                ':id_registro' => $id_sala,
                ':dados' => json_encode([
                    'numero_sala' => $sala['numero_sala'],
                    'tipo_sala' => $sala['tipo_sala'],
                    'id_unidade' => $sala['id_unidade']
                ]),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
        } catch (PDOException $e) {
            // Não interrompe o processo
            error_log('Erro ao registrar histórico: ' . $e->getMessage());
        }
        
        setMessage('success', "Sala <strong>{$sala['numero_sala']}</strong> excluída com sucesso!");
        
    } catch (PDOException $e) {
        setMessage('error', 'Erro ao excluir sala: ' . $e->getMessage());
    }
    
} else {
    setMessage('error', 'Método inválido para exclusão.');
}

// ============================================================
// REDIRECIONAR PARA LISTAGEM
// ============================================================
redirect('listar_salas.php');
exit;
?>