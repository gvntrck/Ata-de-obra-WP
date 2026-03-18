<?php
declare(strict_types=1);

/**
 * Formulario de Registro de Atas de Obra
 *
 * @version 2.0.0
 */

session_start();
date_default_timezone_set('America/Sao_Paulo');

define('ATA_OBRA_VERSAO', '2.0.0');
define('ATA_OBRA_DB_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'ata-obra.sqlite');
define('ATA_OBRA_POR_PAGINA', 10);
define('ATA_OBRA_SELF', $_SERVER['PHP_SELF'] ?? 'index-sqlite.php');

function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function sanitize_key($key): string
{
    $key = strtolower((string) wp_unslash($key));

    return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
}

function sanitize_text_field($value): string
{
    $value = trim((string) wp_unslash($value));
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function sanitize_textarea_field($value): string
{
    $value = trim((string) wp_unslash($value));
    $value = strip_tags($value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\P{C}\n\t]+/u', '', $value) ?? $value;

    return trim($value);
}

function esc_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr($value): string
{
    return esc_html($value);
}

function wp_json_encode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return false === $json ? 'null' : $json;
}

function current_time(string $type = 'mysql'): string
{
    if ('mysql' === $type) {
        return date('Y-m-d H:i:s');
    }

    return (string) time();
}

function status_header(int $status_code): void
{
    http_response_code($status_code);
}

function ata_app_url(): string
{
    return ATA_OBRA_SELF;
}

function redirecionar_para_app(): void
{
    header('Location: ' . ata_app_url());
    exit;
}

function responder_json(array $payload, int $status_code = 200): void
{
    status_header($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($payload);
    exit;
}

function usuario_atas_autenticado(): bool
{
    return isset($_SESSION['atas_autenticado']) && true === $_SESSION['atas_autenticado'];
}

function admin_atas_autenticado(): bool
{
    return isset($_SESSION['atas_admin_autenticado']) && true === $_SESSION['atas_admin_autenticado'];
}

function exigir_autenticacao_atas(): void
{
    if (!usuario_atas_autenticado()) {
        responder_json(
            ['success' => false, 'message' => 'Sessao expirada. Faca login novamente.'],
            401
        );
    }
}

function exigir_autenticacao_admin(): void
{
    exigir_autenticacao_atas();

    if (!admin_atas_autenticado()) {
        responder_json(
            ['success' => false, 'message' => 'Acesso administrativo nao autenticado.'],
            403
        );
    }
}

function definir_flash(string $mensagem, string $tipo): void
{
    $_SESSION['mensagem'] = $mensagem;
    $_SESSION['tipo_mensagem'] = $tipo;
}

function consumir_flash(): array
{
    $mensagem = isset($_SESSION['mensagem']) ? (string) $_SESSION['mensagem'] : '';
    $tipo = isset($_SESSION['tipo_mensagem']) ? (string) $_SESSION['tipo_mensagem'] : '';

    unset($_SESSION['mensagem'], $_SESSION['tipo_mensagem']);

    return [$mensagem, $tipo];
}

function sanitizar_data_ata($value): string
{
    $value = sanitize_text_field($value);

    if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return date('Y-m-d');
}

function sanitizar_hora($value): string
{
    $value = sanitize_text_field($value);

    if (1 === preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
        return $value;
    }

    return '';
}

function obter_array_post(string $chave): array
{
    $valor = $_POST[$chave] ?? [];

    return is_array($valor) ? wp_unslash($valor) : [];
}

function linhas_para_objetos(array $linhas): array
{
    return array_map(
        static function (array $linha): object {
            return (object) $linha;
        },
        $linhas
    );
}

function renderizar_erro_fatal(string $mensagem): void
{
    status_header(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro - Ata de Obra</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="alert alert-danger shadow-sm mb-0" role="alert">
                        <h1 class="h4">Falha ao inicializar o sistema</h1>
                        <p class="mb-0"><?php echo esc_html($mensagem); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function criar_tabelas_atas(): void
{
    ata_repo()->criarEstrutura();
}

function exportar_csv_atas(array $atas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="atas_obra_' . date('Y-m-d_His') . '.csv"');

    $output = fopen('php://output', 'w');

    if (false === $output) {
        responder_json(['success' => false, 'message' => 'Nao foi possivel gerar o CSV.'], 500);
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv(
        $output,
        [
            'ID',
            'Data da Ata',
            'Data de Registro',
            'Hora de Registro',
            'Obra',
            'Tecnicos',
            'Hora Inicio',
            'Hora Termino',
            'Participantes',
            'Atividades Realizadas',
            'Pendencias',
        ],
        ';'
    );

    foreach ($atas as $ata) {
        $meta = $ata['meta'] ?? [];

        $tecnicos = [];
        $indice = 1;
        while (isset($meta['tecnico_' . $indice])) {
            $tecnicos[] = $meta['tecnico_' . $indice];
            $indice++;
        }

        $participantes = [];
        $indice = 1;
        while (isset($meta['participante_' . $indice . '_nome'])) {
            $nome = $meta['participante_' . $indice . '_nome'];
            $funcao = $meta['participante_' . $indice . '_funcao'] ?? '';
            $participantes[] = $nome . ('' !== $funcao ? ' (' . $funcao . ')' : '');
            $indice++;
        }

        $pendencias = [];
        $indice = 1;
        while (isset($meta['pendencia_' . $indice . '_descricao'])) {
            $descricao = $meta['pendencia_' . $indice . '_descricao'];
            $responsavel = $meta['pendencia_' . $indice . '_responsavel'] ?? '';
            $pendencias[] = $descricao . ('' !== $responsavel ? ' [Resp: ' . $responsavel . ']' : '');
            $indice++;
        }

        $dataAta = '';
        if (!empty($ata['data_ata'])) {
            $timestampDataAta = strtotime((string) $ata['data_ata']);
            $dataAta = false !== $timestampDataAta ? date('d/m/Y', $timestampDataAta) : (string) $ata['data_ata'];
        }

        $dataRegistro = '';
        $horaRegistro = '';
        if (!empty($ata['data_criacao'])) {
            $timestampCriacao = strtotime((string) $ata['data_criacao']);
            if (false !== $timestampCriacao) {
                $dataRegistro = date('d/m/Y', $timestampCriacao);
                $horaRegistro = date('H:i', $timestampCriacao);
            }
        }

        fputcsv(
            $output,
            [
                $ata['id'],
                $dataAta,
                $dataRegistro,
                $horaRegistro,
                $meta['obra'] ?? '',
                implode(', ', $tecnicos),
                $meta['hora_inicio'] ?? '',
                $meta['hora_termino'] ?? '',
                implode('; ', $participantes),
                $meta['atividades'] ?? '',
                implode('; ', $pendencias),
            ],
            ';'
        );
    }

    fclose($output);
    exit;
}

final class AtaObraSqliteRepository
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Nao foi possivel criar o diretorio do banco SQLite.');
        }

        $this->pdo = new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function criarEstrutura(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS wincor_atas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                data_criacao TEXT NOT NULL,
                data_ata TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS wincor_atas_meta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                ata_id INTEGER NOT NULL,
                meta_key TEXT NOT NULL,
                meta_value TEXT,
                FOREIGN KEY (ata_id) REFERENCES wincor_atas(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS wincor_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_type TEXT NOT NULL,
                config_value TEXT NOT NULL,
                config_order INTEGER NOT NULL DEFAULT 0
            );

            CREATE INDEX IF NOT EXISTS idx_wincor_atas_data_criacao ON wincor_atas(data_criacao);
            CREATE INDEX IF NOT EXISTS idx_wincor_atas_meta_ata_id ON wincor_atas_meta(ata_id);
            CREATE INDEX IF NOT EXISTS idx_wincor_atas_meta_meta_key ON wincor_atas_meta(meta_key);
            CREATE INDEX IF NOT EXISTS idx_wincor_config_type_order ON wincor_config(config_type, config_order, id);'
        );
    }

    public function getConfigValue(string $type): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT config_value
            FROM wincor_config
            WHERE config_type = :config_type
            ORDER BY config_order, id
            LIMIT 1'
        );
        $statement->execute([':config_type' => $type]);
        $value = $statement->fetchColumn();

        return false === $value ? null : (string) $value;
    }

    public function listConfigByType(string $type): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, config_value, config_order
            FROM wincor_config
            WHERE config_type = :config_type
            ORDER BY config_order, id'
        );
        $statement->execute([':config_type' => $type]);
        $items = $statement->fetchAll();

        return array_map(
            static function (array $item): array {
                $item['id'] = (int) $item['id'];
                $item['config_order'] = (int) $item['config_order'];

                return $item;
            },
            $items
        );
    }

    public function addConfig(string $type, string $value): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(config_order), 0)
            FROM wincor_config
            WHERE config_type = :config_type'
        );
        $statement->execute([':config_type' => $type]);
        $maxOrder = (int) $statement->fetchColumn();

        $insert = $this->pdo->prepare(
            'INSERT INTO wincor_config (config_type, config_value, config_order)
            VALUES (:config_type, :config_value, :config_order)'
        );
        $insert->execute(
            [
                ':config_type' => $type,
                ':config_value' => $value,
                ':config_order' => $maxOrder + 1,
            ]
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function updateConfig(int $id, string $value): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE wincor_config
            SET config_value = :config_value
            WHERE id = :id'
        );

        return $statement->execute(
            [
                ':config_value' => $value,
                ':id' => $id,
            ]
        );
    }

    public function deleteConfig(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM wincor_config WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function listAtasPaginated(int $pagina, int $porPagina): array
    {
        $pagina = max(1, $pagina);
        $porPagina = max(1, $porPagina);
        $offset = ($pagina - 1) * $porPagina;

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM wincor_atas')->fetchColumn();
        $totalPaginas = $total > 0 ? (int) ceil($total / $porPagina) : 0;

        $statement = $this->pdo->prepare(
            'SELECT id, data_criacao, data_ata
            FROM wincor_atas
            ORDER BY data_criacao DESC
            LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $atas = $statement->fetchAll();

        return [
            'atas' => $this->anexarMetadados($atas),
            'pagina_atual' => $pagina,
            'total_paginas' => $totalPaginas,
            'total_registros' => $total,
        ];
    }

    public function listAllAtas(): array
    {
        $atas = $this->pdo
            ->query(
                'SELECT id, data_criacao, data_ata
                FROM wincor_atas
                ORDER BY data_criacao DESC'
            )
            ->fetchAll();

        return $this->anexarMetadados($atas);
    }

    public function insertAta(string $dataAta, array $metadados): int
    {
        $this->pdo->beginTransaction();

        try {
            $statementAta = $this->pdo->prepare(
                'INSERT INTO wincor_atas (data_criacao, data_ata)
                VALUES (:data_criacao, :data_ata)'
            );
            $statementAta->execute(
                [
                    ':data_criacao' => current_time('mysql'),
                    ':data_ata' => $dataAta,
                ]
            );

            $ataId = (int) $this->pdo->lastInsertId();
            $statementMeta = $this->pdo->prepare(
                'INSERT INTO wincor_atas_meta (ata_id, meta_key, meta_value)
                VALUES (:ata_id, :meta_key, :meta_value)'
            );

            foreach ($metadados as $meta) {
                $statementMeta->execute(
                    [
                        ':ata_id' => $ataId,
                        ':meta_key' => (string) $meta['meta_key'],
                        ':meta_value' => isset($meta['meta_value']) ? (string) $meta['meta_value'] : '',
                    ]
                );
            }

            $this->pdo->commit();

            return $ataId;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public function deleteAta(int $ataId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM wincor_atas WHERE id = :id');
        $statement->execute([':id' => $ataId]);

        return $statement->rowCount() > 0;
    }

    private function anexarMetadados(array $atas): array
    {
        if ([] === $atas) {
            return [];
        }

        $ids = array_map(
            static function (array $ata): int {
                return (int) $ata['id'];
            },
            $atas
        );

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT ata_id, meta_key, meta_value
            FROM wincor_atas_meta
            WHERE ata_id IN (' . $placeholders . ')
            ORDER BY meta_id ASC'
        );

        foreach ($ids as $index => $id) {
            $statement->bindValue($index + 1, $id, PDO::PARAM_INT);
        }

        $statement->execute();
        $metas = [];

        foreach ($statement->fetchAll() as $meta) {
            $ataId = (int) $meta['ata_id'];
            $metas[$ataId][$meta['meta_key']] = $meta['meta_value'];
        }

        return array_map(
            static function (array $ata) use ($metas): array {
                $ataId = (int) $ata['id'];

                return [
                    'id' => $ataId,
                    'data_criacao' => (string) $ata['data_criacao'],
                    'data_ata' => (string) $ata['data_ata'],
                    'meta' => $metas[$ataId] ?? [],
                ];
            },
            $atas
        );
    }
}

function ata_repo(): AtaObraSqliteRepository
{
    static $repo;

    if (!$repo instanceof AtaObraSqliteRepository) {
        $repo = new AtaObraSqliteRepository(ATA_OBRA_DB_PATH);
        $repo->criarEstrutura();
    }

    return $repo;
}

try {
    $repo = ata_repo();
} catch (Throwable $throwable) {
    $mensagem = 'Nao foi possivel abrir o banco SQLite. Verifique a extensao PDO SQLite e a permissao de escrita em '
        . basename(ATA_OBRA_DB_PATH)
        . '.';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        responder_json(['success' => false, 'message' => $mensagem], 500);
    }

    renderizar_erro_fatal($mensagem);
}

$senha_config = $repo->getConfigValue('senha_atas');
$senha_admin = $repo->getConfigValue('senha_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $acao = sanitize_key($_POST['action']);
    $acoes_admin = [
        'listar_config',
        'adicionar_config',
        'editar_config',
        'deletar_config',
        'listar_atas',
        'exportar_csv',
        'deletar_ata',
    ];

    if ('verificar_senha_admin' === $acao) {
        exigir_autenticacao_atas();

        $senha_digitada = isset($_POST['senha_admin']) ? (string) wp_unslash($_POST['senha_admin']) : '';

        if (null !== $senha_admin && hash_equals((string) $senha_admin, $senha_digitada)) {
            session_regenerate_id(true);
            $_SESSION['atas_admin_autenticado'] = true;
            responder_json(['success' => true]);
        }

        unset($_SESSION['atas_admin_autenticado']);
        responder_json(['success' => false, 'message' => 'Senha incorreta!'], 403);
    }

    if ('logout_admin' === $acao) {
        exigir_autenticacao_atas();
        unset($_SESSION['atas_admin_autenticado']);
        responder_json(['success' => true]);
    }

    if (in_array($acao, $acoes_admin, true)) {
        exigir_autenticacao_admin();

        switch ($acao) {
            case 'listar_config':
                $tipo = isset($_POST['tipo']) ? sanitize_text_field($_POST['tipo']) : '';
                responder_json(['success' => true, 'items' => $repo->listConfigByType($tipo)]);

            case 'adicionar_config':
                $tipo = isset($_POST['tipo']) ? sanitize_text_field($_POST['tipo']) : '';
                $valor = isset($_POST['valor']) ? sanitize_text_field($_POST['valor']) : '';

                if ('' === $tipo || '' === $valor) {
                    responder_json(['success' => false, 'message' => 'Tipo e valor sao obrigatorios.'], 422);
                }

                responder_json(['success' => true, 'id' => $repo->addConfig($tipo, $valor)]);

            case 'editar_config':
                $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                $valor = isset($_POST['valor']) ? sanitize_text_field($_POST['valor']) : '';

                if ($id <= 0 || '' === $valor) {
                    responder_json(['success' => false, 'message' => 'Dados invalidos para edicao.'], 422);
                }

                if ($repo->updateConfig($id, $valor)) {
                    responder_json(['success' => true]);
                }

                responder_json(['success' => false, 'message' => 'Erro ao editar']);

            case 'deletar_config':
                $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

                if ($id <= 0) {
                    responder_json(['success' => false, 'message' => 'Identificador invalido.'], 422);
                }

                if ($repo->deleteConfig($id)) {
                    responder_json(['success' => true]);
                }

                responder_json(['success' => false, 'message' => 'Erro ao deletar']);

            case 'listar_atas':
                $pagina = isset($_POST['pagina']) ? max(1, (int) $_POST['pagina']) : 1;
                $resultado = $repo->listAtasPaginated($pagina, ATA_OBRA_POR_PAGINA);

                responder_json(
                    [
                        'success' => true,
                        'atas' => $resultado['atas'],
                        'pagina_atual' => $resultado['pagina_atual'],
                        'total_paginas' => $resultado['total_paginas'],
                        'total_registros' => $resultado['total_registros'],
                    ]
                );

            case 'exportar_csv':
                exportar_csv_atas($repo->listAllAtas());

            case 'deletar_ata':
                $ataId = isset($_POST['ata_id']) ? (int) $_POST['ata_id'] : 0;

                if ($ataId <= 0) {
                    responder_json(['success' => false, 'message' => 'Identificador da ata invalido.'], 422);
                }

                if ($repo->deleteAta($ataId)) {
                    responder_json(['success' => true]);
                }

                responder_json(['success' => false, 'message' => 'Erro ao deletar ata']);
        }
    }

    responder_json(['success' => false, 'message' => 'Acao invalida.'], 400);
}

if (isset($_GET['logout'])) {
    unset($_SESSION['atas_autenticado'], $_SESSION['atas_admin_autenticado']);
    redirecionar_para_app();
}

$erro_login = '';
$aviso_login = '';

if (null === $senha_config || '' === trim($senha_config)) {
    $aviso_login = 'Banco SQLite sem senha configurada. Execute migrar-para-sqlite.php para importar os dados atuais.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha_acesso'])) {
    $senha_digitada = (string) wp_unslash($_POST['senha_acesso']);

    if (null !== $senha_config && hash_equals((string) $senha_config, $senha_digitada)) {
        session_regenerate_id(true);
        $_SESSION['atas_autenticado'] = true;
        unset($_SESSION['atas_admin_autenticado']);
        redirecionar_para_app();
    }

    $erro_login = '' !== $aviso_login ? $aviso_login : 'Senha incorreta!';
}

