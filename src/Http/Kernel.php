<?php
declare(strict_types=1);
namespace AdelPos\License\Http;

use AdelPos\License\App;
use AdelPos\License\Application\{Actor, TrustedDevices};
use AdelPos\License\Domain\{Input, Problem};
use AdelPos\License\Infrastructure\ReadRepository;
use BaconQrCode\Renderer\{ImageRenderer, Image\SvgImageBackEnd, RendererStyle\RendererStyle};
use BaconQrCode\Writer;

final class Kernel
{
	private ?array $session = null;
	private ?Actor $actor = null;
	private string $token = '';
	private string $path = '/';
	private bool $formRetried = false;

	public function __construct(private App $app) {}

	public function handle(): void
	{
		$this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		try {
			$this->dispatch();
		} catch (Problem $problem) {
			if (
				$this->actor !== null &&
				str_starts_with($this->path, '/admin/') &&
				(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || $problem->status === 403)
			) {
				$this->app->db->transaction(
					fn() => $this->app->audit->append(
						$this->actor->id,
						'admin.operation_failed',
						'admin',
						$this->actor->id,
						['path' => $this->path, 'http_status' => $problem->status, 'result' => 'rejected'],
					),
				);
			}
			if ($this->showFormAgain($problem)) {
				return;
			}
			http_response_code($problem->status);
			if ($problem->status === 429) {
				header('Retry-After: 900');
			}
			$this->render('error', [
				'title' => 'No se pudo completar la operación',
				'message' => $problem->getMessage(),
				'status' => $problem->status,
			]);
		}
	}

