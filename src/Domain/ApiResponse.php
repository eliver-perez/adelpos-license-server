<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** Respuesta de la API en línea: el código HTTP y el cuerpo JSON exacto que se guarda para los reintentos. */
final class ApiResponse
{
	public function __construct(public readonly int $status, public readonly string $body) {}

	public static function data(array $data): self
	{
		return new self(200, Protocol::json($data));
	}

	/**
	 * Formato de error del contrato (sección 8.7).
	 *
	 * @param ?string $requestId el del cliente si envió un UUID válido; null en cualquier otro caso
	 */
	public static function error(ApiProblem $error, ?string $requestId): self
	{
		return new self(
			$error->status,
			Protocol::json([
				'error' => [
					'code' => $error->errorCode,
					'message' => $error->getMessage(),
					'request_id' => $requestId,
					// Siempre un objeto JSON, aunque esté vacío.
					'details' => (object) $error->details,
				],
			]),
		);
	}
}
