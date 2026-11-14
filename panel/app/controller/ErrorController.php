<?php
declare(strict_types=1);

namespace app\controller;

use think\Response;

class ErrorController extends BaseController
{
    /** Unmatched URL: the panel's own 404 page with a 404 status. */
    public function notFound(): Response
    {
        return $this->render('404', [], 404);
    }
}
