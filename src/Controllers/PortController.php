<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Skill;
use BNT\Models\ShipType;
use BNT\Models\Upgrade;

class PortController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Skill $skillModel,
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
        
        // Ensure we have the latest ship data (refresh from database)
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /main');
            exit;
        }

        $sector = $this->universeModel->getSector((int)$ship['sector']);

        if (!$sector || $sector['port_type'] === 'none') {
            $this->session->set('error', 'No port in this sector');
            header('Location: /main');
            exit;
        }

        $portType = $sector['port_type'];
        $tradingConfig = $this->config['trading'];
        $isStarbase = $this->universeModel->isStarbase((int)$ship['sector']);

        // Get trading skill bonus
        $skills = $this->skillModel->getSkills((int)$ship['ship_id']);
        $tradingBonus = $this->skillModel->getTradingBonus($skills['trading']);

        // Calculate port prices based on inventory
        $prices = $this->calculatePrices($sector, $tradingConfig, $tradingBonus, $portType);

        // Calculate ship capacity
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        // Get upgrade info if starbase
        $upgradeInfo = null;
        if ($isStarbase) {
            $upgradeModel = new Upgrade($this->universeModel->getDb());
            $upgradeInfo = $upgradeModel->getUpgradeInfo($ship, $this->config);
        }

        $session = $this->session;
        $title = 'Port - BlackNova Traders';
        $showHeader = true;
        
        // Extract variables to make them available to the view
        extract(compact('ship', 'sector', 'portType', 'prices', 'maxHolds', 'usedHolds', 'isStarbase', 'upgradeInfo', 'session', 'title', 'showHeader', 'config'));

        ob_start();
        include __DIR__ . '/../Views/port.php';
        echo ob_get_clean();
    }

    public function trade(): void
    {
        $ship = $this->requireAuth();
        
        // Refresh ship data to ensure we have the latest cargo values
        $ship = $this->shipModel->find((int)$ship['ship_id']);

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

        $portType = $sector['port_type'];

        // Validate port trading restrictions
        // When user buys, port sells - so check if port can sell
        if ($action === 'buy' && !$this->canPortSell($portType, $commodity)) {
            $this->session->set('error', ucfirst($portType) . ' ports do not sell ' . $commodity);
            header('Location: /port');
            exit;
        }

        // When user sells, port buys - so check if port can buy
        if ($action === 'sell' && !$this->canPortBuy($portType, $commodity)) {
            $this->session->set('error', ucfirst($portType) . ' ports do not buy ' . $commodity . ' (they only buy ' . implode(' and ', $this->getPortBuyCommodities($portType)) . ')');
            header('Location: /port');
            exit;
        }

        // Get trading skill bonus
        $skills = $this->skillModel->getSkills((int)$ship['ship_id']);
        $tradingBonus = $this->skillModel->getTradingBonus($skills['trading']);

        $tradingConfig = $this->config['trading'];
        $prices = $this->calculatePrices($sector, $tradingConfig, $tradingBonus, $portType);

        if ($action === 'buy') {
            $this->buyFromPort($ship, $sector, $commodity, $amount, $prices[$commodity]['buy']);
        } else {
            $this->sellToPort($ship, $sector, $commodity, $amount, $prices[$commodity]['sell']);
        }

        // Award skill points for trading (1 point per 50,000 credits traded)
        $tradeValue = $amount * ($action === 'buy' ? $prices[$commodity]['buy'] : $prices[$commodity]['sell']);
        $skillPointsEarned = (int)floor($tradeValue / 50000);
        if ($skillPointsEarned > 0) {
            $this->skillModel->awardSkillPoints((int)$ship['ship_id'], $skillPointsEarned);
        }

        // Refresh ship data after trade to ensure accurate display
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
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
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

    private function calculatePrices(array $sector, array $tradingConfig, float $tradingBonus = 0.0, string $portType = 'none'): array
    {
        $prices = [];
        $allCommodities = ['ore', 'organics', 'goods', 'energy'];

        // Get commodities this port can trade
        $canSell = $this->getPortSellCommodities($portType);
        $canBuy = $this->getPortBuyCommodities($portType);

        foreach ($allCommodities as $commodity) {
            $config = $tradingConfig[$commodity];
            $portAmount = $sector["port_$commodity"];
            $maxCapacity = $config['limit'];
            $basePrice = $config['price'];
            $priceFactor = $config['delta'];

            // Supply & Demand Formula:
            // Price = Base_Price + (Price_Factor × (Max_Capacity - Current_Stock) / Max_Capacity)
            // When stock is low (high demand): price increases
            // When stock is high (high supply): price decreases to base price
            
            $demandRatio = ($maxCapacity - $portAmount) / $maxCapacity;
            $priceAdjustment = $priceFactor * $demandRatio;
            $calculatedPrice = $basePrice + $priceAdjustment;

            // For commodities the port SELLS (user buys):
            // - High supply (full stock) = low price (good for buyer)
            // - Low supply (empty stock) = high price (bad for buyer)
            if (in_array($commodity, $canSell)) {
                $buyPrice = (int)$calculatedPrice;
                
                // Apply trading skill bonus (reduces buy price for user)
                if ($tradingBonus > 0) {
                    $buyPrice = (int)($buyPrice * (1.0 - $tradingBonus / 100));
                }
                $buyPrice = max(1, $buyPrice);
            } else {
                $buyPrice = 0;
            }

            // For commodities the port BUYS (user sells):
            // - High supply (full stock) = low price (bad for seller)
            // - Low supply (empty stock) = high price (good for seller)
            if (in_array($commodity, $canBuy)) {
                $sellPrice = (int)$calculatedPrice;
                
                // Apply trading skill bonus (increases sell price for user)
                if ($tradingBonus > 0) {
                    $sellPrice = (int)($sellPrice * (1.0 + $tradingBonus / 100));
                }
                $sellPrice = max(1, $sellPrice);
            } else {
                $sellPrice = 0;
            }

            $prices[$commodity] = [
                'buy' => $buyPrice,
                'sell' => $sellPrice,
                'stock' => $portAmount,
                'canBuy' => in_array($commodity, $canBuy),
                'canSell' => in_array($commodity, $canSell)
            ];
        }

        return $prices;
    }

    private function calculateHolds(int $level, string $shipType): int
    {
        $baseCapacity = (int)round(pow(1.5, $level) * 100);
        return ShipType::getCargoCapacity($shipType, $baseCapacity);
    }

    /**
     * Get commodities that a port can sell (the port's product type)
     */
    private function getPortSellCommodities(string $portType): array
    {
        return match ($portType) {
            'ore' => ['ore'],
            'organics' => ['organics'],
            'goods' => ['goods'],
            'energy' => ['energy'],
            default => [] // 'none' or unknown types
        };
    }

    /**
     * Get commodities that a port can buy (all other types except its own)
     */
    private function getPortBuyCommodities(string $portType): array
    {
        return match ($portType) {
            'ore' => ['organics', 'goods'],
            'organics' => ['ore', 'goods'],
            'goods' => ['ore', 'organics'],
            'energy' => ['ore', 'organics', 'goods'], // Energy ports can buy all others
            default => [] // 'none' or unknown types
        };
    }

    /**
     * Check if a port can sell a specific commodity
     */
    private function canPortSell(string $portType, string $commodity): bool
    {
        return in_array($commodity, $this->getPortSellCommodities($portType));
    }

    /**
     * Check if a port can buy a specific commodity
     */
    private function canPortBuy(string $portType, string $commodity): bool
    {
        return in_array($commodity, $this->getPortBuyCommodities($portType));
    }

    /**
     * Handle colonists loading/unloading at port
     */
    public function colonists(): void
    {
        $ship = $this->requireAuth();
        
        // Refresh ship data
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /main');
            exit;
        }

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
        $amount = max(0, (int)($_POST['amount'] ?? 0));

        if (!in_array($action, ['load', 'unload']) || $amount <= 0) {
            $this->session->set('error', 'Invalid parameters');
            header('Location: /port');
            exit;
        }

        if ($action === 'load') {
            $this->loadColonists($ship, $sector, $amount);
        } else {
            $this->unloadColonists($ship, $sector, $amount);
        }

        header('Location: /port');
        exit;
    }

    /**
     * Load colonists from port onto ship
     */
    private function loadColonists(array $ship, array $sector, int $amount): void
    {
        $portColonists = (int)($sector['port_colonists'] ?? 0);

        if ($portColonists < $amount) {
            $this->session->set('error', 'Port does not have enough colonists');
            return;
        }

        // Check cargo space
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        if ($usedHolds + $amount > $maxHolds) {
            $this->session->set('error', 'Not enough cargo space');
            return;
        }

        // Execute transfer
        $this->universeModel->update((int)$sector['sector_id'], [
            'port_colonists' => $portColonists - $amount
        ]);

        $this->shipModel->update((int)$ship['ship_id'], [
            'ship_colonists' => $ship['ship_colonists'] + $amount
        ]);

        $this->session->set('message', "Loaded $amount colonists from port");
    }

    /**
     * Unload colonists from ship to port
     */
    private function unloadColonists(array $ship, array $sector, int $amount): void
    {
        if ($ship['ship_colonists'] < $amount) {
            $this->session->set('error', 'You do not have enough colonists');
            return;
        }

        $portColonists = (int)($sector['port_colonists'] ?? 0);

        // Execute transfer
        $this->universeModel->update((int)$sector['sector_id'], [
            'port_colonists' => $portColonists + $amount
        ]);

        $this->shipModel->update((int)$ship['ship_id'], [
            'ship_colonists' => $ship['ship_colonists'] - $amount
        ]);

        $this->session->set('message', "Unloaded $amount colonists to port");
    }

    /**
     * Purchase fighters or torpedoes at starbase
     */
    public function purchase(): void
    {
        $ship = $this->requireAuth();
        
        // Refresh ship data
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /main');
            exit;
        }

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

        // Check if this is a starbase
        if (!$this->universeModel->isStarbase((int)$ship['sector'])) {
            $this->session->set('error', 'Equipment can only be purchased at starbases');
            header('Location: /port');
            exit;
        }

        $item = $_POST['item'] ?? '';
        $amount = max(0, (int)($_POST['amount'] ?? 0));

        if (!in_array($item, ['fighters', 'torpedoes']) || $amount <= 0) {
            $this->session->set('error', 'Invalid purchase parameters');
            header('Location: /port');
            exit;
        }

        $this->purchaseEquipment($ship, $item, $amount);
        header('Location: /port');
        exit;
    }

    /**
     * Purchase fighters or torpedoes
     */
    private function purchaseEquipment(array $ship, string $item, int $amount): void
    {
        $starbaseConfig = $this->config['starbase'] ?? [];
        $pricePerUnit = $item === 'fighters' 
            ? ($starbaseConfig['fighter_price'] ?? 50)
            : ($starbaseConfig['torpedo_price'] ?? 100);
        
        $totalCost = $amount * $pricePerUnit;

        if ($ship['credits'] < $totalCost) {
            $this->session->set('error', "Not enough credits. Need $totalCost credits.");
            return;
        }

        // Update ship
        $updates = ['credits' => $ship['credits'] - $totalCost];
        
        if ($item === 'fighters') {
            $updates['ship_fighters'] = $ship['ship_fighters'] + $amount;
        } else {
            $updates['torps'] = $ship['torps'] + $amount;
        }

        $this->shipModel->update((int)$ship['ship_id'], $updates);

        $itemName = $item === 'fighters' ? 'fighters' : 'torpedoes';
        $this->session->set('message', "Purchased $amount $itemName for " . number_format($totalCost) . " credits");
    }
}
