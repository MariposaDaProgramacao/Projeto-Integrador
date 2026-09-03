<?php
// ============================================================
// ARQUIVO: RECESSOS/cadastrar_recesso.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Formulário para registrar recesso/feriado
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CARREGAR CONEXÃO E FUNÇÕES
// ============================================================
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
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem registrar recessos.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
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
// FUNÇÃO PARA BUSCAR DIAS DE RECESSO DA UNIDADE (MODIFICADA)
// ============================================================
function buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso = null) {
    try {
        $sql = "SELECT data_inicio, data_fim, id_cursos 
                FROM recessos 
                WHERE id_unidade = :id_unidade 
                AND id_cliente = :id_cliente
                AND ativo = 1";
        
        $params = [
            ':id_unidade' => $id_unidade,
            ':id_cliente' => $id_cliente
        ];
        
        if ($id_curso) {
            $sql .= " AND (id_cursos IS NULL OR FIND_IN_SET(:id_curso, id_cursos) > 0)";
            $params[':id_curso'] = $id_curso;
        }
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $recessos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $diasRecesso = [];
        foreach ($recessos as $recesso) {
            $dataInicio = new DateTime($recesso['data_inicio']);
            $dataFim = new DateTime($recesso['data_fim']);
            $dataFim->modify('+1 day');
            
            while ($dataInicio < $dataFim) {
                $diasRecesso[] = $dataInicio->format('Y-m-d');
                $dataInicio->modify('+1 day');
            }
        }
        
        return $diasRecesso;
    } catch (PDOException $e) {
        return [];
    }
}

