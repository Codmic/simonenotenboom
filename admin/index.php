<?php
session_start();

$INDEX_PATH = realpath(__DIR__ . "/..") . DIRECTORY_SEPARATOR . "index.html";
$BACKUP_DIR = __DIR__ . DIRECTORY_SEPARATOR . "backups";
$PASS_FILE = __DIR__ . DIRECTORY_SEPARATOR . "pass.hash";

// Ensure index file exists
if (!file_exists($INDEX_PATH)) {
    http_response_code(500);
    echo "Error: index.html not found at expected location.";
    exit();
}

// Ensure backups directory exists and is writable
if (!is_dir($BACKUP_DIR)) {
    mkdir($BACKUP_DIR, 0755, true);
}

// Create a password file with a default password the first time (password: admin)
// The user is encouraged to change it immediately after logging in.
if (!file_exists($PASS_FILE)) {
    $defaultHash = password_hash("admin", PASSWORD_DEFAULT);
    file_put_contents($PASS_FILE, $defaultHash);
}

function is_logged_in()
{
    return !empty($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}

function require_login()
{
    if (!is_logged_in()) {
        header("Location: ?");
        exit();
    }
}

function csrf_token()
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(16));
    }
    return $_SESSION["csrf"];
}

function check_csrf($token)
{
    return !empty($token) &&
        !empty($_SESSION["csrf"]) &&
        hash_equals($_SESSION["csrf"], $token);
}

function list_backups($dir)
{
    $files = glob($dir . DIRECTORY_SEPARATOR . "index_*.html");
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return $files;
}

function backup_current($indexPath, $backupDir)
{
    $ts = date("Ymd_His");
    $dest = $backupDir . DIRECTORY_SEPARATOR . "index_{$ts}.html";
    if (!copy($indexPath, $dest)) {
        return false;
    }
    return $dest;
}

function replace_body($origHtml, $newBodyInnerHtml)
{
    // Replace the content between <body ...> and </body>, preserving the body tag attributes.
    if (preg_match("/<body([^>]*)>(.*?)<\/body>/is", $origHtml, $matches)) {
        $opening = "<body" . $matches[1] . ">";
        $closing = "</body>";
        return preg_replace(
            "/<body([^>]*)>(.*?)<\/body>/is",
            $opening . $newBodyInnerHtml . $closing,
            $origHtml,
            1,
        );
    } else {
        // If there's no body tag, append new body
        return $origHtml . "\n<body>\n" . $newBodyInnerHtml . "\n</body>\n";
    }
}

// Handle actions
$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "login") {
        $provided = $_POST["password"] ?? "";
        $hash = @file_get_contents($PASS_FILE);
        if ($hash && password_verify($provided, trim($hash))) {
            $_SESSION["logged_in"] = true;
            $_SESSION["login_time"] = time();
            // regenerate csrf
            $_SESSION["csrf"] = bin2hex(random_bytes(16));
            $message = "Logged in.";
        } else {
            $error = "Invalid password.";
        }
    }

    if ($action === "logout") {
        session_unset();
        session_destroy();
        session_start();
        $message = "Logged out.";
    }

    if ($action === "save") {
        if (!is_logged_in()) {
            $error = "Not logged in.";
        } else {
            $token = $_POST["csrf"] ?? "";
            if (!check_csrf($token)) {
                $error = "Invalid CSRF token.";
            } else {
                // Save a backup first
                $b = backup_current($INDEX_PATH, $BACKUP_DIR);
                if (!$b) {
                    $error = "Failed to create backup. Aborting save.";
                } else {
                    // The posted content is the body innerHTML
                    $newContent = $_POST["content"] ?? "";
                    // Normalize line endings
                    $newContent = str_replace(
                        ["\r\n", "\r"],
                        "\n",
                        $newContent,
                    );
                    $orig = file_get_contents($INDEX_PATH);
                    $newFull = replace_body($orig, $newContent);
                    if (file_put_contents($INDEX_PATH, $newFull) === false) {
                        $error = "Failed to write the index.html file.";
                    } else {
                        $message =
                            "Saved successfully. Backup created: " .
                            basename($b);
                    }
                }
            }
        }
    }

    if ($action === "restore") {
        if (!is_logged_in()) {
            $error = "Not logged in.";
        } else {
            $token = $_POST["csrf"] ?? "";
            if (!check_csrf($token)) {
                $error = "Invalid CSRF token.";
            } else {
                $which = $_POST["backup"] ?? "";
                $path = realpath($which);
                // Ensure the backup is inside the backups directory
                if (
                    $path &&
                    strpos($path, realpath($BACKUP_DIR)) === 0 &&
                    is_file($path)
                ) {
                    // Backup the current state first
                    $pre = backup_current($INDEX_PATH, $BACKUP_DIR);
                    if (!copy($path, $INDEX_PATH)) {
                        $error = "Failed to restore backup.";
                    } else {
                        $message =
                            "Restored from " .
                            basename($path) .
                            ". A backup of the previous version was saved: " .
                            basename($pre);
                    }
                } else {
                    $error = "Invalid backup specified.";
                }
            }
        }
    }

    if ($action === "change_password") {
        if (!is_logged_in()) {
            $error = "Not logged in.";
        } else {
            $token = $_POST["csrf"] ?? "";
            if (!check_csrf($token)) {
                $error = "Invalid CSRF token.";
            } else {
                $pw = $_POST["new_password"] ?? "";
                $pw2 = $_POST["new_password_confirm"] ?? "";
                if (empty($pw) || $pw !== $pw2) {
                    $error = "Passwords are empty or do not match.";
                } else {
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    if (file_put_contents($PASS_FILE, $hash) === false) {
                        $error = "Failed to write password file.";
                    } else {
                        $message = "Password changed successfully.";
                    }
                }
            }
        }
    }

    if ($action === "delete_backup") {
        if (!is_logged_in()) {
            $error = "Not logged in.";
        } else {
            $token = $_POST["csrf"] ?? "";
            if (!check_csrf($token)) {
                $error = "Invalid CSRF token.";
            } else {
                $which = $_POST["backup"] ?? "";
                $path = realpath($which);
                if (
                    $path &&
                    strpos($path, realpath($BACKUP_DIR)) === 0 &&
                    is_file($path)
                ) {
                    if (unlink($path)) {
                        $message = "Deleted backup " . basename($path);
                    } else {
                        $error = "Failed to delete backup.";
                    }
                } else {
                    $error = "Invalid backup specified.";
                }
            }
        }
    }
}