if (!usuario_atas_autenticado()) {
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
                max-width: 420px;
                width: 100%;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h1 class="h4 mb-0">Acesso Restrito</h1>
                </div>
                <div class="card-body">
                    <?php if ('' !== $erro_login) : ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo esc_html($erro_login); ?>
                        </div>
                    <?php elseif ('' !== $aviso_login) : ?>
                        <div class="alert alert-warning" role="alert">
                            <?php echo esc_html($aviso_login); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="senha_acesso" class="form-label">Senha de Acesso</label>
                            <input type="password" name="senha_acesso" id="senha_acesso" class="form-control" required autofocus>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Entrar</button>
                            <?php if ('' !== $aviso_login) : ?>
                                <small class="text-muted">
                                    Use <code>migrar-para-sqlite.php</code> para trazer os dados do banco atual.
                                </small>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <div class="text-center text-muted small mt-3">
                Versao: <?php echo esc_html(ATA_OBRA_VERSAO); ?>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

[$mensagem, $tipo_mensagem] = consumir_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_envio'])) {
    $tecnicos = array_values(
        array_filter(
            array_map('sanitize_text_field', obter_array_post('tecnicos')),
            static function (string $tecnico): bool {
                return '' !== $tecnico;
            }
        )
    );
    $obra = isset($_POST['obra']) ? sanitize_text_field($_POST['obra']) : '';
    $horaInicio = isset($_POST['hora_inicio']) ? sanitizar_hora($_POST['hora_inicio']) : '';
    $horaTermino = isset($_POST['hora_termino']) ? sanitizar_hora($_POST['hora_termino']) : '';

    if ([] === $tecnicos || '' === $obra || '' === $horaInicio || '' === $horaTermino) {
        definir_flash('Por favor, preencha todos os campos obrigatorios.', 'danger');
        redirecionar_para_app();
    }

    $metadados = [];

    foreach ($tecnicos as $indice => $tecnico) {
        $metadados[] = [
            'meta_key' => 'tecnico_' . ($indice + 1),
            'meta_value' => $tecnico,
        ];
    }

    $metadados[] = [
        'meta_key' => 'obra',
        'meta_value' => $obra,
    ];

    $participantesNome = obter_array_post('participante_nome');
    $participantesFuncao = obter_array_post('participante_funcao');
    $totalParticipantes = max(count($participantesNome), count($participantesFuncao));
    $indiceParticipante = 1;

    for ($i = 0; $i < $totalParticipantes; $i++) {
        $nome = isset($participantesNome[$i]) ? sanitize_text_field($participantesNome[$i]) : '';
        $funcao = isset($participantesFuncao[$i]) ? sanitize_text_field($participantesFuncao[$i]) : '';

        if ('' === $nome) {
            continue;
        }

        $metadados[] = [
            'meta_key' => 'participante_' . $indiceParticipante . '_nome',
            'meta_value' => $nome,
        ];
        $metadados[] = [
            'meta_key' => 'participante_' . $indiceParticipante . '_funcao',
            'meta_value' => $funcao,
        ];

        $indiceParticipante++;
    }

    $metadados[] = [
        'meta_key' => 'hora_inicio',
        'meta_value' => $horaInicio,
    ];
    $metadados[] = [
        'meta_key' => 'hora_termino',
        'meta_value' => $horaTermino,
    ];

    $atividades = isset($_POST['atividades']) ? sanitize_textarea_field($_POST['atividades']) : '';
    if ('' !== $atividades) {
        $metadados[] = [
            'meta_key' => 'atividades',
            'meta_value' => $atividades,
        ];
    }

    $pendenciasDescricao = obter_array_post('pendencia_descricao');
    $pendenciasResponsavel = obter_array_post('pendencia_responsavel');
    $totalPendencias = max(count($pendenciasDescricao), count($pendenciasResponsavel));
    $indicePendencia = 1;

    for ($i = 0; $i < $totalPendencias; $i++) {
        $descricao = isset($pendenciasDescricao[$i]) ? sanitize_text_field($pendenciasDescricao[$i]) : '';
        $responsavel = isset($pendenciasResponsavel[$i]) ? sanitize_text_field($pendenciasResponsavel[$i]) : '';

        if ('' === $descricao) {
            continue;
        }

        $metadados[] = [
            'meta_key' => 'pendencia_' . $indicePendencia . '_descricao',
            'meta_value' => $descricao,
        ];
        $metadados[] = [
            'meta_key' => 'pendencia_' . $indicePendencia . '_responsavel',
            'meta_value' => $responsavel,
        ];

        $indicePendencia++;
    }

    try {
        $ataId = $repo->insertAta(
            sanitizar_data_ata($_POST['data'] ?? ''),
            $metadados
        );

        definir_flash('Ata de obra registrada com sucesso! ID: ' . $ataId, 'success');
    } catch (Throwable $throwable) {
        definir_flash('Erro ao salvar a ata. Por favor, tente novamente.', 'danger');
    }

    redirecionar_para_app();
}