	/**
	 * Un formulario del panel que no se pudo completar se vuelve a mostrar en su pantalla, con el mensaje
	 * (docs/PANEL.md, sección 7; DECISIONS.md, sección 18, Fase 1, n.º 7):
	 *
	 * - 422 (un dato) o 401 (la identidad): con lo escrito, sin contraseñas ni códigos;
	 * - 409 (otro administrador cambió algo): con los datos actuales y sin lo escrito, para no pisar el cambio.
	 *
	 * El formulario trae la dirección de su pantalla (form_origin, de View::fields). La pantalla se vuelve a
	 * pintar en la misma respuesta, con el mismo código HTTP: no se guarda nada. Devuelve false si no aplica
	 * (otro código, sin pantalla de origen o la pantalla también falla), y entonces se muestra la página de
	 * error de siempre.
	 */
	private function showFormAgain(Problem $problem): bool
	{
		$origin = $_POST['form_origin'] ?? null;
		if (
			$this->actor === null ||
			$this->formRetried ||
			($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
			!in_array($problem->status, [401, 409, 422], true) ||
			!is_string($origin) ||
			!preg_match('~\A/admin(?:/[A-Za-z0-9/_-]*)?(?:\?[A-Za-z0-9_=&%.:+-]*)?\z~', $origin)
		) {
			return false;
		}
		$this->formRetried = true;
		$form = $this->path;
		$posted = $_POST;
		View::remember($problem->status === 409 ? '' : $form, $problem->status === 409 ? [] : $posted);
		View::formError($problem->getMessage());
		$query = [];
		parse_str((string) parse_url($origin, PHP_URL_QUERY), $query);
		$_GET = $query;
		$_POST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->path = (string) parse_url($origin, PHP_URL_PATH);
		ob_start();
		try {
			$this->dispatch();
		} catch (Problem) {
			ob_end_clean();
			View::remember('', []);
			View::formError('');
			$this->path = $form;
			$_POST = $posted;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			return false;
		}
		http_response_code($problem->status);
		ob_end_flush();
		return true;
	}

	private function dispatch(): void
	{
		if (str_starts_with($this->path, '/v1/')) {
			(new ApiKernel($this->app))->handle();
			return;
		}
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$https = ($_SERVER['HTTPS'] ?? '') === 'on';
		if (!$https && !($this->app->config->development() && in_array($ip, ['127.0.0.1', '::1'], true))) {
			throw new Problem('El panel requiere una conexión HTTPS.', 403);
		}
		if (str_starts_with($this->path, '/verify/')) {
			header('Referrer-Policy: no-referrer');
			header('X-Robots-Tag: noindex, nofollow');
			if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
				header('Allow: GET');
				throw new Problem('Método no permitido.', 405);
			}
			$this->app->rate->consume('document-verify', $ip, 30, 60);
			$verification = $this->app->documents->verify(substr($this->path, 8));
			if ($verification === null) {
				http_response_code(404);
			}
			require dirname(__DIR__, 2) . '/templates/verify-document.php';
			return;
		}
		if (!Network::allows($this->app->config->get('ADMIN_NETWORKS'), $ip)) {
			throw new Problem('El panel no está disponible desde esta red.', 403);
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if (!in_array($method, ['GET', 'POST'], true)) {
			header('Allow: GET, POST');
			throw new Problem('Método no permitido.', 405);
		}
		$uploadRoute = $this->path === '/admin/offline/import';
		if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ($uploadRoute ? 98304 : 65536)) {
			throw new Problem('El formulario supera el tamaño permitido.', 413);
		}
		$cookie = $_COOKIE[$this->cookieName()] ?? null;
		$this->token = is_string($cookie) ? $cookie : '';
		$this->session = $this->app->sessions->load($this->token);
		if ($this->session === null) {
			$this->setToken($this->app->sessions->create());
		}
		if ($method === 'POST') {
			$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
			if ($contentType !== ($uploadRoute ? 'multipart/form-data' : 'application/x-www-form-urlencoded')) {
				throw new Problem('El formato del formulario no es válido.', 415);
			}
			$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
			if (
				($origin !== null && $origin !== rtrim($this->app->config->get('APP_URL'), '/')) ||
				!$this->app->sessions->validCsrf($this->token, $_POST['csrf'] ?? null)
			) {
				throw new Problem('El formulario venció o no pertenece a esta sesión. Recarga la página.', 403);
			}
		}
		if ($this->session['stage'] === 'authenticated') {
			$this->actor = $this->app->access->actor($this->session['admin_uuid']);
		}
		if ($this->path === '/') {
			$this->redirect($this->actor ? '/admin' : '/login');
			return;
		}
		if ($this->path === '/login') {
			if ($this->actor) {
				$this->redirect('/admin');
				return;
			}
			if ($method === 'POST') {
				try {
					$token = $this->app->auth->login(
						Input::text($_POST, 'login'),
						$this->password('password'),
						$ip,
						$this->token,
						$https ? $this->trustedToken() : null,
					);
					$this->setToken($token);
					$this->redirect($this->session['stage'] === 'authenticated' ? '/admin' : '/mfa');
					return;
				} catch (Problem $problem) {
					http_response_code($problem->status);
					if ($problem->status === 429) {
						header('Retry-After: 900');
					}
					$this->render('login', ['title' => 'Iniciar sesión', 'error' => $problem->getMessage()]);
					return;
				}
			}
			$this->render('login', ['title' => 'Iniciar sesión']);
			return;
		}
		if ($this->path === '/mfa') {
			$this->mfa($method, $ip);
			return;
		}
		if ($this->path === '/logout' && $method === 'POST') {
			$this->app->auth->logout($this->token, $this->session['admin_uuid']);
			setcookie($this->cookieName(), '', $this->cookieOptions(time() - 3600));
			$this->redirect('/login');
			return;
		}
		if ($this->actor === null) {
			$this->redirect($this->session['stage'] === 'pending' ? '/mfa' : '/login');
			return;
		}
		if ($this->path === '/trust-device') {
			if (!$this->app->trustedDevices->canOffer($this->session)) {
				$this->redirect('/admin');
				return;
			}
			$error = null;
			if ($method === 'POST') {
				$choice = Input::choice($_POST, 'choice', ['trust', 'skip']);
				if ($choice === 'trust' && !$https) {
					throw new Problem('Conéctate mediante HTTPS para confiar en este dispositivo.', 403);
				}
				try {
					$grant = $this->app->trustedDevices->decide(
						$this->token,
						$choice === 'trust',
						$_SERVER['HTTP_USER_AGENT'] ?? '',
						$this->trustedToken(),
					);
					if ($grant !== null) {
						setcookie(
							TrustedDevices::COOKIE,
							$grant['token'],
							TrustedDevices::cookieOptions($grant['expires']),
						);
					}
					$this->redirect('/admin');
					return;
				} catch (\PDOException $failure) {
					error_log('Trusted device authorization unavailable.');
					$error =
						'No se pudo guardar la confianza. Puedes continuar con «Ahora no» e intentarlo en tu próximo inicio de sesión.';
				}
			}
			$this->render('trust-device', [
				'title' => '¿Deseas confiar en este dispositivo?',
				'https' => $https,
				'error' => $error,
			]);
			return;
		}
		$operationId = $method === 'POST' ? Input::text($_POST, 'operation_id') : '';
		if ($this->path === '/admin/security/devices/revoke' && $method === 'POST') {
			if (Input::choice($_POST, 'confirmation', ['revoke', 'cancel']) === 'cancel') {
				$this->redirect('/admin/security');
				return;
			}
			$scope = Input::choice($_POST, 'scope', ['one', 'all']);
			$this->app->trustedDevices->revoke(
				$this->actor,
				$scope === 'all' ? null : Input::text($_POST, 'device_id', 36),
			);
			$this->redirect('/admin/security?notice=devices-revoked');
			return;
		}
		if (
			preg_match(
				'~^/admin/licenses/([a-f0-9-]{36})/(administrative|reveal|documents)$~',
				$this->path,
				$matches,
			) &&
			$method === 'POST'
		) {
			if ($matches[2] === 'administrative') {
				$this->app->licenses->administrative($this->actor, $operationId, $matches[1], $_POST);
				$notice = 'administrative';
			} elseif ($matches[2] === 'documents') {
				$this->app->rate->consume('document-generate', $this->actor->id, 10, 60);
				$this->app->documents->generate(
					$this->actor,
					$operationId,
					$matches[1],
					$_POST,
					$this->password('password'),
					Input::text($_POST, 'code', 6, false),
				);
				$notice = 'document';
			} else {
				$secret = $this->app->materials->reveal(
					$this->actor,
					$matches[1],
					$this->password('password'),
					Input::text($_POST, 'code', 6),
				);
				$this->render('issued', [
					'title' => 'Código de activación',
					'licenseId' => $matches[1],
					'commercialKey' => $secret,
				]);
				return;
			}
			$this->redirect('/admin/licenses/' . $matches[1] . '?notice=' . $notice);
			return;
		}
		if (preg_match('~^/admin/documents/([a-f0-9-]{36})/download$~', $this->path, $matches)) {
			$this->actor->requireRole(['superadmin', 'operator']);
			$meta =
				$this->app->db->one(
					'SELECT document_type,credential_id,offline_revision_id FROM license_documents WHERE id=?',
					[\AdelPos\License\Domain\Uuid::bytes($matches[1])],
				) ?? throw new Problem('No se encontró la constancia.', 404);
			if ($meta['document_type'] === 'delivery') {
				$this->actor->requirePermission('documents.delivery');
				if ($meta['credential_id'] !== null) {
					$this->actor->requirePermission('credentials.reveal');
				}
				if ($meta['offline_revision_id'] !== null) {
					$this->actor->requirePermission('offline.download');
				}
				if ($method === 'GET') {
					$this->render('material-confirm', ['title' => 'Descargar constancia de entrega para activación']);
					return;
				}
			}
			$document = $this->app->documents->download(
				$this->actor,
				$matches[1],
				$this->password('password'),
				Input::text($_POST, 'code', 6, false),
			);
			$this->download($document['bytes'], $document['filename'], 'application/pdf');
			return;
		}
		if ($this->path === '/admin' && $method === 'GET') {
			// «Por vencer» en 7, 15 o 30 días de calendario (30 por omisión).
			$due = Input::text($_GET, 'due', 2, false);
			$dueDays = in_array($due, ['7', '15', '30'], true) ? (int) $due : 30;
			$this->render('dashboard', [
				'title' => 'Resumen',
				'counts' => $this->app->panel->counts(),
				'due' => $this->app->panel->dueSoon($dueDays),
				'dueDays' => $dueDays,
				'collections' => $this->app->panel->collections(),
				'events' => $this->app->read->recentEvents(),
			]);
			return;
		}
		if ($this->path === '/admin/alerts' && $method === 'GET') {
			$this->listing('alerts', 'Alertas');
			return;
		}
		if (preg_match('~^/admin/alerts/([a-f0-9-]{36})/review$~', $this->path, $matches) && $method === 'POST') {
			// Marcar como revisada: el operador y el superadministrador; el servicio lo vuelve a comprobar.
			$this->actor->requireRole(['superadmin', 'operator']);
			$this->app->alerts->review($this->actor, $operationId, $matches[1]);
			// Vuelve a la pantalla de donde se pidió: el resumen, las alertas o la licencia (también su sección
			// de computadoras y transferencias).
			$return = Input::text($_POST, 'return_to', 90, false);
			if (!preg_match('~\A/admin(?:/alerts|/licenses/[a-f0-9-]{36}(?:\?section=computers)?)?\z~', $return)) {
				$return = '/admin/alerts';
			}
			$this->redirect($return . (str_contains($return, '?') ? '&' : '?') . 'notice=alert-reviewed');
			return;
		}
		if ($this->path === '/admin/offline' && $method === 'GET') {
			$this->listing('offline', 'Solicitudes sin internet');
			return;
		}
		if ($this->path === '/admin/offline/import' && $method === 'POST') {
			$this->actor->requireRole(['superadmin', 'operator']);
			$this->app->rate->consume('offline-import', $this->actor->id, 30, 60);
			$file = $_FILES['request_file'] ?? null;
			if (
				!is_array($file) ||
				!isset($file['error'], $file['tmp_name'], $file['size']) ||
				$file['error'] !== UPLOAD_ERR_OK ||
				!is_string($file['tmp_name']) ||
				!is_uploaded_file($file['tmp_name'])
			) {
				throw new Problem('Selecciona un archivo .licreq válido de hasta 64 KiB.');
			}
			if ($file['size'] > 65536) {
				throw new Problem('El archivo supera 64 KiB.', 413);
			}
			$envelope = file_get_contents($file['tmp_name'], false, null, 0, 65537);
			if ($envelope === false) {
				throw new Problem('No se pudo leer el archivo.');
			}
			$result = $this->app->offline->import($this->actor, $operationId, $envelope);
			$this->redirect('/admin/offline/' . $result['id']);
			return;
		}
		if (preg_match('~^/admin/offline/([a-f0-9-]{36})(?:/(approve|reject|download))?$~', $this->path, $matches)) {
			if (($matches[2] ?? '') === 'download') {
				$this->actor->requirePermission('offline.download');
				$offline = $this->app->read->offline($matches[1]);
				if ($offline['status'] !== 'approved') {
					throw new Problem('La solicitud no tiene un archivo autorizado.', 409);
				}
				if ($method === 'GET') {
					$this->render('material-confirm', ['title' => 'Descargar archivo de licencia (.lic)']);
					return;
				}
				$revision = $this->app->materials->revision(
					$this->actor,
					$offline['license_text'],
					$offline['revision_text'],
					$this->password('password'),
					Input::text($_POST, 'code', 6),
				);
				$this->download(
					$revision['license_jws'],
					'request-' . $matches[1] . '.lic',
					'text/plain; charset=utf-8',
				);
				return;
			}
			if ($method === 'POST' && in_array($matches[2] ?? '', ['approve', 'reject'], true)) {
				$this->actor->requireRole(['superadmin', 'operator']);
				$offlineAction = $this->app->read->offline($matches[1])['payload']['action'];
				if (
					$matches[2] === 'approve' &&
					(Input::text($_POST, 'force_activation_id', 36, false) !== '' || $offlineAction === 'deactivate')
				) {
					$this->actor->requireRole(['superadmin']);
					$this->confirmIdentity();
				}
				$result = $this->app->offline->decide(
					$this->actor,
					$operationId,
					$matches[1],
					$matches[2] === 'approve' ? 'approved' : 'rejected',
					$_POST,
				);
				$this->redirect('/admin/offline/' . $result['id']);
				return;
			}
			if ($method === 'GET') {
				$offline = $this->app->read->offline($matches[1]);
				if (!isset($matches[2])) {
					$selected = $offline['payload']['license_id'] ?? Input::text($_GET, 'license_id', 36, false);
					$license = $selected === '' ? null : $this->app->read->license($selected);
					if ($license !== null && $license['product_id'] !== $offline['product_id']) {
						throw new Problem('La licencia corresponde a otro producto.');
					}
					$query = Input::text($_GET, 'q', 100, false);
					$this->render('offline-detail', [
						'title' => 'Revisar solicitud sin internet',
						'offline' => $offline,
						'license' => $license,
						'plan' => $license === null ? null : $this->app->read->plan($license['plan_id']),
						'candidates' =>
							$offline['payload']['action'] === 'activate' && $offline['status'] === 'pending'
								? $this->app->read->offlineCandidates($offline['product_id'], $query)
								: [],
						'query' => $query,
					]);
					return;
				}
			}
		}
		if (
			preg_match(
				'~^/admin/licenses/([a-f0-9-]{36})/(reveal|reissue|revoke|release|transfer-release)$~',
				$this->path,
				$matches,
			) &&
			$method === 'GET'
		) {
			$this->confirmation($matches[1], $matches[2]);
			return;
		}
		if (
			preg_match('~^/admin/licenses/([a-f0-9-]{36})/transfer-release$~', $this->path, $matches) &&
			$method === 'POST'
		) {
			// Liberación manual del contador de autoservicio: el servicio exige el rol y confirma la identidad.
			$this->app->transfers->release(
				$this->actor,
				$operationId,
				$matches[1],
				$_POST,
				$this->password('password'),
				Input::text($_POST, 'code', 6),
			);
			$this->redirect('/admin/licenses/' . $matches[1] . '?notice=transfer-released');
			return;
		}
		if (preg_match('~^/admin/licenses/([a-f0-9-]{36})/release$~', $this->path, $matches) && $method === 'POST') {
			$this->actor->requireRole(['superadmin']);
			$this->confirmIdentity();
			$this->licenseResult(
				$this->app->offline->release($this->actor, $operationId, $matches[1], $_POST),
				'release',
			);
			return;
		}
		if ($this->path === '/admin/customers' && $method === 'GET') {
			$filters = ['q' => Input::text($_GET, 'q', 100, false), 'state' => Input::text($_GET, 'state', 16, false)];
			$this->render('customers', [
				'title' => 'Clientes',
				'listing' => $this->app->panel->customers($filters, self::page()),
				'filters' => $filters,
			]);
			return;
		}
		if ($this->path === '/admin/customers/new' && $method === 'GET') {
			$this->actor->requireRole(['superadmin', 'operator']);
			$this->render('customer-form', ['title' => 'Nuevo cliente', 'customer' => null]);
			return;
		}
		if (preg_match('~^/admin/customers/([a-f0-9-]{36}|create)(/edit)?$~', $this->path, $matches)) {
			$id = $matches[1] === 'create' ? null : $matches[1];
			if ($method === 'POST' && !isset($matches[2])) {
				$result = $this->app->catalog->saveCustomer($this->actor, $operationId, $id, $_POST);
				$this->redirect(
					'/admin/customers/' . $result['id'] . '?notice=' . ($id === null ? 'created' : 'saved'),
				);
				return;
			}
			if ($id !== null && $method === 'GET' && isset($matches[2])) {
				$this->actor->requireRole(['superadmin', 'operator']);
				$customer = $this->app->read->customer($id);
				$this->render('customer-form', [
					'title' => 'Editar ' . $customer['display_name'],
					'customer' => $customer,
				]);
				return;
			}
			if ($id !== null && $method === 'GET') {
				$customer = $this->app->read->customer($id);
				$this->render('customer-detail', [
					'title' => $customer['display_name'],
					'customer' => $customer,
					'licenses' => $this->app->panel->customerLicenses($id),
					'payments' => $this->app->panel->customerPayments($id),
				]);
				return;
			}
		}
		if ($this->path === '/admin/products' && $method === 'GET') {
			$this->render('products', ['title' => 'Productos', 'products' => $this->app->read->products()]);
			return;
		}
		if ($this->path === '/admin/products/new' && $method === 'GET') {
			$this->actor->requireRole(['superadmin']);
			$this->render('product-form', ['title' => 'Nuevo producto', 'product' => null, 'capabilities' => []]);
			return;
		}
		if (preg_match('~^/admin/products/([a-z][a-z0-9_]{1,63})(/capabilities)?$~', $this->path, $matches)) {
			$id = $matches[1] === 'create' ? null : $matches[1];
			if ($method === 'POST') {
				$result = isset($matches[2])
					? $this->app->catalog->saveCapability($this->actor, $operationId, (string) $id, $_POST)
					: $this->app->catalog->saveProduct($this->actor, $operationId, $id, $_POST);
				$this->redirect('/admin/products/' . $result['id']);
				return;
			}
			if ($id !== null && !isset($matches[2])) {
				$this->render('product-form', [
					'title' => 'Detalle del producto',
					'product' => $this->app->read->product($id),
					'capabilities' => $this->app->read->capabilities($id),
				]);
				return;
			}
		}
		if (str_starts_with($this->path, '/admin/plans')) {
			$this->plans($method, $operationId);
			return;
		}
		if ($this->path === '/admin/licenses' && $method === 'GET') {
			$filters = [];
			foreach (
				['q' => 100, 'type' => 16, 'state' => 16, 'plan' => 64, 'custom' => 1, 'transfer' => 16, 'sort' => 16]
				as $key => $length
			) {
				$filters[$key] = Input::text($_GET, $key, $length, false);
			}
			$this->render('licenses', [
				'title' => 'Licencias',
				'listing' => $this->app->panel->licenses($filters, self::page()),
				'filters' => $filters,
				'plans' => $this->app->read->plans(true),
			]);
			return;
		}
		if ($this->path === '/admin/licenses/new' && $method === 'GET') {
			$this->actor->requireRole(['superadmin', 'operator']);
			$this->render('license-form', [
				'title' => 'Emitir licencia',
				'selectedCustomer' => Input::text($_GET, 'customer_id', 36, false),
				'customers' => $this->app->read->customers(),
				'products' => $this->app->read->products(),
				'plans' => $this->app->read->plans(),
				'capabilities' => $this->app->read->capabilities(),
			]);
			return;
		}
		if ($this->path === '/admin/licenses/create' && $method === 'POST') {
			$this->licenseResult($this->app->licenses->issue($this->actor, $operationId, $_POST));
			return;
		}
		if (
			preg_match('~^/admin/licenses/([a-f0-9-]{36})/revisions/([a-f0-9-]{36})/download$~', $this->path, $matches)
		) {
			$this->actor->requirePermission('offline.download');
			if ($method === 'GET') {
				$this->render('material-confirm', ['title' => 'Descargar archivo firmado']);
				return;
			}
			$revision = $this->app->materials->revision(
				$this->actor,
				$matches[1],
				$matches[2],
				$this->password('password'),
				Input::text($_POST, 'code', 6),
			);
			$this->download(
				$revision['license_jws'],
				'license-' . $matches[1] . '-r' . $revision['license_revision'] . '.lic',
				'text/plain; charset=utf-8',
			);
			return;
		}
		if (
			preg_match(
				'~^/admin/licenses/([a-f0-9-]{36})/(renew|convert|plan|scheduled-cancel|maintenance|terms)$~',
				$this->path,
				$matches,
			)
		) {
			$this->commercial($method, $operationId, $matches[1], $matches[2]);
			return;
		}
		if (preg_match('~^/admin/licenses/([a-f0-9-]{36})(?:/(features|reissue|revoke))?$~', $this->path, $matches)) {
			if ($method === 'POST' && isset($matches[2])) {
				if (in_array($matches[2], ['revoke', 'reissue'], true)) {
					$this->confirmIdentity();
				}
				$this->licenseResult(
					$this->app->licenses->change($this->actor, $operationId, $matches[1], $matches[2], $_POST),
					$matches[2],
				);
				return;
			}
			if ($method === 'GET' && !isset($matches[2])) {
				// Una sección por dirección (docs/PANEL.md, sección 3.3): solo se consulta lo que se muestra.
				$section = Input::text($_GET, 'section', 16, false);
				$section = array_key_exists($section, ReadRepository::LICENSE_SECTIONS) ? $section : 'summary';
				$revisionPage = Input::text($_GET, 'revision_page', 8, false);
				$license = $this->app->read->license(
					$matches[1],
					ctype_digit($revisionPage) ? (int) $revisionPage : 1,
					$section,
				);
				$this->render('license-detail', [
					'title' => $license['customer_name'] . ' · ' . ReadRepository::LICENSE_SECTIONS[$section],
					'license' => $license,
					'section' => $section,
					'capabilities' => $section === 'summary' ? $this->app->read->capabilities() : [],
				]);
				return;
			}
		}
		if ($this->path === '/admin/audit' && $method === 'GET') {
			$this->listing('audit', 'Auditoría');
			return;
		}
		if ($this->path === '/admin/users' && $method === 'GET') {
			$this->actor->requireRole(['superadmin']);
			$this->render('users', ['title' => 'Administradores', 'users' => $this->app->read->users()]);
			return;
		}
		if ($this->path === '/admin/users/create' && $method === 'POST') {
			$this->actor->requireRole(['superadmin']);
			$this->confirmIdentity();
			$this->app->admins->create($this->actor, $operationId, $_POST);
			$this->redirect('/admin/users');
			return;
		}
		if (preg_match('~^/admin/users/([a-f0-9-]{36})$~', $this->path, $matches) && $method === 'POST') {
			$this->actor->requireRole(['superadmin']);
			$this->confirmIdentity();
			$this->app->admins->update($this->actor, $operationId, $matches[1], $_POST);
			$this->redirect('/admin/users');
			return;
		}
		if ($this->path === '/admin/security') {
			if ($method === 'POST') {
				$password = $this->password('new_password');
				if ($password !== $this->password('confirmation')) {
					throw new Problem('Las contraseñas nuevas no coinciden.');
				}
				$this->confirmIdentity();
				$this->app->auth->changePassword($this->actor, $password);
				$this->redirect('/login');
				return;
			}
			$devices = [];
			$devicesUnavailable = false;
			try {
				$devices = $this->app->trustedDevices->devices($this->actor, $https ? $this->trustedToken() : null);
			} catch (\PDOException $failure) {
				$devicesUnavailable = true;
				error_log('Trusted device listing unavailable.');
			}
			$this->render('security', [
				'title' => 'Seguridad de tu cuenta',
				'devices' => $devices,
				'devicesUnavailable' => $devicesUnavailable,
				'devicesRevoked' => ($_GET['notice'] ?? '') === 'devices-revoked',
			]);
			return;
		}
		throw new Problem('La página solicitada no existe.', 404);
	}

