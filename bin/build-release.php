<?php
declare(strict_types=1);
require __DIR__.'/console.php';

consoleRun(function (): void {
    $options = getopt('',['output:']);
    $output = $options['output'] ?? '';
    if (!str_ends_with($output,'.tar.gz')) { throw new AdelPos\License\Domain\Problem('Uso: build-release.php --output=/ruta/nueva/adelpos-license.tar.gz (directorio existente).'); }
    AdelPos\License\Infrastructure\Backup::outsidePublic($output);
    $tar = substr($output,0,-3);
    foreach ([$output,$tar,$output.'.sha256'] as $path) { if (file_exists($path) || is_link($path)) { throw new AdelPos\License\Domain\Problem('El destino ya existe.'); } }
    $root = dirname(__DIR__);
    // Allowlist: only files versioned in git under these directories, plus the explicit files.
    // Local configuration, keys, data (var/), tests, contracts, dev dependencies and untracked
    // files (for example .DS_Store) never enter the package.
    $process = proc_open(['git','-C',$root,'ls-files','-z','--','bin','src','public','templates','database','deploy'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('git is required to build a release.'); }
    $listed = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) { throw new AdelPos\License\Domain\Problem('El paquete se arma desde el repositorio git del proyecto.'); }
    $files = array_values(array_filter(explode("\0",(string)$listed)));
    foreach (['composer.json','composer.lock','config/local.example.php','README.md','ARCHITECTURE.md','SCHEMA.md','SECURITY.md','OPERATIONS.md','DEPLOY_UBUNTU_24_04.md','TEST_PLAN.md','DECISIONS.md','docs/CONTRATO_LICENCIAS_ADEL_POS.md'] as $file) { $files[]=$file; }
    foreach ($files as $file) {
        // Defence in depth: the secrets check already keeps these out of the repository.
        if ($file==='config/local.php' || str_starts_with($file,'var/') || preg_match('/(?:\A|\/)(?:\.DS_Store|__MACOSX)(?:\/|\z)|\.(?:key|pem)\z/i',$file)) { throw new RuntimeException('Forbidden release file: '.$file); }
    }
    sort($files); $hashes = [];
    try {
        $archive = new PharData($tar);
        foreach ($files as $file) {
            if (!is_file($root.'/'.$file) || is_link($root.'/'.$file)) { throw new RuntimeException('Missing or unsafe release file.'); }
            $archive->addFile($root.'/'.$file,'adelpos-license/'.$file);
            $hashes[$file]=hash_file('sha256',$root.'/'.$file);
        }
        $archive->addFromString('adelpos-license/RELEASE.json',json_encode(['format'=>1,'created_at'=>gmdate(DATE_ATOM),'dependencies'=>'Run composer install --no-dev from the included lockfile.','files_sha256'=>$hashes],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $archive->compress(Phar::GZ); unset($archive); unlink($tar);
        $hash = hash_file('sha256',$output);
        file_put_contents($output.'.sha256',$hash.'  '.basename($output).PHP_EOL);
        fwrite(STDOUT,json_encode(['archive'=>$output,'sha256'=>$hash,'files'=>count($files),'vendor_included'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL);
    } catch (Throwable $error) {
        unset($archive);
        foreach ([$tar,$output,$output.'.sha256'] as $path) { if (is_file($path)) { unlink($path); } }
        throw $error;
    }
});
