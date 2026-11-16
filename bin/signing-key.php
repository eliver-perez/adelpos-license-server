<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

use AdelPos\License\Domain\{Problem, Protocol};

consoleRun(function (): void {
    $options = getopt('', ['action:','kid:','purpose:','reason:','trust-confirmed','user:']);
    if (isset($options['user'])) {
        putenv('DB_USER=' . $options['user']);
        putenv('DB_PASSWORD=' . hiddenInput('Contraseña de la cuenta de gestión de llaves: '));
    }
    $app = AdelPos\License\App::boot();
    $keys = $app->signingKeys;
    $environment = $keys->environment();
    $action = $options['action'] ?? '';
    // La exportación deja solo JSON en la salida estándar, para poder guardarla en un archivo.
    fwrite($action === 'export' ? STDERR : STDOUT, 'Entorno de licencias: ' . $environment . PHP_EOL);
    if ($action === 'generate') {
        $public = $keys->generate($options['kid'] ?? '', $options['purpose'] ?? 'license');
        echo Protocol::json($public) . PHP_EOL;
        echo "Llave preparada en el entorno {$environment}. Incluye su pública en AD-EL POS (--action=export) antes de activarla.\n";
    } elseif ($action === 'activate') {
        if (!array_key_exists('trust-confirmed', $options)) { throw new Problem('Para activar, confirma con --trust-confirmed que AD-EL POS ya confía en esta llave pública.'); }
        $keys->activate($options['kid'] ?? '', $options['reason'] ?? '');
        echo "Llave firmante activada en el entorno {$environment}. Las públicas anteriores se conservan.\n";
    } elseif ($action === 'list') {
        foreach ($app->db->all('SELECT kid,environment,purpose,public_key,state,created_at FROM signing_keys WHERE environment=? ORDER BY created_at,kid', [$environment]) as $key) {
            $key['public_key'] = Protocol::encode($key['public_key']);
            echo Protocol::json($key) . PHP_EOL;
        }
    } elseif ($action === 'export') {
        echo json_encode($keys->publicKeys(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        throw new Problem('Usa --action=generate|activate|list|export. --kid es obligatorio para activar y opcional para generar una llave de licencias: sin él se asigna el siguiente identificador del entorno. Generar y activar requieren permisos SQL de migración; usa --user si es necesario.');
    }
});
