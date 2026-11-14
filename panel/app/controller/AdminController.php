<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\UserService;
use NightPanel\Db;
use NightPanel\NsConfig;
use NightPanel\NsException;
use think\Request;
use think\Response;

class AdminController extends BaseController
{
    public function users(): Response
    {
        return $this->renderAdmin();
    }

    public function deleteUser(Request $request): Response
    {
        UserService::delete((int)$request->post('id', 0));
        return redirect('/admin/users');
    }

    public function saveSettings(Request $request): Response
    {
        // Blank means "keep the current value": the form is an override form,
        // so a blank field must never wipe a working connection.
        $error = NsConfig::save(
            (string)$request->post('ns_url', ''),
            (string)$request->post('ns_secret', '')
        );

        if ($error !== null) {
            return $this->renderAdmin($error);
        }

        return redirect('/admin/users');
    }

    private function renderAdmin(?string $settingsError = null): Response
    {
        $users = Db::pdo()
            ->query('SELECT id, username, role, ns_subject_id, created_at FROM panel_users ORDER BY id')
            ->fetchAll();

        $nsStatus = null;
        $nsError = null;
        try {
            $nsStatus = NsConfig::client()->status();
        } catch (NsException $e) {
            $nsError = $e->getMessage();
        }

        return $this->render('admin', [
            'users' => $users,
            'nsStatus' => $nsStatus,
            'nsError' => $nsError,
            'settingsError' => $settingsError,
            'configSource' => NsConfig::source(),
        ]);
    }
}
