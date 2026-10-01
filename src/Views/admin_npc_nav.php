<style>
    .admin-nav { background: rgba(231, 76, 60, 0.2); padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid rgba(231, 76, 60, 0.5); }
    .admin-nav a { color: #e74c3c; margin-right: 15px; text-decoration: none; padding: 8px 15px; border-radius: 5px; transition: background 0.3s; }
    .admin-nav a:hover { background: rgba(231, 76, 60, 0.3); }
    .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .npc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .npc-form label { display: block; margin: 10px 0 4px; color: #95a5a6; font-size: 13px; }
    .npc-form input[type=text], .npc-form input[type=number], .npc-form textarea, .npc-form select { width: 100%; max-width: 640px; }
    pre.json { background: rgba(0,0,0,.35); padding: 10px; border-radius: 6px; overflow-x: auto; max-height: 260px; font-size: 12px; white-space: pre-wrap; }
</style>
<div class="admin-header">
    <h2 style="color: #e74c3c;">🛡️ <?= htmlspecialchars($heading ?? 'Admin') ?></h2>
    <a href="/admin/logout" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">Logout</a>
</div>
<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/npcs"><strong>NPCs</strong></a>
    <a href="/admin/npcs/global">NPC Controls</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
    <a href="/">← Game</a>
</div>
