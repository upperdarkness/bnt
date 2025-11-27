<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class IBank
{
    public function __construct(private Database $db) {}

    /**
     * Get player's bank account information
     *
     * @param int $shipId Player's ship ID
     * @return array|null Account data or null if not found
     */
    public function getAccount(int $shipId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM ibank_accounts WHERE ship_id = :ship_id',
            ['ship_id' => $shipId]
        );
    }

    /**
     * Deposit credits from ship to bank account
     *
     * @param int $shipId Player's ship ID
     * @param int $amount Amount to deposit
     * @return array Result with success/error message
     */
    public function deposit(int $shipId, int $amount): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid deposit amount'];
        }

        // Get ship credits
        $ship = $this->db->fetch(
            'SELECT credits FROM ships WHERE ship_id = :ship_id',
            ['ship_id' => $shipId]
        );

        if (!$ship) {
            return ['success' => false, 'error' => 'Ship not found'];
        }

        if ($ship['credits'] < $amount) {
            return ['success' => false, 'error' => 'Insufficient credits on ship'];
        }

        // Perform transaction
        $this->db->beginTransaction();

        try {
            // Deduct from ship
            $this->db->execute(
                'UPDATE ships SET credits = credits - :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $shipId]
            );

            // Add to bank
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance + :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $shipId]
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => sprintf('Deposited %s credits to your bank account', number_format($amount))
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Transaction failed'];
        }
    }

    /**
     * Withdraw credits from bank to ship
     *
     * @param int $shipId Player's ship ID
     * @param int $amount Amount to withdraw
     * @return array Result with success/error message
     */
    public function withdraw(int $shipId, int $amount): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid withdrawal amount'];
        }

        // Get bank account
        $account = $this->getAccount($shipId);

        if (!$account) {
            return ['success' => false, 'error' => 'Account not found'];
        }

        if ($account['balance'] < $amount) {
            return ['success' => false, 'error' => 'Insufficient bank balance'];
        }

        // Perform transaction
        $this->db->beginTransaction();

        try {
            // Deduct from bank
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance - :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $shipId]
            );

            // Add to ship
            $this->db->execute(
                'UPDATE ships SET credits = credits + :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $shipId]
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => sprintf('Withdrawn %s credits from your bank account', number_format($amount))
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Transaction failed'];
        }
    }

    /**
     * Transfer credits to another player
     *
     * @param int $fromShipId Sender's ship ID
     * @param int $toShipId Recipient's ship ID
     * @param int $amount Amount to transfer
     * @param float $paymentFee Payment fee percentage (0.05 = 5%)
     * @return array Result with success/error message
     */
    public function transfer(int $fromShipId, int $toShipId, int $amount, float $paymentFee): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid transfer amount'];
        }

        if ($fromShipId === $toShipId) {
            return ['success' => false, 'error' => 'Cannot transfer to yourself'];
        }

        // Calculate fee and total
        $fee = (int)round($amount * $paymentFee);
        $total = $amount + $fee;

        // Get sender's account
        $fromAccount = $this->getAccount($fromShipId);
        if (!$fromAccount || $fromAccount['balance'] < $total) {
            return ['success' => false, 'error' => 'Insufficient bank balance (including fee)'];
        }

        // Get recipient's account
        $toAccount = $this->getAccount($toShipId);
        if (!$toAccount) {
            return ['success' => false, 'error' => 'Recipient account not found'];
        }

        // Get recipient name
        $recipient = $this->db->fetch(
            'SELECT character_name FROM ships WHERE ship_id = :ship_id',
            ['ship_id' => $toShipId]
        );

        // Perform transaction
        $this->db->beginTransaction();

        try {
            // Deduct from sender (amount + fee)
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance - :total WHERE ship_id = :ship_id',
                ['total' => $total, 'ship_id' => $fromShipId]
            );

            // Add to recipient (amount only, fee goes to the bank)
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance + :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $toShipId]
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => sprintf(
                    'Transferred %s credits to %s (fee: %s credits)',
                    number_format($amount),
                    $recipient['character_name'],
                    number_format($fee)
                )
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Transfer failed'];
        }
    }

    /**
     * Take out a loan
     *
     * @param int $shipId Player's ship ID
     * @param int $amount Loan amount
     * @param float $loanFactor One-time loan fee (0.10 = 10%)
     * @param float $loanLimit Maximum loan as percentage of net worth
     * @return array Result with success/error message
     */
    public function takeLoan(int $shipId, int $amount, float $loanFactor, float $loanLimit): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid loan amount'];
        }

        // Get account
        $account = $this->getAccount($shipId);
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found'];
        }

        // Calculate net worth (simplified - you may want to expand this)
        $ship = $this->db->fetch(
            'SELECT credits, score FROM ships WHERE ship_id = :ship_id',
            ['ship_id' => $shipId]
        );

        $netWorth = max(10000, (int)$ship['score']); // Use score as proxy for net worth
        $maxLoan = (int)($netWorth * $loanLimit);

        if ($amount > $maxLoan) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Loan amount exceeds maximum (max: %s credits based on net worth)',
                    number_format($maxLoan)
                )
            ];
        }

        // Calculate fee
        $fee = (int)round($amount * $loanFactor);
        $netLoan = $amount - $fee;

        // Perform transaction
        $this->db->beginTransaction();

        try {
            // Add loan to account
            $this->db->execute(
                'UPDATE ibank_accounts SET loan = loan + :amount WHERE ship_id = :ship_id',
                ['amount' => $amount, 'ship_id' => $shipId]
            );

            // Add net amount to balance (after fee)
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance + :net WHERE ship_id = :ship_id',
                ['net' => $netLoan, 'ship_id' => $shipId]
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => sprintf(
                    'Loan approved! Received %s credits (fee: %s credits, total debt: %s credits)',
                    number_format($netLoan),
                    number_format($fee),
                    number_format($amount)
                )
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Loan failed'];
        }
    }

    /**
     * Repay a loan
     *
     * @param int $shipId Player's ship ID
     * @param int $amount Amount to repay
     * @return array Result with success/error message
     */
    public function repayLoan(int $shipId, int $amount): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid repayment amount'];
        }

        // Get account
        $account = $this->getAccount($shipId);
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found'];
        }

        if ($account['loan'] <= 0) {
            return ['success' => false, 'error' => 'No outstanding loan'];
        }

        // Cap repayment at loan amount
        $actualAmount = min($amount, (int)$account['loan']);

        if ($account['balance'] < $actualAmount) {
            return ['success' => false, 'error' => 'Insufficient bank balance'];
        }

        // Perform transaction
        $this->db->beginTransaction();

        try {
            // Deduct from balance
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance - :amount WHERE ship_id = :ship_id',
                ['amount' => $actualAmount, 'ship_id' => $shipId]
            );

            // Reduce loan
            $this->db->execute(
                'UPDATE ibank_accounts SET loan = loan - :amount WHERE ship_id = :ship_id',
                ['amount' => $actualAmount, 'ship_id' => $shipId]
            );

            $this->db->commit();

            $remainingLoan = $account['loan'] - $actualAmount;

            return [
                'success' => true,
                'message' => sprintf(
                    'Repaid %s credits (remaining loan: %s credits)',
                    number_format($actualAmount),
                    number_format(max(0, $remainingLoan))
                )
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Repayment failed'];
        }
    }

    /**
     * Calculate loan payment limit based on net worth
     *
     * @param int $netWorth Player's net worth
     * @param float $loanLimit Loan limit percentage
     * @return int Maximum loan amount
     */
    public static function calculateLoanLimit(int $netWorth, float $loanLimit): int
    {
        return (int)($netWorth * $loanLimit);
    }
}
