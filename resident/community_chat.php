<?php
// resident/community_chat.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Handle POST request (New Message)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $message = $conn->real_escape_string($_POST['message']);
    if (!empty($message)) {
        $conn->query("INSERT INTO community_chat (estate_id, user_id, message) VALUES ($estate_id, '$user_id', '$message')");
    }
    if (isset($_POST['ajax'])) {
        exit('success');
    }
}

// If AJAX fetch messages
if (isset($_GET['fetch_messages'])) {
    $messages_res = $conn->query("SELECT c.*, u.name as sender_name 
                                 FROM community_chat c 
                                 JOIN users u ON c.user_id = u.id 
                                 WHERE c.estate_id = $estate_id
                                 ORDER BY c.created_at ASC 
                                 LIMIT 100");
    $messages = [];
    if ($messages_res) {
        while ($m = $messages_res->fetch_assoc()) {
            $m['is_me'] = ($m['user_id'] == $user_id);
            $timestamp = strtotime($m['created_at']);
            if (date('Y-m-d', $timestamp) == date('Y-m-d')) {
                $m['time'] = date('H:i', $timestamp);
            } else {
                $m['time'] = date('M j, H:i', $timestamp);
            }
            $messages[] = $m;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($messages);
    exit;
}

$user_res = $conn->query("SELECT name FROM users WHERE id = $user_id");
$current_user = $user_res->fetch_assoc();

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-comments text-primary me-2"></i> Estate Forum & Community Chat</h2>
        <p class="text-secondary small mb-0">Connect and chat with other residents in your estate community.</p>
    </div>
</div>

<div class="card border-0 shadow-sm glass overflow-hidden" style="height: calc(100vh - 210px); min-height: 500px; display: flex; flex-direction: column;">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center px-4">
        <h6 class="font-bold m-0 text-slate-800"><i class="fa-solid fa-users text-primary me-2"></i> Resident Community Discussion</h6>
        <span class="badge bg-light text-dark border"><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.6rem;"></i> Live Chat</span>
    </div>

    <div class="card-body p-4 overflow-y-auto d-flex flex-column gap-3" id="chatContainer" style="flex: 1; background: #f8fafc;">
        <!-- Messages loaded dynamically -->
    </div>

    <div class="card-footer bg-white p-3 border-top">
        <form id="chatForm" class="d-flex gap-2">
            <input type="text" id="messageInput" class="form-control rounded-pill px-4" placeholder="Type a message..." required autocomplete="off">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold d-flex align-items-center gap-2">
                <i class="fa-solid fa-paper-plane"></i> <span class="d-none d-sm-inline">Send</span>
            </button>
        </form>
    </div>
</div>

<style>
    .message-bubble {
        max-width: 75%;
        padding: 0.75rem 1.1rem;
        border-radius: 1.25rem;
        line-height: 1.5;
        font-size: 0.95rem;
        word-break: break-word;
    }
    .message-me {
        background: var(--primary-color, #3b82f6);
        color: white;
        align-self: flex-end;
        border-bottom-right-radius: 0.25rem;
    }
    .message-other {
        background: #ffffff;
        color: #1e293b;
        align-self: flex-start;
        border-bottom-left-radius: 0.25rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .sender-name {
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        color: #64748b;
    }
    .message-time {
        font-size: 0.7rem;
        opacity: 0.75;
        margin-top: 0.25rem;
        text-align: right;
    }
</style>

<script>
    const chatContainer = document.getElementById('chatContainer');
    const chatForm = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');

    async function fetchMessages() {
        try {
            const res = await fetch('community_chat?fetch_messages=1');
            const data = await res.json();
            
            const isAtBottom = chatContainer.scrollHeight - chatContainer.clientHeight <= chatContainer.scrollTop + 100;
            
            chatContainer.innerHTML = '';
            data.forEach(msg => {
                const div = document.createElement('div');
                div.className = `message-bubble ${msg.is_me ? 'message-me' : 'message-other'}`;
                
                let html = '';
                if (!msg.is_me) {
                    html += `<div class="sender-name">${msg.sender_name}</div>`;
                }
                html += `<div>${msg.message}</div>`;
                html += `<div class="message-time">${msg.time}</div>`;
                
                div.innerHTML = html;
                chatContainer.appendChild(div);
            });

            if (isAtBottom || chatContainer.scrollTop === 0) {
                chatContainer.scrollTop = chatContainer.scrollHeight;
            }
        } catch(e) {
            console.error(e);
        }
    }

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = messageInput.value.trim();
        if (!msg) return;

        const formData = new FormData();
        formData.append('send_message', '1');
        formData.append('message', msg);
        formData.append('ajax', '1');

        messageInput.value = '';
        
        await fetch('community_chat', {
            method: 'POST',
            body: formData
        });
        
        fetchMessages();
    });

    fetchMessages();
    setInterval(fetchMessages, 3000);
</script>

<?php include 'footer.php'; ?>
