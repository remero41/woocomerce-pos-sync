<?php
declare(strict_types=1);
/**
 * Actualización automática desde GitHub Releases.
 *
 * WordPress solo actualiza solo lo que viene de wordpress.org. Este plugin se
 * distribuye por GitHub, así que hasta la 2.1.0 una tienda se quedaba en la
 * versión que le instalaron: para subirla había que entrar a su WordPress y
 * subir el ZIP a mano, tienda por tienda.
 *
 * Ahora el plugin se engancha al mismo sitio por donde WordPress pregunta por
 * actualizaciones (`pre_set_site_transient_update_plugins`) y le contesta con
 * el último release publicado. El comerciante ve el aviso de siempre en
 * Plugins y actualiza con un clic, sin desinstalar ni perder su configuración
 * (que vive en wp_options, no en los ficheros).
 *
 * El repo es PÚBLICO, así que la API se consulta sin token ni credenciales.
 *
 * Cuidado con el ZIP: se usa el asset del release, NUNCA el "Source code" que
 * GitHub genera solo. Ese lleva la raíz del repo con el plugin en un
 * subdirectorio y WordPress no lo instala — ya mordió el 18-09-2026.
 */

defined('ABSPATH') || exit;

class TPV_Sync_Updater
{
    /** Repo público donde se publican los releases. */
    public const REPO = 'remero41/woocomerce-pos-sync';

    /** Cuánto se cachea la respuesta de GitHub.
     *
     *  Hay caché porque sin token GitHub da 60 peticiones por hora y por IP, y
     *  en hosting compartido varias tiendas comparten IP. Pero estaba en 12h y
     *  eso dejaba una versión nueva INVISIBLE medio día (visto el 23-09-2026:
     *  2.4.0 publicada y el panel sin ofrecer nada). Una hora protege igual el
     *  límite y no hace esperar al comerciante. */
    private const CACHE_TTL   = HOUR_IN_SECONDS;
    private const CACHE_KEY   = 'tpv_sync_ultimo_release';
    private const TIMEOUT     = 8;

    private string $pluginFile;   // 'woocommerce-conector/woocommerce-conector.php'
    private string $versionActual;

    public function __construct(string $pluginFile, string $versionActual)
    {
        $this->pluginFile    = $pluginFile;
        $this->versionActual = $versionActual;
    }

