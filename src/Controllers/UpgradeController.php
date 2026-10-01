<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ship;
use BNT\Models\Upgrade;
use BNT\Models\Skill;
use BNT\Core\Session;
use BNT\Services\TradeService;

class UpgradeController
{
    public function __construct(
        private Ship $shipModel,
        private Upgrade $upgradeModel,
        private Skill $skillModel,
        private Session $session,
        private array $config,
        private ?TradeService $tradeService = null
    ) {}

    /**
     * Display ship upgrades page
     */
    public function index(): void
    {
        // Require authentication
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $playerId = $this->session->getUserId();
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship
        $ship = $this->shipModel->find($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get upgrade information for all components
        $upgradeInfo = $this->upgradeModel->getUpgradeInfo($ship, $this->config);

        // Calculate total ship level
        $totalLevel = Upgrade::calculateShipLevel($ship);

        // Render view
        $this->render('upgrades', [
            'ship' => $ship,
            'upgradeInfo' => $upgradeInfo,
            'totalLevel' => $totalLevel,
            'session' => $this->session,
            'title' => 'Ship Upgrades',
            'showHeader' => true
        ]);
    }

    /**
     * Process component upgrade
     */
    public function upgrade(): void
    {
        // Require authentication
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $playerId = $this->session->getUserId();
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Validate CSRF token
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($submittedToken)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /upgrades');
            exit;
        }

        // Get component from POST
        $component = $_POST['component'] ?? '';

        if (empty($component)) {
            $this->session->set('error', 'Component not specified');
            header('Location: /upgrades');
            exit;
        }

        // The web page works anywhere (as before); the TradeService applies starbase alignment rules where they apply.
        $ship = $this->shipModel->find($playerId);
        $outcome = $this->tradeService->buyUpgrade($ship, $component, false);
        $result = $outcome['success'] ? $outcome['result'] : ($outcome['details'] ?? ['success' => false, 'error' => $outcome['error']]);
        $result['success'] = $outcome['success'];

        if ($result['success']) {
            $this->session->set('message', sprintf(
                '%s upgraded from level %d to level %d for %s credits!',
                $result['component'],
                $result['old_level'],
                $result['new_level'],
                number_format($result['cost'])
            ));

        } else {
            $this->session->set('error', $result['error'] ?? 'Upgrade failed');
        }

        header('Location: /upgrades');
        exit;
    }

    /**
     * Process component downgrade
     */
    public function downgrade(): void
    {
        // Require authentication
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $playerId = $this->session->getUserId();
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Validate CSRF token
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($submittedToken)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /upgrades');
            exit;
        }

        // Get component from POST
        $component = $_POST['component'] ?? '';

        if (empty($component)) {
            $this->session->set('error', 'Component not specified');
            header('Location: /upgrades');
            exit;
        }

        // Attempt downgrade
        $result = $this->upgradeModel->downgradeComponent($playerId, $component, $this->config);

        if ($result['success']) {
            $this->session->set('message', sprintf(
                '%s downgraded from level %d to level %d. Refunded %s credits.',
                $result['component'],
                $result['old_level'],
                $result['new_level'],
                number_format($result['refund'])
            ));
        } else {
            $this->session->set('error', $result['error'] ?? 'Downgrade failed');
        }

        header('Location: /upgrades');
        exit;
    }

    /**
     * Render a view
     */
    private function render(string $view, array $data = []): void
    {
        extract($data);
        ob_start();
        include __DIR__ . '/../Views/' . $view . '.php';
        $content = ob_get_clean();
        include __DIR__ . '/../Views/layout.php';
    }
}
