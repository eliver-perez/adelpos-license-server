<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Error de la API en línea, con los códigos del contrato (sección 8.7). */
final class ApiProblem extends \RuntimeException
{
	/** Código HTTP que el contrato asigna a cada código de error. */
	public const STATUS = [
		'INVALID_REQUEST' => 400,
		'INCOMPATIBLE_SCHEMA' => 400,
		'INVALID_PROOF' => 401,
		'REVOKED' => 403,
		'LICENSE_NOT_FOUND' => 404,
		'ACTIVATION_LIMIT' => 409,
		'TRANSFER_LIMIT' => 409,
		'REQUEST_CONFLICT' => 409,
		'RATE_LIMITED' => 429,
		'TEMPORARY_UNAVAILABLE' => 503,
	];

	/** Texto informativo. AD-EL POS muestra sus propios textos a partir del código. */
	private const MESSAGES = [
		'INVALID_REQUEST' => 'La solicitud no es válida.',
		'INCOMPATIBLE_SCHEMA' => 'La versión del contrato no es compatible.',
		'INVALID_PROOF' => 'No se pudo autenticar la solicitud.',
		'REVOKED' => 'La licencia fue revocada.',
		'LICENSE_NOT_FOUND' => 'El código y el producto no corresponden a una licencia.',
		'ACTIVATION_LIMIT' => 'La licencia ya está activa en otra computadora.',
		'TRANSFER_LIMIT' => 'Hay una transferencia pendiente de confirmar.',
		'REQUEST_CONFLICT' => 'El identificador de la solicitud ya se usó con otro contenido.',
		'RATE_LIMITED' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
		'TEMPORARY_UNAVAILABLE' => 'El servicio no está disponible temporalmente.',
	];

	public readonly int $status;

	/**
	 * @param ?int $status solo para los errores de transporte de INVALID_REQUEST (404, 405, 413 y 415);
	 *     en los demás casos se usa el de la tabla del contrato
	 * @param array<string, mixed> $details datos adicionales del error; vacío si no aplica
	 */
	public function __construct(
		public readonly string $errorCode,
		?int $status = null,
		public readonly array $details = [],
	) {
		if (!isset(self::STATUS[$errorCode])) {
			throw new \LogicException('Unknown API error code.');
		}
		$this->status = $status ?? self::STATUS[$errorCode];
		parent::__construct(self::MESSAGES[$errorCode]);
	}
}
