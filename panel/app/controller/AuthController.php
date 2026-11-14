<?php
declare(strict_types=1);

namespace app\controller;

use app\service\UserService;
use NightPanel\Auth;
use think\Request;
use think\Response;

class AuthController extends BaseController
{
    public function loginPage(): Response
    {
        return $this->render('login', ['error' => null]);
    }

    public function login(Request $request): Response
    {
        if ($request->middleware('throttled') === 'login') {
            return $this->render('login', ['error' => t('login.tooMany')]);
        }

        $username = trim((string)$request->post('username', ''));
        $user = UserService::find($username);

        if ($user === null || !password_verify((string)$request->post('password', ''), (string)$user['password_hash'])) {
            return $this->render('login', ['error' => t('login.invalid')]);
        }

        Auth::login($user);
        return redirect('/dashboard');
    }

    /**
     * Logout is POST + CSRF: a bare GET from another site must not be able to
     * sign the user out. Any other method answers 405 with an Allow header.
     */
    public function logout(Request $request): Response
    {
        if (!$request->isPost()) {
            return response('', 405)->header(['Allow' => 'POST']);
        }

        Auth::logout();
        return redirect('/login');
    }

    public function registerPage(): Response
    {
        return $this->render('register', ['error' => null, 'success' => null, 'info' => null]);
    }

    public function register(Request $request): Response
    {
        if ($request->middleware('throttled') === 'register') {
            return $this->render('register', [
                'error' => t('register.tooMany'),
                'success' => null,
                'info' => null,
            ]);
        }

        [$error, $info] = UserService::register(
            (string)$request->post('username', ''),
            (string)$request->post('password', '')
        );

        if ($error !== null) {
            return $this->render('register', ['error' => $error, 'success' => null, 'info' => null]);
        }

        return $this->render('register', ['error' => null, 'success' => true, 'info' => $info]);
    }
}
