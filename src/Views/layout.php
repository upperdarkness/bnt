<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'BlackNova Traders') ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e0e0e0;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            background: rgba(15, 76, 117, 0.3);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }

        h1 {
            color: #3498db;
            text-shadow: 0 0 10px rgba(52, 152, 219, 0.5);
        }

        .nav {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .nav a, .btn {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            border: 1px solid rgba(52, 152, 219, 0.5);
            transition: all 0.3s;
            display: inline-block;
            cursor: pointer;
            font-size: 14px;
        }

        .nav a:hover, .btn:hover {
            background: rgba(52, 152, 219, 0.4);
            box-shadow: 0 0 15px rgba(52, 152, 219, 0.5);
        }

        .content {
            background: rgba(22, 33, 62, 0.6);
            padding: 30px;
            border-radius: 10px;
            border: 1px solid rgba(52, 152, 219, 0.2);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.2);
            border-color: #2ecc71;
            color: #2ecc71;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.2);
            border-color: #e74c3c;
            color: #e74c3c;
        }

        .alert-info {
            background: rgba(52, 152, 219, 0.2);
            border-color: #3498db;
            color: #3498db;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(52, 152, 219, 0.2);
        }

        th {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        tr:hover {
            background: rgba(52, 152, 219, 0.1);
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            background: rgba(22, 33, 62, 0.8);
            border: 1px solid rgba(52, 152, 219, 0.3);
            border-radius: 5px;
            color: #e0e0e0;
            margin: 5px 0;
        }

        input[type="submit"],
        button[type="submit"] {
            background: rgba(52, 152, 219, 0.3);
            color: #3498db;
            padding: 12px 30px;
            border: 1px solid rgba(52, 152, 219, 0.5);
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }

        input[type="submit"]:hover,
        button[type="submit"]:hover {
            background: rgba(52, 152, 219, 0.5);
            box-shadow: 0 0 15px rgba(52, 152, 219, 0.5);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: rgba(15, 76, 117, 0.3);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 12px;
            text-transform: uppercase;
        }

        .stat-value {
            color: #3498db;
            font-size: 24px;
            font-weight: bold;
            margin-top: 5px;
        }

        footer {
            text-align: center;
            margin-top: 30px;
            color: #7f8c8d;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($showHeader) && $showHeader): ?>
        <header>
            <h1>BlackNova Traders</h1>
            <?php if (isset($ship)): ?>
            <div class="nav">
                <a href="/main">Main</a>
                <a href="/status">Status</a>
                <a href="/scan">Scan</a>
                <a href="/combat">Combat</a>
                <a href="/defenses">Defenses</a>
                <a href="/logs">Logs</a>
                <a href="/planets">Planets</a>
                <a href="/teams">Teams</a>
                <a href="/messages">Messages</a>
                <a href="/upgrades">Upgrades</a>
                <a href="/skills">Skills</a>
                <a href="/ibank">IGB</a>
                <a href="/ranking">Rankings</a>
                <a href="/logout">Logout</a>
            </div>
            <?php endif; ?>
        </header>
        <?php endif; ?>

        <div class="content">
            <?php
            // Display flash messages
            if (isset($session)) {
                if ($message = $session->get('message')) {
                    echo '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
                    $session->remove('message');
                }
                if ($error = $session->get('error')) {
                    echo '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>';
                    $session->remove('error');
                }
            }
            ?>

            <?= $content ?? '' ?>
        </div>

        <footer>
            BlackNova Traders v2.0 | Modern PHP Edition | &copy; <?= date('Y') ?>
        </footer>
    </div>
</body>
</html>
