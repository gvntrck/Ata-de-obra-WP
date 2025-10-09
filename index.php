<?php
/**
 * Formulário de Registro de Atas de Obra
 * 
 * @version 1.0.0
 */

// Carrega o WordPress
require_once('wp-load.php');

global $wpdb;

// Processa o formulário
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_envio'])) {
    // Validação dos campos obrigatórios
    if (empty($_POST['tecnicos']) || empty($_POST['obra']) || empty($_POST['hora_inicio']) || empty($_POST['hora_termino'])) {
        $mensagem = 'Por favor, preencha todos os campos obrigatórios.';
        $tipo_mensagem = 'danger';
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
            
            $mensagem = 'Ata de obra registrada com sucesso! ID: ' . $ata_id;
            $tipo_mensagem = 'success';
        } else {
            $mensagem = 'Erro ao salvar a ata. Por favor, tente novamente.';
            $tipo_mensagem = 'danger';
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
    
    // Insere dados de exemplo se a tabela estiver vazia
    $count = $wpdb->get_var("SELECT COUNT(*) FROM wincor_config");
    if ($count == 0) {
        // Técnicos de exemplo
        $wpdb->insert('wincor_config', array('config_type' => 'tecnico', 'config_value' => 'João Silva', 'config_order' => 1));
        $wpdb->insert('wincor_config', array('config_type' => 'tecnico', 'config_value' => 'Maria Santos', 'config_order' => 2));
        $wpdb->insert('wincor_config', array('config_type' => 'tecnico', 'config_value' => 'Pedro Oliveira', 'config_order' => 3));
        
        // Obras de exemplo
        $wpdb->insert('wincor_config', array('config_type' => 'obra', 'config_value' => 'Obra Centro Comercial', 'config_order' => 1));
        $wpdb->insert('wincor_config', array('config_type' => 'obra', 'config_value' => 'Obra Residencial Norte', 'config_order' => 2));
        $wpdb->insert('wincor_config', array('config_type' => 'obra', 'config_value' => 'Obra Industrial Sul', 'config_order' => 3));
    }
}

// Busca técnicos e obras do banco
$tecnicos = $wpdb->get_results("SELECT config_value FROM wincor_config WHERE config_type = 'tecnico' ORDER BY config_order");
$obras = $wpdb->get_results("SELECT config_value FROM wincor_config WHERE config_type = 'obra' ORDER BY config_order");

// Cria as tabelas na primeira execução
criar_tabelas_atas();
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
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
        }
        .remove-btn {
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2rem;
        }
        .required-label::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0">Registro de Ata de Obra</h3>
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
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="reset" class="btn btn-secondary">Limpar</button>
                        <button type="submit" class="btn btn-primary">Salvar Ata</button>
                    </div>
                </form>
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
                <span class="remove-btn" onclick="removerCampo(this)">×</span>
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
                        <span class="remove-btn" onclick="removerCampo(this)">×</span>
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
                        <span class="remove-btn" onclick="removerCampo(this)">×</span>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        }

        // Remover campo
        function removerCampo(element) {
            element.closest('.field-group').remove();
        }
    </script>
</body>
</html>
