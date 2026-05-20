<?php

// GET /api/events
$router->get('/api/events', function () {
    $events = Database::fetchAll(
        "SELECT id, title, description, event_date 
         FROM events WHERE event_date >= CURDATE() 
         ORDER BY event_date ASC"
    );
    Response::success($events);
});
