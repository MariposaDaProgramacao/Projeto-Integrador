<?php
// ============================================================
// ARQUIVO: UNIDADES/processar_unidades.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Processar cadastro, edição e exclusão de unidades
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Apenas administradores podem processar unidades
$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem gerenciar unidades.');
    redirect('listar_unidade.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();

// ============================================================
// RECEBER AÇÃO
// ============================================================
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if (empty($acao)) {
    setMessage('error', 'Ação não especificada.');
    redirect('listar_unidade.php');
}

// ============================================================
// FUNÇÃO PARA VALIDAR FUSO HORÁRIO
// ============================================================
function validarFuso($fuso) {
    $fusos_validos = [
        'America/Noronha', 'America/Belem', 'America/Fortaleza', 
        'America/Recife', 'America/Araguaina', 'America/Maceio', 
        'America/Bahia', 'America/Sao_Paulo', 'America/Campo_Grande', 
        'America/Cuiaba', 'America/Santarem', 'America/Porto_Velho', 
        'America/Boa_Vista', 'America/Manaus', 'America/Eirunepe', 
        'America/Rio_Branco'
    ];
    return in_array($fuso, $fusos_validos) ? $fuso : 'America/Sao_Paulo';
}

// ============================================================
// PROCESSAR AÇÃO
// ============================================================
try {
    switch ($acao) {
        // ============================================================
        // CADASTRAR UNIDADE
        // ============================================================
        case 'cadastrar':
            // Receber dados
            $nome = trim($_POST['nome'] ?? '');
            $estado = strtoupper(trim($_POST['estado'] ?? ''));
            $cidade = trim($_POST['cidade'] ?? '');
            $endereco = trim($_POST['endereco'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $status_unidade = $_POST['status_unidade'] ?? 'ativo';
            $fuso = $_POST['fuso'] ?? 'America/Sao_Paulo';

            // Validar campos obrigatórios
            if (empty($nome) || empty($estado) || empty($cidade) || empty($endereco)) {
                setMessage('error', 'Preencha todos os campos obrigatórios.');
                redirect('cadastrar_unidade.php');
            }

            // Validar estado (UF)
            if (strlen($estado) !== 2) {
                setMessage('error', 'UF deve ter 2 caracteres.');
                redirect('cadastrar_unidade.php');
            }

            // Validar fuso horário
            $fuso = validarFuso($fuso);

            // Verificar se já existe unidade com este nome para este cliente
            $sql_check = "SELECT COUNT(*) FROM unidades WHERE nome_unidade = :nome AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':nome' => $nome,
                ':id_cliente' => $id_cliente
            ]);
            if ($stmt_check->fetchColumn() > 0) {
                setMessage('error', 'Já existe uma unidade com este nome nesta organização.');
                redirect('cadastrar_unidade.php');
            }

            // Inserir no banco
            $sql = "INSERT INTO unidades (
                        id_cliente,
                        nome_unidade, 
                        estado_unidade, 
                        cidade_unidade, 
                        endereco_unidade, 
                        telefone_unidade, 
                        email_unidade, 
                        status_unidade,
                        fuso
                    ) VALUES (
                        :id_cliente,
                        :nome, 
                        :estado, 
                        :cidade, 
                        :endereco, 
                        :telefone, 
                        :email, 
                        :status_unidade,
                        :fuso
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id_cliente' => $id_cliente,
                ':nome' => $nome,
                ':estado' => $estado,
                ':cidade' => $cidade,
                ':endereco' => $endereco,
                ':telefone' => $telefone,
                ':email' => $email,
                ':status_unidade' => $status_unidade,
                ':fuso' => $fuso
            ]);

            $id_unidade = $conn->lastInsertId();

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
                    'unidades',
                    :id_registro,
                    'INSERT',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario,
                    ':id_registro' => $id_unidade,
                    ':dados' => json_encode([
                        'nome' => $nome,
                        'estado' => $estado,
                        'cidade' => $cidade,
                        'status' => $status_unidade
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                // Não interrompe o processo
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }

            setMessage('success', "Unidade <strong>" . htmlspecialchars($nome) . "</strong> cadastrada com sucesso!");
            redirect('listar_unidade.php');
            break;

        // ============================================================
        // EDITAR UNIDADE
        // ============================================================
        case 'editar':
            // Receber dados
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $estado = strtoupper(trim($_POST['estado'] ?? ''));
            $cidade = trim($_POST['cidade'] ?? '');
            $endereco = trim($_POST['endereco'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $status_unidade = $_POST['status_unidade'] ?? 'ativo';
            $fuso = $_POST['fuso'] ?? 'America/Sao_Paulo';

            // Validar ID
            if ($id <= 0) {
                setMessage('error', 'ID inválido.');
                redirect('listar_unidade.php');
            }

            // Validar campos obrigatórios
            if (empty($nome) || empty($estado) || empty($cidade) || empty($endereco)) {
                setMessage('error', 'Preencha todos os campos obrigatórios.');
                redirect("editar_unidade.php?id=$id");
            }

            // Validar estado (UF)
            if (strlen($estado) !== 2) {
                setMessage('error', 'UF deve ter 2 caracteres.');
                redirect("editar_unidade.php?id=$id");
            }

            // Validar fuso horário
            $fuso = validarFuso($fuso);

            // Verificar se a unidade pertence ao cliente
            $sql_check = "SELECT id_unidade, nome_unidade, status_unidade FROM unidades 
                          WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $unidade = $stmt_check->fetch();

            if (!$unidade) {
                setMessage('error', 'Unidade não encontrada ou não pertence à sua organização.');
                redirect('listar_unidade.php');
            }

            // Verificar se existe outra unidade com este nome (exceto a atual)
            $sql_check = "SELECT COUNT(*) FROM unidades 
                          WHERE nome_unidade = :nome 
                          AND id_cliente = :id_cliente
                          AND id_unidade != :id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':nome' => $nome,
                ':id_cliente' => $id_cliente,
                ':id' => $id
            ]);
            if ($stmt_check->fetchColumn() > 0) {
                setMessage('error', 'Já existe outra unidade com este nome nesta organização.');
                redirect("editar_unidade.php?id=$id");
            }

            // Atualizar no banco
            $sql = "UPDATE unidades SET 
                        nome_unidade = :nome, 
                        estado_unidade = :estado, 
                        cidade_unidade = :cidade, 
                        endereco_unidade = :endereco, 
                        telefone_unidade = :telefone, 
                        email_unidade = :email, 
                        status_unidade = :status_unidade,
                        fuso = :fuso
                    WHERE id_unidade = :id AND id_cliente = :id_cliente";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':estado' => $estado,
                ':cidade' => $cidade,
                ':endereco' => $endereco,
                ':telefone' => $telefone,
                ':email' => $email,
                ':status_unidade' => $status_unidade,
                ':fuso' => $fuso,
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
                    'unidades',
                    :id_registro,
                    'UPDATE',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario,
                    ':id_registro' => $id,
                    ':dados' => json_encode([
                        'nome_anterior' => $unidade['nome_unidade'],
                        'status_anterior' => $unidade['status_unidade'],
                        'nome_novo' => $nome,
                        'status_novo' => $status_unidade
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                // Não interrompe o processo
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }

            setMessage('success', "Unidade <strong>" . htmlspecialchars($nome) . "</strong> atualizada com sucesso!");
            redirect('listar_unidade.php');
            break;

        // ============================================================
        // EXCLUIR UNIDADE (NÃO RECOMENDADO - USAR INATIVAR)
        // ============================================================
        case 'excluir':
            $id = (int)($_GET['id'] ?? 0);

            // Validar ID
            if ($id <= 0) {
                setMessage('error', 'ID inválido.');
                redirect('listar_unidade.php');
            }

            // Verificar se a unidade pertence ao cliente
            $sql_check = "SELECT nome_unidade FROM unidades 
                          WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':id' => $id,
                ':id_cliente' => $id_cliente
            ]);
            $unidade = $stmt_check->fetch();

            if (!$unidade) {
                setMessage('error', 'Unidade não encontrada.');
                redirect('listar_unidade.php');
            }

            // Verificar se a unidade tem dependências
            $sql_check = "SELECT COUNT(*) FROM cursos WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id]);
            $cursos_count = $stmt_check->fetchColumn();

            $sql_check = "SELECT COUNT(*) FROM salas WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id]);
            $salas_count = $stmt_check->fetchColumn();

            $sql_check = "SELECT COUNT(*) FROM funcionarios WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id]);
            $funcionarios_count = $stmt_check->fetchColumn();

            $sql_check = "SELECT COUNT(*) FROM recessos WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id]);
            $recessos_count = $stmt_check->fetchColumn();

            // Se houver dependências, não permite excluir
            if ($cursos_count > 0 || $salas_count > 0 || $funcionarios_count > 0 || $recessos_count > 0) {
                $mensagem = 'Não é possível excluir esta unidade pois existem registros vinculados:';
                if ($cursos_count > 0) $mensagem .= " <strong>{$cursos_count}</strong> curso(s),";
                if ($salas_count > 0) $mensagem .= " <strong>{$salas_count}</strong> sala(s),";
                if ($funcionarios_count > 0) $mensagem .= " <strong>{$funcionarios_count}</strong> funcionário(s),";
                if ($recessos_count > 0) $mensagem .= " <strong>{$recessos_count}</strong> recesso(s)";
                $mensagem = rtrim($mensagem, ',');
                setMessage('error', $mensagem);
                redirect('listar_unidade.php');
            }

            // Excluir a unidade
            $sql = "DELETE FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
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
                    dados_anteriores,
                    ip_origem
                ) VALUES (
                    :id_funcionario,
                    'unidades',
                    :id_registro,
                    'DELETE',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => $id_usuario,
                    ':id_registro' => $id,
                    ':dados' => json_encode([
                        'nome_unidade' => $unidade['nome_unidade']
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                // Não interrompe o processo
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }

            setMessage('success', "Unidade <strong>" . htmlspecialchars($unidade['nome_unidade']) . "</strong> excluída com sucesso!");
            redirect('listar_unidade.php');
            break;

        // ============================================================
        // AÇÃO INVÁLIDA
        // ============================================================
        default:
            setMessage('error', 'Ação inválida.');
            redirect('listar_unidade.php');
            break;
    }

} catch (PDOException $e) {
    // Erro de banco de dados
    setMessage('error', 'Erro no banco de dados: ' . $e->getMessage());
    redirect('listar_unidade.php');
} catch (Exception $e) {
    // Outros erros
    setMessage('error', 'Erro: ' . $e->getMessage());
    redirect('listar_unidade.php');
}
?>