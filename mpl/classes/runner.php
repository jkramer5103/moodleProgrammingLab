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
 * HTTP client for the runner service. Only the Moodle server talks to the runner.
 *
 * @package    mod_mpl
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runner {

    /** @var string */
    protected $baseurl;

    /** @var string */
    protected $secret;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->baseurl = rtrim((string)get_config('mod_mpl', 'runnerurl'), '/');
        $this->secret = (string)get_config('mod_mpl', 'runnersecret');
    }

    /**
     * Is the runner configured?
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->baseurl !== '' && $this->secret !== '';
    }

    /**
     * Perform a request.
     *
     * @param string $method GET|POST|DELETE
     * @param string $path
     * @param array|null $body
     * @param int $timeout seconds
     * @return array decoded JSON
     * @throws \moodle_exception
     */
    public function request(string $method, string $path, ?array $body = null, int $timeout = 30): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if (!$this->is_configured()) {
            throw new \moodle_exception('runnernotconfigured', 'mpl');
        }
        // The runner lives in the internal network, so the curl security helper
        // (which blocks private addresses) must not apply here.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Authorization: Bearer ' . $this->secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $options = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_RETURNTRANSFER' => true,
        ];
        if (!get_config('mod_mpl', 'runnerverifytls')) {
            $options['CURLOPT_SSL_VERIFYPEER'] = false;
            $options['CURLOPT_SSL_VERIFYHOST'] = 0;
        }
        $url = $this->baseurl . $path;
        $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        switch ($method) {
            case 'GET':
                $response = $curl->get($url, [], $options);
                break;
            case 'DELETE':
                $response = $curl->delete($url, [], $options);
                break;
            default:
                $response = $curl->post($url, $payload, $options);
        }
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        if ($curl->get_errno()) {
            throw new \moodle_exception('runnerunreachable', 'mpl', '', $curl->error);
        }
        $data = json_decode((string)$response, true);
        if ($status >= 400 || !is_array($data)) {
            $detail = is_array($data) && isset($data['detail']) ? (string)(is_array($data['detail'])
                ? json_encode($data['detail']) : $data['detail']) : ('HTTP ' . $status);
            if ($status === 404) {
                throw new \moodle_exception('sessionnotfound', 'mpl');
            }
            throw new \moodle_exception('runnererror', 'mpl', '', $detail);
        }
        return $data;
    }

    /**
     * Evaluate code against tests.
     *
     * @param string $code
     * @param string $reference
     * @param array $tests
     * @param array $limits
     * @return array result
     */
    public function evaluate(string $code, string $reference, array $tests, array $limits): array {
        return $this->request('POST', '/v1/evaluate', [
            'code' => $code,
            'reference' => $reference,
            'tests' => $tests,
            'limits' => $limits,
        ], 330);
    }

    /**
     * Start an interactive session.
     *
     * @param string $code
     * @param int $owner user id
     * @param array $limits
     * @return string session id
     */
    public function session_start(string $code, int $owner, array $limits): string {
        $data = $this->request('POST', '/v1/sessions', ['code' => $code, 'owner' => (string)$owner,
            'limits' => $limits], 60);
        return (string)$data['id'];
    }

    /**
     * Poll a session.
     *
     * @param string $id
     * @param int $offset
     * @param float $wait seconds the runner may wait for new output
     * @return array
     */
    public function session_poll(string $id, int $offset, float $wait = 1.0): array {
        return $this->request('GET', '/v1/sessions/' . rawurlencode($id) . '?offset=' . $offset .
            '&wait=' . $wait, null, 15);
    }

    /**
     * Send input.
     *
     * @param string $id
     * @param string $data
     * @return array
     */
    public function session_input(string $id, string $data): array {
        return $this->request('POST', '/v1/sessions/' . rawurlencode($id) . '/input', ['data' => $data], 15);
    }

    /**
     * Stop a session.
     *
     * @param string $id
     * @return array
     */
    public function session_stop(string $id): array {
        return $this->request('DELETE', '/v1/sessions/' . rawurlencode($id), null, 15);
    }

    /**
     * Start a task authoring job (teacher assistant).
     *
     * @param array $messages conversation [{role: teacher|assistant, content}]
     * @param array $state current form state
     * @param string $lang
     * @return array job view
     */
    public function author_start(array $messages, array $state, string $lang): array {
        return $this->request('POST', '/v1/author', ['messages' => $messages, 'state' => $state, 'lang' => $lang], 30);
    }

    /**
     * Status of a task authoring job.
     *
     * @param string $jobid
     * @return array job view
     */
    public function author_get(string $jobid): array {
        return $this->request('GET', '/v1/author/' . rawurlencode($jobid), null, 30);
    }

    /**
     * Connection test (admin settings page).
     *
     * @return array
     */
    public function info(): array {
        return $this->request('GET', '/v1/info', null, 10);
    }
}
