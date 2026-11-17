<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regla del propietario: el código no se ofusca ni se minifica. Se escribe con saltos de línea
 * e indentación con tabuladores para que se entienda al leerlo.
 */
final class CodeStyleTest extends TestCase
{
	private const MAX_COLUMNS = 140;
	private const TAB_WIDTH = 4;

	private const PATTERNS = [
		'src/*.php',
		'src/*/*.php',
		'bin/*.php',
		'tests/*.php',
		'tests/*/*.php',
		'tests/*.cjs',
		'config/*.php',
		'public/*.php',
		'public/assets/app.js',
		'public/assets/app.css',
		'contracts/v1/*.php',
		'contracts/v1/verify/*.go',
		'templates/*.php',
		'database/migrations/*.sql',
	];

	/** La especificación de la etapa 2 prohíbe modificar la migración inicial. */
	private const EXCLUDED = ['database/migrations/001_esquema_inicial.sql'];

	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	/** @return list<string> */
	private static function files(): array
	{
		$files = [];
		foreach (self::PATTERNS as $pattern) {
			foreach (glob(self::root() . '/' . $pattern) as $path) {
				$relative = substr($path, strlen(self::root()) + 1);
				if (!in_array($relative, self::EXCLUDED, true)) {
					$files[] = $relative;
				}
			}
		}
		sort($files);
		return $files;
	}

	public function testSourceFilesAreIndentedWithTabsAndHaveReadableLines(): void
	{
		$files = self::files();
		self::assertGreaterThan(100, count($files));
		$problems = [];
		foreach ($files as $file) {
			$lines = explode("\n", file_get_contents(self::root() . '/' . $file));
			foreach ($lines as $index => $line) {
				$place = $file . ':' . ($index + 1);
				// Las líneas « * » son la continuación de un comentario de bloque.
				if (str_starts_with($line, ' ') && !str_starts_with($line, ' *')) {
					$problems[] = $place . ' está indentada con espacios';
				}
				if (rtrim($line) !== $line) {
					$problems[] = $place . ' termina con espacios';
				}
				$columns = mb_strlen(str_replace("\t", str_repeat(' ', self::TAB_WIDTH), $line));
				if ($columns > self::MAX_COLUMNS) {
					$problems[] = $place . ' mide ' . $columns . ' columnas';
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testTemplatesKeepOneStatementPerPhpTag(): void
	{
		$problems = [];
		foreach (glob(self::root() . '/templates/*.php') as $path) {
			$statements = 0;
			$multiline = false;
			$line = 0;
			foreach (\PhpToken::tokenize(file_get_contents($path)) as $token) {
				if ($token->id === T_OPEN_TAG || $token->id === T_OPEN_TAG_WITH_ECHO) {
					$statements = 0;
					$line = $token->line;
					$multiline = str_contains($token->text, "\n");
				} elseif ($token->id === T_WHITESPACE && str_contains($token->text, "\n")) {
					$multiline = true;
				} elseif ($token->text === ';' || $token->text === ':' || $token->text === '{') {
					$statements += $token->text === ':' ? 0 : 1;
				} elseif ($token->id === T_CLOSE_TAG && !$multiline && $statements > 1) {
					$problems[] = basename($path) . ':' . $line . ' junta varias sentencias en una línea';
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testFormatterAgreesWithTheCommittedCode(): void
	{
		$prettier = self::root() . '/node_modules/.bin/prettier';
		if (!is_file($prettier)) {
			self::markTestSkipped('Requiere las dependencias de desarrollo de npm (npm ci).');
		}
		$package = json_decode(file_get_contents(self::root() . '/package.json'), true);
		self::assertStringStartsWith('prettier --write ', $package['scripts']['format']);
		self::assertSame(
			str_replace('prettier --write ', 'prettier --check ', $package['scripts']['format']),
			$package['scripts']['format:check'],
		);
		$process = proc_open(
			['npm', 'run', '--silent', 'format:check'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			self::root(),
		);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), $output);
	}
}
