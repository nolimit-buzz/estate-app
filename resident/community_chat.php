<?php
// resident/community_chat.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Handle POST request (New Message or Self-Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['send_message'])) {
        $message = $conn->real_escape_string(trim($_POST['message']));
        if (!empty($message)) {
            $conn->query("INSERT INTO community_chat (estate_id, user_id, message) VALUES ($estate_id, '$user_id', '$message')");
        }
        if (isset($_POST['ajax'])) {
            exit('success');
        }
    }

    if (isset($_POST['delete_message'])) {
        $msg_id = intval($_POST['message_id'] ?? 0);
        if ($msg_id > 0) {
            $conn->query("DELETE FROM community_chat WHERE id = $msg_id AND user_id = $user_id AND estate_id = $estate_id");
        }
        if (isset($_POST['ajax'])) {
            exit('success');
        }
    }
}

// If AJAX fetch messages
if (isset($_GET['fetch_messages'])) {
    $sql = "SELECT c.id, c.user_id, c.message, c.created_at,
                   u.name as sender_name, u.role as user_role,
                   COALESCE(z_user.name, MAX(z_res.name)) as zone_name,
                   COALESCE(z_user.code, MAX(z_res.code)) as zone_code,
                   MAX(s.role) as staff_designation
            FROM community_chat c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN zones z_user ON u.zone_id = z_user.id
            LEFT JOIN residents r ON (u.id = r.user_id AND r.estate_id = $estate_id)
            LEFT JOIN flats f ON r.flat_id = f.id
            LEFT JOIN buildings b ON f.building_id = b.id
            LEFT JOIN streets st ON b.street_id = st.id
            LEFT JOIN zones z_res ON st.zone_id = z_res.id
            LEFT JOIN estate_staff s ON (u.id = s.user_id AND s.estate_id = $estate_id)
            WHERE c.estate_id = $estate_id
            GROUP BY c.id, c.user_id, c.message, c.created_at, u.name, u.role, z_user.name, z_user.code
            ORDER BY c.created_at ASC
            LIMIT 250";

    $messages_res = $conn->query($sql);
    $messages = [];
    if ($messages_res) {
        while ($m = $messages_res->fetch_assoc()) {
            $is_me = ($m['user_id'] == $user_id);
            $m['is_me'] = $is_me;
            $m['can_delete'] = $is_me;
            $timestamp = strtotime($m['created_at']);
            if (date('Y-m-d', $timestamp) == date('Y-m-d')) {
                $m['time'] = date('h:i A', $timestamp);
            } else {
                $m['time'] = date('M j, h:i A', $timestamp);
            }

            // Derive Role Badge Info
            $role = $m['user_role'] ?? 'resident';
            if (in_array($role, ['superadmin', 'admin', 'manager'])) {
                $m['role_label'] = ($role === 'superadmin') ? 'Super Admin' : 'Central Admin';
                $m['role_class'] = 'badge-role-admin';
                $m['role_icon'] = 'fa-solid fa-shield-halved';
            } elseif ($role === 'zone_admin') {
                $m['role_label'] = !empty($m['zone_code']) ? ($m['zone_code'] . ' Admin') : (!empty($m['zone_name']) ? ($m['zone_name'] . ' Admin') : 'Zone Admin');
                $m['role_class'] = 'badge-role-zone';
                $m['role_icon'] = 'fa-solid fa-layer-group';
            } elseif (in_array($role, ['security', 'staff', 'accountant', 'technician']) || !empty($m['staff_designation'])) {
                $m['role_label'] = !empty($m['staff_designation']) ? $m['staff_designation'] : ($role === 'security' ? 'Security Personnel' : 'Estate Staff');
                $m['role_class'] = 'badge-role-staff';
                $m['role_icon'] = 'fa-solid fa-user-shield';
            } else {
                $m['role_label'] = !empty($m['zone_code']) ? ('Resident • ' . $m['zone_code']) : 'Resident';
                $m['role_class'] = 'badge-role-resident';
                $m['role_icon'] = 'fa-solid fa-house-user';
            }

            $messages[] = $m;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($messages);
    exit;
}

$user_res = $conn->query("SELECT name FROM users WHERE id = $user_id");
$current_user = $user_res ? $user_res->fetch_assoc() : null;

// Total messages counter for badge
$count_res = $conn->query("SELECT COUNT(*) as total FROM community_chat WHERE estate_id = $estate_id");
$total_chat_messages = $count_res ? ($count_res->fetch_assoc()['total'] ?? 0) : 0;

include 'header.php';
include 'sidebar.php';
?>

<!-- Futuristic Chat Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="mature-badge mature-badge-primary">
                <i class="fa-solid fa-comments"></i> Community Hub
            </span>
            <span class="text-secondary small">• Encrypted Forum</span>
        </div>
        <h2 class="h4 font-bold text-slate-800 m-0">Estate Residents Forum</h2>
        <p class="text-secondary small mb-0">Live community communications, announcements, and peer discussion stream.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace small d-flex align-items-center gap-2">
            <span class="status-dot-pulse bg-success"></span> Live Channel
        </span>
        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill font-monospace small">
            <?= number_format($total_chat_messages) ?> Posts
        </span>
    </div>
</div>

<div class="resident-glass-panel overflow-hidden d-flex flex-column" style="height: calc(100vh - 240px); min-height: 540px; border-radius: 20px;">
    <!-- Chat Header Bar -->
    <div class="resident-card-header d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                <i class="fa-solid fa-users-rays fs-5"></i>
            </div>
            <div>
                <h6 class="resident-card-title mb-0 fs-6">
                    Resident General Channel
                </h6>
                <div class="text-secondary small d-flex align-items-center gap-2">
                    <span>Active broadcast stream</span>
                    <span>•</span>
                    <span class="text-success"><i class="fa-solid fa-circle" style="font-size: 0.55rem;"></i> Auto-syncing</span>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="fetchMessages(true)" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1" title="Refresh messages">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>
    </div>

    <!-- Scrollable Messages Body -->
    <div class="resident-chat-container" id="chatContainer">
        <!-- Messages loaded dynamically -->
        <div class="d-flex align-items-center justify-content-center py-5 text-secondary">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            <span>Connecting to estate forum stream...</span>
        </div>
    </div>

    <!-- Input Footer Bar -->
    <div class="p-3 border-top" style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px);">
        <form id="chatForm" class="d-flex align-items-center gap-2">
            <div class="input-group flex-grow-1">
                <span class="input-group-text bg-transparent border-end-0 text-secondary ps-3">
                    <i class="fa-regular fa-comment-dots"></i>
                </span>
                <input type="text" id="messageInput" class="form-control border-start-0 ps-2" placeholder="Write a message to your neighbors and estate management..." required autocomplete="off" style="border-radius: 0 9999px 9999px 0; padding-top: 0.75rem; padding-bottom: 0.75rem;">
            </div>
            <button type="submit" id="sendBtn" class="btn btn-primary rounded-pill px-4 fw-semibold d-flex align-items-center gap-2 shadow-sm" style="padding-top: 0.75rem; padding-bottom: 0.75rem;">
                <i class="fa-solid fa-paper-plane"></i>
                <span class="d-none d-sm-inline">Send</span>
            </button>
        </form>
    </div>
</div>

<style>
.status-dot-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    position: relative;
}
.status-dot-pulse::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    border-radius: 50%;
    background: inherit;
    animation: pulse-ring 1.8s cubic-bezier(0.24, 0, 0.38, 1) infinite;
    opacity: 0.7;
}
@keyframes pulse-ring {
    0% { transform: scale(0.9); opacity: 0.8; }
    70% { transform: scale(2.2); opacity: 0; }
    100% { transform: scale(2.2); opacity: 0; }
}

