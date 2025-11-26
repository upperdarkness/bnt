<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;

class PortController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Session $session,
        private array $config
    ) {}

    private function requireAuth(): ?array
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $shipId = $this->session->getUserId();
        $ship = $this->shipModel->find($shipId);

        if (!$ship) {
            $this->session->logout();
            header('Location: /');
            exit;
        }

        return $ship;
    }

    public function show(): void
    {
        $ship = $this->requireAuth();

        $sector = $this->universeModel->getSector((int)$ship['sector']);

        if (!$sector || $sector['port_type'] === 'none') {
            $this->session->set('error', 'No port in this sector');
            header('Location: /main');
            exit;
        }

        $portType = $sector['port_type'];
        $tradingConfig = $this->config['trading'];

        // Calculate port prices based on inventory
        $prices = $this->calculatePrices($sector, $tradingConfig);

        // Calculate ship capacity
        $maxHolds = $this->calculateHolds($ship['hull']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        $data = compact('ship', 'sector', 'portType', 'prices', 'maxHolds', 'usedHolds');

        ob_start();
        include __DIR__ . '/../Views/port.php';
        echo ob_get_clean();
    }

    public function trade(): void
    {
        $ship = $this->requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /port');
            exit;
        }

        // Verify CSRF token
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /port');
            exit;
        }

        $sector = $this->universeModel->getSector((int)$ship['sector']);
        if (!$sector || $sector['port_type'] === 'none') {
            $this->session->set('error', 'No port in this sector');
            header('Location: /main');
            exit;
        }

        $action = $_POST['action'] ?? '';
        $commodity = $_POST['commodity'] ?? '';
        $amount = max(0, (int)($_POST['amount'] ?? 0));

        if (!in_array($action, ['buy', 'sell']) || !in_array($commodity, ['ore', 'organics', 'goods', 'energy'])) {
            $this->session->set('error', 'Invalid trade parameters');
            header('Location: /port');
            exit;
        }

        $tradingConfig = $this->config['trading'];
        $prices = $this->calculatePrices($sector, $tradingConfig);

        if ($action === 'buy') {
            $this->buyFromPort($ship, $sector, $commodity, $amount, $prices[$commodity]['buy']);
        } else {
            $this->sellToPort($ship, $sector, $commodity, $amount, $prices[$commodity]['sell']);
        }

        header('Location: /port');
        exit;
    }

    private function buyFromPort(array $ship, array $sector, string $commodity, int $amount, int $price): void
    {
        $portColumn = "port_$commodity";
        $shipColumn = "ship_$commodity";

        if ($sector[$portColumn] < $amount) {
            $this->session->set('error', 'Port does not have enough ' . $commodity);
            return;
        }

        $cost = $amount * $price;
        if ($ship['credits'] < $cost) {
            $this->session->set('error', 'Not enough credits');
            return;
        }

        // Check cargo space
        $maxHolds = $this->calculateHolds($ship['hull']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        if ($usedHolds + $amount > $maxHolds) {
            $this->session->set('error', 'Not enough cargo space');
            return;
        }

        // Execute trade
        $this->universeModel->update((int)$sector['sector_id'], [
            $portColumn => $sector[$portColumn] - $amount
        ]);

        $this->shipModel->update((int)$ship['ship_id'], [
            'credits' => $ship['credits'] - $cost,
            $shipColumn => $ship[$shipColumn] + $amount
        ]);

        $this->session->set('message', "Bought $amount $commodity for $cost credits");
    }

    private function sellToPort(array $ship, array $sector, string $commodity, int $amount, int $price): void
    {
        $portColumn = "port_$commodity";
        $shipColumn = "ship_$commodity";

        if ($ship[$shipColumn] < $amount) {
            $this->session->set('error', 'You do not have enough ' . $commodity);
            return;
        }

        $earnings = $amount * $price;

        // Execute trade
        $this->universeModel->update((int)$sector['sector_id'], [
            $portColumn => $sector[$portColumn] + $amount
        ]);

        $this->shipModel->update((int)$ship['ship_id'], [
            'credits' => $ship['credits'] + $earnings,
            $shipColumn => $ship[$shipColumn] - $amount
        ]);

        $this->session->set('message', "Sold $amount $commodity for $earnings credits");
    }

    private function calculatePrices(array $sector, array $tradingConfig): array
    {
        $prices = [];

        foreach (['ore', 'organics', 'goods', 'energy'] as $commodity) {
            $config = $tradingConfig[$commodity];
            $portAmount = $sector["port_$commodity"];

            // Port sells high when low stock, buys low when high stock
            $sellPrice = $config['price'] + (int)(($config['rate'] - $portAmount) / $config['rate'] * $config['delta']);
            $buyPrice = $config['price'] - (int)(($portAmount - $config['rate']) / $config['rate'] * $config['delta']);

            $prices[$commodity] = [
                'buy' => max(1, $sellPrice),
                'sell' => max(1, $buyPrice),
                'stock' => $portAmount
            ];
        }

        return $prices;
    }

    private function calculateHolds(int $level): int
    {
        return (int)round(pow(1.5, $level) * 100);
    }
}
