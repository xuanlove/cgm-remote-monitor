<?php
declare(strict_types=1);

namespace app\controller;

use think\Response;
use think\facade\View;

/**
 * Shared response helpers.
 *
 * Every controller returns a Response: the old front controller echoed and
 * exited, which would bypass ThinkPHP's middleware and header handling.
 */
abstract class BaseController
{
    /**
     * @param array<string,mixed> $vars
     */
    protected function render(string $template, array $vars = [], int $status = 200): Response
    {
        // A leading slash pins the template to view/ instead of letting the
        // engine prepend the controller directory (view/auth/login.html).
        return response(View::fetch('/' . $template, $vars), $status);
    }

    /** JSON with the panel's historical encoding flags. */
    protected function jsonOut($data, int $status = 200): Response
    {
        return json($data, $status, [], [
            'json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ]);
    }

    /** A download response: CSV with a BOM, or JSON, always as an attachment. */
    protected function attachment(string $body, string $filename, string $contentType): Response
    {
        return response($body)
            ->header([
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
    }
}
