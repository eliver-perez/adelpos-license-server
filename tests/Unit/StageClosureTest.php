<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Criterios de cierre de la etapa 2 que se pueden comprobar leyendo el repositorio: el contrato
 * no se modificó, no queda protocolo anterior y lo pendiente está marcado con su etapa.
 */
final class StageClosureTest extends TestCase
{
	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	/** @return list<string> Archivos versionados, con rutas relativas a la raíz. */
	private static function tracked(string ...$paths): array
	{
		$process = proc_open(
			['git', '-C', self::root(), 'ls-files', '-z', '--', ...$paths],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		$listed = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process));
		return array_values(array_filter(explode("\0", (string) $listed)));
	}

	public function testTheContractWasNotModified(): void
	{
		// El contrato V1.0 es la fuente de verdad. Una aclaración va como propuesta V1.1, no como edición.
		self::assertSame(
			'a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb',
			hash_file('sha256', self::root() . '/docs/CONTRATO_LICENCIAS_ADEL_POS.md'),
		);
	}

	public function testNoInheritanceMarkerIsLeftOutsideTheProgressRecord(): void
	{
		// AVANCE.md conserva el registro de las dos etapas y la especificación de la etapa 2 nombra el marcador.
		$allowed = ['AVANCE.md', 'docs/ETAPA_2_CONTRATO_LICENCIA_FIRMA_API.md', 'tests/Unit/StageClosureTest.php'];
		$marker = 'HERE' . 'DADO';
		$found = [];
		foreach (self::tracked() as $file) {
			if (in_array($file, $allowed, true) || !is_file(self::root() . '/' . $file)) {
				continue;
			}
			if (str_contains((string) file_get_contents(self::root() . '/' . $file), $marker)) {
				$found[] = $file;
			}
		}
		self::assertSame([], $found);
	}

	public function testThePreviousProtocolIsGoneFromTheServerCode(): void
	{
		$traces = [
			'prefijo anterior de la prueba de posesión' => '/(?<!ADELPOS-)LIC-V1/',
			'prefijo anterior de la solicitud sin internet' => '/(?<!ADELPOS-)LICREQ-V1/',
			'tipo anterior del JWS' => '/(?<!adelpos-)lic\+jws/',
			'huella del equipo' => '/fingerprint/i',
			'identidad de instalación anterior' => '/installation_id/',
			'límites anteriores' => '/max_installations|max_users|max_libraries|max_documents|license_limits/',
			'fechas anteriores' => '/maintenance_until|entitled_release_until/',
		];
		$found = [];
		foreach (self::tracked('src', 'bin', 'templates', 'public/index.php', 'public/assets/app.js') as $file) {
			$text = (string) file_get_contents(self::root() . '/' . $file);
			foreach ($traces as $name => $pattern) {
				if (preg_match($pattern, $text)) {
					$found[] = $file . ': ' . $name;
				}
			}
		}
		self::assertSame([], $found);
	}

	public function testWhatIsLeftForLaterStagesIsMarkedWithItsStage(): void
	{
		$marks = [];
		foreach (self::tracked('src', 'bin', 'templates') as $file) {
			$text = (string) file_get_contents(self::root() . '/' . $file);
			if (preg_match_all('/PENDIENTE etapa (\d)/', $text, $matches)) {
				foreach ($matches[1] as $stage) {
					$marks[$file][] = (int) $stage;
				}
			}
			// Toda marca de pendiente nombra su etapa.
			self::assertSame(
				substr_count($text, 'PENDIENTE'),
				preg_match_all('/PENDIENTE etapa [345]:/', $text),
				$file,
			);
		}
		ksort($marks);
		self::assertSame(
			[
				// Transferencia por autoservicio.
				'src/Application/ActivationService.php' => [4],
				// Conversión de prueba, mantenimiento y periodo inicial (etapa 3, fases 4 y 6).
				'src/Application/LicenseService.php' => [3],
				// Alertas de transferencias.
				'src/Application/OfflineService.php' => [4],
				'src/Domain/Protocol.php' => [4],
				'src/Http/ApiKernel.php' => [4],
				'src/Http/Kernel.php' => [3],
				// Diseño final de las constancias.
				'templates/certificate-pdf.php' => [5],
				'templates/license-detail.php' => [3],
			],
			$marks,
		);
	}
}
