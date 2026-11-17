<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Http\View;
use PHPUnit\Framework\TestCase;

/** Every audit operation shown in the panel (from src/ and bin/) has a plain-Spanish label, never its internal key. */
final class AuditLabelsTest extends TestCase
{
	/** Operations whose name is built at run time. */
	private const COMPUTED = [
		'activation.activate',
		'activation.refresh',
		'activation.deactivate',
		'license.features',
		'license.reissue',
		'license.revoke',
	];

	public function testEveryAuditOperationHasASpanishLabel(): void
	{
		$operations = self::COMPUTED;
		$root = dirname(__DIR__, 2);
		$files = [
			...new \RegexIterator(
				new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
				),
				'/\.php\z/',
			),
			...array_map(
				static fn(string $path): \SplFileInfo => new \SplFileInfo($path),
				glob($root . '/bin/*.php') ?: [],
			),
		];
		foreach ($files as $file) {
			$source = (string) file_get_contents($file->getPathname());
			// Literal operations passed to append(), failed() or the license history's record(), up to the end of
			// the statement, including both branches of a ternary.
			preg_match_all('/->(?:append|failed|record)\([^;]*;/s', $source, $calls);
			foreach ($calls[0] as $call) {
				preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $call, $names);
				array_push($operations, ...$names[1]);
			}
		}
		$operations = array_values(array_unique($operations));
		sort($operations);
		self::assertGreaterThan(30, count($operations));
		$missing = array_values(
			array_filter($operations, static fn(string $operation): bool => View::action($operation) === $operation),
		);
		self::assertSame([], $missing);
	}
}
