<?php
// ==========================================================
// listar_aulas_dia.php - Mapa de Salas por Data (MODIFICADO PARA MULTI-TENANT)
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CARREGAR CONEXÃO E FUNÇÕES
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// CONFIGURAÇÃO DE FUSO HORÁRIO DA UNIDADE
// ============================================================
if (isset($_SESSION['usuario_fuso'])) {
    date_default_timezone_set($_SESSION['usuario_fuso']);
} else {
    date_default_timezone_set('America/Sao_Paulo');
}

// ============================================================
// VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// PERMISSÕES - TODOS OS TIPOS DE USUÁRIO PODEM VISUALIZAR
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente', 'usuario', 'visualizador'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// VARIÁVEIS DE PERMISSÃO (NOVO SISTEMA)
// ============================================================
$id_cliente = getClienteId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;
$pode_agendar = in_array($tipo_usuario, ['admin_cliente', 'gerente']);

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
// RECEBER DATA DO FILTRO (ou usar data atual)
// ============================================================
$dataSelecionada = $_GET['data'] ?? date('Y-m-d');
$dataHoje = $dataSelecionada;

// ============================================================
// SE FOR ADMINISTRADOR, VERIFICAR SE UNIDADE FOI SELECIONADA
// ============================================================
$unidadeSelecionada = null;
$mostrarSelecaoUnidade = false;

if ($tipo_usuario === 'admin_cliente') {
    if (isset($_GET['unidade']) && !empty($_GET['unidade'])) {
        $unidadeSelecionada = (int)$_GET['unidade'];
        $_SESSION['unidade_selecionada_admin'] = $unidadeSelecionada;
    } elseif (isset($_SESSION['unidade_selecionada_admin']) && !empty($_SESSION['unidade_selecionada_admin'])) {
        $unidadeSelecionada = (int)$_SESSION['unidade_selecionada_admin'];
    } else {
        $mostrarSelecaoUnidade = true;
    }
} else {
    $unidadeSelecionada = $id_unidade_usuario;
}

$unidades = [];
try {
    $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? AND status_unidade = 'ativo' ORDER BY nome_unidade";
    $stmtUnidades = $conn->prepare($sqlUnidades);
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = 'Erro ao carregar unidades.';
}

$busca = $_GET['busca'] ?? '';
$turnoFiltro = $_GET['turno'] ?? '';
$statusSalaFiltro = $_GET['status_sala'] ?? '';
$tipoSalaFiltro = $_GET['tipo_sala'] ?? '';

