<?php
declare(strict_types=1);
namespace AdelPos\License;

use AdelPos\License\Application\{
	Access,
	ActivationService,
	AdminService,
	AuthService,
	CatalogService,
	DocumentService,
	LicenseActivator,
	LicenseMaterials,
	LicenseService,
	OfflineService,
	Operations,
	Payments,
	PlanService,
	RevisionPublisher,
	TrustedDevices,
};
use AdelPos\License\Infrastructure\{
	Audit,
	Clock,
	Crypto,
	Database,
	RateLimiter,
	ReadRepository,
	Sessions,
	SigningKeys,
	Totp,
};

final class App
{
	public readonly Clock $clock;
	public readonly Crypto $crypto;
	public readonly Audit $audit;
	public readonly Sessions $sessions;
	public readonly Totp $totp;
	public readonly AuthService $auth;
	public readonly TrustedDevices $trustedDevices;
	public readonly CatalogService $catalog;
	public readonly LicenseService $licenses;
	public readonly AdminService $admins;
	public readonly ReadRepository $read;
	public readonly SigningKeys $signingKeys;
	public readonly RevisionPublisher $publisher;
	public readonly ActivationService $activations;
	public readonly RateLimiter $rate;
	public readonly OfflineService $offline;
	public readonly Access $access;
	public readonly LicenseMaterials $materials;
	public readonly DocumentService $documents;
	public readonly Payments $payments;
	public readonly PlanService $plans;

	public function __construct(public readonly Config $config, public readonly Database $db, ?Clock $clock = null)
	{
		$this->clock = $clock ?? new Clock();
		$this->crypto = new Crypto($config);
		$this->audit = new Audit($db, $this->crypto, $this->clock);
		$this->sessions = new Sessions($db, $this->crypto, $this->clock);
		$this->totp = new Totp($this->clock);
		$rate = new RateLimiter($db, $this->crypto, $this->clock);
		$this->rate = $rate;
		$this->signingKeys = new SigningKeys($config, $db, $this->clock, $this->audit);
		$this->publisher = new RevisionPublisher($db, $this->clock, $this->signingKeys);
		$activator = new LicenseActivator($db, $this->clock, $this->publisher);
		$this->activations = new ActivationService(
			$db,
			$this->clock,
			$this->crypto,
			$this->audit,
			$this->publisher,
			$activator,
			$rate,
		);
		$this->trustedDevices = new TrustedDevices($db, $this->clock, $this->audit, $this->sessions);
		$this->auth = new AuthService(
			$db,
			$this->crypto,
			$this->clock,
			$this->audit,
			$this->sessions,
			$rate,
			$this->totp,
			$this->trustedDevices,
		);
		$operations = new Operations($db, $this->crypto, $this->clock);
		$this->catalog = new CatalogService($db, $operations, $this->audit, $this->clock);
		$this->payments = new Payments($db, $this->clock, $this->audit);
		$this->licenses = new LicenseService(
			$db,
			$operations,
			$this->audit,
			$this->clock,
			$this->crypto,
			$this->publisher,
			$this->payments,
		);
		$this->plans = new PlanService($db, $operations, $this->audit, $this->clock, $this->publisher);
		$this->offline = new OfflineService(
			$db,
			$operations,
			$this->audit,
			$this->clock,
			$this->crypto,
			$this->publisher,
			$activator,
		);
		$this->admins = new AdminService(
			$db,
			$operations,
			$this->auth,
			$this->audit,
			$this->clock,
			$this->trustedDevices,
		);
		$this->read = new ReadRepository($db, $this->clock);
		$this->access = new Access($db);
		$this->materials = new LicenseMaterials($db, $this->crypto, $this->audit, $this->access, $this->auth);
		$this->documents = new DocumentService(
			$db,
			$config,
			$this->clock,
			$this->crypto,
			$this->audit,
			$operations,
			$this->access,
			$this->materials,
			new \AdelPos\License\Infrastructure\CertificatePdf(),
		);
	}

	public static function boot(): self
	{
		$config = Config::load();
		$config->validate();
		$app = new self($config, Database::connect($config), null);
		// El servidor no arranca si su llave activa es de otro entorno de licencias.
		$app->signingKeys->assertEnvironment();
		return $app;
	}
}
