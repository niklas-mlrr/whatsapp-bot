<?php

// Connect to database
$pdo = new PDO('mysql:host=localhost;dbname=whatsapp_bot', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get the latest poll message
$stmt = $pdo->prepare("
    SELECT id, content, type, metadata, created_at 
    FROM whatsapp_messages 
    WHERE type = 'poll' 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->execute();
$poll = $stmt->fetch(PDO::FETCH_ASSOC);

if ($poll) {
    echo "=== LATEST POLL MESSAGE ===\n";
    echo "ID: " . $poll['id'] . "\n";
    echo "Content: " . $poll['content'] . "\n";
    echo "Type: " . $poll['type'] . "\n";
    echo "Metadata: " . $poll['metadata'] . "\n";
    
    // Parse metadata if it's JSON
    $metadata = json_decode($poll['metadata'], true);
    if ($metadata) {
        echo "\n=== PARSED METADATA ===\n";
        echo "Poll Data: " . json_encode($metadata['poll_data'] ?? null, JSON_PRETTY_PRINT) . "\n";
        echo "Vote Counts: " . json_encode($metadata['poll_vote_counts'] ?? null, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No poll messages found in database\n";
}
?>
