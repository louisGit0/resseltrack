<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base controller: view rendering (wrapped in the layout), redirects,
 * JSON responses, and simple flash/old-input helpers stored in the session.
 */
abstract class Controller
{
    /** Render a view inside the main layout. */
    protected function view(string $view, array $data = [], ?string $title = null): void
    {
        $content = $this->renderPartial($view, $data);

        $layoutData = [
            'content'    => $content,
            'title'      => $title ?? 'ResellTrack',
            'flash'      => $this->pullFlash(),
            'authName'   => Auth::name(),
            'authCheck'  => Auth::check(),
        ];
        echo $this->renderPartial('layout', $layoutData);
    }

    /** Render a view file to a string without the layout. */
    protected function renderPartial(string $view, array $data = []): string
    {
        $file = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    protected function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Flash messages -------------------------------------------------
    protected function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type:string, message:string}> */
    protected function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    // ---- Old input (re-populate forms after a validation error) --------
    protected function flashErrors(array $errors, array $old): void
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = $old;
    }

    /** @return array{0: array<string,string>, 1: array<string,mixed>} */
    protected function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);
        return [$errors, $old];
    }
}
