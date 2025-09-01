<?php
// backend/scripts/debug_chats.php
// Usage (PowerShell): php backend/scripts/debug_chats.php [userId]
// Boots Laravel and inspects chats/memberships to diagnose why chats are not listed.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

// Bootstrap the application (so Facades like DB work)
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Helper to pretty print
function out($label, $data) {
    echo "\n=== $label ===\n";
    if (is_scalar($data) || $data === null) {
        var_export($data);
        echo "\n";
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

$userId = $argv[1] ?? null;

// Determine user
if (!$userId) {
    // Try auth fallback user used in controllers
    $firstUser = \App\Models\User::getFirstUser();
    if ($firstUser) {
        $userId = $firstUser->id;
    }
}

out('App env', config('app.env'));
out('DB connection', config('database.default'));

if (!$userId) {
    out('Error', 'No user id available. Pass a userId arg or ensure getFirstUser() returns a user.');
    exit(1);
}

out('Target userId', $userId);

// 1) Raw users summary (email may not exist)
$users = DB::select('SELECT id, name FROM users ORDER BY id ASC');
out('Users', $users);

// 2) Memberships for user
$memberships = DB::select('SELECT chat_id, user_id, created_at FROM chat_user WHERE user_id = ? ORDER BY chat_id ASC', [$userId]);
out('Memberships for user', $memberships);

// 3) All chats basic
$chats = DB::select('SELECT id, name, is_group, is_archived, created_by, participants, updated_at FROM chats ORDER BY updated_at DESC');

// Annotate chats with membership and participant inclusion
$annotated = array_map(function ($c) use ($userId) {
    $isMember = (bool) DB::select('SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ? LIMIT 1', [$c->id, $userId]);
    $isCreator = ((string) $c->created_by === (string) $userId);
    $inParticipants = false;
    if (!empty($c->participants)) {
        // participants may be JSON string; attempt decode
        $decoded = json_decode($c->participants, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $inParticipants = in_array((string) $userId, array_map('strval', $decoded), true);
        } else {
            // fallback to LIKE evaluation similar to controller
            $likePattern = '%"' . $userId . '"%';
            $inParticipants = (bool) DB::select('SELECT 1 FROM chats WHERE id = ? AND participants LIKE ? LIMIT 1', [$c->id, $likePattern]);
        }
    }

    return [
        'id' => $c->id,
        'name' => $c->name,
        'is_group' => (int) $c->is_group,
        'is_archived' => (int) ($c->is_archived ?? 0),
        'created_by' => $c->created_by,
        'participants_raw' => $c->participants,
        'membership' => [
            'is_member' => $isMember,
            'is_creator' => $isCreator,
            'in_participants' => $inParticipants,
        ],
        'included_by_index_query' => null, // will be filled below by simulating the index() WHERE
        'updated_at' => $c->updated_at,
    ];
}, $chats);

// 4) Simulate index() WHERE logic to show which chats should be returned
$likePattern = '%"' . $userId . '"%';
$indexQuery = DB::select('
    SELECT c.id
    FROM chats c
    LEFT JOIN chat_user cu ON c.id = cu.chat_id AND cu.user_id = ?
    WHERE (
        cu.user_id = ?
        OR c.created_by = ?
        OR (c.participants IS NOT NULL AND c.participants LIKE ?)
    )
    AND (c.is_archived = 0 OR c.is_archived IS NULL)
', [$userId, $userId, $userId, $likePattern]);

$allowedIds = array_map(fn($r) => (string) $r->id, $indexQuery);
foreach ($annotated as &$row) {
    $row['included_by_index_query'] = in_array((string) $row['id'], $allowedIds, true);
}
unset($row);

out('Annotated chats for user (with inclusion flags)', $annotated);

// 5) Show why any excluded chat is excluded
$excluded = array_values(array_filter($annotated, fn($r) => !$r['included_by_index_query']));
if ($excluded) {
    out('Excluded chats (reasons)', array_map(function ($r) {
        $reasons = [];
        if (!$r['membership']['is_member']) $reasons[] = 'not a member';
        if (!$r['membership']['is_creator']) $reasons[] = 'not creator';
        if (!$r['membership']['in_participants']) $reasons[] = 'not in participants';
        if ((int)$r['is_archived'] === 1) $reasons[] = 'archived';
        $r['exclusion_reasons'] = $reasons;
        return $r;
    }, $excluded));
} else {
    out('Excluded chats (reasons)', 'none');
}

out('Done', 'OK');
