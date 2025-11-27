<h2>💰 Intergalactic Bank (IGB)</h2>

<div class="stat-grid" style="margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-label">Ship Credits</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format((int)$ship['credits']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Bank Balance</div>
        <div class="stat-value" style="color: #3498db;"><?= number_format((int)$account['balance']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Outstanding Loan</div>
        <div class="stat-value" style="color: <?= $account['loan'] > 0 ? '#e74c3c' : '#95a5a6' ?>;">
            <?= number_format((int)$account['loan']) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Assets</div>
        <div class="stat-value" style="color: #f39c12;">
            <?= number_format((int)$ship['credits'] + (int)$account['balance'] - (int)$account['loan']) ?>
        </div>
    </div>
</div>

<div style="margin-bottom: 25px; padding: 15px; background: rgba(52, 152, 219, 0.1); border-left: 4px solid #3498db; border-radius: 5px;">
    <h3 style="color: #3498db; margin: 0 0 10px 0;">Bank Information</h3>
    <ul style="margin: 0; padding-left: 20px; color: #e0e0e0; font-size: 14px;">
        <li style="margin-bottom: 5px;">Interest on deposits: <strong><?= ($config['ibank_interest'] * 100) ?>%</strong> per tick</li>
        <li style="margin-bottom: 5px;">Interest on loans: <strong><?= ($config['ibank_loaninterest'] * 100) ?>%</strong> per tick</li>
        <li style="margin-bottom: 5px;">Transfer fee: <strong><?= ($config['ibank_paymentfee'] * 100) ?>%</strong> of amount</li>
        <li style="margin-bottom: 5px;">Loan origination fee: <strong><?= ($config['ibank_loanfactor'] * 100) ?>%</strong> of loan amount</li>
        <li style="margin-bottom: 5px;">Maximum loan: <strong><?= ($config['ibank_loanlimit'] * 100) ?>%</strong> of net worth</li>
    </ul>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <!-- Deposit Form -->
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h3 style="color: #2ecc71; margin: 0 0 15px 0;">💵 Deposit</h3>
        <p style="color: #95a5a6; font-size: 13px; margin-bottom: 15px;">
            Transfer credits from your ship to your bank account to earn interest.
        </p>
        <form action="/ibank/deposit" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 15px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">Amount:</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= (int)$ship['credits'] ?>"
                    value=""
                    placeholder="Enter amount"
                    required
                    style="width: 100%;"
                >
                <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                    Available: <?= number_format((int)$ship['credits']) ?> CR
                </small>
            </div>
            <button type="submit" class="btn" style="width: 100%; margin: 0; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71; color: #2ecc71;">
                Deposit Credits
            </button>
        </form>
    </div>

    <!-- Withdraw Form -->
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h3 style="color: #3498db; margin: 0 0 15px 0;">💳 Withdraw</h3>
        <p style="color: #95a5a6; font-size: 13px; margin-bottom: 15px;">
            Transfer credits from your bank account to your ship.
        </p>
        <form action="/ibank/withdraw" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 15px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">Amount:</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= (int)$account['balance'] ?>"
                    value=""
                    placeholder="Enter amount"
                    required
                    style="width: 100%;"
                >
                <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                    Available: <?= number_format((int)$account['balance']) ?> CR
                </small>
            </div>
            <button type="submit" class="btn" style="width: 100%; margin: 0; background: rgba(52, 152, 219, 0.3); border-color: #3498db; color: #3498db;">
                Withdraw Credits
            </button>
        </form>
    </div>

    <!-- Transfer Form -->
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h3 style="color: #9b59b6; margin: 0 0 15px 0;">📤 Transfer</h3>
        <p style="color: #95a5a6; font-size: 13px; margin-bottom: 15px;">
            Transfer credits to another player's bank account.
        </p>
        <form action="/ibank/transfer" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 10px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">To Player:</label>
                <input
                    type="text"
                    name="recipient"
                    placeholder="Player name"
                    required
                    style="width: 100%;"
                >
            </div>
            <div style="margin-bottom: 15px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">Amount:</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= (int)$account['balance'] ?>"
                    value=""
                    placeholder="Enter amount"
                    required
                    style="width: 100%;"
                >
                <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                    Fee: <?= ($config['ibank_paymentfee'] * 100) ?>% (deducted from your balance)
                </small>
            </div>
            <button type="submit" class="btn" style="width: 100%; margin: 0; background: rgba(155, 89, 182, 0.3); border-color: #9b59b6; color: #9b59b6;">
                Transfer Credits
            </button>
        </form>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <!-- Take Loan Form -->
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(230, 126, 34, 0.5);">
        <h3 style="color: #e67e22; margin: 0 0 15px 0;">💳 Take Loan</h3>
        <p style="color: #95a5a6; font-size: 13px; margin-bottom: 15px;">
            Borrow credits from the bank. Interest accrues every tick.
        </p>
        <div style="background: rgba(230, 126, 34, 0.1); padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #95a5a6; font-size: 12px;">Your Net Worth:</span>
                <span style="color: #e0e0e0; font-size: 12px;"><?= number_format($netWorth) ?> CR</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #95a5a6; font-size: 12px;">Maximum Loan:</span>
                <span style="color: #e67e22; font-size: 12px; font-weight: bold;"><?= number_format($loanLimit) ?> CR</span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #95a5a6; font-size: 12px;">Available:</span>
                <span style="color: #2ecc71; font-size: 12px; font-weight: bold;"><?= number_format($availableLoan) ?> CR</span>
            </div>
        </div>
        <?php if ($availableLoan > 0): ?>
        <form action="/ibank/loan" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 15px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">Loan Amount:</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= $availableLoan ?>"
                    value=""
                    placeholder="Enter loan amount"
                    required
                    style="width: 100%;"
                >
                <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                    Origination fee: <?= ($config['ibank_loanfactor'] * 100) ?>% (deducted from loan)
                </small>
            </div>
            <button type="submit" class="btn" style="width: 100%; margin: 0; background: rgba(230, 126, 34, 0.3); border-color: #e67e22; color: #e67e22;">
                Request Loan
            </button>
        </form>
        <?php else: ?>
        <div class="alert alert-error" style="margin: 0;">
            You have reached your maximum loan limit.
        </div>
        <?php endif; ?>
    </div>

    <!-- Repay Loan Form -->
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(231, 76, 60, 0.5);">
        <h3 style="color: #e74c3c; margin: 0 0 15px 0;">💸 Repay Loan</h3>
        <p style="color: #95a5a6; font-size: 13px; margin-bottom: 15px;">
            Pay down your outstanding loan to reduce interest charges.
        </p>
        <?php if ($account['loan'] > 0): ?>
        <div style="background: rgba(231, 76, 60, 0.1); padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #95a5a6; font-size: 12px;">Outstanding Loan:</span>
                <span style="color: #e74c3c; font-size: 12px; font-weight: bold;"><?= number_format((int)$account['loan']) ?> CR</span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #95a5a6; font-size: 12px;">Interest Rate:</span>
                <span style="color: #e0e0e0; font-size: 12px;"><?= ($config['ibank_loaninterest'] * 100) ?>% per tick</span>
            </div>
        </div>
        <form action="/ibank/repay" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 15px;">
                <label style="color: #95a5a6; font-size: 13px; display: block; margin-bottom: 5px;">Repayment Amount:</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    max="<?= min((int)$account['balance'], (int)$account['loan']) ?>"
                    value=""
                    placeholder="Enter amount"
                    required
                    style="width: 100%;"
                >
                <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                    Max: <?= number_format(min((int)$account['balance'], (int)$account['loan'])) ?> CR
                </small>
            </div>
            <button type="submit" class="btn" style="width: 100%; margin: 0; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c; color: #e74c3c;">
                Repay Loan
            </button>
        </form>
        <?php else: ?>
        <div class="alert alert-info" style="margin: 0;">
            You have no outstanding loans.
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="margin-top: 30px; padding: 20px; background: rgba(230, 126, 34, 0.1); border-left: 4px solid #e67e22; border-radius: 5px;">
    <h3 style="color: #e67e22; margin: 0 0 10px 0;">⚠️ Important Notes</h3>
    <ul style="margin: 0; padding-left: 20px; color: #e0e0e0; font-size: 13px;">
        <li style="margin-bottom: 5px;">Interest on deposits and loans is calculated every game tick (scheduler update)</li>
        <li style="margin-bottom: 5px;">Transfers include a <?= ($config['ibank_paymentfee'] * 100) ?>% fee paid by the sender</li>
        <li style="margin-bottom: 5px;">New loans include a <?= ($config['ibank_loanfactor'] * 100) ?>% origination fee deducted from the loan amount</li>
        <li style="margin-bottom: 5px;">Maximum loan is <?= ($config['ibank_loanlimit'] * 100) ?>% of your net worth (based on score)</li>
        <li style="margin-bottom: 5px;">Keep sufficient balance to cover loan interest or face penalties</li>
    </ul>
</div>

<div style="margin-top: 20px;">
    <a href="/main" class="btn">← Back to Main</a>
    <a href="/status" class="btn">View Ship Status</a>
</div>
