<?php

declare(strict_types=1);

namespace LogiDock\Core;

use Throwable;

/**
 * Renderizza i template PHP dentro il layout comune.
 * I valori dinamici nei template vanno sempre passati da e() (vedi src/helpers.php).
 */
final class View
{
    /**
     * @param array<string, mixed> $shared variabili disponibili in tutti i template
     */
    public function __construct(
        private readonly string $templatesPath,
        private array $shared = []
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null          $layout nome del layout, null per renderizzare solo il template
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $variables = array_merge($this->shared, $data);
        $content = $this->renderFile($template, $variables);

        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, array_merge($variables, ['content' => $content]));
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderFile(string $template, array $variables): string
    {
        $file = $this->templatesPath . '/' . $template . '.php';

        // Funzione isolata: il template vede solo le sue variabili, non $this.
        $render = static function (string $file, array $variables): string {
            extract($variables, EXTR_SKIP);
            ob_start();
            try {
                require $file;

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $render($file, $variables);
    }
}
