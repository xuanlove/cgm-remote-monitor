<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\ProfileService;
use NightPanel\Auth;
use NightPanel\Backends;
use NightPanel\Mirror;
use think\Request;
use think\Response;

/**
 * Nightscout-compatible API, served from the panel.
 *
 * Reads come from the local mirror; writes are proxied to the user's
 * Nightscout backend with the attribution forced server-side (device /
 * enteredBy = username — the same scope the backend enforces for the user's
 * token) and the mirror updated from the created records. Every response is
 * a bare JSON array (or object for status/profile current) shaped like the
 * Nightscout v1 endpoints, newest record first.
 */
class NsApiController extends BaseController
{
    /** Ceiling shared by every endpoint; Nightscout caps its own reads too. */
    private const MAX_COUNT = 1000;

    /** Nightscout's own batch ceiling for write payloads (write-payload.js). */
    private const MAX_BATCH = 10000;

    private const VERSION = '1.0';

    // ----------------------------------------------------------- write paths

    /**
     * Upload entries: CGM readings, calibrations, anything Nightscout
     * stores in its entries collection. Every record is attributed to the
     * caller before it leaves the panel.
     */
    public function postEntries(Request $request): Response
    {
        $user = PanelContext::requireUser();
        [$documents, $error] = $this->documentsFromBody($request);
        if ($error !== null) {
            return $error;
        }
        $single = count($documents) === 1;
        // Resolved once: a 10000-record batch must not query the profile per row.
        $tz = ProfileService::timezone($user);

        foreach ($documents as &$record) {
            $record['device'] = (string)$user['username'];
            if (!isset($record['date']) && isset($record['dateString'])) {
                // Uploaders normally send `date` (ms epoch) and only use
                // dateString for display. When it is the sole timestamp, a
                // zoneless value belongs to the user's profile timezone, not
                // whatever the PHP host's clock is set to.
                try {
                    $record['date'] = (new \DateTimeImmutable((string)$record['dateString'], $tz))->getTimestamp() * 1000;
                } catch (\Throwable $unparsable) {
                    // leave date unset: the backend rejects what it cannot date
                }
            }
        }
        unset($record);

        // Historical Nightscout no-op: a single object without any date is
        // accepted and answered with an empty array, nothing stored.
        if ($single && !isset($documents[0]['date'])) {
            return $this->jsonOut([]);
        }

        $created = $this->normalizeCreated(
            Backends::clientFor((int)($user['backend_id'] ?? 0))->bulkPostEntries($documents)
        );
        foreach ($created as $record) {
            Mirror::storeApiEntry((int)$user['id'], $record);
        }
        return $this->jsonOut($created);
    }

    /**
     * Upload treatments: boluses, carbs, notes — any eventType the backend
     * accepts. Attribution and created_at are forced server-side.
     */
    public function postTreatments(Request $request): Response
    {
        $user = PanelContext::requireUser();
        [$documents, $error] = $this->documentsFromBody($request);
        if ($error !== null) {
            return $error;
        }

        foreach ($documents as &$record) {
            $record['enteredBy'] = (string)$user['username'];
            if (!isset($record['created_at'])) {
                $record['created_at'] = gmdate('c');
            }
        }
        unset($record);

        $created = $this->normalizeCreated(
            Backends::clientFor((int)($user['backend_id'] ?? 0))->bulkPostTreatments($documents)
        );
        foreach ($created as $record) {
            Mirror::storeTreatment((int)$user['id'], $record);
        }
        return $this->jsonOut($created);
    }

    /** Upload device status records (iob/cob/battery and uploader state). */
    public function postDeviceStatus(Request $request): Response
    {
        $user = PanelContext::requireUser();
        [$documents, $error] = $this->documentsFromBody($request);
        if ($error !== null) {
            return $error;
        }

        foreach ($documents as &$record) {
            $record['device'] = (string)$user['username'];
        }
        unset($record);

        $created = $this->normalizeCreated(
            Backends::clientFor((int)($user['backend_id'] ?? 0))->bulkPostDeviceStatus($documents)
        );
        foreach ($created as $record) {
            Mirror::storeDeviceStatus((int)$user['id'], $record);
        }
        return $this->jsonOut($created);
    }

