<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\TreatmentService;
use NightPanel\Mirror;
use NightPanel\NsException;
use NightPanel\TreatmentTypes;
use think\Request;
use think\Response;

class TreatmentController extends BaseController
{
    public function index(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $editing = null;

        $editId = (string)$request->get('edit', '');
        if ($editId !== '') {
            $existing = TreatmentService::find($user, $editId);
            // Ownership is decided here, never by what the request claims.
            if ($existing !== null && TreatmentService::owned($existing, $user)) {
                $editing = $existing;
            }
        }

        return $this->render('treatments', $this->vars($user, $editing, null, null));
    }

    public function save(Request $request): Response
    {
        $user = PanelContext::requireUser();

        // Live read: sending back a snapshot could revert fields that changed
        // elsewhere since the last sync.
        $existing = TreatmentService::findLive($user, (string)$request->post('_id', ''));

        if ($existing === null || !TreatmentService::owned($existing, $user)) {
            return $this->render('treatments', $this->vars($user, null, t('val.notOwner'), null), 403);
        }

        try {
            TreatmentService::save($user, $request->post(), $existing);
        } catch (\InvalidArgumentException $e) {
            return $this->render('treatments', $this->vars($user, $existing, $e->getMessage(), null));
        } catch (NsException $e) {
            return $this->render('treatments', $this->vars(
                $user,
                $existing,
                t('msg.saveFailed', ['msg' => $e->getMessage()]),
                null
            ));
        }

        return redirect('/treatments');
    }

    public function delete(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $id = (string)$request->post('_id', '');

        $existing = TreatmentService::findLive($user, $id);
        if ($existing !== null) {
            TreatmentService::delete($user, $id, $existing);
        }

        return redirect('/treatments');
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed>|null $editing
     */
    private function vars(array $user, ?array $editing, ?string $error, ?string $success): array
    {
        return [
            'types' => TreatmentTypes::types(),
            'fields' => TreatmentTypes::fields(),
            'typesJson' => TreatmentService::typesJson(),
            'fieldsJson' => TreatmentService::fieldsJson(),
            'recordJson' => js_json($editing ?? new \stdClass()),
            'rows' => TreatmentService::displayRows(Mirror::recentTreatments((int)$user['id'], 100)),
            'editing' => $editing,
            'error' => $error,
            'success' => $success,
        ];
    }
}
