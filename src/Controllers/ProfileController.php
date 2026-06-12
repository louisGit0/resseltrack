<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\User;

final class ProfileController extends Controller
{
    private User $users;

    public function __construct()
    {
        Auth::require();
        $this->users = new User();
    }

    public function index(): void
    {
        $user = $this->users->findById(Auth::id());
        if ($user === null) {
            Auth::logout();
            $this->redirect('/login');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('profile/index', [
            'user'   => $user,
            'errors' => $errors,
            'old'    => $old,
        ], 'Mon compte');
    }

    /** Update name + email. */
    public function update(): void
    {
        Csrf::validate();
        $userId = Auth::id();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Le nom est requis.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse email invalide.';
        } elseif ($this->users->emailExists($email, $userId)) {
            $errors['email'] = 'Cet email est déjà utilisé par un autre compte.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors, ['name' => $name, 'email' => $email]);
            $this->redirect('/profile');
        }

        $this->users->update($userId, $name, $email);
        $_SESSION['user_name'] = $name;
        $this->flash('success', 'Profil mis à jour.');
        $this->redirect('/profile');
    }

    /** Change password (requires the current one). */
    public function updatePassword(): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $user = $this->users->findById($userId);

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $errors = [];
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        }
        if ($new !== $confirm) {
            $errors['new_password_confirm'] = 'Les mots de passe ne correspondent pas.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors, []);
            $this->redirect('/profile');
        }

        $this->users->updatePassword($userId, password_hash($new, PASSWORD_DEFAULT));
        $this->flash('success', 'Mot de passe modifié.');
        $this->redirect('/profile');
    }
}
