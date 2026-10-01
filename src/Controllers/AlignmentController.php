<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Services\AlignmentService;
use BNT\Services\BountyService;

/** Player-facing alignment page: tier, exact number (own ship only), Wanted status, history. */
class AlignmentController
{
    public function __construct(
        private Ship $shipModel,
        private AlignmentService $alignment,
        private BountyService $bounties,
        private Session $session,
        private array $config
    ) {}

    public function show(): void
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }
        $ship = $this->shipModel->find($this->session->getUserId());
        if (!$ship) {
            header('Location: /');
            exit;
        }
        $db = $this->shipModel->getDb();
        $recent = $db->fetchAll(
            'SELECT a.delta, a.new_value, a.reason, a.created_at, s.character_name AS related_name
             FROM alignment_log a LEFT JOIN ships s ON s.ship_id = a.related_ship_id
             WHERE a.ship_id = :id ORDER BY a.created_at DESC, a.id DESC LIMIT 25',
            ['id' => (int)$ship['ship_id']]
        );
        $alignmentService = $this->alignment;
        $rules = $this->alignment->rules();
        $wanted = $this->alignment->isWanted($ship);
        $openBounty = $this->bounties->openTotal((int)$ship['ship_id']);
        $fine = $this->alignment->quoteFine($ship);
        $session = $this->session;
        $title = 'Alignment - BlackNova Traders';
        $showHeader = true;
        $config = $this->config;

        extract(compact('ship', 'recent', 'alignmentService', 'rules', 'wanted', 'openBounty', 'fine', 'session', 'title', 'showHeader', 'config'));
        ob_start();
        include __DIR__ . '/../Views/alignment.php';
        $content = ob_get_clean();
        include __DIR__ . '/../Views/layout.php';
    }

    /** POST /alignment/bounty: fund a bounty from your IGB balance (non-refundable). */
    public function placeBounty(): void
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }
        if (!$this->session->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->session->set('error', 'Invalid request');
            header('Location: /alignment');
            exit;
        }
        $target = $this->shipModel->findByName(trim((string)($_POST['target'] ?? '')));
        if (!$target) {
            $this->session->set('error', 'Player not found');
            header('Location: /alignment');
            exit;
        }
        $result = $this->bounties->place((int)$this->session->getUserId(), (int)$target['ship_id'], (int)($_POST['amount'] ?? 0));
        if ($result['success']) {
            $this->session->set('message', 'Bounty of ' . number_format($result['amount']) . ' credits placed on ' . $target['character_name'] . '.');
        } else {
            $this->session->set('error', $result['error']);
        }
        header('Location: /alignment');
        exit;
    }
}
