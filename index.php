<?php
/**
 * Formulário de Registro de Atas de Obra
 * 
 * @version 1.6.3
 */

// Inicia a sessão
session_start();

// Carrega o WordPress
require_once('../../wp-load.php');

global $wpdb;

define('ATA_OBRA_VERSAO', '1.6.3');

function usuario_atas_autenticado() {
    return isset($_SESSION['atas_autenticado']) && true === $_SESSION['atas_autenticado'];
}

function admin_atas_autenticado() {
    return isset($_SESSION['atas_admin_autenticado']) && true === $_SESSION['atas_admin_autenticado'];
}

function responder_json(array $payload, $status_code = 200) {
    status_header($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($payload);
    exit;
}

function exigir_autenticacao_atas() {
    if (!usuario_atas_autenticado()) {
        responder_json(['success' => false, 'message' => 'Sessao expirada. Faca login novamente.'], 401);
    }
}

function exigir_autenticacao_admin() {
    exigir_autenticacao_atas();

    if (!admin_atas_autenticado()) {
        responder_json(['success' => false, 'message' => 'Acesso administrativo nao autenticado.'], 403);
    }
}

// Cria as tabelas na primeira execução
criar_tabelas_atas();

// Busca as senhas de acesso do banco
$senha_config = $wpdb->get_var("SELECT config_value FROM wincor_config WHERE config_type = 'senha_atas'");
$senha_admin = $wpdb->get_var("SELECT config_value FROM wincor_config WHERE config_type = 'senha_admin'");

// Processa autenticação do modal admin via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $acao = sanitize_key(wp_unslash($_POST['action']));
    $acoes_admin = array(
        'listar_config',
        'adicionar_config',
        'editar_config',
        'deletar_config',
        'listar_atas',
        'exportar_csv',
        'deletar_ata',
    );

    if ($acao === 'verificar_senha_admin') {
        exigir_autenticacao_atas();

        $senha_digitada = isset($_POST['senha_admin']) ? (string) wp_unslash($_POST['senha_admin']) : '';

        if ($senha_admin !== null && hash_equals((string) $senha_admin, $senha_digitada)) {
            session_regenerate_id(true);
            $_SESSION['atas_admin_autenticado'] = true;
            responder_json(['success' => true]);
        }

        unset($_SESSION['atas_admin_autenticado']);
        responder_json(['success' => false, 'message' => 'Senha incorreta!'], 403);
    }

    if ($acao === 'logout_admin') {
        exigir_autenticacao_atas();
        unset($_SESSION['atas_admin_autenticado']);
        responder_json(['success' => true]);
    }

    if (in_array($acao, $acoes_admin, true)) {
        exigir_autenticacao_admin();
        header('Content-Type: application/json; charset=utf-8');

// Processa ações administrativas via AJAX
        switch ($acao) {
        case 'listar_config':
            $tipo = isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : '';
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT id, config_value, config_order FROM wincor_config WHERE config_type = %s ORDER BY config_order, id",
                $tipo
            ));
            echo json_encode(['success' => true, 'items' => $items]);
            exit;
            
        case 'adicionar_config':
            $tipo = isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : '';
            $valor = isset($_POST['valor']) ? sanitize_text_field(wp_unslash($_POST['valor'])) : '';
            
            // Busca a maior ordem atual
            $max_order = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(config_order) FROM wincor_config WHERE config_type = %s",
                $tipo
            ));
            
            $result = $wpdb->insert(
                'wincor_config',
                array(
                    'config_type' => $tipo,
                    'config_value' => $valor,
                    'config_order' => ($max_order + 1)
                ),
                array('%s', '%s', '%d')
            );
            
            if ($result) {
                echo json_encode(['success' => true, 'id' => $wpdb->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao adicionar']);
            }
            exit;
            
        case 'editar_config':
            $id = intval($_POST['id']);
            $valor = isset($_POST['valor']) ? sanitize_text_field(wp_unslash($_POST['valor'])) : '';
            
            $result = $wpdb->update(
                'wincor_config',
                array('config_value' => $valor),
                array('id' => $id),
                array('%s'),
                array('%d')
            );
            
            if ($result !== false) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao editar']);
            }
            exit;
            
        case 'deletar_config':
            $id = intval($_POST['id']);
            
            $result = $wpdb->delete(
                'wincor_config',
                array('id' => $id),
                array('%d')
            );
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao deletar']);
            }
            exit;
            
        case 'listar_atas':
            $pagina = isset($_POST['pagina']) ? intval($_POST['pagina']) : 1;
            $por_pagina = 10;
            $offset = ($pagina - 1) * $por_pagina;
            
            // Conta total de atas
            $total = $wpdb->get_var("SELECT COUNT(*) FROM wincor_atas");
            $total_paginas = ceil($total / $por_pagina);
            
            // Busca atas da página atual
            $atas = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data_criacao, data_ata FROM wincor_atas ORDER BY data_criacao DESC LIMIT %d OFFSET %d",
                $por_pagina,
                $offset
            ));
            
            // Para cada ata, busca os metadados
            $atas_completas = array();
            foreach ($atas as $ata) {
                $meta = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, meta_value FROM wincor_atas_meta WHERE ata_id = %d",
                    $ata->id
                ));
                
                $ata_data = array(
                    'id' => $ata->id,
                    'data_criacao' => $ata->data_criacao,
                    'data_ata' => $ata->data_ata,
                    'meta' => array()
                );
                
                foreach ($meta as $m) {
                    $ata_data['meta'][$m->meta_key] = $m->meta_value;
                }
                
                $atas_completas[] = $ata_data;
            }
            
            echo json_encode([
                'success' => true,
                'atas' => $atas_completas,
                'pagina_atual' => $pagina,
                'total_paginas' => $total_paginas,
                'total_registros' => $total
            ]);
            exit;
            
        case 'exportar_csv':
            // Busca todas as atas
            $atas = $wpdb->get_results("SELECT id, data_criacao, data_ata FROM wincor_atas ORDER BY data_criacao DESC");
            
            // Configura headers para download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="atas_obra_' . date('Y-m-d_His') . '.csv"');
            
            // Abre output
            $output = fopen('php://output', 'w');
            
            // BOM para UTF-8 (para Excel reconhecer acentos)
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Cabeçalho do CSV
            fputcsv($output, array(
                'ID',
                'Data da Ata',
                'Data de Registro',
                'Hora de Registro',
                'Obra',
                'Técnicos',
                'Hora Início',
                'Hora Término',
                'Participantes',
                'Atividades Realizadas',
                'Pendências'
            ), ';');
            
            // Dados
            foreach ($atas as $ata) {
                $meta = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, meta_value FROM wincor_atas_meta WHERE ata_id = %d",
                    $ata->id
                ));
                
                $meta_array = array();
                foreach ($meta as $m) {
                    $meta_array[$m->meta_key] = $m->meta_value;
                }
                
                // Extrai técnicos
                $tecnicos = array();
                $i = 1;
                while (isset($meta_array['tecnico_' . $i])) {
                    $tecnicos[] = $meta_array['tecnico_' . $i];
                    $i++;
                }
                
                // Extrai participantes
                $participantes = array();
                $i = 1;
                while (isset($meta_array['participante_' . $i . '_nome'])) {
                    $nome = $meta_array['participante_' . $i . '_nome'];
                    $funcao = isset($meta_array['participante_' . $i . '_funcao']) ? $meta_array['participante_' . $i . '_funcao'] : '';
                    $participantes[] = $nome . ($funcao ? ' (' . $funcao . ')' : '');
                    $i++;
                }
                
                // Extrai pendências
                $pendencias = array();
                $i = 1;
                while (isset($meta_array['pendencia_' . $i . '_descricao'])) {
                    $desc = $meta_array['pendencia_' . $i . '_descricao'];
                    $resp = isset($meta_array['pendencia_' . $i . '_responsavel']) ? $meta_array['pendencia_' . $i . '_responsavel'] : '';
                    $pendencias[] = $desc . ($resp ? ' [Resp: ' . $resp . ']' : '');
                    $i++;
                }
                
                // Formata datas
                $data_ata_formatada = date('d/m/Y', strtotime($ata->data_ata));
                $data_registro = date('d/m/Y', strtotime($ata->data_criacao));
                $hora_registro = date('H:i', strtotime($ata->data_criacao));
                
                fputcsv($output, array(
                    $ata->id,
                    $data_ata_formatada,
                    $data_registro,
                    $hora_registro,
                    isset($meta_array['obra']) ? $meta_array['obra'] : '',
                    implode(', ', $tecnicos),
                    isset($meta_array['hora_inicio']) ? $meta_array['hora_inicio'] : '',
                    isset($meta_array['hora_termino']) ? $meta_array['hora_termino'] : '',
                    implode('; ', $participantes),
                    isset($meta_array['atividades']) ? $meta_array['atividades'] : '',
                    implode('; ', $pendencias)
                ), ';');
            }
            
            fclose($output);
            exit;
            
        case 'deletar_ata':
            $ata_id = intval($_POST['ata_id']);
            
            // Deleta os metadados da ata
            $result_meta = $wpdb->delete(
                'wincor_atas_meta',
                array('ata_id' => $ata_id),
                array('%d')
            );
            
            // Deleta a ata
            $result_ata = $wpdb->delete(
                'wincor_atas',
                array('id' => $ata_id),
                array('%d')
            );
            
            if ($result_ata) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao deletar ata']);
            }
            exit;
    }
    }
}

