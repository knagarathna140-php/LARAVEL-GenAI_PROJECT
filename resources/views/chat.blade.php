<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slab Inventory Assistant</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: #263746;
            background: #f3f6f8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 28px 18px; }
        button, input { font: inherit; }
        button { cursor: pointer; }
        .app {
            width: min(900px, 100%);
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid #d8e0e6;
            border-radius: 15px;
            background: #f9fbfc;
            box-shadow: 0 14px 40px rgba(29, 47, 62, .09);
        }
        .topbar {
            min-height: 62px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 12px 20px;
            background: #1d2b38;
            color: #f7fafc;
        }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .live-dot { width: 10px; height: 10px; flex: 0 0 10px; border-radius: 50%; background: #76d2b5; }
        .brand h1 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: .01em; }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .subtitle { color: #b7c4ce; font-size: 12px; text-align: right; }
        .role-status { color: #b7c4ce; font-size: 12px; white-space: nowrap; }
        .logout {
            height: 32px;
            padding: 0 11px;
            border: 1px solid #71808c;
            border-radius: 7px;
            background: transparent;
            color: #f7fafc;
            font-size: 12px;
            font-weight: 600;
        }
        .logout:hover { background: #344756; }
        .logout:disabled, button:disabled { opacity: .6; cursor: wait; }
        .workspace { padding: clamp(14px, 3vw, 24px); }
        .login-panel {
            width: min(420px, 100%);
            margin: 22px auto;
            padding: 24px;
            border: 1px solid #dce4e9;
            border-radius: 12px;
            background: #fff;
        }
        .login-panel h2 { margin: 0 0 6px; font-size: 20px; }
        .login-hint { margin: 0 0 20px; color: #677886; font-size: 14px; line-height: 1.5; }
        label { display: block; margin: 13px 0 6px; font-size: 13px; font-weight: 650; }
        input {
            width: 100%;
            min-width: 0;
            height: 42px;
            padding: 0 11px;
            border: 1px solid #cbd6de;
            border-radius: 7px;
            background: #fff;
            color: #263746;
            outline: none;
        }
        input:focus { border-color: #4283b7; box-shadow: 0 0 0 3px rgba(66, 131, 183, .13); }
        .primary-button {
            min-height: 42px;
            padding: 0 17px;
            border: 0;
            border-radius: 7px;
            background: #2d4355;
            color: white;
            font-size: 14px;
            font-weight: 650;
        }
        .primary-button:hover { background: #203748; }
        .login-panel .primary-button { width: 100%; margin-top: 18px; }
        .chat { display: flex; flex-direction: column; }
        .messages {
            height: min(68vh, 640px);
            min-height: 390px;
            display: flex;
            flex-direction: column;
            gap: 13px;
            overflow-y: auto;
            padding: clamp(14px, 2vw, 20px);
            border: 1px solid #dce4e9;
            border-radius: 12px;
            background: #f8fafc;
            scroll-behavior: smooth;
        }
        .message-row { width: min(100%, 680px); display: flex; flex-direction: column; gap: 5px; }
        .message-row.user { align-self: flex-end; align-items: flex-end; }
        .message-row.assistant { align-self: flex-start; align-items: flex-start; }
        .message-label { padding: 0 3px; color: #687986; font-size: 11px; font-weight: 700; }
        .bubble {
            max-width: 100%;
            padding: 9px 13px;
            border: 1px solid #d7e0e6;
            border-radius: 11px;
            background: #fff;
            color: #344654;
            font-size: 14px;
            line-height: 1.5;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }
        .user .bubble { border-color: #2d4355; background: #2d4355; color: #fff; }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 21px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 650;
            line-height: 1.35;
        }
        .badge.green { background: #e1f3ed; color: #18866b; }
        .badge.blue { background: #e7eff8; color: #356a9d; }
        .badge.red { background: #fde9e6; color: #c84637; }
        .badge.amber { background: #fff2d8; color: #a76c08; }
        .composer {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            margin-top: 13px;
        }
        .composer input { height: 46px; }
        .error { min-height: 19px; margin-top: 8px; color: #b42318; font-size: 13px; }
        .hidden { display: none !important; }
        @media (max-width: 620px) {
            body { padding: 10px; }
            .app { min-height: calc(100vh - 20px); border-radius: 12px; }
            .topbar { min-height: 58px; padding: 11px 13px; }
            .brand { gap: 8px; }
            .brand h1 { font-size: 14px; }
            .header-right { gap: 8px; }
            .subtitle { display: none; }
            .role-status { font-size: 11px; }
            .workspace { padding: 12px; }
            .login-panel { margin: 10px auto; padding: 20px 16px; }
            .messages { height: calc(100dvh - 200px); min-height: 300px; padding: 12px; }
            .message-row { width: 94%; }
            .bubble { font-size: 13px; }
            .composer { grid-template-columns: minmax(0, 1fr) 76px; gap: 7px; }
            .composer .primary-button { padding: 0 10px; }
        }
        @media (max-width: 380px) {
            .logout { height: 30px; padding: 0 8px; }
        }
    </style>
</head>
<body>
<main class="app">
    <header class="topbar">
        <div class="brand">
            <span class="live-dot" aria-hidden="true"></span>
            <h1>Slab Inventory Assistant</h1>
        </div>
        <div class="header-right">
            <span class="subtitle">Example conversation with sample data</span>
            <span class="role-status hidden" id="roleStatus"></span>
            <button class="logout hidden" id="logoutButton" type="button">Logout</button>
        </div>
    </header>

    <section class="workspace">
        <form class="login-panel" id="loginForm">
            <h2>Sign in</h2>
            <p class="login-hint">Use your Sales or Purchasing account to continue.</p>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="sales@example.com" autocomplete="username" required>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" value="password" autocomplete="current-password" required>
            <div class="error" id="loginError" role="alert"></div>
            <button class="primary-button" type="submit">Sign in</button>
        </form>

        <div class="chat hidden" id="chat">
            <div class="messages" id="messages" aria-live="polite">
                <div class="message-row assistant">
                    <div class="bubble">Ask about inventory, purchase order status, or other ERP data.</div>
                </div>
            </div>
            <form class="composer" id="chatForm">
                <input id="message" name="message" placeholder="How many 3cm Calacatta slabs do we have in Houston?" autocomplete="off" required>
                <button class="primary-button" type="submit">Send</button>
            </form>
            <div class="error" id="chatError" role="alert"></div>
        </div>
    </section>
</main>

<script>
    let token = localStorage.getItem('stone_erp_token');
    let role = localStorage.getItem('stone_erp_role');
    let conversationId = null;
    let hasFollowUp = false;

    const loginForm = document.getElementById('loginForm');
    const chat = document.getElementById('chat');
    const roleStatus = document.getElementById('roleStatus');
    const logoutButton = document.getElementById('logoutButton');
    const messages = document.getElementById('messages');

    function showChat(userRole) {
        role = userRole || role;
        loginForm.classList.add('hidden');
        chat.classList.remove('hidden');
        roleStatus.textContent = role ? role.charAt(0).toUpperCase() + role.slice(1) : '';
        roleStatus.classList.toggle('hidden', !role);
        logoutButton.classList.remove('hidden');
    }

    function showLogin() {
        chat.classList.add('hidden');
        loginForm.classList.remove('hidden');
        roleStatus.textContent = '';
        roleStatus.classList.add('hidden');
        logoutButton.classList.add('hidden');
        document.getElementById('loginError').textContent = '';
    }

    function appendMessage(kind, text, badgeText = '', badgeColor = 'green') {
        const row = document.createElement('div');
        row.className = `message-row ${kind}`;

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text;
        row.appendChild(bubble);

        if (kind === 'user') {
            const label = document.createElement('div');
            label.className = 'message-label';
            label.textContent = role ? role.charAt(0).toUpperCase() + role.slice(1) : 'User';
            row.appendChild(label);
        }

        if (badgeText) {
            const badge = document.createElement('span');
            badge.className = `badge ${badgeColor}`;
            badge.textContent = badgeText;
            row.appendChild(badge);
        }

        messages.appendChild(row);
        messages.scrollTop = messages.scrollHeight;
    }

    function replyBadge(reply) {
        if (/forbidden|only the purchasing|only available to the purchasing/i.test(reply)) {
            return {text: 'Blocked by the role check', color: 'red'};
        }
        if (/draft/i.test(reply) && /confirm/i.test(reply)) {
            return {text: 'Waits for confirmation before saving', color: 'amber'};
        }
        if (hasFollowUp) {
            return {text: 'Remembers the conversation', color: 'blue'};
        }
        return {text: 'Answer based on ERP data', color: 'green'};
    }

    if (token) showChat(role);

    logoutButton.addEventListener('click', async () => {
        logoutButton.disabled = true;
        try {
            if (token) {
                await fetch('/api/logout', {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Authorization': `Bearer ${token}`},
                });
            }
        } finally {
            localStorage.removeItem('stone_erp_token');
            localStorage.removeItem('stone_erp_role');
            token = null;
            role = null;
            conversationId = null;
            hasFollowUp = false;
            messages.innerHTML = '';
            appendMessage('assistant', 'Ask about inventory, purchase order status, or other ERP data.');
            document.getElementById('chatError').textContent = '';
            logoutButton.disabled = false;
            showLogin();
        }
    });

    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = document.getElementById('loginError');
        const button = loginForm.querySelector('button');
        error.textContent = '';
        button.disabled = true;

        try {
            const response = await fetch('/api/login', {
                method: 'POST',
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams(new FormData(loginForm)),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Login failed.');
            token = data.token;
            role = data.role;
            localStorage.setItem('stone_erp_token', token);
            localStorage.setItem('stone_erp_role', role);
            showChat(role);
        } catch (e) {
            error.textContent = e.message;
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById('chatForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = document.getElementById('message');
        const error = document.getElementById('chatError');
        const button = event.currentTarget.querySelector('button');
        const text = input.value.trim();
        if (!text) return;

        appendMessage('user', text);
        input.value = '';
        error.textContent = '';
        button.disabled = true;

        try {
            const body = new URLSearchParams({message: text});
            if (conversationId) body.set('conversation_id', conversationId);
            const response = await fetch('/api/assistant', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Authorization': `Bearer ${token}`},
                body,
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Assistant request failed.');
            conversationId = data.conversation_id;
            const badge = replyBadge(data.reply);
            appendMessage('assistant', data.reply, badge.text, badge.color);
            hasFollowUp = true;
        } catch (e) {
            error.textContent = e.message;
        } finally {
            button.disabled = false;
        }
    });
</script>
</body>
</html>