// ============================================================
// FUNÇÃO PARA EMPURRAR AULAS PARA FRENTE (MODIFICADA)
// ============================================================
function empurrarAulas($conn, $data_inicio, $data_fim, $id_unidade, $id_cliente, $turno_curso = null, $tipo_curso = null, $cursos_selecionados = []) {
    try {
        $diasRecesso = buscarDiasRecesso($conn, $id_unidade, $id_cliente);
        
        $sqlBuscar = "SELECT c.id_aula, c.data_aula, c.id_curso, c.id_sala, 
                              c.horario_inicio, c.horario_fim, c.turno,
                              cu.dias_semana, cu.id_unidade
                      FROM cronograma c
                      INNER JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                      WHERE c.data_aula BETWEEN :data_inicio AND :data_fim
                      AND c.status_aula IN ('agendada', 'remarcada')
                      AND cu.id_unidade = :id_unidade
                      AND c.id_cliente = :id_cliente";
        
        $params = [
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim,
            ':id_unidade' => $id_unidade,
            ':id_cliente' => $id_cliente
        ];
        
        if (!empty($turno_curso)) {
            $sqlBuscar .= " AND c.turno = :turno_curso";
            $params[':turno_curso'] = $turno_curso;
        }
        
        if (!empty($tipo_curso)) {
            $sqlBuscar .= " AND cu.tipo_curso = :tipo_curso";
            $params[':tipo_curso'] = $tipo_curso;
        }
        
        if (!empty($cursos_selecionados)) {
            $placeholders = [];
            foreach ($cursos_selecionados as $i => $id) {
                $paramName = ":curso_{$i}";
                $placeholders[] = $paramName;
                $params[$paramName] = $id;
            }
            $sqlBuscar .= " AND c.id_curso IN (" . implode(',', $placeholders) . ")";
        }
        
        $sqlBuscar .= " ORDER BY c.data_aula ASC, c.horario_inicio ASC";
        
        $stmtBuscar = $conn->prepare($sqlBuscar);
        foreach ($params as $key => $value) {
            $stmtBuscar->bindValue($key, $value);
        }
        $stmtBuscar->execute();
        $aulas = $stmtBuscar->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($aulas)) {
            return [
                'success' => true,
                'aulas_atualizadas' => 0,
                'aulas_aguardando' => 0,
                'cursos_afetados' => [],
                'message' => 'Nenhuma aula encontrada no período do recesso com os filtros selecionados.'
            ];
        }
        
        $aulasAtualizadas = 0;
        $cursosAfetados = [];
        $aulasComErro = [];
        
        foreach ($aulas as $aula) {
            $dataOriginal = $aula['data_aula'];
            $dataAtual = new DateTime($dataOriginal);
            $dataAtual->modify('+1 day');
            $encontrouData = false;
            $tentativas = 0;
            $maxTentativas = 365;
            
            $diasSemanaMap = [
                'segunda' => 1, 'terca' => 2, 'quarta' => 3,
                'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
            ];
            
            $diasSemanaArray = explode(',', $aula['dias_semana']);
            $diasPermitidos = [];
            foreach ($diasSemanaArray as $dia) {
                $dia = trim($dia);
                if (isset($diasSemanaMap[$dia])) {
                    $diasPermitidos[] = $diasSemanaMap[$dia];
                }
            }
            
            while (!$encontrouData && $tentativas < $maxTentativas) {
                $diaSemana = (int)$dataAtual->format('N');
                $dataStr = $dataAtual->format('Y-m-d');
                
                if (!in_array($diaSemana, $diasPermitidos)) {
                    $dataAtual->modify('+1 day');
                    $tentativas++;
                    continue;
                }
                
                if (in_array($dataStr, $diasRecesso)) {
                    $dataAtual->modify('+1 day');
                    $tentativas++;
                    continue;
                }
                
                $sqlVerificar = "SELECT COUNT(*) FROM cronograma 
                                WHERE id_sala = :id_sala 
                                AND data_aula = :data_aula 
                                AND id_cliente = :id_cliente
                                AND id_aula != :id_aula
                                AND ((horario_inicio < :horario_fim AND horario_fim > :horario_inicio))";
                $stmtVerificar = $conn->prepare($sqlVerificar);
                $stmtVerificar->execute([
                    ':id_sala' => $aula['id_sala'],
                    ':data_aula' => $dataStr,
                    ':id_cliente' => $id_cliente,
                    ':id_aula' => $aula['id_aula'],
                    ':horario_inicio' => $aula['horario_inicio'],
                    ':horario_fim' => $aula['horario_fim']
                ]);
                $conflito = $stmtVerificar->fetchColumn();
                
                if ($conflito == 0) {
                    $encontrouData = true;
                    break;
                }
                
                $dataAtual->modify('+1 day');
                $tentativas++;
            }
            
            if ($encontrouData) {
                $novaData = $dataAtual->format('Y-m-d');
                $sqlUpdate = "UPDATE cronograma SET 
                                data_aula = :nova_data,
                                observacao = CONCAT(IFNULL(observacao, ''), ' | Recesso em ', :data_original, ' - Aula remanejada para ', :nova_data)
                              WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':nova_data' => $novaData,
                    ':data_original' => date('d/m/Y', strtotime($dataOriginal)),
                    ':id_aula' => $aula['id_aula'],
                    ':id_cliente' => $id_cliente
                ]);
                
                $aulasAtualizadas++;
                if (!in_array($aula['id_curso'], $cursosAfetados)) {
                    $cursosAfetados[] = $aula['id_curso'];
                }
            } else {
                $sqlUpdate = "UPDATE cronograma SET 
                                status_aula = 'aguardando_remarcacao',
                                observacao = CONCAT(IFNULL(observacao, ''), ' | Recesso em ', :data_original, ' - Aguardando remarcação')
                              WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':data_original' => date('d/m/Y', strtotime($dataOriginal)),
                    ':id_aula' => $aula['id_aula'],
                    ':id_cliente' => $id_cliente
                ]);
                
                $aulasComErro[] = $aula['id_aula'];
            }
        }
        
        foreach ($cursosAfetados as $id_curso) {
            recalcularDataFimCurso($conn, $id_curso, $id_cliente);
        }
        
        $mensagem = "$aulasAtualizadas aula(s) remanejadas com sucesso";
        if (!empty($aulasComErro)) {
            $mensagem .= " e " . count($aulasComErro) . " aula(s) marcadas como 'Aguardando Remarcação' (não foi possível encontrar data disponível)";
        }
        
        return [
            'success' => true,
            'aulas_atualizadas' => $aulasAtualizadas,
            'aulas_aguardando' => count($aulasComErro),
            'cursos_afetados' => $cursosAfetados,
            'message' => $mensagem
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao remanejar aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// FUNÇÃO PARA RECALCULAR DATA DE FIM DO CURSO (MODIFICADA)
// ============================================================
function recalcularDataFimCurso($conn, $id_curso, $id_cliente) {
    try {
        $sql = "SELECT MAX(data_aula) as ultima_data FROM cronograma 
                WHERE id_curso = :id_curso 
                AND id_cliente = :id_cliente
                AND status_aula NOT IN ('cancelada')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resultado && $resultado['ultima_data']) {
            $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':data_fim' => $resultado['ultima_data'],
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            return true;
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// BUSCAR UNIDADES (FILTRADAS POR CLIENTE)
// ============================================================
try {
    if ($tipo_usuario === 'admin_cliente') {
        $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? AND status_unidade = 'ativo' ORDER BY nome_unidade";
        $stmtUnidades = $conn->prepare($sqlUnidades);
        $stmtUnidades->execute([$id_cliente]);
        $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
        $stmtUnidades = $conn->prepare($sqlUnidades);
        $stmtUnidades->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $unidades = [];
}

// ============================================================
// BUSCAR CURSOS PARA CHECKBOX (FILTRADOS POR CLIENTE)
// ============================================================
try {
    if ($tipo_usuario === 'admin_cliente') {
        $sqlCursos = "SELECT id_curso, nome_curso, numero_curso, turno_curso, tipo_curso,
                             data_inicio_curso, data_fim_curso_calculada, dias_letivos,
                             dias_semana, status_curso
                      FROM cursos WHERE id_cliente = :id_cliente AND status_curso = 'ativo' 
                      ORDER BY numero_curso, nome_curso";
        $stmtCursos = $conn->prepare($sqlCursos);
        $stmtCursos->execute([':id_cliente' => $id_cliente]);
    } else {
        $sqlCursos = "SELECT id_curso, nome_curso, numero_curso, turno_curso, tipo_curso,
                             data_inicio_curso, data_fim_curso_calculada, dias_letivos,
                             dias_semana, status_curso
                      FROM cursos WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente AND status_curso = 'ativo' 
                      ORDER BY numero_curso, nome_curso";
        $stmtCursos = $conn->prepare($sqlCursos);
        $stmtCursos->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
    }
    $cursos = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cursos = [];
}

// ============================================================
// PROCESSAR FORMULÁRIO
// ============================================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_recesso = trim($_POST['nome_recesso'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $tipo = $_POST['tipo'] ?? 'feriado';
    $tipo_periodo = $_POST['tipo_periodo'] ?? 'unico';
    $data_unica = $_POST['data_unica'] ?? '';
    $data_inicio = $_POST['data_inicio'] ?? '';
    $data_fim = $_POST['data_fim'] ?? '';
    $id_unidade = !empty($_POST['id_unidade']) ? (int)$_POST['id_unidade'] : null;
    
    $turno_curso = isset($_POST['turno_curso']) ? implode(',', $_POST['turno_curso']) : null;
    $tipo_curso = isset($_POST['tipo_curso']) ? implode(',', $_POST['tipo_curso']) : null;
    $cursos_selecionados = isset($_POST['cursos']) ? $_POST['cursos'] : [];
    $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
    
    if ($tipo_periodo === 'unico') {
        $data_inicio = $data_unica;
        $data_fim = $data_unica;
    }
    
    $erros = [];
    if (empty($nome_recesso)) $erros[] = 'Nome do recesso é obrigatório.';
    if (empty($data_inicio)) $erros[] = 'Data é obrigatória.';
    if (empty($data_fim)) $erros[] = 'Data de fim é obrigatória.';
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            $id_cursos = !empty($cursos_selecionados) ? implode(',', $cursos_selecionados) : null;
            
            $sqlInsert = "INSERT INTO recessos (
                            nome_recesso, descricao, tipo, data_inicio, data_fim, ano,
                            id_unidade, id_cliente, turno_curso, tipo_curso, dias_semana, id_cursos, ativo
                        ) VALUES (
                            :nome_recesso, :descricao, :tipo, :data_inicio, :data_fim, :ano,
                            :id_unidade, :id_cliente, :turno_curso, :tipo_curso, :dias_semana, :id_cursos, 1
                        )";
            
            $stmtInsert = $conn->prepare($sqlInsert);
            $stmtInsert->execute([
                ':nome_recesso' => $nome_recesso,
                ':descricao' => $descricao,
                ':tipo' => $tipo,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim,
                ':ano' => date('Y', strtotime($data_inicio)),
                ':id_unidade' => $id_unidade,
                ':id_cliente' => $id_cliente,
                ':turno_curso' => $turno_curso,
                ':tipo_curso' => $tipo_curso,
                ':dias_semana' => $dias_semana,
                ':id_cursos' => $id_cursos
            ]);
            
            $id_recesso = $conn->lastInsertId();
            
            $unidadeParaEmpurrar = $id_unidade ?? $id_unidade_usuario;
            
            $resultadoEmpurrar = empurrarAulas(
                $conn, 
                $data_inicio, 
                $data_fim, 
                $unidadeParaEmpurrar,
                $id_cliente,
                $turno_curso,
                $tipo_curso,
                $cursos_selecionados
            );
            
            if ($resultadoEmpurrar['success']) {
                $aulasAfetadas = $resultadoEmpurrar['aulas_atualizadas'] ?? 0;
                $aulasAguardando = $resultadoEmpurrar['aulas_aguardando'] ?? 0;
                $cursosAfetados = $resultadoEmpurrar['cursos_afetados'] ?? [];
                $mensagemAulas = $resultadoEmpurrar['message'];
            } else {
                throw new Exception($resultadoEmpurrar['message']);
            }
            
            $cursosAfetados = array_unique($cursosAfetados);
            $cursosAtualizados = 0;
            
            foreach ($cursosAfetados as $id_curso) {
                if (recalcularDataFimCurso($conn, $id_curso, $id_cliente)) {
                    $cursosAtualizados++;
                }
            }
            
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
                    'recessos',
                    :id_registro,
                    'INSERT',
                    :dados,
                    :ip
                )";
                $stmtHistorico = $conn->prepare($sqlHistorico);
                $stmtHistorico->execute([
                    ':id_funcionario' => getUsuarioId(),
                    ':id_registro' => $id_recesso,
                    ':dados' => json_encode([
                        'nome' => $nome_recesso,
                        'tipo' => $tipo,
                        'data_inicio' => $data_inicio,
                        'data_fim' => $data_fim,
                        'id_unidade' => $id_unidade,
                        'cursos_afetados' => $cursos_selecionados,
                        'turnos' => $turno_curso,
                        'tipos_curso' => $tipo_curso
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (PDOException $e) {
                // Não interrompe o processo
                error_log('Erro ao registrar histórico: ' . $e->getMessage());
            }
            
            $conn->commit();
            
            $mensagem_sucesso = "✅ Recesso '{$nome_recesso}' registrado com sucesso!";
            
            if (!empty($cursos_selecionados)) {
                $nomesCursos = [];
                foreach ($cursos as $curso) {
                    if (in_array($curso['id_curso'], $cursos_selecionados)) {
                        $nomesCursos[] = $curso['numero_curso'] . ' - ' . $curso['nome_curso'];
                    }
                }
                $mensagem_sucesso .= "<br>📚 <strong>Cursos afetados:</strong> " . implode(', ', $nomesCursos);
            } else {
                $mensagem_sucesso .= "<br>📚 <strong>Cursos afetados:</strong> Todos os cursos";
            }
            
            if (!empty($turno_curso)) {
                $turnosLabels = [
                    'manha' => 'Manhã',
                    'tarde' => 'Tarde',
                    'noite' => 'Noite',
                    'integral' => 'Integral'
                ];
                $turnosArray = explode(',', $turno_curso);
                $turnosNomes = [];
                foreach ($turnosArray as $t) {
                    $turnosNomes[] = $turnosLabels[$t] ?? $t;
                }
                $mensagem_sucesso .= "<br>🕐 <strong>Turnos afetados:</strong> " . implode(', ', $turnosNomes);
            }
            
            if (!empty($tipo_curso)) {
                $tiposLabels = [
                    'curso_tecnico' => 'Técnico',
                    'curso_agil' => 'Ágil',
                    'pos_graduacao' => 'Pós-graduação'
                ];
                $tiposArray = explode(',', $tipo_curso);
                $tiposNomes = [];
                foreach ($tiposArray as $t) {
                    $tiposNomes[] = $tiposLabels[$t] ?? $t;
                }
                $mensagem_sucesso .= "<br>📖 <strong>Tipos de curso afetados:</strong> " . implode(', ', $tiposNomes);
            }
            
            if ($aulasAfetadas > 0) {
                $mensagem_sucesso .= "<br>📅 <strong>{$aulasAfetadas} aula(s)</strong> foram <strong>remanejadas</strong> para os próximos dias úteis.";
            }
            
            if ($aulasAguardando > 0) {
                $mensagem_sucesso .= "<br>⏳ <strong>{$aulasAguardando} aula(s)</strong> foram marcadas como <strong>'Aguardando Remarcação'</strong>.";
            }
            
            if ($cursosAtualizados > 0) {
                $mensagem_sucesso .= "<br>📅 <strong>Data de fim de " . $cursosAtualizados . " curso(s)</strong> foi atualizada automaticamente.";
            }
            
            if ($aulasAfetadas == 0 && $aulasAguardando == 0) {
                $mensagem_sucesso .= "<br>📝 Nenhuma aula foi afetada pelo recesso com os filtros selecionados.";
            }
            
            $mensagem_sucesso .= "<br>📌 <strong>Dias de recesso serão automaticamente ignorados no agendamento.</strong>";
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $mensagem_erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $mensagem_erro = '❌ Erro ao registrar recesso: ' . $e->getMessage();
        }
    } else {
        $mensagem_erro = '⚠️ ' . implode(' ', $erros);
    }
}

$tiposRecesso = [
    'feriado' => 'Feriado',
    'recesso' => 'Recesso',
    'ponto_facultativo' => 'Ponto Facultativo',
    'paralisacao' => 'Paralisação'
];

$turnosLista = [
    'manha' => '☀️ Manhã',
    'tarde' => '🌤️ Tarde',
    'noite' => '🌙 Noite',
    'integral' => '🔄 Integral'
];

$tiposCursoLista = [
    'curso_tecnico' => '📘 Técnico',
    'curso_agil' => '📗 Ágil',
    'pos_graduacao' => '📕 Pós-graduação'
];

$diasSemana = [
    'segunda' => 'Segunda',
    'terca' => 'Terça',
    'quarta' => 'Quarta',
    'quinta' => 'Quinta',
    'sexta' => 'Sexta',
    'sabado' => 'Sábado',
    'domingo' => 'Domingo'
];

$titulo = 'Registrar Recesso - Gerenciamento de Ambientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
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
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-title { font-size: 24px; font-weight: 700; color: #0e1a2b; }
        .page-title i { color: #1a73e8; margin-right: 10px; }
        .page-subtitle { font-size: 14px; color: #7a8aa0; margin-top: 4px; }
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
        }
        .btn-secondary { background: #e2e9f3; color: #1a2639; border: 1px solid #d8e0ec; }
        .btn-secondary:hover { background: #d0dbe8; }
        .btn-primary { background: #1a73e8; color: #ffffff; border: none; box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35); }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }
        
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2d3a4f;
            margin-bottom: 5px;
        }
        .form-group label i { margin-right: 6px; color: #1a73e8; }
        .form-group label .required { color: #dc3545; margin-left: 2px; }
        .form-group label .optional { color: #7a8aa0; font-weight: 400; font-size: 12px; margin-left: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            background: #fafcff;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
            outline: none;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
            flex-wrap: wrap;
            padding-top: 20px;
            border-top: 2px solid #f0f4fb;
        }
        
        .periodo-opcoes {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding: 12px 16px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #e2e9f3;
        }
        .periodo-opcoes label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            cursor: pointer;
            padding: 6px 14px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .periodo-opcoes label:hover { background: #e3f2fd; }
        .periodo-opcoes input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #1a73e8;
        }
        .periodo-opcoes label.selecionado {
            background: #e3f2fd;
            border: 1px solid #1a73e8;
        }
        
        .campo-periodo { display: block; }
        .campo-periodo.hidden { display: none; }
        
        .checkbox-grid-cursos {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 10px;
            padding: 14px 16px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #e2e9f3;
            max-height: 350px;
            overflow-y: auto;
        }
        .checkbox-grid-cursos label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 14px;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e8edf5;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }
        .checkbox-grid-cursos label:hover {
            border-color: #1a73e8;
            background: #f0f7ff;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .checkbox-grid-cursos label.selecionado {
            border-color: #1a73e8;
            background: #e3f2fd;
            box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.15);
        }
        .checkbox-grid-cursos input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #1a73e8;
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .checkbox-grid-cursos .curso-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .checkbox-grid-cursos .curso-info .numero-destaque {
            font-size: 16px;
            font-weight: 700;
            color: #1a73e8;
            margin-right: 6px;
        }
        .checkbox-grid-cursos .curso-info .nome {
            font-weight: 600;
            color: #0e1a2b;
            font-size: 14px;
        }
        .checkbox-grid-cursos .curso-info .detalhe {
            font-size: 12px;
            color: #5a6a7e;
            margin-top: 3px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 12px;
            align-items: center;
        }
        .checkbox-grid-cursos .curso-info .detalhe i {
            margin-right: 3px;
            color: #1a73e8;
            font-size: 11px;
        }
        .checkbox-grid-cursos .badge-turno-pequeno {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-turno-manha { background: #fff3cd; color: #856404; }
        .badge-turno-tarde { background: #cce5ff; color: #004085; }
        .badge-turno-noite { background: #d6d8db; color: #383d41; }
        .badge-turno-integral { background: #e8f5e9; color: #1b5e20; }
        
        .checkbox-grid-simples {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 10px 14px;
            background: #f8faff;
            border-radius: 10px;
            border: 1px solid #e2e9f3;
        }
        .checkbox-grid-simples label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid #e8edf5;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }
        .checkbox-grid-simples label:hover {
            border-color: #1a73e8;
            background: #f0f7ff;
        }
        .checkbox-grid-simples label.selecionado {
            border-color: #1a73e8;
            background: #e3f2fd;
        }
        .checkbox-grid-simples input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #1a73e8;
            cursor: pointer;
        }
        
        .info-box {
            background: #f8faff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid #1a73e8;
        }
        .info-box p { font-size: 13px; color: #5a6a7e; margin: 4px 0; }
        .info-box strong { color: #0e1a2b; }
        .info-box.warning { border-left-color: #ffc107; background: #fff8e1; }
        .info-box.danger { border-left-color: #dc3545; background: #ffe9e9; }
        .info-box.success { border-left-color: #28a745; background: #e6f7e9; }
        
        .alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
        .alert-danger { background: #ffe9e9; color: #b33a3a; border: 1px solid #ffd6d6; }
        .alert-success { background: #e6f7e9; color: #1e8546; border: 1px solid #c8f0cf; }
        .alert i { font-size: 18px; }

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
        .footer-system { text-align: center; font-size: 12px; color: #8a9bb5; padding: 16px 0 8px; border-top: 1px solid #e2e9f3; margin-top: auto; background: transparent; flex-shrink: 0; }

        .badge-info-box {
            display: inline-block;
            background: #e3f2fd;
            color: #0d47a1;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .selecao-resumo {
            margin-top: 8px;
            padding: 10px 16px;
            background: #e8f5e9;
            border-radius: 8px;
            border-left: 4px solid #43a047;
            font-size: 13px;
            color: #1b5e20;
            display: none;
        }
        .selecao-resumo.active {
            display: block;
        }
        .selecao-resumo i {
            margin-right: 6px;
            color: #43a047;
        }

        @media (max-width: 640px) {
            .main { padding: 16px; }
            .card-panel { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .checkbox-grid-cursos { grid-template-columns: 1fr; }
            .periodo-opcoes { flex-direction: column; gap: 8px; }
            .checkbox-grid-simples { flex-direction: column; }
        }
        @media (max-width: 820px) {
            .sidebar { display: none; }
        }
    </style>
    
    <script>
        // MANTIDO O MESMO JAVASCRIPT DO SEU ARQUIVO ORIGINAL
        function togglePeriodo() {
            const tipoPeriodo = document.querySelector('input[name="tipo_periodo"]:checked').value;
            const campoUnico = document.getElementById('campoUnico');
            const campoPeriodo = document.getElementById('campoPeriodo');
            const dataUnica = document.getElementById('data_unica');
            const dataInicio = document.getElementById('data_inicio');
            const dataFim = document.getElementById('data_fim');
            
            if (tipoPeriodo === 'unico') {
                campoUnico.classList.remove('hidden');
                campoPeriodo.classList.add('hidden');
                dataUnica.required = true;
                dataInicio.required = false;
                dataFim.required = false;
                dataInicio.value = '';
                dataFim.value = '';
            } else {
                campoUnico.classList.add('hidden');
                campoPeriodo.classList.remove('hidden');
                dataUnica.required = false;
                dataInicio.required = true;
                dataFim.required = true;
                dataUnica.value = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.periodo-opcoes input[type="radio"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.periodo-opcoes label').forEach(function(label) {
                        label.classList.remove('selecionado');
                    });
                    this.closest('label').classList.add('selecionado');
                    togglePeriodo();
                });
            });
            
            const radioUnico = document.querySelector('.periodo-opcoes input[value="unico"]');
            if (radioUnico) {
                radioUnico.checked = true;
                radioUnico.closest('label').classList.add('selecionado');
                togglePeriodo();
            }
            
            document.querySelectorAll('.checkbox-grid-cursos input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    var label = this.closest('label');
                    if (this.checked) {
                        label.classList.add('selecionado');
                    } else {
                        label.classList.remove('selecionado');
                    }
                    atualizarResumo();
                });
            });
            
            document.querySelectorAll('.checkbox-grid-simples input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    var label = this.closest('label');
                    if (this.checked) {
                        label.classList.add('selecionado');
                    } else {
                        label.classList.remove('selecionado');
                    }
                    atualizarResumo();
                });
            });
            
            function atualizarResumo() {
                var cursosSelecionados = document.querySelectorAll('.checkbox-grid-cursos input[type="checkbox"]:checked');
                var turnosSelecionados = document.querySelectorAll('.checkbox-grid-simples input[name="turno_curso[]"]:checked');
                var tiposSelecionados = document.querySelectorAll('.checkbox-grid-simples input[name="tipo_curso[]"]:checked');
                var resumo = document.getElementById('selecaoResumo');
                var texto = '';
                
                if (cursosSelecionados.length > 0 || turnosSelecionados.length > 0 || tiposSelecionados.length > 0) {
                    texto = '📌 ';
                    if (cursosSelecionados.length > 0) {
                        texto += cursosSelecionados.length + ' curso(s) ';
                    }
                    if (turnosSelecionados.length > 0) {
                        if (cursosSelecionados.length > 0) texto += '• ';
                        texto += turnosSelecionados.length + ' turno(s) ';
                    }
                    if (tiposSelecionados.length > 0) {
                        if (cursosSelecionados.length > 0 || turnosSelecionados.length > 0) texto += '• ';
                        texto += tiposSelecionados.length + ' tipo(s) de curso';
                    }
                    texto += ' selecionados';
                    resumo.textContent = texto;
                    resumo.classList.add('active');
                } else {
                    resumo.classList.remove('active');
                }
            }
            
            setTimeout(atualizarResumo, 100);
            
            document.getElementById('data_fim').addEventListener('change', function() {
                const inicio = document.getElementById('data_inicio').value;
                const fim = this.value;
                if (inicio && fim && fim < inicio) {
                    alert('⚠️ A data de fim não pode ser anterior à data de início.');
                    this.value = '';
                }
            });

            document.getElementById('data_inicio').addEventListener('change', function() {
                const fim = document.getElementById('data_fim').value;
                const inicio = this.value;
                if (inicio && fim && fim < inicio) {
                    alert('⚠️ A data de fim não pode ser anterior à data de início.');
                    document.getElementById('data_fim').value = '';
                }
            });

            document.getElementById('formRecesso').addEventListener('submit', function(e) {
                const btn = document.getElementById('btnSalvar');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando...';
            });
        });
    </script>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-calendar-times"></i> Registrar Recesso</h1>
                <p class="page-subtitle">Registre feriados, recessos ou paralisação de aulas</p>
            </div>
            <a href="listar_recesso.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Ver Recessos
            </a>
        </header>

        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; border-left: 4px solid #28a745; padding: 16px 20px; border-radius: 8px; background: #f0faf3;">
                <i class="fas fa-check-circle" style="color: #28a745; font-size: 20px; margin-right: 10px;"></i> 
                <?php echo $mensagem_sucesso; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; border-left: 4px solid #dc3545; padding: 16px 20px; border-radius: 8px; background: #fdf0f0;">
                <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 20px; margin-right: 10px;"></i> 
                <?php echo htmlspecialchars($mensagem_erro); ?>
            </div>
        <?php endif; ?>

        <div class="card-panel">
            <div class="info-box success">
                <p><i class="fas fa-check-circle" style="color: #28a745;"></i> <strong>O que acontece ao registrar um recesso?</strong></p>
                <p>• Aulas já agendadas no período serão <strong>remanejadas</strong> para os próximos dias úteis</p>
                <p>• Nenhuma aula será <strong>excluída</strong> do sistema</p>
                <p>• A data de fim dos cursos afetados será <strong>atualizada automaticamente</strong></p>
                <p>• Novas aulas não serão agendadas em dias de recesso</p>
                <p>• <strong>Você pode selecionar cursos, turnos e tipos de curso específicos</strong> para aplicar o recesso</p>
            </div>

            <form method="POST" action="" id="formRecesso">
                <!-- ============================================================
                SEÇÃO 1: INFORMAÇÕES BÁSICAS
                ============================================================ -->
                <div style="margin-bottom: 20px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-info-circle" style="color: #1a73e8;"></i> Informações do Recesso
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nome_recesso"><i class="fas fa-tag"></i> Nome do Recesso <span class="required">*</span></label>
                            <input type="text" name="nome_recesso" id="nome_recesso" 
                                   placeholder="Ex: Feriado de Carnaval, Recesso de Natal..." 
                                   value="<?php echo isset($_POST['nome_recesso']) ? htmlspecialchars($_POST['nome_recesso']) : ''; ?>"
                                   required>
                        </div>
                        <div class="form-group">
                            <label for="tipo"><i class="fas fa-circle"></i> Tipo <span class="required">*</span></label>
                            <select name="tipo" id="tipo" required>
                                <?php foreach ($tiposRecesso as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] == $key) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="descricao"><i class="fas fa-comment"></i> Descrição <span class="optional">(opcional)</span></label>
                        <textarea name="descricao" id="descricao" rows="2" 
                                  placeholder="Descreva o motivo do recesso (opcional)"><?php echo isset($_POST['descricao']) ? htmlspecialchars($_POST['descricao']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- ============================================================
                SEÇÃO 2: PERÍODO
                ============================================================ -->
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-calendar-alt" style="color: #1a73e8;"></i> Período do Recesso
                    </h3>
                    
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Tipo de Período <span class="required">*</span></label>
                        <div class="periodo-opcoes">
                            <label class="selecionado">
                                <input type="radio" name="tipo_periodo" value="unico" <?php echo (!isset($_POST['tipo_periodo']) || $_POST['tipo_periodo'] == 'unico') ? 'checked' : ''; ?>>
                                <i class="fas fa-calendar-day"></i> Dia Único
                            </label>
                            <label>
                                <input type="radio" name="tipo_periodo" value="periodo" <?php echo (isset($_POST['tipo_periodo']) && $_POST['tipo_periodo'] == 'periodo') ? 'checked' : ''; ?>>
                                <i class="fas fa-calendar-week"></i> Período (vários dias)
                            </label>
                        </div>
                    </div>

                    <div id="campoUnico" class="campo-periodo <?php echo (isset($_POST['tipo_periodo']) && $_POST['tipo_periodo'] == 'periodo') ? 'hidden' : ''; ?>">
                        <div class="form-group">
                            <label for="data_unica"><i class="fas fa-calendar-plus"></i> Data do Recesso <span class="required">*</span></label>
                            <input type="date" name="data_unica" id="data_unica" 
                                   value="<?php echo isset($_POST['data_unica']) ? htmlspecialchars($_POST['data_unica']) : ''; ?>"
                                   <?php echo (!isset($_POST['tipo_periodo']) || $_POST['tipo_periodo'] == 'unico') ? 'required' : ''; ?>>
                            <small style="color: #7a8aa0; font-size: 12px;">
                                <i class="fas fa-info-circle"></i> Selecione o dia específico do recesso.
                            </small>
                        </div>
                    </div>

                    <div id="campoPeriodo" class="campo-periodo <?php echo (!isset($_POST['tipo_periodo']) || $_POST['tipo_periodo'] == 'unico') ? 'hidden' : ''; ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="data_inicio"><i class="fas fa-calendar-plus"></i> Data Início <span class="required">*</span></label>
                                <input type="date" name="data_inicio" id="data_inicio"
                                       value="<?php echo isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : ''; ?>"
                                       <?php echo (isset($_POST['tipo_periodo']) && $_POST['tipo_periodo'] == 'periodo') ? 'required' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="data_fim"><i class="fas fa-calendar-minus"></i> Data Fim <span class="required">*</span></label>
                                <input type="date" name="data_fim" id="data_fim"
                                       value="<?php echo isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : ''; ?>"
                                       <?php echo (isset($_POST['tipo_periodo']) && $_POST['tipo_periodo'] == 'periodo') ? 'required' : ''; ?>>
                                <small style="color: #7a8aa0; font-size: 12px;">
                                    <i class="fas fa-info-circle"></i> Período do recesso (início e fim).
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                SEÇÃO 3: UNIDADE
                ============================================================ -->
                <?php if ($tipo_usuario === 'admin_cliente'): ?>
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-building" style="color: #1a73e8;"></i> Unidade
                    </h3>
                    <div class="form-group">
                        <label for="id_unidade"><i class="fas fa-building"></i> Unidade</label>
                        <select name="id_unidade" id="id_unidade">
                            <option value="">Todas as unidades</option>
                            <?php foreach ($unidades as $unidade): ?>
                                <option value="<?php echo $unidade['id_unidade']; ?>" <?php echo (isset($_POST['id_unidade']) && $_POST['id_unidade'] == $unidade['id_unidade']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> Selecione uma unidade específica ou deixe em branco para <strong>todas</strong>.
                        </small>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="id_unidade" value="<?php echo $id_unidade_usuario; ?>">
                <?php endif; ?>

                <!-- ============================================================
                SEÇÃO 4: CURSOS AFETADOS - CHECKBOX
                ============================================================ -->
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-book" style="color: #1a73e8;"></i> Cursos Afetados
                        <span class="badge-info-box" style="margin-left: 8px;">Opcional</span>
                    </h3>
                    <p style="font-size: 13px; color: #7a8aa0; margin-bottom: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        Selecione os cursos que serão afetados por este recesso. 
                        <strong>Deixe em branco para aplicar a todos os cursos.</strong>
                    </p>

                    <div class="form-group">
                        <label><i class="fas fa-list"></i> Selecione os Cursos</label>
                        <div class="checkbox-grid-cursos" id="cursosGrid">
                            <?php foreach ($cursos as $curso): 
                                $turnoLabel = [
                                    'manha' => '☀️ Manhã',
                                    'tarde' => '🌤️ Tarde',
                                    'noite' => '🌙 Noite',
                                    'integral' => '🔄 Integral'
                                ][$curso['turno_curso'] ?? 'manha'];
                                
                                $turnoClass = 'badge-turno-' . ($curso['turno_curso'] ?? 'manha');
                                
                                $dataInicio = !empty($curso['data_inicio_curso']) ? date('d/m/Y', strtotime($curso['data_inicio_curso'])) : 'N/I';
                                $dataFim = !empty($curso['data_fim_curso_calculada']) ? date('d/m/Y', strtotime($curso['data_fim_curso_calculada'])) : 'N/I';
                                $diasLetivos = $curso['dias_letivos'] ?? 0;
                                
                                $checked = (isset($_POST['cursos']) && in_array($curso['id_curso'], $_POST['cursos'])) ? 'checked' : '';
                            ?>
                                <label class="<?php echo $checked ? 'selecionado' : ''; ?>">
                                    <input type="checkbox" name="cursos[]" value="<?php echo $curso['id_curso']; ?>" <?php echo $checked; ?>>
                                    <div class="curso-info">
                                        <span>
                                            <span class="numero-destaque"><?php echo $curso['numero_curso']; ?></span>
                                            <span class="nome"><?php echo htmlspecialchars($curso['nome_curso']); ?></span>
                                        </span>
                                        <span class="detalhe">
                                            <span class="badge-turno-pequeno <?php echo $turnoClass; ?>"><?php echo $turnoLabel; ?></span>
                                            <span><i class="fas fa-calendar-alt"></i> <?php echo $dataInicio; ?> → <?php echo $dataFim; ?></span>
                                            <span><i class="fas fa-calendar-week"></i> <?php echo $diasLetivos; ?> dias</span>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 6px;">
                            <i class="fas fa-info-circle"></i> Selecione um ou mais cursos. Deixe em branco para <strong>TODOS</strong>.
                        </small>
                    </div>
                </div>

                <!-- ============================================================
                SEÇÃO 5: TURNOS AFETADOS - CHECKBOX
                ============================================================ -->
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-clock" style="color: #1a73e8;"></i> Turnos Afetados
                        <span class="badge-info-box" style="margin-left: 8px;">Opcional</span>
                    </h3>
                    <p style="font-size: 13px; color: #7a8aa0; margin-bottom: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        Selecione os turnos que serão afetados por este recesso.
                        <strong>Deixe em branco para aplicar a todos os turnos.</strong>
                    </p>

                    <div class="form-group">
                        <label><i class="fas fa-list"></i> Selecione os Turnos</label>
                        <div class="checkbox-grid-simples" id="turnosGrid">
                            <?php foreach ($turnosLista as $key => $label): 
                                $checked = (isset($_POST['turno_curso']) && in_array($key, $_POST['turno_curso'])) ? 'checked' : '';
                            ?>
                                <label class="<?php echo $checked ? 'selecionado' : ''; ?>">
                                    <input type="checkbox" name="turno_curso[]" value="<?php echo $key; ?>" <?php echo $checked; ?>>
                                    <?php echo $label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 6px;">
                            <i class="fas fa-info-circle"></i> Selecione um ou mais turnos. Deixe em branco para <strong>TODOS</strong>.
                        </small>
                    </div>
                </div>

                <!-- ============================================================
                SEÇÃO 6: TIPOS DE CURSO AFETADOS - CHECKBOX
                ============================================================ -->
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-tag" style="color: #1a73e8;"></i> Tipos de Curso Afetados
                        <span class="badge-info-box" style="margin-left: 8px;">Opcional</span>
                    </h3>
                    <p style="font-size: 13px; color: #7a8aa0; margin-bottom: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        Selecione os tipos de curso que serão afetados por este recesso.
                        <strong>Deixe em branco para aplicar a todos os tipos.</strong>
                    </p>

                    <div class="form-group">
                        <label><i class="fas fa-list"></i> Selecione os Tipos de Curso</label>
                        <div class="checkbox-grid-simples" id="tiposGrid">
                            <?php foreach ($tiposCursoLista as $key => $label): 
                                $checked = (isset($_POST['tipo_curso']) && in_array($key, $_POST['tipo_curso'])) ? 'checked' : '';
                            ?>
                                <label class="<?php echo $checked ? 'selecionado' : ''; ?>">
                                    <input type="checkbox" name="tipo_curso[]" value="<?php echo $key; ?>" <?php echo $checked; ?>>
                                    <?php echo $label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 6px;">
                            <i class="fas fa-info-circle"></i> Selecione um ou mais tipos. Deixe em branco para <strong>TODOS</strong>.
                        </small>
                    </div>
                </div>

                <!-- ============================================================
                RESUMO DE SELEÇÕES
                ============================================================ -->
                <div id="selecaoResumo" class="selecao-resumo">
                    <i class="fas fa-check-circle"></i> 
                    <span id="resumoTexto">Nenhuma seleção feita</span>
                </div>

                <!-- ============================================================
                SEÇÃO 7: DIAS DA SEMANA
                ============================================================ -->
                <div style="margin-bottom: 20px; padding-top: 16px; border-top: 2px solid #f0f4fb;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #0e1a2b; margin-bottom: 12px;">
                        <i class="fas fa-calendar-week" style="color: #1a73e8;"></i> Dias da Semana Afetados
                        <span class="badge-info-box" style="margin-left: 8px;">Opcional</span>
                    </h3>
                    <p style="font-size: 13px; color: #7a8aa0; margin-bottom: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        Selecione os dias da semana em que o recesso se aplica. 
                        <strong>Deixe em branco para aplicar a todos os dias.</strong>
                    </p>
                    <div class="checkbox-grid-simples" id="diasSemanaGrid">
                        <?php foreach ($diasSemana as $key => $label): ?>
                            <label>
                                <input type="checkbox" name="dias_semana[]" value="<?php echo $key; ?>"
                                    <?php echo (isset($_POST['dias_semana']) && in_array($key, $_POST['dias_semana'])) ? 'checked' : ''; ?>>
                                <?php echo $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i> Selecione os dias da semana afetados. Deixe em branco para <strong>TODOS</strong>.
                    </small>
                </div>

                <!-- ============================================================
                BOTÕES
                ============================================================ -->
                <div class="form-actions">
                    <a href="listar_recesso.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary" id="btnSalvar">
                        <i class="fas fa-save"></i> Registrar Recesso
                    </button>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>