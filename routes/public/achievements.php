<?php

// GET /api/achievements
$router->get('/api/achievements', function () {
    $achievements = Database::fetchAll(
        "SELECT id, title FROM achievements ORDER BY sort_order"
    );

    foreach ($achievements as &$a) {
        $a['images'] = Database::fetchAll(
            "SELECT id, image_path FROM achievement_images WHERE achievement_id = ? ORDER BY sort_order",
            [$a['id']]
        );
    }

    Response::success($achievements);
});
