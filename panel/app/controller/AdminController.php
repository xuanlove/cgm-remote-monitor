<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BackendMigration;
use app\service\UserService;
use NightPanel\Backends;
use NightPanel\Db;
use NightPanel\Lang;
use NightPanel\NsException;
use think\Request;
use think\Response;

/**
 * Admin surface: account list plus the Nightscout backend registry and the
 * one-click backend migration. Backend secrets arrive from POST forms and are
 * stored encrypted; a blank secret on edit keeps the stored one.
 */
class AdminController extends BaseController
{
    // ------------------------------------------------------------- user list

    public function users(): Response
    {
        return $this->renderAdmin();
    }

    public function deleteUser(Request $request): Response
    {
        UserService::delete((int)$request->post('id', 0));
        return redirect('/admin/users');
    }

    // ---------------------------------------------------------- backend CRUD

    public function addBackend(Request $request): Response
    {
        try {
            Backends::create(
                trim((string)$request->post('name', '')),
                (string)$request->post('url', ''),
                trim((string)$request->post('secret', ''))
            );
        } catch (NsException $e) {
            return $this->renderAdmin($e->getMessage());
        }
        return redirect('/admin/users');
    }

    public function saveBackend(Request $request): Response
    {
        try {
            Backends::save(
                (int)$request->post('id', 0),
                trim((string)$request->post('name', '')),
                (string)$request->post('url', ''),
                trim((string)$request->post('secret', ''))
            );
        } catch (NsException $e) {
            return $this->renderAdmin($e->getMessage());
        }
        return redirect('/admin/users');
    }

    public function toggleBackend(Request $request): Response
    {
        $id = (int)$request->post('id', 0);
        $row = Backends::find($id);
        if ($row !== null) {
            Backends::setEnabled($id, (int)$row['enabled'] !== 1);
        }
        return redirect('/admin/users');
    }

    public function deleteBackend(Request $request): Response
    {
        try {
            Backends::delete((int)$request->post('id', 0));
        } catch (NsException $e) {
            return $this->renderAdmin($e->getMessage());
        }
        return redirect('/admin/users');
    }

    public function testBackend(Request $request): Response
    {
        $row = Backends::find((int)$request->post('id', 0));
        if ($row === null) {
            return $this->renderAdmin(Lang::t('admin.backendMissing'));
        }
        try {
            $status = Backends::clientFor($row)->status();
        } catch (NsException $e) {
            return $this->renderAdmin(
                Lang::t('admin.testFailed', ['name' => (string)$row['name'], 'msg' => $e->getMessage()])
            );
        }
        return $this->renderAdmin(null, Lang::t('admin.testOk', [
            'name' => (string)$row['name'],
            'version' => (string)($status['version'] ?? '?'),
            'state' => (string)($status['runtimeState'] ?? '?'),
        ]));
    }

    // --------------------------------------------------------------- migrate

    /** Migration form: pick source/target backend and the cleanup option. */
    public function migratePage(): Response
    {
        return $this->render('admin_migrate', [
            'backends' => $this->backendsWithCounts(),
            'error' => null,
        ]);
    }

    /** Confirmation page listing the accounts that will move. */
    public function migrateConfirm(Request $request): Response
    {
        [$from, $to, $cleanup, $error] = $this->migrationTargets($request);
        if ($error !== null) {
            return $this->render('admin_migrate', [
                'backends' => $this->backendsWithCounts(),
                'error' => $error,
            ]);
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, username, created_at FROM panel_users
              WHERE backend_id = ? AND role != "admin" ORDER BY id'
        );
        $stmt->execute([(int)$from['id']]);
        $affected = $stmt->fetchAll();
        if ($affected === []) {
            return $this->render('admin_migrate', [
                'backends' => $this->backendsWithCounts(),
                'error' => Lang::t('admin.migrateEmpty'),
            ]);
        }

        return $this->render('admin_migrate_confirm', [
            'affected' => $affected,
            'from' => $from,
            'to' => $to,
            'cleanup' => $cleanup,
        ]);
    }

    /** Execute the migration and render the per-user result page. */
    public function migrateRun(Request $request): Response
    {
        [$from, $to, $cleanup, $error] = $this->migrationTargets($request);
        if ($error !== null) {
            return $this->render('admin_migrate', [
                'backends' => $this->backendsWithCounts(),
                'error' => $error,
            ]);
        }

        // Full-history copies of every account can outlast any default limit.
        set_time_limit(0);

        try {
            $results = BackendMigration::run($from, $to, $cleanup);
        } catch (NsException $e) {
            return $this->render('admin_migrate', [
                'backends' => $this->backendsWithCounts(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->render('admin_migrate_done', [
            'from' => $from,
            'to' => $to,
            'cleanup' => $cleanup,
            'results' => $results,
        ]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array{0:?array,1:?array,2:bool,3:?string} from, to, cleanup, error
     */
    private function migrationTargets(Request $request): array
    {
        $fromId = (int)$request->post('from', 0);
        $toId = (int)$request->post('to', 0);
        $cleanup = $request->post('cleanup', '') === '1';

        $from = Backends::find($fromId);
        $to = Backends::find($toId);
        if ($from === null || $to === null) {
            return [null, null, false, Lang::t('admin.migratePickBoth')];
        }
        if ($fromId === $toId) {
            return [null, null, false, Lang::t('admin.migrateSame')];
        }
        return [$from, $to, $cleanup, null];
    }

    /** @return array<int,array<string,mixed>> backend rows with user counts */
    private function backendsWithCounts(): array
    {
        $rows = [];
        foreach (Backends::all() as $row) {
            $row['user_count'] = Backends::countUsers((int)$row['id']);
            $rows[] = $row;
        }
        return $rows;
    }

    private function renderAdmin(?string $error = null, ?string $notice = null): Response
    {
        $users = Db::pdo()->query(
            'SELECT u.id, u.username, u.role, u.backend_id, u.ns_subject_id, u.created_at,
                    b.name AS backend_name
               FROM panel_users u
               LEFT JOIN panel_ns_backends b ON b.id = u.backend_id
              ORDER BY u.id'
        )->fetchAll();

        // Messages carry admin-entered names and Nightscout error bodies.
        return $this->render('admin', [
            'users' => $users,
            'backends' => $this->backendsWithCounts(),
            'error' => $error !== null ? self::esc($error) : null,
            'notice' => $notice !== null ? self::esc($notice) : null,
        ]);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
