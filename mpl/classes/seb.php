<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


namespace mod_mpl;

/**
 * Safe Exam Browser: configuration file and Config Key.
 *
 * The Config Key is the SHA-256 of the settings as "SEB-JSON" (keys sorted case-insensitively,
 * no whitespace, no escaping), see https://safeexambrowser.org/developer/seb-config-key.html.
 * SEB sends sha256(url . configkey) in the header X-SafeExamBrowser-ConfigKeyHash (and offers
 * the same value via its JavaScript API), so Moodle can check that exactly this configuration is used.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seb {

    /**
     * Settings of the configuration generated for an activity.
     *
     * Only booleans, integers and strings are used, so the plist and the SEB-JSON stay simple.
     * All keys start with a lower-case letter: the community Linux client (seb-linux, Qt) sorts
     * keys case-sensitively when computing the Config Key, SEB itself case-insensitively; with
     * such keys both orders are the same, so both compute the same key.
     *
     * @param \moodle_url $start start page (the activity)
     * @param \moodle_url $quit URL that ends SEB
     * @param string $quitpassword optional password to quit SEB
     * @return array
     */
    public static function settings(\moodle_url $start, \moodle_url $quit, string $quitpassword = ''): array {
        $settings = [
            'startURL' => $start->out(false),
            'sendBrowserExamKey' => true,
            'browserWindowWebView' => 3,
            'examSessionClearCookiesOnStart' => false,
            'allowPreferencesWindow' => false,
            'allowQuit' => true,
            'quitURL' => $quit->out(false),
            'quitURLConfirm' => true,
            'browserWindowAllowReload' => true,
            'showReloadButton' => true,
            'showTaskBar' => true,
            'showTime' => true,
            'showInputLanguage' => true,
            'allowSpellCheck' => false,
            'allowWlan' => false,
            'audioControlEnabled' => false,
            'browserMediaCaptureCamera' => false,
            'browserMediaCaptureMicrophone' => false,
        ];
        if ($quitpassword !== '') {
            $settings['hashedQuitPassword'] = hash('sha256', $quitpassword);
        }
        return $settings;
    }

    /**
     * Settings as SEB-JSON.
     *
     * @param array $settings
     * @return string
     */
    public static function seb_json(array $settings): string {
        unset($settings['originatorVersion']);
        $settings = self::sort($settings);
        return json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sort keys case-insensitively, recursively.
     *
     * @param array $data
     * @return array
     */
    protected static function sort(array $data): array {
        if (array_is_list($data)) {
            return array_map(function($v) {
                return is_array($v) ? self::sort($v) : $v;
            }, $data);
        }
        uksort($data, 'strcasecmp');
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::sort($v);
            }
        }
        return $data;
    }

    /**
     * Config Key of the settings (64 hex characters).
     *
     * @param array $settings
     * @return string
     */
    public static function config_key(array $settings): string {
        return hash('sha256', self::seb_json($settings));
    }

    /**
     * Settings as (unencrypted) SEB configuration file (Apple plist XML).
     *
     * @param array $settings
     * @return string
     */
    public static function plist(array $settings): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n" .
            '<plist version="1.0">' . "\n" . self::plist_value(self::sort($settings), 0) . "</plist>\n";
        return $xml;
    }

    /**
     * One plist value.
     *
     * @param mixed $value
     * @param int $depth
     * @return string
     */
    protected static function plist_value($value, int $depth): string {
        $pad = str_repeat("\t", $depth);
        if (is_bool($value)) {
            return $pad . ($value ? '<true/>' : '<false/>') . "\n";
        }
        if (is_int($value)) {
            return $pad . '<integer>' . $value . "</integer>\n";
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $out = $pad . "<array>\n";
                foreach ($value as $v) {
                    $out .= self::plist_value($v, $depth + 1);
                }
                return $out . $pad . "</array>\n";
            }
            $out = $pad . "<dict>\n";
            foreach ($value as $k => $v) {
                $out .= $pad . "\t<key>" . htmlspecialchars((string)$k, ENT_XML1) . "</key>\n" .
                    self::plist_value($v, $depth + 1);
            }
            return $out . $pad . "</dict>\n";
        }
        return $pad . '<string>' . htmlspecialchars((string)$value, ENT_XML1) . "</string>\n";
    }

    /**
     * Does the user agent belong to Safe Exam Browser?
     *
     * @return bool
     */
    public static function is_seb_useragent(): bool {
        $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return (bool)preg_match('/\bSEB\b|SafeExamBrowser/i', $agent);
    }

    /**
     * Link that starts SEB with the configuration of an activity (seb:// or sebs://).
     *
     * @param int $cmid
     * @return string
     */
    public static function start_link(int $cmid): string {
        $url = (new \moodle_url('/mod/mpl/seb.php', ['id' => $cmid]))->out(false);
        return preg_replace('/^http(s?):\/\//i', 'seb$1://', $url);
    }
}
