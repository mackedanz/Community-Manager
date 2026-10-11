<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Images\ShipImages;

/**
 * Aussehen der Installation: Logo, Hintergrundbild und Deckkraft des Hintergrunds (getrennt für dunkel und hell).
 * Gilt für die ganze Installation. Geändert wird es von Server-Admins, per Discord-Befehl /design oder in "Orga verwalten".
 * Bilder werden nur von Discord geladen bzw. hochgeladen, geprüft, neu kodiert (PNG bzw. JPEG) und unter ihrem
 * Inhalts-Hash abgelegt; ohne eigene Auswahl gelten die mitgelieferten Bilder.
 */
final class Branding
{
    public const DEFAULT_DARK = 10;
    public const DEFAULT_LIGHT = 40;
    public const DEFAULT_LOGO = '/logo.png';
    public const DEFAULT_BACKGROUND = '/img/background.jpg';
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 4000;
    public const MIN_SIDE = 64;
    public const LOGO_SIDE = 256;
    public const BACKGROUND_WIDTH = 2000;
    /** Von hier lädt der Bot Anhänge (Discord-Bildserver). */
    public const HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];
    private const FILE = '/^(logo|bg)-[a-f0-9]{16}\.(png|jpg)$/';

    /** @var array{logoUrl:string,backgroundUrl:string,logoCustom:bool,backgroundCustom:bool,opacityDark:int,opacityLight:int}|null */
    private static ?array $cache = null;

    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function dir(): string
    {
        return Config::imageDir() . '/brand';
    }

    private static function get(string $name): ?string
    {
        $v = Db::val('SELECT value FROM app_settings WHERE name = ?', [$name]);
        return is_string($v) ? $v : null;
    }

    private static function put(string $name, ?string $value): void
    {
        if ($value === null) {
            Db::run('DELETE FROM app_settings WHERE name = ?', [$name]);
            return;
        }
        Db::run('INSERT INTO app_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    }

    /** Pfad einer gespeicherten Branding-Datei (nur gültige Namen, nur vorhandene). */
    public static function path(string $file): ?string
    {
        if (preg_match(self::FILE, $file) !== 1) {
            return null;
        }
        $p = self::dir() . '/' . $file;
        return is_file($p) ? $p : null;
    }

    /** @return array{logoUrl:string,backgroundUrl:string,logoCustom:bool,backgroundCustom:bool,opacityDark:int,opacityLight:int} */
    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $r = [
            'logoUrl' => self::DEFAULT_LOGO, 'backgroundUrl' => self::DEFAULT_BACKGROUND, 'logoCustom' => false, 'backgroundCustom' => false,
            'opacityDark' => self::DEFAULT_DARK, 'opacityLight' => self::DEFAULT_LIGHT,
        ];
        try {
            $logo = self::get('brand_logo');
            if ($logo !== null && self::path($logo) !== null) {
                $r['logoUrl'] = '/brand/' . $logo;
                $r['logoCustom'] = true;
            }
            $bg = self::get('brand_background');
            if ($bg !== null && self::path($bg) !== null) {
                $r['backgroundUrl'] = '/brand/' . $bg;
                $r['backgroundCustom'] = true;
            }
            foreach (['opacityDark' => 'brand_opacity_dark', 'opacityLight' => 'brand_opacity_light'] as $k => $name) {
                $v = self::get($name);
                if ($v !== null && ctype_digit($v) && (int) $v <= 100) {
                    $r[$k] = (int) $v;
                }
            }
        } catch (\Throwable) {
            // Datenbank nicht erreichbar: die Seite soll trotzdem mit den Standardbildern erscheinen
        }
        return self::$cache = $r;
    }

    /** CSS-Variablen für das Layout: Hintergrundbild und Überblendung (Deckkraft des Bildes = 1 - Alpha der Überblendung). */
    public static function css(): string
    {
        $c = self::current();
        $alpha = static fn (int $opacity): string => number_format(1 - $opacity / 100, 2, '.', '');
        return ':root{' . ($c['backgroundCustom'] ? '--bg-image:url("' . $c['backgroundUrl'] . '");' : '')
            . '--bg-overlay:rgb(10 10 10 / ' . $alpha($c['opacityDark']) . ')}'
            . ':root[data-theme="light"]{--bg-overlay:rgb(250 250 250 / ' . $alpha($c['opacityLight']) . ')}';
    }

    /** Lädt einen Discord-Anhang (nur von Discords Bildservern). @throws OrgError */
    public static function fetch(string $url): string
    {
        $img = ShipImages::download($url, self::HOSTS);
        if ($img === null) {
            throw new OrgError('Das Bild konnte nicht von Discord geladen werden (erlaubt: PNG, JPG, WebP bis 5 MB).');
        }
        return $img['body'];
    }

    /**
     * Prüft ein Bild und kodiert es neu: Logo höchstens 256 Pixel (PNG, mit Transparenz), Hintergrund höchstens 2000 Pixel breit (JPEG).
     * @return array{body:string,ext:string}
     * @throws OrgError
     */
    private static function process(string $kind, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new OrgError('Das Bild ist größer als 5 MB.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new OrgError('Erlaubt sind PNG, JPG und WebP.');
        }
        [$w, $h] = $info;
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            throw new OrgError('Das Bild ist zu groß (höchstens ' . self::MAX_SIDE . ' × ' . self::MAX_SIDE . ' Pixel).');
        }
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            throw new OrgError('Das Bild ist zu klein (mindestens ' . self::MIN_SIDE . ' × ' . self::MIN_SIDE . ' Pixel).');
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new OrgError('Das Bild lässt sich nicht lesen.');
        }
        $scale = $kind === 'logo' ? min(1.0, self::LOGO_SIDE / max($w, $h)) : min(1.0, self::BACKGROUND_WIDTH / $w);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($kind === 'logo') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 0, 0, 0));   // Transparenz auf Schwarz
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $kind === 'logo' ? imagepng($dst, null, 9) : imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();
        return ['body' => $out, 'ext' => $kind === 'logo' ? 'png' : 'jpg'];
    }

    /** @param 'logo'|'background' $kind @throws OrgError */
    public static function setImage(string $kind, string $bytes): void
    {
        if ($kind !== 'logo' && $kind !== 'background') {
            throw new OrgError('Unbekannte Auswahl.');
        }
        $img = self::process($kind, $bytes);
        $base = ($kind === 'logo' ? 'logo' : 'bg') . '-' . substr(sha1($img['body']), 0, 16);
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new OrgError('Der Bildordner ist nicht beschreibbar.');
        }
        if (ShipImages::storeIn(self::dir(), $base, $img['body'], $img['ext']) === null) {
            throw new OrgError('Das Bild konnte nicht gespeichert werden.');
        }
        self::swap('brand_' . $kind, $base . '.' . $img['ext']);
    }

    /** Setzt die Einstellung und löscht die zuvor gespeicherte Datei. */
    private static function swap(string $setting, ?string $file): void
    {
        $old = self::get($setting);
        self::put($setting, $file);
        if ($old !== null && $old !== $file && ($p = self::path($old)) !== null) {
            @unlink($p);
        }
        self::forget();
    }

    /** @param 'logo'|'background'|'all' $what */
    public static function reset(string $what): void
    {
        if (!in_array($what, ['logo', 'background', 'all'], true)) {
            throw new OrgError('Unbekannte Auswahl.');
        }
        if ($what !== 'background') {
            self::swap('brand_logo', null);
        }
        if ($what !== 'logo') {
            self::swap('brand_background', null);
        }
        if ($what === 'all') {
            self::put('brand_opacity_dark', null);
            self::put('brand_opacity_light', null);
        }
        self::forget();
    }

    /** Deckkraft des Hintergrundbildes in Prozent, getrennt für dunkel und hell; null lässt den Wert unverändert. @throws OrgError */
    public static function setOpacity(?int $dark, ?int $light): void
    {
        foreach ([$dark, $light] as $v) {
            if ($v !== null && ($v < 0 || $v > 100)) {
                throw new OrgError('Die Deckkraft muss zwischen 0 und 100 Prozent liegen.');
            }
        }
        if ($dark !== null) {
            self::put('brand_opacity_dark', (string) $dark);
        }
        if ($light !== null) {
            self::put('brand_opacity_light', (string) $light);
        }
        self::forget();
    }
}