    /**
     * Update the caller's profile store entry. The body goes to the backend
     * with the caller's own token, so the backend's scoped-merge semantics
     * apply: only this user's store key changes, everyone else's survives.
     * The mirror snapshot refreshes afterwards.
     */
    public function putProfile(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $body = json_decode((string)$request->getContent(), true);
        if (!is_array($body) || !isset($body['store']) || !is_array($body['store'])) {
            return $this->badRequest('profile body expects a store object');
        }
        $username = (string)$user['username'];
        if (!isset($body['store'][$username])) {
            return $this->badRequest('profile store must contain your own entry');
        }
        // Defensive projection: the backend strips other users' keys for
        // scoped tokens anyway, but the panel never forwards them.
        $body['store'] = [$username => $body['store'][$username]];
        if (!isset($body['_id'])) {
            $record = ProfileService::recordLive($user);
            if ($record === null) {
                return $this->badRequest('no profile record exists yet on the backend');
            }
            $body['_id'] = (string)$record['_id'];
        }

        $created = Backends::clientFor((int)($user['backend_id'] ?? 0))->requestOrThrow(
            'PUT',
            '/api/v1/profile.json',
            $body,
            Auth::userToken($user)
        );
        ProfileService::refreshSnapshot($user);
        return $this->jsonOut($created);
    }

    // ------------------------------------------------------------- endpoints

    public function entries(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $count = $this->countParam($request, 10);
        if ($count === 0) {
            return $this->jsonOut([]);
        }

        [$find, $error] = $this->findFilters($request, [
            'type' => 'text',
            'date' => 'ms',
            'device' => 'text',
        ]);
        if ($error !== null) {
            return $error;
        }

        $type = null;
        if ($find['type'] !== null) {
            $wanted = array_values(array_intersect(
                $find['type']['in'] ?? [$find['type']['eq'] ?? ''],
                ['sgv', 'mbg']
            ));
            if ($wanted === []) {
                // Types the mirror never stores (calibrations, for one): an
                // empty collection is what the backend answers when nothing
                // matches, and it keeps a polling client working.
                return $this->jsonOut([]);
            }
            // Both types wanted is the same as no filter at all.
            $type = count($wanted) === 1 ? $wanted[0] : null;
        }

        $records = Mirror::apiEntries(
            (int)$user['id'],
            $type,
            $find['date']['gte'] ?? null,
            $find['date']['lt'] ?? null,
            $count
        );
        if ($find['device'] !== null) {
            $records = $this->filterDevices($records, $find['device']);
        }

        return $this->serveCollection($request, $records, static fn(array $e): ?int => isset($e['date']) && is_numeric($e['date'])
            ? (int)$e['date']
            : (isset($e['mills']) && is_numeric($e['mills']) ? (int)$e['mills'] : null));
    }

    public function treatments(Request $request): Response
    {
        $user = PanelContext::requireUser();

        [$find, $error] = $this->findFilters($request, ['created_at' => 'iso']);
        if ($error !== null) {
            return $error;
        }

        // Nightscout's own default: 100, raised when the caller filters.
        $count = $this->countParam($request, $request->get('find') !== null ? 1000 : 100);
        if ($count === 0) {
            return $this->jsonOut([]);
        }

        $records = Mirror::apiTreatments(
            (int)$user['id'],
            $find['created_at']['gte'] ?? null,
            $find['created_at']['lt'] ?? null,
            $count
        );

        return $this->serveCollection($request, $records, static fn(array $t): ?int => ($ts = strtotime((string)($t['created_at'] ?? ''))) !== false
            ? $ts * 1000
            : null);
    }

    /** The mirror keeps only the newest devicestatus record per account. */
    public function devicestatus(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $count = $this->countParam($request, 10);
        if ($count === 0) {
            return $this->jsonOut([]);
        }
        $latest = Mirror::latestDeviceStatus((int)$user['id']);
        return $this->jsonOut($latest === null ? [] : [$latest]);
    }

    /**
     * Scoped projection of the backend's profile records: the caller only
     * ever sees their own store entry, mirroring what the backend returns to
     * a token with api:profile:<name>:read (projectRecords there).
     */
    public function profile(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $count = $this->countParam($request, 10);
        $records = array_slice($this->projectedProfiles($user), 0, $count);
        return $this->jsonOut($records);
    }

    /** /profile/current answers the newest projected record as one object. */
    public function profileCurrent(): Response
    {
        $user = PanelContext::requireUser();
        $records = $this->projectedProfiles($user);
        return $this->jsonOut($records[0] ?? new \stdClass());
    }

