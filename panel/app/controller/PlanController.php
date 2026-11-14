<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\ProfileService;
use NightPanel\NsException;
use think\Request;
use think\Response;

class PlanController extends BaseController
{
    public function index(): Response
    {
        $user = PanelContext::requireUser();

        return $this->render('settings', [
            'tab' => 'plans',
            'plans' => ProfileService::plans($user),
            'error' => null,
            'success' => null,
        ]);
    }

    public function activate(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $error = $this->run(
            static fn() => ProfileService::activate($user, (string)$request->post('_id', '')),
            'msg.enableFailed'
        );

        if ($error === null) {
            return redirect('/profile/plans');
        }

        return $this->render('settings', [
            'tab' => 'plans',
            'plans' => ProfileService::plans($user),
            'error' => $error,
            'success' => null,
        ]);
    }

    public function delete(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $error = $this->run(
            static fn() => ProfileService::deletePlan($user, (string)$request->post('_id', '')),
            'msg.deleteFailed'
        );

        if ($error === null) {
            return redirect('/profile/plans');
        }

        return $this->render('settings', [
            'tab' => 'plans',
            'plans' => ProfileService::plans($user),
            'error' => $error,
            'success' => null,
        ]);
    }

    /** Run a plan mutation, mapping both failure kinds to a display message. */
    private function run(callable $action, string $failureKey): ?string
    {
        try {
            $action();
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (NsException $e) {
            return t($failureKey, ['msg' => $e->getMessage()]);
        }
    }
}
