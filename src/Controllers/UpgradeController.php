<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ship;
use BNT\Models\Upgrade;
use BNT\Models\Skill;
use BNT\Core\Session;

class UpgradeController
{
    public function __construct(
        private Ship $shipModel,
        private Upgrade $upgradeModel,
        private Skill $skillModel,
        private Session $session,
        private array $config
    ) {}

    /**
     * Display ship upgrades page
     */
    public function index(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship
        $ship = $this->shipModel->getShipById($playerId);
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
        $playerId = $this->session->get('player_id');
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

        // Get engineering skill discount
        $skills = $this->skillModel->getSkills($playerId);
        $engineeringDiscount = $this->skillModel->getEngineeringDiscount($skills['engineering']);

        // Attempt upgrade
        $result = $this->upgradeModel->upgradeComponent($playerId, $component, $this->config, $engineeringDiscount);

        if ($result['success']) {
            $this->session->set('message', sprintf(
                '%s upgraded from level %d to level %d for %s credits!',
                $result['component'],
                $result['old_level'],
                $result['new_level'],
                number_format($result['cost'])
            ));

            // Award skill point every 5 upgrades
            if (($result['new_level'] % 5) == 0) {
                $this->skillModel->awardSkillPoints($playerId, 1);
            }
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
        $playerId = $this->session->get('player_id');
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
