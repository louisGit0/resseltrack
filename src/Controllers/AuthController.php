<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('auth/login', ['errors' => $errors, 'old' => $old], 'Connexion');
    }

    public function login(): void
    {
        Csrf::validate();

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->flashErrors(['email' => 'Email et mot de passe requis.'], ['email' => $email]);
            $this->redirect('/login');
        }

        // Brute-force guard: block after repeated failures for this email+IP.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $limiter = new RateLimiter();
        $wait = $limiter->minutesUntilRetry($email, $ip);
        if ($wait > 0) {
            $this->flashErrors(
                ['email' => "Trop de tentatives. Réessayez dans {$wait} minute" . ($wait > 1 ? 's' : '') . '.'],
                ['email' => $email]
            );
            $this->redirect('/login');
        }

        if (!Auth::attempt($email, $password)) {
            $limiter->recordFailure($email, $ip);
            $this->flashErrors(['email' => 'Identifiants invalides.'], ['email' => $email]);
            $this->redirect('/login');
        }

        $limiter->reset($email, $ip);

        $this->flash('success', 'Connexion réussie. Bienvenue !');
        $this->redirect('/dashboard');
    }

    public function showRegister(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('auth/register', ['errors' => $errors, 'old' => $old], 'Inscription');
    }

    public function register(): void
    {
        Csrf::validate();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Le nom est requis.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse email invalide.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Le mot de passe doit faire au moins 8 caractères.';
        }
        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Les mots de passe ne correspondent pas.';
        }

        $userModel = new User();
        if (!isset($errors['email']) && $userModel->emailExists($email)) {
            $errors['email'] = 'Cet email est déjà utilisé.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors, ['name' => $name, 'email' => $email]);
            $this->redirect('/register');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $userModel->create($email, $hash, $name);

        Auth::login($userId, $name);
        $this->flash('success', 'Compte créé. Bienvenue sur ResellTrack !');
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        Csrf::validate();
        Auth::logout();
        $this->redirect('/login');
    }
}
