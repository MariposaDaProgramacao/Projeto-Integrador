<?php
// ==========================================================
// processar_cursos.php - Processar Cadastro/Edição de Cursos (MODIFICADO PARA MULTI-TENANT)
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem gerenciar cursos.');
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

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

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
// FUNÇÃO PARA CALCULAR DATA DE FIM (MODIFICADA)
// ============================================================
function calcularDataFim($conn, $data_inicio, $dias_letivos, $dias_semana, $id_unidade = null, $id_cliente = null, $turno = null, $id_curso = null) {
    // Mapeamento dos dias da semana
    $diasSemanaMap = [
        'segunda' => 1, 'terca' => 2, 'quarta' => 3,
        'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
    ];
    
    // Converter dias_semana para array de números
    $diasSemanaArray = explode(',', $dias_semana);
    $diasPermitidos = [];
    foreach ($diasSemanaArray as $dia) {
        $dia = trim($dia);
        if (isset($diasSemanaMap[$dia])) {
            $diasPermitidos[] = $diasSemanaMap[$dia];
        }
    }
    
    if (empty($diasPermitidos)) {
        return null;
    }
    
    // ============================================================
    // BUSCAR DIAS DE RECESSO - FILTRANDO PELO CURSO E CLIENTE
    // ============================================================
    $diasRecesso = buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso);
    
    $dataAtual = new DateTime($data_inicio);
    $diasContados = 0;
    $diasPulados = [];
    $ultimaData = null;
    
    // Contar dias letivos a partir da data de início
    while ($diasContados < $dias_letivos) {
        $diaSemana = (int)$dataAtual->format('N');
        $dataStr = $dataAtual->format('Y-m-d');
        
        if (in_array($dataStr, $diasRecesso)) {
            $diasPulados[] = [
                'data' => $dataStr,
                'nome' => 'Recesso',
                'tipo' => 'recesso'
            ];
            $dataAtual->modify('+1 day');
            continue;
        }
        
        if (in_array($diaSemana, $diasPermitidos)) {
            $diasContados++;
            $ultimaData = clone $dataAtual;
        }
        
        $dataAtual->modify('+1 day');
    }
    
    if (!$ultimaData) {
        return null;
    }
    
    return [
        'data_fim' => $ultimaData->format('Y-m-d'),
        'dias_pulados' => $diasPulados,
        'total_pulados' => count($diasPulados)
    ];
}

