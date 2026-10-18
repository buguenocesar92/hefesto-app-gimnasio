<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;

/**
 * Verificador de identidad contra Authentik.
 *
 * POR QUÉ NO HAY CONTRASEÑAS EN ESTE SISTEMA
 * -----------------------------------------
 * Las contraseñas se roban y no se pueden recuperar. Un gimnasio que pierde
 * la contraseña de un socio pierde también la confianza de ese socio, así
 * que la identidad vive en Authentik y acá solo se verifica el token que
 * vuelve.
 *
 * LO QUE ESTÁ VERIFICADO Y LO QUE NO
 * ----------------------------------
 * Verificado contra la documentación oficial del estándar OpenID Connect
 * (descubrimiento del proveedor y claim `jwks_uri`) y contra la estructura
 * del JWKS:
 *  - el descubrimiento en `/.well-known/openid-configuration`;
 *  - las llaves públicas del `jwks_uri` que ese documento declara;
 *  - los claims estándar `iss`, `aud`, `exp`, `iat` y `sub`.
 *
 * PENDIENTE DE VERIFICAR CONTRA UN AUTHENTIK REAL (ver docs/05 y docs/10):
 *  - que el claim `groups` venga en el token. En Authentik el alcance de los
 *    grupos es configurable por proveedor y por aplicación, así que hay que
 *    revisar en la consola que el campo esté mapeado. Si no viene, este
 *    servicio devuelve un conjunto vacío y el usuario queda con el rol más
 *    bajo, que es el comportamiento seguro.
 *  - la rotación de llaves: el código vuelve a leer el JWKS cuando la firma
 *    no valida, que es lo correcto, pero no se pudo probar contra un Authentik
 *    real en este entorno (docs/10, sección 3).
 *
 * SOBRE EL CAMPO DE LLAVE PÚBLICA
 * -------------------------------
 * Authentik entrega llaves RSA con el certificado X.509 en `x5c`, que es lo
 * normal. Ese camino está implementado. El caso `n` + `e` sin certificado
 * NO se implementa a mano: armar un PEM desde el módulo y el exponente es
 * código criptográfico hecho a mano, y ese es exactamente el tipo de código
 * que no debería existir. Si algún día aparece una llave así, el sistema
 * falla con un mensaje claro en vez de devolver algo medio implementado.
 */
class VerificadorIdToken
{
    public function __construct(
        private readonly string $emisor,
        private readonly string $clientId,
        private readonly string $algoritmo = 'RS256'
    ) {}

    /**
     * Verifica el token y devuelve la identidad normalizada.
     *
     * @param  list<array<string, mixed>>  $llaves
     * @return array{sub: string, correo: string, correo_verificado: bool, nombre: ?string, grupos: list<string>}
     */
    public function verificar(string $token, array $llaves): array
    {
        try {
            // JWT::decode valida la firma, `exp` y `nbf`. `iss` y `aud` se
            // comprueban acá, porque la librería deja esos dos a criterio de
            // quien la usa, y son los que impiden que un token emitido para
            // otra aplicación se acepte en esta.
            $claims = JWT::decode($token, $this->llavesUtilizables($llaves));
        } catch (SignatureInvalidException $error) {
            throw new RuntimeException(
                'La firma del token no es válida. Puede que Authentik haya rotado las llaves.',
                previous: $error
            );
        } catch (ExpiredException $error) {
            throw new RuntimeException('El token venció. Vuelva a entrar.', previous: $error);
        } catch (BeforeValidException $error) {
            throw new RuntimeException('El token todavía no es válido.', previous: $error);
        }

        $this->comprobarEmisor((string) ($claims->iss ?? ''));
        $this->comprobarAudiencia($claims->aud ?? null);

        $sub = (string) ($claims->sub ?? '');

        if ($sub === '') {
            throw new RuntimeException('Authentik no entregó el identificador del usuario (sub).');
        }

        $correo = strtolower(trim((string) ($claims->email ?? '')));

        if ($correo === '') {
            throw new RuntimeException(
                'El alcance "email" es obligatorio en el proveedor de Authentik, o el usuario no tiene correo.'
            );
        }

        return [
            'sub' => $sub,
            'correo' => $correo,
            'correo_verificado' => (bool) ($claims->email_verified ?? false),
            'nombre' => isset($claims->name) ? (string) $claims->name : null,
            'grupos' => $this->grupos($claims),
        ];
    }