$tecnicos = linhas_para_objetos($repo->listConfigByType('tecnico'));
$obras = linhas_para_objetos($repo->listConfigByType('obra'));

ob_start();
?>
<option value="">Selecione um tecnico</option>
<?php foreach ($tecnicos as $tecnico) : ?>
    <option value="<?php echo esc_attr($tecnico->config_value); ?>">
        <?php echo esc_html($tecnico->config_value); ?>
    </option>
<?php endforeach; ?>
<?php
$opcoes_tecnicos = trim((string) ob_get_clean());
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
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .btn-add,
        .btn-remove {
            padding: 0.25rem 0.5rem;
            font-size: 1.2rem;
            line-height: 1;
        }

        .field-group {
            margin-bottom: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
        }

        .required-label::after {
            content: " *";
            color: #dc3545;
        }

        .form-control,
        .form-select,
        .form-check-input {
            border: 1px solid #495057 !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header text-black d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">Registro de Ata de Obra</h1>
                <a href="<?php echo esc_attr(ata_app_url()); ?>?logout=1" class="btn btn-sm btn-outline-danger">Sair</a>
            </div>
            <div class="card-body">
                <?php if ('' !== $mensagem) : ?>
                    <div class="alert alert-<?php echo esc_attr($tipo_mensagem); ?> alert-dismissible fade show" role="alert">
                        <?php echo esc_html($mensagem); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="formAta">
                    <div class="mb-3">
                        <label class="form-label required-label">Tecnico(s)</label>
                        <div id="tecnicos-container">
                            <div class="field-group d-flex align-items-center gap-2 mb-2">
                                <select name="tecnicos[]" class="form-select" required>
                                    <?php echo $opcoes_tecnicos; ?>
                                </select>
                                <button type="button" class="btn btn-success btn-add" onclick="adicionarTecnico()">+</button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required-label">Obra</label>
                        <select name="obra" class="form-select" required>
                            <option value="">Selecione uma obra</option>
                            <?php foreach ($obras as $obra) : ?>
                                <option value="<?php echo esc_attr($obra->config_value); ?>">
                                    <?php echo esc_html($obra->config_value); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Participantes (caso exista)</label>
                        <div id="participantes-container">
                            <div class="field-group">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <input type="text" name="participante_nome[]" class="form-control" placeholder="Nome">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="participante_funcao[]" class="form-control" placeholder="Funcao">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-success btn-add" onclick="adicionarParticipante()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Data</label>
                        <input type="date" name="data" class="form-control" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required-label">Hora de Inicio</label>
                            <input type="time" name="hora_inicio" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label">Hora de Termino</label>
                            <input type="time" name="hora_termino" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Atividades Realizadas</label>
                        <textarea name="atividades" class="form-control" rows="5" placeholder="Descreva as atividades realizadas..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Pendencias</label>
                        <div id="pendencias-container">
                            <div class="field-group">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <input type="text" name="pendencia_descricao[]" class="form-control" placeholder="Pendencia">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="pendencia_responsavel[]" class="form-control" placeholder="Responsavel">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-success btn-add" onclick="adicionarPendencia()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="confirmar_envio" class="form-check-input" id="confirmarEnvio" required>
                            <label class="form-check-label required-label" for="confirmarEnvio">
                                Marque para salvar (Para evitar envio acidental)
                            </label>
                        </div>
                    </div>

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
            Versao: <?php echo esc_html(ATA_OBRA_VERSAO); ?>
        </div>
    </footer>

    <div class="modal fade" id="modalAdmin" tabindex="-1" aria-labelledby="modalAdminLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div id="adminLogin">
                    <div class="modal-header bg-warning">
                        <h2 class="modal-title h5 mb-0" id="modalAdminLabel">Acesso Administrativo</h2>
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

                <div id="adminConteudo" class="d-none">
                    <div class="modal-header bg-success text-white">
                        <h2 class="modal-title h5 mb-0">Painel Administrativo</h2>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tecnicos-tab" data-bs-toggle="tab" data-bs-target="#tecnicos-panel" type="button" role="tab">
                                    Tecnicos
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="obras-tab" data-bs-toggle="tab" data-bs-target="#obras-panel" type="button" role="tab">
                                    Obras
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="relatorios-tab" data-bs-toggle="tab" data-bs-target="#relatorios-panel" type="button" role="tab">
                                    Relatorios
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="adminTabsContent">
                            <div class="tab-pane fade show active" id="tecnicos-panel" role="tabpanel">
                                <div class="mb-3">
                                    <h3 class="h6">Adicionar Novo Tecnico</h3>
                                    <div class="input-group">
                                        <input type="text" id="novoTecnico" class="form-control" placeholder="Nome do tecnico">
                                        <button class="btn btn-primary" onclick="adicionarItem('tecnico')">Adicionar</button>
                                    </div>
                                </div>
                                <hr>
                                <h3 class="h6">Tecnicos Cadastrados</h3>
                                <div id="listaTecnicos" class="list-group">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Carregando...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="obras-panel" role="tabpanel">
                                <div class="mb-3">
                                    <h3 class="h6">Adicionar Nova Obra</h3>
                                    <div class="input-group">
                                        <input type="text" id="novaObra" class="form-control" placeholder="Nome da obra">
                                        <button class="btn btn-primary" onclick="adicionarItem('obra')">Adicionar</button>
                                    </div>
                                </div>
                                <hr>
                                <h3 class="h6">Obras Cadastradas</h3>
                                <div id="listaObras" class="list-group">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Carregando...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="relatorios-panel" role="tabpanel">
                                <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h3 class="h6 mb-0">Atas Registradas</h3>
                                    <div class="d-flex gap-2 align-items-center">
                                        <span id="totalRegistros" class="badge bg-primary">0 registros</span>
                                        <button class="btn btn-success btn-sm" onclick="exportarCSV()">
                                            Exportar CSV
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
                                <nav aria-label="Navegacao de paginas" id="paginacaoContainer" class="d-none">
                                    <ul class="pagination pagination-sm justify-content-center mt-3" id="paginacao"></ul>
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
        const APP_ENDPOINT = <?php echo wp_json_encode(ata_app_url()); ?>;
        const TECNICO_OPTIONS = <?php echo wp_json_encode($opcoes_tecnicos); ?>;
        let houveAlteracoes = false;

        function postAction(payload) {
            return fetch(APP_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(payload).toString()
            }).then(response => response.json());
        }

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        }

        function formatarDataBr(valor) {
            if (!valor) {
                return '-';
            }

            const data = new Date(valor + 'T00:00:00');
            return Number.isNaN(data.getTime()) ? valor : data.toLocaleDateString('pt-BR');
        }

        function formatarDataHora(valor) {
            if (!valor) {
                return { data: '-', hora: '-' };
            }

            const data = new Date(valor.replace(' ', 'T'));
            if (Number.isNaN(data.getTime())) {
                return { data: valor, hora: '-' };
            }

            return {
                data: data.toLocaleDateString('pt-BR'),
                hora: data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
            };
        }

        function adicionarTecnico() {
            const container = document.getElementById('tecnicos-container');
            const newField = document.createElement('div');
            newField.className = 'field-group d-flex align-items-center gap-2 mb-2';
            newField.innerHTML = `
                <select name="tecnicos[]" class="form-select">
                    ${TECNICO_OPTIONS}
                </select>
                <button type="button" class="btn btn-success btn-add" onclick="adicionarTecnico()">+</button>
                <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">&times;</button>
            `;
            container.appendChild(newField);
        }

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
                        <input type="text" name="participante_funcao[]" class="form-control" placeholder="Funcao">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success btn-add" onclick="adicionarParticipante()">+</button>
                        <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">&times;</button>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        }

        function adicionarPendencia() {
            const container = document.getElementById('pendencias-container');
            const newField = document.createElement('div');
            newField.className = 'field-group';
            newField.innerHTML = `
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="pendencia_descricao[]" class="form-control" placeholder="Pendencia">
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="pendencia_responsavel[]" class="form-control" placeholder="Responsavel">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success btn-add" onclick="adicionarPendencia()">+</button>
                        <button type="button" class="btn btn-danger btn-remove" onclick="removerCampo(this)">&times;</button>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        }

        function removerCampo(element) {
            element.closest('.field-group').remove();
        }

        document.getElementById('formSenhaAdmin').addEventListener('submit', function (event) {
            event.preventDefault();

            const senha = document.getElementById('senhaAdmin').value;
            const erroDiv = document.getElementById('erroSenhaAdmin');

            postAction({
                action: 'verificar_senha_admin',
                senha_admin: senha
            }).then(data => {
                if (data.success) {
                    document.getElementById('adminLogin').classList.add('d-none');
                    document.getElementById('adminConteudo').classList.remove('d-none');
                    erroDiv.classList.add('d-none');
                    document.getElementById('senhaAdmin').value = '';
                    houveAlteracoes = false;
                    carregarLista('tecnico');
                    carregarLista('obra');
                    carregarRelatorios(1);
                    return;
                }

                erroDiv.textContent = data.message || 'Senha incorreta!';
                erroDiv.classList.remove('d-none');
                document.getElementById('senhaAdmin').value = '';
                document.getElementById('senhaAdmin').focus();
            }).catch(() => {
                erroDiv.textContent = 'Erro ao verificar senha. Tente novamente.';
                erroDiv.classList.remove('d-none');
            });
        });

        function encerrarSessaoAdmin() {
            return postAction({ action: 'logout_admin' }).catch(() => null);
        }

        function carregarLista(tipo) {
            const containerId = tipo === 'tecnico' ? 'listaTecnicos' : 'listaObras';
            const container = document.getElementById(containerId);
            container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';

            postAction({
                action: 'listar_config',
                tipo: tipo
            }).then(data => {
                if (!data.success) {
                    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar itens.</div>';
                    return;
                }

                if (data.items.length === 0) {
                    container.innerHTML = '<div class="alert alert-info">Nenhum item cadastrado</div>';
                    return;
                }

                container.innerHTML = '';
                data.items.forEach(item => {
                    container.appendChild(criarItemLista(item, tipo));
                });
            }).catch(() => {
                container.innerHTML = '<div class="alert alert-danger">Erro ao carregar itens.</div>';
            });
        }

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
            btnEditar.textContent = 'Editar';
            btnEditar.onclick = () => editarItem(item.id);

            const btnSalvar = document.createElement('button');
            btnSalvar.className = 'btn btn-success btn-salvar d-none';
            btnSalvar.textContent = 'Salvar';
            btnSalvar.onclick = () => salvarItem(item.id, tipo);

            const btnCancelar = document.createElement('button');
            btnCancelar.className = 'btn btn-secondary btn-cancelar d-none';
            btnCancelar.textContent = 'Cancelar';
            btnCancelar.onclick = () => cancelarEdicao(item.id);

            const btnDeletar = document.createElement('button');
            btnDeletar.className = 'btn btn-danger btn-deletar';
            btnDeletar.textContent = 'Excluir';
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

        function adicionarItem(tipo) {
            const inputId = tipo === 'tecnico' ? 'novoTecnico' : 'novaObra';
            const input = document.getElementById(inputId);
            const valor = input.value.trim();

            if (!valor) {
                alert('Por favor, preencha o campo.');
                return;
            }

            postAction({
                action: 'adicionar_config',
                tipo: tipo,
                valor: valor
            }).then(data => {
                if (data.success) {
                    input.value = '';
                    houveAlteracoes = true;
                    carregarLista(tipo);
                    return;
                }

                alert(data.message || 'Erro ao adicionar.');
            }).catch(() => {
                alert('Erro ao adicionar.');
            });
        }

        function editarItem(id) {
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

        function salvarItem(id, tipo) {
            const item = document.getElementById('item-' + id);
            const input = item.querySelector('.item-input');
            const valor = input.value.trim();

            if (!valor) {
                alert('O campo nao pode estar vazio.');
                return;
            }

            postAction({
                action: 'editar_config',
                id: id,
                valor: valor
            }).then(data => {
                if (data.success) {
                    houveAlteracoes = true;
                    carregarLista(tipo);
                    return;
                }

                alert(data.message || 'Erro ao editar.');
            }).catch(() => {
                alert('Erro ao editar.');
            });
        }

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

        function deletarItem(id, tipo) {
            if (!confirm('Tem certeza que deseja deletar este item?')) {
                return;
            }

            postAction({
                action: 'deletar_config',
                id: id
            }).then(data => {
                if (data.success) {
                    houveAlteracoes = true;
                    carregarLista(tipo);
                    return;
                }

                alert(data.message || 'Erro ao deletar.');
            }).catch(() => {
                alert('Erro ao deletar.');
            });
        }

        document.getElementById('novoTecnico').addEventListener('keypress', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                adicionarItem('tecnico');
            }
        });

        document.getElementById('novaObra').addEventListener('keypress', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                adicionarItem('obra');
            }
        });

        function carregarRelatorios(pagina = 1) {
            const container = document.getElementById('listaAtas');
            container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';

            postAction({
                action: 'listar_atas',
                pagina: pagina
            }).then(data => {
                if (!data.success) {
                    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar atas.</div>';
                    return;
                }

                document.getElementById('totalRegistros').textContent = data.total_registros + ' registro' + (data.total_registros !== 1 ? 's' : '');

                if (data.atas.length === 0) {
                    container.innerHTML = '<div class="alert alert-info">Nenhuma ata registrada</div>';
                    document.getElementById('paginacaoContainer').classList.add('d-none');
                    return;
                }

                container.innerHTML = '';
                data.atas.forEach(ata => {
                    container.appendChild(criarCardAta(ata));
                });

                if (data.total_paginas > 1) {
                    criarPaginacao(data.pagina_atual, data.total_paginas);
                    document.getElementById('paginacaoContainer').classList.remove('d-none');
                    return;
                }

                document.getElementById('paginacaoContainer').classList.add('d-none');
            }).catch(() => {
                container.innerHTML = '<div class="alert alert-danger">Erro ao carregar atas.</div>';
            });
        }

        function criarCardAta(ata) {
            const card = document.createElement('div');
            card.className = 'card mb-2';

            const tecnicos = [];
            let indice = 1;
            while (ata.meta['tecnico_' + indice]) {
                tecnicos.push(ata.meta['tecnico_' + indice]);
                indice++;
            }

            const participantes = [];
            indice = 1;
            while (ata.meta['participante_' + indice + '_nome']) {
                participantes.push({
                    nome: ata.meta['participante_' + indice + '_nome'],
                    funcao: ata.meta['participante_' + indice + '_funcao'] || ''
                });
                indice++;
            }

            const pendencias = [];
            indice = 1;
            while (ata.meta['pendencia_' + indice + '_descricao']) {
                pendencias.push({
                    descricao: ata.meta['pendencia_' + indice + '_descricao'],
                    responsavel: ata.meta['pendencia_' + indice + '_responsavel'] || ''
                });
                indice++;
            }

            const dataCriacao = formatarDataHora(ata.data_criacao);
            const dataAta = formatarDataBr(ata.data_ata);

            let duracao = '-';
            if (ata.meta.hora_inicio && ata.meta.hora_termino) {
                const [horaIni, minIni] = ata.meta.hora_inicio.split(':').map(Number);
                const [horaFim, minFim] = ata.meta.hora_termino.split(':').map(Number);
                let totalInicio = horaIni * 60 + minIni;
                let totalFim = horaFim * 60 + minFim;

                if (totalFim < totalInicio) {
                    totalFim += 24 * 60;
                }

                const diferenca = totalFim - totalInicio;
                const horas = Math.floor(diferenca / 60);
                const minutos = diferenca % 60;
                duracao = `${horas}h${minutos > 0 ? minutos.toString().padStart(2, '0') + 'min' : ''}`;
            }

            const accordionId = 'accordion-' + ata.id;
            const atividades = ata.meta.atividades
                ? `<div class="mb-3"><strong>Atividades Realizadas:</strong><p class="mb-0">${escapeHtml(ata.meta.atividades).replace(/\n/g, '<br>')}</p></div>`
                : '';
            const participantesHtml = participantes.length > 0
                ? `<div class="mb-3"><strong>Participantes:</strong><ul class="mb-0">${participantes.map(p => `<li>${escapeHtml(p.nome)}${p.funcao ? ' - ' + escapeHtml(p.funcao) : ''}</li>`).join('')}</ul></div>`
                : '';
            const pendenciasHtml = pendencias.length > 0
                ? `<div class="mb-0"><strong>Pendencias:</strong><ul class="mb-0">${pendencias.map(p => `<li>${escapeHtml(p.descricao)}${p.responsavel ? ' - <em>Responsavel: ' + escapeHtml(p.responsavel) + '</em>' : ''}</li>`).join('')}</ul></div>`
                : '';
            const semDetalhes = !atividades && participantes.length === 0 && pendencias.length === 0
                ? '<p class="text-muted mb-0">Nenhum detalhe adicional registrado.</p>'
                : '';

            card.innerHTML = `
                <div class="card-body p-2 p-md-3">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <small class="text-muted d-block">ID: #${ata.id}</small>
                            <strong>Data da Ata:</strong> ${escapeHtml(dataAta)}
                        </div>
                        <div class="col-12 col-md-6 d-flex justify-content-between align-items-start">
                            <small class="text-muted d-block">Registrado em: ${escapeHtml(dataCriacao.data)} as ${escapeHtml(dataCriacao.hora)}</small>
                            <button class="btn btn-danger btn-sm" onclick="deletarAta(${ata.id})" title="Deletar ata">
                                Excluir
                            </button>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <strong>Obra:</strong> ${escapeHtml(ata.meta.obra || '-')}
                        </div>
                        <div class="col-12 col-md-6">
                            <strong>Tecnico(s):</strong> ${escapeHtml(tecnicos.join(', ') || '-')}
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6 col-md-3">
                            <strong>Inicio:</strong> ${escapeHtml(ata.meta.hora_inicio || '-')}
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>Termino:</strong> ${escapeHtml(ata.meta.hora_termino || '-')}
                        </div>
                        <div class="col-12 col-md-3">
                            <strong>Duracao:</strong> <span>${escapeHtml(duracao)}</span>
                        </div>
                    </div>
                    <div class="accordion mt-3" id="${accordionId}">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${ata.id}">
                                    Ver detalhes completos
                                </button>
                            </h2>
                            <div id="collapse-${ata.id}" class="accordion-collapse collapse" data-bs-parent="#${accordionId}">
                                <div class="accordion-body">
                                    ${atividades}
                                    ${participantesHtml}
                                    ${pendenciasHtml}
                                    ${semDetalhes}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            return card;
        }

        function exportarCSV() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = APP_ENDPOINT;
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

        function deletarAta(ataId) {
            if (!confirm('Tem certeza que deseja deletar esta ata?\n\nEsta acao nao pode ser desfeita!')) {
                return;
            }

            postAction({
                action: 'deletar_ata',
                ata_id: ataId
            }).then(data => {
                if (data.success) {
                    alert('Ata deletada com sucesso!');
                    carregarRelatorios(1);
                    return;
                }

                alert(data.message || 'Erro ao deletar ata.');
            }).catch(() => {
                alert('Erro ao deletar ata. Tente novamente.');
            });
        }

        function criarPaginacao(paginaAtual, totalPaginas) {
            const paginacao = document.getElementById('paginacao');
            paginacao.innerHTML = '';

            const liPrev = document.createElement('li');
            liPrev.className = 'page-item' + (paginaAtual === 1 ? ' disabled' : '');
            liPrev.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${paginaAtual - 1}); return false;">Anterior</a>`;
            paginacao.appendChild(liPrev);

            const inicio = Math.max(1, paginaAtual - 2);
            const fim = Math.min(totalPaginas, paginaAtual + 2);

            for (let i = inicio; i <= fim; i++) {
                const li = document.createElement('li');
                li.className = 'page-item' + (i === paginaAtual ? ' active' : '');
                li.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${i}); return false;">${i}</a>`;
                paginacao.appendChild(li);
            }

            const liNext = document.createElement('li');
            liNext.className = 'page-item' + (paginaAtual === totalPaginas ? ' disabled' : '');
            liNext.innerHTML = `<a class="page-link" href="#" onclick="carregarRelatorios(${paginaAtual + 1}); return false;">Proximo</a>`;
            paginacao.appendChild(liNext);
        }

        document.getElementById('modalAdmin').addEventListener('shown.bs.modal', function () {
            document.getElementById('senhaAdmin').focus();
        });

        document.getElementById('modalAdmin').addEventListener('hidden.bs.modal', function () {
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
