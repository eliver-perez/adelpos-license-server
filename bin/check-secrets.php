<?php
declare(strict_types=1);

/*
 * Revisión de secretos. Se ejecuta antes de cada commit:
 *
 *     php bin/check-secrets.php [--root=/ruta/del/repositorio]
 *
 * Revisa lo que entraría al repositorio: el contenido del índice de git y los
 * archivos nuevos que no están ignorados. Termina con código 1 si encuentra:
 *
 *   - valores no vacíos en claves de configuración privadas (DB_PASSWORD, *_KEY);
 *   - llaves privadas PEM, o semillas o llaves secretas Ed25519;
 *   - archivos bajo var/keys/, o archivos *.key o *.pem.
 *
 * Las llaves de los archivos de prueba del contrato son públicas a propósito: se
 * aceptan solo si su semilla es exactamente sha256(prefijo público + nombre).
 * Nunca imprime el valor encontrado, solo su ubicación.
 * Códigos de salida: 0 sin hallazgos, 1 con hallazgos, 2 si no se pudo revisar.
 */

const PUBLIC_TEST_SEED_PREFIXES = ['AIBIDLICENSE PUBLIC TEST ONLY V1 '];
const PRIVATE_CONFIG_NAME = '(?:DB_PASSWORD|[A-Z][A-Z0-9_]*_KEY)';
const KEY_MATERIAL_FIELD = '(?:secret[_-]?key|private[_-]?key|signing[_-]?secret|seed(?:_hex|_b64u?)?|sk)';

function fail(string $message): never
{
    fwrite(STDERR, 'Revisión de secretos: ' . $message . PHP_EOL);
    exit(2);
}