.sender-pill {
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}
.sender-pill-other {
    color: var(--primary-color, #2563eb);
}
.sender-pill-me {
    color: rgba(255, 255, 255, 0.95);
}

/* Role Badges */
.badge-role-admin {
    background: #0f172a;
    color: #f8fafc;
    border: 1px solid rgba(255, 255, 255, 0.15);
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 6px;
    font-weight: 600;
}
.badge-role-zone {
    background: #7e22ce;
    color: #ffffff;
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 6px;
    font-weight: 600;
}
.badge-role-staff {
    background: #0d9488;
    color: #ffffff;
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 6px;
    font-weight: 600;
}
.badge-role-resident {
    background: rgba(100, 116, 139, 0.12);
    color: #475569;
    border: 1px solid rgba(100, 116, 139, 0.2);
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 6px;
    font-weight: 600;
}

.chat-bubble-actions {
    position: absolute;
    top: 6px;
    right: 8px;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
.resident-message-bubble:hover .chat-bubble-actions {
    opacity: 1;
}

.chat-time-stamp {
    font-size: 0.7rem;
    opacity: 0.75;
    margin-top: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
.chat-time-me {
    justify-content: flex-end;
    color: rgba(255, 255, 255, 0.85);
}
.chat-time-other {
    justify-content: flex-start;
    color: #64748b;
}

[data-theme="dark"] .chat-time-other,
body.dark-mode .chat-time-other {
    color: #94a3b8;
}

[data-theme="dark"] .resident-chat-container,
body.dark-mode .resident-chat-container {
    background: rgba(15, 23, 42, 0.8) !important;
}

[data-theme="dark"] .p-3.border-top,
body.dark-mode .p-3.border-top {
    background: rgba(30, 41, 59, 0.85) !important;
    border-top-color: rgba(255, 255, 255, 0.08) !important;
}
</style>

<script>
    const chatContainer = document.getElementById('chatContainer');
    const chatForm = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    let lastMessageCount = 0;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function fetchMessages(forceScroll = false) {
        try {
            const res = await fetch('community_chat?fetch_messages=1');
            const data = await res.json();
            
            const isAtBottom = chatContainer.scrollHeight - chatContainer.clientHeight <= chatContainer.scrollTop + 120;
            const hasNewMessages = (data.length !== lastMessageCount);
            
            if (!hasNewMessages && !forceScroll) {
                return;
            }
            
            lastMessageCount = data.length;
            chatContainer.innerHTML = '';
            
            if (data.length === 0) {
                chatContainer.innerHTML = `
                    <div class="text-center py-5 my-auto text-secondary">
                        <div class="w-12 h-12 rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width:54px; height:54px; font-size:1.5rem;">
                            <i class="fa-solid fa-comments"></i>
                        </div>
                        <h6 class="font-bold text-slate-700 mb-1">No Messages Yet</h6>
                        <p class="text-secondary small mb-0">Start the conversation with fellow estate residents!</p>
                    </div>
                `;
                return;
            }

            data.forEach(msg => {
                const div = document.createElement('div');
                div.className = `resident-message-bubble ${msg.is_me ? 'resident-message-me' : 'resident-message-other'} position-relative`;
                
                let html = '';
                
                // Self Delete Button
                if (msg.can_delete) {
                    html += `
                        <div class="chat-bubble-actions">
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 px-1" onclick="deleteMessage(${msg.id})" title="Delete My Message">
                                <i class="fa-solid fa-trash-can" style="font-size: 0.75rem;"></i>
                            </button>
                        </div>
                    `;
                }

                // Sender identification and role badge
                html += `<div class="sender-pill ${msg.is_me ? 'sender-pill-me' : 'sender-pill-other'}">
                    <span><i class="fa-solid fa-circle-user"></i> ${escapeHtml(msg.sender_name)}</span>
                    <span class="${msg.role_class}"><i class="${msg.role_icon} me-1"></i>${escapeHtml(msg.role_label)}</span>
                </div>`;
                
                html += `<div class="message-content" style="white-space: pre-wrap; padding-right: 1.25rem;">${escapeHtml(msg.message)}</div>`;
                
                html += `<div class="chat-time-stamp ${msg.is_me ? 'chat-time-me' : 'chat-time-other'}">
                    <span>${msg.time}</span>
                    ${msg.is_me ? '<i class="fa-solid fa-check-double text-sky-200 ms-1" style="font-size:0.65rem;"></i>' : ''}
                </div>`;
                
                div.innerHTML = html;
                chatContainer.appendChild(div);
            });

            if (isAtBottom || forceScroll) {
                chatContainer.scrollTop = chatContainer.scrollHeight;
            }
        } catch(e) {
            console.error("Error fetching messages:", e);
        }
    }

    async function deleteMessage(id) {
        if (!confirm('Are you sure you want to remove your message?')) {
            return;
        }
        const formData = new FormData();
        formData.append('delete_message', '1');
        formData.append('message_id', id);
        formData.append('ajax', '1');

        try {
            await fetch('community_chat', {
                method: 'POST',
                body: formData
            });
            await fetchMessages(true);
        } catch (err) {
            console.error("Error deleting message:", err);
        }
    }

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = messageInput.value.trim();
        if (!msg) return;

        // Optimistic UI or button disable
        messageInput.disabled = true;
        sendBtn.disabled = true;

        const formData = new FormData();
        formData.append('send_message', '1');
        formData.append('message', msg);
        formData.append('ajax', '1');

        try {
            await fetch('community_chat', {
                method: 'POST',
                body: formData
            });
            messageInput.value = '';
            await fetchMessages(true);
        } catch(err) {
            console.error("Error sending message:", err);
        } finally {
            messageInput.disabled = false;
            sendBtn.disabled = false;
            messageInput.focus();
        }
    });

    fetchMessages(true);
    setInterval(() => fetchMessages(false), 3500);
</script>

<?php include 'footer.php'; ?>
