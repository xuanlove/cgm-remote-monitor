<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Nightscout API client. All panel -> Nightscout traffic goes through here:
 * admin calls use the instance API_SECRET, per-user calls use that user's
 * access token.
 */
final class NsClient
{
    private string $baseUrl;
    private string $adminSecret;

    public function __construct(string $baseUrl, string $adminSecret)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->adminSecret = $adminSecret;
    }

    /**
     * Request with admin credentials. $asUserToken sends the user's access
     * token instead (used for scope-restricted profile access).
     */
    public function request(string $method, string $path, ?array $body = null, ?string $asUserToken = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($asUserToken !== null) {
            // A subject access token is accepted in the api-secret header and
            // resolves to that subject's role permissions.
            $headers[] = 'api-secret: ' . $asUserToken;
        } else {
            // Clients send the SHA-1 of the API_SECRET passphrase; a 40-hex
            // secret is assumed to already be the digest.
            $secret = preg_match('/^[a-f0-9]{40}$/', $this->adminSecret)
                ? $this->adminSecret
                : sha1($this->adminSecret);
            $headers[] = 'api-secret: ' . $secret;
        }

        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new NsException('Nightscout connection failed: ' . $error);
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new NsException('Nightscout returned invalid JSON (HTTP ' . $status . ')');
        }

        return ['status' => $status, 'body' => $decoded];
    }

    /** Throw when the API does not answer with the expected status. */
    public function requestOrThrow(string $method, string $path, ?array $body = null, ?string $asUserToken = null, array $okStatuses = [200, 201]): array
    {
        $result = $this->request($method, $path, $body, $asUserToken);
        if (!in_array($result['status'], $okStatuses, true)) {
            $detail = is_array($result['body']) ? json_encode($result['body']) : (string)$result['body'];
            throw new NsException('Nightscout API ' . $method . ' ' . $path . ' -> HTTP ' . $result['status'] . ': ' . $detail, $result['status']);
        }
        return $result['body'];
    }

    public function status(): array
    {
        return $this->requestOrThrow('GET', '/api/v1/status.json');
    }

    /** The whole profile document (admin view). */
    public function profiles(): array
    {
        $body = $this->requestOrThrow('GET', '/api/v1/profile.json');
        return is_array($body) ? $body : [];
    }

    /** Latest profile record (the one Nightscout treats as active). */
    public function currentProfileRecord(): ?array
    {
        $records = $this->profiles();
        return $records[0] ?? null;
    }

    /** Create a role. Returns the stored document. */
    public function createRole(string $name, array $permissions): array
    {
        return $this->requestOrThrow('POST', '/api/v2/authorization/roles', [
            'name' => $name,
            'permissions' => $permissions,
        ]);
    }

    /** Create a subject (idempotent); the derived accessToken comes from the list. */
    public function createSubject(string $name, array $roles): array
    {
        try {
            $this->requestOrThrow('POST', '/api/v2/authorization/subjects', [
                'name' => $name,
                'roles' => $roles,
            ]);
        } catch (NsException $e) {
            // A retry after a partial failure hits the name-already-taken 400;
            // the fetch below still finds the subject and its token.
            if (strpos($e->getMessage(), 'HTTP 400') === false) {
                throw $e;
            }
        }

        // The POST response does not carry the derived token; the list does.
        $subjects = $this->requestOrThrow('GET', '/api/v2/authorization/subjects');
        foreach ($subjects as $subject) {
            if (($subject['name'] ?? null) === $name && !empty($subject['accessToken'])) {
                $subject['_id'] = (string)$subject['_id'];
                return $subject;
            }
        }
        throw new NsException('Nightscout did not return an access token for the new subject.');
    }

    public function deleteSubject(string $subjectId): void
    {
        $this->requestOrThrow('DELETE', '/api/v2/authorization/subjects/' . rawurlencode($subjectId));
    }

    /** CGM entries for one device attribution. */
    public function entriesByDevice(string $device, int $count = 288): array
    {
        $path = '/api/v1/entries.json?count=' . $count
            . '&find[device]=' . rawurlencode($device)
            . '&find[type]=' . rawurlencode('sgv');
        $body = $this->requestOrThrow('GET', $path);
        return is_array($body) ? $body : [];
    }

    /**
     * Entries of one type for a device within a millisecond-epoch range.
     * The range query lifts the default 288-record cap so historical days
     * come back whole.
     */
    public function entriesInRange(string $device, string $type, int $startMs, int $endMs, int $count = 1500): array
    {
        $path = '/api/v1/entries.json?count=' . $count
            . '&find[device]=' . rawurlencode($device)
            . '&find[type]=' . rawurlencode($type)
            . '&find[date][$gte]=' . $startMs
            . '&find[date][$lt]=' . $endMs;
        $body = $this->requestOrThrow('GET', $path);
        return is_array($body) ? $body : [];
    }

    /** Latest device status record attributed to one device (iob/cob/battery). */
    public function latestDeviceStatus(string $device): ?array
    {
        $path = '/api/v1/devicestatus.json?count=1&find[device]=' . rawurlencode($device);
        $body = $this->requestOrThrow('GET', $path);
        $records = is_array($body) ? $body : [];
        return $records[0] ?? null;
    }

    /** Treatments entered by one user. */
    public function treatmentsByUser(string $enteredBy, int $count = 100): array
    {
        return $this->treatmentQuery('&find[enteredBy]=' . rawurlencode($enteredBy), $count);
    }

    private function treatmentQuery(string $filter, int $count): array
    {
        $path = '/api/v1/treatments.json?count=' . $count . $filter;
        $body = $this->requestOrThrow('GET', $path);
        return is_array($body) ? $body : [];
    }

    /** Write a treatment/activity record, forcing the attribution fields. */
    public function createTreatment(array $record, string $enteredBy): array
    {
        $record['enteredBy'] = $enteredBy;
        $record['created_at'] = gmdate('c');
        return $this->requestOrThrow('POST', '/api/v1/treatments.json', $record);
    }

    /** One treatment by its id, or null when it does not exist. */
    public function treatmentById(string $id): ?array
    {
        $records = $this->treatmentQuery('&find[_id]=' . rawurlencode($id), 1);
        return $records[0] ?? null;
    }

    /** Update a treatment document (whole-record PUT, as Nightscout expects). */
    public function updateTreatment(array $record): array
    {
        return $this->requestOrThrow('PUT', '/api/v1/treatments.json', $record);
    }

    public function deleteTreatment(string $id): void
    {
        $this->requestOrThrow('DELETE', '/api/v1/treatments/' . rawurlencode($id), null, null, [200, 204]);
    }

    /** Write an activity record, forcing the attribution fields. */
    public function createActivity(array $record, string $enteredBy): array
    {
        $record['enteredBy'] = $enteredBy;
        return $this->requestOrThrow('POST', '/api/v1/activity/', $record);
    }

    /** Hand-entered glucose value, attributed through the device field. */
    public function createEntry(string $device, float $sgv, ?string $timestamp = null): array
    {
        return $this->requestOrThrow('POST', '/api/v1/entries.json', [[
            'type' => 'sgv',
            'sgv' => (int)round($sgv),
            'device' => $device,
            'date' => $timestamp !== null ? strtotime($timestamp) * 1000 : round(microtime(true) * 1000),
            'dateString' => $timestamp ?? gmdate('c'),
        ]]);
    }
}

final class NsException extends \RuntimeException
{
    private ?int $httpStatus;

    public function __construct(string $message, ?int $httpStatus = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
