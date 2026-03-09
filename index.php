<?php
require_once '/home/../config/config.php'; // for variables
require_once '/home/../config/send_sms.php'; // for sms
// 1. DISABLE BROWSER CACHING (Must be at the very top)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

ob_start();
date_default_timezone_set('America/New_York');
$file = 'tasks.json';
$default_url = "https://tasks.$domain_name";

// --- DATA REPAIR & MIGRATION ENGINE ---
// If file is missing, we treat data as empty to ensure the UI clears
$decoded_data = [];
if (file_exists($file)) {
    $raw_json = file_get_contents($file);
    $decoded_data = json_decode($raw_json, true) ?: [];
}

$tasks = [];

foreach ($decoded_data as $data) {
    $clean_data = [
        'desc'        => $data['desc'] ?? 'Untitled Task',
        'link'        => (!empty($data['link'])) ? $data['link'] : $default_url,
        'comments'    => $data['comments'] ?? '',
        'deleted'     => (bool)($data['deleted'] ?? false),
        'completed'   => (bool)($data['completed'] ?? false), // New Status
        'created_est' => $data['created_est'] ?? date('Y-m-d H:i:s')
    ];
    $base_id = date('YmdHis', strtotime($clean_data['created_est']));
    $unique_id = $base_id;
    $suffix = 1;
    while (isset($tasks[$unique_id])) {
        $unique_id = $base_id . "_" . $suffix++;
    }
    $tasks[$unique_id] = $clean_data;
}

// Update file with cleaned data if it exists
if (file_exists($file)) {
    file_put_contents($file, json_encode($tasks));
}

// --- ACTION HANDLING ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    if (isset($tasks[$id])) {
        if ($_GET['action'] === 'soft_delete') $tasks[$id]['deleted'] = true;
        elseif ($_GET['action'] === 'restore') $tasks[$id]['deleted'] = false;
        elseif ($_GET['action'] === 'expunge') unset($tasks[$id]);
        elseif ($_GET['action'] === 'toggle_complete') $tasks[$id]['completed'] = !$tasks[$id]['completed'];
        file_put_contents($file, json_encode($tasks));
    }
    $redir = isset($_GET['view_deleted']) ? "index.php?view_deleted=1" : "index.php";
    // Append timestamp to URL to force browser to treat it as a new page
    header("Location: " . $redir . (strpos($redir, '?') ? '&' : '?') . "nocache=" . time());
    exit;
}

// --- ADD / UPDATE LOGIC ---
if (isset($_POST['save_task'])) {
    $created_raw = $_POST['created_est'] ?: date('Y-m-d H:i:s');
    $created_ts = date('Y-m-d H:i:s', strtotime($created_raw));
    $id = (!empty($_POST['task_id'])) ? $_POST['task_id'] : (date('YmdHis', strtotime($created_ts)) . "_" . rand(100,999));

    // Default Link Logic
    $submitted_link = trim($_POST['link']);
    $final_link = (!empty($submitted_link)) ? filter_var($submitted_link, FILTER_SANITIZE_URL) : $default_url;
    $temp_desc = trim($_POST['desc']);
    $tasks[$id] = [
        'desc'        => htmlspecialchars($temp_desc),
        'link'        => $final_link,
        'comments'    => htmlspecialchars($_POST['comments']),
        'deleted'     => false,
        'completed'   => (bool)($tasks[$id]['completed'] ?? false),
        'created_est' => $created_ts
    ];
    file_put_contents($file, json_encode($tasks));
    
    // send sms
    $text_message = $temp_desc . PHP_EOL . $final_link;
    //file_put_contents('sms.log', $text_message, LOCK_EX);
    //$recipient = USER1;
    //$result = send_telnyx_sms($recipient, $text_message);
    $recipient = USER2;
    //$result = send_telnyx_sms($recipient, $text_message);

    //echo $result;
    header("Location: index.php?nocache=" . time());
    exit;
}