// ============================================================
// PROCESSAR AÇÕES
// ============================================================
try {
    switch ($acao) {
        case 'cadastrar':
            // ============================================================
            // CADASTRAR NOVO CURSO
            // ============================================================
            $numero_curso = $_POST['numero_curso'] ?? '';
            $nome_curso = $_POST['nome_curso'] ?? '';
            $id_unidade = (int)($_POST['id_unidade'] ?? 0);
            $id_docente = !empty($_POST['id_docente']) ? (int)$_POST['id_docente'] : null;
            $carga_horaria_curso = (int)($_POST['carga_horaria_curso'] ?? 0);
            $horas_por_dia = (int)($_POST['horas_por_dia'] ?? 4);
            $tipo_sala_preferencial = $_POST['tipo_sala_preferencial'] ?? null;
            $data_inicio_curso = $_POST['data_inicio_curso'] ?? '';
            $turno_curso = $_POST['turno_curso'] ?? '';
            $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : '';
            $tipo_curso = $_POST['tipo_curso'] ?? '';
            $status_curso = $_POST['status_curso'] ?? 'ativo';
            
            // ============================================================
            // VALIDAÇÃO DE UNIDADE PARA COORDENADOR (GERENTE)
            // ============================================================
            if ($tipo_usuario === 'gerente') {
                $id_unidade = $id_unidade_usuario;
            }
            
            // Validações básicas
            if (empty($numero_curso) || empty($nome_curso) || empty($id_unidade) || 
                empty($carga_horaria_curso) || empty($data_inicio_curso) || 
                empty($turno_curso) || empty($dias_semana) || empty($tipo_curso)) {
                setMessage('error', 'Preencha todos os campos obrigatórios.');
                redirect('cadastrar_curso.php');
            }
            
            // ============================================================
            // VERIFICAR SE O NÚMERO DO CURSO JÁ EXISTE PARA ESTE CLIENTE
            // ============================================================
            $sqlCheck = "SELECT id_curso FROM cursos WHERE numero_curso = :numero_curso AND id_cliente = :id_cliente";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->execute([
                ':numero_curso' => $numero_curso,
                ':id_cliente' => $id_cliente
            ]);
            if ($stmtCheck->rowCount() > 0) {
                setMessage('error', 'Já existe um curso com este número. Por favor, utilize um número diferente.');
                redirect('cadastrar_curso.php');
            }
            
            // Calcular dias letivos
            $dias_letivos = ceil($carga_horaria_curso / $horas_por_dia);
            
            // ============================================================
            // CALCULAR DATA DE FIM
            // ============================================================
            $resultado = calcularDataFim($conn, $data_inicio_curso, $dias_letivos, $dias_semana, $id_unidade, $id_cliente, $turno_curso, null);
            
            if ($resultado && isset($resultado['data_fim'])) {
                $data_fim_curso_calculada = $resultado['data_fim'];
                $total_pulados = $resultado['total_pulados'] ?? 0;
            } else {
                $dataObj = new DateTime($data_inicio_curso);
                $dataObj->modify('+30 days');
                $data_fim_curso_calculada = $dataObj->format('Y-m-d');
                $total_pulados = 0;
            }
            
            // Inserir curso
            $sql = "INSERT INTO cursos (
                        id_cliente,
                        numero_curso, nome_curso, id_unidade, id_docente,
                        carga_horaria_curso, horas_por_dia, tipo_sala_preferencial,
                        data_inicio_curso, data_fim_curso_calculada, dias_letivos,
                        turno_curso, dias_semana, tipo_curso, status_curso
                    ) VALUES (
                        :id_cliente,
                        :numero_curso, :nome_curso, :id_unidade, :id_docente,
                        :carga_horaria_curso, :horas_por_dia, :tipo_sala_preferencial,
                        :data_inicio_curso, :data_fim_curso_calculada, :dias_letivos,
                        :turno_curso, :dias_semana, :tipo_curso, :status_curso
                    )";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id_cliente' => $id_cliente,
                ':numero_curso' => $numero_curso,
                ':nome_curso' => $nome_curso,
                ':id_unidade' => $id_unidade,
                ':id_docente' => $id_docente,
                ':carga_horaria_curso' => $carga_horaria_curso,
                ':horas_por_dia' => $horas_por_dia,
                ':tipo_sala_preferencial' => $tipo_sala_preferencial,
                ':data_inicio_curso' => $data_inicio_curso,
                ':data_fim_curso_calculada' => $data_fim_curso_calculada,
                ':dias_letivos' => $dias_letivos,
                ':turno_curso' => $turno_curso,
                ':dias_semana' => $dias_semana,
                ':tipo_curso' => $tipo_curso,
                ':status_curso' => $status_curso
            ]);
            
            $id_curso = $conn->lastInsertId();
            
            // ============================================================
            // RECALCULAR DATA DE FIM COM O ID DO CURSO
            // ============================================================
            if ($id_curso) {
                $resultadoRecalculo = calcularDataFim($conn, $data_inicio_curso, $dias_letivos, $dias_semana, $id_unidade, $id_cliente, $turno_curso, $id_curso);
                
                if ($resultadoRecalculo && isset($resultadoRecalculo['data_fim'])) {
                    $nova_data_fim = $resultadoRecalculo['data_fim'];
                    $total_pulados = $resultadoRecalculo['total_pulados'] ?? 0;
                    
                    $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                    $stmtUpdate = $conn->prepare($sqlUpdate);
                    $stmtUpdate->execute([
                        ':data_fim' => $nova_data_fim,
                        ':id_curso' => $id_curso,
                        ':id_cliente' => $id_cliente
                    ]);
                    
                    $data_fim_curso_calculada = $nova_data_fim;
                }
            }
            
            // Mensagem de sucesso
            $mensagem = '✅ Curso cadastrado com sucesso!';
            if ($total_pulados > 0) {
                $mensagem .= " <br>📅 <strong>{$total_pulados} dia(s)</strong> foram pulados por serem recessos/feriados.";
            }
            if ($data_fim_curso_calculada) {
                $mensagem .= ' <br>📅 Data de fim: <strong>' . date('d/m/Y', strtotime($data_fim_curso_calculada)) . '</strong>';
            }
            
            setMessage('success', $mensagem);
            redirect('listar_cursos.php');
            
        case 'editar':
            // ============================================================
            // EDITAR CURSO EXISTENTE
            // ============================================================
            $id_curso = (int)($_POST['id'] ?? 0);
            $numero_curso = $_POST['numero_curso'] ?? '';
            $nome_curso = $_POST['nome_curso'] ?? '';
            $id_unidade = (int)($_POST['id_unidade'] ?? 0);
            $id_docente = !empty($_POST['id_docente']) ? (int)$_POST['id_docente'] : null;
            $carga_horaria_curso = (int)($_POST['carga_horaria_curso'] ?? 0);
            $horas_por_dia = (int)($_POST['horas_por_dia'] ?? 4);
            $tipo_sala_preferencial = $_POST['tipo_sala_preferencial'] ?? null;
            $data_inicio_curso = $_POST['data_inicio_curso'] ?? '';
            $turno_curso = $_POST['turno_curso'] ?? '';
            $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : '';
            $tipo_curso = $_POST['tipo_curso'] ?? '';
            $status_curso = $_POST['status_curso'] ?? 'ativo';
            
            // ============================================================
            // VERIFICAR SE O CURSO PERTENCE AO CLIENTE
            // ============================================================
            $sqlVerificar = "SELECT id_unidade, id_cliente FROM cursos WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtVerificar = $conn->prepare($sqlVerificar);
            $stmtVerificar->execute([
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            $cursoExistente = $stmtVerificar->fetch(PDO::FETCH_ASSOC);
            
            if (!$cursoExistente) {
                setMessage('error', 'Curso não encontrado.');
                redirect('listar_cursos.php');
            }
            
            // ============================================================
            // VALIDAÇÃO DE UNIDADE PARA COORDENADOR (GERENTE)
            // ============================================================
            if ($tipo_usuario === 'gerente') {
                if ($cursoExistente['id_unidade'] != $id_unidade_usuario) {
                    setMessage('error', 'Você não tem permissão para editar este curso.');
                    redirect("editar_cursos.php?id=$id_curso");
                }
                $id_unidade = $id_unidade_usuario;
            }
            
            // Validações básicas
            if (empty($id_curso) || empty($numero_curso) || empty($nome_curso) || 
                empty($id_unidade) || empty($carga_horaria_curso) || 
                empty($data_inicio_curso) || empty($turno_curso) || 
                empty($dias_semana) || empty($tipo_curso)) {
                setMessage('error', 'Preencha todos os campos obrigatórios.');
                redirect("editar_cursos.php?id=$id_curso");
            }
            
            // ============================================================
            // VERIFICAR SE O NÚMERO DO CURSO JÁ EXISTE PARA ESTE CLIENTE
            // ============================================================
            $sqlCheck = "SELECT id_curso FROM cursos WHERE numero_curso = :numero_curso AND id_cliente = :id_cliente AND id_curso != :id_curso";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->execute([
                ':numero_curso' => $numero_curso,
                ':id_cliente' => $id_cliente,
                ':id_curso' => $id_curso
            ]);
            if ($stmtCheck->rowCount() > 0) {
                setMessage('error', 'Já existe outro curso com este número. Por favor, utilize um número diferente.');
                redirect("editar_cursos.php?id=$id_curso");
            }
            
            // ============================================================
            // CALCULAR DIAS LETIVOS E DATA DE FIM
            // ============================================================
            $dias_letivos = ceil($carga_horaria_curso / $horas_por_dia);
            
            $resultado = calcularDataFim($conn, $data_inicio_curso, $dias_letivos, $dias_semana, $id_unidade, $id_cliente, $turno_curso, $id_curso);
            
            if ($resultado && isset($resultado['data_fim'])) {
                $data_fim_curso_calculada = $resultado['data_fim'];
                $total_pulados = $resultado['total_pulados'] ?? 0;
            } else {
                $dataObj = new DateTime($data_inicio_curso);
                $dataObj->modify('+30 days');
                $data_fim_curso_calculada = $dataObj->format('Y-m-d');
                $total_pulados = 0;
            }
            
            // ============================================================
            // ATUALIZAR CURSO
            // ============================================================
            $sql = "UPDATE cursos SET 
                        numero_curso = :numero_curso,
                        nome_curso = :nome_curso,
                        id_unidade = :id_unidade,
                        id_docente = :id_docente,
                        carga_horaria_curso = :carga_horaria_curso,
                        horas_por_dia = :horas_por_dia,
                        tipo_sala_preferencial = :tipo_sala_preferencial,
                        data_inicio_curso = :data_inicio_curso,
                        data_fim_curso_calculada = :data_fim_curso_calculada,
                        dias_letivos = :dias_letivos,
                        turno_curso = :turno_curso,
                        dias_semana = :dias_semana,
                        tipo_curso = :tipo_curso,
                        status_curso = :status_curso
                    WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':numero_curso' => $numero_curso,
                ':nome_curso' => $nome_curso,
                ':id_unidade' => $id_unidade,
                ':id_docente' => $id_docente,
                ':carga_horaria_curso' => $carga_horaria_curso,
                ':horas_por_dia' => $horas_por_dia,
                ':tipo_sala_preferencial' => $tipo_sala_preferencial,
                ':data_inicio_curso' => $data_inicio_curso,
                ':data_fim_curso_calculada' => $data_fim_curso_calculada,
                ':dias_letivos' => $dias_letivos,
                ':turno_curso' => $turno_curso,
                ':dias_semana' => $dias_semana,
                ':tipo_curso' => $tipo_curso,
                ':status_curso' => $status_curso,
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            
            // ============================================================
            // MENSAGEM DE SUCESSO
            // ============================================================
            $mensagem = '✅ Curso atualizado com sucesso!';
            if ($total_pulados > 0) {
                $mensagem .= " <br>📅 <strong>{$total_pulados} dia(s)</strong> foram pulados por serem recessos/feriados específicos deste curso.";
            }
            if ($data_fim_curso_calculada) {
                $mensagem .= ' <br>📅 Nova data de fim: <strong>' . date('d/m/Y', strtotime($data_fim_curso_calculada)) . '</strong>';
            }
            setMessage('success', $mensagem);
            
            redirect('listar_cursos.php');
            
        default:
            setMessage('error', 'Ação inválida.');
            redirect('listar_cursos.php');
    }
    
} catch (PDOException $e) {
    setMessage('error', 'Erro ao processar curso: ' . $e->getMessage());
    
    if ($acao === 'editar' && isset($id_curso)) {
        redirect("editar_cursos.php?id=$id_curso");
    } else {
        redirect('listar_cursos.php');
    }
    exit;
}
?>