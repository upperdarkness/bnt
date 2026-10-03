<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Services\ProtectionService;
use BNT\Services\RumourService;

/** Web actions for newbie protection, interview preferences and rumours. */
class ContentWebController
{
    public function __construct(
        private Ship $shipModel,
        private ProtectionService $protection,
        private RumourService $rumours,
        private Session $session
    ) {}

    private function ship(string $back): array
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }
        if (!$this->session->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->session->set('error', 'Invalid request');
            header("Location: $back");
            exit;
        }
        $ship = $this->shipModel->find((int)$this->session->getUserId());
        if (!$ship) {
            header('Location: /');
            exit;
        }
        return $ship;
    }

    public function optOut(): void
    {
        $ship = $this->ship('/status');
        if ($this->protection->optOut((int)$ship['ship_id'])) {
            $this->session->set('message', 'You gave up newbie protection. You can now be attacked.');
        } else {
            $this->session->set('error', 'You are not protected.');
        }
        header('Location: /status');
        exit;
    }

    public function interviews(): void
    {
        $ship = $this->ship('/status');
        $optOut = ($_POST['opt_out'] ?? '') === '1';
        $this->shipModel->getDb()->getConnection()->prepare('UPDATE ships SET interview_opt_out = :v WHERE ship_id = :id')
            ->execute(['v' => $optOut ? 't' : 'f', 'id' => (int)$ship['ship_id']]);
        $this->session->set('message', $optOut ? 'The Courier will no longer ask you for interviews.' : 'The Courier may ask you for interviews again.');
        header('Location: /status');
        exit;
    }

    public function rumourBuy(): void
    {
        $ship = $this->ship('/port');
        $result = $this->rumours->buy((int)$ship['ship_id'], (string)($_POST['tier'] ?? ''));
        if ($result['success']) {
            $this->session->set('message', 'Rumour (' . $result['rumour']['tier'] . '): ' . $result['rumour']['text']);
        } else {
            $this->session->set('error', $result['error']);
        }
        header('Location: /port');
        exit;
    }
}