// Processa logout
if (isset($_GET['logout'])) {
    unset($_SESSION['atas_autenticado']);
    unset($_SESSION['atas_admin_autenticado']);
    header('Location: index.php');
    exit;
}

// Processa login
$erro_login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha_acesso'])) {
    $senha_digitada = (string) wp_unslash($_POST['senha_acesso']);
    if ($senha_digitada === $senha_config) {
        session_regenerate_id(true);
        $_SESSION['atas_autenticado'] = true;
        unset($_SESSION['atas_admin_autenticado']);
        header('Location: index.php');
        exit;
    } else {
        $erro_login = 'Senha incorreta!';
    }
}

// Verifica se está autenticado
if (!isset($_SESSION['atas_autenticado']) || $_SESSION['atas_autenticado'] !== true) {
    // Exibe formulário de login
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acesso - Ata de Obra</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                background-color: #f6f6f6;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            .login-card {
                max-width: 400px;
                width: 100%;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Acesso Restrito</h4>
                </div>
                <div class="card-body">
                    <?php if ($erro_login): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo $erro_login; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="senha_acesso" class="form-label">Senha de Acesso</label>
                            <input type="password" name="senha_acesso" id="senha_acesso" class="form-control" required autofocus>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Entrar</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="text-center text-muted small mt-3">
                Versao do sistema: <?php echo esc_html(ATA_OBRA_VERSAO); ?>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

global $wpdb;

// Recupera mensagens da sessão (após redirect)
$mensagem = '';
$tipo_mensagem = '';
if (isset($_SESSION['mensagem'])) {
    $mensagem = $_SESSION['mensagem'];
    $tipo_mensagem = $_SESSION['tipo_mensagem'];
    unset($_SESSION['mensagem']);
    unset($_SESSION['tipo_mensagem']);
}

// Processa o formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_envio'])) {
    // Validação dos campos obrigatórios
    if (empty($_POST['tecnicos']) || empty($_POST['obra']) || empty($_POST['hora_inicio']) || empty($_POST['hora_termino'])) {
        $_SESSION['mensagem'] = 'Por favor, preencha todos os campos obrigatórios.';
        $_SESSION['tipo_mensagem'] = 'danger';
        header('Location: index.php');
        exit;
    } else {
        // Cria as tabelas se não existirem
        criar_tabelas_atas();
        
        // Insere a ata principal
        $data_ata = !empty($_POST['data']) ? sanitize_text_field($_POST['data']) : date('Y-m-d');
        
        $wpdb->insert(
            'wincor_atas',
            array(
                'data_criacao' => current_time('mysql'),
                'data_ata' => $data_ata
            ),
            array('%s', '%s')
        );
        
        $ata_id = $wpdb->insert_id;
        
        if ($ata_id) {
            // Salva os técnicos
            if (!empty($_POST['tecnicos'])) {
                foreach ($_POST['tecnicos'] as $index => $tecnico) {
                    if (!empty($tecnico)) {
                        $wpdb->insert(
                            'wincor_atas_meta',
                            array(
                                'ata_id' => $ata_id,
                                'meta_key' => 'tecnico_' . ($index + 1),
                                'meta_value' => sanitize_text_field($tecnico)
                            ),
                            array('%d', '%s', '%s')
                        );
                    }
                }
            }
            
            // Salva a obra
            $wpdb->insert(
                'wincor_atas_meta',
                array(
                    'ata_id' => $ata_id,
                    'meta_key' => 'obra',
                    'meta_value' => sanitize_text_field($_POST['obra'])
                ),
                array('%d', '%s', '%s')
            );
            
            // Salva participantes
            if (!empty($_POST['participante_nome'])) {
                foreach ($_POST['participante_nome'] as $index => $nome) {
                    if (!empty($nome)) {
                        $funcao = isset($_POST['participante_funcao'][$index]) ? sanitize_text_field($_POST['participante_funcao'][$index]) : '';
                        $wpdb->insert(
                            'wincor_atas_meta',
                            array(
                                'ata_id' => $ata_id,
                                'meta_key' => 'participante_' . ($index + 1) . '_nome',
                                'meta_value' => sanitize_text_field($nome)
                            ),
                            array('%d', '%s', '%s')
                        );
                        $wpdb->insert(
                            'wincor_atas_meta',
                            array(
                                'ata_id' => $ata_id,
                                'meta_key' => 'participante_' . ($index + 1) . '_funcao',
                                'meta_value' => $funcao
                            ),
                            array('%d', '%s', '%s')
                        );
                    }
                }
            }
            
            // Salva horários
            $wpdb->insert(
                'wincor_atas_meta',
                array(
                    'ata_id' => $ata_id,
                    'meta_key' => 'hora_inicio',
                    'meta_value' => sanitize_text_field($_POST['hora_inicio'])
                ),
                array('%d', '%s', '%s')
            );
            
            $wpdb->insert(
                'wincor_atas_meta',
                array(
                    'ata_id' => $ata_id,
                    'meta_key' => 'hora_termino',
                    'meta_value' => sanitize_text_field($_POST['hora_termino'])
                ),
                array('%d', '%s', '%s')
            );
            
            // Salva atividades realizadas
            if (!empty($_POST['atividades'])) {
                $wpdb->insert(
                    'wincor_atas_meta',
                    array(
                        'ata_id' => $ata_id,
                        'meta_key' => 'atividades',
                        'meta_value' => sanitize_textarea_field($_POST['atividades'])
                    ),
                    array('%d', '%s', '%s')
                );
            }
            
            // Salva pendências
            if (!empty($_POST['pendencia_descricao'])) {
                foreach ($_POST['pendencia_descricao'] as $index => $pendencia) {
                    if (!empty($pendencia)) {
                        $responsavel = isset($_POST['pendencia_responsavel'][$index]) ? sanitize_text_field($_POST['pendencia_responsavel'][$index]) : '';
                        $wpdb->insert(
                            'wincor_atas_meta',
                            array(
                                'ata_id' => $ata_id,
                                'meta_key' => 'pendencia_' . ($index + 1) . '_descricao',
                                'meta_value' => sanitize_text_field($pendencia)
                            ),
                            array('%d', '%s', '%s')
                        );
                        $wpdb->insert(
                            'wincor_atas_meta',
                            array(
                                'ata_id' => $ata_id,
                                'meta_key' => 'pendencia_' . ($index + 1) . '_responsavel',
                                'meta_value' => $responsavel
                            ),
                            array('%d', '%s', '%s')
                        );
                    }
                }
            }
            
            $_SESSION['mensagem'] = 'Ata de obra registrada com sucesso! ID: ' . $ata_id;
            $_SESSION['tipo_mensagem'] = 'success';
            header('Location: index.php');
            exit;
        } else {
            $_SESSION['mensagem'] = 'Erro ao salvar a ata. Por favor, tente novamente.';
            $_SESSION['tipo_mensagem'] = 'danger';
            header('Location: index.php');
            exit;
        }
    }
}

