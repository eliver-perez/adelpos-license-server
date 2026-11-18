<?php
declare(strict_types=1);
namespace AdelPos\License\Application;

/**
 * Acciones de la barra del detalle de una licencia (docs/PANEL.md, sección 3.3): solo las que aplican
 * a esa licencia y al rol de la cuenta. Es lo que el panel muestra; cada servicio vuelve a comprobar
 * sus reglas al ejecutar.
 */
final class LicenseActions
{
	/** Nombre de cada acción, en el orden de la barra. */
	public const LABELS = [
		'renew' => 'Renovar',
		'convert' => 'Convertir',
		'plan' => 'Cambiar de plan',
		'maintenance' => 'Mantenimiento',
		'terms' => 'Editar periodo inicial',
		'download' => 'Descargar .lic vigente',
		'documents' => 'Constancias',
		'reveal' => 'Revelar código',
		'reissue' => 'Reemplazar clave',
		'revoke' => 'Revocar',
	];

	/**
	 * Claves de las acciones disponibles. La licencia necesita commercial_status, license_type,
	 * period_starts_at y, para «download», current_revision (la revisión vigente de su computadora activa,
	 * o null si no tiene una).
	 *
	 * @return list<string>
	 */
	public static function for(array $license, Actor $actor): array
	{
		$issued = $license['commercial_status'] === 'issued';
		$activated = $license['period_starts_at'] !== null;
		$type = $license['license_type'];
		$write = $actor->canWrite() && $issued;
		$rules = [
			'renew' => $write && $type === 'subscription' && $activated,
			'convert' => $write && $type === 'trial',
			'plan' => $write && ($activated || $type === 'trial'),
			'maintenance' => $write && $type === 'perpetual' && $activated,
			'terms' => $write && !$activated,
			'download' => ($license['current_revision'] ?? null) !== null && $actor->allows('offline.download'),
			'documents' => $actor->canWrite(),
			'reveal' => $issued && $actor->allows('credentials.reveal'),
			'reissue' => $write,
			'revoke' => $issued && $actor->role === 'superadmin',
		];
		return array_keys(array_filter($rules));
	}
}
