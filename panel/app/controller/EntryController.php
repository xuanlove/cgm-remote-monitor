<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\TreatmentService;
use NightPanel\NsException;
use NightPanel\TreatmentTypes;
use think\Request;
use think\Response;

class EntryController extends BaseController
{
    public function show(Request $request): Response
    {
        $preset = (string)$request->get('type', '');

        return $this->render('entry', $this->vars(null, null, $preset));
    }

    public function submit(Request $request): Response
    {
        $user = PanelContext::requireUser();

        try {
            TreatmentService::submit($user, $request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->render('entry', $this->vars($e->getMessage(), null, (string)$request->post('eventType', '')));
        } catch (NsException $e) {
            return $this->render('entry', $this->vars(
                t('msg.submitFailed', ['msg' => $e->getMessage()]),
                null,
                (string)$request->post('eventType', '')
            ));
        }

        return $this->render('entry', $this->vars(null, t('msg.dataSubmitted'), ''));
    }

    /**
     * Template variables shared by the GET and POST paths.
     *
     * The registry is translated once here and injected as JSON so the page's
     * script can build the dynamic form without a second round trip.
     */
    private function vars(?string $error, ?string $success, string $preset): array
    {
        $user = PanelContext::requireUser();

        return [
            'types' => TreatmentTypes::types(),
            'fields' => TreatmentTypes::fields(),
            'typesJson' => TreatmentService::typesJson(),
            'fieldsJson' => TreatmentService::fieldsJson(),
            'groupsJson' => TreatmentService::groupsJson(),
            'presetJson' => js_json($preset),
            'usernameJson' => js_json((string)$user['username']),
            'error' => $error,
            'success' => $success,
            'preset' => $preset,
        ];
    }
}