// Load current index.html body inner HTML for the editor
$bodyInnerHtml = "";
$indexHtml = file_get_contents($INDEX_PATH);
if (preg_match("/<body([^>]*)>(.*?)<\/body>/is", $indexHtml, $m)) {
    $bodyInnerHtml = $m[2];
} else {
    // fallback: whole file
    $bodyInnerHtml = $indexHtml;
}

$backups = list_backups($BACKUP_DIR);
$token = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Editor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 0; }
    header { background:#222; color:#fff; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; }
    header h1 { margin:0; font-size:18px; }
    main { padding:16px; }
    .toolbar button { margin-right:6px; }
    #editor_frame { width:100%; height:60vh; border:1px solid #ccc; }
    .message { padding:10px; margin-bottom:10px; border-radius:4px; }
    .message.ok { background:#e6ffed; border:1px solid #64c28a; color:#114b2b; }
    .message.err { background:#ffe6e6; border:1px solid #cc6363; color:#641212; }
    .flex { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .col { flex:1; min-width:200px; }
    .backups { max-height:200px; overflow:auto; border:1px solid #ddd; padding:8px; background:#fafafa; }
    .backup-item { padding:6px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
    form.inline { display:inline; }
    .small { font-size:13px; color:#666; }
    footer { padding:10px 16px; background:#f6f6f6; border-top:1px solid #e6e6e6; text-align:center; }
</style>
</head>
<body>
<header>
    <h1>Site Admin Editor</h1>
    <div>
        <?php if (is_logged_in()): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Logout</button>
            </form>
        <?php else: ?>
            <span class="small">Not logged in</span>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if ($message): ?>
        <div class="message ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!is_logged_in()): ?>
        <h2>Login</h2>
        <p>Enter the admin password. Default password (first run): <strong>admin</strong>. Change it after login.</p>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <label>
                Password:
                <input type="password" name="password" required>
            </label>
            <button type="submit">Login</button>
        </form>

        <hr>
        <h3>Preview of current site</h3>
        <iframe src="<?php echo htmlspecialchars(
            "../index.html",
        ); ?>" style="width:100%;height:50vh;border:1px solid #ccc"></iframe>

    <?php else: ?>
        <div class="flex" style="margin-bottom:12px;">
            <div class="col">
                <h2>Visual Editor</h2>
                <div class="toolbar">
                    <button type="button" onclick="exec('bold')"><b>B</b></button>
                    <button type="button" onclick="exec('italic')"><i>I</i></button>
                    <button type="button" onclick="exec('underline')"><u>U</u></button>
                    <button type="button" onclick="wrapBlock('h1')">H1</button>
                    <button type="button" onclick="wrapBlock('p')">P</button>
                    <button type="button" onclick="exec('insertUnorderedList')">• UL</button>
                    <button type="button" onclick="exec('insertOrderedList')">1. OL</button>
                    <button type="button" onclick="createLink()">Link</button>
                    <button type="button" onclick="exec('undo')">Undo</button>
                    <button type="button" onclick="exec('redo')">Redo</button>
                    <button type="button" onclick="toggleRaw()">Toggle HTML</button>
                </div>
                <p class="small">This editor allows editing all visible text and basic formatting visually. Complex structure stays intact because only the <code>&lt;body&gt;</code> contents are replaced.</p>

                <iframe id="editor_frame"></iframe>

                <form method="post" id="saveForm" style="margin-top:10px">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                        $token,
                    ); ?>">
                    <input type="hidden" name="content" id="contentField">
                    <button type="button" onclick="doSave()">Save changes</button>
                    <button type="button" onclick="previewSite()">Open live site (new tab)</button>
                </form>

                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                        $token,
                    ); ?>">
                    <strong>Change password</strong><br>
                    <input type="password" name="new_password" placeholder="New password" required>
                    <input type="password" name="new_password_confirm" placeholder="Confirm" required>
                    <button type="submit">Change</button>
                </form>

            </div>

            <div style="width:320px">
                <h3>Backups</h3>
                <div class="backups">
                    <?php if (empty($backups)): ?>
                        <div class="small">No backups yet.</div>
                    <?php else: ?>
                        <?php foreach ($backups as $b): ?>
                            <div class="backup-item">
                                <div style="flex:1">
                                    <div><strong><?php echo htmlspecialchars(
                                        basename($b),
                                    ); ?></strong></div>
                                    <div class="small"><?php echo date(
                                        "Y-m-d H:i:s",
                                        filemtime($b),
                                    ); ?></div>
                                </div>
                                <div style="margin-left:8px">
                                    <form method="post" class="inline" onsubmit="return confirm('Restore this backup?');">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                                            $token,
                                        ); ?>">
                                        <input type="hidden" name="backup" value="<?php echo htmlspecialchars(
                                            $b,
                                        ); ?>">
                                        <button type="submit">Restore</button>
                                    </form>
                                    <form method="post" class="inline" onsubmit="return confirm('Delete this backup?');">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(
                                            $token,
                                        ); ?>">
                                        <input type="hidden" name="backup" value="<?php echo htmlspecialchars(
                                            $b,
                                        ); ?>">
                                        <button type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</main>

<footer>
    <small>Admin editor — edits replace the contents of &lt;body&gt; only. Backups are stored in <code>admin/backups</code>.</small>
</footer>

<script>
    (function(){
        // Utilities
        function by(id){ return document.getElementById(id); }

        // Initialize editor iframe with current body HTML from server
        var editorFrame = by('editor_frame');
        var bodyContent = <?php echo json_encode(
            $bodyInnerHtml,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        ); ?>;
        function initEditor() {
            var doc = editorFrame.contentDocument || editorFrame.contentWindow.document;
            doc.open();
            doc.write('<!doctype html><html><head><meta charset=\"utf-8\"><style>body{font-family:inherit;padding:10px;}</style></head><body>' + bodyContent + '</body></html>');
            doc.close();
            try {
                doc.designMode = 'on';
            } catch(e) {
                // ignore
            }
        }

        var rawMode = false;

        // Exec command on the iframe document
        function exec(cmd, val){
            var doc = editorFrame.contentDocument || editorFrame.contentWindow.document;
            doc.execCommand(cmd, false, val || null);
            editorFrame.contentWindow.focus();
        }
        window.exec = exec;

        function wrapBlock(tag){
            var doc = editorFrame.contentDocument || editorFrame.contentWindow.document;
            try {
                doc.execCommand('formatBlock', false, tag);
            } catch(e) {
                // fallback: wrap selection manually
                var sel = doc.getSelection();
                if (sel.rangeCount) {
                    var range = sel.getRangeAt(0);
                    var el = doc.createElement(tag);
                    range.surroundContents(el);
                }
            }
            editorFrame.contentWindow.focus();
        }
        window.wrapBlock = wrapBlock;

        function createLink(){
            var url = prompt('Enter the URL for the link (include http:// or https://):');
            if (!url) return;
            exec('createLink', url);
        }
        window.createLink = createLink;

        function toggleRaw(){
            var doc = editorFrame.contentDocument || editorFrame.contentWindow.document;
            if (!rawMode) {
                // show a prompt with current HTML for manual editing
                var current = doc.body.innerHTML;
                var edited = prompt('Edit HTML of the body (be careful):', current);
                if (edited !== null) {
                    doc.body.innerHTML = edited;
                }
                rawMode = true;
                // small timeout to allow user to edit then exit raw mode on next toggle
                setTimeout(function(){ rawMode = false; }, 200);
            } else {
                // re-enable designMode (already on)
                try { doc.designMode = 'on'; } catch(e){}
                rawMode = false;
            }
        }
        window.toggleRaw = toggleRaw;

        // Save - copy iframe body HTML into hidden field and submit
        window.doSave = function(){
            var doc = editorFrame.contentDocument || editorFrame.contentWindow.document;
            var html = doc.body.innerHTML;
            by('contentField').value = html;
            document.getElementById('saveForm').submit();
        };

        window.previewSite = function(){
            window.open('../index.html', '_blank');
        };

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function(){
            initEditor();
        });
    })();
</script>

</body>
</html>
