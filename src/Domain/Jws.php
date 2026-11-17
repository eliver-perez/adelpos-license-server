<?php
declare(strict_types=1);
namespace AdelPos\License\Domain;

/** JWS Compact de la licencia, con el encabezado exacto del contrato (sección 3). */
final class Jws
{
	public static function sign(array $payload, string $kid, string $secret): string
	{
		$message =
			Protocol::encode(Protocol::json(['alg' => 'EdDSA', 'kid' => $kid, 'typ' => LicenseContent::JWS_TYPE])) .
			'.' .
			Protocol::encode(Protocol::json($payload));
		return $message . '.' . Protocol::encode(sodium_crypto_sign_detached($message, $secret));
	}
}
