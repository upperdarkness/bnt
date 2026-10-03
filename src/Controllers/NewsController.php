<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\News;
use BNT\Models\Ship;

class NewsController
{
    public function __construct(private News $news, private Ship $ships, private Session $session) {}

    public function index(): void
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }
        $ship = $this->ships->find($this->session->getUserId());
        if (!$ship) {
            header('Location: /');
            exit;
        }
        $source = in_array($_GET['source'] ?? '', ['journalist', 'system'], true) ? $_GET['source'] : null;
        $items = $this->news->recent(100, $source);
        $session = $this->session;
        include __DIR__ . '/../Views/news.php';
    }
}