/** @return array{0:int,1:string,2:string} */
function git(string $root, array $arguments): array
{
    $process = proc_open(['git', '-C', $root, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { fail('no se pudo ejecutar git.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), (string) $output, (string) $error];
}

/** Lee los blobs del índice con un solo proceso, uno a la vez, para no bloquear las tuberías. */
function indexBlobs(string $root, array $objects): array
{
    if ($objects === []) { return []; }
    $process = proc_open(['git', '-C', $root, 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { fail('no se pudo leer el índice de git.'); }
    $contents = [];
    foreach (array_unique($objects) as $object) {
        fwrite($pipes[0], $object . "\n"); fflush($pipes[0]);
        $header = fgets($pipes[1]);
        if ($header === false || !preg_match('/\A([0-9a-f]+) blob (\d+)\n\z/', $header, $match)) { fail('respuesta inesperada de git cat-file.'); }
        $size = (int) $match[2]; $bytes = '';
        while (strlen($bytes) < $size) {
            $chunk = fread($pipes[1], min(65536, $size - strlen($bytes)));
            if ($chunk === false || $chunk === '') { fail('lectura incompleta del índice de git.'); }
            $bytes .= $chunk;
        }
        fread($pipes[1], 1);
        $contents[$object] = $bytes;
    }
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    return $contents;
}

function unquote(string $token): string
{
    $quote = $token[0];
    $body = substr($token, 1, -1);
    return $quote === "'" ? strtr($body, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($body);
}

/** Devuelve 32 o 64 bytes si el texto es material de llave Ed25519 en hex, base64 o base64url. */
function keyMaterial(string $value): ?string
{
    if (preg_match('/\A(?:[0-9a-fA-F]{64}|[0-9a-fA-F]{128})\z/', $value)) { return hex2bin($value); }
    if (!preg_match('/\A[A-Za-z0-9+\/_-]{40,}={0,2}\z/', $value)) { return null; }
    $bytes = base64_decode(strtr($value, '-_', '+/'), true);
    return $bytes !== false && in_array(strlen($bytes), [32, 64], true) ? $bytes : null;
}

/** Semillas públicas de prueba que pueden aparecer en este archivo: sha256(prefijo + nombre). */
function publicTestSeeds(string $text): array
{
    preg_match_all('/["\'](?:name|key_name)["\']\s*(?::|=>)\s*["\']([a-z0-9][a-z0-9._-]{0,63})["\']/', $text, $matches);
    $seeds = [];
    foreach (array_unique($matches[1]) as $name) {
        foreach (PUBLIC_TEST_SEED_PREFIXES as $prefix) { $seeds[hash('sha256', $prefix . $name, true)] = true; }
    }
    return $seeds;
}

function line(string $text, int $offset): int
{
    return substr_count($text, "\n", 0, $offset) + 1;
}

/** @return list<array{int,string}> */
function scanPhpConfig(string $text): array
{
    $findings = [];
    try { $tokens = token_get_all($text, TOKEN_PARSE); } catch (\Throwable) { $tokens = token_get_all($text); }
    $significant = array_values(array_filter($tokens, static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    foreach ($significant as $index => $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $value = unquote($token[1]);
        if (preg_match('/\A' . PRIVATE_CONFIG_NAME . '\z/', $value)) {
            $arrow = $significant[$index + 1] ?? null; $next = $significant[$index + 2] ?? null;
            if (is_array($arrow) && $arrow[0] === T_DOUBLE_ARROW && is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING && unquote($next[1]) !== '') {
                $findings[] = [$token[2], 'valor no vacío en la clave privada ' . $value];
            }
        } elseif (preg_match('/\A(' . PRIVATE_CONFIG_NAME . ')=(.+)\z/s', $value, $match)) {
            $findings[] = [$token[2], 'valor no vacío en la clave privada ' . $match[1]];
        }
    }
    return $findings;
}

/** @return list<array{int,string}> */
function scanTextConfig(string $text): array
{
    $findings = [];
    $patterns = [
        // DB_PASSWORD=valor, export AUDIT_KEY="valor", MFA_KEY: valor
        '/^[ \t]*(?:export[ \t]+)?(' . PRIVATE_CONFIG_NAME . ')[ \t]*[=:][ \t]*(?!["\']{2}[ \t]*$)(?!\$)(\S[^\r\n]*)$/m',
        // 'DB_PASSWORD' => 'valor', "MFA_KEY": "valor"
        '/(["\'])(' . PRIVATE_CONFIG_NAME . ')\1[ \t]*(?:=>|:)[ \t]*(["\'])(?!\3)(?:\\\\.|(?!\3).)+\3/',
    ];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $name = $match[1][0] === '"' || $match[1][0] === "'" ? $match[2][0] : $match[1][0];
            $findings[] = [line($text, $match[0][1]), 'valor no vacío en la clave privada ' . $name];
        }
    }
    return $findings;
}

/** @return list<array{int,string}> */
function scanKeys(string $text): array
{
    $findings = [];
    if (preg_match_all('/-{5}BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-{5}/', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) { $findings[] = [line($text, $match[1]), 'llave privada PEM']; }
    }
    $pattern = '/(["\'])(' . KEY_MATERIAL_FIELD . ')\1[ \t]*(?::|=>)[ \t]*(["\'])([A-Za-z0-9+\/_=-]+)\3/i';
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        $publicSeeds = null;
        foreach ($matches as $match) {
            $bytes = keyMaterial($match[4][0]);
            if ($bytes === null) { continue; }
            $publicSeeds ??= publicTestSeeds($text);
            if (isset($publicSeeds[substr($bytes, 0, 32)])) { continue; }
            $findings[] = [line($text, $match[0][1]), 'material de llave privada Ed25519 en «' . $match[2][0] . '»'];
        }
    }
    return $findings;
}

$options = getopt('', ['root:']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if ($root === false || !is_dir($root)) { fail('la ruta del repositorio no existe.'); }
[$status, $top] = git($root, ['rev-parse', '--show-toplevel']);
if ($status !== 0) { fail('la ruta no es un repositorio git.'); }
$root = rtrim($top, "\n");

[$status, $staged] = git($root, ['ls-files', '-z', '--stage']);
if ($status !== 0) { fail('no se pudo listar el índice.'); }
[$status, $untracked] = git($root, ['ls-files', '-z', '--others', '--exclude-standard']);
if ($status !== 0) { fail('no se pudieron listar los archivos nuevos.'); }

$versions = [];
$objects = [];
foreach (array_filter(explode("\0", $staged)) as $entry) {
    if (!preg_match('/\A(\d+) ([0-9a-f]+) \d\t(.+)\z/s', $entry, $match)) { fail('entrada inesperada en el índice.'); }
    if ($match[1] === '160000') { continue; }
    $objects[$match[3]] = $match[2];
}
foreach (indexBlobs($root, array_values($objects)) as $object => $bytes) {
    foreach (array_keys($objects, $object, true) as $path) { $versions[$path][] = $bytes; }
}
foreach (array_merge(array_keys($objects), array_filter(explode("\0", $untracked))) as $path) {
    $file = $root . '/' . $path;
    if (is_file($file) && !is_link($file)) {
        $bytes = file_get_contents($file);
        if ($bytes !== false && !in_array($bytes, $versions[$path] ?? [], true)) { $versions[$path][] = $bytes; }
    }
}
ksort($versions);

$findings = [];
foreach ($versions as $path => $contents) {
    $path = (string) $path;
    if (str_starts_with($path, 'var/keys/')) { $findings[$path . ':0: archivo bajo var/keys/'] = true; }
    if (preg_match('/\.(?:key|pem)\z/i', $path)) { $findings[$path . ':0: archivo de llave (.key o .pem)'] = true; }
    foreach ($contents as $text) {
        if (str_contains($text, "\0")) { continue; }
        $found = scanKeys($text);
        if (!str_starts_with($path, 'public/assets/vendor/')) {
            $found = array_merge($found, str_ends_with($path, '.php') ? scanPhpConfig($text) : scanTextConfig($text));
        }
        foreach ($found as [$line, $reason]) { $findings[$path . ':' . $line . ': ' . $reason] = true; }
    }
}

if ($findings !== []) {
    fwrite(STDERR, "Revisión de secretos: se encontraron posibles secretos. No hagas commit hasta resolverlos.\n");
    foreach (array_keys($findings) as $finding) { fwrite(STDERR, '  ' . $finding . PHP_EOL); }
    exit(1);
}
fwrite(STDOUT, 'Revisión de secretos: sin hallazgos en ' . count($versions) . " archivos.\n");
exit(0);
