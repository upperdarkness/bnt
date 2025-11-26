<?php
$title = 'Create Team - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Create a New Team</h2>

<div class="alert alert-info">
    As the founder, you'll have full control over the team including inviting members, kicking players, and managing team settings.
</div>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px; max-width: 600px; margin: 30px auto;">
    <form action="/teams/store" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div style="margin-bottom: 20px;">
            <label for="team_name" style="display: block; margin-bottom: 5px; color: #3498db;">
                Team Name <span style="color: #e74c3c;">*</span>
            </label>
            <input type="text"
                   id="team_name"
                   name="team_name"
                   required
                   minlength="3"
                   maxlength="50"
                   placeholder="Enter a unique team name (3-50 characters)">
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                Choose a memorable name that represents your team's identity
            </small>
        </div>

        <div style="margin-bottom: 20px;">
            <label for="description" style="display: block; margin-bottom: 5px; color: #3498db;">
                Description
            </label>
            <textarea id="description"
                      name="description"
                      rows="5"
                      maxlength="500"
                      placeholder="Describe your team, its goals, and playstyle (optional, max 500 characters)"></textarea>
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                This will be visible to all players when viewing your team
            </small>
        </div>

        <div style="background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h4 style="color: #3498db; margin-bottom: 10px;">Team Benefits</h4>
            <ul style="margin-left: 20px; color: #e0e0e0; line-height: 1.8;">
                <li>Team members can't attack each other</li>
                <li>Share sector defenses with teammates</li>
                <li>Team chat for private communication</li>
                <li>Combined team statistics and rankings</li>
                <li>Coordinate attacks and trading routes</li>
            </ul>
        </div>

        <div style="background: rgba(241, 196, 15, 0.1); padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h4 style="color: #f39c12; margin-bottom: 10px;">Founder Responsibilities</h4>
            <ul style="margin-left: 20px; color: #e0e0e0; line-height: 1.8;">
                <li>Invite and manage team members</li>
                <li>Update team description and settings</li>
                <li>Can kick members if needed</li>
                <li>Must disband team or transfer leadership before leaving</li>
                <li>Lead your team to victory!</li>
            </ul>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn" style="flex: 1; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                Create Team
            </button>
            <a href="/teams" class="btn" style="flex: 1; text-align: center;">
                Cancel
            </a>
        </div>
    </form>
</div>

<div style="margin-top: 30px; text-align: center;">
    <h3>Team Strategy Tips</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
        <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
            <h4 style="color: #3498db;">Communication</h4>
            <p style="color: #e0e0e0; line-height: 1.6;">
                Use team chat to coordinate movements, plan attacks, and share intelligence about enemy positions.
            </p>
        </div>
        <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
            <h4 style="color: #3498db;">Defense</h4>
            <p style="color: #e0e0e0; line-height: 1.6;">
                Deploy fighters and mines in sectors your team controls. Defenses won't attack teammates.
            </p>
        </div>
        <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
            <h4 style="color: #3498db;">Territory</h4>
            <p style="color: #e0e0e0; line-height: 1.6;">
                Build bases on planets to claim sectors. A strong team controls key trading routes and resources.
            </p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