    public function status(): Response
    {
        $nowMs = (int)round(microtime(true) * 1000);
        return $this->jsonOut([
            'status' => 'ok',
            'name' => 'NightPanel',
            'version' => self::VERSION,
            'serverTime' => gmdate('c', intdiv($nowMs, 1000)),
            'serverTimeEpoch' => $nowMs,
            'apiEnabled' => true,
            'careportalEnabled' => false,
            'boluscalcEnabled' => false,
            'settings' => ['units' => 'mg/dl'],
            'extendedSettings' => new \stdClass(),
            'authorized' => new \stdClass(),
            'runtimeState' => 'loaded',
        ]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Parse a write payload the way the Nightscout fork's write-payload
     * normalizer does: a single object or an array of objects, capped at
     * MAX_BATCH. Errors answer in the fork's exact 400 shapes.
     *
     * @return array{0:?array<int,array<string,mixed>>,1:?Response}
     */
    private function documentsFromBody(Request $request): array
    {
        $body = json_decode((string)$request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, $this->badRequest('Invalid JSON body')];
        }
        $documents = is_array($body) && !array_is_list($body) ? [$body] : $body;
        if (!is_array($documents)) {
            return [null, $this->badRequest('Expected an object or array of objects', 'Invalid entries payload')];
        }
        if (count($documents) > self::MAX_BATCH) {
            return [null, $this->badRequest('Maximum records per request: ' . self::MAX_BATCH, 'Too many entries')];
        }
        foreach ($documents as $document) {
            if (!is_array($document) || array_is_list($document)) {
                return [null, $this->badRequest('Expected an object or array of objects', 'Invalid entries payload')];
            }
        }
        return [$documents, null];
    }

    /**
     * Nightscout answers writes with the created record(s) — a single object
     * or an array depending on endpoint; the panel mirrors every created
     * record, so normalize to a list first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeCreated(mixed $body): array
    {
        if (is_array($body) && array_is_list($body)) {
            return $body;
        }
        return is_array($body) ? [$body] : [];
    }

    /**
     * The backend's profile records with every store key but the caller's
     * own stripped out.
     *
     * @param array<string,mixed> $user
     * @return array<int,array<string,mixed>>
     */
    private function projectedProfiles(array $user): array
    {
        $username = (string)$user['username'];
        $out = [];
        foreach (Mirror::profiles((int)($user['backend_id'] ?? 0)) as $record) {
            if (!isset($record['store'][$username])) {
                continue;
            }
            $record['store'] = [$username => $record['store'][$username]];
            // The active default of the shared document belongs to someone
            // else's store key; from here it is the viewer's entry.
            if (($record['defaultProfile'] ?? '') !== $username) {
                $record['defaultProfile'] = $username;
            }
            $out[] = $record;
        }
        return $out;
    }

    /**
     * Nightscout count semantics: absent/invalid → the endpoint default,
     * 0 → an empty answer, above the cap → the cap.
     */
    private function countParam(Request $request, int $default): int
    {
        $raw = $request->get('count');
        if ($raw === null || (string)$raw === '') {
            return $default;
        }
        if (!ctype_digit((string)$raw)) {
            return $default;
        }
        return max(0, min((int)$raw, self::MAX_COUNT));
    }

    /**
     * Parse the whitelisted find[] subset. Unknown filters are refused with
     * 400 — the Nightscout query guards fail closed on unknown operators
     * too, and refusing beats silently ignoring a caller's typo.
     *
     * @param array<string,string> $spec field => kind ('ms'|'iso'|'text')
     * @return array{0:array<string,mixed>,1:?Response} parsed values (null when absent) and a 400 response on invalid input
     */
    private function findFilters(Request $request, array $spec): array
    {
        $out = array_fill_keys(array_keys($spec), null);
        $find = $request->get('find');
        if ($find === null) {
            return [$out, null];
        }
        if (!is_array($find)) {
            return [$out, $this->badRequest('find must be a set of filters')];
        }
        foreach ($find as $field => $filter) {
            if (!isset($spec[$field])) {
                return [$out, $this->badRequest('unsupported find filter: ' . (string)$field)];
            }
            $parsed = match ($spec[$field]) {
                'ms' => $this->rangeFilter($filter, static fn($v) => ctype_digit((string)$v) ? (int)$v : null),
                'iso' => $this->rangeFilter($filter, static fn($v) => ($ts = strtotime((string)$v)) !== false ? gmdate('c', $ts) : null),
                'text' => $this->textFilter($filter),
            };
            if ($parsed === null) {
                return [$out, $this->badRequest('unsupported operator in find[' . (string)$field . ']')];
            }
            $out[$field] = $parsed;
        }
        return [$out, null];
    }

    /**
     * A date filter carries $gte/$lt operators with parseable values; a bare
     * equality is not offered for dates.
     *
     * @return array{gte:?string,lt:?string}|null
     */
    private function rangeFilter(mixed $filter, \Closure $parse): ?array
    {
        if (!is_array($filter)) {
            return null;
        }
        $out = ['gte' => null, 'lt' => null];
        foreach ($filter as $op => $value) {
            $bound = null;
            if ($op === '$gte' && $out['gte'] === null) {
                $bound = 'gte';
            } elseif ($op === '$lt' && $out['lt'] === null) {
                $bound = 'lt';
            } else {
                return null; // repeated or unsupported operator
            }
            $parsed = $parse($value);
            if ($parsed === null) {
                return null;
            }
            $out[$bound] = $parsed;
        }
        return $out;
    }

    /**
     * A text filter matches equality ($eq or a bare value) or a set ($in).
     *
     * @return array{eq?:string,in?:array<int,string>}|null
     */
    private function textFilter(mixed $filter): ?array
    {
        if (is_array($filter)) {
            if (isset($filter['$eq']) && is_scalar($filter['$eq'])) {
                return ['eq' => (string)$filter['$eq']];
            }
            if (isset($filter['$in']) && is_array($filter['$in'])) {
                $in = [];
                foreach ($filter['$in'] as $value) {
                    if (!is_scalar($value)) {
                        return null;
                    }
                    $in[] = (string)$value;
                }
                return ['in' => $in];
            }
            return null;
        }
        return is_scalar($filter) ? ['eq' => (string)$filter] : null;
    }

    /**
     * Nightscout scoped semantics: an explicit device filter intersects with
     * what the caller may see. The mirror already holds only their records,
     * so a non-matching device simply answers fewer of them.
     *
     * @param array<int,array<string,mixed>> $records
     * @param array{eq?:string,in?:array<int,string>} $device
     * @return array<int,array<string,mixed>>
     */
    private function filterDevices(array $records, array $device): array
    {
        $wanted = isset($device['in']) ? $device['in'] : [$device['eq']];
        return array_values(array_filter($records, static fn(array $record): bool =>
            in_array((string)($record['device'] ?? ''), $wanted, true)));
    }

    /**
     * Serve an array payload with the Nightscout conditional-request
     * semantics: Last-Modified from the newest record, 304 when
     * If-Modified-Since is not older than it (seconds comparison, <=).
     *
     * @param array<int,array<string,mixed>> $records newest-first
     * @param \Closure(array<string,mixed>):?int $timestampOfMs ms epoch per record
     */
    private function serveCollection(Request $request, array $records, \Closure $timestampOfMs): Response
    {
        $lastMs = null;
        foreach ($records as $record) {
            $ms = $timestampOfMs($record);
            if ($ms !== null && $ms > 0) {
                $lastMs = $ms;
                break; // records arrive newest-first
            }
        }

        if ($lastMs !== null) {
            $ifMod = (string)$request->header('If-Modified-Since', '');
            if ($ifMod !== '') {
                $since = strtotime($ifMod);
                if ($since !== false && (int)floor($lastMs / 1000) <= $since) {
                    return $this->notModified($lastMs);
                }
            }
        }

        $response = $this->jsonOut($records);
        return $lastMs === null ? $response : $this->withLastModified($response, $lastMs);
    }

    private function notModified(int $lastMs): Response
    {
        return $this->withLastModified(
            $this->jsonOut(['status' => 304, 'message' => 'Not modified', 'type' => 'internal'], 304),
            $lastMs
        );
    }

    private function withLastModified(Response $response, int $ms): Response
    {
        $response->header(['Last-Modified' => gmdate('D, d M Y H:i:s', (int)floor($ms / 1000)) . ' GMT']);
        return $response;
    }

    /** 400 in the Nightscout error shape (sendJSONStatus: message + description). */
    private function badRequest(string $description, string $message = 'Bad Request'): Response
    {
        return $this->jsonOut(['status' => 400, 'message' => $message, 'description' => $description], 400);
    }
}