// Função para criar as tabelas
function criar_tabelas_atas() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabela principal de atas
    $sql_atas = "CREATE TABLE IF NOT EXISTS wincor_atas (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        data_criacao datetime NOT NULL,
        data_ata date NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    // Tabela de metadados das atas
    $sql_atas_meta = "CREATE TABLE IF NOT EXISTS wincor_atas_meta (
        meta_id bigint(20) NOT NULL AUTO_INCREMENT,
        ata_id bigint(20) NOT NULL,
        meta_key varchar(255) NOT NULL,
        meta_value longtext,
        PRIMARY KEY (meta_id),
        KEY ata_id (ata_id),
        KEY meta_key (meta_key)
    ) $charset_collate;";
    
    // Tabela de configurações
    $sql_config = "CREATE TABLE IF NOT EXISTS wincor_config (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        config_type varchar(50) NOT NULL,
        config_value varchar(255) NOT NULL,
        config_order int(11) DEFAULT 0,
        PRIMARY KEY (id),
        KEY config_type (config_type)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_atas);
    dbDelta($sql_atas_meta);
    dbDelta($sql_config);
}

// Busca técnicos e obras do banco
$tecnicos = $wpdb->get_results("SELECT config_value FROM wincor_config WHERE config_type = 'tecnico' ORDER BY config_order");
$obras = $wpdb->get_results("SELECT config_value FROM wincor_config WHERE config_type = 'obra' ORDER BY config_order");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Ata de Obra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f6f6f6;
            padding: 20px 0;
        }
        .container {
            max-width: 900px;
        }
        .card {
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .btn-add {
            padding: 0.25rem 0.5rem;
            font-size: 1.2rem;
            line-height: 1;
        }
        .field-group {
            margin-bottom: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
        }
        .btn-remove {
            padding: 0.25rem 0.5rem;
            font-size: 1.2rem;
            line-height: 1;
        }
        .required-label::after {
            content: " *";
            color: #dc3545;
        }
        .form-control, .form-select {
            border: 1px solid #495057 !important;
        }
        .form-check-input {
            border: 1px solid #495057 !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="">
            <div class="card-header text-black d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Registro de Ata de Obra</h3>
                <a href="?logout=1" class="btn btn-sm btn-outline-danger">Sair</a>
            </div>
            <div class="card-body">
                <?php if ($mensagem): ?>
                    <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensagem; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="formAta">
                    <!-- Técnicos -->
                    <div class="mb-3">
                        <label class="form-label required-label">Técnico(s)</label>
                        <div id="tecnicos-container">
                            <div class="field-group d-flex align-items-center gap-2 mb-2">
                                <select name="tecnicos[]" class="form-select" required>
                                    <option value="">Selecione um técnico</option>
                                    <?php foreach ($tecnicos as $tecnico): ?>
                                        <option value="<?php echo esc_attr($tecnico->config_value); ?>">
                                            <?php echo esc_html($tecnico->config_value); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success btn-add" onclick="adicionarTecnico()">+</button>
                            </div>
                        </div>
                    </div>

                    <!-- Obra -->
                    <div class="mb-3">
                        <label class="form-label required-label">Obra</label>
                        <select name="obra" class="form-select" required>
                            <option value="">Selecione uma obra</option>
                            <?php foreach ($obras as $obra): ?>
                                <option value="<?php echo esc_attr($obra->config_value); ?>">
                                    <?php echo esc_html($obra->config_value); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Participantes -->
                    <div class="mb-3">
                        <label class="form-label">Participantes (caso exista)</label>
                        <div id="participantes-container">
                            <div class="field-group">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <input type="text" name="participante_nome[]" class="form-control" placeholder="Nome">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="participante_funcao[]" class="form-control" placeholder="Função">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-success btn-add" onclick="adicionarParticipante()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data -->
                    <div class="mb-3">
                        <label class="form-label">Data</label>
                        <input type="date" name="data" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <!-- Horários -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required-label">Hora de Início</label>
                            <input type="time" name="hora_inicio" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label">Hora de Término</label>
                            <input type="time" name="hora_termino" class="form-control" required>
                        </div>
                    </div>

                    <!-- Atividades Realizadas -->
                    <div class="mb-3">
                        <label class="form-label">Atividades Realizadas</label>
                        <textarea name="atividades" class="form-control" rows="5" placeholder="Descreva as atividades realizadas..."></textarea>
                    </div>

                    <!-- Pendências -->
                    <div class="mb-3">
                        <label class="form-label">Pendências</label>
                        <div id="pendencias-container">
                            <div class="field-group">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <input type="text" name="pendencia_descricao[]" class="form-control" placeholder="Pendência">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="pendencia_responsavel[]" class="form-control" placeholder="Responsável">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-success btn-add" onclick="adicionarPendencia()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmação -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="confirmar_envio" class="form-check-input" id="confirmarEnvio" required>
                            <label class="form-check-label required-label" for="confirmarEnvio">
                                Marque para salvar (Para evitar envio acidental)
                            </label>
                        </div>
                    </div>

                    <!-- Botões -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                        <button type="submit" class="btn btn-primary">Salvar Ata</button>
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalAdmin">Admin</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <footer class="container mt-3">
        <div class="text-center text-muted small">
            Versao do sistema: <?php echo esc_html(ATA_OBRA_VERSAO); ?>
        </div>
    </footer>

    <!-- Modal Admin -->
    <div class="modal fade" id="modalAdmin" tabindex="-1" aria-labelledby="modalAdminLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <!-- Tela de Login do Admin -->
                <div id="adminLogin">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title" id="modalAdminLabel">Acesso Administrativo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="erroSenhaAdmin" class="alert alert-danger d-none" role="alert"></div>
                        <form id="formSenhaAdmin">
                            <div class="mb-3">
                                <label for="senhaAdmin" class="form-label">Senha de Administrador</label>
                                <input type="password" class="form-control" id="senhaAdmin" required autofocus>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-warning">Entrar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Conteúdo do Admin (oculto inicialmente) -->
                <div id="adminConteudo" class="d-none">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Painel Administrativo</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Abas de Navegação -->
                        <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tecnicos-tab" data-bs-toggle="tab" data-bs-target="#tecnicos-panel" type="button" role="tab">
                                    Técnicos
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="obras-tab" data-bs-toggle="tab" data-bs-target="#obras-panel" type="button" role="tab">
                                    Obras
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="relatorios-tab" data-bs-toggle="tab" data-bs-target="#relatorios-panel" type="button" role="tab">
                                    Relatórios
                                </button>
                            </li>
                        </ul>

                        <!-- Conteúdo das Abas -->
                        <div class="tab-content" id="adminTabsContent">
                            <!-- Aba Técnicos -->
                            <div class="tab-pane fade show active" id="tecnicos-panel" role="tabpanel">
                                <div class="mb-3">
                                    <h6>Adicionar Novo Técnico</h6>
                                    <div class="input-group">
                                        <input type="text" id="novoTecnico" class="form-control" placeholder="Nome do técnico">
                                        <button class="btn btn-primary" onclick="adicionarItem('tecnico')">Adicionar</button>
                                    </div>
                                </div>
                                <hr>
                                <h6>Técnicos Cadastrados</h6>
                                <div id="listaTecnicos" class="list-group">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Carregando...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Aba Obras -->
                            <div class="tab-pane fade" id="obras-panel" role="tabpanel">
                                <div class="mb-3">
                                    <h6>Adicionar Nova Obra</h6>
                                    <div class="input-group">
                                        <input type="text" id="novaObra" class="form-control" placeholder="Nome da obra">
                                        <button class="btn btn-primary" onclick="adicionarItem('obra')">Adicionar</button>
                                    </div>
                                </div>
                                <hr>
                                <h6>Obras Cadastradas</h6>
                                <div id="listaObras" class="list-group">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Carregando...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Aba Relatórios -->
                            <div class="tab-pane fade" id="relatorios-panel" role="tabpanel">
                                <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h6 class="mb-0">Atas Registradas</h6>
                                    <div class="d-flex gap-2 align-items-center">
                                        <span id="totalRegistros" class="badge bg-primary">0 registros</span>
                                        <button class="btn btn-success btn-sm" onclick="exportarCSV()">
                                            📊 Exportar CSV
                                        </button>
                                    </div>
                                </div>
                                <div id="listaAtas">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Carregando...</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Paginação -->
                                <nav aria-label="Navegação de páginas" id="paginacaoContainer" class="d-none">
                                    <ul class="pagination pagination-sm justify-content-center mt-3" id="paginacao">
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script>
        // Adicionar técnico
        function adicionarTecnico() {
            const container = document.getElementById('tecnicos-container');
            const newField = document.createElement('div');
            newField.className = 'field-group d-flex align-items-center gap-2 mb-2';
            newField.innerHTML = `
                <select name="tecnicos[]" class="form-select">
                    <option value="">Selecione um técnico</option>
                    <?php foreach ($tecnicos as $tecnico): ?>
                        <option value="<?php echo esc_attr($tecnico->config_value); ?>">
                            <?php echo esc_html($tecnico->config_value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-success btn-add" onclick="adicionarTecnico()">+</button>
                <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">×</button>
            `;
            container.appendChild(newField);
        }

        // Adicionar participante
        function adicionarParticipante() {
            const container = document.getElementById('participantes-container');
            const newField = document.createElement('div');
            newField.className = 'field-group';
            newField.innerHTML = `
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="participante_nome[]" class="form-control" placeholder="Nome">
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="participante_funcao[]" class="form-control" placeholder="Função">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success btn-add" onclick="adicionarParticipante()">+</button>
                        <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        }

        // Adicionar pendência
        function adicionarPendencia() {
            const container = document.getElementById('pendencias-container');
            const newField = document.createElement('div');
            newField.className = 'field-group';
            newField.innerHTML = `
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="pendencia_descricao[]" class="form-control" placeholder="Pendência">
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="pendencia_responsavel[]" class="form-control" placeholder="Responsável">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success btn-add" onclick="adicionarPendencia()">+</button>
                        <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        }

        // Remover campo
        function removerCampo(element) {
            element.closest('.field-group').remove();
        }

        // Flag para controlar se houve alterações
        let houveAlteracoes = false;

        // Autenticação do Modal Admin
        document.getElementById('formSenhaAdmin').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const senha = document.getElementById('senhaAdmin').value;
            const erroDiv = document.getElementById('erroSenhaAdmin');
            
            // Envia requisição AJAX para verificar senha
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=verificar_senha_admin&senha_admin=' + encodeURIComponent(senha)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Oculta login e mostra conteúdo
                    document.getElementById('adminLogin').classList.add('d-none');
                    document.getElementById('adminConteudo').classList.remove('d-none');
                    erroDiv.classList.add('d-none');
                    document.getElementById('senhaAdmin').value = '';
                    
                    // Reset da flag de alterações
                    houveAlteracoes = false;
                    
                    // Carrega as listas
                    carregarLista('tecnico');
                    carregarLista('obra');
                    carregarRelatorios(1);
                } else {
                    // Mostra erro
                    erroDiv.textContent = data.message || 'Senha incorreta!';
                    erroDiv.classList.remove('d-none');
                    document.getElementById('senhaAdmin').value = '';
                    document.getElementById('senhaAdmin').focus();
                }
            })
            .catch(error => {
                erroDiv.textContent = 'Erro ao verificar senha. Tente novamente.';
                erroDiv.classList.remove('d-none');
            });
        });

        // Função para carregar lista de técnicos ou obras
        function encerrarSessaoAdmin() {
            return fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout_admin'
            }).catch(() => null);
        }

        function carregarLista(tipo) {
            const containerId = tipo === 'tecnico' ? 'listaTecnicos' : 'listaObras';
            const container = document.getElementById(containerId);
            
            container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=listar_config&tipo=' + tipo
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.items.length === 0) {
                        container.innerHTML = '<div class="alert alert-info">Nenhum item cadastrado</div>';
                    } else {
                        container.innerHTML = '';
                        data.items.forEach(item => {
                            container.appendChild(criarItemLista(item, tipo));
                        });
                    }
                }
            });
        }

        // Função para criar elemento da lista
        function criarItemLista(item, tipo) {
            const div = document.createElement('div');
            div.className = 'list-group-item d-flex justify-content-between align-items-center';
            div.id = 'item-' + item.id;
            
            const span = document.createElement('span');
            span.className = 'item-valor';
            span.textContent = item.config_value;
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm item-input d-none';
            input.value = item.config_value;
            
            const btnGroup = document.createElement('div');
            btnGroup.className = 'btn-group btn-group-sm';
            
            const btnEditar = document.createElement('button');
            btnEditar.className = 'btn btn-warning btn-editar';
            btnEditar.innerHTML = '✏️';
            btnEditar.onclick = () => editarItem(item.id, tipo);
            
            const btnSalvar = document.createElement('button');
            btnSalvar.className = 'btn btn-success btn-salvar d-none';
            btnSalvar.innerHTML = '✓';
            btnSalvar.onclick = () => salvarItem(item.id, tipo);
            
            const btnCancelar = document.createElement('button');
            btnCancelar.className = 'btn btn-secondary btn-cancelar d-none';
            btnCancelar.innerHTML = '✕';
            btnCancelar.onclick = () => cancelarEdicao(item.id);
            
            const btnDeletar = document.createElement('button');
            btnDeletar.className = 'btn btn-danger btn-deletar';
            btnDeletar.innerHTML = '🗑️';
            btnDeletar.onclick = () => deletarItem(item.id, tipo);
            
            btnGroup.appendChild(btnEditar);
            btnGroup.appendChild(btnSalvar);
            btnGroup.appendChild(btnCancelar);
            btnGroup.appendChild(btnDeletar);
            
            div.appendChild(span);
            div.appendChild(input);
            div.appendChild(btnGroup);
            
            return div;
        }

        // Função para adicionar novo item
        function adicionarItem(tipo) {
            const inputId = tipo === 'tecnico' ? 'novoTecnico' : 'novaObra';
            const input = document.getElementById(inputId);
            const valor = input.value.trim();
            
            if (!valor) {
                alert('Por favor, preencha o campo');
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=adicionar_config&tipo=' + tipo + '&valor=' + encodeURIComponent(valor)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    houveAlteracoes = true;
                    carregarLista(tipo);
                } else {
                    alert(data.message || 'Erro ao adicionar');
                }
            });
        }

        // Função para editar item
        function editarItem(id, tipo) {
            const item = document.getElementById('item-' + id);
            const span = item.querySelector('.item-valor');
            const input = item.querySelector('.item-input');
            const btnEditar = item.querySelector('.btn-editar');
            const btnSalvar = item.querySelector('.btn-salvar');
            const btnCancelar = item.querySelector('.btn-cancelar');
            const btnDeletar = item.querySelector('.btn-deletar');
            
            span.classList.add('d-none');
            input.classList.remove('d-none');
            btnEditar.classList.add('d-none');
            btnSalvar.classList.remove('d-none');
            btnCancelar.classList.remove('d-none');
            btnDeletar.classList.add('d-none');
            
            input.focus();
        }

        // Função para salvar edição
        function salvarItem(id, tipo) {
            const item = document.getElementById('item-' + id);
            const input = item.querySelector('.item-input');
            const valor = input.value.trim();
            
            if (!valor) {
                alert('O campo não pode estar vazio');
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=editar_config&id=' + id + '&valor=' + encodeURIComponent(valor)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    houveAlteracoes = true;
                    carregarLista(tipo);
                } else {
                    alert(data.message || 'Erro ao editar');
                }
            });
        }

        // Função para cancelar edição
        function cancelarEdicao(id) {
            const item = document.getElementById('item-' + id);
            const span = item.querySelector('.item-valor');
            const input = item.querySelector('.item-input');
            const btnEditar = item.querySelector('.btn-editar');
            const btnSalvar = item.querySelector('.btn-salvar');
            const btnCancelar = item.querySelector('.btn-cancelar');
            const btnDeletar = item.querySelector('.btn-deletar');
            
            input.value = span.textContent;
            span.classList.remove('d-none');
            input.classList.add('d-none');
            btnEditar.classList.remove('d-none');
            btnSalvar.classList.add('d-none');
            btnCancelar.classList.add('d-none');
            btnDeletar.classList.remove('d-none');
        }

        // Função para deletar item
        function deletarItem(id, tipo) {
            if (!confirm('Tem certeza que deseja deletar este item?')) {
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=deletar_config&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    houveAlteracoes = true;
                    carregarLista(tipo);
                } else {
                    alert(data.message || 'Erro ao deletar');
                }
            });
        }

        // Permite adicionar com Enter
        document.getElementById('novoTecnico').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                adicionarItem('tecnico');
            }
        });
        
        document.getElementById('novaObra').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                adicionarItem('obra');
            }
        });

        // Função para carregar relatórios de atas
        function carregarRelatorios(pagina = 1) {
            const container = document.getElementById('listaAtas');
            container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=listar_atas&pagina=' + pagina
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('totalRegistros').textContent = data.total_registros + ' registro' + (data.total_registros !== 1 ? 's' : '');
                    
                    if (data.atas.length === 0) {
                        container.innerHTML = '<div class="alert alert-info">Nenhuma ata registrada</div>';
                        document.getElementById('paginacaoContainer').classList.add('d-none');
                    } else {
                        container.innerHTML = '';
                        data.atas.forEach(ata => {
                            container.appendChild(criarCardAta(ata));
                        });
                        
                        // Cria paginação
                        if (data.total_paginas > 1) {
                            criarPaginacao(data.pagina_atual, data.total_paginas);
                            document.getElementById('paginacaoContainer').classList.remove('d-none');
                        } else {
                            document.getElementById('paginacaoContainer').classList.add('d-none');
                        }
                    }
                }
            });
        }

        // Função para criar card de ata
        function criarCardAta(ata) {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            
            // Extrai técnicos
            const tecnicos = [];
            let i = 1;
            while (ata.meta['tecnico_' + i]) {
                tecnicos.push(ata.meta['tecnico_' + i]);
                i++;
            }
            
            // Extrai participantes
            const participantes = [];
            i = 1;
            while (ata.meta['participante_' + i + '_nome']) {
                participantes.push({
                    nome: ata.meta['participante_' + i + '_nome'],
                    funcao: ata.meta['participante_' + i + '_funcao'] || ''
                });
                i++;
            }
            
            // Extrai pendências
            const pendencias = [];
            i = 1;
            while (ata.meta['pendencia_' + i + '_descricao']) {
                pendencias.push({
                    descricao: ata.meta['pendencia_' + i + '_descricao'],
                    responsavel: ata.meta['pendencia_' + i + '_responsavel'] || ''
                });
                i++;
            }
            
            // Formata datas
            const dataAta = new Date(ata.data_ata + 'T00:00:00').toLocaleDateString('pt-BR');
            const dataCriacao = new Date(ata.data_criacao).toLocaleDateString('pt-BR');
            const horaCriacao = new Date(ata.data_criacao).toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
            
            // Calcula duração
            let duracao = '-';
            if (ata.meta.hora_inicio && ata.meta.hora_termino) {
                const [horaIni, minIni] = ata.meta.hora_inicio.split(':').map(Number);
                const [horaFim, minFim] = ata.meta.hora_termino.split(':').map(Number);
                
                let totalMinutosIni = horaIni * 60 + minIni;
                let totalMinutosFim = horaFim * 60 + minFim;
                
                // Se hora fim for menor, passou da meia-noite
                if (totalMinutosFim < totalMinutosIni) {
                    totalMinutosFim += 24 * 60;
                }
                
                const diferencaMinutos = totalMinutosFim - totalMinutosIni;
                const horas = Math.floor(diferencaMinutos / 60);
                const minutos = diferencaMinutos % 60;
                
                duracao = `${horas}h${minutos > 0 ? minutos.toString().padStart(2, '0') + 'min' : ''}`;
            }
            
            const accordionId = 'accordion-' + ata.id;
            
            card.innerHTML = `
                <div class="card-body p-2 p-md-3">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <small class="text-muted d-block">ID: #${ata.id}</small>
                            <strong>Data da Ata:</strong> ${dataAta}
                        </div>
                        <div class="col-12 col-md-6 d-flex justify-content-between align-items-start">
                            <small class="text-muted d-block">Registrado em: ${dataCriacao} às ${horaCriacao}</small>
                            <button class="btn btn-danger btn-sm" onclick="deletarAta(${ata.id})" title="Deletar ata">
                                🗑️
                            </button>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <strong>Obra:</strong> ${ata.meta.obra || '-'}
                        </div>
                        <div class="col-12 col-md-6">
                            <strong>Técnico(s):</strong> ${tecnicos.join(', ') || '-'}
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6 col-md-3">
                            <strong>Início:</strong> ${ata.meta.hora_inicio || '-'}
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>Término:</strong> ${ata.meta.hora_termino || '-'}
                        </div>
                        <div class="col-12 col-md-3">
                            <strong>Duração:</strong> <span>${duracao}</span>
                        </div>
                    </div>
                    
                    <!-- Accordion para detalhes completos -->
                    <div class="accordion mt-3" id="${accordionId}">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${ata.id}">
                                    Ver detalhes completos
                                </button>
                            </h2>
                            <div id="collapse-${ata.id}" class="accordion-collapse collapse" data-bs-parent="#${accordionId}">
                                <div class="accordion-body">
                                    ${ata.meta.atividades ? `
                                    <div class="mb-3">
                                        <strong>Atividades Realizadas:</strong>
                                        <p class="mb-0">${ata.meta.atividades.replace(/\n/g, '<br>')}</p>
                                    </div>
                                    ` : ''}
                                    
                                    ${participantes.length > 0 ? `
                                    <div class="mb-3">
                                        <strong>Participantes:</strong>
                                        <ul class="mb-0">
                                            ${participantes.map(p => `<li>${p.nome}${p.funcao ? ' - ' + p.funcao : ''}</li>`).join('')}
                                        </ul>
                                    </div>
                                    ` : ''}
                                    
                                    ${pendencias.length > 0 ? `
                                    <div class="mb-0">
                                        <strong>Pendências:</strong>
                                        <ul class="mb-0">
                                            ${pendencias.map(p => `<li>${p.descricao}${p.responsavel ? ' - <em>Responsável: ' + p.responsavel + '</em>' : ''}</li>`).join('')}
                                        </ul>
                                    </div>
                                    ` : ''}
                                    
                                    ${!ata.meta.atividades && participantes.length === 0 && pendencias.length === 0 ? `
                                    <p class="text-muted mb-0">Nenhum detalhe adicional registrado.</p>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            return card;
        }

        // Função para exportar CSV
        function exportarCSV() {
            // Cria um formulário temporário para fazer o download
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'exportar_csv';
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // Função para deletar ata
        function deletarAta(ataId) {
            if (!confirm('Tem certeza que deseja deletar esta ata?\n\nEsta ação não pode ser desfeita!')) {
                return;
            }
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=deletar_ata&ata_id=' + ataId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Ata deletada com sucesso!');
                    // Recarrega a lista de relatórios
                    carregarRelatorios(1);
                } else {
                    alert(data.message || 'Erro ao deletar ata');
                }
            })
            .catch(error => {
                alert('Erro ao deletar ata. Tente novamente.');
            });
        }

        // Função para criar paginação
        function criarPaginacao(paginaAtual, totalPaginas) {
            const paginacao = document.getElementById('paginacao');
            paginacao.innerHTML = '';
            
            // Botão anterior
            const liPrev = document.createElement('li');
            liPrev.className = 'page-item' + (paginaAtual === 1 ? ' disabled' : '');
            liPrev.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${paginaAtual - 1}); return false;">Anterior</a>`;
            paginacao.appendChild(liPrev);
            
            // Páginas
            const inicio = Math.max(1, paginaAtual - 2);
            const fim = Math.min(totalPaginas, paginaAtual + 2);
            
            for (let i = inicio; i <= fim; i++) {
                const li = document.createElement('li');
                li.className = 'page-item' + (i === paginaAtual ? ' active' : '');
                li.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${i}); return false;">${i}</a>`;
                paginacao.appendChild(li);
            }
            
            // Botão próximo
            const liNext = document.createElement('li');
            liNext.className = 'page-item' + (paginaAtual === totalPaginas ? ' disabled' : '');
            liNext.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${paginaAtual + 1}); return false;">Próximo</a>`;
            paginacao.appendChild(liNext);
        }

        // Foco automático no campo de senha ao abrir o modal
        document.getElementById('modalAdmin').addEventListener('shown.bs.modal', function () {
            document.getElementById('senhaAdmin').focus();
        });

        // Reset do modal quando fechar
        document.getElementById('modalAdmin').addEventListener('hidden.bs.modal', function () {
            // Se houve alterações, recarrega a página
            document.getElementById('adminLogin').classList.remove('d-none');
            document.getElementById('adminConteudo').classList.add('d-none');
            document.getElementById('erroSenhaAdmin').classList.add('d-none');
            document.getElementById('senhaAdmin').value = '';

            const precisaRecarregar = houveAlteracoes;
            houveAlteracoes = false;

            encerrarSessaoAdmin().finally(() => {
                if (precisaRecarregar) {
                    location.reload();
                }
            });
        });
    </script>
</body>
</html>