if ($mostrarSelecaoUnidade && $tipo_usuario === 'admin_cliente') {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selecionar Unidade - Mapa de Salas</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .select-container {
            background: #ffffff;
            border-radius: 24px;
            padding: 48px 56px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            border: 1px solid #e8edf5;
            text-align: center;
        }
        .select-container .icon { font-size: 56px; color: #1a73e8; margin-bottom: 16px; }
        .select-container h1 { font-size: 24px; font-weight: 700; color: #0e1a2b; margin-bottom: 8px; }
        .select-container p { color: #7a8aa0; font-size: 14px; margin-bottom: 28px; }
        .select-container .form-group { text-align: left; margin-bottom: 20px; }
        .select-container .form-group label { display: block; font-weight: 600; font-size: 14px; color: #1a2639; margin-bottom: 6px; }
        .select-container .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e9f3;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            background: #fafcff;
            color: #1a2639;
            transition: border-color 0.2s;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237a8aa0' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
            cursor: pointer;
        }
        .select-container .form-group select:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }
        .btn {
            padding: 12px 32px;
            border-radius: 60px;
            border: none;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
            width: 100%;
            justify-content: center;
        }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        @media (max-width: 540px) {
            .select-container { padding: 32px 24px; margin: 20px; }
        }
    </style>
</head>
<body>
    <div class="select-container">
        <div class="icon"><i class="fas fa-building"></i></div>
        <h1>Selecionar Unidade</h1>
        <p>Escolha uma unidade para visualizar o mapa de salas</p>
        <form method="GET" action="">
            <div class="form-group">
                <label for="unidade">Unidade</label>
                <select name="unidade" id="unidade" required>
                    <option value="">Selecione uma unidade...</option>
                    <?php foreach ($unidades as $unidade): ?>
                        <option value="<?php echo $unidade['id_unidade']; ?>">
                            <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($busca)): ?>
                <input type="hidden" name="busca" value="<?php echo htmlspecialchars($busca); ?>">
            <?php endif; ?>
            <?php if (!empty($turnoFiltro)): ?>
                <input type="hidden" name="turno" value="<?php echo htmlspecialchars($turnoFiltro); ?>">
            <?php endif; ?>
            <?php if (!empty($dataSelecionada)): ?>
                <input type="hidden" name="data" value="<?php echo htmlspecialchars($dataSelecionada); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> Visualizar Mapa
            </button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

try {
    // ============================================================
    // 1. Buscar salas (apenas da unidade selecionada e cliente)
    // ============================================================
    $sqlSalas = "SELECT id_sala, numero_sala, tipo_sala, status_sala, id_unidade
                 FROM salas
                 WHERE id_cliente = :id_cliente";
    $params = [':id_cliente' => $id_cliente];

    if ($tipo_usuario === 'admin_cliente' && $unidadeSelecionada) {
        $sqlSalas .= " AND id_unidade = :unidade";
        $params[':unidade'] = $unidadeSelecionada;
    } elseif ($tipo_usuario === 'gerente') {
        $sqlSalas .= " AND id_unidade = :unidade";
        $params[':unidade'] = $id_unidade_usuario;
    }

    $stmtSalas = $conn->prepare($sqlSalas);
    foreach ($params as $key => $value) {
        $stmtSalas->bindValue($key, $value);
    }
    $stmtSalas->execute();
    $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 2. BUSCAR MANUTENÇÕES QUE COBREM A DATA SELECIONADA
    // ============================================================
    try {
        $sqlManut = "SELECT id_sala, data_inicio, data_fim, status, motivo 
                     FROM manutencoes 
                     WHERE id_cliente = :id_cliente
                     AND :data_hoje BETWEEN data_inicio AND data_fim
                     AND status != 'concluida'";
        
        $stmtManut = $conn->prepare($sqlManut);
        $stmtManut->execute([
            ':id_cliente' => $id_cliente,
            ':data_hoje' => $dataHoje
        ]);
        $manutencoesAtivas = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
        
        $manutencoesPorSala = [];
        foreach ($manutencoesAtivas as $manut) {
            $manutencoesPorSala[$manut['id_sala']] = $manut;
        }
    } catch (PDOException $e) {
        $manutencoesPorSala = [];
    }

    // ============================================================
    // 3. Buscar TODAS as aulas da DATA SELECIONADA (FILTRADAS POR CLIENTE)
    // ============================================================
    $sqlAulas = "SELECT c.*, 
                        cu.nome_curso,
                        cu.numero_curso,
                        cu.dias_letivos AS dias_letivos_curso,
                        cu.data_inicio_curso,
                        f.nome_funcionario AS nome_professor,
                        s.numero_sala,
                        s.tipo_sala,
                        s.status_sala,
                        u.nome_unidade,
                        (SELECT COUNT(*) 
                         FROM cronograma c2 
                         WHERE c2.id_curso = c.id_curso 
                         AND c2.id_cliente = c.id_cliente
                         AND c2.status_aula != 'cancelada' 
                         AND (c2.data_aula < c.data_aula OR (c2.data_aula = c.data_aula AND c2.id_aula <= c.id_aula))) AS numero_aula_ordem
                     FROM cronograma c
                     LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                     LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
                     LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
                     LEFT JOIN unidades u ON cu.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                     WHERE c.data_aula = :data_hoje
                       AND c.id_cliente = :id_cliente
                       AND c.status_aula IN ('agendada', 'remarcada')";

    $paramsAulas = [
        ':data_hoje' => $dataHoje,
        ':id_cliente' => $id_cliente
    ];

    if ($tipo_usuario === 'admin_cliente' && $unidadeSelecionada) {
        $sqlAulas .= " AND cu.id_unidade = :unidade";
        $paramsAulas[':unidade'] = $unidadeSelecionada;
    } elseif ($tipo_usuario === 'gerente') {
        $sqlAulas .= " AND cu.id_unidade = :unidade";
        $paramsAulas[':unidade'] = $id_unidade_usuario;
    }

    $stmtAulas = $conn->prepare($sqlAulas);
    foreach ($paramsAulas as $key => $value) {
        $stmtAulas->bindValue($key, $value);
    }
    $stmtAulas->execute();
    $aulas = $stmtAulas->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 4. Organizar por sala e turno
    // ============================================================
    $turnos = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
    $agenda = [];

    foreach ($salas as $sala) {
        $idSala = $sala['id_sala'];
        $agenda[$idSala] = [
            'sala' => $sala,
            'aulas' => [
                'manha' => null,
                'tarde' => null,
                'noite' => null
            ]
        ];
    }

    foreach ($aulas as $aula) {
        $idSala = $aula['id_sala'];
        $turno = $aula['turno'];
        if (isset($agenda[$idSala])) {
            $agenda[$idSala]['aulas'][$turno] = $aula;
        }
    }

    // ============================================================
    // 5. FILTRO DE TURNO
    // ============================================================
    if (!empty($turnoFiltro)) {
        foreach ($agenda as $idSala => &$item) {
            foreach ($item['aulas'] as $turno => &$aula) {
                if ($turno !== $turnoFiltro) {
                    $aula = null;
                }
            }
            unset($aula);
        }
        unset($item);
    }

    // ============================================================
    // 6. Define quais turnos serão exibidos
    // ============================================================
    $turnosExibir = $turnos;
    if (!empty($turnoFiltro) && isset($turnos[$turnoFiltro])) {
        $turnosExibir = [$turnoFiltro => $turnos[$turnoFiltro]];
    }

    // ============================================================
    // 7. CONTAGEM DE SALAS POR STATUS (considerando manutenções na data)
    // ============================================================
    $contagemStatus = [
        'disponivel' => 0,
        'ocupada' => 0,
        'manutencao' => 0,
        'inativa' => 0
    ];

    $totalOcupadas = 0;
    $totalDisponiveis = 0;
    $totalManutencao = 0;
    $totalInativa = 0;
    
    foreach ($agenda as $item) {
        $sala = $item['sala'];
        $idSala = $sala['id_sala'];
        $statusSala = $sala['status_sala'];
        
        $emManutencaoNaData = isset($manutencoesPorSala[$idSala]);
        
        if ($emManutencaoNaData) {
            $totalManutencao++;
            continue;
        }
        if ($statusSala === 'manutencao') {
            $totalManutencao++;
            continue;
        }
        if ($statusSala === 'inativa') {
            $totalInativa++;
            continue;
        }
        
        $temAulaEmAlgumTurno = false;
        foreach ($item['aulas'] as $turno => $aula) {
            if ($aula !== null) {
                $temAulaEmAlgumTurno = true;
                break;
            }
        }
        
        if ($temAulaEmAlgumTurno) {
            $totalOcupadas++;
        } else {
            $totalDisponiveis++;
        }
    }
    
    $contagemStatus['ocupada'] = $totalOcupadas;
    $contagemStatus['disponivel'] = $totalDisponiveis;
    $contagemStatus['manutencao'] = $totalManutencao;
    $contagemStatus['inativa'] = $totalInativa;

    // ============================================================
    // 8. FILTROS DE BUSCA, STATUS E TIPO
    // ============================================================
    if (!empty($busca) || !empty($statusSalaFiltro) || !empty($tipoSalaFiltro)) {
        $agendaFiltrada = [];
        foreach ($agenda as $idSala => $item) {
            $sala = $item['sala'];
            $numeroSala = (string)($sala['numero_sala'] ?? '');
            $statusSala = $sala['status_sala'] ?? '';
            $tipoSala = $sala['tipo_sala'] ?? '';
            $encontrou = false;
            
            $emManutencaoNaData = isset($manutencoesPorSala[$idSala]);
            
            $statusExibido = '';
            
            if ($emManutencaoNaData) {
                $statusExibido = 'manutencao';
            } elseif ($statusSala === 'manutencao') {
                $statusExibido = 'manutencao';
            } elseif ($statusSala === 'inativa') {
                $statusExibido = 'inativa';
            } else {
                $temAulaEmAlgumTurno = false;
                foreach ($item['aulas'] as $turno => $aula) {
                    if ($aula !== null) {
                        $temAulaEmAlgumTurno = true;
                        break;
                    }
                }
                
                if ($temAulaEmAlgumTurno) {
                    $statusExibido = 'ocupada';
                } else {
                    $statusExibido = 'disponivel';
                }
            }
            
            if (!empty($statusSalaFiltro)) {
                $statusFiltroMap = [
                    'disponivel' => 'disponivel',
                    'ocupada' => 'ocupada',
                    'manutencao' => 'manutencao',
                    'inativa' => 'inativa'
                ];
                
                $statusFiltroComparacao = $statusFiltroMap[$statusSalaFiltro] ?? $statusSalaFiltro;
                
                if ($statusExibido !== $statusFiltroComparacao) {
                    continue;
                }
            }
            
            if (!empty($tipoSalaFiltro)) {
                if ($tipoSala !== $tipoSalaFiltro) {
                    continue;
                }
            }
            
            if (!empty($busca)) {
                $buscaLower = strtolower($busca);
                
                if (stripos($numeroSala, $busca) !== false) {
                    $encontrou = true;
                }
                
                if (!$encontrou && stripos($tipoSala, $busca) !== false) {
                    $encontrou = true;
                }
                
                if (!$encontrou && stripos($statusSala, $busca) !== false) {
                    $encontrou = true;
                }
                
                if (!$encontrou) {
                    foreach ($item['aulas'] as $turno => $aula) {
                        if ($aula !== null) {
                            $nomeCurso = strtolower($aula['nome_curso'] ?? '');
                            $numeroCurso = strtolower($aula['numero_curso'] ?? '');
                            $nomeProfessor = strtolower($aula['nome_professor'] ?? '');
                            $observacao = strtolower($aula['observacao'] ?? '');
                            
                            if (stripos($nomeCurso, $busca) !== false || 
                                stripos($numeroCurso, $busca) !== false ||
                                stripos($nomeProfessor, $busca) !== false ||
                                stripos($observacao, $busca) !== false) {
                                $encontrou = true;
                                break;
                            }
                        }
                    }
                }
                
                if (!$encontrou) {
                    continue;
                }
            }
            
            $agendaFiltrada[$idSala] = $item;
        }
        $agenda = $agendaFiltrada;
    }

    $nomeUnidade = '';
    if ($tipo_usuario === 'admin_cliente' && $unidadeSelecionada) {
        try {
            $stmtUnidade = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
            $stmtUnidade->execute([
                ':id' => $unidadeSelecionada,
                ':id_cliente' => $id_cliente
            ]);
            $nomeUnidade = $stmtUnidade->fetchColumn();
        } catch (PDOException $e) {}
    } elseif ($tipo_usuario === 'gerente') {
        try {
            $stmtUnidade = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
            $stmtUnidade->execute([
                ':id' => $id_unidade_usuario,
                ':id_cliente' => $id_cliente
            ]);
            $nomeUnidade = $stmtUnidade->fetchColumn();
        } catch (PDOException $e) {}
    }

    $statusSalas = ['disponivel', 'ocupada', 'manutencao', 'inativa'];
    $tiposSalas = [];
    try {
        $stmtTipos = $conn->prepare("SELECT DISTINCT tipo_sala FROM salas WHERE id_cliente = :id_cliente ORDER BY tipo_sala");
        $stmtTipos->execute([':id_cliente' => $id_cliente]);
        $tiposSalas = $stmtTipos->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    $erro = "Erro ao carregar dados: " . $e->getMessage();
    $agenda = [];
    $totalOcupadas = 0;
    $turnosExibir = $turnos;
    $statusSalas = [];
    $tiposSalas = [];
    $manutencoesPorSala = [];
    $contagemStatus = [
        'disponivel' => 0,
        'ocupada' => 0,
        'manutencao' => 0,
        'inativa' => 0
    ];
}

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? '';
$mensagem_erro = $_SESSION['mensagem_erro'] ?? $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro'], $_SESSION['erro']);

// ============================================================
// FUNÇÃO SIMPLIFICADA - ÍCONE UNIVERSAL
// ============================================================
function getIconeSala($tipo) {
    return 'fa-door-open';
}

function getLabelSala($tipo) {
    if (empty($tipo)) {
        return 'Sala';
    }
    return ucfirst(str_replace('_', ' ', $tipo));
}

$titulo = 'Mapa de Salas - Gerenciamento de Ambientes';
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
            background: #f5f7fb;
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
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header h1 { font-size: 24px; font-weight: 700; color: #0e1a2b; }
        .page-header h1 i { color: #1a73e8; margin-right: 10px; }
        .page-header .subtitle { font-size: 14px; color: #7a8aa0; margin-top: 4px; }
        
        .data-destaque {
            display: inline-block;
            background: #1a73e8;
            color: #ffffff;
            padding: 4px 16px;
            border-radius: 60px;
            font-weight: 700;
            font-size: 14px;
            margin: 0 4px;
        }
        
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .btn {
            padding: 6px 16px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #1a73e8; color: #fff; }
        .btn-primary:hover { background: #1557b0; }
        .btn-outline { background: transparent; color: #1a2639; border: 1px solid #d8e0ec; }
        .btn-outline:hover { background: #f0f4fb; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { background: #c82333; }
        
        .filter-bar {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            border: 1px solid #e8edf5;
        }
        .filter-bar .form-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-bar .form-group label { font-size: 12px; font-weight: 500; color: #5a6a7e; }
        .filter-bar .form-group input,
        .filter-bar .form-group select {
            padding: 6px 12px;
            border: 1px solid #e2e9f3;
            border-radius: 6px;
            font-size: 13px;
            background: #fafcff;
            min-width: 140px;
        }
        
        .filter-data {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-data label {
            font-size: 12px;
            font-weight: 600;
            color: #1a2639;
        }
        .filter-data input[type="date"] {
            padding: 5px 10px;
            border: 1px solid #e2e9f3;
            border-radius: 6px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            background: #fafcff;
            color: #1a2639;
            outline: none;
            min-width: 150px;
        }
        .filter-data input[type="date"]:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }
        
        .agenda-container { display: flex; flex-direction: column; gap: 32px; }
        .turno-section {
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid #e8edf5;
            transition: all 0.2s;
            background: #ffffff;
        }
        .turno-manha { border-color: #bbdefb; background: #f5faff; }
        .turno-manha .turno-header { background: #bbdefb; color: #0d47a1; }
        .turno-manha .turno-header .badge-turno { background: rgba(13, 71, 161, 0.12); color: #0d47a1; }
        .turno-tarde { border-color: #ffccbc; background: #fff8f5; }
        .turno-tarde .turno-header { background: #ffccbc; color: #bf360c; }
        .turno-tarde .turno-header .badge-turno { background: rgba(191, 54, 12, 0.12); color: #bf360c; }
        .turno-noite { border-color: #d1c4e9; background: #f8f6ff; }
        .turno-noite .turno-header { background: #d1c4e9; color: #311b92; }
        .turno-noite .turno-header .badge-turno { background: rgba(49, 27, 146, 0.12); color: #311b92; }
        .turno-header {
            padding: 14px 24px;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .turno-header .badge-turno { font-size: 13px; font-weight: 500; padding: 4px 12px; border-radius: 60px; }
        .turno-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            padding: 20px 24px;
        }
        
        .sala-card {
            border-radius: 12px;
            padding: 20px 16px;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            background: #ffffff;
            border: 1px solid #e8edf5;
            cursor: default;
        }
        .sala-card:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        
        .sala-card .sala-numero { font-size: 20px; font-weight: 700; color: #0e1a2b; }
        .sala-card .sala-tipo { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            gap: 4px; 
            font-size: 12px; 
            color: #7a8aa0; 
        }
        .sala-card .sala-tipo i { 
            font-size: 32px; 
            color: #5a7a9a; 
        }
        .sala-card .aula-info { margin-top: 4px; width: 100%; }
        .sala-card .turma-numero {
            font-weight: 700;
            font-size: 15px;
            color: #1a2639;
            background: #f0f4fc;
            padding: 2px 12px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 4px;
        }
        .sala-card .numero-aula {
            font-weight: 700;
            font-size: 16px;
            color: #1a73e8;
            background: #e8f0fe;
            padding: 4px 14px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 4px;
        }
        .sala-card .numero-aula .badge-nao-iniciado {
            font-size: 10px;
            color: #856404;
            background: #fff3cd;
            padding: 1px 8px;
            border-radius: 4px;
            margin-left: 4px;
            display: inline-block;
            font-weight: 400;
        }
        .sala-card .aula-curso { font-size: 13px; color: #7a8aa0; margin-top: 2px; }
        .sala-card .aula-curso i { font-size: 12px; margin-right: 4px; }
        .sala-card .aula-horario {
            font-size: 13px;
            color: #1a73e8;
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
            background: transparent;
        }
        .status-badge.badge-disponivel {
            color: #1b5e20;
            background: #e8f5e9;
        }
        .status-badge.badge-ocupada {
            color: #b71c1c;
            background: #ffebee;
        }
        .status-badge.badge-manutencao {
            color: #e37400;
            background: #fff8e1;
            border: 2px solid #ffc107;
        }
        .status-badge.badge-inativa {
            color: #616161;
            background: #f5f5f5;
        }
        
        .manutencao-info {
            font-size: 11px;
            color: #e65100;
            background: #fff3cd;
            padding: 2px 10px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
            width: 100%;
            text-align: center;
        }
        
        .footer-system {
            text-align: center;
            font-size: 12px;
            color: #8a9bb5;
            padding: 16px 0 8px;
            border-top: 1px solid #e2e9f3;
            margin-top: auto;
            background: transparent;
            flex-shrink: 0;
        }
        
        .legenda {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            padding: 12px 20px;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e8edf5;
            margin-bottom: 16px;
            justify-content: center;
        }
        .legenda-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #1a2639;
        }
        .legenda-cor {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }
        .legenda-cor.disponivel { background: #34a853; }
        .legenda-cor.ocupada { background: #ea4335; }
        .legenda-cor.manutencao { background: #fbbc04; }
        .legenda-cor.inativa { background: #9aa0a6; }
        .legenda-contagem {
            background: #f0f4fb;
            padding: 0 10px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 700;
            color: #1a2639;
        }
        
        @media (max-width: 820px) {
            .main { padding: 16px 18px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar .form-group { width: 100%; }
            .filter-bar .form-group input,
            .filter-bar .form-group select { width: 100%; }
            .filter-data { flex-wrap: wrap; }
            .filter-data input[type="date"] { width: 100%; }
            .turno-cards { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); padding: 16px; }
            .legenda { gap: 12px; }
        }
        @media (max-width: 540px) {
            .main { padding: 12px 14px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; flex-wrap: wrap; }
            .turno-cards { grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px; }
            .sala-card { padding: 14px 12px; }
            .sala-card .sala-numero { font-size: 17px; }
            .sala-card .sala-tipo i { font-size: 26px; }
            .filter-data { flex-direction: column; align-items: stretch; gap: 4px; }
            .filter-data input[type="date"] { width: 100%; }
            .legenda { flex-direction: column; align-items: center; gap: 6px; }
            .legenda-item { font-size: 12px; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1><i class="fas fa-map"></i> Mapa de Salas</h1>
                <p class="subtitle">
                    <span class="data-destaque">
                        <i class="fas fa-calendar-day"></i> <?php echo date('d/m/Y', strtotime($dataHoje)); ?>
                    </span>
                    <?php if (!empty($busca)): ?>
                        • <strong>Resultados para: "<?php echo htmlspecialchars($busca); ?>"</strong>
                    <?php endif; ?>
                    <?php if (!empty($nomeUnidade)): ?>
                        • <strong><?php echo htmlspecialchars($nomeUnidade); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions">
                <?php if ($tipo_usuario === 'admin_cliente'): ?>
                    <a href="../trocar_unidade.php?redirect=mapa_salas_dia.php" class="btn btn-outline">
                        <i class="fas fa-exchange-alt"></i> Trocar Unidade
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- LEGENDA DE CORES COM CONTAGEM -->
        <div class="legenda">
            <span class="legenda-item">
                <span class="legenda-cor disponivel"></span>
                Disponível
                <span class="legenda-contagem"><?php echo $contagemStatus['disponivel']; ?></span>
            </span>
            <span class="legenda-item">
                <span class="legenda-cor ocupada"></span>
                Ocupada
                <span class="legenda-contagem"><?php echo $contagemStatus['ocupada']; ?></span>
            </span>
            <span class="legenda-item">
                <span class="legenda-cor manutencao"></span>
                Manutenção
                <span class="legenda-contagem"><?php echo $contagemStatus['manutencao']; ?></span>
            </span>
            <span class="legenda-item">
                <span class="legenda-cor inativa"></span>
                Inativa
                <span class="legenda-contagem"><?php echo $contagemStatus['inativa']; ?></span>
            </span>
        </div>

        <!-- FILTROS -->
        <div class="filter-bar">
            <form method="GET" action="" style="display: contents;">
                <div class="filter-data">
                    <label for="data"><i class="fas fa-calendar-day"></i> Data:</label>
                    <input type="date" name="data" id="data" value="<?php echo $dataHoje; ?>">
                </div>

                <div class="form-group">
                    <label for="busca"><i class="fas fa-search"></i> Buscar</label>
                    <input type="text" name="busca" id="busca" placeholder="Curso, aula ou turma..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>

                <div class="form-group">
                    <label for="turno">Turno</label>
                    <select name="turno" id="turno">
                        <option value="">Todos</option>
                        <option value="manha" <?php echo $turnoFiltro === 'manha' ? 'selected' : ''; ?>>☁️ Manhã</option>
                        <option value="tarde" <?php echo $turnoFiltro === 'tarde' ? 'selected' : ''; ?>>☀️ Tarde</option>
                        <option value="noite" <?php echo $turnoFiltro === 'noite' ? 'selected' : ''; ?>>🌙 Noite</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status_sala"><i class="fas fa-circle"></i> Status Sala</label>
                    <select name="status_sala" id="status_sala">
                        <option value="">Todos</option>
                        <?php foreach ($statusSalas as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($statusSalaFiltro == $status) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="tipo_sala"><i class="fas fa-tag"></i> Tipo Sala</label>
                    <select name="tipo_sala" id="tipo_sala">
                        <option value="">Todos</option>
                        <?php foreach ($tiposSalas as $tipo): ?>
                            <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo ($tipoSalaFiltro == $tipo) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $tipo)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="mapa_sala.php" class="btn btn-danger"><i class="fas fa-times"></i> Limpar</a>
            </form>
        </div>

        <div class="agenda-container">
            <?php 
            $temAulasNoDia = false;
            foreach ($turnosExibir as $turnoKey => $turnoNome): 
                $horarios = [
                    'manha' => '07:00 - 12:00',
                    'tarde' => '13:00 - 18:00',
                    'noite' => '19:00 - 22:00'
                ];
                $horario = $horarios[$turnoKey] ?? '';
                $iconeTurno = '';
                if ($turnoKey === 'manha') $iconeTurno = '☁️';
                elseif ($turnoKey === 'tarde') $iconeTurno = '☀️';
                elseif ($turnoKey === 'noite') $iconeTurno = '🌙';
                
                $classeTurno = 'turno-' . $turnoKey;
                
                $temAulasNoTurno = false;
                foreach ($agenda as $item) {
                    if ($item['aulas'][$turnoKey] !== null) {
                        $temAulasNoTurno = true;
                        $temAulasNoDia = true;
                        break;
                    }
                }
            ?>
            <section class="turno-section <?php echo $classeTurno; ?>">
                <div class="turno-header">
                    <?php echo $iconeTurno; ?> <?php echo $turnoNome; ?>
                    <span class="badge-turno"><?php echo $horario; ?></span>
                    <?php if (!empty($busca) && $temAulasNoTurno): ?>
                        <span class="badge-turno" style="background: rgba(26,115,232,0.12); color: #1a73e8;">
                            <i class="fas fa-search"></i> <?php echo count(array_filter($agenda, function($item) use ($turnoKey) { return $item['aulas'][$turnoKey] !== null; })); ?> aula(s)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="turno-cards">
                    <?php
                    $temSalas = false;
                    foreach ($agenda as $item):
                        $sala = $item['sala'];
                        $aula = $item['aulas'][$turnoKey];
                        $idSala = $sala['id_sala'];
                        
                        $deveMostrar = true;
                        
                        $emManutencaoNaData = isset($manutencoesPorSala[$idSala]);
                        $motivoManut = '';
                        if ($emManutencaoNaData) {
                            $motivoManut = $manutencoesPorSala[$idSala]['motivo'] ?? '';
                        }
                        
                        if (!empty($statusSalaFiltro)) {
                            $statusSalaReal = $sala['status_sala'];
                            
                            if ($statusSalaFiltro === 'manutencao') {
                                if (!$emManutencaoNaData && $statusSalaReal !== 'manutencao') {
                                    $deveMostrar = false;
                                }
                            } elseif ($statusSalaFiltro === 'ocupada') {
                                if ($aula === null) {
                                    $deveMostrar = false;
                                }
                            } elseif ($statusSalaFiltro === 'disponivel') {
                                $temAulaEmAlgumTurno = false;
                                foreach ($item['aulas'] as $turno => $a) {
                                    if ($a !== null) {
                                        $temAulaEmAlgumTurno = true;
                                        break;
                                    }
                                }
                                if ($temAulaEmAlgumTurno) {
                                    $deveMostrar = false;
                                }
                            } elseif ($statusSalaFiltro === 'inativa') {
                                if ($statusSalaReal !== 'inativa') {
                                    $deveMostrar = false;
                                }
                            }
                        }
                        
                        if (!$deveMostrar) {
                            continue;
                        }
                        
                        $statusSala = $sala['status_sala'];
                        
                        if ($emManutencaoNaData) {
                            $statusTexto = 'MANUTENÇÃO';
                            $statusBadgeClass = 'badge-manutencao';
                        } elseif ($statusSala === 'manutencao') {
                            $statusTexto = 'MANUTENÇÃO';
                            $statusBadgeClass = 'badge-manutencao';
                        } elseif ($statusSala === 'inativa') {
                            $statusTexto = 'INATIVA';
                            $statusBadgeClass = 'badge-inativa';
                        } elseif ($aula !== null) {
                            $statusTexto = 'OCUPADA';
                            $statusBadgeClass = 'badge-ocupada';
                        } else {
                            $statusTexto = 'DISPONÍVEL';
                            $statusBadgeClass = 'badge-disponivel';
                        }

                        $numeroTurma = $aula ? htmlspecialchars($aula['numero_curso']) : '';
                        $nomeCurso = $aula ? htmlspecialchars($aula['nome_curso']) : '';
                        $horarioAula = $aula ? date('H:i', strtotime($aula['horario_inicio'])) . ' - ' . date('H:i', strtotime($aula['horario_fim'])) : '';
                        
                        $numeroAula = $aula ? ($aula['numero_aula_ordem'] ?? '-') : null;
                        $totalDias = $aula ? ($aula['dias_letivos_curso'] ?? null) : null;
                        
                        $exibirNumeroAula = $numeroAula !== null && $numeroAula !== '-';
                        $numeroAulaExibicao = $exibirNumeroAula ? $numeroAula : '?';
                        
                        $cursoComecou = false;
                        if ($aula && !empty($aula['data_inicio_curso'])) {
                            $dataInicioCurso = $aula['data_inicio_curso'];
                            $cursoComecou = strtotime($dataInicioCurso) <= time();
                        }

                        $icone = 'fa-door-open';
                        
                        if (empty($sala['tipo_sala'])) {
                            $tipoLabel = 'Sala';
                        } else {
                            $tipoLabel = ucfirst(str_replace('_', ' ', $sala['tipo_sala']));
                        }

                        $temSalas = true;
                    ?>
                    <div class="sala-card">
                        <span class="sala-numero">Sala <?php echo $sala['numero_sala']; ?></span>
                        
                        <div class="sala-tipo">
                            <i class="fas <?php echo $icone; ?>"></i>
                            <span><?php echo $tipoLabel; ?></span>
                        </div>

                        <div class="aula-info">
                            <?php if ($numeroTurma): ?>
                                <div class="turma-numero">Turma <?php echo $numeroTurma; ?></div>
                            <?php endif; ?>
                            
                            <?php if ($aula && $exibirNumeroAula): ?>
                                <div class="numero-aula">
                                    <i class="fas fa-hashtag"></i> 
                                    Aula <?php echo $numeroAulaExibicao; ?> 
                                    <?php if ($totalDias): ?>
                                        de <?php echo $totalDias; ?>
                                    <?php endif; ?>
                                    <?php if (!$cursoComecou): ?>
                                        <span class="badge-nao-iniciado">⏳ Não iniciado</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($aula && !$exibirNumeroAula): ?>
                                <div class="aula-curso" style="color: #999;">Aula sem numeração</div>
                            <?php elseif (!$aula): ?>
                                <div class="aula-curso" style="color: #999;">Sem agendamento</div>
                            <?php else: ?>
                                <div class="aula-curso" style="color: #999;">Aula não iniciada</div>
                            <?php endif; ?>
                            
                            <?php if ($nomeCurso): ?>
                                <div class="aula-curso"><i class="fas fa-folder-open"></i> <?php echo $nomeCurso; ?></div>
                            <?php endif; ?>
                            
                            <?php if ($horarioAula): ?>
                                <div class="aula-horario">
                                    <i class="fas fa-clock"></i> <?php echo $horarioAula; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <span class="status-badge <?php echo $statusBadgeClass; ?>"><?php echo $statusTexto; ?></span>
                        
                        <?php if ($emManutencaoNaData && !empty($motivoManut)): ?>
                            <div class="manutencao-info">
                                <i class="fas fa-tools"></i> <?php echo htmlspecialchars($motivoManut); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$temSalas): ?>
                        <p style="grid-column: 1/-1; color: #9e9e9e; text-align: center; padding: 20px 0;">
                            <?php if (!empty($busca)): ?>
                                Nenhuma aula encontrada para "<?php echo htmlspecialchars($busca); ?>" neste turno.
                            <?php else: ?>
                                Nenhuma sala disponível neste turno.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>
            <?php endforeach; ?>
            
            <?php if (empty($agenda) || !$temAulasNoDia): ?>
                <div style="text-align: center; padding: 60px 20px; background: #ffffff; border-radius: 16px; border: 1px solid #e8edf5;">
                    <i class="fas fa-calendar-day" style="font-size: 48px; color: #dce3ef; display: block; margin-bottom: 16px;"></i>
                    <h3 style="color: #1a2639; margin-bottom: 8px;">Nenhuma aula agendada para <?php echo date('d/m/Y', strtotime($dataHoje)); ?></h3>
                    <p style="color: #7a8aa0;">
                        <?php if (!empty($busca)): ?>
                            Não encontramos resultados para "<?php echo htmlspecialchars($busca); ?>" nesta data.
                        <?php else: ?>
                            <?php if ($pode_agendar): ?>
                                Aproveite para <a href="../CRONOGRAMA_AULAS/agendar_aula.php" style="color: #1a73e8; text-decoration: none; font-weight: 600;">agendar uma nova aula</a>!
                            <?php else: ?>
                                Nenhuma aula programada para esta data.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

</body>
</html>