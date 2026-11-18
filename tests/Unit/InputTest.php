<?php
declare(strict_types=1);
namespace AdelPos\License\Tests\Unit;

use AdelPos\License\Domain\{Input, Problem};
use PHPUnit\Framework\TestCase;

/** Mensajes de validación de los formularios en español claro, con el nombre del campo (etapa 5, Fase 3). */
final class InputTest extends TestCase
{
	private static function message(callable $read): string
	{
		try {
			$read();
		} catch (Problem $problem) {
			self::assertSame(422, $problem->status);
			return $problem->getMessage();
		}
		self::fail('Valor aceptado');
	}

	public function testMessagesNameTheFieldInSpanish(): void
	{
		self::assertSame(
			'Escribe o elige el campo «Nombre de contacto»: es obligatorio.',
			self::message(fn() => Input::text(['contact_name' => '  '], 'contact_name')),
		);
		self::assertSame(
			'Revisa el campo «Referencia comercial»: admite hasta 5 caracteres, sin caracteres de control.',
			self::message(fn() => Input::text(['reference' => 'demasiado'], 'reference', 5)),
		);
		self::assertSame(
			'Revisa el campo «Motivo»: admite hasta 500 caracteres, sin caracteres de control.',
			self::message(fn() => Input::text(['reason' => "a\x07b"], 'reason', 500)),
		);
		self::assertSame(
			'El campo «Plan» no es válido.',
			self::message(fn() => Input::text(['plan_id' => ['x']], 'plan_id')),
		);
		self::assertSame(
			'Elige una opción válida en «Estado».',
			self::message(fn() => Input::choice(['state' => 'otro'], 'state', ['active', 'archived'])),
		);
		// Un campo sin nombre registrado usa su clave.
		self::assertSame('campo_nuevo', Input::label('campo_nuevo'));
		self::assertSame('ok', Input::text(['x' => ' ok '], 'x'));
	}
}