// --- BACKUP & RESTORE ---
if (isset($_POST['download_backup'])) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="tasks_backup_'.date('Ymd_His').'.json"');
    echo json_encode($tasks, JSON_PRETTY_PRINT); exit;
}
if (isset($_FILES['restore_file']) && $_FILES['restore_file']['error'] === 0) {
    $content = file_get_contents($_FILES['restore_file']['tmp_name']);
    if (json_decode($content, true) !== null) file_put_contents($file, $content);
    header("Location: index.php?nocache=" . time()); exit;
}

// --- DISPLAY PREP ---
$isDeletedView = isset($_GET['view_deleted']);
$sortOrder = (isset($_GET['sort']) && $_GET['sort'] === 'asc') ? 'asc' : 'desc';
$displayTasks = array_filter($tasks, function($t) use ($isDeletedView) { return ($t['deleted'] ?? false) === $isDeletedView; });
uasort($displayTasks, function ($a, $b) use ($sortOrder) {
    $dateA = strtotime($a['created_est']); $dateB = strtotime($b['created_est']);
    return ($sortOrder === 'asc') ? $dateA <=> $dateB : $dateB <=> $dateA;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <title>Task Items</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 40px 40px 140px 40px; background: #f4f7f6; }
        .container { max-width: 1350px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .add-form { display: flex; gap: 10px; margin-bottom: 25px; align-items: center; }
        .add-form input { padding: 10px; border: 1px solid #ddd; border-radius: 6px; flex: 1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
        .edit-fields { display: none; padding: 5px; border: 1px solid #1a73e8; border-radius: 4px; font-size: 14px; }
        .strikethrough { text-decoration: line-through; color: #888; }
        .checkbox-cell { text-align: center; width: 40px; cursor: pointer; font-size: 20px; }
        .footer { position: fixed; bottom: 0; left: 0; width: 100%; background: #202124; padding: 25px 0; display: flex; justify-content: center; align-items: center; gap: 20px; z-index: 1000; }
        .btn { padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; height: 42px; border: none; box-sizing: border-box; text-decoration: none; font-family: inherit; }
    </style>
</head>
<body>

<div class="container">
    <h1 style="text-align:center; color:#1a73e8;"><?php echo {$CustomUser}'s Items  {$isDeletedView} ? "(Trash View)" : ""; ?></h1>

    <h4 style="text-align:center;color:#1a73e8;">
         <?php echo '<a href="https://{$domain_name}/{$tasklist1}/{$task_list_name1}">{$task_list_name1} Tasks</a> | <a href="https://{$domain_name}/{$tasklist2}/{$task_list_name2}">{$task_list_name2} Tasks</a> | <a href="https://{$domain_name}/{$tasklist3}/{$task_list_name3}">{task_list_name3} Reminders</a>"'; ?>
         </h4>
    <div class="add-form">
        <a href="?sort=<?php echo ($sortOrder === 'desc' ? 'asc' : 'desc'); ?>&nocache=<?php echo time(); ?>" class="btn" style="background:#eee;"><?php echo ($sortOrder === 'desc' ? 'Newest ↓' : 'Oldest ↑'); ?></a>
        <form method="POST" action="index.php?nocache=<?php echo time(); ?>" style="display:flex; flex:1; gap:10px;">
            <input type="text" name="desc" placeholder="Task description" required>
            <input type="url" name="link" placeholder="Link (Optional)">
            <input type="text" name="comments" placeholder="Comments">
            <input type="hidden" name="created_est" value="<?php echo date('Y-m-d H:i:s'); ?>">
            <button type="submit" name="save_task" class="btn" style="background:#28a745; color:white;">Add Task</button>
        </form>
        <a href="?<?php echo $isDeletedView ? "" : "view_deleted=1"; ?>&nocache=<?php echo time(); ?>" class="btn" style="background:#f0ad4e; color:white;"><?php echo $isDeletedView ? "View Active" : "View Deleted"; ?></a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Done</th>
                <th>#</th>
                <th style="width:220px;">Date Added (EST)</th>
                <th>Task</th>
                <th>Link</th>
                <th>Comments</th>
                <th style="text-align:right; width:150px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $count = 1; foreach ($displayTasks as $id => $task): 
                $created_display = date('M d, Y h:i A', strtotime($task['created_est']));
                $created_input = date('Y-m-d\TH:i', strtotime($task['created_est']));
                $isDone = $task['completed'] ?? false;
            ?>
            <tr id="row-<?php echo $id; ?>">
                <form method="POST" action="index.php?nocache=<?php echo time(); ?>">
                    <td class="checkbox-cell" onclick="location.href='?action=toggle_complete&id=<?php echo $id; ?><?php echo $isDeletedView ? '&view_deleted=1' : ''; ?>'">
                        <?php echo $isDone ? '☑' : '☐'; ?>
                    </td>
                    <td><?php echo $count++; ?></td>
                    <td><span class="view-mode"><?php echo $created_display; ?></span><input type="datetime-local" name="created_est" class="edit-fields" value="<?php echo $created_input; ?>"></td>
                    <td><span class="view-mode <?php echo $isDone ? 'strikethrough' : ''; ?>"><?php echo $task['desc']; ?></span><input type="text" name="desc" class="edit-fields" value="<?php echo $task['desc']; ?>" required></td>
                    <td>
                        <span class="view-mode"><a href="<?php echo $task['link']; ?>" target="_blank" style="color:#1a73e8;" title="<?php echo $task['link']; ?>">Visit</a></span>
                        <input type="url" name="link" class="edit-fields" value="<?php echo $task['link']; ?>">
                    </td>
                    <td><span class="view-mode"><?php echo $task['comments']; ?></span><input type="text" name="comments" class="edit-fields" value="<?php echo $task['comments']; ?>"></td>
                    <td style="text-align:right;">
                        <input type="hidden" name="task_id" value="<?php echo $id; ?>">
                        <div class="view-mode">
                            <?php if (!$isDeletedView): ?>
                                <span style="color:#1a73e8; cursor:pointer; font-weight:600; margin-right:15px;" onclick="toggleEdit('<?php echo $id; ?>')">Edit</span>
                                <a href="?action=soft_delete&id=<?php echo $id; ?>&nocache=<?php echo time(); ?>" style="color:#d93025; text-decoration:none; font-weight:600;">Delete</a>
                            <?php else: ?>
                                <a href="?action=restore&id=<?php echo $id; ?>&view_deleted=1&nocache=<?php echo time(); ?>" style="color:#28a745; text-decoration:none; font-weight:600;">Restore</a>
                                <a href="?action=expunge&id=<?php echo $id; ?>&view_deleted=1&nocache=<?php echo time(); ?>" style="color:#d93025; text-decoration:none; font-weight:600; margin-left:15px;" onclick="return confirm('Expunge permanently?')">Expunge</a>
                            <?php endif; ?>
                        </div>
                        <div class="edit-fields">
                            <button type="submit" name="save_task" class="btn" style="background:#1a73e8; color:white; height:30px; padding:0 10px;">Save</button>
                            <button type="button" class="btn" style="background:#ddd; height:30px; padding:0 10px; margin-left:5px;" onclick="toggleEdit('<?php echo $id; ?>')">Cancel</button>
                        </div>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="footer">
    <form method="POST"><button type="submit" name="download_backup" class="btn" style="background:#3c4043; color:white;">Download Backup</button></form>
    <form method="POST" enctype="multipart/form-data" id="restore-form" action="index.php?nocache=<?php echo time(); ?>">
        <label for="file-upload" class="btn" style="background:#3c4043; color:white;">Restore from File</label>
        <input id="file-upload" type="file" name="restore_file" style="display:none;" onchange="if(confirm('Overwrite current database?')) document.getElementById('restore-form').submit();">
    </form>
</div>

<script>
function toggleEdit(id) {
    const row = document.getElementById('row-' + id);
    const views = row.querySelectorAll('.view-mode'), edits = row.querySelectorAll('.edit-fields');
    const isEdit = (edits[0].style.display === 'inline-block');
    views.forEach(v => v.style.display = isEdit ? 'inline-block' : 'none');
    edits.forEach(e => e.style.display = isEdit ? 'none' : 'inline-block');
}
</script>
</body>
</html>