	private function mfa(string $method, string $ip): void
	{
		if ($this->actor) {
			$this->redirect('/admin');
			return;
		}
		if ($this->session['stage'] !== 'pending') {
			$this->redirect('/login');
			return;
		}
		$error = null;
		if ($method === 'POST') {
			try {
				$result = $this->app->auth->verifyMfa($this->token, Input::text($_POST, 'code', 64), $ip);
				$this->setToken($result['token']);
				$this->actor = new Actor(
					$this->session['admin_uuid'],
					$this->session['role'],
					$this->session['display_name'],
				);
				if ($result['recovery_codes'] !== []) {
					$this->render('recovery', [
						'title' => 'Guarda tus códigos de recuperación',
						'codes' => $result['recovery_codes'],
						'continueUrl' => '/trust-device',
					]);
					return;
				}
				$this->redirect($this->app->trustedDevices->canOffer($this->session) ? '/trust-device' : '/admin');
				return;
			} catch (Problem $problem) {
				$error = $problem->getMessage();
				http_response_code($problem->status);
				if ($problem->status === 429) {
					header('Retry-After: 900');
				}
			}
		}
		$secret = null;
		$qr = null;
		if ($this->session['user_mfa'] === null && $this->session['enrollment_secret'] !== null) {
			$secret = $this->app->crypto->decrypt(
				$this->session['enrollment_secret'],
				'mfa:' . $this->session['admin_uuid'],
			);
			$writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd()));
			$qr =
				'data:image/svg+xml;base64,' .
				base64_encode(
					$writer->writeString($this->app->totp->provisioningUri($secret, $this->session['login'])),
				);
		}
		$this->render('mfa', [
			'title' => $secret === null ? 'Verificar acceso' : 'Protege tu cuenta',
			'secret' => $secret,
			'qr' => $qr,
			'error' => $error,
		]);
	}

	/**
	 * Operaciones del ciclo comercial de una licencia (etapa 3). GET muestra la vista previa con las
	 * fechas nuevas y el monto sugerido; POST confirma. El servicio vuelve a calcular todo.
	 */
	private function commercial(string $method, string $operationId, string $licenseId, string $operation): void
	{
		$this->actor->requireRole(['superadmin', 'operator']);
		if ($method === 'POST') {
			$result = match ($operation) {
				'renew' => $this->app->commercial->renew($this->actor, $operationId, $licenseId, $_POST),
				'convert' => $this->app->commercial->convert($this->actor, $operationId, $licenseId, $_POST),
				'plan' => $this->app->commercial->changePlan($this->actor, $operationId, $licenseId, $_POST),
				'maintenance' => $this->app->commercial->buyMaintenance($this->actor, $operationId, $licenseId, $_POST),
				'terms' => $this->app->commercial->editInitialTerms($this->actor, $operationId, $licenseId, $_POST),
				'scheduled-cancel' => $this->app->commercial->cancelScheduledChange(
					$this->actor,
					$operationId,
					$licenseId,
					$_POST,
				),
			};
			$this->redirect('/admin/licenses/' . $result['id'] . '?notice=' . $operation);
			return;
		}
		$license = $this->app->read->license($licenseId);
		if (!in_array($operation, ['renew', 'convert', 'plan', 'maintenance', 'terms'], true)) {
			throw new Problem('La página solicitada no existe.', 404);
		}
		// La conversión y el cambio de plan piden primero su destino; la renovación y el mantenimiento se
		// calculan de inmediato con sus valores por omisión (docs/PANEL.md, sección 3.4). Un dato que no se
		// puede calcular se muestra junto al formulario, con lo elegido.
		$quote = null;
		$quoteError = '';
		$needsTarget = in_array($operation, ['convert', 'plan'], true) && !isset($_GET['plan_id']);
		if (!$needsTarget) {
			try {
				$quote = match ($operation) {
					'renew' => $this->app->commercial->quoteRenewal($licenseId, $_GET),
					'convert' => $this->app->commercial->quoteConversion($licenseId, $_GET),
					'plan' => $this->app->commercial->quotePlanChange($licenseId, $_GET),
					'maintenance' => $this->app->commercial->quoteMaintenance($licenseId, $_GET),
					'terms' => ['plans' => $this->app->read->plans()],
				};
			} catch (Problem $problem) {
				if ($problem->status !== 422) {
					throw $problem;
				}
				http_response_code(422);
				$quoteError = $problem->getMessage();
			}
		}
		$titles = [
			'renew' => 'Renovar la suscripción',
			'convert' => 'Convertir la prueba',
			'plan' => 'Cambiar de plan',
			'maintenance' => 'Comprar mantenimiento',
			'terms' => 'Editar el periodo inicial',
		];
		$this->render('license-' . $operation, [
			'title' => $titles[$operation],
			'license' => $license,
			'quote' => $quote,
			'quoteError' => $quoteError,
			'plans' => $this->app->read->plans(),
		]);
	}

	/**
	 * Catálogo de planes (etapa 3, Fase 2). Cualquier cuenta lo consulta; solo el superadministrador
	 * lo edita, y el servicio vuelve a comprobarlo.
	 */
	private function plans(string $method, string $operationId): void
	{
		if ($this->path === '/admin/plans' && $method === 'GET') {
			$this->render('plans', ['title' => 'Planes', 'plans' => $this->app->read->plans(true)]);
			return;
		}
		if ($this->path === '/admin/plans/new' && $method === 'GET') {
			$this->actor->requireRole(['superadmin']);
			$this->render('plan-form', ['title' => 'Nuevo plan', 'plan' => null]);
			return;
		}
		if ($this->path === '/admin/plans/create' && $method === 'POST') {
			$result = $this->app->plans->create($this->actor, $operationId, $_POST);
			$this->redirect('/admin/plans/' . $result['id'] . '?notice=created');
			return;
		}
		if (!preg_match('~^/admin/plans/([a-z][a-z0-9_]{1,63})(/state)?$~', $this->path, $matches)) {
			throw new Problem('La página solicitada no existe.', 404);
		}
		if ($method === 'GET' && !isset($matches[2])) {
			$plan = $this->app->read->plan($matches[1]);
			$this->render('plan-form', [
				'title' => $plan['display_name'],
				'plan' => $plan,
				// Lo que alcanzaría la propagación, para el conteo previo a guardar (docs/PANEL.md, sección 3.6).
				'propagation' => $this->app->panel->propagationGroups($matches[1]),
				'examples' => $this->app->panel->propagationExamples($matches[1]),
			]);
			return;
		}
		if ($method !== 'POST') {
			throw new Problem('La página solicitada no existe.', 404);
		}
		if (isset($matches[2])) {
			$state = Input::choice($_POST, 'state', ['active', 'archived']);
			$this->app->plans->setState($this->actor, $operationId, $matches[1], $_POST);
			$this->redirect(
				'/admin/plans/' . $matches[1] . '?notice=' . ($state === 'archived' ? 'archived' : 'restored'),
			);
			return;
		}
		$result = $this->app->plans->update($this->actor, $operationId, $matches[1], $_POST);
		$this->redirect(
			'/admin/plans/' .
				$matches[1] .
				'?notice=saved&updated=' .
				(int) $result['licenses_updated'] .
				'&signed=' .
				(int) $result['revisions_signed'],
		);
	}

	/** Listas de solicitudes sin internet, alertas y auditoría. */
	private function listing(string $kind, string $title): void
	{
		$filters = [];
		foreach (['q', 'product_id', 'status', 'type'] as $key) {
			$filters[$key] = Input::text($_GET, $key, 100, false);
		}
		$operations = [];
		if ($kind === 'audit' && $filters['q'] !== '') {
			// La auditoría también se busca por el nombre en español de la operación.
			foreach (View::ACTIONS as $operation => $label) {
				if (mb_stripos($label, $filters['q']) !== false) {
					$operations[] = $operation;
				}
			}
		}
		$this->render($kind, [
			'title' => $title,
			'listing' => $this->app->read->page($kind, $filters + ['operations' => $operations], self::page()),
			'filters' => $filters,
			'products' => $kind === 'offline' ? $this->app->read->products() : [],
		]);
	}

	/** Página pedida de una lista; 1 si no viene o no es un número. */
	private static function page(): int
	{
		return isset($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page'])
			? max(1, min(1000000, (int) $_GET['page']))
			: 1;
	}

	/**
	 * Confirmación explícita de una operación sensible de una licencia (docs/PANEL.md, sección 3.3). La
	 * página exige el mismo rol o permiso que la operación; el servicio lo vuelve a comprobar al enviar.
	 */
	private function confirmation(string $licenseId, string $operation): void
	{
		match ($operation) {
			'reveal' => $this->actor->requirePermission('credentials.reveal'),
			'reissue' => $this->actor->requireRole(['superadmin', 'operator']),
			default => $this->actor->requireRole(['superadmin']),
		};
		$license = $this->app->read->license($licenseId, 1, 'computers');
		$available = match ($operation) {
			'release' => $license['active'] !== null && $license['commercial_status'] === 'issued',
			'transfer-release' => $license['pending_transfer'],
			default => $license['commercial_status'] === 'issued',
		};
		if (!$available) {
			throw new Problem('Esta operación no aplica a la licencia en su estado actual.', 409);
		}
		$titles = [
			'reveal' => 'Revelar el código de activación',
			'reissue' => 'Reemplazar la clave comercial',
			'revoke' => 'Revocar la licencia',
			'release' => 'Recuperar plaza por computadora averiada',
			'transfer-release' => 'Liberar el contador de autoservicio',
		];
		$this->render('license-confirm', [
			'title' => $titles[$operation],
			'license' => $license,
			'operation' => $operation,
		]);
	}

	private function licenseResult(array $result, string $notice = ''): void
	{
		if (isset($result['secret'])) {
			$this->render('issued', [
				'title' => 'Clave comercial generada',
				'licenseId' => $result['id'],
				'commercialKey' => $result['secret'],
			]);
			return;
		}
		$this->redirect('/admin/licenses/' . $result['id'] . ($notice === '' ? '' : '?notice=' . $notice));
	}

	private function confirmIdentity(): void
	{
		$this->app->auth->reauthenticate($this->actor, $this->password('password'), Input::text($_POST, 'code', 6));
	}
	private function download(string $bytes, string $filename, string $type): void
	{
		header('Content-Type: ' . $type);
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($bytes));
		echo $bytes;
	}
	private function password(string $field): string
	{
		$value = $_POST[$field] ?? '';
		if (!is_string($value)) {
			throw new Problem('La contraseña no es válida.');
		}
		return $value;
	}
	private function redirect(string $path): void
	{
		header('Location: ' . $path, true, 303);
	}
	private function cookieName(): string
	{
		return $this->app->config->development() ? 'adelpos_admin_dev' : '__Host-license_admin';
	}
	private function trustedToken(): ?string
	{
		$token = $_COOKIE[TrustedDevices::COOKIE] ?? null;
		return is_string($token) ? $token : null;
	}
	private function cookieOptions(int $expires = 0): array
	{
		return [
			'expires' => $expires,
			'path' => '/',
			'secure' =>
				!$this->app->config->development() || str_starts_with($this->app->config->get('APP_URL'), 'https://'),
			'httponly' => true,
			'samesite' => 'Lax',
		];
	}

	private function setToken(string $token): void
	{
		$this->token = $token;
		setcookie($this->cookieName(), $token, $this->cookieOptions());
		$this->session = $this->app->sessions->load($token);
	}

	private function render(string $template, array $data): void
	{
		// La pantalla que se muestra: sus formularios la envían para volver a ella si no se completan.
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
			View::origin($this->path . ($_GET === [] ? '' : '?' . http_build_query($_GET)));
		}
		(new View())->render(
			$template,
			$data + [
				'actor' => $this->actor,
				'csrf' => $this->token === '' ? '' : $this->app->sessions->csrf($this->token),
				'path' => $this->path,
				'now' => $this->app->clock->now(),
				// Botón de notificaciones de la barra superior, en todas las pantallas del panel.
				'alerts' => $this->actor === null ? null : $this->app->read->alertSummary(),
				// Solicitudes sin internet pendientes, con su número en la navegación.
				'pendingOffline' => $this->actor === null ? 0 : $this->app->panel->offlinePending(),
			],
		);
	}
}
