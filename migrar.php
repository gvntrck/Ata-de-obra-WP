<?php
declare(strict_types=1);

/**
 * Migrador do banco atual para SQLite
 */

date_default_timezone_set('America/Sao_Paulo');

define('ATA_OBRA_SQLITE_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'ata-obra.sqlite');

function migracao_esc_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function migracao_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function migracao_saida(string $mensagem): void
{
    if (migracao_cli()) {
        echo $mensagem . PHP_EOL;
        return;
    }

    echo $mensagem;
}

function migracao_erro(string $mensagem, int $statusCode = 1): void
{
    if (migracao_cli()) {
        fwrite(STDERR, $mensagem . PHP_EOL);
        exit($statusCode);
    }

    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro de Migracao</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="alert alert-danger mb-0">
                        <h1 class="h4">Falha na migracao</h1>
                        <p class="mb-0"><?php echo migracao_esc_html($mensagem); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function localizar_wp_load(): ?string
{
    $candidatos = [];

    if (migracao_cli()) {
        global $argv;
        foreach (array_slice($argv ?? [], 1) as $argumento) {
            if (0 === strpos($argumento, '--wp-load=')) {
                $candidatos[] = substr($argumento, 10);
            } elseif ('' !== trim($argumento)) {
                $candidatos[] = $argumento;
            }
        }
    } elseif (!empty($_REQUEST['wp_load'])) {
        $candidatos[] = (string) $_REQUEST['wp_load'];
    }

    $candidatos[] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'wp-load.php';

    $diretorio = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $candidatos[] = $diretorio . DIRECTORY_SEPARATOR . 'wp-load.php';
        $pai = dirname($diretorio);
        if ($pai === $diretorio) {
            break;
        }

        $diretorio = $pai;
    }

    foreach ($candidatos as $candidato) {
        if (!is_string($candidato) || '' === trim($candidato)) {
            continue;
        }

        $real = realpath($candidato);
        if (false !== $real && is_file($real)) {
            return $real;
        }
    }

    return null;
}

function criar_sqlite(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
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

function executar_migracao(string $wpLoadPath): array
{
    require_once $wpLoadPath;

    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException('Nao foi possivel inicializar a conexao com o banco atual via WordPress.');
    }

    $configs = $wpdb->get_results(
        'SELECT id, config_type, config_value, config_order FROM wincor_config ORDER BY id ASC',
        ARRAY_A
    );
    $atas = $wpdb->get_results(
        'SELECT id, data_criacao, data_ata FROM wincor_atas ORDER BY id ASC',
        ARRAY_A
    );
    $metas = $wpdb->get_results(
        'SELECT meta_id, ata_id, meta_key, meta_value FROM wincor_atas_meta ORDER BY meta_id ASC',
        ARRAY_A
    );

    $configs = is_array($configs) ? $configs : [];
    $atas = is_array($atas) ? $atas : [];
    $metas = is_array($metas) ? $metas : [];

    $pdo = new PDO('sqlite:' . ATA_OBRA_SQLITE_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    criar_sqlite($pdo);

    $pdo->beginTransaction();

    try {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DELETE FROM wincor_atas_meta');
        $pdo->exec('DELETE FROM wincor_atas');
        $pdo->exec('DELETE FROM wincor_config');
        $insertConfig = $pdo->prepare(
            'INSERT INTO wincor_config (id, config_type, config_value, config_order)
            VALUES (:id, :config_type, :config_value, :config_order)'
        );
        foreach ($configs as $config) {
            $insertConfig->execute(
                [
                    ':id' => (int) $config['id'],
                    ':config_type' => (string) $config['config_type'],
                    ':config_value' => (string) $config['config_value'],
                    ':config_order' => (int) $config['config_order'],
                ]
            );
        }

        $insertAta = $pdo->prepare(
            'INSERT INTO wincor_atas (id, data_criacao, data_ata)
            VALUES (:id, :data_criacao, :data_ata)'
        );
        foreach ($atas as $ata) {
            $insertAta->execute(
                [
                    ':id' => (int) $ata['id'],
                    ':data_criacao' => (string) $ata['data_criacao'],
                    ':data_ata' => (string) $ata['data_ata'],
                ]
            );
        }

        $insertMeta = $pdo->prepare(
            'INSERT INTO wincor_atas_meta (meta_id, ata_id, meta_key, meta_value)
            VALUES (:meta_id, :ata_id, :meta_key, :meta_value)'
        );
        foreach ($metas as $meta) {
            $insertMeta->execute(
                [
                    ':meta_id' => (int) $meta['meta_id'],
                    ':ata_id' => (int) $meta['ata_id'],
                    ':meta_key' => (string) $meta['meta_key'],
                    ':meta_value' => isset($meta['meta_value']) ? (string) $meta['meta_value'] : '',
                ]
            );
        }

        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }

    return [
        'sqlite_path' => ATA_OBRA_SQLITE_PATH,
        'wp_load' => $wpLoadPath,
        'configs' => count($configs),
        'atas' => count($atas),
        'metas' => count($metas),
    ];
}

$wpLoadPath = localizar_wp_load();

if (migracao_cli()) {
    if (null === $wpLoadPath) {
        migracao_erro('Nao foi possivel localizar o wp-load.php. Informe o caminho: php migrar-para-sqlite.php --wp-load=C:\\caminho\\wp-load.php');
    }

    try {
        $resultado = executar_migracao($wpLoadPath);
    } catch (Throwable $throwable) {
        migracao_erro($throwable->getMessage());
    }

    migracao_saida('Migracao concluida com sucesso.');
    migracao_saida('wp-load.php: ' . $resultado['wp_load']);
    migracao_saida('SQLite: ' . $resultado['sqlite_path']);
    migracao_saida('Configs: ' . $resultado['configs']);
    migracao_saida('Atas: ' . $resultado['atas']);
    migracao_saida('Metadados: ' . $resultado['metas']);
    exit(0);
}

$executar = isset($_REQUEST['executar']) && '1' === (string) $_REQUEST['executar'];
$resultado = null;
$erro = '';

if ($executar) {
    if (null === $wpLoadPath) {
        $erro = 'Nao foi possivel localizar o wp-load.php automaticamente. Informe o caminho manualmente no campo abaixo.';
    } else {
        try {
            $resultado = executar_migracao($wpLoadPath);
        } catch (Throwable $throwable) {
            $erro = $throwable->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migracao para SQLite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h1 class="h4 mb-0">Migracao do banco atual para SQLite</h1>
                    </div>
                    <div class="card-body">
                        <?php if ('' !== $erro) : ?>
                            <div class="alert alert-danger"><?php echo migracao_esc_html($erro); ?></div>
                        <?php endif; ?>

                        <?php if (null !== $resultado) : ?>
                            <div class="alert alert-success">Migracao concluida com sucesso.</div>
                            <ul class="mb-0">
                                <li><strong>wp-load.php:</strong> <?php echo migracao_esc_html($resultado['wp_load']); ?></li>
                                <li><strong>SQLite:</strong> <?php echo migracao_esc_html($resultado['sqlite_path']); ?></li>
                                <li><strong>Configs:</strong> <?php echo (int) $resultado['configs']; ?></li>
                                <li><strong>Atas:</strong> <?php echo (int) $resultado['atas']; ?></li>
                                <li><strong>Metadados:</strong> <?php echo (int) $resultado['metas']; ?></li>
                            </ul>
                        <?php else : ?>
                            <p>Este script importa as tabelas <code>wincor_config</code>, <code>wincor_atas</code> e <code>wincor_atas_meta</code> do banco atual para <code><?php echo migracao_esc_html(ATA_OBRA_SQLITE_PATH); ?></code>.</p>
                            <form method="post">
                                <input type="hidden" name="executar" value="1">
                                <div class="mb-3">
                                    <label for="wp_load" class="form-label">Caminho do wp-load.php</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="wp_load"
                                        name="wp_load"
                                        value="<?php echo migracao_esc_html((string) ($wpLoadPath ?? '')); ?>"
                                        placeholder="C:\caminho\do\wp-load.php"
                                    >
                                </div>
                                <button type="submit" class="btn btn-primary">Executar migracao</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
