<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OCI ARM Capacity Watcher</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #0b0d12;
    --card: #151923;
    --border: #262b38;
    --text: #e6e9ef;
    --muted: #8b93a7;
  }
  @media (prefers-color-scheme: light) {
    :root { --bg: #f4f5f7; --card: #ffffff; --border: #e2e5ea; --text: #1a1d23; --muted: #666f80; }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .card {
    width: 100%;
    max-width: 560px;
    margin: 24px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px;
  }
  h1 {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 24px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .badge-row {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
  }
  .dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
    background: #8b93a7;
    box-shadow: 0 0 0 6px color-mix(in srgb, currentColor 18%, transparent);
  }
  .dot.starting { color: #8b93a7; background: #8b93a7; }
  .dot.searching { color: #3b82f6; background: #3b82f6; animation: pulse 1.6s ease-in-out infinite; }
  .dot.rate_limited { color: #f59e0b; background: #f59e0b; animation: pulse 1.6s ease-in-out infinite; }
  .dot.success { color: #22c55e; background: #22c55e; }
  .dot.error { color: #ef4444; background: #ef4444; }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
  }
  .status-text {
    font-size: 22px;
    font-weight: 700;
  }
  .message {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.5;
    word-break: break-word;
    margin-bottom: 20px;
  }
  .meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 20px;
    font-size: 13px;
    border-top: 1px solid var(--border);
    padding-top: 20px;
  }
  .meta div span:first-child {
    display: block;
    color: var(--muted);
    margin-bottom: 2px;
  }
  .instance {
    margin-top: 20px;
    padding: 14px;
    border-radius: 10px;
    background: color-mix(in srgb, #22c55e 12%, transparent);
    border: 1px solid color-mix(in srgb, #22c55e 35%, transparent);
    font-size: 13px;
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>
</head>
<body>
  <div class="card">
    <h1>OCI ARM Capacity Watcher</h1>
    <div class="badge-row">
      <div class="dot starting" id="dot"></div>
      <div class="status-text" id="statusText">Loading&hellip;</div>
    </div>
    <div class="message" id="message">Waiting for the first status update from the worker.</div>
    <div class="meta">
      <div><span>Attempts</span><span id="attempts">&mdash;</span></div>
      <div><span>Shape</span><span id="shape">&mdash;</span></div>
      <div><span>Started</span><span id="startedAt">&mdash;</span></div>
      <div><span>Last updated</span><span id="updatedAt">&mdash;</span></div>
    </div>
    <div class="instance" id="instance" style="display:none"></div>
  </div>

<script>
const STATUS_LABELS = {
  starting: 'Starting',
  searching: 'Searching for capacity',
  rate_limited: 'Waiting (rate limited)',
  success: 'VM created!',
  error: 'Error',
};

function timeAgo(iso) {
  if (!iso) return '—';
  const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (seconds < 5) return 'just now';
  if (seconds < 60) return seconds + 's ago';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return minutes + 'm ago';
  const hours = Math.floor(minutes / 60);
  if (hours < 48) return hours + 'h ago';
  return Math.floor(hours / 24) + 'd ago';
}

async function refresh() {
  try {
    const res = await fetch('status.json?_=' + Date.now(), { cache: 'no-store' });
    if (!res.ok) throw new Error('no status yet');
    const data = await res.json();

    const dot = document.getElementById('dot');
    dot.className = 'dot ' + (data.status || 'starting');
    document.getElementById('statusText').textContent = STATUS_LABELS[data.status] || data.status || 'Unknown';
    document.getElementById('message').textContent = data.message || '';
    document.getElementById('attempts').textContent = data.attempts ?? '—';
    document.getElementById('shape').textContent = data.shape || '—';
    document.getElementById('startedAt').textContent = timeAgo(data.startedAt);
    document.getElementById('updatedAt').textContent = timeAgo(data.updatedAt);

    const instanceEl = document.getElementById('instance');
    if (data.status === 'success' && data.instance) {
      instanceEl.style.display = 'block';
      instanceEl.textContent = JSON.stringify(data.instance, null, 2);
    } else {
      instanceEl.style.display = 'none';
    }
  } catch (e) {
    document.getElementById('statusText').textContent = 'Initializing…';
    document.getElementById('message').textContent = 'Waiting for the worker to write its first status update.';
  }
}

refresh();
setInterval(refresh, 5000);
</script>
</body>
</html>
