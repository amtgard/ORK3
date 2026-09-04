<?php

/**
 * Scrollery — award certificates printed from ORK award records.
 *
 * Artwork and layer designs originate in Arcanyst by Valory Fox — see NOTICE.
 */
class Controller_Scrollery extends Controller
{
    public function index($action = null)
    {
    }

    /**
     * The bare print surface. Never the authority — it re-runs the permission
     * check against its own parameters, so the URL stays shareable and a future
     * PDF service can simply fetch it.
     *
     * Rendered and exited here rather than through $this->template. View() wraps
     * every template in the controller template and then the site theme — nav,
     * GTM, footer — and this page must contain nothing but certificates.
     */
    public function render($action = null)
    {
        $viewerId  = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        $awardsIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($_GET['awards'] ?? '')))
        )));

        $variant = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['variant'] ?? '')));

        $scrolls = [];
        foreach ($awardsIds as $awardsId) {
            $scroll = $this->Scrollery->scroll_for_awards_id($awardsId, $viewerId, $variant);
            if ($scroll !== null) {
                $scrolls[] = $scroll;
            }
        }

        if ($scrolls === []) {
            header('Location: ' . UIR . 'Scrollery');
            exit;
        }

        $this->render_bare('Scrollery_render.tpl', [
            'Scrolls'    => $scrolls,
            'AutoPrint'  => isset($_GET['print']),
            'PageTitle'  => count($scrolls) === 1
                ? $scrolls[0]['title'] . ' — ' . $scrolls[0]['player']
                : count($scrolls) . ' scrolls',
        ]);
    }

    /**
     * Render one revised-frontend template with no chrome around it, and stop.
     *
     * $data becomes local variables in the template's scope, so the template reads
     * $Scrolls rather than $this->__data['Scrolls'] — there is no View here to
     * carry that property.
     */
    private function render_bare(string $template, array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        (static function (string $__path, array $__data): void {
            extract($__data, EXTR_SKIP);
            include $__path;
        })(DIR_TEMPLATE . 'revised-frontend/' . $template, $data);
        exit;
    }
}
