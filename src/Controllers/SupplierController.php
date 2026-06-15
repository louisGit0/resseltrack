<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\Supplier;

final class SupplierController extends Controller
{
    private Supplier $suppliers;

    public function __construct()
    {
        Auth::require();
        $this->suppliers = new Supplier();
    }

    public function index(): void
    {
        $this->view('suppliers/index', [
            'suppliers' => $this->suppliers->allForUser(Auth::id()),
        ], 'Fournisseurs');
    }

    public function create(): void
    {
        [$errors, $old] = $this->pullErrors();
        $this->view('suppliers/form', [
            'supplier' => null,
            'errors'   => $errors,
            'old'      => $old,
        ], 'Nouveau fournisseur');
    }

    public function store(): void
    {
        Csrf::validate();
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/suppliers/create');
        }
        $this->suppliers->create(Auth::id(), $data);
        $this->flash('success', 'Fournisseur créé.');
        $this->redirect('/suppliers');
    }

    public function edit(array $params): void
    {
        $supplier = $this->suppliers->find((int) $params['id'], Auth::id());
        if ($supplier === null) {
            $this->flash('danger', 'Fournisseur introuvable.');
            $this->redirect('/suppliers');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('suppliers/form', [
            'supplier' => $supplier,
            'errors'   => $errors,
            'old'      => $old,
        ], 'Modifier le fournisseur');
    }

    public function update(array $params): void
    {
        Csrf::validate();
        $id = (int) $params['id'];
        if ($this->suppliers->find($id, Auth::id()) === null) {
            $this->flash('danger', 'Fournisseur introuvable.');
            $this->redirect('/suppliers');
        }
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/suppliers/' . $id . '/edit');
        }
        $this->suppliers->update($id, Auth::id(), $data);
        $this->flash('success', 'Fournisseur mis à jour.');
        $this->redirect('/suppliers');
    }

    public function destroy(array $params): void
    {
        Csrf::validate();
        $this->suppliers->delete((int) $params['id'], Auth::id());
        $this->flash('success', 'Fournisseur supprimé. Les commandes liées conservent leur nom de fournisseur.');
        $this->redirect('/suppliers');
    }

    /** @return array<string,mixed>|null */
    private function validate(): ?array
    {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $url     = trim((string) ($_POST['url'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $rawRating = trim((string) ($_POST['rating'] ?? ''));
        $rating    = $rawRating === '' ? null : (int) $rawRating;

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Le nom du fournisseur est requis.';
        }
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $errors['rating'] = 'La note doit être comprise entre 1 et 5.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors, [
                'name' => $name, 'url' => $url, 'rating' => $rawRating, 'comment' => $comment,
            ]);
            return null;
        }

        return ['name' => $name, 'url' => $url, 'rating' => $rating, 'comment' => $comment];
    }
}
