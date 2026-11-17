<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Comprueba datos contra los archivos formales del contrato: contracts/v1/license.schema.json
 * y contracts/v1/openapi.yaml. Solo se usa en pruebas; el servidor valida con su propio código.
 */
final class ContractSchemas
{
	/** Identificador base con el que se resuelven las referencias entre los dos archivos. */
	private const BASE = 'https://licensing.sicrat.com/contracts/v1/';

	private static ?Validator $validator = null;
	private static ?array $openapi = null;

	public static function directory(): string
	{
		return dirname(__DIR__) . '/contracts/v1';
	}

	/** Los archivos de prueba compartidos, tal como los publica el generador. */
	public static function vectors(): array
	{
		return json_decode(
			file_get_contents(self::directory() . '/fixtures/vectors.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
	}

	/** El documento OpenAPI como arreglos, para recorrerlo. */
	public static function openapi(): array
	{
		return self::$openapi ??= json_decode(self::openapiJson(), true, 512, JSON_THROW_ON_ERROR);
	}

	/** Contenido de una licencia contra license.schema.json. Devuelve null si cumple, o el motivo. */
	public static function licenseErrors(string $contentJson): ?string
	{
		return self::errors(self::BASE . 'license.schema.json', $contentJson);
	}

	/** Un JSON contra un esquema de «components.schemas» del OpenAPI. */
	public static function componentErrors(string $name, string $json): ?string
	{
		return self::errors(self::BASE . 'openapi.json#/components/schemas/' . $name, $json);
	}

	/** El cuerpo de una petición contra el esquema que el OpenAPI declara para esa ruta. */
	public static function requestErrors(string $path, string $bodyJson): ?string
	{
		$schema = self::openapi()['paths'][$path]['post']['requestBody']['content']['application/json']['schema'];
		return self::errors(self::reference($schema['$ref']), $bodyJson);
	}

	/**
	 * El cuerpo de una respuesta contra el esquema que el OpenAPI declara para esa ruta y ese
	 * código HTTP. Un error con un HTTP que la ruta no enumera (los de transporte) se compara
	 * con el formato general de error.
	 */
	public static function responseErrors(string $path, int $status, string $bodyJson): ?string
	{
		$responses = self::openapi()['paths'][$path]['post']['responses'] ?? [];
		$response = $responses[(string) $status] ?? null;
		if ($response === null) {
			return $status >= 400
				? self::componentErrors('Error', $bodyJson)
				: 'El OpenAPI no documenta la respuesta ' . $status . ' de ' . $path;
		}
		if (isset($response['$ref'])) {
			$name = substr($response['$ref'], strlen('#/components/responses/'));
			$response = self::openapi()['components']['responses'][$name];
		}
		return self::errors(self::reference($response['content']['application/json']['schema']['$ref']), $bodyJson);
	}

	/** Campos obligatorios de la respuesta que el OpenAPI declara para esa ruta y ese código HTTP. */
	public static function responseFields(string $path, int $status): array
	{
		$response = self::openapi()['paths'][$path]['post']['responses'][(string) $status];
		if (isset($response['$ref'])) {
			$name = substr($response['$ref'], strlen('#/components/responses/'));
			$response = self::openapi()['components']['responses'][$name];
		}
		$schema = basename($response['content']['application/json']['schema']['$ref']);
		return self::openapi()['components']['schemas'][$schema]['required'];
	}

	/** Convierte una referencia interna del OpenAPI («#/components/…») en una absoluta. */
	private static function reference(string $ref): string
	{
		return self::BASE . 'openapi.json' . $ref;
	}

	private static function errors(string $schemaId, string $json): ?string
	{
		try {
			$data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			return 'JSON inválido: ' . $error->getMessage();
		}
		$result = self::validator()->validate($data, $schemaId);
		if ($result->isValid()) {
			return null;
		}
		$messages = (new ErrorFormatter())->format($result->error(), false);
		return json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private static function validator(): Validator
	{
		if (self::$validator === null) {
			self::$validator = new Validator();
			self::$validator->setMaxErrors(5);
			$resolver = self::$validator->resolver();
			$resolver->registerRaw(
				file_get_contents(self::directory() . '/license.schema.json'),
				self::BASE . 'license.schema.json',
			);
			$resolver->registerRaw(self::openapiJson(), self::BASE . 'openapi.json');
		}
		return self::$validator;
	}

	/** El YAML convertido a JSON, conservando los objetos vacíos como objetos. */
	private static function openapiJson(): string
	{
		$document = Yaml::parseFile(self::directory() . '/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
		return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
