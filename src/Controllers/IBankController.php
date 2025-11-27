<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ship;
use BNT\Models\IBank;
use BNT\Core\Session;

class IBankController
{
    public function __construct(
        private Ship $shipModel,
        private IBank $ibankModel,
        private Session $session,
        private array $config
    ) {}

    /**
     * Display IGB main page
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

        // Get bank account
        $account = $this->ibankModel->getAccount($playerId);
        if (!$account) {
            $this->session->set('error', 'Bank account not found');
            header('Location: /main');
            exit;
        }

        // Calculate loan limit
        $netWorth = max(10000, (int)$ship['score']);
        $loanLimit = IBank::calculateLoanLimit($netWorth, $this->config['ibank_loanlimit']);
        $availableLoan = max(0, $loanLimit - (int)$account['loan']);

        // Render view
        $this->render('ibank', [
            'ship' => $ship,
            'account' => $account,
            'netWorth' => $netWorth,
            'loanLimit' => $loanLimit,
            'availableLoan' => $availableLoan,
            'config' => $this->config,
            'session' => $this->session,
            'title' => 'Intergalactic Bank',
            'showHeader' => true
        ]);
    }

    /**
     * Process deposit
     */
    public function deposit(): void
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
            header('Location: /ibank');
            exit;
        }

        // Get amount
        $amount = (int)($_POST['amount'] ?? 0);

        // Process deposit
        $result = $this->ibankModel->deposit($playerId, $amount);

        if ($result['success']) {
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['error']);
        }

        header('Location: /ibank');
        exit;
    }

    /**
     * Process withdrawal
     */
    public function withdraw(): void
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
            header('Location: /ibank');
            exit;
        }

        // Get amount
        $amount = (int)($_POST['amount'] ?? 0);

        // Process withdrawal
        $result = $this->ibankModel->withdraw($playerId, $amount);

        if ($result['success']) {
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['error']);
        }

        header('Location: /ibank');
        exit;
    }

    /**
     * Process transfer
     */
    public function transfer(): void
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
            header('Location: /ibank');
            exit;
        }

        // Get recipient and amount
        $recipientName = trim($_POST['recipient'] ?? '');
        $amount = (int)($_POST['amount'] ?? 0);

        if (empty($recipientName)) {
            $this->session->set('error', 'Recipient name required');
            header('Location: /ibank');
            exit;
        }

        // Find recipient
        $recipient = $this->shipModel->findByName($recipientName);
        if (!$recipient) {
            $this->session->set('error', 'Player not found');
            header('Location: /ibank');
            exit;
        }

        // Process transfer
        $result = $this->ibankModel->transfer(
            $playerId,
            (int)$recipient['ship_id'],
            $amount,
            $this->config['ibank_paymentfee']
        );

        if ($result['success']) {
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['error']);
        }

        header('Location: /ibank');
        exit;
    }

    /**
     * Process loan
     */
    public function loan(): void
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
            header('Location: /ibank');
            exit;
        }

        // Get amount
        $amount = (int)($_POST['amount'] ?? 0);

        // Process loan
        $result = $this->ibankModel->takeLoan(
            $playerId,
            $amount,
            $this->config['ibank_loanfactor'],
            $this->config['ibank_loanlimit']
        );

        if ($result['success']) {
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['error']);
        }

        header('Location: /ibank');
        exit;
    }

    /**
     * Process loan repayment
     */
    public function repay(): void
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
            header('Location: /ibank');
            exit;
        }

        // Get amount
        $amount = (int)($_POST['amount'] ?? 0);

        // Process repayment
        $result = $this->ibankModel->repayLoan($playerId, $amount);

        if ($result['success']) {
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['error']);
        }

        header('Location: /ibank');
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