    public function registrar(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'ofrecerActualizacion']);
        add_filter('plugins_api', [$this, 'ficha'], 10, 3);
        // Tras actualizar, la respuesta cacheada ya no vale.
        add_action('upgrader_process_complete', static function ($upgrader, array $hook): void {
            if (($hook['type'] ?? '') === 'plugin') { delete_transient(self::CACHE_KEY); }
        }, 10, 2);

        // Y cuando el usuario pulsa "Comprobar de nuevo" en Escritorio →
        // Actualizaciones, WordPress borra SU transient y dispara este hook.
        // Sin engancharse aquí, ese botón no servía de nada para este plugin:
        // seguíamos contestando con la respuesta guardada.
        add_action('delete_site_transient_update_plugins', static function (): void {
            delete_transient(self::CACHE_KEY);
        });
    }

    // ─── Enganches de WordPress ──────────────────────────────────────────

    /**
     * WordPress pregunta "¿hay algo que actualizar?" y aquí se le contesta.
     *
     * Devuelve $transient intacto salvo que haya una versión nueva de verdad:
     * ante cualquier duda (API caída, rate limit, release sin ZIP) se deja
     * como estaba. Una actualización inventada rompería la instalación de
     * todas las tiendas a la vez.
     */
    public function ofrecerActualizacion($transient)
    {
        if (!is_object($transient)) { return $transient; }

        $release = $this->ultimoRelease();
        if ($release === null) { return $transient; }

        $nueva = self::versionDeRelease($release);
        if ($nueva === null || !self::hayQueActualizar($this->versionActual, $nueva)) {
            return $transient;
        }

        $info = self::infoParaWp($release, $this->pluginFile);
        if ($info === null) { return $transient; }

        $transient->response[$this->pluginFile] = $info;
        return $transient;
    }

    /**
     * La ficha del "Ver detalles de la versión" del panel de plugins.
     */
    public function ficha($resultado, string $accion, $args)
    {
        if ($accion !== 'plugin_information') { return $resultado; }
        $slug = dirname($this->pluginFile);
        if (($args->slug ?? '') !== $slug) { return $resultado; }

        $release = $this->ultimoRelease();
        if ($release === null) { return $resultado; }
        $version = self::versionDeRelease($release);
        $zip     = self::zipDeRelease($release);
        if ($version === null || $zip === null) { return $resultado; }

        $ficha = new stdClass();
        $ficha->name          = 'Catinfog Conector';
        $ficha->slug          = $slug;
        $ficha->version       = $version;
        $ficha->download_link = $zip;
        $ficha->sections      = [
            'changelog' => wp_kses_post((string) ($release['body'] ?? '')),
        ];
        return $ficha;
    }

    // ─── Decisiones puras (lo que se prueba) ─────────────────────────────

    /**
     * La versión que anuncia un release. El tag puede venir como `v2.1.0` o
     * `2.1.0`; la `v` no es parte de la versión.
     */
    public static function versionDeRelease(array $release): ?string
    {
        $tag = trim((string) ($release['tag_name'] ?? ''));
        if ($tag === '') { return null; }
        return ltrim($tag, 'vV');
    }

    /**
     * ¿La publicada es más nueva que la instalada?
     *
     * version_compare(), no strcmp: como texto "2.10.0" < "2.9.0" y una
     * tienda en la 2.9 no volvería a ver una actualización nunca.
     *
     * Una versión que no parezca una versión (vacía, 'latest', basura) se
     * trata como "no hay nada": mejor no ofrecer que ofrecer humo.
     */
    public static function hayQueActualizar(string $instalada, string $publicada): bool
    {
        $publicada = trim($publicada);
        if ($publicada === '' || !preg_match('/^\d+(\.\d+)*/', $publicada)) { return false; }
        return version_compare($publicada, $instalada, '>');
    }

    /**
     * El ZIP instalable del release: el ASSET que subimos, nunca el zipball
     * automático de GitHub (raíz del repo + plugin en subcarpeta = no se
     * instala). Sin asset, null: preferimos no ofrecer actualización a
     * ofrecer un paquete que rompe la instalación.
     */
    public static function zipDeRelease(array $release): ?string
    {
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url !== '' && str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.zip')) {
                return $url;
            }
        }
        return null;
    }

    /**
     * El objeto que espera WordPress en el transient de actualizaciones.
     * null si el release no sirve (sin tag, sin ZIP, respuesta vacía).
     */
    public static function infoParaWp(array $release, string $pluginFile): ?object
    {
        $version = self::versionDeRelease($release);
        $zip     = self::zipDeRelease($release);
        if ($version === null || $zip === null) { return null; }

        $info = new stdClass();
        $info->slug        = dirname($pluginFile);
        $info->plugin      = $pluginFile;
        $info->new_version = $version;
        $info->package     = $zip;
        $info->url         = (string) ($release['html_url'] ?? '');
        $info->tested      = '';
        $info->icons       = [];
        return $info;
    }

    // ─── Acceso a GitHub ─────────────────────────────────────────────────

    /**
     * El último release, cacheado. null ante cualquier problema.
     *
     * Se cachea TAMBIÉN el fallo (con un TTL corto): si GitHub está caído o
     * se agotó el rate limit, no tiene sentido reintentar en cada carga del
     * panel de administración.
     */
    private function ultimoRelease(): ?array
    {
        $cache = get_transient(self::CACHE_KEY);
        if ($cache === 'sin-datos') { return null; }
        if (is_array($cache))       { return $cache; }

        $resp = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'catinfog-conector/' . $this->versionActual,
                ],
            ]
        );

        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient(self::CACHE_KEY, 'sin-datos', HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient(self::CACHE_KEY, 'sin-datos', HOUR_IN_SECONDS);
            return null;
        }

        set_transient(self::CACHE_KEY, $body, self::CACHE_TTL);
        return $body;
    }
}