    /**
     * Descarga el JWKS declarado por el documento de descubrimiento.
     *
     * @return list<array<string, mixed>>
     */
    public function llavesPublicas(): array
    {
        $descubrimiento = $this->descubrimiento();
        $jwks = $descubrimiento['jwks_uri'] ?? null;

        if (! is_string($jwks) || $jwks === '') {
            throw new RuntimeException(
                'El documento de descubrimiento de Authentik no trae `jwks_uri`. Revise la URL del emisor.'
            );
        }

        $respuesta = @file_get_contents($jwks);

        if ($respuesta === false) {
            throw new RuntimeException('No se pudo descargar el conjunto de llaves de Authentik en ' . $jwks);
        }

        $decodificado = json_decode($respuesta, true);

        if (! is_array($decodificado) || ! isset($decodificado['keys']) || ! is_array($decodificado['keys'])) {
            throw new RuntimeException('El JWKS de Authentik tiene un formato que no se reconoce.');
        }

        return array_values($decodificado['keys']);
    }

    /**
     * Documento de descubrimiento del proveedor (OpenID Connect Discovery).
     *
     * @return array<string, mixed>
     */
    public function descubrimiento(): array
    {
        $url = rtrim($this->emisor, '/') . '/.well-known/openid-configuration';
        $respuesta = @file_get_contents($url);

        if ($respuesta === false) {
            throw new RuntimeException('No se pudo contactar a Authentik en ' . $url);
        }

        $decodificado = json_decode($respuesta, true);

        if (! is_array($decodificado)) {
            throw new RuntimeException('El documento de descubrimiento de Authentik no es JSON válido.');
        }

        return $decodificado;
    }

    /**
     * Traduce el JWKS a las llaves que la librería puede verificar.
     *
     * @param  list<array<string, mixed>>  $llaves
     * @return list<Key>
     */
    private function llavesUtilizables(array $llaves): array
    {
        $claves = [];

        foreach ($llaves as $llave) {
            $kid = (string) ($llave['kid'] ?? '');
            $certificado = $this->certificadoDe($llave);

            if ($certificado === null) {
                continue;
            }

            $claves[] = new Key($certificado, $this->algoritmo, $kid);
        }

        if ($claves === []) {
            throw new RuntimeException(
                'Ninguna llave del JWKS de Authentik trae un certificado utilizable. '
                . 'Se esperaba una llave RSA con el campo x5c, que es lo que entrega Authentik por defecto.'
            );
        }

        return $claves;
    }

    /**
     * @param  array<string, mixed>  $llave
     */
    private function certificadoDe(array $llave): ?string
    {
        $x5c = $llave['x5c'] ?? null;

        if (! is_array($x5c) || $x5c === [] || ! is_string($x5c[0]) || $x5c[0] === '') {
            return null;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($x5c[0], 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private function comprobarEmisor(string $emisor): void
    {
        if ($emisor === '' || ! hash_equals(rtrim($this->emisor, '/'), rtrim($emisor, '/'))) {
            throw new RuntimeException(
                'El token viene de ' . $emisor . ' y este sistema espera ' . $this->emisor . '.'
            );
        }
    }

    private function comprobarAudiencia(mixed $audiencia): void
    {
        $recibida = is_array($audiencia) ? $audiencia : [$audiencia];
        $coincide = array_intersect(
            [$this->clientId],
            array_map(static fn (mixed $valor): string => (string) $valor, $recibida)
        );

        if ($coincide === []) {
            throw new RuntimeException('El token no fue emitido para esta aplicación.');
        }
    }

    /**
     * @return list<string>
     */
    private function grupos(object $claims): array
    {
        $grupos = $claims->groups ?? [];

        if (is_string($grupos)) {
            $grupos = [$grupos];
        }

        if (! is_array($grupos)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $grupos)));
    }
}